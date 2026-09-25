<?php
/**
 * Notice settings (the reminder → alert → escalation chain, the weekly digest) and the
 * "Notices sent" log: every queued notice with its recipient and send result.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_agency_defaults',
	function ( $d ) {
		return $d + array(
			'chain_alert_days'         => 3,
			'chain_escalate_days'      => 10,
			'decision_retention_years' => 0,
		);
	}
);

add_filter(
	'wic_agency_fields',
	function ( $f ) {
		return $f + array(
			'chain_alert_days'         => array( __( 'Alert the supervisor this many days after a due date is missed', 'wic-tp' ), 'number', __( 'The learner is reminded on the due date. 0 = never alert.', 'wic-tp' ) ),
			'chain_escalate_days'      => array( __( 'Escalate to administrators this many days after a missed due date', 'wic-tp' ), 'number', __( '0 = never escalate.', 'wic-tp' ) ),
			'decision_retention_years' => array( __( 'Records retention period (years after an account is closed)', 'wic-tp' ), 'number', __( 'Open decision: how long records are kept is agency policy, not a technical choice. 0 = keep everything. When set, closed accounts past the period are listed for review and can be anonymised one at a time; completion records are never deleted.', 'wic-tp' ) ),
		);
	}
);

add_action(
	'wic_init',
	function () {
		WIC_Notice_Log::init();
	}
);

class WIC_Notice_Log {

	const TYPES = array( 'due_soon', 'overdue', 'reminder', 'overdue_alert', 'overdue_escalate', 'escalation', 'approval_waiting', 'weekly_digest' );

	public static function init() {
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		add_action( 'admin_post_wic_digest_pref', array( __CLASS__, 'digest_pref' ) );
		add_filter(
			'wic_flash_messages',
			function ( $m ) {
				return $m + array( 'digest_saved' => __( 'Weekly summary preference saved.', 'wic-tp' ) );
			}
		);
	}

	public static function views( $views ) {
		$views['notices'] = array(
			'label'    => __( 'Notices sent', 'wic-tp' ),
			'group'    => 'team',
			'cap'      => 'wic_view_team',
			'callback' => array( __CLASS__, 'view' ),
			'order'    => 60,
		);
		return $views;
	}

	public static function type_label( $type ) {
		$labels = array(
			'due_soon'         => __( 'Due soon', 'wic-tp' ),
			'overdue'          => __( 'Overdue — learner', 'wic-tp' ),
			'reminder'         => __( 'Reminder', 'wic-tp' ),
			'overdue_alert'    => __( 'Overdue — supervisor alert', 'wic-tp' ),
			'overdue_escalate' => __( 'Overdue — escalated', 'wic-tp' ),
			'escalation'       => __( 'Approval escalation', 'wic-tp' ),
			'approval_waiting' => __( 'Approval waiting', 'wic-tp' ),
			'weekly_digest'    => __( 'Weekly summary', 'wic-tp' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : ucfirst( str_replace( '_', ' ', $type ) );
	}

	public static function result_label( $e ) {
		if ( ! $e->sent_at ) {
			return __( 'Queued', 'wic-tp' );
		}
		$labels = array(
			'sent'    => __( 'Sent', 'wic-tp' ),
			'failed'  => __( 'Failed', 'wic-tp' ),
			'skipped' => __( 'Skipped (account not active)', 'wic-tp' ),
			'inline'  => __( 'Sent', 'wic-tp' ),
		);
		return isset( $labels[ $e->send_result ] ) ? $labels[ $e->send_result ] : (string) $e->send_result;
	}

	public static function view( $uid ) {
		global $wpdb;
		$type   = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
		$result = isset( $_GET['result'] ) ? sanitize_key( $_GET['result'] ) : '';
		$ids    = array_unique( array_merge( wic_scope_user_ids( $uid ), array( (int) $uid ) ) );
		$where  = 'send_email = 1 AND user_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
		if ( $type ) {
			$where .= $wpdb->prepare( ' AND type = %s', $type );
		}
		if ( 'queued' === $result ) {
			$where .= ' AND sent_at IS NULL';
		} elseif ( $result ) {
			$where .= $wpdb->prepare( ' AND send_result = %s', $result );
		}
		$rows = $wpdb->get_results( 'SELECT * FROM ' . wic_table( 'events' ) . " WHERE $where ORDER BY id DESC LIMIT 300" ); // phpcs:ignore WordPress.DB.PreparedSQL
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Notices sent', 'wic-tp' ); ?></h2>
		<p class="wic-help"><?php printf( esc_html__( 'Every email notice to you and the people you manage: the reminder on the due date, the alert to the supervisor after %1$d days, and escalation to administrators after %2$d days. Latest 300.', 'wic-tp' ), (int) wic_setting( 'chain_alert_days' ), (int) wic_setting( 'chain_escalate_days' ) ); ?></p>
		<form method="get" class="wic-form wic-form--inline" action="<?php echo esc_url( wic_page_url( 'portal' ) ); ?>">
			<input type="hidden" name="view" value="notices">
			<?php WIC_Reports::page_id_field(); ?>
			<div class="wic-field">
				<label for="wic-nl-type"><?php esc_html_e( 'Type', 'wic-tp' ); ?></label>
				<select id="wic-nl-type" name="type">
					<option value=""><?php esc_html_e( 'All types', 'wic-tp' ); ?></option>
					<?php foreach ( self::TYPES as $t ) : ?>
						<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type, $t ); ?>><?php echo esc_html( self::type_label( $t ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="wic-field">
				<label for="wic-nl-result"><?php esc_html_e( 'Result', 'wic-tp' ); ?></label>
				<select id="wic-nl-result" name="result">
					<option value=""><?php esc_html_e( 'Any result', 'wic-tp' ); ?></option>
					<option value="queued" <?php selected( $result, 'queued' ); ?>><?php esc_html_e( 'Queued', 'wic-tp' ); ?></option>
					<option value="sent" <?php selected( $result, 'sent' ); ?>><?php esc_html_e( 'Sent', 'wic-tp' ); ?></option>
					<option value="failed" <?php selected( $result, 'failed' ); ?>><?php esc_html_e( 'Failed', 'wic-tp' ); ?></option>
					<option value="skipped" <?php selected( $result, 'skipped' ); ?>><?php esc_html_e( 'Skipped', 'wic-tp' ); ?></option>
				</select>
			</div>
			<button type="submit" class="wic-btn"><?php esc_html_e( 'Filter', 'wic-tp' ); ?></button>
		</form>
		<?php if ( ! $rows ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'No notices match.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<div class="wic-table-wrap">
		<table class="wic-table" data-wic-sortable>
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Created', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'To', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Type', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Message', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Result', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Sent', 'wic-tp' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $e ) : ?>
				<?php $to = get_userdata( $e->user_id ); ?>
				<tr>
					<td data-sort="<?php echo esc_attr( $e->created_at ); ?>"><?php echo esc_html( wic_format_date( $e->created_at, true ) ); ?></td>
					<td><?php echo esc_html( $to ? $to->display_name : '#' . $e->user_id ); ?></td>
					<td><?php echo esc_html( self::type_label( $e->type ) ); ?></td>
					<td><?php echo esc_html( wp_trim_words( $e->message, 30 ) ); ?></td>
					<td><?php echo 'failed' === $e->send_result ? '<strong class="wic-late">' . esc_html( self::result_label( $e ) ) . '</strong>' : esc_html( self::result_label( $e ) ); ?></td>
					<td data-sort="<?php echo esc_attr( (string) $e->sent_at ); ?>"><?php echo esc_html( wic_format_date( $e->sent_at, true ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/** Weekly summary opt-out, shown in the notifications view for anyone who manages people. */
	public static function digest_form( $uid ) {
		if ( ! user_can( $uid, 'wic_view_team' ) ) {
			return;
		}
		$off = (bool) get_user_meta( $uid, 'wic_digest_optout', true );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-toolbar wic-panel" style="margin-top:1.5rem">
			<input type="hidden" name="action" value="wic_digest_pref">
			<?php wp_nonce_field( 'wic_digest_pref' ); ?>
			<label><input type="checkbox" name="digest" value="1" <?php checked( ! $off ); ?>> <?php esc_html_e( 'Email me a summary of my team every Monday (overdue, due this week, completed last week)', 'wic-tp' ); ?></label>
			<button type="submit" class="wic-btn wic-btn--small"><?php esc_html_e( 'Save', 'wic-tp' ); ?></button>
		</form>
		<?php
	}

	public static function digest_pref() {
		check_admin_referer( 'wic_digest_pref' );
		if ( ! current_user_can( 'wic_view_team' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
		}
		update_user_meta( get_current_user_id(), 'wic_digest_optout', empty( $_POST['digest'] ) ? '1' : '' );
		WIC_Portal::back( 'notifications', 'digest_saved' );
	}
}
