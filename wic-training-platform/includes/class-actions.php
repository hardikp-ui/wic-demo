<?php
/**
 * Form handlers. Every one checks a nonce, a capability, and the shared scoping rule.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Actions {

	public static function init() {
		foreach ( array( 'approve', 'reject', 'remind_all', 'assign', 'move_team', 'extend', 'export', 'set_status', 'revoke' ) as $a ) {
			add_action( 'admin_post_wic_' . $a, array( __CLASS__, $a ) );
		}
	}

	private static function back( $view, $msg ) {
		wp_safe_redirect( wic_page_url( 'portal', array( 'view' => $view, 'wic_msg' => $msg ) ) );
		exit;
	}

	private static function deny() {
		wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
	}

	public static function approve() {
		$user = isset( $_POST['user'] ) ? absint( $_POST['user'] ) : 0;
		check_admin_referer( 'wic_decide_' . $user );
		if ( ! WIC_Registration::can_approve( get_current_user_id(), $user ) ) {
			self::deny();
		}
		WIC_Registration::approve( $user, isset( $_POST['group'] ) ? sanitize_key( $_POST['group'] ) : 'staff' );
		self::back( 'approvals', 'approved' );
	}

	public static function reject() {
		$user = isset( $_POST['user'] ) ? absint( $_POST['user'] ) : 0;
		check_admin_referer( 'wic_decide_' . $user );
		if ( ! WIC_Registration::can_approve( get_current_user_id(), $user ) ) {
			self::deny();
		}
		WIC_Registration::reject( $user );
		self::back( 'approvals', 'rejected' );
	}

	/** Remind everyone at once: queued, not fired inline, and logged. */
	public static function remind_all() {
		check_admin_referer( 'wic_remind_all' );
		if ( ! current_user_can( 'wic_view_team' ) ) {
			self::deny();
		}
		$clinic = isset( $_POST['clinic'] ) ? sanitize_text_field( wp_unslash( $_POST['clinic'] ) ) : '';
		$rows   = WIC_Portal::overdue_rows( get_current_user_id(), $clinic );
		foreach ( $rows as $r ) {
			WIC_Notify::event(
				$r['user']->ID,
				'reminder',
				$r['assignment_id'],
				sprintf( __( 'Reminder from your supervisor: "%1$s" was due on %2$s. Please complete it as soon as you can.', 'wic-tp' ), $r['title'], wic_format_date( $r['due_at'] ) ),
				true
			);
		}
		wic_audit( 'remind_all', 'team', get_current_user_id(), array( 'count' => count( $rows ) ) );
		self::back( 'overdue', 'reminded' );
	}

	/**
	 * Assignment targets a person, a group, a role, a clinic or everyone in scope.
	 * Administrators reach the whole agency; supervisors only their own people.
	 */
	public static function assign() {
		check_admin_referer( 'wic_assign' );
		if ( ! current_user_can( 'wic_view_team' ) ) {
			self::deny();
		}
		$uid    = get_current_user_id();
		$return = isset( $_POST['return'] ) ? sanitize_key( $_POST['return'] ) : 'agency';
		$course = isset( $_POST['course'] ) ? absint( $_POST['course'] ) : 0;
		$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
		$due    = isset( $_POST['due'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_POST['due'] ) ? get_gmt_from_date( sanitize_text_field( $_POST['due'] ) . ' 23:59:59' ) : null;

		$ids = array();
		foreach ( WIC_Assign::people_in_scope( $uid ) as $u ) {
			$id = (int) $u->ID;
			if ( 'all' === $target
				|| ( 'group:' . wic_user_group( $id ) ) === $target
				|| ( 'user:' . $id ) === $target
				|| ( 0 === strpos( $target, 'role:' ) && in_array( substr( $target, 5 ), (array) $u->roles, true ) )
				|| ( 0 === strpos( $target, 'clinic:' ) && WIC_Assign::user_in_clinic( $id, substr( $target, 7 ) ) ) ) {
				$ids[] = $id;
			}
		}
		foreach ( $ids as $id ) {
			WIC_Records::assign( $id, $course, 'manual', $due );
		}
		wic_audit( 'assign_bulk', 'course', $course, array( 'target' => $target, 'count' => count( $ids ) ) );
		self::back( $return, $ids ? 'assigned' : 'error' );
	}

	/** Bulk update of the reports-to field only — progress records are never touched. */
	public static function move_team() {
		check_admin_referer( 'wic_move_team' );
		if ( ! current_user_can( 'wic_manage_people' ) ) {
			self::deny();
		}
		$from = isset( $_POST['from'] ) ? absint( $_POST['from'] ) : 0;
		$to   = isset( $_POST['to'] ) ? absint( $_POST['to'] ) : 0;
		if ( ! $from || ! $to || $from === $to ) {
			self::back( 'agency', 'error' );
		}
		$ids = get_users(
			array(
				'meta_key'   => 'wic_reports_to',
				'meta_value' => $from,
				'fields'     => 'ID',
			)
		);
		foreach ( $ids as $id ) {
			if ( (int) $id !== $to ) {
				update_user_meta( $id, 'wic_reports_to', $to );
			}
		}
		wic_audit( 'move_team', 'user', $from, array( 'to' => $to, 'count' => count( $ids ) ) );
		self::back( 'agency', 'moved' );
	}

	public static function extend() {
		global $wpdb;
		$id = isset( $_POST['assignment'] ) ? absint( $_POST['assignment'] ) : 0;
		check_admin_referer( 'wic_extend_' . $id );
		$a = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'assignments' ) . ' WHERE id = %d', $id ) );
		if ( ! $a || ! current_user_can( 'wic_view_team' ) || ! wic_can_see_user( get_current_user_id(), $a->user_id ) ) {
			self::deny();
		}
		$due    = isset( $_POST['due'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_POST['due'] ) ? get_gmt_from_date( sanitize_text_field( $_POST['due'] ) . ' 23:59:59' ) : '';
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		if ( ! $due || ! $reason ) {
			self::back( 'overdue', 'error' );
		}
		WIC_Records::extend_assignment( $id, $due, $reason );
		self::back( 'overdue', 'extended' );
	}

	/** One report engine: the supervisor's team export and the agency export are the same query, scoped. */
	public static function export() {
		check_admin_referer( 'wic_export' );
		if ( ! current_user_can( 'wic_view_team' ) ) {
			self::deny();
		}
		$uid    = get_current_user_id();
		$clinic = isset( $_GET['clinic'] ) ? sanitize_text_field( wp_unslash( $_GET['clinic'] ) ) : '';
		$rows   = array( array( 'Name', 'Email', 'Staff number', 'Clinic', 'Group', 'Account status', 'Supervisor', 'Course', 'Required', 'Status', 'Note', 'Progress %', 'Due', 'Completed', 'Score %', 'Certificate' ) );
		foreach ( wic_scope_user_ids( $uid ) as $id ) {
			if ( $id === $uid && ! current_user_can( 'wic_view_all' ) ) {
				continue;
			}
			$u = get_userdata( $id );
			if ( ! $u || ( '' !== $clinic && ! WIC_Assign::user_in_clinic( $id, $clinic ) ) ) {
				continue;
			}
			$sup   = get_userdata( wic_reports_to( $id ) );
			$base  = array( $u->display_name, $u->user_email, get_user_meta( $id, 'wic_staff_number', true ), wic_user_clinic_name( $id ), wic_group_label( wic_user_group( $id ) ), wic_user_status( $id ), $sup ? $sup->display_name : '' );
			$items = WIC_Records::user_assignments( $id );
			if ( ! $items ) {
				$rows[] = array_merge( $base, array( '', '', wic_status_label( 'never_assigned' ), '', '', '', '', '', '' ) );
			}
			foreach ( $items as $a ) {
				$na     = 'not_applicable' === $a['status'] ? WIC_Assign::na_for( $a['assignment_id'] ) : null;
				$rows[] = array_merge(
					$base,
					array(
						$a['title'],
						$a['required'] ? 'Yes' : 'No',
						wic_status_label( $a['status'] ),
						$na ? $na->reason : '',
						$a['progress'],
						$a['due_at'] ? get_date_from_gmt( $a['due_at'], 'Y-m-d' ) : '',
						$a['completion'] ? get_date_from_gmt( $a['completion']->completed_at, 'Y-m-d' ) : '',
						$a['completion'] ? $a['completion']->score : '',
						$a['certificate'] ? $a['certificate']->cert_number : '',
					)
				);
			}
		}
		wic_audit( 'export', 'report', 0, array( 'type' => 'team', 'clinic' => $clinic ) );
		wic_send_csv( 'training-record-' . gmdate( 'Y-m-d' ) . '.csv', $rows );
	}

	public static function set_status() {
		$user = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
		check_admin_referer( 'wic_set_status_' . $user );
		if ( ! current_user_can( 'wic_manage_people' ) ) {
			self::deny();
		}
		$status = isset( $_GET['status'] ) && 'active' === $_GET['status'] ? 'active' : 'deactivated';
		WIC_Roles::set_status( $user, $status );
		wp_safe_redirect( admin_url( 'users.php' ) );
		exit;
	}

	public static function revoke() {
		$id = isset( $_POST['cert'] ) ? absint( $_POST['cert'] ) : 0;
		check_admin_referer( 'wic_revoke_' . $id );
		if ( ! current_user_can( 'wic_manage_people' ) ) {
			self::deny();
		}
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		WIC_Certificates::revoke( $id, $reason );
		wp_safe_redirect( admin_url( 'admin.php?page=wic-certificates&revoked=1' ) );
		exit;
	}
}
