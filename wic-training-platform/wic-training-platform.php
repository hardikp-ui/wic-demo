<?php
/**
 * Plugin Name: WIC Training Platform
 * Description: Multi-agency WIC staff training portal — registration and approvals, course/module/slide authoring, lesson player with slide layers and knowledge checks, progress records, certificates with public verification, supervisor/administrator views, classroom and competency records, signed forms, resources, reinforcement, vendor portal and data portability.
 * Version:     0.2.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Text Domain: wic-tp
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'WIC_TP_VERSION', '0.2.0' );
define( 'WIC_TP_DB_VERSION', '2' );
define( 'WIC_TP_FILE', __FILE__ );
define( 'WIC_TP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WIC_TP_URL', plugin_dir_url( __FILE__ ) );

require_once WIC_TP_DIR . 'includes/helpers.php';
require_once WIC_TP_DIR . 'includes/class-install.php';
require_once WIC_TP_DIR . 'includes/class-roles.php';
require_once WIC_TP_DIR . 'includes/class-content.php';
require_once WIC_TP_DIR . 'includes/class-records.php';
require_once WIC_TP_DIR . 'includes/class-registration.php';
require_once WIC_TP_DIR . 'includes/class-notify.php';
require_once WIC_TP_DIR . 'includes/class-certificates.php';
require_once WIC_TP_DIR . 'includes/class-rest.php';
require_once WIC_TP_DIR . 'includes/class-player.php';
require_once WIC_TP_DIR . 'includes/class-portal.php';
require_once WIC_TP_DIR . 'includes/class-actions.php';
require_once WIC_TP_DIR . 'includes/class-agency.php';
require_once WIC_TP_DIR . 'includes/class-admin.php';
require_once WIC_TP_DIR . 'includes/class-sample.php';

/*
 * Feature modules. Each file in includes/modules/ is self-contained: it hooks itself in,
 * registers its own tables through the `wic_table_sql` filter and its own portal views
 * through `wic_portal_views`, so modules never have to edit each other.
 */
foreach ( (array) glob( WIC_TP_DIR . 'includes/modules/*.php' ) as $wic_module_file ) {
	require_once $wic_module_file;
}
unset( $wic_module_file );

register_activation_hook( __FILE__, array( 'WIC_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WIC_Install', 'deactivate' ) );

add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'wic-tp', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	WIC_Install::maybe_upgrade();
	WIC_Roles::init();
	WIC_Content::init();
	WIC_Registration::init();
	WIC_Notify::init();
	WIC_Certificates::init();
	WIC_Rest::init();
	WIC_Player::init();
	WIC_Portal::init();
	WIC_Actions::init();
	WIC_Agency::init();
	WIC_Admin::init();
	/** Modules initialise here, after the core classes. */
	do_action( 'wic_init' );
} );
