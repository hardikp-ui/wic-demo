<?php
/**
 * Site header: navy bar, product lockup, primary navigation and the one sign-in action.
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="ba-skip" href="#main"><?php esc_html_e( 'Skip to content', 'brushart-wic' ); ?></a>
<header class="ba-header">
	<div class="ba-wrap ba-header__inner">
		<?php bawic_lockup( 'white' ); ?>
		<button type="button" class="ba-nav-toggle" aria-expanded="false" aria-controls="ba-nav">
			<span class="ba-nav-toggle__bars" aria-hidden="true"></span>
			<span class="ba-nav-toggle__label"><?php esc_html_e( 'Menu', 'brushart-wic' ); ?></span>
		</button>
		<nav class="ba-nav" id="ba-nav" aria-label="<?php esc_attr_e( 'Main', 'brushart-wic' ); ?>">
			<?php bawic_primary_nav(); ?>
			<div class="ba-nav__action"><?php bawic_signin_button(); ?></div>
		</nav>
	</div>
</header>
<main id="main" class="ba-main" tabindex="-1">
