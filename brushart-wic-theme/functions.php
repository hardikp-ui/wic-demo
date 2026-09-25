<?php
/**
 * BrushArt WIC Training theme.
 *
 * The training platform stays white-label: this theme only dresses the site around it,
 * and passes its fonts to the portal through CSS variables.
 */

defined( 'ABSPATH' ) || exit;

define( 'BAWIC_VERSION', '1.0.0' );
define( 'BAWIC_URL', get_template_directory_uri() );

add_action(
	'after_setup_theme',
	function () {
		load_theme_textdomain( 'brushart-wic', get_template_directory() . '/languages' );
		add_theme_support( 'title-tag' );
		add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
		add_theme_support( 'custom-logo' );
		add_theme_support( 'post-thumbnails' );
		register_nav_menus(
			array(
				'primary' => __( 'Primary', 'brushart-wic' ),
				'footer'  => __( 'Footer', 'brushart-wic' ),
			)
		);
	}
);

add_action(
	'wp_enqueue_scripts',
	function () {
		wp_enqueue_style( 'bawic-fonts', 'https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..800&family=Inter:wght@400;500;600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_style( 'bawic-theme', BAWIC_URL . '/assets/css/theme.css', array( 'bawic-fonts' ), BAWIC_VERSION );
		wp_enqueue_script( 'bawic-theme', BAWIC_URL . '/assets/js/theme.js', array(), BAWIC_VERSION, true );
	}
);

/** Mark JS as available before first paint, so the mobile menu starts closed only when it can open. */
add_action(
	'wp_head',
	function () {
		echo "<script>document.documentElement.className+=' js';</script>\n";
	},
	1
);

add_filter(
	'wp_resource_hints',
	function ( $urls, $type ) {
		if ( 'preconnect' === $type ) {
			$urls[] = 'https://fonts.googleapis.com';
			$urls[] = array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' );
		}
		return $urls;
	},
	10,
	2
);

/** The platform's page keys. Read from the plugin when it is active. */
function bawic_wic_page_keys() {
	if ( class_exists( 'WIC_Install' ) && method_exists( 'WIC_Install', 'page_defs' ) ) {
		return array_keys( WIC_Install::page_defs() );
	}
	return array( 'portal', 'register', 'learn', 'certificate', 'verify', 'vendor', 'clinics', 'a11y_statement' );
}

/** ID of a platform page, 0 if it does not exist or is not published. */
function bawic_page_id( $key ) {
	$id = (int) get_option( 'wic_page_' . $key );
	return ( $id && 'publish' === get_post_status( $id ) ) ? $id : 0;
}

function bawic_page_url( $key ) {
	$id = bawic_page_id( $key );
	return $id ? get_permalink( $id ) : '';
}

function bawic_is_wic_page() {
	if ( ! is_page() ) {
		return false;
	}
	$current = get_queried_object_id();
	foreach ( bawic_wic_page_keys() as $key ) {
		if ( $current && (int) get_option( 'wic_page_' . $key ) === $current ) {
			return true;
		}
	}
	return false;
}

add_filter(
	'body_class',
	function ( $classes ) {
		if ( bawic_is_wic_page() ) {
			$classes[] = 'is-wic-page';
		}
		return $classes;
	}
);

/** Wordmark + divider + product name. $variant is white (on navy) or navy (on light). */
function bawic_lockup( $variant = 'white' ) {
	$file = 'navy' === $variant ? 'brushart-logo-navy.svg' : 'brushart-logo-white.svg';
	?>
	<a class="ba-lockup ba-lockup--<?php echo esc_attr( $variant ); ?>" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
		<img class="ba-lockup__mark" src="<?php echo esc_url( BAWIC_URL . '/assets/img/' . $file ); ?>" width="176" height="11" alt="<?php esc_attr_e( 'Brush Art', 'brushart-wic' ); ?>">
		<span class="ba-lockup__divider" aria-hidden="true"></span>
		<span class="ba-lockup__product"><?php esc_html_e( 'WIC Training', 'brushart-wic' ); ?></span>
	</a>
	<?php
}

/** Links built from the platform pages that exist; used when no menu is assigned. */
function bawic_fallback_links() {
	$links = array( array( home_url( '/' ), __( 'Home', 'brushart-wic' ), is_front_page() ) );
	$map   = array(
		'portal'  => __( 'Training portal', 'brushart-wic' ),
		'clinics' => __( 'Clinic directory', 'brushart-wic' ),
		'vendor'  => __( 'Vendors', 'brushart-wic' ),
		'verify'  => __( 'Verify a certificate', 'brushart-wic' ),
	);
	foreach ( $map as $key => $label ) {
		$id = bawic_page_id( $key );
		if ( $id ) {
			$links[] = array( get_permalink( $id ), $label, is_page( $id ) );
		}
	}
	return $links;
}

function bawic_primary_nav() {
	if ( has_nav_menu( 'primary' ) ) {
		wp_nav_menu(
			array(
				'theme_location' => 'primary',
				'container'      => false,
				'menu_class'     => 'ba-nav__list',
				'depth'          => 1,
			)
		);
		return;
	}
	echo '<ul class="ba-nav__list">';
	foreach ( bawic_fallback_links() as $l ) {
		printf(
			'<li><a href="%1$s"%3$s>%2$s</a></li>',
			esc_url( $l[0] ),
			esc_html( $l[1] ),
			$l[2] ? ' aria-current="page"' : ''
		);
	}
	echo '</ul>';
}

/** The one header action: sign in, or straight to the portal once signed in. */
function bawic_signin_button() {
	$portal = bawic_page_url( 'portal' );
	$url    = $portal ? $portal : wp_login_url();
	$label  = is_user_logged_in() ? __( 'My training', 'brushart-wic' ) : __( 'Sign in', 'brushart-wic' );
	printf( '<a class="ba-btn ba-btn--red ba-btn--sm" href="%s">%s</a>', esc_url( $url ), esc_html( $label ) );
}

/**
 * Real counts from the platform's own records. Nothing here is estimated;
 * a zero means the demo has not generated that record yet.
 */
function bawic_live_numbers() {
	if ( ! function_exists( 'wic_table' ) ) {
		return array();
	}
	global $wpdb;
	$count = function ( $sql ) use ( $wpdb ) {
		$wpdb->suppress_errors( true );
		$n = (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed queries, table names only.
		$wpdb->suppress_errors( false );
		return $n;
	};
	$courses  = wp_count_posts( 'wic_course' );
	$learners = get_users(
		array(
			'role__in'   => array( 'wic_learner', 'wic_supervisor', 'wic_admin', 'wic_local_admin' ),
			'meta_key'   => 'wic_status',
			'meta_value' => 'active',
			'fields'     => 'ID',
		)
	);
	return array(
		array( isset( $courses->publish ) ? (int) $courses->publish : 0, __( 'Published courses', 'brushart-wic' ) ),
		array( count( $learners ), __( 'Active staff accounts', 'brushart-wic' ) ),
		array( $count( 'SELECT COUNT(*) FROM ' . wic_table( 'completions' ) ), __( 'Completions recorded', 'brushart-wic' ) ),
		array( $count( 'SELECT COUNT(*) FROM ' . wic_table( 'certificates' ) ), __( 'Certificates issued', 'brushart-wic' ) ),
	);
}

/** Small inline icons, drawn on a 24px grid with currentColor strokes. */
function bawic_icon( $name ) {
	$paths = array(
		'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
		'book'     => '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 19V5"/>',
		'play'     => '<rect x="3" y="4" width="18" height="14" rx="2"/><path d="M10 8l5 3-5 3z"/>',
		'check'    => '<circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/>',
		'globe'    => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/>',
		'award'    => '<circle cx="12" cy="9" r="6"/><path d="M8.5 14L7 22l5-3 5 3-1.5-8"/>',
		'list'     => '<path d="M9 6h11M9 12h11M9 18h11"/><path d="M4 6h1M4 12h1M4 18h1"/>',
		'chart'    => '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-5M12 16V8M16 16v-3"/>',
		'pen'      => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13 7l4 4"/>',
		'people'   => '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3 20c0-3 3-5 6-5s6 2 6 5"/><path d="M15 15c3 0 6 1.5 6 4"/>',
		'folder'   => '<path d="M3 6a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
		'refresh'  => '<path d="M20 11a8 8 0 0 0-14-5l-2 2"/><path d="M4 4v4h4"/><path d="M4 13a8 8 0 0 0 14 5l2-2"/><path d="M20 20v-4h-4"/>',
		'store'    => '<path d="M4 9l1.5-5h13L20 9"/><path d="M4 9h16v2a3 3 0 0 1-6 0 3 3 0 0 1-4 0 3 3 0 0 1-6 0z"/><path d="M5 12v8h14v-8"/>',
		'layers'   => '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
		'access'   => '<circle cx="12" cy="4.5" r="1.8"/><path d="M5 8l7 1.5L19 8"/><path d="M12 9.5V14l-3 7M12 14l3 7"/>',
		'export'   => '<path d="M12 3v12"/><path d="M7 8l5-5 5 5"/><path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/>',
		'shield'   => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
	);
	$d = isset( $paths[ $name ] ) ? $paths[ $name ] : $paths['check'];
	return '<svg class="ba-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $d . '</svg>';
}

/** Allowed markup for bawic_icon() output. */
function bawic_icon_kses() {
	$shape = array_fill_keys( array( 'd', 'cx', 'cy', 'r', 'x', 'y', 'width', 'height', 'rx' ), true );
	return array(
		'svg'    => array_fill_keys( array( 'class', 'viewbox', 'width', 'height', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'aria-hidden', 'focusable' ), true ),
		'path'   => $shape,
		'circle' => $shape,
		'rect'   => $shape,
	);
}

/** Excerpt length for news cards. */
add_filter(
	'excerpt_length',
	function () {
		return 24;
	}
);
