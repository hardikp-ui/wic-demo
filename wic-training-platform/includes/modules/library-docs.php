<?php
/**
 * Resources and job aids (#113 document library, #114 ready-written messages,
 * #115 reference tables, #119 version history, #120 who opened which document,
 * #162–#164 placeholder categories for content the agency has to write).
 *
 * Documents keep every version; the current one is marked and older ones stay downloadable.
 * Opening a document goes through a tracked link, so "who has read the new policy?" is a query.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_table_sql',
	function ( $sql, $c ) {
		$sql[] = 'CREATE TABLE ' . wic_table( 'doc_opens' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  doc_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  version varchar(40) NOT NULL DEFAULT '',
  opened_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY doc_id (doc_id),
  KEY user_id (user_id)
) $c;";
		return $sql;
	},
	10,
	2
);

class WIC_Library {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'post_edit_form_tag', array( __CLASS__, 'multipart' ) );
		add_action( 'save_post_wic_doc', array( __CLASS__, 'save_doc' ) );
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		add_action( 'admin_post_wic_doc_open', array( __CLASS__, 'handle_open' ) );
	}

	public static function register() {
		WIC_E::register_type( 'wic_doc', __( 'Documents', 'wic-tp' ), __( 'Document', 'wic-tp' ), array( 'title', 'editor', 'revisions' ), array( 'taxonomies' => array( 'wic_doc_cat' ) ) );
		WIC_E::register_type( 'wic_message', __( 'Ready messages', 'wic-tp' ), __( 'Message', 'wic-tp' ), array( 'title', 'editor', 'revisions' ), array( 'taxonomies' => array( 'wic_message_cat' ) ) );
		WIC_E::register_type( 'wic_reference', __( 'Reference tables', 'wic-tp' ), __( 'Reference table', 'wic-tp' ) );
		$tax = array(
			'hierarchical'      => true,
			'public'            => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => false,
			'capabilities'      => array(
				'manage_terms' => 'wic_manage_content',
				'edit_terms'   => 'wic_manage_content',
				'delete_terms' => 'wic_manage_content',
				'assign_terms' => 'wic_manage_content',
			),
		);
		register_taxonomy( 'wic_doc_cat', 'wic_doc', $tax + array( 'labels' => array( 'name' => __( 'Document categories', 'wic-tp' ), 'singular_name' => __( 'Category', 'wic-tp' ) ) ) );
		register_taxonomy( 'wic_message_cat', 'wic_message', $tax + array( 'labels' => array( 'name' => __( 'Message categories', 'wic-tp' ), 'singular_name' => __( 'Category', 'wic-tp' ) ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Documents                                                          */
	/* ------------------------------------------------------------------ */

	/** Versions oldest first: array( 'label', 'url', 'attachment', 'date', 'note' ). */
	public static function versions( $doc_id ) {
		$v = get_post_meta( $doc_id, '_wic_doc_versions', true );
		return is_array( $v ) ? array_values( $v ) : array();
	}

	public static function current_index( $doc_id ) {
		$v = self::versions( $doc_id );
		if ( ! $v ) {
			return -1;
		}
		$i = get_post_meta( $doc_id, '_wic_doc_current', true );
		return ( '' !== $i && isset( $v[ (int) $i ] ) ) ? (int) $i : count( $v ) - 1;
	}

	/** Tracked link to one version of a document. */
	public static function open_url( $doc_id, $index = null ) {
		$args = array( 'action' => 'wic_doc_open', 'doc' => (int) $doc_id );
		if ( null !== $index ) {
			$args['v'] = (int) $index;
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'wic_doc_open_' . (int) $doc_id );
	}

	/** Who can see a document: everyone unless roles or groups are ticked. */
	public static function can_see( $doc_id, $uid ) {
		$groups = array_filter( (array) get_post_meta( $doc_id, '_wic_doc_groups', true ) );
		$roles  = array_filter( (array) get_post_meta( $doc_id, '_wic_doc_roles', true ) );
		if ( user_can( $uid, 'wic_manage_content' ) || user_can( $uid, 'wic_view_all' ) ) {
			return true;
		}
		if ( ! $groups && ! $roles ) {
			return true;
		}
		return WIC_E::matches( $uid, $groups, $roles, 0 );
	}

	public static function handle_open() {
		global $wpdb;
		$doc = isset( $_GET['doc'] ) ? absint( $_GET['doc'] ) : 0;
		check_admin_referer( 'wic_doc_open_' . $doc );
		$uid = get_current_user_id();
		if ( ! $uid || 'wic_doc' !== get_post_type( $doc ) || 'publish' !== get_post_status( $doc ) || ! self::can_see( $doc, $uid ) ) {
			WIC_E::deny();
		}
		$versions = self::versions( $doc );
		$i        = isset( $_GET['v'] ) ? absint( $_GET['v'] ) : self::current_index( $doc );
		if ( ! isset( $versions[ $i ] ) || empty( $versions[ $i ]['url'] ) ) {
			wp_die( esc_html__( 'This document has no file yet.', 'wic-tp' ), '', array( 'response' => 404, 'back_link' => true ) );
		}
		$wpdb->insert(
			wic_table( 'doc_opens' ),
			array(
				'doc_id'    => $doc,
				'user_id'   => $uid,
				'version'   => substr( (string) $versions[ $i ]['label'], 0, 40 ),
				'opened_at' => wic_now(),
			)
		);
		wp_redirect( esc_url_raw( $versions[ $i ]['url'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- document files may live on the agency's own file host.
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Authoring                                                          */
	/* ------------------------------------------------------------------ */

	public static function multipart( $post ) {
		if ( $post && 'wic_doc' === $post->post_type ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	public static function meta_boxes() {
		add_meta_box( 'wic_doc_meta', __( 'Files and versions', 'wic-tp' ), array( __CLASS__, 'doc_box' ), 'wic_doc', 'normal', 'high' );
		add_meta_box( 'wic_doc_audience', __( 'Who sees this document', 'wic-tp' ), array( __CLASS__, 'audience_box' ), 'wic_doc', 'side' );
	}

	public static function doc_box( $post ) {
		wp_nonce_field( 'wic_doc_meta', 'wic_doc_nonce' );
		$versions = self::versions( $post->ID );
		$current  = self::current_index( $post->ID );
		?>
		<p><?php esc_html_e( 'The editor above is a short description shown in the library. Every file you add is kept; choose which one is current.', 'wic-tp' ); ?></p>
		<?php if ( $versions ) : ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Current', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Version', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Added', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Note', 'wic-tp' ); ?></th><th><?php esc_html_e( 'File', 'wic-tp' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $versions as $i => $v ) : ?>
					<tr>
						<td><label><input type="radio" name="wic_doc_current" value="<?php echo (int) $i; ?>" <?php checked( $current, $i ); ?>> <span class="screen-reader-text"><?php esc_html_e( 'Make current', 'wic-tp' ); ?></span></label></td>
						<td><?php echo esc_html( $v['label'] ); ?></td>
						<td><?php echo esc_html( wic_format_date( $v['date'] ) ); ?></td>
						<td><?php echo esc_html( $v['note'] ); ?></td>
						<td><a href="<?php echo esc_url( $v['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'wic-tp' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<h4><?php esc_html_e( 'Add a new version', 'wic-tp' ); ?></h4>
		<table class="form-table" role="presentation">
			<tr><th><label for="wic_doc_file"><?php esc_html_e( 'Upload a file', 'wic-tp' ); ?></label></th><td><input type="file" id="wic_doc_file" name="wic_doc_file"></td></tr>
			<tr><th><label for="wic_doc_url"><?php esc_html_e( '…or link to a file', 'wic-tp' ); ?></label></th><td><input type="url" class="large-text" id="wic_doc_url" name="wic_doc_url"></td></tr>
			<tr><th><label for="wic_doc_label"><?php esc_html_e( 'Version label', 'wic-tp' ); ?></label></th><td><input type="text" id="wic_doc_label" name="wic_doc_label" placeholder="<?php echo esc_attr( sprintf( __( 'Version %d', 'wic-tp' ), count( $versions ) + 1 ) ); ?>"></td></tr>
			<tr><th><label for="wic_doc_note"><?php esc_html_e( 'What changed', 'wic-tp' ); ?></label></th><td><input type="text" class="large-text" id="wic_doc_note" name="wic_doc_note"></td></tr>
		</table>
		<p class="description"><?php esc_html_e( 'A new version becomes the current one.', 'wic-tp' ); ?></p>
		<?php
	}

	public static function audience_box( $post ) {
		WIC_E::audience_fields( 'wic_doc', get_post_meta( $post->ID, '_wic_doc_groups', true ), get_post_meta( $post->ID, '_wic_doc_roles', true ) );
		echo '<p class="description">' . esc_html__( 'Leave all unticked for everyone.', 'wic-tp' ) . '</p>';
	}

	public static function save_doc( $post_id ) {
		if ( ! WIC_E::can_save( $post_id, 'wic_doc_nonce', 'wic_doc_meta' ) ) {
			return;
		}
		$versions = self::versions( $post_id );
		$url      = '';
		$att      = 0;
		if ( ! empty( $_FILES['wic_doc_file']['name'] ) && current_user_can( 'upload_files' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$att = media_handle_upload( 'wic_doc_file', $post_id );
			if ( ! is_wp_error( $att ) ) {
				$url = wp_get_attachment_url( $att );
			} else {
				$att = 0;
			}
		}
		if ( ! $url && ! empty( $_POST['wic_doc_url'] ) ) {
			$url = esc_url_raw( wp_unslash( $_POST['wic_doc_url'] ) );
		}
		if ( $url ) {
			$label      = isset( $_POST['wic_doc_label'] ) ? sanitize_text_field( wp_unslash( $_POST['wic_doc_label'] ) ) : '';
			$versions[] = array(
				'label'      => '' !== $label ? $label : sprintf( __( 'Version %d', 'wic-tp' ), count( $versions ) + 1 ),
				'url'        => $url,
				'attachment' => (int) $att,
				'date'       => wic_now(),
				'note'       => isset( $_POST['wic_doc_note'] ) ? sanitize_text_field( wp_unslash( $_POST['wic_doc_note'] ) ) : '',
			);
			update_post_meta( $post_id, '_wic_doc_versions', $versions );
			update_post_meta( $post_id, '_wic_doc_current', count( $versions ) - 1 );
			wic_audit( 'doc_version', 'doc', $post_id, array( 'version' => count( $versions ) ) );
		} elseif ( isset( $_POST['wic_doc_current'] ) && isset( $versions[ absint( $_POST['wic_doc_current'] ) ] ) ) {
			update_post_meta( $post_id, '_wic_doc_current', absint( $_POST['wic_doc_current'] ) );
		}
		WIC_E::save_audience( $post_id, 'wic_doc', '_wic_doc' );
	}

	/* ------------------------------------------------------------------ */
	/* Portal                                                             */
	/* ------------------------------------------------------------------ */

	public static function views( $views ) {
		$views['library']    = array( 'label' => __( 'Library', 'wic-tp' ), 'group' => 'learn', 'cap' => 'wic_learn', 'callback' => array( __CLASS__, 'view_library' ), 'order' => 70 );
		$views['messages']   = array( 'label' => __( 'Ready messages', 'wic-tp' ), 'group' => 'learn', 'cap' => 'wic_learn', 'callback' => array( __CLASS__, 'view_messages' ), 'order' => 72 );
		$views['references'] = array( 'label' => __( 'Reference tables', 'wic-tp' ), 'group' => 'learn', 'cap' => 'wic_learn', 'callback' => array( __CLASS__, 'view_references' ), 'order' => 74 );
		$views['doc_opens']  = array( 'label' => __( 'Document reads', 'wic-tp' ), 'group' => 'team', 'cap' => 'wic_view_team', 'callback' => array( __CLASS__, 'view_opens' ), 'order' => 70 );
		return $views;
	}

	/** Search box shared by the three library views. */
	private static function search_form( $view, $q, $tax = '', $cat = 0 ) {
		?>
		<form method="get" class="wic-form wic-form--inline" role="search">
			<?php if ( ! get_option( 'permalink_structure' ) ) : ?><input type="hidden" name="page_id" value="<?php echo (int) wic_page_id( 'portal' ); ?>"><?php endif; ?>
			<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
			<div class="wic-field"><label for="wic-lib-q"><?php esc_html_e( 'Search', 'wic-tp' ); ?></label><input type="search" id="wic-lib-q" name="q" value="<?php echo esc_attr( $q ); ?>"></div>
			<?php if ( $tax ) : ?>
				<?php $terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) ); ?>
				<?php if ( $terms && ! is_wp_error( $terms ) ) : ?>
					<div class="wic-field"><label for="wic-lib-cat"><?php esc_html_e( 'Category', 'wic-tp' ); ?></label>
						<select id="wic-lib-cat" name="cat"><option value="0"><?php esc_html_e( 'All categories', 'wic-tp' ); ?></option>
							<?php foreach ( $terms as $t ) : ?><option value="<?php echo (int) $t->term_id; ?>" <?php selected( $cat, $t->term_id ); ?>><?php echo esc_html( $t->name ); ?></option><?php endforeach; ?>
						</select></div>
				<?php endif; ?>
			<?php endif; ?>
			<button type="submit" class="wic-btn"><?php esc_html_e( 'Search', 'wic-tp' ); ?></button>
		</form>
		<?php
	}

	/** Published posts of a type, filtered accent-insensitively on title, content and terms. */
	private static function find( $type, $q, $tax = '', $cat = 0 ) {
		$args = array(
			'post_type'      => $type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);
		if ( $tax && $cat ) {
			$args['tax_query'] = array( array( 'taxonomy' => $tax, 'terms' => (int) $cat ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$posts  = get_posts( $args );
		$needle = wic_fold( $q );
		if ( '' === trim( $needle ) ) {
			return $posts;
		}
		return array_values(
			array_filter(
				$posts,
				function ( $p ) use ( $needle, $tax ) {
					$hay = $p->post_title . ' ' . $p->post_content;
					if ( $tax ) {
						$hay .= ' ' . implode( ' ', wp_get_post_terms( $p->ID, $tax, array( 'fields' => 'names' ) ) );
					}
					return false !== strpos( wic_fold( $hay ), $needle );
				}
			)
		);
	}

	public static function view_library( $uid ) {
		$q    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$cat  = isset( $_GET['cat'] ) ? absint( $_GET['cat'] ) : 0;
		$docs = array_filter(
			self::find( 'wic_doc', $q, 'wic_doc_cat', $cat ),
			function ( $d ) use ( $uid ) {
				return self::can_see( $d->ID, $uid );
			}
		);
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Document library', 'wic-tp' ); ?></h2>
		<?php self::search_form( 'library', $q, 'wic_doc_cat', $cat ); ?>
		<p class="wic-live screen-reader-text" aria-live="polite"><?php echo esc_html( sprintf( _n( '%d document found', '%d documents found', count( $docs ), 'wic-tp' ), count( $docs ) ) ); ?></p>
		<?php if ( ! $docs ) : ?>
			<div class="wic-empty"><p><?php echo $q ? esc_html__( 'No documents match that search.', 'wic-tp' ) : esc_html__( 'The library is empty.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<div class="wic-grid">
		<?php foreach ( $docs as $d ) : ?>
			<?php
			$versions = self::versions( $d->ID );
			$cur      = self::current_index( $d->ID );
			$cats     = wp_get_post_terms( $d->ID, 'wic_doc_cat', array( 'fields' => 'names' ) );
			?>
			<article class="wic-card">
				<div class="wic-card__top"><h3><?php echo esc_html( $d->post_title ); ?></h3></div>
				<?php if ( $cats ) : ?><p class="wic-meta"><?php echo esc_html( implode( ', ', $cats ) ); ?></p><?php endif; ?>
				<?php if ( $d->post_content ) : ?><div class="wic-meta"><?php echo wp_kses_post( wpautop( wp_trim_words( $d->post_content, 40 ) ) ); ?></div><?php endif; ?>
				<?php if ( $cur >= 0 ) : ?>
					<p class="wic-meta"><?php echo esc_html( sprintf( __( 'Current: %1$s, %2$s', 'wic-tp' ), $versions[ $cur ]['label'], wic_format_date( $versions[ $cur ]['date'] ) ) ); ?></p>
					<div class="wic-card__actions"><a class="wic-btn wic-btn--primary" href="<?php echo esc_url( self::open_url( $d->ID ) ); ?>"><?php esc_html_e( 'Open', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( $d->post_title ); ?></span></a></div>
					<?php if ( count( $versions ) > 1 ) : ?>
						<details><summary><?php esc_html_e( 'Version history', 'wic-tp' ); ?></summary>
							<ul>
							<?php foreach ( array_reverse( $versions, true ) as $i => $v ) : ?>
								<li><a href="<?php echo esc_url( self::open_url( $d->ID, $i ) ); ?>"><?php echo esc_html( $v['label'] ); ?></a> — <?php echo esc_html( wic_format_date( $v['date'] ) ); ?><?php echo $i === $cur ? ' <strong>(' . esc_html__( 'current', 'wic-tp' ) . ')</strong>' : ''; ?><?php echo $v['note'] ? ' · ' . esc_html( $v['note'] ) : ''; ?></li>
							<?php endforeach; ?>
							</ul>
						</details>
					<?php endif; ?>
				<?php else : ?>
					<p class="wic-meta"><?php esc_html_e( 'No file attached yet.', 'wic-tp' ); ?></p>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
		</div>
		<?php
	}

	public static function view_messages( $uid ) {
		$q    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$cat  = isset( $_GET['cat'] ) ? absint( $_GET['cat'] ) : 0;
		$msgs = self::find( 'wic_message', $q, 'wic_message_cat', $cat );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Ready-written messages', 'wic-tp' ); ?></h2>
		<p class="wic-help"><?php esc_html_e( 'Approved wording for texting and emailing participants. Copy one and paste it into your message.', 'wic-tp' ); ?></p>
		<?php self::search_form( 'messages', $q, 'wic_message_cat', $cat ); ?>
		<?php if ( ! $msgs ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'No messages found.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<div class="wic-grid">
		<?php foreach ( $msgs as $m ) : ?>
			<?php $text = trim( wp_strip_all_tags( $m->post_content ) ); ?>
			<article class="wic-card">
				<h3><?php echo esc_html( $m->post_title ); ?></h3>
				<?php $cats = wp_get_post_terms( $m->ID, 'wic_message_cat', array( 'fields' => 'names' ) ); ?>
				<?php if ( $cats ) : ?><p class="wic-meta"><?php echo esc_html( implode( ', ', $cats ) ); ?></p><?php endif; ?>
				<p class="wic-message-text" id="wic-msg-<?php echo (int) $m->ID; ?>"><?php echo nl2br( esc_html( $text ) ); ?></p>
				<div class="wic-card__actions">
					<button type="button" class="wic-btn" data-wic-copy="wic-msg-<?php echo (int) $m->ID; ?>"><?php esc_html_e( 'Copy', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( $m->post_title ); ?></span></button>
				</div>
			</article>
		<?php endforeach; ?>
		</div>
		<p class="screen-reader-text" aria-live="polite" data-wic-copy-status></p>
		<?php
	}

	public static function view_references( $uid ) {
		$q    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$refs = self::find( 'wic_reference', $q );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Reference tables', 'wic-tp' ); ?></h2>
		<?php self::search_form( 'references', $q ); ?>
		<?php if ( ! $refs ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'No reference tables found.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		foreach ( $refs as $r ) {
			echo '<section class="wic-section wic-reference"><h3 class="wic-h3">' . esc_html( $r->post_title ) . '</h3><div class="wic-table-wrap">' . self::captioned( $r->post_content, $r->post_title ) . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput -- kses applied in captioned().
		}
	}

	/** Reference bodies are author HTML tables; give each a caption and header scopes. */
	private static function captioned( $html, $title ) {
		$html = wp_kses_post( $html );
		if ( false === stripos( $html, '<table' ) ) {
			return '<div class="wic-panel">' . wpautop( $html ) . '</div>';
		}
		$html = preg_replace( '/<table(?![^>]*class=)/i', '<table class="wic-table"', $html, 1 );
		if ( false === stripos( $html, '<caption' ) ) {
			$html = preg_replace( '/(<table[^>]*>)/i', '$1<caption class="screen-reader-text">' . esc_html( $title ) . '</caption>', $html, 1 );
		}
		return preg_replace( '/<th(?![^>]*scope=)/i', '<th scope="col"', $html );
	}

	public static function view_opens( $uid ) {
		global $wpdb;
		$doc  = isset( $_GET['doc'] ) ? absint( $_GET['doc'] ) : 0;
		$docs = WIC_E::options( 'wic_doc' );
		$team = WIC_E::team( $uid );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Who has opened which document', 'wic-tp' ); ?></h2>
		<form method="get" class="wic-form wic-form--inline">
			<?php if ( ! get_option( 'permalink_structure' ) ) : ?><input type="hidden" name="page_id" value="<?php echo (int) wic_page_id( 'portal' ); ?>"><?php endif; ?>
			<input type="hidden" name="view" value="doc_opens">
			<div class="wic-field"><label for="wic-op-doc"><?php esc_html_e( 'Document', 'wic-tp' ); ?></label>
				<select id="wic-op-doc" name="doc"><option value="0"><?php esc_html_e( 'Summary of every document', 'wic-tp' ); ?></option>
					<?php foreach ( $docs as $id => $t ) : ?><option value="<?php echo (int) $id; ?>" <?php selected( $doc, $id ); ?>><?php echo esc_html( $t ); ?></option><?php endforeach; ?>
				</select></div>
			<button type="submit" class="wic-btn"><?php esc_html_e( 'Show', 'wic-tp' ); ?></button>
		</form>
		<?php
		$ids = array_map(
			function ( $u ) {
				return (int) $u->ID;
			},
			$team
		);
		if ( ! $ids ) {
			echo '<div class="wic-empty"><p>' . esc_html__( 'Nobody reports to you yet.', 'wic-tp' ) . '</p></div>';
			return;
		}
		$in = implode( ',', $ids );
		if ( ! $doc ) {
			$rows = $wpdb->get_results( 'SELECT doc_id, COUNT(DISTINCT user_id) AS people, COUNT(*) AS opens, MAX(opened_at) AS last FROM ' . wic_table( 'doc_opens' ) . " WHERE user_id IN ($in) GROUP BY doc_id" ); // phpcs:ignore WordPress.DB.PreparedSQL -- integer list.
			$by   = array();
			foreach ( $rows as $r ) {
				$by[ (int) $r->doc_id ] = $r;
			}
			echo '<div class="wic-table-wrap"><table class="wic-table" data-wic-sortable><thead><tr><th scope="col">' . esc_html__( 'Document', 'wic-tp' ) . '</th><th scope="col">' . esc_html__( 'People who opened it', 'wic-tp' ) . '</th><th scope="col">' . esc_html__( 'Opens', 'wic-tp' ) . '</th><th scope="col">' . esc_html__( 'Last opened', 'wic-tp' ) . '</th></tr></thead><tbody>';
			foreach ( $docs as $id => $t ) {
				$r = isset( $by[ $id ] ) ? $by[ $id ] : null;
				echo '<tr><td><a href="' . esc_url( wic_portal_url( 'doc_opens', array( 'doc' => $id ) ) ) . '">' . esc_html( $t ) . '</a></td><td data-sort="' . ( $r ? (int) $r->people : 0 ) . '">' . ( $r ? (int) $r->people : 0 ) . ' / ' . count( $ids ) . '</td><td>' . ( $r ? (int) $r->opens : 0 ) . '</td><td>' . esc_html( $r ? wic_format_date( $r->last ) : __( 'Never', 'wic-tp' ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
			return;
		}
		// Newest first, so the first row seen per person is their latest open.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, opened_at, version FROM ' . wic_table( 'doc_opens' ) . " WHERE doc_id = %d AND user_id IN ($in) ORDER BY opened_at DESC, id DESC", $doc ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- integer list.
		$by   = array();
		foreach ( $rows as $r ) {
			if ( ! isset( $by[ (int) $r->user_id ] ) ) {
				$by[ (int) $r->user_id ] = (object) array( 'last' => $r->opened_at, 'version' => $r->version );
			}
		}
		$cur     = self::current_index( $doc );
		$vs      = self::versions( $doc );
		$current = $cur >= 0 ? $vs[ $cur ]['label'] : '';
		echo '<div class="wic-table-wrap"><table class="wic-table" data-wic-sortable><thead><tr><th scope="col">' . esc_html__( 'Name', 'wic-tp' ) . '</th><th scope="col">' . esc_html__( 'Clinic', 'wic-tp' ) . '</th><th scope="col">' . esc_html__( 'Opened', 'wic-tp' ) . '</th><th scope="col">' . esc_html__( 'Last version opened', 'wic-tp' ) . '</th></tr></thead><tbody>';
		foreach ( $team as $u ) {
			$r = isset( $by[ $u->ID ] ) ? $by[ $u->ID ] : null;
			echo '<tr><td>' . esc_html( $u->display_name ) . '</td><td>' . esc_html( wic_user_clinic_name( $u->ID ) ) . '</td><td data-sort="' . esc_attr( $r ? $r->last : '' ) . '">' . ( $r ? esc_html( wic_format_date( $r->last, true ) ) : '<strong class="wic-late">' . esc_html__( 'Not opened', 'wic-tp' ) . '</strong>' ) . '</td><td>' . esc_html( $r ? $r->version . ( $r->version === $current ? ' (' . __( 'current', 'wic-tp' ) . ')' : '' ) : '—' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}

add_action( 'wic_init', array( 'WIC_Library', 'init' ) );
add_action( 'wic_register_types', array( 'WIC_Library', 'register' ) );

/*
 * Placeholders only. The platform does not write telehealth, remote appointment or
 * transfer guidance: these categories and empty documents mark what the agency must supply.
 */
add_action(
	'wic_install',
	function () {
		if ( get_option( 'wic_sample_library_seeded' ) || ! taxonomy_exists( 'wic_doc_cat' ) ) {
			return;
		}
		$needed = array(
			'Telehealth'                 => 'Telehealth guidance for staff (Content needed)',
			'Remote appointments'        => 'Remote appointment support materials (Content needed)',
			'Out-of-state transfers'     => 'Out-of-state transfer guidance (Content needed)',
		);
		foreach ( $needed as $cat => $title ) {
			$term = term_exists( $cat, 'wic_doc_cat' );
			if ( ! $term ) {
				$term = wp_insert_term( $cat, 'wic_doc_cat' );
			}
			$id = wp_insert_post(
				array(
					'post_type'    => 'wic_doc',
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => 'Content needed. This document has not been written. It must be written and approved by the agency before staff rely on it. No file is attached.',
				)
			);
			if ( $id && ! is_wp_error( $id ) && ! is_wp_error( $term ) ) {
				wp_set_object_terms( $id, (int) ( is_array( $term ) ? $term['term_id'] : $term ), 'wic_doc_cat' );
			}
		}
		wp_insert_post(
			array(
				'post_type'    => 'wic_message',
				'post_status'  => 'draft',
				'post_title'   => 'Appointment reminder (Sample)',
				'post_content' => 'Sample placeholder. Replace with wording your agency has approved before publishing.',
			)
		);
		update_option( 'wic_sample_library_seeded', 1 );
	}
);
