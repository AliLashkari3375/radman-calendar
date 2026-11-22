<?php
/**
 * Copyright (c) 2026 Ali Lashkari. All Rights Reserved. See LICENSE.
 *
 * زیرصفحه پیشخوان: «توضیحات و لینک‌ها»
 *
 * به ادمین اجازه می‌دهد برای هر کلاس (بر اساس class_key از لن‌میس)
 * توضیح دستی و لینک اختصاصی ثبت کند. ذخیره‌سازی به‌صورت AJAX و تکی
 * برای هر ردیف انجام می‌شود تا با فهرست‌های بلند هم مشکلی نداشته باشد.
 *
 * این داده‌ها در جدول RC_Manual_Data ذخیره می‌شوند و طبق طراحی پلاگین،
 * با Sync مجدد از لن‌میس از بین نمی‌روند (چون کلید اتصال class_key است، نه رکورد لن‌میس).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RC_Admin_Manual {

	private static $instance = null;

	/** تعداد ردیف پیش‌فرض در هر صفحه (نسخه ۱.۷.۳) */
	const DEFAULT_PER_PAGE = 50;

	/** گزینه‌های مجاز «تعداد در هر صفحه» (0 = همه) */
	public static function per_page_options() {
		return array( 25, 50, 100, 200, 0 );
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_rc_save_manual_data', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_rc_delete_manual_data', array( $this, 'ajax_delete' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'radman-calendar',
			'توضیحات و لینک‌ها',
			'توضیحات و لینک‌ها',
			'manage_options',
			'radman-calendar-manual',
			array( $this, 'render_page' )
		);
	}

	public function enqueue( $hook ) {
		if ( strpos( $hook, 'radman-calendar-manual' ) === false ) {
			return;
		}
		$script_path = RC_PATH . 'assets/js/admin-manual.js';
		$script_ver  = file_exists( $script_path ) ? filemtime( $script_path ) : RC_VERSION;

		wp_enqueue_script( 'rc-admin-manual', RC_URL . 'assets/js/admin-manual.js', array( 'jquery' ), $script_ver, true );
		wp_localize_script(
			'rc-admin-manual',
			'rcAdminManual',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'rc_manual_data_nonce' ),
			)
		);
	}

	/**
	 * پردازش AJAX ذخیره یک ردیف
	 */
	public function ajax_save() {
		check_ajax_referer( 'rc_manual_data_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
		}

		$class_key   = isset( $_POST['class_key'] ) ? sanitize_text_field( wp_unslash( $_POST['class_key'] ) ) : '';
		$description = isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '';
		$link        = isset( $_POST['link'] ) ? esc_url_raw( wp_unslash( $_POST['link'] ), array( 'http', 'https' ) ) : '';
		$priority    = isset( $_POST['priority'] ) ? (int) RC_Jalali::to_latin_digits( wp_unslash( $_POST['priority'] ) ) : 0;
		$title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';

		if ( empty( $class_key ) ) {
			wp_send_json_error( array( 'message' => 'class_key نامعتبر است' ), 400 );
		}

		$ok = RC_Manual_Data::upsert( $class_key, $description, $link, $priority, $title );

		if ( false === $ok ) {
			wp_send_json_error( array( 'message' => 'خطا در ذخیره‌سازی دیتابیس' ), 500 );
		}

		$display_title = isset( $_POST['display_title'] ) ? sanitize_text_field( wp_unslash( $_POST['display_title'] ) ) : '';
		$effective_title = '' !== $title ? $title : $display_title;
		if ( '' !== $effective_title ) {
			RC_Manual_Data::set_title_priority( $effective_title, $priority );
		}

		// بدون این، جدول‌های ادغام‌شده سایت تا پایان TTL توضیح/لینک قبلی را نشان می‌دادند
		if ( class_exists( 'RC_Shortcodes' ) ) {
			RC_Shortcodes::purge_merge_cache();
		}

		wp_send_json_success( array( 'message' => 'ذخیره شد' ) );
	}

	/**
	 * پردازش AJAX حذف توضیحات/لینک دستی یک ردیف (بازگشت به حالت خالی/پیش‌فرض)
	 */
	public function ajax_delete() {
		check_ajax_referer( 'rc_manual_data_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
		}

		$class_key = isset( $_POST['class_key'] ) ? sanitize_text_field( wp_unslash( $_POST['class_key'] ) ) : '';
		if ( empty( $class_key ) ) {
			wp_send_json_error( array( 'message' => 'class_key نامعتبر است' ), 400 );
		}

		RC_Manual_Data::delete( $class_key );
		if ( class_exists( 'RC_Shortcodes' ) ) {
			RC_Shortcodes::purge_merge_cache();
		}
		wp_send_json_success( array( 'message' => 'پاک شد' ) );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$connector  = RC_Lanmis_Connector::instance();
		$branch_key = isset( $_GET['branch_key'] ) ? sanitize_text_field( wp_unslash( $_GET['branch_key'] ) ) : '';
		$term_key   = isset( $_GET['term_key'] ) ? sanitize_text_field( wp_unslash( $_GET['term_key'] ) ) : '';
		$search     = isset( $_GET['rc_s'] ) ? sanitize_text_field( wp_unslash( $_GET['rc_s'] ) ) : '';

		$per_page = isset( $_GET['rc_per_page'] ) ? (int) $_GET['rc_per_page'] : self::DEFAULT_PER_PAGE;
		if ( ! in_array( $per_page, self::per_page_options(), true ) ) {
			$per_page = self::DEFAULT_PER_PAGE;
		}

		$paged = isset( $_GET['rc_paged'] ) ? max( 1, (int) $_GET['rc_paged'] ) : 1;

		$branches = $connector->get_branches();
		$terms    = $connector->get_terms();
		?>
		<div class="wrap" dir="rtl">
			<h1>توضیحات و لینک‌های اختصاصی کلاس‌ها</h1>
			<p class="description">این توضیحات و لینک‌ها با هر بار به‌روزرسانی داده از لن‌میس حفظ می‌شوند.</p>

			<form method="get" style="margin:16px 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
				<input type="hidden" name="page" value="radman-calendar-manual">

				<label>
					شعبه:
					<select name="branch_key">
						<option value="">— انتخاب کنید —</option>
						<?php foreach ( (array) ( $branches['success'] ? $branches['data'] : array() ) as $b ) : ?>
							<option value="<?php echo esc_attr( $b['key'] ); ?>" <?php selected( $branch_key, $b['key'] ); ?>>
								<?php echo esc_html( $b['title'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					ترم:
					<select name="term_key">
						<option value="">— انتخاب کنید —</option>
						<?php foreach ( (array) ( $terms['success'] ? $terms['data'] : array() ) as $t ) : ?>
							<option value="<?php echo esc_attr( $t['key'] ); ?>" <?php selected( $term_key, $t['key'] ); ?>>
								<?php echo esc_html( $t['title'] ); ?><?php echo ! empty( $t['is_current_term'] ) ? ' (جاری)' : ''; ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					جستجو:
					<input type="search" name="rc_s" value="<?php echo esc_attr( $search ); ?>" placeholder="نام دوره، استاد، دپارتمان...">
				</label>

				<label>
					در هر صفحه:
					<select name="rc_per_page">
						<?php foreach ( self::per_page_options() as $option ) : ?>
							<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $per_page, $option ); ?>>
								<?php echo 0 === $option ? 'همه' : esc_html( (string) $option ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<?php submit_button( 'نمایش کلاس‌ها', 'secondary', 'submit', false ); ?>
			</form>

			<?php if ( $branch_key && $term_key ) : ?>
				<?php $this->render_class_table( $branch_key, $term_key, $search, $per_page, $paged ); ?>
			<?php else : ?>
				<p>یک شعبه و ترم انتخاب کنید تا لیست کلاس‌ها نمایش داده شود.</p>
			<?php endif; ?>
		</div>
		<?php
	}


	/**
	 * جدول ویرایش داده دستی.
	 *
	 * ------------------------- کارایی ۱.۷.۳ -------------------------
	 * قبلاً *تمام* کلاس‌های یک شعبه/ترم در یک جدول رندر می‌شدند. با چند صد
	 * کلاس، این یعنی چند مگابایت HTML و بیش از ۲۰۰۰ فیلد ورودی در یک صفحه:
	 * سرور برای ساختنش وقت می‌گذاشت، شبکه برای فرستادنش، و مرورگر برای
	 * چیدن و رنگ‌آمیزی‌اش. همان چیزی که کاربر به‌عنوان «داشبورد کند» حس
	 * می‌کرد؛ خیلی وقت‌ها هم کندی از لن‌میس نبود، از حجم خود صفحه بود.
	 *
	 * حالا جستجو و صفحه‌بندی سمت سرور انجام می‌شود. داده و خروجی سایت هیچ
	 * تغییری نکرده - فقط همین صفحه ویرایش سبک شده.
	 */
	private function render_class_table( $branch_key, $term_key, $search = '', $per_page = self::DEFAULT_PER_PAGE, $paged = 1 ) {
		$result = RC_Query::get_classes(
			array(
				'branch_key' => $branch_key,
				'term_key'   => $term_key,
				'sort_by'    => 'date_desc',
			)
		);

		if ( ! $result['success'] ) {
			printf( '<p style="color:red;">خطا: %s</p>', esc_html( $result['error'] ) );
			return;
		}

		if ( empty( $result['items'] ) ) {
			echo '<p>کلاسی برای این شعبه/ترم یافت نشد.</p>';
			return;
		}

		$items = $result['items'];

		if ( '' !== $search ) {
			$needle = self::fold( $search );
			$items  = array_values(
				array_filter(
					$items,
					function ( $item ) use ( $needle ) {
						$haystack = self::fold(
							$item['title'] . ' ' . $item['course'] . ' ' . $item['department'] . ' '
							. $item['teacher'] . ' ' . $item['class_key'] . ' ' . $item['start_date']
						);
						return false !== strpos( $haystack, $needle );
					}
				)
			);
		}

		$total = count( $items );

		if ( 0 === $total ) {
			echo '<p>هیچ کلاسی با این عبارت جستجو پیدا نشد.</p>';
			return;
		}

		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
		$paged       = min( max( 1, (int) $paged ), $total_pages );
		$offset      = $per_page > 0 ? ( $paged - 1 ) * $per_page : 0;
		$page_items  = $per_page > 0 ? array_slice( $items, $offset, $per_page ) : $items;

		$this->render_pagination( $total, $total_pages, $paged, $per_page, $offset, count( $page_items ) );
		?>
		<p class="description">
			«نام دلخواه»: اگر اسم درسی که لن‌میس می‌دهد نادرست/ناقص است (مانند بعضی درس‌های
			دپارتمان تعمیرات و تاسیسات)، اینجا اسم درست را بنویسید؛ هم در جدول سایت و هم در
			خروجی عکس اعمال می‌شود. «اولویت» فقط وقتی اثر دارد که ترتیب روی «اولویت دستی
			ادمین» باشد (عدد بزرگ‌تر = بالاتر).
		</p>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:18%;">عنوان / تاریخ</th>
					<th style="width:16%;">نام دلخواه</th>
					<th style="width:26%;">توضیحات دستی</th>
					<th style="width:20%;">لینک اختصاصی</th>
					<th style="width:8%;">اولویت</th>
					<th style="width:12%;">وضعیت</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $page_items as $item ) : ?>
					<tr class="rc-manual-row" data-class-key="<?php echo esc_attr( $item['class_key'] ); ?>">
						<td>
							<strong><?php echo esc_html( $item['title'] ); ?></strong><br>
							<span style="color:#777;font-size:12px;">
								<?php echo esc_html( $item['start_date'] ); ?> — <?php echo esc_html( $item['class_key'] ); ?>
							</span>
						</td>
						<td>
							<input type="text" class="rc-manual-title" style="width:100%;"
								value="<?php echo esc_attr( $item['manual_title'] ); ?>"
								placeholder="<?php echo esc_attr( $item['title'] ); ?>">
						</td>
						<td>
							<textarea class="rc-manual-description" rows="2" style="width:100%;"><?php echo esc_textarea( $item['description'] ); ?></textarea>
						</td>
						<td>
							<input type="url" class="rc-manual-link" style="width:100%;" value="<?php echo esc_attr( $item['link'] ); ?>" placeholder="https://...">
						</td>
						<td>
							<input type="number" class="rc-manual-priority" style="width:80px;" value="<?php echo (int) $item['sort_priority']; ?>">
						</td>
						<td>
							<button type="button" class="button button-primary rc-manual-save">ذخیره</button>
							<button type="button" class="button rc-manual-delete">پاک کردن</button>
							<span class="rc-manual-status"></span>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$this->render_pagination( $total, $total_pages, $paged, $per_page, $offset, count( $page_items ) );
	}

	/**
	 * نرمال‌سازی متن برای جستجو: ارقام لاتین، حذف نیم‌فاصله، یکسان‌سازی
	 * «ی» و «ک» عربی. بدون این، جستجوی «کلاس» متنی که با ي عربی نوشته شده
	 * را پیدا نمی‌کند - همان مشکل همیشگی داده‌های فارسی از منابع مختلف.
	 */
	private static function fold( $value ) {
		$value = RC_Jalali::to_latin_digits( (string) $value );
		$value = str_replace(
			array( "\u{200C}", "\u{200F}", "\u{200E}", "\u{064A}", "\u{0643}" ),
			array( '', '', '', 'ی', 'ک' ),
			$value
		);
		$value = preg_replace( '/\s+/u', ' ', $value );
		return trim( function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value ) );
	}

	/**
	 * نوار صفحه‌بندی (بالا و پایین جدول)
	 */
	private function render_pagination( $total, $total_pages, $paged, $per_page, $offset, $shown ) {
		$base_args = array(
			'page'        => 'radman-calendar-manual',
			'branch_key'  => isset( $_GET['branch_key'] ) ? sanitize_text_field( wp_unslash( $_GET['branch_key'] ) ) : '',
			'term_key'    => isset( $_GET['term_key'] ) ? sanitize_text_field( wp_unslash( $_GET['term_key'] ) ) : '',
			'rc_s'        => isset( $_GET['rc_s'] ) ? sanitize_text_field( wp_unslash( $_GET['rc_s'] ) ) : '',
			'rc_per_page' => (int) $per_page,
		);

		$link = function ( $page_number ) use ( $base_args ) {
			$args             = $base_args;
			$args['rc_paged'] = (int) $page_number;
			return add_query_arg( $args, admin_url( 'admin.php' ) );
		};

		$from = $shown > 0 ? $offset + 1 : 0;
		$to   = $offset + $shown;
		?>
		<div class="tablenav" style="direction:rtl;">
			<div class="tablenav-pages" style="float:none;text-align:right;">
				<span class="displaying-num">
					نمایش <?php echo esc_html( (string) $from ); ?>–<?php echo esc_html( (string) $to ); ?>
					از <strong><?php echo esc_html( (string) $total ); ?></strong> کلاس
				</span>
				<?php if ( $total_pages > 1 ) : ?>
					<span class="pagination-links" style="margin-inline-start:12px;">
						<?php if ( $paged > 1 ) : ?>
							<a class="button" href="<?php echo esc_url( $link( 1 ) ); ?>">« اول</a>
							<a class="button" href="<?php echo esc_url( $link( $paged - 1 ) ); ?>">‹ قبلی</a>
						<?php endif; ?>
						<span class="paging-input" style="margin:0 8px;">
							صفحه <?php echo esc_html( (string) $paged ); ?> از <?php echo esc_html( (string) $total_pages ); ?>
						</span>
						<?php if ( $paged < $total_pages ) : ?>
							<a class="button" href="<?php echo esc_url( $link( $paged + 1 ) ); ?>">بعدی ›</a>
							<a class="button" href="<?php echo esc_url( $link( $total_pages ) ); ?>">آخر »</a>
						<?php endif; ?>
					</span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
