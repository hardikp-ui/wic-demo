<?php
/**
 * Read-only access to records for an agency's own reporting (#158).
 *
 * GET /wp-json/wic/v1/data/{people|assignments|completions|certificates}?page=1&per_page=100[&since=YYYY-MM-DD]
 * with the header "Authorization: Bearer <key>" (or "X-WIC-Key: <key>").
 *
 * Keys are created and revoked in the portal. Only a hash is stored and the key is shown
 * once. Each key belongs to one agency and only ever sees that agency's people.
 * Every call is written to the audit log.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_table_sql',
	function ( $sql, $c ) {
		$sql[] = 'CREATE TABLE ' . wic_table( 'api_keys' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  label varchar(120) NOT NULL DEFAULT '',
  key_prefix varchar(16) NOT NULL,
  key_hash varchar(64) NOT NULL,
  agency_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  last_used datetime NULL,
  calls bigint(20) unsigned NOT NULL DEFAULT 0,
  revoked_at datetime NULL,
  PRIMARY KEY  (id),
  KEY key_prefix (key_prefix)
) $c;";
		return $sql;
	},
	10,
	2
);

class WIC_Data_Api {

	const RESOURCES = array( 'people', 'assignments', 'completions', 'certificates' );

	/** The key that authenticated this request. */
	private static $key = null;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		add_filter( 'wic_flash_messages', array( __CLASS__, 'messages' ) );
		add_action( 'admin_post_wic_api_key_create', array( __CLASS__, 'create' ) );
		add_action( 'admin_post_wic_api_key_revoke', array( __CLASS__, 'revoke' ) );
	}

	private static function hash( $key ) {
		return hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Authentication                                                     */
	/* ------------------------------------------------------------------ */

	private static function presented_key( WP_REST_Request $req ) {
		$h = (string) $req->get_header( 'authorization' );
		if ( preg_match( '/^Bearer\s+(\S+)$/i', $h, $m ) ) {
			return $m[1];
		}
		return (string) $req->get_header( 'x_wic_key' );
	}

	public static function authenticate( WP_REST_Request $req ) {
		global $wpdb;
		$key = self::presented_key( $req );
		if ( ! preg_match( '/^wic_[A-Za-z0-9]{40}$/', $key ) ) {
			return new WP_Error( 'wic_api_key', __( 'A valid API key is required.', 'wic-tp' ), array( 'status' => 401 ) );
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'api_keys' ) . ' WHERE key_prefix = %s AND revoked_at IS NULL', substr( $key, 0, 12 ) ) );
		if ( ! $row || ! hash_equals( $row->key_hash, self::hash( $key ) ) ) {
			return new WP_Error( 'wic_api_key', __( 'A valid API key is required.', 'wic-tp' ), array( 'status' => 401 ) );
		}
		self::$key = $row;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . wic_table( 'api_keys' ) . ' SET last_used = %s, calls = calls + 1 WHERE id = %d', wic_now(), $row->id ) );
		return true;
	}

	public static function routes() {
		foreach ( self::RESOURCES as $r ) {
			register_rest_route(
				'wic/v1',
				'/data/' . $r,
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'serve' ),
					'permission_callback' => array( __CLASS__, 'authenticate' ),
					'args'                => array(
						'page'     => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
						'per_page' => array( 'default' => 100, 'sanitize_callback' => 'absint' ),
						'since'    => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					),
				)
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Data                                                               */
	/* ------------------------------------------------------------------ */

	/** Everyone on the platform who belongs to the key's agency. */
	private static function agency_user_ids( $agency_id ) {
		$ids = get_users(
			array(
				'role__in' => WIC_Roles::all_roles(),
				'fields'   => 'ID',
				'orderby'  => 'ID',
				'order'    => 'ASC',
			)
		);
		return array_values(
			array_filter(
				array_map( 'intval', $ids ),
				function ( $id ) use ( $agency_id ) {
					return wic_user_agency_id( $id ) === (int) $agency_id;
				}
			)
		);
	}

	private static function iso( $mysql ) {
		return $mysql ? gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $mysql . ' UTC' ) ) : null;
	}

	public static function serve( WP_REST_Request $req ) {
		global $wpdb;
		$route    = $req->get_route();
		$resource = substr( $route, strrpos( $route, '/' ) + 1 );
		$page     = max( 1, (int) $req['page'] );
		$per      = max( 1, min( 200, (int) $req['per_page'] ) );
		$since    = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $req['since'] ) ? $req['since'] . ' 00:00:00' : '';
		$ids      = self::agency_user_ids( self::$key->agency_id );
		$items    = array();
		$total    = 0;

		if ( 'people' === $resource ) {
			$total = count( $ids );
			foreach ( array_slice( $ids, ( $page - 1 ) * $per, $per ) as $id ) {
				$u = get_userdata( $id );
				if ( ! $u ) {
					continue;
				}
				$items[] = array(
					'id'           => $id,
					'name'         => $u->display_name,
					'email'        => $u->user_email,
					'staff_number' => (string) get_user_meta( $id, 'wic_staff_number', true ),
					'clinic'       => wic_user_clinic_name( $id ),
					'group'        => wic_user_group( $id ),
					'roles'        => array_values( (array) $u->roles ),
					'status'       => wic_user_status( $id ),
					'reports_to'   => wic_reports_to( $id ),
					'registered'   => self::iso( $u->user_registered ),
				);
			}
		} elseif ( $ids ) {
			$in    = implode( ',', array_map( 'intval', $ids ) );
			$table = array(
				'assignments'  => array( 'assignments', 'assigned_at' ),
				'completions'  => array( 'completions', 'completed_at' ),
				'certificates' => array( 'certificates', 'issued_at' ),
			);
			list( $t, $col ) = $table[ $resource ];
			$where           = "user_id IN ($in)";
			if ( $since ) {
				$where .= $wpdb->prepare( " AND $col >= %s", $since ); // phpcs:ignore WordPress.DB.PreparedSQL -- column from a fixed list.
			}
			$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . wic_table( $t ) . " WHERE $where" ); // phpcs:ignore
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( $t ) . " WHERE $where ORDER BY id ASC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ) ); // phpcs:ignore
			foreach ( $rows as $r ) {
				if ( 'assignments' === $resource ) {
					$desc    = WIC_Records::describe( (int) $r->user_id, $r );
					$items[] = array(
						'id'             => (int) $r->id,
						'user_id'        => (int) $r->user_id,
						'course_id'      => (int) $r->course_id,
						'course'         => get_the_title( $r->course_id ),
						'course_version' => (int) $r->course_version,
						'source'         => $r->source,
						'assigned_at'    => self::iso( $r->assigned_at ),
						'due_at'         => self::iso( $r->due_at ),
						'record_status'  => $r->status,
						'status'         => 'active' === $r->status ? $desc['status'] : $r->status,
						'progress'       => (int) $desc['progress'],
						'note'           => (string) $r->note,
					);
				} elseif ( 'completions' === $resource ) {
					$items[] = array(
						'id'             => (int) $r->id,
						'user_id'        => (int) $r->user_id,
						'course_id'      => (int) $r->course_id,
						'course'         => get_the_title( $r->course_id ),
						'course_version' => (int) $r->course_version,
						'run'            => (int) $r->run,
						'score'          => (int) $r->score,
						'time_spent'     => (int) $r->time_spent,
						'source'         => isset( $r->source ) ? $r->source : 'online',
						'completed_at'   => self::iso( $r->completed_at ),
					);
				} else {
					$items[] = array(
						'id'          => (int) $r->id,
						'user_id'     => (int) $r->user_id,
						'course_id'   => (int) $r->course_id,
						'number'      => $r->cert_number,
						'verify_code' => $r->verify_code,
						'name'        => $r->learner_name,
						'course'      => $r->course_title,
						'score'       => (int) $r->score,
						'hours'       => (float) $r->hours,
						'credit_type' => $r->credit_type,
						'issued_at'   => self::iso( $r->issued_at ),
						'expires_at'  => self::iso( $r->expires_at ),
						'status'      => WIC_Certificates::state( $r ),
					);
				}
			}
		}

		wic_audit( 'api_read', 'api_key', self::$key->id, array( 'resource' => $resource, 'page' => $page, 'count' => count( $items ) ) );
		$res = rest_ensure_response(
			array(
				'resource' => $resource,
				'page'     => $page,
				'per_page' => $per,
				'total'    => $total,
				'items'    => $items,
			)
		);
		$res->header( 'X-WP-Total', (string) $total );
		$res->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / $per ) ) );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	/* ------------------------------------------------------------------ */
	/* Key management                                                     */
	/* ------------------------------------------------------------------ */

	public static function views( $views ) {
		$views['api_keys'] = array(
			'label'    => __( 'API keys', 'wic-tp' ),
			'group'    => 'agency',
			'cap'      => 'wic_manage_settings',
			'callback' => array( __CLASS__, 'view' ),
			'order'    => 85,
		);
		return $views;
	}

	public static function messages( $m ) {
		$m['api_created'] = __( 'API key created. Copy it now — it will not be shown again.', 'wic-tp' );
		$m['api_revoked'] = __( 'API key revoked. Requests using it are refused from now on.', 'wic-tp' );
		return $m;
	}

	public static function create() {
		global $wpdb;
		check_admin_referer( 'wic_api_key_create' );
		if ( ! current_user_can( 'wic_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
		}
		$label  = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$agency = isset( $_POST['agency'] ) ? absint( $_POST['agency'] ) : wic_current_agency_id();
		$key    = 'wic_' . wp_generate_password( 40, false, false );
		$wpdb->insert(
			wic_table( 'api_keys' ),
			array(
				'label'      => $label ? $label : __( 'Unnamed key', 'wic-tp' ),
				'key_prefix' => substr( $key, 0, 12 ),
				'key_hash'   => self::hash( $key ),
				'agency_id'  => $agency,
				'created_by' => get_current_user_id(),
				'created_at' => wic_now(),
			)
		);
		wic_audit( 'api_key_create', 'api_key', $wpdb->insert_id, array( 'label' => $label, 'agency' => $agency ) );
		// Shown once, to the person who made it, on the next page load only.
		set_transient( 'wic_new_api_key_' . get_current_user_id(), $key, 120 );
		WIC_Portal::back( 'api_keys', 'api_created' );
	}

	public static function revoke() {
		global $wpdb;
		$id = isset( $_POST['key'] ) ? absint( $_POST['key'] ) : 0;
		check_admin_referer( 'wic_api_key_revoke_' . $id );
		if ( ! current_user_can( 'wic_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
		}
		$wpdb->update( wic_table( 'api_keys' ), array( 'revoked_at' => wic_now() ), array( 'id' => $id ) );
		wic_audit( 'api_key_revoke', 'api_key', $id );
		WIC_Portal::back( 'api_keys', 'api_revoked' );
	}

	public static function view( $uid ) {
		global $wpdb;
		$new = get_transient( 'wic_new_api_key_' . $uid );
		if ( $new ) {
			delete_transient( 'wic_new_api_key_' . $uid );
		}
		$keys     = $wpdb->get_results( 'SELECT * FROM ' . wic_table( 'api_keys' ) . ' ORDER BY id DESC' );
		$agencies = array( 0 => wic_setting( 'name' ) );
		if ( class_exists( 'WIC_Agencies' ) ) {
			foreach ( WIC_Agencies::all() as $a ) {
				$agencies[ (int) $a->id ] = $a->name;
			}
		}
		$agencies = apply_filters( 'wic_agency_options', $agencies );
		$base     = rest_url( 'wic/v1/data/' );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'API keys', 'wic-tp' ); ?></h2>
		<p class="wic-meta"><?php esc_html_e( 'Read-only access for the agency\'s own reporting tools. A key can read people, assignments, completions and certificates for one agency, and nothing else. Every call is recorded in the audit log.', 'wic-tp' ); ?></p>
		<?php if ( $new ) : ?>
			<div class="wic-notice wic-notice--warn" role="alert">
				<p><strong><?php esc_html_e( 'Your new key (shown once):', 'wic-tp' ); ?></strong></p>
				<p><label class="screen-reader-text" for="wic-new-key"><?php esc_html_e( 'New API key', 'wic-tp' ); ?></label><input type="text" id="wic-new-key" readonly value="<?php echo esc_attr( $new ); ?>" size="50" onfocus="this.select()"></p>
			</div>
		<?php endif; ?>
		<section class="wic-section wic-two">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form wic-panel">
				<h3 class="wic-h3"><?php esc_html_e( 'Create a key', 'wic-tp' ); ?></h3>
				<input type="hidden" name="action" value="wic_api_key_create">
				<?php wp_nonce_field( 'wic_api_key_create' ); ?>
				<div class="wic-field"><label for="wic-api-label"><?php esc_html_e( 'What it is for', 'wic-tp' ); ?></label><input type="text" id="wic-api-label" name="label" required placeholder="<?php esc_attr_e( 'e.g. State reporting dashboard', 'wic-tp' ); ?>"></div>
				<div class="wic-field">
					<label for="wic-api-agency"><?php esc_html_e( 'Agency', 'wic-tp' ); ?></label>
					<select id="wic-api-agency" name="agency">
						<?php foreach ( $agencies as $aid => $aname ) : ?>
							<option value="<?php echo (int) $aid; ?>" <?php selected( (int) $aid, wic_current_agency_id() ); ?>><?php echo esc_html( $aname ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Create key', 'wic-tp' ); ?></button>
			</form>
			<div class="wic-panel">
				<h3 class="wic-h3"><?php esc_html_e( 'How to call it', 'wic-tp' ); ?></h3>
				<p><code>GET <?php echo esc_html( $base ); ?>people?page=1&amp;per_page=100</code></p>
				<p><?php esc_html_e( 'Also: assignments, completions, certificates. Add since=YYYY-MM-DD to fetch only newer records.', 'wic-tp' ); ?></p>
				<p><code>Authorization: Bearer wic_…</code></p>
			</div>
		</section>
		<?php if ( ! $keys ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'No keys yet.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<div class="wic-table-wrap">
		<table class="wic-table">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Label', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Key starts', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Agency', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Created', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Last used', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Calls', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'wic-tp' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $keys as $k ) : ?>
				<tr>
					<td><?php echo esc_html( $k->label ); ?></td>
					<td><code><?php echo esc_html( $k->key_prefix ); ?>…</code></td>
					<td><?php echo esc_html( isset( $agencies[ $k->agency_id ] ) ? $agencies[ $k->agency_id ] : '#' . $k->agency_id ); ?></td>
					<td><?php echo esc_html( wic_format_date( $k->created_at ) ); ?></td>
					<td><?php echo esc_html( $k->last_used ? wic_format_date( $k->last_used, true ) : __( 'Never', 'wic-tp' ) ); ?></td>
					<td><?php echo (int) $k->calls; ?></td>
					<td>
						<?php if ( $k->revoked_at ) : ?>
							<span class="wic-badge wic-badge--expired"><?php echo esc_html( sprintf( __( 'Revoked %s', 'wic-tp' ), wic_format_date( $k->revoked_at ) ) ); ?></span>
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-inline-form">
								<input type="hidden" name="action" value="wic_api_key_revoke">
								<input type="hidden" name="key" value="<?php echo (int) $k->id; ?>">
								<?php wp_nonce_field( 'wic_api_key_revoke_' . $k->id ); ?>
								<span class="wic-badge wic-badge--complete"><?php esc_html_e( 'Active', 'wic-tp' ); ?></span>
								<button type="submit" class="wic-btn wic-btn--small" data-wic-confirm="<?php esc_attr_e( 'Revoke this key? Anything using it will stop working.', 'wic-tp' ); ?>"><?php esc_html_e( 'Revoke', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( $k->label ); ?></span></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}
}

add_action( 'wic_init', array( 'WIC_Data_Api', 'init' ) );
