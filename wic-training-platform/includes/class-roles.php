<?php
/**
 * Roles, the reports-to line, and the rule that people are deactivated, never deleted.
 *
 * Staff and Intern are a group on the account, not separate roles — so an intern
 * becoming staff is a field change and keeps every record.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Roles {

	const ROLES = array( 'wic_admin', 'wic_supervisor', 'wic_learner', 'wic_author' );

	/** Content permissions are kept separate from people permissions from the start. */
	const CONTENT_CAPS = array(
		'edit_wic_items',
		'edit_others_wic_items',
		'edit_published_wic_items',
		'edit_private_wic_items',
		'publish_wic_items',
		'read_private_wic_items',
		'delete_wic_items',
		'delete_others_wic_items',
		'delete_published_wic_items',
		'delete_private_wic_items',
		'wic_manage_content',
		'upload_files',
	);

	public static function init() {
		add_filter( 'map_meta_cap', array( __CLASS__, 'block_delete' ), 10, 4 );
		add_filter( 'authenticate', array( __CLASS__, 'block_inactive_login' ), 30 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'profile_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
		add_filter( 'user_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'manage_users_columns', array( __CLASS__, 'columns' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'column_value' ), 10, 3 );
	}

	public static function add_roles() {
		$content = array_fill_keys( self::CONTENT_CAPS, true );

		$learner    = array(
			'read'      => true,
			'wic_learn' => true,
		);
		$supervisor = $learner + array(
			'wic_approve'   => true,
			'wic_view_team' => true,
		);
		$admin      = $supervisor + $content + array(
			'wic_view_all'        => true,
			'wic_manage_people'   => true,
			'wic_manage_settings' => true,
			'list_users'          => true,
			'edit_users'          => true,
			'create_users'        => true,
		);
		$author     = array( 'read' => true ) + $content;

		$defs = array(
			'wic_admin'      => array( __( 'WIC Administrator', 'wic-tp' ), $admin ),
			'wic_supervisor' => array( __( 'WIC Supervisor', 'wic-tp' ), $supervisor ),
			'wic_learner'    => array( __( 'WIC Learner', 'wic-tp' ), $learner ),
			'wic_author'     => array( __( 'WIC Content Author', 'wic-tp' ), $author ),
		);
		/**
		 * Modules add roles (vendor, local administrator) or capabilities here.
		 * Each entry: role => array( label, caps ). Caps added to wic_admin are also given to WP administrators.
		 */
		$defs  = apply_filters( 'wic_role_defs', $defs );
		$admin = $defs['wic_admin'][1];

		foreach ( $defs as $role => $def ) {
			remove_role( $role );
			add_role( $role, $def[0], $def[1] );
		}

		$wp_admin = get_role( 'administrator' );
		if ( $wp_admin ) {
			foreach ( array_keys( $admin ) as $cap ) {
				$wp_admin->add_cap( $cap );
			}
		}
	}

	/** No delete path against a person who holds training records. */
	public static function block_delete( $caps, $cap, $user_id, $args ) {
		if ( in_array( $cap, array( 'delete_user', 'remove_user' ), true ) && ! empty( $args[0] ) && wic_is_wic_user( (int) $args[0] ) ) {
			$caps[] = 'do_not_allow';
		}
		return $caps;
	}

	public static function block_inactive_login( $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return $user;
		}
		$status = get_user_meta( $user->ID, 'wic_status', true );
		if ( ! $status || 'active' === $status ) {
			return $user;
		}
		$messages = array(
			'pending'     => __( 'Your registration is waiting for your supervisor to approve it.', 'wic-tp' ),
			'rejected'    => __( 'Your registration was not approved. Please contact your agency administrator.', 'wic-tp' ),
			'deactivated' => __( 'This account has been closed. Please contact your agency administrator.', 'wic-tp' ),
		);
		if ( in_array( 'wic_vendor', (array) $user->roles, true ) ) {
			$messages['pending']  = __( 'Your vendor application is waiting for the agency to review it.', 'wic-tp' );
			$messages['rejected'] = __( 'Your vendor application was not approved. Please call the vendor helpline.', 'wic-tp' );
		} elseif ( 'pending' === $status && ! wic_reports_to( $user->ID ) ) {
			$messages['pending'] = __( 'Your registration is waiting for an agency administrator to approve it.', 'wic-tp' );
		}
		return new WP_Error( 'wic_inactive', isset( $messages[ $status ] ) ? $messages[ $status ] : $messages['deactivated'] );
	}

	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof WP_User && user_can( $user, 'wic_vendor' ) && wic_page_id( 'vendor' ) ) {
			return wic_page_url( 'vendor' );
		}
		if ( $user instanceof WP_User && user_can( $user, 'wic_learn' ) && ! user_can( $user, 'wic_manage_content' ) ) {
			return wic_page_url( 'portal' );
		}
		return $redirect_to;
	}

	public static function admin_bar( $show ) {
		if ( is_user_logged_in() && ! current_user_can( 'wic_manage_content' ) && ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		return $show;
	}

	/** Every platform role, core plus module roles. */
	public static function all_roles() {
		return array_keys( apply_filters( 'wic_role_defs', array_fill_keys( self::ROLES, array( '', array() ) ) ) );
	}

	public static function supervisors( $for_registration = false ) {
		$args  = array(
			'role__in' => apply_filters( 'wic_supervisor_roles', array( 'wic_supervisor', 'wic_admin' ) ),
			'orderby'  => 'display_name',
		);
		$users = apply_filters( 'wic_supervisors', get_users( $args ), $for_registration );
		return array_values(
			array_filter(
				$users,
				function ( $u ) use ( $for_registration ) {
					if ( 'active' !== wic_user_status( $u->ID ) ) {
						return false;
					}
					// Test and service accounts never appear on the public registration list.
					if ( $for_registration && get_user_meta( $u->ID, 'wic_hide_from_registration', true ) ) {
						return false;
					}
					return true;
				}
			)
		);
	}

	public static function profile_fields( $user ) {
		if ( ! current_user_can( 'wic_manage_people' ) ) {
			return;
		}
		$status  = wic_user_status( $user->ID );
		$group   = wic_user_group( $user->ID );
		$reports = wic_reports_to( $user->ID );
		wp_nonce_field( 'wic_profile', 'wic_profile_nonce' );
		?>
		<h2><?php esc_html_e( 'WIC Training', 'wic-tp' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="wic_status"><?php esc_html_e( 'Account status', 'wic-tp' ); ?></label></th>
				<td>
					<select name="wic_status" id="wic_status">
						<?php foreach ( array( 'active', 'pending', 'rejected', 'deactivated' ) as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Closing an account keeps every completion record. Accounts are never deleted.', 'wic-tp' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="wic_group"><?php esc_html_e( 'Learner group', 'wic-tp' ); ?></label></th>
				<td>
					<select name="wic_group" id="wic_group">
						<option value="staff" <?php selected( $group, 'staff' ); ?>><?php esc_html_e( 'Staff', 'wic-tp' ); ?></option>
						<option value="intern" <?php selected( $group, 'intern' ); ?>><?php esc_html_e( 'Intern', 'wic-tp' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Changing group keeps all progress and adds any newly required courses.', 'wic-tp' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="wic_reports_to"><?php esc_html_e( 'Reports to', 'wic-tp' ); ?></label></th>
				<td>
					<select name="wic_reports_to" id="wic_reports_to">
						<option value="0"><?php esc_html_e( '— Nobody —', 'wic-tp' ); ?></option>
						<?php foreach ( self::supervisors() as $s ) : ?>
							<?php
							if ( (int) $s->ID === (int) $user->ID ) {
								continue;
							}
							?>
							<option value="<?php echo esc_attr( $s->ID ); ?>" <?php selected( $reports, $s->ID ); ?>><?php echo esc_html( $s->display_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="wic_clinic"><?php esc_html_e( 'Clinic', 'wic-tp' ); ?></label></th>
				<td>
					<?php if ( class_exists( 'WIC_Clinics' ) ) : ?>
						<?php echo WIC_Clinics::select( 'wic_clinic_id', wic_user_clinic_id( $user->ID ), 'wic_clinic', false, __( '— No clinic —', 'wic-tp' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_* ?>
					<?php else : ?>
						<input type="text" class="regular-text" name="wic_clinic" id="wic_clinic" value="<?php echo esc_attr( get_user_meta( $user->ID, 'wic_clinic', true ) ); ?>">
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="wic_staff_number"><?php esc_html_e( 'Staff number', 'wic-tp' ); ?></label></th>
				<td><input type="text" class="regular-text" name="wic_staff_number" id="wic_staff_number" value="<?php echo esc_attr( get_user_meta( $user->ID, 'wic_staff_number', true ) ); ?>">
					<p class="description"><?php esc_html_e( 'Used to match this person against an HR list.', 'wic-tp' ); ?></p></td>
			</tr>
			<tr>
				<th><label for="wic_mentor"><?php esc_html_e( 'Mentor', 'wic-tp' ); ?></label></th>
				<td><?php
					wp_dropdown_users(
						array(
							'name'              => 'wic_mentor',
							'id'                => 'wic_mentor',
							'selected'          => (int) get_user_meta( $user->ID, 'wic_mentor', true ),
							'show_option_none'  => __( '— No mentor —', 'wic-tp' ),
							'option_none_value' => 0,
							'exclude'           => array( $user->ID ),
							'role__in'          => self::all_roles(),
						)
					);
				?></td>
			</tr>
			<?php if ( in_array( 'wic_local_admin', (array) $user->roles, true ) && class_exists( 'WIC_Clinics' ) ) : ?>
				<?php $mine = class_exists( 'WIC_People' ) ? WIC_People::admin_clinics( $user->ID ) : array(); ?>
				<tr>
					<th><?php esc_html_e( 'Clinics they administer', 'wic-tp' ); ?></th>
					<td>
						<input type="hidden" name="wic_admin_clinics_sent" value="1">
						<?php foreach ( WIC_Clinics::all() as $c ) : ?>
							<label style="display:block"><input type="checkbox" name="wic_admin_clinics[]" value="<?php echo (int) $c->id; ?>" <?php checked( in_array( (int) $c->id, $mine, true ) ); ?>> <?php echo esc_html( $c->name ); ?></label>
						<?php endforeach; ?>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<th><label for="wic_deactivate_on"><?php esc_html_e( 'Leaving date', 'wic-tp' ); ?></label></th>
				<td>
					<input type="date" name="wic_deactivate_on" id="wic_deactivate_on" value="<?php echo esc_attr( get_user_meta( $user->ID, 'wic_deactivate_on', true ) ); ?>">
					<p class="description"><?php esc_html_e( 'The account closes itself on this date.', 'wic-tp' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Registration list', 'wic-tp' ); ?></th>
				<td><label><input type="checkbox" name="wic_hide_from_registration" value="1" <?php checked( (bool) get_user_meta( $user->ID, 'wic_hide_from_registration', true ) ); ?>> <?php esc_html_e( 'Hide from the supervisor list on the registration form (test or service accounts)', 'wic-tp' ); ?></label></td>
			</tr>
		</table>
		<?php
	}

	public static function save_profile( $user_id ) {
		if ( ! current_user_can( 'wic_manage_people' ) || ! isset( $_POST['wic_profile_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wic_profile_nonce'] ), 'wic_profile' ) ) {
			return;
		}
		$old_group   = wic_user_group( $user_id );
		$old_reports = wic_reports_to( $user_id );
		$old_status  = wic_user_status( $user_id );

		$status = isset( $_POST['wic_status'] ) ? sanitize_key( $_POST['wic_status'] ) : $old_status;
		$group  = isset( $_POST['wic_group'] ) && in_array( $_POST['wic_group'], array( 'staff', 'intern' ), true ) ? sanitize_key( $_POST['wic_group'] ) : $old_group;
		$report = isset( $_POST['wic_reports_to'] ) ? absint( $_POST['wic_reports_to'] ) : $old_reports;
		if ( $report === (int) $user_id ) {
			$report = 0;
		}

		update_user_meta( $user_id, 'wic_status', $status );
		update_user_meta( $user_id, 'wic_group', $group );
		update_user_meta( $user_id, 'wic_reports_to', $report );
		if ( isset( $_POST['wic_clinic_id'] ) && class_exists( 'WIC_Clinics' ) ) {
			WIC_Clinics::set_user_clinic( $user_id, absint( $_POST['wic_clinic_id'] ) );
		} elseif ( isset( $_POST['wic_clinic'] ) ) {
			update_user_meta( $user_id, 'wic_clinic', sanitize_text_field( wp_unslash( $_POST['wic_clinic'] ) ) );
		}
		if ( isset( $_POST['wic_staff_number'] ) ) {
			update_user_meta( $user_id, 'wic_staff_number', sanitize_text_field( wp_unslash( $_POST['wic_staff_number'] ) ) );
		}
		if ( isset( $_POST['wic_mentor'] ) ) {
			$mentor = absint( $_POST['wic_mentor'] );
			update_user_meta( $user_id, 'wic_mentor', $mentor === (int) $user_id ? 0 : $mentor );
		}
		if ( ! empty( $_POST['wic_admin_clinics_sent'] ) ) {
			update_user_meta( $user_id, 'wic_admin_clinics', isset( $_POST['wic_admin_clinics'] ) ? array_map( 'absint', (array) $_POST['wic_admin_clinics'] ) : array() );
		}
		update_user_meta( $user_id, 'wic_deactivate_on', isset( $_POST['wic_deactivate_on'] ) ? sanitize_text_field( wp_unslash( $_POST['wic_deactivate_on'] ) ) : '' );
		update_user_meta( $user_id, 'wic_hide_from_registration', empty( $_POST['wic_hide_from_registration'] ) ? '' : '1' );

		if ( $old_status !== $status ) {
			wic_audit( 'user_status', 'user', $user_id, array( 'from' => $old_status, 'to' => $status ) );
		}
		if ( $old_reports !== $report ) {
			wic_audit( 'user_reports_to', 'user', $user_id, array( 'from' => $old_reports, 'to' => $report ) );
		}
		if ( $old_group !== $group ) {
			wic_audit( 'user_group', 'user', $user_id, array( 'from' => $old_group, 'to' => $group ) );
		}
		// Additive only: re-running the rules never removes anything already assigned or completed.
		if ( 'active' === $status ) {
			WIC_Records::apply_rules( $user_id );
		}
	}

	public static function set_status( $user_id, $status ) {
		$old = wic_user_status( $user_id );
		update_user_meta( $user_id, 'wic_status', $status );
		if ( 'deactivated' === $status && class_exists( 'WP_Session_Tokens' ) ) {
			WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		}
		wic_audit( 'user_status', 'user', $user_id, array( 'from' => $old, 'to' => $status ) );
	}

	public static function row_actions( $actions, $user ) {
		if ( ! current_user_can( 'wic_manage_people' ) || ! wic_is_wic_user( $user->ID ) ) {
			return $actions;
		}
		unset( $actions['delete'], $actions['remove'] );
		$status = wic_user_status( $user->ID );
		$target = 'deactivated' === $status ? 'active' : 'deactivated';
		$label  = 'deactivated' === $status ? __( 'Reactivate', 'wic-tp' ) : __( 'Deactivate', 'wic-tp' );
		$url    = wp_nonce_url( admin_url( 'admin-post.php?action=wic_set_status&user=' . $user->ID . '&status=' . $target ), 'wic_set_status_' . $user->ID );
		$actions['wic_status'] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		return $actions;
	}

	public static function columns( $cols ) {
		$cols['wic_status']  = __( 'WIC status', 'wic-tp' );
		$cols['wic_reports'] = __( 'Reports to', 'wic-tp' );
		return $cols;
	}

	public static function column_value( $value, $column, $user_id ) {
		if ( 'wic_status' === $column ) {
			return wic_is_wic_user( $user_id ) ? esc_html( ucfirst( wic_user_status( $user_id ) ) . ' · ' . wic_group_label( wic_user_group( $user_id ) ) ) : '—';
		}
		if ( 'wic_reports' === $column ) {
			$sup = wic_reports_to( $user_id );
			return $sup && get_userdata( $sup ) ? esc_html( get_userdata( $sup )->display_name ) : '—';
		}
		return $value;
	}
}
