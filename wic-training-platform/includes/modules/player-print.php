<?php
/**
 * Print a module (or the whole course) as a handout (#35): every slide's text, images with
 * their descriptions, every layer expanded and the narration transcript. Questions are
 * printed without answers — scoring stays on the server.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Print {

	public static function url( $course_id, $module_id = 0 ) {
		return wic_page_url( 'learn', array( 'course' => $course_id, 'print' => $module_id ? $module_id : 'all' ) );
	}

	public static function render( $uid, $course_id ) {
		$want = isset( $_GET['print'] ) ? sanitize_key( wp_unslash( $_GET['print'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification
		$lang = class_exists( 'WIC_I18n' ) ? WIC_I18n::user_lang( $uid ) : '';
		$def  = class_exists( 'WIC_I18n' ) ? WIC_I18n::default_lang() : '';
		$tree = WIC_Records::learner_tree( $uid, $course_id );
		if ( 'all' !== $want ) {
			$tree = array_values(
				array_filter(
					$tree,
					function ( $m ) use ( $want ) {
						return (int) $m['id'] === (int) $want;
					}
				)
			);
		}
		if ( ! $tree ) {
			wp_die( esc_html__( 'That module is not part of this course.', 'wic-tp' ), '', array( 'response' => 404, 'back_link' => true ) );
		}
		wic_audit( 'print_handout', 'course', $course_id, array( 'module' => $want ) );
		$course = get_post( $course_id );
		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex">
	<title><?php echo esc_html( sprintf( __( 'Handout: %s', 'wic-tp' ), $course->post_title ) ); ?></title>
	<style>
		<?php echo WIC_Agency::tokens_css(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		body { font: 12pt/1.5 Georgia, "Times New Roman", serif; color: #1d2430; max-width: 46rem; margin: 2rem auto; padding: 0 1rem; }
		h1, h2, h3, h4 { font-family: Arial, Helvetica, sans-serif; line-height: 1.25; }
		h1 { font-size: 1.6rem; border-bottom: 3px solid var(--wic-primary, #1f5f8b); padding-bottom: .4rem; }
		h2 { font-size: 1.3rem; margin-top: 2rem; }
		h3 { font-size: 1.1rem; margin: 1.5rem 0 .4rem; }
		.slide { border-top: 1px solid #d9dee5; padding-top: .75rem; break-inside: avoid; }
		img { max-width: 100%; height: auto; }
		figcaption { font-size: .9em; color: #5b6573; }
		.layer { border-left: 3px solid #d9dee5; padding-left: .75rem; margin: .5rem 0; }
		.transcript { font-size: .92em; color: #333; background: #f3f5f8; padding: .5rem .75rem; }
		.meta { color: #5b6573; font-size: .9em; }
		.toolbar { display: flex; gap: .75rem; margin-bottom: 1rem; font-family: Arial, sans-serif; }
		.toolbar button, .toolbar a { font: inherit; padding: .5rem 1rem; border: 1px solid #9aa4b1; border-radius: .4rem; background: #fff; color: #1d2430; text-decoration: none; cursor: pointer; }
		.toolbar button { background: var(--wic-primary, #1f5f8b); color: #fff; border-color: transparent; }
		:focus-visible { outline: 3px solid var(--wic-accent, #e0a100); outline-offset: 2px; }
		@media print { .toolbar { display: none; } body { margin: 0; max-width: none; } a { color: inherit; text-decoration: none; } }
	</style>
</head>
<body>
	<div class="toolbar">
		<button type="button" onclick="window.print()"><?php esc_html_e( 'Print or save as PDF', 'wic-tp' ); ?></button>
		<a href="<?php echo esc_url( wic_page_url( 'learn', array( 'course' => $course_id ) ) ); ?>"><?php esc_html_e( 'Back to the lesson', 'wic-tp' ); ?></a>
	</div>
	<h1><?php echo esc_html( $course->post_title ); ?></h1>
	<p class="meta"><?php echo esc_html( wic_setting( 'name' ) ); ?> · <?php echo esc_html( sprintf( __( 'Printed %s', 'wic-tp' ), wp_date( get_option( 'date_format' ) ) ) ); ?></p>
	<?php foreach ( $tree as $m ) : ?>
		<h2><?php echo esc_html( $m['title'] ); ?></h2>
		<?php foreach ( $m['slides'] as $sid ) : ?>
			<?php
			$post   = get_post( $sid );
			$t      = array();
			if ( $lang && $lang !== $def && class_exists( 'WIC_I18n' ) ) {
				$all = WIC_I18n::get( $sid );
				$t   = isset( $all[ $lang ] ) ? $all[ $lang ] : array();
			}
			$title  = ! empty( $t['title'] ) ? $t['title'] : $post->post_title;
			$html   = ! empty( $t['html'] ) ? $t['html'] : $post->post_content;
			$script = ! empty( $t['script'] ) ? $t['script'] : (string) get_post_meta( $sid, '_wic_script', true );
			$img    = (string) get_post_meta( $sid, '_wic_image_url', true );
			$alt    = (string) get_post_meta( $sid, '_wic_image_alt', true );
			$q      = WIC_Content::question( $sid );
			if ( $q && class_exists( 'WIC_I18n' ) ) {
				$q = WIC_I18n::question_for_learner( $q, $sid, $lang );
			}
			$pq     = WIC_Content::public_question( $q );
			?>
			<section class="slide">
				<h3><?php echo esc_html( $title ); ?></h3>
				<?php if ( $img ) : ?>
					<figure><img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $alt ); ?>"><figcaption><?php echo esc_html( $alt ); ?></figcaption></figure>
				<?php endif; ?>
				<?php echo wp_kses_post( wpautop( $html ) ); ?>
				<?php foreach ( WIC_Content::layers( $sid ) as $l ) : ?>
					<div class="layer"><h4><?php echo esc_html( $l['label'] ); ?></h4><?php echo wp_kses_post( $l['html'] ); ?></div>
				<?php endforeach; ?>
				<?php if ( $pq ) : ?>
					<div class="layer">
						<h4><?php esc_html_e( 'Knowledge check', 'wic-tp' ); ?></h4>
						<?php echo wp_kses_post( $pq['prompt'] ); ?>
						<?php if ( ! empty( $pq['options'] ) ) : ?>
							<ul><?php foreach ( $pq['options'] as $o ) : ?><li><?php echo wp_kses_post( $o['text'] ); ?></li><?php endforeach; ?></ul>
						<?php elseif ( ! empty( $pq['items'] ) ) : ?>
							<p><?php echo esc_html( implode( ' · ', $pq['items'] ) ); ?> → <?php echo esc_html( implode( ' / ', $pq['categories'] ) ); ?></p>
						<?php elseif ( ! empty( $pq['left'] ) ) : ?>
							<p><?php echo esc_html( implode( ' · ', $pq['left'] ) ); ?> ↔ <?php echo esc_html( implode( ' · ', $pq['right'] ) ); ?></p>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<?php if ( $script ) : ?>
					<p class="transcript"><strong><?php esc_html_e( 'Transcript:', 'wic-tp' ); ?></strong> <?php echo esc_html( $script ); ?></p>
				<?php endif; ?>
			</section>
		<?php endforeach; ?>
	<?php endforeach; ?>
</body>
</html>
		<?php
		exit;
	}
}
