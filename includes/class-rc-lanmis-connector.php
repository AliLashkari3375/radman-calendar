<?php
/**
 * Copyright (c) 2026 Ali Lashkari. All Rights Reserved. See LICENSE.
 *
 * کلاس اتصال به API لن‌میس
 * مسئولیت‌ها:
 *  - ارسال درخواست به Endpointهای لن‌میس با هدر احراز هویت
 *  - کش کردن پاسخ‌ها (Transient) برای جلوگیری از فشار روی سرور
 *  - مدیریت خطا: اگر API در دسترس نبود، آخرین داده کش‌شده (حتی منقضی) برگردانده شود
 *  - ثبت لاگ آخرین وضعیت اتصال برای بخش پیشخوان ادمین
 *
 * ------------------------------ نسخه ۱.۷.۲ ------------------------------
 * بازنویسی لایه کارایی. مشکل قبلی: رندر هر صفحه سایت می‌توانست چندین
 * درخواست HTTP همگام (Blocking) به لن‌میس بزند - هر کدام با timeout ۴۰
 * ثانیه و تا ۲ بار sleep() برای کد ۴۲۹ - و بازدیدکننده باید تا رسیدن
 * جواب همه‌شان منتظر می‌ماند. با ۱۲ ترم در «بازه خودکار» این یعنی صفحه
 * عملاً می‌توانست دقیقه‌ها طول بکشد.
 *
 * چهار سدّ محافظ اضافه شد:
 *  ۱) memo درون-درخواست: یک ترکیب endpoint+params در یک بارگذاری صفحه
 *     فقط یک‌بار خوانده می‌شود (قبلاً برای هر شرت‌کد/هر بار صدا زدن تکرار می‌شد).
 *  ۲) بودجه شبکه (Budget): در یک درخواست فرانت، حداکثر چند تماس زنده و
 *     چند ثانیه اجازه داریم. بعد از آن اگر داده قدیمی داریم، همان را
 *     برمی‌گردانیم و تازه‌سازی را به پس‌زمینه می‌سپاریم.
 *  ۳) فیوز (Circuit Breaker): اگر لن‌میس یک‌بار خطا/۴۲۹/Timeout داد، برای
 *     یک دقیقه هیچ بازدیدکننده‌ای دوباره سراغ شبکه نمی‌رود؛ همه از کش
 *     سرو می‌شوند. این همان چیزی است که «کندی آبشاری» را می‌کشد.
 *  ۴) Stale-While-Revalidate + پیش‌گرم‌کردن با Cron: بازدیدکننده هیچ‌وقت
 *     هزینه انقضای کش را نمی‌پردازد؛ داده قدیمی فوری نمایش داده می‌شود و
 *     تازه‌سازی در پس‌زمینه انجام می‌گیرد.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RC_Lanmis_Connector {

	/** @var RC_Lanmis_Connector|null */
	private static $instance = null;

	/** مدت اعتبار کش پیش‌فرض (ثانیه) - قابل تغییر از تنظیمات ادمین */
	const DEFAULT_CACHE_TTL = 900; // 15 دقیقه

	/** پیشوند کلید‌های Transient */
	const CACHE_PREFIX = 'rc_lanmis_';

	/** شماره نسل کش لن‌میس (برای ابطال O(1) بدون نیاز به wp_cache_flush) */
	const CACHE_GEN_OPTION = 'rc_lanmis_cache_gen';

	/** پیشوند کلید‌های کش دائمی (fallback هنگام قطعی API) */
	const FALLBACK_PREFIX = 'rc_lanmis_fallback_';

	/** نام آپشن برای ذخیره آخرین وضعیت اتصال (لاگ) */
	const LOG_OPTION = 'rc_lanmis_last_log';

	/** فیوز: تا وقتی این transient وجود دارد، فرانت سراغ شبکه نمی‌رود */
	const BREAKER_KEY = 'rc_lanmis_breaker';

	/** مدت باز بودن فیوز بعد از یک خطا (ثانیه) */
	const BREAKER_TTL = 60;

	/** فهرست ترکیب‌های پارامتری که واقعاً در سایت استفاده می‌شوند (برای پیش‌گرم‌کردن با Cron) */
	const WARM_OPTION = 'rc_lanmis_warm_index';

	/** حداکثر تعداد ترکیب ذخیره‌شده در فهرست پیش‌گرم‌کردن */
	const WARM_MAX = 24;

	/** هوک تازه‌سازی پس‌زمینه (تک‌بار) */
	const REFRESH_HOOK = 'rc_lanmis_bg_refresh';

	/**
	 * مدت اضافه‌ای که داده منقضی‌شده داخل خود Transient می‌ماند و فوری سرو می‌شود
	 * (Stale-While-Revalidate واقعی). بعد از این مدت، اگر Cron هم کار نکرده باشد،
	 * مسیر قدیمی (تماس زنده + fallback) فعال می‌شود؛ پس داده هیچ‌وقت بی‌نهایت کهنه نمی‌ماند.
	 */
	const STALE_GRACE = 6 * HOUR_IN_SECONDS;

	/**
	 * سقف تماس زنده با لن‌میس در یک بارگذاری صفحه فرانت، وقتی داده قدیمی
	 * برای نمایش داریم (حالت عادی روزمره - سخت‌گیرانه، چون چیزی از دست نمی‌رود).
	 */
	const FRONT_MAX_CALLS = 12;
	const FRONT_MAX_SECONDS = 6;

	/**
	 * سقف مطلق وقتی هیچ داده قدیمی نداریم (اولین بارگذاری بعد از نصب یا بعد از
	 * پاک کردن کش).
	 */
	const FRONT_COLD_MAX_CALLS = 16;
	const FRONT_COLD_MAX_SECONDS = 12;

	/**
	 * سقف بودجه شبکه در صفحه‌های پیشخوان ادمین.
	 */
	const ADMIN_MAX_CALLS   = 24;
	const ADMIN_MAX_SECONDS = 35;

	/** timeout هر درخواست: فرانت متعادل، پیشخوان سخاوتمندتر، Cron/CLI بلند */
	const FRONT_TIMEOUT = 12;
	const ADMIN_TIMEOUT = 20;
	const BACKGROUND_TIMEOUT = 40;

	/** قفل کوتاه‌مدت زمان‌بندی تازه‌سازی پس‌زمینه (جلوگیری از نوشتن مکرر آپشن cron) */
	const REFRESH_LOCK_PREFIX = 'rc_lanmis_bgq_';
	const REFRESH_LOCK_TTL    = 300;

	/** کش درون-درخواست پاسخ‌ها: signature => result */
	private $memo = array();

	/** شمارنده بودجه شبکه در همین درخواست PHP */
	private $live_calls = 0;

	/** زمان مصرف‌شده شبکه در همین درخواست PHP (ثانیه) */
	private $live_seconds = 0.0;

	/** کش درون-درخواست توکن و TTL تا get_option چند بار صدا زده نشود */
	private $token_memo = null;
	private $ttl_memo   = null;

	/** کش درون-درخواست داده fallback: signature => data|null (null = fallback نداریم) */
	private $stale_memo = array();

	/** کش درون-درخواست شماره نسل کش */
	private static $cache_gen_memo = null;

	public static function get_cache_gen() {
		if ( null === self::$cache_gen_memo ) {
			self::$cache_gen_memo = (int) get_option( self::CACHE_GEN_OPTION, 0 );
		}
		return self::$cache_gen_memo;
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * خواندن توکن API از constant یا تنظیمات ذخیره‌شده در دیتابیس
	 */
	private function get_token() {
		if ( null !== $this->token_memo ) {
			return $this->token_memo;
		}
		if ( defined( 'RC_LANMIS_TOKEN' ) && RC_LANMIS_TOKEN ) {
			$this->token_memo = RC_LANMIS_TOKEN;
			return $this->token_memo;
		}
		$settings         = get_option( 'rc_settings', array() );
		$this->token_memo = isset( $settings['lanmis_token'] ) ? (string) $settings['lanmis_token'] : '';
		return $this->token_memo;
	}

	private function get_cache_ttl() {
		if ( null !== $this->ttl_memo ) {
			return $this->ttl_memo;
		}
		$settings       = get_option( 'rc_settings', array() );
		$ttl            = isset( $settings['cache_ttl'] ) ? (int) $settings['cache_ttl'] : self::DEFAULT_CACHE_TTL;
		$this->ttl_memo = $ttl > 0 ? $ttl : self::DEFAULT_CACHE_TTL;
		return $this->ttl_memo;
	}

	// -----------------------------------------------------------------
	// تشخیص بستر اجرا و بودجه شبکه
	// -----------------------------------------------------------------

	/**
	 * آیا این یک بستر «واقعاً بی‌بودجه» است؟ فقط Cron و WP-CLI.
	 *
	 * اینجا هیچ انسانی پشت مرورگر منتظر نیست، پس timeout بلند، retry با
	 * sleep و نادیده گرفتن فیوز مجاز (و لازم) است. مهم: پیشخوان ادمین دیگر
	 * جزو این دسته نیست — دلیل اصلی کندی داشبورد همین بود.
	 */
	private function is_unbounded_context() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
			return true;
		}
		return false;
	}

	/**
	 * آیا این درخواست، رندر یک صفحه/عملیات واقعی پیشخوان ادمین است؟
	 *
	 * admin-ajax.php هم is_admin()=true می‌دهد، ولی بخش زیادی از درخواست‌های
	 * آن در واقع «رندر برای بازدیدکننده» هستند (مثل rc_merge_group که nopriv
	 * است). فقط اکشن‌هایی که واقعاً کار پنل ادمین‌اند بودجه ادمین می‌گیرند؛
	 * بقیه با بودجه سبک فرانت اجرا می‌شوند تا پاسخ سریع بماند.
	 */
	private function is_admin_ui_context() {
		if ( ! is_admin() ) {
			return false;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			$admin_actions = array( 'rc_preview_shortcode', 'rc_save_manual_data', 'rc_delete_manual_data' );
			$action        = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			return in_array( $action, $admin_actions, true ) && current_user_can( 'manage_options' );
		}

		return true;
	}

	/**
	 * آیا اجازه داریم یک تماس زنده دیگر با لن‌میس بزنیم؟
	 *
	 * @param bool $has_stale آیا داده قدیمی قابل نمایش داریم؟
	 */
	private function can_spend_budget( $has_stale = true ) {
		if ( $this->is_unbounded_context() ) {
			return true;
		}

		if ( $this->is_admin_ui_context() ) {
			$max_calls   = self::ADMIN_MAX_CALLS;
			$max_seconds = self::ADMIN_MAX_SECONDS;
		} else {
			$max_calls   = $has_stale ? self::FRONT_MAX_CALLS : self::FRONT_COLD_MAX_CALLS;
			$max_seconds = $has_stale ? self::FRONT_MAX_SECONDS : self::FRONT_COLD_MAX_SECONDS;
		}

		if ( $this->live_calls >= $max_calls ) {
			return false;
		}
		if ( $this->live_seconds >= $max_seconds ) {
			return false;
		}
		return true;
	}

	private function request_timeout() {
		if ( $this->is_unbounded_context() ) {
			return self::BACKGROUND_TIMEOUT;
		}
		return $this->is_admin_ui_context() ? self::ADMIN_TIMEOUT : self::FRONT_TIMEOUT;
	}

	/**
	 * فیوز باز است؟ (یعنی همین چند ثانیه پیش لن‌میس خطا داده و نباید دوباره
	 * صف انتظار کاربرها را روی یک سرور خراب هدر داد)
	 *
	 * پیشخوان هم از این به بعد به فیوز احترام می‌گذارد: وقتی لن‌میس قطع است،
	 * ادمین باید فوری داده کش‌شده را ببیند، نه اینکه هر صفحه چند timeout
	 * کامل بسوزاند. دکمه «به‌روزرسانی اجباری» (force) همچنان فیوز را دور
	 * می‌زند، چون همان مسیر صریحاً برای «الان واقعاً تلاش کن» ساخته شده.
	 */
	private function breaker_is_open() {
		if ( $this->is_unbounded_context() ) {
			return false; // Cron/CLI باید بتواند واقعاً تلاش کند
		}
		return (bool) get_transient( self::BREAKER_KEY );
	}

	private function trip_breaker() {
		set_transient( self::BREAKER_KEY, 1, self::BREAKER_TTL );
	}

	private function reset_breaker() {
		delete_transient( self::BREAKER_KEY );
	}

	// -----------------------------------------------------------------
	// درخواست مرکزی
	// -----------------------------------------------------------------

	/**
	 * تابع مرکزی درخواست به لن‌میس
	 *
	 * @param string $endpoint  مسیر endpoint، مثال: 'oapi/v1/edu/class_list'
	 * @param array  $params    پارامترهای Query String
	 * @param bool   $force     اگر true باشد، از کش صرف‌نظر و درخواست تازه ارسال می‌شود
	 *
	 * @return array{success:bool, data:mixed, from_cache:bool, error:string|null}
	 */
	public function request( $endpoint, $params = array(), $force = false ) {
		$params = (array) $params;
		ksort( $params );
		$signature    = md5( $endpoint . wp_json_encode( $params ) );
		$cache_key    = self::CACHE_PREFIX . self::get_cache_gen() . '_' . $signature;
		$fallback_key = self::FALLBACK_PREFIX . $signature;

		// (۱) کش درون-درخواست: چند شرت‌کد در یک صفحه معمولاً دقیقاً همان
		// ترکیب پارامتری را می‌خواهند؛ قبلاً هر بار get_transient تکرار می‌شد.
		if ( ! $force && isset( $this->memo[ $signature ] ) ) {
			return $this->memo[ $signature ];
		}

		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				$is_fresh = true;
				$data     = $this->unwrap_cache_entry( $cached, $is_fresh );

				// کش تازه است، یا کش کهنه‌ای داریم ولی در Cron/CLI نیستیم:
				// بازدیدکننده/ادمین نباید منتظر لن‌میس بماند. داده قبلی همین الان
				// نشان داده می‌شود و تازه‌سازی واقعی در پس‌زمینه انجام می‌گیرد.
				// (این مسیر حتی get_option حجیم fallback را هم نمی‌خواند.)
				if ( $is_fresh || ! $this->is_unbounded_context() ) {
					if ( ! $is_fresh ) {
						$this->stale_memo[ $signature ] = $data;
						$this->queue_background_refresh( $endpoint, $params );
					}
					return $this->memoize(
						$signature,
						array(
							'success'    => true,
							'data'       => $data,
							'from_cache' => true,
							'error'      => null,
						)
					);
				}
				// Cron/CLI با داده کهنه: ادامه می‌دهیم تا واقعاً تازه شود.
			}
		}

		// داده قدیمی (اگر هست) - سلاح اصلی ما برای اینکه کاربر هرگز منتظر نماند.
		// fallback یک آپشن غیر-autoload است، پس هر خواندنش یک کوئری جداست؛
		// در یک صفحه با چند شرت‌کد این خواندن ده‌ها بار تکرار می‌شد.
		$stale     = $this->read_stale( $signature, $fallback_key );
		$has_stale = ( null !== $stale );

		// (۳) فیوز باز است → بدون لمس شبکه.
		// نکته‌ای که در تست شبیه‌سازی مشخص شد: اگر فیوز را فقط وقتی احترام
		// بگذاریم که داده قدیمی داریم، در حالت «API کاملاً قطع + کش خالی»
		// هر منبع دوباره یک timeout کامل می‌سوزاند تا بودجه به آن برسد.
		// پس فیوز در هر دو حالت جلوی شبکه را می‌گیرد؛ فقط پاسخ متفاوت است.
		if ( ! $force && $this->breaker_is_open() ) {
			$this->queue_background_refresh( $endpoint, $params );

			if ( $has_stale ) {
				return $this->memoize( $signature, $this->stale_result( $stale, 'سرور لن‌میس چند لحظه پیش پاسخ نداد؛ داده از کش نمایش داده می‌شود.' ) );
			}

			return $this->memoize(
				$signature,
				array(
					'success'    => false,
					'data'       => array(),
					'from_cache' => false,
					'error'      => 'سرور لن‌میس در حال حاضر پاسخ نمی‌دهد. تلاش بعدی به‌صورت خودکار انجام می‌شود.',
				)
			);
		}

		// (۲) بودجه شبکه این صفحه تمام شده → داده قدیمی + تازه‌سازی پس‌زمینه
		if ( ! $force && ! $this->can_spend_budget( $has_stale ) ) {
			$this->queue_background_refresh( $endpoint, $params );

			if ( $has_stale ) {
				return $this->memoize( $signature, $this->stale_result( $stale, 'برای جلوگیری از کند شدن صفحه، این بخش از کش نمایش داده شد و در پس‌زمینه تازه می‌شود.' ) );
			}

			// هیچ داده قدیمی هم نداریم: این ترکیب را همین بار رد می‌کنیم تا
			// صفحه سریع تمام شود. تازه‌سازی پس‌زمینه زمان‌بندی شد، پس بارگذاری
			// بعدی کامل خواهد بود. (بقیه بخش‌های صفحه که داده گرفتند رندر می‌شوند.)
			return $this->memoize(
				$signature,
				array(
					'success'    => false,
					'data'       => array(),
					'from_cache' => false,
					'error'      => 'این بخش برای جلوگیری از کند شدن صفحه در پس‌زمینه دریافت می‌شود؛ لطفاً چند لحظه بعد صفحه را دوباره باز کنید.',
				)
			);
		}

		$token = $this->get_token();
		if ( empty( $token ) ) {
			return $this->memoize( $signature, $this->handle_failure( $fallback_key, 'توکن API لن‌میس تنظیم نشده است.', false ) );
		}

		$url = trailingslashit( RC_LANMIS_BASE_URL ) . ltrim( $endpoint, '/' );
		if ( ! empty( $params ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $params ), $url );
		}

		// طبق مستندات لن‌میس، class_list صفحه‌بندی ندارد و کل نتیجه در یک پاسخ
		// برمی‌گردد؛ پس برای شعبه/ترمی با کلاس زیاد پاسخ حجیم و کند است.
		//
		// نکته مهم: retry با sleep() فقط در Cron/WP-CLI انجام می‌شود.
		// قبلاً همین sleep روی رندر صفحه فرانت هم اجرا می‌شد و به‌تنهایی
		// می‌توانست ۳ ثانیه به هر درخواست اضافه کند - ضربدر تعداد ترم‌ها.
		// و در نسخه ۱.۷.۲ هنوز روی صفحه‌های پیشخوان اجرا می‌شد (چون ادمین
		// «پس‌زمینه» حساب می‌شد) و داشبورد را قفل می‌کرد. نه بازدیدکننده و نه
		// ادمین نباید هزینه محدودیت نرخ لن‌میس را بپردازند؛ برای آن‌ها یک
		// تلاش کافی است و در صورت شکست، کش سرو می‌شود.
		//
		// استثنا: «به‌روزرسانی اجباری» ($force) در Cron/CLI. در پیشخوان،
		// force فقط کش را دور می‌زند و همچنان یک تلاش می‌کند تا صفحه
		// تنطیمات هیچ‌وقت پشت چند sleep گیر نکند.
		$max_attempts = $this->is_unbounded_context() ? 3 : 1;
		$attempt      = 0;
		$response     = null;
		$code         = 0;
		$timeout      = $this->request_timeout();

		do {
			++$attempt;
			++$this->live_calls;
			$started = microtime( true );

			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => $timeout,
					'redirection' => 2,
					'sslverify'   => true,
					'headers'     => array(
						'lanmis-token' => $token,
						'content-type' => 'application/json',
					),
				)
			);

			$this->live_seconds += ( microtime( true ) - $started );

			if ( is_wp_error( $response ) ) {
				break; // خطای شبکه (نه ۴۲۹) - نیازی به retry نیست
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 429 !== $code ) {
				break; // موفق یا هر خطای دیگری - همین یک تلاش کافی است
			}

			if ( $attempt < $max_attempts ) {
				sleep( $attempt ); // فقط در بستر پس‌زمینه به اینجا می‌رسیم
			}
		} while ( $attempt < $max_attempts );

		if ( is_wp_error( $response ) ) {
			return $this->memoize( $signature, $this->handle_failure( $fallback_key, $response->get_error_message(), true ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) ) {
			$msg = 429 === $code
				? 'سرور لن‌میس به دلیل ارسال درخواست‌های زیاد در بازه کوتاه، این درخواست را رد کرد (HTTP 429). لطفاً کمی صبر کنید.'
				: sprintf( 'HTTP %s از سرور لن‌میس دریافت شد.', $code );
			// خطای ۴۲۹ و ۵xx یعنی «سرور الان حالش خوب نیست» → فیوز را بزن
			$trip = ( 429 === $code || $code >= 500 || 0 === $code );
			return $this->memoize( $signature, $this->handle_failure( $fallback_key, $msg, $trip ) );
		}

		if ( ! isset( $body['status_code'] ) || 0 !== (int) $body['status_code'] ) {
			$msg = isset( $body['status_text'] ) ? $body['status_text'] : 'خطای نامشخص از API لن‌میس';
			return $this->memoize( $signature, $this->handle_failure( $fallback_key, $msg, false ) );
		}

		$result = isset( $body['result'] ) ? $body['result'] : array();
		$result = is_array( $result ) ? $result : array();
		$result = $this->decode_keys( $result );

		// ذخیره در کش موقت (TTL) و کش دائمی fallback
		set_transient( $cache_key, $this->wrap_cache_entry( $result ), $this->get_cache_ttl() + self::STALE_GRACE );
		update_option( $fallback_key, $result, false );
		$this->stale_memo[ $signature ] = $result;

		// این ترکیب واقعاً در سایت استفاده می‌شود → Cron از این به بعد
		// خودش پیش از انقضای کش تازه‌اش می‌کند تا بازدیدکننده منتظر نماند.
		$this->remember_warm_target( $signature, $endpoint, $params );

		$this->reset_breaker();

		// تعداد رکورد دریافتی را در لاگ ثبت می‌کنیم تا ادمین بتواند بعد از
		// «به‌روزرسانی اجباری» ببیند چند رکورد واقعاً از لن‌میس آمده.
		$this->write_log( true, sprintf( 'اتصال موفق (%d رکورد دریافت شد)', count( $result ) ) );

		return $this->memoize(
			$signature,
			array(
				'success'    => true,
				'data'       => $result,
				'from_cache' => false,
				'error'      => null,
			)
		);
	}

	/**
	 * بسته‌بندی نتیجه برای ذخیره در Transient، همراه زمان «تازه بودن».
	 * خود Transient تا TTL + STALE_GRACE زنده می‌ماند، ولی تا پایان TTL تازه حساب می‌شود.
	 */
	private function wrap_cache_entry( $result ) {
		return array(
			'__rc_swr'    => 1,
			'fresh_until' => time() + $this->get_cache_ttl(),
			'data'        => $result,
		);
	}

	/**
	 * باز کردن مقدار Transient. مقدارهای قدیمی (بدون بسته‌بندی) تازه فرض می‌شوند.
	 *
	 * @param mixed $cached
	 * @param bool  $is_fresh خروجی: آیا هنوز در بازه TTL است؟
	 * @return mixed
	 */
	private function unwrap_cache_entry( $cached, &$is_fresh ) {
		if ( is_array( $cached ) && isset( $cached['__rc_swr'] ) && array_key_exists( 'data', $cached ) ) {
			$is_fresh = time() < (int) ( isset( $cached['fresh_until'] ) ? $cached['fresh_until'] : 0 );
			return $cached['data'];
		}
		$is_fresh = true;
		return $cached;
	}

	/**
	 * ثبت نتیجه در کش درون-درخواست و برگرداندن همان
	 */
	private function memoize( $signature, $result ) {
		$this->memo[ $signature ] = $result;
		return $result;
	}

	/**
	 * ساخت پاسخ «داده قدیمی ولی قابل استفاده»
	 */
	private function stale_result( $stale, $notice ) {
		return array(
			'success'    => true,
			'data'       => $stale,
			'from_cache' => true,
			'error'      => $notice,
		);
	}

	/**
	 * زمان‌بندی یک تازه‌سازی پس‌زمینه برای همین ترکیب پارامتری.
	 * wp_schedule_single_event خودش از ثبت تکراری با آرگومان یکسان جلوگیری
	 * می‌کند، ولی برای فهمیدن همین موضوع هم باید کل آپشن cron را بخواند و
	 * unserialize کند - پس یک قفل transient جلوترش می‌گذاریم.
	 */
	private function queue_background_refresh( $endpoint, $params ) {
		if ( $this->is_unbounded_context() ) {
			return; // خودِ پس‌زمینه لازم نیست برای خودش کار زمان‌بندی کند
		}
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}

		// قفل کوتاه‌مدت. بدون این، هر بازدیدی که بودجه‌اش تمام شده بود یک
		// wp_schedule_single_event صدا می‌زد و هر بار آپشن حجیم «cron» از
		// دیتابیس خوانده، unserialize، دستکاری و دوباره نوشته می‌شد. زیر بار،
		// همین به‌تنهایی یک گلوگاه نوشتن روی wp_options است.
		$sorted_params = (array) $params;
		ksort( $sorted_params );
		$lock = self::REFRESH_LOCK_PREFIX . md5( (string) $endpoint . wp_json_encode( $sorted_params ) );
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, self::REFRESH_LOCK_TTL );

		wp_schedule_single_event( time() + 30, self::REFRESH_HOOK, array( (string) $endpoint, (array) $params ) );
	}

	/**
	 * خواندن آخرین داده معتبر ذخیره‌شده (fallback) با کش درون-درخواست.
	 *
	 * @return mixed|null
	 */
	private function read_stale( $signature, $fallback_key ) {
		if ( array_key_exists( $signature, $this->stale_memo ) ) {
			return $this->stale_memo[ $signature ];
		}
		$value                          = get_option( $fallback_key, null );
		$this->stale_memo[ $signature ] = $value;
		return $value;
	}

	/**
	 * اجرای تازه‌سازی پس‌زمینه (به هوک rc_lanmis_bg_refresh وصل است)
	 */
	public static function handle_background_refresh( $endpoint = '', $params = array() ) {
		if ( '' === $endpoint ) {
			return;
		}
		self::instance()->request( $endpoint, (array) $params, true );
	}

	// -----------------------------------------------------------------
	// فهرست پیش‌گرم‌کردن (Warm index)
	// -----------------------------------------------------------------

	/**
	 * به‌خاطر سپردن یک ترکیب endpoint+params که واقعاً در سایت استفاده شده.
	 * برای اینکه هر درخواست موفق باعث یک نوشتن در دیتابیس نشود، فقط وقتی
	 * ورودی تازه است یا بیش از یک ساعت از آخرین ثبتش گذشته، آپشن به‌روز می‌شود.
	 */
	private function remember_warm_target( $signature, $endpoint, $params ) {
		// endpointهای سبک را Cron همیشه تازه می‌کند؛ فقط class_list ارزش ثبت دارد
		if ( false === strpos( $endpoint, 'class_list' ) ) {
			return;
		}

		$index = get_option( self::WARM_OPTION, array() );
		$index = is_array( $index ) ? $index : array();
		$now   = time();

		if ( isset( $index[ $signature ]['seen'] ) && ( $now - (int) $index[ $signature ]['seen'] ) < HOUR_IN_SECONDS ) {
			return;
		}

		$index[ $signature ] = array(
			'endpoint' => (string) $endpoint,
			'params'   => (array) $params,
			'seen'     => $now,
		);

		// حذف ترکیب‌هایی که یک هفته است استفاده نشده‌اند
		foreach ( $index as $sig => $row ) {
			if ( ! isset( $row['seen'] ) || ( $now - (int) $row['seen'] ) > WEEK_IN_SECONDS ) {
				unset( $index[ $sig ] );
			}
		}

		// سقف: پرمصرف‌ترین/تازه‌ترین‌ها بمانند
		if ( count( $index ) > self::WARM_MAX ) {
			uasort(
				$index,
				function ( $a, $b ) {
					return ( isset( $b['seen'] ) ? $b['seen'] : 0 ) <=> ( isset( $a['seen'] ) ? $a['seen'] : 0 );
				}
			);
			$index = array_slice( $index, 0, self::WARM_MAX, true );
		}

		update_option( self::WARM_OPTION, $index, false );
	}

	/**
	 * ترکیب‌هایی که Cron باید پیش از انقضای کش تازه‌شان کند
	 *
	 * @param int $limit سقف تعداد در هر اجرای Cron (تا اجرای Cron طولانی نشود)
	 * @return array
	 */
	public function get_warm_targets( $limit = 8 ) {
		$index = get_option( self::WARM_OPTION, array() );
		$index = is_array( $index ) ? $index : array();

		uasort(
			$index,
			function ( $a, $b ) {
				return ( isset( $b['seen'] ) ? $b['seen'] : 0 ) <=> ( isset( $a['seen'] ) ? $a['seen'] : 0 );
			}
		);

		return array_slice( array_values( $index ), 0, max( 1, (int) $limit ) );
	}

	/**
	 * لن‌میس مقادیر key را از قبل به‌صورت URL-encoded برمی‌گرداند (مثلاً "EQ4%3D").
	 * اگر این مقدار مستقیم در فرم HTML یا Query String استفاده شود، هنگام
	 * ارسال دوباره encode می‌شود و به سرور بی‌اعتبار می‌رسد. برای جلوگیری
	 * از این مشکل، بلافاصله بعد از دریافت پاسخ، همه فیلدهای *_key (و key)
	 * را یک‌بار decode می‌کنیم تا در کل پلاگین با مقدار خام کار شود.
	 */
	private function decode_keys( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) ) {
				$data[ $k ] = $this->decode_keys( $v );
			} elseif ( is_string( $v ) && ( 'key' === $k || ( strlen( $k ) > 4 && '_key' === substr( $k, -4 ) ) ) ) {
				$data[ $k ] = urldecode( $v );
			}
		}
		return $data;
	}

	/**
	 * در صورت شکست درخواست: تلاش برای بازگرداندن آخرین داده معتبر ذخیره‌شده
	 *
	 * @param bool $trip_breaker آیا این خطا از نوع «سرور مشکل دارد» است و باید فیوز را بزند؟
	 */
	private function handle_failure( $fallback_key, $error_message, $trip_breaker = false ) {
		$this->write_log( false, $error_message );

		if ( $trip_breaker ) {
			$this->trip_breaker();
		}

		$sig      = substr( $fallback_key, strlen( self::FALLBACK_PREFIX ) );
		$fallback = $this->read_stale( $sig, $fallback_key );
		if ( null !== $fallback ) {
			return array(
				'success'    => true, // از دید مصرف‌کننده، داده معتبر (قدیمی) داریم
				'data'       => $fallback,
				'from_cache' => true,
				'error'      => $error_message,
			);
		}

		return array(
			'success'    => false,
			'data'       => array(),
			'from_cache' => false,
			'error'      => $error_message,
		);
	}

	/**
	 * ثبت آخرین وضعیت اتصال برای نمایش در پیشخوان ادمین (بخش Log)
	 */
	private function write_log( $success, $message ) {
		update_option(
			self::LOG_OPTION,
			array(
				'success' => (bool) $success,
				'message' => $message,
				'time'    => current_time( 'mysql' ),
			),
			false
		);
	}

	public static function get_last_log() {
		return get_option( self::LOG_OPTION, null );
	}

	/**
	 * پاک‌سازی کامل تمام کش‌های مربوط به لن‌میس (Transient و fallback دائمی)
	 *
	 * مهم: نباید مستقیم روی جدول wp_options دیلیت بزنیم. اگر روی هاست یک
	 * Object Cache پایدار (Redis/Memcached) نصب باشد، get_transient/set_transient
	 * اصلاً به دیتابیس نمی‌روند و DELETE مستقیم هیچ اثری ندارد. پس فقط از
	 * delete_transient()/delete_option() استفاده می‌کنیم.
	 */
	public function purge_all_cache() {
		global $wpdb;

		$this->memo       = array();
		$this->stale_memo = array();

		// افزایش شماره نسل: ابطال آنی و O(1) تمام کش‌ها (حتی روی Redis/Memcached)
		// بدون نیاز به wp_cache_flush() که کل کش سایت/فروشگاه را پاک می‌کرد.
		$next_gen = self::get_cache_gen() + 1;
		update_option( self::CACHE_GEN_OPTION, $next_gen, true );
		self::$cache_gen_memo = $next_gen;

		// پاک‌سازی یک‌جای جدول wp_options در یک کوئری (به‌جای حلقه delete_option/delete_transient)
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '" . self::FALLBACK_PREFIX . "%'
			    OR option_name LIKE '_transient_" . self::CACHE_PREFIX . "%'
			    OR option_name LIKE '_transient_timeout_" . self::CACHE_PREFIX . "%'"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		// فیوز و کش HTML جدول‌ها هم باید آزاد شوند
		$this->reset_breaker();
		if ( class_exists( 'RC_Shortcodes' ) ) {
			RC_Shortcodes::purge_merge_cache();
		}
	}

	// ---------------------------------------------------------------
	// توابع کمکی برای هر Endpoint (Wrapperهای مشخص طبق مستندات لن‌میس)
	// ---------------------------------------------------------------

	/** لیست شعب: oapi/v1/ins/branches */
	public function get_branches( $force = false ) {
		return $this->request( 'oapi/v1/ins/branches', array(), $force );
	}

	/** لیست ترم‌ها: oapi/v1/ins/terms */
	public function get_terms( $force = false ) {
		return $this->request( 'oapi/v1/ins/terms', array(), $force );
	}

	/** دپارتمان‌ها/دوره‌ها/سطوح: oapi/v1/ins/dep_levels */
	public function get_dep_levels( $branch_key = '', $force = false ) {
		$params = array();
		if ( ! empty( $branch_key ) ) {
			$params['branchKey'] = $branch_key;
		}
		return $this->request( 'oapi/v1/ins/dep_levels', $params, $force );
	}

	/**
	 * لیست کلاس‌ها: oapi/v1/edu/class_list
	 * branchKey و termKey طبق مستندات اجباری هستند.
	 */
	public function get_class_list( $args = array(), $force = false ) {
		$defaults = array(
			'branchKey' => '',
			'termKey'   => '',
			'depKey'    => '',
			'courseKey' => '',
			'levelKey'  => '',
			'fromDate'  => '',
			'toDate'    => '',
		);
		$args = array_filter( array_merge( $defaults, $args ) ); // حذف پارامترهای خالی

		if ( empty( $args['branchKey'] ) || empty( $args['termKey'] ) ) {
			return array(
				'success'    => false,
				'data'       => array(),
				'from_cache' => false,
				'error'      => 'پارامترهای branchKey و termKey برای دریافت لیست کلاس‌ها الزامی هستند.',
			);
		}

		// ترتیب کلیدها را ثابت می‌کنیم تا دو فراخوانی با همان پارامترها
		// (ولی ترتیب نوشتن متفاوت) به یک کلید کش برسند.
		ksort( $args );

		return $this->request( 'oapi/v1/edu/class_list', $args, $force );
	}
}
