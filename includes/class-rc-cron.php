<?php
/**
 * Copyright (c) 2026 Ali Lashkari. All Rights Reserved. See LICENSE.
 *
 * زمان‌بندی همگام‌سازی خودکار پس‌زمینه (بند ۴ سند: «پنل تنظیمات مرکزی برای
 * زمان‌بندی sync خودکار»).
 *
 * چون endpoint اصلی کلاس‌ها (class_list) نیاز به branchKey/termKey مشخص دارد
 * و این پلاگین به‌صورت عمومی نمی‌داند کدام شعبه/ترم‌ها در سایت استفاده می‌شوند،
 * Cron خودکار سبک‌وزن endpointهای پایه (شعب/ترم‌ها/دپارتمان‌ها) را تازه نگه
 * می‌دارد. جدول کلاس‌ها با TTL کش خودش (مستقل) طبق بازدید واقعی کاربران
 * تازه‌سازی می‌شود.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RC_Cron {

	const HOOK = 'rc_scheduled_sync';

	/** بازه‌های مجاز زمان‌بندی (هر مقدار دیگری باعث می‌شود Cron هرگز ثبت نشود) */
	public static function allowed_intervals() {
		return array( 'rc_15_min', 'rc_30_min', 'hourly', 'twicedaily', 'daily' );
	}

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_custom_schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'run_sync' ) );

		// تازه‌سازی پس‌زمینه یک ترکیب مشخص پارامتری.
		// این همان چیزی است که باعث می‌شود بازدیدکننده هیچ‌وقت هزینه انقضای
		// کش را نپردازد: به او داده قدیمی سریع داده می‌شود و تازه‌سازی واقعی
		// چند ثانیه بعد و بیرون از مسیر رندر صفحه انجام می‌گیرد.
		add_action(
			RC_Lanmis_Connector::REFRESH_HOOK,
			array( 'RC_Lanmis_Connector', 'handle_background_refresh' ),
			10,
			2
		);
	}

	public static function add_custom_schedules( $schedules ) {
		$schedules['rc_15_min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => 'هر ۱۵ دقیقه (رادمان)',
		);
		$schedules['rc_30_min'] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => 'هر ۳۰ دقیقه (رادمان)',
		);
		return $schedules;
	}

	/**
	 * فعال‌سازی پلاگین: زمان‌بندی اولیه Cron بر اساس تنظیمات ذخیره‌شده (یا پیش‌فرض hourly)
	 */
	public static function activate() {
		$settings = get_option( 'rc_settings', array() );
		$interval = isset( $settings['sync_interval'] ) ? $settings['sync_interval'] : 'hourly';
		self::reschedule( $interval );
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
		// رویدادهای تک‌بار تازه‌سازی پس‌زمینه هم بعد از غیرفعال‌سازی نباید بمانند
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( RC_Lanmis_Connector::REFRESH_HOOK );
		}
	}

	/**
	 * حذف زمان‌بندی قبلی و ثبت مجدد با بازه جدید - وقتی ادمین تنظیمات را تغییر می‌دهد صدا زده می‌شود
	 */
	public static function reschedule( $interval ) {
		// اعتبارسنجی: قبلاً هر رشته‌ای پذیرفته می‌شد و اگر بازه ناشناخته بود،
		// wp_schedule_event() فقط false برمی‌گرداند - یعنی زمان‌بندی قبلی حذف
		// می‌شد و هیچ زمان‌بندی جدیدی جایش نمی‌آمد و همگام‌سازی خودکار
		// بی‌صدا برای همیشه می‌مُرد.
		if ( ! in_array( $interval, self::allowed_intervals(), true ) ) {
			$interval = 'hourly';
		}

		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
		wp_schedule_event( time() + 60, $interval, self::HOOK );
	}

	/**
	 * اجرای واقعی sync پس‌زمینه - force refresh روی endpointهای سبک
	 */
	public static function run_sync() {
		if ( class_exists( 'RC_License' ) && ! RC_License::instance()->is_valid() ) {
			return;
		}
		$connector = RC_Lanmis_Connector::instance();
		// توجه مهم: اینجا نباید purge_all_cache() صدا زده شود. آن تابع علاوه بر
		// کش موقت، fallback (آخرین داده معتبر ذخیره‌شده برای زمان قطعی/کندی
		// لن‌میس) را هم پاک می‌کند. چون این Cron هر ۱۵ تا ۳۰ دقیقه (بسته به
		// تنظیمات ادمین) به‌صورت خودکار اجرا می‌شود، صدا زدن purge_all_cache
		// اینجا باعث می‌شد بعد از هر اجرا، یک بازه زمانی پیش بیاد که هم کش و
		// هم fallback کلاس‌ها خالی است؛ اگر دقیقاً در همان بازه یک بازدیدکننده
		// به صفحه‌ای برسد که درخواست تازه‌اش به لن‌میس کند/ناموفق باشد (که با
		// توجه به حجم بالای کلاس‌ها و نبود صفحه‌بندی در API کاملاً محتمل است)،
		// هیچ داده‌ای (نه تازه، نه حتی قدیمیِ fallback) برای نمایش نمی‌ماند و
		// کاربر صفحه را خالی می‌بیند. با force=true در ادامه، این سه endpoint
		// سبک همینطور هم تازه‌سازی می‌شوند، بدون این‌که شبکه ایمنی fallback
		// کل سایت (که مربوط به class_list هر صفحه است) پاک شود.
		$connector->get_branches( true );
		$connector->get_terms( true );
		$connector->get_dep_levels( '', true );

		// -----------------------------------------------------------------
		// پیش‌گرم‌کردن جدول کلاس‌ها (بخش سنگین)
		// -----------------------------------------------------------------
		// قبلاً class_list هیچ‌وقت در پس‌زمینه تازه نمی‌شد: هر بار کش منقضی
		// می‌شد، *اولین بازدیدکننده بعدی* هزینه‌اش را می‌پرداخت - چند درخواست
		// همگام و کند به لن‌میس، پشت سر هم، وسط رندر صفحه. دقیقاً همان
		// «سایت گاهی خیلی کند است»ی که دیده می‌شد.
		//
		// حالا کانکتور ترکیب‌های پارامتری واقعاً استفاده‌شده در سایت را
		// به‌خاطر می‌سپارد و همین‌جا (بیرون از مسیر کاربر، با timeout بلند و
		// اجازه retry) تازه‌شان می‌کنیم. نتیجه: بازدید صفحه تقریباً همیشه
		// یک خواندن ساده از کش است.
		// سقف زمانی کل حلقه پیش‌گرم‌کردن. هر هدف می‌تواند تا ۴۰ ثانیه (× ۳ تلاش
		// در صورت ۴۲۹) طول بکشد؛ بدون این سقف، یک لن‌میس کند می‌توانست اجرای
		// Cron را چند دقیقه زنده نگه دارد و روی هاست‌های اشتراکی هم به
		// max_execution_time بخورد و هم با اجرای بعدی همپوشانی پیدا کند.
		$deadline = microtime( true ) + 120;

		foreach ( $connector->get_warm_targets( RC_Lanmis_Connector::WARM_MAX ) as $target ) {
			if ( empty( $target['endpoint'] ) ) {
				continue;
			}
			if ( microtime( true ) > $deadline ) {
				break; // بقیه در اجرای بعدی Cron گرم می‌شوند
			}
			$connector->request( $target['endpoint'], isset( $target['params'] ) ? (array) $target['params'] : array(), true );
		}

		// کش HTML جدول‌های ادغام‌شده هم باید همراه داده تازه شود
		if ( class_exists( 'RC_Shortcodes' ) ) {
			RC_Shortcodes::purge_merge_cache();
		}
	}
}
