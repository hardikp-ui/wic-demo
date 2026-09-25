<?php
/**
 * Getting data out (#156, #157, #160).
 *
 * - An xAPI feed of completions, passes, failures and (optionally) answers to an agency's own
 *   Learning Record Store. Statements are queued and sent by the hourly job with backoff.
 * - cmi5 export: a course package (cmi5.xml, one AU per course) whose AU URL is this site's
 *   launch endpoint, and a results export as xAPI statements.
 * - cmi5 launch: a course opened from another LMS. The launch endpoint follows the cmi5 launch
 *   sequence (fetch URL → auth token → initialized … completed/passed → terminated).
 *
 * IMPORTANT: the cmi5 launch has been written to the cmi5 specification but has NOT been
 * tested against a real LMS. Treat it as a prototype until it has been run end to end with
 * the agency's LMS. Per the spec, the LMS (not the AU) records "launched"; this AU sends
 * initialized, completed, passed/failed and terminated. LMS.LaunchData (moveOn, mastery
 * score) is not read yet — the course's own pass mark is used.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_table_sql',
	function ( $sql, $c ) {
		$sql[] = 'CREATE TABLE ' . wic_table( 'xapi_queue' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  target varchar(20) NOT NULL DEFAULT 'feed',
  endpoint varchar(255) NOT NULL DEFAULT '',
  auth text NULL,
  verb varchar(40) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  statement longtext NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'queued',
  attempts int(11) NOT NULL DEFAULT 0,
  next_try datetime NOT NULL,
  last_error text NULL,
  created_at datetime NOT NULL,
  sent_at datetime NULL,
  PRIMARY KEY  (id),
  KEY status_next (status,next_try)
) $c;";
		return $sql;
	},
	10,
	2
);

class WIC_Xapi {

	const OPT      = 'wic_xapi';
	const CMI5_CAT = 'https://w3id.org/xapi/cmi5/context/categories/cmi5';
	const MOVEON   = 'https://w3id.org/xapi/cmi5/context/categories/moveon';
	const SESSION  = 'https://w3id.org/xapi/cmi5/context/extensions/sessionid';
	const MAX_TRY  = 10;

	public static function init() {
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		add_filter( 'wic_flash_messages', array( __CLASS__, 'messages' ) );
		add_action( 'wic_course_completed', array( __CLASS__, 'on_completed' ), 50 );
		add_action( 'wic_answer_recorded', array( __CLASS__, 'on_answer' ), 30, 6 );
		add_filter( 'wic_evaluate_result', array( __CLASS__, 'on_evaluate' ), 90, 4 );
		add_action( 'wic_hourly', array( __CLASS__, 'send_queue' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		foreach ( array( 'xapi_save', 'xapi_retry', 'xapi_send_now', 'cmi5_export', 'xapi_results' ) as $a ) {
			add_action( 'admin_post_wic_' . $a, array( __CLASS__, $a ) );
		}
	}

	public static function opts() {
		return wp_parse_args(
			get_option( self::OPT, array() ),
			array(
				'enabled'      => 0,
				'endpoint'     => '',
				'key'          => '',
				'secret'       => '',
				'answers'      => 0,
				'cmi5_enabled' => 0,
				'cmi5_hosts'   => '',
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Statements                                                         */
	/* ------------------------------------------------------------------ */

	public static function course_iri( $course_id ) {
		return home_url( '/wic/course/' . (int) $course_id );
	}

	public static function actor( $user_id ) {
		$u = get_userdata( $user_id );
		return array(
			'objectType' => 'Agent',
			'name'       => $u ? $u->display_name : '',
			'mbox'       => 'mailto:' . ( $u ? $u->user_email : 'unknown@example.invalid' ),
		);
	}

	public static function verb( $name ) {
		return array(
			'id'      => 'http://adlnet.gov/expapi/verbs/' . $name,
			'display' => array( 'en-US' => $name ),
		);
	}

	public static function course_object( $course_id, $iri = null ) {
		return array(
			'objectType' => 'Activity',
			'id'         => $iri ? $iri : self::course_iri( $course_id ),
			'definition' => array(
				'name' => array( 'en-US' => wp_strip_all_tags( get_the_title( $course_id ) ) ),
				'type' => 'http://adlnet.gov/expapi/activities/course',
			),
		);
	}

	public static function duration( $seconds ) {
		return 'PT' . max( 0, (int) $seconds ) . 'S';
	}

	private static function statement( $actor, $verb, $object, $extra = array() ) {
		return array_merge(
			array(
				'id'        => wp_generate_uuid4(),
				'actor'     => $actor,
				'verb'      => self::verb( $verb ),
				'object'    => $object,
				'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'context'   => array( 'platform' => 'WIC Training Platform' ),
			),
			$extra
		);
	}

	/** The statements that describe one completion: completed, then passed with the score. */
	public static function completion_statements( $completion ) {
		$actor  = self::actor( $completion->user_id );
		$object = self::course_object( $completion->course_id );
		$ts     = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $completion->completed_at . ' UTC' ) );
		$score  = array(
			'scaled' => round( (int) $completion->score / 100, 2 ),
			'raw'    => (int) $completion->score,
			'min'    => 0,
			'max'    => 100,
		);
		$a              = self::statement( $actor, 'completed', $object, array( 'result' => array( 'completion' => true, 'duration' => self::duration( $completion->time_spent ) ) ) );
		$b              = self::statement( $actor, 'passed', $object, array( 'result' => array( 'success' => true, 'completion' => true, 'score' => $score ) ) );
		$a['timestamp'] = $ts;
		$b['timestamp'] = $ts;
		return array( $a, $b );
	}

	/* ------------------------------------------------------------------ */
	/* Queue                                                              */
	/* ------------------------------------------------------------------ */

	public static function enqueue( $statement, $target = 'feed', $endpoint = '', $auth = '', $user_id = 0 ) {
		global $wpdb;
		$wpdb->insert(
			wic_table( 'xapi_queue' ),
			array(
				'target'     => $target,
				'endpoint'   => $endpoint,
				'auth'       => $auth,
				'verb'       => isset( $statement['verb']['display']['en-US'] ) ? $statement['verb']['display']['en-US'] : '',
				'user_id'    => (int) $user_id,
				'statement'  => wp_json_encode( $statement ),
				'status'     => 'queued',
				'attempts'   => 0,
				'next_try'   => wic_now(),
				'created_at' => wic_now(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	private static function feed_on() {
		$o = self::opts();
		return $o['enabled'] && $o['endpoint'];
	}

	public static function on_completed( $completion ) {
		if ( self::feed_on() ) {
			foreach ( self::completion_statements( $completion ) as $s ) {
				self::enqueue( $s, 'feed', '', '', $completion->user_id );
			}
		}
		self::cmi5_on_completed( $completion );
	}

	public static function on_answer( $user_id, $course_id, $slide_id, $correct, $attempt_no, $run ) {
		$o = self::opts();
		if ( ! self::feed_on() || ! $o['answers'] ) {
			return;
		}
		$object = array(
			'objectType' => 'Activity',
			'id'         => self::course_iri( $course_id ) . '/question/' . (int) $slide_id,
			'definition' => array(
				'name' => array( 'en-US' => wp_strip_all_tags( get_the_title( $slide_id ) ) ),
				'type' => 'http://adlnet.gov/expapi/activities/cmi.interaction',
			),
		);
		$s = self::statement(
			self::actor( $user_id ),
			'answered',
			$object,
			array(
				'result' => array( 'success' => (bool) $correct ),
			)
		);
		$s['context']['contextActivities'] = array( 'parent' => array( array( 'id' => self::course_iri( $course_id ) ) ) );
		$s['context']['extensions']        = array( 'https://w3id.org/xapi/cmi5/result/extensions/progress' => (int) $attempt_no );
		self::enqueue( $s, 'feed', '', '', $user_id );
	}

	/** A finished course whose graded module is below the pass mark: send "failed" once per run. */
	public static function on_evaluate( $result, $user_id, $course_id, $run ) {
		if ( empty( $result['failed'] ) || ! empty( $result['unseen'] ) ) {
			return $result;
		}
		$key  = 'wic_xapi_failed_' . (int) $course_id;
		$sent = (int) get_user_meta( $user_id, $key, true );
		if ( $sent === (int) $run ) {
			return $result;
		}
		update_user_meta( $user_id, $key, (int) $run );
		$score = array( 'scaled' => round( (int) $result['score'] / 100, 2 ), 'raw' => (int) $result['score'], 'min' => 0, 'max' => 100 );
		if ( self::feed_on() ) {
			self::enqueue( self::statement( self::actor( $user_id ), 'failed', self::course_object( $course_id ), array( 'result' => array( 'success' => false, 'score' => $score ) ) ), 'feed', '', '', $user_id );
		}
		$sess = self::cmi5_session( $user_id, $course_id );
		if ( $sess ) {
			self::cmi5_send( $sess, 'failed', array( 'success' => false, 'score' => $score ), true );
		}
		return $result;
	}

	/** POST one statement. Returns true or an error string. */
	private static function post( $row ) {
		if ( 'feed' === $row->target ) {
			$o        = self::opts();
			$endpoint = $o['endpoint'];
			$auth     = 'Basic ' . base64_encode( $o['key'] . ':' . $o['secret'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		} else {
			$endpoint = $row->endpoint;
			$auth     = 'Basic ' . $row->auth;
		}
		if ( ! $endpoint ) {
			return 'No endpoint configured';
		}
		$res = wp_safe_remote_post(
			trailingslashit( $endpoint ) . 'statements',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'             => 'application/json',
					'X-Experience-API-Version' => '1.0.3',
					'Authorization'            => $auth,
				),
				'body'    => $row->statement,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res->get_error_message();
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}
		return 'HTTP ' . $code . ' ' . substr( wp_strip_all_tags( wp_remote_retrieve_body( $res ) ), 0, 200 );
	}

	private static function send_row( $row ) {
		global $wpdb;
		$ok       = self::post( $row );
		$attempts = (int) $row->attempts + 1;
		if ( true === $ok ) {
			$wpdb->update( wic_table( 'xapi_queue' ), array( 'status' => 'sent', 'attempts' => $attempts, 'sent_at' => wic_now(), 'last_error' => '' ), array( 'id' => $row->id ) );
			return true;
		}
		// Backoff: 5, 10, 20, 40 … minutes, capped at a day; give up after MAX_TRY.
		$wait = min( DAY_IN_SECONDS, 300 * pow( 2, $attempts - 1 ) );
		$wpdb->update(
			wic_table( 'xapi_queue' ),
			array(
				'status'     => $attempts >= self::MAX_TRY ? 'failed' : 'queued',
				'attempts'   => $attempts,
				'next_try'   => gmdate( 'Y-m-d H:i:s', time() + (int) $wait ),
				'last_error' => $ok,
			),
			array( 'id' => $row->id )
		);
		return false;
	}

	public static function send_queue() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'xapi_queue' ) . " WHERE status = 'queued' AND next_try <= %s ORDER BY id ASC LIMIT 50", wic_now() ) );
		foreach ( $rows as $r ) {
			if ( 'feed' === $r->target && ! self::feed_on() ) {
				continue;
			}
			self::send_row( $r );
		}
	}

	/* ------------------------------------------------------------------ */
	/* cmi5 launch (#160) — untested against a real LMS                   */
	/* ------------------------------------------------------------------ */

	public static function routes() {
		register_rest_route(
			'wic/v1',
			'/cmi5/launch',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'cmi5_launch' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function launch_url( $course_id ) {
		return add_query_arg( 'course', (int) $course_id, rest_url( 'wic/v1/cmi5/launch' ) );
	}

	private static function host_allowed( $url ) {
		$o     = self::opts();
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$hosts = array_filter( array_map( 'strtolower', array_map( 'trim', preg_split( '/[\s,]+/', (string) $o['cmi5_hosts'] ) ) ) );
		return $host && in_array( $host, $hosts, true ) && 'https' === wp_parse_url( $url, PHP_URL_SCHEME );
	}

	private static function launch_fail( $msg ) {
		wic_audit( 'cmi5_launch_refused', 'course', 0, array( 'reason' => $msg ) );
		wp_die( esc_html( $msg ), esc_html__( 'Course could not be opened', 'wic-tp' ), array( 'response' => 400 ) );
	}

	/**
	 * GET /wic/v1/cmi5/launch?course=ID&endpoint=…&fetch=…&actor=…&registration=…&activityId=…
	 * Only launches from LMS hosts the agency has listed are accepted, the fetch URL must
	 * return a token (proving the launch came from that LMS), and only plain learner
	 * accounts can be opened this way — never supervisors or administrators.
	 */
	public static function cmi5_launch( WP_REST_Request $req ) {
		$o = self::opts();
		if ( ! $o['cmi5_enabled'] ) {
			self::launch_fail( __( 'Launching courses from another system is not switched on for this agency.', 'wic-tp' ) );
		}
		$course_id    = absint( $req['course'] );
		$endpoint     = esc_url_raw( (string) $req['endpoint'] );
		$fetch        = esc_url_raw( (string) $req['fetch'] );
		$registration = sanitize_text_field( (string) $req['registration'] );
		$activity     = esc_url_raw( (string) $req['activityId'] );
		$actor        = json_decode( (string) $req['actor'], true );

		if ( 'wic_course' !== get_post_type( $course_id ) || 'publish' !== get_post_status( $course_id ) ) {
			self::launch_fail( __( 'That course is not available.', 'wic-tp' ) );
		}
		if ( ! $endpoint || ! $fetch || ! $registration || ! is_array( $actor ) ) {
			self::launch_fail( __( 'The launch is missing required cmi5 parameters.', 'wic-tp' ) );
		}
		if ( ! self::host_allowed( $fetch ) || ! self::host_allowed( $endpoint ) ) {
			self::launch_fail( __( 'This system is not on the agency\'s list of trusted learning systems.', 'wic-tp' ) );
		}
		$email = isset( $actor['mbox'] ) ? sanitize_email( preg_replace( '/^mailto:/i', '', $actor['mbox'] ) ) : '';
		if ( ! is_email( $email ) ) {
			self::launch_fail( __( 'The learner must be identified by an email address (actor mbox).', 'wic-tp' ) );
		}

		$res = wp_safe_remote_post( $fetch, array( 'timeout' => 15 ) );
		$tok = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $tok ) || empty( $tok['auth-token'] ) ) {
			self::launch_fail( __( 'The learning system did not confirm this launch.', 'wic-tp' ) );
		}

		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$elevated = user_can( $user, 'wic_view_team' ) || user_can( $user, 'wic_manage_content' ) || user_can( $user, 'manage_options' ) || user_can( $user, 'wic_manage_people' );
			if ( $elevated || 'active' !== wic_user_status( $user->ID ) ) {
				self::launch_fail( __( 'This account cannot be opened from another system. Please sign in to the portal directly.', 'wic-tp' ) );
			}
			$uid = (int) $user->ID;
		} else {
			$name  = isset( $actor['name'] ) ? sanitize_text_field( $actor['name'] ) : '';
			$login = sanitize_user( strtolower( current( explode( '@', $email ) ) ), true );
			$base  = $login ? $login : 'learner';
			$n     = 1;
			while ( ! $login || username_exists( $login ) ) {
				$login = $base . ( ++$n );
			}
			$uid = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 32, true, true ),
					'display_name' => $name ? $name : $email,
					'role'         => 'wic_learner',
				)
			);
			if ( is_wp_error( $uid ) ) {
				self::launch_fail( __( 'The learner account could not be created.', 'wic-tp' ) );
			}
			update_user_meta( $uid, 'wic_status', 'active' );
			update_user_meta( $uid, 'wic_cmi5_provisioned', wic_now() );
			wic_audit( 'cmi5_provision', 'user', $uid, array( 'lms' => wp_parse_url( $fetch, PHP_URL_HOST ) ) );
		}

		if ( ! WIC_Records::active_assignment( $uid, $course_id ) && ! WIC_Records::latest_completion( $uid, $course_id ) ) {
			WIC_Records::assign( $uid, $course_id, 'cmi5' );
		}
		$sess = array(
			'endpoint'     => $endpoint,
			'token'        => sanitize_text_field( $tok['auth-token'] ),
			'registration' => $registration,
			'activity'     => $activity ? $activity : self::course_iri( $course_id ),
			'actor'        => $actor,
			'session'      => wp_generate_uuid4(),
			'started'      => time(),
			'course'       => $course_id,
		);
		update_user_meta( $uid, 'wic_cmi5_' . $course_id, $sess );
		wic_audit( 'cmi5_launch', 'course', $course_id, array( 'user' => $uid, 'lms' => wp_parse_url( $fetch, PHP_URL_HOST ) ) );

		wp_set_current_user( $uid );
		wp_set_auth_cookie( $uid, false );
		self::cmi5_send( $sess, 'initialized', null, false );

		wp_redirect( wic_page_url( 'learn', array( 'course' => $course_id ) ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- own page.
		exit;
	}

	public static function cmi5_session( $user_id, $course_id ) {
		$s = get_user_meta( $user_id, 'wic_cmi5_' . (int) $course_id, true );
		return is_array( $s ) && ! empty( $s['endpoint'] ) ? $s : null;
	}

	/** Build and send a cmi5-defined statement to the launching LMS. */
	public static function cmi5_send( $sess, $verb, $result = null, $moveon = false ) {
		$cats = array( array( 'id' => self::CMI5_CAT ) );
		if ( $moveon ) {
			$cats[] = array( 'id' => self::MOVEON );
		}
		$s = array(
			'id'        => wp_generate_uuid4(),
			'actor'     => $sess['actor'],
			'verb'      => self::verb( $verb ),
			'object'    => array( 'id' => $sess['activity'], 'objectType' => 'Activity' ),
			'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'context'   => array(
				'registration'      => $sess['registration'],
				'contextActivities' => array( 'category' => $cats ),
				'extensions'        => array( self::SESSION => $sess['session'] ),
			),
		);
		if ( $result ) {
			$s['result'] = $result;
		}
		$id  = self::enqueue( $s, 'cmi5', $sess['endpoint'], $sess['token'] );
		$row = $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( 'SELECT * FROM ' . wic_table( 'xapi_queue' ) . ' WHERE id = %d', $id ) );
		if ( $row ) {
			self::send_row( $row ); // The LMS expects these promptly; the queue retries if it fails.
		}
	}

	public static function cmi5_on_completed( $completion ) {
		$sess = self::cmi5_session( $completion->user_id, $completion->course_id );
		if ( ! $sess ) {
			return;
		}
		$dur   = self::duration( time() - (int) $sess['started'] );
		$score = array( 'scaled' => round( (int) $completion->score / 100, 2 ), 'raw' => (int) $completion->score, 'min' => 0, 'max' => 100 );
		self::cmi5_send( $sess, 'completed', array( 'completion' => true, 'duration' => $dur ), true );
		self::cmi5_send( $sess, 'passed', array( 'success' => true, 'score' => $score, 'duration' => $dur ), true );
		self::cmi5_send( $sess, 'terminated', array( 'duration' => $dur ), false );
		delete_user_meta( $completion->user_id, 'wic_cmi5_' . (int) $completion->course_id );
	}

	/* ------------------------------------------------------------------ */
	/* Exports (#156)                                                     */
	/* ------------------------------------------------------------------ */

	private static function x( $s ) {
		return htmlspecialchars( (string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	public static function cmi5_xml( $course_ids ) {
		$lang = str_replace( '_', '-', get_locale() );
		$xml  = '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<courseStructure xmlns="https://w3id.org/xapi/profiles/cmi5/v1/CourseStructure.xsd">' . "\n";
		$ids  = array_values( $course_ids );
		$main = count( $ids ) === 1 ? get_the_title( $ids[0] ) : wic_setting( 'name' ) . ' — ' . __( 'courses', 'wic-tp' );
		$xml .= '  <course id="' . self::x( home_url( '/wic/package/' . md5( implode( ',', $ids ) ) ) ) . '">' . "\n";
		$xml .= '    <title><langstring lang="' . self::x( $lang ) . '">' . self::x( wp_strip_all_tags( $main ) ) . '</langstring></title>' . "\n";
		$xml .= '    <description><langstring lang="' . self::x( $lang ) . '">' . self::x( wic_setting( 'name' ) ) . '</langstring></description>' . "\n";
		$xml .= "  </course>\n";
		foreach ( $ids as $cid ) {
			$post    = get_post( $cid );
			$mastery = round( WIC_Boost_Pass::mark( $cid ) / 100, 2 );
			$xml    .= '  <au id="' . self::x( self::course_iri( $cid ) ) . '" moveOn="CompletedAndPassed" masteryScore="' . self::x( $mastery ) . '" launchMethod="OwnWindow">' . "\n";
			$xml    .= '    <title><langstring lang="' . self::x( $lang ) . '">' . self::x( wp_strip_all_tags( $post->post_title ) ) . '</langstring></title>' . "\n";
			$xml    .= '    <description><langstring lang="' . self::x( $lang ) . '">' . self::x( wp_trim_words( wp_strip_all_tags( $post->post_content ), 40 ) ) . '</langstring></description>' . "\n";
			$xml    .= '    <url>' . self::x( self::launch_url( $cid ) ) . '</url>' . "\n";
			$xml    .= "  </au>\n";
		}
		return $xml . "</courseStructure>\n";
	}

	/** Build a zip holding the given files (name => contents) and stream it. */
	public static function send_zip( $filename, $files ) {
		$tmp = wp_tempnam( 'wic-zip' );
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			$zip->open( $tmp, ZipArchive::OVERWRITE );
			foreach ( $files as $name => $data ) {
				$zip->addFromString( $name, $data );
			}
			$zip->close();
		} else {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
			$dir = trailingslashit( get_temp_dir() ) . 'wic-zip-' . wp_generate_password( 8, false );
			wp_mkdir_p( $dir );
			$paths = array();
			foreach ( $files as $name => $data ) {
				file_put_contents( $dir . '/' . $name, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$paths[] = $dir . '/' . $name;
			}
			@unlink( $tmp ); // phpcs:ignore
			$zip = new PclZip( $tmp );
			$zip->create( $paths, PCLZIP_OPT_REMOVE_PATH, $dir );
			foreach ( $paths as $p ) {
				@unlink( $p ); // phpcs:ignore
			}
			@rmdir( $dir ); // phpcs:ignore
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		@unlink( $tmp ); // phpcs:ignore
		exit;
	}

	private static function guard( $nonce, $cap = 'wic_manage_settings' ) {
		check_admin_referer( $nonce );
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
		}
	}

	public static function cmi5_export() {
		self::guard( 'wic_cmi5_export' );
		$ids = isset( $_POST['courses'] ) ? array_filter( array_map( 'absint', (array) $_POST['courses'] ) ) : array();
		$ids = array_values(
			array_filter(
				$ids,
				function ( $id ) {
					return 'wic_course' === get_post_type( $id ) && 'publish' === get_post_status( $id );
				}
			)
		);
		if ( ! $ids ) {
			WIC_Portal::back( 'data_exchange', 'err_xapi_courses' );
		}
		$format = isset( $_POST['format'] ) ? sanitize_key( $_POST['format'] ) : 'cmi5';
		wic_audit( 'export', 'course', $ids[0], array( 'format' => $format, 'courses' => $ids ) );
		if ( 'package' === $format && self::package_exporter() ) {
			$files = array();
			foreach ( $ids as $id ) {
				$files[ sanitize_title( get_the_title( $id ) ) . '-' . $id . '.json' ] = wp_json_encode( call_user_func( self::package_exporter(), $id ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
			self::send_zip( 'courses-' . gmdate( 'Y-m-d' ) . '.zip', $files );
		}
		$name = count( $ids ) === 1 ? sanitize_title( get_the_title( $ids[0] ) ) : 'courses';
		self::send_zip( $name . '-cmi5.zip', array( 'cmi5.xml' => self::cmi5_xml( $ids ) ) );
	}

	/**
	 * The JSON course package exporter from the authoring module, when it exists.
	 * Returns a callable taking a course ID and returning the package array, or null.
	 */
	public static function package_exporter() {
		$candidates = apply_filters(
			'wic_course_package_exporter',
			array(
				array( 'WIC_Course_Package', 'export' ),
				array( 'WIC_Authoring_Package', 'export' ),
				array( 'WIC_Package', 'export' ),
				array( 'WIC_Authoring', 'export_package' ),
			)
		);
		if ( is_callable( $candidates ) ) {
			return $candidates;
		}
		foreach ( (array) $candidates as $c ) {
			if ( is_array( $c ) && class_exists( $c[0] ) && is_callable( $c ) ) {
				return $c;
			}
		}
		return null;
	}

	/** Results as xAPI statements (JSON), for the viewer's scope and a date range. */
	public static function xapi_results() {
		global $wpdb;
		self::guard( 'wic_xapi_results', 'wic_view_all' );
		$from = isset( $_POST['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_POST['from'] ) ? sanitize_text_field( $_POST['from'] ) . ' 00:00:00' : '1970-01-01 00:00:00';
		$to   = isset( $_POST['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_POST['to'] ) ? sanitize_text_field( $_POST['to'] ) . ' 23:59:59' : '9999-12-31 23:59:59';
		$ids  = wic_scope_user_ids( get_current_user_id() );
		$out  = array();
		if ( $ids ) {
			$in   = implode( ',', array_map( 'intval', $ids ) );
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'completions' ) . " WHERE user_id IN ($in) AND completed_at BETWEEN %s AND %s ORDER BY completed_at ASC", get_gmt_from_date( $from ), get_gmt_from_date( $to ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- integer list.
			foreach ( $rows as $c ) {
				foreach ( self::completion_statements( $c ) as $s ) {
					$out[] = $s;
				}
			}
		}
		wic_audit( 'export', 'report', 0, array( 'format' => 'xapi', 'count' => count( $out ) ) );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="xapi-statements-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Settings and status                                                */
	/* ------------------------------------------------------------------ */

	public static function xapi_save() {
		self::guard( 'wic_xapi_save' );
		$o                 = self::opts();
		$o['enabled']      = empty( $_POST['enabled'] ) ? 0 : 1;
		$o['answers']      = empty( $_POST['answers'] ) ? 0 : 1;
		$o['endpoint']     = isset( $_POST['endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint'] ) ) : '';
		$o['key']          = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$o['cmi5_enabled'] = empty( $_POST['cmi5_enabled'] ) ? 0 : 1;
		$o['cmi5_hosts']   = isset( $_POST['cmi5_hosts'] ) ? sanitize_text_field( wp_unslash( $_POST['cmi5_hosts'] ) ) : '';
		// The secret is write-only: blank keeps the stored one, "clear" removes it.
		if ( ! empty( $_POST['clear_secret'] ) ) {
			$o['secret'] = '';
		} elseif ( isset( $_POST['secret'] ) && '' !== $_POST['secret'] ) {
			$o['secret'] = sanitize_text_field( wp_unslash( $_POST['secret'] ) );
		}
		update_option( self::OPT, $o, false );
		wic_audit( 'xapi_settings', 'settings', 0, array( 'enabled' => $o['enabled'], 'cmi5' => $o['cmi5_enabled'] ) );
		WIC_Portal::back( 'data_exchange', 'saved' );
	}

	public static function xapi_retry() {
		global $wpdb;
		self::guard( 'wic_xapi_retry' );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . wic_table( 'xapi_queue' ) . " SET status = 'queued', attempts = 0, next_try = %s WHERE status = 'failed'", wic_now() ) );
		WIC_Portal::back( 'data_exchange', 'xapi_retried' );
	}

	public static function xapi_send_now() {
		self::guard( 'wic_xapi_send_now' );
		self::send_queue();
		WIC_Portal::back( 'data_exchange', 'xapi_sent' );
	}

	public static function messages( $m ) {
		$m['xapi_retried']     = __( 'Failed statements have been queued again.', 'wic-tp' );
		$m['xapi_sent']        = __( 'The queue has been processed. See the status below.', 'wic-tp' );
		$m['err_xapi_courses'] = __( 'Choose at least one published course.', 'wic-tp' );
		return $m;
	}

	public static function views( $views ) {
		$views['data_exchange'] = array(
			'label'    => __( 'Data exchange', 'wic-tp' ),
			'group'    => 'agency',
			'cap'      => 'wic_manage_settings',
			'callback' => array( __CLASS__, 'view' ),
			'order'    => 80,
		);
		return $views;
	}

	public static function view( $uid ) {
		global $wpdb;
		$o       = self::opts();
		$counts  = array();
		foreach ( $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . wic_table( 'xapi_queue' ) . ' GROUP BY status' ) as $r ) {
			$counts[ $r->status ] = (int) $r->n;
		}
		$errors  = $wpdb->get_results( 'SELECT id, verb, target, attempts, last_error, next_try, status FROM ' . wic_table( 'xapi_queue' ) . " WHERE last_error IS NOT NULL AND last_error <> '' AND status <> 'sent' ORDER BY id DESC LIMIT 10" );
		$courses = get_posts( array( 'post_type' => 'wic_course', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$post    = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Data exchange', 'wic-tp' ); ?></h2>
		<p class="wic-meta"><?php esc_html_e( 'Send training records to the agency\'s own Learning Record Store, export courses in the cmi5 standard, and let another learning system open these courses.', 'wic-tp' ); ?></p>

		<section class="wic-section wic-two">
			<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="wic-form wic-panel">
				<h3 class="wic-h3"><?php esc_html_e( 'xAPI feed to your Learning Record Store', 'wic-tp' ); ?></h3>
				<input type="hidden" name="action" value="wic_xapi_save">
				<?php wp_nonce_field( 'wic_xapi_save' ); ?>
				<label><input type="checkbox" name="enabled" value="1" <?php checked( (int) $o['enabled'], 1 ); ?>> <?php esc_html_e( 'Send completions, passes and failures', 'wic-tp' ); ?></label>
				<label><input type="checkbox" name="answers" value="1" <?php checked( (int) $o['answers'], 1 ); ?>> <?php esc_html_e( 'Also send every question answer', 'wic-tp' ); ?></label>
				<div class="wic-field">
					<label for="wic-x-end"><?php esc_html_e( 'LRS endpoint', 'wic-tp' ); ?></label>
					<input type="url" id="wic-x-end" name="endpoint" value="<?php echo esc_attr( $o['endpoint'] ); ?>" placeholder="https://lrs.example.org/xapi/">
				</div>
				<div class="wic-field">
					<label for="wic-x-key"><?php esc_html_e( 'Key (username)', 'wic-tp' ); ?></label>
					<input type="text" id="wic-x-key" name="key" value="<?php echo esc_attr( $o['key'] ); ?>" autocomplete="off">
				</div>
				<div class="wic-field">
					<label for="wic-x-sec"><?php esc_html_e( 'Secret', 'wic-tp' ); ?></label>
					<input type="password" id="wic-x-sec" name="secret" value="" autocomplete="new-password" aria-describedby="wic-x-sec-h">
					<p class="wic-help" id="wic-x-sec-h"><?php echo $o['secret'] ? esc_html__( 'A secret is stored. It is never shown again; leave this empty to keep it.', 'wic-tp' ) : esc_html__( 'No secret stored yet.', 'wic-tp' ); ?></p>
					<?php if ( $o['secret'] ) : ?>
						<label><input type="checkbox" name="clear_secret" value="1"> <?php esc_html_e( 'Remove the stored secret', 'wic-tp' ); ?></label>
					<?php endif; ?>
				</div>
				<h3 class="wic-h3"><?php esc_html_e( 'Opening courses from another learning system (cmi5)', 'wic-tp' ); ?></h3>
				<label><input type="checkbox" name="cmi5_enabled" value="1" <?php checked( (int) $o['cmi5_enabled'], 1 ); ?>> <?php esc_html_e( 'Accept cmi5 launches', 'wic-tp' ); ?></label>
				<div class="wic-field">
					<label for="wic-x-hosts"><?php esc_html_e( 'Trusted learning system hosts', 'wic-tp' ); ?></label>
					<input type="text" id="wic-x-hosts" name="cmi5_hosts" value="<?php echo esc_attr( $o['cmi5_hosts'] ); ?>" placeholder="lms.example.gov, lrs.example.gov" aria-describedby="wic-x-hosts-h">
					<p class="wic-help" id="wic-x-hosts-h"><?php esc_html_e( 'Host names, comma separated. Launches from anywhere else are refused, and supervisor or administrator accounts can never be opened this way. This launch has not yet been tested with a real LMS — test it end to end before relying on it.', 'wic-tp' ); ?></p>
				</div>
				<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Save', 'wic-tp' ); ?></button>
			</form>

			<div class="wic-panel">
				<h3 class="wic-h3"><?php esc_html_e( 'Send queue', 'wic-tp' ); ?></h3>
				<dl class="wic-dl">
					<dt><?php esc_html_e( 'Waiting', 'wic-tp' ); ?></dt><dd><?php echo isset( $counts['queued'] ) ? (int) $counts['queued'] : 0; ?></dd>
					<dt><?php esc_html_e( 'Sent', 'wic-tp' ); ?></dt><dd><?php echo isset( $counts['sent'] ) ? (int) $counts['sent'] : 0; ?></dd>
					<dt><?php esc_html_e( 'Failed (gave up)', 'wic-tp' ); ?></dt><dd><?php echo isset( $counts['failed'] ) ? (int) $counts['failed'] : 0; ?></dd>
				</dl>
				<p class="wic-help"><?php esc_html_e( 'The queue is sent every hour. A failed send is retried after 5, 10, 20… minutes, up to 10 times.', 'wic-tp' ); ?></p>
				<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="wic-inline-form">
					<input type="hidden" name="action" value="wic_xapi_send_now"><?php wp_nonce_field( 'wic_xapi_send_now' ); ?>
					<button type="submit" class="wic-btn wic-btn--small"><?php esc_html_e( 'Send now', 'wic-tp' ); ?></button>
				</form>
				<?php if ( ! empty( $counts['failed'] ) ) : ?>
					<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="wic-inline-form">
						<input type="hidden" name="action" value="wic_xapi_retry"><?php wp_nonce_field( 'wic_xapi_retry' ); ?>
						<button type="submit" class="wic-btn wic-btn--small"><?php esc_html_e( 'Retry failed', 'wic-tp' ); ?></button>
					</form>
				<?php endif; ?>
				<?php if ( $errors ) : ?>
					<h4><?php esc_html_e( 'Recent errors', 'wic-tp' ); ?></h4>
					<ul class="wic-list">
						<?php foreach ( $errors as $e ) : ?>
							<li><span><code><?php echo esc_html( $e->verb ); ?></code> (<?php echo esc_html( $e->target ); ?>, <?php echo esc_html( sprintf( _n( '%d attempt', '%d attempts', (int) $e->attempts, 'wic-tp' ), (int) $e->attempts ) ); ?>): <?php echo esc_html( $e->last_error ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</section>

		<section class="wic-section wic-two">
			<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="wic-form wic-panel">
				<h3 class="wic-h3"><?php esc_html_e( 'Export courses', 'wic-tp' ); ?></h3>
				<input type="hidden" name="action" value="wic_cmi5_export">
				<?php wp_nonce_field( 'wic_cmi5_export' ); ?>
				<fieldset class="wic-field">
					<legend><?php esc_html_e( 'Courses', 'wic-tp' ); ?></legend>
					<?php foreach ( $courses as $c ) : ?>
						<label><input type="checkbox" name="courses[]" value="<?php echo (int) $c->ID; ?>"> <?php echo esc_html( $c->post_title ); ?></label>
					<?php endforeach; ?>
					<?php if ( ! $courses ) : ?><p class="wic-help"><?php esc_html_e( 'No published courses yet.', 'wic-tp' ); ?></p><?php endif; ?>
				</fieldset>
				<div class="wic-field">
					<label for="wic-x-fmt"><?php esc_html_e( 'Format', 'wic-tp' ); ?></label>
					<select id="wic-x-fmt" name="format">
						<option value="cmi5"><?php esc_html_e( 'cmi5 package (opens here from another LMS)', 'wic-tp' ); ?></option>
						<?php if ( self::package_exporter() ) : ?>
							<option value="package"><?php esc_html_e( 'Full course content (JSON package)', 'wic-tp' ); ?></option>
						<?php endif; ?>
					</select>
				</div>
				<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Download', 'wic-tp' ); ?></button>
			</form>

			<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="wic-form wic-panel">
				<h3 class="wic-h3"><?php esc_html_e( 'Export results as xAPI statements', 'wic-tp' ); ?></h3>
				<input type="hidden" name="action" value="wic_xapi_results">
				<?php wp_nonce_field( 'wic_xapi_results' ); ?>
				<div class="wic-field"><label for="wic-x-from"><?php esc_html_e( 'Completed from', 'wic-tp' ); ?></label><input type="date" id="wic-x-from" name="from"></div>
				<div class="wic-field"><label for="wic-x-to"><?php esc_html_e( 'Completed to', 'wic-tp' ); ?></label><input type="date" id="wic-x-to" name="to"></div>
				<p class="wic-help"><?php esc_html_e( 'Leave both empty for every completion. One "completed" and one "passed" statement per completion.', 'wic-tp' ); ?></p>
				<button type="submit" class="wic-btn"><?php esc_html_e( 'Download JSON', 'wic-tp' ); ?></button>
			</form>
		</section>
		<?php
	}
}

/** The course pass mark, shared by exports without depending on the refresher module loading first. */
class WIC_Boost_Pass {
	public static function mark( $course_id ) {
		$c = get_post_meta( $course_id, '_wic_pass_mark', true );
		return '' !== $c ? (int) $c : (int) wic_setting( 'pass_mark' );
	}
}

add_action( 'wic_init', array( 'WIC_Xapi', 'init' ) );
