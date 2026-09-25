<?php
/**
 * Plugin Name:      WIC Demo Content
 * Description:      Fills the WIC Training Platform with a fictional agency for sales demonstrations — clinics, people, sample courses, progress, certificates, sessions, forms and more — plus a "View as" persona switcher. Demonstration only: never install on a live site.
 * Version:          1.0.0
 * Requires at least: 6.5
 * Requires PHP:     7.4
 * Requires Plugins: wic-training-platform
 * Text Domain:      wic-demo
 */

defined( 'ABSPATH' ) || exit;

define( 'WIC_DEMO_VERSION', '1.0.0' );
define( 'WIC_DEMO_DIR', plugin_dir_path( __FILE__ ) );
define( 'WIC_DEMO_URL', plugin_dir_url( __FILE__ ) );

require_once WIC_DEMO_DIR . 'includes/class-demo-seeder.php';
require_once WIC_DEMO_DIR . 'includes/class-demo-switcher.php';

register_activation_hook(
	__FILE__,
	function () {
		// The platform may be activated in the same request; seed once everything is loaded.
		update_option( 'wic_demo_seed_pending', 1, false );
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WIC_Records' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'WIC Demo Content needs the WIC Training Platform plugin to be active.', 'wic-demo' ) . '</p></div>';
				}
			);
			return;
		}
		WIC_Demo_Switcher::init();
		add_action( 'admin_menu', 'wic_demo_menu', 20 );
		add_action( 'admin_post_wic_demo_load', 'wic_demo_handle' );
		add_action( 'admin_post_wic_demo_remove', 'wic_demo_handle' );
		// Seed after activation, once the platform's content types and tables exist.
		add_action(
			'wp_loaded',
			function () {
				if ( get_option( 'wic_demo_seed_pending' ) && is_admin() && current_user_can( 'manage_options' ) ) {
					delete_option( 'wic_demo_seed_pending' );
					WIC_Demo_Seeder::seed();
				}
			}
		);
	},
	20
);

function wic_demo_menu() {
	$parent = menu_page_url( 'wic-platform', false ) ? 'wic-platform' : 'tools.php';
	add_submenu_page( $parent, __( 'Demo content', 'wic-demo' ), __( 'Demo content', 'wic-demo' ), 'manage_options', 'wic-demo', 'wic_demo_page' );
}

function wic_demo_handle() {
	check_admin_referer( 'wic_demo_admin' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'wic-demo' ), '', array( 'response' => 403 ) );
	}
	$action = isset( $_POST['action'] ) ? sanitize_key( $_POST['action'] ) : '';
	if ( 'wic_demo_remove' === $action ) {
		WIC_Demo_Seeder::reset();
		$msg = 'removed';
	} else {
		$r   = WIC_Demo_Seeder::seed();
		$msg = is_wp_error( $r ) ? 'failed' : 'loaded';
	}
	wp_safe_redirect( admin_url( 'admin.php?page=wic-demo&wic_demo=' . $msg ) );
	exit;
}

