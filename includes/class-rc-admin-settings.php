<?php
/**
 * Copyright (c) 2026 Ali Lashkari. All Rights Reserved. See LICENSE.
 *
 * پنل تنطیمات مرکزی پلاگین (پیشخوان ادمین)
 *  - توکن API و بازه به‌روزرسانی خودکار
 *  - دکمه «به‌روزرسانی اجباری» + لاگ آخرین اتصال
 *  - تنطیمات ظاهری (رنگ‌ها و فونت)
 *  - تنطیمات خروجی عکس (فوتر، ترجمه دپارتمان‌ها، اولویت دپارتمان‌ها)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RC_Admin_Settings {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_rc_force_sync', array( $this, 'handle_force_sync' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * اسکریپت‌های صفحه تنطیمات - فقط روی همین یک صفحه.
	 *
	 * wp_enqueue_media() چند ده فایل جاوااسکریپت و قالب underscore کتابخانه
	 * رسانه را لود می‌کند. قبلاً وسط رندر HTML صفحه صدا زده می‌شد؛ حالا در
	 * جای درست خودش (admin_enqueue_scripts) و با وابستگی صریح است، تا هم
	 * ترتیب لود قطعی باشد و هم روی هیچ صفحه دیگری از پیشخوان اجرا نشود.
	 */
	public function enqueue( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'radman-calendar' !== $page ) {
			return;
		}
		unset( $hook );

		wp_enqueue_media();

		$path    = RC_PATH . 'assets/js/admin-settings.js';
		$version = file_exists( $path ) ? filemtime( $path ) : RC_VERSION;

		wp_enqueue_script(
			'rc-admin-settings',
			RC_URL . 'assets/js/admin-settings.js',
			array( 'jquery', 'media-editor' ),
			$version,
			true
		);
	}

	public function register_menu() {
		add_menu_page(
			'تقویم آموزشی رادمان',
			'تقویم آموزشی',
			'manage_options',
			'radman-calendar',
			array( $this, 'render_settings_page' ),
			'dashicons-calendar-alt',
			58
		);
	}

	public function register_settings() {
		register_setting( 'rc_settings_group', 'rc_settings', array( $this, 'sanitize_settings' ) );
	}

	public function sanitize_settings( $input ) {
		$output   = array();
		$input    = (array) $input;
		$existing = (array) get_option( 'rc_settings', array() );

		/**
		 * بعضی تنطیمات (فونت و خط دوم فوتر) دیگر فیلد فرم ندارند.
		 * اگر مثل بقیه فیلدها فقط با isset() خوانده شوند، هر بار ذخیرهٔ
		 * تنطیمات مقدارشان پاک می‌شود (نتیجه: استایل‌شیت فونت فارسی
		 * از دست می‌رفت و خروجی عکس روی فونت Tahoma می‌افتاد).
		 * پس مقدار قبلی حفظ می‌شود. چک‌باکس‌ها عمداً این‌طور نیستند
		 * (وگرنه هیچ‌وقت قابل خاموش‌کردن نبودند).
		 */
		$keep = function ( $key, $default = '' ) use ( $input, $existing ) {
			if ( array_key_exists( $key, $input ) ) {
				return $input[ $key ];
			}
			return array_key_exists( $key, $existing ) ? $existing[ $key ] : $default;
		};

		/**
		 * توکن API.
		 *
		 * فیلد فرم حالا از نوع password است و مقدار ذخیره‌شده هرگز داخل HTML
		 * صفحه چاپ نمی‌شود (قبلاً توکن به‌صورت متن خام در value ورودی بود و
		 * با هر بازدید از صفحه تنظیمات یا هر نشتِ HTML قابل خواندن بود).
		 * پس یعنی فیلد معمولاً خالی ارسال می‌شود؛ خالی = «تغییری نده»، و برای
		 * حذف واقعی، چک‌باکس صریح «حذف توکن» وجود دارد.
		 */
		$submitted_token = isset( $input['lanmis_token'] ) ? sanitize_text_field( $input['lanmis_token'] ) : '';
		$existing_token  = isset( $existing['lanmis_token'] ) ? (string) $existing['lanmis_token'] : '';

		if ( ! empty( $input['lanmis_token_clear'] ) ) {
			$output['lanmis_token'] = '';
		} elseif ( '' !== $submitted_token ) {
			$output['lanmis_token'] = $submitted_token;
		} else {
			$output['lanmis_token'] = $existing_token;
		}

		$output['cache_ttl'] = isset( $input['cache_ttl'] ) ? max( 60, (int) $input['cache_ttl'] ) : 900;

		$allowed_sorts         = array( 'date_desc', 'date_asc', 'manual_priority' );
		$sort                  = isset( $input['default_sort'] ) ? sanitize_text_field( $input['default_sort'] ) : 'date_desc';
		$output['default_sort'] = in_array( $sort, $allowed_sorts, true ) ? $sort : 'date_desc';

		// بازه Cron باید از فهرست مجاز باشد، وگرنه wp_schedule_event شکست
		// می‌خورد و همگام‌سازی خودکار بی‌صدا برای همیشه متوقف می‌شود.
		$interval                = isset( $input['sync_interval'] ) ? sanitize_text_field( $input['sync_interval'] ) : 'hourly';
		$interval                = in_array( $interval, RC_Cron::allowed_intervals(), true ) ? $interval : 'hourly';
		$output['sync_interval'] = $interval;

		// فقط وقتی واقعاً چیزی عوض شده (یا زمان‌بندی گم شده) دوباره ثبت کن؛
		// قبلاً هر ذخیره تنظیمات، Cron را حذف و از صفر ثبت می‌کرد.
		$old_interval = isset( $existing['sync_interval'] ) ? $existing['sync_interval'] : '';
		if ( $old_interval !== $interval || false === wp_next_scheduled( RC_Cron::HOOK ) ) {
			RC_Cron::reschedule( $interval );
		}

		// منبع «نام دوره» در جدول/خروجی عکس
		$allowed_sources        = array( 'auto', 'level', 'course', 'course_level' );
		$title_source           = isset( $input['title_source'] ) ? sanitize_text_field( $input['title_source'] ) : 'auto';
		$output['title_source'] = in_array( $title_source, $allowed_sources, true ) ? $title_source : 'auto';

		// تنطیمات ظاهری (رنگ‌ها) - در صورت نامعتبر بودن، رنگ پیش‌فرض برمی‌گردد تا null ذخیره نشود
		$color_defaults = array(
			'color_header'         => '#1c6ea4',
			'color_hover'          => '#f1f7fb',
			'color_badge_online'   => '#2ecc71',
			'color_badge_offline'  => '#e67e22',
			'color_badge_presence' => '#3498db',
			'color_banner'         => '#1c4f73',
		);
		foreach ( $color_defaults as $ck => $cdef ) {
			$sanitized    = isset( $input[ $ck ] ) ? sanitize_hex_color( $input[ $ck ] ) : null;
			$output[ $ck ] = $sanitized ? $sanitized : $cdef;
		}

		// فونت
		$schemes                    = array( 'http', 'https' );
		$output['font_family']      = sanitize_text_field( (string) $keep( 'font_family' ) );
		$output['font_regular_url'] = esc_url_raw( (string) $keep( 'font_regular_url' ), $schemes );
		$output['font_bold_url']    = esc_url_raw( (string) $keep( 'font_bold_url' ), $schemes );
		$output['font_css_url']     = esc_url_raw( (string) $keep( 'font_css_url' ), $schemes );

		// اولویت دپارتمان‌ها + ترجمه انگلیسی دپارتمان‌ها
		$output['department_priority']     = isset( $input['department_priority'] ) ? sanitize_textarea_field( $input['department_priority'] ) : '';
		$output['department_translations'] = isset( $input['department_translations'] ) ? sanitize_textarea_field( $input['department_translations'] ) : '';

		// نمایش/عدم‌نمایش تعداد کل ردیف‌ها بالای جدول
		$output['show_count'] = ! empty( $input['show_count'] ) ? 1 : 0;

		// فوتر خروجی عکس
		$output['footer_enabled']        = ! empty( $input['footer_enabled'] ) ? 1 : 0;
		$output['footer_text_before']    = isset( $input['footer_text_before'] ) ? sanitize_text_field( $input['footer_text_before'] ) : 'برای مشاوره';
		$output['footer_text_highlight'] = isset( $input['footer_text_highlight'] ) ? sanitize_text_field( $input['footer_text_highlight'] ) : 'رایگان';
		$output['footer_text_after']     = isset( $input['footer_text_after'] ) ? sanitize_text_field( $input['footer_text_after'] ) : 'و اطلاعات بیشتر';
		$output['footer_text_line2']     = sanitize_text_field( (string) $keep( 'footer_text_line2', 'فقط کافیه «نام دوره» رو ارسال کنی' ) );
		$output['footer_phone_left']     = isset( $input['footer_phone_left'] ) ? sanitize_text_field( $input['footer_phone_left'] ) : 'شماره تماس خود را وارد کنید';
		$output['footer_phone_right']    = isset( $input['footer_phone_right'] ) ? sanitize_text_field( $input['footer_phone_right'] ) : '';
		$output['footer_logo_text']      = isset( $input['footer_logo_text'] ) ? sanitize_text_field( $input['footer_logo_text'] ) : 'آکادمی تخصصی رادمان';
		$output['footer_logo_url']        = isset( $input['footer_logo_url'] ) ? esc_url_raw( $input['footer_logo_url'], array( 'http', 'https' ) ) : '';

		// تنطیمات عوض شد → کش HTML جدول‌های ادغام‌شده باید دور ریخته شود،
		// وگرنه تا پایان TTL همان خروجی با تنطیمات قبلی نمایش داده می‌شود.
		// (این دو خط باید *آخرین* کار این تابع باشند. قبلاً یک انتساب مقدار
		// بعد از آن‌ها آمده بود که خیلی راحت می‌توانست وسط ویرایش‌های بعدی
		// بیرون از return بیفتد و مقدار لوگو بی‌صدا پاک شود.)
		if ( class_exists( 'RC_Shortcodes' ) ) {
			RC_Shortcodes::purge_merge_cache();
		}
		RC_Query::flush_runtime_cache();

		return $output;
	}

	/**
	 * پردازش دکمه «به‌روزرسانی اجباری» - کش را دور می‌زند و مستقیم از API می‌خواند
	 */
	public function handle_force_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی غیرمجاز' );
		}
		check_admin_referer( 'rc_force_sync_action' );

		$connector = RC_Lanmis_Connector::instance();
		$connector->purge_all_cache();
		RC_Query::flush_runtime_cache();
		$connector->get_branches( true );

		$this->purge_page_cache_plugins();

		wp_safe_redirect( add_query_arg( array( 'page' => 'radman-calendar', 'synced' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * پاک کردن کش صفحه در پلاگین‌های کش رایج (اگر نصب باشند)
	 */
	private function purge_page_cache_plugins() {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		if ( has_action( 'litespeed_purge_all' ) || class_exists( 'LiteSpeed_Cache_API' ) ) {
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName
		}

		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache( true );
		}

		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}

		do_action( 'rc_after_force_sync_purge_cache' );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = get_option( 'rc_settings', array() );
		$log      = RC_Lanmis_Connector::get_last_log();
		?>
		<div class="wrap" dir="rtl">
			<h1>تنطیمات تقویم آموزشی رادمان</h1>

			<?php if ( isset( $_GET['synced'] ) ) : ?>
				<div class="notice notice-success"><p>کش داخلی/Object Cache و کش پلاگین‌های کش شناخته‌شده (در صورت وجود) پاک شد. اگر سایت پشت Cloudflare یا CDN دیگری است، ممکن است لازم باشد کش آن را هم جداگانه پاک کنید.</p></div>
			<?php endif; ?>

			<h2>وضعیت اتصال به API لن‌میس</h2>
			<?php if ( $log ) : ?>
				<p>
					<strong>آخرین وضعیت:</strong>
					<?php if ( $log['success'] ) : ?>
						<span style="color:green;">موفق</span>
					<?php else : ?>
						<span style="color:red;">خطا - <?php echo esc_html( $log['message'] ); ?></span>
					<?php endif; ?>
					&nbsp;|&nbsp; <strong>زمان:</strong> <?php echo esc_html( $log['time'] ); ?>
				</p>
			<?php else : ?>
				<p>هنوز درخواستی ثبت نشده است.</p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'rc_force_sync_action' ); ?>
				<input type="hidden" name="action" value="rc_force_sync">
				<?php submit_button( 'به‌روزرسانی اجباری', 'secondary', 'submit', false ); ?>
			</form>

			<hr>

			<form method="post" action="options.php">
				<?php settings_fields( 'rc_settings_group' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="lanmis_token">توکن API لن‌میس</label></th>
						<td>
							<?php
							$saved_token = isset( $settings['lanmis_token'] ) ? (string) $settings['lanmis_token'] : '';
							$has_token   = '' !== $saved_token;
							$token_hint  = $has_token
								? str_repeat( '•', 8 ) . esc_html( substr( $saved_token, -4 ) )
								: '';
							?>
							<input type="password" id="lanmis_token" name="rc_settings[lanmis_token]"
								value="" class="regular-text" autocomplete="new-password"
								placeholder="<?php echo $has_token ? 'برای تغییر، توکن جدید را وارد کنید' : 'توکن را وارد کنید'; ?>">
							<p class="description">
								<?php if ( $has_token ) : ?>
									توکن فعلی ذخیره شده است (<code dir="ltr"><?php echo $token_hint; // phpcs:ignore WordPress.Security.EscapeOutput ?></code>).
									خالی گذاشتن این فیلد یعنی «تغییری نده».
									<label style="display:block;margin-top:6px;">
										<input type="checkbox" name="rc_settings[lanmis_token_clear]" value="1"> حذف توکن ذخیره‌شده
									</label>
								<?php endif; ?>
								<br>ترجیحاً این مقدار را در wp-config.php تعریف کنید:
								<code>define('RC_LANMIS_TOKEN', '...');</code>
								<?php if ( defined( 'RC_LANMIS_TOKEN' ) && RC_LANMIS_TOKEN ) : ?>
									<br><strong>در حال حاضر توکن از wp-config.php خوانده می‌شود و همین مقدار اولویت دارد.</strong>
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cache_ttl">مدت اعتبار کش (ثانیه)</label></th>
						<td>
							<input type="number" id="cache_ttl" name="rc_settings[cache_ttl]" min="60"
								value="<?php echo esc_attr( isset( $settings['cache_ttl'] ) ? $settings['cache_ttl'] : 900 ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="default_sort">اولویت پیش‌فرض چیدمان</label></th>
						<td>
							<select id="default_sort" name="rc_settings[default_sort]">
								<option value="date_desc" <?php selected( isset( $settings['default_sort'] ) ? $settings['default_sort'] : '', 'date_desc' ); ?>>جدیدترین به قدیمی‌ترین</option>
								<option value="date_asc" <?php selected( isset( $settings['default_sort'] ) ? $settings['default_sort'] : '', 'date_asc' ); ?>>قدیمی‌ترین به جدیدترین</option>
								<option value="manual_priority" <?php selected( isset( $settings['default_sort'] ) ? $settings['default_sort'] : '', 'manual_priority' ); ?>>اولویت دستی ادمین</option>
							</select>
							<p class="description">
								در حالت «اولویت دستی ادمین»، ترتیب ردیف‌ها از عدد «اولویت»ی که در صفحه
								«توضیحات و لینک‌ها» برای هر کلاس ثبت می‌کنید خوانده می‌شود
								(عدد بزرگ‌تر = بالاتر)؛ ردیف‌هایی که اولویت ندارند بر اساس تاریخ شروع می‌آیند.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="title_source">منبع «نام دوره»</label></th>
						<td>
							<?php $ts = isset( $settings['title_source'] ) ? $settings['title_source'] : 'auto'; ?>
							<select id="title_source" name="rc_settings[title_source]">
								<option value="auto" <?php selected( $ts, 'auto' ); ?>>خودکار هوشمند (پیشنهادی)</option>
								<option value="level" <?php selected( $ts, 'level' ); ?>>فقط نام سطح (level)</option>
								<option value="course" <?php selected( $ts, 'course' ); ?>>فقط نام دوره (course)</option>
								<option value="course_level" <?php selected( $ts, 'course_level' ); ?>>دوره + سطح (کنار هم)</option>
							</select>
							<p class="description">
								در حالت خودکار، اگر نام سطح یک اسم واقعی باشد (مانند «سخت افزار موبایل») همان نمایش داده می‌شود؛
								اگر خالی یا یک کد داخلی (مانند "A" یا "**") باشد به نام دوره برمی‌گردد، و اگر فقط یک سطح عمومی
								(«مقدماتی»، «ترم ۲») باشد، نام دوره + سطح کنار هم نوشته می‌شود.
								برای هر کلاس خاص هم می‌توانید در صفحه «توضیحات و لینک‌ها» نام دلخواه دستی بگذارید.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sync_interval">زمان‌بندی همگام‌سازی خودکار</label></th>
						<td>
							<select id="sync_interval" name="rc_settings[sync_interval]">
								<?php
								$current = isset( $settings['sync_interval'] ) ? $settings['sync_interval'] : 'hourly';
								$options = array(
									'rc_15_min'  => 'هر ۱۵ دقیقه',
									'rc_30_min'  => 'هر ۳۰ دقیقه',
									'hourly'     => 'هر ساعت',
									'twicedaily' => 'هر ۱۲ ساعت',
									'daily'      => 'روزی یک‌بار',
								);
								foreach ( $options as $value => $label ) :
									?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								در این بازه، لیست شعب/ترم‌ها/دپارتمان‌ها خودکار در پس‌زمینه تازه‌سازی می‌شود.
								<?php if ( false !== wp_next_scheduled( 'rc_scheduled_sync' ) ) : ?>
									<br>زمان‌بندی بعدی: <?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', wp_next_scheduled( 'rc_scheduled_sync' ) ), 'Y-m-d H:i:s' ) ); ?>
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">نمایش تعداد کل ردیف‌ها در سایت</th>
						<td>
							<label>
								<input type="checkbox" name="rc_settings[show_count]" value="1"
									<?php checked( ! empty( $settings['show_count'] ) ); ?>>
								بالای هر جدول تعداد کل ردیف‌های نمایش داده شده نوشته شود
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="department_priority">اولویت دپارتمان‌ها</label></th>
						<td>
							<textarea id="department_priority" name="rc_settings[department_priority]" rows="4" class="large-text"
								placeholder="هر دپارتمان در یک خط، به ترتیب اولویت"><?php echo esc_textarea( isset( $settings['department_priority'] ) ? $settings['department_priority'] : '' ); ?></textarea>
							<p class="description">
								این لیست دو کار می‌کند:
								<br>۱) در شرت‌کد <code>[radman_upcoming]</code>: اول دوره‌هایی که تا ۷ روز آینده شروع می‌شوند نمایش داده می‌شوند؛
								اگر تعدادشان از count کمتر بود، جای خالی با دوره‌های بعدی اولویت‌دارترین دپارتمان‌های این لیست پر می‌شود.
								<br>۲) ترتیب بلوک دپارتمان‌ها در خروجی عکس (برنامه کلاسی) را هم تعیین می‌کند.
								<br>نام دپارتمان باید دقیقاً مطابق همان چیزی باشد که لن‌میس برمی‌گرداند. خالی گذاشتن هم مشکلی ایجاد نمی‌کند.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="department_translations">ترجمه انگلیسی دپارتمان‌ها (برای خروجی عکس)</label></th>
						<td>
							<textarea id="department_translations" name="rc_settings[department_translations]" rows="5" class="large-text" style="direction:rtl;text-align:right;"
								placeholder="هر خط: نام فارسی=ترجمه انگلیسی&#10;مثال: برنامه نویسی و هوش مصنوعی=Programming &amp; Artificial Intelligence"><?php echo esc_textarea( isset( $settings['department_translations'] ) ? $settings['department_translations'] : '' ); ?></textarea>
							<p class="description">اگر دپارتمانی اینجا ترجمه نداشته باشد، زیرنویس انگلیسی نمایش داده نمی‌شود (مشکلی ایجاد نمی‌کند).</p>
						</td>
					</tr>

					<tr>
						<th colspan="2"><h2 style="margin-bottom:0;">فوتر خروجی عکس</h2><p class="description" style="font-weight:normal;">بنر «مشاوره رایگان» + دو شماره تماس + لوگو که ته هر صفحه خروجی عکس (چسبیده به پایین) تکرار می‌شود.</p></th>
					</tr>
					<tr>
						<th scope="row">نمایش فوتر در خروجی عکس</th>
						<td>
							<label>
								<input type="checkbox" name="rc_settings[footer_enabled]" value="1"
									<?php checked( ! isset( $settings['footer_enabled'] ) || ! empty( $settings['footer_enabled'] ) ); ?>>
								ته هر صفحه از خروجی عکس این فوتر نمایش داده شود
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="footer_text_before">متن بنر - قبل از کلمه پررنگ</label></th>
						<td>
							<input type="text" id="footer_text_before" name="rc_settings[footer_text_before]" class="regular-text"
								value="<?php echo esc_attr( isset( $settings['footer_text_before'] ) ? $settings['footer_text_before'] : 'برای مشاوره' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="footer_text_highlight">کلمه پررنگ (طلایی) وسط جمله</label></th>
						<td>
							<input type="text" id="footer_text_highlight" name="rc_settings[footer_text_highlight]" class="regular-text"
								value="<?php echo esc_attr( isset( $settings['footer_text_highlight'] ) ? $settings['footer_text_highlight'] : 'رایگان' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="footer_text_after">متن بنر - بعد از کلمه پررنگ</label></th>
						<td>
							<input type="text" id="footer_text_after" name="rc_settings[footer_text_after]" class="regular-text"
								value="<?php echo esc_attr( isset( $settings['footer_text_after'] ) ? $settings['footer_text_after'] : 'و اطلاعات بیشتر' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="footer_text_line2">خط دوم بنر</label></th>
						<td>
							<input type="text" id="footer_text_line2" name="rc_settings[footer_text_line2]" class="regular-text"
								value="<?php echo esc_attr( isset( $settings['footer_text_line2'] ) ? $settings['footer_text_line2'] : 'فقط کافیه «نام دوره» رو ارسال کنی' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="footer_phone_left">شماره تماس سمت چپ</label></th>
						<td>
							<input type="text" id="footer_phone_left" name="rc_settings[footer_phone_left]" class="regular-text" dir="ltr"
								value="<?php echo esc_attr( isset( $settings['footer_phone_left'] ) ? $settings['footer_phone_left'] : 'شماره تماس خود را وارد کنید' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="footer_phone_right">شماره تماس سمت راست</label></th>
						<td>
							<input type="text" id="footer_phone_right" name="rc_settings[footer_phone_right]" class="regular-text" dir="ltr"
								value="<?php echo esc_attr( isset( $settings['footer_phone_right'] ) ? $settings['footer_phone_right'] : '' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="footer_logo_url">لوگو (تصویر) وسط فوتر</label></th>
						<td>
							<div id="rc-logo-preview" style="margin-bottom:8px;">
								<?php if ( ! empty( $settings['footer_logo_url'] ) ) : ?>
									<img src="<?php echo esc_url( $settings['footer_logo_url'] ); ?>" style="max-height:60px;max-width:200px;display:block;border:1px solid #ddd;padding:4px;background:#fff;">
								<?php endif; ?>
							</div>
							<input type="hidden" id="footer_logo_url" name="rc_settings[footer_logo_url]" value="<?php echo esc_attr( isset( $settings['footer_logo_url'] ) ? $settings['footer_logo_url'] : '' ); ?>">
							<button type="button" class="button" id="rc-logo-upload-btn">انتخاب لوگو از کتابخانه رسانه</button>
							<button type="button" class="button" id="rc-logo-remove-btn" <?php echo empty( $settings['footer_logo_url'] ) ? 'style="display:none;"' : ''; ?>>حذف لوگو</button>
							<p class="description">ترجیحاً یک PNG با پس‌زمینه شفاف و تقریباً مربعی.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="footer_logo_text">متن زیر لوگو</label></th>
						<td>
							<input type="text" id="footer_logo_text" name="rc_settings[footer_logo_text]" class="regular-text"
								value="<?php echo esc_attr( isset( $settings['footer_logo_text'] ) ? $settings['footer_logo_text'] : 'آکادمی تخصصی رادمان' ); ?>">
						</td>
					</tr>

					<tr>
						<th colspan="2"><h2 style="margin-bottom:0;">رنگ‌ها</h2></th>
					</tr>
					<tr>
						<th scope="row">رنگ هدر جدول</th>
						<td><input type="text" class="rc-color-field" name="rc_settings[color_header]" value="<?php echo esc_attr( isset( $settings['color_header'] ) ? $settings['color_header'] : '#1c6ea4' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row">رنگ متن بنر بالای جدول</th>
						<td>
							<input type="text" class="rc-color-field" name="rc_settings[color_banner]" value="<?php echo esc_attr( isset( $settings['color_banner'] ) ? $settings['color_banner'] : '#1c4f73' ); ?>">
							<p class="description">متن «اطلاعیه شروع دوره‌های ...» بالای جدول روی سایت.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">رنگ Hover ردیف</th>
						<td><input type="text" class="rc-color-field" name="rc_settings[color_hover]" value="<?php echo esc_attr( isset( $settings['color_hover'] ) ? $settings['color_hover'] : '#f1f7fb' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row">رنگ برچسب آنلاین</th>
						<td><input type="text" class="rc-color-field" name="rc_settings[color_badge_online]" value="<?php echo esc_attr( isset( $settings['color_badge_online'] ) ? $settings['color_badge_online'] : '#2ecc71' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row">رنگ برچسب آفلاین</th>
						<td><input type="text" class="rc-color-field" name="rc_settings[color_badge_offline]" value="<?php echo esc_attr( isset( $settings['color_badge_offline'] ) ? $settings['color_badge_offline'] : '#e67e22' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row">رنگ برچسب حضوری</th>
						<td><input type="text" class="rc-color-field" name="rc_settings[color_badge_presence]" value="<?php echo esc_attr( isset( $settings['color_badge_presence'] ) ? $settings['color_badge_presence'] : '#3498db' ); ?>"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
