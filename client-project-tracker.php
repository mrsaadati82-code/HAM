<?php
/**
 * Plugin Name: هماهنگ - افزونه ی مدیریت پروژه و تیم
 * Description: هماهنگ، اولین افزونه ی اختصاصی ایرانی مدیریت پروژه است که امکاناتی فراتر از مدیریت پروژه دارد و مطابق با نیاز کسب و کار های ایرانی ساخته شده است. 
 * Version: 5.4.19
 * Author: امیرحسین سعادتی
 * Text Domain: cptt
 */

if ( ! defined('ABSPATH') ) exit;

define('CPTT_VERSION', '5.4.19');
define('CPTT_PATH', plugin_dir_path(__FILE__));
define('CPTT_URL', plugin_dir_url(__FILE__));

/* mPDF autoload if installed */
if (file_exists(CPTT_PATH . 'vendor/autoload.php')) {
	require_once CPTT_PATH . 'vendor/autoload.php';
}

require_once CPTT_PATH . 'includes/class-cptt-core.php';
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

register_activation_hook(__FILE__, ['CPTT_Core', 'activate']);
register_activation_hook(__FILE__, function(){
	if (class_exists('CPTT_Auth')) { CPTT_Auth::instance()->add_rewrites(); }
	flush_rewrite_rules(false);
});
register_deactivation_hook(__FILE__, function(){ flush_rewrite_rules(false); });
register_deactivation_hook(__FILE__, ['CPTT_Core', 'deactivate']);

add_action('plugins_loaded', function () {
	CPTT_Core::instance();
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
});