function wic_demo_page() {
	global $wpdb;
	$state  = WIC_Demo_Seeder::state();
	$loaded = WIC_Demo_Seeder::is_loaded();
	$msg    = isset( $_GET['wic_demo'] ) ? sanitize_key( $_GET['wic_demo'] ) : '';
	$notes  = array(
		'loaded'  => array( 'success', __( 'Demo content loaded.', 'wic-demo' ) ),
		'removed' => array( 'success', __( 'Demo content removed.', 'wic-demo' ) ),
		'failed'  => array( 'error', __( 'The demo content could not be loaded. Is the WIC Training Platform active?', 'wic-demo' ) ),
	);
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Demo content', 'wic-demo' ); ?></h1>
		<?php if ( isset( $notes[ $msg ] ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notes[ $msg ][0] ); ?>"><p><?php echo esc_html( $notes[ $msg ][1] ); ?></p></div>
		<?php endif; ?>
		<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Demonstration only — never install this plugin on a live site.', 'wic-demo' ); ?></strong>
			<?php esc_html_e( 'Every person, clinic, store, course and record it creates is fictional. Removing it deletes the demo accounts directly from the database — something the platform itself never does to real people — and removes only what it created.', 'wic-demo' ); ?></p></div>

		<?php if ( $loaded ) : ?>
			<?php
			$users = array_values( array_filter( array_map( 'intval', $state['users'] ) ) );
			$in    = $users ? implode( ',', $users ) : '0';
			$count = function ( $table ) use ( $wpdb, $in ) {
				$wpdb->suppress_errors( true );
				$n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . wic_table( $table ) . " WHERE user_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL -- integer list.
				$wpdb->suppress_errors( false );
				return $n;
			};
			$rows = array(
				__( 'People', 'wic-demo' )         => count( $users ),
				__( 'Clinics', 'wic-demo' )        => count( (array) $state['clinics'] ) + ( isset( $state['clinics_kept'] ) ? count( $state['clinics_kept'] ) : 0 ),
				__( 'Courses', 'wic-demo' )        => count( (array) $state['courses'] ),
				__( 'Assignments', 'wic-demo' )    => $count( 'assignments' ),
				__( 'Completions', 'wic-demo' )    => $count( 'completions' ),
				__( 'Certificates', 'wic-demo' )   => $count( 'certificates' ),
				__( 'Answers recorded', 'wic-demo' ) => $count( 'attempts' ),
				__( 'Signatures', 'wic-demo' )     => $count( 'signatures' ),
				__( 'Session places', 'wic-demo' ) => $count( 'roster' ),
				__( 'Refreshers', 'wic-demo' )     => $count( 'boost_rounds' ),
			);
			?>
			<p><?php echo esc_html( sprintf( __( 'Loaded %s.', 'wic-demo' ), $state['seeded_at'] ) ); ?></p>
			<table class="widefat striped" style="max-width:420px"><tbody>
				<?php foreach ( $rows as $label => $n ) : ?>
					<tr><td><?php echo esc_html( $label ); ?></td><td><strong><?php echo (int) $n; ?></strong></td></tr>
				<?php endforeach; ?>
			</tbody></table>

			<h2><?php esc_html_e( 'View as', 'wic-demo' ); ?></h2>
			<p><?php esc_html_e( 'Switch to a persona in one click; the floating "Demo: view as" panel brings you back.', 'wic-demo' ); ?></p>
			<table class="widefat striped" style="max-width:720px"><tbody>
				<?php foreach ( WIC_Demo_Switcher::personas() as $key => $p ) : ?>
					<?php
					if ( empty( $state['personas'][ $key ] ) ) {
						continue;
					}
					$u = get_userdata( (int) $state['personas'][ $key ] );
					?>
					<tr>
						<td><strong><?php echo esc_html( $p[0] ); ?></strong><br><span class="description"><?php echo esc_html( $p[1] ); ?></span></td>
						<td><?php echo esc_html( $u ? $u->display_name : '' ); ?></td>
						<td><a class="button" href="<?php echo esc_url( WIC_Demo_Switcher::url( $key ) ); ?>"><?php esc_html_e( 'View as', 'wic-demo' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody></table>
		<?php else : ?>
			<p><?php esc_html_e( 'No demo content is loaded.', 'wic-demo' ); ?></p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:20px;display:flex;gap:8px">
			<?php wp_nonce_field( 'wic_demo_admin' ); ?>
			<button class="button button-primary" name="action" value="wic_demo_load" onclick="return confirm('<?php echo esc_js( $loaded ? __( 'Remove the current demo content and load it fresh?', 'wic-demo' ) : __( 'Load the demo content?', 'wic-demo' ) ); ?>')"><?php echo $loaded ? esc_html__( 'Reset demo content', 'wic-demo' ) : esc_html__( 'Load demo content', 'wic-demo' ); ?></button>
			<?php if ( $loaded ) : ?>
				<button class="button" name="action" value="wic_demo_remove" onclick="return confirm('<?php echo esc_js( __( 'Remove all demo content?', 'wic-demo' ) ); ?>')"><?php esc_html_e( 'Remove demo content', 'wic-demo' ); ?></button>
			<?php endif; ?>
		</form>
	</div>
	<?php
}
