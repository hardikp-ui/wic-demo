<?php
/**
 * The portal: three views, not one report with filters.
 * A learner sees what they owe; a supervisor sees who on their team is behind;
 * an administrator sees the whole agency.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Portal {

	public static function init() {
		add_shortcode( 'wic_portal', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	public static function maybe_enqueue() {
		foreach ( apply_filters( 'wic_portal_style_pages', array( 'portal', 'register', 'verify' ) ) as $key ) {
			if ( wic_page_id( $key ) && is_page( wic_page_id( $key ) ) ) {
				self::enqueue();
			}
		}
	}

	/** Core portal assets, then every module stylesheet and script in assets/modules/. */
	public static function enqueue() {
		if ( wp_style_is( 'wic-portal', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style( 'wic-portal', WIC_TP_URL . 'assets/css/portal.css', array(), WIC_TP_VERSION );
		wp_add_inline_style( 'wic-portal', WIC_Agency::tokens_css() );
		wp_enqueue_script( 'wic-portal', WIC_TP_URL . 'assets/js/portal.js', array(), WIC_TP_VERSION, true );
		foreach ( (array) glob( WIC_TP_DIR . 'assets/modules/*.css' ) as $f ) {
			wp_enqueue_style( 'wic-mod-' . basename( $f, '.css' ), WIC_TP_URL . 'assets/modules/' . basename( $f ), array( 'wic-portal' ), WIC_TP_VERSION . '-' . filemtime( $f ) );
		}
		foreach ( (array) glob( WIC_TP_DIR . 'assets/modules/*.js' ) as $f ) {
			wp_enqueue_script( 'wic-mod-' . basename( $f, '.js' ), WIC_TP_URL . 'assets/modules/' . basename( $f ), array( 'wic-portal' ), WIC_TP_VERSION . '-' . filemtime( $f ), true );
		}
		wp_localize_script(
			'wic-portal',
			'WIC_PORTAL',
			array(
				'rest'  => esc_url_raw( rest_url( WIC_Rest::NS ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'post'  => esc_url_raw( admin_url( 'admin-post.php' ) ),
			)
		);
		do_action( 'wic_portal_enqueue' );
	}

	private static function url( $view, $args = array() ) {
		return wic_portal_url( $view, $args );
	}

	/**
	 * Navigation groups: what the person is doing, not which report they want.
	 * Modules may add a group through `wic_portal_groups`.
	 */
	public static function groups() {
		return apply_filters(
			'wic_portal_groups',
			array(
				'learn'     => array( 'label' => __( 'My learning', 'wic-tp' ), 'order' => 10 ),
				'resources' => array( 'label' => __( 'Resources', 'wic-tp' ), 'order' => 15 ),
				'team'      => array( 'label' => __( 'My team', 'wic-tp' ), 'order' => 20 ),
				'agency'    => array( 'label' => __( 'Agency', 'wic-tp' ), 'order' => 30 ),
				'author'    => array( 'label' => __( 'Authoring', 'wic-tp' ), 'order' => 40 ),
				'account'   => array( 'label' => __( 'Account', 'wic-tp' ), 'order' => 90 ),
			)
		);
	}

	/**
	 * Every portal view. Modules register theirs through `wic_portal_views`:
	 *
	 *   $views['library'] = array(
	 *     'label'    => 'Library',
	 *     'group'    => 'learn',                 // learn | team | agency | author
	 *     'cap'      => 'wic_learn',             // capability string, or callable( $uid ) returning bool
	 *     'callback' => array( 'WIC_Library', 'view' ),  // receives $uid, echoes HTML
	 *     'order'    => 50,
	 *     'badge'    => callable( $uid ) returning int (optional),
	 *     'hidden'   => false,                   // true = reachable by URL but not in the menu
	 *   );
	 */
	public static function views() {
		$views = array(
			'home'          => array( 'label' => __( 'Home', 'wic-tp' ), 'group' => 'learn', 'cap' => 'wic_learn', 'callback' => array( __CLASS__, 'view_home' ), 'order' => 10 ),
			'training'      => array( 'label' => __( 'My training', 'wic-tp' ), 'group' => 'learn', 'cap' => 'wic_learn', 'callback' => array( __CLASS__, 'view_training' ), 'order' => 20 ),
			'certificates'  => array( 'label' => __( 'Certificates', 'wic-tp' ), 'group' => 'learn', 'cap' => 'wic_learn', 'callback' => array( __CLASS__, 'view_certificates' ), 'order' => 30 ),
			'notifications' => array(
				'label'    => __( 'Notifications', 'wic-tp' ),
				'group'    => 'learn',
				'cap'      => 'read',
				'callback' => array( __CLASS__, 'view_notifications' ),
				'order'    => 90,
				'badge'    => array( 'WIC_Notify', 'unread_count' ),
			),
			'team'          => array( 'label' => __( 'Team progress', 'wic-tp' ), 'group' => 'team', 'cap' => 'wic_view_team', 'callback' => array( __CLASS__, 'view_team' ), 'order' => 10 ),
			'overdue'       => array( 'label' => __( 'Overdue', 'wic-tp' ), 'group' => 'team', 'cap' => 'wic_view_team', 'callback' => array( __CLASS__, 'view_overdue' ), 'order' => 20 ),
			'approvals'     => array(
				'label'    => __( 'Approvals', 'wic-tp' ),
				'group'    => 'team',
				'cap'      => 'wic_approve',
				'callback' => array( __CLASS__, 'view_approvals' ),
				'order'    => 30,
				'badge'    => function ( $uid ) {
					return count( WIC_Registration::pending_for( $uid ) );
				},
			),
			'agency'        => array( 'label' => __( 'Overview', 'wic-tp' ), 'group' => 'agency', 'cap' => 'wic_view_all', 'callback' => array( __CLASS__, 'view_agency' ), 'order' => 10 ),
		);
		$views = apply_filters( 'wic_portal_views', $views );
		/*
		 * A learner's own training stays in "My learning"; reading material moves to "Resources"
		 * and personal settings to "Account", so no section grows into a long list.
		 */
		$regroup = apply_filters(
			'wic_portal_regroup',
			array(
				'news'          => 'resources',
				'library'       => 'resources',
				'messages'      => 'resources',
				'references'    => 'resources',
				'faq'           => 'resources',
				'formats'       => 'resources',
				'notifications' => 'account',
				'account'       => 'account',
				'my_record'     => 'account',
			)
		);
		foreach ( $regroup as $key => $group ) {
			if ( isset( $views[ $key ] ) && ( ! isset( $views[ $key ]['group'] ) || 'learn' === $views[ $key ]['group'] ) ) {
				$views[ $key ]['group'] = $group;
			}
		}
		uasort(
			$views,
			function ( $a, $b ) {
				return ( isset( $a['order'] ) ? $a['order'] : 50 ) - ( isset( $b['order'] ) ? $b['order'] : 50 );
			}
		);
		return $views;
	}

	public static function can_view( $view, $uid ) {
		if ( empty( $view['cap'] ) ) {
			return true;
		}
		if ( is_callable( $view['cap'] ) && ! is_string( $view['cap'] ) ) {
			return (bool) call_user_func( $view['cap'], $uid );
		}
		return user_can( $uid, $view['cap'] );
	}

	public static function shortcode() {
		self::enqueue();
		if ( ! is_user_logged_in() ) {
			return apply_filters( 'wic_portal_signin', self::signin() );
		}
		$uid     = get_current_user_id();
		$allowed = array_filter(
			self::views(),
			function ( $v ) use ( $uid ) {
				return self::can_view( $v, $uid );
			}
		);
		// Notifications alone is not access to the portal.
		if ( ! array_diff( array_keys( $allowed ), array( 'notifications' ) ) ) {
			return '<div class="wic-portal"><p class="wic-notice">' . esc_html__( 'Your account does not have access to the training portal.', 'wic-tp' ) . '</p></div>';
		}

		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : '';
		if ( ! isset( $allowed[ $view ] ) ) {
			$view = apply_filters( 'wic_portal_default_view', isset( $allowed['home'] ) ? 'home' : key( $allowed ), $uid );
			if ( ! isset( $allowed[ $view ] ) ) {
				$view = key( $allowed );
			}
		}
		$groups  = self::groups();
		$current = isset( $allowed[ $view ]['group'] ) ? $allowed[ $view ]['group'] : 'learn';
		$by_grp  = array();
		foreach ( $allowed as $key => $v ) {
			if ( ! empty( $v['hidden'] ) ) {
				continue;
			}
			$g              = isset( $v['group'], $groups[ $v['group'] ] ) ? $v['group'] : 'learn';
			$by_grp[ $g ][] = $key;
		}
		uksort(
			$by_grp,
			function ( $a, $b ) use ( $groups ) {
				return $groups[ $a ]['order'] - $groups[ $b ]['order'];
			}
		);

		$user      = wp_get_current_user();
		$role      = self::role_label( $user );
		$clinic    = wic_user_clinic_name( $uid );
		$initials  = strtoupper( substr( $user->first_name ? $user->first_name : $user->display_name, 0, 1 ) . substr( $user->last_name ? $user->last_name : '', 0, 1 ) );
		$group_lbl = isset( $groups[ $current ] ) ? $groups[ $current ]['label'] : '';
		ob_start();
		?>
		<div class="wic-portal wic-app">
			<?php $banner = WIC_Agency::announcement(); ?>
			<?php if ( $banner ) : ?>
				<div class="wic-banner" role="region" aria-label="<?php esc_attr_e( 'Announcement', 'wic-tp' ); ?>"><?php echo esc_html( $banner ); ?></div>
			<?php endif; ?>
			<div class="wic-shell" data-wic-shell>
				<aside class="wic-side" id="wic-side" aria-label="<?php esc_attr_e( 'Portal navigation', 'wic-tp' ); ?>">
					<div class="wic-side__brand">
						<a href="<?php echo esc_url( self::url( 'home' ) ); ?>" class="wic-side__logo">
							<?php if ( class_exists( 'WIC_Design_Fonts' ) ) : ?>
								<?php echo WIC_Design_Fonts::logo_on_dark(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the helper. ?>
							<?php elseif ( wic_setting( 'logo_url' ) ) : ?>
								<img class="is-chip" src="<?php echo esc_url( wic_setting( 'logo_url' ) ); ?>" alt="<?php echo esc_attr( wic_setting( 'name' ) ); ?>">
							<?php else : ?>
								<span class="wic-brand__name"><?php echo esc_html( wic_setting( 'name' ) ); ?></span>
							<?php endif; ?>
						</a>
						<span class="wic-side__product"><?php esc_html_e( 'Training portal', 'wic-tp' ); ?></span>
						<button type="button" class="wic-side__close" data-wic-side-close aria-label="<?php esc_attr_e( 'Close menu', 'wic-tp' ); ?>">&times;</button>
					</div>
					<div class="wic-side__me">
						<span class="wic-avatar" aria-hidden="true"><?php echo esc_html( $initials ? $initials : '?' ); ?></span>
						<span class="wic-side__who">
							<strong><?php echo esc_html( $user->display_name ); ?></strong>
							<span><?php echo esc_html( trim( $role . ( $clinic ? ' · ' . $clinic : '' ) ) ); ?></span>
						</span>
					</div>
					<nav class="wic-side__nav" aria-label="<?php esc_attr_e( 'Portal', 'wic-tp' ); ?>">
						<?php foreach ( $by_grp as $g => $keys ) : ?>
							<?php
							$grp_count = 0;
							foreach ( $keys as $k ) {
								$grp_count += ! empty( $allowed[ $k ]['badge'] ) && is_callable( $allowed[ $k ]['badge'] ) ? (int) call_user_func( $allowed[ $k ]['badge'], $uid ) : 0;
							}
							?>
							<?php if ( count( $by_grp ) > 1 ) : ?>
							<details class="wic-side__group<?php echo $g === $current ? ' is-current' : ''; ?>" <?php echo $g === $current ? 'open' : ''; ?>>
								<summary class="wic-side__label">
									<span><?php echo esc_html( $groups[ $g ]['label'] ); ?></span>
									<?php if ( $grp_count && $g !== $current ) : ?>
										<span class="wic-side__dot" aria-label="<?php echo esc_attr( sprintf( _n( '%d item waiting', '%d items waiting', $grp_count, 'wic-tp' ), $grp_count ) ); ?>"><?php echo (int) $grp_count; ?></span>
									<?php endif; ?>
								</summary>
							<?php else : ?>
							<div class="wic-side__group is-current">
							<?php endif; ?>
								<ul>
									<?php foreach ( $keys as $key ) : ?>
										<?php
										$v = $allowed[ $key ];
										$n = ! empty( $v['badge'] ) && is_callable( $v['badge'] ) ? (int) call_user_func( $v['badge'], $uid ) : 0;
										?>
										<li>
											<a href="<?php echo esc_url( self::url( $key ) ); ?>" <?php echo $key === $view ? 'aria-current="page"' : ''; ?>>
												<?php echo self::icon( $key ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG. ?>
												<span class="wic-side__text"><?php echo esc_html( $v['label'] ); ?></span>
												<?php if ( $n ) : ?>
													<span class="wic-count" aria-label="<?php echo esc_attr( sprintf( _n( '%d waiting', '%d waiting', $n, 'wic-tp' ), $n ) ); ?>"><?php echo (int) $n; ?></span>
												<?php endif; ?>
											</a>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php echo count( $by_grp ) > 1 ? '</details>' : '</div>'; ?>
						<?php endforeach; ?>
					</nav>
					<div class="wic-side__foot">
						<a href="<?php echo esc_url( wp_logout_url( wic_page_url( 'portal' ) ) ); ?>">
							<?php echo self::icon( 'signout' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG. ?>
							<span class="wic-side__text"><?php esc_html_e( 'Sign out', 'wic-tp' ); ?></span>
						</a>
					</div>
				</aside>
				<div class="wic-side__scrim" data-wic-side-close hidden></div>

				<div class="wic-main">
					<header class="wic-topbar">
						<button type="button" class="wic-menu-btn" data-wic-side-open aria-controls="wic-side" aria-expanded="false">
							<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
							<span><?php esc_html_e( 'Portal menu', 'wic-tp' ); ?></span>
						</button>
						<p class="wic-crumb">
							<?php if ( $group_lbl && count( $by_grp ) > 1 ) : ?>
								<span class="wic-crumb__group"><?php echo esc_html( $group_lbl ); ?></span>
								<span class="wic-crumb__sep" aria-hidden="true">/</span>
							<?php endif; ?>
							<span class="wic-crumb__view"><?php echo esc_html( $allowed[ $view ]['label'] ); ?></span>
						</p>
						<div class="wic-user wic-topbar__tools">
							<?php do_action( 'wic_portal_header_tools', $uid ); ?>
						</div>
					</header>
					<?php self::flash(); ?>
					<main class="wic-view" id="wic-main">
						<?php call_user_func( $allowed[ $view ]['callback'], $uid ); ?>
					</main>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/** The person's platform role in words, for the sidebar card. */
	private static function role_label( $user ) {
		$names = wp_roles()->role_names;
		foreach ( (array) $user->roles as $r ) {
			if ( 0 === strpos( $r, 'wic_' ) && isset( $names[ $r ] ) ) {
				return trim( str_replace( 'WIC ', '', translate_user_role( $names[ $r ] ) ) );
			}
		}
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return __( 'Administrator', 'wic-tp' );
		}
		return '';
	}

	/**
	 * A small line icon for a navigation item, chosen by the view key so module views get a
	 * sensible icon without registering one. Decorative: hidden from assistive technology.
	 */
	public static function icon( $key ) {
		$paths = array(
			'home'     => '<path d="M4 11l8-7 8 7v8a1 1 0 0 1-1 1h-5v-6h-4v6H5a1 1 0 0 1-1-1z"/>',
			'book'     => '<path d="M5 4h10a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3z"/><path d="M5 17a3 3 0 0 1 3-3h10"/>',
			'award'    => '<circle cx="12" cy="9" r="5"/><path d="M9 13.5L7.5 21l4.5-2.5 4.5 2.5-1.5-7.5"/>',
			'bell'     => '<path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
			'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6.5 6.5 0 0 1 3.5 6"/>',
			'clock'    => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
			'check'    => '<rect x="3.5" y="3.5" width="17" height="17" rx="3"/><path d="M8 12.5l2.8 2.8L16.5 9.5"/>',
			'chart'    => '<path d="M4 20V4M4 20h16"/><path d="M8 16v-5M12 16V8M16 16v-3"/>',
			'building' => '<path d="M4 20V6l8-3 8 3v14"/><path d="M9 20v-5h6v5M8 9h1M15 9h1M8 12h1M15 12h1"/>',
			'calendar' => '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
			'pen'      => '<path d="M4 20l4-1 11-11-3-3L5 16z"/><path d="M14 6l3 3"/>',
			'file'     => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
			'globe'    => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.5 2.5 2.5 14.5 0 17M12 3.5c-2.5 2.5-2.5 14.5 0 17"/>',
			'gear'     => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1"/>',
			'bookmark' => '<path d="M7 3.5h10v17l-5-3.5-5 3.5z"/>',
			'refresh'  => '<path d="M19 12a7 7 0 1 1-2.1-5"/><path d="M19 4v4h-4"/>',
			'route'    => '<circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="6" r="2.5"/><path d="M8.5 18H15a3 3 0 0 0 0-6H9a3 3 0 0 1 0-6h6.5"/>',
			'clip'     => '<rect x="5" y="4.5" width="14" height="16" rx="2"/><path d="M9 3.5h6v3H9zM8.5 12l2 2 4-4M8.5 17h7"/>',
			'store'    => '<path d="M4 9l1.5-5h13L20 9"/><path d="M4 9a2.7 2.7 0 0 0 5.3 0 2.7 2.7 0 0 0 5.4 0A2.7 2.7 0 0 0 20 9"/><path d="M5.5 11v9h13v-9M10 20v-5h4v5"/>',
			'megaphone' => '<path d="M4 10v4h3l8 4V6L7 10z"/><path d="M18 9.5a3.5 3.5 0 0 1 0 5"/>',
			'help'     => '<circle cx="12" cy="12" r="8.5"/><path d="M9.8 9.5a2.3 2.3 0 1 1 3.4 2c-.8.5-1.2 1-1.2 2M12 16.8v.2"/>',
			'upload'   => '<path d="M12 15V4M7.5 8.5L12 4l4.5 4.5"/><path d="M4 15v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4"/>',
			'list'     => '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
			'person'   => '<circle cx="12" cy="8" r="4"/><path d="M4.5 20.5a7.5 7.5 0 0 1 15 0"/>',
			'link'     => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
			'signout'  => '<path d="M14 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4"/><path d="M10 16l-4-4 4-4M6 12h10"/>',
			'dot'      => '<circle cx="12" cy="12" r="3.5"/>',
		);
		// Keyword → icon, first match wins; module view keys pick up the closest meaning.
		$map = array(
			'signout' => 'signout', 'home' => 'home', 'cert' => 'award', 'notif' => 'bell', 'overdue' => 'clock',
			'approv' => 'check', 'vendor' => 'store', 'clinic' => 'building', 'agencies' => 'globe', 'agency' => 'chart',
			'report' => 'chart', 'compliance' => 'chart', 'retention' => 'chart', 'gap' => 'chart', 'usage' => 'chart', 'compare' => 'chart',
			'mentee' => 'users', 'team' => 'users', 'people' => 'users', 'person' => 'person', 'add' => 'person', 'account' => 'person',
			'session' => 'calendar', 'roster' => 'calendar', 'sign' => 'pen', 'form' => 'pen', 'transcript' => 'file', 'request' => 'file',
			'library' => 'book', 'doc' => 'book', 'reference' => 'book', 'message' => 'book', 'training' => 'book', 'course' => 'book',
			'author' => 'pen', 'template' => 'pen', 'editor' => 'pen', 'note' => 'bookmark', 'refresh' => 'refresh', 'boost' => 'refresh',
			'path' => 'route', 'competen' => 'clip', 'observe' => 'clip', 'checklist' => 'clip', 'outside' => 'upload', 'external' => 'upload',
			'import' => 'upload', 'news' => 'megaphone', 'help' => 'help', 'faq' => 'help', 'rule' => 'list', 'notice' => 'list', 'requirement' => 'list',
			'saved' => 'list', 'link' => 'link', 'api' => 'gear', 'key' => 'gear', 'exchange' => 'gear', 'setting' => 'gear', 'format' => 'file',
		);
		$icon = 'dot';
		foreach ( $map as $needle => $name ) {
			if ( false !== strpos( $key, $needle ) ) {
				$icon = $name;
				break;
			}
		}
		$icon = apply_filters( 'wic_portal_icon', $icon, $key );
		$path = isset( $paths[ $icon ] ) ? $paths[ $icon ] : $paths['dot'];
		return '<svg class="wic-ico" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
	}

	/** Redirect back to a portal view with a flash message key. Modules use this from their handlers. */
	public static function back( $view, $msg, $args = array() ) {
		wp_safe_redirect( wic_portal_url( $view, array_merge( $args, array( 'wic_msg' => $msg ) ) ) );
		exit;
	}

	private static function signin() {
		ob_start();
		?>
		<div class="wic-portal wic-signin">
			<div class="wic-signin__grid">
				<div class="wic-signin__brand">
					<?php if ( class_exists( 'WIC_Design_Fonts' ) ) : ?>
						<?php echo WIC_Design_Fonts::logo_on_dark(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the helper. ?>
					<?php elseif ( wic_setting( 'logo_url' ) ) : ?>
						<img class="is-chip" src="<?php echo esc_url( wic_setting( 'logo_url' ) ); ?>" alt="<?php echo esc_attr( wic_setting( 'name' ) ); ?>">
					<?php else : ?>
						<span class="wic-brand__name"><?php echo esc_html( wic_setting( 'name' ) ); ?></span>
					<?php endif; ?>
					<div>
						<span class="wic-kicker"><?php esc_html_e( 'Staff training portal', 'wic-tp' ); ?></span>
						<p class="wic-signin__title"><?php esc_html_e( 'Your training, in one place.', 'wic-tp' ); ?></p>
						<p><?php esc_html_e( 'Pick up where you left off, see what is due, and keep every certificate you earn.', 'wic-tp' ); ?></p>
					</div>
					<ul>
						<li><?php esc_html_e( 'Courses that remember your place', 'wic-tp' ); ?></li>
						<li><?php esc_html_e( 'Due dates and reminders', 'wic-tp' ); ?></li>
						<li><?php esc_html_e( 'Certificates you can verify', 'wic-tp' ); ?></li>
					</ul>
				</div>
				<div class="wic-signin__form">
					<h2><?php echo esc_html( sprintf( __( '%s training portal', 'wic-tp' ), wic_setting( 'name' ) ) ); ?></h2>
					<p class="wic-help"><?php esc_html_e( 'Sign in with your portal username or email address.', 'wic-tp' ); ?></p>
					<?php
					wp_login_form(
						array(
							'redirect' => wic_page_url( 'portal' ),
						)
					);
					?>
					<p class="wic-signin__links"><a href="<?php echo esc_url( wp_lostpassword_url( wic_page_url( 'portal' ) ) ); ?>"><?php esc_html_e( 'Lost your password?', 'wic-tp' ); ?></a>
					· <a href="<?php echo esc_url( wic_page_url( 'register' ) ); ?>"><?php esc_html_e( 'New staff? Register here', 'wic-tp' ); ?></a></p>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function flash() {
		$msg      = isset( $_GET['wic_msg'] ) ? sanitize_key( $_GET['wic_msg'] ) : '';
		$messages = array(
			'approved'  => __( 'Registration approved. A set-password link has been sent.', 'wic-tp' ),
			'rejected'  => __( 'Registration rejected.', 'wic-tp' ),
			'reminded'  => __( 'Reminders queued. They will be sent within the hour.', 'wic-tp' ),
			'assigned'  => __( 'Course assigned.', 'wic-tp' ),
			'moved'     => __( 'Team moved. Training history is unchanged.', 'wic-tp' ),
			'extended'  => __( 'Due date extended.', 'wic-tp' ),
			'cancelled' => __( 'Assignment cancelled. Its record is kept.', 'wic-tp' ),
			'error'     => __( 'That action could not be completed.', 'wic-tp' ),
			'saved'     => __( 'Saved.', 'wic-tp' ),
		);
		/** Modules add messages; a key ending in _error or starting with err_ renders as an error. */
		$messages = apply_filters( 'wic_flash_messages', $messages );
		if ( isset( $messages[ $msg ] ) ) {
			$type = ( 'error' === $msg || 0 === strpos( $msg, 'err_' ) || '_error' === substr( $msg, -6 ) ) ? 'err' : 'ok';
			echo '<div class="wic-notice wic-notice--' . esc_attr( $type ) . '" role="status">' . esc_html( $messages[ $msg ] ) . '</div>';
		}
	}

	/* ------------------------------------------------------------------ */
	/* Learner views                                                      */
	/* ------------------------------------------------------------------ */

	private static function badge( $status ) {
		return '<span class="wic-badge wic-badge--' . esc_attr( $status ) . '">' . esc_html( wic_status_label( $status ) ) . '</span>';
	}

	private static function progress_bar( $pct, $label = '' ) {
		$pct = max( 0, min( 100, (int) $pct ) );
		return '<div class="wic-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $pct . '" aria-label="' . esc_attr( $label ? $label : __( 'Progress', 'wic-tp' ) ) . '"><span style="width:' . $pct . '%"></span></div><span class="wic-bar__pct">' . $pct . '%</span>';
	}

	/**
	 * A decorative cover for a course card: the first slide image in the course if there is
	 * one, otherwise the agency's brand pattern. The kicker names the credit type or "Course".
	 */
	private static function course_cover( $course_id ) {
		static $cache = array();
		if ( isset( $cache[ $course_id ] ) ) {
			return $cache[ $course_id ];
		}
		$image = '';
		foreach ( WIC_Content::tree( $course_id ) as $m ) {
			foreach ( $m['slides'] as $sid ) {
				$url = (string) get_post_meta( $sid, '_wic_image_url', true );
				if ( $url ) {
					$image = $url;
					break 2;
				}
			}
		}
		$kicker = (string) get_post_meta( $course_id, '_wic_credit_type', true );
		if ( get_post_meta( $course_id, '_wic_vendor_course', true ) ) {
			$kicker = __( 'Vendor training', 'wic-tp' );
		}
		$cache[ $course_id ] = array(
			'image'  => $image,
			'kicker' => $kicker ? $kicker : __( 'Course', 'wic-tp' ),
		);
		return $cache[ $course_id ];
	}

	private static function course_card( $a ) {
		$learn = wic_page_url( 'learn', array( 'course' => $a['course_id'] ) );
		// Coming-due and overdue courses may not have been opened yet, so the label follows activity, not status.
		if ( in_array( $a['status'], array( 'complete', 'expired' ), true ) ) {
			$label = 'complete' === $a['status'] ? __( 'Review', 'wic-tp' ) : __( 'Retake', 'wic-tp' );
		} else {
			$label = $a['last_access'] ? __( 'Continue', 'wic-tp' ) : __( 'Start', 'wic-tp' );
		}
		ob_start();
		?>
		<?php $cover = self::course_cover( $a['course_id'] ); ?>
		<article class="wic-card wic-card--course" data-status="<?php echo esc_attr( $a['status'] ); ?>">
			<div class="wic-card__cover<?php echo $cover['image'] ? ' has-image' : ''; ?>" aria-hidden="true">
				<?php if ( $cover['image'] ) : ?>
					<img src="<?php echo esc_url( $cover['image'] ); ?>" alt="" loading="lazy">
				<?php endif; ?>
				<span class="wic-card__kicker"><?php echo esc_html( $cover['kicker'] ); ?></span>
			</div>
			<div class="wic-card__top">
				<h3><?php echo esc_html( $a['title'] ); ?></h3>
				<?php echo self::badge( $a['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
			<p class="wic-meta">
				<?php echo esc_html( sprintf( __( 'About %d min', 'wic-tp' ), max( 1, $a['minutes'] ) ) ); ?>
				<?php if ( $a['required'] ) : ?> · <?php esc_html_e( 'Required', 'wic-tp' ); ?><?php endif; ?>
				<?php if ( $a['due_at'] && 'complete' !== $a['status'] ) : ?> · <?php echo esc_html( sprintf( __( 'Due %s', 'wic-tp' ), wic_format_date( $a['due_at'] ) ) ); ?><?php endif; ?>
				<?php if ( $a['days_late'] ) : ?> · <strong><?php echo esc_html( sprintf( _n( '%d day late', '%d days late', $a['days_late'], 'wic-tp' ), $a['days_late'] ) ); ?></strong><?php endif; ?>
				<?php $na = 'not_applicable' === $a['status'] && class_exists( 'WIC_Assign' ) ? WIC_Assign::na_for( $a['assignment_id'] ) : null; ?>
				<?php if ( $na ) : ?> · <?php echo esc_html( sprintf( __( 'Not applicable: %s', 'wic-tp' ), $na->reason ) ); ?><?php endif; ?>
			</p>
			<div class="wic-card__progress"><?php echo self::progress_bar( $a['progress'], $a['title'] ); // phpcs:ignore ?></div>
			<div class="wic-card__actions">
				<a class="wic-btn wic-btn--primary" href="<?php echo esc_url( $learn ); ?>"><?php echo esc_html( $label ); ?><span class="screen-reader-text"> <?php echo esc_html( $a['title'] ); ?></span></a>
				<?php if ( $a['certificate'] ) : ?>
					<a class="wic-btn" href="<?php echo esc_url( WIC_Certificates::url( $a['certificate'] ) ); ?>"><?php esc_html_e( 'Certificate', 'wic-tp' ); ?></a>
				<?php endif; ?>
			</div>
		</article>
		<?php
		return ob_get_clean();
	}

	public static function view_home( $uid ) {
		global $wpdb;
		$items = WIC_Records::user_assignments( $uid );
		$count = array_count_values( wp_list_pluck( $items, 'status' ) );
		// Continue where you left off: the most recently opened course that is not finished.
		$resume = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'positions' ) . ' WHERE user_id = %d ORDER BY last_access DESC LIMIT 1', $uid ) );
		$resume_item = null;
		if ( $resume ) {
			foreach ( $items as $i ) {
				if ( $i['course_id'] === (int) $resume->course_id && 'complete' !== $i['status'] ) {
					$resume_item = $i;
				}
			}
		}
		$me     = wp_get_current_user();
		$clinic = wic_user_clinic_name( $uid );
		$role   = '';
		foreach ( (array) $me->roles as $r ) {
			$names = wp_roles()->get_names();
			if ( isset( $names[ $r ] ) ) {
				$role = translate_user_role( $names[ $r ] );
				break;
			}
		}
		$meta = array_filter( array( $clinic, $role, wic_setting( 'name' ) ) );
		?>
		<div class="wic-dash-head">
			<div>
				<span class="wic-kicker"><?php echo esc_html( wp_date( get_option( 'date_format' ) ) ); ?></span>
				<h2 class="wic-h"><?php echo esc_html( sprintf( __( 'Welcome, %s', 'wic-tp' ), $me->first_name ? $me->first_name : $me->display_name ) ); ?></h2>
				<?php if ( $meta ) : ?>
					<p class="wic-meta"><?php echo esc_html( implode( ' · ', $meta ) ); ?></p>
				<?php endif; ?>
			</div>
			<a class="wic-btn" href="<?php echo esc_url( self::url( 'training' ) ); ?>"><?php esc_html_e( 'All my training', 'wic-tp' ); ?></a>
		</div>
		<div class="wic-dash">
		<div class="wic-dash__main">
		<?php if ( $resume_item ) : ?>
			<section class="wic-resume" aria-labelledby="wic-resume-h">
				<h3 id="wic-resume-h"><?php esc_html_e( 'Continue where you left off', 'wic-tp' ); ?></h3>
				<p><strong><?php echo esc_html( $resume_item['title'] ); ?></strong> <?php echo esc_html( get_the_title( $resume->current_slide ) ); ?></p>
				<div class="wic-resume__progress"><?php echo self::progress_bar( $resume_item['progress'], $resume_item['title'] ); // phpcs:ignore ?></div>
				<a class="wic-btn wic-btn--primary" href="<?php echo esc_url( wic_page_url( 'learn', array( 'course' => $resume_item['course_id'], 'slide' => $resume->current_slide ) ) ); ?>"><?php esc_html_e( 'Continue', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( $resume_item['title'] ); ?></span></a>
			</section>
		<?php endif; ?>
		<div class="wic-stats" role="list" aria-label="<?php esc_attr_e( 'My training by status', 'wic-tp' ); ?>">
			<?php foreach ( array( 'overdue', 'coming_due', 'not_started', 'in_progress', 'complete' ) as $s ) : ?>
				<a role="listitem" class="wic-stat wic-stat--<?php echo esc_attr( $s ); ?>" href="<?php echo esc_url( self::url( 'training', array( 'filter' => $s ) ) ); ?>">
					<span class="wic-stat__n"><?php echo isset( $count[ $s ] ) ? (int) $count[ $s ] : 0; ?></span>
					<span class="wic-stat__l"><?php echo esc_html( wic_status_label( $s ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
		<?php if ( ! $items ) : ?>
			<div class="wic-empty">
				<p><?php esc_html_e( 'Nothing is assigned to you yet. Once your supervisor assigns training it will appear here — usually within your first day.', 'wic-tp' ); ?></p>
			</div>
		<?php else : ?>
			<h3 class="wic-h3"><?php esc_html_e( 'Up next', 'wic-tp' ); ?></h3>
			<div class="wic-grid">
				<?php
				$next = array_slice(
					array_filter(
						$items,
						function ( $i ) {
							return ! in_array( $i['status'], array( 'complete', 'not_applicable' ), true );
						}
					),
					0,
					3
				);
				foreach ( $next as $a ) {
					echo self::course_card( $a ); // phpcs:ignore
				}
				if ( ! $next ) {
					echo '<div class="wic-empty wic-empty--good"><p>' . esc_html__( 'All your assigned training is complete. Well done.', 'wic-tp' ) . '</p></div>';
				}
				?>
			</div>
		<?php endif; ?>
		</div>
		<aside class="wic-dash__side" aria-label="<?php esc_attr_e( 'At a glance', 'wic-tp' ); ?>">
			<?php self::home_side_panels( $uid ); ?>
		</aside>
		</div>
		<?php
	}

	/**
	 * The side column of the home dashboard. Each panel appears only when its module is
	 * active and has something to say, so a small agency sees a short page.
	 */
	private static function home_side_panels( $uid ) {
		if ( user_can( $uid, 'wic_view_team' ) ) {
			$overdue = count( self::overdue_rows( $uid ) );
			$waiting = user_can( $uid, 'wic_approve' ) ? count( WIC_Registration::pending_for( $uid ) ) : 0;
			$people  = count( self::team( $uid ) );
			?>
			<section class="wic-panel" aria-labelledby="wic-dash-team">
				<h3 class="wic-h3" id="wic-dash-team"><?php esc_html_e( 'Your team', 'wic-tp' ); ?></h3>
				<div class="wic-team-sum">
					<a href="<?php echo esc_url( self::url( 'overdue' ) ); ?>" class="<?php echo $overdue ? 'is-alert' : ''; ?>"><b><?php echo (int) $overdue; ?></b><span><?php esc_html_e( 'Overdue courses', 'wic-tp' ); ?></span></a>
					<a href="<?php echo esc_url( self::url( 'approvals' ) ); ?>" class="<?php echo $waiting ? 'is-alert' : ''; ?>"><b><?php echo (int) $waiting; ?></b><span><?php esc_html_e( 'Waiting for approval', 'wic-tp' ); ?></span></a>
				</div>
				<p class="wic-panel__foot"><a href="<?php echo esc_url( self::url( 'team' ) ); ?>"><?php echo esc_html( sprintf( _n( 'See %d person on your team', 'See all %d people on your team', $people, 'wic-tp' ), $people ) ); ?></a></p>
			</section>
			<?php
		}

		if ( class_exists( 'WIC_Boost' ) && method_exists( 'WIC_Boost', 'due_rounds' ) ) {
			$rounds = WIC_Boost::due_rounds( $uid );
			if ( $rounds ) {
				?>
				<section class="wic-panel" aria-labelledby="wic-dash-boost">
					<h3 class="wic-h3" id="wic-dash-boost"><?php esc_html_e( 'Refreshers due', 'wic-tp' ); ?></h3>
					<ul class="wic-mini-list">
						<?php foreach ( array_slice( $rounds, 0, 3 ) as $r ) : ?>
							<li><span><?php echo esc_html( get_the_title( $r->course_id ) ); ?></span><span class="wic-meta"><?php esc_html_e( 'A few questions', 'wic-tp' ); ?></span></li>
						<?php endforeach; ?>
					</ul>
					<p class="wic-panel__foot"><a href="<?php echo esc_url( self::url( 'refreshers' ) ); ?>"><?php esc_html_e( 'Start a refresher', 'wic-tp' ); ?></a></p>
				</section>
				<?php
			}
		}

		if ( class_exists( 'WIC_Forms' ) && method_exists( 'WIC_Forms', 'outstanding' ) ) {
			$forms = WIC_Forms::outstanding( $uid );
			if ( $forms ) {
				?>
				<section class="wic-panel" aria-labelledby="wic-dash-forms">
					<h3 class="wic-h3" id="wic-dash-forms"><?php esc_html_e( 'Forms to sign', 'wic-tp' ); ?></h3>
					<ul class="wic-mini-list">
						<?php foreach ( array_slice( array_keys( $forms ), 0, 3 ) as $fid ) : ?>
							<li><a href="<?php echo esc_url( self::url( 'form_sign', array( 'form' => $fid ) ) ); ?>"><?php echo esc_html( get_the_title( $fid ) ); ?></a><span class="wic-badge wic-badge--pending"><?php esc_html_e( 'Not signed', 'wic-tp' ); ?></span></li>
						<?php endforeach; ?>
					</ul>
					<p class="wic-panel__foot"><a href="<?php echo esc_url( self::url( 'forms' ) ); ?>"><?php esc_html_e( 'All forms', 'wic-tp' ); ?></a></p>
				</section>
				<?php
			}
		}

		if ( class_exists( 'WIC_Sessions' ) && method_exists( 'WIC_Sessions', 'sessions' ) && method_exists( 'WIC_Sessions', 'row' ) ) {
			$mine = array();
			foreach ( WIC_Sessions::sessions( true ) as $s ) {
				$row = WIC_Sessions::row( $s->ID, $uid );
				if ( $row && in_array( $row->status, array( 'booked', 'waitlist' ), true ) ) {
					$mine[] = array( $s, $row );
				}
			}
			if ( $mine ) {
				?>
				<section class="wic-panel" aria-labelledby="wic-dash-sessions">
					<h3 class="wic-h3" id="wic-dash-sessions"><?php esc_html_e( 'Your booked sessions', 'wic-tp' ); ?></h3>
					<ul class="wic-mini-list">
						<?php foreach ( array_slice( $mine, 0, 3 ) as $m ) : ?>
							<li>
								<span><?php echo esc_html( get_the_title( $m[0] ) ); ?><br><span class="wic-meta"><?php echo esc_html( WIC_Sessions::when( $m[0]->ID ) ); ?></span></span>
								<?php if ( 'waitlist' === $m[1]->status ) : ?>
									<span class="wic-badge wic-badge--waitlist"><?php esc_html_e( 'Waiting list', 'wic-tp' ); ?></span>
								<?php else : ?>
									<span class="wic-badge wic-badge--complete"><?php esc_html_e( 'Booked', 'wic-tp' ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<p class="wic-panel__foot"><a href="<?php echo esc_url( self::url( 'sessions' ) ); ?>"><?php esc_html_e( 'All sessions', 'wic-tp' ); ?></a></p>
				</section>
				<?php
			}
		}

		if ( post_type_exists( 'wic_news' ) ) {
			$news = get_posts(
				array(
					'post_type'      => 'wic_news',
					'post_status'    => 'publish',
					'posts_per_page' => 3,
				)
			);
			if ( $news ) {
				?>
				<section class="wic-panel" aria-labelledby="wic-dash-news">
					<h3 class="wic-h3" id="wic-dash-news"><?php esc_html_e( 'Latest news', 'wic-tp' ); ?></h3>
					<ul class="wic-mini-list">
						<?php foreach ( $news as $n ) : ?>
							<li><span><?php echo esc_html( get_the_title( $n ) ); ?></span><time class="wic-meta" datetime="<?php echo esc_attr( get_post_time( 'c', true, $n ) ); ?>"><?php echo esc_html( get_the_date( '', $n ) ); ?></time></li>
						<?php endforeach; ?>
					</ul>
					<p class="wic-panel__foot"><a href="<?php echo esc_url( self::url( 'news' ) ); ?>"><?php esc_html_e( 'All news', 'wic-tp' ); ?></a></p>
				</section>
				<?php
			}
		}
	}

	public static function view_training( $uid ) {
		$items  = WIC_Records::user_assignments( $uid );
		$filter = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : '';
		$groups = array(
			'not_started'    => __( 'Not started', 'wic-tp' ),
			'in_progress'    => __( 'In progress', 'wic-tp' ),
			'coming_due'     => __( 'Coming due', 'wic-tp' ),
			'overdue'        => __( 'Overdue', 'wic-tp' ),
			'complete'       => __( 'Complete', 'wic-tp' ),
			'not_applicable' => __( 'Not applicable', 'wic-tp' ),
		);
		?>
		<h2 class="wic-h"><?php esc_html_e( 'My training', 'wic-tp' ); ?></h2>
		<div class="wic-filters" role="group" aria-label="<?php esc_attr_e( 'Filter by status', 'wic-tp' ); ?>" data-wic-filters>
			<?php foreach ( $groups as $key => $label ) : ?>
				<button type="button" class="wic-chip" data-filter="<?php echo esc_attr( $key ); ?>" aria-pressed="<?php echo ( ! $filter || $filter === $key ) ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></button>
			<?php endforeach; ?>
		</div>
		<div class="wic-grid" data-wic-filter-target>
			<?php
			foreach ( $items as $a ) {
				echo self::course_card( $a ); // phpcs:ignore
			}
			?>
		</div>
		<div class="wic-empty" data-wic-empty <?php echo $items ? 'hidden' : ''; ?>>
			<p><?php esc_html_e( 'No training matches these filters.', 'wic-tp' ); ?></p>
		</div>
		<p class="wic-live screen-reader-text" aria-live="polite" data-wic-filter-status></p>
		<?php
	}

	public static function view_certificates( $uid ) {
		$certs = WIC_Certificates::for_user( $uid );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Certificates', 'wic-tp' ); ?></h2>
		<?php if ( ! $certs ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'Certificates appear here as soon as you complete a course. They are kept in your account permanently.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<div class="wic-table-wrap">
		<table class="wic-table">
			<thead><tr><th scope="col"><?php esc_html_e( 'Course', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Completed', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Score', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Number', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'wic-tp' ); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'wic-tp' ); ?></span></th></tr></thead>
			<tbody>
			<?php foreach ( $certs as $c ) : ?>
				<tr>
					<td><?php echo esc_html( $c->course_title ); ?></td>
					<td><?php echo esc_html( wic_format_date( $c->issued_at ) ); ?></td>
					<td><?php echo (int) $c->score; ?>%</td>
					<td><code><?php echo esc_html( $c->cert_number ); ?></code></td>
					<td><?php echo esc_html( ucfirst( WIC_Certificates::state( $c ) ) ); ?></td>
					<td><a class="wic-btn wic-btn--small" href="<?php echo esc_url( WIC_Certificates::url( $c ) ); ?>"><?php esc_html_e( 'View / print', 'wic-tp' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	public static function view_notifications( $uid ) {
		$events = WIC_Notify::recent( $uid );
		WIC_Notify::mark_all_read( $uid );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Notifications', 'wic-tp' ); ?></h2>
		<?php
		if ( class_exists( 'WIC_Notice_Log' ) ) {
			WIC_Notice_Log::digest_form( $uid );
		}
		?>
		<?php if ( ! $events ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'No notifications yet. Assignments, due dates and completions will show up here.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<ul class="wic-list">
			<?php foreach ( $events as $e ) : ?>
				<li class="<?php echo $e->read_at ? '' : 'is-unread'; ?>">
					<span><?php echo esc_html( $e->message ); ?></span>
					<time datetime="<?php echo esc_attr( mysql2date( 'c', $e->created_at ) ); ?>"><?php echo esc_html( wic_format_date( $e->created_at, true ) ); ?></time>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Supervisor views                                                   */
	/* ------------------------------------------------------------------ */

	/** People this viewer manages. A supervisor's own reports; an administrator sees everyone but themself. */
	private static function team( $uid ) {
		$ids = array_diff( wic_scope_user_ids( $uid ), array( $uid ) );
		$out = array();
		foreach ( $ids as $id ) {
			if ( 'active' !== wic_user_status( $id ) ) {
				continue;
			}
			$u = get_userdata( $id );
			if ( $u ) {
				$out[] = $u;
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return strcasecmp( $a->display_name, $b->display_name );
			}
		);
		return $out;
	}

	/** Current clinic filter from the query string ('' = all). */
	private static function clinic_filter() {
		return isset( $_REQUEST['clinic'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['clinic'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/** Clinics present among a set of people, as key => label, for the filter. */
	private static function clinics_among( $people ) {
		$out = array();
		foreach ( $people as $u ) {
			$key = wic_user_clinic_id( $u->ID ) ? (string) wic_user_clinic_id( $u->ID ) : wic_user_clinic_name( $u->ID );
			if ( '' !== $key ) {
				$out[ $key ] = WIC_Assign::clinic_label( $key );
			}
		}
		asort( $out );
		return $out;
	}

	private static function clinic_filter_form( $view, $people, $current ) {
		$clinics = self::clinics_among( $people );
		if ( count( $clinics ) < 2 && '' === $current ) {
			return;
		}
		?>
		<form method="get" class="wic-form wic-form--inline wic-filterbar" action="<?php echo esc_url( wic_page_url( 'portal' ) ); ?>">
			<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
			<?php WIC_Reports::page_id_field(); ?>
			<div class="wic-field">
				<label for="wic-<?php echo esc_attr( $view ); ?>-clinic"><?php esc_html_e( 'Clinic', 'wic-tp' ); ?></label>
				<select id="wic-<?php echo esc_attr( $view ); ?>-clinic" name="clinic">
					<option value=""><?php esc_html_e( 'All clinics', 'wic-tp' ); ?></option>
					<?php foreach ( $clinics as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $current, (string) $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<button type="submit" class="wic-btn"><?php esc_html_e( 'Filter', 'wic-tp' ); ?></button>
		</form>
		<?php
	}

	/** Who is behind, worst first: overdue count, then coming due, then completion rate. */
	public static function view_team( $uid ) {
		$all    = self::team( $uid );
		$clinic = self::clinic_filter();
		$team   = array_values(
			array_filter(
				$all,
				function ( $u ) use ( $clinic ) {
					return WIC_Assign::user_in_clinic( $u->ID, $clinic );
				}
			)
		);
		$rows   = array();
		foreach ( $team as $u ) {
			$items  = WIC_Reports::assignments( $u->ID );
			$c      = array_count_values( wp_list_pluck( $items, 'status' ) );
			$last   = array_filter( wp_list_pluck( $items, 'last_access' ) );
			rsort( $last );
			$counted = count( $items ) - ( isset( $c['not_applicable'] ) ? $c['not_applicable'] : 0 );
			$rows[]  = array(
				'u'       => $u,
				'c'       => $c,
				'done'    => isset( $c['complete'] ) ? (int) $c['complete'] : 0,
				'counted' => $counted,
				'overdue' => isset( $c['overdue'] ) ? (int) $c['overdue'] : 0,
				'soon'    => isset( $c['coming_due'] ) ? (int) $c['coming_due'] : 0,
				'last'    => $last ? $last[0] : '',
			);
		}
		usort(
			$rows,
			function ( $a, $b ) {
				if ( $a['overdue'] !== $b['overdue'] ) {
					return $b['overdue'] - $a['overdue'];
				}
				if ( $a['soon'] !== $b['soon'] ) {
					return $b['soon'] - $a['soon'];
				}
				$ra = $a['counted'] ? $a['done'] / $a['counted'] : 1;
				$rb = $b['counted'] ? $b['done'] / $b['counted'] : 1;
				return $ra === $rb ? strcasecmp( $a['u']->display_name, $b['u']->display_name ) : ( $ra < $rb ? -1 : 1 );
			}
		);
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Team progress', 'wic-tp' ); ?></h2>
		<?php if ( ! $all ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'Nobody reports to you yet. People appear here once you approve their registration.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<p class="wic-help"><?php esc_html_e( 'Worst first: most overdue at the top, then coming due, then lowest completion. Select a column heading to sort differently.', 'wic-tp' ); ?></p>
		<?php self::clinic_filter_form( 'team', $all, $clinic ); ?>
		<?php if ( ! $rows ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'Nobody at this clinic.', 'wic-tp' ); ?></p></div>
		<?php else : ?>
		<div class="wic-table-wrap">
		<table class="wic-table" data-wic-sortable>
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Name', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Clinic', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Group', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Complete', 'wic-tp' ); ?></th>
				<th scope="col" aria-sort="descending"><?php esc_html_e( 'Overdue', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Coming due', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Last activity', 'wic-tp' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Records', 'wic-tp' ); ?></span></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<?php $u = $r['u']; ?>
				<tr>
					<td><?php echo WIC_Reports::person_link( $u ); // phpcs:ignore ?><?php echo user_can( $u, 'wic_view_team' ) ? ' <span class="wic-tag">' . esc_html__( 'Supervisor', 'wic-tp' ) . '</span>' : ''; ?></td>
					<td><?php echo esc_html( wic_user_clinic_name( $u->ID ) ); ?></td>
					<td><?php echo esc_html( wic_group_label( wic_user_group( $u->ID ) ) ); ?></td>
					<td data-sort="<?php echo $r['counted'] ? (int) round( $r['done'] / $r['counted'] * 100 ) : 100; ?>"><?php echo (int) $r['done']; ?> / <?php echo (int) $r['counted']; ?></td>
					<td data-sort="<?php echo (int) $r['overdue']; ?>"><?php echo $r['overdue'] ? '<strong class="wic-late">' . esc_html( sprintf( _n( '%d overdue', '%d overdue', $r['overdue'], 'wic-tp' ), $r['overdue'] ) ) . '</strong>' : '0'; ?></td>
					<td data-sort="<?php echo (int) $r['soon']; ?>"><?php echo (int) $r['soon']; ?></td>
					<td data-sort="<?php echo esc_attr( $r['last'] ); ?>"><?php echo esc_html( $r['last'] ? wic_format_date( $r['last'] ) : __( 'Never', 'wic-tp' ) ); ?></td>
					<td><a class="wic-btn wic-btn--small" href="<?php echo esc_url( wic_portal_url( 'transcript', array( 'user' => $u->ID ) ) ); ?>"><?php esc_html_e( 'Transcript', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( $u->display_name ); ?></span></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php endif; ?>
		<p><a class="wic-btn" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'wic_export', 'clinic' => $clinic ), admin_url( 'admin-post.php' ) ), 'wic_export' ) ); ?>"><?php esc_html_e( 'Export team record (CSV)', 'wic-tp' ); ?></a></p>
		<section class="wic-section wic-two">
			<?php echo WIC_Assign::assign_form( $uid, 'team' ); // phpcs:ignore ?>
		</section>
		<?php
	}

	public static function overdue_rows( $uid, $clinic = '' ) {
		$rows = array();
		foreach ( self::team( $uid ) as $u ) {
			if ( '' !== $clinic && ! WIC_Assign::user_in_clinic( $u->ID, $clinic ) ) {
				continue;
			}
			foreach ( WIC_Records::user_assignments( $u->ID ) as $a ) {
				if ( 'overdue' === $a['status'] ) {
					$a['user'] = $u;
					$rows[]    = $a;
				}
			}
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return $b['days_late'] - $a['days_late'];
			}
		);
		return $rows;
	}

	public static function view_overdue( $uid ) {
		$clinic = self::clinic_filter();
		$rows   = self::overdue_rows( $uid, $clinic );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Overdue training', 'wic-tp' ); ?></h2>
		<?php self::clinic_filter_form( 'overdue', self::team( $uid ), $clinic ); ?>
		<?php if ( ! $rows ) : ?>
			<div class="wic-empty wic-empty--good"><p><?php echo '' === $clinic ? esc_html__( 'Nobody on your team is overdue.', 'wic-tp' ) : esc_html__( 'Nobody at this clinic is overdue.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-toolbar">
			<input type="hidden" name="action" value="wic_remind_all">
			<input type="hidden" name="clinic" value="<?php echo esc_attr( $clinic ); ?>">
			<?php wp_nonce_field( 'wic_remind_all' ); ?>
			<p><?php echo esc_html( sprintf( _n( '%d overdue course, worst first. Nudge one person, or remind everyone listed at once.', '%d overdue courses, worst first. Nudge one person, or remind everyone listed at once.', count( $rows ), 'wic-tp' ), count( $rows ) ) ); ?></p>
			<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Remind everyone', 'wic-tp' ); ?></button>
		</form>
		<div class="wic-table-wrap">
		<table class="wic-table" data-wic-sortable>
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Name', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Clinic', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Course', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Due', 'wic-tp' ); ?></th>
				<th scope="col" aria-sort="descending"><?php esc_html_e( 'Days late', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Progress', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Remind', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Extend or exempt', 'wic-tp' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<td><?php echo WIC_Reports::person_link( $r['user'] ); // phpcs:ignore ?></td>
					<td><?php echo esc_html( wic_user_clinic_name( $r['user']->ID ) ); ?></td>
					<td><?php echo esc_html( $r['title'] ); ?></td>
					<td data-sort="<?php echo esc_attr( $r['due_at'] ); ?>"><?php echo esc_html( wic_format_date( $r['due_at'] ) ); ?></td>
					<td data-sort="<?php echo (int) $r['days_late']; ?>"><strong class="wic-late"><?php echo (int) $r['days_late']; ?></strong></td>
					<td data-sort="<?php echo (int) $r['progress']; ?>"><?php echo (int) $r['progress']; ?>%</td>
					<td><?php echo WIC_Assign::nudge_button( $r, 'overdue' ); // phpcs:ignore ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-inline-form">
							<input type="hidden" name="action" value="wic_extend">
							<input type="hidden" name="assignment" value="<?php echo (int) $r['assignment_id']; ?>">
							<?php wp_nonce_field( 'wic_extend_' . $r['assignment_id'] ); ?>
							<label class="screen-reader-text" for="wic-ext-<?php echo (int) $r['assignment_id']; ?>"><?php esc_html_e( 'New due date', 'wic-tp' ); ?></label>
							<input type="date" id="wic-ext-<?php echo (int) $r['assignment_id']; ?>" name="due" required min="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
							<label class="screen-reader-text" for="wic-why-<?php echo (int) $r['assignment_id']; ?>"><?php esc_html_e( 'Reason', 'wic-tp' ); ?></label>
							<input type="text" id="wic-why-<?php echo (int) $r['assignment_id']; ?>" name="reason" required placeholder="<?php esc_attr_e( 'Reason', 'wic-tp' ); ?>">
							<button type="submit" class="wic-btn wic-btn--small"><?php esc_html_e( 'Extend', 'wic-tp' ); ?></button>
						</form>
						<?php echo WIC_Assign::na_controls( $r, 'overdue' ); // phpcs:ignore ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	public static function view_approvals( $uid ) {
		$pending = WIC_Registration::pending_for( $uid );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Registrations waiting for approval', 'wic-tp' ); ?></h2>
		<?php if ( ! $pending ) : ?>
			<div class="wic-empty wic-empty--good"><p><?php esc_html_e( 'No registrations are waiting.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<div class="wic-table-wrap">
		<table class="wic-table">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Name', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Email', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Clinic', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Waiting', 'wic-tp' ); ?></th>
				<?php if ( current_user_can( 'wic_view_all' ) ) : ?><th scope="col"><?php esc_html_e( 'Supervisor', 'wic-tp' ); ?></th><?php endif; ?>
				<th scope="col"><?php esc_html_e( 'Decision', 'wic-tp' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $pending as $p ) : ?>
				<?php $days = (int) floor( ( time() - strtotime( $p->user_registered . ' UTC' ) ) / DAY_IN_SECONDS ); ?>
				<tr>
					<td><?php echo esc_html( $p->display_name ); ?></td>
					<td><?php echo esc_html( $p->user_email ); ?></td>
					<td><?php echo esc_html( wic_user_clinic_name( $p->ID ) ); ?></td>
					<td><?php echo esc_html( sprintf( _n( '%d day', '%d days', $days, 'wic-tp' ), $days ) ); ?></td>
					<?php if ( current_user_can( 'wic_view_all' ) ) : ?>
						<?php $sup = get_userdata( wic_reports_to( $p->ID ) ); ?>
						<td><?php echo esc_html( $sup ? $sup->display_name : '—' ); ?></td>
					<?php endif; ?>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-inline-form">
							<input type="hidden" name="user" value="<?php echo (int) $p->ID; ?>">
							<?php wp_nonce_field( 'wic_decide_' . $p->ID ); ?>
							<label for="wic-grp-<?php echo (int) $p->ID; ?>"><?php esc_html_e( 'Group', 'wic-tp' ); ?></label>
							<select name="group" id="wic-grp-<?php echo (int) $p->ID; ?>">
								<option value="staff"><?php esc_html_e( 'Staff', 'wic-tp' ); ?></option>
								<option value="intern"><?php esc_html_e( 'Intern', 'wic-tp' ); ?></option>
							</select>
							<button type="submit" name="action" value="wic_approve" class="wic-btn wic-btn--small wic-btn--primary"><?php esc_html_e( 'Approve', 'wic-tp' ); ?></button>
							<button type="submit" name="action" value="wic_reject" class="wic-btn wic-btn--small" data-wic-confirm="<?php esc_attr_e( 'Reject this registration?', 'wic-tp' ); ?>"><?php esc_html_e( 'Reject', 'wic-tp' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Administrator view                                                 */
	/* ------------------------------------------------------------------ */

	public static function view_agency( $uid ) {
		$supervisors = WIC_Roles::supervisors();
		$courses     = get_posts(
			array(
				'post_type'      => 'wic_course',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$people      = self::team( $uid );
		$pending_all = WIC_Registration::pending_for( $uid );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Agency', 'wic-tp' ); ?></h2>

		<section class="wic-section">
			<h3 class="wic-h3"><?php esc_html_e( 'Approval queues by supervisor', 'wic-tp' ); ?></h3>
			<div class="wic-table-wrap">
			<table class="wic-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Supervisor', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Waiting', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Oldest (days)', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Team size', 'wic-tp' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $supervisors as $s ) : ?>
					<?php
					$mine   = array_filter(
						$pending_all,
						function ( $p ) use ( $s ) {
							return wic_reports_to( $p->ID ) === (int) $s->ID;
						}
					);
					$oldest = 0;
					foreach ( $mine as $p ) {
						$oldest = max( $oldest, (int) floor( ( time() - strtotime( $p->user_registered . ' UTC' ) ) / DAY_IN_SECONDS ) );
					}
					$size = count(
						get_users(
							array(
								'meta_key'   => 'wic_reports_to',
								'meta_value' => $s->ID,
								'fields'     => 'ID',
							)
						)
					) - count( $mine );
					?>
					<tr>
						<td><?php echo esc_html( $s->display_name ); ?></td>
						<td><?php echo count( $mine ); ?></td>
						<td><?php echo $oldest >= (int) wic_setting( 'escalation_days' ) ? '<strong class="wic-late">' . (int) $oldest . '</strong>' : (int) $oldest; ?></td>
						<td><?php echo (int) $size; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</section>

		<section class="wic-section wic-two">
			<?php echo WIC_Assign::assign_form( $uid, 'agency' ); // phpcs:ignore ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form wic-panel">
				<h3 class="wic-h3"><?php esc_html_e( 'Move a whole team', 'wic-tp' ); ?></h3>
				<input type="hidden" name="action" value="wic_move_team">
				<?php wp_nonce_field( 'wic_move_team' ); ?>
				<div class="wic-field">
					<label for="wic-mv-from"><?php esc_html_e( 'Everyone reporting to', 'wic-tp' ); ?></label>
					<select id="wic-mv-from" name="from">
						<?php foreach ( $supervisors as $s ) : ?>
							<option value="<?php echo (int) $s->ID; ?>"><?php echo esc_html( $s->display_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="wic-field">
					<label for="wic-mv-to"><?php esc_html_e( 'Now reports to', 'wic-tp' ); ?></label>
					<select id="wic-mv-to" name="to">
						<?php foreach ( $supervisors as $s ) : ?>
							<option value="<?php echo (int) $s->ID; ?>"><?php echo esc_html( $s->display_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<p class="wic-help"><?php esc_html_e( 'Only the reporting line changes. Progress and completions are untouched.', 'wic-tp' ); ?></p>
				<button type="submit" class="wic-btn" data-wic-confirm="<?php esc_attr_e( 'Move everyone in this team?', 'wic-tp' ); ?>"><?php esc_html_e( 'Move team', 'wic-tp' ); ?></button>
			</form>
		</section>

		<section class="wic-section">
			<div class="wic-toolbar">
				<h3 class="wic-h3"><?php esc_html_e( 'Training matrix', 'wic-tp' ); ?></h3>
				<a class="wic-btn" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wic_export' ), 'wic_export' ) ); ?>"><?php esc_html_e( 'Export agency record (CSV)', 'wic-tp' ); ?></a>
			</div>
			<?php
			// Vendor training is for store staff, not agency staff, so it stays out of the staff matrix.
			$courses = array_values(
				array_filter(
					$courses,
					function ( $c ) {
						return '1' !== get_post_meta( $c->ID, '_wic_vendor_course', true );
					}
				)
			);
			?>
			<?php if ( ! $people || ! $courses ) : ?>
				<div class="wic-empty"><p><?php esc_html_e( 'The matrix fills in once there are people and published courses.', 'wic-tp' ); ?></p></div>
			<?php else : ?>
				<div class="wic-table-wrap">
				<table class="wic-table wic-matrix">
					<thead><tr><th scope="col"><?php esc_html_e( 'Person', 'wic-tp' ); ?></th>
						<?php foreach ( $courses as $c ) : ?>
							<th scope="col"><?php echo esc_html( $c->post_title ); ?></th>
						<?php endforeach; ?>
					</tr></thead>
					<tbody>
					<?php foreach ( $people as $p ) : ?>
						<?php
						$by_course = array();
						foreach ( WIC_Reports::assignments( $p->ID ) as $a ) {
							$by_course[ $a['course_id'] ] = $a['status'];
						}
						?>
						<tr>
							<th scope="row"><?php echo WIC_Reports::person_link( $p ); // phpcs:ignore ?> <span class="wic-meta"><?php echo esc_html( wic_user_clinic_name( $p->ID ) ); ?></span></th>
							<?php foreach ( $courses as $c ) : ?>
								<td><?php echo isset( $by_course[ $c->ID ] ) ? self::badge( $by_course[ $c->ID ] ) : self::badge( 'never_assigned' ); // phpcs:ignore ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}
}
