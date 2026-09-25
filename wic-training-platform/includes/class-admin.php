<?php
/**
 * WP-admin screens: overview, agency settings, certificates, reports, audit log.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wic_demo_accounts', array( __CLASS__, 'demo_accounts' ) );
	}

	public static function menu() {
		add_menu_page( __( 'WIC Platform', 'wic-tp' ), __( 'WIC Platform', 'wic-tp' ), 'wic_manage_content', 'wic-platform', array( __CLASS__, 'overview' ), 'dashicons-welcome-learn-more', 26 );
		add_submenu_page( 'wic-platform', __( 'Overview', 'wic-tp' ), __( 'Overview', 'wic-tp' ), 'wic_manage_content', 'wic-platform', array( __CLASS__, 'overview' ) );
		add_submenu_page( 'wic-platform', __( 'Agency settings', 'wic-tp' ), __( 'Agency settings', 'wic-tp' ), 'wic_manage_settings', 'wic-agency', array( 'WIC_Agency', 'page' ) );
		add_submenu_page( 'wic-platform', __( 'Certificates', 'wic-tp' ), __( 'Certificates', 'wic-tp' ), 'wic_manage_people', 'wic-certificates', array( __CLASS__, 'certificates' ) );
		add_submenu_page( 'wic-platform', __( 'Reports', 'wic-tp' ), __( 'Reports', 'wic-tp' ), 'wic_view_all', 'wic-reports', array( __CLASS__, 'reports' ) );
		add_submenu_page( 'wic-platform', __( 'Audit log', 'wic-tp' ), __( 'Audit log', 'wic-tp' ), 'wic_manage_people', 'wic-audit', array( __CLASS__, 'audit' ) );
	}

	public static function overview() {
		global $wpdb;
		$counts = array(
			__( 'Courses', 'wic-tp' )       => wp_count_posts( 'wic_course' )->publish,
			__( 'Modules', 'wic-tp' )       => wp_count_posts( 'wic_module' )->publish,
			__( 'Slides', 'wic-tp' )        => wp_count_posts( 'wic_slide' )->publish,
			__( 'Assignments', 'wic-tp' )   => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . wic_table( 'assignments' ) . " WHERE status = 'active'" ),
			__( 'Completions', 'wic-tp' )   => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . wic_table( 'completions' ) ),
			__( 'Certificates', 'wic-tp' )  => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . wic_table( 'certificates' ) ),
		);
		$demo = get_transient( 'wic_demo_accounts_' . get_current_user_id() );
		if ( $demo ) {
			delete_transient( 'wic_demo_accounts_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WIC Training Platform', 'wic-tp' ); ?></h1>
			<?php if ( $demo ) : ?>
				<div class="notice notice-success"><p><strong><?php esc_html_e( 'Demo accounts created. These passwords are shown once — note them now.', 'wic-tp' ); ?></strong></p>
					<ul><?php foreach ( $demo as $d ) : ?><li><code><?php echo esc_html( $d['login'] ); ?></code> / <code><?php echo esc_html( $d['pass'] ); ?></code> — <?php echo esc_html( $d['role'] ); ?></li><?php endforeach; ?></ul></div>
			<?php endif; ?>
			<table class="widefat striped" style="max-width:520px"><tbody>
				<?php foreach ( $counts as $label => $n ) : ?>
					<tr><td><?php echo esc_html( $label ); ?></td><td><strong><?php echo (int) $n; ?></strong></td></tr>
				<?php endforeach; ?>
			</tbody></table>
			<h2><?php esc_html_e( 'Pages', 'wic-tp' ); ?></h2>
			<ul>
				<?php foreach ( array_keys( WIC_Install::page_defs() ) as $key ) : ?>
					<?php if ( 'learn' === $key || 'certificate' === $key ) { continue; } ?>
					<li><a href="<?php echo esc_url( wic_page_url( $key ) ); ?>" target="_blank"><?php echo esc_html( get_the_title( wic_page_id( $key ) ) ); ?></a></li>
				<?php endforeach; ?>
			</ul>
			<h2><?php esc_html_e( 'Authoring', 'wic-tp' ); ?></h2>
			<p><?php esc_html_e( 'Build a course as Course → Modules → Slides. Set each module\'s course and each slide\'s module in its settings box, and order them with "Order" in Page Attributes.', 'wic-tp' ); ?></p>
			<?php if ( current_user_can( 'wic_manage_people' ) ) : ?>
				<h2><?php esc_html_e( 'Testing', 'wic-tp' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wic_demo_accounts">
					<?php wp_nonce_field( 'wic_demo_accounts' ); ?>
					<p><?php esc_html_e( 'Creates a demo supervisor and two demo learners reporting to them, so every view can be tried. Remove before going live — they are closed, not deleted, like everyone else.', 'wic-tp' ); ?></p>
					<?php submit_button( __( 'Create demo accounts', 'wic-tp' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function demo_accounts() {
		check_admin_referer( 'wic_demo_accounts' );
		if ( ! current_user_can( 'wic_manage_people' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ) );
		}
		$made = array();
		$defs = array(
			array( 'demo-supervisor', 'Dana', 'Supervisor', 'wic_supervisor', 'staff', 'North Clinic (Sample)' ),
			array( 'demo-staff', 'Sam', 'Staff', 'wic_learner', 'staff', 'North Clinic (Sample)' ),
			array( 'demo-intern', 'Ira', 'Intern', 'wic_learner', 'intern', 'South Clinic (Sample)' ),
		);
		$sup_id = 0;
		foreach ( $defs as $d ) {
			if ( username_exists( $d[0] ) ) {
				$u = get_user_by( 'login', $d[0] );
				if ( 'wic_supervisor' === $d[3] ) {
					$sup_id = $u->ID;
				}
				continue;
			}
			$pass = wp_generate_password( 16, false );
			$id   = wp_insert_user(
				array(
					'user_login'   => $d[0],
					'user_pass'    => $pass,
					'user_email'   => $d[0] . '@example.invalid',
					'first_name'   => $d[1],
					'last_name'    => $d[2],
					'display_name' => $d[1] . ' ' . $d[2],
					'role'         => $d[3],
				)
			);
			if ( is_wp_error( $id ) ) {
				continue;
			}
			update_user_meta( $id, 'wic_status', 'active' );
			update_user_meta( $id, 'wic_group', $d[4] );
			if ( class_exists( 'WIC_Clinics' ) ) {
				WIC_Clinics::set_user_clinic( $id, (int) WIC_Clinics::ensure( $d[5] ) );
			} else {
				update_user_meta( $id, 'wic_clinic', $d[5] );
			}
			if ( 'wic_supervisor' === $d[3] ) {
				$sup_id = $id;
				update_user_meta( $id, 'wic_reports_to', get_current_user_id() );
			} else {
				update_user_meta( $id, 'wic_reports_to', $sup_id );
			}
			WIC_Records::apply_rules( $id );
			wic_audit( 'demo_account', 'user', $id );
			$made[] = array(
				'login' => $d[0],
				'pass'  => $pass,
				'role'  => $d[3],
			);
		}
		set_transient( 'wic_demo_accounts_' . get_current_user_id(), $made, 300 );
		wp_safe_redirect( admin_url( 'admin.php?page=wic-platform' ) );
		exit;
	}

	public static function certificates() {
		global $wpdb;
		$certs = $wpdb->get_results( 'SELECT * FROM ' . wic_table( 'certificates' ) . ' ORDER BY id DESC LIMIT 200' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Certificates', 'wic-tp' ); ?></h1>
			<?php if ( isset( $_GET['revoked'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Certificate revoked. The check page now reports it as revoked.', 'wic-tp' ); ?></p></div><?php endif; ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Number', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Name', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Course', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Issued', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Status', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Actions', 'wic-tp' ); ?></th></tr></thead>
				<tbody>
				<?php if ( ! $certs ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No certificates issued yet.', 'wic-tp' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $certs as $c ) : ?>
					<tr>
						<td><code><?php echo esc_html( $c->cert_number ); ?></code></td>
						<td><?php echo esc_html( $c->learner_name ); ?></td>
						<td><?php echo esc_html( $c->course_title ); ?></td>
						<td><?php echo esc_html( wic_format_date( $c->issued_at ) ); ?></td>
						<td><?php echo esc_html( ucfirst( WIC_Certificates::state( $c ) ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( WIC_Certificates::url( $c ) ); ?>" target="_blank"><?php esc_html_e( 'View', 'wic-tp' ); ?></a>
							<?php if ( 'revoked' !== $c->status ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-flex;gap:4px;margin-left:8px">
									<input type="hidden" name="action" value="wic_revoke">
									<input type="hidden" name="cert" value="<?php echo (int) $c->id; ?>">
									<?php wp_nonce_field( 'wic_revoke_' . $c->id ); ?>
									<input type="text" name="reason" required placeholder="<?php esc_attr_e( 'Reason', 'wic-tp' ); ?>">
									<button class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Revoke this certificate?', 'wic-tp' ) ); ?>')"><?php esc_html_e( 'Revoke', 'wic-tp' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** Most-missed questions and completion by clinic: both are plain queries over stored rows. */
	public static function reports() {
		global $wpdb;
		$missed = $wpdb->get_results(
			'SELECT slide_id, COUNT(*) AS answers, SUM(correct = 0) AS wrong FROM ' . wic_table( 'attempts' ) . ' GROUP BY slide_id HAVING answers > 0 ORDER BY (SUM(correct = 0) / COUNT(*)) DESC, answers DESC LIMIT 20'
		);
		$clinics = array();
		foreach ( wic_scope_user_ids( get_current_user_id() ) as $id ) {
			if ( 'active' !== wic_user_status( $id ) ) {
				continue;
			}
			$clinic = wic_user_clinic_name( $id );
			$clinic = $clinic ? $clinic : __( '(no clinic)', 'wic-tp' );
			if ( ! isset( $clinics[ $clinic ] ) ) {
				$clinics[ $clinic ] = array( 'assigned' => 0, 'complete' => 0, 'overdue' => 0 );
			}
			foreach ( WIC_Records::user_assignments( $id ) as $a ) {
				$clinics[ $clinic ]['assigned']++;
				if ( 'complete' === $a['status'] ) {
					$clinics[ $clinic ]['complete']++;
				}
				if ( 'overdue' === $a['status'] ) {
					$clinics[ $clinic ]['overdue']++;
				}
			}
		}
		ksort( $clinics );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reports', 'wic-tp' ); ?></h1>
			<p><?php esc_html_e( 'The full reports — compliance, certification gaps, where people stop, transcripts and saved reports, with CSV and PDF downloads — are in the portal:', 'wic-tp' ); ?>
				<a href="<?php echo esc_url( wic_portal_url( 'reports' ) ); ?>"><?php esc_html_e( 'Open portal reports', 'wic-tp' ); ?></a></p>
			<h2><?php esc_html_e( 'Completion by clinic', 'wic-tp' ); ?></h2>
			<table class="widefat striped" style="max-width:720px">
				<thead><tr><th><?php esc_html_e( 'Clinic', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Assigned', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Complete', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Rate', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Overdue', 'wic-tp' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $clinics as $name => $c ) : ?>
					<tr><td><?php echo esc_html( $name ); ?></td><td><?php echo (int) $c['assigned']; ?></td><td><?php echo (int) $c['complete']; ?></td><td><?php echo $c['assigned'] ? (int) round( $c['complete'] / $c['assigned'] * 100 ) . '%' : '—'; ?></td><td><?php echo (int) $c['overdue']; ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<h2><?php esc_html_e( 'Most-missed questions', 'wic-tp' ); ?></h2>
			<p><?php esc_html_e( 'Questions answered wrongly most often. A high rate usually means the content needs work, not the staff.', 'wic-tp' ); ?></p>
			<table class="widefat striped" style="max-width:720px">
				<thead><tr><th><?php esc_html_e( 'Question slide', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Answers', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Wrong', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Wrong rate', 'wic-tp' ); ?></th></tr></thead>
				<tbody>
				<?php if ( ! $missed ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No answers recorded yet.', 'wic-tp' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $missed as $m ) : ?>
					<tr><td><a href="<?php echo esc_url( get_edit_post_link( $m->slide_id ) ); ?>"><?php echo esc_html( get_the_title( $m->slide_id ) ); ?></a></td><td><?php echo (int) $m->answers; ?></td><td><?php echo (int) $m->wrong; ?></td><td><?php echo (int) round( $m->wrong / $m->answers * 100 ); ?>%</td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function audit() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . wic_table( 'audit' ) . ' ORDER BY id DESC LIMIT 200' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Audit log', 'wic-tp' ); ?></h1>
			<p><?php esc_html_e( 'Who did what, and when. This log cannot be edited or deleted from the portal. Latest 200 entries.', 'wic-tp' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'When', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Who', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Action', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Object', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Details', 'wic-tp' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<?php $actor = get_userdata( $r->actor_id ); ?>
					<tr>
						<td><?php echo esc_html( wic_format_date( $r->created_at, true ) ); ?></td>
						<td><?php echo esc_html( $actor ? $actor->display_name : __( 'System', 'wic-tp' ) ); ?></td>
						<td><code><?php echo esc_html( $r->action ); ?></code></td>
						<td><?php echo esc_html( $r->object_type . ' #' . $r->object_id ); ?></td>
						<td><code><?php echo esc_html( $r->details ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
