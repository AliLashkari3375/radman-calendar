<?php
/**
 * Copyright (c) 2026 Ali Lashkari. All Rights Reserved. See LICENSE.
 *
 * تابع/کلاس مرکزی Query.
 *
 * تنها نقطه‌ای است که داده کلاس‌ها را از لن‌میس می‌گیرد، با داده دستی
 * (توضیحات/لینک/نام دلخواه/اولویت) Merge می‌کند و خروجی یکسان برای همه
 * شرت‌کدها (تقویم اصلی، سینگل دوره، Upcoming) فراهم می‌کند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RC_Query {

	/**
	 * کش درون-درخواست.
	 *
	 * دلیل: get_terms_normalized() در یک بارگذاری صفحه چند بار صدا زده می‌شد
	 * (یک‌بار برای پنجره ترم‌ها، یک‌بار از get_current_term_key که خودش دوباره
	 * همین را صدا می‌زد، و یک‌بار برای متن بنر). هر بار یعنی یک get_transient
	 * + نرمال‌سازی + usort روی همه ترم‌ها. با چند شرت‌کد در یک صفحه این کار
	 * ده‌ها بار بی‌دلیل تکرار می‌شد.
	 */
	private static $terms_cache    = null;
	private static $current_cache  = null;
	private static $settings_cache = null;
	private static $rank_cache     = null;
	private static $label_cache    = array();
	private static $classes_cache  = array();

	/** سقف تعداد فراخوانی API در یک مجموعه ادغام (سد محافظ در برابر انفجار ترکیبی) */
	const MAX_MERGE_SOURCES = 24;

	/**
	 * خواندن تنطیمات پلاگین با کش درون-درخواست.
	 * pick_title() قبلاً برای هر ردیف جدول یک get_option جدا صدا می‌زد.
	 */
	public static function settings() {
		if ( null === self::$settings_cache ) {
			self::$settings_cache = (array) get_option( 'rc_settings', array() );
		}
		return self::$settings_cache;
	}

	/** خالی کردن کش درون-درخواست (بعد از تغییر تنطیمات یا پاک کردن کش) */
	public static function flush_runtime_cache() {
		self::$terms_cache    = null;
		self::$current_cache  = null;
		self::$settings_cache = null;
		self::$rank_cache     = null;
		self::$label_cache    = array();
		self::$classes_cache  = array();
	}

	/**
	 * لیست ترم‌های لن‌میس به شکل نرمال و مرتب‌شده (قدیمی → جدید).
	 *
	 * @return array لیستی از array{key,title,year,month,ym,is_current}
	 */
	public static function get_terms_normalized() {
		if ( null !== self::$terms_cache ) {
			return self::$terms_cache;
		}

		$connector = RC_Lanmis_Connector::instance();
		$terms     = $connector->get_terms();

		if ( ! $terms['success'] ) {
			self::$terms_cache = array();
			return self::$terms_cache;
		}

		$out = array();
		foreach ( (array) $terms['data'] as $term ) {
			if ( empty( $term['key'] ) ) {
				continue;
			}
			$title = isset( $term['title'] ) ? trim( (string) $term['title'] ) : '';
			$year  = isset( $term['year'] ) ? trim( (string) RC_Jalali::to_latin_digits( $term['year'] ) ) : '';
			$month = RC_Jalali::month_number_from_title( $title );

			// اگر سال در فیلد جدا نبود، از خود عنوان درمی‌آوریم (مانند «مرداد 1405»)
			if ( '' === $year && preg_match( '/(1[34]\d{2})/', RC_Jalali::to_latin_digits( $title ), $m ) ) {
				$year = $m[1];
			}

			$out[] = array(
				'key'        => $term['key'],
				'title'      => $title,
				'year'       => $year,
				'month'      => $month,
				'ym'         => RC_Jalali::ym_index( $year, $month ),
				'is_current' => ! empty( $term['is_current_term'] ),
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				return $a['ym'] <=> $b['ym'];
			}
		);

		self::$terms_cache = $out;

		return self::$terms_cache;
	}

	/**
	 * دریافت term_key ترمی که لن‌میس آن را «ترم جاری» (is_current_term) اعلام کرده.
	 */
	public static function get_current_term_key() {
		if ( null !== self::$current_cache ) {
			return self::$current_cache;
		}
		self::$current_cache = '';
		foreach ( self::get_terms_normalized() as $term ) {
			if ( $term['is_current'] ) {
				self::$current_cache = $term['key'];
				break;
			}
		}
		return self::$current_cache;
	}

	/**
	 * کلید تمام ترم‌هایی که در «پنجره زمانی خودکار» قرار می‌گیرند:
	 * از ماهی که (امروز - N روز) در آن است، تا انتهای ترم‌هایی که لن‌میس دارد.
	 *
	 * این قلب حل مشکل «برنامه شهریور نمایش داده نمی‌شود» است: API لن‌میس در
	 * هر درخواست فقط یک termKey می‌پذیر، پس بازه تاریخی که از مرز ماه رد می‌شود
	 * باید به چند درخواست (یکی برای هر ترم/ماه) ترجمه شود و نتیجه‌ها ادغام شوند.
	 *
	 * @param int $days_back      چند روز عقب (۷ یا ۳۰)
	 * @param int $months_ahead   چند ماه شمسی جلوتر از امروز (پیش‌فرض ۳). صفر یا منفی = بدون سقف
	 *                            (همه ترم‌های آینده‌ای که لن‌میس دارد، با سقف ایمنی ۱۲ ترم)
	 * @return string[]
	 */
	public static function get_window_term_keys( $days_back = 30, $months_ahead = 3 ) {
		$terms = self::get_terms_normalized();
		if ( empty( $terms ) ) {
			return array();
		}

		$months_ahead = (int) $months_ahead;

		list( $from_y, $from_m ) = RC_Jalali::offset_ym( -1 * abs( (int) $days_back ) );
		$from_ym = RC_Jalali::ym_index( $from_y, $from_m );

		// باگ قبلی: سقف پنجره روی «ماه جاری» قفل شده بود و پارامتر
		// $months_ahead اصلاً خوانده نمی‌شد؛ یعنی ترم‌های ماه‌های آینده هرگز
		// از لن‌میس واکشی نمی‌شدند و بازه «۳ ماه آینده» عملاً فقط همین ماه بود.
		if ( $months_ahead > 0 ) {
			$to_parts = explode( '-', RC_Jalali::add_months_str( $months_ahead, '-' ) );
			$to_ym    = RC_Jalali::ym_index( $to_parts[0], $to_parts[1] );
		} else {
			$to_ym = PHP_INT_MAX;
		}

		$keys = array();
		foreach ( $terms as $term ) {
			// ترمی که ماه/سالش قابل تشخیص نیست ولی جاری است، نباید حذف شود
			if ( 0 === $term['ym'] ) {
				if ( $term['is_current'] ) {
					$keys[] = $term['key'];
				}
				continue;
			}
			if ( $term['ym'] >= $from_ym && $term['ym'] <= $to_ym ) {
				$keys[] = $term['key'];
			}
		}

		// ترم جاری همیشه در لیست باشد (حتی اگر عنوانش غیراستاندارد باشد)
		$current = self::get_current_term_key();
		if ( '' !== $current && ! in_array( $current, $keys, true ) ) {
			$keys[] = $current;
		}

		$keys = array_values( array_unique( $keys ) );

		// safety cap: one API request per term
		if ( count( $keys ) > 12 ) {
			$keys = array_slice( $keys, 0, 12 );
		}

		return $keys;
	}

	/**
	 * کلید ترم‌هایی که ماهشان با یک بازه تاریخ «دستی» هم‌پوشانی دارد.
	 *
	 * API لن‌میس در هر درخواست فقط یک termKey می‌پذیرد؛ پس بازه دستی مثل
	 * «۱۵ مهر تا ۱۵ آبان» اگر فقط ترم انتخاب‌شده (مهر) خوانده شود، کلاس‌های
	 * آبان هیچ‌وقت نمی‌آیند. اگر فقط یک سر بازه داده شده باشد، سر دیگر تا
	 * ۳ ماه در همان جهت در نظر گرفته می‌شود تا درخواست بی‌سقف نشود.
	 *
	 * @param string $from  YYYY/MM/DD یا خالی
	 * @param string $to    YYYY/MM/DD یا خالی
	 * @return string[]
	 */
	public static function get_range_term_keys( $from, $to ) {
		if ( '' === $from && '' === $to ) {
			return array();
		}
		$terms = self::get_terms_normalized();
		if ( empty( $terms ) ) {
			return array();
		}

		$from_ym = '' !== $from ? RC_Jalali::ym_index( substr( $from, 0, 4 ), substr( $from, 5, 2 ) ) : 0;
		$to_ym   = '' !== $to ? RC_Jalali::ym_index( substr( $to, 0, 4 ), substr( $to, 5, 2 ) ) : 0;

		if ( 0 === $from_ym ) {
			$from_ym = $to_ym - 3;
		}
		if ( 0 === $to_ym ) {
			$to_ym = $from_ym + 3;
		}

		$keys = array();
		foreach ( $terms as $term ) {
			if ( $term['ym'] > 0 && $term['ym'] >= $from_ym && $term['ym'] <= $to_ym ) {
				$keys[] = $term['key'];
			}
		}

		return array_slice( array_values( array_unique( $keys ) ), 0, 12 );
	}

	/**
	 * دریافت عنوان (نام ماه) و سال یک یا چند ترم - برای بنر بالای جدول
	 * («اطلاعیه شروع دوره‌های مرداد و شهریور ۱۴۰۵»).
	 *
	 * از لیست چندتایی جدا شده با کاما هم پشتیبانی می‌کند و ماه‌ها را به ترتیب
	 * تقویمی (نه ترتیب نوشتن در شرت‌کد) مرتب می‌کند.
	 *
	 * @return array{month:string, year:string, months:array, years:array, headline:string}
	 */
	public static function get_term_label( $term_key ) {
		$empty = array(
			'month'    => '',
			'year'     => '',
			'months'   => array(),
			'years'    => array(),
			'headline' => '',
		);

		$keys = is_array( $term_key ) ? $term_key : explode( ',', (string) $term_key );
		$keys = array_filter( array_map( 'trim', $keys ) );

		if ( empty( $keys ) ) {
			return $empty;
		}

		// هر جدول رندرشده یک‌بار همین را می‌خواهد؛ با چند شرت‌کد در صفحه تکراری می‌شد
		sort( $keys );
		$memo_key = md5( implode( '|', $keys ) );
		if ( isset( self::$label_cache[ $memo_key ] ) ) {
			return self::$label_cache[ $memo_key ];
		}

		$terms = self::get_terms_normalized();
		if ( empty( $terms ) ) {
			return $empty;
		}

		$matched = array();
		foreach ( $terms as $term ) {
			if ( in_array( $term['key'], $keys, true ) ) {
				$matched[] = $term;
			}
		}

		$months = array();
		$years  = array();
		foreach ( $matched as $term ) {
			$month = $term['month'] ? RC_Jalali::month_name( $term['month'] ) : $term['title'];
			if ( '' !== $month && ! in_array( $month, $months, true ) ) {
				$months[] = $month;
			}
			if ( '' !== $term['year'] && ! in_array( $term['year'], $years, true ) ) {
				$years[] = $term['year'];
			}
		}

		$label = array(
			'month'  => implode( ' و ', $months ),
			'year'   => implode( ' و ', $years ),
			'months' => $months,
			'years'  => $years,
		);

		$label['headline'] = self::build_headline( $label );

		self::$label_cache[ $memo_key ] = $label;

		return $label;
	}

	/**
	 * متن بنر: «اطلاعیه شروع دوره‌های مرداد و شهریور ۱۴۰۵»
	 * (کلمه «ماه» فقط وقتی یک ماه داریم اضافه می‌شود؛ با دو ماه گفتن «مرداد و شهریور ماه» غلط است)
	 */
	public static function build_headline( $label ) {
		$months = isset( $label['months'] ) ? $label['months'] : array();
		$years  = isset( $label['years'] ) ? $label['years'] : array();

		$text = 'اطلاعیه شروع دوره‌ها';

		if ( ! empty( $months ) ) {
			$text .= 'ی ' . implode( ' و ', $months );
			if ( 1 === count( $months ) ) {
				$text .= ' ماه';
			}
		}

		if ( ! empty( $years ) ) {
			$text .= ' ' . RC_Jalali::to_persian_digits( implode( ' و ', $years ) );
		}

		return $text;
	}

	/**
	 * دریافت لیست کلاس‌های Merge شده (لن‌میس + داده دستی)
	 *
	 * @return array{success:bool, error:string|null, items:array}
	 */
	public static function get_classes( $args = array() ) {
		$defaults = array(
			'branch_key' => '',
			'term_key'   => '',
			'dep_key'    => '',
			'course_key' => '',
			'level_key'  => '',
			'from_date'  => '',
			'to_date'    => '',
			'limit'      => 0,
			'sort_by'    => 'date_desc',
			'force'      => false,
		);
		$args = wp_parse_args( $args, $defaults );
		ksort( $args );
		$memo_sig = md5( wp_json_encode( $args ) );
		if ( empty( $args['force'] ) && isset( self::$classes_cache[ $memo_sig ] ) ) {
			return self::$classes_cache[ $memo_sig ];
		}

		$connector = RC_Lanmis_Connector::instance();

		$response = $connector->get_class_list(
			array(
				'branchKey' => $args['branch_key'],
				'termKey'   => $args['term_key'],
				'depKey'    => $args['dep_key'],
				'courseKey' => $args['course_key'],
				'levelKey'  => $args['level_key'],
				'fromDate'  => $args['from_date'],
				'toDate'    => $args['to_date'],
			),
			$args['force']
		);

		if ( ! $response['success'] ) {
			return array(
				'success' => false,
				'error'   => $response['error'],
				'items'   => array(),
			);
		}

		$raw_items = is_array( $response['data'] ) ? $response['data'] : array();

		// جمع‌آوری تمام class_key ها برای یک کوئری دستی واحد (به‌جای N کوئری).
		// wp_list_pluck روی رکوردی که آرایه نباشد خطای PHP می‌دهد، و پاسخ API
		// همیشه دقیقاً همان شکلی نیست که انتظار داریم؛ پس خودمان می‌چینیم.
		$class_keys = array();
		foreach ( $raw_items as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				unset( $raw_items[ $index ] );
				continue;
			}
			if ( isset( $raw['key'] ) && is_scalar( $raw['key'] ) && '' !== (string) $raw['key'] ) {
				$class_keys[] = (string) $raw['key'];
			}
		}

		$manual_map = RC_Manual_Data::get_many( $class_keys );

		$items = array();
		foreach ( $raw_items as $raw ) {
			$key    = isset( $raw['key'] ) ? $raw['key'] : '';
			$manual = isset( $manual_map[ $key ] ) ? $manual_map[ $key ] : null;

			$items[] = self::normalize_item( $raw, $manual );
		}

		$items = self::sort_items( $items, $args['sort_by'] );

		if ( $args['limit'] > 0 ) {
			$items = array_slice( $items, 0, (int) $args['limit'] );
		}

		$out = array(
			'success'    => true,
			'error'      => $response['error'], // ممکن است هشدار fallback باشد حتی وقتی success=true
			'from_cache' => $response['from_cache'],
			'items'      => $items,
		);
		self::$classes_cache[ $memo_sig ] = $out;
		return $out;
	}

	/**
	 * همان get_classes ولی برای چند ترم (چند ماه) با هم؛ نتیجه‌ها در یک
	 * لیست واحد (بدون تکرار) ادغام می‌شوند.
	 *
	 * @param string[] $term_keys
	 */
	public static function get_classes_for_terms( $term_keys, $args = array() ) {
		$term_keys = array_values( array_filter( array_map( 'trim', (array) $term_keys ) ) );
		if ( empty( $term_keys ) ) {
			$term_keys = array( isset( $args['term_key'] ) ? $args['term_key'] : '' );
		}

		$id_list = array();
		foreach ( $term_keys as $tk ) {
			$id_list[] = array( 'term_key' => $tk );
		}

		return self::get_classes_merged( $id_list, $args );
	}

	/**
	 * فیلتر پنجره زمانی: فقط آیتم‌هایی که تاریخ شروعشان بین cutoff و
	 * (در صورت وجود) until است. until خالی = بدون سقف در آینده.
	 */
	public static function filter_from_date( $items, $cutoff, $until = '' ) {
		$cutoff = RC_Jalali::normalize_date( $cutoff );
		$until  = RC_Jalali::normalize_date( $until );
		if ( '' === $cutoff && '' === $until ) {
			return $items;
		}

		return array_values(
			array_filter(
				$items,
				function ( $item ) use ( $cutoff, $until ) {
					if ( empty( $item['start_date'] ) ) {
						return false;
					}
					$start = $item['start_date'];
					if ( '' !== $cutoff && $start < $cutoff ) {
						return false;
					}
					if ( '' !== $until && $start > $until ) {
						return false;
					}
					return true;
				}
			)
		);
	}

	/**
	 * پاک‌سازی یک متن خام API (حذف نیم‌فاصله اضافی، فاصله‌های تکراری و کاراکترهای تزئینی)
	 */
	private static function clean_text( $value ) {
		$value = (string) $value;
		$value = str_replace( array( "\u{200C}", "\u{200F}", "\u{200E}" ), ' ', $value );
		$value = preg_replace( '/\s+/u', ' ', $value );
		// حذف کاراکترهای تزئینی/بی‌معنی ابتدا و انتها (با /u تا روی متن فارسی مشکل بایتی ایجاد نشود)
		$junk  = '[\s*#_.,:;|()\[\]{}،\/\\\\–—-]+';
		$value = preg_replace( '/^' . $junk . '|' . $junk . '$/u', '', (string) $value );
		return (string) $value;
	}

	/**
	 * آیا این متن واقعاً یک «نام» است یا یک کد داخلی/علامت بی‌معنی (مانند "A"، "**"، "-")
	 */
	private static function is_meaningful( $value ) {
		$value = self::clean_text( $value );
		if ( '' === $value ) {
			return false;
		}
		$letters = preg_replace( '/[^\p{L}]+/u', '', $value );
		return '' !== $letters && mb_strlen( $letters, 'UTF-8' ) >= 3;
	}

	/**
	 * آیا این متن فقط یک «سطح» عمومی است (مانند «مقدماتی»، «سطح ۲»، «ترم ۱»)
	 * که به‌تنهایی اسم درس را نمی‌رساند و باید همراه نام دوره نوشته شود.
	 */
	private static function is_generic_level( $value ) {
		$value = self::clean_text( $value );
		if ( '' === $value ) {
			return false;
		}

		$generic = array( 'فوق پیشرفته', 'پیشرفته', 'مقدماتی', 'متوسط', 'تکمیلی', 'جامع', 'پایه', 'سطح', 'ترم', 'مرحله', 'دوره', 'بخش', 'قسمت', 'پک', 'عمومی' );
		$stripped = $value;
		foreach ( $generic as $word ) {
			$stripped = str_replace( $word, '', $stripped );
		}
		$stripped = preg_replace( '/[\s\d۰-۹٠-٩ivxIVX\-–—_.،,]+/u', '', $stripped );

		return '' === $stripped;
	}

	/**
	 * انتخاب «نام دوره» قابل نمایش برای دانشجو.
	 *
	 * مشکلی که حل می‌کند: در بعضی دپارتمان‌ها (مثلاً تعمیرات و تاسیسات) فیلد
	 * level یا خالی است، یا یک کد داخلی ("A"، "**") است، یا فقط یک سطح عمومی
	 * ("مقدماتی") است؛ در همه این حالت‌ها قبلاً اسم درس نادرست/ناقص نمایش داده می‌شد.
	 *
	 * ترتیب تصمیم (حالت خودکار):
	 *  ۱) اگر ادمین برای این کلاس نام دلخواه ثبت کرده → همان
	 *  ۲) سطح معنادار و غیرعمومی → سطح
	 *  ۳) سطح عمومی + دوره معنادار → «دوره سطح» (مانند «پایتون مقدماتی»)
	 *  ۴) دوره معنادار → دوره
	 *  ۵) عنوان خام کلاس → همان
	 */
	private static function pick_title( $raw, $manual ) {
		if ( $manual && ! empty( $manual['custom_title'] ) ) {
			return self::clean_text( $manual['custom_title'] );
		}

		$level  = self::clean_text( isset( $raw['level'] ) ? $raw['level'] : '' );
		$course = self::clean_text( isset( $raw['course'] ) ? $raw['course'] : '' );
		$class  = self::clean_text( isset( $raw['class_title'] ) ? $raw['class_title'] : '' );

		$settings = self::settings();
		$source   = isset( $settings['title_source'] ) ? $settings['title_source'] : 'auto';

		if ( 'level' === $source && self::is_meaningful( $level ) ) {
			return $level;
		}
		if ( 'course' === $source && self::is_meaningful( $course ) ) {
			return $course;
		}
		if ( 'course_level' === $source ) {
			$parts = array_filter( array( $course, $level ) );
			if ( ! empty( $parts ) ) {
				return implode( ' ', array_unique( $parts ) );
			}
		}

		// حالت خودکار (پیش‌فرض)
		if ( self::is_meaningful( $level ) && ! self::is_generic_level( $level ) ) {
			return $level;
		}

		if ( self::is_meaningful( $course ) ) {
			if ( '' !== $level && self::is_generic_level( $level ) && false === mb_strpos( $course, $level ) ) {
				return $course . ' ' . $level;
			}
			return $course;
		}

		if ( self::is_meaningful( $level ) ) {
			return $level;
		}

		if ( self::is_meaningful( $class ) ) {
			return $class;
		}

		return '' !== $class ? $class : 'کلاس بدون عنوان';
	}

	/**
	 * تبدیل یک آیتم خام لن‌میس + داده دستی به ساختار استاندارد خروجی
	 */
	private static function extract_all_dates( $raw_date ) {
		$raw_date = RC_Jalali::to_latin_digits( (string) $raw_date );
		if ( '' === trim( $raw_date ) ) {
			return array();
		}
		if ( preg_match_all( '/\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}/', $raw_date, $matches ) ) {
			$out = array();
			foreach ( $matches[0] as $d ) {
				$norm = RC_Jalali::normalize_date( $d );
				if ( '' !== $norm && ! in_array( $norm, $out, true ) ) {
					$out[] = $norm;
				}
			}
			return $out;
		}
		$single = RC_Jalali::normalize_date( $raw_date );
		return '' !== $single ? array( $single ) : array();
	}

	private static function normalize_item( $raw, $manual ) {
		$mode_labels = array();
		if ( ! empty( $raw['is_presence'] ) ) {
			$mode_labels[] = array( 'key' => 'presence', 'label' => 'حضوری' );
		}
		if ( ! empty( $raw['is_online'] ) ) {
			$mode_labels[] = array( 'key' => 'online', 'label' => 'آنلاین' );
		}
		if ( ! empty( $raw['is_offline'] ) ) {
			$mode_labels[] = array( 'key' => 'offline', 'label' => 'آفلاین' );
		}

		return array(
			'class_key'     => isset( $raw['key'] ) ? (string) $raw['key'] : '',
			'title'         => self::pick_title( $raw, $manual ),
			'course'        => isset( $raw['course'] ) ? (string) $raw['course'] : '',
			'department'    => isset( $raw['department'] ) ? trim( (string) $raw['department'] ) : '',
			'level'         => isset( $raw['level'] ) ? (string) $raw['level'] : '',
			'start_date'    => RC_Jalali::normalize_date( isset( $raw['start_date'] ) ? (string) $raw['start_date'] : '' ),
			'start_dates'   => self::extract_all_dates( isset( $raw['start_date'] ) ? (string) $raw['start_date'] : '' ),
			'end_date'      => RC_Jalali::normalize_date( isset( $raw['end_date'] ) ? (string) $raw['end_date'] : '' ),
			'class_times'   => isset( $raw['class_times'] ) ? (string) $raw['class_times'] : '',
			'mode_labels'   => $mode_labels, // آنلاین/آفلاین/حضوری - یک ستون طبق سند
			'capacity'      => isset( $raw['capacity'] ) ? (int) $raw['capacity'] : null,
			'registered'    => isset( $raw['registered'] ) ? (int) $raw['registered'] : null,
			'teacher'       => isset( $raw['teacher'] ) ? (string) $raw['teacher'] : '',
			'price'         => isset( $raw['price'] ) ? $raw['price'] : null,
			// داده‌های دستی (اگر ادمین ثبت کرده باشد)
			'description'   => $manual && isset( $manual['description'] ) ? (string) $manual['description'] : '',
			'link'          => $manual && isset( $manual['custom_link'] ) ? (string) $manual['custom_link'] : '',
			'manual_title'  => $manual && ! empty( $manual['custom_title'] ) ? (string) $manual['custom_title'] : '',
			'sort_priority' => $manual && isset( $manual['sort_priority'] ) ? (int) $manual['sort_priority'] : 0,
		);
	}

	/**
	 * مرتب‌سازی آیتم‌ها بر اساس تنطیمات ادمین یا شرت‌کد
	 */
	/**
	 * ارث‌بری اولویت دستی بر اساس عنوان دوره در بازه‌های چندترمی:
	 * اگر ادمین برای یک دوره (مثلاً «پایتون مقدماتی») در یک ترم اولویت ثبت کرده
	 * باشد و همان دوره در ترم دیگر هنوز اولویت جداگانه (class_key) نداشته باشد،
	 * اولویت همان عنوان را می‌گیرد تا در بازه‌های تاریخی چندماهه ترتیب به‌هم نریزد.
	 */
	public static function apply_title_priority_fallback( $items ) {
		if ( empty( $items ) ) {
			return $items;
		}

		$title_map = RC_Manual_Data::get_title_priority_map();

		// از خود آیتم‌های همین درخواست هم بالاترین اولویت هر عنوان را جمع می‌کنیم
		foreach ( $items as $item ) {
			$prio = isset( $item['sort_priority'] ) ? (int) $item['sort_priority'] : 0;
			if ( $prio > 0 ) {
				$norm = self::normalize_department_key( isset( $item['title'] ) ? $item['title'] : '' );
				if ( '' !== $norm && ( ! isset( $title_map[ $norm ] ) || $prio > $title_map[ $norm ] ) ) {
					$title_map[ $norm ] = $prio;
				}
			}
		}

		if ( empty( $title_map ) ) {
			return $items;
		}

		foreach ( $items as $idx => $item ) {
			$prio = isset( $item['sort_priority'] ) ? (int) $item['sort_priority'] : 0;
			if ( 0 === $prio ) {
				$norm = self::normalize_department_key( isset( $item['title'] ) ? $item['title'] : '' );
				if ( '' !== $norm && isset( $title_map[ $norm ] ) ) {
					$items[ $idx ]['sort_priority'] = (int) $title_map[ $norm ];
				}
			}
		}

		return $items;
	}

	public static function sort_items( $items, $sort_by ) {
		switch ( $sort_by ) {
			case 'date_asc':
				usort(
					$items,
					function ( $a, $b ) {
						if ( '' === $a['start_date'] && '' === $b['start_date'] ) {
							return 0;
						}
						if ( '' === $a['start_date'] ) {
							return 1;
						}
						if ( '' === $b['start_date'] ) {
							return -1;
						}
						return strcmp( $a['start_date'], $b['start_date'] );
					}
				);
				break;

			case 'manual':
			case 'manual_priority':
				// عدد بزرگ‌تر = بالاتر؛ برای ردیف‌هایی که ادمین اولویتی ثبت نکرده
				// (همه صفر)، ترتیب تاریخ شروع (نزدیک‌ترین اول) ملاک می‌شود تا
				// خروجی بی‌نطم/تصادفی نباشد.
				usort(
					$items,
					function ( $a, $b ) {
						if ( $a['sort_priority'] !== $b['sort_priority'] ) {
							return $b['sort_priority'] <=> $a['sort_priority'];
						}
						if ( '' === $a['start_date'] && '' === $b['start_date'] ) {
							return 0;
						}
						if ( '' === $a['start_date'] ) {
							return 1;
						}
						if ( '' === $b['start_date'] ) {
							return -1;
						}
						return strcmp( $a['start_date'], $b['start_date'] );
					}
				);
				break;

			case 'date_desc':
			default:
				usort(
					$items,
					function ( $a, $b ) {
						return strcmp( $b['start_date'], $a['start_date'] );
					}
				);
				break;
		}
		return $items;
	}

	/**
	 * بخش ۳ سند: صفحه Upcoming Courses
	 * دوره‌هایی که تاریخ شروعشان در هفته پیش‌رو است؛ اگر کمتر از count بود،
	 * بر اساس اولویت دپارتمان‌ها (تنطیم‌شده در پنل ادمین) از دوره‌های بعدی تکمیل می‌شود.
	 */
	public static function get_upcoming( $args = array() ) {
		$defaults = array(
			'branch_key' => '',
			'term_key'   => '',
			'term_keys'  => array(),
			'count'      => 10,
		);
		$args  = wp_parse_args( $args, $defaults );
		$count = max( 1, (int) $args['count'] );

		$term_keys = ! empty( $args['term_keys'] ) ? (array) $args['term_keys'] : array_filter( array_map( 'trim', explode( ',', (string) $args['term_key'] ) ) );

		// دریافت کل کلاس‌های ترم/ترم‌ها (بدون محدودیت تاریخ) برای اعمال منطق محلی
		$all = self::get_classes_for_terms(
			$term_keys,
			array(
				'branch_key' => $args['branch_key'],
				'sort_by'    => 'date_asc',
			)
		);

		if ( ! $all['success'] ) {
			return $all;
		}

		$today_str  = RC_Jalali::today_str( '/' );
		$week_ahead = RC_Jalali::offset_str( 7, '/' );

		// فقط دوره‌های آینده (تاریخ شروع >= امروز)
		$future = self::filter_from_date( $all['items'], $today_str );
		$future = self::sort_items( $future, 'date_asc' );

		// گام ۱: دوره‌هایی که در «هفته پیش‌رو» هستند
		$this_week = array_values(
			array_filter(
				$future,
				function ( $item ) use ( $week_ahead ) {
					return $item['start_date'] <= $week_ahead;
				}
			)
		);

		$selected      = array_slice( $this_week, 0, $count );
		$selected_keys = wp_list_pluck( $selected, 'class_key' );

		if ( count( $selected ) < $count ) {
			// گام ۲: تکمیل از باقی دوره‌های آینده بر اساس اولویت دپارتمان
			$rank_map = self::get_department_rank_map();

			$remaining = array_values(
				array_filter(
					$future,
					function ( $item ) use ( $selected_keys ) {
						return ! in_array( $item['class_key'], $selected_keys, true );
					}
				)
			);

			usort(
				$remaining,
				function ( $a, $b ) {
					$rank_a = self::get_department_rank( $a['department'] );
					$rank_b = self::get_department_rank( $b['department'] );
					if ( $rank_a !== $rank_b ) {
						return $rank_a <=> $rank_b;
					}
					return strcmp( $a['start_date'], $b['start_date'] );
				}
			);

			$need     = $count - count( $selected );
			$selected = array_merge( $selected, array_slice( $remaining, 0, $need ) );
		}

		return array(
			'success' => true,
			'error'   => $all['error'],
			'items'   => $selected,
		);
	}

	/**
	 * خواندن اولویت دپارتمان‌ها از تنطیمات ادمین (هر خط = یک عنوان دپارتمان، به ترتیب اولویت)
	 */
	public static function get_department_priority() {
		$settings = self::settings();
		$raw      = isset( $settings['department_priority'] ) ? $settings['department_priority'] : '';
		$lines    = preg_split( '/\r\n|\r|\n/', $raw );
		$lines    = array_map( 'trim', (array) $lines );
		return array_values( array_filter( $lines ) );
	}

	/**
	 * نرمال‌سازی کلید نام دپارتمان برای تطبیق دقیق بین خروجی لن‌میس و تنظیمات ادمین:
	 * یکسان‌سازی ی/ک عربی، الف/آ، حذف نیم‌فاصله و فاصله‌ها، و حذف پیشوند اختیاری «دپارتمان».
	 */
	public static function normalize_department_key( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return '';
		}
		$name = RC_Jalali::to_latin_digits( $name );
		$name = str_replace(
			array( "\u{200C}", "\u{200F}", "\u{200E}", "\u{00A0}", "\u{064A}", "\u{0643}", "\u{0629}", "\u{0623}", "\u{0625}", "\u{0622}" ),
			array( ' ', '', '', ' ', 'ی', 'ک', 'ه', 'ا', 'ا', 'ا' ),
			$name
		);
		$name = preg_replace( '/^\s*دپارتمان\s+/u', '', $name );
		$name = preg_replace( '/[\s\-–—_،,]+/u', '', (string) $name );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $name, 'UTF-8' ) : strtolower( (string) $name );
	}

	/**
	 * نگاشت «عنوان نرمال‌شده دپارتمان → رتبه» (عدد کمتر = مهم‌تر)
	 */
	public static function get_department_rank_map() {
		if ( null === self::$rank_cache ) {
			$map = array();
			foreach ( self::get_department_priority() as $idx => $line ) {
				$norm = self::normalize_department_key( $line );
				if ( '' !== $norm && ! isset( $map[ $norm ] ) ) {
					$map[ $norm ] = $idx;
				}
			}
			self::$rank_cache = $map;
		}
		return self::$rank_cache;
	}

	/**
	 * دریافت رتبه یک دپارتمان بر اساس تنظیمات اولویت (عدد کمتر = اولویت بالاتر)
	 */
	public static function get_department_rank( $department_name ) {
		$map = self::get_department_rank_map();
		if ( empty( $map ) ) {
			return PHP_INT_MAX;
		}
		$norm = self::normalize_department_key( $department_name );
		if ( '' === $norm ) {
			return PHP_INT_MAX;
		}
		return isset( $map[ $norm ] ) ? $map[ $norm ] : PHP_INT_MAX;
	}

	/**
	 * ادغام چند مجموعه (چند course_key/level_key/term_key) در یک لیست واحد
	 *
	 * @param array $id_list آرایه‌ای از آرایه‌های args قابل قبول در get_classes()
	 */
	public static function get_classes_merged( $id_list, $shared_args = array() ) {
		$all_items = array();
		$has_error = false;
		$error_msg = '';
		$any_ok    = false;

		// حذف ترکیب‌های تکراری قبل از هر فراخوانی.
		// مثال واقعی: در حالت «بازه خودکار» با چند دپارتمان، همان ترکیب
		// ترم/دپارتمان می‌توانست چند بار در $id_list بیفتد و هر بار یک
		// درخواست کامل به لن‌میس بزند. حالا فقط یک‌بار خوانده می‌شود.
		$unique_args = array();
		foreach ( (array) $id_list as $single_args ) {
			$merged_args = wp_parse_args( (array) $single_args, $shared_args );
			ksort( $merged_args );
			$unique_args[ md5( wp_json_encode( $merged_args ) ) ] = $merged_args;
		}

		// سد محافظ: هر عضو این لیست یک درخواست HTTP جدا به لن‌میس است
		// (چون class_list در هر بار فقط یک termKey می‌پذیرد). بدون سقف،
		// یک شرت‌کد با ترم/دپارتمان زیاد می‌توانست ده‌ها درخواست همگام
		// روی رندر یک صفحه بار کند - دقیقاً منبع اصلی کندی.
		if ( count( $unique_args ) > self::MAX_MERGE_SOURCES ) {
			$unique_args = array_slice( $unique_args, 0, self::MAX_MERGE_SOURCES, true );
		}

		foreach ( $unique_args as $merged_args ) {
			$result = self::get_classes( $merged_args );

			if ( ! $result['success'] ) {
				$has_error = true;
				$error_msg = $result['error'];
				continue;
			}
			$any_ok    = true;
			$all_items = array_merge( $all_items, $result['items'] );
		}

		// حذف موارد تکراری (اگر یک کلاس در چند فراخوانی برگردد)
		// کلاسی که class_key ندارد نباید با بقیه کلاس‌های بی‌کلید روی یک
		// کلید خالی بیفتد و فقط آخرینش بماند.
		$unique = array();
		foreach ( $all_items as $i => $item ) {
			$dedupe_key            = '' !== (string) $item['class_key'] ? 'k:' . $item['class_key'] : 'i:' . $i;
			$unique[ $dedupe_key ] = $item;
		}

		$sort_by = isset( $shared_args['sort_by'] ) ? $shared_args['sort_by'] : 'date_desc';
		$items   = self::sort_items( array_values( $unique ), $sort_by );

		if ( ! empty( $shared_args['limit'] ) ) {
			$items = array_slice( $items, 0, (int) $shared_args['limit'] );
		}

		return array(
			'success' => $any_ok || ! $has_error,
			'error'   => $has_error ? $error_msg : null,
			'items'   => $items,
		);
	}
}
