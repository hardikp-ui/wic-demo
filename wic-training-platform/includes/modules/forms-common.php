<?php
/**
 * Small shared helpers for the forms, classroom, competency, paths and library modules.
 */

defined( 'ABSPATH' ) || exit;

class WIC_E {

	/** People the viewer manages, excluding themself, active only, sorted by name. */
	public static function team( $uid ) {
		$out = array();
		foreach ( array_diff( wic_scope_user_ids( $uid ), array( (int) $uid ) ) as $id ) {
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

	/** Published posts of a type as id => title. */
	public static function options( $type, $status = 'publish' ) {
		$posts = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => $status,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$out   = array();
		foreach ( $posts as $p ) {
			$out[ $p->ID ] = $p->post_title;
		}
		return $out;
	}

	/** Register a platform content type the way class-content.php does. */
	public static function register_type( $type, $plural, $singular, $supports = array( 'title', 'editor', 'revisions' ), $extra = array() ) {
		register_post_type(
			$type,
			array_merge(
				array(
					'public'          => false,
					'show_ui'         => true,
					'show_in_menu'    => 'wic-platform',
					'show_in_rest'    => false,
					'capability_type' => array( 'wic_item', 'wic_items' ),
					'map_meta_cap'    => true,
					'supports'        => $supports,
					'labels'          => array(
						'name'          => $plural,
						'singular_name' => $singular,
						/* translators: %s: content type name */
						'add_new_item'  => sprintf( __( 'Add %s', 'wic-tp' ), strtolower( $singular ) ),
						/* translators: %s: content type name */
						'edit_item'     => sprintf( __( 'Edit %s', 'wic-tp' ), strtolower( $singular ) ),
					),
				),
				$extra
			)
		);
	}

	/** Does a person match a "required for" rule? Empty rule matches nobody. */
	public static function matches( $user_id, $groups, $roles, $agency ) {
		$groups = array_filter( (array) $groups );
		$roles  = array_filter( (array) $roles );
		if ( ! $groups && ! $roles ) {
			return false;
		}
		if ( (int) $agency && (int) $agency !== wic_user_agency_id( $user_id ) ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		if ( $groups && in_array( wic_user_group( $user_id ), $groups, true ) ) {
			return true;
		}
		return (bool) array_intersect( $roles, (array) $user->roles );
	}

	/** Checkbox list for group/role targeting in meta boxes. */
	public static function audience_fields( $prefix, $groups, $roles, $agency = null ) {
		$groups = (array) $groups;
		$roles  = (array) $roles;
		echo '<p><strong>' . esc_html__( 'Groups', 'wic-tp' ) . '</strong><br>';
		foreach ( array( 'staff', 'intern' ) as $g ) {
			echo '<label style="margin-right:12px"><input type="checkbox" name="' . esc_attr( $prefix ) . '_groups[]" value="' . esc_attr( $g ) . '" ' . checked( in_array( $g, $groups, true ), true, false ) . '> ' . esc_html( wic_group_label( $g ) ) . '</label>';
		}
		echo '</p><p><strong>' . esc_html__( 'Roles', 'wic-tp' ) . '</strong><br>';
		foreach ( wp_roles()->roles as $key => $r ) {
			if ( 0 !== strpos( $key, 'wic_' ) ) {
				continue;
			}
			echo '<label style="margin-right:12px"><input type="checkbox" name="' . esc_attr( $prefix ) . '_roles[]" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, $roles, true ), true, false ) . '> ' . esc_html( translate_user_role( $r['name'] ) ) . '</label>';
		}
		echo '</p>';
		if ( null !== $agency ) {
			echo '<p><label>' . esc_html__( 'Only for agency ID (0 = every agency)', 'wic-tp' ) . ' <input type="number" min="0" name="' . esc_attr( $prefix ) . '_agency" value="' . esc_attr( (int) $agency ) . '" style="width:6em"></label></p>';
		}
	}

	public static function save_audience( $post_id, $prefix, $meta_prefix ) {
		$roles_ok = array_filter(
			array_keys( wp_roles()->roles ),
			function ( $r ) {
				return 0 === strpos( $r, 'wic_' );
			}
		);
		$groups   = isset( $_POST[ $prefix . '_groups' ] ) ? array_values( array_intersect( (array) wp_unslash( $_POST[ $prefix . '_groups' ] ), array( 'staff', 'intern' ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		$roles    = isset( $_POST[ $prefix . '_roles' ] ) ? array_values( array_intersect( (array) wp_unslash( $_POST[ $prefix . '_roles' ] ), $roles_ok ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		update_post_meta( $post_id, $meta_prefix . '_groups', $groups );
		update_post_meta( $post_id, $meta_prefix . '_roles', $roles );
		if ( isset( $_POST[ $prefix . '_agency' ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			update_post_meta( $post_id, $meta_prefix . '_agency', absint( $_POST[ $prefix . '_agency' ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
	}

	/** Standard guard for a meta-box save. */
	public static function can_save( $post_id, $nonce_field, $nonce_action ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}
		if ( ! isset( $_POST[ $nonce_field ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ $nonce_field ] ), $nonce_action ) ) {
			return false;
		}
		return current_user_can( 'edit_post', $post_id );
	}

	public static function deny() {
		wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
	}

	/** A person picker limited to the viewer's scope. */
	public static function person_select( $name, $id, $uid, $selected = 0, $required = true ) {
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '"' . ( $required ? ' required' : '' ) . '><option value="">' . esc_html__( 'Choose a person', 'wic-tp' ) . '</option>';
		foreach ( self::team( $uid ) as $u ) {
			echo '<option value="' . (int) $u->ID . '" ' . selected( (int) $selected, (int) $u->ID, false ) . '>' . esc_html( $u->display_name . ( wic_user_clinic_name( $u->ID ) ? ' — ' . wic_user_clinic_name( $u->ID ) : '' ) ) . '</option>';
		}
		echo '</select>';
	}

	public static function post_url() {
		return admin_url( 'admin-post.php' );
	}
}
