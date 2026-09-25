<?php
/**
 * Clinics as real records (#8) with contact data (#144).
 *
 * Everyone belongs to one clinic, by ID, which is what makes clinic-level reporting and
 * local administrators possible. The clinic name is still copied to the old `wic_clinic`
 * meta so anything reading the free-text value keeps working.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_table_sql',
	function ( $sql, $c ) {
		$sql[] = 'CREATE TABLE ' . wic_table( 'clinics' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  agency_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(200) NOT NULL,
  code varchar(40) NOT NULL DEFAULT '',
  address text NULL,
  phone varchar(60) NOT NULL DEFAULT '',
  email varchar(200) NOT NULL DEFAULT '',
  hours text NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL,
  updated_at datetime NULL,
  PRIMARY KEY  (id),
  KEY agency (agency_id),
  KEY name (name)
) $c;";
		return $sql;
	},
	10,
	2
);

add_filter(
	'wic_pages',
	function ( $pages ) {
		$pages['clinics'] = array( 'Clinic Directory', '[wic_clinics]' );
		return $pages;
	}
);

add_filter(
	'wic_portal_style_pages',
	function ( $keys ) {
		$keys[] = 'clinics';
		return $keys;
	}
);

class WIC_Clinics {

	private static $cache = null;

	public static function init() {
		add_filter( 'wic_clinic_options', array( __CLASS__, 'options_filter' ) );
		add_filter( 'wic_user_clinic_id', array( __CLASS__, 'user_clinic_id' ), 10, 2 );
		add_filter( 'wic_user_clinic_name', array( __CLASS__, 'user_clinic_name' ), 10, 2 );
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		add_filter( 'wic_flash_messages', array( __CLASS__, 'messages' ) );
		add_action( 'admin_post_wic_clinic_save', array( __CLASS__, 'save' ) );
		add_shortcode( 'wic_clinics', array( __CLASS__, 'directory' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Reading                                                            */
	/* ------------------------------------------------------------------ */

	/** All clinics, active first then by name. Pass true to include closed clinics. */
	public static function all( $include_closed = false ) {
		global $wpdb;
		if ( null === self::$cache ) {
			self::$cache = (array) $wpdb->get_results( 'SELECT * FROM ' . wic_table( 'clinics' ) . " ORDER BY status = 'active' DESC, name ASC" );
		}
		$agency = wic_current_agency_id();
		$rows   = array_filter(
			self::$cache,
			function ( $c ) use ( $agency ) {
				return ! $agency || (int) $c->agency_id === $agency || 0 === (int) $c->agency_id;
			}
		);
		if ( ! $include_closed ) {
			$rows = array_filter(
				$rows,
				function ( $c ) {
					return 'active' === $c->status;
				}
			);
		}
		return array_values( $rows );
	}

	public static function get( $id ) {
		foreach ( self::all( true ) as $c ) {
			if ( (int) $c->id === (int) $id ) {
				return $c;
			}
		}
		return null;
	}

	public static function by_name( $name ) {
		$want = wic_fold( trim( (string) $name ) );
		if ( '' === $want ) {
			return null;
		}
		foreach ( self::all( true ) as $c ) {
			if ( wic_fold( $c->name ) === $want || ( '' !== $c->code && wic_fold( $c->code ) === $want ) ) {
				return $c;
			}
		}
		return null;
	}

	public static function flush() {
		self::$cache = null;
	}

	public static function options_filter( $options ) {
		$out = array();
		foreach ( self::all() as $c ) {
			$out[ (int) $c->id ] = $c->name;
		}
		return $out;
	}

	public static function user_clinic_id( $id, $user_id ) {
		if ( $id ) {
			return $id;
		}
		// People registered before clinics were records: match the free-text name.
		$c = self::by_name( get_user_meta( $user_id, 'wic_clinic', true ) );
		return $c ? (int) $c->id : 0;
	}

	public static function user_clinic_name( $name, $user_id ) {
		$id = (int) get_user_meta( $user_id, 'wic_clinic_id', true );
		$c  = $id ? self::get( $id ) : null;
		return $c ? $c->name : $name;
	}

	/** The one way to put someone in a clinic: ID and name kept in step, and audited. */
	public static function set_user_clinic( $user_id, $clinic_id ) {
		$clinic_id = (int) $clinic_id;
		$old       = (int) get_user_meta( $user_id, 'wic_clinic_id', true );
		$c         = $clinic_id ? self::get( $clinic_id ) : null;
		update_user_meta( $user_id, 'wic_clinic_id', $c ? (int) $c->id : 0 );
		update_user_meta( $user_id, 'wic_clinic', $c ? $c->name : '' );
		if ( $old !== ( $c ? (int) $c->id : 0 ) ) {
			wic_audit( 'user_clinic', 'user', $user_id, array( 'from' => $old, 'to' => $c ? (int) $c->id : 0 ) );
		}
	}

	/** Find a clinic by name, creating it if it does not exist yet. Used by imports and migration. */
	public static function ensure( $name ) {
		global $wpdb;
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return 0;
		}
		$c = self::by_name( $name );
		if ( $c ) {
			return (int) $c->id;
		}
		$wpdb->insert(
			wic_table( 'clinics' ),
			array(
				'agency_id'  => wic_current_agency_id(),
				'name'       => $name,
				'status'     => 'active',
				'created_at' => wic_now(),
			)
		);
		self::flush();
		return (int) $wpdb->insert_id;
	}

	/** Migration: every free-text clinic becomes a record, and people point at it. Safe to repeat. */
	public static function migrate() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''", 'wic_clinic' ) );
		foreach ( $rows as $r ) {
			if ( (int) get_user_meta( $r->user_id, 'wic_clinic_id', true ) ) {
				continue;
			}
			$id = self::ensure( $r->meta_value );
			if ( $id ) {
				update_user_meta( $r->user_id, 'wic_clinic_id', $id );
			}
		}
		if ( ! get_option( 'wic_clinics_seeded' ) ) {
			update_option( 'wic_clinics_seeded', 1 );
			if ( ! self::all( true ) ) {
				foreach ( array( 'North Clinic (Sample)', 'South Clinic (Sample)' ) as $n ) {
					self::ensure( $n );
				}
			}
		}
	}

	/** Member count per clinic, for the list. */
	private static function counts() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value AS id, COUNT(*) AS n FROM {$wpdb->usermeta} WHERE meta_key = %s GROUP BY meta_value", 'wic_clinic_id' ) );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r->id ] = (int) $r->n;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Portal                                                             */
	/* ------------------------------------------------------------------ */

	public static function views( $views ) {
		$views['clinics'] = array(
			'label'    => __( 'Clinics', 'wic-tp' ),
			'group'    => 'agency',
			'cap'      => 'wic_manage_people',
			'callback' => array( __CLASS__, 'view' ),
			'order'    => 40,
		);
		return $views;
	}

	public static function messages( $m ) {
		$m['clinic_saved']  = __( 'Clinic saved.', 'wic-tp' );
		$m['err_clinic']    = __( 'A clinic needs a name.', 'wic-tp' );
		return $m;
	}

	public static function select( $name, $selected = 0, $id = '', $required = false, $empty_label = '' ) {
		$html  = '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ? $id : $name ) . '"' . ( $required ? ' required' : '' ) . '>';
		$html .= '<option value="">' . esc_html( $empty_label ? $empty_label : __( 'Choose a clinic', 'wic-tp' ) ) . '</option>';
		foreach ( self::all() as $c ) {
			$html .= '<option value="' . (int) $c->id . '"' . selected( (int) $selected, (int) $c->id, false ) . '>' . esc_html( $c->name ) . '</option>';
		}
		return $html . '</select>';
	}

	public static function view( $uid ) {
		$edit   = isset( $_GET['clinic'] ) ? self::get( absint( $_GET['clinic'] ) ) : null;
		$counts = self::counts();
		$f      = function ( $key ) use ( $edit ) {
			return $edit && isset( $edit->$key ) ? (string) $edit->$key : '';
		};
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Clinics', 'wic-tp' ); ?></h2>
		<p class="wic-help"><?php esc_html_e( 'Everyone belongs to one clinic. Clinics are closed, never deleted, so their history stays readable.', 'wic-tp' ); ?>
			<a href="<?php echo esc_url( wic_page_url( 'clinics' ) ); ?>"><?php esc_html_e( 'Public clinic directory', 'wic-tp' ); ?></a></p>
		<div class="wic-table-wrap">
			<table class="wic-table" data-wic-sortable>
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Clinic', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Code', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Phone', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Email', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'People', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'wic-tp' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'wic-tp' ); ?></span></th>
				</tr></thead>
				<tbody>
				<?php foreach ( self::all( true ) as $c ) : ?>
					<tr>
						<td><?php echo esc_html( $c->name ); ?></td>
						<td><?php echo esc_html( $c->code ); ?></td>
						<td><?php echo esc_html( $c->phone ); ?></td>
						<td><?php echo esc_html( $c->email ); ?></td>
						<td><?php echo isset( $counts[ (int) $c->id ] ) ? (int) $counts[ (int) $c->id ] : 0; ?></td>
						<td><span class="wic-badge wic-badge--<?php echo 'active' === $c->status ? 'complete' : 'not_started'; ?>"><?php echo 'active' === $c->status ? esc_html__( 'Open', 'wic-tp' ) : esc_html__( 'Closed', 'wic-tp' ); ?></span></td>
						<td><a class="wic-btn wic-btn--small" href="<?php echo esc_url( wic_portal_url( 'clinics', array( 'clinic' => $c->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( $c->name ); ?></span></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form wic-panel" style="margin-top:1.5rem">
			<h3 class="wic-h3" id="wic-clinic-form"><?php echo $edit ? esc_html( sprintf( __( 'Edit %s', 'wic-tp' ), $edit->name ) ) : esc_html__( 'Add a clinic', 'wic-tp' ); ?></h3>
			<input type="hidden" name="action" value="wic_clinic_save">
			<input type="hidden" name="id" value="<?php echo $edit ? (int) $edit->id : 0; ?>">
			<?php wp_nonce_field( 'wic_clinic_save' ); ?>
			<div class="wic-field"><label for="wic-cl-name"><?php esc_html_e( 'Name', 'wic-tp' ); ?></label><input type="text" id="wic-cl-name" name="name" required value="<?php echo esc_attr( $f( 'name' ) ); ?>"></div>
			<div class="wic-field"><label for="wic-cl-code"><?php esc_html_e( 'Code (optional)', 'wic-tp' ); ?></label><input type="text" id="wic-cl-code" name="code" value="<?php echo esc_attr( $f( 'code' ) ); ?>"><p class="wic-help"><?php esc_html_e( 'The identifier used in HR or state systems, so imports can match it.', 'wic-tp' ); ?></p></div>
			<div class="wic-field"><label for="wic-cl-addr"><?php esc_html_e( 'Address', 'wic-tp' ); ?></label><textarea id="wic-cl-addr" name="address" rows="3"><?php echo esc_textarea( $f( 'address' ) ); ?></textarea></div>
			<div class="wic-field"><label for="wic-cl-phone"><?php esc_html_e( 'Phone', 'wic-tp' ); ?></label><input type="tel" id="wic-cl-phone" name="phone" value="<?php echo esc_attr( $f( 'phone' ) ); ?>"></div>
			<div class="wic-field"><label for="wic-cl-email"><?php esc_html_e( 'Email', 'wic-tp' ); ?></label><input type="email" id="wic-cl-email" name="email" value="<?php echo esc_attr( $f( 'email' ) ); ?>"></div>
			<div class="wic-field"><label for="wic-cl-hours"><?php esc_html_e( 'Opening hours', 'wic-tp' ); ?></label><textarea id="wic-cl-hours" name="hours" rows="3" placeholder="<?php esc_attr_e( 'e.g. Mon–Fri 8:00–4:30', 'wic-tp' ); ?>"><?php echo esc_textarea( $f( 'hours' ) ); ?></textarea></div>
			<div class="wic-field"><label for="wic-cl-status"><?php esc_html_e( 'Status', 'wic-tp' ); ?></label>
				<select id="wic-cl-status" name="status">
					<option value="active" <?php selected( $f( 'status' ), 'active' ); ?>><?php esc_html_e( 'Open', 'wic-tp' ); ?></option>
					<option value="closed" <?php selected( $f( 'status' ), 'closed' ); ?>><?php esc_html_e( 'Closed (hidden from lists; history kept)', 'wic-tp' ); ?></option>
				</select></div>
			<div>
				<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Save clinic', 'wic-tp' ); ?></button>
				<?php if ( $edit ) : ?><a class="wic-btn" href="<?php echo esc_url( wic_portal_url( 'clinics' ) ); ?>"><?php esc_html_e( 'Cancel', 'wic-tp' ); ?></a><?php endif; ?>
			</div>
		</form>
		<?php
	}

	public static function save() {
		global $wpdb;
		check_admin_referer( 'wic_clinic_save' );
		if ( ! current_user_can( 'wic_manage_people' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
		}
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' === $name ) {
			WIC_Portal::back( 'clinics', 'err_clinic' );
		}
		$data = array(
			'name'       => $name,
			'code'       => isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '',
			'address'    => isset( $_POST['address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['address'] ) ) : '',
			'phone'      => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
			'email'      => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
			'hours'      => isset( $_POST['hours'] ) ? sanitize_textarea_field( wp_unslash( $_POST['hours'] ) ) : '',
			'status'     => isset( $_POST['status'] ) && 'closed' === $_POST['status'] ? 'closed' : 'active',
			'updated_at' => wic_now(),
		);
		if ( $id && self::get( $id ) ) {
			$wpdb->update( wic_table( 'clinics' ), $data, array( 'id' => $id ) );
			// Keep the back-compat name in step for everyone in the clinic.
			$members = get_users( array( 'meta_key' => 'wic_clinic_id', 'meta_value' => $id, 'fields' => 'ID' ) );
			foreach ( $members as $m ) {
				update_user_meta( $m, 'wic_clinic', $name );
			}
		} else {
			$data['created_at'] = wic_now();
			$data['agency_id']  = wic_current_agency_id();
			$wpdb->insert( wic_table( 'clinics' ), $data );
			$id = (int) $wpdb->insert_id;
		}
		self::flush();
		wic_audit( 'clinic_save', 'clinic', $id, array( 'name' => $name, 'status' => $data['status'] ) );
		WIC_Portal::back( 'clinics', 'clinic_saved' );
	}

	/* ------------------------------------------------------------------ */
	/* Public directory                                                   */
	/* ------------------------------------------------------------------ */

	public static function directory() {
		WIC_Portal::enqueue();
		$q       = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$clinics = self::all();
		if ( '' !== $q ) {
			$needle  = wic_fold( $q );
			$clinics = array_filter(
				$clinics,
				function ( $c ) use ( $needle ) {
					return false !== strpos( wic_fold( $c->name . ' ' . $c->address . ' ' . $c->code ), $needle );
				}
			);
		}
		ob_start();
		?>
		<div class="wic-portal wic-clinics">
			<form method="get" class="wic-form wic-form--inline" role="search">
				<?php if ( ! get_option( 'permalink_structure' ) ) : ?>
					<input type="hidden" name="page_id" value="<?php echo esc_attr( wic_page_id( 'clinics' ) ); ?>">
				<?php endif; ?>
				<div class="wic-field">
					<label for="wic-clq"><?php esc_html_e( 'Find a clinic by name or town', 'wic-tp' ); ?></label>
					<input type="search" id="wic-clq" name="q" value="<?php echo esc_attr( $q ); ?>">
				</div>
				<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Search', 'wic-tp' ); ?></button>
			</form>
			<p class="wic-live" role="status"><?php echo esc_html( sprintf( _n( '%d clinic', '%d clinics', count( $clinics ), 'wic-tp' ), count( $clinics ) ) ); ?></p>
			<?php if ( ! $clinics ) : ?>
				<div class="wic-empty"><p><?php esc_html_e( 'No clinics match that search.', 'wic-tp' ); ?></p></div>
			<?php endif; ?>
			<div class="wic-grid">
				<?php foreach ( $clinics as $c ) : ?>
					<article class="wic-card">
						<h3><?php echo esc_html( $c->name ); ?></h3>
						<dl class="wic-dl">
							<?php if ( $c->address ) : ?><dt><?php esc_html_e( 'Address', 'wic-tp' ); ?></dt><dd><?php echo nl2br( esc_html( $c->address ) ); ?></dd><?php endif; ?>
							<?php if ( $c->hours ) : ?><dt><?php esc_html_e( 'Hours', 'wic-tp' ); ?></dt><dd><?php echo nl2br( esc_html( $c->hours ) ); ?></dd><?php endif; ?>
							<?php if ( $c->phone ) : ?><dt><?php esc_html_e( 'Phone', 'wic-tp' ); ?></dt><dd><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $c->phone ) ); ?>"><?php echo esc_html( $c->phone ); ?></a></dd><?php endif; ?>
							<?php if ( $c->email ) : ?><dt><?php esc_html_e( 'Email', 'wic-tp' ); ?></dt><dd><a href="mailto:<?php echo esc_attr( $c->email ); ?>"><?php echo esc_html( $c->email ); ?></a></dd><?php endif; ?>
						</dl>
						<?php if ( ! $c->address && ! $c->hours && ! $c->phone && ! $c->email ) : ?>
							<p class="wic-help"><?php esc_html_e( 'Contact details have not been added yet.', 'wic-tp' ); ?></p>
						<?php endif; ?>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}

add_action( 'wic_init', array( 'WIC_Clinics', 'init' ) );
add_action( 'wic_install', array( 'WIC_Clinics', 'migrate' ) );
