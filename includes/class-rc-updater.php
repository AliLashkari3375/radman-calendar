<?php
/**
 * Copyright (c) 2026 Ali Lashkari. All Rights Reserved. See LICENSE.
 *
 * آپدیت‌چکر مبتنی بر GitHub Releases.
 *
 * این کلاس افزونه را وادار می‌کند مثل پلاگین‌های رسمی وردپرس.org رفتار کند:
 * اگر نسخه جدیدتری در GitHub Releases منتشر شده باشد، در صفحه «افزونه‌ها» و
 * «به‌روزرسانی‌ها»ی خود وردپرس نمایش داده می‌شود و دکمه «Update Now» هم کار می‌کند.
 *
 * راه‌اندازی (بعد از ساخت ریپو):
 *   ۱) این دو مقدار را پایین همین فایل پر کن:
 *        RC_GITHUB_REPO  = 'نام‌کاربری/نام‌ریپو'   مثال: 'ricktech/radman-calendar'
 *        RC_GITHUB_TOKEN = فقط اگر ریپو خصوصی (Private) است؛ یک Personal Access
 *                          Token با دسترسی خواندن Contents/Releases بساز.
 *                          برای ریپوی عمومی این را خالی بگذار.
 *   ۲) هر بار نسخه جدید آماده شد، در GitHub یک Release جدید بساز با:
 *        - Tag مطابق نسخه، مثلاً v1.1.0 (پیشوند v اختیاری است)
 *        - یک فایل zip پیوست (Asset) دقیقاً با همین ساختاری که الان داری
 *          (پوشه radman-calendar/ داخل zip)، ترجیحاً با نام radman-calendar.zip
 *          (اگر asset پیوست نکنی، از zipball خودکار گیت‌هاب استفاده می‌شود که
 *          هم کار می‌کند ولی asset اختصاصی مطمئن‌تر و سریع‌تر است)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// -----------------------------------------------------------------------
// تنظیمات - این دو خط را بعد از ساخت ریپو پر کن
// -----------------------------------------------------------------------
if ( ! defined( 'RC_GITHUB_REPO' ) ) {
	define( 'RC_GITHUB_REPO', '' ); // مثال: 'ricktech/radman-calendar'
}
if ( ! defined( 'RC_GITHUB_TOKEN' ) ) {
	define( 'RC_GITHUB_TOKEN', '' ); // فقط برای ریپوی خصوصی لازم است
}

class RC_Updater {

	private static $instance = null;

	/** نام دایرکتوری/اسلاگ افزونه، مثلاً radman-calendar */
	private $slug;
	/** basename استاندارد وردپرس، مثلاً radman-calendar/radman-calendar.php */
	private $basename;
	/** کش نتیجه GitHub API (ساعت) */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;
	const CACHE_KEY = 'rc_github_release_cache';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->slug     = dirname( plugin_basename( RC_PATH . 'radman-calendar.php' ) );
		$this->basename = plugin_basename( RC_PATH . 'radman-calendar.php' );

		if ( empty( RC_GITHUB_REPO ) ) {
			return; // تا وقتی ریپو تنظیم نشده، این ماژول کاملاً خاموش می‌ماند
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'maybe_authenticated_download' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_folder_name' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'purge_cache' ), 10, 2 );
	}

	/**
	 * دریافت اطلاعات آخرین Release از GitHub API (با کش)
	 *
	 * @return array|null {version, zip_url, changelog, html_url} یا null در صورت خطا
	 */
	private function get_latest_release( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( false !== $cached ) {
				return $cached ? $cached : null;
			}
		}

		$url  = 'https://api.github.com/repos/' . RC_GITHUB_REPO . '/releases/latest';
		$args = array(
			'timeout' => 12,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'RadmanCalendarUpdater',
			),
		);
		if ( ! empty( RC_GITHUB_TOKEN ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . RC_GITHUB_TOKEN;
		}

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, 0, 15 * MINUTE_IN_SECONDS ); // خطا را هم کوتاه‌مدت کش کن تا اسپم درخواست نشه
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, 0, 15 * MINUTE_IN_SECONDS );
			return null;
		}

		$version = ltrim( $body['tag_name'], 'vV' );

		// اولویت با asset اختصاصی zip (با نام دقیق اسلاگ)؛ در غیر این صورت zipball خودکار گیت‌هاب
		$zip_url = '';
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( isset( $asset['name'] ) && $asset['name'] === $this->slug . '.zip' ) {
					// ریپوی خصوصی: آدرس API asset (با توکن و Accept: octet-stream
					// در maybe_authenticated_download دانلود می‌شود).
					// ریپوی عمومی: آدرس API بدون آن هدر فقط JSON متادیتا برمی‌گرداند
					// و آپدیت با «بسته نامعتبر» شکست می‌خورد؛ پس browser_download_url.
					if ( empty( RC_GITHUB_TOKEN ) && ! empty( $asset['browser_download_url'] ) ) {
						$zip_url = $asset['browser_download_url'];
					} else {
						$zip_url = $asset['url'];
					}
					break;
				}
			}
		}
		if ( empty( $zip_url ) && ! empty( $body['zipball_url'] ) ) {
			$zip_url = $body['zipball_url'];
		}

		if ( empty( $zip_url ) || ! self::is_trusted_github_url( $zip_url ) ) {
			// آدرس بسته مستقیم به عنوان «package» به وردپرس داده می‌شود و
			// وردپرس آن را دانلود و روی فایل‌های سایت باز می‌کند. اگر پاسخ API
			// (یا یک پاسخ جعلی وسط راه) آدرسی بیرون از گیت‌هاب بدهد، این عملاً
			// اجرای کد دلخواه روی سایت است. پس فقط دامنه‌های خود گیت‌هاب.
			set_transient( self::CACHE_KEY, 0, 15 * MINUTE_IN_SECONDS );
			return null;
		}

		$data = array(
			'version'   => $version,
			'zip_url'   => $zip_url,
			'changelog' => isset( $body['body'] ) ? $body['body'] : '',
			'html_url'  => isset( $body['html_url'] ) ? $body['html_url'] : '',
		);

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	/**
	 * آیا این آدرس واقعاً روی زیرساخت خود گیت‌هاب است؟
	 * (جلوگیری از تزریق آدرس بسته دلخواه از طریق پاسخ دستکاری‌شده API)
	 */
	private static function is_trusted_github_url( $url ) {
		$parts  = wp_parse_url( $url );
		$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';

		if ( 'https' !== $scheme || '' === $host ) {
			return false;
		}

		$allowed = array( 'api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com' );

		return in_array( $host, $allowed, true );
	}

	/**
	 * قلاب اصلی: تزریق اطلاعات نسخه جدید به transient آپدیت‌های وردپرس
	 */
	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$installed = isset( $transient->checked[ $this->basename ] ) ? $transient->checked[ $this->basename ] : RC_VERSION;

		if ( version_compare( $release['version'], $installed, '>' ) ) {
			$item = array(
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $release['version'],
				'url'         => $release['html_url'],
				'package'     => $release['zip_url'],
				'tested'      => get_bloginfo( 'version' ),
			);
			$transient->response[ $this->basename ] = (object) $item;
			unset( $transient->no_update[ $this->basename ] );
		} else {
			// طبق توصیه وردپرس ۵.۵+، برای جلوگیری از چک‌های تکراری/گیج‌کننده
			$transient->no_update[ $this->basename ] = (object) array(
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => RC_VERSION,
			);
		}

		return $transient;
	}

	/**
	 * پنجره «مشاهده جزئیات» در صفحه افزونه‌ها/آپدیت‌ها
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Radman Calendar (تقویم آموزشی رادمان)',
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => defined( 'RC_DEV_NAME' ) ? RC_DEV_NAME : '',
			'homepage'      => $release['html_url'],
			'sections'      => array(
				'description' => 'سیستم جامع تقویم آموزشی - اتصال به API لن‌میس.',
				'changelog'   => wpautop( wp_kses_post( $release['changelog'] ) ),
			),
			'download_link' => $release['zip_url'],
		);
	}

	/**
	 * دانلود دستی zip از GitHub وقتی توکن (ریپوی خصوصی) لازم است.
	 * برای ریپوی عمومی این تابع کاری نمی‌کند و به روال پیش‌فرض وردپرس اجازه می‌دهد ادامه دهد.
	 *
	 * ----------------------- اصلاح امنیتی ۱.۷.۳ -----------------------
	 * upgrader_pre_download برای *هر* دانلودی که وردپرس انجام می‌دهد اجرا
	 * می‌شود - آپدیت هر افزونه، هر قالب و هسته. شرط قبلی فقط این بود که رشته
	 * "api.github.com" جایی داخل آدرس پیدا شود. دو مشکل جدی داشت:
	 *
	 *  ۱) نشت توکن: آدرسی مثل https://evil.example/?x=api.github.com هم از
	 *     شرط رد می‌شد و ما توکن شخصی گیت‌هاب (Bearer) را در هدر Authorization
	 *     مستقیم برای همان دامنه غریبه می‌فرستادیم.
	 *  ۲) ربودن آپدیت افزونه‌های دیگر: هر افزونه/قالب دیگری که آپدیت‌چکر
	 *     مبتنی بر گیت‌هاب دارد (که کم هم نیستند)، دانلودش توسط این فیلتر
	 *     دزدیده و با توکن *ما* انجام می‌شد.
	 *
	 * حالا سه شرط سخت‌گیرانه: (الف) این دانلود باید مربوط به همین افزونه
	 * باشد، (ب) میزبان آدرس باید دقیقاً api.github.com باشد (نه شامل آن)،
	 * (ج) اتصال باید https باشد تا توکن روی سیم لو نرود.
	 */
	public function maybe_authenticated_download( $reply, $package, $upgrader, $hook_extra = array() ) {
		if ( empty( RC_GITHUB_TOKEN ) ) {
			return $reply;
		}

		$hook_extra = (array) $hook_extra;
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $reply; // این دانلود مربوط به افزونه ما نیست - دست نزن
		}

		if ( ! is_string( $package ) || '' === $package ) {
			return $reply;
		}

		$parts  = wp_parse_url( $package );
		$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';

		if ( 'api.github.com' !== $host || 'https' !== $scheme ) {
			return $reply; // توکن فقط و فقط به خود api.github.com و فقط روی https
		}

		$tmp_file = wp_tempnam( $package );
		if ( ! $tmp_file ) {
			return new WP_Error( 'rc_updater_tmp_failed', 'امکان ساخت فایل موقت برای دانلود آپدیت نبود.' );
		}

		$response = wp_remote_get(
			$package,
			array(
				'timeout'  => 60,
				'stream'   => true,
				'filename' => $tmp_file,
				'headers'  => array(
					'Accept'        => 'application/octet-stream',
					'Authorization' => 'Bearer ' . RC_GITHUB_TOKEN,
					'User-Agent'    => 'RadmanCalendarUpdater',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			@unlink( $tmp_file );
			return new WP_Error( 'rc_updater_download_failed', 'دانلود آپدیت از گیت‌هاب ناموفق بود.' );
		}

		return $tmp_file;
	}

	/**
	 * اصلاح نام پوشه استخراج‌شده - وقتی از zipball خودکار گیت‌هاب استفاده می‌شود
	 * (نام پوشه‌ای مثل owner-repo-commitsha است، نه radman-calendar) وردپرس باید
	 * بداند این پوشه معادل افزونه فعلی است، وگرنه به‌جای آپدیت، یک کپی جدید نصب می‌کند.
	 */
	public function fix_source_folder_name( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->slug . '/';

		if ( trailingslashit( $source ) === $desired ) {
			return $source; // نام از قبل درست است (asset اختصاصی zip)
		}

		if ( $wp_filesystem->move( $source, $desired, true ) ) {
			return $desired;
		}

		return $source;
	}

	/**
	 * بعد از اتمام آپدیت، کش نسخه را پاک کن تا وضعیت جدید بلافاصله منعکس شود
	 */
	public function purge_cache( $upgrader, $hook_extra ) {
		$plugins = array();
		if ( isset( $hook_extra['plugin'] ) ) {
			$plugins[] = $hook_extra['plugin'];
		}
		if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			$plugins = array_merge( $plugins, $hook_extra['plugins'] ); // آپدیت گروهی
		}
		if ( in_array( $this->basename, $plugins, true ) ) {
			delete_transient( self::CACHE_KEY );
		}
	}
}
