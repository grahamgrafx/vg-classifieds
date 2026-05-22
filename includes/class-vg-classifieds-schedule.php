<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'VG_Classifieds_Schedule', false ) ) {

	/**
	 * Expire-at meta and helpers for vg_classified. Publish timing uses WordPress core (post_date / future).
	 */
	class VG_Classifieds_Schedule {
		const CPT             = 'vg_classified';
		const META_EXPIRE_AT   = '_vg_expire_at';
		const DATE_FORMAT      = 'Y-m-d';
		const DATETIME_FORMAT  = 'Y-m-d H:i:s';
		const EXPIRE_TIME_EOD  = '23:59:59';
		const MIGRATION_FLAG   = 'vg_classifieds_removed_publish_meta';

		public static function init() {
			add_action( 'init', array( __CLASS__, 'register_meta' ) );
			add_action( 'init', array( __CLASS__, 'migrate_legacy_publish_meta' ), 20 );
			add_filter( 'update_post_metadata', array( __CLASS__, 'validate_expire_meta_update' ), 10, 5 );
		}

		public static function register_meta() {
			register_post_meta(
				self::CPT,
				self::META_EXPIRE_AT,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => array( __CLASS__, 'sanitize_meta_value' ),
					'auth_callback'     => array( __CLASS__, 'auth_edit_post' ),
				)
			);
		}

		/**
		 * Remove deprecated _vg_publish_at meta (one-time).
		 */
		public static function migrate_legacy_publish_meta() {
			if ( get_option( self::MIGRATION_FLAG ) ) {
				return;
			}

			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_vg_publish_at' ) );

			update_option( self::MIGRATION_FLAG, 1, false );
		}

		/**
		 * @param mixed  $meta_value Raw meta value.
		 * @param string $meta_key   Meta key.
		 * @param string $object_type Object type.
		 * @return string Empty string clears stored value.
		 */
		public static function sanitize_meta_value( $meta_value, $meta_key, $object_type ) {
			unset( $meta_key, $object_type );
			return self::sanitize_expire_date( $meta_value );
		}

		/**
		 * @param bool   $allowed   Whether allowed.
		 * @param string $meta_key  Meta key.
		 * @param int    $post_id   Post ID.
		 * @return bool
		 */
		public static function auth_edit_post( $allowed, $meta_key, $post_id ) {
			unset( $allowed, $meta_key );
			return (bool) current_user_can( 'edit_post', (int) $post_id );
		}

		/**
		 * @param null|bool $check      Short-circuit return value.
		 * @param int       $object_id  Post ID.
		 * @param string    $meta_key   Meta key.
		 * @param mixed     $meta_value New value.
		 * @param mixed     $prev_value Previous value.
		 * @return null|bool
		 */
		public static function validate_expire_meta_update( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
			unset( $prev_value );

			if ( self::META_EXPIRE_AT !== $meta_key || self::CPT !== get_post_type( (int) $object_id ) ) {
				return $check;
			}

			$expire_at = self::sanitize_expire_date( $meta_value );
			if ( '' === $expire_at ) {
				return $check;
			}

			$valid = self::validate_expire_against_post_date( $expire_at, (int) $object_id );
			if ( is_wp_error( $valid ) ) {
				return false;
			}

			return $check;
		}

		/**
		 * @return \DateTimeZone
		 */
		public static function timezone() {
			return wp_timezone();
		}

		/**
		 * Current moment in site timezone as MySQL datetime string.
		 *
		 * @return string
		 */
		public static function now_mysql() {
			return wp_date( self::DATETIME_FORMAT, null, self::timezone() );
		}

		/**
		 * Normalize date input to stored datetime (end of that calendar day, site TZ).
		 *
		 * @param mixed $value Raw input (Y-m-d, or legacy datetime string).
		 * @return string MySQL datetime or empty.
		 */
		public static function sanitize_expire_date( $value ) {
			$value = is_string( $value ) ? trim( $value ) : '';
			if ( '' === $value ) {
				return '';
			}

			$tz = self::timezone();

			if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $value, $m ) ) {
				$date = $m[1];
			} else {
				return '';
			}

			$dt = DateTimeImmutable::createFromFormat(
				self::DATETIME_FORMAT,
				$date . ' ' . self::EXPIRE_TIME_EOD,
				$tz
			);

			if ( false === $dt ) {
				return '';
			}

			return $dt->format( self::DATETIME_FORMAT );
		}

		/**
		 * @param string $mysql Stored datetime.
		 * @return string Value for date input (Y-m-d) or empty.
		 */
		public static function mysql_to_date_input( $mysql ) {
			$mysql = (string) $mysql;
			if ( '' === $mysql ) {
				return '';
			}

			$dt = DateTimeImmutable::createFromFormat( self::DATETIME_FORMAT, $mysql, self::timezone() );
			if ( false === $dt ) {
				return '';
			}

			return $dt->format( self::DATE_FORMAT );
		}

		/**
		 * @param string $mysql Stored datetime.
		 * @return string|false Unix timestamp or false.
		 */
		public static function mysql_to_timestamp( $mysql ) {
			$mysql = (string) $mysql;
			if ( '' === $mysql ) {
				return false;
			}

			$dt = DateTimeImmutable::createFromFormat( self::DATETIME_FORMAT, $mysql, self::timezone() );
			if ( false === $dt ) {
				return false;
			}

			return $dt->getTimestamp();
		}

		/**
		 * Publish moment from WordPress post_date (site timezone).
		 *
		 * @param int $post_id Post ID.
		 * @return string|false Unix timestamp or false.
		 */
		public static function post_publish_timestamp( $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post || ! $post->post_date ) {
				return false;
			}

			return self::mysql_to_timestamp( $post->post_date );
		}

		/**
		 * Expire must be after the post's publish date (core scheduler / post_date).
		 *
		 * @param string $expire_at Expire datetime (MySQL).
		 * @param int    $post_id   Post ID.
		 * @return true|\WP_Error
		 */
		public static function validate_expire_against_post_date( $expire_at, $post_id ) {
			$expire_at = (string) $expire_at;
			if ( '' === $expire_at ) {
				return true;
			}

			$expire_ts  = self::mysql_to_timestamp( $expire_at );
			$publish_ts = self::post_publish_timestamp( $post_id );

			if ( false === $expire_ts || false === $publish_ts ) {
				return true;
			}

			if ( $expire_ts <= $publish_ts ) {
				return new WP_Error(
					'vg_classifieds_invalid_schedule',
					__( 'Expire date must be on or after the post publish date.', 'vg-classifieds' )
				);
			}

			return true;
		}

		/**
		 * @param int    $post_id Post ID.
		 * @param string $value   MySQL datetime or empty to delete.
		 */
		public static function update_expire_meta( $post_id, $value ) {
			self::update_meta( $post_id, self::META_EXPIRE_AT, $value );
		}

		/**
		 * @param int    $post_id Post ID.
		 * @param string $meta_key Meta key.
		 * @param string $value    MySQL datetime or empty to delete.
		 */
		public static function update_meta( $post_id, $meta_key, $value ) {
			$post_id = (int) $post_id;
			$value   = (string) $value;

			if ( '' === $value ) {
				delete_post_meta( $post_id, $meta_key );
				return;
			}

			update_post_meta( $post_id, $meta_key, $value );
		}

		/**
		 * @param int $post_id Post ID.
		 * @return string
		 */
		public static function format_expire_for_display( $post_id ) {
			return self::format_for_display( $post_id, self::META_EXPIRE_AT );
		}

		/**
		 * @param int    $post_id Post ID.
		 * @param string $meta_key Meta key.
		 * @return string
		 */
		public static function format_for_display( $post_id, $meta_key ) {
			$mysql = get_post_meta( (int) $post_id, $meta_key, true );
			if ( ! is_string( $mysql ) || '' === $mysql ) {
				return '—';
			}

			$ts = self::mysql_to_timestamp( $mysql );
			if ( false === $ts ) {
				return $mysql;
			}

			return wp_date( get_option( 'date_format' ), $ts, self::timezone() );
		}
	}
}
