<?php
/**
 * Lesson player page. Variables in scope: $course (WP_Post), $data (array).
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex">
	<title><?php echo esc_html( $course->post_title . ' — ' . wic_setting( 'name' ) ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( WIC_TP_URL . 'assets/css/player.css?ver=' . WIC_TP_VERSION ); ?>">
	<style><?php echo WIC_Agency::tokens_css(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from sanitised hex colours. ?></style>
	<?php
	foreach ( (array) glob( WIC_TP_DIR . 'assets/modules/player-*.css' ) as $wic_css ) {
		echo '<link rel="stylesheet" href="' . esc_url( WIC_TP_URL . 'assets/modules/' . basename( $wic_css ) . '?ver=' . filemtime( $wic_css ) ) . '">' . "\n";
	}
	/** Other modules may print player-page head tags here. */
	do_action( 'wic_player_head', $data );
	?>
</head>
<body class="wicp">
	<a class="wicp-skip" href="#wicp-slide"><?php esc_html_e( 'Skip to lesson', 'wic-tp' ); ?></a>
	<header class="wicp-top">
		<button type="button" class="wicp-iconbtn" id="wicp-toggle-side" aria-controls="wicp-side" aria-expanded="true">
			<span aria-hidden="true">☰</span><span class="wicp-sr"><?php esc_html_e( 'Show or hide the outline', 'wic-tp' ); ?></span>
		</button>
		<div class="wicp-brand">
			<a class="wicp-brand__logo" href="<?php echo esc_url( $data['portalUrl'] ); ?>">
				<?php if ( class_exists( 'WIC_Design_Fonts' ) ) : ?>
					<?php echo WIC_Design_Fonts::logo_on_dark(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the helper. ?>
				<?php elseif ( wic_setting( 'logo_url' ) ) : ?>
					<img class="is-chip" src="<?php echo esc_url( wic_setting( 'logo_url' ) ); ?>" alt="<?php echo esc_attr( wic_setting( 'name' ) ); ?>">
				<?php else : ?>
					<span class="wic-brand__name"><?php echo esc_html( wic_setting( 'name' ) ); ?></span>
				<?php endif; ?>
				<span class="wicp-sr"><?php esc_html_e( '— back to the training portal', 'wic-tp' ); ?></span>
			</a>
			<div class="wicp-brand__title">
				<span class="wicp-brand__kicker"><?php esc_html_e( 'Lesson', 'wic-tp' ); ?></span>
				<h1><?php echo esc_html( $course->post_title ); ?></h1>
			</div>
		</div>
		<div class="wicp-top__right">
			<div class="wicp-progress" aria-live="off">
				<div class="wicp-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="<?php esc_attr_e( 'Course progress', 'wic-tp' ); ?>" id="wicp-bar"><span></span></div>
				<span id="wicp-progress-text">0%</span>
				<span class="wicp-remaining" id="wicp-remaining"></span>
			</div>
			<span class="wicp-offline" id="wicp-offline" role="status" hidden></span>
			<?php if ( ! empty( $data['languages'] ) && count( $data['languages'] ) > 1 ) : ?>
				<label class="wicp-lang">
					<span class="wicp-sr"><?php esc_html_e( 'Course language', 'wic-tp' ); ?></span>
					<select id="wicp-lang">
						<?php foreach ( $data['languages'] as $wic_lang ) : ?>
							<option value="<?php echo esc_attr( $wic_lang['code'] ); ?>" lang="<?php echo esc_attr( $wic_lang['code'] ); ?>" <?php selected( $data['lang'], $wic_lang['code'] ); ?>><?php echo esc_html( $wic_lang['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>
			<div class="wicp-size" role="group" aria-label="<?php esc_attr_e( 'Text size', 'wic-tp' ); ?>">
				<button type="button" class="wicp-iconbtn" data-size="-1"><span aria-hidden="true">A−</span><span class="wicp-sr"><?php esc_html_e( 'Smaller text', 'wic-tp' ); ?></span></button>
				<button type="button" class="wicp-iconbtn" data-size="0"><span aria-hidden="true">A</span><span class="wicp-sr"><?php esc_html_e( 'Normal text size', 'wic-tp' ); ?></span></button>
				<button type="button" class="wicp-iconbtn" data-size="1"><span aria-hidden="true">A+</span><span class="wicp-sr"><?php esc_html_e( 'Larger text', 'wic-tp' ); ?></span></button>
			</div>
			<button type="button" class="wicp-iconbtn" id="wicp-fullscreen" aria-pressed="false">
				<span aria-hidden="true">⛶</span><span class="wicp-sr"><?php esc_html_e( 'Full screen', 'wic-tp' ); ?></span>
			</button>
			<a class="wicp-btn" href="<?php echo esc_url( $data['portalUrl'] ); ?>" id="wicp-exit"><?php esc_html_e( 'Save and exit', 'wic-tp' ); ?></a>
		</div>
	</header>

	<div class="wicp-body">
		<aside class="wicp-side" id="wicp-side" aria-label="<?php esc_attr_e( 'Course navigation', 'wic-tp' ); ?>">
			<div class="wicp-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Lesson panels', 'wic-tp' ); ?>">
				<button type="button" role="tab" id="wicp-tab-outline" aria-controls="wicp-panel-outline" aria-selected="true"><?php esc_html_e( 'Outline', 'wic-tp' ); ?></button>
				<button type="button" role="tab" id="wicp-tab-transcript" aria-controls="wicp-panel-transcript" aria-selected="false" tabindex="-1"><?php esc_html_e( 'Transcript', 'wic-tp' ); ?></button>
				<button type="button" role="tab" id="wicp-tab-notes" aria-controls="wicp-panel-notes" aria-selected="false" tabindex="-1"><?php esc_html_e( 'Notes', 'wic-tp' ); ?></button>
				<?php if ( ! empty( $data['resources'] ) ) : ?>
					<button type="button" role="tab" id="wicp-tab-resources" aria-controls="wicp-panel-resources" aria-selected="false" tabindex="-1"><?php esc_html_e( 'Resources', 'wic-tp' ); ?></button>
				<?php endif; ?>
			</div>
			<div role="tabpanel" id="wicp-panel-outline" aria-labelledby="wicp-tab-outline">
				<nav id="wicp-outline" aria-label="<?php esc_attr_e( 'Course outline', 'wic-tp' ); ?>"></nav>
				<p class="wicp-print">
					<a id="wicp-print-module" href="#" target="_blank" rel="noopener"><?php esc_html_e( 'Print this module as a handout', 'wic-tp' ); ?></a>
					<?php if ( ! empty( $data['printUrl'] ) ) : ?>
						· <a href="<?php echo esc_url( $data['printUrl'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Print the whole course', 'wic-tp' ); ?></a>
					<?php endif; ?>
				</p>
			</div>
			<div role="tabpanel" id="wicp-panel-transcript" aria-labelledby="wicp-tab-transcript" hidden>
				<form class="wicp-search" id="wicp-search" role="search">
					<label for="wicp-search-q"><?php esc_html_e( 'Search every transcript in this course', 'wic-tp' ); ?></label>
					<div class="wicp-search__row">
						<input type="search" id="wicp-search-q" autocomplete="off">
						<button type="submit" class="wicp-btn"><?php esc_html_e( 'Search', 'wic-tp' ); ?></button>
					</div>
				</form>
				<div id="wicp-search-results" class="wicp-search__results" aria-live="polite"></div>
				<div id="wicp-transcript" class="wicp-transcript"></div>
			</div>
			<div role="tabpanel" id="wicp-panel-notes" aria-labelledby="wicp-tab-notes" hidden>
				<div class="wicp-notes">
					<button type="button" class="wicp-btn" id="wicp-bookmark" aria-pressed="false"><?php esc_html_e( 'Bookmark this slide', 'wic-tp' ); ?></button>
					<label for="wicp-note"><?php esc_html_e( 'Your private note on this slide', 'wic-tp' ); ?></label>
					<textarea id="wicp-note" rows="5"></textarea>
					<p class="wicp-help" id="wicp-note-status" role="status"></p>
					<h3><?php esc_html_e( 'In this course', 'wic-tp' ); ?></h3>
					<ul id="wicp-note-list" class="wicp-note-list"></ul>
				</div>
			</div>
			<?php if ( ! empty( $data['resources'] ) ) : ?>
				<div role="tabpanel" id="wicp-panel-resources" aria-labelledby="wicp-tab-resources" hidden>
					<ul class="wicp-resources" id="wicp-resources"></ul>
				</div>
			<?php endif; ?>
		</aside>

		<main class="wicp-main">
			<article class="wicp-slide" id="wicp-slide" aria-live="off"></article>

			<div class="wicp-audio" id="wicp-audio-wrap" hidden>
				<audio id="wicp-audio" controls preload="auto"></audio>
				<label for="wicp-speed"><?php esc_html_e( 'Speed', 'wic-tp' ); ?></label>
				<select id="wicp-speed">
					<option value="0.75">0.75×</option>
					<option value="1" selected>1×</option>
					<option value="1.25">1.25×</option>
					<option value="1.5">1.5×</option>
					<option value="2">2×</option>
				</select>
				<label class="wicp-check"><input type="checkbox" id="wicp-auto"> <?php esc_html_e( 'Auto-advance', 'wic-tp' ); ?></label>
			</div>
			<div class="wicp-audio" id="wicp-tts-wrap" hidden>
				<button type="button" class="wicp-btn" id="wicp-tts" aria-pressed="false"><?php esc_html_e( 'Listen', 'wic-tp' ); ?></button>
				<span class="wicp-help"><?php esc_html_e( 'Generated voice, read by your browser — not a recording.', 'wic-tp' ); ?></span>
			</div>

			<p class="wicp-locked" id="wicp-locked" role="status" hidden></p>
			<nav class="wicp-controls" aria-label="<?php esc_attr_e( 'Slide controls', 'wic-tp' ); ?>">
				<button type="button" class="wicp-btn" id="wicp-prev"><span aria-hidden="true">←</span> <?php esc_html_e( 'Back', 'wic-tp' ); ?></button>
				<span class="wicp-pos" id="wicp-pos"></span>
				<button type="button" class="wicp-btn wicp-btn--primary" id="wicp-next"><?php esc_html_e( 'Next', 'wic-tp' ); ?> <span aria-hidden="true">→</span></button>
			</nav>
			<p class="wicp-keys"><?php esc_html_e( 'Keyboard: ← → to move between slides, K to play or pause narration, B to bookmark the slide.', 'wic-tp' ); ?></p>
		</main>
	</div>

	<div class="wicp-lightbox" id="wicp-lightbox" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Enlarged image', 'wic-tp' ); ?>" hidden>
		<button type="button" class="wicp-btn" id="wicp-lightbox-close"><?php esc_html_e( 'Close', 'wic-tp' ); ?></button>
		<img alt="" id="wicp-lightbox-img">
	</div>

	<div class="wicp-sr" aria-live="polite" id="wicp-announce"></div>

	<script>window.WIC_PLAYER = <?php echo wp_json_encode( $data ); ?>;</script>
	<script src="<?php echo esc_url( WIC_TP_URL . 'assets/js/player.js?ver=' . WIC_TP_VERSION ); ?>"></script>
	<?php
	foreach ( (array) glob( WIC_TP_DIR . 'assets/modules/player-*.js' ) as $wic_js ) {
		echo '<script src="' . esc_url( WIC_TP_URL . 'assets/modules/' . basename( $wic_js ) . '?ver=' . filemtime( $wic_js ) ) . '"></script>' . "\n";
	}
	do_action( 'wic_player_footer', $data );
	?>
</body>
</html>
