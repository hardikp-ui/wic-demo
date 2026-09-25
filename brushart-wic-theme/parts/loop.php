<?php
/**
 * Card grid for any post listing, with pagination and an honest empty state.
 */

defined( 'ABSPATH' ) || exit;

if ( have_posts() ) :
	?>
	<div class="ba-cards">
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<article <?php post_class( 'ba-card ba-card--post' ); ?>>
				<p class="ba-card__meta"><time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time></p>
				<h2 class="ba-card__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
				<div class="ba-card__text"><?php the_excerpt(); ?></div>
				<a class="ba-link-arrow" href="<?php the_permalink(); ?>" aria-hidden="true" tabindex="-1"><?php esc_html_e( 'Read more', 'brushart-wic' ); ?></a>
			</article>
		<?php endwhile; ?>
	</div>
	<?php
	the_posts_pagination(
		array(
			'mid_size'  => 1,
			'prev_text' => __( 'Previous', 'brushart-wic' ),
			'next_text' => __( 'Next', 'brushart-wic' ),
		)
	);
else :
	?>
	<div class="ba-empty">
		<p><?php esc_html_e( 'Nothing to show here yet.', 'brushart-wic' ); ?></p>
		<p><a class="ba-btn ba-btn--navy" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to home', 'brushart-wic' ); ?></a></p>
	</div>
	<?php
endif;
