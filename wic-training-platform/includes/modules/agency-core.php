<?php
/**
 * Agencies: one platform, several state agencies, each with its own name, colours,
 * web address and people — and one shared library of courses.
 *
 * Agency 0 is the site's own default agency. Its courses (agency 0) form the shared
 * library that every agency can see. With no agencies created, nothing changes:
 * everybody is agency 0 and sees exactly what they saw before.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_table_sql',
	function ( $sql, $c ) {
		$sql[] = 'CREATE TABLE ' . wic_table( 'agencies' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(200) NOT NULL,
  slug varchar(100) NOT NULL,
  domain varchar(200) NOT NULL DEFAULT '',
  settings longtext NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY domain (domain)
) $c;";
		return $sql;
	},
	10,
	2
);

add_filter(
	'wic_agency_defaults',
	function ( $d ) {
		$d['decision_share_courses']    = 0;
		$d['decision_compare_agencies'] = 0;
		return $d;
	}
);

add_filter(
	'wic_agency_fields',
	function ( $f ) {
		$f['decision_share_courses']    = array( __( 'Agencies may share their own courses into the shared library', 'wic-tp' ), 'checkbox', __( 'Needs agreement on who owns a shared course and who may change it (item 140).', 'wic-tp' ) );
		$f['decision_compare_agencies'] = array( __( 'Platform administrators may compare agencies', 'wic-tp' ), 'checkbox', __( 'Needs agreement about who may see whose numbers (item 98).', 'wic-tp' ) );
		return $f;
	}
);

class WIC_Agencies {

	/** Settings an agency record may override. Everything else stays platform-wide. */
	const BRAND_KEYS = array( 'name', 'logo_url', 'color_primary', 'color_accent', 'color_ink', 'color_surface', 'cert_prefix', 'signatory_name', 'signatory_title', 'sender_name', 'pass_mark', 'announcement', 'announce_from', 'announce_until' );

	private static $cache   = array();
	private static $current = null;

	public static function init() {
		add_filter( 'wic_current_agency_id', array( __CLASS__, 'resolve_current' ) );
		add_filter( 'wic_setting', array( __CLASS__, 'setting' ), 10, 2 );
		add_filter( 'wic_scope_user_ids', array( __CLASS__, 'scope' ), 5, 2 );
		add_filter( 'wic_supervisors', array( __CLASS__, 'supervisors' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'remember_choice' ), 1 );
		add_action( 'user_register', array( __CLASS__, 'stamp_user' ), 5 );
		add_action( 'save_post_wic_course', array( __CLASS__, 'stamp_course' ), 5, 3 );
		add_action( 'pre_get_posts', array( __CLASS__, 'restrict_courses' ) );
		add_filter( 'map_meta_cap', array( __CLASS__, 'protect_shared' ), 10, 4 );
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		add_filter( 'wic_flash_messages', array( __CLASS__, 'messages' ) );
		add_action( 'admin_post_wic_agency_create', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_wic_agency_brand', array( __CLASS__, 'handle_brand' ) );
		add_action( 'admin_post_wic_agency_status', array( __CLASS__, 'handle_status' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'profile_field' ), 20 );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_field' ), 20 );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile_field' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile_field' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Records                                                            */
	/* ------------------------------------------------------------------ */

	public static function get( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( ! $id ) {
			return null;
		}
		if ( ! array_key_exists( $id, self::$cache ) ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'agencies' ) . ' WHERE id = %d', $id ) );
			if ( $row ) {
				$row->settings = $row->settings ? (array) json_decode( $row->settings, true ) : array();
			}
			self::$cache[ $id ] = $row;
		}
		return self::$cache[ $id ];
	}

	public static function by_slug( $slug ) {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . wic_table( 'agencies' ) . ' WHERE slug = %s', sanitize_title( $slug ) ) );
		return $id ? self::get( $id ) : null;
	}

	/** The table may not exist yet on the very first request after an update. */
	public static function table_ready() {
		static $ready = null;
		if ( null === $ready ) {
			global $wpdb;
			$table = wic_table( 'agencies' );
			$ready = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		}
		return $ready;
	}

	public static function all( $include_inactive = true ) {
		global $wpdb;
		$table = wic_table( 'agencies' );
		if ( ! self::table_ready() ) {
			return array();
		}
		$rows = $wpdb->get_results( 'SELECT id FROM ' . $table . ( $include_inactive ? '' : " WHERE status = 'active'" ) . ' ORDER BY name ASC' );
		return array_values( array_filter( array_map( array( __CLASS__, 'get' ), wp_list_pluck( $rows, 'id' ) ) ) );
	}

	public static function current() {
		return self::get( wic_current_agency_id() );
	}

	/** Display name for an agency ID, including the default agency. */
	public static function label( $id ) {
		if ( ! $id ) {
			$opts = get_option( 'wic_agency', array() );
			return ! empty( $opts['name'] ) ? $opts['name'] : get_bloginfo( 'name' );
		}
		$a = self::get( $id );
		return $a ? $a->name : sprintf( __( 'Agency #%d', 'wic-tp' ), $id );
	}

	public static function create( $name, $slug, $domain, $settings ) {
		global $wpdb;
		$slug = sanitize_title( $slug ? $slug : $name );
		if ( ! $slug || self::by_slug( $slug ) ) {
			return new WP_Error( 'wic_slug', __( 'That short name is already used by another agency.', 'wic-tp' ) );
		}
		$wpdb->insert(
			wic_table( 'agencies' ),
			array(
				'name'       => $name,
				'slug'       => $slug,
				'domain'     => self::clean_domain( $domain ),
				'settings'   => wp_json_encode( self::clean_settings( $settings ) ),
				'status'     => 'active',
				'created_at' => wic_now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		wic_audit( 'agency_create', 'agency', $id, array( 'name' => $name ) );
		return $id;
	}

	public static function update_settings( $id, $settings ) {
		global $wpdb;
		$wpdb->update( wic_table( 'agencies' ), array( 'settings' => wp_json_encode( self::clean_settings( $settings ) ) ), array( 'id' => (int) $id ) );
		unset( self::$cache[ (int) $id ] );
		wic_audit( 'agency_brand', 'agency', $id );
	}

	public static function clean_domain( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		$domain = preg_replace( '#^https?://#', '', $domain );
		return preg_replace( '/[^a-z0-9.\-:]/', '', strtok( $domain, '/' ) ?: '' );
	}

	public static function clean_settings( $in ) {
		$out = array();
		foreach ( self::BRAND_KEYS as $key ) {
			if ( ! isset( $in[ $key ] ) ) {
				continue;
			}
			$v = is_string( $in[ $key ] ) ? wp_unslash( $in[ $key ] ) : $in[ $key ];
			if ( 0 === strpos( $key, 'color_' ) ) {
				$v = (string) sanitize_hex_color( $v );
			} elseif ( 'logo_url' === $key ) {
				$v = esc_url_raw( $v );
			} elseif ( 'pass_mark' === $key ) {
				$v = '' === $v ? '' : min( 100, absint( $v ) );
			} elseif ( 'announcement' === $key ) {
				$v = sanitize_textarea_field( $v );
			} else {
				$v = sanitize_text_field( $v );
			}
			$out[ $key ] = $v;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Which agency is this request?                                      */
	/* ------------------------------------------------------------------ */

	/** ?wic_agency=slug (local testing) is remembered in a cookie; ?wic_agency=default clears it. */
	public static function remember_choice() {
		if ( ! isset( $_GET['wic_agency'] ) ) {
			return;
		}
		$slug = sanitize_title( wp_unslash( $_GET['wic_agency'] ) );
		$a    = 'default' === $slug ? null : self::by_slug( $slug );
		if ( headers_sent() ) {
			return;
		}
		if ( $a ) {
			setcookie( 'wic_agency', $a->slug, 0, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
			$_COOKIE['wic_agency'] = $a->slug;
		} else {
			setcookie( 'wic_agency', '', time() - HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
			unset( $_COOKIE['wic_agency'] );
		}
		self::$current = null;
	}

	/**
	 * Order: an explicit choice (query or cookie), then the signed-in person's agency,
	 * then the web address. Branding follows this; data scope always follows the person.
	 */
	public static function resolve_current( $id ) {
		if ( null !== self::$current ) {
			return self::$current;
		}
		self::$current = 0; // Guard against re-entry while resolving.
		$found         = 0;
		$choice        = '';
		if ( isset( $_GET['wic_agency'] ) ) {
			$choice = sanitize_title( wp_unslash( $_GET['wic_agency'] ) );
		} elseif ( isset( $_COOKIE['wic_agency'] ) ) {
			$choice = sanitize_title( wp_unslash( $_COOKIE['wic_agency'] ) );
		}
		if ( $choice && 'default' !== $choice ) {
			$a     = self::by_slug( $choice );
			$found = $a && 'active' === $a->status ? (int) $a->id : 0;
		}
		if ( ! $found && ! $choice && function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$found = (int) get_user_meta( get_current_user_id(), 'wic_agency_id', true );
		}
		if ( ! $found && ! $choice && ! empty( $_SERVER['HTTP_HOST'] ) ) {
			global $wpdb;
			$host  = self::clean_domain( wp_unslash( $_SERVER['HTTP_HOST'] ) );
			$table = wic_table( 'agencies' );
			if ( $host && self::table_ready() ) {
				$found = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $table . " WHERE domain = %s AND status = 'active' LIMIT 1", $host ) );
			}
		}
		self::$current = $found;
		return $found;
	}

	/** Brand settings come from the current agency's record where it sets them. */
	public static function setting( $value, $key ) {
		if ( ! in_array( $key, self::BRAND_KEYS, true ) ) {
			return $value;
		}
		$a = self::current();
		if ( $a && isset( $a->settings[ $key ] ) && '' !== $a->settings[ $key ] ) {
			return $a->settings[ $key ];
		}
		if ( $a && 'name' === $key ) {
			return $a->name;
		}
		return $value;
	}

	/* ------------------------------------------------------------------ */
	/* Data separation                                                    */
	/* ------------------------------------------------------------------ */

	/** Nobody sees another agency's people. The default agency's administrators see the whole platform. */
	public static function scope( $ids, $viewer_id ) {
		$mine = wic_user_agency_id( $viewer_id );
		if ( ! $mine ) {
			return $ids;
		}
		return array_values(
			array_filter(
				$ids,
				function ( $id ) use ( $mine, $viewer_id ) {
					return (int) $id === (int) $viewer_id || wic_user_agency_id( $id ) === $mine;
				}
			)
		);
	}

	/** Registration lists only the supervisors of the agency whose address this is. */
	public static function supervisors( $users, $for_registration ) {
		$logged = is_user_logged_in() && ! $for_registration;
		$agency = $logged ? wic_user_agency_id( get_current_user_id() ) : wic_current_agency_id();
		if ( ( ! $agency && $logged ) || ! self::all() ) {
			return $users; // Platform administrators pick from everyone.
		}
		return array_values(
			array_filter(
				$users,
				function ( $u ) use ( $agency ) {
					return wic_user_agency_id( $u->ID ) === $agency;
				}
			)
		);
	}

	public static function stamp_user( $user_id ) {
		if ( '' === get_user_meta( $user_id, 'wic_agency_id', true ) ) {
			$creator = get_current_user_id();
			$agency  = $creator ? wic_user_agency_id( $creator ) : wic_current_agency_id();
			if ( ! $agency ) {
				$agency = wic_current_agency_id();
			}
			update_user_meta( $user_id, 'wic_agency_id', (int) $agency );
		}
	}

	public static function stamp_course( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || '' !== get_post_meta( $post_id, '_wic_agency_id', true ) ) {
			return;
		}
		$uid = get_current_user_id();
		update_post_meta( $post_id, '_wic_agency_id', $uid ? wic_user_agency_id( $uid ) : 0 );
	}

	public static function course_agency( $course_id ) {
		return (int) get_post_meta( $course_id, '_wic_agency_id', true );
	}

	/** Own courses, the shared library, and (once agreed) courses other agencies have shared. */
	public static function course_visible( $course_id, $agency_id ) {
		$owner = self::course_agency( $course_id );
		if ( ! $owner || ! $agency_id || $owner === (int) $agency_id ) {
			return true;
		}
		return (int) wic_setting( 'decision_share_courses' ) && get_post_meta( $course_id, '_wic_shared', true );
	}

	/** Course lists everywhere (catalogue, pickers, rules) show own + shared only. */
	public static function restrict_courses( $q ) {
		$type = $q->get( 'post_type' );
		if ( 'wic_course' !== $type && array( 'wic_course' ) !== $type ) {
			return;
		}
		$uid    = get_current_user_id();
		$agency = $uid ? wic_user_agency_id( $uid ) : wic_current_agency_id();
		if ( ! $agency || $q->get( 'wic_all_agencies' ) ) {
			return;
		}
		$clause = array(
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
		if ( (int) wic_setting( 'decision_share_courses' ) ) {
			$clause[] = array(
				'key'   => '_wic_shared',
				'value' => '1',
			);
		}
		$meta   = $q->get( 'meta_query' );
		$meta   = is_array( $meta ) ? $meta : array();
		$meta[] = $clause;
		$q->set( 'meta_query', $meta );
	}

	/** Course, module or slide → its course ID. */
	public static function course_of( $post_id ) {
		$post = get_post( $post_id );
		for ( $i = 0; $post && $i < 3; $i++ ) {
			if ( 'wic_course' === $post->post_type ) {
				return (int) $post->ID;
			}
			$post = $post->post_parent ? get_post( $post->post_parent ) : null;
		}
		return 0;
	}

	/**
	 * A shared course is edited only by the default agency. Another agency makes its own
	 * copy (a fork) instead — and never edits another agency's courses at all.
	 */
	public static function protect_shared( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, array( 'edit_post', 'delete_post', 'publish_post' ), true ) || empty( $args[0] ) ) {
			return $caps;
		}
		$post = get_post( (int) $args[0] );
		if ( ! $post || ! in_array( $post->post_type, array( 'wic_course', 'wic_module', 'wic_slide' ), true ) ) {
			return $caps;
		}
		$mine = wic_user_agency_id( $user_id );
		if ( ! $mine ) {
			return $caps;
		}
		$course = self::course_of( $post->ID );
		if ( $course && self::course_agency( $course ) !== $mine ) {
			$caps[] = 'do_not_allow';
		}
		return $caps;
	}

	/* ------------------------------------------------------------------ */
	/* Usage and comparison                                               */
	/* ------------------------------------------------------------------ */

	public static function user_ids( $agency_id ) {
		if ( $agency_id ) {
			return array_map(
				'intval',
				get_users(
					array(
						'meta_key'   => 'wic_agency_id',
						'meta_value' => (int) $agency_id,
						'fields'     => 'ID',
					)
				)
			);
		}
		return array_map(
			'intval',
			get_users(
				array(
					'fields'     => 'ID',
					'meta_query' => array(
						'relation' => 'OR',
						array(
							'key'     => 'wic_agency_id',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'   => 'wic_agency_id',
							'value' => '0',
						),
					),
				)
			)
		);
	}

	/** Staff, courses, completions, certificates and file storage for one agency. */
	public static function usage( $agency_id ) {
		global $wpdb;
		$ids     = self::user_ids( $agency_id );
		$in      = $ids ? implode( ',', array_map( 'intval', $ids ) ) : '0';
		$courses = get_posts(
			array(
				'post_type'        => 'wic_course',
				'post_status'      => array( 'publish', 'draft', 'wic_retired' ),
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'wic_all_agencies' => true,
				'meta_query'       => $agency_id ? array(
					array(
						'key'   => '_wic_agency_id',
						'value' => (int) $agency_id,
					),
				) : array(
					'relation' => 'OR',
					array(
						'key'     => '_wic_agency_id',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => '_wic_agency_id',
						'value' => '0',
					),
				),
			)
		);
		$bytes   = 0;
		$files   = $ids ? $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_author IN ($in)" ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL -- integers only.
		foreach ( $files as $att ) {
			$path = get_attached_file( $att );
			if ( $path && file_exists( $path ) ) {
				$bytes += (int) filesize( $path );
			}
		}
		$active = 0;
		foreach ( $ids as $id ) {
			if ( 'active' === wic_user_status( $id ) ) {
				$active++;
			}
		}
		return array(
			'staff'        => count( $ids ),
			'active'       => $active,
			'courses'      => count( $courses ),
			'completions'  => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . wic_table( 'completions' ) . " WHERE user_id IN ($in)" ), // phpcs:ignore
			'certificates' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . wic_table( 'certificates' ) . " WHERE user_id IN ($in)" ), // phpcs:ignore
			'storage'      => $bytes,
			'files'        => count( $files ),
		);
	}

	/** Completion and overdue rates for one agency, over active people's active assignments. */
	public static function rates( $agency_id ) {
		$assigned = 0;
		$complete = 0;
		$overdue  = 0;
		foreach ( self::user_ids( $agency_id ) as $id ) {
			if ( 'active' !== wic_user_status( $id ) ) {
				continue;
			}
			foreach ( WIC_Records::user_assignments( $id ) as $a ) {
				$assigned++;
				if ( 'complete' === $a['status'] ) {
					$complete++;
				} elseif ( 'overdue' === $a['status'] ) {
					$overdue++;
				}
			}
		}
		return array(
			'assigned' => $assigned,
			'complete' => $complete,
			'overdue'  => $overdue,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Portal views                                                       */
	/* ------------------------------------------------------------------ */

	private static function is_platform_admin( $uid ) {
		return user_can( $uid, 'wic_manage_settings' ) && ! wic_user_agency_id( $uid );
	}

	public static function views( $views ) {
		$views['agencies']       = array(
			'label'    => __( 'Agencies', 'wic-tp' ),
			'group'    => 'agency',
			'cap'      => array( __CLASS__, 'is_platform_admin_cb' ),
			'callback' => array( __CLASS__, 'view_agencies' ),
			'order'    => 80,
		);
		$views['agency_brand']   = array(
			'label'    => __( 'Look and feel', 'wic-tp' ),
			'group'    => 'agency',
			'cap'      => 'wic_manage_settings',
			'callback' => array( __CLASS__, 'view_brand' ),
			'order'    => 82,
		);
		$views['agency_compare'] = array(
			'label'    => __( 'Compare agencies', 'wic-tp' ),
			'group'    => 'agency',
			'cap'      => array( __CLASS__, 'can_compare' ),
			'callback' => array( __CLASS__, 'view_compare' ),
			'order'    => 84,
		);
		return $views;
	}

	public static function is_platform_admin_cb( $uid ) {
		return self::is_platform_admin( $uid );
	}

	public static function can_compare( $uid ) {
		return self::is_platform_admin( $uid ) && (int) wic_setting( 'decision_compare_agencies' );
	}

	public static function messages( $m ) {
		$m['agency_created']   = __( 'Agency created. The first administrator has been sent a link to set their password.', 'wic-tp' );
		$m['agency_cloned']    = __( 'Agency created from the copied settings.', 'wic-tp' );
		$m['agency_branded']   = __( 'Look and feel saved.', 'wic-tp' );
		$m['agency_status']    = __( 'Agency status changed.', 'wic-tp' );
		$m['err_agency_slug']  = __( 'That short name is empty or already used by another agency.', 'wic-tp' );
		$m['err_agency_admin'] = __( 'The agency was created, but the administrator account could not be — that email may already be in use.', 'wic-tp' );
		return $m;
	}

	private static function size( $bytes ) {
		return size_format( $bytes, 1 ) ? size_format( $bytes, 1 ) : '0 B';
	}

	public static function view_agencies( $uid ) {
		$agencies = self::all();
		$rows     = array_merge( array( 0 ), wp_list_pluck( $agencies, 'id' ) );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Agencies on this platform', 'wic-tp' ); ?></h2>
		<p class="wic-help"><?php esc_html_e( 'Each agency has its own name, colours, web address and people. Courses of the default agency form the shared library every agency can use.', 'wic-tp' ); ?></p>
		<div class="wic-table-wrap">
			<table class="wic-table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Agency', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Web address', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Staff (active)', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Own courses', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Completions', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Certificates', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Storage', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'wic-tp' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $id ) : ?>
					<?php
					$a = $id ? self::get( $id ) : null;
					$u = self::usage( $id );
					?>
					<tr>
						<th scope="row">
							<?php echo esc_html( self::label( $id ) ); ?>
							<?php if ( ! $id ) : ?> <span class="wic-tag"><?php esc_html_e( 'Default · shared library', 'wic-tp' ); ?></span><?php endif; ?>
							<?php if ( $a ) : ?><br><a href="<?php echo esc_url( add_query_arg( 'wic_agency', $a->slug, wic_page_url( 'portal' ) ) ); ?>"><?php esc_html_e( 'View as this agency', 'wic-tp' ); ?></a><?php endif; ?>
						</th>
						<td><?php echo $a && $a->domain ? '<code>' . esc_html( $a->domain ) . '</code>' : '—'; ?></td>
						<td><?php echo (int) $u['staff']; ?> (<?php echo (int) $u['active']; ?>)</td>
						<td><?php echo (int) $u['courses']; ?></td>
						<td><?php echo (int) $u['completions']; ?></td>
						<td><?php echo (int) $u['certificates']; ?></td>
						<td><?php echo esc_html( self::size( $u['storage'] ) ); ?> <span class="wic-meta">(<?php echo (int) $u['files']; ?> <?php esc_html_e( 'files', 'wic-tp' ); ?>)</span></td>
						<td>
							<?php if ( $a ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-inline-form">
									<input type="hidden" name="action" value="wic_agency_status">
									<input type="hidden" name="agency" value="<?php echo (int) $a->id; ?>">
									<?php wp_nonce_field( 'wic_agency_status_' . $a->id ); ?>
									<span class="wic-badge wic-badge--<?php echo 'active' === $a->status ? 'complete' : 'not_started'; ?>"><?php echo 'active' === $a->status ? esc_html__( 'Active', 'wic-tp' ) : esc_html__( 'Suspended', 'wic-tp' ); ?></span>
									<button type="submit" class="wic-btn wic-btn--small" data-wic-confirm="<?php esc_attr_e( 'Change this agency\'s status? Its records are kept either way.', 'wic-tp' ); ?>"><?php echo 'active' === $a->status ? esc_html__( 'Suspend', 'wic-tp' ) : esc_html__( 'Reactivate', 'wic-tp' ); ?></button>
								</form>
							<?php else : ?>
								<span class="wic-badge wic-badge--complete"><?php esc_html_e( 'Active', 'wic-tp' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<section class="wic-section wic-two" style="margin-top:1.5rem">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form wic-panel" data-wic-brand-form>
				<h3 class="wic-h3"><?php esc_html_e( 'Set up a new agency', 'wic-tp' ); ?></h3>
				<p class="wic-help"><?php esc_html_e( 'A branded portal an agency can see before committing. The first administrator gets a one-time link to set their password.', 'wic-tp' ); ?></p>
				<input type="hidden" name="action" value="wic_agency_create">
				<?php wp_nonce_field( 'wic_agency_create' ); ?>
				<div class="wic-field"><label for="wic-ag-name"><?php esc_html_e( 'Agency name', 'wic-tp' ); ?></label><input id="wic-ag-name" name="name" required data-brand="name"></div>
				<div class="wic-field"><label for="wic-ag-slug"><?php esc_html_e( 'Short name (used in links)', 'wic-tp' ); ?></label><input id="wic-ag-slug" name="slug" pattern="[a-z0-9\-]+" placeholder="north-dakota"></div>
				<div class="wic-field"><label for="wic-ag-domain"><?php esc_html_e( 'Web address (optional)', 'wic-tp' ); ?></label><input id="wic-ag-domain" name="domain" placeholder="training.agency.example"><p class="wic-help"><?php esc_html_e( 'Point this address at the platform and staff will only ever see this agency\'s name.', 'wic-tp' ); ?></p></div>
				<?php self::brand_fields( array(), 'new' ); ?>
				<fieldset class="wic-field">
					<legend><strong><?php esc_html_e( 'First administrator', 'wic-tp' ); ?></strong></legend>
					<label for="wic-ag-first"><?php esc_html_e( 'Name', 'wic-tp' ); ?></label><input id="wic-ag-first" name="admin_name">
					<label for="wic-ag-email"><?php esc_html_e( 'Email', 'wic-tp' ); ?></label><input id="wic-ag-email" name="admin_email" type="email">
				</fieldset>
				<?php self::preview_panel(); ?>
				<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Create agency', 'wic-tp' ); ?></button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form wic-panel">
				<h3 class="wic-h3"><?php esc_html_e( 'Clone an agency\'s settings', 'wic-tp' ); ?></h3>
				<p class="wic-help"><?php esc_html_e( 'Copies name-independent settings — colours, logo, certificate signatory, pass mark — into a new agency. People and records are never copied.', 'wic-tp' ); ?></p>
				<input type="hidden" name="action" value="wic_agency_create">
				<input type="hidden" name="clone" value="1">
				<?php wp_nonce_field( 'wic_agency_create' ); ?>
				<div class="wic-field"><label for="wic-cl-from"><?php esc_html_e( 'Copy from', 'wic-tp' ); ?></label>
					<select id="wic-cl-from" name="from">
						<?php foreach ( $rows as $id ) : ?>
							<option value="<?php echo (int) $id; ?>"><?php echo esc_html( self::label( $id ) ); ?></option>
						<?php endforeach; ?>
					</select></div>
				<div class="wic-field"><label for="wic-cl-name"><?php esc_html_e( 'New agency name', 'wic-tp' ); ?></label><input id="wic-cl-name" name="name" required></div>
				<div class="wic-field"><label for="wic-cl-slug"><?php esc_html_e( 'Short name', 'wic-tp' ); ?></label><input id="wic-cl-slug" name="slug" pattern="[a-z0-9\-]+"></div>
				<div class="wic-field"><label for="wic-cl-domain"><?php esc_html_e( 'Web address (optional)', 'wic-tp' ); ?></label><input id="wic-cl-domain" name="domain"></div>
				<div class="wic-field"><label for="wic-cl-prefix"><?php esc_html_e( 'Certificate prefix', 'wic-tp' ); ?></label><input id="wic-cl-prefix" name="brand[cert_prefix]" maxlength="10"><p class="wic-help"><?php esc_html_e( 'Keep prefixes different so certificate numbers never clash between agencies.', 'wic-tp' ); ?></p></div>
				<button type="submit" class="wic-btn"><?php esc_html_e( 'Create from copy', 'wic-tp' ); ?></button>
			</form>
		</section>
		<?php
	}

	/** Brand inputs, shared by the set-up wizard and the look-and-feel screen. */
	private static function brand_fields( $values, $prefix ) {
		$fields = array(
			'logo_url'        => array( __( 'Logo URL', 'wic-tp' ), 'url' ),
			'color_primary'   => array( __( 'Primary colour', 'wic-tp' ), 'color' ),
			'color_accent'    => array( __( 'Accent colour', 'wic-tp' ), 'color' ),
			'color_ink'       => array( __( 'Text colour', 'wic-tp' ), 'color' ),
			'color_surface'   => array( __( 'Background colour', 'wic-tp' ), 'color' ),
			'cert_prefix'     => array( __( 'Certificate number prefix', 'wic-tp' ), 'text' ),
			'signatory_name'  => array( __( 'Certificate signatory name', 'wic-tp' ), 'text' ),
			'signatory_title' => array( __( 'Certificate signatory title', 'wic-tp' ), 'text' ),
			'sender_name'     => array( __( 'Email sender name', 'wic-tp' ), 'text' ),
		);
		$defaults = wic_agency_defaults();
		foreach ( $fields as $key => $f ) {
			$id  = 'wic-' . $prefix . '-' . $key;
			$val = isset( $values[ $key ] ) && '' !== $values[ $key ] ? $values[ $key ] : ( 'color' === $f[1] ? $defaults[ $key ] : '' );
			echo '<div class="wic-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $f[0] ) . '</label>';
			echo '<input type="' . esc_attr( $f[1] ) . '" id="' . esc_attr( $id ) . '" name="brand[' . esc_attr( $key ) . ']" value="' . esc_attr( $val ) . '" data-brand="' . esc_attr( $key ) . '"></div>';
		}
	}

	/** Live preview of the new look, with a contrast check, before anything is saved. */
	private static function preview_panel() {
		?>
		<div class="wic-brand-preview" data-wic-brand-preview aria-live="polite">
			<p class="wic-help"><strong><?php esc_html_e( 'Preview', 'wic-tp' ); ?></strong> — <?php esc_html_e( 'updates as you change the fields. Nothing is saved until you submit.', 'wic-tp' ); ?></p>
			<div class="wic-brand-preview__frame" data-preview-frame>
				<div class="wic-brand-preview__head"><img alt="" data-preview-logo hidden><span data-preview-name><?php echo esc_html( wic_setting( 'name' ) ); ?></span></div>
				<div class="wic-brand-preview__tabs"><span class="is-on"><?php esc_html_e( 'My training', 'wic-tp' ); ?></span><span><?php esc_html_e( 'Certificates', 'wic-tp' ); ?></span></div>
				<div class="wic-brand-preview__card">
					<strong><?php esc_html_e( 'Sample course', 'wic-tp' ); ?></strong>
					<div class="wic-brand-preview__bar"><span></span></div>
					<span class="wic-brand-preview__btn"><?php esc_html_e( 'Continue', 'wic-tp' ); ?></span>
					<a href="#" onclick="return false;"><?php esc_html_e( 'A link', 'wic-tp' ); ?></a>
				</div>
			</div>
			<p class="wic-help" data-preview-contrast></p>
		</div>
		<?php
	}

	public static function view_brand( $uid ) {
		$agency = wic_user_agency_id( $uid );
		$a      = $agency ? self::get( $agency ) : null;
		$values = $a ? $a->settings : get_option( 'wic_agency', array() );
		?>
		<h2 class="wic-h"><?php echo esc_html( sprintf( __( 'Look and feel — %s', 'wic-tp' ), self::label( $agency ) ) ); ?></h2>
		<p class="wic-help"><?php esc_html_e( 'Carried through to the portal, the lesson player, certificates and emails. Check the preview and the contrast line before saving.', 'wic-tp' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form wic-panel" data-wic-brand-form>
			<input type="hidden" name="action" value="wic_agency_brand">
			<?php wp_nonce_field( 'wic_agency_brand' ); ?>
			<div class="wic-field"><label for="wic-br-name"><?php esc_html_e( 'Agency name', 'wic-tp' ); ?></label><input id="wic-br-name" name="brand[name]" value="<?php echo esc_attr( self::label( $agency ) ); ?>" data-brand="name"></div>
			<?php self::brand_fields( $values, 'br' ); ?>
			<?php self::preview_panel(); ?>
			<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Save look and feel', 'wic-tp' ); ?></button>
		</form>
		<?php
	}

	public static function view_compare( $uid ) {
		$rows = array_merge( array( 0 ), wp_list_pluck( self::all(), 'id' ) );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Compare agencies', 'wic-tp' ); ?></h2>
		<div class="wic-notice wic-notice--warn"><?php esc_html_e( 'Visible to platform administrators only, because comparing agencies was switched on in Agency settings. Agencies themselves never see each other\'s numbers here.', 'wic-tp' ); ?></div>
		<div class="wic-table-wrap">
			<table class="wic-table" data-wic-sortable>
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Agency', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Active assignments', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Complete', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Completion rate', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Overdue', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Overdue rate', 'wic-tp' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $id ) : ?>
					<?php
					$r  = self::rates( $id );
					$cr = $r['assigned'] ? (int) round( $r['complete'] / $r['assigned'] * 100 ) : 0;
					$or = $r['assigned'] ? (int) round( $r['overdue'] / $r['assigned'] * 100 ) : 0;
					?>
					<tr>
						<th scope="row"><?php echo esc_html( self::label( $id ) ); ?></th>
						<td><?php echo (int) $r['assigned']; ?></td>
						<td><?php echo (int) $r['complete']; ?></td>
						<td data-sort="<?php echo (int) $cr; ?>"><?php echo $r['assigned'] ? (int) $cr . '%' : '—'; ?></td>
						<td><?php echo (int) $r['overdue']; ?></td>
						<td data-sort="<?php echo (int) $or; ?>"><?php echo $r['assigned'] ? (int) $or . '%' : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Handlers                                                           */
	/* ------------------------------------------------------------------ */

	public static function handle_create() {
		check_admin_referer( 'wic_agency_create' );
		$uid = get_current_user_id();
		if ( ! self::is_platform_admin( $uid ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
		}
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$slug  = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
		$dom   = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
		$brand = isset( $_POST['brand'] ) && is_array( $_POST['brand'] ) ? wp_unslash( $_POST['brand'] ) : array(); // phpcs:ignore -- cleaned in clean_settings().
		$clone = ! empty( $_POST['clone'] );
		if ( $clone ) {
			$from   = isset( $_POST['from'] ) ? absint( $_POST['from'] ) : 0;
			$source = $from ? ( self::get( $from ) ? self::get( $from )->settings : array() ) : get_option( 'wic_agency', array() );
			$copy   = array_intersect_key( (array) $source, array_flip( self::BRAND_KEYS ) );
			unset( $copy['name'], $copy['announcement'], $copy['announce_from'], $copy['announce_until'] );
			$brand = array_merge( $copy, array_filter( $brand, 'strlen' ) );
		}
		if ( ! $name ) {
			WIC_Portal::back( 'agencies', 'err_agency_slug' );
		}
		$id = self::create( $name, $slug, $dom, $brand );
		if ( is_wp_error( $id ) ) {
			WIC_Portal::back( 'agencies', 'err_agency_slug' );
		}
		if ( $clone ) {
			wic_audit( 'agency_clone', 'agency', $id, array( 'from' => isset( $from ) ? $from : 0 ) );
			WIC_Portal::back( 'agencies', 'agency_cloned' );
		}
		$email = isset( $_POST['admin_email'] ) ? sanitize_email( wp_unslash( $_POST['admin_email'] ) ) : '';
		$aname = isset( $_POST['admin_name'] ) ? sanitize_text_field( wp_unslash( $_POST['admin_name'] ) ) : '';
		if ( $email ) {
			if ( ! self::invite_admin( $id, $email, $aname ) ) {
				WIC_Portal::back( 'agencies', 'err_agency_admin' );
			}
		}
		WIC_Portal::back( 'agencies', 'agency_created' );
	}

	/** First administrator for a new agency: active at once, with a one-time set-password link. */
	private static function invite_admin( $agency_id, $email, $name ) {
		if ( ! is_email( $email ) || email_exists( $email ) ) {
			return false;
		}
		$login = sanitize_user( strtolower( current( explode( '@', $email ) ) ), true );
		$base  = $login ? $login : 'admin';
		$n     = 1;
		while ( ! $login || username_exists( $login ) ) {
			$login = $base . ( ++$n );
		}
		$parts   = explode( ' ', $name, 2 );
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'first_name'   => $parts[0],
				'last_name'    => isset( $parts[1] ) ? $parts[1] : '',
				'display_name' => $name ? $name : $login,
				'role'         => 'wic_admin',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return false;
		}
		update_user_meta( $user_id, 'wic_agency_id', (int) $agency_id );
		update_user_meta( $user_id, 'wic_status', 'active' );
		update_user_meta( $user_id, 'wic_reports_to', 0 );
		wic_audit( 'agency_admin_invite', 'user', $user_id, array( 'agency' => $agency_id ) );
		$user = get_userdata( $user_id );
		$key  = get_password_reset_key( $user );
		if ( ! is_wp_error( $key ) ) {
			$url = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
			WIC_Notify::mail(
				$email,
				__( 'Your agency training portal is ready', 'wic-tp' ),
				'<p>' . esc_html( sprintf( __( 'You are the first administrator for %s on the training platform.', 'wic-tp' ), self::label( $agency_id ) ) ) . '</p><p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Set my password', 'wic-tp' ) . '</a></p>'
			);
		}
		return true;
	}

	public static function handle_brand() {
		check_admin_referer( 'wic_agency_brand' );
		$uid = get_current_user_id();
		if ( ! current_user_can( 'wic_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
		}
		$brand  = isset( $_POST['brand'] ) && is_array( $_POST['brand'] ) ? wp_unslash( $_POST['brand'] ) : array(); // phpcs:ignore -- cleaned below.
		$agency = wic_user_agency_id( $uid );
		if ( $agency ) {
			$a     = self::get( $agency );
			$clean = array_merge( $a ? $a->settings : array(), self::clean_settings( $brand ) );
			if ( ! empty( $clean['name'] ) ) {
				global $wpdb;
				$wpdb->update( wic_table( 'agencies' ), array( 'name' => $clean['name'] ), array( 'id' => $agency ) );
			}
			self::update_settings( $agency, $clean );
		} else {
			$opts = get_option( 'wic_agency', array() );
			foreach ( self::clean_settings( $brand ) as $k => $v ) {
				$opts[ $k ] = $v;
			}
			update_option( 'wic_agency', $opts );
		}
		WIC_Portal::back( 'agency_brand', 'agency_branded' );
	}

	public static function handle_status() {
		global $wpdb;
		$id = isset( $_POST['agency'] ) ? absint( $_POST['agency'] ) : 0;
		check_admin_referer( 'wic_agency_status_' . $id );
		if ( ! self::is_platform_admin( get_current_user_id() ) || ! self::get( $id ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
		}
		$new = 'active' === self::get( $id )->status ? 'suspended' : 'active';
		$wpdb->update( wic_table( 'agencies' ), array( 'status' => $new ), array( 'id' => $id ) );
		wic_audit( 'agency_status', 'agency', $id, array( 'to' => $new ) );
		WIC_Portal::back( 'agencies', 'agency_status' );
	}

	/* ------------------------------------------------------------------ */
	/* WP-admin: which agency a person belongs to                         */
	/* ------------------------------------------------------------------ */

	public static function profile_field( $user ) {
		if ( ! current_user_can( 'wic_manage_people' ) || ! self::is_platform_admin( get_current_user_id() ) || ! self::all() ) {
			return;
		}
		$cur = wic_user_agency_id( $user->ID );
		wp_nonce_field( 'wic_agency_profile', 'wic_agency_profile_nonce' );
		?>
		<table class="form-table" role="presentation"><tr>
			<th><label for="wic_agency_id"><?php esc_html_e( 'Agency', 'wic-tp' ); ?></label></th>
			<td><select name="wic_agency_id" id="wic_agency_id">
				<option value="0"><?php echo esc_html( self::label( 0 ) ); ?></option>
				<?php foreach ( self::all() as $a ) : ?>
					<option value="<?php echo (int) $a->id; ?>" <?php selected( $cur, (int) $a->id ); ?>><?php echo esc_html( $a->name ); ?></option>
				<?php endforeach; ?>
			</select></td>
		</tr></table>
		<?php
	}

	public static function save_profile_field( $user_id ) {
		if ( ! isset( $_POST['wic_agency_profile_nonce'], $_POST['wic_agency_id'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wic_agency_profile_nonce'] ), 'wic_agency_profile' ) || ! self::is_platform_admin( get_current_user_id() ) ) {
			return;
		}
		$old = wic_user_agency_id( $user_id );
		$new = absint( $_POST['wic_agency_id'] );
		if ( $old !== $new ) {
			update_user_meta( $user_id, 'wic_agency_id', $new );
			wic_audit( 'user_agency', 'user', $user_id, array( 'from' => $old, 'to' => $new ) );
		}
	}
}

add_action( 'wic_init', array( 'WIC_Agencies', 'init' ) );
