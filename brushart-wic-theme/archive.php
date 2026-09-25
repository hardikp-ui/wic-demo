<?php
/**
 * Archives, including the platform's news post type.
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<header class="ba-pagehead">
	<div class="ba-wrap">
		<p class="ba-kicker"><?php esc_html_e( 'Archive', 'brushart-wic' ); ?></p>
		<h1 class="ba-pagehead__title"><?php echo esc_html( wp_strip_all_tags( get_the_archive_title() ) ); ?></h1>
		<?php the_archive_description( '<div class="ba-pagehead__lede">', '</div>' ); ?>
	</div>
</header>
<div class="ba-wrap ba-content">
	<?php get_template_part( 'parts/loop' ); ?>
</div>
<?php
get_footer();
