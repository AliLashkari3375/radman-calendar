<?php
/**
 * Plugin Name: Radman Calendar
 * Plugin URI: https://alilashkari.ir/
 * Description: سیستم جامع تقویم آموزشی - اتصال به API لن‌میس، شرت‌کدهای تقویم/دوره، و پنل مدیریت.
 * Version: 1.8.0
 * Author: Ali Lashkari
 * Author URI: https://alilashkari.ir/
 * Text Domain: radman-calendar
 *
 * پیوست فنی مرجع: پیوست فنی ۱۰۰۰
 *
 * ---------------------------------------------------------------------
 * Copyright (c) 2026 Ali Lashkari. All Rights Reserved.
 * این مخزن فقط برای نمایش (پورتفولیو) منتشر شده است. کپی، ویرایش، توزیع
 * یا فروش این کد بدون اجازه‌ی کتبی نویسنده مجاز نیست. برای جزئیات کامل،
 * فایل LICENSE در ریشه‌ی ریپو را ببین.
 * ---------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // جلوگیری از دسترسی مستقیم
}

// -----------------------------------------------------------------------
// ثابت‌های اصلی پلاگین
// -----------------------------------------------------------------------
define( 'RC_VERSION', '1.8.0' );
define( 'RC_PATH', plugin_dir_path( __FILE__ ) );
define( 'RC_URL', plugin_dir_url( __FILE__ ) );
define( 'RC_DB_VERSION', '1.1' ); // برای مایگریشن‌های آینده جدول دیتابیس

/**
 * توکن API لن‌میس.
 * روش پیشنهادی: تعریف در wp-config.php به صورت:
 *   define( 'RC_LANMIS_TOKEN', 'your-token-here' );
 * اگر تعریف نشده باشد، از تنظیمات ذخیره‌شده در wp_options (صفحه تنظیمات پلاگین) خوانده می‌شود.
 */
if ( ! defined( 'RC_LANMIS_BASE_URL' ) ) {
	define( 'RC_LANMIS_BASE_URL', 'https://api.lanmis.com/' );
}

// -----------------------------------------------------------------------
// اطلاعات توسعه‌دهنده (پشتیبانی)
// -----------------------------------------------------------------------
/**
 * برای تغییر اطلاعات پشتیبانی فقط همین چند خط را ویرایش کن (یا همان نام‌ها را
 * در wp-config.php تعریف کن تا با آپدیت افزونه بازنویسی نشوند).
 *
 * نسخه ۱.۷.۳: این ثابت‌ها قبلاً داخل فایل ویجت پیشخوان تعریف می‌شدند، در حالی
 * که RC_License و RC_Updater هم استفاده‌شان می‌کنند. یعنی هر جایی که آن فایل
 * لود نمی‌شد (رندر سایت، درخواست‌های AJAX، Cron)، پیام «برای تمدید تماس
 * بگیرید» بی‌صدا بدون شماره تماس نمایش داده می‌شد. تعریف باید جایی باشد که
 * همیشه لود می‌شود.
 */
if ( ! defined( 'RC_DEV_NAME' ) ) {
	define( 'RC_DEV_NAME', 'Ali Lashkari' );
}
if ( ! defined( 'RC_DEV_PHONE' ) ) {
	define( 'RC_DEV_PHONE', '+989931886367' );
}
if ( ! defined( 'RC_DEV_EMAIL' ) ) {
	define( 'RC_DEV_EMAIL', 'alilashkari020@gmail.com' );
}
if ( ! defined( 'RC_DEV_WEBSITE' ) ) {
	define( 'RC_DEV_WEBSITE', 'alilashkari.ir' );
}
if ( ! defined( 'RC_DEV_NOTE' ) ) {
	define( 'RC_DEV_NOTE', 'برای پشتیبانی، درخواست تغییر یا آپدیت این افزونه با اطلاعات بالا تماس بگیرید.' );
}

