<?php
/**
 * Not found.
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<section class="ba-404">
	<div class="ba-wrap ba-wrap--narrow">
		<p class="ba-kicker"><?php esc_html_e( 'Error 404', 'brushart-wic' ); ?></p>
		<h1 class="ba-pagehead__title"><?php esc_html_e( 'That page is not here.', 'brushart-wic' ); ?></h1>
		<p class="ba-lede"><?php esc_html_e( 'The link may be old, or the page may have moved. Try a search, or head to the training portal.', 'brushart-wic' ); ?></p>
		<?php get_search_form(); ?>
		<p class="ba-actions">
			<a class="ba-btn ba-btn--red" href="<?php echo esc_url( bawic_page_url( 'portal' ) ? bawic_page_url( 'portal' ) : home_url( '/' ) ); ?>"><?php esc_html_e( 'Go to the training portal', 'brushart-wic' ); ?></a>
			<a class="ba-btn ba-btn--ghost-navy" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'brushart-wic' ); ?></a>
		</p>
	</div>
</section>
<?php
get_footer();
