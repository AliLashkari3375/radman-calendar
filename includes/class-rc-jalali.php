<?php
/**
 * Copyright (c) 2026 Ali Lashkari. All Rights Reserved. See LICENSE.
 *
 * تبدیل تاریخ میلادی به شمسی (جلالی) - پیاده‌سازی خالص PHP بدون وابستگی خارجی.
 * لن‌میس تاریخ‌ها را به فرمت شمسی برمی‌گرداند و پارامترهای fromDate/toDate هم
 * شمسی می‌خواهد؛ برای محاسبه «هفته پیش‌رو» (صفحه Upcoming Courses) به این
 * تبدیل نیاز داریم چون سرور و PHP بومی فقط تاریخ میلادی می‌دهند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RC_Jalali {

	/**
	 * تبدیل سال/ماه/روز میلادی به آرایه [jy, jm, jd] شمسی
	 * (الگوریتم استاندارد و متن‌باز رایج تبدیل تقویم، مبتنی بر محاسبات عددی روز ژولین)
	 */
	public static function to_jalali( $gy, $gm, $gd ) {
		$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );

		$gy2   = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
		$days  = 355666 + ( 365 * $gy ) + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 )
				+ intdiv( $gy2 + 399, 400 ) + $gd + $g_d_m[ $gm - 1 ];

		$jy = -1595 + ( 33 * intdiv( $days, 12053 ) );
		$days %= 12053;
		$jy += 4 * intdiv( $days, 1461 );
		$days %= 1461;

		if ( $days > 365 ) {
			$jy += intdiv( $days - 1, 365 );
			$days = ( $days - 1 ) % 365;
		}

		if ( $days < 186 ) {
			$jm = 1 + intdiv( $days, 31 );
			$jd = 1 + ( $days % 31 );
		} else {
			$jm = 7 + intdiv( $days - 186, 30 );
			$jd = 1 + ( ( $days - 186 ) % 30 );
		}

		return array( $jy, $jm, $jd );
	}

	/**
	 * تاریخ شمسی امروز (بر اساس timezone وردپرس) به‌صورت رشته
	 *
	 * @param string $sep جداکننده - لن‌میس برای فیلتر fromDate/toDate از "-" استفاده می‌کند
	 */
	public static function today_str( $sep = '-' ) {
		return self::offset_str( 0, $sep );
	}

	/**
	 * تاریخ شمسی امروز + N روز
	 */
	private static $offset_cache = array();

	public static function offset_str( $days_offset, $sep = '-' ) {
		$days_offset = (int) $days_offset;
		$sep         = (string) $sep;
		$cache_key   = $days_offset . '|' . $sep;
		if ( isset( self::$offset_cache[ $cache_key ] ) ) {
			return self::$offset_cache[ $cache_key ];
		}

		try {
			$dt = new DateTime( 'now', wp_timezone() );
		} catch ( Exception $e ) {
			$dt = new DateTime( 'now' );
		}
		if ( 0 !== $days_offset ) {
			$dt->modify( sprintf( '%+d days', $days_offset ) );
		}

		list( $jy, $jm, $jd ) = self::to_jalali( (int) $dt->format( 'Y' ), (int) $dt->format( 'n' ), (int) $dt->format( 'j' ) );

		self::$offset_cache[ $cache_key ] = sprintf( '%04d%s%02d%s%02d', $jy, $sep, $jm, $sep, $jd );
		return self::$offset_cache[ $cache_key ];
	}

	/**
	 * تاریخ شمسی «امروز + N ماه شمسی» (نه N×30 روز).
	 *
	 * روز ماه اگر در ماه مقصد وجود نداشته باشد به آخرین روز همان ماه
	 * محدود می‌شود (۳۱ شهریور + ۱ ماه = ۳۰ مهر). اسفند همیشه ۳۰ در نظر
	 * گرفته می‌شود؛ چون این خروجی فقط سقف مقایسه رشته‌ای است، «۱۲/۳۰» در
	 * سال غیرکبیسه بی‌ضرر است (هیچ کلاسی در آن تاریخ وجود ندارد).
	 *
	 * @param int    $months_offset
	 * @param string $sep
	 */
	public static function add_months_str( $months_offset, $sep = '-' ) {
		list( $jy, $jm, $jd ) = array_map( 'intval', explode( '-', self::offset_str( 0, '-' ) ) );

		$index = ( $jy * 12 ) + ( $jm - 1 ) + (int) $months_offset;
		$jy    = intdiv( $index, 12 );
		$jm    = ( $index % 12 ) + 1;

		$max_day = $jm <= 6 ? 31 : 30;
		if ( $jd > $max_day ) {
			$jd = $max_day;
		}

		return sprintf( '%04d%s%02d%s%02d', $jy, $sep, $jm, $sep, $jd );
	}

	/** نام فارسی ماه‌های شمسی بر اساس شماره (۱ تا ۱۲) */
	const MONTH_NAMES = array(
		1  => 'فروردین',
		2  => 'اردیبهشت',
		3  => 'خرداد',
		4  => 'تیر',
		5  => 'مرداد',
		6  => 'شهریور',
		7  => 'مهر',
		8  => 'آبان',
		9  => 'آذر',
		10 => 'دی',
		11 => 'بهمن',
		12 => 'اسفند',
	);

	public static function month_name( $month_number ) {
		$month_number = (int) $month_number;
		return isset( self::MONTH_NAMES[ $month_number ] ) ? self::MONTH_NAMES[ $month_number ] : '';
	}

	/**
	 * تبدیل رقم‌های لاتین به فارسی (برای نمایش تاریخ به شکل «۱۹ مرداد ماه»)
	 */
	public static function to_persian_digits( $str ) {
		$latin   = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
		$persian = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
		return str_replace( $latin, $persian, (string) $str );
	}

	/**
	 * تبدیل تاریخ شمسی خام "1405/05/19" به فرمت خوانا «۱۹ مرداد ماه»
	 * (برای نمایش در بنر خروجی عکس - نه جدول تعاملی که تاریخ خام را نشان می‌دهد)
	 */
	public static function format_readable( $raw_date ) {
		$raw_date = (string) $raw_date;
		if ( '' === $raw_date || ! preg_match( '#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $raw_date, $m ) ) {
			return $raw_date;
		}
		$month_name = self::month_name( (int) $m[2] );
		if ( '' === $month_name ) {
			return $raw_date;
		}
		return self::to_persian_digits( (int) $m[3] ) . ' ' . $month_name . ' ماه';
	}

	/**
	 * تبدیل ارقام فارسی/عربی به لاتین (داده لن‌میس همیشه یکدست نیست)
	 */
	public static function to_latin_digits( $str ) {
		$fa = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
		$ar = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
		$en = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
		return str_replace( array_merge( $fa, $ar ), array_merge( $en, $en ), (string) $str );
	}

	/**
	 * شماره ماه شمسی را از دل عنوان ترم لن‌میس درمی‌آورد
	 * (مانند «مرداد»، «مرداد ماه»، «مرداد ۱۴۰۵»، «ترم شهریور»).
	 * خروجی: ۱..۱۲ یا ۰ اگر قابل تشخیص نبود.
	 */
	public static function month_number_from_title( $title ) {
		$title = str_replace( array( "\u{200C}", "\u{064A}", "\u{0643}" ), array( ' ', 'ی', 'ک' ), (string) $title );

		// تطبیق «کلمه کامل»، نه زیررشته. قبلاً mb_strpos روی «دی» با هر عنوانی
		// که کلمه‌ای مثل «جدید»، «ورودی» یا «بندی» داشت مطابقت می‌کرد و ترم
		// اشتباهاً «دی» تشخیص داده می‌شد؛ در نتیجه در پنجره زمانی خودکار یا
		// جا می‌ماند یا جای اشتباه می‌نشست. «ماه» چسبیده (مثل «دیماه») مجاز است.
		foreach ( self::MONTH_NAMES as $num => $name ) {
			$pattern = '/(?<!\p{L})' . preg_quote( $name, '/' ) . '(?:ماه)?(?!\p{L})/u';
			if ( '' !== $name && preg_match( $pattern, $title ) ) {
				return (int) $num;
			}
		}

		// برخی نگارش‌های رایج دیگر
		$aliases = array(
			'اردیبشت'  => 2,
			'مردادماه' => 5,
			'شهریورماه' => 6,
		);
		foreach ( $aliases as $needle => $num ) {
			if ( false !== mb_strpos( $title, $needle ) ) {
				return (int) $num;
			}
		}

		return 0;
	}

	/**
	 * ایندکس خطی سال/ماه (برای مقایسه ساده دو ماه شمسی)
	 */
	public static function ym_index( $year, $month ) {
		$year  = (int) self::to_latin_digits( $year );
		$month = (int) self::to_latin_digits( $month );
		if ( $year < 1 || $month < 1 ) {
			return 0;
		}
		return ( $year * 12 ) + $month;
	}

	/**
	 * سال/ماه شمسی مربوط به «امروز + N روز»
	 *
	 * @return array{0:int,1:int} [سال، ماه]
	 */
	public static function offset_ym( $days_offset = 0 ) {
		$str = self::offset_str( $days_offset, '-' );
		$p   = explode( '-', $str );
		return array( (int) $p[0], (int) $p[1] );
	}

	/**
	 * اعتبارسنجی و یکدست‌سازی تاریخ شمسی که «ادمین دستی» در شرت‌کد نوشته.
	 *
	 * ارقام فارسی/عربی، جداکننده «-» یا «/» یا «.»، و نبود صفر ابتدایی
	 * ("1405/7/1") همه پذیرفته می‌شوند. خروجی همیشه YYYY/MM/DD لاتین است،
	 * یا رشته خالی اگر تاریخ معتبر نباشد (ماه ۱..۱۲، روز ۱..۳۱؛ روز ۳۱ در
	 * ماه‌های ۳۰ روزه به ۳۰ محدود می‌شود). تاریخ نامعتبر عمداً خالی برمی‌گردد تا به‌جای
	 * یک فیلتر غلط/خطای API، آن سمت بازه «باز» در نظر گرفته شود.
	 */
	public static function normalize_input_date( $raw ) {
		$raw = trim( self::to_latin_digits( (string) $raw ) );
		if ( '' === $raw ) {
			return '';
		}
		$raw = str_replace( array( '-', '.', '\\' ), '/', $raw );
		if ( ! preg_match( '#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $raw, $m ) ) {
			return '';
		}
		$y = (int) $m[1];
		$mo = (int) $m[2];
		$d = (int) $m[3];
		if ( $y < 1300 || $y > 1500 || $mo < 1 || $mo > 12 || $d < 1 ) {
			return '';
		}
		// «۳۱ مهر» (مهر ۳۰ روزه است) = آخر مهر؛ رد کردنش بی‌صدا یک سر بازه را باز می‌کرد
		$max_day = $mo <= 6 ? 31 : 30;
		if ( $d > $max_day ) {
			if ( $d > 31 ) {
				return '';
			}
			$d = $max_day;
		}
		return sprintf( '%04d/%02d/%02d', $y, $mo, $d );
	}

	/**
	 * تاریخ شمسی را به فرمت مقایسه‌پذیر یکدست می‌کند (YYYY/MM/DD با ارقام لاتین)
	 * تا مقایسه رشته‌ای تاریخ‌ها همیشه درست باشد (فارسی/لاتین، با یا بدون صفر ابتدایی، "-" یا "/").
	 */
	public static function normalize_date( $raw ) {
		$raw = trim( self::to_latin_digits( $raw ) );
		if ( '' === $raw ) {
			return '';
		}
		$raw = str_replace( array( '-', '.' ), '/', $raw );
		if ( ! preg_match( '#^(\d{4})/(\d{1,2})/(\d{1,2})#', $raw, $m ) ) {
			return $raw;
		}
		return sprintf( '%04d/%02d/%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
	}
}
