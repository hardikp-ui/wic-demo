<?php
/**
 * Learning paths (#106 blended paths, #111 phased paths for new staff, #34 manuals chained end to end).
 *
 * A path is named phases, each an ordered list of steps: a course, a session or a form.
 * Assigning a path assigns its courses; progress is always derived from the records the
 * steps already have (completions, attendance, signatures), never stored separately.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_table_sql',
	function ( $sql, $c ) {
		$sql[] = 'CREATE TABLE ' . wic_table( 'path_assign' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  path_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  assigned_by bigint(20) unsigned NOT NULL DEFAULT 0,
  assigned_at datetime NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY path_id (path_id)
) $c;";
		return $sql;
	},
	10,
	2
);

class WIC_Paths {

	const STEP_SLOTS  = 3;  // Blank step rows offered per phase in the editor.
	const PHASE_SLOTS = 1;  // Blank phases offered in the editor.

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_wic_path', array( __CLASS__, 'save' ) );
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		add_filter( 'wic_flash_messages', array( __CLASS__, 'messages' ) );
		add_action( 'admin_post_wic_path_assign', array( __CLASS__, 'handle_assign' ) );
		add_filter( 'wic_player_data', array( __CLASS__, 'player_next' ), 10, 3 );
		add_filter( 'wic_required_forms', array( __CLASS__, 'required_forms' ), 10, 2 );
		add_action( 'wic_apply_rules', array( __CLASS__, 'apply_rules' ) );
		add_action( 'wic_person_record_sections', array( __CLASS__, 'person_section' ), 20, 2 );
	}

	public static function register() {
		WIC_E::register_type( 'wic_path', __( 'Learning paths', 'wic-tp' ), __( 'Learning path', 'wic-tp' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Data                                                               */
	/* ------------------------------------------------------------------ */

	/** array( array( 'name' => …, 'steps' => array( 'course:12', 'session:40', 'form:7' ) ) ) */
	public static function phases( $path_id ) {
		$p = get_post_meta( $path_id, '_wic_phases', true );
		return is_array( $p ) ? $p : array();
	}

	public static function parse( $step ) {
		$parts = explode( ':', (string) $step, 2 );
		return array( $parts[0], isset( $parts[1] ) ? (int) $parts[1] : 0 );
	}

	public static function user_paths( $user_id ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT path_id FROM ' . wic_table( 'path_assign' ) . " WHERE user_id = %d AND status = 'active'", $user_id ) );
		return array_values(
			array_filter(
				array_map( 'intval', $ids ),
				function ( $id ) {
					return 'publish' === get_post_status( $id );
				}
			)
		);
	}

	public static function is_assigned( $path_id, $user_id ) {
		return in_array( (int) $path_id, self::user_paths( $user_id ), true );
	}

	/** Is one step done for this person? */
	public static function step_done( $user_id, $step ) {
		list( $type, $id ) = self::parse( $step );
		switch ( $type ) {
			case 'course':
				return (bool) WIC_Records::latest_completion( $user_id, $id );
			case 'session':
				if ( class_exists( 'WIC_Sessions' ) && WIC_Sessions::attended( $user_id, $id ) ) {
					return true;
				}
				// Attending another session for the same course counts too.
				$course = (int) get_post_meta( $id, '_wic_course', true );
				return $course && (bool) WIC_Records::latest_completion( $user_id, $course );
			case 'form':
				return class_exists( 'WIC_Forms' ) && WIC_Forms::is_signed( $user_id, $id );
		}
		return false;
	}

	public static function step_label( $step ) {
		list( $type, $id ) = self::parse( $step );
		$labels            = array(
			'course'  => __( 'Course', 'wic-tp' ),
			'session' => __( 'Session', 'wic-tp' ),
			'form'    => __( 'Form', 'wic-tp' ),
		);
		return array( isset( $labels[ $type ] ) ? $labels[ $type ] : $type, get_the_title( $id ) );
	}

	public static function step_url( $step ) {
		list( $type, $id ) = self::parse( $step );
		switch ( $type ) {
			case 'course':
				return wic_page_url( 'learn', array( 'course' => $id ) );
			case 'session':
				return wic_portal_url( 'sessions' );
			case 'form':
				return wic_portal_url( 'form_sign', array( 'form' => $id ) );
		}
		return '';
	}

	/** Steps in path order, flattened. */
	public static function steps( $path_id ) {
		$out = array();
		foreach ( self::phases( $path_id ) as $ph ) {
			foreach ( (array) $ph['steps'] as $s ) {
				$out[] = $s;
			}
		}
		return $out;
	}

	public static function progress( $user_id, $path_id ) {
		$steps = self::steps( $path_id );
		if ( ! $steps ) {
			return 0;
		}
		$done = count( array_filter( $steps, function ( $s ) use ( $user_id ) { return self::step_done( $user_id, $s ); } ) );
		return (int) floor( $done / count( $steps ) * 100 );
	}

	/** Assign a path: record it, and assign every course step. Additive only. */
	public static function assign( $path_id, $user_id, $source = 'manual' ) {
		global $wpdb;
		if ( ! self::is_assigned( $path_id, $user_id ) ) {
			$wpdb->insert(
				wic_table( 'path_assign' ),
				array(
					'path_id'     => $path_id,
					'user_id'     => $user_id,
					'assigned_by' => get_current_user_id(),
					'assigned_at' => wic_now(),
					'status'      => 'active',
				)
			);
			wic_audit( 'path_assign', 'path', $path_id, array( 'user' => $user_id, 'source' => $source ) );
			/* translators: %s: path title */
			WIC_Notify::event( $user_id, 'path_assigned', $path_id, sprintf( __( 'You have been given the learning path "%s".', 'wic-tp' ), get_the_title( $path_id ) ), false );
		}
		foreach ( self::steps( $path_id ) as $s ) {
			list( $type, $id ) = self::parse( $s );
			if ( 'course' === $type && ! WIC_Records::latest_completion( $user_id, $id ) ) {
				WIC_Records::assign( $user_id, $id, 'path' );
			}
		}
	}

	/** Paths set to auto-assign by group are applied whenever the rules run. */
	public static function apply_rules( $user_id ) {
		$paths = get_posts( array( 'post_type' => 'wic_path', 'post_status' => 'publish', 'posts_per_page' => -1 ) );
		foreach ( $paths as $p ) {
			if ( WIC_E::matches( $user_id, get_post_meta( $p->ID, '_wic_path_groups', true ), get_post_meta( $p->ID, '_wic_path_roles', true ), get_post_meta( $p->ID, '_wic_path_agency', true ) ) ) {
				self::assign( $p->ID, $user_id, 'rule' );
			}
		}
	}

	/** Form steps on an assigned path are required forms. */
	public static function required_forms( $forms, $user_id ) {
		foreach ( self::user_paths( $user_id ) as $pid ) {
			foreach ( self::steps( $pid ) as $s ) {
				list( $type, $id ) = self::parse( $s );
				if ( 'form' === $type && ! isset( $forms[ $id ] ) && 'publish' === get_post_status( $id ) ) {
					/* translators: %s: path title */
					$forms[ $id ] = sprintf( __( 'A step on your path "%s"', 'wic-tp' ), get_the_title( $pid ) );
				}
			}
		}
		return $forms;
	}

	/** After finishing a course on a path, the results screen offers the next course. */
	public static function player_next( $data, $uid, $course_id ) {
		foreach ( self::user_paths( $uid ) as $pid ) {
			$steps = self::steps( $pid );
			$found = false;
			foreach ( $steps as $s ) {
				list( $type, $id ) = self::parse( $s );
				if ( ! $found ) {
					$found = ( 'course' === $type && (int) $id === (int) $course_id );
					continue;
				}
				if ( 'course' === $type && 'publish' === get_post_status( $id ) && ! WIC_Records::latest_completion( $uid, $id ) ) {
					$data['next'] = array(
						'title' => get_the_title( $id ),
						'url'   => wic_page_url( 'learn', array( 'course' => $id ) ),
						'path'  => get_the_title( $pid ),
					);
					return $data;
				}
			}
		}
		return $data;
	}

	/* ------------------------------------------------------------------ */
	/* Authoring                                                          */
	/* ------------------------------------------------------------------ */

	public static function meta_boxes() {
		add_meta_box( 'wic_path_meta', __( 'Phases and steps', 'wic-tp' ), array( __CLASS__, 'box' ), 'wic_path', 'normal', 'high' );
	}

	public static function box( $post ) {
		wp_nonce_field( 'wic_path_meta', 'wic_path_nonce' );
		$phases  = self::phases( $post->ID );
		$choices = array(
			__( 'Courses', 'wic-tp' )  => array( 'course', WIC_E::options( 'wic_course' ) ),
			__( 'Sessions', 'wic-tp' ) => array( 'session', WIC_E::options( 'wic_session' ) ),
			__( 'Forms', 'wic-tp' )    => array( 'form', WIC_E::options( 'wic_form' ) ),
		);
		for ( $i = 0; $i < self::PHASE_SLOTS; $i++ ) {
			$phases[] = array( 'name' => '', 'steps' => array() );
		}
		echo '<p class="description">' . esc_html__( 'Name each phase (for example "First week"), then choose its steps in order. Empty rows are ignored; save to get more rows.', 'wic-tp' ) . '</p>';
		foreach ( $phases as $pi => $ph ) {
			$steps = array_merge( (array) $ph['steps'], array_fill( 0, self::STEP_SLOTS, '' ) );
			echo '<fieldset style="border:1px solid #dcdcde;padding:8px 12px;margin:0 0 12px"><legend><strong>' . esc_html( sprintf( __( 'Phase %d', 'wic-tp' ), $pi + 1 ) ) . '</strong></legend>';
			echo '<p><label>' . esc_html__( 'Phase name', 'wic-tp' ) . ' <input type="text" class="regular-text" name="wic_phase[' . (int) $pi . '][name]" value="' . esc_attr( $ph['name'] ) . '"></label></p><ol>';
			foreach ( $steps as $si => $s ) {
				echo '<li><label class="screen-reader-text" for="wic-step-' . (int) $pi . '-' . (int) $si . '">' . esc_html( sprintf( __( 'Step %d', 'wic-tp' ), $si + 1 ) ) . '</label><select id="wic-step-' . (int) $pi . '-' . (int) $si . '" name="wic_phase[' . (int) $pi . '][steps][]"><option value="">' . esc_html__( '— none —', 'wic-tp' ) . '</option>';
				foreach ( $choices as $label => $c ) {
					echo '<optgroup label="' . esc_attr( $label ) . '">';
					foreach ( $c[1] as $id => $t ) {
						$v = $c[0] . ':' . $id;
						echo '<option value="' . esc_attr( $v ) . '" ' . selected( $s, $v, false ) . '>' . esc_html( $t ) . '</option>';
					}
					echo '</optgroup>';
				}
				echo '</select></li>';
			}
			echo '</ol></fieldset>';
		}
		echo '<h4>' . esc_html__( 'Assign automatically to', 'wic-tp' ) . '</h4>';
		WIC_E::audience_fields( 'wic_path', get_post_meta( $post->ID, '_wic_path_groups', true ), get_post_meta( $post->ID, '_wic_path_roles', true ), (int) get_post_meta( $post->ID, '_wic_path_agency', true ) );
		echo '<p class="description">' . esc_html__( 'Applied at approval and when someone changes group. Leave unticked to assign by hand from the portal.', 'wic-tp' ) . '</p>';
	}

	public static function save( $post_id ) {
		if ( ! WIC_E::can_save( $post_id, 'wic_path_nonce', 'wic_path_meta' ) ) {
			return;
		}
		$in  = isset( $_POST['wic_phase'] ) ? (array) wp_unslash( $_POST['wic_phase'] ) : array();
		$out = array();
		foreach ( $in as $ph ) {
			$name  = isset( $ph['name'] ) ? sanitize_text_field( $ph['name'] ) : '';
			$steps = array();
			foreach ( isset( $ph['steps'] ) ? (array) $ph['steps'] : array() as $s ) {
				if ( preg_match( '/^(course|session|form):(\d+)$/', (string) $s ) ) {
					$steps[] = $s;
				}
			}
			if ( $steps || '' !== $name ) {
				$out[] = array(
					'name'  => '' !== $name ? $name : sprintf( __( 'Phase %d', 'wic-tp' ), count( $out ) + 1 ),
					'steps' => $steps,
				);
			}
		}
		update_post_meta( $post_id, '_wic_phases', $out );
		WIC_E::save_audience( $post_id, 'wic_path', '_wic_path' );
	}

	/* ------------------------------------------------------------------ */
	/* Portal                                                             */
	/* ------------------------------------------------------------------ */

	public static function views( $views ) {
		$views['path']        = array(
			'label'    => __( 'My path', 'wic-tp' ),
			'group'    => 'learn',
			'cap'      => function ( $uid ) {
				return user_can( $uid, 'wic_learn' ) && (bool) self::user_paths( $uid );
			},
			'callback' => array( __CLASS__, 'view_path' ),
			'order'    => 15,
		);
		$views['paths_assign'] = array(
			'label'    => __( 'Learning paths', 'wic-tp' ),
			'group'    => 'team',
			'cap'      => 'wic_view_team',
			'callback' => array( __CLASS__, 'view_assign' ),
			'order'    => 45,
		);
		return $views;
	}

	public static function messages( $m ) {
		$m['path_assigned'] = __( 'Learning path assigned. Its courses have been added to each person\'s training.', 'wic-tp' );
		return $m;
	}

	public static function view_path( $uid ) {
		$paths = self::user_paths( $uid );
		echo '<h2 class="wic-h">' . esc_html__( 'My learning path', 'wic-tp' ) . '</h2>';
		if ( ! $paths ) {
			echo '<div class="wic-empty"><p>' . esc_html__( 'You have not been given a learning path.', 'wic-tp' ) . '</p></div>';
			return;
		}
		foreach ( $paths as $pid ) {
			$phases   = self::phases( $pid );
			$pct      = self::progress( $uid, $pid );
			$next_set = false;
			?>
			<section class="wic-section wic-path">
				<h3 class="wic-h3"><?php echo esc_html( get_the_title( $pid ) ); ?></h3>
				<div class="wic-card__progress"><div class="wic-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) $pct; ?>" aria-label="<?php echo esc_attr( get_the_title( $pid ) ); ?>"><span style="width:<?php echo (int) $pct; ?>%"></span></div><span class="wic-bar__pct"><?php echo (int) $pct; ?>%</span></div>
				<?php if ( get_post_field( 'post_content', $pid ) ) : ?><div class="wic-meta"><?php echo wp_kses_post( wpautop( get_post_field( 'post_content', $pid ) ) ); ?></div><?php endif; ?>
				<ol class="wic-phases">
				<?php foreach ( $phases as $ph ) : ?>
					<?php
					$steps = (array) $ph['steps'];
					$done  = array_filter( $steps, function ( $s ) use ( $uid ) { return self::step_done( $uid, $s ); } );
					$state = count( $done ) === count( $steps ) ? __( 'Phase complete', 'wic-tp' ) : sprintf( __( '%1$d of %2$d done', 'wic-tp' ), count( $done ), count( $steps ) );
					?>
					<li class="wic-phase<?php echo count( $done ) === count( $steps ) ? ' is-done' : ''; ?>">
						<h4><?php echo esc_html( $ph['name'] ); ?> <span class="wic-meta">— <?php echo esc_html( $state ); ?></span></h4>
						<ol class="wic-steps">
						<?php foreach ( $steps as $s ) : ?>
							<?php
							$is_done = self::step_done( $uid, $s );
							$is_next = ! $is_done && ! $next_set;
							if ( $is_next ) {
								$next_set = true;
							}
							list( $kind, $title ) = self::step_label( $s );
							?>
							<li class="wic-step<?php echo $is_done ? ' is-done' : ''; ?><?php echo $is_next ? ' is-next' : ''; ?>">
								<span class="wic-step__mark" aria-hidden="true"><?php echo $is_done ? '✓' : ( $is_next ? '→' : '○' ); ?></span>
								<span class="wic-tag"><?php echo esc_html( $kind ); ?></span>
								<span><?php echo esc_html( $title ); ?></span>
								<span class="wic-meta"><?php echo $is_done ? esc_html__( 'Done', 'wic-tp' ) : ( $is_next ? esc_html__( 'Next', 'wic-tp' ) : esc_html__( 'To do', 'wic-tp' ) ); ?></span>
								<?php if ( ! $is_done ) : ?>
									<a class="wic-btn wic-btn--small<?php echo $is_next ? ' wic-btn--primary' : ''; ?>" href="<?php echo esc_url( self::step_url( $s ) ); ?>"><?php esc_html_e( 'Open', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( $title ); ?></span></a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
						</ol>
					</li>
				<?php endforeach; ?>
				</ol>
			</section>
			<?php
		}
	}

	public static function view_assign( $uid ) {
		$paths = WIC_E::options( 'wic_path' );
		$team  = WIC_E::team( $uid );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Learning paths', 'wic-tp' ); ?></h2>
		<?php if ( ! $paths ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'No learning paths are published yet. Build them under WIC Platform → Learning paths.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<form method="post" action="<?php echo esc_url( WIC_E::post_url() ); ?>" class="wic-form wic-panel">
			<h3 class="wic-h3"><?php esc_html_e( 'Assign a path', 'wic-tp' ); ?></h3>
			<input type="hidden" name="action" value="wic_path_assign">
			<?php wp_nonce_field( 'wic_path_assign' ); ?>
			<div class="wic-field">
				<label for="wic-pa-path"><?php esc_html_e( 'Path', 'wic-tp' ); ?></label>
				<select id="wic-pa-path" name="path" required>
					<?php foreach ( $paths as $id => $t ) : ?>
						<option value="<?php echo (int) $id; ?>"><?php echo esc_html( $t ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="wic-field">
				<label for="wic-pa-target"><?php esc_html_e( 'Assign to', 'wic-tp' ); ?></label>
				<select id="wic-pa-target" name="target" required>
					<option value="group:staff"><?php esc_html_e( 'All staff on my team', 'wic-tp' ); ?></option>
					<option value="group:intern"><?php esc_html_e( 'All interns on my team', 'wic-tp' ); ?></option>
					<?php foreach ( $team as $u ) : ?>
						<option value="user:<?php echo (int) $u->ID; ?>"><?php echo esc_html( $u->display_name ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Assign', 'wic-tp' ); ?></button>
		</form>

		<h3 class="wic-h3"><?php esc_html_e( 'Progress on paths', 'wic-tp' ); ?></h3>
		<?php
		$rows = array();
		foreach ( $team as $u ) {
			foreach ( self::user_paths( $u->ID ) as $pid ) {
				$rows[] = array( $u, $pid );
			}
		}
		if ( ! $rows ) {
			echo '<div class="wic-empty"><p>' . esc_html__( 'Nobody on your team has a path yet.', 'wic-tp' ) . '</p></div>';
			return;
		}
		?>
		<div class="wic-table-wrap">
		<table class="wic-table" data-wic-sortable>
			<thead><tr><th scope="col"><?php esc_html_e( 'Name', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Path', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Current phase', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Progress', 'wic-tp' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<?php
				list( $u, $pid ) = $r;
				$phase = __( 'Complete', 'wic-tp' );
				foreach ( self::phases( $pid ) as $ph ) {
					foreach ( (array) $ph['steps'] as $s ) {
						if ( ! self::step_done( $u->ID, $s ) ) {
							$phase = $ph['name'];
							break 2;
						}
					}
				}
				$pct = self::progress( $u->ID, $pid );
				?>
				<tr>
					<td><?php echo esc_html( $u->display_name ); ?></td>
					<td><?php echo esc_html( get_the_title( $pid ) ); ?></td>
					<td><?php echo esc_html( $phase ); ?></td>
					<td data-sort="<?php echo (int) $pct; ?>"><?php echo (int) $pct; ?>%</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	public static function handle_assign() {
		check_admin_referer( 'wic_path_assign' );
		$uid = get_current_user_id();
		if ( ! current_user_can( 'wic_view_team' ) ) {
			WIC_E::deny();
		}
		$path   = isset( $_POST['path'] ) ? absint( $_POST['path'] ) : 0;
		$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
		if ( 'wic_path' !== get_post_type( $path ) || 'publish' !== get_post_status( $path ) ) {
			WIC_Portal::back( 'paths_assign', 'error' );
		}
		$ids = array();
		foreach ( WIC_E::team( $uid ) as $u ) {
			if ( 'user:' . $u->ID === $target || ( 'group:' . wic_user_group( $u->ID ) ) === $target ) {
				$ids[] = $u->ID;
			}
		}
		foreach ( $ids as $id ) {
			self::assign( $path, $id );
		}
		WIC_Portal::back( 'paths_assign', $ids ? 'path_assigned' : 'error' );
	}

	public static function person_section( $user_id, $viewer_id ) {
		$paths = self::user_paths( $user_id );
		if ( ! $paths ) {
			return;
		}
		echo '<section class="wic-section"><h3 class="wic-h3">' . esc_html__( 'Learning paths', 'wic-tp' ) . '</h3><ul>';
		foreach ( $paths as $pid ) {
			echo '<li>' . esc_html( get_the_title( $pid ) ) . ' — ' . (int) self::progress( $user_id, $pid ) . '%</li>';
		}
		echo '</ul></section>';
	}
}

add_action( 'wic_init', array( 'WIC_Paths', 'init' ) );
add_action( 'wic_register_types', array( 'WIC_Paths', 'register' ) );
