<?php
/**
 * A person's record moving with them between agencies (#161). DECISION FIRST.
 *
 * When `decision_portable_record` is on, a learner can download a signed transcript: a JSON
 * document listing their completions, signed with HMAC-SHA256 using a secret held only by
 * this site. Anyone can ask this site whether a transcript is genuine at the public verify
 * endpoint. An agency receiving one uploads it; the issuing site's verify endpoint is asked,
 * and only a genuine transcript is recorded — as outside-training entries, never as the
 * receiving agency's own completions.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_table_sql',
	function ( $sql, $c ) {
		$sql[] = 'CREATE TABLE ' . wic_table( 'portable_imports' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  issuer_name varchar(200) NOT NULL DEFAULT '',
  issuer_url varchar(255) NOT NULL DEFAULT '',
  course_title varchar(255) NOT NULL DEFAULT '',
  completed_at datetime NULL,
  score varchar(10) NOT NULL DEFAULT '',
  hours varchar(10) NOT NULL DEFAULT '',
  credit_type varchar(100) NOT NULL DEFAULT '',
  cert_number varchar(40) NOT NULL DEFAULT '',
  verify_code varchar(20) NOT NULL DEFAULT '',
  imported_by bigint(20) unsigned NOT NULL DEFAULT 0,
  imported_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id)
) $c;";
		return $sql;
	},
	10,
	2
);

add_filter(
	'wic_agency_defaults',
	function ( $d ) {
		$d['decision_portable_record'] = 0;
		return $d;
	}
);

add_filter(
	'wic_agency_fields',
	function ( $f ) {
		$f['decision_portable_record'] = array( __( 'Portable training record between agencies', 'wic-tp' ), 'checkbox', __( 'Needs agreement beyond any one agency: which agencies accept each other\'s signed transcripts, and how imported training counts. When on, learners can download a signed transcript and administrators can import one.', 'wic-tp' ) );
		return $f;
	}
);

class WIC_Transcript {

	const FORMAT = 'wic-transcript/1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		add_filter( 'wic_flash_messages', array( __CLASS__, 'messages' ) );
		add_action( 'admin_post_wic_transcript_download', array( __CLASS__, 'download' ) );
		add_action( 'admin_post_wic_transcript_import', array( __CLASS__, 'import' ) );
		add_action( 'wic_person_record_sections', array( __CLASS__, 'person_section' ), 60, 2 );
		add_filter( 'wic_record_external_training', array( __CLASS__, 'to_outside_training' ), 10, 3 );
	}

	/**
	 * Imported entries also go on the outside-training register when that module is present,
	 * already approved (the issuing agency has vouched for them), so transcripts and reports
	 * include them without a second review.
	 */
	public static function to_outside_training( $done, $user_id, $entry ) {
		global $wpdb;
		if ( $done || ! class_exists( 'WIC_External' ) ) {
			return $done;
		}
		$date = substr( $entry['completed_at'], 0, 10 );
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . wic_table( 'external' ) . ' WHERE user_id = %d AND title = %s AND completed_on = %s LIMIT 1', $user_id, $entry['course_title'], $date ) ) ) {
			return true;
		}
		$wpdb->insert(
			wic_table( 'external' ),
			array(
				'user_id'      => $user_id,
				'title'        => $entry['course_title'],
				'provider'     => $entry['issuer_name'],
				'hours'        => max( 0, (float) $entry['hours'] ),
				'credit_type'  => $entry['credit_type'],
				'completed_on' => $date,
				'status'       => 'approved',
				'source'       => 'transfer',
				'reviewed_by'  => get_current_user_id(),
				'reviewed_at'  => wic_now(),
				'review_note'  => $entry['cert_number'] ? sprintf( 'Signed transcript, certificate %s', $entry['cert_number'] ) : 'Signed transcript',
				'created_at'   => wic_now(),
			)
		);
		return true;
	}

	public static function on() {
		return (bool) (int) wic_setting( 'decision_portable_record' );
	}

	private static function secret() {
		$s = get_option( 'wic_transcript_secret' );
		if ( ! $s ) {
			$s = wp_generate_password( 64, true, true );
			update_option( 'wic_transcript_secret', $s, false );
		}
		return $s;
	}

	public static function verify_url() {
		return rest_url( 'wic/v1/transcript/verify' );
	}

	/** Canonical form: the document without its signature, encoded the same way every time. */
	private static function canonical( $doc ) {
		unset( $doc['signature'] );
		return wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	public static function sign( $doc ) {
		$doc['signature'] = array(
			'alg'   => 'HS256',
			'value' => hash_hmac( 'sha256', self::canonical( $doc ), self::secret() ),
		);
		return $doc;
	}

	public static function is_genuine( $doc ) {
		if ( ! is_array( $doc ) || empty( $doc['signature']['value'] ) || self::FORMAT !== ( isset( $doc['format'] ) ? $doc['format'] : '' ) ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', self::canonical( $doc ), self::secret() ), (string) $doc['signature']['value'] );
	}

	/** Every value is a string so the document re-encodes byte for byte on any site. */
	public static function build( $user_id ) {
		global $wpdb;
		$u       = get_userdata( $user_id );
		$entries = array();
		$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'completions' ) . ' WHERE user_id = %d ORDER BY completed_at ASC', $user_id ) );
		foreach ( $rows as $c ) {
			$cert      = WIC_Certificates::for_completion( $c->id );
			$entries[] = array(
				'course'       => (string) ( $cert ? $cert->course_title : get_the_title( $c->course_id ) ),
				'completed_at' => (string) gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $c->completed_at . ' UTC' ) ),
				'score'        => (string) (int) $c->score,
				'hours'        => (string) ( $cert ? $cert->hours : WIC_Content::course_hours( $c->course_id ) ),
				'credit_type'  => (string) ( $cert ? $cert->credit_type : get_post_meta( $c->course_id, '_wic_credit_type', true ) ),
				'certificate'  => (string) ( $cert ? $cert->cert_number : '' ),
				'verify_code'  => (string) ( $cert ? $cert->verify_code : '' ),
			);
		}
		return self::sign(
			array(
				'format'    => self::FORMAT,
				'issuer'    => array(
					'name'       => (string) wic_setting( 'name' ),
					'url'        => (string) home_url( '/' ),
					'verify_url' => (string) self::verify_url(),
				),
				'person'    => array(
					'name'  => (string) $u->display_name,
					'email' => (string) $u->user_email,
				),
				'issued_at' => (string) gmdate( 'Y-m-d\TH:i:s\Z' ),
				'entries'   => $entries,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Public verify endpoint                                             */
	/* ------------------------------------------------------------------ */

	public static function routes() {
		register_rest_route(
			'wic/v1',
			'/transcript/verify',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_verify' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/** POST the whole transcript JSON as the request body. Answers only genuine or not. */
	public static function rest_verify( WP_REST_Request $req ) {
		$doc     = json_decode( $req->get_body(), true );
		$genuine = self::is_genuine( $doc );
		wic_audit( 'transcript_verify', 'transcript', 0, array( 'genuine' => $genuine ) );
		return array(
			'genuine'   => $genuine,
			'issuer'    => wic_setting( 'name' ),
			'issued_at' => $genuine ? $doc['issued_at'] : null,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Portal                                                             */
	/* ------------------------------------------------------------------ */

	public static function views( $views ) {
		if ( ! self::on() ) {
			return $views;
		}
		$views['my_record']   = array(
			'label'    => __( 'Portable record', 'wic-tp' ),
			'group'    => 'learn',
			'cap'      => 'wic_learn',
			'callback' => array( __CLASS__, 'view_mine' ),
			'order'    => 35,
		);
		$views['transcripts'] = array(
			'label'    => __( 'Import a transcript', 'wic-tp' ),
			'group'    => 'agency',
			'cap'      => 'wic_manage_people',
			'callback' => array( __CLASS__, 'view_import' ),
			'order'    => 70,
		);
		return $views;
	}

	public static function messages( $m ) {
		$m['transcript_imported'] = __( 'Transcript verified with the issuing agency and recorded as outside training.', 'wic-tp' );
		$m['err_transcript_file'] = __( 'That file is not a transcript from this platform.', 'wic-tp' );
		$m['err_transcript_fake'] = __( 'The issuing agency did not confirm this transcript. Nothing was recorded.', 'wic-tp' );
		$m['err_transcript_who']  = __( 'The transcript belongs to a different email address. Confirm it is the same person to import it.', 'wic-tp' );
		$m['err_transcript_off']  = __( 'Portable records are not switched on.', 'wic-tp' );
		return $m;
	}

	public static function imports( $user_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'portable_imports' ) . ' WHERE user_id = %d ORDER BY completed_at DESC', $user_id ) );
	}

	public static function view_mine( $uid ) {
		$doc = self::build( $uid );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Portable training record', 'wic-tp' ); ?></h2>
		<p class="wic-meta"><?php esc_html_e( 'A signed copy of your completed training that you can give to another agency. They can check with us that it is genuine and has not been changed.', 'wic-tp' ); ?></p>
		<?php if ( ! $doc['entries'] ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'You have no completed training to include yet.', 'wic-tp' ); ?></p></div>
		<?php else : ?>
			<div class="wic-table-wrap">
			<table class="wic-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Course', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Completed', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Score', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Hours', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Certificate', 'wic-tp' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $doc['entries'] as $e ) : ?>
					<tr><td><?php echo esc_html( $e['course'] ); ?></td><td><?php echo esc_html( wic_format_date( gmdate( 'Y-m-d H:i:s', strtotime( $e['completed_at'] ) ) ) ); ?></td><td><?php echo esc_html( $e['score'] ); ?>%</td><td><?php echo esc_html( $e['hours'] ); ?></td><td><code><?php echo esc_html( $e['certificate'] ); ?></code></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<p><a class="wic-btn wic-btn--primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wic_transcript_download' ), 'wic_transcript_download' ) ); ?>"><?php esc_html_e( 'Download signed transcript', 'wic-tp' ); ?></a></p>
		<?php endif; ?>
		<?php
		$imported = self::imports( $uid );
		if ( $imported ) {
			echo '<h3 class="wic-h3">' . esc_html__( 'Training brought from another agency', 'wic-tp' ) . '</h3>';
			self::imports_table( $imported );
		}
	}

	private static function imports_table( $rows ) {
		?>
		<div class="wic-table-wrap">
		<table class="wic-table">
			<thead><tr><th scope="col"><?php esc_html_e( 'Course', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'From', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Completed', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Score', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Hours', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Certificate', 'wic-tp' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<tr><td><?php echo esc_html( $r->course_title ); ?></td><td><?php echo esc_html( $r->issuer_name ); ?></td><td><?php echo esc_html( wic_format_date( $r->completed_at ) ); ?></td><td><?php echo esc_html( $r->score ); ?>%</td><td><?php echo esc_html( $r->hours ); ?></td><td><code><?php echo esc_html( $r->cert_number ); ?></code></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	public static function download() {
		check_admin_referer( 'wic_transcript_download' );
		if ( ! self::on() || ! current_user_can( 'wic_learn' ) ) {
			wp_die( esc_html__( 'Portable records are not switched on.', 'wic-tp' ), '', array( 'response' => 403 ) );
		}
		$uid = get_current_user_id();
		$doc = self::build( $uid );
		wic_audit( 'transcript_download', 'user', $uid, array( 'entries' => count( $doc['entries'] ) ) );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="training-transcript-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public static function view_import( $uid ) {
		$people = array();
		foreach ( wic_scope_user_ids( $uid ) as $id ) {
			$u = get_userdata( $id );
			if ( $u && (int) $id !== (int) $uid ) {
				$people[ $id ] = $u->display_name . ' — ' . $u->user_email;
			}
		}
		asort( $people );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Import a transcript from another agency', 'wic-tp' ); ?></h2>
		<p class="wic-meta"><?php esc_html_e( 'Upload the signed transcript a new member of staff brought with them. It is checked with the agency that issued it; only a genuine, unaltered transcript is recorded, as outside training on their record.', 'wic-tp' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form wic-panel" enctype="multipart/form-data">
			<input type="hidden" name="action" value="wic_transcript_import">
			<?php wp_nonce_field( 'wic_transcript_import' ); ?>
			<div class="wic-field">
				<label for="wic-tr-person"><?php esc_html_e( 'Person', 'wic-tp' ); ?></label>
				<select id="wic-tr-person" name="person" required>
					<option value=""><?php esc_html_e( 'Choose a person', 'wic-tp' ); ?></option>
					<?php foreach ( $people as $id => $label ) : ?>
						<option value="<?php echo (int) $id; ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="wic-field">
				<label for="wic-tr-file"><?php esc_html_e( 'Transcript file (.json)', 'wic-tp' ); ?></label>
				<input type="file" id="wic-tr-file" name="transcript" accept="application/json,.json" required>
			</div>
			<label><input type="checkbox" name="confirm_person" value="1"> <?php esc_html_e( 'I have checked this is the same person, even if the email address differs', 'wic-tp' ); ?></label>
			<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Verify and import', 'wic-tp' ); ?></button>
		</form>
		<?php
	}

	/** Ask the issuing site whether the transcript is genuine. */
	private static function verify_with_issuer( $doc ) {
		$url = isset( $doc['issuer']['verify_url'] ) ? esc_url_raw( $doc['issuer']['verify_url'] ) : '';
		if ( ! $url ) {
			return false;
		}
		if ( untrailingslashit( $url ) === untrailingslashit( self::verify_url() ) ) {
			return self::is_genuine( $doc );
		}
		$res = wp_safe_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return false;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		return is_array( $body ) && true === ( isset( $body['genuine'] ) ? $body['genuine'] : false );
	}

	public static function import() {
		global $wpdb;
		check_admin_referer( 'wic_transcript_import' );
		if ( ! current_user_can( 'wic_manage_people' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
		}
		if ( ! self::on() ) {
			WIC_Portal::back( 'transcripts', 'err_transcript_off' );
		}
		$person = isset( $_POST['person'] ) ? absint( $_POST['person'] ) : 0;
		if ( ! $person || ! wic_can_see_user( get_current_user_id(), $person ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
		}
		$file = isset( $_FILES['transcript']['tmp_name'] ) ? $_FILES['transcript']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $file || ! is_uploaded_file( $file ) || filesize( $file ) > MB_IN_BYTES ) {
			WIC_Portal::back( 'transcripts', 'err_transcript_file' );
		}
		$doc = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $doc ) || self::FORMAT !== ( isset( $doc['format'] ) ? $doc['format'] : '' ) || empty( $doc['entries'] ) || ! is_array( $doc['entries'] ) ) {
			WIC_Portal::back( 'transcripts', 'err_transcript_file' );
		}
		$user = get_userdata( $person );
		if ( strtolower( (string) $doc['person']['email'] ) !== strtolower( $user->user_email ) && empty( $_POST['confirm_person'] ) ) {
			WIC_Portal::back( 'transcripts', 'err_transcript_who' );
		}
		if ( ! self::verify_with_issuer( $doc ) ) {
			wic_audit( 'transcript_import_refused', 'user', $person, array( 'issuer' => isset( $doc['issuer']['url'] ) ? $doc['issuer']['url'] : '' ) );
			WIC_Portal::back( 'transcripts', 'err_transcript_fake' );
		}
		$issuer = sanitize_text_field( $doc['issuer']['name'] );
		$added  = 0;
		foreach ( $doc['entries'] as $e ) {
			$entry = array(
				'user_id'      => $person,
				'issuer_name'  => $issuer,
				'issuer_url'   => esc_url_raw( $doc['issuer']['url'] ),
				'course_title' => sanitize_text_field( $e['course'] ),
				'completed_at' => gmdate( 'Y-m-d H:i:s', strtotime( $e['completed_at'] ) ),
				'score'        => substr( sanitize_text_field( $e['score'] ), 0, 10 ),
				'hours'        => substr( sanitize_text_field( $e['hours'] ), 0, 10 ),
				'credit_type'  => sanitize_text_field( $e['credit_type'] ),
				'cert_number'  => sanitize_text_field( $e['certificate'] ),
				'verify_code'  => sanitize_text_field( $e['verify_code'] ),
				'imported_by'  => get_current_user_id(),
				'imported_at'  => wic_now(),
			);
			$dupe = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . wic_table( 'portable_imports' ) . ' WHERE user_id = %d AND issuer_url = %s AND cert_number = %s AND course_title = %s AND completed_at = %s', $person, $entry['issuer_url'], $entry['cert_number'], $entry['course_title'], $entry['completed_at'] ) );
			if ( $dupe ) {
				continue;
			}
			$wpdb->insert( wic_table( 'portable_imports' ), $entry );
			++$added;
			/**
			 * The outside-training module may record the entry on its own register too.
			 * Filter returns true once it has done so.
			 */
			apply_filters( 'wic_record_external_training', false, $person, $entry );
		}
		wic_audit( 'transcript_import', 'user', $person, array( 'issuer' => $entry['issuer_url'], 'added' => $added ) );
		WIC_Portal::back( 'transcripts', 'transcript_imported' );
	}

	public static function person_section( $user_id, $viewer_id ) {
		$rows = self::imports( $user_id );
		// With the outside-training module present, imported entries already show in its section.
		if ( ! $rows || class_exists( 'WIC_External' ) ) {
			return;
		}
		echo '<section class="wic-section"><h3 class="wic-h3">' . esc_html__( 'Training brought from another agency', 'wic-tp' ) . '</h3>';
		self::imports_table( $rows );
		echo '</section>';
	}
}

add_action( 'wic_init', array( 'WIC_Transcript', 'init' ) );
