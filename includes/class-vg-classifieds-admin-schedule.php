<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'VG_Classifieds_Admin_Schedule', false ) ) {

	/**
	 * Admin meta box and list column for auto-expire. Publish uses core post scheduler.
	 */
	class VG_Classifieds_Admin_Schedule {
		const NONCE_ACTION = 'vg_classifieds_save_schedule';
		const NONCE_FIELD  = 'vg_classifieds_schedule_nonce';
		const ERROR_KEY    = 'vg_classifieds_schedule_error';

		public static function init() {
			add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
			add_action( 'save_post_' . VG_Classifieds_Schedule::CPT, array( __CLASS__, 'save_post' ), 10, 2 );
			add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
			add_filter( 'manage_' . VG_Classifieds_Schedule::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
			add_filter( 'manage_edit-' . VG_Classifieds_Schedule::CPT . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
			add_action( 'manage_' . VG_Classifieds_Schedule::CPT . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
			add_action( 'pre_get_posts', array( __CLASS__, 'sort_posts_by_expire' ) );
		}

		public static function add_meta_box() {
			add_meta_box(
				'vg-classifieds-schedule',
				__( 'Expiration', 'vg-classifieds' ),
				array( __CLASS__, 'render_meta_box' ),
				VG_Classifieds_Schedule::CPT,
				'side',
				'high'
			);
		}

		/**
		 * @param WP_Post $post Post object.
		 */
		public static function render_meta_box( $post ) {
			wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

			$expire_at = VG_Classifieds_Schedule::mysql_to_date_input(
				get_post_meta( $post->ID, VG_Classifieds_Schedule::META_EXPIRE_AT, true )
			);

			$tz_string = wp_timezone_string();
			?>
			<p class="description" style="margin-top:0;">
				<?php esc_html_e( 'To schedule when this Classified goes live, use the Publish control in the document sidebar (WordPress core).', 'vg-classifieds' ); ?>
			</p>
			<p>
				<label for="vg_expire_at"><strong><?php esc_html_e( 'Expires on', 'vg-classifieds' ); ?></strong></label><br />
				<input type="date" id="vg_expire_at" name="vg_expire_at" value="<?php echo esc_attr( $expire_at ); ?>" class="widefat" />
			</p>
			<p class="description">
				<?php esc_html_e( 'Last day the Classified stays published. It moves to Draft after the end of this day.', 'vg-classifieds' ); ?>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: site timezone string */
					esc_html__( 'Calendar dates use site timezone (%s). Must be on or after the publish date. Leave blank for no automatic expiration.', 'vg-classifieds' ),
					esc_html( $tz_string )
				);
				?>
			</p>
			<?php
		}

		/**
		 * @param int     $post_id Post ID.
		 * @param WP_Post $post    Post object.
		 */
		public static function save_post( $post_id, $post ) {
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			if ( wp_is_post_revision( $post_id ) ) {
				return;
			}

			if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
				return;
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			$expire_raw = isset( $_POST['vg_expire_at'] ) ? wp_unslash( $_POST['vg_expire_at'] ) : '';
			$expire_at  = VG_Classifieds_Schedule::sanitize_expire_date( $expire_raw );

			$valid = VG_Classifieds_Schedule::validate_expire_against_post_date( $expire_at, $post_id );
			if ( is_wp_error( $valid ) ) {
				set_transient( self::ERROR_KEY . '_' . get_current_user_id(), $valid->get_error_message(), 30 );
				return;
			}

			VG_Classifieds_Schedule::update_expire_meta( $post_id, $expire_at );
		}

		public static function admin_notices() {
			$key = self::ERROR_KEY . '_' . get_current_user_id();
			$msg = get_transient( $key );
			if ( ! $msg ) {
				return;
			}
			delete_transient( $key );

			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		/**
		 * @param string[] $columns List table columns.
		 * @return string[]
		 */
		public static function columns( $columns ) {
			$new = array();
			foreach ( $columns as $key => $label ) {
				$new[ $key ] = $label;
				if ( 'title' === $key ) {
					$new['vg_expire_at'] = __( 'Expires on', 'vg-classifieds' );
				}
			}
			return $new;
		}

		/**
		 * @param string[] $columns Sortable columns.
		 * @return string[]
		 */
		public static function sortable_columns( $columns ) {
			$columns['vg_expire_at'] = 'vg_expire_at';
			return $columns;
		}

		/**
		 * @param WP_Query $query Main admin list query.
		 */
		public static function sort_posts_by_expire( $query ) {
			if ( ! is_admin() || ! $query->is_main_query() ) {
				return;
			}

			if ( VG_Classifieds_Schedule::CPT !== $query->get( 'post_type' ) ) {
				return;
			}

			if ( 'vg_expire_at' !== $query->get( 'orderby' ) ) {
				return;
			}

			$query->set( 'vg_classifieds_orderby_expire', true );
			add_filter( 'posts_clauses', array( __CLASS__, 'posts_clauses_sort_expire' ), 10, 2 );
		}

		/**
		 * Sort by expire meta (DATETIME); posts without a date sort last.
		 *
		 * @param string[] $clauses Query clauses.
		 * @param WP_Query $query   Query instance.
		 * @return string[]
		 */
		public static function posts_clauses_sort_expire( $clauses, $query ) {
			if ( ! $query->get( 'vg_classifieds_orderby_expire' ) ) {
				return $clauses;
			}

			remove_filter( 'posts_clauses', array( __CLASS__, 'posts_clauses_sort_expire' ), 10 );

			global $wpdb;

			$meta_key = VG_Classifieds_Schedule::META_EXPIRE_AT;
			$clauses['join'] .= $wpdb->prepare(
				" LEFT JOIN {$wpdb->postmeta} AS vg_expire_meta ON ({$wpdb->posts}.ID = vg_expire_meta.post_id AND vg_expire_meta.meta_key = %s) ",
				$meta_key
			);

			$order = 'desc' === strtolower( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';
			$clauses['orderby'] = "ISNULL(vg_expire_meta.meta_value) ASC, vg_expire_meta.meta_value {$order}, {$wpdb->posts}.post_title ASC";

			return $clauses;
		}

		/**
		 * @param string $column  Column key.
		 * @param int    $post_id Post ID.
		 */
		public static function column_content( $column, $post_id ) {
			if ( 'vg_expire_at' === $column ) {
				echo esc_html( VG_Classifieds_Schedule::format_expire_for_display( $post_id ) );
			}
		}
	}
}
