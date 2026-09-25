<?php
/**
 * Pages. Platform pages (portal, player, verify…) render full width with no theme title —
 * the portal draws its own heading.
 */

defined( 'ABSPATH' ) || exit;

get_header();
$bawic_wic = bawic_is_wic_page();
while ( have_posts() ) :
	the_post();
	if ( $bawic_wic ) :
		?>
		<div class="ba-wic">
			<div class="ba-wrap">
				<h1 class="screen-reader-text"><?php the_title(); ?></h1>
				<?php the_content(); ?>
			</div>
		</div>
	<?php else : ?>
		<header class="ba-pagehead">
			<div class="ba-wrap">
				<h1 class="ba-pagehead__title"><?php the_title(); ?></h1>
			</div>
		</header>
		<div class="ba-wrap ba-content">
			<article <?php post_class( 'ba-prose' ); ?>>
				<?php the_content(); ?>
				<?php wp_link_pages(); ?>
			</article>
		</div>
		<?php
	endif;
endwhile;
get_footer();
