<?php
/**
 * Copyright (c) 2026 Ali Lashkari. All Rights Reserved. See LICENSE.
 *
 * زیرصفحه پیشخوان: «شرت‌کد ساز»
 *
 * به‌جای اینکه ادمین مجبور باشد key های خام (branch_key/term_key/level_key) را
 * از صفحه دیباگ کپی کند، این صفحه همان داده را یک‌بار از API می‌خواند و با
 * دراپ‌داون‌های آبشاری (شعبه → ترم → دپارتمان → دوره → سطح) شرت‌کد نهایی را
 * به‌صورت زنده (سمت کلاینت، بدون رفرش) می‌سازد و آماده کپی می‌کند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RC_Admin_Builder {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 25 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_rc_preview_shortcode', array( $this, 'ajax_preview' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'radman-calendar',
			'شرت‌کد ساز',
			'شرت‌کد ساز',
			'manage_options',
			'radman-calendar-builder',
			array( $this, 'render_page' )
		);
	}

	public function enqueue( $hook ) {
		if ( strpos( $hook, 'radman-calendar-builder' ) === false ) {
			return;
		}

		// خروجی عکس (A4 و استوری ۹:۱۶) فقط با کلیک ادمین ساخته می‌شود.
		// به همین دلیل html2canvas (~۱۹۴KB)، موتور خروجی و استایل‌شیت فونت اصلاً
		// همراه صفحه لود نمی‌شوند؛ آدرسشان در rcBuilderData.lazy می‌آید و
		// جاوااسکریپت اولین بار که عکسی خواسته شد، همان لحظه لودشان می‌کند.
		// (استایل‌شیت فونت را هم خود موتور موقع ساخت عکس تزریق می‌کند.)
		$builder_settings = (array) get_option( 'rc_settings', array() );

		$image_export_path  = RC_PATH . 'assets/js/rc-image-export.js';
		$admin_builder_path = RC_PATH . 'assets/js/admin-builder.js';
		$export_css_path    = RC_PATH . 'assets/css/export.css';
		$image_export_ver   = file_exists( $image_export_path ) ? filemtime( $image_export_path ) : RC_VERSION;
		$admin_builder_ver  = file_exists( $admin_builder_path ) ? filemtime( $admin_builder_path ) : RC_VERSION;
		$export_css_ver     = file_exists( $export_css_path ) ? filemtime( $export_css_path ) : RC_VERSION;

		wp_enqueue_script( 'rc-admin-builder', RC_URL . 'assets/js/admin-builder.js', array(), $admin_builder_ver, true );

		$connector  = RC_Lanmis_Connector::instance();
		$branches   = $connector->get_branches();
		$terms      = $connector->get_terms();
		$dep_levels = $connector->get_dep_levels();

		wp_localize_script(
			'rc-admin-builder',
			'rcBuilderData',
			array(
				'branches'   => $branches['success'] ? array_values( (array) $branches['data'] ) : array(),
				'terms'      => $terms['success'] ? array_values( (array) $terms['data'] ) : array(),
				'depTree'    => $dep_levels['success'] ? array_values( (array) $dep_levels['data'] ) : array(),
				'errors'     => array(
					'branches' => $branches['success'] ? null : $branches['error'],
					'terms'    => $terms['success'] ? null : $terms['error'],
					'depTree'  => $dep_levels['success'] ? null : $dep_levels['error'],
				),
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'rc_builder_preview_nonce' ),
				// آدرس اسکریپت‌هایی که فقط هنگام ساخت عکس (on-demand) لود می‌شوند
				'lazy'       => array(
					'html2canvas' => RC_URL . 'assets/js/vendor/html2canvas.min.js',
					'export'      => add_query_arg( 'ver', $image_export_ver, RC_URL . 'assets/js/rc-image-export.js' ),
				),
				// آدرس استایل قالب خروجی عکس (موتور خروجی قبل از رندر تزریقش می‌کند).
				'exportCss'  => add_query_arg( 'ver', $export_css_ver, RC_URL . 'assets/css/export.css' ),
				// CSS داینامیک (فونت/رنگ انتخابی ادمین) تا پیش‌نمایش عکس دقیقاً مانند خروجی نهایی باشد
				'dynamicCss' => class_exists( 'RC_Shortcodes' ) ? RC_Shortcodes::dynamic_css() : '',
				// داده‌های فونت خروجی عکس: موتور خروجی با این‌ها قبل از capture
				// فونت را قطعی لود می‌کند (فایل woff2/woff یا استایل‌شیت).
				'fontFamily'     => isset( $builder_settings['font_family'] ) ? sanitize_text_field( $builder_settings['font_family'] ) : '',
				'fontRegularUrl' => isset( $builder_settings['font_regular_url'] ) ? esc_url_raw( $builder_settings['font_regular_url'], array( 'http', 'https' ) ) : '',
				'fontBoldUrl'    => isset( $builder_settings['font_bold_url'] ) ? esc_url_raw( $builder_settings['font_bold_url'], array( 'http', 'https' ) ) : '',
				'fontCssUrl'     => isset( $builder_settings['font_css_url'] ) ? esc_url_raw( $builder_settings['font_css_url'], array( 'http', 'https' ) ) : '',
			)
		);
	}

	/**
	 * پیش‌نمایش زنده: شرت‌کد ساخته‌شده در فرم را واقعاً اجرا می‌کند و HTML خروجی را برمی‌گرداند.
	 * فقط شرت‌کدهای خود این پلاگین (radman_calendar/radman_course/radman_upcoming) پذیرفته می‌شوند
	 * تا این endpoint نتواند برای اجرای شرت‌کد دلخواه/امنیتی-حساس دیگری سوءاستفاده شود.
	 */
	public function ajax_preview() {
		check_ajax_referer( 'rc_builder_preview_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
		}

		$raw = isset( $_POST['shortcode'] ) ? wp_unslash( $_POST['shortcode'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$raw = is_string( $raw ) ? trim( $raw ) : '';

		if ( '' === $raw || strlen( $raw ) > 2000 ) {
			wp_send_json_error( array( 'message' => 'شرت‌کد نامعتبر' ), 400 );
		}

		/**
		 * اعتبارسنجی سخت‌گیرانه.
		 *
		 * روش قبلی فقط چک می‌کرد که رشته با یکی از سه تگ مجاز *شروع* شود.
		 * یعنی مقداری مثل `[radman_calendar][any_other_shortcode ...]` هم
		 * از فیلتر رد می‌شد و do_shortcode() هر شرت‌کد ثبت‌شده دیگری در
		 * سایت (قالب، فرم‌ساز، فروشگاه...) را هم اجرا می‌کرد. حالا:
		 *  ۱) فقط *یک* شرت‌کد و فقط از سه تگ خودمان پذیرفته می‌شود
		 *  ۲) هیچ چیزی بعد از براکت بسته مجاز نیست
		 *  ۳) شرت‌کد از صفر و از دل پارامترهای پاک‌سازی‌شده بازسازی می‌شود،
		 *     پس ورودی خام هرگز به do_shortcode() نمی‌رسد.
		 */
		if ( ! preg_match( '/^\[\s*(radman_calendar|radman_course|radman_upcoming)([^\]\[]*)\]$/', $raw, $m ) ) {
			wp_send_json_error( array( 'message' => 'شرت‌کد نامعتبر' ), 400 );
		}

		$tag       = $m[1];
		$atts      = shortcode_parse_atts( trim( $m[2] ) );
		$rebuilt   = '[' . $tag;
		$att_count = 0;

		if ( is_array( $atts ) ) {
			foreach ( $atts as $key => $value ) {
				if ( ! is_string( $key ) || ! preg_match( '/^[a-z][a-z0-9_]{0,30}$/', $key ) ) {
					continue;
				}
				if ( ++$att_count > 20 ) {
					break;
				}
				$clean    = sanitize_text_field( (string) $value );
				$clean    = str_replace( array( '[', ']', '"' ), '', $clean );
				$rebuilt .= ' ' . $key . '="' . $clean . '"';
			}
		}

		$rebuilt .= ']';

		$html = do_shortcode( $rebuilt );
		wp_send_json_success( array( 'html' => $html, 'shortcode' => $rebuilt ) );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap" dir="rtl">
			<h1>شرت‌کد ساز تقویم آموزشی</h1>
			<p class="description">شعبه، ترم و (در صورت نیاز) دوره/سطح را انتخاب کنید؛ شرت‌کد آماده در پایین ساخته می‌شود.</p>

			<p class="description">هر تعداد شرت‌کد هم‌نوع در یک صفحه (مثلاً یک تقویم برای هر دپارتمان) خودبه‌خود در یک جدول واحد با جستجوی مشترک ادغام می‌شوند. برای خاموش کردن ادغام یک شرت‌کد، در همان شرت‌کد <code>merge="0"</code> بگذارید؛ برای چند گروه جداگانه، به هر گروه یک <code>merge_group="نام-دلخواه"</code> بدهید.</p>

			<div id="rc-builder-errors"></div>

			<table class="form-table" id="rc-builder-form">
				<tr>
					<th scope="row"><label for="rc-b-type">نوع شرت‌کد</label></th>
					<td>
						<select id="rc-b-type">
							<option value="radman_calendar">تقویم اصلی (همه دوره‌ها)</option>
							<option value="radman_course">سینگل دوره / چند سطح خاص</option>
							<option value="radman_upcoming">دوره‌های پیش‌رو (Upcoming)</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rc-b-branch">شعبه</label></th>
					<td><select id="rc-b-branch"><option value="">در حال بارگذاری...</option></select></td>
				</tr>
				<tr>
					<th scope="row"><label for="rc-b-term">ترم</label></th>
					<td><select id="rc-b-term"><option value="">در حال بارگذاری...</option></select></td>
				</tr>
				<tr>
					<th scope="row"><label for="rc-b-auto-term">آپدیت خودکار ترم</label></th>
					<td>
						<label>
							<input type="checkbox" id="rc-b-auto-term">
							فعال باشد (هر ماه خودش به ترم جاری می‌رود، نیازی به تغییر دستی شرت‌کد نیست)
						</label>
						<p class="description">
							پیش‌فرض خاموش است. وقتی فعال باشد، ترمی که بالا انتخاب کردید فقط برای
							پیش‌نمایش استفاده می‌شود؛ در شرت‌کد نهایی به‌جایش <code>auto_term="1"</code>
							گذاشته می‌شود و همیشه ترمی که خود لن‌میس «ترم جاری» اعلام کرده استفاده خواهد شد.
						</p>
					</td>
				</tr>
				<tr class="rc-b-row-dep">
					<th scope="row"><label for="rc-b-dep">دپارتمان</label></th>
					<td><select id="rc-b-dep"><option value="">— انتخاب کنید —</option></select></td>
				</tr>
				<tr class="rc-b-row-course">
					<th scope="row"><label for="rc-b-course">دوره</label></th>
					<td><select id="rc-b-course"><option value="">— ابتدا دپارتمان را انتخاب کنید —</option></select></td>
				</tr>
				<tr class="rc-b-row-levels">
					<th scope="row">سطح‌ها</th>
					<td>
						<div id="rc-b-levels" style="max-height:220px;overflow:auto;border:1px solid #ddd;padding:8px;background:#fff;">
							<em>ابتدا دوره را انتخاب کنید</em>
						</div>
						<p class="description">می‌توانید چند سطح را هم‌زمان انتخاب کنید تا در یک جدول واحد ادغام شوند.</p>
					</td>
				</tr>
				<tr class="rc-b-row-count">
					<th scope="row"><label for="rc-b-count">تعداد (count)</label></th>
					<td><input type="number" id="rc-b-count" value="10" min="1" style="width:100px;"></td>
				</tr>
				<tr class="rc-b-row-limit">
					<th scope="row"><label for="rc-b-limit">محدودیت تعداد (limit)</label></th>
					<td><input type="number" id="rc-b-limit" value="0" min="0" style="width:100px;"> <span class="description">۰ = بدون محدودیت</span></td>
				</tr>
				<tr class="rc-b-row-dates">
					<th scope="row">حالت بازه تاریخ</th>
					<td>
						<select id="rc-b-date-mode">
							<option value="">دستی (یک بازه ثابت انتخاب می‌کنم)</option>
							<option value="auto">خودکار (از چند روز قبل تا انتها - مستقل از ترم)</option>
						</select>
						<select id="rc-b-auto-days" style="margin-inline-start:8px;">
							<option value="7">از ۷ روز قبل</option>
							<option value="30" selected>از ۳۰ روز قبل</option>
						</select>
						<select id="rc-b-months-ahead" style="margin-inline-start:8px;">
							<option value="1">تا ۱ ماه آینده</option>
							<option value="2">تا ۲ ماه آینده</option>
							<option value="3" selected>تا ۳ ماه آینده</option>
							<option value="6">تا ۶ ماه آینده</option>
							<option value="0">بدون سقف (همه ترم‌های آینده)</option>
						</select>
						<p class="description">در حالت خودکار، دوره‌هایی که بیش از این تعداد روز از شروع‌شان گذشته، یا شروع‌شان بعد از سقف ماه‌های آینده است، نمایش داده نمی‌شوند. مهم: در این حالت خروجی دیگر به یک ترم محدود نیست؛ همه ترم‌هایی که در این بازه می‌افتند (مثلاً مهر + آبان + آذر + دی) در یک جدول واحد خوانده می‌شوند و بنر بالای جدول هم همه ماه‌ها را می‌نویسد.</p>
					</td>
				</tr>
				<tr class="rc-b-row-dates rc-b-row-dates-manual">
					<th scope="row">بازه تاریخ (شمسی)</th>
					<td>
						از: <input type="text" id="rc-b-from" placeholder="1405-07-01" style="width:130px;" dir="ltr">
						تا: <input type="text" id="rc-b-to" placeholder="1405-08-30" style="width:130px;" dir="ltr">
						<p class="description">فرمت شمسی سال-ماه-روز؛ «/» و ارقام فارسی هم قبول است. هر کدام را خالی بگذارید، آن سمت باز است. بازه می‌تواند چند ماه (چند ترم) را بگیرد؛ همه ترم‌های داخل بازه خودکار خوانده می‌شوند.</p>
					</td>
				</tr>
				<tr class="rc-b-row-sort">
					<th scope="row"><label for="rc-b-sort">ترتیب</label></th>
					<td>
						<select id="rc-b-sort">
							<option value="">پیش‌فرض تنظیمات پلاگین</option>
							<option value="date_desc">جدیدترین به قدیمی‌ترین</option>
							<option value="date_asc">قدیمی‌ترین به جدیدترین</option>
							<option value="manual_priority">اولویت دستی ادمین</option>
						</select>
					</td>
				</tr>
			</table>

			<h2>شرت‌کد نهایی</h2>
			<p>
				<textarea id="rc-b-output" readonly rows="2" style="width:100%;max-width:800px;font-family:monospace;font-size:14px;padding:10px;" dir="ltr"></textarea>
			</p>
			<p>
				<button type="button" class="button button-primary" id="rc-b-copy">کپی شرت‌کد</button>
				<span id="rc-b-copy-status" style="margin-inline-start:8px;color:green;"></span>
			</p>

			<h2>پیش‌نمایش زنده</h2>
			<p class="description">این پیش‌نمایش، خروجی واقعی همین شرت‌کد را در همین صفحه (با AJAX) نشان می‌دهد.</p>
			<div id="rc-b-preview-cols" style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
				<div style="flex:1 1 380px;min-width:280px;">
					<h3 style="margin:0 0 6px;font-size:13px;color:#555;">پیش‌نمایش (بدون استایل)</h3>
					<div id="rc-b-preview" style="border:1px dashed #ccc;padding:12px;background:#fafafa;overflow:auto;">
						<em>پس از انتخاب شعبه و ترم، پیش‌نمایش این‌جا نمایش داده می‌شود.</em>
					</div>
				</div>
				<div style="flex:0 0 260px;min-width:220px;">
					<h3 style="margin:0 0 6px;font-size:13px;color:#555;">خروجی عکس (فقط با کلیک ساخته می‌شود)</h3>
					<div id="rc-b-preview-image" style="border:1px dashed #ccc;padding:12px;background:#fafafa;min-height:120px;">
						<em>—</em>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
