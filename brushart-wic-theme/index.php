<?php
/**
 * Fallback listing: blog index and search results.
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<header class="ba-pagehead">
	<div class="ba-wrap">
		<?php if ( is_search() ) : ?>
			<p class="ba-kicker"><?php esc_html_e( 'Search', 'brushart-wic' ); ?></p>
			<h1 class="ba-pagehead__title"><?php echo esc_html( sprintf( /* translators: %s: search terms */ __( 'Results for “%s”', 'brushart-wic' ), get_search_query() ) ); ?></h1>
		<?php else : ?>
			<h1 class="ba-pagehead__title"><?php echo esc_html( is_home() && get_option( 'page_for_posts' ) ? get_the_title( get_option( 'page_for_posts' ) ) : __( 'Latest', 'brushart-wic' ) ); ?></h1>
		<?php endif; ?>
		<div class="ba-pagehead__search"><?php get_search_form(); ?></div>
	</div>
</header>
<div class="ba-wrap ba-content">
	<?php get_template_part( 'parts/loop' ); ?>
</div>
<?php
get_footer();
