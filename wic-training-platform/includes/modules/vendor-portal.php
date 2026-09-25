<?php
/**
 * Vendor portal (#128–#133).
 *
 * Largely a second instance of accounts, records and certificates with the supervisor removed:
 * vendors apply themselves, the agency approves, they take annual training that resets each
 * calendar year, and their certificate can be checked by an inspector on the public verify page.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_role_defs',
	function ( $defs ) {
		$defs['wic_vendor'] = array(
			__( 'WIC Vendor', 'wic-tp' ),
			array(
				'read'       => true,
				'wic_learn'  => true,
				'wic_vendor' => true,
			),
		);
		return $defs;
	}
);

add_filter(
	'wic_pages',
	function ( $pages ) {
		$pages['vendor'] = array( 'Vendor Portal', '[wic_vendor]' );
		return $pages;
	}
);

add_filter(
	'wic_portal_style_pages',
	function ( $keys ) {
		$keys[] = 'vendor';
		return $keys;
	}
);

add_filter(
	'wic_agency_defaults',
	function ( $d ) {
		$d['vendor_help_phone'] = '';
		$d['vendor_help_email'] = '';
		$d['vendor_help_hours'] = '';
		return $d;
	}
);

add_filter(
	'wic_agency_fields',
	function ( $f ) {
		$f['vendor_help_phone'] = array( __( 'Vendor helpline phone', 'wic-tp' ), 'text', __( 'Shown only on the vendor portal — keep it separate from the participant line.', 'wic-tp' ) );
		$f['vendor_help_email'] = array( __( 'Vendor helpline email', 'wic-tp' ), 'text' );
		$f['vendor_help_hours'] = array( __( 'Vendor helpline hours', 'wic-tp' ), 'text' );
		return $f;
	}
);

class WIC_Vendor {

	const FIELDS = array( 'store', 'number', 'address', 'phone' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_types' ) );
		add_action( 'wic_register_types', array( __CLASS__, 'register_types' ) );
		add_shortcode( 'wic_vendor', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_post_nopriv_wic_vendor_apply', array( __CLASS__, 'handle_apply' ) );
		add_action( 'admin_post_wic_vendor_apply', array( __CLASS__, 'handle_apply' ) );
		add_action( 'admin_post_wic_vendor_decide', array( __CLASS__, 'handle_decide' ) );
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		add_filter( 'wic_flash_messages', array( __CLASS__, 'messages' ) );
		add_filter( 'wic_derive_status', array( __CLASS__, 'calendar_year_status' ), 20, 4 );
		add_action( 'wic_course_completed', array( __CLASS__, 'calendar_year_expiry' ), 20 );
		add_action( 'wic_verify_details', array( __CLASS__, 'verify_details' ) );
		add_filter( 'wic_can_view_certificate', array( __CLASS__, 'can_view_certificate' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'keep_vendors_home' ), 5 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_wic_course', array( __CLASS__, 'save_course' ), 20 );
		add_action( 'save_post_wic_vendor_res', array( __CLASS__, 'save_resource' ) );
		add_action( 'wic_apply_rules', array( __CLASS__, 'assign_vendor_training' ) );
		add_action( 'wic_daily', array( __CLASS__, 'daily_reset' ) );
	}

	public static function register_types() {
		register_post_type(
			'wic_vendor_res',
			array(
				'labels'          => array(
					'name'          => __( 'Vendor resources', 'wic-tp' ),
					'singular_name' => __( 'Vendor resource', 'wic-tp' ),
					'add_new_item'  => __( 'Add vendor resource', 'wic-tp' ),
					'edit_item'     => __( 'Edit vendor resource', 'wic-tp' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'wic-platform',
				'show_in_rest'    => false,
				'capability_type' => array( 'wic_item', 'wic_items' ),
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'editor', 'page-attributes', 'revisions' ),
			)
		);
	}

	public static function is_vendor( $user_id ) {
		$u = get_userdata( $user_id );
		return $u && in_array( 'wic_vendor', (array) $u->roles, true );
	}

	public static function vendor_courses() {
		return get_posts(
			array(
				'post_type'      => 'wic_course',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_key'       => '_wic_vendor_course',
				'meta_value'     => '1',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	private static function year_end_gmt() {
		return get_gmt_from_date( current_time( 'Y' ) . '-12-31 23:59:59' );
	}

	/** Vendors get every vendor course, due by the end of the calendar year. Additive only. */
	public static function assign_vendor_training( $user_id ) {
		if ( ! self::is_vendor( $user_id ) || 'active' !== wic_user_status( $user_id ) ) {
			return;
		}
		foreach ( self::vendor_courses() as $c ) {
			WIC_Records::assign( $user_id, $c->ID, 'vendor', self::year_end_gmt() );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Annual training that resets each calendar year (#131)             */
	/* ------------------------------------------------------------------ */

	private static function is_calendar_year( $course_id ) {
		return 'calendar_year' === get_post_meta( $course_id, '_wic_validity_mode', true );
	}

	private static function local_year( $mysql_gmt ) {
		return (int) get_date_from_gmt( $mysql_gmt, 'Y' );
	}

	/** A completion only counts in the calendar year it was earned. */
	public static function calendar_year_status( $status, $assignment, $completion, $position ) {
		if ( $completion && in_array( $status, array( 'complete', 'expired' ), true ) && self::is_calendar_year( $assignment->course_id ) ) {
			return self::local_year( $completion->completed_at ) < (int) current_time( 'Y' ) ? 'expired' : 'complete';
		}
		return $status;
	}

	/** The certificate says so: valid until 31 December of the year it was issued. */
	public static function calendar_year_expiry( $completion ) {
		global $wpdb;
		if ( ! self::is_calendar_year( $completion->course_id ) ) {
			return;
		}
		$year = self::local_year( $completion->completed_at );
		$wpdb->update(
			wic_table( 'certificates' ),
			array( 'expires_at' => get_gmt_from_date( $year . '-12-31 23:59:59' ) ),
			array( 'completion_id' => $completion->id )
		);
	}

	/**
	 * In a new year, anyone whose calendar-year course was last completed in an earlier year starts
	 * a fresh run (the old run and certificate stay on the record) and gets a new year-end due date.
	 */
	public static function reset_user( $user_id ) {
		global $wpdb;
		foreach ( WIC_Records::user_assignments( $user_id ) as $a ) {
			if ( ! self::is_calendar_year( $a['course_id'] ) || 'expired' !== $a['status'] || ! $a['completion'] ) {
				continue;
			}
			if ( (int) $a['completion']->run === WIC_Records::current_run( $user_id, $a['course_id'] ) ) {
				WIC_Records::restart( $user_id, $a['course_id'] );
				$wpdb->update( wic_table( 'assignments' ), array( 'due_at' => self::year_end_gmt() ), array( 'id' => $a['assignment_id'] ) );
				WIC_Notify::event( $user_id, 'annual_reset', $a['assignment_id'], sprintf( __( 'Your annual training "%s" is due again this year.', 'wic-tp' ), $a['title'] ), true );
			}
		}
	}

	public static function daily_reset() {
		global $wpdb;
		$courses = get_posts(
			array(
				'post_type'      => 'wic_course',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_wic_validity_mode',
				'meta_value'     => 'calendar_year',
			)
		);
		if ( ! $courses ) {
			return;
		}
		$ids   = implode( ',', array_map( 'intval', $courses ) );
		$users = $wpdb->get_col( 'SELECT DISTINCT user_id FROM ' . wic_table( 'assignments' ) . " WHERE status = 'active' AND course_id IN ($ids)" ); // phpcs:ignore -- integers only.
		foreach ( $users as $uid ) {
			self::reset_user( (int) $uid );
		}
		// New vendor courses reach existing vendors too.
		foreach ( get_users( array( 'role' => 'wic_vendor', 'fields' => 'ID' ) ) as $vid ) {
			self::assign_vendor_training( (int) $vid );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Certificates and verification (#132)                               */
	/* ------------------------------------------------------------------ */

	public static function verify_details( $cert ) {
		if ( ! self::is_vendor( $cert->user_id ) ) {
			return;
		}
		$store = get_user_meta( $cert->user_id, 'wic_vendor_store', true );
		echo '<dt>' . esc_html__( 'Certificate type', 'wic-tp' ) . '</dt><dd>' . esc_html__( 'Authorised WIC vendor training', 'wic-tp' ) . '</dd>';
		if ( $store ) {
			echo '<dt>' . esc_html__( 'Store', 'wic-tp' ) . '</dt><dd>' . esc_html( $store ) . '</dd>';
		}
		$num = get_user_meta( $cert->user_id, 'wic_vendor_number', true );
		if ( $num ) {
			echo '<dt>' . esc_html__( 'Vendor ID', 'wic-tp' ) . '</dt><dd>' . esc_html( $num ) . '</dd>';
		}
	}

	/** Administrators can open vendor certificates even though vendors are not in any team. */
	public static function can_view_certificate( $allowed, $cert, $viewer ) {
		return $allowed || ( user_can( $viewer, 'wic_manage_people' ) && self::is_vendor( $cert->user_id ) );
	}

	/** Vendors live on the vendor page; the staff portal is not theirs. */
	public static function keep_vendors_home() {
		if ( ! is_user_logged_in() || ! wic_page_id( 'portal' ) || ! wic_page_id( 'vendor' ) || ! is_page( wic_page_id( 'portal' ) ) ) {
			return;
		}
		if ( self::is_vendor( get_current_user_id() ) ) {
			wp_safe_redirect( wic_page_url( 'vendor' ) );
			exit;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Authoring boxes                                                    */
	/* ------------------------------------------------------------------ */

	public static function meta_boxes() {
		add_meta_box( 'wic_vendor_course', __( 'Vendor training', 'wic-tp' ), array( __CLASS__, 'course_box' ), 'wic_course', 'side' );
		add_meta_box( 'wic_vendor_res_meta', __( 'File or link', 'wic-tp' ), array( __CLASS__, 'resource_box' ), 'wic_vendor_res', 'normal', 'high' );
	}

	public static function course_box( $post ) {
		wp_nonce_field( 'wic_vendor_course', 'wic_vendor_course_nonce' );
		?>
		<p><label><input type="checkbox" name="wic_vendor_course" value="1" <?php checked( get_post_meta( $post->ID, '_wic_vendor_course', true ), '1' ); ?>> <?php esc_html_e( 'Assign to every approved vendor', 'wic-tp' ); ?></label></p>
		<p><label><input type="checkbox" name="wic_validity_calendar" value="1" <?php checked( get_post_meta( $post->ID, '_wic_validity_mode', true ), 'calendar_year' ); ?>> <?php esc_html_e( 'Resets each calendar year (completion valid until 31 December)', 'wic-tp' ); ?></label></p>
		<?php
	}

	public static function save_course( $post_id ) {
		if ( ! isset( $_POST['wic_vendor_course_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wic_vendor_course_nonce'] ), 'wic_vendor_course' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, '_wic_vendor_course', empty( $_POST['wic_vendor_course'] ) ? '' : '1' );
		update_post_meta( $post_id, '_wic_validity_mode', empty( $_POST['wic_validity_calendar'] ) ? '' : 'calendar_year' );
	}

	public static function resource_box( $post ) {
		wp_nonce_field( 'wic_vendor_res', 'wic_vendor_res_nonce' );
		?>
		<p><label for="wic_vres_url"><?php esc_html_e( 'File or page URL (optional)', 'wic-tp' ); ?></label><br>
			<input type="url" class="large-text" id="wic_vres_url" name="wic_vres_url" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wic_file_url', true ) ); ?>"></p>
		<p class="description"><?php esc_html_e( 'Upload a guide or form in Media and paste its URL here. Shown to approved vendors on the vendor portal.', 'wic-tp' ); ?></p>
		<?php
	}

	public static function save_resource( $post_id ) {
		if ( ! isset( $_POST['wic_vendor_res_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wic_vendor_res_nonce'] ), 'wic_vendor_res' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, '_wic_file_url', isset( $_POST['wic_vres_url'] ) ? esc_url_raw( wp_unslash( $_POST['wic_vres_url'] ) ) : '' );
	}

	/* ------------------------------------------------------------------ */
	/* Vendor page                                                        */
	/* ------------------------------------------------------------------ */

	public static function messages( $m ) {
		$m['vendor_approved'] = __( 'Vendor approved. A set-password link has been sent.', 'wic-tp' );
		$m['vendor_rejected'] = __( 'Vendor application declined.', 'wic-tp' );
		return $m;
	}

	private static function helpline() {
		$phone = wic_setting( 'vendor_help_phone' );
		$email = wic_setting( 'vendor_help_email' );
		$hours = wic_setting( 'vendor_help_hours' );
		if ( ! $phone && ! $email ) {
			return;
		}
		?>
		<section class="wic-panel wic-section" aria-labelledby="wic-vh">
			<h3 class="wic-h3" id="wic-vh"><?php esc_html_e( 'Vendor helpline', 'wic-tp' ); ?></h3>
			<dl class="wic-dl">
				<?php if ( $phone ) : ?><dt><?php esc_html_e( 'Phone', 'wic-tp' ); ?></dt><dd><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a></dd><?php endif; ?>
				<?php if ( $email ) : ?><dt><?php esc_html_e( 'Email', 'wic-tp' ); ?></dt><dd><a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a></dd><?php endif; ?>
				<?php if ( $hours ) : ?><dt><?php esc_html_e( 'Hours', 'wic-tp' ); ?></dt><dd><?php echo esc_html( $hours ); ?></dd><?php endif; ?>
			</dl>
			<p class="wic-help"><?php esc_html_e( 'This line is for authorised stores and retailers. Participants should contact their clinic.', 'wic-tp' ); ?></p>
		</section>
		<?php
	}

	public static function shortcode() {
		WIC_Portal::enqueue();
		ob_start();
		echo '<div class="wic-portal wic-vendor">';
		$msg     = isset( $_GET['wic_msg'] ) ? sanitize_key( $_GET['wic_msg'] ) : '';
		$notices = array(
			'applied'  => array( 'ok', __( 'Thank you. Your application has been sent to the agency. You will get an email with a link to set your password once it is approved.', 'wic-tp' ) ),
			'pending'  => array( 'warn', __( 'An application with this email is already waiting for review.', 'wic-tp' ) ),
			'active'   => array( 'warn', __( 'An account with this email already exists. Sign in below, or use "Lost your password?".', 'wic-tp' ) ),
			'rejected' => array( 'err', __( 'A previous application with this email was not approved. Please call the vendor helpline.', 'wic-tp' ) ),
			'closed'   => array( 'err', __( 'An account with this email has been closed. Please call the vendor helpline.', 'wic-tp' ) ),
			'invalid'  => array( 'err', __( 'Please fill in every field with a valid email address.', 'wic-tp' ) ),
		);
		if ( isset( $notices[ $msg ] ) ) {
			echo '<div class="wic-notice wic-notice--' . esc_attr( $notices[ $msg ][0] ) . '" role="status">' . esc_html( $notices[ $msg ][1] ) . '</div>';
		}
		if ( ! is_user_logged_in() ) {
			self::public_page( $msg );
		} elseif ( self::is_vendor( get_current_user_id() ) ) {
			self::home( get_current_user_id() );
		} else {
			echo '<p class="wic-notice">' . esc_html__( 'This page is for authorised vendors.', 'wic-tp' ) . ' <a href="' . esc_url( wic_page_url( 'portal' ) ) . '">' . esc_html__( 'Go to the staff training portal', 'wic-tp' ) . '</a></p>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	private static function public_page( $msg ) {
		?>
		<h2 class="wic-h"><?php echo esc_html( sprintf( __( '%s vendor portal', 'wic-tp' ), wic_setting( 'name' ) ) ); ?></h2>
		<p><?php esc_html_e( 'For authorised WIC stores and retailers: apply to become a vendor, take your annual vendor training and show your certificate.', 'wic-tp' ); ?></p>
		<div class="wic-two wic-section">
			<section class="wic-panel wic-signin">
				<h3 class="wic-h3"><?php esc_html_e( 'Vendor sign in', 'wic-tp' ); ?></h3>
				<?php wp_login_form( array( 'redirect' => wic_page_url( 'vendor' ), 'id_username' => 'wic_v_user', 'id_password' => 'wic_v_pass', 'id_remember' => 'wic_v_rem', 'id_submit' => 'wic_v_submit', 'form_id' => 'wic-vendor-login' ) ); ?>
				<p><a href="<?php echo esc_url( wp_lostpassword_url( wic_page_url( 'vendor' ) ) ); ?>"><?php esc_html_e( 'Lost your password?', 'wic-tp' ); ?></a></p>
			</section>
			<?php if ( 'applied' !== $msg ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form wic-panel" novalidate>
					<h3 class="wic-h3"><?php esc_html_e( 'Apply to become a WIC vendor', 'wic-tp' ); ?></h3>
					<input type="hidden" name="action" value="wic_vendor_apply">
					<?php wp_nonce_field( 'wic_vendor_apply', 'wic_nonce' ); ?>
					<div class="wic-hp" aria-hidden="true"><label>Leave empty <input type="text" name="wic_website" tabindex="-1" autocomplete="off"></label></div>
					<div class="wic-field"><label for="wic-v-store"><?php esc_html_e( 'Store name', 'wic-tp' ); ?></label><input type="text" id="wic-v-store" name="store" required autocomplete="organization"></div>
					<div class="wic-field"><label for="wic-v-num"><?php esc_html_e( 'WIC vendor ID (if you have one)', 'wic-tp' ); ?></label><input type="text" id="wic-v-num" name="number"></div>
					<div class="wic-field"><label for="wic-v-addr"><?php esc_html_e( 'Store address', 'wic-tp' ); ?></label><textarea id="wic-v-addr" name="address" rows="3" required autocomplete="street-address"></textarea></div>
					<div class="wic-field"><label for="wic-v-first"><?php esc_html_e( 'Contact first name', 'wic-tp' ); ?></label><input type="text" id="wic-v-first" name="first_name" required autocomplete="given-name"></div>
					<div class="wic-field"><label for="wic-v-last"><?php esc_html_e( 'Contact last name', 'wic-tp' ); ?></label><input type="text" id="wic-v-last" name="last_name" required autocomplete="family-name"></div>
					<div class="wic-field"><label for="wic-v-phone"><?php esc_html_e( 'Phone', 'wic-tp' ); ?></label><input type="tel" id="wic-v-phone" name="phone" required autocomplete="tel"></div>
					<div class="wic-field"><label for="wic-v-email"><?php esc_html_e( 'Email', 'wic-tp' ); ?></label><input type="email" id="wic-v-email" name="email" required autocomplete="email"></div>
					<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Send application', 'wic-tp' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
		self::helpline();
	}

	private static function home( $uid ) {
		self::reset_user( $uid ); // In case the daily job has not run since New Year.
		$user   = wp_get_current_user();
		$items  = WIC_Records::user_assignments( $uid );
		$certs  = WIC_Certificates::for_user( $uid );
		$res    = get_posts( array( 'post_type' => 'wic_vendor_res', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => array( 'menu_order' => 'ASC', 'title' => 'ASC' ) ) );
		$store  = get_user_meta( $uid, 'wic_vendor_store', true );
		$unread = WIC_Notify::unread_count( $uid );
		?>
		<header class="wic-vendor-hero">
			<div class="wic-vendor-hero__brand">
				<?php if ( class_exists( 'WIC_Design_Fonts' ) ) : ?>
					<?php echo WIC_Design_Fonts::logo_on_dark(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the helper. ?>
				<?php else : ?>
					<span class="wic-brand__name"><?php echo esc_html( wic_setting( 'name' ) ); ?></span>
				<?php endif; ?>
				<a class="wic-vendor-hero__out" href="<?php echo esc_url( wp_logout_url( wic_page_url( 'vendor' ) ) ); ?>"><?php esc_html_e( 'Sign out', 'wic-tp' ); ?></a>
			</div>
			<span class="wic-kicker"><?php esc_html_e( 'Vendor portal', 'wic-tp' ); ?></span>
			<p class="wic-vendor-hero__title"><?php echo esc_html( $store ? $store : $user->display_name ); ?></p>
			<p class="wic-vendor-hero__who"><?php echo esc_html( sprintf( __( 'Signed in as %s', 'wic-tp' ), $user->display_name ) ); ?></p>
		</header>
		<?php if ( $unread ) : ?>
			<section class="wic-section">
				<h3 class="wic-h3"><?php esc_html_e( 'New notices', 'wic-tp' ); ?></h3>
				<ul class="wic-list">
					<?php foreach ( WIC_Notify::recent( $uid, $unread ) as $e ) : ?>
						<li class="is-unread"><span><?php echo esc_html( $e->message ); ?></span><time><?php echo esc_html( wic_format_date( $e->created_at, true ) ); ?></time></li>
					<?php endforeach; ?>
				</ul>
				<?php WIC_Notify::mark_all_read( $uid ); ?>
			</section>
		<?php endif; ?>

		<section class="wic-section">
			<h2 class="wic-h"><?php esc_html_e( 'Your vendor training', 'wic-tp' ); ?></h2>
			<?php if ( ! $items ) : ?>
				<div class="wic-empty"><p><?php esc_html_e( 'No vendor training is assigned yet. It will appear here as soon as the agency publishes it.', 'wic-tp' ); ?></p></div>
			<?php else : ?>
				<div class="wic-grid">
					<?php foreach ( $items as $a ) : ?>
						<?php $label = 'not_started' === $a['status'] ? __( 'Start', 'wic-tp' ) : ( 'complete' === $a['status'] ? __( 'Review', 'wic-tp' ) : __( 'Continue', 'wic-tp' ) ); ?>
						<article class="wic-card" data-status="<?php echo esc_attr( $a['status'] ); ?>">
							<div class="wic-card__top"><h3><?php echo esc_html( $a['title'] ); ?></h3><span class="wic-badge wic-badge--<?php echo esc_attr( $a['status'] ); ?>"><?php echo esc_html( wic_status_label( $a['status'] ) ); ?></span></div>
							<p class="wic-meta">
								<?php if ( self::is_calendar_year( $a['course_id'] ) ) : ?><?php esc_html_e( 'Required every calendar year', 'wic-tp' ); ?><?php endif; ?>
								<?php if ( $a['due_at'] && 'complete' !== $a['status'] ) : ?> · <?php echo esc_html( sprintf( __( 'Due %s', 'wic-tp' ), wic_format_date( $a['due_at'] ) ) ); ?><?php endif; ?>
							</p>
							<div class="wic-card__actions">
								<a class="wic-btn wic-btn--primary" href="<?php echo esc_url( wic_page_url( 'learn', array( 'course' => $a['course_id'] ) ) ); ?>"><?php echo esc_html( $label ); ?><span class="screen-reader-text"> <?php echo esc_html( $a['title'] ); ?></span></a>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>

		<section class="wic-section">
			<h3 class="wic-h3"><?php esc_html_e( 'Your certificates', 'wic-tp' ); ?></h3>
			<?php if ( ! $certs ) : ?>
				<div class="wic-empty"><p><?php esc_html_e( 'Your certificate appears here when you complete your training.', 'wic-tp' ); ?></p></div>
			<?php else : ?>
				<p class="wic-help"><?php esc_html_e( 'An inspector can check a certificate by scanning its QR code, or by entering the verification code on the public check page.', 'wic-tp' ); ?></p>
				<div class="wic-table-wrap"><table class="wic-table">
					<thead><tr><th scope="col"><?php esc_html_e( 'Training', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Completed', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Valid until', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Verification code', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'wic-tp' ); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'wic-tp' ); ?></span></th></tr></thead>
					<tbody>
					<?php foreach ( $certs as $c ) : ?>
						<tr>
							<td><?php echo esc_html( $c->course_title ); ?></td>
							<td><?php echo esc_html( wic_format_date( $c->issued_at ) ); ?></td>
							<td><?php echo esc_html( $c->expires_at ? wic_format_date( $c->expires_at ) : '—' ); ?></td>
							<td><code><?php echo esc_html( $c->verify_code ); ?></code></td>
							<td><?php echo esc_html( ucfirst( WIC_Certificates::state( $c ) ) ); ?></td>
							<td><a class="wic-btn wic-btn--small" href="<?php echo esc_url( WIC_Certificates::url( $c ) ); ?>"><?php esc_html_e( 'View / print', 'wic-tp' ); ?></a>
								<a class="wic-btn wic-btn--small" href="<?php echo esc_url( WIC_Certificates::verify_url( $c ) ); ?>"><?php esc_html_e( 'Check page', 'wic-tp' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			<?php endif; ?>
		</section>

		<section class="wic-section">
			<h3 class="wic-h3"><?php esc_html_e( 'Vendor guides and forms', 'wic-tp' ); ?></h3>
			<?php if ( ! $res ) : ?>
				<div class="wic-empty"><p><?php esc_html_e( 'No vendor resources have been published yet.', 'wic-tp' ); ?></p></div>
			<?php else : ?>
				<?php foreach ( $res as $r ) : ?>
					<details class="wic-panel" style="margin-bottom:0.5rem">
						<summary><strong><?php echo esc_html( $r->post_title ); ?></strong></summary>
						<div><?php echo wp_kses_post( wpautop( $r->post_content ) ); ?></div>
						<?php $url = get_post_meta( $r->ID, '_wic_file_url', true ); ?>
						<?php if ( $url ) : ?><p><a class="wic-btn wic-btn--small" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open the file', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( $r->post_title ); ?></span></a></p><?php endif; ?>
					</details>
				<?php endforeach; ?>
			<?php endif; ?>
		</section>

		<section class="wic-panel wic-section">
			<h3 class="wic-h3"><?php esc_html_e( 'Your store', 'wic-tp' ); ?></h3>
			<dl class="wic-dl">
				<dt><?php esc_html_e( 'Store', 'wic-tp' ); ?></dt><dd><?php echo esc_html( $store ? $store : '—' ); ?></dd>
				<dt><?php esc_html_e( 'Vendor ID', 'wic-tp' ); ?></dt><dd><?php echo esc_html( get_user_meta( $uid, 'wic_vendor_number', true ) ? get_user_meta( $uid, 'wic_vendor_number', true ) : '—' ); ?></dd>
				<dt><?php esc_html_e( 'Address', 'wic-tp' ); ?></dt><dd><?php echo nl2br( esc_html( get_user_meta( $uid, 'wic_vendor_address', true ) ) ); ?></dd>
				<dt><?php esc_html_e( 'Contact', 'wic-tp' ); ?></dt><dd><?php echo esc_html( $user->display_name . ' · ' . $user->user_email ); ?></dd>
			</dl>
			<p><a class="wic-btn wic-btn--small" href="<?php echo esc_url( wp_lostpassword_url( wic_page_url( 'vendor' ) ) ); ?>"><?php esc_html_e( 'Send me a password link', 'wic-tp' ); ?></a></p>
		</section>
		<?php
		do_action( 'wic_account_sections', $uid );
		self::helpline();
	}

	public static function handle_apply() {
		$back = function ( $msg ) {
			wp_safe_redirect( add_query_arg( 'wic_msg', $msg, wic_page_url( 'vendor' ) ) );
			exit;
		};
		if ( ! isset( $_POST['wic_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wic_nonce'] ), 'wic_vendor_apply' ) ) {
			$back( 'invalid' );
		}
		if ( ! empty( $_POST['wic_website'] ) ) {
			$back( 'applied' );
		}
		$f = array();
		foreach ( array( 'store', 'number', 'phone', 'first_name', 'last_name' ) as $k ) {
			$f[ $k ] = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
		}
		$f['address'] = isset( $_POST['address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['address'] ) ) : '';
		$email        = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! $f['store'] || ! $f['address'] || ! $f['phone'] || ! $f['first_name'] || ! $f['last_name'] || ! is_email( $email ) ) {
			$back( 'invalid' );
		}
		$existing = get_user_by( 'email', $email );
		if ( $existing ) {
			$s = wic_user_status( $existing->ID );
			$back( 'pending' === $s ? 'pending' : ( 'rejected' === $s ? 'rejected' : ( 'deactivated' === $s ? 'closed' : 'active' ) ) );
		}
		$id = WIC_People::create_user( $email, $f['first_name'], $f['last_name'], 'wic_vendor' );
		if ( ! $id ) {
			$back( 'invalid' );
		}
		update_user_meta( $id, 'wic_status', 'pending' );
		delete_user_meta( $id, 'wic_approved_at' );
		update_user_meta( $id, 'wic_group', 'vendor' );
		update_user_meta( $id, 'wic_reports_to', 0 );
		update_user_meta( $id, 'wic_vendor_store', $f['store'] );
		update_user_meta( $id, 'wic_vendor_number', $f['number'] );
		update_user_meta( $id, 'wic_vendor_address', $f['address'] );
		update_user_meta( $id, 'wic_vendor_phone', $f['phone'] );
		wic_audit( 'vendor_apply', 'user', $id, array( 'store' => $f['store'] ) );
		foreach ( get_users( array( 'role' => 'wic_admin', 'fields' => 'ID' ) ) as $admin ) {
			WIC_Notify::event( (int) $admin, 'vendor_application', $id, sprintf( __( '%1$s (%2$s) has applied to become a WIC vendor.', 'wic-tp' ), $f['store'], $email ), true );
		}
		$back( 'applied' );
	}

	/* ------------------------------------------------------------------ */
	/* Agency view: applications and vendors (#130)                      */
	/* ------------------------------------------------------------------ */

	public static function views( $views ) {
		$views['vendors'] = array(
			'label'    => __( 'Vendors', 'wic-tp' ),
			'group'    => 'agency',
			'cap'      => 'wic_manage_people',
			'callback' => array( __CLASS__, 'view' ),
			'order'    => 60,
			'badge'    => function () {
				return count( self::pending() );
			},
		);
		return $views;
	}

	public static function pending() {
		return get_users( array( 'role' => 'wic_vendor', 'meta_key' => 'wic_status', 'meta_value' => 'pending', 'orderby' => 'registered', 'order' => 'ASC' ) );
	}

	public static function view( $uid ) {
		$pending = self::pending();
		$active  = get_users( array( 'role' => 'wic_vendor', 'meta_key' => 'wic_status', 'meta_value' => 'active', 'orderby' => 'display_name' ) );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Vendors', 'wic-tp' ); ?></h2>
		<p class="wic-help"><?php esc_html_e( 'Authorised stores and retailers. Vendors have no supervisor; the agency approves them here.', 'wic-tp' ); ?>
			<a href="<?php echo esc_url( wic_page_url( 'vendor' ) ); ?>"><?php esc_html_e( 'Open the vendor portal', 'wic-tp' ); ?></a> ·
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=wic_vendor_res' ) ); ?>"><?php esc_html_e( 'Manage vendor resources', 'wic-tp' ); ?></a></p>

		<section class="wic-section">
			<h3 class="wic-h3"><?php esc_html_e( 'Applications waiting', 'wic-tp' ); ?></h3>
			<?php if ( ! $pending ) : ?>
				<div class="wic-empty wic-empty--good"><p><?php esc_html_e( 'No vendor applications are waiting.', 'wic-tp' ); ?></p></div>
			<?php else : ?>
				<div class="wic-table-wrap"><table class="wic-table">
					<thead><tr><th scope="col"><?php esc_html_e( 'Store', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Vendor ID', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Address', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Contact', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Waiting', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Decision', 'wic-tp' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $pending as $p ) : ?>
						<?php $days = (int) floor( ( time() - strtotime( $p->user_registered . ' UTC' ) ) / DAY_IN_SECONDS ); ?>
						<tr>
							<td><?php echo esc_html( get_user_meta( $p->ID, 'wic_vendor_store', true ) ); ?></td>
							<td><?php echo esc_html( get_user_meta( $p->ID, 'wic_vendor_number', true ) ); ?></td>
							<td><?php echo nl2br( esc_html( get_user_meta( $p->ID, 'wic_vendor_address', true ) ) ); ?></td>
							<td><?php echo esc_html( $p->display_name ); ?><br><?php echo esc_html( $p->user_email ); ?><br><?php echo esc_html( get_user_meta( $p->ID, 'wic_vendor_phone', true ) ); ?></td>
							<td><?php echo esc_html( sprintf( _n( '%d day', '%d days', $days, 'wic-tp' ), $days ) ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-inline-form">
									<input type="hidden" name="action" value="wic_vendor_decide">
									<input type="hidden" name="user" value="<?php echo (int) $p->ID; ?>">
									<?php wp_nonce_field( 'wic_vendor_decide_' . $p->ID ); ?>
									<button type="submit" name="decision" value="approve" class="wic-btn wic-btn--small wic-btn--primary"><?php esc_html_e( 'Approve', 'wic-tp' ); ?></button>
									<button type="submit" name="decision" value="reject" class="wic-btn wic-btn--small" data-wic-confirm="<?php esc_attr_e( 'Decline this application?', 'wic-tp' ); ?>"><?php esc_html_e( 'Decline', 'wic-tp' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			<?php endif; ?>
		</section>

		<section class="wic-section">
			<h3 class="wic-h3"><?php esc_html_e( 'Approved vendors', 'wic-tp' ); ?></h3>
			<?php if ( ! $active ) : ?>
				<div class="wic-empty"><p><?php esc_html_e( 'No approved vendors yet.', 'wic-tp' ); ?></p></div>
			<?php else : ?>
				<div class="wic-table-wrap"><table class="wic-table" data-wic-sortable>
					<thead><tr><th scope="col"><?php esc_html_e( 'Store', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Vendor ID', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Contact', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Training this year', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Certificates', 'wic-tp' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $active as $v ) : ?>
						<?php
						$items = WIC_Records::user_assignments( $v->ID );
						$done  = count( array_filter( $items, function ( $a ) { return 'complete' === $a['status']; } ) );
						$certs = WIC_Certificates::for_user( $v->ID );
						?>
						<tr>
							<td><?php echo esc_html( get_user_meta( $v->ID, 'wic_vendor_store', true ) ); ?></td>
							<td><?php echo esc_html( get_user_meta( $v->ID, 'wic_vendor_number', true ) ); ?></td>
							<td><?php echo esc_html( $v->display_name . ' · ' . $v->user_email ); ?></td>
							<td data-sort="<?php echo (int) $done; ?>"><?php echo (int) $done; ?> / <?php echo count( $items ); ?> <?php echo $items && $done === count( $items ) ? '<span class="wic-badge wic-badge--complete">' . esc_html__( 'Up to date', 'wic-tp' ) . '</span>' : ''; ?></td>
							<td>
								<?php foreach ( array_slice( $certs, 0, 3 ) as $c ) : ?>
									<a href="<?php echo esc_url( WIC_Certificates::url( $c ) ); ?>"><code><?php echo esc_html( $c->cert_number ); ?></code></a>
								<?php endforeach; ?>
								<?php echo $certs ? '' : '—'; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			<?php endif; ?>
		</section>
		<?php
	}

	public static function handle_decide() {
		$id = isset( $_POST['user'] ) ? absint( $_POST['user'] ) : 0;
		check_admin_referer( 'wic_vendor_decide_' . $id );
		if ( ! current_user_can( 'wic_manage_people' ) || ! self::is_vendor( $id ) || 'pending' !== wic_user_status( $id ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
		}
		if ( isset( $_POST['decision'] ) && 'approve' === $_POST['decision'] ) {
			update_user_meta( $id, 'wic_status', 'active' );
			update_user_meta( $id, 'wic_approved_at', wic_now() );
			wic_audit( 'vendor_approve', 'user', $id );
			self::assign_vendor_training( $id );
			WIC_Registration::send_set_password( $id );
			WIC_Notify::event( $id, 'welcome', 0, __( 'Welcome. Your vendor training is listed on the vendor portal.', 'wic-tp' ), false );
			WIC_Portal::back( 'vendors', 'vendor_approved' );
		}
		update_user_meta( $id, 'wic_status', 'rejected' );
		wic_audit( 'vendor_reject', 'user', $id );
		WIC_Portal::back( 'vendors', 'vendor_rejected' );
	}

	/** One sample resource so the vendor home is not empty. It makes no claims. */
	public static function seed() {
		if ( get_option( 'wic_vendor_seeded' ) ) {
			return;
		}
		update_option( 'wic_vendor_seeded', 1 );
		self::register_types();
		wp_insert_post(
			array(
				'post_type'    => 'wic_vendor_res',
				'post_status'  => 'publish',
				'post_title'   => 'How the vendor portal works (Sample)',
				'post_content' => 'This is a sample resource. Replace it with your agency\'s own vendor guide, application forms and policies under WIC Platform → Vendor resources.',
			)
		);
	}
}

add_action( 'wic_init', array( 'WIC_Vendor', 'init' ) );
add_action( 'wic_install', array( 'WIC_Vendor', 'seed' ) );
