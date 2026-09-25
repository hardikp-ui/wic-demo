<?php
/**
 * Site footer: deepest navy, wordmark, the platform's useful links.
 */

defined( 'ABSPATH' ) || exit;

$bawic_links = array(
	'portal'         => __( 'Training portal', 'brushart-wic' ),
	'register'       => __( 'Register as new staff', 'brushart-wic' ),
	'verify'         => __( 'Verify a certificate', 'brushart-wic' ),
	'clinics'        => __( 'Clinic directory', 'brushart-wic' ),
	'vendor'         => __( 'Vendor portal', 'brushart-wic' ),
	'a11y_statement' => __( 'Accessibility statement', 'brushart-wic' ),
);
?>
</main>
<footer class="ba-footer">
	<div class="ba-wrap ba-footer__grid">
		<div class="ba-footer__brand">
			<?php bawic_lockup( 'white' ); ?>
			<p>
				<?php esc_html_e( 'A WIC staff training platform presented by', 'brushart-wic' ); ?>
				<a href="https://www.brushart.com" rel="noopener"><?php esc_html_e( 'Brush Art', 'brushart-wic' ); ?></a>.
			</p>
		</div>
		<nav class="ba-footer__col" aria-label="<?php esc_attr_e( 'Platform', 'brushart-wic' ); ?>">
			<h2 class="ba-footer__h"><?php esc_html_e( 'Platform', 'brushart-wic' ); ?></h2>
			<ul>
				<li><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'brushart-wic' ); ?></a></li>
				<?php foreach ( array( 'portal', 'register', 'verify' ) as $bawic_key ) : ?>
					<?php if ( bawic_page_id( $bawic_key ) ) : ?>
						<li><a href="<?php echo esc_url( bawic_page_url( $bawic_key ) ); ?>"><?php echo esc_html( $bawic_links[ $bawic_key ] ); ?></a></li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
		</nav>
		<nav class="ba-footer__col" aria-label="<?php esc_attr_e( 'For partners', 'brushart-wic' ); ?>">
			<h2 class="ba-footer__h"><?php esc_html_e( 'Clinics and partners', 'brushart-wic' ); ?></h2>
			<ul>
				<?php foreach ( array( 'clinics', 'vendor', 'a11y_statement' ) as $bawic_key ) : ?>
					<?php if ( bawic_page_id( $bawic_key ) ) : ?>
						<li><a href="<?php echo esc_url( bawic_page_url( $bawic_key ) ); ?>"><?php echo esc_html( $bawic_links[ $bawic_key ] ); ?></a></li>
					<?php endif; ?>
				<?php endforeach; ?>
				<?php if ( has_nav_menu( 'footer' ) ) : ?>
					<?php
					wp_nav_menu(
						array(
							'theme_location' => 'footer',
							'container'      => false,
							'items_wrap'     => '%3$s',
							'depth'          => 1,
						)
					);
					?>
				<?php endif; ?>
			</ul>
		</nav>
	</div>
	<div class="ba-wrap ba-footer__base">
		<p>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php esc_html_e( 'Brush Art Corporation. Demonstration site — sample content only.', 'brushart-wic' ); ?></p>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
