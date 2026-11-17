<?php
/**
 * Copyright (c) 2026 Ali Lashkari. All Rights Reserved. See LICENSE.
 *
 * ویجت پیشخوان وردپرس: «پشتیبانی و توسعه‌دهنده افزونه»
 *
 * برخلاف بخش «پشتیبانی و آپدیت» در صفحه تنظیمات (که از دیتابیس/فرم خونده می‌شه)،
 * این ویجت عمداً مقادیرش را از constant می‌خواند، نه از دیتابیس - چون قرار است
 * ثابت و فقط با ویرایش دستی کد توسط توسعه‌دهنده تغییر کند.
 *
 * ثابت‌های RC_DEV_* از نسخه ۱.۷.۳ در فایل اصلی افزونه (radman-calendar.php)
 * تعریف می‌شوند، چون RC_License و RC_Updater هم به آن‌ها نیاز دارند و این فایل
 * فقط روی صفحه‌های پیشخوان لود می‌شود. برای تغییر اطلاعات پشتیبانی، همان‌جا
 * (یا در wp-config.php) ویرایش کن.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RC_Dashboard_Widget {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
	}

	public function register_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'rc_support_widget',
			'🗓️ افزونه تقویم آموزشی — پشتیبانی و توسعه‌دهنده',
			array( $this, 'render_widget' )
		);
	}

	public function render_widget() {
		?>
		<style>
			.rc-dash-card {
				direction: rtl;
				font-family: inherit;
				display: flex;
				flex-direction: column;
				gap: 10px;
			}
			.rc-dash-row {
				display: flex;
				align-items: center;
				gap: 10px;
				padding: 10px 12px;
				background: #f8fafc;
				border-radius: 8px;
				transition: background 0.15s ease;
			}
			.rc-dash-row:hover {
				background: #eef3f8;
			}
			.rc-dash-row .dashicons {
				color: #1c6ea4;
				background: #e8f1f8;
				border-radius: 50%;
				padding: 6px;
				width: 32px;
				height: 32px;
				box-sizing: border-box;
				font-size: 20px;
			}
			.rc-dash-row a {
				text-decoration: none;
				color: #1d2327;
				font-weight: 500;
			}
			.rc-dash-row a:hover {
				color: #1c6ea4;
			}
			.rc-dash-label {
				font-size: 11px;
				color: #8a8f94;
				display: block;
				margin-bottom: 1px;
			}
			.rc-dash-note {
				font-size: 12.5px;
				color: #646970;
				line-height: 1.8;
				border-top: 1px solid #f0f2f4;
				padding-top: 10px;
				margin-top: 2px;
			}
			.rc-dash-version {
				font-size: 11px;
				color: #b0b6bb;
				text-align: left;
				direction: ltr;
			}
		</style>

		<div class="rc-dash-card">
			<?php if ( RC_DEV_NAME ) : ?>
				<div class="rc-dash-row">
					<span class="dashicons dashicons-admin-users"></span>
					<div>
						<span class="rc-dash-label">توسعه‌دهنده</span>
						<?php echo esc_html( RC_DEV_NAME ); ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( RC_DEV_PHONE ) : ?>
				<div class="rc-dash-row">
					<span class="dashicons dashicons-phone"></span>
					<div>
						<span class="rc-dash-label">تماس</span>
						<a href="tel:<?php echo esc_attr( RC_DEV_PHONE ); ?>" dir="ltr"><?php echo esc_html( RC_DEV_PHONE ); ?></a>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( RC_DEV_EMAIL ) : ?>
				<div class="rc-dash-row">
					<span class="dashicons dashicons-email"></span>
					<div>
						<span class="rc-dash-label">ایمیل</span>
						<a href="mailto:<?php echo esc_attr( RC_DEV_EMAIL ); ?>"><?php echo esc_html( RC_DEV_EMAIL ); ?></a>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( RC_DEV_WEBSITE ) : ?>
				<div class="rc-dash-row">
					<span class="dashicons dashicons-admin-site-alt3"></span>
					<div>
						<span class="rc-dash-label">وب‌سایت</span>
						<?php
						// esc_url() برای آدرس بدون پروتکل، http:// می‌گذارد.
						// متن نمایشی همان مقدار ثابت می‌ماند، ولی لینک https می‌شود.
						$rc_dev_site = RC_DEV_WEBSITE;
						if ( ! preg_match( '#^https?://#i', $rc_dev_site ) ) {
							$rc_dev_site = 'https://' . ltrim( $rc_dev_site, '/' );
						}
						?>
						<a href="<?php echo esc_url( $rc_dev_site ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( RC_DEV_WEBSITE ); ?></a>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( RC_DEV_NOTE ) : ?>
				<p class="rc-dash-note"><?php echo esc_html( RC_DEV_NOTE ); ?></p>
			<?php endif; ?>

			<div class="rc-dash-version">نسخه افزونه: <?php echo esc_html( RC_VERSION ); ?></div>
		</div>
		<?php
	}
}
