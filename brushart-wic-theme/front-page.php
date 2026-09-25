<?php
/**
 * Front page: the product, told plainly. Every fact here is true of the build;
 * the only numbers that move are read from the platform's own records.
 */

defined( 'ABSPATH' ) || exit;

get_header();

$bawic_portal = bawic_page_url( 'portal' );
$bawic_verify = bawic_page_url( 'verify' );
$bawic_portal = $bawic_portal ? $bawic_portal : wp_login_url();

$bawic_facts = array(
	array( '164', __( 'functions across 17 areas, each with a build status', 'brushart-wic' ) ),
	array( '5', __( 'compliance states: complete, overdue, coming due, in progress, never assigned', 'brushart-wic' ) ),
	array( '0', __( 'deleted training records — people are deactivated, never deleted', 'brushart-wic' ) ),
	array( 'AA', __( 'WCAG 2.1 built in from the first screen, not retrofitted', 'brushart-wic' ) ),
);

$bawic_roles = array(
	array(
		'icon'  => 'shield',
		'title' => __( 'State administrators', 'brushart-wic' ),
		'items' => array(
			__( 'One management-evaluation report: every person, every required course', 'brushart-wic' ),
			__( 'Assign by person, clinic, role or everyone, with due dates', 'brushart-wic' ),
			__( 'Audit log of who did what, and when', 'brushart-wic' ),
		),
	),
	array(
		'icon'  => 'people',
		'title' => __( 'Supervisors', 'brushart-wic' ),
		'items' => array(
			__( 'Who is behind, worst first, filtered by clinic', 'brushart-wic' ),
			__( 'Remind everyone late at once, or nudge one person', 'brushart-wic' ),
			__( 'Approve registrations and extend due dates with a reason', 'brushart-wic' ),
		),
	),
	array(
		'icon'  => 'user',
		'title' => __( 'Staff', 'brushart-wic' ),
		'items' => array(
			__( 'Pick up exactly where you left off, on any device', 'brushart-wic' ),
			__( 'Transcript, narration speed and full screen in every lesson', 'brushart-wic' ),
			__( 'Certificates you can reprint any time', 'brushart-wic' ),
		),
	),
	array(
		'icon'  => 'pen',
		'title' => __( 'Content authors', 'brushart-wic' ),
		'items' => array(
			__( 'Edit wording, questions, images and slide order in the portal', 'brushart-wic' ),
			__( 'Draft, preview as a learner, then publish', 'brushart-wic' ),
			__( 'Publishing is blocked until every image has real alt text', 'brushart-wic' ),
		),
	),
	array(
		'icon'  => 'layers',
		'title' => __( 'Local agencies', 'brushart-wic' ),
		'items' => array(
			__( 'Your own name, colours, logo and web address', 'brushart-wic' ),
			__( 'Local administrators who manage only their own clinics', 'brushart-wic' ),
			__( 'Your data kept separate from every other agency', 'brushart-wic' ),
		),
	),
	array(
		'icon'  => 'store',
		'title' => __( 'Vendors', 'brushart-wic' ),
		'items' => array(
			__( 'Separate sign-in and application for authorised stores', 'brushart-wic' ),
			__( 'Annual training that resets each calendar year', 'brushart-wic' ),
			__( 'A certificate code an inspector can check', 'brushart-wic' ),
		),
	),
);

