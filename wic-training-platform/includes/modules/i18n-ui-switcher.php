<?php
/**
 * The portal in other languages (#54).
 *
 * Interface strings are translated through the usual text domain, with a WordPress 6.5+
 * PHP translation file per language in languages/. People choose a language in the portal
 * header (stored as their user locale); visitors on the sign-in, registration and public
 * pages choose with a link that sets a cookie.
 *
 * The Spanish interface translation has not been reviewed by a fluent speaker yet.
 * It must be reviewed before release.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_agency_defaults',
	function ( $d ) {
		$d['lang_switcher'] = 1;
		return $d;
	}
);

add_filter(
	'wic_agency_fields',
	function ( $f ) {
		$f['lang_switcher'] = array( __( 'Offer the portal in Spanish', 'wic-tp' ), 'checkbox', __( 'Adds a language choice to the portal header, sign-in and registration pages. The Spanish interface translation has not yet been reviewed by a fluent speaker — have it reviewed before release. Course content is translated separately, per slide.', 'wic-tp' ) );
		return $f;
	}
);

class WIC_I18n_UI {

	const COOKIE = 'wic_lang';

	/** Languages with an interface translation. Locale => name in that language. */
	public static function languages() {
		return apply_filters(
			'wic_ui_languages',
			array(
				'en_US' => 'English',
				'es_ES' => 'Español',
			)
		);
	}

	/**
	 * Registered at file load, before the plugin text domain is loaded on plugins_loaded,
	 * so the chosen language applies to this request. Front end only: wp-admin already
	 * uses each person's own profile language.
	 */
	public static function locale( $locale ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $locale;
		}
		$langs  = self::languages();
		$choice = '';
		if ( did_action( 'plugins_loaded' ) && function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$choice = (string) get_user_meta( get_current_user_id(), 'locale', true );
		}
		if ( ! $choice && isset( $_COOKIE[ self::COOKIE ] ) ) {
			$choice = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		}
		return $choice && isset( $langs[ $choice ] ) ? $choice : $locale;
	}

	public static function init() {
		if ( ! (int) wic_setting( 'lang_switcher' ) ) {
			return;
		}
		add_action( 'wic_portal_header_tools', array( __CLASS__, 'header_switcher' ), 20 );
		add_filter( 'wic_portal_signin', array( __CLASS__, 'prepend_links' ) );
		add_filter( 'the_content', array( __CLASS__, 'register_links' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_param' ), 1 );
		add_action( 'admin_post_wic_set_lang', array( __CLASS__, 'set_lang' ) );
		// <html lang> follows automatically: language_attributes() reads the filtered locale.
	}

	/** ?wic_lang=es_ES on any page: remember the choice and reload without the parameter. */
	public static function handle_param() {
		if ( ! isset( $_GET['wic_lang'] ) ) {
			return;
		}
		$lang  = sanitize_text_field( wp_unslash( $_GET['wic_lang'] ) );
		$langs = self::languages();
		if ( isset( $langs[ $lang ] ) ) {
			setcookie( self::COOKIE, $lang, time() + YEAR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
			if ( is_user_logged_in() ) {
				update_user_meta( get_current_user_id(), 'locale', 'en_US' === $lang ? '' : $lang );
			}
		}
		wp_safe_redirect( remove_query_arg( 'wic_lang' ) );
		exit;
	}

	private static function current() {
		$l = determine_locale();
		return isset( self::languages()[ $l ] ) ? $l : 'en_US';
	}

	public static function links() {
		$cur = self::current();
		$out = array();
		foreach ( self::languages() as $locale => $name ) {
			$lang  = substr( $locale, 0, 2 );
			$out[] = $locale === $cur
				? '<a href="' . esc_url( add_query_arg( 'wic_lang', $locale ) ) . '" aria-current="true" lang="' . esc_attr( $lang ) . '">' . esc_html( $name ) . '</a>'
				: '<a href="' . esc_url( add_query_arg( 'wic_lang', $locale ) ) . '" lang="' . esc_attr( $lang ) . '" hreflang="' . esc_attr( $lang ) . '">' . esc_html( $name ) . '</a>';
		}
		return '<nav class="wic-lang-links" aria-label="' . esc_attr__( 'Language', 'wic-tp' ) . '">' . implode( ' · ', $out ) . '</nav>';
	}

	public static function prepend_links( $html ) {
		return str_replace( '<div class="wic-portal wic-signin">', '<div class="wic-portal wic-signin">' . self::links(), $html );
	}

	public static function register_links( $content ) {
		$ids = array( wic_page_id( 'register' ), wic_page_id( 'verify' ), wic_page_id( 'a11y_statement' ), wic_page_id( 'vendor' ) );
		if ( ! in_the_loop() || ! is_page() || ! in_array( get_the_ID(), array_filter( $ids ), true ) ) {
			return $content;
		}
		return '<div class="wic-portal">' . self::links() . '</div>' . $content;
	}

	public static function header_switcher( $uid ) {
		$cur = self::current();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-lang">
			<input type="hidden" name="action" value="wic_set_lang">
			<?php wp_nonce_field( 'wic_set_lang' ); ?>
			<input type="hidden" name="back" value="<?php echo esc_url( ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' ) ); ?>">
			<label for="wic-lang-select" class="screen-reader-text"><?php esc_html_e( 'Language', 'wic-tp' ); ?></label>
			<select id="wic-lang-select" name="lang">
				<?php foreach ( self::languages() as $locale => $name ) : ?>
					<option value="<?php echo esc_attr( $locale ); ?>" lang="<?php echo esc_attr( substr( $locale, 0, 2 ) ); ?>" <?php selected( $cur, $locale ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="wic-btn wic-btn--small"><?php esc_html_e( 'Change', 'wic-tp' ); ?></button>
		</form>
		<?php
	}

	public static function set_lang() {
		check_admin_referer( 'wic_set_lang' );
		$lang  = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : 'en_US';
		$langs = self::languages();
		if ( isset( $langs[ $lang ] ) && is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), 'locale', 'en_US' === $lang ? '' : $lang );
			setcookie( self::COOKIE, $lang, time() + YEAR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		}
		$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '';
		wp_safe_redirect( $back ? $back : wic_page_url( 'portal' ) );
		exit;
	}
}

add_filter( 'locale', array( 'WIC_I18n_UI', 'locale' ), 20 );
add_action( 'wic_init', array( 'WIC_I18n_UI', 'init' ) );
