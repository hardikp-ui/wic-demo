<?php
/**
 * Slide templates: the same handful of layouts, reused, so new content is quick and
 * consistent. A template is a slide that belongs to no course; "new slide from template"
 * copies its layout, body, layers and question skeleton into a real slide.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Slide_Templates {

	const TYPE = 'wic_slide_tpl';

	/** Meta copied from a template into a new slide. */
	const KEYS = array( '_wic_layout', '_wic_layers', '_wic_question', '_wic_image_url', '_wic_image_alt', '_wic_seconds' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_post_wic_tpl_apply', array( __CLASS__, 'handle_apply' ) );
	}

	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Slide templates', 'wic-tp' ),
					'singular_name' => __( 'Slide template', 'wic-tp' ),
					'add_new_item'  => __( 'Add slide template', 'wic-tp' ),
					'edit_item'     => __( 'Edit slide template', 'wic-tp' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'wic-platform',
				'show_in_rest'    => false,
				'capability_type' => array( 'wic_item', 'wic_items' ),
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'editor', 'revisions', 'page-attributes' ),
			)
		);
	}

	public static function all() {
		return get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);
	}

	/** Create a slide in a module from a template (0 = blank). Returns the new slide ID. */
	public static function new_slide( $module_id, $template_id = 0, $title = '' ) {
		$siblings = get_posts(
			array(
				'post_type'        => 'wic_slide',
				'post_parent'      => (int) $module_id,
				'post_status'      => array( 'publish', 'draft' ),
				'posts_per_page'   => 1,
				'orderby'          => 'menu_order',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);
		$order    = $siblings ? (int) $siblings[0]->menu_order + 10 : 10;
		$tpl      = $template_id ? get_post( $template_id ) : null;
		if ( $tpl && self::TYPE !== $tpl->post_type ) {
			$tpl = null;
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'wic_slide',
				'post_status'  => 'draft',
				'post_title'   => $title ? $title : ( $tpl ? $tpl->post_title : __( 'New slide', 'wic-tp' ) ),
				'post_content' => $tpl ? wp_slash( $tpl->post_content ) : '',
				'post_parent'  => (int) $module_id,
				'menu_order'   => $order,
			)
		);
		if ( ! $id || is_wp_error( $id ) ) {
			return 0;
		}
		update_post_meta( $id, '_wic_layout', 'text' );
		if ( $tpl ) {
			self::copy_meta( $tpl->ID, $id );
		}
		return (int) $id;
	}

	public static function copy_meta( $from, $to ) {
		foreach ( self::KEYS as $key ) {
			$v = get_post_meta( $from, $key, true );
			if ( '' !== $v ) {
				update_post_meta( $to, $key, wp_slash( $v ) );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* WP-admin                                                           */
	/* ------------------------------------------------------------------ */

	public static function meta_boxes() {
		add_meta_box( 'wic_tpl_meta', __( 'Template settings', 'wic-tp' ), array( __CLASS__, 'tpl_box' ), self::TYPE, 'normal', 'high' );
		add_meta_box( 'wic_tpl_apply', __( 'Start from a template', 'wic-tp' ), array( __CLASS__, 'apply_box' ), 'wic_slide', 'side', 'default' );
	}

	public static function tpl_box( $post ) {
		wp_nonce_field( 'wic_tpl_meta', 'wic_tpl_nonce' );
		$layout = get_post_meta( $post->ID, '_wic_layout', true );
		?>
		<p><label for="wic_tpl_layout"><strong><?php esc_html_e( 'Layout', 'wic-tp' ); ?></strong></label><br>
			<select name="wic_tpl_layout" id="wic_tpl_layout">
				<?php foreach ( WIC_Content::layouts() as $l ) : ?>
					<option value="<?php echo esc_attr( $l ); ?>" <?php selected( $layout, $l ); ?>><?php echo esc_html( ucfirst( $l ) ); ?></option>
				<?php endforeach; ?>
			</select></p>
		<p><label for="wic_tpl_layers"><strong><?php esc_html_e( 'Layers (JSON)', 'wic-tp' ); ?></strong></label>
			<textarea class="large-text code" rows="4" name="wic_tpl_layers" id="wic_tpl_layers"><?php echo esc_textarea( get_post_meta( $post->ID, '_wic_layers', true ) ); ?></textarea></p>
		<p><label for="wic_tpl_question"><strong><?php esc_html_e( 'Question skeleton (JSON)', 'wic-tp' ); ?></strong></label>
			<textarea class="large-text code" rows="4" name="wic_tpl_question" id="wic_tpl_question"><?php echo esc_textarea( get_post_meta( $post->ID, '_wic_question', true ) ); ?></textarea></p>
		<p class="description"><?php esc_html_e( 'The body above becomes the new slide\'s text. Keep templates free of real content — they are starting points.', 'wic-tp' ); ?></p>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['wic_tpl_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wic_tpl_nonce'] ), 'wic_tpl_meta' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$layout = isset( $_POST['wic_tpl_layout'] ) ? sanitize_key( $_POST['wic_tpl_layout'] ) : 'text';
		update_post_meta( $post_id, '_wic_layout', in_array( $layout, WIC_Content::layouts(), true ) ? $layout : 'text' );
		foreach ( array( 'wic_tpl_layers' => '_wic_layers', 'wic_tpl_question' => '_wic_question' ) as $field => $key ) {
			$raw = isset( $_POST[ $field ] ) ? trim( wp_unslash( $_POST[ $field ] ) ) : ''; // phpcs:ignore -- JSON, validated below.
			if ( '' === $raw || null !== json_decode( $raw, true ) ) {
				update_post_meta( $post_id, $key, wp_slash( $raw ) );
			}
		}
	}

	public static function apply_box( $post ) {
		$tpls = self::all();
		if ( ! $tpls ) {
			echo '<p>' . esc_html__( 'No templates yet.', 'wic-tp' ) . '</p>';
			return;
		}
		$base = wp_nonce_url( admin_url( 'admin-post.php?action=wic_tpl_apply&slide=' . $post->ID ), 'wic_tpl_apply_' . $post->ID );
		?>
		<p><label for="wic_tpl_pick"><?php esc_html_e( 'Template', 'wic-tp' ); ?></label>
			<select id="wic_tpl_pick" style="width:100%" onchange="document.getElementById('wic_tpl_go').href='<?php echo esc_js( $base ); ?>&template='+this.value;">
				<?php foreach ( $tpls as $t ) : ?>
					<option value="<?php echo (int) $t->ID; ?>"><?php echo esc_html( $t->post_title ); ?></option>
				<?php endforeach; ?>
			</select></p>
		<p><a class="button" id="wic_tpl_go" href="<?php echo esc_url( $base . '&template=' . $tpls[0]->ID ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Replace this slide\'s body, layout, layers and question with the template? Save your changes first.', 'wic-tp' ) ); ?>');"><?php esc_html_e( 'Apply template', 'wic-tp' ); ?></a></p>
		<?php
	}

	public static function handle_apply() {
		$slide = isset( $_GET['slide'] ) ? absint( $_GET['slide'] ) : 0;
		$tpl   = isset( $_GET['template'] ) ? absint( $_GET['template'] ) : 0;
		check_admin_referer( 'wic_tpl_apply_' . $slide );
		if ( ! current_user_can( 'edit_post', $slide ) || 'wic_slide' !== get_post_type( $slide ) || self::TYPE !== get_post_type( $tpl ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
		}
		wp_update_post(
			array(
				'ID'           => $slide,
				'post_content' => wp_slash( get_post_field( 'post_content', $tpl ) ),
			)
		);
		self::copy_meta( $tpl, $slide );
		wic_audit( 'slide_template', 'slide', $slide, array( 'template' => $tpl ) );
		wp_safe_redirect( get_edit_post_link( $slide, 'url' ) );
		exit;
	}

	/** Four neutral starting points. No subject content — authors write that. */
	public static function seed() {
		if ( get_option( 'wic_tpl_seeded' ) ) {
			return;
		}
		$defs = array(
			array( __( 'Heading and text', 'wic-tp' ), 'text', '<p>' . __( 'Write the one main point of this slide here, in two or three short sentences.', 'wic-tp' ) . '</p>', '' ),
			array( __( 'Image with explanation', 'wic-tp' ), 'image', '<p>' . __( 'Say what the image shows and why it matters. Add the image URL and a description of the image (alt text) before publishing.', 'wic-tp' ) . '</p>', '' ),
			array( __( 'Key point callout', 'wic-tp' ), 'callout', '<p>' . __( 'One thing to remember, stated plainly.', 'wic-tp' ) . '</p>', '' ),
			array(
				__( 'Reveal three points', 'wic-tp' ),
				'text',
				'<p>' . __( 'Introduce the three points. Learners select each button to reveal it.', 'wic-tp' ) . '</p>',
				wp_json_encode(
					array(
						array( 'label' => __( 'First point', 'wic-tp' ), 'content' => __( 'Detail for the first point.', 'wic-tp' ) ),
						array( 'label' => __( 'Second point', 'wic-tp' ), 'content' => __( 'Detail for the second point.', 'wic-tp' ) ),
						array( 'label' => __( 'Third point', 'wic-tp' ), 'content' => __( 'Detail for the third point.', 'wic-tp' ) ),
					)
				),
			),
		);
		foreach ( $defs as $i => $d ) {
			$id = wp_insert_post(
				array(
					'post_type'    => self::TYPE,
					'post_status'  => 'publish',
					'post_title'   => $d[0],
					'post_content' => $d[2],
					'menu_order'   => ( $i + 1 ) * 10,
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				update_post_meta( $id, '_wic_layout', $d[1] );
				if ( $d[3] ) {
					update_post_meta( $id, '_wic_layers', wp_slash( $d[3] ) );
				}
			}
		}
		update_option( 'wic_tpl_seeded', 1 );
	}
}

add_action( 'wic_register_types', array( 'WIC_Slide_Templates', 'register' ) );
add_action( 'wic_install', array( 'WIC_Slide_Templates', 'seed' ) );
add_action( 'wic_init', array( 'WIC_Slide_Templates', 'init' ) );