$bawic_areas = array(
	array( 'user', __( 'Accounts and access', 'brushart-wic' ), __( 'Self-registration with supervisor approval, clinics as records, two-factor sign-in.', 'brushart-wic' ) ),
	array( 'book', __( 'Courses and authoring', 'brushart-wic' ), __( 'Course, module and slide, with versions pinned for anyone mid-course.', 'brushart-wic' ) ),
	array( 'play', __( 'The lesson player', 'brushart-wic' ), __( 'Layers, narration, transcript, deep links and resume in the browser.', 'brushart-wic' ) ),
	array( 'check', __( 'Questions and scoring', 'brushart-wic' ), __( 'Five question types, scored on the server, drag-and-drop without a mouse.', 'brushart-wic' ) ),
	array( 'globe', __( 'Languages and narration', 'brushart-wic' ), __( 'Switch language mid-course; changed English flags the Spanish for review.', 'brushart-wic' ) ),
	array( 'award', __( 'Records and certificates', 'brushart-wic' ), __( 'Snapshot certificates with a QR code and a public check page.', 'brushart-wic' ) ),
	array( 'list', __( 'Assignments', 'brushart-wic' ), __( 'Rules by role and location, not-applicable with a reason, weekly digests.', 'brushart-wic' ) ),
	array( 'chart', __( 'Compliance and reporting', 'brushart-wic' ), __( 'Requirement crosswalk, certification gaps, CSV and PDF exports.', 'brushart-wic' ) ),
	array( 'pen', __( 'Signed forms', 'brushart-wic' ), __( 'Acknowledgements signed against a specific version of each policy.', 'brushart-wic' ) ),
	array( 'people', __( 'Classroom and competency', 'brushart-wic' ), __( 'Sessions with rosters, and checklists signed off on a phone.', 'brushart-wic' ) ),
	array( 'folder', __( 'Resources and job aids', 'brushart-wic' ), __( 'Searchable library with version history and who-opened-what.', 'brushart-wic' ) ),
	array( 'refresh', __( 'Making training stick', 'brushart-wic' ), __( 'Short refreshers spaced to each person, reported apart from completion.', 'brushart-wic' ) ),
	array( 'store', __( 'Vendor portal', 'brushart-wic' ), __( 'Application, approval and yearly training for authorised stores.', 'brushart-wic' ) ),
	array( 'layers', __( 'Multi-agency set-up', 'brushart-wic' ), __( 'One shared course library; each agency keeps its own brand and data.', 'brushart-wic' ) ),
	array( 'access', __( 'Accessibility', 'brushart-wic' ), __( 'Keyboard alone, text enlargement, captions and nothing by colour alone.', 'brushart-wic' ) ),
	array( 'export', __( 'Portability', 'brushart-wic' ), __( 'Spreadsheet export from day one, xAPI feed and cmi5 packages.', 'brushart-wic' ) ),
);

$bawic_steps = array(
	array( __( 'Set up the agency', 'brushart-wic' ), __( 'Name, logo, colours, clinics and supervisors — or copy an existing agency’s settings.', 'brushart-wic' ) ),
	array( __( 'Bring the training in', 'brushart-wic' ), __( 'Import existing courses or build them in the portal from reusable slide templates.', 'brushart-wic' ) ),
	array( __( 'Staff learn, supervisors see', 'brushart-wic' ), __( 'Required courses assign themselves by role and clinic; overdue work rises to the top.', 'brushart-wic' ) ),
	array( __( 'Prove it at evaluation', 'brushart-wic' ), __( 'One report shows every person against every requirement, ready as a spreadsheet or PDF.', 'brushart-wic' ) ),
);

$bawic_numbers = bawic_live_numbers();
$bawic_has_num = array_sum( wp_list_pluck( $bawic_numbers, 0 ) ) > 0;

$bawic_news = post_type_exists( 'wic_news' ) ? get_posts(
	array(
		'post_type'   => 'wic_news',
		'numberposts' => 3,
		'post_status' => 'publish',
	)
) : array();
?>

