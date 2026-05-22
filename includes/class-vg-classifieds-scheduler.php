<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'VG_Classifieds_Scheduler', false ) ) {

	/**
	 * Cron-driven auto-expire (publish uses WordPress core post_date / future status).
	 */
	class VG_Classifieds_Scheduler {
		const CRON_HOOK     = 'vg_classifieds_process_schedules';
		const OPTION_LAST   = 'vg_classifieds_schedule_last_run';
		const DEFAULT_BATCH = 50;

		public static function init() {
			add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_interval' ) );
			add_action( self::CRON_HOOK, array( __CLASS__, 'process_schedules' ) );
			add_action( 'admin_init', array( __CLASS__, 'maybe_catch_up' ) );
		}

		/**
		 * @param array<string, array<string, int|string>> $schedules Cron schedules.
		 * @return array<string, array<string, int|string>>
		 */
		public static function add_cron_interval( $schedules ) {
			$minutes = (int) apply_filters( 'vg_classifieds_schedule_interval_minutes', 15 );
			$minutes = max( 1, $minutes );

			$schedules['vg_classifieds_interval'] = array(
				'interval' => $minutes * MINUTE_IN_SECONDS,
				'display'  => sprintf(
					/* translators: %d: minutes */
					__( 'Every %d minutes (vg-classifieds)', 'vg-classifieds' ),
					$minutes
				),
			);

			return $schedules;
		}

		public static function schedule_event() {
			if ( wp_next_scheduled( self::CRON_HOOK ) ) {
				return;
			}

			wp_schedule_event( time(), 'vg_classifieds_interval', self::CRON_HOOK );
		}

		public static function unschedule_event() {
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
			while ( $timestamp ) {
				wp_unschedule_event( $timestamp, self::CRON_HOOK );
				$timestamp = wp_next_scheduled( self::CRON_HOOK );
			}
		}

		/**
		 * Lightweight catch-up when cron may have been missed.
		 */
		public static function maybe_catch_up() {
			if ( ! apply_filters( 'vg_classifieds_schedule_catch_up', true ) ) {
				return;
			}

			$last      = (int) get_option( self::OPTION_LAST, 0 );
			$minutes   = (int) apply_filters( 'vg_classifieds_schedule_interval_minutes', 15 );
			$threshold = max( 1, $minutes ) * 2 * MINUTE_IN_SECONDS;

			if ( $last > 0 && ( time() - $last ) < $threshold ) {
				return;
			}

			self::process_schedules();
		}

		public static function process_schedules() {
			update_option( self::OPTION_LAST, time(), false );

			$batch = (int) apply_filters( 'vg_classifieds_schedule_batch_size', self::DEFAULT_BATCH );
			$batch = max( 1, $batch );
			$now   = VG_Classifieds_Schedule::now_mysql();

			self::expire_due( $now, $batch );
		}

		/**
		 * @param string $now   MySQL datetime (site TZ).
		 * @param int    $batch Max posts per run.
		 */
		private static function expire_due( $now, $batch ) {
			$query = new WP_Query(
				array(
					'post_type'              => VG_Classifieds_Schedule::CPT,
					'post_status'            => 'publish',
					'posts_per_page'         => $batch,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'meta_query'             => array(
						'relation' => 'AND',
						array(
							'key'     => VG_Classifieds_Schedule::META_EXPIRE_AT,
							'compare' => 'EXISTS',
						),
						array(
							'key'     => VG_Classifieds_Schedule::META_EXPIRE_AT,
							'value'   => $now,
							'compare' => '<=',
							'type'    => 'DATETIME',
						),
					),
					'meta_key'               => VG_Classifieds_Schedule::META_EXPIRE_AT,
					'orderby'                => 'meta_value',
					'order'                  => 'ASC',
				)
			);

			foreach ( $query->posts as $post_id ) {
				$post_id = (int) $post_id;
				if ( 'publish' !== get_post_status( $post_id ) ) {
					continue;
				}

				$result = wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => 'draft',
					),
					true
				);

				if ( ! is_wp_error( $result ) && $result ) {
					/**
					 * Fires after a classified is auto-expired (draft) by schedule.
					 *
					 * @param int $post_id Post ID.
					 */
					do_action( 'vg_classifieds_expired_scheduled', $post_id );
				}
			}
		}
	}
}
