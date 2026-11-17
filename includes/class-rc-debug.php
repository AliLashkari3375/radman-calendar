<?php
/**
 * Copyright (c) 2026 Ali Lashkari. All Rights Reserved. See LICENSE.
 *
 * شرت‌کد موقت دیباگ: [radman_debug_ids]
 *
 * هدف: نمایش key های شعب، ترم‌ها و درخت دپارتمان/دوره/سطح از API لن‌میس
 * تا ادمین بتونه مقادیر branch_key / term_key / level_key رو برای استفاده
 * در [radman_calendar] و [radman_course] کپی کنه.
 *
 * فقط برای کاربران دارای manage_options نمایش داده می‌شود (خروجی حاوی
 * اطلاعات داخلی سامانه است و نباید برای بازدیدکننده عمومی قابل مشاهده باشد).
 *
 * نکته: این شرت‌کد موقتی است و بعد از اتمام مرحله تست/راه‌اندازی
 * باید از صفحه برداشته شود.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RC_Debug {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'radman_debug_ids', array( $this, 'render' ) );
		// [radman_debug_class_list branch_key="" term_key=""]
		// شرت‌کد موقت دیباگ: تعداد کل رکورد دریافتی از class_list و اینکه آیا
		// این داده مستقیم و تازه از لن‌میس آمده یا از fallback (کش قدیمی) —
		// برای تشخیص اینکه آیا درخواست واقعی به لن‌میس (مثلاً به‌خاطر Timeout
		// یا محدودیت نرخ ۴۲۹) شکست می‌خورد و سایت مدام روی داده قدیمی می‌ماند.
		add_shortcode( 'radman_debug_class_list', array( $this, 'render_class_list_debug' ) );
	}

	public function render_class_list_debug( $atts ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '<p>این بخش فقط برای مدیر سایت قابل مشاهده است.</p>';
		}

		$atts = shortcode_atts( array( 'branch_key' => '', 'term_key' => '' ), $atts );

		$connector = RC_Lanmis_Connector::instance();
		$result    = $connector->get_class_list(
			array(
				'branchKey' => $atts['branch_key'],
				'termKey'   => $atts['term_key'],
			),
			true // همیشه تازه بگیر تا واقعیت فعلی API را ببینیم، نه کش
		);

		ob_start();
		echo '<div dir="rtl" style="font-family:monospace;background:#f6f6f6;padding:16px;border:1px solid #ddd;border-radius:8px;">';
		echo '<h3>دیباگ class_list</h3>';
		if ( ! $result['success'] ) {
			printf( '<p style="color:red;">خطا (و هیچ fallback ذخیره‌شده‌ای هم پیدا نشد): %s</p>', esc_html( $result['error'] ) );
		} else {
			printf( '<p><strong>تعداد کل رکورد دریافت‌شده:</strong> %d</p>', count( (array) $result['data'] ) );
			if ( ! empty( $result['error'] ) ) {
				printf(
					'<p style="color:#b8860b;"><strong>⚠ توجه:</strong> این عدد از fallback (کش قدیمی) آمده، نه از یک درخواست تازه موفق؛ یعنی درخواست تازه به لن‌میس با این خطا مواجه شده: %s</p>',
					esc_html( $result['error'] )
				);
			} else {
				echo '<p style="color:green;">✔ این داده مستقیم و تازه از لن‌میس دریافت شده (نه fallback).</p>';
			}
			echo '<p>این عدد را با تعداد واقعی کلاس‌های همان شعبه/ترم در پنل لن‌میس مقایسه کنید.</p>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '<p>این بخش فقط برای مدیر سایت قابل مشاهده است.</p>';
		}

		$connector = RC_Lanmis_Connector::instance();

		ob_start();
		echo '<div dir="rtl" style="font-family:monospace;background:#f6f6f6;padding:16px;border:1px solid #ddd;border-radius:8px;">';

		// ------------------ شعب ------------------
		echo '<h3>شعب (branch_key)</h3>';
		$branches = $connector->get_branches();
		$this->render_result_or_error( $branches );
		if ( $branches['success'] ) {
			echo '<ul>';
			foreach ( (array) $branches['data'] as $b ) {
				printf(
					'<li><strong>%s</strong> — key: <code>%s</code> — شهر: %s</li>',
					esc_html( $b['title'] ?? '' ),
					esc_html( $b['key'] ?? '' ),
					esc_html( $b['city'] ?? '' )
				);
			}
			echo '</ul>';
		}

		// ------------------ ترم‌ها ------------------
		echo '<h3>ترم‌ها (term_key)</h3>';
		$terms = $connector->get_terms();
		$this->render_result_or_error( $terms );
		if ( $terms['success'] ) {
			echo '<ul>';
			foreach ( (array) $terms['data'] as $t ) {
				printf(
					'<li><strong>%s</strong> — key: <code>%s</code> %s</li>',
					esc_html( $t['title'] ?? '' ),
					esc_html( $t['key'] ?? '' ),
					! empty( $t['is_current_term'] ) ? '⭐ (ترم جاری)' : ''
				);
			}
			echo '</ul>';
		}

		// ------------------ دپارتمان‌ها / دوره‌ها / سطوح ------------------
		echo '<h3>دپارتمان‌ها، دوره‌ها و سطوح (level_key)</h3>';
		$dep_levels = $connector->get_dep_levels();
		$this->render_result_or_error( $dep_levels );
		if ( $dep_levels['success'] ) {
			echo '<ul>';
			foreach ( (array) $dep_levels['data'] as $dep ) {
				printf( '<li><strong>%s</strong> (dep_key: <code>%s</code>)<ul>', esc_html( $dep['dep_title'] ?? '' ), esc_html( $dep['dep_key'] ?? '' ) );
				foreach ( (array) ( $dep['courses'] ?? array() ) as $course ) {
					printf( '<li>%s (course_key: <code>%s</code>)<ul>', esc_html( $course['course_title'] ?? '' ), esc_html( $course['course_key'] ?? '' ) );
					foreach ( (array) ( $course['levels'] ?? array() ) as $level ) {
						printf(
							'<li>%s — level_key: <code>%s</code></li>',
							esc_html( $level['level_title'] ?? '' ),
							esc_html( $level['level_key'] ?? '' )
						);
					}
					echo '</ul></li>';
				}
				echo '</ul></li>';
			}
			echo '</ul>';
		}

		echo '</div>';
		return ob_get_clean();
	}

	private function render_result_or_error( $result ) {
		if ( ! $result['success'] ) {
			printf( '<p style="color:red;">خطا: %s</p>', esc_html( $result['error'] ) );
		} elseif ( ! empty( $result['error'] ) ) {
			printf( '<p style="color:#b8860b;">هشدار (داده از کش قدیمی است): %s</p>', esc_html( $result['error'] ) );
		}
	}
}
