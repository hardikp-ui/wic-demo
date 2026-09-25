<?php
/**
 * Making the training stick (#121–#127).
 *
 * After a completion, short refreshers ("boosters") are scheduled at set intervals
 * (default days 7, 30 and 60). Each refresher is a handful of the course's own question
 * slides, chosen by how likely each one is to have been forgotten, and scored on the
 * server with the same scoring as the course. Answering a refresher never changes the
 * original completion — the delayed result is recorded alongside it.
 *
 * Per-person spacing (#126) uses a Leitner scheme: every question a person has met sits in
 * one of five boxes. A correct refresher answer moves it up a box, a wrong one sends it
 * back to box 1, and each box has its own review interval (1, 3, 7, 14, 30 days). People
 * who remember well are asked less often; people who forget see the question again sooner.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_table_sql',
	function ( $sql, $c ) {
		$sql[] = 'CREATE TABLE ' . wic_table( 'boost_rounds' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  completion_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(20) NOT NULL DEFAULT 'scheduled',
  day_offset int(11) NOT NULL DEFAULT 0,
  trigger_slide bigint(20) unsigned NOT NULL DEFAULT 0,
  due_at datetime NOT NULL,
  slides text NULL,
  status varchar(20) NOT NULL DEFAULT 'due',
  correct int(11) NOT NULL DEFAULT 0,
  total int(11) NOT NULL DEFAULT 0,
  score int(11) NOT NULL DEFAULT 0,
  passed tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  completed_at datetime NULL,
  PRIMARY KEY  (id),
  KEY user_status (user_id,status),
  KEY course (course_id)
) $c;";
		$sql[] = 'CREATE TABLE ' . wic_table( 'boost_items' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  slide_id bigint(20) unsigned NOT NULL,
  box tinyint(3) unsigned NOT NULL DEFAULT 1,
  next_due datetime NULL,
  reviews int(11) NOT NULL DEFAULT 0,
  lapses int(11) NOT NULL DEFAULT 0,
  last_result tinyint(1) NULL,
  last_reviewed datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_slide (user_id,slide_id),
  KEY course (course_id)
) $c;";
		$sql[] = 'CREATE TABLE ' . wic_table( 'boost_answers' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  round_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  slide_id bigint(20) unsigned NOT NULL,
  answer longtext NULL,
  correct tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY round_id (round_id),
  KEY slide (slide_id)
) $c;";
		return $sql;
	},
	10,
	2
);

add_filter(
	'wic_agency_defaults',
	function ( $d ) {
		$d['boost_enabled']    = 1;
		$d['boost_error_rate'] = 40;
		$d['boost_error_min']  = 5;
		return $d;
	}
);

add_filter(
	'wic_agency_fields',
	function ( $f ) {
		$f['boost_enabled']    = array( __( 'Schedule refreshers after completion', 'wic-tp' ), 'checkbox', __( 'Short refresher quizzes drawn from each course\'s own questions. The schedule is set per course.', 'wic-tp' ) );
		$f['boost_error_rate'] = array( __( 'Targeted refresher when a question is answered wrongly by at least (%)', 'wic-tp' ), 'number', __( 'An agency setting, not a benchmark: people who got such a question wrong receive a refresher on it.', 'wic-tp' ) );
		$f['boost_error_min']  = array( __( '…and has at least this many answers', 'wic-tp' ), 'number' );
		return $f;
	}
);

class WIC_Boost {

	const DEFAULT_DAYS  = '7, 30, 60';
	const DEFAULT_COUNT = 3;
	/** Leitner review interval in days for boxes 1–5. */
	const BOX_DAYS = array( 1 => 1, 2 => 3, 3 => 7, 4 => 14, 5 => 30 );

	public static function init() {
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		add_filter( 'wic_flash_messages', array( __CLASS__, 'messages' ) );
		add_filter( 'wic_email_subjects', array( __CLASS__, 'subjects' ) );
		add_action( 'wic_course_completed', array( __CLASS__, 'on_completed' ), 40 );
		add_action( 'wic_answer_recorded', array( __CLASS__, 'on_answer' ), 20, 6 );
		add_action( 'wic_daily', array( __CLASS__, 'daily' ) );
		add_action( 'admin_post_wic_boost_submit', array( __CLASS__, 'submit' ) );
		add_action( 'admin_post_wic_retention_csv', array( __CLASS__, 'retention_csv' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_wic_course', array( __CLASS__, 'save_meta' ), 10, 2 );
		add_action( 'wic_person_record_sections', array( __CLASS__, 'person_section' ), 50, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Course settings                                                    */
	/* ------------------------------------------------------------------ */

	public static function course_days( $course_id ) {
		if ( get_post_meta( $course_id, '_wic_boost_off', true ) ) {
			return array();
		}
		$raw  = (string) get_post_meta( $course_id, '_wic_boost_days', true );
		$raw  = '' === trim( $raw ) ? self::DEFAULT_DAYS : $raw;
		$days = array_filter( array_map( 'absint', preg_split( '/[\s,;]+/', $raw ) ) );
		$days = array_values( array_unique( $days ) );
		sort( $days );
		return $days;
	}

	public static function course_count( $course_id ) {
		$n = (int) get_post_meta( $course_id, '_wic_boost_count', true );
		return $n > 0 ? min( 20, $n ) : self::DEFAULT_COUNT;
	}

	/** Refresher clips: one per line, "URL | Title". */
	public static function course_clips( $course_id ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) get_post_meta( $course_id, '_wic_boost_clips', true ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			$url   = esc_url_raw( $parts[0] );
			if ( $url ) {
				$out[] = array(
					'url'   => $url,
					'title' => isset( $parts[1] ) && '' !== $parts[1] ? $parts[1] : __( 'Refresher clip', 'wic-tp' ),
				);
			}
		}
		return $out;
	}

	public static function pass_mark( $course_id ) {
		$c = get_post_meta( $course_id, '_wic_pass_mark', true );
		return '' !== $c ? (int) $c : (int) wic_setting( 'pass_mark' );
	}

	public static function meta_box() {
		add_meta_box( 'wic_boost_meta', __( 'Refreshers after completion', 'wic-tp' ), array( __CLASS__, 'render_box' ), 'wic_course', 'side' );
	}

	public static function render_box( $post ) {
		wp_nonce_field( 'wic_boost_meta', 'wic_boost_nonce' );
		?>
		<p><label><input type="checkbox" name="wic_boost_off" value="1" <?php checked( (bool) get_post_meta( $post->ID, '_wic_boost_off', true ) ); ?>> <?php esc_html_e( 'No refreshers for this course', 'wic-tp' ); ?></label></p>
		<p><label for="wic_boost_days"><strong><?php esc_html_e( 'Days after completion', 'wic-tp' ); ?></strong></label><br>
			<input type="text" id="wic_boost_days" name="wic_boost_days" class="widefat" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wic_boost_days', true ) ); ?>" placeholder="<?php echo esc_attr( self::DEFAULT_DAYS ); ?>"></p>
		<p><label for="wic_boost_count"><strong><?php esc_html_e( 'Questions per refresher', 'wic-tp' ); ?></strong></label><br>
			<input type="number" min="1" max="20" id="wic_boost_count" name="wic_boost_count" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wic_boost_count', true ) ); ?>" placeholder="<?php echo (int) self::DEFAULT_COUNT; ?>"></p>
		<p><label for="wic_boost_clips"><strong><?php esc_html_e( 'Refresher clips', 'wic-tp' ); ?></strong></label><br>
			<textarea id="wic_boost_clips" name="wic_boost_clips" class="widefat" rows="3" placeholder="https://… | Title"><?php echo esc_textarea( get_post_meta( $post->ID, '_wic_boost_clips', true ) ); ?></textarea>
			<span class="description"><?php esc_html_e( 'Optional short task videos, one per line: URL | title. Shown before the refresher questions. Captioned video is required for accessibility.', 'wic-tp' ); ?></span></p>
		<?php
	}

	public static function save_meta( $post_id, $post ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! isset( $_POST['wic_boost_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wic_boost_nonce'] ), 'wic_boost_meta' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, '_wic_boost_off', empty( $_POST['wic_boost_off'] ) ? '' : '1' );
		update_post_meta( $post_id, '_wic_boost_days', isset( $_POST['wic_boost_days'] ) ? implode( ', ', array_filter( array_map( 'absint', preg_split( '/[\s,;]+/', sanitize_text_field( wp_unslash( $_POST['wic_boost_days'] ) ) ) ) ) ) : '' );
		update_post_meta( $post_id, '_wic_boost_count', isset( $_POST['wic_boost_count'] ) && '' !== $_POST['wic_boost_count'] ? (string) absint( $_POST['wic_boost_count'] ) : '' );
		update_post_meta( $post_id, '_wic_boost_clips', isset( $_POST['wic_boost_clips'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wic_boost_clips'] ) ) : '' );
	}

	/* ------------------------------------------------------------------ */
	/* Items and selection                                                */
	/* ------------------------------------------------------------------ */

	/** Question slides in a course, slide_id => question. */
	public static function questions( $course_id ) {
		$out = array();
		foreach ( array_keys( WIC_Content::flat_slides( $course_id ) ) as $sid ) {
			$q = WIC_Content::question( $sid );
			if ( $q ) {
				$out[ $sid ] = $q;
			}
		}
		return $out;
	}

	public static function item( $user_id, $slide_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'boost_items' ) . ' WHERE user_id = %d AND slide_id = %d', $user_id, $slide_id ) );
	}

	private static function due_in( $box ) {
		$box = max( 1, min( 5, (int) $box ) );
		return gmdate( 'Y-m-d H:i:s', time() + self::BOX_DAYS[ $box ] * DAY_IN_SECONDS );
	}

	/** Leitner update: correct moves up a box, wrong goes back to box 1. */
	public static function review_item( $user_id, $course_id, $slide_id, $correct ) {
		global $wpdb;
		$item = self::item( $user_id, $slide_id );
		if ( ! $item ) {
			$box = $correct ? 2 : 1;
			$wpdb->insert(
				wic_table( 'boost_items' ),
				array(
					'user_id'       => $user_id,
					'course_id'     => $course_id,
					'slide_id'      => $slide_id,
					'box'           => $box,
					'next_due'      => self::due_in( $box ),
					'reviews'       => 1,
					'lapses'        => $correct ? 0 : 1,
					'last_result'   => $correct ? 1 : 0,
					'last_reviewed' => wic_now(),
				)
			);
			return;
		}
		$box = $correct ? min( 5, (int) $item->box + 1 ) : 1;
		$wpdb->update(
			wic_table( 'boost_items' ),
			array(
				'box'           => $box,
				'next_due'      => self::due_in( $box ),
				'reviews'       => (int) $item->reviews + 1,
				'lapses'        => (int) $item->lapses + ( $correct ? 0 : 1 ),
				'last_result'   => $correct ? 1 : 0,
				'last_reviewed' => wic_now(),
			),
			array( 'id' => $item->id )
		);
	}

	/**
	 * At-risk ranking (#122): questions the person got wrong or needed several attempts on
	 * in the course, that sit in a low box, have lapsed before, or have gone longest unseen.
	 */
	public static function rank_at_risk( $user_id, $course_id, $limit, $must = array() ) {
		$questions  = self::questions( $course_id );
		$completion = WIC_Records::latest_completion( $user_id, $course_id );
		$run        = $completion ? (int) $completion->run : WIC_Records::current_run( $user_id, $course_id );
		$scores     = array();
		foreach ( $questions as $sid => $q ) {
			$score    = 0;
			$attempts = WIC_Records::slide_attempts( $user_id, $course_id, $run, $sid );
			if ( $attempts ) {
				$last   = end( $attempts );
				$score += ( count( $attempts ) - 1 ) * 3;
				$score += (int) $last->correct ? 0 : 6;
			} else {
				$score += 2; // Never answered (for example, drawn out of a question bank).
			}
			$item = self::item( $user_id, $sid );
			if ( $item ) {
				$score += ( 6 - (int) $item->box ) * 2 + (int) $item->lapses * 2;
				if ( $item->next_due && strtotime( $item->next_due . ' UTC' ) <= time() ) {
					$score += 4;
				}
				$seen = $item->last_reviewed ? $item->last_reviewed : ( $completion ? $completion->completed_at : null );
			} else {
				$seen = $completion ? $completion->completed_at : null;
			}
			if ( $seen ) {
				$score += min( 10, (int) floor( ( time() - strtotime( $seen . ' UTC' ) ) / ( 7 * DAY_IN_SECONDS ) ) );
			}
			if ( in_array( $sid, $must, true ) ) {
				$score += 1000;
			}
			$scores[ $sid ] = $score;
		}
		arsort( $scores );
		return array_slice( array_map( 'intval', array_keys( $scores ) ), 0, max( 1, (int) $limit ) );
	}

	/* ------------------------------------------------------------------ */
	/* Rounds                                                             */
	/* ------------------------------------------------------------------ */

	public static function round( $round_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'boost_rounds' ) . ' WHERE id = %d', $round_id ) );
	}

	public static function open_round( $user_id, $course_id, $kind = null ) {
		global $wpdb;
		$sql = 'SELECT * FROM ' . wic_table( 'boost_rounds' ) . " WHERE user_id = %d AND course_id = %d AND status = 'due' AND due_at <= %s";
		$arg = array( $user_id, $course_id, wic_now() );
		if ( $kind ) {
			$sql  .= ' AND kind = %s';
			$arg[] = $kind;
		}
		return $wpdb->get_row( $wpdb->prepare( $sql . ' ORDER BY due_at ASC LIMIT 1', $arg ) );
	}

	public static function create_round( $user_id, $course_id, $kind, $due_mysql, $args = array() ) {
		global $wpdb;
		$wpdb->insert(
			wic_table( 'boost_rounds' ),
			array(
				'user_id'       => $user_id,
				'course_id'     => $course_id,
				'completion_id' => isset( $args['completion_id'] ) ? (int) $args['completion_id'] : 0,
				'kind'          => $kind,
				'day_offset'    => isset( $args['day_offset'] ) ? (int) $args['day_offset'] : 0,
				'trigger_slide' => isset( $args['trigger_slide'] ) ? (int) $args['trigger_slide'] : 0,
				'due_at'        => $due_mysql,
				'slides'        => isset( $args['slides'] ) ? wp_json_encode( array_values( array_map( 'intval', $args['slides'] ) ) ) : '',
				'status'        => 'due',
				'created_at'    => wic_now(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** Slides are chosen when the refresher is first opened, so the ranking reflects the day it is taken. */
	public static function materialise( $round ) {
		global $wpdb;
		$slides = $round->slides ? json_decode( $round->slides, true ) : array();
		if ( is_array( $slides ) && $slides ) {
			return array_map( 'intval', $slides );
		}
		$must   = $round->trigger_slide ? array( (int) $round->trigger_slide ) : array();
		$slides = self::rank_at_risk( (int) $round->user_id, (int) $round->course_id, self::course_count( $round->course_id ), $must );
		$wpdb->update( wic_table( 'boost_rounds' ), array( 'slides' => wp_json_encode( $slides ) ), array( 'id' => $round->id ) );
		return $slides;
	}

	/** Schedule the refreshers for a completion (#121) and seed each question's box from how it went. */
	public static function on_completed( $completion ) {
		global $wpdb;
		if ( ! (int) wic_setting( 'boost_enabled' ) ) {
			return;
		}
		$user_id   = (int) $completion->user_id;
		$course_id = (int) $completion->course_id;
		$days      = self::course_days( $course_id );
		$questions = self::questions( $course_id );
		if ( ! $days || ! $questions ) {
			return;
		}
		// A fresh completion (for example, a recertification) replaces refreshers still waiting from the last one.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . wic_table( 'boost_rounds' ) . " SET status = 'cancelled' WHERE user_id = %d AND course_id = %d AND status = 'due' AND kind = 'scheduled'", $user_id, $course_id ) );

		$base = strtotime( $completion->completed_at . ' UTC' );
		foreach ( $days as $d ) {
			self::create_round(
				$user_id,
				$course_id,
				'scheduled',
				gmdate( 'Y-m-d H:i:s', $base + $d * DAY_IN_SECONDS ),
				array(
					'completion_id' => $completion->id,
					'day_offset'    => $d,
				)
			);
		}
		foreach ( array_keys( $questions ) as $sid ) {
			if ( self::item( $user_id, $sid ) ) {
				continue;
			}
			$attempts = WIC_Records::slide_attempts( $user_id, $course_id, (int) $completion->run, $sid );
			$first_ok = $attempts && (int) $attempts[0]->correct;
			$box      = $first_ok ? 2 : 1;
			$wpdb->insert(
				wic_table( 'boost_items' ),
				array(
					'user_id'   => $user_id,
					'course_id' => $course_id,
					'slide_id'  => $sid,
					'box'       => $box,
					'next_due'  => gmdate( 'Y-m-d H:i:s', $base + max( $days[0], self::BOX_DAYS[ $box ] ) * DAY_IN_SECONDS ),
					'reviews'   => 0,
					'lapses'    => $first_ok ? 0 : 1,
				)
			);
		}
	}

	/** A wrong answer after the course is already complete queues a targeted refresher (#123). */
	public static function on_answer( $user_id, $course_id, $slide_id, $correct, $attempt_no, $run ) {
		if ( $correct || ! (int) wic_setting( 'boost_enabled' ) || ! WIC_Records::latest_completion( $user_id, $course_id ) ) {
			return;
		}
		self::queue_error_round( $user_id, $course_id, $slide_id );
	}

	public static function queue_error_round( $user_id, $course_id, $slide_id ) {
		global $wpdb;
		$recent = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . wic_table( 'boost_rounds' ) . " WHERE user_id = %d AND trigger_slide = %d AND kind = 'error' AND created_at > %s LIMIT 1", $user_id, $slide_id, gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		if ( $recent ) {
			return 0;
		}
		$item = self::item( $user_id, $slide_id );
		if ( $item ) {
			$wpdb->update( wic_table( 'boost_items' ), array( 'box' => 1, 'next_due' => wic_now() ), array( 'id' => $item->id ) );
		}
		return self::create_round( $user_id, $course_id, 'error', wic_now(), array( 'trigger_slide' => $slide_id ) );
	}

	/** Daily: agency-wide error triggers, per-person spaced reviews, and "refresher ready" notices. */
	public static function daily() {
		global $wpdb;
		if ( ! (int) wic_setting( 'boost_enabled' ) ) {
			return;
		}
		$rate = max( 1, (int) wic_setting( 'boost_error_rate' ) );
		$min  = max( 1, (int) wic_setting( 'boost_error_min' ) );

		// Questions the agency gets wrong often (#123).
		$hard = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT slide_id, course_id, COUNT(*) AS n, SUM(correct = 0) AS wrong FROM ' . wic_table( 'attempts' ) . ' GROUP BY slide_id, course_id HAVING n >= %d AND SUM(correct = 0) * 100 >= %d * n',
				$min,
				$rate
			)
		);
		foreach ( $hard as $h ) {
			// People whose final answer on it was wrong and who have completed the course.
			$users = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT a.user_id FROM ' . wic_table( 'attempts' ) . ' a INNER JOIN ( SELECT user_id, MAX(id) AS last_id FROM ' . wic_table( 'attempts' ) . ' WHERE slide_id = %d GROUP BY user_id ) l ON l.last_id = a.id WHERE a.correct = 0',
					$h->slide_id
				)
			);
			foreach ( $users as $uid ) {
				if ( 'active' === wic_user_status( $uid ) && WIC_Records::latest_completion( (int) $uid, (int) $h->course_id ) ) {
					self::queue_error_round( (int) $uid, (int) $h->course_id, (int) $h->slide_id );
				}
			}
		}

		// Per-person spacing (#126): questions whose Leitner interval has passed.
		$due = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, course_id, COUNT(*) AS n FROM ' . wic_table( 'boost_items' ) . ' WHERE next_due IS NOT NULL AND next_due <= %s GROUP BY user_id, course_id', wic_now() ) );
		foreach ( $due as $d ) {
			$busy = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM ' . wic_table( 'boost_rounds' ) . " WHERE user_id = %d AND course_id = %d AND ( ( status = 'due' AND due_at <= %s ) OR completed_at > %s ) LIMIT 1",
					$d->user_id,
					$d->course_id,
					gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS ),
					gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS )
				)
			);
			if ( ! $busy && 'active' === wic_user_status( $d->user_id ) && ! self::questions_gone( (int) $d->course_id ) ) {
				self::create_round( (int) $d->user_id, (int) $d->course_id, 'spaced', wic_now() );
			}
		}

		// "Your refresher is ready" — once per round.
		$ready = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'boost_rounds' ) . " WHERE status = 'due' AND due_at <= %s", wic_now() ) );
		foreach ( $ready as $r ) {
			if ( 'active' !== wic_user_status( $r->user_id ) || WIC_Notify::has_event( $r->user_id, 'boost_due', $r->id ) ) {
				continue;
			}
			WIC_Notify::event( $r->user_id, 'boost_due', $r->id, sprintf( __( 'A short refresher on "%s" is ready. It takes a few minutes and does not change your completion.', 'wic-tp' ), get_the_title( $r->course_id ) ), true );
		}
	}

	private static function questions_gone( $course_id ) {
		return 'publish' !== get_post_status( $course_id ) || ! self::questions( $course_id );
	}

	public static function due_rounds( $user_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'boost_rounds' ) . " WHERE user_id = %d AND status = 'due' AND due_at <= %s ORDER BY due_at ASC", $user_id, wic_now() ) );
	}

	public static function due_count( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . wic_table( 'boost_rounds' ) . " WHERE user_id = %d AND status = 'due' AND due_at <= %s", $user_id, wic_now() ) );
	}

	/* ------------------------------------------------------------------ */
	/* Portal                                                             */
	/* ------------------------------------------------------------------ */

	public static function views( $views ) {
		$views['refreshers'] = array(
			'label'    => __( 'Refreshers', 'wic-tp' ),
			'group'    => 'learn',
			'cap'      => 'wic_learn',
			'callback' => array( __CLASS__, 'view_refreshers' ),
			'order'    => 25,
			'badge'    => array( __CLASS__, 'due_count' ),
		);
		$views['retention']  = array(
			'label'    => __( 'Retention', 'wic-tp' ),
			'group'    => 'agency',
			'cap'      => 'wic_view_all',
			'callback' => array( __CLASS__, 'view_retention' ),
			'order'    => 60,
		);
		return $views;
	}

	public static function messages( $m ) {
		$m['boost_done']  = __( 'Refresher recorded. Your original completion is unchanged.', 'wic-tp' );
		$m['err_boost']   = __( 'That refresher is not available.', 'wic-tp' );
		return $m;
	}

	public static function subjects( $s ) {
		$s['boost_due'] = __( 'A short refresher is ready', 'wic-tp' );
		return $s;
	}

	private static function kind_label( $kind ) {
		$labels = array(
			'scheduled' => __( 'Scheduled', 'wic-tp' ),
			'spaced'    => __( 'Review', 'wic-tp' ),
			'error'     => __( 'Targeted', 'wic-tp' ),
		);
		return isset( $labels[ $kind ] ) ? $labels[ $kind ] : $kind;
	}

	public static function view_refreshers( $uid ) {
		global $wpdb;
		if ( isset( $_GET['round'] ) ) {
			self::view_round( $uid, absint( $_GET['round'] ) );
			return;
		}
		if ( isset( $_GET['result'] ) ) {
			self::view_result( $uid, absint( $_GET['result'] ) );
			return;
		}
		$due      = self::due_rounds( $uid );
		$upcoming = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'boost_rounds' ) . " WHERE user_id = %d AND status = 'due' AND due_at > %s ORDER BY due_at ASC LIMIT 10", $uid, wic_now() ) );
		$done     = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'boost_rounds' ) . " WHERE user_id = %d AND status = 'done' ORDER BY completed_at DESC LIMIT 50", $uid ) );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Refreshers', 'wic-tp' ); ?></h2>
		<p class="wic-meta"><?php esc_html_e( 'A few questions from courses you have finished, spaced out so the training sticks. They take a few minutes and never change your completion or certificate.', 'wic-tp' ); ?></p>
		<?php if ( ! $due ) : ?>
			<div class="wic-empty wic-empty--good"><p><?php esc_html_e( 'No refreshers are due right now.', 'wic-tp' ); ?></p></div>
		<?php else : ?>
			<div class="wic-grid">
				<?php foreach ( $due as $r ) : ?>
					<article class="wic-card">
						<div class="wic-card__top">
							<h3><?php echo esc_html( get_the_title( $r->course_id ) ); ?></h3>
							<span class="wic-badge wic-badge--in_progress"><?php echo esc_html( self::kind_label( $r->kind ) ); ?></span>
						</div>
						<p class="wic-meta">
							<?php
							if ( 'scheduled' === $r->kind ) {
								echo esc_html( sprintf( _n( '%d day after completion', '%d days after completion', (int) $r->day_offset, 'wic-tp' ), (int) $r->day_offset ) );
							} elseif ( 'error' === $r->kind ) {
								esc_html_e( 'On a question that is often missed', 'wic-tp' );
							} else {
								esc_html_e( 'Questions due for review at your own pace', 'wic-tp' );
							}
							?>
						</p>
						<div class="wic-card__actions"><a class="wic-btn wic-btn--primary" href="<?php echo esc_url( wic_portal_url( 'refreshers', array( 'round' => $r->id ) ) ); ?>"><?php esc_html_e( 'Start refresher', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( get_the_title( $r->course_id ) ); ?></span></a></div>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( $upcoming ) : ?>
			<h3 class="wic-h3"><?php esc_html_e( 'Coming up', 'wic-tp' ); ?></h3>
			<ul class="wic-list">
				<?php foreach ( $upcoming as $r ) : ?>
					<li><span><?php echo esc_html( get_the_title( $r->course_id ) ); ?></span><time datetime="<?php echo esc_attr( mysql2date( 'c', $r->due_at ) ); ?>"><?php echo esc_html( wic_format_date( $r->due_at ) ); ?></time></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<h3 class="wic-h3"><?php esc_html_e( 'Delayed checks you have taken', 'wic-tp' ); ?></h3>
		<?php if ( ! $done ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'Once you take a refresher, the result appears here beside the course.', 'wic-tp' ); ?></p></div>
		<?php else : ?>
			<?php self::history_table( $done ); ?>
		<?php endif; ?>
		<?php
	}

	private static function history_table( $rows ) {
		?>
		<div class="wic-table-wrap">
		<table class="wic-table">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Course', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Taken', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Days after completion', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Score', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Result', 'wic-tp' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<?php
				$c    = WIC_Records::latest_completion( $r->user_id, $r->course_id );
				$gap  = $c && $r->completed_at ? max( 0, (int) floor( ( strtotime( $r->completed_at . ' UTC' ) - strtotime( $c->completed_at . ' UTC' ) ) / DAY_IN_SECONDS ) ) : '—';
				?>
				<tr>
					<td><?php echo esc_html( get_the_title( $r->course_id ) ); ?></td>
					<td><?php echo esc_html( wic_format_date( $r->completed_at ) ); ?></td>
					<td><?php echo esc_html( (string) $gap ); ?></td>
					<td><?php echo (int) $r->correct; ?> / <?php echo (int) $r->total; ?> (<?php echo (int) $r->score; ?>%)</td>
					<td><?php echo $r->passed ? '<span class="wic-badge wic-badge--complete">' . esc_html__( 'Passed again', 'wic-tp' ) . '</span>' : '<span class="wic-badge wic-badge--overdue">' . esc_html__( 'Needs review', 'wic-tp' ) . '</span>'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	private static function view_round( $uid, $round_id ) {
		$r = self::round( $round_id );
		if ( ! $r || (int) $r->user_id !== (int) $uid || 'due' !== $r->status || strtotime( $r->due_at . ' UTC' ) > time() ) {
			echo '<div class="wic-notice wic-notice--err" role="status">' . esc_html__( 'That refresher is not available.', 'wic-tp' ) . '</div>';
			return;
		}
		$slides = self::materialise( $r );
		$qs     = array();
		foreach ( $slides as $sid ) {
			$q = WIC_Content::question( $sid );
			if ( $q ) {
				$qs[ $sid ] = $q;
			}
		}
		$clips = self::course_clips( $r->course_id );
		?>
		<p><a href="<?php echo esc_url( wic_portal_url( 'refreshers' ) ); ?>">← <?php esc_html_e( 'All refreshers', 'wic-tp' ); ?></a></p>
		<h2 class="wic-h"><?php echo esc_html( sprintf( __( 'Refresher: %s', 'wic-tp' ), get_the_title( $r->course_id ) ) ); ?></h2>
		<?php if ( ! $qs ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'This course no longer has questions to review.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<?php if ( $clips ) : ?>
			<section class="wic-section wic-boost-clips" aria-labelledby="wic-boost-clips-h">
				<h3 class="wic-h3" id="wic-boost-clips-h"><?php esc_html_e( 'Watch first (optional)', 'wic-tp' ); ?></h3>
				<?php foreach ( $clips as $clip ) : ?>
					<figure class="wic-boost-clip">
						<?php if ( preg_match( '/\.(mp4|webm|ogv|ogg)(\?|$)/i', $clip['url'] ) ) : ?>
							<video controls preload="metadata" src="<?php echo esc_url( $clip['url'] ); ?>"></video>
							<figcaption><?php echo esc_html( $clip['title'] ); ?></figcaption>
						<?php else : ?>
							<a href="<?php echo esc_url( $clip['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $clip['title'] ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'wic-tp' ); ?></span></a>
						<?php endif; ?>
					</figure>
				<?php endforeach; ?>
			</section>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form wic-boost-form">
			<input type="hidden" name="action" value="wic_boost_submit">
			<input type="hidden" name="round" value="<?php echo (int) $r->id; ?>">
			<?php wp_nonce_field( 'wic_boost_' . $r->id ); ?>
			<?php
			$n = 0;
			foreach ( $qs as $sid => $q ) {
				++$n;
				self::render_question( $sid, $q, $n, count( $qs ) );
			}
			?>
			<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Check my answers', 'wic-tp' ); ?></button>
		</form>
		<?php
	}

	/** Server-rendered question. Values are original indices; options are shown in a random order. */
	private static function render_question( $sid, $q, $n, $total ) {
		$name = 'a[' . (int) $sid . ']';
		$id   = 'wic-bq-' . (int) $sid;
		echo '<fieldset class="wic-panel wic-boost-q"><legend><span class="wic-meta">' . esc_html( sprintf( __( 'Question %1$d of %2$d', 'wic-tp' ), $n, $total ) ) . '</span><br>' . wp_kses_post( isset( $q['prompt'] ) ? $q['prompt'] : '' ) . '</legend>';
		switch ( $q['type'] ) {
			case 'mc':
			case 'tf':
			case 'mr':
				$order = array_keys( $q['options'] );
				if ( 'tf' !== $q['type'] ) {
					shuffle( $order );
				}
				if ( 'mr' === $q['type'] ) {
					echo '<p class="wic-help">' . esc_html__( 'Select all that apply.', 'wic-tp' ) . '</p>';
				}
				foreach ( $order as $oi ) {
					$oid = $id . '-' . (int) $oi;
					$in  = 'mr' === $q['type']
						? '<input type="checkbox" name="' . esc_attr( $name ) . '[]" id="' . esc_attr( $oid ) . '" value="' . (int) $oi . '">'
						: '<input type="radio" name="' . esc_attr( $name ) . '" id="' . esc_attr( $oid ) . '" value="' . (int) $oi . '" required>';
					echo '<div class="wic-boost-opt">' . $in . ' <label for="' . esc_attr( $oid ) . '">' . wp_kses_post( isset( $q['options'][ $oi ]['text'] ) ? $q['options'][ $oi ]['text'] : '' ) . '</label></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				break;
			case 'sort':
				foreach ( (array) $q['items'] as $i => $item ) {
					$fid = $id . '-' . (int) $i;
					echo '<div class="wic-field"><label for="' . esc_attr( $fid ) . '">' . esc_html( wp_strip_all_tags( $item['text'] ) ) . '</label><select id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '[' . (int) $i . ']" required><option value="">' . esc_html__( 'Choose where it belongs', 'wic-tp' ) . '</option>';
					foreach ( (array) $q['categories'] as $ci => $cat ) {
						echo '<option value="' . (int) $ci . '">' . esc_html( wp_strip_all_tags( $cat ) ) . '</option>';
					}
					echo '</select></div>';
				}
				break;
			case 'match':
				$right = array_keys( (array) $q['pairs'] );
				shuffle( $right );
				foreach ( (array) $q['pairs'] as $i => $pair ) {
					$fid = $id . '-' . (int) $i;
					echo '<div class="wic-field"><label for="' . esc_attr( $fid ) . '">' . esc_html( wp_strip_all_tags( $pair['left'] ) ) . '</label><select id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '[' . (int) $i . ']" required><option value="">' . esc_html__( 'Choose its match', 'wic-tp' ) . '</option>';
					foreach ( $right as $ri ) {
						echo '<option value="' . (int) $ri . '">' . esc_html( wp_strip_all_tags( $q['pairs'][ $ri ]['right'] ) ) . '</option>';
					}
					echo '</select></div>';
				}
				break;
		}
		echo '</fieldset>';
	}

	/** Parse a submitted answer into what WIC_Records::score_answer() expects. */
	private static function parse_answer( $q, $raw ) {
		switch ( $q['type'] ) {
			case 'mc':
			case 'tf':
				return is_numeric( $raw ) ? (int) $raw : -1;
			case 'mr':
				return array_map( 'intval', (array) $raw );
			case 'sort':
			case 'match':
				$out = array();
				$n   = 'sort' === $q['type'] ? count( (array) $q['items'] ) : count( (array) $q['pairs'] );
				for ( $i = 0; $i < $n; $i++ ) {
					$out[ $i ] = isset( $raw[ $i ] ) && '' !== $raw[ $i ] ? (int) $raw[ $i ] : -1;
				}
				return $out;
		}
		return null;
	}

	public static function submit() {
		global $wpdb;
		$round_id = isset( $_POST['round'] ) ? absint( $_POST['round'] ) : 0;
		check_admin_referer( 'wic_boost_' . $round_id );
		$uid = get_current_user_id();
		$r   = self::round( $round_id );
		if ( ! $r || (int) $r->user_id !== $uid || 'due' !== $r->status ) {
			WIC_Portal::back( 'refreshers', 'err_boost' );
		}
		$raw     = isset( $_POST['a'] ) && is_array( $_POST['a'] ) ? wp_unslash( $_POST['a'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed to integers below.
		$correct = 0;
		$total   = 0;
		foreach ( self::materialise( $r ) as $sid ) {
			$q = WIC_Content::question( $sid );
			if ( ! $q ) {
				continue;
			}
			$answer = self::parse_answer( $q, isset( $raw[ $sid ] ) ? $raw[ $sid ] : null );
			list( $ok ) = WIC_Records::score_answer( $q, $answer );
			++$total;
			$correct += $ok ? 1 : 0;
			$wpdb->insert(
				wic_table( 'boost_answers' ),
				array(
					'round_id'   => $r->id,
					'user_id'    => $uid,
					'course_id'  => $r->course_id,
					'slide_id'   => $sid,
					'answer'     => wp_json_encode( $answer ),
					'correct'    => $ok ? 1 : 0,
					'created_at' => wic_now(),
				)
			);
			self::review_item( $uid, (int) $r->course_id, $sid, $ok );
		}
		$score = $total ? (int) round( $correct / $total * 100 ) : 0;
		$wpdb->update(
			wic_table( 'boost_rounds' ),
			array(
				'status'       => 'done',
				'correct'      => $correct,
				'total'        => $total,
				'score'        => $score,
				'passed'       => $total && $score >= self::pass_mark( $r->course_id ) ? 1 : 0,
				'completed_at' => wic_now(),
			),
			array( 'id' => $r->id )
		);
		wic_audit( 'boost_done', 'boost_round', $r->id, array( 'course' => (int) $r->course_id, 'score' => $score ) );
		do_action( 'wic_boost_completed', self::round( $r->id ) );
		wp_safe_redirect( wic_portal_url( 'refreshers', array( 'result' => $r->id, 'wic_msg' => 'boost_done' ) ) );
		exit;
	}

	private static function view_result( $uid, $round_id ) {
		global $wpdb;
		$r = self::round( $round_id );
		if ( ! $r || (int) $r->user_id !== (int) $uid || 'done' !== $r->status ) {
			echo '<div class="wic-notice wic-notice--err" role="status">' . esc_html__( 'That refresher is not available.', 'wic-tp' ) . '</div>';
			return;
		}
		$answers = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'boost_answers' ) . ' WHERE round_id = %d ORDER BY id ASC', $r->id ) );
		?>
		<p><a href="<?php echo esc_url( wic_portal_url( 'refreshers' ) ); ?>">← <?php esc_html_e( 'All refreshers', 'wic-tp' ); ?></a></p>
		<h2 class="wic-h" tabindex="-1"><?php echo esc_html( sprintf( __( 'Refresher result: %s', 'wic-tp' ), get_the_title( $r->course_id ) ) ); ?></h2>
		<div class="wic-notice wic-notice--<?php echo $r->passed ? 'ok' : 'warn'; ?>" role="status">
			<strong><?php echo $r->passed ? esc_html__( 'Passed again.', 'wic-tp' ) : esc_html__( 'Worth another look.', 'wic-tp' ); ?></strong>
			<?php echo esc_html( sprintf( __( '%1$d of %2$d correct (%3$d%%). Your original completion and certificate are unchanged.', 'wic-tp' ), (int) $r->correct, (int) $r->total, (int) $r->score ) ); ?>
		</div>
		<ol class="wic-list wic-boost-results">
			<?php foreach ( $answers as $a ) : ?>
				<?php $q = WIC_Content::question( $a->slide_id ); ?>
				<li>
					<div>
						<p><strong><?php echo $a->correct ? esc_html__( 'Correct:', 'wic-tp' ) : esc_html__( 'Not quite:', 'wic-tp' ); ?></strong> <?php echo $q ? wp_kses_post( $q['prompt'] ) : esc_html( get_the_title( $a->slide_id ) ); ?></p>
						<?php if ( ! $a->correct && $q ) : ?>
							<p><?php esc_html_e( 'The correct answer:', 'wic-tp' ); ?> <?php echo esc_html( WIC_Records::correct_answer_text( $q ) ); ?></p>
							<?php if ( WIC_Rest::can_open_course( $uid, $r->course_id ) ) : ?>
								<?php $target = ! empty( $q['explain_slide'] ) ? (int) $q['explain_slide'] : (int) $a->slide_id; ?>
								<p><a href="<?php echo esc_url( wic_page_url( 'learn', array( 'course' => $r->course_id, 'slide' => $target ) ) ); ?>"><?php esc_html_e( 'Review this in the course', 'wic-tp' ); ?></a></p>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Retention report (#124, #125)                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Completion and delayed-check results side by side. Completion rate = people who completed /
	 * people assigned or completed. Delayed-check pass rate = refreshers passed / refreshers taken,
	 * all taken after the course was completed. No external benchmark is shown.
	 */
	public static function retention_rows( $viewer_id ) {
		global $wpdb;
		$ids = wic_scope_user_ids( $viewer_id );
		if ( ! $ids ) {
			return array( array(), array() );
		}
		$in      = implode( ',', array_map( 'intval', $ids ) );
		$courses = array();
		$clinics = array();
		$init    = array( 'people' => 0, 'completed' => 0, 'checks' => 0, 'passed' => 0, 'checked_people' => 0 );

		$assigned = $wpdb->get_results( 'SELECT DISTINCT user_id, course_id FROM ' . wic_table( 'assignments' ) . " WHERE status <> 'cancelled' AND user_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL -- integers only.
		$done     = $wpdb->get_results( 'SELECT DISTINCT user_id, course_id FROM ' . wic_table( 'completions' ) . " WHERE user_id IN ($in)" ); // phpcs:ignore
		$rounds   = $wpdb->get_results( 'SELECT user_id, course_id, COUNT(*) AS n, SUM(passed) AS p FROM ' . wic_table( 'boost_rounds' ) . " WHERE status = 'done' AND user_id IN ($in) GROUP BY user_id, course_id" ); // phpcs:ignore

		$pairs = array();
		foreach ( $assigned as $a ) {
			$pairs[ $a->user_id . ':' . $a->course_id ] = array( 'u' => (int) $a->user_id, 'c' => (int) $a->course_id, 'done' => false, 'n' => 0, 'p' => 0 );
		}
		foreach ( $done as $d ) {
			$k = $d->user_id . ':' . $d->course_id;
			if ( ! isset( $pairs[ $k ] ) ) {
				$pairs[ $k ] = array( 'u' => (int) $d->user_id, 'c' => (int) $d->course_id, 'done' => false, 'n' => 0, 'p' => 0 );
			}
			$pairs[ $k ]['done'] = true;
		}
		foreach ( $rounds as $r ) {
			$k = $r->user_id . ':' . $r->course_id;
			if ( isset( $pairs[ $k ] ) ) {
				$pairs[ $k ]['n'] = (int) $r->n;
				$pairs[ $k ]['p'] = (int) $r->p;
			}
		}
		foreach ( $pairs as $p ) {
			$clinic = wic_user_clinic_name( $p['u'] );
			$clinic = '' !== $clinic ? $clinic : __( '(no clinic)', 'wic-tp' );
			foreach ( array( 'c' => $p['c'], 'k' => $clinic ) as $which => $key ) {
				if ( 'c' === $which ) {
					$bucket = &$courses;
				} else {
					$bucket = &$clinics;
				}
				if ( ! isset( $bucket[ $key ] ) ) {
					$bucket[ $key ] = $init;
				}
				$bucket[ $key ]['people']++;
				$bucket[ $key ]['completed']      += $p['done'] ? 1 : 0;
				$bucket[ $key ]['checks']         += $p['n'];
				$bucket[ $key ]['passed']         += $p['p'];
				$bucket[ $key ]['checked_people'] += $p['n'] ? 1 : 0;
				unset( $bucket );
			}
		}
		ksort( $clinics );
		return array( $courses, $clinics );
	}

	private static function pct( $a, $b ) {
		return $b ? (int) round( $a / $b * 100 ) . '%' : '—';
	}

	public static function view_retention( $uid ) {
		list( $courses, $clinics ) = self::retention_rows( $uid );
		?>
		<div class="wic-toolbar">
			<h2 class="wic-h"><?php esc_html_e( 'Retention', 'wic-tp' ); ?></h2>
			<a class="wic-btn" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wic_retention_csv' ), 'wic_retention_csv' ) ); ?>"><?php esc_html_e( 'Export (CSV)', 'wic-tp' ); ?></a>
		</div>
		<p class="wic-meta"><?php esc_html_e( 'Completion shows who finished a course. Retention shows who could still answer its questions later: every refresher here was taken after the course was completed, and "passed" means it met the course pass mark. The two are reported separately on purpose. Figures are this agency\'s own; no outside benchmark is shown.', 'wic-tp' ); ?></p>
		<?php if ( ! $courses ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'Retention figures appear once people have completed courses and taken refreshers.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		foreach ( array( 'course' => $courses, 'clinic' => $clinics ) as $cut => $rows ) :
			?>
			<h3 class="wic-h3"><?php echo 'course' === $cut ? esc_html__( 'By course', 'wic-tp' ) : esc_html__( 'By clinic', 'wic-tp' ); ?></h3>
			<div class="wic-table-wrap">
			<table class="wic-table" data-wic-sortable>
				<thead><tr>
					<th scope="col"><?php echo 'course' === $cut ? esc_html__( 'Course', 'wic-tp' ) : esc_html__( 'Clinic', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'People', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Completed', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Completion rate', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'People checked later', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Refreshers taken', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Refreshers passed', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Delayed-check pass rate', 'wic-tp' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $key => $r ) : ?>
					<tr>
						<td><?php echo esc_html( 'course' === $cut ? get_the_title( $key ) : $key ); ?></td>
						<td><?php echo (int) $r['people']; ?></td>
						<td><?php echo (int) $r['completed']; ?></td>
						<td data-sort="<?php echo $r['people'] ? (int) round( $r['completed'] / $r['people'] * 100 ) : -1; ?>"><?php echo esc_html( self::pct( $r['completed'], $r['people'] ) ); ?></td>
						<td><?php echo (int) $r['checked_people']; ?></td>
						<td><?php echo (int) $r['checks']; ?></td>
						<td><?php echo (int) $r['passed']; ?></td>
						<td data-sort="<?php echo $r['checks'] ? (int) round( $r['passed'] / $r['checks'] * 100 ) : -1; ?>"><?php echo esc_html( self::pct( $r['passed'], $r['checks'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php
		endforeach;
	}

	public static function retention_csv() {
		check_admin_referer( 'wic_retention_csv' );
		if ( ! current_user_can( 'wic_view_all' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
		}
		list( $courses, $clinics ) = self::retention_rows( get_current_user_id() );
		$rows = array( array( 'Cut', 'Name', 'People', 'Completed', 'Completion rate %', 'People checked later', 'Refreshers taken', 'Refreshers passed', 'Delayed-check pass rate %' ) );
		foreach ( array( 'Course' => $courses, 'Clinic' => $clinics ) as $cut => $list ) {
			foreach ( $list as $key => $r ) {
				$rows[] = array(
					$cut,
					'Course' === $cut ? get_the_title( $key ) : $key,
					$r['people'],
					$r['completed'],
					$r['people'] ? round( $r['completed'] / $r['people'] * 100 ) : '',
					$r['checked_people'],
					$r['checks'],
					$r['passed'],
					$r['checks'] ? round( $r['passed'] / $r['checks'] * 100 ) : '',
				);
			}
		}
		wic_audit( 'export', 'report', 0, array( 'report' => 'retention' ) );
		wic_send_csv( 'retention-' . gmdate( 'Y-m-d' ) . '.csv', $rows );
	}

	/** Delayed checks on the person record screen (#124). */
	public static function person_section( $user_id, $viewer_id ) {
		global $wpdb;
		$done = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'boost_rounds' ) . " WHERE user_id = %d AND status = 'done' ORDER BY completed_at DESC LIMIT 50", $user_id ) );
		echo '<section class="wic-section"><h3 class="wic-h3">' . esc_html__( 'Delayed checks (refreshers)', 'wic-tp' ) . '</h3>';
		if ( ! $done ) {
			echo '<p class="wic-meta">' . esc_html__( 'No refreshers taken yet.', 'wic-tp' ) . '</p>';
		} else {
			self::history_table( $done );
		}
		echo '</section>';
	}
}

add_action( 'wic_init', array( 'WIC_Boost', 'init' ) );
