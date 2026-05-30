<?php
/**
 * CPTT Currency Manager — v5.5.0
 * 
 * مدیریت یکپارچه‌ی واحد مالی (تومان/ریال/دلار/یورو) به‌صورت Display-Only.
 * 
 * - مقادیر دیتابیس همیشه روی واحد "پایه" (تومان) ذخیره می‌شوند.
 * - این کلاس فقط در نمایش، تبدیل واحد، فرمت عددی و برچسب‌ها دخالت می‌کند.
 * - اگر کاربر واحد را عوض کند، مثلاً 35,000 تومان به‌صورت 350,000 ریال یا 0.83 دلار نمایش داده می‌شود.
 */

if (!defined('ABSPATH')) exit;

class CPTT_Currency {
	private static $instance = null;
	const OPT_KEY = 'cptt_currency_settings';

	public static function instance() {
		if (self::$instance === null) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		// چیزی برای hook کردن نیست؛ همه‌چیز static است
	}

	/* =========================================================
	   تنظیمات
	   ========================================================= */
	public static function get_settings() {
		$defaults = [
			'unit'      => 'toman',     // toman | rial | usd | eur
			'usd_rate'  => 700000,      // 1 USD = ? toman (پایه)
			'eur_rate'  => 760000,      // 1 EUR = ? toman (پایه)
			'decimals'  => 0,           // تعداد رقم اعشار در نمایش
		];
		return wp_parse_args(get_option(self::OPT_KEY, []), $defaults);
	}

	public static function save_settings($data) {
		$old = self::get_settings();
		$new = wp_parse_args($data, $old);
		$new['unit'] = self::sanitize_unit($new['unit']);
		$new['usd_rate'] = max(1, (float)$new['usd_rate']);
		$new['eur_rate'] = max(1, (float)$new['eur_rate']);
		$new['decimals'] = max(0, min(4, (int)$new['decimals']));
		update_option(self::OPT_KEY, $new, false);
		return $new;
	}

	public static function sanitize_unit($u) {
		$u = strtolower(sanitize_key((string)$u));
		return in_array($u, ['toman','rial','usd','eur'], true) ? $u : 'toman';
	}

	public static function current_unit() {
		$s = self::get_settings();
		return $s['unit'];
	}

	/* =========================================================
	   برچسب‌ها (label/symbol)
	   ========================================================= */
	public static function label($unit = null, $form = 'long') {
		if ($unit === null) $unit = self::current_unit();
		$labels = [
			'toman' => ['long' => 'تومان', 'short' => 'تومان', 'sym' => 'T'],
			'rial'  => ['long' => 'ریال',  'short' => 'ریال',  'sym' => '﷼'],
			'usd'   => ['long' => 'دلار',  'short' => 'دلار',  'sym' => '$'],
			'eur'   => ['long' => 'یورو',  'short' => 'یورو',  'sym' => '€'],
		];
		$entry = $labels[$unit] ?? $labels['toman'];
		return $entry[$form] ?? $entry['long'];
	}

	/* =========================================================
	   تبدیل از واحد پایه (تومان) به واحد جاری
	   ========================================================= */
	public static function from_base($amount_toman, $unit = null) {
		$amount_toman = (float)$amount_toman;
		if ($unit === null) $unit = self::current_unit();
		$s = self::get_settings();
		switch ($unit) {
			case 'rial': return $amount_toman * 10;
			case 'usd':  return $s['usd_rate'] > 0 ? $amount_toman / $s['usd_rate'] : 0;
			case 'eur':  return $s['eur_rate'] > 0 ? $amount_toman / $s['eur_rate'] : 0;
			case 'toman':
			default:     return $amount_toman;
		}
	}

	/* =========================================================
	   تبدیل از واحد دلخواه به واحد پایه (تومان) — برای ذخیره
	   ========================================================= */
	public static function to_base($amount, $unit = null) {
		$amount = (float)$amount;
		if ($unit === null) $unit = self::current_unit();
		$s = self::get_settings();
		switch ($unit) {
			case 'rial': return $amount / 10;
			case 'usd':  return $amount * $s['usd_rate'];
			case 'eur':  return $amount * $s['eur_rate'];
			case 'toman':
			default:     return $amount;
		}
	}

	/* =========================================================
	   فرمت نمایش با برچسب
	   ========================================================= */
	public static function format($amount_toman, $with_label = true, $unit = null) {
		if ($unit === null) $unit = self::current_unit();
		$converted = self::from_base($amount_toman, $unit);
		$s = self::get_settings();
		$decimals = in_array($unit, ['usd','eur'], true) ? max(2, $s['decimals']) : $s['decimals'];
		$num = number_format($converted, $decimals, '.', ',');
		if (!$with_label) return $num;
		return $num . ' ' . self::label($unit);
	}

	/**
	 * فرمت بدون برچسب، فقط عدد
	 */
	public static function number($amount_toman, $unit = null) {
		return self::format($amount_toman, false, $unit);
	}

	/**
	 * تبدیل ورودی متنی کاربر (با کاما/فاصله) به عدد در واحد پایه
	 */
	public static function parse_input($input_str, $unit = null) {
		$clean = preg_replace('/[^0-9\.\-]/', '', (string)$input_str);
		if ($clean === '' || $clean === '-' || $clean === '.') return 0;
		return self::to_base((float)$clean, $unit);
	}
}
