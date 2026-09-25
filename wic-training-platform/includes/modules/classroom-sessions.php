<?php
/**
 * Scheduled sessions with a roster (#104) and attendance that writes a completion (#105).
 *
 * A session is a dated, placed instance with a trainer and a capacity. Booking past the
 * capacity joins the waiting list; a cancellation promotes the first person waiting.
 * Marking someone attended writes a completion for the linked course, so the classroom
 * record lands in the same place as the online one.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_table_sql',
	function ( $sql, $c ) {
		$sql[] = 'CREATE TABLE ' . wic_table( 'roster' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'booked',
  booked_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  marked_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY session_user (session_id,user_id),
  KEY user_id (user_id)
) $c;";
		return $sql;
	},
	10,
	2
);

class WIC_Sessions {

	const ACTIVE = array( 'booked', 'waitlist', 'attended', 'no_show' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_wic_session', array( __CLASS__, 'save' ) );
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		add_filter( 'wic_flash_messages', array( __CLASS__, 'messages' ) );
		add_action( 'admin_post_wic_session_book', array( __CLASS__, 'handle_book' ) );
		add_action( 'admin_post_wic_session_cancel', array( __CLASS__, 'handle_cancel' ) );
		add_action( 'admin_post_wic_session_attendance', array( __CLASS__, 'handle_attendance' ) );
		add_action( 'wic_person_record_sections', array( __CLASS__, 'person_section' ), 40, 2 );
	}

	public static function register() {
		WIC_E::register_type( 'wic_session', __( 'Sessions', 'wic-tp' ), __( 'Session', 'wic-tp' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Data                                                               */
	/* ------------------------------------------------------------------ */

	public static function meta( $sid ) {
		return array(
			'start'    => (string) get_post_meta( $sid, '_wic_start', true ),
			'end'      => (string) get_post_meta( $sid, '_wic_end', true ),
			'place'    => (string) get_post_meta( $sid, '_wic_place', true ),
			'link'     => (string) get_post_meta( $sid, '_wic_link', true ),
			'trainer'  => (int) get_post_meta( $sid, '_wic_trainer', true ),
			'capacity' => (int) get_post_meta( $sid, '_wic_capacity', true ),
			'course'   => (int) get_post_meta( $sid, '_wic_course', true ),
		);
	}

	/** Stored as local "Y-m-d H:i" in the site timezone; returns a timestamp. */
	public static function ts( $local ) {
		if ( ! $local ) {
			return 0;
		}
		return (int) strtotime( get_gmt_from_date( $local . ':00' ) . ' UTC' );
	}

	public static function when( $sid ) {
		$m = self::meta( $sid );
		if ( ! $m['start'] ) {
			return __( 'Date to be set', 'wic-tp' );
		}
		$fmt  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$text = wp_date( $fmt, self::ts( $m['start'] ) );
		if ( $m['end'] ) {
			$text .= ' – ' . wp_date( get_option( 'time_format' ), self::ts( $m['end'] ) );
		}
		return $text;
	}

	public static function sessions( $upcoming = true, $course = 0 ) {
		$args  = array(
			'post_type'      => 'wic_session',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_key'       => '_wic_start',
			'orderby'        => 'meta_value',
			'order'          => $upcoming ? 'ASC' : 'DESC',
		);
		$now   = current_time( 'Y-m-d H:i' );
		$posts = get_posts( $args );
		return array_values(
			array_filter(
				$posts,
				function ( $p ) use ( $upcoming, $now, $course ) {
					if ( $course && (int) get_post_meta( $p->ID, '_wic_course', true ) !== (int) $course ) {
						return false;
					}
					$start = (string) get_post_meta( $p->ID, '_wic_start', true );
					return null === $upcoming ? true : ( $upcoming ? $start >= $now : $start < $now );
				}
			)
		);
	}

	public static function row( $sid, $uid ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'roster' ) . " WHERE session_id = %d AND user_id = %d AND status <> 'cancelled' ORDER BY id DESC LIMIT 1", $sid, $uid ) );
	}

	public static function roster( $sid ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'roster' ) . " WHERE session_id = %d AND status <> 'cancelled' ORDER BY booked_at ASC, id ASC", $sid ) );
		// People with a place first, the waiting list last, each in booking order.
		$rank = array( 'booked' => 0, 'attended' => 0, 'no_show' => 0, 'waitlist' => 1 );
		usort(
			$rows,
			function ( $a, $b ) use ( $rank ) {
				$d = ( isset( $rank[ $a->status ] ) ? $rank[ $a->status ] : 2 ) - ( isset( $rank[ $b->status ] ) ? $rank[ $b->status ] : 2 );
				return $d ? $d : (int) $a->id - (int) $b->id;
			}
		);
		return $rows;
	}

	public static function booked_count( $sid ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . wic_table( 'roster' ) . " WHERE session_id = %d AND status IN ('booked','attended','no_show')", $sid ) );
	}

	public static function waiting_count( $sid ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . wic_table( 'roster' ) . " WHERE session_id = %d AND status = 'waitlist'", $sid ) );
	}

	/** Has this person attended any session linked to the course, or this session? */
	public static function attended( $uid, $sid ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . wic_table( 'roster' ) . " WHERE session_id = %d AND user_id = %d AND status = 'attended' LIMIT 1", $sid, $uid ) );
	}

	public static function book( $sid, $uid ) {
		global $wpdb;
		if ( self::row( $sid, $uid ) ) {
			return self::row( $sid, $uid )->status;
		}
		$m      = self::meta( $sid );
		$status = ( ! $m['capacity'] || self::booked_count( $sid ) < $m['capacity'] ) ? 'booked' : 'waitlist';
		$wpdb->insert(
			wic_table( 'roster' ),
			array(
				'session_id' => $sid,
				'user_id'    => $uid,
				'status'     => $status,
				'booked_at'  => wic_now(),
				'updated_at' => wic_now(),
			)
		);
		wic_audit( 'session_' . $status, 'session', $sid, array( 'user' => $uid ) );
		return $status;
	}

	/** Cancelling a booked place promotes the first person on the waiting list. */
	public static function cancel( $sid, $uid ) {
		global $wpdb;
		$row = self::row( $sid, $uid );
		if ( ! $row || in_array( $row->status, array( 'attended', 'no_show' ), true ) ) {
			return false;
		}
		$wpdb->update( wic_table( 'roster' ), array( 'status' => 'cancelled', 'updated_at' => wic_now() ), array( 'id' => $row->id ) );
		wic_audit( 'session_cancel', 'session', $sid, array( 'user' => $uid ) );
		if ( 'booked' === $row->status ) {
			self::promote( $sid );
		}
		return true;
	}

	public static function promote( $sid ) {
		global $wpdb;
		$m = self::meta( $sid );
		while ( ( ! $m['capacity'] || self::booked_count( $sid ) < $m['capacity'] ) ) {
			$next = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'roster' ) . " WHERE session_id = %d AND status = 'waitlist' ORDER BY booked_at ASC, id ASC LIMIT 1", $sid ) );
			if ( ! $next ) {
				break;
			}
			$wpdb->update( wic_table( 'roster' ), array( 'status' => 'booked', 'updated_at' => wic_now() ), array( 'id' => $next->id ) );
			wic_audit( 'session_promoted', 'session', $sid, array( 'user' => $next->user_id ) );
			/* translators: 1: session title, 2: date */
			WIC_Notify::event( $next->user_id, 'session_place', $sid, sprintf( __( 'A place opened up: you are now booked on "%1$s" (%2$s).', 'wic-tp' ), get_the_title( $sid ), self::when( $sid ) ), true );
		}
	}

	public static function can_manage( $viewer, $sid ) {
		return user_can( $viewer, 'wic_view_all' ) || (int) self::meta( $sid )['trainer'] === (int) $viewer || user_can( $viewer, 'wic_view_team' );
	}

	/* ------------------------------------------------------------------ */
	/* Authoring                                                          */
	/* ------------------------------------------------------------------ */

	public static function meta_boxes() {
		add_meta_box( 'wic_session_meta', __( 'Session details', 'wic-tp' ), array( __CLASS__, 'box' ), 'wic_session', 'normal', 'high' );
	}

	public static function box( $post ) {
		wp_nonce_field( 'wic_session_meta', 'wic_session_nonce' );
		$m        = self::meta( $post->ID );
		$trainers = get_users( array( 'role__in' => array( 'wic_admin', 'wic_supervisor', 'wic_author', 'administrator', 'wic_local_admin' ), 'orderby' => 'display_name' ) );
		$to_input = function ( $v ) {
			return $v ? str_replace( ' ', 'T', $v ) : '';
		};
		?>
		<p><?php esc_html_e( 'The editor above is the description people see when booking.', 'wic-tp' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th><label for="wic_start"><?php esc_html_e( 'Starts', 'wic-tp' ); ?></label></th><td><input type="datetime-local" id="wic_start" name="wic_start" value="<?php echo esc_attr( $to_input( $m['start'] ) ); ?>" required></td></tr>
			<tr><th><label for="wic_end"><?php esc_html_e( 'Ends', 'wic-tp' ); ?></label></th><td><input type="datetime-local" id="wic_end" name="wic_end" value="<?php echo esc_attr( $to_input( $m['end'] ) ); ?>"></td></tr>
			<tr><th><label for="wic_place"><?php esc_html_e( 'Place', 'wic-tp' ); ?></label></th><td><input type="text" class="regular-text" id="wic_place" name="wic_place" value="<?php echo esc_attr( $m['place'] ); ?>"></td></tr>
			<tr><th><label for="wic_link"><?php esc_html_e( 'Video link (online sessions)', 'wic-tp' ); ?></label></th><td><input type="url" class="large-text" id="wic_link" name="wic_link" value="<?php echo esc_attr( $m['link'] ); ?>"></td></tr>
			<tr><th><label for="wic_trainer"><?php esc_html_e( 'Trainer', 'wic-tp' ); ?></label></th><td><select id="wic_trainer" name="wic_trainer"><option value="0"><?php esc_html_e( '— None —', 'wic-tp' ); ?></option>
				<?php foreach ( $trainers as $t ) : ?>
					<option value="<?php echo (int) $t->ID; ?>" <?php selected( $m['trainer'], $t->ID ); ?>><?php echo esc_html( $t->display_name ); ?></option>
				<?php endforeach; ?>
			</select></td></tr>
			<tr><th><label for="wic_capacity"><?php esc_html_e( 'Capacity', 'wic-tp' ); ?></label></th><td><input type="number" min="0" id="wic_capacity" name="wic_capacity" value="<?php echo esc_attr( $m['capacity'] ); ?>"> <span class="description"><?php esc_html_e( '0 = no limit. Bookings past this join a waiting list.', 'wic-tp' ); ?></span></td></tr>
			<tr><th><label for="wic_course_link"><?php esc_html_e( 'Linked course', 'wic-tp' ); ?></label></th><td><select id="wic_course_link" name="wic_course_link"><option value="0"><?php esc_html_e( '— None —', 'wic-tp' ); ?></option>
				<?php foreach ( WIC_E::options( 'wic_course', array( 'publish', 'draft' ) ) as $id => $t ) : ?>
					<option value="<?php echo (int) $id; ?>" <?php selected( $m['course'], $id ); ?>><?php echo esc_html( $t ); ?></option>
				<?php endforeach; ?>
			</select><p class="description"><?php esc_html_e( 'Marking someone attended records a completion of this course.', 'wic-tp' ); ?></p></td></tr>
		</table>
		<?php
	}

	public static function save( $post_id ) {
		if ( ! WIC_E::can_save( $post_id, 'wic_session_nonce', 'wic_session_meta' ) ) {
			return;
		}
		$dt = function ( $k ) {
			$v = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $v ) ? substr( str_replace( 'T', ' ', $v ), 0, 16 ) : '';
		};
		update_post_meta( $post_id, '_wic_start', $dt( 'wic_start' ) );
		update_post_meta( $post_id, '_wic_end', $dt( 'wic_end' ) );
		update_post_meta( $post_id, '_wic_place', isset( $_POST['wic_place'] ) ? sanitize_text_field( wp_unslash( $_POST['wic_place'] ) ) : '' );
		update_post_meta( $post_id, '_wic_link', isset( $_POST['wic_link'] ) ? esc_url_raw( wp_unslash( $_POST['wic_link'] ) ) : '' );
		update_post_meta( $post_id, '_wic_trainer', isset( $_POST['wic_trainer'] ) ? absint( $_POST['wic_trainer'] ) : 0 );
		update_post_meta( $post_id, '_wic_capacity', isset( $_POST['wic_capacity'] ) ? absint( $_POST['wic_capacity'] ) : 0 );
		update_post_meta( $post_id, '_wic_course', isset( $_POST['wic_course_link'] ) ? absint( $_POST['wic_course_link'] ) : 0 );
		// A larger capacity may make room for people waiting.
		self::promote( $post_id );
	}

	/* ------------------------------------------------------------------ */
	/* Portal                                                             */
	/* ------------------------------------------------------------------ */

	public static function views( $views ) {
		$views['sessions'] = array(
			'label'    => __( 'Sessions', 'wic-tp' ),
			'group'    => 'learn',
			'cap'      => 'wic_learn',
			'callback' => array( __CLASS__, 'view_sessions' ),
			'order'    => 45,
		);
		$views['rosters']  = array(
			'label'    => __( 'Session rosters', 'wic-tp' ),
			'group'    => 'team',
			'cap'      => function ( $uid ) {
				if ( user_can( $uid, 'wic_view_team' ) ) {
					return true;
				}
				return (bool) get_posts( array( 'post_type' => 'wic_session', 'meta_key' => '_wic_trainer', 'meta_value' => (int) $uid, 'posts_per_page' => 1, 'fields' => 'ids' ) );
			},
			'callback' => array( __CLASS__, 'view_rosters' ),
			'order'    => 40,
		);
		return $views;
	}

	public static function messages( $m ) {
		$m['session_booked']     = __( 'You are booked on the session.', 'wic-tp' );
		$m['session_waitlist']   = __( 'The session is full, so you are on the waiting list. You will be told if a place opens up.', 'wic-tp' );
		$m['session_cancelled']  = __( 'Your booking is cancelled.', 'wic-tp' );
		$m['attendance_saved']   = __( 'Attendance saved. Completions were recorded for everyone marked attended.', 'wic-tp' );
		return $m;
	}

	private static function status_badge( $status ) {
		$labels = array(
			'booked'   => array( 'in_progress', __( 'Booked', 'wic-tp' ) ),
			'waitlist' => array( 'not_started', __( 'Waiting list', 'wic-tp' ) ),
			'attended' => array( 'complete', __( 'Attended', 'wic-tp' ) ),
			'no_show'  => array( 'overdue', __( 'Did not attend', 'wic-tp' ) ),
		);
		$l      = isset( $labels[ $status ] ) ? $labels[ $status ] : array( 'not_started', $status );
		return '<span class="wic-badge wic-badge--' . esc_attr( $l[0] ) . '">' . esc_html( $l[1] ) . '</span>';
	}

	public static function view_sessions( $uid ) {
		$upcoming = self::sessions( true );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Classroom and live sessions', 'wic-tp' ); ?></h2>
		<?php if ( ! $upcoming ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'No sessions are scheduled at the moment.', 'wic-tp' ); ?></p></div>
		<?php else : ?>
			<div class="wic-grid">
			<?php foreach ( $upcoming as $s ) : ?>
				<?php
				$m    = self::meta( $s->ID );
				$row  = self::row( $s->ID, $uid );
				$left = $m['capacity'] ? max( 0, $m['capacity'] - self::booked_count( $s->ID ) ) : null;
				$tr   = $m['trainer'] ? get_userdata( $m['trainer'] ) : null;
				?>
				<article class="wic-card">
					<div class="wic-card__top">
						<h3><?php echo esc_html( $s->post_title ); ?></h3>
						<?php echo $row ? self::status_badge( $row->status ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
					<dl class="wic-dl">
						<dt><?php esc_html_e( 'When', 'wic-tp' ); ?></dt><dd><?php echo esc_html( self::when( $s->ID ) ); ?></dd>
						<?php if ( $m['place'] ) : ?><dt><?php esc_html_e( 'Where', 'wic-tp' ); ?></dt><dd><?php echo esc_html( $m['place'] ); ?></dd><?php endif; ?>
						<?php if ( $tr ) : ?><dt><?php esc_html_e( 'Trainer', 'wic-tp' ); ?></dt><dd><?php echo esc_html( $tr->display_name ); ?></dd><?php endif; ?>
						<?php if ( $m['course'] ) : ?><dt><?php esc_html_e( 'Counts towards', 'wic-tp' ); ?></dt><dd><?php echo esc_html( get_the_title( $m['course'] ) ); ?></dd><?php endif; ?>
						<dt><?php esc_html_e( 'Places', 'wic-tp' ); ?></dt><dd><?php echo null === $left ? esc_html__( 'No limit', 'wic-tp' ) : esc_html( sprintf( __( '%1$d of %2$d left', 'wic-tp' ), $left, $m['capacity'] ) ); ?><?php echo self::waiting_count( $s->ID ) ? esc_html( ' · ' . sprintf( __( '%d waiting', 'wic-tp' ), self::waiting_count( $s->ID ) ) ) : ''; ?></dd>
					</dl>
					<?php if ( $s->post_content ) : ?><div class="wic-meta"><?php echo wp_kses_post( wpautop( $s->post_content ) ); ?></div><?php endif; ?>
					<?php if ( $row && 'booked' === $row->status && $m['link'] ) : ?>
						<p><a href="<?php echo esc_url( $m['link'] ); ?>" rel="noopener"><?php esc_html_e( 'Join link', 'wic-tp' ); ?></a></p>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( WIC_E::post_url() ); ?>" class="wic-card__actions">
						<input type="hidden" name="session" value="<?php echo (int) $s->ID; ?>">
						<?php wp_nonce_field( 'wic_session_' . $s->ID ); ?>
						<?php if ( $row ) : ?>
							<input type="hidden" name="action" value="wic_session_cancel">
							<button type="submit" class="wic-btn" data-wic-confirm="<?php esc_attr_e( 'Cancel this booking?', 'wic-tp' ); ?>"><?php echo 'waitlist' === $row->status ? esc_html__( 'Leave the waiting list', 'wic-tp' ) : esc_html__( 'Cancel booking', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( $s->post_title ); ?></span></button>
						<?php else : ?>
							<input type="hidden" name="action" value="wic_session_book">
							<button type="submit" class="wic-btn wic-btn--primary"><?php echo 0 === $left ? esc_html__( 'Join the waiting list', 'wic-tp' ) : esc_html__( 'Book a place', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( $s->post_title ); ?></span></button>
						<?php endif; ?>
					</form>
				</article>
			<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php self::history( $uid ); ?>
		<?php
	}

	private static function history( $uid ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'roster' ) . " WHERE user_id = %d AND status IN ('attended','no_show') ORDER BY updated_at DESC", $uid ) );
		if ( ! $rows ) {
			return;
		}
		echo '<h3 class="wic-h3">' . esc_html__( 'Sessions you have been to', 'wic-tp' ) . '</h3><div class="wic-table-wrap"><table class="wic-table"><thead><tr><th scope="col">' . esc_html__( 'Session', 'wic-tp' ) . '</th><th scope="col">' . esc_html__( 'When', 'wic-tp' ) . '</th><th scope="col">' . esc_html__( 'Attendance', 'wic-tp' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( get_the_title( $r->session_id ) ) . '</td><td>' . esc_html( self::when( $r->session_id ) ) . '</td><td>' . self::status_badge( $r->status ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table></div>';
	}

	public static function handle_book() {
		$sid = isset( $_POST['session'] ) ? absint( $_POST['session'] ) : 0;
		check_admin_referer( 'wic_session_' . $sid );
		$uid = get_current_user_id();
		if ( ! current_user_can( 'wic_learn' ) || 'wic_session' !== get_post_type( $sid ) || 'publish' !== get_post_status( $sid ) ) {
			WIC_E::deny();
		}
		$status = self::book( $sid, $uid );
		WIC_Portal::back( 'sessions', 'waitlist' === $status ? 'session_waitlist' : 'session_booked' );
	}

	public static function handle_cancel() {
		$sid = isset( $_POST['session'] ) ? absint( $_POST['session'] ) : 0;
		check_admin_referer( 'wic_session_' . $sid );
		if ( ! current_user_can( 'wic_learn' ) ) {
			WIC_E::deny();
		}
		self::cancel( $sid, get_current_user_id() );
		WIC_Portal::back( 'sessions', 'session_cancelled' );
	}

	public static function view_rosters( $uid ) {
		$sid = isset( $_GET['session'] ) ? absint( $_GET['session'] ) : 0;
		if ( $sid && 'wic_session' === get_post_type( $sid ) && self::can_manage( $uid, $sid ) ) {
			self::view_roster( $uid, $sid );
			return;
		}
		$list = array_merge( self::sessions( true ), array_slice( self::sessions( false ), 0, 20 ) );
		if ( ! user_can( $uid, 'wic_view_team' ) ) {
			$list = array_filter(
				$list,
				function ( $s ) use ( $uid ) {
					return (int) get_post_meta( $s->ID, '_wic_trainer', true ) === (int) $uid;
				}
			);
		}
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Session rosters', 'wic-tp' ); ?></h2>
		<?php if ( ! $list ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'No sessions yet. Sessions are scheduled under WIC Platform → Sessions.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<div class="wic-table-wrap">
		<table class="wic-table">
			<thead><tr><th scope="col"><?php esc_html_e( 'Session', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'When', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Booked', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Waiting', 'wic-tp' ); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Action', 'wic-tp' ); ?></span></th></tr></thead>
			<tbody>
			<?php foreach ( $list as $s ) : ?>
				<?php $m = self::meta( $s->ID ); ?>
				<tr>
					<td><?php echo esc_html( $s->post_title ); ?></td>
					<td><?php echo esc_html( self::when( $s->ID ) ); ?></td>
					<td><?php echo (int) self::booked_count( $s->ID ); ?><?php echo $m['capacity'] ? ' / ' . (int) $m['capacity'] : ''; ?></td>
					<td><?php echo (int) self::waiting_count( $s->ID ); ?></td>
					<td><a class="wic-btn wic-btn--small" href="<?php echo esc_url( wic_portal_url( 'rosters', array( 'session' => $s->ID ) ) ); ?>"><?php esc_html_e( 'Roster and attendance', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( $s->post_title ); ?></span></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	private static function view_roster( $uid, $sid ) {
		$m    = self::meta( $sid );
		$rows = self::roster( $sid );
		?>
		<p><a href="<?php echo esc_url( wic_portal_url( 'rosters' ) ); ?>">← <?php esc_html_e( 'All sessions', 'wic-tp' ); ?></a></p>
		<h2 class="wic-h"><?php echo esc_html( get_the_title( $sid ) ); ?></h2>
		<p class="wic-meta"><?php echo esc_html( self::when( $sid ) ); ?><?php echo $m['place'] ? ' · ' . esc_html( $m['place'] ) : ''; ?><?php echo $m['course'] ? ' · ' . esc_html( sprintf( __( 'Attendance completes "%s"', 'wic-tp' ), get_the_title( $m['course'] ) ) ) : ' · ' . esc_html__( 'No linked course — attendance is recorded but writes no completion', 'wic-tp' ); ?></p>
		<?php if ( ! $rows ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'Nobody has booked yet.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<form method="post" action="<?php echo esc_url( WIC_E::post_url() ); ?>">
			<input type="hidden" name="action" value="wic_session_attendance">
			<input type="hidden" name="session" value="<?php echo (int) $sid; ?>">
			<?php wp_nonce_field( 'wic_attendance_' . $sid ); ?>
			<div class="wic-table-wrap">
			<table class="wic-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Name', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Clinic', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Attendance', 'wic-tp' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<?php $u = get_userdata( $r->user_id ); ?>
					<tr>
						<td><?php echo esc_html( $u ? $u->display_name : '#' . $r->user_id ); ?></td>
						<td><?php echo esc_html( wic_user_clinic_name( $r->user_id ) ); ?></td>
						<td><?php echo self::status_badge( $r->status ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td>
							<?php if ( 'waitlist' === $r->status ) : ?>
								<span class="wic-meta"><?php esc_html_e( 'On the waiting list', 'wic-tp' ); ?></span>
							<?php else : ?>
								<fieldset class="wic-inline-form">
									<legend class="screen-reader-text"><?php echo esc_html( sprintf( __( 'Attendance for %s', 'wic-tp' ), $u ? $u->display_name : '' ) ); ?></legend>
									<label><input type="radio" name="att[<?php echo (int) $r->id; ?>]" value="attended" <?php checked( $r->status, 'attended' ); ?>> <?php esc_html_e( 'Attended', 'wic-tp' ); ?></label>
									<label><input type="radio" name="att[<?php echo (int) $r->id; ?>]" value="no_show" <?php checked( $r->status, 'no_show' ); ?>> <?php esc_html_e( 'Did not attend', 'wic-tp' ); ?></label>
									<label><input type="radio" name="att[<?php echo (int) $r->id; ?>]" value="booked" <?php checked( $r->status, 'booked' ); ?>> <?php esc_html_e( 'Not marked', 'wic-tp' ); ?></label>
								</fieldset>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<p><button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Save attendance', 'wic-tp' ); ?></button></p>
		</form>
		<?php
	}

	public static function handle_attendance() {
		global $wpdb;
		$sid = isset( $_POST['session'] ) ? absint( $_POST['session'] ) : 0;
		check_admin_referer( 'wic_attendance_' . $sid );
		$uid = get_current_user_id();
		if ( 'wic_session' !== get_post_type( $sid ) || ! self::can_manage( $uid, $sid ) ) {
			WIC_E::deny();
		}
		$m        = self::meta( $sid );
		$is_admin = user_can( $uid, 'wic_view_all' ) || $m['trainer'] === $uid;
		$att      = isset( $_POST['att'] ) ? (array) wp_unslash( $_POST['att'] ) : array();
		foreach ( $att as $rid => $status ) {
			$status = sanitize_key( $status );
			if ( ! in_array( $status, array( 'attended', 'no_show', 'booked' ), true ) ) {
				continue;
			}
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'roster' ) . ' WHERE id = %d AND session_id = %d', absint( $rid ), $sid ) );
			if ( ! $row || 'waitlist' === $row->status || $row->status === $status ) {
				continue;
			}
			// Supervisors who are not the trainer may only mark their own people.
			if ( ! $is_admin && ! wic_can_see_user( $uid, $row->user_id ) ) {
				continue;
			}
			$wpdb->update( wic_table( 'roster' ), array( 'status' => $status, 'updated_at' => wic_now(), 'marked_by' => $uid ), array( 'id' => $row->id ) );
			wic_audit( 'session_attendance', 'session', $sid, array( 'user' => $row->user_id, 'status' => $status ) );
			if ( 'attended' === $status && $m['course'] && 'wic_course' === get_post_type( $m['course'] ) ) {
				WIC_Records::record_completion( (int) $row->user_id, $m['course'], WIC_Records::current_run( (int) $row->user_id, $m['course'] ), 100, 0, 'attendance' );
			}
			do_action( 'wic_session_attendance', (int) $row->user_id, $sid, $status );
		}
		WIC_Portal::back( 'rosters', 'attendance_saved', array( 'session' => $sid ) );
	}

	public static function person_section( $user_id, $viewer_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'roster' ) . " WHERE user_id = %d AND status <> 'cancelled' ORDER BY booked_at DESC", $user_id ) );
		echo '<section class="wic-section"><h3 class="wic-h3">' . esc_html__( 'Sessions', 'wic-tp' ) . '</h3>';
		if ( ! $rows ) {
			echo '<p class="wic-meta">' . esc_html__( 'No session bookings.', 'wic-tp' ) . '</p></section>';
			return;
		}
		echo '<div class="wic-table-wrap"><table class="wic-table"><thead><tr><th scope="col">' . esc_html__( 'Session', 'wic-tp' ) . '</th><th scope="col">' . esc_html__( 'When', 'wic-tp' ) . '</th><th scope="col">' . esc_html__( 'Status', 'wic-tp' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( get_the_title( $r->session_id ) ) . '</td><td>' . esc_html( self::when( $r->session_id ) ) . '</td><td>' . self::status_badge( $r->status ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table></div></section>';
	}
}

add_action( 'wic_init', array( 'WIC_Sessions', 'init' ) );
add_action( 'wic_register_types', array( 'WIC_Sessions', 'register' ) );
