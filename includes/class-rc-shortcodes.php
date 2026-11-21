<?php
/**
 * Copyright (c) 2026 Ali Lashkari. All Rights Reserved. See LICENSE.
 *
 * شرت‌کدهای پلاگین:
 *  [radman_calendar] - جدول اصلی تقویم آموزشی (بخش ۱ سند)
 *  [radman_course]   - صفحه سینگل دوره / چند سطح خاص (بخش ۲ سند)
 *  [radman_upcoming] - دوره‌های پیش‌رو (بخش ۳ سند)
 *
 * هر سه شرت‌کد از یک منبع داده (RC_Query) و یک تابع رندر مشترک
 * تغذیه می‌شوند تا طبق سند «تمامی ستون‌ها و پارامترها مثل هم باشند».
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RC_Shortcodes {

	private static $instance = null;
	/** آیا در این درخواست حداقل یک شرت‌کد رندر شده (برای enqueue شرطی assets) */
	private static $assets_needed = false;

	/** پیشوند کش HTML جدول ادغام‌شده */
	const MERGE_CACHE_PREFIX = 'rc_merge_html_';

	/** پیشوند کش HTML هر شرت‌کد تکی */
	const SC_CACHE_PREFIX = 'rc_sc_html_';

	/** سقف تعداد شرت‌کد در یک درخواست ادغام (سد محافظ endpoint عمومی) */
	const MERGE_MAX_SLOTS = 12;

	/** شماره نسل کش HTML ادغام‌شده (بالا بردنش = بی‌اعتبار شدن همه) */
	const MERGE_GEN_OPTION = 'rc_merge_cache_gen';

	/** پیشوند کلید محدودیت نرخ endpoint عمومی ادغام */
	const MERGE_RATE_PREFIX = 'rc_merge_rl_';

	/** کش درون-درخواست ترجمه دپارتمان‌ها */
	private static $translations_cache = null;

	/** کش درون-درخواست نسل کش رندر و خروجی رندر */
	private static $cache_gen_memo = null;
	private static $render_memo    = array();

	public static function get_cache_gen() {
		if ( null === self::$cache_gen_memo ) {
			self::$cache_gen_memo = (int) get_option( self::MERGE_GEN_OPTION, 0 );
		}
		return self::$cache_gen_memo;
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'radman_calendar', array( $this, 'render_calendar' ) );
		add_shortcode( 'radman_course', array( $this, 'render_course_container' ) );
		add_shortcode( 'radman_upcoming', array( $this, 'render_upcoming' ) );

		// ادغام تمام شرت‌کدهای هم‌نوع یک صفحه در یک جدول واحد، بعد از لود صفحه
		// با یک درخواست AJAX کوچک انجام می‌شود. هر شرت‌کد از همان ابتدا
		// جدول مستقل و درست خودش را سمت سرور رندر می‌کند؛ اگر ادغام سمت
		// کلاینت انجام نشود (جاوااسکریپت غیرفعال، خطای شبکه)، باز هم هرگز
		// چیزی خالی/نامرئی نمی‌ماند.
		add_action( 'wp_ajax_rc_merge_group', array( $this, 'ajax_merge_group' ) );
		add_action( 'wp_ajax_nopriv_rc_merge_group', array( $this, 'ajax_merge_group' ) );
	}

	// -----------------------------------------------------------------
	// تنطیمات / ابزارهای کمکی
	// -----------------------------------------------------------------

	/**
	 * چک لایسنس قبل از هرگونه درخواست به API لن‌میس
	 *
	 * @return string|null پیام قفل در صورت نامعتبر بودن، یا null اگر لایسنس معتبر است
	 */
	private function license_gate() {
		if ( class_exists( 'RC_License' ) && ! RC_License::instance()->is_valid() ) {
			$this->mark_assets_needed();
			return RC_License::instance()->locked_message();
		}
		return null;
	}

	/**
	 * خواندن اولویت پیش‌فرض چیدمان از تنطیمات ادمین (بخش ۴ سند)
	 */
	private function default_sort() {
		$settings = RC_Query::settings();
		return isset( $settings['default_sort'] ) ? $settings['default_sort'] : 'date_desc';
	}

	/**
	 * پیش‌فرض‌های مشترک همه شرت‌کدها (تا در مسیر AJAX هم دقیقاً همین مقادیر اعمال شود)
	 */
	private function shortcode_defaults( $tag ) {
		$common = array(
			'branch_key'  => '',
			'term_key'    => '',
			'auto_term'   => '',
			'date_mode'   => 'manual', // 'manual' | 'auto'
			'auto_days'   => 30,       // پنجره خودکار: از N روز قبل ... (۷ یا ۳۰)
			'months_ahead' => 3,       // ... تا N ماه شمسی آینده (۰ = بدون سقف)
			'merge'       => '1',      // ادغام با شرت‌کدهای هم‌نوع همین صفحه
			'merge_group' => '',       // گروه ادغام دلخواه (خالی = یک گروه به ازای هر نوع شرت‌کد)
		);

		if ( 'radman_upcoming' === $tag ) {
			return array_merge( $common, array( 'count' => 10 ) );
		}

		$base = array_merge(
			$common,
			array(
				'dep_key'   => '',
				'from_date' => '',
				'to_date'   => '',
				'limit'     => 0,
				'sort'      => $this->default_sort(),
			)
		);

		if ( 'radman_course' === $tag ) {
			return array_merge(
				$base,
				array(
					'course_key' => '',
					'level_keys' => '',
				)
			);
		}

		return $base;
	}

	private function is_on( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'on' ), true );
	}

	private function is_off( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( '0', 'no', 'false', 'off' ), true );
	}

	private function csv( $value ) {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ) ) );
	}

	// -----------------------------------------------------------------
	// منطق ترم / بازه تاریخ
	// -----------------------------------------------------------------

	/**
	 * تعداد روزهای پنجره خودکار: از auto_days یا از خود date_mode (مانند auto7 / auto30)
	 */
	private function auto_days( $atts ) {
		$days = isset( $atts['auto_days'] ) ? (int) RC_Jalali::to_latin_digits( $atts['auto_days'] ) : 0;

		if ( isset( $atts['date_mode'] ) && preg_match( '/(\d+)/', (string) $atts['date_mode'], $m ) ) {
			$days = (int) $m[1];
		}

		return $days > 0 ? $days : 30;
	}

	/**
	 * سقف آینده پنجره خودکار به ماه شمسی. خالی/نامعتبر = ۳؛ صفر = بدون سقف.
	 */
	private function months_ahead( $atts ) {
		if ( ! isset( $atts['months_ahead'] ) || '' === trim( (string) $atts['months_ahead'] ) ) {
			return 3;
		}
		$months = (int) RC_Jalali::to_latin_digits( $atts['months_ahead'] );
		if ( $months < 0 ) {
			return 3;
		}
		return min( $months, 12 );
	}

	private function is_auto_mode( $atts ) {
		$mode = isset( $atts['date_mode'] ) ? strtolower( trim( (string) $atts['date_mode'] ) ) : 'manual';
		return 0 === strpos( $mode, 'auto' );
	}

	/**
	 * تعیین بازه تاریخ:
	 * - «دستی» (پیش‌فرض): from_date/to_date شرت‌کد.
	 *   باگ قبلی: این دو مقدار «خام» مستقیم به‌عنوان fromDate/toDate به
	 *   لن‌میس فرستاده می‌شدند. سه مشکل داشت: (۱) API هر toDate آینده را رد
	 *   می‌کند، پس هر بازه‌ای که تا آینده ادامه داشت (یعنی تقریباً همیشه!)
	 *   با خطا/جدول خالی برمی‌گشت؛ (۲) تاریخ با «/» یا ارقام فارسی یا بدون
	 *   صفر ابتدایی ("1405/7/1") به API نامعتبر می‌رسید؛ (۳) فقط ترم
	 *   انتخاب‌شده خوانده می‌شد، پس بازه‌ای که از مرز ماه رد می‌شد نصفه بود.
	 *   حالا تاریخ‌ها نرمال و اعتبارسنجی می‌شوند، هیچ تاریخی به API نمی‌رود،
	 *   ترم‌های کل بازه خوانده می‌شوند و فیلتر (شامل هر دو سر) روی
	 *   start_date در PHP انجام می‌شود — دقیقاً مثل حالت خودکار.
	 * - «خودکار»: هیچ from_date/to_date ای به لن‌میس فرستاده نمی‌شود (خود API هر
	 *   toDate آینده را رد می‌کند)؛ فیلتر «از N روز قبل تا M ماه آینده» را
	 *   خودمان در PHP روی start_date اعمال می‌کنیم.
	 */
	private function resolve_date_range( $atts ) {
		if ( $this->is_auto_mode( $atts ) ) {
			$days   = $this->auto_days( $atts );
			$months = $this->months_ahead( $atts );
			return array(
				'from_date' => '',
				'to_date'   => '',
				'is_auto'   => true,
				'days'      => $days,
				'cutoff'    => RC_Jalali::offset_str( -1 * $days, '/' ),
				'until'     => $months > 0 ? RC_Jalali::add_months_str( $months, '/' ) : '',
			);
		}

		$from = RC_Jalali::normalize_input_date( isset( $atts['from_date'] ) ? $atts['from_date'] : '' );
		$to   = RC_Jalali::normalize_input_date( isset( $atts['to_date'] ) ? $atts['to_date'] : '' );

		// جابه‌جا نوشتن «از» و «تا» نباید جدول را خالی کند
		if ( '' !== $from && '' !== $to && $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}

		return array(
			'from_date' => '',
			'to_date'   => '',
			'is_auto'   => false,
			'days'      => 0,
			'cutoff'    => $from,
			'until'     => $to,
		);
	}

	/**
	 * ترم‌هایی که واقعاً باید از لن‌میس خوانده شوند.
	 *
	 * مهم‌ترین تغییر نسبت به قبل: در حالت «بازه خودکار» دیگر به یک ترم
	 * مشخص (یا فقط ترم جاری) محدود نیستیم؛ همه ترم‌هایی که در پنجره
	 * زمانی می‌افتند (مرداد + شهریور + ماه‌های بعدی) همزمان خوانده و در یک
	 * جدول ادغام می‌شوند - چون API لن‌میس در هر درخواست فقط یک termKey می‌پذیرد.
	 *
	 * @return string[]
	 */
	private function resolve_terms( $atts ) {
		$explicit = $this->csv( isset( $atts['term_key'] ) ? $atts['term_key'] : '' );

		if ( $this->is_auto_mode( $atts ) ) {
			$window = RC_Query::get_window_term_keys( $this->auto_days( $atts ), $this->months_ahead( $atts ) );
			if ( ! empty( $window ) ) {
				return $window;
			}
		}

		$base = $explicit;
		if ( isset( $atts['auto_term'] ) && $this->is_on( $atts['auto_term'] ) ) {
			$current = RC_Query::get_current_term_key();
			if ( ! empty( $current ) ) {
				$base = array( $current );
			}
		}

		// بازه دستی: ترم‌های کل بازه هم خوانده شوند (نه فقط ترم انتخاب‌شده)
		if ( ! $this->is_auto_mode( $atts ) ) {
			$dates = $this->resolve_date_range( $atts );
			if ( '' !== $dates['cutoff'] || '' !== $dates['until'] ) {
				$range = RC_Query::get_range_term_keys( $dates['cutoff'], $dates['until'] );
				if ( ! empty( $range ) ) {
					// ترم صریحِ خارج از بازه چیزی به جدول اضافه نمی‌کند (فیلتر
					// تاریخ حذفش می‌کند) و فقط یک درخواست و یک ماه اضافه در بنر
					// می‌سازد؛ پس وقتی ترم‌های بازه پیدا شدند، همان‌ها ملاک‌اند.
					return $range;
				}
			}
		}

		return $base;
	}

	// -----------------------------------------------------------------
	// واکشی داده (منبع واحد برای هر سه شرت‌کد و هم مسیر AJAX ادغام)
	// -----------------------------------------------------------------

	/**
	 * واکشی آیتم‌های یک شرت‌کد (با همه ترکیب‌های ترم × دپارتمان × سطح)
	 *
	 * @return array{success:bool, error:string|null, items:array, terms:array}
	 */
	private function fetch_items( $atts, $tag ) {
		$terms = $this->resolve_terms( $atts );
		$dates = $this->resolve_date_range( $atts );

		if ( 'radman_upcoming' === $tag ) {
			// «دوره‌های پیش‌رو» با auto_term قبلاً فقط ترم جاری را می‌خواند؛
			// یعنی روزهای آخر ماه، کلاس‌هایی که هفته بعد (اول ماه بعد) شروع
			// می‌شدند اصلاً واکشی نمی‌شدند و جدول خالی/ناقص می‌ماند.
			if ( ! $this->is_auto_mode( $atts ) && isset( $atts['auto_term'] ) && $this->is_on( $atts['auto_term'] ) ) {
				$window = RC_Query::get_window_term_keys( 0, 1 );
				if ( ! empty( $window ) ) {
					$terms = $window;
				}
			}

			$result = RC_Query::get_upcoming(
				array(
					'branch_key' => $atts['branch_key'],
					'term_keys'  => $terms,
					'count'      => (int) $atts['count'],
				)
			);
			$result['terms'] = $terms;
			return $result;
		}

		$level_keys = isset( $atts['level_keys'] ) ? $this->csv( $atts['level_keys'] ) : array();
		$dep_keys   = isset( $atts['dep_key'] ) ? $this->csv( $atts['dep_key'] ) : array();
		$course_key = isset( $atts['course_key'] ) ? trim( (string) $atts['course_key'] ) : '';

		// منابع: هر سطح، یا یک دوره، یا هیچ (همه کلاس‌ها)
		$sources = array();
		if ( ! empty( $level_keys ) ) {
			foreach ( $level_keys as $lk ) {
				$sources[] = array( 'level_key' => $lk );
			}
		} elseif ( '' !== $course_key ) {
			$sources[] = array( 'course_key' => $course_key );
		} else {
			$sources[] = array();
		}

		if ( empty( $dep_keys ) ) {
			$dep_keys = array( '' );
		}

		$effective_terms = ! empty( $terms ) ? $terms : array( '' );

		// هر عضو $id_list = یک درخواست HTTP جدا به لن‌میس. حاصل‌ضرب
		// ترم × دپارتمان × سطح می‌تواند به‌سرعت به ده‌ها برسد؛ سقف می‌گذاریم
		// تا رندر یک صفحه هرگز به یک آبشار درخواست تبدیل نشود.
		$id_list = array();
		foreach ( $effective_terms as $tk ) {
			foreach ( $dep_keys as $dk ) {
				foreach ( $sources as $source ) {
					if ( count( $id_list ) >= RC_Query::MAX_MERGE_SOURCES ) {
						break 3;
					}
					$id_list[] = array_merge(
						$source,
						array(
							'term_key' => $tk,
							'dep_key'  => $dk,
						)
					);
				}
			}
		}

		$sort_by = isset( $atts['sort'] ) && '' !== $atts['sort'] ? $atts['sort'] : $this->default_sort();
		$limit   = isset( $atts['limit'] ) ? (int) $atts['limit'] : 0;

		$result = RC_Query::get_classes_merged(
			$id_list,
			array(
				'branch_key' => $atts['branch_key'],
				'from_date'  => $dates['from_date'],
				'to_date'    => $dates['to_date'],
				'limit'      => 0, // limit بعد از فیلتر پنجره خودکار اعمال می‌شود
				'sort_by'    => $sort_by,
			)
		);

		if ( $result['success'] && ( '' !== $dates['cutoff'] || '' !== $dates['until'] ) ) {
			$result['items'] = RC_Query::filter_from_date( $result['items'], $dates['cutoff'], $dates['until'] );
		}

		$result['items'] = RC_Query::sort_items( $result['items'], $sort_by );

		if ( $limit > 0 ) {
			$result['items'] = array_slice( $result['items'], 0, $limit );
		}

		$result['terms'] = $terms;

		return $result;
	}

	// -----------------------------------------------------------------
	// شرت‌کدها
	// -----------------------------------------------------------------

	/**
	 * [radman_calendar branch_key="" term_key="" dep_key="" date_mode="auto" auto_days="30" months_ahead="3" limit="0" sort="date_desc"]
	 *
	 * dep_key از لیست جداشده با کاما هم پشتیبانی می‌کند (چند دپارتمان در یک جدول)،
	 * و چند [radman_calendar] در یک صفحه خودبه‌خود در یک جدول واحد ادغام می‌شوند.
	 */
	public function render_calendar( $atts ) {
		return $this->render_shortcode( $atts, 'radman_calendar', 'rc-calendar-main' );
	}

	/**
	 * [radman_course branch_key="" term_key="" level_keys="ID1,ID2" course_key="" ...]
	 */
	public function render_course_container( $atts ) {
		return $this->render_shortcode( $atts, 'radman_course', 'rc-calendar-course' );
	}

	/**
	 * [radman_upcoming branch_key="" term_key="" count="10"]
	 */
	public function render_upcoming( $atts ) {
		return $this->render_shortcode( $atts, 'radman_upcoming', 'rc-calendar-upcoming' );
	}

	/**
	 * مسیر مشترک رندر هر سه شرت‌کد
	 */
	private function render_shortcode( $atts, $tag, $context_class ) {
		$blocked = $this->license_gate();
		if ( null !== $blocked ) {
			return $blocked;
		}

		$atts = shortcode_atts( $this->shortcode_defaults( $tag ), $atts, $tag );
		$this->mark_assets_needed();

		$is_builder_preview = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax()
			&& isset( $_REQUEST['action'] ) && 'rc_preview_shortcode' === sanitize_key( wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $is_builder_preview ) {
			$cache_key = self::SC_CACHE_PREFIX . md5(
				$tag . '|' . wp_json_encode( $atts ) . '|' . self::get_cache_gen() . '|' . RC_Lanmis_Connector::get_cache_gen() . '|' . RC_Jalali::today_str( '-' ) . '|' . RC_VERSION
			);

			if ( isset( self::$render_memo[ $cache_key ] ) ) {
				return self::$render_memo[ $cache_key ];
			}

			$cached_html = get_transient( $cache_key );
			if ( is_string( $cached_html ) && '' !== $cached_html ) {
				self::$render_memo[ $cache_key ] = $cached_html;
				return $cached_html;
			}
		}

		$result = $this->fetch_items( $atts, $tag );
		$inner  = $this->render_table( $result, $context_class, $result['terms'] );
		$output = $this->wrap_slot( $tag, $atts, $inner );

		// فقط خروجی‌های سالم و بدون خطا کش می‌شوند تا در صورت قطعی موقت یا fallback، بازیابی سریع باشد
		if ( ! $is_builder_preview && ! empty( $result['success'] ) && empty( $result['error'] ) ) {
			$settings = RC_Query::settings();
			$ttl      = isset( $settings['cache_ttl'] ) ? max( 60, (int) $settings['cache_ttl'] ) : 900;
			set_transient( $cache_key, $output, $ttl );
			self::$render_memo[ $cache_key ] = $output;
		}

		return $output;
	}

	/**
	 * هر خروجی در یک اسلات قرار می‌گیرد تا اسکریپت فرانت بتواند شرت‌کدهای
	 * هم‌نوع همین صفحه را در یک جدول واحد (با یک جستجو/فیلتر مشترک) ادغام کند.
	 * با merge="0" می‌توان این رفتار را برای یک شرت‌کد خاص خاموش کرد.
	 */
	private function wrap_slot( $tag, $atts, $inner ) {
		if ( isset( $atts['merge'] ) && $this->is_off( $atts['merge'] ) ) {
			return $inner;
		}

		$group = isset( $atts['merge_group'] ) && '' !== trim( (string) $atts['merge_group'] )
			? trim( (string) $atts['merge_group'] )
			: 'rc-page-' . $tag;

		$payload          = $atts;
		$payload['_tag']  = $tag;

		return sprintf(
			'<div class="rc-slot rc-course-slot" data-rc-group="%s" data-rc-tag="%s" data-rc-atts="%s">%s</div>',
			esc_attr( $group ),
			esc_attr( $tag ),
			esc_attr( wp_json_encode( $payload ) ),
			$inner
		);
	}

	/**
	 * ادغام چند شرت‌کد هم‌نوع یک صفحه در یک جدول واحد (AJAX)
	 */
	public function ajax_merge_group() {
		check_ajax_referer( 'rc_merge_group_nonce', 'nonce' );

		if ( class_exists( 'RC_License' ) && ! RC_License::instance()->is_valid() ) {
			wp_send_json_error( array( 'message' => 'لایسنس نامعتبر' ), 403 );
		}

		// این endpoint عمداً nopriv است (بازدیدکننده مهمان هم باید جدول ادغام‌شده
		// را ببیند). ولی همین یعنی هر کسی می‌تواند صدایش بزند و هر فراخوانی
		// به چند درخواست HTTP خروجی به لن‌میس ترجمه می‌شود. بدون سقف، این یک
		// تقویت‌کننده حمله (Amplification) روی هم سایت خودمان و هم سرور لن‌میس
		// است. سه سد: حجم payload، تعداد اسلات، و محدودیت نرخ برای مهمان‌ها.
		$raw = isset( $_POST['atts_list'] ) ? wp_unslash( $_POST['atts_list'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$raw = is_string( $raw ) ? $raw : '';

		if ( strlen( $raw ) > 20000 ) {
			wp_send_json_error( array( 'message' => 'ورودی نامعتبر' ), 400 );
		}

		$atts_list = json_decode( $raw, true );

		if ( ! is_array( $atts_list ) || count( $atts_list ) < 2 ) {
			wp_send_json_error( array( 'message' => 'ورودی نامعتبر' ), 400 );
		}

		if ( count( $atts_list ) > self::MERGE_MAX_SLOTS ) {
			$atts_list = array_slice( $atts_list, 0, self::MERGE_MAX_SLOTS );
		}

		// محدودیت نرخ برای همه، به‌جز مدیر سایت.
		// قبلاً فقط مهمان‌ها محدود می‌شدند؛ یعنی هر حساب سطح پایین (مشترک،
		// مشتری فروشگاه، نویسنده) می‌توانست بی‌نهایت بار این endpoint را صدا
		// بزند و هر فراخوانی به چند درخواست خروجی سنگین به لن‌میس ترجمه می‌شد.
		// عضویت آزاد یا فروشگاهی، این را به یک تقویت‌کننده حمله تبدیل می‌کرد.
		if ( ! current_user_can( 'manage_options' ) && ! $this->merge_rate_limit_ok() ) {
			wp_send_json_error( array( 'message' => 'تعداد درخواست بیش از حد مجاز' ), 429 );
		}

		// کش HTML خروجی: قبلاً هر بازدید صفحه، بعد از رندر سمت سرور، یک
		// درخواست AJAX می‌فرستاد که *دقیقاً همان کار* را از صفر تکرار می‌کرد
		// (همان ترم‌ها، همان دپارتمان‌ها، همان درخواست‌ها به لن‌میس). یعنی
		// هزینه واکشی هر صفحه دو برابر بود. حالا نتیجه ادغام کش می‌شود و
		// بازدیدهای بعدی فقط یک خواندن از کش هستند.
		// نسل کش + تاریخ امروز در کلید: purge فقط شماره نسل را بالا می‌برد، پس
		// روی هاست با Object Cache (Redis/Memcached) هم واقعاً کار می‌کند
		// (DELETE با LIKE روی wp_options آنجا هیچ اثری نداشت)، و پنجره‌های
		// «از N روز قبل» با عوض شدن روز، خروجی دیروز را سرو نمی‌کنند.
		$normalized_slots = array();
		foreach ( $atts_list as $raw_atts ) {
			if ( ! is_array( $raw_atts ) ) {
				continue;
			}
			$slot_tag = isset( $raw_atts['_tag'] ) ? sanitize_key( $raw_atts['_tag'] ) : 'radman_calendar';
			if ( ! in_array( $slot_tag, array( 'radman_calendar', 'radman_course', 'radman_upcoming' ), true ) ) {
				continue;
			}
			$clean_atts = array();
			foreach ( $raw_atts as $att_key => $att_value ) {
				if ( '_tag' === $att_key || ! is_string( $att_key ) || ! is_scalar( $att_value ) ) {
					continue;
				}
				$clean_atts[ $att_key ] = sanitize_text_field( (string) $att_value );
			}
			$single_norm         = shortcode_atts( $this->shortcode_defaults( $slot_tag ), $clean_atts );
			$single_norm['_tag'] = $slot_tag;
			$normalized_slots[]  = $single_norm;
		}

		if ( count( $normalized_slots ) < 2 ) {
			wp_send_json_error( array( 'message' => 'ورودی نامعتبر' ), 400 );
		}

		$cache_key = self::MERGE_CACHE_PREFIX . md5(
			wp_json_encode( $normalized_slots ) . '|' . self::get_cache_gen() . '|' . RC_Lanmis_Connector::get_cache_gen() . '|' . RC_Jalali::today_str( '-' ) . '|' . RC_VERSION
		);
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			wp_send_json_success( array( 'html' => $cached, 'cached' => true ) );
		}

		$all_items   = array();
		$all_terms   = array();
		$has_error   = false;
		$error_msg   = '';
		$any_ok      = false;
		$limit       = 0;
		$count       = 0;
		$sort_by     = $this->default_sort();
		$first       = true;
		$context     = 'rc-calendar-merged';

		foreach ( $normalized_slots as $single ) {
			$tag = $single['_tag'];
			unset( $single['_tag'] );

			if ( $first ) {
				$limit   = isset( $single['limit'] ) ? (int) $single['limit'] : 0;
				$count   = isset( $single['count'] ) ? (int) $single['count'] : 0;
				$sort_by = isset( $single['sort'] ) && '' !== $single['sort'] ? $single['sort'] : $this->default_sort();
				// «پیش‌رو» ترتیب خودش را دارد (نزدیک‌ترین اول)؛ مرتب‌سازی پیش‌فرض
				// «جدیدترین اول» آن را برعکس می‌کرد و دورترین کلاس بالا می‌آمد.
				if ( 'radman_upcoming' === $tag ) {
					$sort_by = 'date_asc';
				}
				$context = 'radman_course' === $tag ? 'rc-calendar-course-merged' : ( 'radman_upcoming' === $tag ? 'rc-calendar-upcoming-merged' : 'rc-calendar-merged' );
				$first   = false;
			}

			$sub = $this->fetch_items( $single, $tag );

			if ( empty( $sub['success'] ) ) {
				$has_error = true;
				$error_msg = isset( $sub['error'] ) ? $sub['error'] : '';
				continue;
			}

			$any_ok = true;
			if ( ! empty( $sub['error'] ) ) {
				$has_error = true;
				$error_msg = $sub['error'];
			}

			$all_items = array_merge( $all_items, $sub['items'] );
			$all_terms = array_merge( $all_terms, (array) $sub['terms'] );
		}

		// حذف موارد تکراری + مرتب‌سازی + محدودسازی نهایی
		$unique = array();
		foreach ( $all_items as $i => $item ) {
			$dedupe_key            = '' !== (string) $item['class_key'] ? 'k:' . $item['class_key'] : 'i:' . $i;
			$unique[ $dedupe_key ] = $item;
		}
		$items = RC_Query::sort_items( array_values( $unique ), $sort_by );

		$cap = $limit > 0 ? $limit : $count;
		if ( $cap > 0 ) {
			$items = array_slice( $items, 0, $cap );
		}

		if ( empty( $items ) && ! $any_ok ) {
			wp_send_json_error( array( 'message' => $error_msg ), 502 );
		}

		$result = array(
			'success' => true,
			'error'   => $has_error ? $error_msg : null,
			'items'   => $items,
		);

		$html = $this->render_table( $result, $context, array_values( array_unique( $all_terms ) ) );

		$settings = RC_Query::settings();
		$ttl      = isset( $settings['cache_ttl'] ) ? max( 60, (int) $settings['cache_ttl'] ) : 900;
		set_transient( $cache_key, $html, $ttl );

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * محدودیت نرخ ساده برای endpoint عمومی ادغام (فقط بازدیدکننده مهمان).
	 * هدف: جلوگیری از اینکه یک کلاینت با تکرار سریع این درخواست، سایت را
	 * مجبور به ارسال سیل درخواست خروجی به لن‌میس کند.
	 */
	private function merge_rate_limit_ok() {
		// کاربر لاگین‌شده با شناسه‌اش شمرده می‌شود و مهمان با IP. اتکای صرف به
		// IP روی سایت‌هایی که پشت پروکسی/CDN هستند یا کاربرهای یک شبکه اداری،
		// همه را در یک سهمیه می‌ریخت و باعث خطای بی‌دلیل ۴۲۹ می‌شد.
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			$identity = 'u' . $user_id;
		} else {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			if ( '' === $ip ) {
				return true;
			}
			$identity = 'i' . $ip;
		}

		$key   = self::MERGE_RATE_PREFIX . md5( $identity );
		$hits  = (int) get_transient( $key );
		$limit = 30; // حداکثر ۳۰ درخواست در ۵ دقیقه

		if ( $hits >= $limit ) {
			return false;
		}

		set_transient( $key, $hits + 1, 5 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * پاک کردن کش HTML جدول‌های ادغام‌شده (با «به‌روزرسانی اجباری» صدا زده می‌شود)
	 */
	public static function purge_merge_cache() {
		global $wpdb;

		$next_gen = self::get_cache_gen() + 1;
		update_option( self::MERGE_GEN_OPTION, $next_gen, true );
		self::$cache_gen_memo = $next_gen;
		self::$render_memo    = array();

		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_" . self::MERGE_CACHE_PREFIX . "%'
			    OR option_name LIKE '_transient_timeout_" . self::MERGE_CACHE_PREFIX . "%'
			    OR option_name LIKE '_transient_" . self::SC_CACHE_PREFIX . "%'
			    OR option_name LIKE '_transient_timeout_" . self::SC_CACHE_PREFIX . "%'"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	// -----------------------------------------------------------------
	// Assets و CSS داینامیک (رنگ‌ها + فونت)
	// -----------------------------------------------------------------

	public static function enqueue_frontend_assets() {
		if ( self::$assets_needed ) {
			return;
		}
		self::$assets_needed = true;

		$settings = RC_Query::settings();

		if ( ! empty( $settings['font_css_url'] ) ) {
			$font_css = esc_url_raw( (string) $settings['font_css_url'], array( 'http', 'https' ) );
			if ( '' !== $font_css ) {
				wp_enqueue_style( 'rc-calendar-font', $font_css, array(), RC_VERSION );
			}
		}

		wp_enqueue_style( 'rc-calendar-style' );
		wp_enqueue_script( 'rc-calendar-script' );
		wp_add_inline_style( 'rc-calendar-style', self::dynamic_css() );

		$export_css_path = RC_PATH . 'assets/css/export.css';
		$export_css_ver  = file_exists( $export_css_path ) ? filemtime( $export_css_path ) : RC_VERSION;

		wp_localize_script(
			'rc-calendar-script',
			'rcCalendarData',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'mergeNonce' => wp_create_nonce( 'rc_merge_group_nonce' ),
				'lazy'       => array(
					'html2canvas' => RC_URL . 'assets/js/vendor/html2canvas.min.js',
					'export'      => RC_URL . 'assets/js/rc-image-export.js',
					'exportCss'   => add_query_arg( 'ver', $export_css_ver, RC_URL . 'assets/css/export.css' ),
				),
			)
		);
	}

	private function mark_assets_needed() {
		self::enqueue_frontend_assets();
	}

	/**
	 * ساخت CSS داینامیک: متغیرهای رنگ + فونت انتخابی ادمین.
	 *
	 * فونت هم برای جدول روی سایت و هم برای خروجی عکس یکی است (--rc-font)،
	 * چون خروجی عکس باید دقیقاً با همان فونت طرح گرافیکی گرفته شود.
	 * اگر فایل فونت (woff2/woff/ttf) در تنطیمات انتخاب شده باشد، همینجا
	 * @font-face هم ساخته می‌شود تا نیازی به دستکاری فایل قالب نباشد.
	 */
	public static function dynamic_css() {
		$settings = RC_Query::settings();

		$css = '';

		$map = array(
			'--rc-header-color'   => isset( $settings['color_header'] ) ? $settings['color_header'] : '#1c6ea4',
			'--rc-hover-color'    => isset( $settings['color_hover'] ) ? $settings['color_hover'] : '#f1f7fb',
			'--rc-badge-online'   => isset( $settings['color_badge_online'] ) ? $settings['color_badge_online'] : '#2ecc71',
			'--rc-badge-offline'  => isset( $settings['color_badge_offline'] ) ? $settings['color_badge_offline'] : '#e67e22',
			'--rc-badge-presence' => isset( $settings['color_badge_presence'] ) ? $settings['color_badge_presence'] : '#3498db',
			'--rc-banner-color'   => isset( $settings['color_banner'] ) ? $settings['color_banner'] : '#1c4f73',
		);

		// نکته: sanitize_hex_color() برای مقدار نامعتبر null برمی‌گرداند و
		// چون همان null در دیتابیس ذخیره می‌شود، متغیر CSS کلاً حذف می‌شد و
		// جدول رنگ‌های پیش‌فرض خودش را هم از دست می‌داد. حالا به مقدار
		// پیش‌فرض برمی‌گردیم تا ظاهر هرگز نشکند.
		$defaults = array(
			'--rc-header-color'   => '#1c6ea4',
			'--rc-hover-color'    => '#f1f7fb',
			'--rc-badge-online'   => '#2ecc71',
			'--rc-badge-offline'  => '#e67e22',
			'--rc-badge-presence' => '#3498db',
			'--rc-banner-color'   => '#1c4f73',
		);

		$declarations = '';
		foreach ( $map as $var => $value ) {
			$safe          = sanitize_hex_color( (string) $value );
			$safe          = $safe ? $safe : $defaults[ $var ];
			$declarations .= sprintf( '%s:%s;', $var, $safe );
		}

		$css .= sprintf( '.rc-wrapper{%s}', $declarations );

		return $css;
	}

	

	// -----------------------------------------------------------------
	// رندر
	// -----------------------------------------------------------------

	/**
	 * از متن کامل زمان‌بندی («یکشنبه 16:00 الی 17:30») فقط بخش الگوی روز را جدا می‌کند
	 */
	private function extract_day_pattern( $class_times, &$days_count = 0 ) {
		$raw = trim( (string) $class_times );
		if ( '' === $raw ) {
			$days_count = 0;
			return '';
		}

		$normalized = str_replace( array( "\u{200C}", "\u{064A}", "\u{0643}" ), array( '', 'ی', 'ک' ), $raw );

		$pattern = '/(?:یک\s*شنبه|دو\s*شنبه|سه\s*شنبه|چهار\s*شنبه|پنج\s*شنبه|جمعه|شنبه)/u';
		if ( preg_match_all( $pattern, $normalized, $matches ) && ! empty( $matches[0] ) ) {
			$canonical_map = array(
				'یکشنبه'   => 'یکشنبه',
				'دوشنبه'   => 'دوشنبه',
				'سهشنبه'   => 'سه‌شنبه',
				'چهارشنبه' => 'چهارشنبه',
				'پنجشنبه'  => 'پنج‌شنبه',
				'جمعه'     => 'جمعه',
				'شنبه'     => 'شنبه',
			);
			$days = array();
			foreach ( $matches[0] as $m ) {
				$key   = preg_replace( '/\s+/u', '', $m );
				$label = isset( $canonical_map[ $key ] ) ? $canonical_map[ $key ] : $m;
				if ( ! in_array( $label, $days, true ) ) {
					$days[] = $label;
				}
			}
			$days_count = count( $days );
			if ( 1 === $days_count ) {
				return $days[0];
			}
			if ( 2 === $days_count ) {
				return $days[0] . ' و ' . $days[1];
			}
			$last = array_pop( $days );
			return implode( '، ', $days ) . ' و ' . $last;
		}

		// fallback امن برای UTF-8 (مانند «روزهای زوج 16:00 الی 18:00» یا «روزهای فرد ...»)
		// نکته: در PHP تابع trim() بایت‌به‌بایت است و گذاشتن کاراکتر فارسی مانند «،» در آن
		// باعث خراب شدن UTF-8 و خالی شدن خروجی esc_html() می‌شود؛ پس فقط از preg_replace با /u استفاده می‌کنیم.
		$days_count = 1;
		$cut        = preg_replace( '/[0-9۰-۹٠-٩]{1,2}\s*[:：]\s*[0-9۰-۹٠-٩]{2}(?:\s*(?:الی|تا|[-–—])\s*[0-9۰-۹٠-٩]{1,2}\s*[:：]\s*[0-9۰-۹٠-٩]{2})?/u', '', $raw );
		if ( null === $cut ) {
			$cut = $raw;
		}
		$cut = preg_replace( '/[0-9۰-۹٠-٩]+/u', '', $cut );
		if ( null === $cut ) {
			$cut = $raw;
		}
		$cut = preg_replace( '/\s+/u', ' ', $cut );
		$cut = preg_replace( '/^[\s\-–—،,|\/:]+|[\s\-–—،,|\/:]+$/u', '', (string) $cut );
		$cut = trim( (string) $cut );
		return '' !== $cut ? $cut : $raw;
	}

	/**
	 * عنوان‌های طولانی دوره در خروجی عکس، فونت کوچک‌تری می‌گیرند (ولی نه ریز و ناخوانا)
	 */
	private function date_size_class( $count ) {
		if ( $count >= 4 ) {
			return 'rc-export-col-date-xs';
		}
		if ( $count === 3 ) {
			return 'rc-export-col-date-sm';
		}
		if ( $count === 2 ) {
			return 'rc-export-col-date-md';
		}
		return '';
	}

	private function days_size_class( $count, $text ) {
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
		if ( $count >= 3 || $len > 36 ) {
			return 'rc-export-col-days-sm';
		}
		if ( $count === 2 || $len > 22 ) {
			return 'rc-export-col-days-md';
		}
		return '';
	}

	private function title_size_class( $title ) {
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( (string) $title, 'UTF-8' ) : strlen( (string) $title );

		if ( $len > 44 ) {
			return 'rc-export-col-title-xs';
		}
		if ( $len > 32 ) {
			return 'rc-export-col-title-sm';
		}
		if ( $len > 22 ) {
			return 'rc-export-col-title-md';
		}
		return '';
	}

	/**
	 * خواندن نگاشت ترجمه انگلیسی دپارتمان‌ها از تنطیمات (هر خط: «فارسی=انگلیسی»)
	 */
	private function get_department_translations() {
		if ( null !== self::$translations_cache ) {
			return self::$translations_cache;
		}
		$settings = RC_Query::settings();
		$raw      = isset( $settings['department_translations'] ) ? $settings['department_translations'] : '';
		$map      = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$parts = explode( '=', $line, 2 );
			if ( 2 === count( $parts ) && trim( $parts[0] ) !== '' ) {
				$map[ trim( $parts[0] ) ] = trim( $parts[1] );
			}
		}
		self::$translations_cache = $map;
		return self::$translations_cache;
	}

	/**
	 * قالب مخفی خروجی عکس - جدا از جدول تعاملی روی صفحه، دقیقاً مطابق
	 * طرح گرافیکی مرجع. تقسیم به صفحات A4 سمت جاوااسکریپت و بر اساس
	 * ارتفاع واقعی رندرشده انجام می‌شود.
	 */
	private function build_export_template( $items, $term_label, $export_id ) {
		$translations = $this->get_department_translations();

		$settings        = RC_Query::settings();
		$footer_enabled  = ! isset( $settings['footer_enabled'] ) || ! empty( $settings['footer_enabled'] );
		$footer_settings = array(
			'before'      => isset( $settings['footer_text_before'] ) && '' !== $settings['footer_text_before'] ? $settings['footer_text_before'] : 'برای مشاوره',
			'highlight'   => isset( $settings['footer_text_highlight'] ) && '' !== $settings['footer_text_highlight'] ? $settings['footer_text_highlight'] : 'رایگان',
			'after'       => isset( $settings['footer_text_after'] ) && '' !== $settings['footer_text_after'] ? $settings['footer_text_after'] : 'و اطلاعات بیشتر',
			'line2'       => isset( $settings['footer_text_line2'] ) && '' !== $settings['footer_text_line2'] ? $settings['footer_text_line2'] : 'فقط کافیه «نام دوره» رو ارسال کنی',
			'phone_left'  => isset( $settings['footer_phone_left'] ) && '' !== $settings['footer_phone_left'] ? $settings['footer_phone_left'] : 'شماره تماس خود را وارد کنید',
			'phone_right' => isset( $settings['footer_phone_right'] ) && '' !== $settings['footer_phone_right'] ? $settings['footer_phone_right'] : '',
			'logo_text'   => isset( $settings['footer_logo_text'] ) ? $settings['footer_logo_text'] : 'آکادمی تخصصی رادمان',
			'logo_url'    => isset( $settings['footer_logo_url'] ) ? $settings['footer_logo_url'] : '',
		);

		// گروه‌بندی بر اساس دپارتمان
		$groups = array();
		foreach ( $items as $item ) {
			$dep = ! empty( $item['department'] ) ? $item['department'] : 'دوره‌ها';
			if ( ! isset( $groups[ $dep ] ) ) {
				$groups[ $dep ] = array();
			}
			$groups[ $dep ][] = $item;
		}

		// ترتیب دپارتمان‌ها طبق «اولویت دپارتمان‌ها» در تنطیمات (بقیه بعد از آن‌ها)
		$rank_map = RC_Query::get_department_rank_map();
		if ( ! empty( $rank_map ) ) {
			$names = array_keys( $groups );
			usort(
				$names,
				function ( $a, $b ) use ( $rank_map ) {
					$ra = isset( $rank_map[ $a ] ) ? $rank_map[ $a ] : PHP_INT_MAX;
					$rb = isset( $rank_map[ $b ] ) ? $rank_map[ $b ] : PHP_INT_MAX;
					if ( $ra !== $rb ) {
						return $ra <=> $rb;
					}
					return 0;
				}
			);
			$sorted = array();
			foreach ( $names as $name ) {
				$sorted[ $name ] = $groups[ $name ];
			}
			$groups = $sorted;
		}

		ob_start();
		?>
		<div class="rc-export-source" id="<?php echo esc_attr( $export_id ); ?>-src" style="position:absolute;left:-99999px;top:0;width:700px;" aria-hidden="true">
			<div class="rc-export-banner-top">
				<svg width="480" height="113" viewBox="0 0 480 113" fill="none" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">
					<path d="M200.333 2L173 29.1672H2V111H478V49.0456H411V2H302L267.333 36.4559H212.667" stroke="#28719C" stroke-width="2"/>
				</svg>
				<span class="rc-export-banner-top-text"><?php echo esc_html( $term_label['headline'] ); ?></span>
			</div>

			<?php foreach ( $groups as $dep_name => $dep_items ) : ?>
				<div class="rc-export-dept-group">
					<div class="rc-export-dept-banner">
						<svg width="514" height="60" viewBox="0 0 514 60" fill="none" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">
							<path d="M0 2H57.3562L104.51 58H257" stroke="#28719C" stroke-width="2"/>
							<path d="M514 2H456.644L409.49 58H257" stroke="#28719C" stroke-width="2"/>
						</svg>
						<div class="rc-export-dept-text">
							<span class="rc-export-dept-title"><?php echo esc_html( $dep_name ); ?></span>
							<?php if ( ! empty( $translations[ $dep_name ] ) ) : ?>
								<span class="rc-export-dept-subtitle"><?php echo esc_html( $translations[ $dep_name ] ); ?></span>
							<?php endif; ?>
						</div>
					</div>

					<div class="rc-export-col-headers">
						<span class="rc-export-col rc-export-col-title">عنوان دوره</span>
						<span class="rc-export-col rc-export-col-date">تاریخ برگزاری</span>
						<span class="rc-export-col rc-export-col-days">روزهای برگزاری</span>
					</div>

					<?php foreach ( $dep_items as $item ) : ?>
						<?php
						$raw_dates = ! empty( $item['start_dates'] ) ? (array) $item['start_dates'] : ( '' !== (string) $item['start_date'] ? array( $item['start_date'] ) : array() );
						$dates     = array();
						foreach ( $raw_dates as $rd ) {
							$readable = RC_Jalali::format_readable( $rd );
							if ( '' !== $readable && ! in_array( $readable, $dates, true ) ) {
								$dates[] = $readable;
							}
						}
						$date_count = count( $dates );
						$days_count = 0;
						$days_text  = $this->extract_day_pattern( $item['class_times'], $days_count );
						?>
						<div class="rc-export-row">
							<div class="rc-export-row-cells">
								<span class="rc-export-col rc-export-col-title <?php echo esc_attr( $this->title_size_class( $item['title'] ) ); ?>"><?php echo esc_html( $item['title'] ); ?></span>
								<span class="rc-export-col rc-export-col-date <?php echo esc_attr( $this->date_size_class( $date_count ) ); ?>"><?php
									if ( $date_count >= 4 ) {
										echo esc_html( implode( '، ', $dates ) );
									} else {
										echo implode( '<br>', array_map( 'esc_html', $dates ) );
									}
								?></span>
								<span class="rc-export-col rc-export-col-days <?php echo esc_attr( $this->days_size_class( $days_count, $days_text ) ); ?>"><?php echo esc_html( $days_text ); ?></span>
							</div>
							<svg class="rc-export-row-divider" width="413" height="26" viewBox="0 0 413 26" fill="none" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">
								<path d="M0 2H28.3238V24H383.798V2.43564H413" stroke="#28719C" stroke-width="1"/>
							</svg>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>

			<?php if ( $footer_enabled ) : ?>
				<div class="rc-export-footer">
					<div class="rc-export-footer-banner">
						<p>
							<?php echo esc_html( $footer_settings['before'] ); ?> <span class="rc-export-footer-highlight"><?php echo esc_html( $footer_settings['highlight'] ); ?></span> <?php echo esc_html( $footer_settings['after'] ); ?><br>
							<?php echo esc_html( $footer_settings['line2'] ); ?>
						</p>
					</div>
					<div class="rc-export-footer-connectors">
						<span class="rc-export-footer-connector"></span>
						<span class="rc-export-footer-connector"></span>
					</div>
					<div class="rc-export-footer-bottom">
						<div class="rc-export-footer-phone"><?php echo esc_html( $footer_settings['phone_left'] ); ?></div>
						<div class="rc-export-footer-logo">
							<?php if ( ! empty( $footer_settings['logo_url'] ) ) : ?>
								<img src="<?php echo esc_url( $footer_settings['logo_url'] ); ?>" alt="" class="rc-export-footer-logo-img">
							<?php else : ?>
								<svg width="40" height="40" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
									<circle cx="20" cy="20" r="18" stroke="#28719C" stroke-width="1.5"/>
									<path d="M20 10c-4 0-7 3-7 6 0 2 1 3 2 4-2 1-4 3-4 6h18c0-3-2-5-4-6 1-1 2-2 2-4 0-3-3-6-7-6z" fill="#28719C"/>
								</svg>
							<?php endif; ?>
							<?php if ( ! empty( $footer_settings['logo_text'] ) ) : ?>
								<span><?php echo esc_html( $footer_settings['logo_text'] ); ?></span>
							<?php endif; ?>
						</div>
						<div class="rc-export-footer-phone"><?php echo esc_html( $footer_settings['phone_right'] ); ?></div>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * رندر مشترک جدول - همه شرت‌کدها از این تابع استفاده می‌کنند
	 *
	 * @param array        $result
	 * @param string       $context_class
	 * @param array|string $term_keys ترم‌هایی که داده از آن‌ها آمده (برای متن بنر)
	 */
	private function render_table( $result, $context_class, $term_keys = array() ) {
		ob_start();

		if ( empty( $result['success'] ) && empty( $result['items'] ) ) {
			echo '<p class="rc-error">' . esc_html( 'خطا در دریافت اطلاعات: ' . ( isset( $result['error'] ) ? $result['error'] : '' ) ) . '</p>';
			return ob_get_clean();
		}

		if ( ! empty( $result['error'] ) ) {
			// success=true ولی از fallback استفاده شده (هشدار غیرمسدودکننده)
			echo '<p class="rc-warning" style="display:none;" data-rc-log="' . esc_attr( $result['error'] ) . '"></p>';
		}

		if ( empty( $result['items'] ) ) {
			echo '<p class="rc-empty">در حال حاضر کلاسی برای نمایش وجود ندارد.</p>';
			return ob_get_clean();
		}

		$settings   = RC_Query::settings();
		$term_label = RC_Query::get_term_label( $term_keys );
		// قالب A4 فقط برای پیش‌نمایش شورتکدساز داشبورد ساخته می‌شود؛
		// نباید هیچ‌وقت در خروجی عمومی سایت به DOM فرانت اضافه شود.
		$is_builder_preview = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax()
			&& isset( $_REQUEST['action'] ) && 'rc_preview_shortcode' === sanitize_key( wp_unslash( $_REQUEST['action'] ) );
		$can_export = $is_builder_preview;
		// قالب سنگین عکس (SVGها + همه ردیف‌ها) فقط وقتی ساخته می‌شود که ادمین
		// واقعاً عکس خواسته باشد (rc_with_export=1). پیش‌نمایش معمولی فقط دکمه‌ها
		// را دارد، پس هر تغییر در فرم هزینه ساخت قالب عکس را نمی‌پردازد.
		$with_export_template = $can_export && ! empty( $_REQUEST['rc_with_export'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$export_id            = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'rc-export-' ) : 'rc-export-' . substr( md5( microtime() . wp_rand() ), 0, 10 );
		?>
		<?php echo $with_export_template ? $this->build_export_template( $result['items'], $term_label, $export_id ) : ''; ?>
		<div class="rc-wrapper <?php echo esc_attr( $context_class ); ?>" id="<?php echo esc_attr( $export_id ); ?>">
			<div class="rc-banner">
				<div class="rc-banner-text">
					<span class="rc-banner-title"><?php echo esc_html( $term_label['headline'] ? $term_label['headline'] : 'اطلاعیه شروع دوره‌ها' ); ?></span>
				</div>
			</div>

			<?php if ( ! empty( $settings['show_count'] ) ) : ?>
				<p class="rc-count">
					تعداد کل: <strong class="rc-count-number"><?php echo (int) count( $result['items'] ); ?></strong> مورد
				</p>
			<?php endif; ?>

			<div class="rc-controls">
				<input type="text" class="rc-search" placeholder="جستجو در نام دوره، استاد، دپارتمان...">
				<select class="rc-day-filter">
					<option value="">همه روزها</option>
					<option value="شنبه">شنبه</option>
					<option value="یکشنبه">یکشنبه</option>
					<option value="دوشنبه">دوشنبه</option>
					<option value="سه‌شنبه">سه‌شنبه</option>
					<option value="چهارشنبه">چهارشنبه</option>
					<option value="پنجشنبه">پنجشنبه</option>
					<option value="جمعه">جمعه</option>
				</select>
				<?php if ( $can_export ) : ?>
					<button type="button" class="rc-export-btn" data-rc-export-format="a4" data-rc-export-source="<?php echo esc_attr( $export_id ); ?>-src">
						⬇ دانلود A4 (عکس)
					</button>
					<button type="button" class="rc-export-btn" data-rc-export-format="story" data-rc-export-source="<?php echo esc_attr( $export_id ); ?>-src">
						⬇ دانلود استوری ۹:۱۶
					</button>
				<?php endif; ?>
			</div>

			<div class="rc-table-wrap">
				<table class="rc-table">
					<thead>
						<tr>
							<th>نام دوره</th>
							<th>روز و ساعت</th>
							<th>تاریخ شروع</th>
							<th>تاریخ پایان</th>
							<th>نحوه برگزاری</th>
							<th>ظرفیت</th>
							<th>توضیحات</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $result['items'] as $item ) : ?>
							<tr class="rc-row" data-day="<?php echo esc_attr( wp_strip_all_tags( $item['class_times'] ) ); ?>"
								data-search="<?php echo esc_attr( $item['title'] . ' ' . $item['teacher'] . ' ' . $item['course'] . ' ' . $item['department'] ); ?>">
								<td class="rc-col-title" data-label="نام دوره">
									<?php if ( ! empty( $item['link'] ) ) : ?>
										<a href="<?php echo esc_url( $item['link'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $item['title'] ); ?>
									<?php endif; ?>
								</td>
								<td class="rc-col-time" data-label="روز و ساعت"><?php echo esc_html( $item['class_times'] ); ?></td>
								<td class="rc-col-date" data-label="تاریخ شروع"><?php echo esc_html( $item['start_date'] ); ?></td>
								<td class="rc-col-end-date" data-label="تاریخ پایان"><?php echo $item['end_date'] ? esc_html( $item['end_date'] ) : '—'; ?></td>
								<td class="rc-col-mode" data-label="نحوه برگزاری">
									<?php if ( empty( $item['mode_labels'] ) ) : ?>
										<span class="rc-badge-empty">—</span>
									<?php else : ?>
										<?php foreach ( $item['mode_labels'] as $mode ) : ?>
											<span class="rc-badge rc-badge-<?php echo esc_attr( $mode['key'] ); ?>"><?php echo esc_html( $mode['label'] ); ?></span>
										<?php endforeach; ?>
									<?php endif; ?>
								</td>
								<td class="rc-col-capacity" data-label="ظرفیت">
									<?php if ( null !== $item['capacity'] ) : ?>
										<?php echo null !== $item['registered'] ? esc_html( $item['registered'] ) . ' از ' : ''; ?><?php echo esc_html( $item['capacity'] ); ?>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
								<td class="rc-col-desc" data-label="توضیحات"><?php echo wp_kses_post( $item['description'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
