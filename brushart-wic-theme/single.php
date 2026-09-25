<?php
/**
 * Single posts and news items.
 */

defined( 'ABSPATH' ) || exit;

get_header();
while ( have_posts() ) :
	the_post();
	?>
	<header class="ba-pagehead">
		<div class="ba-wrap ba-wrap--narrow">
			<?php $bawic_type = get_post_type_object( get_post_type() ); ?>
			<p class="ba-kicker"><?php echo esc_html( 'wic_news' === get_post_type() ? __( 'News', 'brushart-wic' ) : ( $bawic_type ? $bawic_type->labels->singular_name : '' ) ); ?></p>
			<h1 class="ba-pagehead__title"><?php the_title(); ?></h1>
			<p class="ba-pagehead__meta"><time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time></p>
		</div>
	</header>
	<div class="ba-wrap ba-wrap--narrow ba-content">
		<article <?php post_class( 'ba-prose' ); ?>>
			<?php the_content(); ?>
			<?php wp_link_pages(); ?>
		</article>
		<nav class="ba-postnav" aria-label="<?php esc_attr_e( 'More posts', 'brushart-wic' ); ?>">
			<?php
			previous_post_link( '<span class="ba-postnav__prev">%link</span>', '&larr; %title' );
			next_post_link( '<span class="ba-postnav__next">%link</span>', '%title &rarr;' );
			?>
		</nav>
	</div>
	<?php
endwhile;
get_footer();
