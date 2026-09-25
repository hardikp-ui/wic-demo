<?php
/**
 * Certificate: rendered from the stored snapshot. One template for screen and print.
 * Variables in scope: $cert (row), $state (valid|expired|revoked).
 *
 * Print fills whatever landscape page the printer uses (Letter or A4): the page box is
 * set to landscape with no margin and the certificate is sized to the page, not to mm.
 */

defined( 'ABSPATH' ) || exit;

$verify_url = WIC_Certificates::verify_url( $cert );
$hours      = (float) $cert->hours > 0 ? rtrim( rtrim( number_format( (float) $cert->hours, 2 ), '0' ), '.' ) : '';
$signatory  = wic_setting( 'signatory_name' ) ? wic_setting( 'signatory_name' ) : wic_setting( 'name' );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex">
	<title><?php echo esc_html( sprintf( __( 'Certificate %s', 'wic-tp' ), $cert->cert_number ) ); ?></title>
	<?php
	/** Fonts and other head tags (the design-fonts module prints the agency type here). */
	do_action( 'wic_certificate_head', $cert );
	?>
	<style>
		<?php echo WIC_Agency::tokens_css(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		:root { --c-primary: var(--wic-primary, #112337); --c-accent: var(--wic-accent, #c22227); --c-ink: var(--wic-ink, #1d2430); --c-muted: #5b6270; --c-line: #dde1e7; --c-head: var(--wic-font-heading, Georgia, "Times New Roman", serif); --c-body: var(--wic-font-body, system-ui, -apple-system, "Segoe UI", Arial, sans-serif); --c-stretch: var(--wic-heading-stretch, 100%); }
		@page { size: landscape; margin: 0; }
		* { box-sizing: border-box; }
		html, body { margin: 0; }
		body { font-family: var(--c-body); color: var(--c-ink); background: #e9ecf1; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
		:focus-visible { outline: 3px solid var(--c-accent); outline-offset: 2px; }
		.bar { display: flex; gap: .75rem; justify-content: center; padding: 1.25rem 1rem; flex-wrap: wrap; }
		.bar a, .bar button { font: inherit; font-weight: 700; font-size: .95rem; padding: .65rem 1.25rem; min-height: 2.75rem; border-radius: 8px; border: 1.5px solid var(--c-primary); background: #fff; color: var(--c-primary); text-decoration: none; cursor: pointer; display: inline-flex; align-items: center; }
		.bar button { background: var(--c-accent); border-color: var(--c-accent); color: #fff; }
		.warn { max-width: 60rem; margin: 0 auto 1rem; padding: .85rem 1.1rem; border-radius: 8px; background: #fbe9e7; color: #b3261e; border: 1px solid #f1c4bf; border-left: 4px solid #b3261e; font-weight: 600; }
		.sheet { width: min(279mm, calc(100% - 2rem)); aspect-ratio: 11 / 8.5; margin: 0 auto 2.5rem; background: #fff; box-shadow: 0 2px 6px rgba(17, 35, 55, .08), 0 20px 50px rgba(17, 35, 55, .16); position: relative; overflow: hidden; }
		.cert { position: absolute; inset: 0; display: grid; grid-template-rows: auto 1fr auto; padding: 5.5% 7% 4.5%; isolation: isolate; }
		/* Frame: a primary rule and an accent hairline inside it. */
		.cert::before { content: ""; position: absolute; inset: 2.2%; border: 2px solid var(--c-primary); z-index: -1; }
		.cert::after { content: ""; position: absolute; inset: calc(2.2% + 7px); border: 1px solid var(--c-accent); z-index: -1; }
		/* Brush-stroke bands in the corner, echoing the agency accent. */
		/* Kept to the outer margin so they never cross the number, the signatory or the QR. */
		.band { position: absolute; z-index: 0; right: -7%; top: -0.6%; width: 30%; height: 2.2%; background: var(--c-primary); transform: rotate(-6deg); border-radius: 999px; pointer-events: none; }
		.band--2 { top: 2.2%; right: -4%; width: 18%; height: 1.2%; background: var(--c-accent); }
		.band--3 { top: auto; right: auto; left: -7%; bottom: 0.4%; width: 26%; height: 1.4%; background: var(--c-accent); transform: rotate(-6deg); }
		.top { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; }
		.top img { max-height: 42px; max-width: 45%; width: auto; }
		.agency { font-family: var(--c-head); font-stretch: var(--c-stretch); font-weight: 800; color: var(--c-primary); letter-spacing: .06em; text-transform: uppercase; font-size: .95rem; }
		.number { font-size: .72rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--c-muted); text-align: right; }
		.number b { display: block; color: var(--c-ink); font-size: .85rem; letter-spacing: .06em; margin-top: .15rem; }
		.middle { display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; padding: 1% 0; }
		.kicker { font-family: var(--c-head); font-stretch: var(--c-stretch); font-size: .8rem; font-weight: 700; letter-spacing: .32em; text-transform: uppercase; color: var(--c-accent); margin: 0 0 .75rem; }
		.title { font-family: var(--c-head); font-stretch: var(--c-stretch); font-size: clamp(1.4rem, 3.4vw, 2.6rem); font-weight: 800; color: var(--c-primary); margin: 0; letter-spacing: -.01em; line-height: 1.1; }
		.lead { margin: 1.1rem 0 .4rem; color: var(--c-muted); font-size: 1rem; }
		.name { font-family: var(--c-head); font-stretch: var(--c-stretch); font-size: clamp(1.8rem, 4.8vw, 3.4rem); font-weight: 800; line-height: 1.1; color: var(--c-ink); margin: 0; padding: 0 1.5rem .6rem; border-bottom: 2px solid var(--c-accent); max-width: 90%; overflow-wrap: anywhere; }
		.course { margin: 1rem 0 0; font-size: 1rem; color: var(--c-muted); }
		.course strong { display: block; font-family: var(--c-head); font-stretch: var(--c-stretch); color: var(--c-primary); font-size: clamp(1.1rem, 2.2vw, 1.6rem); margin-top: .35rem; line-height: 1.2; }
		.facts { display: flex; justify-content: center; gap: 0; margin-top: 1.5rem; flex-wrap: wrap; }
		.facts div { text-align: center; padding: 0 1.4rem; border-left: 1px solid var(--c-line); font-weight: 600; font-size: .95rem; }
		.facts div:first-child { border-left: 0; }
		.facts span { display: block; font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .14em; color: var(--c-muted); margin-bottom: .2rem; }
		.bottom { display: flex; justify-content: space-between; align-items: flex-end; gap: 2rem; font-size: .8rem; }
		.sig { min-width: 15rem; }
		.sig__line { border-top: 1.5px solid var(--c-ink); padding-top: .45rem; }
		.sig b { display: block; font-size: .9rem; }
		.sig span { color: var(--c-muted); }
		.verify { display: flex; gap: .9rem; align-items: flex-end; text-align: right; color: var(--c-muted); line-height: 1.45; }
		.verify b { color: var(--c-ink); }
		.verify code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .95rem; font-weight: 700; letter-spacing: .1em; color: var(--c-ink); }
		#qr { width: 84px; height: 84px; padding: 4px; background: #fff; border: 1px solid var(--c-line); }
		#qr img, #qr canvas { width: 100% !important; height: 100% !important; }
		.revoked { position: absolute; inset: 0; display: grid; place-items: center; font-family: var(--c-head); font-size: clamp(3rem, 10vw, 7rem); font-weight: 800; letter-spacing: .1em; color: rgba(179, 38, 30, .2); transform: rotate(-16deg); pointer-events: none; z-index: 2; }
		@media print {
			html, body { background: #fff; width: 100%; height: 100%; }
			.bar, .warn { display: none !important; }
			.sheet { width: 100vw; height: 100vh; aspect-ratio: auto; margin: 0; box-shadow: none; page-break-after: avoid; break-after: avoid; }
		}
		@media (max-width: 760px) {
			.sheet { aspect-ratio: auto; }
			.cert { position: relative; padding: 2.25rem 1.5rem 1.75rem; gap: 1.5rem; }
			.bottom { flex-direction: column; align-items: flex-start; gap: 1.25rem; }
			.verify { text-align: left; flex-direction: row-reverse; justify-content: flex-end; }
			.facts div { padding: .4rem .9rem; }
		}
		@media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
	</style>
</head>
<body>
	<div class="bar">
		<a href="<?php echo esc_url( wic_page_url( 'portal', array( 'view' => 'certificates' ) ) ); ?>"><?php esc_html_e( 'Back to the portal', 'wic-tp' ); ?></a>
		<button type="button" onclick="window.print()"><?php esc_html_e( 'Print or save as PDF', 'wic-tp' ); ?></button>
	</div>
	<?php if ( 'valid' !== $state ) : ?>
		<p class="warn" role="status"><?php echo 'revoked' === $state ? esc_html__( 'This certificate has been revoked and is no longer valid.', 'wic-tp' ) : esc_html__( 'This certificate has expired. Recertification is due.', 'wic-tp' ); ?></p>
	<?php endif; ?>
	<div class="sheet">
		<main class="cert">
			<span class="band" aria-hidden="true"></span><span class="band band--2" aria-hidden="true"></span><span class="band band--3" aria-hidden="true"></span>
			<?php if ( 'revoked' === $state ) : ?><div class="revoked" aria-hidden="true"><?php esc_html_e( 'REVOKED', 'wic-tp' ); ?></div><?php endif; ?>
			<div class="top">
				<?php if ( wic_setting( 'logo_url' ) ) : ?>
					<img src="<?php echo esc_url( wic_setting( 'logo_url' ) ); ?>" alt="<?php echo esc_attr( wic_setting( 'name' ) ); ?>">
				<?php else : ?>
					<span class="agency"><?php echo esc_html( wic_setting( 'name' ) ); ?></span>
				<?php endif; ?>
				<div class="number"><?php esc_html_e( 'Certificate number', 'wic-tp' ); ?><b><?php echo esc_html( $cert->cert_number ); ?></b></div>
			</div>
			<div class="middle">
				<p class="kicker"><?php esc_html_e( 'Certificate of completion', 'wic-tp' ); ?></p>
				<h1 class="title"><?php echo esc_html( wic_setting( 'name' ) ); ?></h1>
				<p class="lead"><?php esc_html_e( 'This certifies that', 'wic-tp' ); ?></p>
				<p class="name"><?php echo esc_html( $cert->learner_name ); ?></p>
				<p class="course"><?php esc_html_e( 'has successfully completed', 'wic-tp' ); ?> <strong><?php echo esc_html( $cert->course_title ); ?></strong></p>
				<div class="facts">
					<div><span><?php esc_html_e( 'Completed', 'wic-tp' ); ?></span><?php echo esc_html( wic_format_date( $cert->issued_at ) ); ?></div>
					<div><span><?php esc_html_e( 'Score', 'wic-tp' ); ?></span><?php echo (int) $cert->score; ?>%</div>
					<?php if ( $hours ) : ?>
						<div><span><?php esc_html_e( 'Hours', 'wic-tp' ); ?></span><?php echo esc_html( $hours ); ?></div>
					<?php endif; ?>
					<?php if ( $cert->credit_type ) : ?>
						<div><span><?php esc_html_e( 'Credit', 'wic-tp' ); ?></span><?php echo esc_html( $cert->credit_type ); ?></div>
					<?php endif; ?>
					<?php if ( $cert->expires_at ) : ?>
						<div><span><?php esc_html_e( 'Valid until', 'wic-tp' ); ?></span><?php echo esc_html( wic_format_date( $cert->expires_at ) ); ?></div>
					<?php endif; ?>
				</div>
			</div>
			<div class="bottom">
				<div class="sig">
					<div class="sig__line">
						<b><?php echo esc_html( $signatory ); ?></b>
						<span><?php echo esc_html( wic_setting( 'signatory_title' ) ); ?></span>
					</div>
				</div>
				<div class="verify">
					<div>
						<?php esc_html_e( 'Verify this certificate at', 'wic-tp' ); ?><br>
						<b><?php echo esc_html( preg_replace( '#^https?://#', '', untrailingslashit( wic_page_url( 'verify' ) ) ) ); ?></b><br>
						<?php esc_html_e( 'Code', 'wic-tp' ); ?> <code><?php echo esc_html( $cert->verify_code ); ?></code>
					</div>
					<div id="qr" role="img" aria-label="<?php esc_attr_e( 'QR code linking to the verification page', 'wic-tp' ); ?>"></div>
				</div>
			</div>
		</main>
	</div>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
	<script>
		if (window.QRCode) {
			new QRCode(document.getElementById('qr'), { text: <?php echo wp_json_encode( $verify_url ); ?>, width: 152, height: 152, correctLevel: QRCode.CorrectLevel.M });
		}
	</script>
</body>
</html>