<section class="ba-hero" aria-labelledby="ba-hero-title">
	<svg class="ba-hero__bands" viewBox="0 0 1440 640" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">
		<path d="M820 640 L1160 0 H1440 V120 L1090 640 Z" fill="#1B3350"/>
		<path d="M1010 640 L1320 60 H1440 V180 L1190 640 Z" fill="#C22227" opacity=".92"/>
		<path d="M1180 640 L1440 190 V360 L1300 640 Z" fill="#ffffff" opacity=".08"/>
		<g fill="#ffffff" opacity=".14">
			<?php for ( $bawic_y = 0; $bawic_y < 6; $bawic_y++ ) : ?>
				<?php for ( $bawic_x = 0; $bawic_x < 8; $bawic_x++ ) : ?>
					<circle cx="<?php echo (int) ( 60 + $bawic_x * 22 ); ?>" cy="<?php echo (int) ( 470 + $bawic_y * 22 ); ?>" r="2"/>
				<?php endfor; ?>
			<?php endfor; ?>
		</g>
	</svg>
	<div class="ba-wrap ba-hero__grid">
		<div class="ba-hero__copy">
			<p class="ba-kicker ba-kicker--light"><?php esc_html_e( 'WIC staff training platform', 'brushart-wic' ); ?></p>
			<h1 id="ba-hero-title" class="ba-hero__title"><?php esc_html_e( 'Training every WIC agency can prove.', 'brushart-wic' ); ?></h1>
			<p class="ba-hero__lede"><?php esc_html_e( 'Courses staff actually finish, records that survive an audit, and one report for the management evaluation — in a portal each agency brands as its own.', 'brushart-wic' ); ?></p>
			<p class="ba-actions">
				<a class="ba-btn ba-btn--red ba-btn--lg" href="<?php echo esc_url( $bawic_portal ); ?>"><?php esc_html_e( 'Explore the portal', 'brushart-wic' ); ?></a>
				<?php if ( $bawic_verify ) : ?>
					<a class="ba-btn ba-btn--ghost ba-btn--lg" href="<?php echo esc_url( $bawic_verify ); ?>"><?php esc_html_e( 'Verify a certificate', 'brushart-wic' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<div class="ba-hero__art" aria-hidden="true">
			<div class="ba-mock ba-mock--course">
				<div class="ba-mock__bar"><span></span><span></span><span></span></div>
				<p class="ba-mock__kicker">Module 2 · Graded</p>
				<p class="ba-mock__title">Serving every family fairly</p>
				<div class="ba-mock__lines"><span></span><span></span><span class="is-short"></span></div>
				<div class="ba-mock__options">
					<span class="ba-mock__opt"><i></i>Offer the alternate format</span>
					<span class="ba-mock__opt is-picked"><i></i>Record the request</span>
					<span class="ba-mock__opt"><i></i>Refer to the supervisor</span>
				</div>
				<div class="ba-mock__progress"><span style="width:64%"></span></div>
				<p class="ba-mock__foot">Slide 9 of 14 · about 6 min left</p>
			</div>
			<div class="ba-mock ba-mock--cert">
				<p class="ba-mock__kicker">Certificate of completion</p>
				<p class="ba-mock__name">Staff member</p>
				<div class="ba-mock__lines"><span></span><span class="is-short"></span></div>
				<div class="ba-mock__qr">
					<?php for ( $bawic_i = 0; $bawic_i < 25; $bawic_i++ ) : ?>
						<i class="<?php echo in_array( $bawic_i, array( 0, 1, 3, 5, 7, 8, 11, 12, 14, 16, 18, 19, 21, 23, 24 ), true ) ? 'on' : ''; ?>"></i>
					<?php endfor; ?>
				</div>
				<p class="ba-mock__valid"><span aria-hidden="true">✓</span> Valid</p>
			</div>
		</div>
	</div>
</section>

<section class="ba-facts" aria-label="<?php esc_attr_e( 'Product facts', 'brushart-wic' ); ?>">
	<div class="ba-wrap">
		<ul class="ba-facts__list">
			<?php foreach ( $bawic_facts as $bawic_f ) : ?>
				<li class="ba-fact">
					<span class="ba-fact__n"><?php echo esc_html( $bawic_f[0] ); ?></span>
					<span class="ba-fact__l"><?php echo esc_html( $bawic_f[1] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>

<section class="ba-section" aria-labelledby="ba-roles-title">
	<div class="ba-wrap">
		<header class="ba-section__head">
			<p class="ba-kicker"><?php esc_html_e( 'Who it is for', 'brushart-wic' ); ?></p>
			<h2 id="ba-roles-title" class="ba-h2"><?php esc_html_e( 'Built for everyone in the program', 'brushart-wic' ); ?></h2>
			<p class="ba-section__lede"><?php esc_html_e( 'Three views, not one report with filters: a learner sees what they owe, a supervisor sees who is behind, an administrator sees the whole agency.', 'brushart-wic' ); ?></p>
		</header>
		<div class="ba-roles">
			<?php foreach ( $bawic_roles as $bawic_r ) : ?>
				<article class="ba-card ba-role">
					<span class="ba-role__icon"><?php echo wp_kses( bawic_icon( $bawic_r['icon'] ), bawic_icon_kses() ); ?></span>
					<h3 class="ba-card__title"><?php echo esc_html( $bawic_r['title'] ); ?></h3>
					<ul class="ba-ticks">
						<?php foreach ( $bawic_r['items'] as $bawic_item ) : ?>
							<li><?php echo esc_html( $bawic_item ); ?></li>
						<?php endforeach; ?>
					</ul>
				</article>
			<?php endforeach; ?>
		</div>
	</div>
</section>

<section class="ba-section ba-section--soft" aria-labelledby="ba-areas-title">
	<div class="ba-wrap">
		<header class="ba-section__head">
			<p class="ba-kicker"><?php esc_html_e( 'What is inside', 'brushart-wic' ); ?></p>
			<h2 id="ba-areas-title" class="ba-h2"><?php esc_html_e( 'Sixteen areas, one platform', 'brushart-wic' ); ?></h2>
			<p class="ba-section__lede"><?php esc_html_e( 'The same sections as the merged functionality list, so what you see here maps straight onto the scope.', 'brushart-wic' ); ?></p>
		</header>
		<ul class="ba-areas">
			<?php foreach ( $bawic_areas as $bawic_a ) : ?>
				<li class="ba-area">
					<span class="ba-area__icon"><?php echo wp_kses( bawic_icon( $bawic_a[0] ), bawic_icon_kses() ); ?></span>
					<h3 class="ba-area__title"><?php echo esc_html( $bawic_a[1] ); ?></h3>
					<p class="ba-area__text"><?php echo esc_html( $bawic_a[2] ); ?></p>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>

<section class="ba-section" aria-labelledby="ba-steps-title">
	<div class="ba-wrap">
		<header class="ba-section__head">
			<p class="ba-kicker"><?php esc_html_e( 'How it works', 'brushart-wic' ); ?></p>
			<h2 id="ba-steps-title" class="ba-h2"><?php esc_html_e( 'From set-up to evaluation day', 'brushart-wic' ); ?></h2>
		</header>
		<ol class="ba-steps">
			<?php foreach ( $bawic_steps as $bawic_n => $bawic_s ) : ?>
				<li class="ba-step">
					<span class="ba-step__n" aria-hidden="true"><?php echo esc_html( sprintf( '%02d', $bawic_n + 1 ) ); ?></span>
					<h3 class="ba-step__title"><?php echo esc_html( $bawic_s[0] ); ?></h3>
					<p><?php echo esc_html( $bawic_s[1] ); ?></p>
				</li>
			<?php endforeach; ?>
		</ol>
	</div>
</section>

<?php if ( $bawic_has_num ) : ?>
	<section class="ba-numbers" aria-labelledby="ba-numbers-title">
		<div class="ba-wrap">
			<header class="ba-section__head ba-section__head--light">
				<p class="ba-kicker ba-kicker--light"><?php esc_html_e( 'On this demonstration site', 'brushart-wic' ); ?></p>
				<h2 id="ba-numbers-title" class="ba-h2"><?php esc_html_e( 'Counted from the live records', 'brushart-wic' ); ?></h2>
			</header>
			<dl class="ba-numbers__list">
				<?php foreach ( $bawic_numbers as $bawic_num ) : ?>
					<div class="ba-number">
						<dt><?php echo esc_html( $bawic_num[1] ); ?></dt>
						<dd><?php echo esc_html( number_format_i18n( $bawic_num[0] ) ); ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
			<p class="ba-numbers__note"><?php esc_html_e( 'Sample accounts and sample content, generated for this demonstration.', 'brushart-wic' ); ?></p>
		</div>
	</section>
<?php endif; ?>

<?php if ( $bawic_news ) : ?>
	<section class="ba-section" aria-labelledby="ba-news-title">
		<div class="ba-wrap">
			<header class="ba-section__head ba-section__head--row">
				<div>
					<p class="ba-kicker"><?php esc_html_e( 'News', 'brushart-wic' ); ?></p>
					<h2 id="ba-news-title" class="ba-h2"><?php esc_html_e( 'Latest from the program', 'brushart-wic' ); ?></h2>
				</div>
				<?php $bawic_archive = get_post_type_archive_link( 'wic_news' ); ?>
				<?php if ( $bawic_archive ) : ?>
					<a class="ba-link-arrow" href="<?php echo esc_url( $bawic_archive ); ?>"><?php esc_html_e( 'All news', 'brushart-wic' ); ?></a>
				<?php endif; ?>
			</header>
			<div class="ba-cards">
				<?php foreach ( $bawic_news as $bawic_post ) : ?>
					<article class="ba-card ba-card--post">
						<p class="ba-card__meta"><time datetime="<?php echo esc_attr( get_the_date( 'c', $bawic_post ) ); ?>"><?php echo esc_html( get_the_date( '', $bawic_post ) ); ?></time></p>
						<h3 class="ba-card__title">
							<?php if ( is_post_type_viewable( 'wic_news' ) ) : ?>
								<a href="<?php echo esc_url( get_permalink( $bawic_post ) ); ?>"><?php echo esc_html( get_the_title( $bawic_post ) ); ?></a>
							<?php else : ?>
								<?php echo esc_html( get_the_title( $bawic_post ) ); ?>
							<?php endif; ?>
						</h3>
						<p class="ba-card__text"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $bawic_post->post_excerpt ? $bawic_post->post_excerpt : $bawic_post->post_content ), 24 ) ); ?></p>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
<?php endif; ?>

<section class="ba-cta" aria-labelledby="ba-cta-title">
	<div class="ba-wrap ba-cta__inner">
		<div>
			<h2 id="ba-cta-title" class="ba-cta__title"><?php esc_html_e( 'See it working.', 'brushart-wic' ); ?></h2>
			<p><?php esc_html_e( 'Sign in with a demonstration account and walk through the learner, supervisor and administrator views.', 'brushart-wic' ); ?></p>
		</div>
		<p class="ba-actions">
			<a class="ba-btn ba-btn--white ba-btn--lg" href="<?php echo esc_url( $bawic_portal ); ?>"><?php esc_html_e( 'Open the training portal', 'brushart-wic' ); ?></a>
			<a class="ba-btn ba-btn--ghost ba-btn--lg" href="https://www.brushart.com" rel="noopener"><?php esc_html_e( 'About Brush Art', 'brushart-wic' ); ?></a>
		</p>
	</div>
</section>

<?php
get_footer();