// -----------------------------------------------------------------------
// بارگذاری کلاس‌های اصلی
// -----------------------------------------------------------------------
// کلاس‌هایی که هم روی سایت و هم در پنل لازم‌اند
require_once RC_PATH . 'includes/class-rc-jalali.php';
require_once RC_PATH . 'includes/class-rc-lanmis-connector.php';
require_once RC_PATH . 'includes/class-rc-manual-data.php';
require_once RC_PATH . 'includes/class-rc-query.php';
require_once RC_PATH . 'includes/class-rc-cron.php';
require_once RC_PATH . 'includes/class-rc-shortcodes.php';
require_once RC_PATH . 'includes/class-rc-debug.php';
require_once RC_PATH . 'includes/class-rc-license.php';

/**
 * آیا این درخواست یک بستر «مدیریتی» است؟
 * صفحه‌های پنل، admin-ajax، Cron و WP-CLI. رندر صفحه عمومی سایت این نیست.
 */
function rc_is_admin_context() {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return true;
	}
	if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
		return true;
	}
	return is_admin();
}

/**
 * آیا این درخواست، رندر یک «صفحه» واقعی پیشخوان است؟
 *
 * admin-ajax.php هم is_admin()=true می‌دهد، ولی روی هر صفحه باز پیشخوان،
 * Heartbeat هر ۱۵ ثانیه و ذخیره خودکار و جستجوی لینک و ... مدام به آن
 * می‌کوبند. کلاس‌هایی که فقط منو/فیلد تنطیمات/ویجت پیشخوان می‌سازند، در آن
 * درخواست‌ها هیچ کاری ندارند و لود شدنشان خالص هزینه است.
 */
function rc_is_admin_screen() {
	if ( ! is_admin() ) {
		return false;
	}
	if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
		return false;
	}
	return true;
}

/**
 * کلاس‌های صرفاً مدیریتی فقط در بستر مدیریتی لود می‌شوند.
 * قبلاً هر بازدید صفحه سایت، پنج فایل کلاس اضافی را include و ده‌ها هوک
 * ادمین را ثبت می‌کرد که روی فرانت هیچ‌وقت اجرا نمی‌شدند.
 */
if ( rc_is_admin_context() ) {
	// این دو کلاس هندلرهای AJAX ثبت می‌کنند، پس در admin-ajax هم لازم‌اند.
	require_once RC_PATH . 'includes/class-rc-admin-manual.php';
	require_once RC_PATH . 'includes/class-rc-admin-builder.php';
	// آپدیت‌چکر روی هوک بررسی آپدیت‌ها می‌نشیند که در Cron هم اجرا می‌شود.
	require_once RC_PATH . 'includes/class-rc-updater.php';
}

// منو، فیلدهای تنطیمات و ویجت پیشخوان فقط روی صفحه‌های واقعی پیشخوان معنا دارند.
if ( rc_is_admin_screen() ) {
	require_once RC_PATH . 'includes/class-rc-admin-settings.php';
	require_once RC_PATH . 'includes/class-rc-dashboard-widget.php';
}

// -----------------------------------------------------------------------
// Activation / Deactivation
// -----------------------------------------------------------------------
register_activation_hook(
	__FILE__,
	function () {
		RC_Manual_Data::install_table();
		RC_Cron::activate();
	}
);
register_deactivation_hook( __FILE__, array( 'RC_Cron', 'deactivate' ) );
RC_Cron::init();

/**
 * راه‌اندازی پلاگین بعد از لود شدن تمام پلاگین‌ها
 */
