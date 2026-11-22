<?php
/**
 * Copyright (c) 2026 Ali Lashkari. All Rights Reserved. See LICENSE.
 *
 * فایل حذف کامل پلاگین.
 *
 * وردپرس این فایل را فقط زمانی اجرا می‌کند که کاربر از صفحه «افزونه‌ها»
 * روی «حذف» (Delete) بزند - نه «غیرفعال کردن» (Deactivate).
 * یعنی با غیرفعال کردن ساده، هیچ داده‌ای پاک نمی‌شود؛ فقط با حذف کامل.
 *
 * این فایل تمام ردپای پلاگین را از دیتابیس پاک می‌کند:
 *  - جدول اختصاصی توضیحات/لینک‌های دستی (wp_rc_manual_data)
 *  - تنظیمات پلاگین (rc_settings) و لاگ اتصال
 *  - تمام کش‌های Transient و fallback مربوط به API لن‌میس
 *  - زمان‌بندی Cron
 */

// جلوگیری از اجرای مستقیم این فایل خارج از فرآیند حذف پلاگین وردپرس
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * پاک‌سازی کامل دیتابیس برای یک سایت (استفاده در چندسایتی هم به همین شکل تکرار می‌شود)
 */
function rc_uninstall_cleanup_site() {
	global $wpdb;

	// ۱) حذف جدول اختصاصی داده‌های دستی
	$table = $wpdb->prefix . 'rc_manual_data';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	// ۲) حذف آپشن‌های ثابت پلاگین
	delete_option( 'rc_settings' );
	delete_option( 'rc_db_version' );
	delete_option( 'rc_lanmis_last_log' );
	delete_option( 'rc_license_key' );
	delete_option( 'rc_lanmis_warm_index' );
	delete_option( 'rc_merge_cache_gen' );
	delete_option( 'rc_lanmis_cache_gen' );
	delete_option( 'rc_title_priority_map' );

	// ۳) حذف تمام Transientها و fallbackهای پویای اتصال به لن‌میس
	// (نام این‌ها بر اساس هش پارامترهای هر درخواست ساخته می‌شود، پس با LIKE پاک می‌شوند)
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE 'rc_lanmis_fallback_%'
		    OR option_name LIKE '_transient_rc_lanmis_%'
		    OR option_name LIKE '_transient_timeout_rc_lanmis_%'
		    OR option_name LIKE '_transient_rc_merge_html_%'
		    OR option_name LIKE '_transient_timeout_rc_merge_html_%'
		    OR option_name LIKE '_transient_rc_sc_html_%'
		    OR option_name LIKE '_transient_timeout_rc_sc_html_%'
		    OR option_name LIKE '_transient_rc_merge_rl_%'
		    OR option_name LIKE '_transient_timeout_rc_merge_rl_%'
		    OR option_name LIKE '_transient_rc_lanmis_bgq_%'
		    OR option_name LIKE '_transient_timeout_rc_lanmis_bgq_%'
		    OR option_name LIKE '_transient_rc_lanmis_breaker'
		    OR option_name LIKE '_transient_timeout_rc_lanmis_breaker'
		    OR option_name LIKE '_transient_rc_github_release_cache'
		    OR option_name LIKE '_transient_timeout_rc_github_release_cache'"
	); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	// ۴) حذف زمان‌بندی Cron
	$timestamp = wp_next_scheduled( 'rc_scheduled_sync' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'rc_scheduled_sync' );
	}
	wp_clear_scheduled_hook( 'rc_scheduled_sync' );

	// هوک تازه‌سازی پس‌زمینه (تک‌بار) با آرگومان ثبت می‌شود، پس باید با
	// wp_unschedule_hook پاک شود؛ wp_clear_scheduled_hook فقط رویدادهای
	// بدون آرگومان را حذف می‌کند.
	if ( function_exists( 'wp_unschedule_hook' ) ) {
		wp_unschedule_hook( 'rc_lanmis_bg_refresh' );
	} else {
		wp_clear_scheduled_hook( 'rc_lanmis_bg_refresh' );
	}
}

// پشتیبانی از حالت چندسایتی (Multisite) - پاک‌سازی برای هر سایت جدا
if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		rc_uninstall_cleanup_site();
		restore_current_blog();
	}
} else {
	rc_uninstall_cleanup_site();
}
