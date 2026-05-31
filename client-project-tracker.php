<?php
/**
 * Plugin Name: هماهنگ - افزونه ی مدیریت پروژه و تیم
 * Description: هماهنگ، اولین افزونه ی اختصاصی ایرانی مدیریت پروژه است که امکاناتی فراتر از مدیریت پروژه دارد و مطابق با نیاز کسب و کار های ایرانی ساخته شده است. 
 * Version: 5.4.39
 * Author: امیرحسین سعادتی
 * Text Domain: cptt
 */

if ( ! defined('ABSPATH') ) exit;

define('CPTT_VERSION', '5.4.39');
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
require_once CPTT_PATH . 'includes/class-cptt-form-builder.php';

register_activation_hook(__FILE__, ['CPTT_Core', 'activate']);
register_activation_hook(__FILE__, function(){
	if (class_exists('CPTT_Auth')) { CPTT_Auth::instance()->add_rewrites(); }
	if (class_exists('CPTT_Form_Builder')) { CPTT_Form_Builder::install_defaults(); }
	flush_rewrite_rules(false);
});
register_deactivation_hook(__FILE__, function(){ flush_rewrite_rules(false); });
register_deactivation_hook(__FILE__, ['CPTT_Core', 'deactivate']);



add_filter('admin_body_class', function($classes){
	$screen = function_exists('get_current_screen') ? get_current_screen() : null;
	$id = $screen ? (string)$screen->id : '';
	$post_type = $screen ? (string)$screen->post_type : '';
	$allowed_ids = ['cptt_project_page_cptt-project-dashboard','cptt_project_page_cptt-accounting','cptt_project_page_cptt-settings','cptt_project_page_cptt-sms-settings','cptt_project_page_cptt-project-labels','cptt_project_page_cptt-customers','cptt_project_page_cptt-experts-manage','cptt_project_page_cptt-experts-hub-settings','cptt_project_page_cptt-payments','cptt_project_page_cptt-form-builder','edit-cptt_order','cptt_order','cptt_template','cptt_checklist_tpl'];
	$allowed_posts = ['cptt_template','cptt_checklist_tpl','cptt_order'];
	if (in_array($id, $allowed_ids, true) || in_array($post_type, $allowed_posts, true)) $classes .= ' ham-admin-glass ';
	return $classes;
});

add_action('admin_enqueue_scripts', function($hook){
	global $post_type;
	$allowed_hooks = [
		'cptt_project_page_cptt-project-dashboard','cptt_project_page_cptt-accounting','cptt_project_page_cptt-settings',
		'cptt_project_page_cptt-sms-settings','cptt_project_page_cptt-project-labels','cptt_project_page_cptt-customers',
		'cptt_project_page_cptt-experts-manage','cptt_project_page_cptt-experts-hub-settings','cptt_project_page_cptt-payments',
		'cptt_project_page_cptt-form-builder'
	];
	$allowed_posts = ['cptt_template','cptt_checklist_tpl','cptt_order'];
	if (in_array($hook, $allowed_hooks, true) || in_array((string)$post_type, $allowed_posts, true)) {
		wp_enqueue_style('ham-admin-glass', CPTT_URL . 'assets/css/admin-glass.css', [], CPTT_VERSION);
		wp_add_inline_script('jquery-core', "document.addEventListener('DOMContentLoaded',function(){document.body.classList.add('ham-admin-glass');});");
	}
});

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
	CPTT_Form_Builder::instance();
});