function rc_init_plugin() {
	load_plugin_textdomain( 'radman-calendar', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	RC_Shortcodes::instance();
	RC_Debug::instance();
	RC_License::instance();

	if ( ! rc_is_admin_context() ) {
		return;
	}

	// مایگریشن جدول داده دستی (مثلاً ستون «نام دلخواه») برای سایت‌هایی که
	// فقط فایل‌های پلاگین را به‌روز می‌کنند و دوباره فعال‌سازی نمی‌کنند.
	// عمداً فقط روی صفحه‌های واقعی پیشخوان: dbDelta سنگین است و نباید احتمال
	// داشته باشد که وسط رندر یک صفحه عمومی، یک Heartbeat، یا چند درخواست
	// همزمان اجرا شود.
	if ( rc_is_admin_screen() ) {
		RC_Manual_Data::maybe_upgrade();
	}

	RC_Admin_Manual::instance();
	RC_Admin_Builder::instance();
	RC_Updater::instance();

	if ( rc_is_admin_screen() ) {
		RC_Admin_Settings::instance();
		RC_Dashboard_Widget::instance();
	}
}
add_action( 'plugins_loaded', 'rc_init_plugin' );

/**
 * بارگذاری استایل و اسکریپت عمومی سایت (فقط وقتی شرت‌کد استفاده شده - از طریق enqueue شرطی)
 */
function rc_enqueue_assets() {
	// به‌جای RC_VERSION ثابت، از filemtime (زمان آخرین ذخیره هر فایل) برای
	// شکستن کش استفاده می‌کنیم. این‌طوری هر بار CSS/JS را ویرایش و ذخیره
	// کنی، آدرس فایل خودش خودکار عوض می‌شود و مرورگر/کش سرور مجبور به
	// گرفتن نسخه تازه می‌شود - دیگر لازم نیست هر بار RC_VERSION را دستی
	// بالا ببری تا تغییرات ظاهری (مثلاً تو خروجی عکس) دیده شود.
	$style_path  = RC_PATH . 'assets/css/style.css';
	$script_path = RC_PATH . 'assets/js/script.js';

	$style_ver  = file_exists( $style_path ) ? filemtime( $style_path ) : RC_VERSION;
	$script_ver = file_exists( $script_path ) ? filemtime( $script_path ) : RC_VERSION;

	wp_register_style( 'rc-calendar-style', RC_URL . 'assets/css/style.css', array(), $style_ver );

	/**
	 * script.js کاملاً Vanilla JS است و یک خط jQuery در آن نیست؛ وابستگی
	 * 'jquery' فقط باعث می‌شد روی قالب‌هایی که jQuery لازم ندارند، وردپرس
	 * مجبور به لود کردنش شود.
	 *
	 * html2canvas (۱۹۴KB) و rc-image-export (۲۰KB) هم از فهرست وابستگی‌ها
	 * حذف شدند: دکمه «دانلود عکس» فقط در پیش‌نمایش شرت‌کدساز پنل ادمین
	 * ساخته می‌شود و روی سایت عمومی هرگز وجود ندارد، پس این ۲۱۴ کیلوبایت
	 * روی هر بازدید فقط دانلود و پارس می‌شد و هیچ‌وقت اجرا نمی‌شد.
	 * در صورت وجود دکمه، script.js همان لحظه on-demand لودشان می‌کند.
	 */
	wp_register_script( 'rc-calendar-script', RC_URL . 'assets/js/script.js', array(), $script_ver, true );

	// مرورگر را تشویق می‌کنیم CSS جدول را زودتر بردارد
	wp_script_add_data( 'rc-calendar-script', 'strategy', 'defer' );

	// پیش‌بارگذاری استایل در <head> برای برگه‌ها/نوشته‌هایی که شرت‌کد دارند (حذف FOUC و CLS)
	// بدون بارگذاری حتی ۱ بایت در صفحاتی که شرت‌کد ندارند.
	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post && isset( $post->post_content ) ) {
			if (
				has_shortcode( $post->post_content, 'radman_calendar' ) ||
				has_shortcode( $post->post_content, 'radman_course' ) ||
				has_shortcode( $post->post_content, 'radman_upcoming' )
			) {
				RC_Shortcodes::enqueue_frontend_assets();
			}
		}
	}
}
add_action( 'wp_enqueue_scripts', 'rc_enqueue_assets' );
