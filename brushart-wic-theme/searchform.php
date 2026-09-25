<?php
/**
 * Search form with a visible label for screen readers and a real button.
 */

defined( 'ABSPATH' ) || exit;

$bawic_sid = wp_unique_id( 'ba-search-' );
?>
<form role="search" method="get" class="ba-search" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label class="screen-reader-text" for="<?php echo esc_attr( $bawic_sid ); ?>"><?php esc_html_e( 'Search this site', 'brushart-wic' ); ?></label>
	<input type="search" id="<?php echo esc_attr( $bawic_sid ); ?>" name="s" value="<?php echo esc_attr( get_search_query() ); ?>" placeholder="<?php esc_attr_e( 'Search this site', 'brushart-wic' ); ?>">
	<button type="submit" class="ba-btn ba-btn--navy"><?php esc_html_e( 'Search', 'brushart-wic' ); ?></button>
</form>
