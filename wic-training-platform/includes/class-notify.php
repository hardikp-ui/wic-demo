<?php
/**
 * One events store feeds the notification centre and the emails.
 * One scheduled job, with a handler per reminder type.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Notify {

	public static function init() {
		add_action( 'wic_cron_tick', array( __CLASS__, 'tick' ) );
		add_action( 'wic_course_completed', array( __CLASS__, 'on_completed' ), 20 );
		add_action( 'wic_daily', array( __CLASS__, 'weekly_digest' ) );
	}

	public static function event( $user_id, $type, $object_id, $message, $send_email = false ) {
		global $wpdb;
		if ( ! $user_id ) {
			return 0;
		}
		$wpdb->insert(
			wic_table( 'events' ),
			array(
				'user_id'    => $user_id,
				'type'       => $type,
				'object_id'  => (int) $object_id,
				'message'    => $message,
				'send_email' => $send_email ? 1 : 0,
				'created_at' => wic_now(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function has_event( $user_id, $type, $object_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . wic_table( 'events' ) . ' WHERE user_id = %d AND type = %s AND object_id = %d LIMIT 1', $user_id, $type, $object_id ) );
	}

	public static function unread_count( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . wic_table( 'events' ) . ' WHERE user_id = %d AND read_at IS NULL', $user_id ) );
	}

	public static function recent( $user_id, $limit = 30 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'events' ) . ' WHERE user_id = %d ORDER BY id DESC LIMIT %d', $user_id, $limit ) );
	}

	/** Marked read when the notification list is opened, not on hover. */
	public static function mark_all_read( $user_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . wic_table( 'events' ) . ' SET read_at = %s WHERE user_id = %d AND read_at IS NULL', wic_now(), $user_id ) );
	}

	/** Every email carries the agency's name and colour from the same configuration record. */
	public static function mail( $to, $subject, $inner_html ) {
		$name    = wic_setting( 'name' );
		$color   = sanitize_hex_color( wic_setting( 'color_primary' ) );
		$logo    = wic_setting( 'logo_url' );
		$header  = $logo ? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $name ) . '" style="max-height:48px">' : esc_html( $name );
		$html    = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#1d2430">'
			. '<div style="background:' . esc_attr( $color ) . ';color:#fff;padding:16px 20px;font-size:18px;font-weight:bold">' . $header . '</div>'
			. '<div style="padding:20px;border:1px solid #e3e6ea;border-top:0;line-height:1.5">' . $inner_html
			. '<p style="margin-top:24px"><a href="' . esc_url( wic_page_url( 'portal' ) ) . '">' . esc_html__( 'Open the training portal', 'wic-tp' ) . '</a></p></div></div>';
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . wp_specialchars_decode( wic_setting( 'sender_name' ) ) . ' <' . get_option( 'admin_email' ) . '>',
		);
		return wp_mail( $to, $subject, $html, $headers );
	}

	public static function on_completed( $completion ) {
		$user   = get_userdata( $completion->user_id );
		$course = get_the_title( $completion->course_id );
		$cert   = WIC_Certificates::for_completion( $completion->id );
		$link   = $cert ? WIC_Certificates::url( $cert ) : wic_page_url( 'portal' );

		$id = self::event( $completion->user_id, 'completed', $completion->course_id, sprintf( __( 'You completed "%s". Your certificate is ready.', 'wic-tp' ), $course ), false );
		self::mark_sent( $id, 'inline' );
		self::mail(
			$user->user_email,
			sprintf( __( 'Certificate: %s', 'wic-tp' ), $course ),
			'<p>' . esc_html( sprintf( __( 'Well done — you completed "%1$s" with a score of %2$d%%.', 'wic-tp' ), $course, $completion->score ) ) . '</p><p><a href="' . esc_url( $link ) . '">' . esc_html__( 'View and download your certificate', 'wic-tp' ) . '</a></p>'
		);

		$sup = wic_reports_to( $completion->user_id );
		if ( $sup ) {
			self::event( $sup, 'team_completed', $completion->course_id, sprintf( __( '%1$s completed "%2$s".', 'wic-tp' ), $user->display_name, $course ), false );
		}
	}

	private static function mark_sent( $event_id, $result ) {
		global $wpdb;
		if ( $event_id ) {
			$wpdb->update( wic_table( 'events' ), array( 'sent_at' => wic_now(), 'send_result' => $result ), array( 'id' => $event_id ) );
		}
	}

	/** Queue: remind-all and every other bulk send goes through here, never inline. */
	public static function send_queue() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . wic_table( 'events' ) . ' WHERE send_email = 1 AND sent_at IS NULL ORDER BY id ASC LIMIT 50' );
		foreach ( $rows as $e ) {
			$user = get_userdata( $e->user_id );
			if ( ! $user || 'active' !== wic_user_status( $e->user_id ) ) {
				self::mark_sent( $e->id, 'skipped' );
				continue;
			}
			$ok = self::mail( $user->user_email, self::subject_for( $e->type ), '<p>' . nl2br( esc_html( $e->message ) ) . '</p>' );
			self::mark_sent( $e->id, $ok ? 'sent' : 'failed' );
		}
	}

	private static function subject_for( $type ) {
		$subjects = array(
			'approval_waiting' => __( 'A registration is waiting for your approval', 'wic-tp' ),
			'reminder'         => __( 'Reminder: training due', 'wic-tp' ),
			'due_soon'         => __( 'Training due soon', 'wic-tp' ),
			'overdue'          => __( 'Training overdue', 'wic-tp' ),
			'escalation'       => __( 'Registrations waiting too long for approval', 'wic-tp' ),
			'overdue_alert'    => __( 'Someone on your team is overdue', 'wic-tp' ),
			'overdue_escalate' => __( 'Overdue training escalated to you', 'wic-tp' ),
			'weekly_digest'    => __( 'Your team this week', 'wic-tp' ),
		);
		$subjects = apply_filters( 'wic_email_subjects', $subjects );
		return isset( $subjects[ $type ] ) ? $subjects[ $type ] : __( 'Training portal notification', 'wic-tp' );
	}

	/** The one scheduled job. Hourly for the send queue, daily for everything else. */
	public static function tick() {
		/** Modules' hourly work (link checks in batches, HR import pulls). */
		do_action( 'wic_hourly' );
		self::send_queue();
		$today = gmdate( 'Y-m-d' );
		if ( get_option( 'wic_last_daily' ) === $today ) {
			return;
		}
		update_option( 'wic_last_daily', $today );
		self::daily_due_and_overdue();
		self::daily_escalation();
		self::daily_scheduled_deactivation();
		/** Modules' daily work (digests, boosters, retention). Weekly jobs check the weekday themselves. */
		do_action( 'wic_daily', $today );
		self::send_queue();
	}

	/**
	 * Reminder, then alert, then escalation: the learner at the due date, their supervisor
	 * N days later, the administrators M days later. Each step is an event, so every
	 * notice is logged with its send result. Not-applicable courses are skipped.
	 */
	private static function daily_due_and_overdue() {
		global $wpdb;
		$soon     = (int) wic_setting( 'reminder_days' );
		$alert    = (int) wic_setting( 'chain_alert_days' );
		$escalate = (int) wic_setting( 'chain_escalate_days' );
		$admins   = null;
		$rows     = $wpdb->get_results( 'SELECT * FROM ' . wic_table( 'assignments' ) . " WHERE status = 'active' AND due_at IS NOT NULL" );
		foreach ( $rows as $a ) {
			if ( 'active' !== wic_user_status( $a->user_id ) || WIC_Records::latest_completion( $a->user_id, $a->course_id ) ) {
				continue;
			}
			if ( class_exists( 'WIC_Assign' ) && WIC_Assign::na_for( $a->id ) ) {
				continue;
			}
			$due   = strtotime( $a->due_at . ' UTC' );
			$title = get_the_title( $a->course_id );
			if ( $due >= time() ) {
				if ( $due - time() <= $soon * DAY_IN_SECONDS && ! self::has_event( $a->user_id, 'due_soon', $a->id ) ) {
					self::event( $a->user_id, 'due_soon', $a->id, sprintf( __( '"%1$s" is due on %2$s.', 'wic-tp' ), $title, wic_format_date( $a->due_at ) ), true );
				}
				continue;
			}
			$late   = (int) floor( ( time() - $due ) / DAY_IN_SECONDS );
			$person = get_userdata( $a->user_id );
			$name   = $person ? $person->display_name : '#' . $a->user_id;
			if ( ! self::has_event( $a->user_id, 'overdue', $a->id ) ) {
				self::event( $a->user_id, 'overdue', $a->id, sprintf( __( '"%1$s" was due on %2$s and is now overdue.', 'wic-tp' ), $title, wic_format_date( $a->due_at ) ), true );
			}
			$sup = wic_reports_to( $a->user_id );
			if ( $alert > 0 && $late >= $alert && $sup && ! self::has_event( $sup, 'overdue_alert', $a->id ) ) {
				self::event( $sup, 'overdue_alert', $a->id, sprintf( __( '%1$s is %2$d days late with "%3$s" (due %4$s).', 'wic-tp' ), $name, $late, $title, wic_format_date( $a->due_at ) ), true );
			}
			if ( $escalate > 0 && $late >= $escalate ) {
				if ( null === $admins ) {
					$admins = get_users( array( 'role' => 'wic_admin', 'fields' => 'ID' ) );
				}
				$sup_user = $sup ? get_userdata( $sup ) : null;
				foreach ( $admins as $admin_id ) {
					if ( (int) $admin_id !== (int) $a->user_id && ! self::has_event( (int) $admin_id, 'overdue_escalate', $a->id ) ) {
						self::event( (int) $admin_id, 'overdue_escalate', $a->id, sprintf( __( '%1$s is %2$d days late with "%3$s". Supervisor: %4$s.', 'wic-tp' ), $name, $late, $title, $sup_user ? $sup_user->display_name : __( 'none', 'wic-tp' ) ), true );
					}
				}
			}
		}
	}

	/**
	 * Monday summary for each supervisor: overdue, due this week, completed last week.
	 * Nothing is sent when there is nothing to say, or when the person has opted out.
	 */
	public static function weekly_digest( $today ) {
		global $wpdb;
		if ( '1' !== gmdate( 'N', strtotime( $today . ' UTC' ) ) || get_option( 'wic_last_digest' ) === $today ) {
			return;
		}
		update_option( 'wic_last_digest', $today );
		$sups = get_users( array( 'role__in' => apply_filters( 'wic_supervisor_roles', array( 'wic_supervisor', 'wic_admin' ) ) ) );
		foreach ( $sups as $s ) {
			if ( 'active' !== wic_user_status( $s->ID ) || get_user_meta( $s->ID, 'wic_digest_optout', true ) || ! user_can( $s, 'wic_view_team' ) ) {
				continue;
			}
			$overdue = array();
			$week    = array();
			$done    = array();
			foreach ( array_diff( wic_scope_user_ids( $s->ID ), array( (int) $s->ID ) ) as $id ) {
				if ( 'active' !== wic_user_status( $id ) ) {
					continue;
				}
				$u = get_userdata( $id );
				foreach ( WIC_Records::user_assignments( $id ) as $a ) {
					if ( 'overdue' === $a['status'] ) {
						$overdue[] = sprintf( '%s — %s (%d days late)', $u->display_name, $a['title'], $a['days_late'] );
					} elseif ( $a['due_at'] && ! in_array( $a['status'], array( 'complete', 'expired', 'not_applicable' ), true ) && strtotime( $a['due_at'] . ' UTC' ) - time() <= 7 * DAY_IN_SECONDS ) {
						$week[] = sprintf( '%s — %s (due %s)', $u->display_name, $a['title'], wic_format_date( $a['due_at'] ) );
					}
				}
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT course_id FROM ' . wic_table( 'completions' ) . ' WHERE user_id = %d AND completed_at >= %s', $id, gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) );
				foreach ( $rows as $r ) {
					$done[] = sprintf( '%s — %s', $u->display_name, get_the_title( $r->course_id ) );
				}
			}
			if ( ! $overdue && ! $week && ! $done ) {
				continue;
			}
			$lines = array( sprintf( __( 'Your team this week, %s.', 'wic-tp' ), $s->display_name ) );
			foreach ( array(
				array( __( 'Overdue', 'wic-tp' ), $overdue ),
				array( __( 'Due in the next 7 days', 'wic-tp' ), $week ),
				array( __( 'Completed in the last 7 days', 'wic-tp' ), $done ),
			) as $sec ) {
				$lines[] = '';
				$lines[] = sprintf( '%s (%d)', $sec[0], count( $sec[1] ) );
				foreach ( array_slice( $sec[1], 0, 25 ) as $l ) {
					$lines[] = '• ' . $l;
				}
				if ( count( $sec[1] ) > 25 ) {
					$lines[] = sprintf( __( '…and %d more in the portal.', 'wic-tp' ), count( $sec[1] ) - 25 );
				}
			}
			self::event( $s->ID, 'weekly_digest', (int) gmdate( 'Ymd', strtotime( $today . ' UTC' ) ), implode( "\n", $lines ), true );
		}
	}

	/** Approvals left too long are flagged to the administrators, who can act directly. */
	private static function daily_escalation() {
		$days    = max( 1, (int) wic_setting( 'escalation_days' ) );
		$pending = get_users(
			array(
				'meta_key'   => 'wic_status',
				'meta_value' => 'pending',
			)
		);
		$admins  = get_users( array( 'role' => 'wic_admin' ) );
		foreach ( $pending as $p ) {
			if ( strtotime( $p->user_registered . ' UTC' ) > time() - $days * DAY_IN_SECONDS ) {
				continue;
			}
			$sup  = get_userdata( wic_reports_to( $p->ID ) );
			$name = $sup ? $sup->display_name : __( 'no supervisor', 'wic-tp' );
			foreach ( $admins as $admin ) {
				if ( ! self::has_event( $admin->ID, 'escalation', $p->ID ) ) {
					self::event( $admin->ID, 'escalation', $p->ID, sprintf( __( '%1$s has waited more than %2$d days for approval by %3$s.', 'wic-tp' ), $p->display_name, $days, $name ), true );
				}
			}
		}
	}

	private static function daily_scheduled_deactivation() {
		$users = get_users(
			array(
				'meta_key'     => 'wic_deactivate_on',
				'meta_value'   => gmdate( 'Y-m-d' ),
				'meta_compare' => '<=',
				'meta_type'    => 'DATE',
			)
		);
		foreach ( $users as $u ) {
			if ( get_user_meta( $u->ID, 'wic_deactivate_on', true ) && 'active' === wic_user_status( $u->ID ) ) {
				WIC_Roles::set_status( $u->ID, 'deactivated' );
			}
		}
	}
}
