<?php
/**
 * People: local administrators (#85), mentors (#84), staff numbers (#9), the person record (#79),
 * "My account", adding people directly (#3), and bringing in a staff list from a spreadsheet
 * (#10) or an HR system (#159). One import engine serves both.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_role_defs',
	function ( $defs ) {
		$defs['wic_admin'][1]['wic_manage_clinic'] = true;
		$defs['wic_local_admin']                   = array(
			__( 'WIC Local Administrator', 'wic-tp' ),
			array(
				'read'              => true,
				'wic_learn'         => true,
				'wic_view_team'     => true,
				'wic_approve'       => true,
				'wic_manage_clinic' => true,
			),
		);
		return $defs;
	}
);

add_filter(
	'wic_agency_defaults',
	function ( $d ) {
		$d['hr_api_key'] = '';
		$d['hr_csv_url'] = '';
		return $d;
	}
);

add_filter(
	'wic_agency_fields',
	function ( $f ) {
		$f['hr_api_key'] = array( __( 'HR sync API key', 'wic-tp' ), 'text', __( 'A long random value. HR systems send it in the X-WIC-Key header to POST /wp-json/wic/v1/hr/sync. Empty = endpoint off.', 'wic-tp' ) );
		$f['hr_csv_url'] = array( __( 'HR staff list URL (daily pull)', 'wic-tp' ), 'url', __( 'Optional. A CSV in the staff-import format, fetched once a day and applied as an import. Empty = off.', 'wic-tp' ) );
		return $f;
	}
);

class WIC_People {

	const IMPORT_COLUMNS = array( 'email', 'first_name', 'last_name', 'staff_number', 'clinic', 'supervisor_email', 'group', 'role', 'leaving_date' );

	public static function init() {
		add_filter( 'wic_learning_roles', array( __CLASS__, 'add_local_admin_role' ) );
		add_filter( 'wic_supervisor_roles', array( __CLASS__, 'add_local_admin_role' ) );
		add_filter( 'wic_scope_user_ids', array( __CLASS__, 'clinic_scope' ), 10, 2 );
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		add_filter( 'wic_flash_messages', array( __CLASS__, 'messages' ) );
		add_action( 'admin_post_wic_person_add', array( __CLASS__, 'handle_add' ) );
		add_action( 'admin_post_wic_person_update', array( __CLASS__, 'handle_update' ) );
		add_action( 'admin_post_wic_person_status', array( __CLASS__, 'handle_status' ) );
		add_action( 'admin_post_wic_import_upload', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_wic_import_commit', array( __CLASS__, 'handle_commit' ) );
		add_action( 'admin_post_wic_import_template', array( __CLASS__, 'handle_template' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wic_daily', array( __CLASS__, 'daily_pull' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Scope and permissions                                              */
	/* ------------------------------------------------------------------ */

	public static function add_local_admin_role( $roles ) {
		$roles[] = 'wic_local_admin';
		return array_unique( $roles );
	}

	/** Clinics a local administrator looks after. */
	public static function admin_clinics( $user_id ) {
		return array_values( array_filter( array_map( 'intval', (array) get_user_meta( $user_id, 'wic_admin_clinics', true ) ) ) );
	}

	/** Local administrators see everyone in their clinics, in addition to anyone who reports to them. */
	public static function clinic_scope( $ids, $viewer_id ) {
		if ( ! $viewer_id || user_can( $viewer_id, 'wic_view_all' ) || ! user_can( $viewer_id, 'wic_manage_clinic' ) ) {
			return $ids;
		}
		$clinics = self::admin_clinics( $viewer_id );
		if ( ! $clinics ) {
			return $ids;
		}
		$members = get_users(
			array(
				'meta_query' => array(
					array(
						'key'     => 'wic_clinic_id',
						'value'   => $clinics,
						'compare' => 'IN',
					),
				),
				'fields'     => 'ID',
			)
		);
		return array_merge( $ids, array_map( 'intval', $members ), array( (int) $viewer_id ) );
	}

	/** Changing someone's clinic, reporting line or account status. */
	public static function can_manage( $viewer_id, $user_id ) {
		if ( (int) $viewer_id === (int) $user_id ) {
			return false;
		}
		if ( user_can( $viewer_id, 'wic_manage_people' ) ) {
			return wic_can_see_user( $viewer_id, $user_id );
		}
		if ( user_can( $viewer_id, 'wic_manage_clinic' ) ) {
			return in_array( wic_user_clinic_id( $user_id ), self::admin_clinics( $viewer_id ), true );
		}
		return false;
	}

	public static function mentees( $mentor_id ) {
		return get_users(
			array(
				'meta_key'   => 'wic_mentor',
				'meta_value' => (int) $mentor_id,
				'orderby'    => 'display_name',
			)
		);
	}

	public static function is_mentor_of( $mentor_id, $user_id ) {
		return $mentor_id && (int) get_user_meta( $user_id, 'wic_mentor', true ) === (int) $mentor_id;
	}

	public static function person_url( $user_id ) {
		return wic_portal_url( 'person', array( 'user' => (int) $user_id ) );
	}

	/* ------------------------------------------------------------------ */
	/* Portal views                                                       */
	/* ------------------------------------------------------------------ */

	public static function views( $views ) {
		$views['account']    = array(
			'label'    => __( 'My account', 'wic-tp' ),
			'group'    => 'learn',
			'cap'      => 'read',
			'callback' => array( __CLASS__, 'view_account' ),
			'order'    => 95,
		);
		$views['person']     = array(
			'label'    => __( 'Person', 'wic-tp' ),
			'group'    => 'team',
			'cap'      => function ( $uid ) {
				return user_can( $uid, 'wic_view_team' ) || (bool) self::mentees( $uid );
			},
			'callback' => array( __CLASS__, 'view_person' ),
			'hidden'   => true,
		);
		$views['mentees']    = array(
			'label'    => __( 'My mentees', 'wic-tp' ),
			'group'    => 'team',
			'cap'      => function ( $uid ) {
				return (bool) self::mentees( $uid );
			},
			'callback' => array( __CLASS__, 'view_mentees' ),
			'order'    => 40,
		);
		$views['add_person'] = array(
			'label'    => __( 'Add a person', 'wic-tp' ),
			'group'    => 'agency',
			'cap'      => function ( $uid ) {
				return user_can( $uid, 'wic_manage_people' ) || user_can( $uid, 'wic_manage_clinic' );
			},
			'callback' => array( __CLASS__, 'view_add' ),
			'order'    => 20,
		);
		$views['import']     = array(
			'label'    => __( 'Import staff', 'wic-tp' ),
			'group'    => 'agency',
			'cap'      => 'wic_manage_people',
			'callback' => array( __CLASS__, 'view_import' ),
			'order'    => 30,
		);
		return $views;
	}

	public static function messages( $m ) {
		$m['person_added']       = __( 'Account created. A set-password link has been emailed to them.', 'wic-tp' );
		$m['person_added_nolink'] = __( 'Account created. No email was sent.', 'wic-tp' );
		$m['err_person_exists']  = __( 'An account with that email already exists.', 'wic-tp' );
		$m['err_person_invalid'] = __( 'Please give a first name, last name and a valid email.', 'wic-tp' );
		$m['person_updated']     = __( 'Details saved. Training history is unchanged.', 'wic-tp' );
		$m['person_status']      = __( 'Account status changed. Training records are kept.', 'wic-tp' );
		$m['import_preview']     = __( 'File read. Check the preview below, then apply it.', 'wic-tp' );
		$m['import_done']        = __( 'Import applied.', 'wic-tp' );
		$m['err_import_file']    = __( 'That file could not be read as a CSV with an email or staff_number column.', 'wic-tp' );
		$m['err_import_expired'] = __( 'The preview expired. Please upload the file again.', 'wic-tp' );
		return $m;
	}

	private static function deny() {
		wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
	}

	private static function user_name( $id ) {
		$u = $id ? get_userdata( $id ) : null;
		return $u ? $u->display_name : '—';
	}

	public static function view_account( $uid ) {
		$u = get_userdata( $uid );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'My account', 'wic-tp' ); ?></h2>
		<section class="wic-panel wic-section">
			<dl class="wic-dl">
				<dt><?php esc_html_e( 'Name', 'wic-tp' ); ?></dt><dd><?php echo esc_html( $u->display_name ); ?></dd>
				<dt><?php esc_html_e( 'Email', 'wic-tp' ); ?></dt><dd><?php echo esc_html( $u->user_email ); ?></dd>
				<dt><?php esc_html_e( 'Username', 'wic-tp' ); ?></dt><dd><?php echo esc_html( $u->user_login ); ?></dd>
				<?php if ( get_user_meta( $uid, 'wic_staff_number', true ) ) : ?>
					<dt><?php esc_html_e( 'Staff number', 'wic-tp' ); ?></dt><dd><?php echo esc_html( get_user_meta( $uid, 'wic_staff_number', true ) ); ?></dd>
				<?php endif; ?>
				<?php if ( ! user_can( $uid, 'wic_vendor' ) ) : ?>
					<dt><?php esc_html_e( 'Clinic', 'wic-tp' ); ?></dt><dd><?php echo esc_html( wic_user_clinic_name( $uid ) ? wic_user_clinic_name( $uid ) : '—' ); ?></dd>
					<dt><?php esc_html_e( 'Reports to', 'wic-tp' ); ?></dt><dd><?php echo esc_html( self::user_name( wic_reports_to( $uid ) ) ); ?></dd>
					<dt><?php esc_html_e( 'Mentor', 'wic-tp' ); ?></dt><dd><?php echo esc_html( self::user_name( (int) get_user_meta( $uid, 'wic_mentor', true ) ) ); ?></dd>
				<?php endif; ?>
			</dl>
			<p class="wic-help"><?php esc_html_e( 'If any of this is wrong, ask your supervisor or agency administrator to correct it.', 'wic-tp' ); ?></p>
		</section>
		<section class="wic-panel wic-section">
			<h3 class="wic-h3"><?php esc_html_e( 'Password', 'wic-tp' ); ?></h3>
			<p><?php esc_html_e( 'To change your password we email you a one-time link.', 'wic-tp' ); ?></p>
			<p><a class="wic-btn" href="<?php echo esc_url( wp_lostpassword_url( wic_portal_url( 'account' ) ) ); ?>"><?php esc_html_e( 'Send me a password link', 'wic-tp' ); ?></a></p>
		</section>
		<?php
		/** Two-factor setup and other per-person settings add sections here. */
		do_action( 'wic_account_sections', $uid );
	}

	public static function view_mentees( $uid ) {
		$people = self::mentees( $uid );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'My mentees', 'wic-tp' ); ?></h2>
		<p class="wic-help"><?php esc_html_e( 'New starters you have been named as mentor for. You can see their progress; approvals and changes stay with their supervisor.', 'wic-tp' ); ?></p>
		<?php if ( ! $people ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'Nobody is assigned to you as a mentee.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		self::people_table( $people );
	}

	/** A compact progress table, shared by the mentees view. */
	public static function people_table( $people ) {
		?>
		<div class="wic-table-wrap">
			<table class="wic-table" data-wic-sortable>
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Name', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Clinic', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Complete', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Overdue', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Started', 'wic-tp' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $people as $p ) : ?>
					<?php
					$items = WIC_Records::user_assignments( $p->ID );
					$c     = array_count_values( wp_list_pluck( $items, 'status' ) );
					$done  = isset( $c['complete'] ) ? (int) $c['complete'] : 0;
					$late  = isset( $c['overdue'] ) ? (int) $c['overdue'] : 0;
					?>
					<tr>
						<td><a href="<?php echo esc_url( self::person_url( $p->ID ) ); ?>"><?php echo esc_html( $p->display_name ); ?></a></td>
						<td><?php echo esc_html( wic_user_clinic_name( $p->ID ) ); ?></td>
						<td data-sort="<?php echo (int) $done; ?>"><?php echo (int) $done; ?> / <?php echo count( $items ); ?></td>
						<td data-sort="<?php echo (int) $late; ?>"><?php echo $late ? '<strong class="wic-late">' . (int) $late . '</strong>' : '0'; ?></td>
						<td data-sort="<?php echo esc_attr( $p->user_registered ); ?>"><?php echo esc_html( wic_format_date( $p->user_registered ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** Everything about one person on one screen. */
	public static function view_person( $uid ) {
		global $wpdb;
		$id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
		$u  = $id ? get_userdata( $id ) : null;
		if ( ! $u || ! ( wic_can_see_user( $uid, $id ) || self::is_mentor_of( $uid, $id ) ) ) {
			echo '<div class="wic-notice wic-notice--err">' . esc_html__( 'That person is not in your team.', 'wic-tp' ) . '</div>';
			return;
		}
		$status      = wic_user_status( $id );
		$assignments = WIC_Records::user_assignments( $id );
		$completions = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'completions' ) . ' WHERE user_id = %d ORDER BY completed_at DESC', $id ) );
		$certs       = WIC_Certificates::for_user( $id );
		$manage      = self::can_manage( $uid, $id );
		$set_mentor  = $manage || ( user_can( $uid, 'wic_view_team' ) && wic_can_see_user( $uid, $id ) && $uid !== $id );
		$roles       = wp_roles();
		$role_names  = array_map(
			function ( $r ) use ( $roles ) {
				return isset( $roles->role_names[ $r ] ) ? translate_user_role( $roles->role_names[ $r ] ) : $r;
			},
			(array) $u->roles
		);
		?>
		<p><a href="<?php echo esc_url( wic_portal_url( user_can( $uid, 'wic_view_team' ) ? 'team' : 'mentees' ) ); ?>">← <?php esc_html_e( 'Back to the team', 'wic-tp' ); ?></a></p>
		<h2 class="wic-h"><?php echo esc_html( $u->display_name ); ?>
			<?php if ( 'active' !== $status ) : ?><span class="wic-badge wic-badge--overdue"><?php echo esc_html( ucfirst( $status ) ); ?></span><?php endif; ?>
		</h2>

		<div class="wic-two wic-section">
			<section class="wic-panel">
				<h3 class="wic-h3"><?php esc_html_e( 'Profile', 'wic-tp' ); ?></h3>
				<dl class="wic-dl">
					<dt><?php esc_html_e( 'Email', 'wic-tp' ); ?></dt><dd><?php echo esc_html( $u->user_email ); ?></dd>
					<dt><?php esc_html_e( 'Staff number', 'wic-tp' ); ?></dt><dd><?php echo esc_html( get_user_meta( $id, 'wic_staff_number', true ) ? get_user_meta( $id, 'wic_staff_number', true ) : '—' ); ?></dd>
					<dt><?php esc_html_e( 'Role', 'wic-tp' ); ?></dt><dd><?php echo esc_html( implode( ', ', $role_names ) ); ?></dd>
					<dt><?php esc_html_e( 'Group', 'wic-tp' ); ?></dt><dd><?php echo esc_html( wic_group_label( wic_user_group( $id ) ) ); ?></dd>
					<dt><?php esc_html_e( 'Clinic', 'wic-tp' ); ?></dt><dd><?php echo esc_html( wic_user_clinic_name( $id ) ? wic_user_clinic_name( $id ) : '—' ); ?></dd>
					<dt><?php esc_html_e( 'Reports to', 'wic-tp' ); ?></dt><dd><?php echo esc_html( self::user_name( wic_reports_to( $id ) ) ); ?></dd>
					<dt><?php esc_html_e( 'Mentor', 'wic-tp' ); ?></dt><dd><?php echo esc_html( self::user_name( (int) get_user_meta( $id, 'wic_mentor', true ) ) ); ?></dd>
					<dt><?php esc_html_e( 'Joined', 'wic-tp' ); ?></dt><dd><?php echo esc_html( wic_format_date( $u->user_registered ) ); ?></dd>
					<?php if ( get_user_meta( $id, 'wic_deactivate_on', true ) ) : ?>
						<dt><?php esc_html_e( 'Leaving date', 'wic-tp' ); ?></dt><dd><?php echo esc_html( get_user_meta( $id, 'wic_deactivate_on', true ) ); ?></dd>
					<?php endif; ?>
				</dl>
			</section>

			<?php if ( $manage || $set_mentor ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form wic-panel">
					<h3 class="wic-h3"><?php esc_html_e( 'Change details', 'wic-tp' ); ?></h3>
					<input type="hidden" name="action" value="wic_person_update">
					<input type="hidden" name="user" value="<?php echo (int) $id; ?>">
					<?php wp_nonce_field( 'wic_person_update_' . $id ); ?>
					<?php if ( $manage ) : ?>
						<div class="wic-field"><label for="wic-p-clinic"><?php esc_html_e( 'Clinic', 'wic-tp' ); ?></label><?php echo WIC_Clinics::select( 'clinic_id', wic_user_clinic_id( $id ), 'wic-p-clinic', false, __( '— No clinic —', 'wic-tp' ) ); // phpcs:ignore ?></div>
						<div class="wic-field"><label for="wic-p-staff"><?php esc_html_e( 'Staff number', 'wic-tp' ); ?></label><input type="text" id="wic-p-staff" name="staff_number" value="<?php echo esc_attr( get_user_meta( $id, 'wic_staff_number', true ) ); ?>"></div>
						<div class="wic-field"><label for="wic-p-sup"><?php esc_html_e( 'Reports to', 'wic-tp' ); ?></label>
							<select id="wic-p-sup" name="reports_to">
								<option value="0"><?php esc_html_e( '— Nobody (goes to administrators) —', 'wic-tp' ); ?></option>
								<?php foreach ( WIC_Roles::supervisors() as $s ) : ?>
									<?php if ( (int) $s->ID === $id ) { continue; } ?>
									<option value="<?php echo (int) $s->ID; ?>" <?php selected( wic_reports_to( $id ), $s->ID ); ?>><?php echo esc_html( $s->display_name ); ?></option>
								<?php endforeach; ?>
							</select></div>
						<div class="wic-field"><label for="wic-p-group"><?php esc_html_e( 'Group', 'wic-tp' ); ?></label>
							<select id="wic-p-group" name="group">
								<option value="staff" <?php selected( wic_user_group( $id ), 'staff' ); ?>><?php esc_html_e( 'Staff', 'wic-tp' ); ?></option>
								<option value="intern" <?php selected( wic_user_group( $id ), 'intern' ); ?>><?php esc_html_e( 'Intern', 'wic-tp' ); ?></option>
							</select></div>
						<?php if ( in_array( 'wic_local_admin', (array) $u->roles, true ) && user_can( $uid, 'wic_manage_people' ) ) : ?>
							<fieldset class="wic-field"><legend><?php esc_html_e( 'Clinics they administer', 'wic-tp' ); ?></legend>
								<?php foreach ( WIC_Clinics::all() as $c ) : ?>
									<label><input type="checkbox" name="admin_clinics[]" value="<?php echo (int) $c->id; ?>" <?php checked( in_array( (int) $c->id, self::admin_clinics( $id ), true ) ); ?>> <?php echo esc_html( $c->name ); ?></label>
								<?php endforeach; ?>
							</fieldset>
						<?php endif; ?>
					<?php endif; ?>
					<div class="wic-field"><label for="wic-p-mentor"><?php esc_html_e( 'Mentor', 'wic-tp' ); ?></label>
						<select id="wic-p-mentor" name="mentor">
							<option value="0"><?php esc_html_e( '— No mentor —', 'wic-tp' ); ?></option>
							<?php foreach ( array_unique( array_merge( wic_scope_user_ids( $uid ), array_map( 'intval', wp_list_pluck( WIC_Roles::supervisors(), 'ID' ) ) ) ) as $mid ) : ?>
								<?php
								if ( $mid === $id || 'active' !== wic_user_status( $mid ) ) {
									continue;
								}
								?>
								<option value="<?php echo (int) $mid; ?>" <?php selected( (int) get_user_meta( $id, 'wic_mentor', true ), $mid ); ?>><?php echo esc_html( self::user_name( $mid ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="wic-help"><?php esc_html_e( 'A named colleague who guides a new starter. They can see this person\'s progress.', 'wic-tp' ); ?></p></div>
					<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Save', 'wic-tp' ); ?></button>
				</form>
			<?php endif; ?>
		</div>

		<section class="wic-section">
			<h3 class="wic-h3"><?php esc_html_e( 'Assigned training', 'wic-tp' ); ?></h3>
			<?php if ( ! $assignments ) : ?>
				<div class="wic-empty"><p><?php esc_html_e( 'Nothing is assigned.', 'wic-tp' ); ?></p></div>
			<?php else : ?>
				<div class="wic-table-wrap"><table class="wic-table" data-wic-sortable>
					<thead><tr><th scope="col"><?php esc_html_e( 'Course', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Progress', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Due', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Completed', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Score', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Last activity', 'wic-tp' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $assignments as $a ) : ?>
						<tr>
							<td><?php echo esc_html( $a['title'] ); ?><?php echo $a['required'] ? ' <span class="wic-tag">' . esc_html__( 'Required', 'wic-tp' ) . '</span>' : ''; ?></td>
							<td><span class="wic-badge wic-badge--<?php echo esc_attr( $a['status'] ); ?>"><?php echo esc_html( wic_status_label( $a['status'] ) ); ?></span></td>
							<td data-sort="<?php echo (int) $a['progress']; ?>"><?php echo (int) $a['progress']; ?>%</td>
							<td data-sort="<?php echo esc_attr( $a['due_at'] ); ?>"><?php echo esc_html( wic_format_date( $a['due_at'] ) ); ?><?php echo $a['days_late'] ? ' <strong class="wic-late">' . esc_html( sprintf( _n( '%d day late', '%d days late', $a['days_late'], 'wic-tp' ), $a['days_late'] ) ) . '</strong>' : ''; ?></td>
							<td><?php echo esc_html( $a['completion'] ? wic_format_date( $a['completion']->completed_at ) : '—' ); ?></td>
							<td><?php echo $a['completion'] ? (int) $a['completion']->score . '%' : '—'; ?></td>
							<td><?php echo esc_html( $a['last_access'] ? wic_format_date( $a['last_access'] ) : __( 'Never', 'wic-tp' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			<?php endif; ?>
		</section>

		<section class="wic-section">
			<h3 class="wic-h3"><?php esc_html_e( 'Completion history', 'wic-tp' ); ?></h3>
			<?php if ( ! $completions ) : ?>
				<div class="wic-empty"><p><?php esc_html_e( 'No completions yet.', 'wic-tp' ); ?></p></div>
			<?php else : ?>
				<div class="wic-table-wrap"><table class="wic-table">
					<thead><tr><th scope="col"><?php esc_html_e( 'Course', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Completed', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Score', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Version', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Attempt', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'How', 'wic-tp' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $completions as $c ) : ?>
						<tr>
							<td><?php echo esc_html( get_the_title( $c->course_id ) ); ?></td>
							<td><?php echo esc_html( wic_format_date( $c->completed_at ) ); ?></td>
							<td><?php echo (int) $c->score; ?>%</td>
							<td><?php echo (int) $c->course_version; ?></td>
							<td><?php echo (int) $c->run; ?></td>
							<td><?php echo esc_html( isset( $c->source ) ? ucfirst( str_replace( '_', ' ', $c->source ) ) : __( 'Online', 'wic-tp' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			<?php endif; ?>
		</section>

		<section class="wic-section">
			<h3 class="wic-h3"><?php esc_html_e( 'Certificates', 'wic-tp' ); ?></h3>
			<?php if ( ! $certs ) : ?>
				<div class="wic-empty"><p><?php esc_html_e( 'No certificates yet.', 'wic-tp' ); ?></p></div>
			<?php else : ?>
				<ul class="wic-list">
					<?php foreach ( $certs as $c ) : ?>
						<li><span><a href="<?php echo esc_url( WIC_Certificates::url( $c ) ); ?>"><?php echo esc_html( $c->course_title ); ?></a> · <code><?php echo esc_html( $c->cert_number ); ?></code> · <?php echo esc_html( ucfirst( WIC_Certificates::state( $c ) ) ); ?></span><time><?php echo esc_html( wic_format_date( $c->issued_at ) ); ?></time></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<?php
		/** Signed forms, competency sign-offs, outside training and compliance add their sections here. */
		do_action( 'wic_person_record_sections', $id, $uid );
		?>

		<?php if ( $manage ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-panel wic-section">
				<h3 class="wic-h3"><?php esc_html_e( 'Account', 'wic-tp' ); ?></h3>
				<input type="hidden" name="action" value="wic_person_status">
				<input type="hidden" name="user" value="<?php echo (int) $id; ?>">
				<?php wp_nonce_field( 'wic_person_status_' . $id ); ?>
				<?php if ( 'deactivated' === $status ) : ?>
					<input type="hidden" name="status" value="active">
					<p><?php esc_html_e( 'This account is closed. Reopening it restores sign-in; every record is still there.', 'wic-tp' ); ?></p>
					<button type="submit" class="wic-btn"><?php esc_html_e( 'Reopen account', 'wic-tp' ); ?></button>
				<?php elseif ( 'active' === $status ) : ?>
					<input type="hidden" name="status" value="deactivated">
					<p><?php esc_html_e( 'Deactivating stops sign-in immediately. The training history stays; accounts are never deleted.', 'wic-tp' ); ?></p>
					<button type="submit" class="wic-btn" data-wic-confirm="<?php esc_attr_e( 'Close this account? They will be signed out now.', 'wic-tp' ); ?>"><?php esc_html_e( 'Deactivate account', 'wic-tp' ); ?></button>
				<?php else : ?>
					<p><?php echo esc_html( sprintf( __( 'Account status: %s. Use Approvals to decide pending registrations.', 'wic-tp' ), ucfirst( $status ) ) ); ?></p>
				<?php endif; ?>
			</form>
		<?php endif; ?>
		<?php
	}

	public static function handle_update() {
		$id  = isset( $_POST['user'] ) ? absint( $_POST['user'] ) : 0;
		$uid = get_current_user_id();
		check_admin_referer( 'wic_person_update_' . $id );
		$manage = self::can_manage( $uid, $id );
		$mentor = $manage || ( user_can( $uid, 'wic_view_team' ) && wic_can_see_user( $uid, $id ) && $uid !== $id );
		if ( ! $mentor ) {
			self::deny();
		}
		if ( $manage ) {
			if ( isset( $_POST['clinic_id'] ) ) {
				WIC_Clinics::set_user_clinic( $id, absint( $_POST['clinic_id'] ) );
			}
			$old_staff = (string) get_user_meta( $id, 'wic_staff_number', true );
			$staff     = isset( $_POST['staff_number'] ) ? sanitize_text_field( wp_unslash( $_POST['staff_number'] ) ) : $old_staff;
			if ( $staff !== $old_staff ) {
				update_user_meta( $id, 'wic_staff_number', $staff );
				wic_audit( 'user_staff_number', 'user', $id, array( 'from' => $old_staff, 'to' => $staff ) );
			}
			$report = isset( $_POST['reports_to'] ) ? absint( $_POST['reports_to'] ) : wic_reports_to( $id );
			if ( $report !== wic_reports_to( $id ) && $report !== $id ) {
				wic_audit( 'user_reports_to', 'user', $id, array( 'from' => wic_reports_to( $id ), 'to' => $report ) );
				update_user_meta( $id, 'wic_reports_to', $report );
			}
			$group = isset( $_POST['group'] ) && in_array( $_POST['group'], array( 'staff', 'intern' ), true ) ? sanitize_key( $_POST['group'] ) : wic_user_group( $id );
			if ( $group !== wic_user_group( $id ) ) {
				wic_audit( 'user_group', 'user', $id, array( 'from' => wic_user_group( $id ), 'to' => $group ) );
				update_user_meta( $id, 'wic_group', $group );
			}
			if ( isset( $_POST['admin_clinics'] ) || ( user_can( $uid, 'wic_manage_people' ) && user_can( $id, 'wic_manage_clinic' ) && ! user_can( $id, 'wic_view_all' ) ) ) {
				$clinics = isset( $_POST['admin_clinics'] ) ? array_map( 'absint', (array) $_POST['admin_clinics'] ) : array();
				update_user_meta( $id, 'wic_admin_clinics', $clinics );
				wic_audit( 'user_admin_clinics', 'user', $id, array( 'clinics' => $clinics ) );
			}
			if ( 'active' === wic_user_status( $id ) ) {
				WIC_Records::apply_rules( $id );
			}
		}
		$m = isset( $_POST['mentor'] ) ? absint( $_POST['mentor'] ) : 0;
		if ( $m === $id ) {
			$m = 0;
		}
		if ( $m !== (int) get_user_meta( $id, 'wic_mentor', true ) ) {
			wic_audit( 'user_mentor', 'user', $id, array( 'from' => (int) get_user_meta( $id, 'wic_mentor', true ), 'to' => $m ) );
			update_user_meta( $id, 'wic_mentor', $m );
			if ( $m ) {
				$u = get_userdata( $id );
				WIC_Notify::event( $m, 'mentor_assigned', $id, sprintf( __( 'You have been named mentor for %s.', 'wic-tp' ), $u ? $u->display_name : '' ), true );
				WIC_Notify::event( $id, 'mentor_assigned', $m, sprintf( __( 'Your mentor is %s.', 'wic-tp' ), self::user_name( $m ) ), false );
			}
		}
		wp_safe_redirect( add_query_arg( 'wic_msg', 'person_updated', self::person_url( $id ) ) );
		exit;
	}

	public static function handle_status() {
		$id = isset( $_POST['user'] ) ? absint( $_POST['user'] ) : 0;
		check_admin_referer( 'wic_person_status_' . $id );
		if ( ! self::can_manage( get_current_user_id(), $id ) ) {
			self::deny();
		}
		$status = isset( $_POST['status'] ) && 'active' === $_POST['status'] ? 'active' : 'deactivated';
		WIC_Roles::set_status( $id, $status );
		wp_safe_redirect( add_query_arg( 'wic_msg', 'person_status', self::person_url( $id ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Add a person directly (#3)                                         */
	/* ------------------------------------------------------------------ */

	private static function creatable_roles( $viewer ) {
		$roles = array(
			'wic_learner'     => __( 'Learner', 'wic-tp' ),
			'wic_supervisor'  => __( 'Supervisor', 'wic-tp' ),
		);
		if ( user_can( $viewer, 'wic_manage_people' ) ) {
			$roles['wic_local_admin'] = __( 'Local administrator', 'wic-tp' );
			$roles['wic_author']      = __( 'Content author', 'wic-tp' );
		}
		if ( user_can( $viewer, 'wic_manage_settings' ) ) {
			$roles['wic_admin'] = __( 'Agency administrator', 'wic-tp' );
		}
		return $roles;
	}

	public static function view_add( $uid ) {
		$clinic_only = ! user_can( $uid, 'wic_manage_people' );
		$clinics     = $clinic_only ? self::admin_clinics( $uid ) : array();
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Add a person', 'wic-tp' ); ?></h2>
		<p class="wic-help"><?php esc_html_e( 'For people who should not wait for approval. The account is active at once and they get a one-time link to set their own password.', 'wic-tp' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form wic-panel">
			<input type="hidden" name="action" value="wic_person_add">
			<?php wp_nonce_field( 'wic_person_add' ); ?>
			<div class="wic-field"><label for="wic-a-first"><?php esc_html_e( 'First name', 'wic-tp' ); ?></label><input type="text" id="wic-a-first" name="first_name" required autocomplete="off"></div>
			<div class="wic-field"><label for="wic-a-last"><?php esc_html_e( 'Last name', 'wic-tp' ); ?></label><input type="text" id="wic-a-last" name="last_name" required autocomplete="off"></div>
			<div class="wic-field"><label for="wic-a-email"><?php esc_html_e( 'Work email', 'wic-tp' ); ?></label><input type="email" id="wic-a-email" name="email" required autocomplete="off"></div>
			<div class="wic-field"><label for="wic-a-staff"><?php esc_html_e( 'Staff number (optional)', 'wic-tp' ); ?></label><input type="text" id="wic-a-staff" name="staff_number"></div>
			<div class="wic-field"><label for="wic-a-role"><?php esc_html_e( 'Role', 'wic-tp' ); ?></label>
				<select id="wic-a-role" name="role">
					<?php foreach ( self::creatable_roles( $uid ) as $r => $label ) : ?>
						<option value="<?php echo esc_attr( $r ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select></div>
			<div class="wic-field"><label for="wic-a-group"><?php esc_html_e( 'Group', 'wic-tp' ); ?></label>
				<select id="wic-a-group" name="group"><option value="staff"><?php esc_html_e( 'Staff', 'wic-tp' ); ?></option><option value="intern"><?php esc_html_e( 'Intern', 'wic-tp' ); ?></option></select></div>
			<div class="wic-field"><label for="wic-a-clinic"><?php esc_html_e( 'Clinic', 'wic-tp' ); ?></label>
				<?php if ( $clinic_only ) : ?>
					<select id="wic-a-clinic" name="clinic_id" required>
						<?php foreach ( $clinics as $cid ) : ?>
							<?php $c = WIC_Clinics::get( $cid ); ?>
							<?php if ( $c ) : ?><option value="<?php echo (int) $c->id; ?>"><?php echo esc_html( $c->name ); ?></option><?php endif; ?>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<?php echo WIC_Clinics::select( 'clinic_id', 0, 'wic-a-clinic', false, __( '— No clinic —', 'wic-tp' ) ); // phpcs:ignore ?>
				<?php endif; ?></div>
			<div class="wic-field"><label for="wic-a-sup"><?php esc_html_e( 'Reports to', 'wic-tp' ); ?></label>
				<select id="wic-a-sup" name="reports_to">
					<option value="0"><?php esc_html_e( '— Nobody (administrators) —', 'wic-tp' ); ?></option>
					<?php foreach ( WIC_Roles::supervisors() as $s ) : ?>
						<option value="<?php echo (int) $s->ID; ?>" <?php selected( $s->ID, $uid ); ?>><?php echo esc_html( $s->display_name ); ?></option>
					<?php endforeach; ?>
				</select></div>
			<div class="wic-field"><label><input type="checkbox" name="send_link" value="1" checked> <?php esc_html_e( 'Email them a set-password link now', 'wic-tp' ); ?></label></div>
			<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Create account', 'wic-tp' ); ?></button>
		</form>
		<?php
	}

	public static function handle_add() {
		check_admin_referer( 'wic_person_add' );
		$uid = get_current_user_id();
		if ( ! user_can( $uid, 'wic_manage_people' ) && ! user_can( $uid, 'wic_manage_clinic' ) ) {
			self::deny();
		}
		$role   = isset( $_POST['role'] ) ? sanitize_key( $_POST['role'] ) : 'wic_learner';
		$clinic = isset( $_POST['clinic_id'] ) ? absint( $_POST['clinic_id'] ) : 0;
		if ( ! isset( self::creatable_roles( $uid )[ $role ] ) ) {
			self::deny();
		}
		if ( ! user_can( $uid, 'wic_manage_people' ) && ! in_array( $clinic, self::admin_clinics( $uid ), true ) ) {
			self::deny();
		}
		$row    = array(
			'email'        => isset( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '',
			'first_name'   => isset( $_POST['first_name'] ) ? wp_unslash( $_POST['first_name'] ) : '',
			'last_name'    => isset( $_POST['last_name'] ) ? wp_unslash( $_POST['last_name'] ) : '',
			'staff_number' => isset( $_POST['staff_number'] ) ? wp_unslash( $_POST['staff_number'] ) : '',
			'group'        => isset( $_POST['group'] ) ? wp_unslash( $_POST['group'] ) : 'staff',
		);
		$row    = array_map( 'sanitize_text_field', $row );
		$email  = sanitize_email( $row['email'] );
		if ( ! $row['first_name'] || ! $row['last_name'] || ! is_email( $email ) ) {
			WIC_Portal::back( 'add_person', 'err_person_invalid' );
		}
		if ( get_user_by( 'email', $email ) ) {
			WIC_Portal::back( 'add_person', 'err_person_exists' );
		}
		$id = self::create_user( $email, $row['first_name'], $row['last_name'], $role );
		if ( ! $id ) {
			WIC_Portal::back( 'add_person', 'err_person_invalid' );
		}
		update_user_meta( $id, 'wic_group', in_array( $row['group'], array( 'staff', 'intern' ), true ) ? $row['group'] : 'staff' );
		update_user_meta( $id, 'wic_staff_number', $row['staff_number'] );
		update_user_meta( $id, 'wic_reports_to', isset( $_POST['reports_to'] ) ? absint( $_POST['reports_to'] ) : 0 );
		WIC_Clinics::set_user_clinic( $id, $clinic );
		WIC_Records::apply_rules( $id );
		wic_audit( 'person_add', 'user', $id, array( 'role' => $role ) );
		$send = ! empty( $_POST['send_link'] );
		if ( $send ) {
			WIC_Registration::send_set_password( $id );
		}
		wp_safe_redirect( add_query_arg( 'wic_msg', $send ? 'person_added' : 'person_added_nolink', self::person_url( $id ) ) );
		exit;
	}

	/** Create an active account with an unguessable password the person never sees. */
	public static function create_user( $email, $first, $last, $role ) {
		$login   = sanitize_user( strtolower( current( explode( '@', $email ) ) ), true );
		$base    = $login ? $login : 'staff';
		$counter = 1;
		while ( ! $login || username_exists( $login ) ) {
			$login = $base . ( ++$counter );
		}
		$id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => trim( $first . ' ' . $last ),
				'role'         => $role,
			)
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		update_user_meta( $id, 'wic_status', 'active' );
		update_user_meta( $id, 'wic_approved_at', wic_now() );
		return (int) $id;
	}

	/* ------------------------------------------------------------------ */
	/* Import engine (#10, #159)                                          */
	/* ------------------------------------------------------------------ */

	/** Header names people actually use, mapped to ours. */
	private static function normalise_header( $h ) {
		$h       = strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) ) );
		$h       = preg_replace( '/[^a-z0-9]+/', '_', $h );
		$h       = trim( $h, '_' );
		$aliases = array(
			'e_mail'          => 'email',
			'email_address'   => 'email',
			'work_email'      => 'email',
			'first'           => 'first_name',
			'firstname'       => 'first_name',
			'given_name'      => 'first_name',
			'last'            => 'last_name',
			'lastname'        => 'last_name',
			'surname'         => 'last_name',
			'family_name'     => 'last_name',
			'staff_no'        => 'staff_number',
			'staff_id'        => 'staff_number',
			'employee_id'     => 'staff_number',
			'employee_number' => 'staff_number',
			'clinic_name'     => 'clinic',
			'location'        => 'clinic',
			'supervisor'      => 'supervisor_email',
			'manager_email'   => 'supervisor_email',
			'leaving'         => 'leaving_date',
			'end_date'        => 'leaving_date',
		);
		return isset( $aliases[ $h ] ) ? $aliases[ $h ] : $h;
	}

	/** CSV text → list of associative rows. Returns null when there is no usable key column. */
	public static function parse_csv( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$fh   = fopen( 'php://temp', 'r+' );
		fwrite( $fh, $text );
		rewind( $fh );
		$header = fgetcsv( $fh );
		if ( ! $header ) {
			fclose( $fh );
			return null;
		}
		$header = array_map( array( __CLASS__, 'normalise_header' ), $header );
		if ( ! in_array( 'email', $header, true ) && ! in_array( 'staff_number', $header, true ) ) {
			fclose( $fh );
			return null;
		}
		$rows = array();
		while ( false !== ( $line = fgetcsv( $fh ) ) ) {
			if ( ! array_filter( $line, 'strlen' ) ) {
				continue;
			}
			$row = array();
			foreach ( $header as $i => $key ) {
				if ( in_array( $key, self::IMPORT_COLUMNS, true ) ) {
					$row[ $key ] = isset( $line[ $i ] ) ? trim( $line[ $i ] ) : '';
				}
			}
			$rows[] = $row;
			if ( count( $rows ) >= 5000 ) {
				break;
			}
		}
		fclose( $fh );
		return $rows;
	}

	private static function find_user( $email, $staff ) {
		if ( $email && is_email( $email ) ) {
			$u = get_user_by( 'email', $email );
			if ( $u ) {
				return $u;
			}
		}
		if ( '' !== (string) $staff ) {
			$ids = get_users(
				array(
					'meta_key'   => 'wic_staff_number',
					'meta_value' => $staff,
					'fields'     => 'ID',
					'number'     => 2,
				)
			);
			if ( 1 === count( $ids ) ) {
				return get_userdata( $ids[0] );
			}
		}
		return null;
	}

	/**
	 * Validate and, when $commit is true, apply rows. The preview and the apply use the same
	 * code path, so what the preview promises is what happens.
	 *
	 * @param array $opts create_clinics (bool), send_links (bool), source (string)
	 * @return array list of array( row, email, name, action, message )
	 */
	public static function import_rows( $rows, $commit, $opts = array() ) {
		$opts    = wp_parse_args( $opts, array( 'create_clinics' => false, 'send_links' => true, 'source' => 'csv' ) );
		$results = array();
		$seen    = array();
		$roles   = array(
			''            => 'wic_learner',
			'learner'     => 'wic_learner',
			'staff'       => 'wic_learner',
			'supervisor'  => 'wic_supervisor',
			'local_admin' => 'wic_local_admin',
			'author'      => 'wic_author',
		);
		foreach ( array_values( (array) $rows ) as $n => $raw ) {
			$r      = array_map( 'sanitize_text_field', array_merge( array_fill_keys( self::IMPORT_COLUMNS, '' ), array_intersect_key( (array) $raw, array_flip( self::IMPORT_COLUMNS ) ) ) );
			$email  = sanitize_email( $r['email'] );
			$res    = array(
				'row'     => $n + 2,
				'email'   => $email ? $email : $r['staff_number'],
				'name'    => trim( $r['first_name'] . ' ' . $r['last_name'] ),
				'action'  => 'error',
				'message' => '',
			);
			$errors = array();
			$notes  = array();

			$key = $email ? $email : 'staff:' . $r['staff_number'];
			if ( isset( $seen[ $key ] ) ) {
				$errors[] = sprintf( __( 'Same person as row %d.', 'wic-tp' ), $seen[ $key ] );
			}
			$seen[ $key ] = $n + 2;

			$user = self::find_user( $email, $r['staff_number'] );
			if ( ! $user && ( ! is_email( $email ) || ! $r['first_name'] || ! $r['last_name'] ) ) {
				$errors[] = __( 'New people need a valid email, first name and last name.', 'wic-tp' );
			}
			$group = strtolower( $r['group'] );
			if ( '' !== $group && ! in_array( $group, array( 'staff', 'intern' ), true ) ) {
				$errors[] = sprintf( __( 'Group "%s" should be staff or intern.', 'wic-tp' ), $r['group'] );
			}
			$role_key = strtolower( str_replace( ' ', '_', $r['role'] ) );
			if ( ! isset( $roles[ $role_key ] ) ) {
				$errors[] = sprintf( __( 'Unknown role "%s".', 'wic-tp' ), $r['role'] );
			}
			$clinic_id = 0;
			if ( '' !== $r['clinic'] ) {
				$c = WIC_Clinics::by_name( $r['clinic'] );
				if ( $c ) {
					$clinic_id = (int) $c->id;
				} elseif ( $opts['create_clinics'] ) {
					$notes[] = sprintf( __( 'New clinic "%s" will be created.', 'wic-tp' ), $r['clinic'] );
				} else {
					$errors[] = sprintf( __( 'Unknown clinic "%s".', 'wic-tp' ), $r['clinic'] );
				}
			}
			$sup_id = null;
			if ( '' !== $r['supervisor_email'] ) {
				$sup = self::find_user( sanitize_email( $r['supervisor_email'] ), $r['supervisor_email'] );
				if ( $sup ) {
					$sup_id = (int) $sup->ID;
				} else {
					$errors[] = sprintf( __( 'Supervisor "%s" has no account.', 'wic-tp' ), $r['supervisor_email'] );
				}
			}
			if ( '' !== $r['leaving_date'] && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $r['leaving_date'] ) ) {
				$errors[] = __( 'Leaving date must be YYYY-MM-DD.', 'wic-tp' );
			}

			if ( $errors ) {
				$res['message'] = implode( ' ', $errors );
				$results[]      = $res;
				continue;
			}
			if ( $user && 'deactivated' === wic_user_status( $user->ID ) ) {
				$notes[] = __( 'Account is closed; details are updated but it is not reopened.', 'wic-tp' );
			}
			$res['action']  = $user ? 'update' : 'create';
			$res['message'] = implode( ' ', $notes );

			if ( $commit ) {
				if ( '' !== $r['clinic'] && ! $clinic_id ) {
					$clinic_id = WIC_Clinics::ensure( $r['clinic'] );
				}
				if ( $user ) {
					$id     = (int) $user->ID;
					$update = array( 'ID' => $id );
					if ( $r['first_name'] ) {
						$update['first_name'] = $r['first_name'];
					}
					if ( $r['last_name'] ) {
						$update['last_name'] = $r['last_name'];
					}
					if ( count( $update ) > 1 ) {
						$update['display_name'] = trim( ( $r['first_name'] ? $r['first_name'] : $user->first_name ) . ' ' . ( $r['last_name'] ? $r['last_name'] : $user->last_name ) );
						wp_update_user( $update );
					}
				} else {
					$id = self::create_user( $email, $r['first_name'], $r['last_name'], $roles[ $role_key ] );
					if ( ! $id ) {
						$res['action']  = 'error';
						$res['message'] = __( 'The account could not be created.', 'wic-tp' );
						$results[]      = $res;
						continue;
					}
					if ( ! $group ) {
						$group = 'staff';
					}
				}
				if ( '' !== $r['staff_number'] ) {
					update_user_meta( $id, 'wic_staff_number', $r['staff_number'] );
				}
				if ( $clinic_id ) {
					WIC_Clinics::set_user_clinic( $id, $clinic_id );
				}
				if ( null !== $sup_id && $sup_id !== $id ) {
					update_user_meta( $id, 'wic_reports_to', $sup_id );
				}
				if ( $group && $group !== get_user_meta( $id, 'wic_group', true ) ) {
					update_user_meta( $id, 'wic_group', $group );
				}
				if ( '' !== $r['leaving_date'] ) {
					update_user_meta( $id, 'wic_deactivate_on', $r['leaving_date'] );
				}
				if ( 'active' === wic_user_status( $id ) ) {
					WIC_Records::apply_rules( $id );
				}
				if ( 'create' === $res['action'] && $opts['send_links'] ) {
					WIC_Registration::send_set_password( $id );
				}
			}
			$results[] = $res;
		}
		if ( $commit ) {
			$counts = array_count_values( wp_list_pluck( $results, 'action' ) );
			wic_audit( 'staff_import', 'import', 0, array( 'source' => $opts['source'], 'counts' => $counts ) );
		}
		return $results;
	}

	public static function view_import( $uid ) {
		$preview = get_transient( 'wic_import_' . $uid );
		$done    = get_transient( 'wic_import_done_' . $uid );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Import a staff list', 'wic-tp' ); ?></h2>
		<p class="wic-help"><?php esc_html_e( 'Upload a CSV. People are matched by email, then by staff number; matches are updated, everyone else gets a new account. Nothing is changed until you apply the preview. Nobody is ever removed by an import.', 'wic-tp' ); ?></p>
		<p class="wic-help"><?php echo esc_html( sprintf( __( 'Columns: %s. Only email (or staff_number) is required for updates.', 'wic-tp' ), implode( ', ', self::IMPORT_COLUMNS ) ) ); ?>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wic_import_template' ), 'wic_import_template' ) ); ?>"><?php esc_html_e( 'Download a template', 'wic-tp' ); ?></a></p>

		<?php if ( $done ) : ?>
			<?php delete_transient( 'wic_import_done_' . $uid ); ?>
			<?php $c = array_count_values( wp_list_pluck( $done, 'action' ) ); ?>
			<div class="wic-notice wic-notice--ok" role="status"><?php echo esc_html( sprintf( __( '%1$d created, %2$d updated, %3$d skipped with errors.', 'wic-tp' ), isset( $c['create'] ) ? $c['create'] : 0, isset( $c['update'] ) ? $c['update'] : 0, isset( $c['error'] ) ? $c['error'] : 0 ) ); ?></div>
			<?php self::results_table( $done ); ?>
		<?php endif; ?>

		<?php if ( $preview ) : ?>
			<?php $c = array_count_values( wp_list_pluck( $preview['results'], 'action' ) ); ?>
			<section class="wic-section">
				<h3 class="wic-h3"><?php esc_html_e( 'Preview', 'wic-tp' ); ?></h3>
				<p><?php echo esc_html( sprintf( __( '%1$d will be created, %2$d updated, %3$d have errors and will be skipped.', 'wic-tp' ), isset( $c['create'] ) ? $c['create'] : 0, isset( $c['update'] ) ? $c['update'] : 0, isset( $c['error'] ) ? $c['error'] : 0 ) ); ?></p>
				<?php self::results_table( $preview['results'] ); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-toolbar" style="margin-top:1rem">
					<input type="hidden" name="action" value="wic_import_commit">
					<?php wp_nonce_field( 'wic_import_commit' ); ?>
					<button type="submit" name="go" value="1" class="wic-btn wic-btn--primary" <?php disabled( empty( $c['create'] ) && empty( $c['update'] ) ); ?>><?php esc_html_e( 'Apply this import', 'wic-tp' ); ?></button>
					<button type="submit" name="go" value="0" class="wic-btn"><?php esc_html_e( 'Discard', 'wic-tp' ); ?></button>
				</form>
			</section>
		<?php endif; ?>

		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form wic-panel">
			<h3 class="wic-h3"><?php esc_html_e( 'Upload', 'wic-tp' ); ?></h3>
			<input type="hidden" name="action" value="wic_import_upload">
			<?php wp_nonce_field( 'wic_import_upload' ); ?>
			<div class="wic-field"><label for="wic-imp-file"><?php esc_html_e( 'CSV file', 'wic-tp' ); ?></label><input type="file" id="wic-imp-file" name="file" accept=".csv,text/csv" required></div>
			<div class="wic-field"><label><input type="checkbox" name="create_clinics" value="1"> <?php esc_html_e( 'Create clinics that do not exist yet', 'wic-tp' ); ?></label></div>
			<div class="wic-field"><label><input type="checkbox" name="send_links" value="1" checked> <?php esc_html_e( 'Email new people a set-password link', 'wic-tp' ); ?></label></div>
			<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Preview import', 'wic-tp' ); ?></button>
		</form>
		<?php
	}

	private static function results_table( $results ) {
		$labels = array(
			'create' => __( 'Create', 'wic-tp' ),
			'update' => __( 'Update', 'wic-tp' ),
			'error'  => __( 'Error — skipped', 'wic-tp' ),
		);
		?>
		<div class="wic-table-wrap"><table class="wic-table">
			<thead><tr><th scope="col"><?php esc_html_e( 'Row', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Person', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Email / number', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Result', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Notes', 'wic-tp' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( array_slice( $results, 0, 500 ) as $r ) : ?>
				<tr>
					<td><?php echo (int) $r['row']; ?></td>
					<td><?php echo esc_html( $r['name'] ); ?></td>
					<td><?php echo esc_html( $r['email'] ); ?></td>
					<td><span class="wic-badge wic-badge--<?php echo 'error' === $r['action'] ? 'overdue' : ( 'create' === $r['action'] ? 'complete' : 'in_progress' ); ?>"><?php echo esc_html( $labels[ $r['action'] ] ); ?></span></td>
					<td><?php echo esc_html( $r['message'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
		<?php if ( count( $results ) > 500 ) : ?>
			<p class="wic-help"><?php echo esc_html( sprintf( __( 'Showing the first 500 of %d rows.', 'wic-tp' ), count( $results ) ) ); ?></p>
		<?php endif; ?>
		<?php
	}

	public static function handle_template() {
		check_admin_referer( 'wic_import_template' );
		if ( ! current_user_can( 'wic_manage_people' ) ) {
			self::deny();
		}
		wic_send_csv(
			'staff-import-template.csv',
			array(
				self::IMPORT_COLUMNS,
				array( 'sam.example@example.invalid', 'Sam', 'Example', 'E1001', 'North Clinic (Sample)', '', 'staff', 'learner', '' ),
			)
		);
	}

	public static function handle_upload() {
		check_admin_referer( 'wic_import_upload' );
		if ( ! current_user_can( 'wic_manage_people' ) ) {
			self::deny();
		}
		$file = isset( $_FILES['file']['tmp_name'] ) ? $_FILES['file']['tmp_name'] : '';
		$text = $file && is_uploaded_file( $file ) && filesize( $file ) < 5 * MB_IN_BYTES ? file_get_contents( $file ) : '';
		$rows = $text ? self::parse_csv( $text ) : null;
		if ( null === $rows ) {
			WIC_Portal::back( 'import', 'err_import_file' );
		}
		$opts = array(
			'create_clinics' => ! empty( $_POST['create_clinics'] ),
			'send_links'     => ! empty( $_POST['send_links'] ),
			'source'         => 'csv',
		);
		set_transient(
			'wic_import_' . get_current_user_id(),
			array(
				'rows'    => $rows,
				'opts'    => $opts,
				'results' => self::import_rows( $rows, false, $opts ),
			),
			HOUR_IN_SECONDS
		);
		WIC_Portal::back( 'import', 'import_preview' );
	}

	public static function handle_commit() {
		check_admin_referer( 'wic_import_commit' );
		if ( ! current_user_can( 'wic_manage_people' ) ) {
			self::deny();
		}
		$uid     = get_current_user_id();
		$preview = get_transient( 'wic_import_' . $uid );
		delete_transient( 'wic_import_' . $uid );
		if ( empty( $_POST['go'] ) ) {
			WIC_Portal::back( 'import', 'saved' );
		}
		if ( ! $preview ) {
			WIC_Portal::back( 'import', 'err_import_expired' );
		}
		set_transient( 'wic_import_done_' . $uid, self::import_rows( $preview['rows'], true, $preview['opts'] ), HOUR_IN_SECONDS );
		WIC_Portal::back( 'import', 'import_done' );
	}

	/* ------------------------------------------------------------------ */
	/* HR system sync (#159)                                              */
	/* ------------------------------------------------------------------ */

	public static function routes() {
		register_rest_route(
			WIC_Rest::NS,
			'/hr/sync',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_sync' ),
				'permission_callback' => array( __CLASS__, 'rest_key_ok' ),
			)
		);
	}

	public static function rest_key_ok( WP_REST_Request $req ) {
		$key = (string) wic_setting( 'hr_api_key' );
		$got = (string) $req->get_header( 'x_wic_key' );
		return strlen( $key ) >= 16 && '' !== $got && hash_equals( $key, $got );
	}

	/**
	 * Body: {"rows":[{"email":…,"first_name":…}], "dry_run":true, "create_clinics":false, "send_links":true}
	 * or a text/csv body in the staff-import format.
	 */
	public static function rest_sync( WP_REST_Request $req ) {
		$ct   = $req->get_content_type();
		$type = $ct && isset( $ct['value'] ) ? (string) $ct['value'] : '';
		if ( false !== strpos( $type, 'csv' ) ) {
			$rows   = self::parse_csv( $req->get_body() );
			$params = $req->get_query_params();
		} else {
			$params = (array) $req->get_json_params();
			$rows   = isset( $params['rows'] ) && is_array( $params['rows'] ) ? $params['rows'] : null;
		}
		if ( null === $rows ) {
			return new WP_Error( 'wic_bad_rows', __( 'Send rows as JSON or a CSV with an email or staff_number column.', 'wic-tp' ), array( 'status' => 400 ) );
		}
		$dry     = ! empty( $params['dry_run'] ) && 'false' !== $params['dry_run'];
		$results = self::import_rows(
			$rows,
			! $dry,
			array(
				'create_clinics' => ! empty( $params['create_clinics'] ) && 'false' !== $params['create_clinics'],
				'send_links'     => ! isset( $params['send_links'] ) || ( $params['send_links'] && 'false' !== $params['send_links'] ),
				'source'         => 'hr_api',
			)
		);
		return array(
			'dry_run' => $dry,
			'counts'  => array_count_values( wp_list_pluck( $results, 'action' ) ),
			'rows'    => $results,
		);
	}

	public static function daily_pull() {
		$url = (string) wic_setting( 'hr_csv_url' );
		if ( ! $url ) {
			return;
		}
		$resp = wp_safe_remote_get( $url, array( 'timeout' => 30 ) );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			wic_audit( 'staff_import_failed', 'import', 0, array( 'source' => 'hr_url', 'error' => is_wp_error( $resp ) ? $resp->get_error_message() : wp_remote_retrieve_response_code( $resp ) ) );
			return;
		}
		$rows = self::parse_csv( wp_remote_retrieve_body( $resp ) );
		if ( null === $rows ) {
			wic_audit( 'staff_import_failed', 'import', 0, array( 'source' => 'hr_url', 'error' => 'unreadable' ) );
			return;
		}
		self::import_rows( $rows, true, array( 'source' => 'hr_url' ) );
	}
}

add_action( 'wic_init', array( 'WIC_People', 'init' ) );
