<?php
/**
 * Plugin Name: هماهنگ - افزونه ی مدیریت پروژه و تیم
 * Description: مدیریت پروژه‌های مشتری و نمایش مراحل پیشرفت داخل پنل کاربری (Stepper + Popup) + سیستم پرداخت چنددرگاهی + فرم‌ساز سفارش بله.
 * Version: 5.5.0
 * Author: Your Name
 * Text Domain: cptt
 */

if ( ! defined('ABSPATH') ) exit;

define('CPTT_VERSION', '5.5.0');
define('CPTT_PATH', plugin_dir_path(__FILE__));
define('CPTT_URL', plugin_dir_url(__FILE__));

/* mPDF autoload if installed */
if (file_exists(CPTT_PATH . 'vendor/autoload.php')) {
	require_once CPTT_PATH . 'vendor/autoload.php';
}

require_once CPTT_PATH . 'includes/class-cptt-core.php';
require_once CPTT_PATH . 'includes/class-cptt-currency.php';
require_once CPTT_PATH . 'includes/class-cptt-admin.php';
require_once CPTT_PATH . 'includes/class-cptt-frontend.php';
require_once CPTT_PATH . 'includes/class-cptt-expert.php';
require_once CPTT_PATH . 'includes/class-cptt-settings.php';
require_once CPTT_PATH . 'includes/class-cptt-report.php';
require_once CPTT_PATH . 'includes/class-cptt-sms.php';
require_once CPTT_PATH . 'includes/class-cptt-woocommerce.php';
require_once CPTT_PATH . 'includes/class-cptt-analytics.php';
require_once CPTT_PATH . 'includes/class-cptt-bale.php';
require_once CPTT_PATH . 'includes/class-cptt-auth.php';
require_once CPTT_PATH . 'includes/class-cptt-payment.php';
require_once CPTT_PATH . 'includes/class-cptt-form-builder.php';

register_activation_hook(__FILE__, ['CPTT_Core', 'activate']);
register_activation_hook(__FILE__, function(){
	if (class_exists('CPTT_Auth')) { CPTT_Auth::instance()->add_rewrites(); }
	if (class_exists('CPTT_Payment')) { CPTT_Payment::install_defaults(); }
	if (class_exists('CPTT_Form_Builder')) { CPTT_Form_Builder::install_defaults(); }
	flush_rewrite_rules(false);
});
register_deactivation_hook(__FILE__, function(){ flush_rewrite_rules(false); });
register_deactivation_hook(__FILE__, ['CPTT_Core', 'deactivate']);

add_action('plugins_loaded', function () {
	CPTT_Core::instance();
	CPTT_Currency::instance();
	CPTT_Admin::instance();
	CPTT_Frontend::instance();
	CPTT_Expert::instance();
	CPTT_Settings::instance();
	CPTT_Report::instance();
	CPTT_SMS::instance();
	CPTT_WooCommerce::instance();
	CPTT_Analytics::instance();
	CPTT_Bale::instance();
	CPTT_Auth::instance();
	CPTT_Payment::instance();
	CPTT_Form_Builder::instance();
});
