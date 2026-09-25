<?php
/**
 * Two-factor sign-in and lockouts (#13).
 *
 * TOTP (RFC 6238: HMAC-SHA1, 6 digits, 30-second steps, one step either side) in plain PHP,
 * so it works with any authenticator app and needs no extension. The password step stays
 * WordPress's own; the code is a second step on the portal page before the session cookie is set.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_agency_defaults',
	function ( $d ) {
		$d['require_2fa_staff'] = 0;
		$d['lockout_attempts']  = 5;
		$d['lockout_minutes']   = 15;
		return $d;
	}
);

add_filter(
	'wic_agency_fields',
	function ( $f ) {
		$f['require_2fa_staff'] = array( __( 'Require two-factor sign-in for supervisors and administrators', 'wic-tp' ), 'checkbox', __( 'They are asked to set it up at their next sign-in and cannot use the portal until they do.', 'wic-tp' ) );
		$f['lockout_attempts']  = array( __( 'Lock an account after this many failed sign-ins', 'wic-tp' ), 'number' );
		$f['lockout_minutes']   = array( __( 'Lockout length (minutes)', 'wic-tp' ), 'number' );
		return $f;
	}
);

class WIC_Security {

	const STEP = 30;

	public static function init() {
		add_filter( 'authenticate', array( __CLASS__, 'enforce_lockout' ), 99, 2 );
		add_action( 'wp_login_failed', array( __CLASS__, 'login_failed' ), 10, 2 );
		add_action( 'wp_login', array( __CLASS__, 'after_password' ), 5, 2 );
		add_filter( 'wic_portal_signin', array( __CLASS__, 'signin_step' ), 5 );
		add_action( 'admin_post_nopriv_wic_2fa_verify', array( __CLASS__, 'handle_verify' ) );
		add_action( 'admin_post_wic_2fa_verify', array( __CLASS__, 'handle_verify' ) );
		add_action( 'admin_post_wic_2fa_enable', array( __CLASS__, 'handle_enable' ) );
		add_action( 'admin_post_wic_2fa_disable', array( __CLASS__, 'handle_disable' ) );
		add_action( 'admin_post_wic_2fa_codes', array( __CLASS__, 'handle_codes' ) );
		add_action( 'wic_account_sections', array( __CLASS__, 'account_section' ) );
		add_filter( 'wic_flash_messages', array( __CLASS__, 'messages' ) );
		add_action( 'template_redirect', array( __CLASS__, 'require_setup' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'require_setup_admin' ) );
	}

	public static function messages( $m ) {
		$m['2fa_on']           = __( 'Two-factor sign-in is on. Keep your recovery codes somewhere safe.', 'wic-tp' );
		$m['2fa_off']          = __( 'Two-factor sign-in is off.', 'wic-tp' );
		$m['2fa_codes']        = __( 'New recovery codes created. The old ones no longer work.', 'wic-tp' );
		$m['err_2fa_code']     = __( 'That code did not match. Check the time on your phone and try the newest code.', 'wic-tp' );
		$m['err_2fa_required'] = __( 'Your agency requires two-factor sign-in for your role. Please set it up below to continue.', 'wic-tp' );
		return $m;
	}

	/* ------------------------------------------------------------------ */
	/* TOTP                                                               */
	/* ------------------------------------------------------------------ */

	const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	public static function base32_encode( $bin ) {
		$bits = '';
		foreach ( str_split( $bin ) as $ch ) {
			$bits .= str_pad( decbin( ord( $ch ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= self::B32[ bindec( str_pad( $chunk, 5, '0', STR_PAD_RIGHT ) ) ];
		}
		return $out;
	}

	public static function base32_decode( $b32 ) {
		$b32  = strtoupper( preg_replace( '/[^A-Za-z2-7]/', '', (string) $b32 ) );
		$bits = '';
		foreach ( str_split( $b32 ) as $ch ) {
			$bits .= str_pad( decbin( strpos( self::B32, $ch ) ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 8 ) as $byte ) {
			if ( 8 === strlen( $byte ) ) {
				$out .= chr( bindec( $byte ) );
			}
		}
		return $out;
	}

	public static function new_secret() {
		return self::base32_encode( random_bytes( 20 ) );
	}

	public static function code_at( $secret, $counter ) {
		$key  = self::base32_decode( $secret );
		$msg  = pack( 'N', 0 ) . pack( 'N', $counter );
		$hash = hash_hmac( 'sha1', $msg, $key, true );
		$off  = ord( $hash[19] ) & 0x0f;
		$num  = ( ( ord( $hash[ $off ] ) & 0x7f ) << 24 )
			| ( ( ord( $hash[ $off + 1 ] ) & 0xff ) << 16 )
			| ( ( ord( $hash[ $off + 2 ] ) & 0xff ) << 8 )
			| ( ord( $hash[ $off + 3 ] ) & 0xff );
		return str_pad( (string) ( $num % 1000000 ), 6, '0', STR_PAD_LEFT );
	}

	/** Returns the matching time step, or false. One step either side allows for clock drift. */
	public static function match( $secret, $code ) {
		$code = preg_replace( '/\D/', '', (string) $code );
		if ( 6 !== strlen( $code ) ) {
			return false;
		}
		$now = (int) floor( time() / self::STEP );
		for ( $i = -1; $i <= 1; $i++ ) {
			if ( hash_equals( self::code_at( $secret, $now + $i ), $code ) ) {
				return $now + $i;
			}
		}
		return false;
	}

	/** Check a code for an enrolled user; a code is never accepted twice. */
	public static function verify_user_code( $user_id, $code ) {
		$secret = (string) get_user_meta( $user_id, 'wic_2fa_secret', true );
		if ( ! $secret ) {
			return false;
		}
		$step = self::match( $secret, $code );
		if ( false === $step || $step <= (int) get_user_meta( $user_id, 'wic_2fa_last', true ) ) {
			return false;
		}
		update_user_meta( $user_id, 'wic_2fa_last', $step );
		return true;
	}

	public static function use_recovery_code( $user_id, $code ) {
		$code  = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ) );
		$codes = (array) get_user_meta( $user_id, 'wic_2fa_recovery', true );
		foreach ( $codes as $i => $hash ) {
			if ( $hash && wp_check_password( $code, $hash ) ) {
				unset( $codes[ $i ] );
				update_user_meta( $user_id, 'wic_2fa_recovery', array_values( $codes ) );
				wic_audit( '2fa_recovery_used', 'user', $user_id, array( 'left' => count( $codes ) ) );
				return true;
			}
		}
		return false;
	}

	/** Ten fresh recovery codes; only hashes are kept. Returns the plain codes to show once. */
	public static function new_recovery_codes( $user_id ) {
		$plain  = array();
		$hashes = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$c        = wic_random_code( 8 );
			$plain[]  = substr( $c, 0, 4 ) . '-' . substr( $c, 4 );
			$hashes[] = wp_hash_password( $c );
		}
		update_user_meta( $user_id, 'wic_2fa_recovery', $hashes );
		return $plain;
	}

	public static function enabled( $user_id ) {
		return '' !== (string) get_user_meta( $user_id, 'wic_2fa_secret', true );
	}

	public static function required_for( $user_id ) {
		if ( ! (int) wic_setting( 'require_2fa_staff' ) ) {
			return false;
		}
		return user_can( $user_id, 'wic_view_team' ) || user_can( $user_id, 'wic_view_all' ) || user_can( $user_id, 'wic_manage_people' );
	}

	/* ------------------------------------------------------------------ */
	/* Lockouts                                                           */
	/* ------------------------------------------------------------------ */

	private static function login_key( $username ) {
		$username = trim( (string) $username );
		if ( is_email( $username ) ) {
			$u = get_user_by( 'email', $username );
			if ( $u ) {
				$username = $u->user_login;
			}
		}
		return md5( strtolower( $username ) );
	}

	public static function is_locked( $username ) {
		return (bool) get_transient( 'wic_lock_' . self::login_key( $username ) );
	}

	/** Runs after the password check, so a correct password cannot open a locked account. */
	public static function enforce_lockout( $user, $username ) {
		if ( $username && self::is_locked( $username ) ) {
			return new WP_Error( 'wic_locked', sprintf( __( 'Too many failed sign-in attempts. This account is locked for %d minutes. Try again later or use "Lost your password?".', 'wic-tp' ), max( 1, (int) wic_setting( 'lockout_minutes' ) ) ) );
		}
		return $user;
	}

	public static function login_failed( $username, $error = null ) {
		if ( $error instanceof WP_Error && 'wic_locked' === $error->get_error_code() ) {
			return;
		}
		self::count_failure( $username );
	}

	public static function count_failure( $username ) {
		$key      = self::login_key( $username );
		$minutes  = max( 1, (int) wic_setting( 'lockout_minutes' ) );
		$attempts = max( 1, (int) wic_setting( 'lockout_attempts' ) );
		$n        = (int) get_transient( 'wic_fail_' . $key ) + 1;
		if ( $n >= $attempts ) {
			delete_transient( 'wic_fail_' . $key );
			set_transient( 'wic_lock_' . $key, 1, $minutes * MINUTE_IN_SECONDS );
			$u = is_email( $username ) ? get_user_by( 'email', $username ) : get_user_by( 'login', $username );
			wic_audit( 'login_lockout', 'user', $u ? $u->ID : 0, array( 'login' => sanitize_user( (string) $username ), 'minutes' => $minutes ) );
			if ( $u ) {
				WIC_Notify::event( $u->ID, 'login_lockout', 0, sprintf( __( 'Your account was locked for %d minutes after repeated failed sign-ins. If this was not you, tell your administrator.', 'wic-tp' ), $minutes ), true );
			}
			return;
		}
		set_transient( 'wic_fail_' . $key, $n, $minutes * MINUTE_IN_SECONDS );
	}

	private static function clear_failures( $user ) {
		delete_transient( 'wic_fail_' . self::login_key( $user->user_login ) );
	}

	/* ------------------------------------------------------------------ */
	/* The second step                                                    */
	/* ------------------------------------------------------------------ */

	/** Password accepted: for enrolled people, drop the new session and ask for a code. */
	public static function after_password( $login, $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return;
		}
		if ( ! self::enabled( $user->ID ) || ! wic_page_id( 'portal' ) ) {
			self::clear_failures( $user );
			return;
		}
		wp_clear_auth_cookie();
		wp_set_current_user( 0 );
		$token = wp_generate_password( 32, false );
		set_transient(
			'wic_2fa_tok_' . $token,
			array(
				'uid'      => (int) $user->ID,
				'remember' => ! empty( $_POST['rememberme'] ),
				'redirect' => isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '',
				'tries'    => 0,
			),
			10 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( add_query_arg( 'wic_2fa', $token, wic_page_url( 'portal' ) ) );
		exit;
	}

	public static function signin_step( $html ) {
		$token = isset( $_GET['wic_2fa'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $_GET['wic_2fa'] ) ) : '';
		if ( ! $token ) {
			return $html;
		}
		$data = get_transient( 'wic_2fa_tok_' . $token );
		ob_start();
		?>
		<div class="wic-portal wic-signin">
			<h2><?php esc_html_e( 'Enter your sign-in code', 'wic-tp' ); ?></h2>
			<?php if ( ! $data ) : ?>
				<div class="wic-notice wic-notice--err" role="alert"><?php esc_html_e( 'This sign-in has expired. Please sign in again.', 'wic-tp' ); ?></div>
				<p><a href="<?php echo esc_url( wic_page_url( 'portal' ) ); ?>"><?php esc_html_e( 'Back to sign in', 'wic-tp' ); ?></a></p>
			<?php else : ?>
				<?php if ( ! empty( $_GET['wic_2fa_err'] ) ) : ?>
					<div class="wic-notice wic-notice--err" role="alert"><?php esc_html_e( 'That code did not match. Try the newest code from your app, or a recovery code.', 'wic-tp' ); ?></div>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form">
					<input type="hidden" name="action" value="wic_2fa_verify">
					<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
					<div class="wic-field">
						<label for="wic-2fa-code"><?php esc_html_e( 'Six-digit code from your authenticator app', 'wic-tp' ); ?></label>
						<input type="text" id="wic-2fa-code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus>
						<p class="wic-help"><?php esc_html_e( 'Lost your phone? Enter one of your recovery codes instead.', 'wic-tp' ); ?></p>
					</div>
					<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Sign in', 'wic-tp' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function handle_verify() {
		$token = isset( $_POST['token'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $_POST['token'] ) ) : '';
		$data  = $token ? get_transient( 'wic_2fa_tok_' . $token ) : false;
		if ( ! $data ) {
			wp_safe_redirect( add_query_arg( 'wic_2fa', 'expired', wic_page_url( 'portal' ) ) );
			exit;
		}
		$uid  = (int) $data['uid'];
		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$user = get_userdata( $uid );
		$ok   = $user && ( self::verify_user_code( $uid, $code ) || self::use_recovery_code( $uid, $code ) );
		if ( ! $ok ) {
			++$data['tries'];
			if ( $user ) {
				self::count_failure( $user->user_login );
			}
			if ( $data['tries'] >= 5 || ( $user && self::is_locked( $user->user_login ) ) ) {
				delete_transient( 'wic_2fa_tok_' . $token );
				wic_audit( '2fa_failed', 'user', $uid );
			} else {
				set_transient( 'wic_2fa_tok_' . $token, $data, 10 * MINUTE_IN_SECONDS );
			}
			wp_safe_redirect( add_query_arg( array( 'wic_2fa' => $token, 'wic_2fa_err' => 1 ), wic_page_url( 'portal' ) ) );
			exit;
		}
		delete_transient( 'wic_2fa_tok_' . $token );
		if ( 'active' !== wic_user_status( $uid ) ) {
			wp_safe_redirect( wic_page_url( 'portal' ) );
			exit;
		}
		self::clear_failures( $user );
		wp_set_auth_cookie( $uid, ! empty( $data['remember'] ) );
		wp_set_current_user( $uid );
		wic_audit( '2fa_signin', 'user', $uid );
		$to = $data['redirect'] ? $data['redirect'] : wic_page_url( 'portal' );
		wp_safe_redirect( apply_filters( 'login_redirect', $to, $to, $user ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Setup in "My account"                                              */
	/* ------------------------------------------------------------------ */

	private static function otpauth_uri( $user, $secret ) {
		$issuer = wic_setting( 'name' );
		$label  = rawurlencode( $issuer . ':' . $user->user_login );
		return 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode( $issuer ) . '&digits=6&period=30';
	}

	public static function account_section( $uid ) {
		$user    = get_userdata( $uid );
		$enabled = self::enabled( $uid );
		$codes   = get_transient( 'wic_2fa_show_' . $uid );
		if ( $codes ) {
			delete_transient( 'wic_2fa_show_' . $uid );
		}
		?>
		<section class="wic-panel wic-section" id="wic-2fa">
			<h3 class="wic-h3"><?php esc_html_e( 'Two-factor sign-in', 'wic-tp' ); ?></h3>
			<?php if ( $codes ) : ?>
				<div class="wic-notice wic-notice--warn" role="status">
					<p><strong><?php esc_html_e( 'Your recovery codes. Each works once. They are shown only now — print or save them.', 'wic-tp' ); ?></strong></p>
					<ul class="wic-codes"><?php foreach ( $codes as $c ) : ?><li><code><?php echo esc_html( $c ); ?></code></li><?php endforeach; ?></ul>
				</div>
			<?php endif; ?>

			<?php if ( $enabled ) : ?>
				<?php $left = count( array_filter( (array) get_user_meta( $uid, 'wic_2fa_recovery', true ) ) ); ?>
				<p><span class="wic-badge wic-badge--complete"><?php esc_html_e( 'On', 'wic-tp' ); ?></span>
					<?php echo esc_html( sprintf( _n( 'You have %d recovery code left.', 'You have %d recovery codes left.', $left, 'wic-tp' ), $left ) ); ?></p>
				<div class="wic-two">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form">
						<input type="hidden" name="action" value="wic_2fa_codes">
						<?php wp_nonce_field( 'wic_2fa_codes' ); ?>
						<div class="wic-field"><label for="wic-2fa-rc"><?php esc_html_e( 'Current code, to make new recovery codes', 'wic-tp' ); ?></label><input type="text" id="wic-2fa-rc" name="code" inputmode="numeric" autocomplete="one-time-code" required></div>
						<button type="submit" class="wic-btn"><?php esc_html_e( 'New recovery codes', 'wic-tp' ); ?></button>
					</form>
					<?php if ( ! self::required_for( $uid ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form">
							<input type="hidden" name="action" value="wic_2fa_disable">
							<?php wp_nonce_field( 'wic_2fa_disable' ); ?>
							<div class="wic-field"><label for="wic-2fa-dc"><?php esc_html_e( 'Current code, to turn it off', 'wic-tp' ); ?></label><input type="text" id="wic-2fa-dc" name="code" inputmode="numeric" autocomplete="one-time-code" required></div>
							<button type="submit" class="wic-btn" data-wic-confirm="<?php esc_attr_e( 'Turn off two-factor sign-in?', 'wic-tp' ); ?>"><?php esc_html_e( 'Turn off', 'wic-tp' ); ?></button>
						</form>
					<?php else : ?>
						<p class="wic-help"><?php esc_html_e( 'Your agency requires two-factor sign-in for your role, so it cannot be turned off.', 'wic-tp' ); ?></p>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<?php
				$secret = (string) get_user_meta( $uid, 'wic_2fa_pending', true );
				if ( ! $secret ) {
					$secret = self::new_secret();
					update_user_meta( $uid, 'wic_2fa_pending', $secret );
				}
				$uri = self::otpauth_uri( $user, $secret );
				?>
				<p><span class="wic-badge wic-badge--not_started"><?php esc_html_e( 'Off', 'wic-tp' ); ?></span>
					<?php esc_html_e( 'Add a second step to signing in: a six-digit code from an authenticator app on your phone.', 'wic-tp' ); ?></p>
				<ol>
					<li><?php esc_html_e( 'Open an authenticator app and scan this code.', 'wic-tp' ); ?>
						<div id="wic-2fa-qr" class="wic-qr" role="img" aria-label="<?php esc_attr_e( 'QR code for your authenticator app. Use the setup key below if you cannot scan it.', 'wic-tp' ); ?>"></div>
					</li>
					<li><?php esc_html_e( 'Or type this setup key into the app:', 'wic-tp' ); ?> <code class="wic-key"><?php echo esc_html( trim( chunk_split( $secret, 4, ' ' ) ) ); ?></code></li>
					<li><?php esc_html_e( 'Enter the code the app shows.', 'wic-tp' ); ?></li>
				</ol>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form">
					<input type="hidden" name="action" value="wic_2fa_enable">
					<?php wp_nonce_field( 'wic_2fa_enable' ); ?>
					<div class="wic-field"><label for="wic-2fa-ec"><?php esc_html_e( 'Code from the app', 'wic-tp' ); ?></label><input type="text" id="wic-2fa-ec" name="code" inputmode="numeric" autocomplete="one-time-code" required></div>
					<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Turn on two-factor sign-in', 'wic-tp' ); ?></button>
				</form>
				<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
				<script>
				(function () {
					var el = document.getElementById('wic-2fa-qr');
					if (el && window.QRCode) {
						new QRCode(el, { text: <?php echo wp_json_encode( $uri ); ?>, width: 168, height: 168, correctLevel: QRCode.CorrectLevel.M });
					}
				})();
				</script>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function account_back( $msg ) {
		wp_safe_redirect( wic_portal_url( 'account', array( 'wic_msg' => $msg ) ) . '#wic-2fa' );
		exit;
	}

	public static function handle_enable() {
		check_admin_referer( 'wic_2fa_enable' );
		$uid    = get_current_user_id();
		$secret = (string) get_user_meta( $uid, 'wic_2fa_pending', true );
		$step   = $uid && $secret ? self::match( $secret, isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '' ) : false;
		if ( false === $step ) {
			self::account_back( 'err_2fa_code' );
		}
		update_user_meta( $uid, 'wic_2fa_secret', $secret );
		update_user_meta( $uid, 'wic_2fa_last', $step );
		delete_user_meta( $uid, 'wic_2fa_pending' );
		set_transient( 'wic_2fa_show_' . $uid, self::new_recovery_codes( $uid ), 10 * MINUTE_IN_SECONDS );
		wic_audit( '2fa_enabled', 'user', $uid );
		self::account_back( '2fa_on' );
	}

	public static function handle_disable() {
		check_admin_referer( 'wic_2fa_disable' );
		$uid = get_current_user_id();
		if ( self::required_for( $uid ) || ! self::verify_user_code( $uid, isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '' ) ) {
			self::account_back( 'err_2fa_code' );
		}
		delete_user_meta( $uid, 'wic_2fa_secret' );
		delete_user_meta( $uid, 'wic_2fa_recovery' );
		delete_user_meta( $uid, 'wic_2fa_last' );
		wic_audit( '2fa_disabled', 'user', $uid );
		self::account_back( '2fa_off' );
	}

	public static function handle_codes() {
		check_admin_referer( 'wic_2fa_codes' );
		$uid = get_current_user_id();
		if ( ! self::verify_user_code( $uid, isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '' ) ) {
			self::account_back( 'err_2fa_code' );
		}
		set_transient( 'wic_2fa_show_' . $uid, self::new_recovery_codes( $uid ), 10 * MINUTE_IN_SECONDS );
		wic_audit( '2fa_codes_regenerated', 'user', $uid );
		self::account_back( '2fa_codes' );
	}

	/* ------------------------------------------------------------------ */
	/* Required setup                                                     */
	/* ------------------------------------------------------------------ */

	private static function must_enrol() {
		$uid = get_current_user_id();
		return $uid && wic_page_id( 'portal' ) && self::required_for( $uid ) && ! self::enabled( $uid );
	}

	public static function require_setup() {
		if ( ! self::must_enrol() ) {
			return;
		}
		$on_account = is_page( wic_page_id( 'portal' ) ) && isset( $_GET['view'] ) && 'account' === $_GET['view'];
		if ( ! $on_account ) {
			wp_safe_redirect( wic_portal_url( 'account', array( 'wic_msg' => 'err_2fa_required' ) ) . '#wic-2fa' );
			exit;
		}
	}

	public static function require_setup_admin() {
		if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ! self::must_enrol() ) {
			return;
		}
		global $pagenow;
		if ( 'admin-post.php' === $pagenow ) {
			return;
		}
		wp_safe_redirect( wic_portal_url( 'account', array( 'wic_msg' => 'err_2fa_required' ) ) . '#wic-2fa' );
		exit;
	}
}

add_action( 'wic_init', array( 'WIC_Security', 'init' ) );
