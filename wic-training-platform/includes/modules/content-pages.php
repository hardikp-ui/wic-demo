<?php
/**
 * Portal content: news and FAQs managed like any other content, the rule that no image
 * is published without a real description, and a last-updated date on every page.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Content_Pages {

	/** Post types the alt-text rule never applies to. */
	const SKIP_TYPES = array( 'revision', 'attachment', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'wp_font_family', 'wp_font_face' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'alt_rule' ), 20, 2 );
		add_filter( 'the_content', array( __CLASS__, 'last_updated' ), 30 );
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		add_shortcode( 'wic_faq', array( __CLASS__, 'faq_shortcode' ) );
		add_action( 'save_post_wic_news', array( __CLASS__, 'stamp' ), 5 );
		add_action( 'save_post_wic_faq', array( __CLASS__, 'stamp' ), 5 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function register() {
		$common = array(
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => 'wic-platform',
			'show_in_rest'    => false,
			'capability_type' => array( 'wic_item', 'wic_items' ),
			'map_meta_cap'    => true,
		);
		register_post_type(
			'wic_news',
			$common + array(
				'labels'   => array(
					'name'          => __( 'News', 'wic-tp' ),
					'singular_name' => __( 'News item', 'wic-tp' ),
					'add_new_item'  => __( 'Add news item', 'wic-tp' ),
					'edit_item'     => __( 'Edit news item', 'wic-tp' ),
				),
				'supports' => array( 'title', 'editor', 'revisions' ),
			)
		);
		register_post_type(
			'wic_faq',
			$common + array(
				'labels'   => array(
					'name'          => __( 'FAQs', 'wic-tp' ),
					'singular_name' => __( 'FAQ', 'wic-tp' ),
					'add_new_item'  => __( 'Add question', 'wic-tp' ),
					'edit_item'     => __( 'Edit question', 'wic-tp' ),
				),
				'supports' => array( 'title', 'editor', 'revisions', 'page-attributes' ),
			)
		);
	}

	public static function stamp( $post_id ) {
		if ( '' === get_post_meta( $post_id, '_wic_agency_id', true ) ) {
			update_post_meta( $post_id, '_wic_agency_id', wic_user_agency_id( get_current_user_id() ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* No image without a description                                     */
	/* ------------------------------------------------------------------ */

	/** Every <img> whose alt is missing or filename-like. Explicitly decorative images are allowed. */
	public static function bad_images( $html ) {
		$bad = array();
		if ( false === stripos( (string) $html, '<img' ) ) {
			return $bad;
		}
		preg_match_all( '/<img\b[^>]*>/i', (string) $html, $tags );
		foreach ( $tags[0] as $tag ) {
			if ( preg_match( '/\brole\s*=\s*["\']?(presentation|none)\b/i', $tag ) || preg_match( '/\baria-hidden\s*=\s*["\']?true\b/i', $tag ) ) {
				continue;
			}
			$alt = preg_match( '/\balt\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $m ) ? html_entity_decode( '' !== $m[2] ? $m[2] : ( isset( $m[3] ) ? $m[3] : '' ) ) : '';
			if ( ! wic_alt_is_plausible( $alt ) ) {
				$src   = preg_match( '/\bsrc\s*=\s*["\']([^"\']+)/i', $tag, $s ) ? $s[1] : '';
				$bad[] = $src ? wp_basename( $src ) : __( 'an image', 'wic-tp' );
			}
		}
		return $bad;
	}

	/** Applies to every post type: publishing is held back until each image is described. */
	public static function alt_rule( $data, $postarr ) {
		if ( 'publish' !== $data['post_status'] || in_array( $data['post_type'], self::SKIP_TYPES, true ) ) {
			return $data;
		}
		$bad = self::bad_images( wp_unslash( $data['post_content'] ) );
		if ( ! $bad ) {
			return $data;
		}
		$data['post_status'] = 'draft';
		if ( get_current_user_id() ) {
			set_transient(
				'wic_alt_notice_' . get_current_user_id(),
				sprintf(
					/* translators: %s: image file names */
					__( 'Kept as a draft: these images need a meaningful description (alt text) before publishing — %s. Mark an image purely decorative with role="presentation".', 'wic-tp' ),
					implode( ', ', array_unique( $bad ) )
				),
				120
			);
		}
		return $data;
	}

	public static function notice() {
		$key = 'wic_alt_notice_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( $msg ) {
			delete_transient( $key );
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}

	/* ------------------------------------------------------------------ */
	/* Last updated                                                       */
	/* ------------------------------------------------------------------ */

	private static function stamp_html( $post ) {
		return '<p class="wic-updated"><small>' . esc_html( sprintf( __( 'Last updated %s', 'wic-tp' ), wic_format_date( $post->post_modified_gmt ) ) ) . '</small></p>';
	}

	/** On the platform's own pages (portal, register, verify and module pages) — the portal's footer. */
	public static function last_updated( $content ) {
		if ( ! in_the_loop() || ! is_main_query() || ! is_page() ) {
			return $content;
		}
		$ids = array();
		foreach ( array_keys( WIC_Install::page_defs() ) as $key ) {
			$ids[] = wic_page_id( $key );
		}
		$post = get_post();
		if ( ! $post || ! in_array( (int) $post->ID, $ids, true ) ) {
			return $content;
		}
		return $content . self::stamp_html( $post );
	}

	/* ------------------------------------------------------------------ */
	/* News and FAQs                                                      */
	/* ------------------------------------------------------------------ */

	private static function items( $type, $limit = -1 ) {
		$agency = is_user_logged_in() ? wic_user_agency_id( get_current_user_id() ) : wic_current_agency_id();
		$args   = array(
			'post_type'      => $type,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'wic_faq' === $type ? array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			) : 'date',
			'order'          => 'wic_faq' === $type ? 'ASC' : 'DESC',
		);
		if ( $agency ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => '_wic_agency_id',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_wic_agency_id',
					'value'   => array( 0, $agency ),
					'compare' => 'IN',
					'type'    => 'NUMERIC',
				),
			);
		}
		return get_posts( $args );
	}

	public static function views( $views ) {
		$views['news'] = array(
			'label'    => __( 'News', 'wic-tp' ),
			'group'    => 'learn',
			'cap'      => 'read',
			'callback' => array( __CLASS__, 'view_news' ),
			'order'    => 70,
		);
		$views['faq']  = array(
			'label'    => __( 'Help', 'wic-tp' ),
			'group'    => 'learn',
			'cap'      => 'read',
			'callback' => array( __CLASS__, 'view_faq' ),
			'order'    => 80,
		);
		return $views;
	}

	public static function view_news( $uid ) {
		$news = self::items( 'wic_news', 30 );
		?>
		<div class="wic-toolbar">
			<h2 class="wic-h"><?php esc_html_e( 'News', 'wic-tp' ); ?></h2>
			<?php if ( current_user_can( 'wic_manage_content' ) ) : ?>
				<a class="wic-btn" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=wic_news' ) ); ?>"><?php esc_html_e( 'Add news item', 'wic-tp' ); ?></a>
			<?php endif; ?>
		</div>
		<?php if ( ! $news ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'No news yet. Updates from your agency will appear here.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		foreach ( $news as $n ) :
			?>
			<article class="wic-panel wic-news">
				<h3 class="wic-h3"><?php echo esc_html( $n->post_title ); ?></h3>
				<p class="wic-meta"><time datetime="<?php echo esc_attr( mysql2date( 'c', $n->post_date_gmt ) ); ?>"><?php echo esc_html( wic_format_date( $n->post_date_gmt ) ); ?></time>
					<?php if ( $n->post_modified_gmt > $n->post_date_gmt ) : ?> · <?php echo esc_html( sprintf( __( 'Last updated %s', 'wic-tp' ), wic_format_date( $n->post_modified_gmt ) ) ); ?><?php endif; ?></p>
				<div class="wic-news__body"><?php echo wp_kses_post( wpautop( $n->post_content ) ); ?></div>
			</article>
			<?php
		endforeach;
	}

	public static function view_faq( $uid ) {
		?>
		<div class="wic-toolbar">
			<h2 class="wic-h"><?php esc_html_e( 'Help and frequently asked questions', 'wic-tp' ); ?></h2>
			<?php if ( current_user_can( 'wic_manage_content' ) ) : ?>
				<a class="wic-btn" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=wic_faq' ) ); ?>"><?php esc_html_e( 'Add question', 'wic-tp' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
		echo self::faq_shortcode(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	}

	public static function faq_shortcode() {
		$faqs = self::items( 'wic_faq' );
		if ( ! $faqs ) {
			return '<div class="wic-empty"><p>' . esc_html__( 'No questions have been added yet.', 'wic-tp' ) . '</p></div>';
		}
		$out = '<div class="wic-faq">';
		foreach ( $faqs as $f ) {
			$out .= '<details class="wic-faq__item"><summary>' . esc_html( $f->post_title ) . '</summary><div class="wic-faq__a">' . wp_kses_post( wpautop( $f->post_content ) ) . self::stamp_html( $f ) . '</div></details>';
		}
		return $out . '</div>';
	}
}

add_action( 'wic_register_types', array( 'WIC_Content_Pages', 'register' ) );
add_action( 'wic_init', array( 'WIC_Content_Pages', 'init' ) );
