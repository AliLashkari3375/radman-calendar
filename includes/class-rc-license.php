<?php
/**
 * Copyright (c) 2026 Ali Lashkari. All Rights Reserved. See LICENSE.
 *
 * =====================================================================
 *  لایسنس محصول: radman calendar  (radman-calendar)
 *  تولیدشده توسط Rick License Kit — 2026-08-19 12:43
 * =====================================================================
 *
 * این فایل فقط کلید عمومی دارد؛ کاملاً بی‌خطر است که داخل زیپ مشتری باشد.
 * با این کلید فقط می‌توان لایسنس را تأیید کرد، نه ساخت.
 *
 * نحوهٔ استفاده در پلاگین:
 *   1) این فایل را در includes/ پلاگین بگذار و require کن:
 *        require_once PLUGIN_PATH . 'includes/class-radman-calendar-license.php';
 *        RC_License::instance();
 *   2) جلوی خروجی اصلی محصول را بگیر:
 *        if ( ! RC_License::instance()->is_valid() ) {
 *            return RC_License::instance()->locked_message();
 *        }
 *   3) این ۲ ثابت اختیاری را قبل از require تعریف کن (اگر خواستی):
 *        define( 'RC_LICENSE_MENU_PARENT', 'my-plugin-menu-slug' ); // پیش‌فرض: منوی تنطیمات
 *        define( 'RC_DEV_PHONE', '0912...' );
 *        define( 'RC_DEV_EMAIL', 'you@example.com' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// -------------------------------------------------------------------
// کلید عمومی مشترک توسعه‌دهنده (فقط تأییدکننده)
// -------------------------------------------------------------------
if ( ! defined( 'RC_LICENSE_PUBLIC_KEY' ) ) {
	define(
		'RC_LICENSE_PUBLIC_KEY',
		"-----BEGIN PUBLIC KEY-----\n" .
		"MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAt8EuMFpCQvwPbLl6+tOZ\n" .
		"KMQO9mNXWM+m6GtLZKWZMkOwSKFGrz1/hsQIaGoOQDCzvEiKYpzymk4sI2yPJ6ss\n" .
		"e6vfgkH/ET95WhCWC5afTU3esFnT9LvwLm4LuPf99XlLVNQBW1ksJ7JHSh5jwBLc\n" .
		"k6tHBUHkQndAWX8Rfd05jwy5dsUerYOCA+yBFataAbmuF2te6sADrSpb3ISakueM\n" .
		"ExJ+mzwjbI386KM0407DH1aLRo8N20Wgou4+AeEVsHJ+awCVvYrKexIITEHxR9jg\n" .
		"bC6IVwmdSq32CepbvJ7BlR1i4T4BjJUyYwCLCmccfSPWo6t0sG0AuRlYihZqYU8B\n" .
		"PwIDAQAB\n" .
		"-----END PUBLIC KEY-----"
	);
}

// شناسهٔ یکتای همین محصول — موقع ساخت لایسنس دقیقاً همین را بزن
if ( ! defined( 'RC_LICENSE_PRODUCT' ) ) {
	define( 'RC_LICENSE_PRODUCT', 'radman-calendar' );
}

if ( ! defined( 'RC_LICENSE_MENU_PARENT' ) ) {
	define( 'RC_LICENSE_MENU_PARENT', 'options-general.php' );
}

if ( ! class_exists( 'RC_License' ) ) {

class RC_License {

	const OPTION_KEY     = 'rc_license_key';
	const PRODUCT_LABEL  = 'radman calendar';
	const PAGE_SLUG      = 'radman-calendar-license';
	const WARN_DAYS      = 14;

	private static $instance = null;
	private $cache = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'register_menu' ), 30 );
			add_action( 'admin_post_rc_save_license', array( $this, 'handle_save' ) );
			add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
		}
	}

	public function register_menu() {
		add_submenu_page(
			RC_LICENSE_MENU_PARENT,
			'لایسنس ' . self::PRODUCT_LABEL,
			'لایسنس',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی غیرمجاز' );
		}
		check_admin_referer( 'rc_save_license_action' );

		$key = isset( $_POST['license_key'] ) ? sanitize_textarea_field( wp_unslash( $_POST['license_key'] ) ) : '';
		$key = preg_replace( '/\s+/', '', $key );

		$this->cache = null;
		update_option( self::OPTION_KEY, $key );
		if ( class_exists( 'RC_Shortcodes' ) ) {
			RC_Shortcodes::purge_merge_cache();
		}

		$redirect = self::admin_page_url();
		wp_safe_redirect( add_query_arg( 'saved', '1', $redirect ) );
		exit;
	}

	public static function admin_page_url() {
		$parent = RC_LICENSE_MENU_PARENT;
		if ( false !== strpos( $parent, '.php' ) ) {
			return admin_url( $parent . '?page=' . self::PAGE_SLUG );
		}
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * تأیید امضای دیجیتال (کاملاً محلی، بدون تماس با سرور).
	 *
	 * یادداشت کارایی: وسوسه شدم نتیجه را در یک Transient کش کنم، ولی اندازه
	 * گرفتم و منطقی نبود: openssl_verify با کلید عمومی ۲۰۴۸ بیتی حدود صدم
	 * میلی‌ثانیه است، در حالی که خواندن یک Transient روی سایتی بدون Object
	 * Cache دو کوئری دیتابیس می‌خورد. کش کردنش سایت را *کندتر* می‌کرد و یک
	 * راه جدید برای بی‌اعتبار نشدن وضعیت لایسنس هم باز می‌کرد. کش درون-درخواستی
	 * ($this->cache) از قبل جلوی تکرار در یک بارگذاری را می‌گیرد و همان کافی است.
	 */
	private function verify_signature( $key ) {
		$key = trim( (string) $key );
		if ( '' === $key || false === strpos( $key, '.' ) ) {
			return null;
		}

		list( $payload_b64, $sig_b64 ) = explode( '.', $key, 2 );

		$signature = base64_decode( strtr( $sig_b64, '-_', '+/' ) ); // phpcs:ignore
		if ( empty( $signature ) ) {
			return null;
		}

		// بدون افزونه OpenSSL در PHP، صدا زدن مستقیم این تابع خطای Fatal
		// روی هر صفحه‌ای که شرت‌کد دارد می‌داد (کل صفحه سفید می‌شد).
		if ( ! function_exists( 'openssl_pkey_get_public' ) || ! function_exists( 'openssl_verify' ) ) {
			return null;
		}
		$public_key = openssl_pkey_get_public( RC_LICENSE_PUBLIC_KEY );
		if ( ! $public_key ) {
			return null;
		}

		if ( 1 !== openssl_verify( $payload_b64, $signature, $public_key, OPENSSL_ALGO_SHA256 ) ) {
			return null;
		}

		$payload = json_decode( base64_decode( strtr( $payload_b64, '-_', '+/' ) ), true ); // phpcs:ignore
		if ( ! is_array( $payload ) || empty( $payload['expires'] ) ) {
			return null;
		}

		return $payload;
	}

	/** @return array {valid, reason, customer, domain, expires, days_left} */
	public function get_status() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$key = (string) get_option( self::OPTION_KEY, '' );

		if ( '' === trim( $key ) ) {
			return $this->cache = array( 'valid' => false, 'reason' => 'no_key' );
		}

		$payload = $this->verify_signature( $key );
		if ( ! $payload ) {
			return $this->cache = array( 'valid' => false, 'reason' => 'invalid_signature' );
		}

		if ( isset( $payload['product'] ) && strtolower( (string) $payload['product'] ) !== strtolower( RC_LICENSE_PRODUCT ) ) {
			return $this->cache = array(
				'valid'   => false,
				'reason'  => 'wrong_product',
				'product' => (string) $payload['product'],
			);
		}

		$customer = isset( $payload['customer'] ) ? (string) $payload['customer'] : '';
		$domain   = isset( $payload['domain'] ) ? (string) $payload['domain'] : '';

		if ( '' !== $domain ) {
			$site_host = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
			$lic_host  = strtolower( preg_replace( '/^www\./', '', $domain ) );
			if ( $site_host !== $lic_host ) {
				return $this->cache = array(
					'valid'    => false,
					'reason'   => 'domain_mismatch',
					'customer' => $customer,
					'domain'   => $domain,
				);
			}
		}

		$expires_ts = self::site_timestamp( $payload['expires'] . ' 23:59:59' );
		$now        = time();
		$days_left  = (int) ceil( ( $expires_ts - $now ) / DAY_IN_SECONDS );

		$base = array(
			'customer'  => $customer,
			'domain'    => $domain,
			'expires'   => (string) $payload['expires'],
			'days_left' => $days_left,
		);

		if ( $now > $expires_ts ) {
			return $this->cache = array_merge( $base, array( 'valid' => false, 'reason' => 'expired' ) );
		}

		return $this->cache = array_merge( $base, array( 'valid' => true, 'reason' => 'ok' ) );
	}

	/**
	 * تبدیل یک تاریخ «محلی سایت» به timestamp یونیکس.
	 *
	 * current_time('timestamp') از وردپرس ۵.۳ منع شده و یک timestamp جعلی
	 * (UTC + آفست) می‌دهد؛ مقایسه‌اش با strtotime() که روی timezone سرور کار
	 * می‌کند، روی هاست‌هایی که timezone سرورشان UTC نیست، تاریخ انقضا را تا
	 * چند ساعت جابه‌جا می‌کرد.
	 */
	private static function site_timestamp( $local_datetime ) {
		if ( function_exists( 'wp_timezone' ) ) {
			try {
				$dt = new DateTime( $local_datetime, wp_timezone() );
				return $dt->getTimestamp();
			} catch ( Exception $e ) {
				return (int) strtotime( $local_datetime );
			}
		}
		return (int) strtotime( $local_datetime );
	}

	public function is_valid() {
		$status = $this->get_status();
		return ! empty( $status['valid'] );
	}

	private function contact_line() {
		$contact = array();
		if ( defined( 'RC_DEV_PHONE' ) && RC_DEV_PHONE ) {
			$contact[] = RC_DEV_PHONE;
		}
		if ( defined( 'RC_DEV_EMAIL' ) && RC_DEV_EMAIL ) {
			$contact[] = RC_DEV_EMAIL;
		}
		return $contact ? implode( ' | ', $contact ) : '';
	}

	/** پیام جایگزین وقتی لایسنس معتبر نیست */
	public function locked_message() {
		$contact = $this->contact_line();
		ob_start();
		?>
		<div style="direction:rtl;padding:16px;background:#fdecea;border:1px solid #f5c2c0;border-radius:8px;color:#7a1f1a;font-size:14px;">
			این بخش از سایت غیرفعال است (لایسنس <?php echo esc_html( self::PRODUCT_LABEL ); ?> معتبر نیست یا منقضی شده).
			<?php if ( $contact ) : ?>
				<br><small>برای تمدید تماس بگیرید: <?php echo esc_html( $contact ); ?></small>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public function reason_text( $status ) {
		$reason = isset( $status['reason'] ) ? $status['reason'] : '';
		switch ( $reason ) {
			case 'ok':
				return 'لایسنس معتبر است.';
			case 'no_key':
				return 'هنوز کلید لایسنسی وارد نشده است.';
			case 'invalid_signature':
				return 'کلید لایسنس معتبر نیست (ناقص کپی شده یا جعلی است).';
			case 'wrong_product':
				return 'این لایسنس برای محصول دیگری صادر شده است' . ( ! empty( $status['product'] ) ? ' (' . $status['product'] . ')' : '' ) . '.';
			case 'domain_mismatch':
				return 'این لایسنس فقط روی دامنهٔ ' . ( isset( $status['domain'] ) ? $status['domain'] : '' ) . ' معتبر است.';
			case 'expired':
				return 'لایسنس در تاریخ ' . ( isset( $status['expires'] ) ? $status['expires'] : '' ) . ' منقضی شده است.';
		}
		return 'وضعیت لایسنس نامشخص است.';
	}

	public function maybe_show_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status = $this->get_status();

		if ( empty( $status['valid'] ) ) {
			echo '<div class="notice notice-error"><p style="direction:rtl"><strong>' . esc_html( self::PRODUCT_LABEL ) . ':</strong> '
				. esc_html( $this->reason_text( $status ) )
				. ' <a href="' . esc_url( self::admin_page_url() ) . '">ورود کلید لایسنس</a></p></div>';
			return;
		}

		if ( isset( $status['days_left'] ) && $status['days_left'] <= self::WARN_DAYS ) {
			echo '<div class="notice notice-warning"><p style="direction:rtl"><strong>' . esc_html( self::PRODUCT_LABEL ) . ':</strong> لایسنس تا '
				. esc_html( (string) (int) $status['days_left'] ) . ' روز دیگر (' . esc_html( $status['expires'] ) . ') منقضی می‌شود.</p></div>';
		}
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status  = $this->get_status();
		$key     = (string) get_option( self::OPTION_KEY, '' );
		$valid   = ! empty( $status['valid'] );
		$contact = $this->contact_line();
		?>
		<div class="wrap" style="direction:rtl;text-align:right;max-width:820px">
			<h1>لایسنس <?php echo esc_html( self::PRODUCT_LABEL ); ?></h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success"><p>کلید لایسنس ذخیره شد.</p></div>
			<?php endif; ?>

			<div style="padding:14px;border-radius:8px;margin:16px 0;background:<?php echo $valid ? '#eaf7ee' : '#fdecea'; ?>;border:1px solid <?php echo $valid ? '#b7e0c2' : '#f5c2c0'; ?>">
				<strong><?php echo $valid ? 'وضعیت: فعال' : 'وضعیت: غیرفعال'; ?></strong><br>
				<?php echo esc_html( $this->reason_text( $status ) ); ?>
				<?php if ( $valid ) : ?>
					<br>مشتری: <?php echo esc_html( $status['customer'] ); ?>
					<br>دامنه: <?php echo esc_html( $status['domain'] ? $status['domain'] : 'بدون محدودیت دامنه' ); ?>
					<br>انقضا: <?php echo esc_html( $status['expires'] ); ?> (<?php echo esc_html( (string) (int) $status['days_left'] ); ?> روز مانده)
				<?php endif; ?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rc_save_license">
				<?php wp_nonce_field( 'rc_save_license_action' ); ?>
				<p><label for="license_key"><strong>کلید لایسنس</strong></label></p>
				<textarea id="license_key" name="license_key" rows="6" style="width:100%;direction:ltr;font-family:monospace" placeholder="کلید دریافتی را اینجا پیست کن"><?php echo esc_textarea( $key ); ?></textarea>
				<p><button type="submit" class="button button-primary">ذخیره لایسنس</button></p>
			</form>

			<?php if ( $contact ) : ?>
				<p style="color:#666">برای خرید یا تمدید: <?php echo esc_html( $contact ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}

}

/** تابع کمکی کوتاه برای گیت کردن قابلیت‌ها */
if ( ! function_exists( 'rc_license_ok' ) ) {
	function rc_license_ok() {
		return RC_License::instance()->is_valid();
	}
}
