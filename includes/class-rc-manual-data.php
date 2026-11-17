<?php
/**
 * Copyright (c) 2026 Ali Lashkari. All Rights Reserved. See LICENSE.
 *
 * کلاس مدیریت جدول دیتابیس اختصاصی برای داده‌های دستی ادمین.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RC_Manual_Data {

	/** کش درون-درخواست ردیف‌های خوانده‌شده: class_key => row|null */
	private static $row_cache = array();

	/**
	 * نام جدول (بدون پیشوند wpdb - در متدها اضافه می‌شود)
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'rc_manual_data';
	}

	/**
	 * ایجاد جدول در زمان فعال‌سازی پلاگین
	 */
	public static function install_table() {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			class_key VARCHAR(191) NOT NULL,
			custom_title VARCHAR(255) NULL,
			description LONGTEXT NULL,
			custom_link VARCHAR(500) NULL,
			sort_priority INT NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY class_key (class_key)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'rc_db_version', RC_DB_VERSION );
	}

	/**
	 * مایگریشن خودکار جدول وقتی پلاگین به‌روز می‌شود ولی دوباره فعال‌سازی نمی‌شود.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'rc_db_version' ) === RC_DB_VERSION ) {
			return;
		}
		self::install_table();
	}

	/**
	 * دریافت داده دستی یک کلاس بر اساس class_key
	 */
	public static function get( $class_key ) {
		$class_key = (string) $class_key;
		if ( '' === $class_key ) {
			return null;
		}

		if ( array_key_exists( $class_key, self::$row_cache ) ) {
			return self::$row_cache[ $class_key ];
		}

		global $wpdb;
		$table = self::table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE class_key = %s", $class_key ),
			ARRAY_A
		);

		self::$row_cache[ $class_key ] = $row ? $row : null;

		return self::$row_cache[ $class_key ];
	}

	/**
	 * دریافت داده دستی برای چند class_key به‌صورت یک‌جا (با کش درون-درخواست)
	 *
	 * @param string[] $class_keys
	 * @return array  آرایه‌ای با کلید class_key => row
	 */
	public static function get_many( $class_keys ) {
		$indexed = array();
		$missing = array();

		foreach ( (array) $class_keys as $key ) {
			if ( ! is_scalar( $key ) || '' === (string) $key ) {
				continue;
			}
			$k = (string) $key;
			if ( array_key_exists( $k, self::$row_cache ) ) {
				if ( null !== self::$row_cache[ $k ] ) {
					$indexed[ $k ] = self::$row_cache[ $k ];
				}
			} else {
				$missing[ $k ] = true;
			}
		}

		$missing_keys = array_keys( $missing );
		if ( empty( $missing_keys ) ) {
			return $indexed;
		}

		global $wpdb;
		$table = self::table_name();

		foreach ( array_chunk( $missing_keys, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql          = "SELECT class_key, custom_title, description, custom_link, sort_priority
				FROM {$table} WHERE class_key IN ({$placeholders})";

			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $chunk ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			$found_in_chunk = array();
			foreach ( (array) $rows as $row ) {
				if ( isset( $row['class_key'] ) ) {
					$ck                          = (string) $row['class_key'];
					$indexed[ $ck ]              = $row;
					self::$row_cache[ $ck ]      = $row;
					$found_in_chunk[ $ck ]       = true;
				}
			}

			// کلیدهایی که در دیتابیس نبودند را هم null کش می‌کنیم تا دوباره کوئری نخورند
			foreach ( $chunk as $ck ) {
				if ( ! isset( $found_in_chunk[ $ck ] ) ) {
					self::$row_cache[ $ck ] = null;
				}
			}
		}

		return $indexed;
	}

	/**
	 * درج یا به‌روزرسانی داده دستی یک کلاس (از پنل ادمین استفاده می‌شود)
	 */
	public static function upsert( $class_key, $description = null, $custom_link = null, $sort_priority = null, $custom_title = null ) {
		global $wpdb;
		$table     = self::table_name();
		$class_key = (string) $class_key;

		$existing = self::get( $class_key );

		$data = array(
			'class_key'  => $class_key,
			'updated_at' => current_time( 'mysql' ),
		);
		if ( null !== $description ) {
			$data['description'] = wp_kses_post( $description );
		}
		if ( null !== $custom_link ) {
			$data['custom_link'] = esc_url_raw( $custom_link );
		}
		if ( null !== $sort_priority ) {
			$data['sort_priority'] = (int) $sort_priority;
		}
		if ( null !== $custom_title ) {
			$data['custom_title'] = sanitize_text_field( $custom_title );
		}

		unset( self::$row_cache[ $class_key ] );

		if ( $existing ) {
			return $wpdb->update( $table, $data, array( 'class_key' => $class_key ) );
		}

		return $wpdb->insert( $table, $data );
	}

	/**
	 * حذف داده دستی یک کلاس
	 */
	public static function delete( $class_key ) {
		global $wpdb;
		$class_key = (string) $class_key;
		unset( self::$row_cache[ $class_key ] );
		return $wpdb->delete( self::table_name(), array( 'class_key' => $class_key ) );
	}
}
