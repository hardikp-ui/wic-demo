<?php
/**
 * Self-registration with supervisor choice, and the approval step.
 *
 * The approver — not the registrant — decides Staff or Intern.
 * On approval the person gets a one-time set-password link, never a password in an email.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Registration {

	public static function init() {
		add_shortcode( 'wic_register', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_post_nopriv_wic_register', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_wic_register', array( __CLASS__, 'handle' ) );
	}

	public static function shortcode() {
		if ( is_user_logged_in() ) {
			return '<div class="wic-portal"><p class="wic-notice">' . sprintf(
				/* translators: %s: link to portal */
				esc_html__( 'You are already signed in. %s', 'wic-tp' ),
				'<a href="' . esc_url( wic_page_url( 'portal' ) ) . '">' . esc_html__( 'Go to the training portal', 'wic-tp' ) . '</a>'
			) . '</p></div>';
		}
		WIC_Portal::enqueue();
		$msg     = isset( $_GET['wic_msg'] ) ? sanitize_key( $_GET['wic_msg'] ) : '';
		$notices = array(
			'submitted' => array( 'ok', __( 'Thank you. Your registration has been sent to your supervisor for approval. You will receive an email with a link to set your password once it is approved.', 'wic-tp' ) ),
			'pending'   => array( 'warn', __( 'A registration with this email is already waiting for approval. Your supervisor has been reminded.', 'wic-tp' ) ),
			'active'    => array( 'warn', __( 'An account with this email already exists. Use "Lost your password?" on the sign-in page if you cannot get in.', 'wic-tp' ) ),
			'rejected'  => array( 'err', __( 'A previous registration with this email was not approved. Please contact your agency administrator.', 'wic-tp' ) ),
			'closed'    => array( 'err', __( 'An account with this email has been closed. Please contact your agency administrator to reopen it.', 'wic-tp' ) ),
			'invalid'   => array( 'err', __( 'Please fill in every field with a valid email address and choose your supervisor.', 'wic-tp' ) ),
		);
		$supervisors = WIC_Roles::supervisors( true );

		ob_start();
		?>
		<div class="wic-portal wic-register">
			<?php if ( isset( $notices[ $msg ] ) ) : ?>
				<div class="wic-notice wic-notice--<?php echo esc_attr( $notices[ $msg ][0] ); ?>" role="status"><?php echo esc_html( $notices[ $msg ][1] ); ?></div>
			<?php endif; ?>
			<?php if ( 'submitted' !== $msg ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form" novalidate>
				<input type="hidden" name="action" value="wic_register">
				<?php wp_nonce_field( 'wic_register', 'wic_nonce' ); ?>
				<div class="wic-hp" aria-hidden="true"><label>Leave empty <input type="text" name="wic_website" tabindex="-1" autocomplete="off"></label></div>
				<div class="wic-field">
					<label for="wic_first"><?php esc_html_e( 'First name', 'wic-tp' ); ?></label>
					<input type="text" id="wic_first" name="first_name" required autocomplete="given-name">
				</div>
				<div class="wic-field">
					<label for="wic_last"><?php esc_html_e( 'Last name', 'wic-tp' ); ?></label>
					<input type="text" id="wic_last" name="last_name" required autocomplete="family-name">
				</div>
				<div class="wic-field">
					<label for="wic_email"><?php esc_html_e( 'Work email', 'wic-tp' ); ?></label>
					<input type="email" id="wic_email" name="email" required autocomplete="email">
				</div>
				<div class="wic-field">
					<label for="wic_clinic_f"><?php esc_html_e( 'Clinic', 'wic-tp' ); ?></label>
					<?php if ( class_exists( 'WIC_Clinics' ) && WIC_Clinics::all() ) : ?>
						<?php echo WIC_Clinics::select( 'clinic_id', 0, 'wic_clinic_f', true ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_* ?>
					<?php else : ?>
						<input type="text" id="wic_clinic_f" name="clinic" required>
					<?php endif; ?>
				</div>
				<div class="wic-field">
					<label for="wic_sup"><?php esc_html_e( 'Your supervisor', 'wic-tp' ); ?></label>
					<select id="wic_sup" name="supervisor" required>
						<option value=""><?php esc_html_e( 'Choose your supervisor', 'wic-tp' ); ?></option>
						<?php foreach ( $supervisors as $s ) : ?>
							<option value="<?php echo esc_attr( $s->ID ); ?>"><?php echo esc_html( $s->display_name ); ?></option>
						<?php endforeach; ?>
						<option value="0"><?php esc_html_e( 'I am not sure — send it to the agency administrators', 'wic-tp' ); ?></option>
					</select>
					<p class="wic-help"><?php esc_html_e( 'Your supervisor approves your registration. If you do not know who that is, an administrator will route it.', 'wic-tp' ); ?></p>
				</div>
				<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Send registration', 'wic-tp' ); ?></button>
				<p class="wic-help"><a href="<?php echo esc_url( wic_page_url( 'portal' ) ); ?>"><?php esc_html_e( 'Already registered? Sign in', 'wic-tp' ); ?></a></p>
			</form>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function back( $msg ) {
		wp_safe_redirect( wic_page_url( 'register', array( 'wic_msg' => $msg ) ) );
		exit;
	}

	public static function handle() {
		if ( ! isset( $_POST['wic_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wic_nonce'] ), 'wic_register' ) ) {
			self::back( 'invalid' );
		}
		if ( ! empty( $_POST['wic_website'] ) ) {
			self::back( 'submitted' ); // Honeypot: pretend success to bots.
		}
		$first  = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last   = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		$email  = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$clinic    = isset( $_POST['clinic'] ) ? sanitize_text_field( wp_unslash( $_POST['clinic'] ) ) : '';
		$clinic_id = isset( $_POST['clinic_id'] ) ? absint( $_POST['clinic_id'] ) : 0;
		if ( $clinic_id && class_exists( 'WIC_Clinics' ) ) {
			$record = WIC_Clinics::get( $clinic_id );
			$clinic = $record && 'active' === $record->status ? $record->name : '';
		}
		$sup_raw = isset( $_POST['supervisor'] ) ? (string) wp_unslash( $_POST['supervisor'] ) : '';
		$sup     = absint( $sup_raw );

		// "0" is a real choice: no supervisor known, so administrators route it (#12).
		$valid_sups = array_map( 'intval', wp_list_pluck( WIC_Roles::supervisors( true ), 'ID' ) );
		if ( ! $first || ! $last || ! is_email( $email ) || ! $clinic || '' === $sup_raw || ( $sup && ! in_array( $sup, $valid_sups, true ) ) ) {
			self::back( 'invalid' );
		}

		// Duplicate email: a different answer for each state the existing account is in.
		$existing = get_user_by( 'email', $email );
		if ( $existing ) {
			$status = wic_user_status( $existing->ID );
			if ( 'pending' === $status ) {
				foreach ( self::approvers_for( $existing->ID ) as $approver ) {
					WIC_Notify::event( $approver, 'approval_waiting', $existing->ID, sprintf( __( '%s is still waiting for you to approve their registration.', 'wic-tp' ), $existing->display_name ), true );
				}
				self::back( 'pending' );
			}
			self::back( 'rejected' === $status ? 'rejected' : ( 'deactivated' === $status ? 'closed' : 'active' ) );
		}

		$login   = sanitize_user( strtolower( current( explode( '@', $email ) ) ), true );
		$base    = $login ? $login : 'staff';
		$counter = 1;
		while ( username_exists( $login ) || ! $login ) {
			$login = $base . ( ++$counter );
		}
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => trim( $first . ' ' . $last ),
				'role'         => 'wic_learner',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			self::back( 'invalid' );
		}
		update_user_meta( $user_id, 'wic_status', 'pending' );
		update_user_meta( $user_id, 'wic_reports_to', $sup );
		update_user_meta( $user_id, 'wic_clinic', $clinic );
		if ( $clinic_id && class_exists( 'WIC_Clinics' ) ) {
			WIC_Clinics::set_user_clinic( $user_id, $clinic_id );
		}
		wic_audit( 'register', 'user', $user_id, array( 'supervisor' => $sup ) );
		foreach ( self::approvers_for( $user_id ) as $approver ) {
			WIC_Notify::event( $approver, 'approval_waiting', $user_id, sprintf( __( '%1$s (%2$s) has registered and is waiting for your approval.', 'wic-tp' ), trim( $first . ' ' . $last ), $clinic ), true );
		}
		self::back( 'submitted' );
	}

	/** Who is told about a pending registration: the chosen supervisor, or the administrators when none was chosen. */
	public static function approvers_for( $user_id ) {
		$sup = wic_reports_to( $user_id );
		if ( $sup ) {
			return array( $sup );
		}
		return array_map( 'intval', get_users( array( 'role' => 'wic_admin', 'fields' => 'ID' ) ) );
	}

	public static function pending_for( $viewer_id ) {
		$args = array(
			'meta_query' => array(
				array(
					'key'   => 'wic_status',
					'value' => 'pending',
				),
			),
			'orderby'    => 'registered',
			'order'      => 'ASC',
			// Vendor applications have their own queue.
			'role__not_in' => array( 'wic_vendor' ),
		);
		$clinics = user_can( $viewer_id, 'wic_manage_clinic' ) && class_exists( 'WIC_People' ) ? WIC_People::admin_clinics( $viewer_id ) : array();
		if ( ! user_can( $viewer_id, 'wic_view_all' ) ) {
			if ( $clinics ) {
				// Local administrators approve for their clinics as well as their own reports.
				$args['meta_query'][] = array(
					'relation' => 'OR',
					array(
						'key'   => 'wic_reports_to',
						'value' => (int) $viewer_id,
					),
					array(
						'key'     => 'wic_clinic_id',
						'value'   => $clinics,
						'compare' => 'IN',
					),
				);
			} else {
				$args['meta_query'][] = array(
					'key'   => 'wic_reports_to',
					'value' => (int) $viewer_id,
				);
			}
		}
		return get_users( $args );
	}

	public static function can_approve( $viewer_id, $user_id ) {
		if ( ! user_can( $viewer_id, 'wic_approve' ) || 'pending' !== wic_user_status( $user_id ) ) {
			return false;
		}
		if ( user_can( $viewer_id, 'wic_view_all' ) || wic_reports_to( $user_id ) === (int) $viewer_id ) {
			return true;
		}
		return user_can( $viewer_id, 'wic_manage_clinic' ) && class_exists( 'WIC_People' ) && in_array( wic_user_clinic_id( $user_id ), WIC_People::admin_clinics( $viewer_id ), true );
	}

	/**
	 * The one-time set-password email (#7). Never a password in an email.
	 * Used on approval, on direct account creation and by imports.
	 */
	public static function send_set_password( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return false;
		}
		$url  = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
		$body = sprintf(
			'<p>%s</p><p>%s</p><p><a href="%s">%s</a></p><p>%s</p>',
			esc_html( sprintf( __( 'Hello %s,', 'wic-tp' ), $user->first_name ? $user->first_name : $user->display_name ) ),
			esc_html__( 'Your training portal account is ready. Use the link below to set your own password. The link can be used once.', 'wic-tp' ),
			esc_url( $url ),
			esc_html__( 'Set my password', 'wic-tp' ),
			esc_html( sprintf( __( 'Your username is %s.', 'wic-tp' ), $user->user_login ) )
		);
		wic_audit( 'set_password_link', 'user', $user_id );
		return WIC_Notify::mail( $user->user_email, __( 'Your training portal account is ready', 'wic-tp' ), $body );
	}

	public static function approve( $user_id, $group ) {
		$group = in_array( $group, array( 'staff', 'intern' ), true ) ? $group : 'staff';
		update_user_meta( $user_id, 'wic_group', $group );
		update_user_meta( $user_id, 'wic_status', 'active' );
		update_user_meta( $user_id, 'wic_approved_at', wic_now() );
		wic_audit( 'approve', 'user', $user_id, array( 'group' => $group ) );
		WIC_Records::apply_rules( $user_id );

		self::send_set_password( $user_id );
		WIC_Notify::event( $user_id, 'welcome', 0, __( 'Welcome. Your required training is listed under My training.', 'wic-tp' ), false );
	}

	public static function reject( $user_id ) {
		update_user_meta( $user_id, 'wic_status', 'rejected' );
		wic_audit( 'reject', 'user', $user_id );
	}
}
