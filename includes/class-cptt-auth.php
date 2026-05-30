<?php
/**
 * CPTT Auth — Login / Register / Forgot / Bale-OTP
 *
 * v5.4.5
 *  - مینیمال، فونت Vazirmatn از همان افزونه
 *  - ۳ تب: ورود / ثبت‌نام / فراموشی رمز
 *  - ورود معمولی با (نام کاربری/ایمیل/شماره موبایل) + رمز عبور
 *  - ورود سریع با کد یک‌بارمصرف بله
 *  - ثبت‌نام: نام/نام‌خانوادگی/موبایل/ایمیل/نام‌کاربری/رمز/تکرار
 *  - فراموشی رمز: شماره موبایل → کد بله → رمز جدید
 *  - تایید موبایل پس از ثبت‌نام از پنل کاربری: نوتیس بنفش + Modal با دکمه «اتصال به بله»
 *  - ریدایرکت پس از ورود: مشتری → my-account، کارشناس → داشبورد کارشناس، ادمین → /wp-admin
 */

if (!defined('ABSPATH')) exit;

class CPTT_Auth {

	private static $instance = null;
	const OTP_OPT_PREFIX = 'cptt_otp_';
	const OTP_TTL        = 300; // ۵ دقیقه
	const RATE_LIMIT_SEC = 60;

	public static function instance() {
		if (self::$instance === null) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		// AJAX endpoints
		foreach (['cptt_auth_login', 'cptt_auth_register', 'cptt_auth_forgot_request', 'cptt_auth_forgot_verify',
			'cptt_auth_request_otp', 'cptt_auth_verify_otp', 'cptt_auth_link_request', 'cptt_auth_link_verify'] as $action) {
			add_action('wp_ajax_nopriv_' . $action, [$this, 'ajax_' . substr($action, 5)]);
			add_action('wp_ajax_' . $action,        [$this, 'ajax_' . substr($action, 5)]);
		}

		// Rewrite + template
		add_action('init',                  [$this, 'add_rewrites']);
		add_filter('query_vars',            [$this, 'add_query_vars']);
		add_action('template_redirect',     [$this, 'maybe_render_login_page']);

		// Force-redirect WP login → custom
		add_action('login_init',            [$this, 'maybe_force_redirect_login']);

		// Account verification notice + modal
		add_action('woocommerce_account_dashboard',  [$this, 'render_phone_verify_notice']);
		add_action('cptt_dashboard_top_notice',      [$this, 'render_phone_verify_notice']);
		add_action('wp_footer',                      [$this, 'render_phone_verify_modal']);
		add_action('wp_enqueue_scripts',             [$this, 'maybe_enqueue_modal_assets']);
	}

	/* ====================================================================
	 * Rewrites + page rendering
	 * ==================================================================== */
	public function add_rewrites() {
		add_rewrite_rule('^cptt-login/?$', 'index.php?cptt_login=1', 'top');
	}
	public function add_query_vars($vars) { $vars[] = 'cptt_login'; return $vars; }

	public function maybe_render_login_page() {
		$is_qv  = (int) get_query_var('cptt_login') === 1;
		$is_uri = isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'cptt-login') !== false;
		if (!$is_qv && !$is_uri) return;
		if (is_user_logged_in()) {
			$redir = $this->get_redirect_for(wp_get_current_user(), !empty($_GET['redirect_to']) ? esc_url_raw($_GET['redirect_to']) : '');
			wp_safe_redirect($redir); exit;
		}
		status_header(200);
		nocache_headers();
		$this->render_login_page();
		exit;
	}

	public function maybe_force_redirect_login() {
		if (!$this->is_enabled() || !$this->is_only_mode()) return;
		$allowed = ['logout', 'loggedout', 'rp', 'resetpass', 'postpass'];
		$action  = isset($_REQUEST['action']) ? sanitize_key($_REQUEST['action']) : '';
		if (in_array($action, $allowed, true)) return;
		if ($_SERVER['REQUEST_METHOD'] === 'POST') return;
		$redirect_to = !empty($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : '';
		$url = home_url('/cptt-login/');
		if ($redirect_to) $url = add_query_arg('redirect_to', urlencode($redirect_to), $url);
		wp_safe_redirect($url); exit;
	}

	/* ====================================================================
	 * Helpers
	 * ==================================================================== */
	private function settings() {
		return class_exists('CPTT_Bale') ? CPTT_Bale::get_settings() : [];
	}
	public function is_enabled() {
		$s = $this->settings();
		return !empty($s['enable_otp_login']) && $s['enable_otp_login'] === '1';
	}
	public function is_only_mode() {
		$s = $this->settings();
		return !empty($s['otp_login_only']) && $s['otp_login_only'] === '1';
	}

	private function normalize_phone($raw) {
		$p = $this->to_english_digits($raw);
		$p = preg_replace('/[\s\-]/', '', $p);
		if (preg_match('/^9\d{9}$/', $p)) $p = '0' . $p;
		if (preg_match('/^\+989\d{9}$/', $p)) $p = '0' . substr($p, 3);
		if (preg_match('/^00989\d{9}$/', $p)) $p = '0' . substr($p, 4);
		return $p;
	}
	private function is_valid_phone($p) { return (bool) preg_match('/^09\d{9}$/', $p); }
	private function to_english_digits($s) {
		$p = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
		$a = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
		$e = range(0, 9);
		$s = str_replace($p, $e, (string)$s);
		return str_replace($a, $e, $s);
	}
	private function generate_otp() {
		return str_pad((string) wp_rand(0, 99999), 5, '0', STR_PAD_LEFT);
	}
	private function find_user_by_phone($phone) {
		$users = get_users(['meta_key' => 'billing_phone',  'meta_value' => $phone, 'number' => 1]);
		if (!empty($users)) return $users[0];
		$users = get_users(['meta_key' => 'cptt_user_phone', 'meta_value' => $phone, 'number' => 1]);
		if (!empty($users)) return $users[0];
		$u = get_user_by('login', $phone);
		if ($u) return $u;
		return null;
	}
	private function find_user_by_identifier($id) {
		$id = trim($id);
		if ($id === '') return null;
		$u = get_user_by('login', $id);
		if ($u) return $u;
		$u = get_user_by('email', $id);
		if ($u) return $u;
		$ph = $this->normalize_phone($id);
		if ($this->is_valid_phone($ph)) {
			$u = $this->find_user_by_phone($ph);
			if ($u) return $u;
		}
		return null;
	}
	private function user_role($user) {
		$roles = (array)$user->roles;
		if (in_array('administrator', $roles, true)) return 'admin';
		if (in_array('cptt_expert', $roles, true))   return 'expert';
		return 'customer';
	}
	private function get_redirect_for($user, $requested = '') {
		if ($requested) return $requested;
		$role = $this->user_role($user);
		if ($role === 'admin')  return admin_url();
		if ($role === 'expert') {
			if (class_exists('CPTT_Expert') && method_exists('CPTT_Expert', 'dashboard_url')) {
				$u = CPTT_Expert::dashboard_url();
				if ($u) return $u;
			}
			return home_url('/expert-dashboard/');
		}
		// customer
		if (function_exists('wc_get_account_endpoint_url')) return wc_get_account_endpoint_url('dashboard');
		if (function_exists('wc_get_page_permalink'))       return wc_get_page_permalink('myaccount');
		return home_url('/my-account/');
	}
	private function get_bot_username() {
		$s = $this->settings();
		$token = isset($s['token']) ? trim($s['token']) : '';
		if ($token === '') return '';
		$cached = get_transient('cptt_bale_bot_username');
		if ($cached) return $cached;
		$res = wp_remote_get('https://tapi.bale.ai/bot' . $token . '/getMe', ['sslverify' => false, 'timeout' => 10]);
		if (is_wp_error($res)) return '';
		$body = json_decode(wp_remote_retrieve_body($res), true);
		if (!empty($body['ok']) && !empty($body['result']['username'])) {
			$u = $body['result']['username'];
			set_transient('cptt_bale_bot_username', $u, DAY_IN_SECONDS);
			return $u;
		}
		return '';
	}

	private function rate_limit_check($key) {
		$last = (int) get_transient($key);
		if ($last && (time() - $last) < self::RATE_LIMIT_SEC) {
			return self::RATE_LIMIT_SEC - (time() - $last);
		}
		return 0;
	}
	private function rate_limit_set($key) {
		set_transient($key, time(), self::RATE_LIMIT_SEC);
	}

	/* ====================================================================
	 * AJAX: login (normal)
	 * ==================================================================== */
	public function ajax_auth_login() {
		check_ajax_referer('cptt_auth_nonce', 'nonce');
		$identifier = isset($_POST['identifier']) ? sanitize_text_field((string)$_POST['identifier']) : '';
		$password   = isset($_POST['password']) ? (string)$_POST['password'] : '';
		$remember   = !empty($_POST['remember']);

		if ($identifier === '' || $password === '') {
			wp_send_json_error('شناسه و رمز عبور را وارد کنید.', 400);
		}
		$user = $this->find_user_by_identifier($identifier);
		if (!$user) wp_send_json_error('کاربری با این مشخصات یافت نشد.', 404);

		$creds = ['user_login' => $user->user_login, 'user_password' => $password, 'remember' => $remember];
		$signed = wp_signon($creds, is_ssl());
		if (is_wp_error($signed)) {
			wp_send_json_error('رمز عبور اشتباه است.', 400);
		}
		$redirect = $this->get_redirect_for($signed, !empty($_POST['redirect_to']) ? esc_url_raw($_POST['redirect_to']) : '');
		wp_send_json_success(['redirect' => $redirect]);
	}

	/* ====================================================================
	 * AJAX: register
	 * ==================================================================== */
	public function ajax_auth_register() {
		check_ajax_referer('cptt_auth_nonce', 'nonce');
		$first  = isset($_POST['first_name']) ? sanitize_text_field((string)$_POST['first_name']) : '';
		$last   = isset($_POST['last_name'])  ? sanitize_text_field((string)$_POST['last_name'])  : '';
		$phone  = $this->normalize_phone(isset($_POST['phone']) ? (string)$_POST['phone'] : '');
		$email  = isset($_POST['email'])      ? sanitize_email((string)$_POST['email'])           : '';
		$login  = isset($_POST['username'])   ? sanitize_user((string)$_POST['username'], true)   : '';
		$pass   = isset($_POST['password'])   ? (string)$_POST['password']                        : '';
		$pass2  = isset($_POST['password2'])  ? (string)$_POST['password2']                       : '';

		if ($first === '')                 wp_send_json_error('نام را وارد کنید.', 400);
		if ($last === '')                  wp_send_json_error('نام خانوادگی را وارد کنید.', 400);
		if (!$this->is_valid_phone($phone)) wp_send_json_error('شماره موبایل ۱۱ رقمی معتبر وارد کنید.', 400);
		if (!is_email($email))             wp_send_json_error('ایمیل نامعتبر است.', 400);
		if ($login === '' || strlen($login) < 3) wp_send_json_error('نام کاربری حداقل ۳ کاراکتر.', 400);
		if (strlen($pass) < 6)             wp_send_json_error('رمز عبور حداقل ۶ کاراکتر.', 400);
		if ($pass !== $pass2)              wp_send_json_error('رمز و تکرار رمز یکسان نیستند.', 400);

		if (username_exists($login)) wp_send_json_error('این نام کاربری قبلاً ثبت شده است.', 400);
		if (email_exists($email))     wp_send_json_error('این ایمیل قبلاً ثبت شده است.', 400);
		if ($this->find_user_by_phone($phone)) wp_send_json_error('این شماره موبایل قبلاً ثبت شده است.', 400);

		$user_id = wp_insert_user([
			'user_login'   => $login,
			'user_pass'    => $pass,
			'user_email'   => $email,
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => trim($first . ' ' . $last),
			'role'         => 'customer',
		]);
		if (is_wp_error($user_id)) wp_send_json_error('خطا در ثبت کاربر: ' . $user_id->get_error_message(), 500);

		update_user_meta($user_id, 'billing_phone',     $phone);
		update_user_meta($user_id, 'cptt_user_phone',   $phone);
		update_user_meta($user_id, 'billing_first_name', $first);
		update_user_meta($user_id, 'billing_last_name',  $last);
		update_user_meta($user_id, 'billing_email',      $email);
		update_user_meta($user_id, '_cptt_phone_verified', '0');
		update_user_meta($user_id, '_cptt_needs_bale_link', '1');

		// auto-login
		wp_set_current_user($user_id);
		wp_set_auth_cookie($user_id, true);

		$user = get_user_by('id', $user_id);
		$redirect = $this->get_redirect_for($user, !empty($_POST['redirect_to']) ? esc_url_raw($_POST['redirect_to']) : '');
		wp_send_json_success(['redirect' => $redirect, 'needs_bale_link' => true]);
	}

	/* ====================================================================
	 * AJAX: forgot password — request OTP
	 * ==================================================================== */
	public function ajax_auth_forgot_request() {
		check_ajax_referer('cptt_auth_nonce', 'nonce');
		$phone = $this->normalize_phone(isset($_POST['phone']) ? (string)$_POST['phone'] : '');
		if (!$this->is_valid_phone($phone)) wp_send_json_error('شماره موبایل نامعتبر است.', 400);

		$key = self::OTP_OPT_PREFIX . 'fp_rl_' . md5($phone);
		$wait = $this->rate_limit_check($key);
		if ($wait > 0) wp_send_json_error("لطفاً {$wait} ثانیه دیگر دوباره تلاش کنید.", 429);

		$user = $this->find_user_by_phone($phone);
		if (!$user) wp_send_json_error('کاربری با این شماره ثبت نشده است.', 404);

		$chat_id = get_user_meta($user->ID, '_cptt_bale_chat_id', true);
		if (!$chat_id) {
			wp_send_json_error([
				'message' => 'حساب شما به ربات بله متصل نیست. برای بازیابی رمز، ابتدا با همان حساب وارد شده و در پنل، حساب خود را به بله متصل کنید.',
				'need_link' => true,
				'bot_username' => $this->get_bot_username(),
			], 400);
		}

		$otp = $this->generate_otp();
		set_transient(self::OTP_OPT_PREFIX . 'fp_' . md5($phone), [
			'hash' => wp_hash_password($otp), 'user_id' => (int)$user->ID,
			'created' => time(), 'tries' => 0,
		], self::OTP_TTL);
		$this->rate_limit_set($key);

		if (class_exists('CPTT_Bale')) {
			CPTT_Bale::send_message($chat_id,
				"🔑 *کد بازیابی رمز عبور*\n\nکد یک‌بارمصرف شما: `{$otp}`\n\n⏱ این کد ۵ دقیقه اعتبار دارد.\n_اگر شما درخواست بازیابی نکرده‌اید، این پیام را نادیده بگیرید._"
			);
		}
		wp_send_json_success(['message' => 'کد به ربات بله شما ارسال شد.']);
	}

	/* ====================================================================
	 * AJAX: forgot password — verify OTP + set new password
	 * ==================================================================== */
	public function ajax_auth_forgot_verify() {
		check_ajax_referer('cptt_auth_nonce', 'nonce');
		$phone = $this->normalize_phone(isset($_POST['phone']) ? (string)$_POST['phone'] : '');
		$code  = $this->to_english_digits(isset($_POST['code']) ? trim((string)$_POST['code']) : '');
		$pass  = isset($_POST['password']) ? (string)$_POST['password'] : '';
		$pass2 = isset($_POST['password2']) ? (string)$_POST['password2'] : '';

		if (!$this->is_valid_phone($phone)) wp_send_json_error('شماره نامعتبر.', 400);
		if (!preg_match('/^\d{5}$/', $code)) wp_send_json_error('کد ۵ رقمی را وارد کنید.', 400);
		if (strlen($pass) < 6) wp_send_json_error('رمز جدید حداقل ۶ کاراکتر باشد.', 400);
		if ($pass !== $pass2) wp_send_json_error('رمز و تکرار رمز یکسان نیستند.', 400);

		$key = self::OTP_OPT_PREFIX . 'fp_' . md5($phone);
		$rec = get_transient($key);
		if (!is_array($rec) || empty($rec['hash'])) wp_send_json_error('کد منقضی شده است.', 400);
		$tries = (int)($rec['tries'] ?? 0);
		if ($tries >= 5) { delete_transient($key); wp_send_json_error('تعداد تلاش بیش از حد.', 400); }
		if (!wp_check_password($code, $rec['hash'])) {
			$rec['tries'] = $tries + 1;
			set_transient($key, $rec, self::OTP_TTL);
			wp_send_json_error('کد اشتباه. (' . (5 - $rec['tries']) . ' تلاش باقی‌مانده)', 400);
		}
		$user_id = (int)($rec['user_id'] ?? 0);
		if (!$user_id) wp_send_json_error('خطا.', 500);
		delete_transient($key);

		wp_set_password($pass, $user_id);
		// auto-login
		wp_set_current_user($user_id);
		wp_set_auth_cookie($user_id, true);
		$user = get_user_by('id', $user_id);
		$redirect = $this->get_redirect_for($user, !empty($_POST['redirect_to']) ? esc_url_raw($_POST['redirect_to']) : '');
		wp_send_json_success(['redirect' => $redirect, 'message' => 'رمز با موفقیت تغییر کرد.']);
	}

	/* ====================================================================
	 * AJAX: login via Bale OTP — request
	 * ==================================================================== */
	public function ajax_auth_request_otp() {
		check_ajax_referer('cptt_auth_nonce', 'nonce');
		$phone = $this->normalize_phone(isset($_POST['phone']) ? (string)$_POST['phone'] : '');
		if (!$this->is_valid_phone($phone)) wp_send_json_error('شماره موبایل نامعتبر است.', 400);

		$rate_key = self::OTP_OPT_PREFIX . 'rl_' . md5($phone);
		$wait = $this->rate_limit_check($rate_key);
		if ($wait > 0) wp_send_json_error("لطفاً {$wait} ثانیه دیگر تلاش کنید.", 429);

		$user = $this->find_user_by_phone($phone);
		if (!$user) wp_send_json_error('کاربری با این شماره ثبت نشده است. لطفاً ابتدا ثبت‌نام کنید.', 404);

		$chat_id = get_user_meta($user->ID, '_cptt_bale_chat_id', true);
		if (!$chat_id) {
			wp_send_json_error([
				'message' => 'حساب شما به ربات بله متصل نیست. ابتدا با رمز عبور وارد شوید و در پنل کاربری، حساب خود را به بله متصل کنید.',
				'need_link' => true,
				'bot_username' => $this->get_bot_username(),
			], 400);
		}

		$otp = $this->generate_otp();
		set_transient(self::OTP_OPT_PREFIX . md5($phone), [
			'hash' => wp_hash_password($otp), 'user_id' => (int)$user->ID,
			'created' => time(), 'tries' => 0,
		], self::OTP_TTL);
		$this->rate_limit_set($rate_key);

		if (class_exists('CPTT_Bale')) {
			CPTT_Bale::send_message($chat_id,
				"🔐 *کد ورود به سایت*\n\nکد یک‌بارمصرف شما: `{$otp}`\n\n⏱ این کد ۵ دقیقه اعتبار دارد."
			);
		}
		wp_send_json_success(['message' => 'کد به ربات بله شما ارسال شد.']);
	}

	/* ====================================================================
	 * AJAX: login via Bale OTP — verify
	 * ==================================================================== */
	public function ajax_auth_verify_otp() {
		check_ajax_referer('cptt_auth_nonce', 'nonce');
		$phone = $this->normalize_phone(isset($_POST['phone']) ? (string)$_POST['phone'] : '');
		$code  = $this->to_english_digits(isset($_POST['code']) ? trim((string)$_POST['code']) : '');
		if (!$this->is_valid_phone($phone)) wp_send_json_error('شماره نامعتبر.', 400);
		if (!preg_match('/^\d{5}$/', $code)) wp_send_json_error('کد ۵ رقمی را وارد کنید.', 400);

		$key = self::OTP_OPT_PREFIX . md5($phone);
		$rec = get_transient($key);
		if (!is_array($rec) || empty($rec['hash'])) wp_send_json_error('کد منقضی شده است.', 400);
		$tries = (int)($rec['tries'] ?? 0);
		if ($tries >= 5) { delete_transient($key); wp_send_json_error('تعداد تلاش بیش از حد.', 400); }
		if (!wp_check_password($code, $rec['hash'])) {
			$rec['tries'] = $tries + 1;
			set_transient($key, $rec, self::OTP_TTL);
			wp_send_json_error('کد اشتباه. (' . (5 - $rec['tries']) . ' تلاش باقی‌مانده)', 400);
		}
		$user_id = (int)($rec['user_id'] ?? 0);
		if (!$user_id) wp_send_json_error('خطا.', 500);
		delete_transient($key);

		wp_set_current_user($user_id);
		wp_set_auth_cookie($user_id, true);
		$user = get_user_by('id', $user_id);
		$redirect = $this->get_redirect_for($user, !empty($_POST['redirect_to']) ? esc_url_raw($_POST['redirect_to']) : '');
		wp_send_json_success(['redirect' => $redirect, 'message' => 'ورود موفق.']);
	}

	/* ====================================================================
	 * AJAX: Link account (verify phone) — request
	 * برای کاربری که قبلاً ثبت‌نام کرده ولی بله وصل نکرده.
	 * ==================================================================== */
	public function ajax_auth_link_request() {
		check_ajax_referer('cptt_auth_nonce', 'nonce');
		if (!is_user_logged_in()) wp_send_json_error('ابتدا وارد شوید.', 401);
		$user = wp_get_current_user();
		$phone = $this->normalize_phone(get_user_meta($user->ID, 'billing_phone', true) ?: get_user_meta($user->ID, 'cptt_user_phone', true) ?: '');
		if (!$this->is_valid_phone($phone)) wp_send_json_error('شماره موبایل شما در پروفایل ثبت نیست.', 400);

		// در این مرحله کاربر *هنوز* به بله وصل نکرده. فقط دستورالعمل می‌دهیم.
		$bot = $this->get_bot_username();
		wp_send_json_success([
			'phone'       => $phone,
			'bot_username'=> $bot,
			'bot_link'    => $bot ? ('https://ble.ir/' . $bot) : '',
			'message'     => 'برای تایید موبایل، در ربات بله /start بزنید و شماره موبایل خود را ارسال کنید.',
		]);
	}

	/* ====================================================================
	 * AJAX: Link verify — check if bale linked
	 * ==================================================================== */
	public function ajax_auth_link_verify() {
		check_ajax_referer('cptt_auth_nonce', 'nonce');
		if (!is_user_logged_in()) wp_send_json_error('ابتدا وارد شوید.', 401);
		$user = wp_get_current_user();
		$chat = get_user_meta($user->ID, '_cptt_bale_chat_id', true);
		if ($chat) {
			update_user_meta($user->ID, '_cptt_phone_verified', '1');
			update_user_meta($user->ID, '_cptt_needs_bale_link', '0');
			wp_send_json_success(['verified' => true, 'message' => '✅ حساب شما با موفقیت تایید و به بله متصل شد.']);
		}
		wp_send_json_error(['verified' => false, 'message' => 'هنوز اتصال ثبت نشده. لطفاً مراحل را در ربات کامل کنید.'], 200);
	}

	/* ====================================================================
	 * Account verify notice + modal (loaded on frontend everywhere; appears only when needed)
	 * ==================================================================== */
	public function maybe_enqueue_modal_assets() {
		if (!is_user_logged_in()) return;
		$user = wp_get_current_user();
		$needs = get_user_meta($user->ID, '_cptt_needs_bale_link', true);
		$has   = get_user_meta($user->ID, '_cptt_bale_chat_id', true);
		if ($needs === '1' && !$has) {
			// just print inline assets via wp_footer
			add_action('wp_print_footer_scripts', [$this, 'print_verify_modal_inline'], 99);
		}
	}

	public function render_phone_verify_notice() {
		if (!is_user_logged_in()) return;
		$user = wp_get_current_user();
		$needs = get_user_meta($user->ID, '_cptt_needs_bale_link', true);
		$has   = get_user_meta($user->ID, '_cptt_bale_chat_id', true);
		if ($needs !== '1' || $has) return;
		?>
		<div class="cptt-verify-notice" style="background:linear-gradient(135deg,#fef3c7,#fde68a);border:1px solid #f59e0b;border-radius:12px;padding:14px 18px;margin:16px 0;display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-family:Vazirmatn,Tahoma,sans-serif;">
			<div style="font-size:28px;line-height:1;">📱</div>
			<div style="flex:1;min-width:200px;">
				<div style="font-weight:900;color:#92400e;margin-bottom:4px;">تایید موبایل شما لازم است</div>
				<div style="font-size:13px;color:#78350f;">برای فعال‌سازی کامل حساب و دریافت اعلان‌ها، شماره موبایل خود را در ربات بله تایید کنید.</div>
			</div>
			<button type="button" id="cptt-open-verify-modal" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;padding:10px 18px;border-radius:10px;font-weight:900;cursor:pointer;font-family:inherit;font-size:13px;box-shadow:0 6px 14px rgba(99,102,241,.3);">✅ تایید حساب</button>
		</div>
		<?php
	}

	public function render_phone_verify_modal() {
		// markup is rendered via inline script when needed
	}

	public function print_verify_modal_inline() {
		$nonce = wp_create_nonce('cptt_auth_nonce');
		$ajax  = admin_url('admin-ajax.php');
		?>
		<style>
		.cptt-verify-modal-wrap { position:fixed; inset:0; z-index:2147483646; display:none; align-items:center; justify-content:center; padding:16px; background:rgba(15,23,42,.65); backdrop-filter:blur(6px); font-family:Vazirmatn,Tahoma,sans-serif; direction:rtl; }
		.cptt-verify-modal-wrap.is-open { display:flex; }
		.cptt-verify-modal { background:#fff; border-radius:18px; max-width:460px; width:100%; padding:28px; box-shadow:0 30px 60px rgba(0,0,0,.3); text-align:center; }
		.cptt-verify-modal h3 { margin:8px 0 6px; font-size:18px; font-weight:900; color:#0f172a; }
		.cptt-verify-modal p { color:#475569; font-size:13px; line-height:1.8; margin:0 0 14px; }
		.cptt-verify-step { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:10px 14px; margin:8px 0; text-align:right; font-size:13px; color:#334155; }
		.cptt-verify-step b { color:#6366f1; }
		.cptt-verify-actions { display:flex; gap:8px; margin-top:18px; flex-wrap:wrap; }
		.cptt-verify-btn { flex:1; min-width:140px; padding:12px; border-radius:10px; border:none; font-weight:900; cursor:pointer; font-family:inherit; font-size:13px; }
		.cptt-verify-btn--primary { background:linear-gradient(135deg,#1d4ed8,#3b82f6); color:#fff; }
		.cptt-verify-btn--check   { background:linear-gradient(135deg,#059669,#10b981); color:#fff; }
		.cptt-verify-btn--ghost   { background:#fff; color:#334155; border:1px solid #cbd5e1; }
		.cptt-verify-msg { margin-top:10px; font-size:12px; padding:8px 12px; border-radius:8px; }
		.cptt-verify-msg.err { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
		.cptt-verify-msg.ok  { background:#f0fdf4; color:#065f46; border:1px solid #bbf7d0; }
		</style>
		<div class="cptt-verify-modal-wrap" id="cptt-verify-modal-wrap">
			<div class="cptt-verify-modal">
				<div style="font-size:42px;">📱</div>
				<h3>تایید موبایل از طریق ربات بله</h3>
				<p>برای فعال‌سازی کامل حساب، لطفاً مراحل زیر را در ربات بله انجام دهید:</p>
				<div class="cptt-verify-step"><b>۱.</b> روی دکمه «رفتن به ربات بله» کلیک کنید.</div>
				<div class="cptt-verify-step"><b>۲.</b> در ربات دستور <code style="background:#fff;padding:2px 6px;border-radius:4px;border:1px solid #e2e8f0;">/start</code> را ارسال کنید.</div>
				<div class="cptt-verify-step"><b>۳.</b> شماره موبایل ثبت‌شده در پروفایل را ارسال کنید.</div>
				<div class="cptt-verify-step"><b>۴.</b> به این صفحه برگردید و «بررسی وضعیت» را بزنید.</div>
				<div class="cptt-verify-actions">
					<a id="cptt-verify-goto-bot" href="#" target="_blank" rel="noopener" class="cptt-verify-btn cptt-verify-btn--primary" style="text-decoration:none;display:flex;align-items:center;justify-content:center;">🤖 رفتن به ربات بله</a>
					<button type="button" class="cptt-verify-btn cptt-verify-btn--check" id="cptt-verify-check">✅ بررسی وضعیت</button>
				</div>
				<div class="cptt-verify-actions">
					<button type="button" class="cptt-verify-btn cptt-verify-btn--ghost" id="cptt-verify-close">بستن</button>
				</div>
				<div id="cptt-verify-msg" style="display:none;"></div>
			</div>
		</div>
		<script>
		(function(){
			var AJAX = <?php echo wp_json_encode($ajax); ?>;
			var NONCE = <?php echo wp_json_encode($nonce); ?>;
			var openers = document.querySelectorAll('#cptt-open-verify-modal');
			var wrap = document.getElementById('cptt-verify-modal-wrap');
			if (!wrap) return;
			var msg = document.getElementById('cptt-verify-msg');
			function open(){ wrap.classList.add('is-open'); loadInfo(); }
			function close(){ wrap.classList.remove('is-open'); }
			function setMsg(t, kind){ msg.style.display='block'; msg.className='cptt-verify-msg '+(kind||'ok'); msg.textContent=t; }
			function clearMsg(){ msg.style.display='none'; }
			openers.forEach(function(b){ b.addEventListener('click', open); });
			wrap.addEventListener('click', function(e){ if (e.target === wrap) close(); });
			document.getElementById('cptt-verify-close').addEventListener('click', close);

			function loadInfo() {
				clearMsg();
				var fd = new FormData();
				fd.append('action', 'cptt_auth_link_request');
				fd.append('nonce', NONCE);
				fetch(AJAX, {method:'POST', credentials:'same-origin', body:fd})
				.then(function(r){return r.json();})
				.then(function(j){
					if (j && j.success && j.data && j.data.bot_link) {
						document.getElementById('cptt-verify-goto-bot').href = j.data.bot_link;
					}
				}).catch(function(){});
			}
			document.getElementById('cptt-verify-check').addEventListener('click', function(){
				var btn = this; btn.disabled = true; btn.textContent = 'در حال بررسی...';
				var fd = new FormData();
				fd.append('action', 'cptt_auth_link_verify');
				fd.append('nonce', NONCE);
				fetch(AJAX, {method:'POST', credentials:'same-origin', body:fd})
				.then(function(r){return r.json();})
				.then(function(j){
					btn.disabled = false; btn.textContent = '✅ بررسی وضعیت';
					if (j && j.success && j.data && j.data.verified) {
						setMsg(j.data.message || 'تایید شد.', 'ok');
						setTimeout(function(){ window.location.reload(); }, 1200);
					} else {
						var m = (j && j.data && j.data.message) || 'هنوز تایید نشده.';
						setMsg(m, 'err');
					}
				}).catch(function(){
					btn.disabled = false; btn.textContent = '✅ بررسی وضعیت';
					setMsg('خطای شبکه.', 'err');
				});
			});
		})();
		</script>
		<?php
	}

	/* ====================================================================
	 * The login page (minimal, with tabs)
	 * ==================================================================== */
	public function render_login_page() {
		$ajax  = admin_url('admin-ajax.php');
		$nonce = wp_create_nonce('cptt_auth_nonce');
		$bot_username = $this->get_bot_username();
		$bot_link     = $bot_username ? ('https://ble.ir/' . $bot_username) : '';
		$redirect_to  = !empty($_GET['redirect_to']) ? esc_url($_GET['redirect_to']) : '';
		$site_name    = get_bloginfo('name');
		$initial_tab  = !empty($_GET['tab']) ? sanitize_key($_GET['tab']) : 'login';
		if (!in_array($initial_tab, ['login','register','forgot'], true)) $initial_tab = 'login';
		$font_url = CPTT_URL . 'assets/fonts/Vazirmatn-Regular.ttf';
		$font_bold_url = CPTT_URL . 'assets/fonts/Vazirmatn-Bold.ttf';
		?><!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>ورود به <?php echo esc_html($site_name); ?></title>
<style>
@font-face {
	font-family: 'Vazirmatn';
	src: url('<?php echo esc_url($font_url); ?>') format('truetype');
	font-weight: 400;
	font-style: normal;
	font-display: swap;
}
@font-face {
	font-family: 'Vazirmatn';
	src: url('<?php echo esc_url($font_bold_url); ?>') format('truetype');
	font-weight: 700;
	font-style: normal;
	font-display: swap;
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; height: 100%; font-family: 'Vazirmatn', system-ui, -apple-system, Tahoma, sans-serif; }
body {
	background: #fafafa;
	min-height: 100vh;
	display: flex; align-items: center; justify-content: center;
	padding: 20px;
	color: #18181b;
	-webkit-font-smoothing: antialiased;
}
.cptt-auth-shell {
	background: #fff;
	border-radius: 16px;
	padding: 36px 32px 32px;
	width: 100%;
	max-width: 400px;
	box-shadow: 0 1px 3px rgba(0,0,0,0.05), 0 10px 40px rgba(0,0,0,0.08);
	border: 1px solid #f4f4f5;
}
.cptt-auth-brand {
	text-align: center;
	margin-bottom: 24px;
}
.cptt-auth-brand-mark {
	display: inline-flex; align-items: center; justify-content: center;
	width: 44px; height: 44px;
	background: #18181b;
	border-radius: 12px;
	color: #fff;
	font-size: 20px;
	margin-bottom: 10px;
}
.cptt-auth-brand h1 {
	font-size: 17px; font-weight: 700; margin: 0; color: #18181b; letter-spacing: -.01em;
}
.cptt-auth-brand p { margin: 4px 0 0; color: #71717a; font-size: 12.5px; }

/* Tabs */
.cptt-tabs {
	display: flex; gap: 4px;
	background: #f4f4f5;
	padding: 4px;
	border-radius: 10px;
	margin-bottom: 20px;
}
.cptt-tab {
	flex: 1;
	background: transparent;
	border: none;
	padding: 8px 6px;
	font-size: 12.5px;
	font-weight: 600;
	color: #71717a;
	cursor: pointer;
	border-radius: 7px;
	transition: all .15s ease;
	font-family: inherit;
}
.cptt-tab:hover { color: #18181b; }
.cptt-tab.is-active { background: #fff; color: #18181b; box-shadow: 0 1px 2px rgba(0,0,0,0.06); }

.cptt-pane { display: none; }
.cptt-pane.is-active { display: block; animation: fadein .25s ease; }
@keyframes fadein { from{opacity:0;transform:translateY(4px)} to{opacity:1;transform:translateY(0)} }

/* Form fields */
.cptt-field { margin-bottom: 12px; }
.cptt-field label { display: block; font-size: 12px; font-weight: 600; color: #3f3f46; margin-bottom: 5px; }
.cptt-field input {
	width: 100%; padding: 10px 12px;
	font-size: 14px;
	font-family: inherit;
	border: 1px solid #e4e4e7;
	border-radius: 8px;
	background: #fff;
	color: #18181b;
	transition: all .15s ease;
}
.cptt-field input:focus {
	outline: none; border-color: #18181b;
	box-shadow: 0 0 0 3px rgba(24,24,27,0.06);
}
.cptt-field input.is-error { border-color: #ef4444; }
.cptt-field input.cptt-ltr { direction: ltr; text-align: left; }
.cptt-field input.cptt-center { text-align: center; letter-spacing: 1px; font-weight: 600; }
.cptt-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

.cptt-row-mini { display: flex; justify-content: space-between; align-items: center; margin: 6px 0 14px; font-size: 12px; }
.cptt-row-mini label { display: flex; align-items: center; gap: 6px; color: #52525b; cursor: pointer; }
.cptt-row-mini label input { width: 14px; height: 14px; accent-color: #18181b; margin: 0; }
.cptt-link-btn { background: none; border: none; color: #18181b; font-weight: 600; cursor: pointer; font-family: inherit; font-size: 12px; padding: 0; text-decoration: underline; text-underline-offset: 2px; }
.cptt-link-btn:hover { color: #6366f1; }

.cptt-btn {
	width: 100%; padding: 11px;
	font-size: 14px; font-weight: 700;
	font-family: inherit;
	background: #18181b;
	color: #fff;
	border: none;
	border-radius: 8px;
	cursor: pointer;
	transition: all .15s ease;
	display: flex; align-items: center; justify-content: center; gap: 8px;
	min-height: 42px;
}
.cptt-btn:hover { background: #27272a; }
.cptt-btn:disabled { opacity: .5; cursor: not-allowed; }
.cptt-btn--ghost {
	background: #fff;
	color: #18181b;
	border: 1px solid #e4e4e7;
}
.cptt-btn--ghost:hover { background: #fafafa; border-color: #d4d4d8; }

.cptt-divider {
	display: flex; align-items: center; gap: 10px;
	margin: 16px 0;
	color: #a1a1aa; font-size: 11px;
}
.cptt-divider::before, .cptt-divider::after { content: ''; flex: 1; height: 1px; background: #e4e4e7; }

.cptt-msg { padding: 9px 12px; border-radius: 7px; font-size: 12.5px; margin-top: 10px; line-height: 1.6; }
.cptt-msg.err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.cptt-msg.ok  { background: #f0fdf4; color: #065f46; border: 1px solid #bbf7d0; }
.cptt-msg.info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }

.cptt-hint { font-size: 11.5px; color: #a1a1aa; text-align: center; margin-top: 14px; line-height: 1.6; }

.cptt-spinner { width: 14px; height: 14px; border: 2px solid rgba(255,255,255,.3); border-top-color: #fff; border-radius: 50%; animation: spin .8s linear infinite; }
.cptt-btn--ghost .cptt-spinner { border-color: rgba(24,24,27,.2); border-top-color: #18181b; }
@keyframes spin { to { transform: rotate(360deg); } }

/* OTP boxes */
.cptt-otp { display: flex; gap: 6px; justify-content: center; direction: ltr; margin: 4px 0 8px; }
.cptt-otp input {
	width: 44px; height: 50px;
	font-size: 20px; text-align: center; padding: 0;
	letter-spacing: 0; font-weight: 700;
	border: 1px solid #e4e4e7; border-radius: 8px;
	font-family: inherit;
	background: #fafafa;
}
.cptt-otp input:focus { background: #fff; border-color: #18181b; outline: none; box-shadow: 0 0 0 3px rgba(24,24,27,0.06); }
.cptt-otp input.is-error { border-color: #ef4444; }

.cptt-timer { font-size: 12px; color: #6366f1; text-align: center; margin: 2px 0 10px; font-weight: 600; }

/* Sub-step (OTP / Reset) */
.cptt-substep { display: none; }
.cptt-substep.is-active { display: block; animation: fadein .25s ease; }

.cptt-bale-link-card {
	background: #fffbeb;
	border: 1px solid #fde68a;
	border-radius: 10px;
	padding: 14px;
	margin-top: 10px;
}
.cptt-bale-link-card h4 { margin: 0 0 6px; font-size: 13px; color: #92400e; font-weight: 700; }
.cptt-bale-link-card p { margin: 0 0 10px; font-size: 12px; color: #78350f; line-height: 1.7; }
.cptt-bale-link-card ol { margin: 0 0 10px; padding-right: 18px; font-size: 12px; color: #78350f; line-height: 1.8; }

.cptt-back { background: none; border: none; color: #71717a; font-size: 12px; cursor: pointer; padding: 4px 0; font-family: inherit; margin-top: 8px; }
.cptt-back:hover { color: #18181b; }

@media (max-width: 480px) {
	.cptt-auth-shell { padding: 28px 22px 24px; border-radius: 14px; }
	.cptt-otp input { width: 38px; height: 46px; font-size: 18px; }
	.cptt-field-row { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="cptt-auth-shell">
	<div class="cptt-auth-brand">
		<div class="cptt-auth-brand-mark">⌘</div>
		<h1><?php echo esc_html($site_name); ?></h1>
		<p>ورود به حساب کاربری</p>
	</div>

	<div class="cptt-tabs" role="tablist">
		<button class="cptt-tab" data-tab="login">ورود</button>
		<button class="cptt-tab" data-tab="register">ثبت‌نام</button>
		<button class="cptt-tab" data-tab="forgot">فراموشی رمز</button>
	</div>

	<!-- =========== LOGIN PANE =========== -->
	<div class="cptt-pane" data-pane="login">
		<div class="cptt-substep" data-sub="login-main">
			<form id="cptt-form-login" autocomplete="on">
				<div class="cptt-field">
					<label for="cptt-li-id">نام کاربری / ایمیل / موبایل</label>
					<input type="text" id="cptt-li-id" name="identifier" autocomplete="username" required>
				</div>
				<div class="cptt-field">
					<label for="cptt-li-pass">رمز عبور</label>
					<input type="password" id="cptt-li-pass" name="password" autocomplete="current-password" required>
				</div>
				<div class="cptt-row-mini">
					<label><input type="checkbox" name="remember" checked> مرا به خاطر بسپار</label>
					<button type="button" class="cptt-link-btn" data-goto-tab="forgot">فراموشی رمز؟</button>
				</div>
				<button type="submit" class="cptt-btn"><span class="cptt-lbl">ورود</span></button>
				<div id="cptt-msg-login"></div>
			</form>
			<div class="cptt-divider">یا</div>
			<button type="button" class="cptt-btn cptt-btn--ghost" id="cptt-bale-login-toggle">🤖 ورود سریع با کد بله</button>
		</div>

		<div class="cptt-substep" data-sub="login-bale-phone">
			<div class="cptt-field">
				<label for="cptt-bl-phone">📱 شماره موبایل</label>
				<input type="tel" id="cptt-bl-phone" inputmode="numeric" maxlength="11" placeholder="09123456789" class="cptt-ltr cptt-center" autocomplete="tel">
			</div>
			<button type="button" class="cptt-btn" id="cptt-bl-send"><span class="cptt-lbl">ارسال کد در بله</span></button>
			<div id="cptt-msg-bl-phone"></div>
			<button type="button" class="cptt-back" data-back-to="login-main">← بازگشت به ورود معمولی</button>
		</div>

		<div class="cptt-substep" data-sub="login-bale-otp">
			<div class="cptt-field">
				<label>🔢 کد ۵ رقمی ارسال‌شده در بله</label>
				<div class="cptt-otp" id="cptt-bl-otp-inputs">
					<input type="tel" inputmode="numeric" maxlength="1" data-i="0">
					<input type="tel" inputmode="numeric" maxlength="1" data-i="1">
					<input type="tel" inputmode="numeric" maxlength="1" data-i="2">
					<input type="tel" inputmode="numeric" maxlength="1" data-i="3">
					<input type="tel" inputmode="numeric" maxlength="1" data-i="4">
				</div>
				<div class="cptt-timer" id="cptt-bl-timer"></div>
			</div>
			<button type="button" class="cptt-btn" id="cptt-bl-verify"><span class="cptt-lbl">تأیید و ورود</span></button>
			<div id="cptt-msg-bl-otp"></div>
			<div style="display:flex;gap:6px;justify-content:space-between;align-items:center;margin-top:8px;">
				<button type="button" class="cptt-back" data-back-to="login-bale-phone">← تغییر شماره</button>
				<button type="button" class="cptt-link-btn" id="cptt-bl-resend" style="display:none;">ارسال مجدد کد</button>
			</div>
		</div>

		<div class="cptt-substep" data-sub="login-bale-link">
			<div class="cptt-bale-link-card">
				<h4>🤖 ابتدا حساب خود را به ربات بله متصل کنید</h4>
				<p>برای ورود با کد بله، باید یک‌بار حساب خود را در ربات وصل کنید:</p>
				<ol>
					<li>روی «رفتن به ربات بله» بزنید.</li>
					<li>دستور <code style="background:#fff;padding:1px 5px;border-radius:4px;border:1px solid #fde68a;">/start</code> را بفرستید.</li>
					<li>شماره موبایل خود را در ربات ارسال کنید.</li>
					<li>به این صفحه بازگردید و دوباره کد را درخواست کنید.</li>
				</ol>
				<?php if ($bot_link): ?>
				<a href="<?php echo esc_url($bot_link); ?>" target="_blank" rel="noopener" class="cptt-btn" style="text-decoration:none;background:#1d4ed8;">🤖 رفتن به ربات بله</a>
				<?php else: ?>
				<div class="cptt-msg err">⚠️ ربات بله توسط مدیر تنظیم نشده.</div>
				<?php endif; ?>
			</div>
			<button type="button" class="cptt-back" data-back-to="login-bale-phone">← بازگشت</button>
		</div>
	</div>

	<!-- =========== REGISTER PANE =========== -->
	<div class="cptt-pane" data-pane="register">
		<form id="cptt-form-register" autocomplete="on">
			<div class="cptt-field-row">
				<div class="cptt-field">
					<label for="cptt-rg-first">نام</label>
					<input type="text" id="cptt-rg-first" name="first_name" autocomplete="given-name" required>
				</div>
				<div class="cptt-field">
					<label for="cptt-rg-last">نام خانوادگی</label>
					<input type="text" id="cptt-rg-last" name="last_name" autocomplete="family-name" required>
				</div>
			</div>
			<div class="cptt-field">
				<label for="cptt-rg-phone">شماره موبایل</label>
				<input type="tel" id="cptt-rg-phone" name="phone" inputmode="numeric" maxlength="11" placeholder="09123456789" class="cptt-ltr cptt-center" autocomplete="tel" required>
			</div>
			<div class="cptt-field">
				<label for="cptt-rg-email">ایمیل</label>
				<input type="email" id="cptt-rg-email" name="email" class="cptt-ltr" autocomplete="email" required>
			</div>
			<div class="cptt-field">
				<label for="cptt-rg-user">نام کاربری</label>
				<input type="text" id="cptt-rg-user" name="username" class="cptt-ltr" autocomplete="username" required>
			</div>
			<div class="cptt-field-row">
				<div class="cptt-field">
					<label for="cptt-rg-pass">رمز عبور</label>
					<input type="password" id="cptt-rg-pass" name="password" autocomplete="new-password" required minlength="6">
				</div>
				<div class="cptt-field">
					<label for="cptt-rg-pass2">تکرار رمز</label>
					<input type="password" id="cptt-rg-pass2" name="password2" autocomplete="new-password" required minlength="6">
				</div>
			</div>
			<button type="submit" class="cptt-btn"><span class="cptt-lbl">ایجاد حساب کاربری</span></button>
			<div id="cptt-msg-register"></div>
			<div class="cptt-hint">با ثبت‌نام، قوانین استفاده از سایت را می‌پذیرید.</div>
		</form>
	</div>

	<!-- =========== FORGOT PANE =========== -->
	<div class="cptt-pane" data-pane="forgot">
		<div class="cptt-substep is-active" data-sub="forgot-phone">
			<div class="cptt-field">
				<label for="cptt-fp-phone">📱 شماره موبایل حساب خود را وارد کنید</label>
				<input type="tel" id="cptt-fp-phone" inputmode="numeric" maxlength="11" placeholder="09123456789" class="cptt-ltr cptt-center">
			</div>
			<button type="button" class="cptt-btn" id="cptt-fp-send"><span class="cptt-lbl">ارسال کد بازیابی</span></button>
			<div id="cptt-msg-fp-phone"></div>
			<div class="cptt-hint">💡 کد به ربات بله متصل به حساب شما ارسال می‌شود.</div>
		</div>

		<div class="cptt-substep" data-sub="forgot-reset">
			<div class="cptt-field">
				<label>🔢 کد ۵ رقمی</label>
				<div class="cptt-otp" id="cptt-fp-otp">
					<input type="tel" inputmode="numeric" maxlength="1" data-i="0">
					<input type="tel" inputmode="numeric" maxlength="1" data-i="1">
					<input type="tel" inputmode="numeric" maxlength="1" data-i="2">
					<input type="tel" inputmode="numeric" maxlength="1" data-i="3">
					<input type="tel" inputmode="numeric" maxlength="1" data-i="4">
				</div>
				<div class="cptt-timer" id="cptt-fp-timer"></div>
			</div>
			<div class="cptt-field-row">
				<div class="cptt-field">
					<label for="cptt-fp-pass">رمز جدید</label>
					<input type="password" id="cptt-fp-pass" autocomplete="new-password" required minlength="6">
				</div>
				<div class="cptt-field">
					<label for="cptt-fp-pass2">تکرار</label>
					<input type="password" id="cptt-fp-pass2" autocomplete="new-password" required minlength="6">
				</div>
			</div>
			<button type="button" class="cptt-btn" id="cptt-fp-verify"><span class="cptt-lbl">ثبت رمز جدید و ورود</span></button>
			<div id="cptt-msg-fp-reset"></div>
			<button type="button" class="cptt-back" data-back-to="forgot-phone">← تغییر شماره</button>
		</div>

		<div class="cptt-substep" data-sub="forgot-link">
			<div class="cptt-bale-link-card">
				<h4>🤖 حساب شما به بله متصل نیست</h4>
				<p>برای بازیابی رمز نیاز است حساب شما به ربات بله متصل باشد. ابتدا با رمز عبور وارد شوید و در پنل کاربری، حساب خود را تایید کنید.</p>
				<?php if ($bot_link): ?>
				<a href="<?php echo esc_url($bot_link); ?>" target="_blank" rel="noopener" class="cptt-btn" style="text-decoration:none;background:#1d4ed8;">🤖 رفتن به ربات بله</a>
				<?php endif; ?>
			</div>
			<button type="button" class="cptt-back" data-back-to="forgot-phone">← بازگشت</button>
		</div>
	</div>

</div>

<script>
(function(){
	var AJAX  = <?php echo wp_json_encode($ajax); ?>;
	var NONCE = <?php echo wp_json_encode($nonce); ?>;
	var REDIR = <?php echo wp_json_encode($redirect_to); ?>;
	var INITIAL_TAB = <?php echo wp_json_encode($initial_tab); ?>;

	function $(s, ctx){ return (ctx||document).querySelector(s); }
	function $$(s, ctx){ return Array.from((ctx||document).querySelectorAll(s)); }
	function toEn(s){ return String(s||'').replace(/[۰-۹]/g, function(d){return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d);}).replace(/[٠-٩]/g, function(d){return '٠١٢٣٤٥٦٧٨٩'.indexOf(d);}); }
	function setBtn(btn, loading, labelDefault){
		var lbl = btn.querySelector('.cptt-lbl');
		if (loading) { btn.disabled = true; if (lbl) lbl.innerHTML = '<span class="cptt-spinner"></span> در حال پردازش...'; }
		else { btn.disabled = false; if (lbl) lbl.textContent = labelDefault; }
	}
	function showMsg(el, txt, kind){ el.innerHTML = '<div class="cptt-msg '+(kind||'info')+'">'+txt+'</div>'; }
	function clrMsg(el){ el.innerHTML = ''; }

	// Tabs
	function activateTab(name){
		$$('.cptt-tab').forEach(function(b){ b.classList.toggle('is-active', b.dataset.tab === name); });
		$$('.cptt-pane').forEach(function(p){ p.classList.toggle('is-active', p.dataset.pane === name); });
		// reset substeps
		if (name === 'login') showSub('login-main');
		if (name === 'forgot') showSub('forgot-phone');
	}
	$$('.cptt-tab').forEach(function(b){ b.addEventListener('click', function(){ activateTab(b.dataset.tab); }); });
	$$('[data-goto-tab]').forEach(function(b){ b.addEventListener('click', function(){ activateTab(b.dataset.gotoTab); }); });
	activateTab(INITIAL_TAB);

	// Substeps
	function showSub(name){
		$$('.cptt-substep').forEach(function(s){ s.classList.toggle('is-active', s.dataset.sub === name); });
		// focus first input in active sub
		setTimeout(function(){
			var first = $('.cptt-substep.is-active input');
			if (first) try { first.focus(); } catch(e){}
		}, 100);
	}
	$$('[data-back-to]').forEach(function(b){ b.addEventListener('click', function(){ showSub(b.dataset.backTo); }); });

	// Initial: login-main active
	showSub('login-main');

	// Numeric inputs sanitization
	$$('input[inputmode="numeric"]').forEach(function(inp){
		inp.addEventListener('input', function(){
			var max = parseInt(this.getAttribute('maxlength')||'99', 10);
			this.value = toEn(this.value).replace(/[^\d]/g, '').slice(0, max);
		});
	});

	/* ============== LOGIN (normal) ============== */
	$('#cptt-form-login').addEventListener('submit', function(e){
		e.preventDefault();
		var f = e.target; var btn = f.querySelector('.cptt-btn'); var msgEl = $('#cptt-msg-login');
		clrMsg(msgEl);
		var fd = new FormData(f); fd.append('action', 'cptt_auth_login'); fd.append('nonce', NONCE);
		if (REDIR) fd.append('redirect_to', REDIR);
		setBtn(btn, true, 'ورود');
		fetch(AJAX, {method:'POST', credentials:'same-origin', body:fd})
		.then(function(r){return r.json();})
		.then(function(j){
			setBtn(btn, false, 'ورود');
			if (j && j.success) {
				showMsg(msgEl, '✓ ورود موفق. در حال انتقال...', 'ok');
				setTimeout(function(){ window.location.href = j.data.redirect || '/'; }, 500);
			} else {
				var t = (j && j.data) ? (typeof j.data === 'string' ? j.data : j.data.message) : 'خطا';
				showMsg(msgEl, '✕ ' + t, 'err');
			}
		}).catch(function(){ setBtn(btn, false, 'ورود'); showMsg(msgEl, '✕ خطای شبکه.', 'err'); });
	});

	/* ============== LOGIN with Bale OTP ============== */
	$('#cptt-bale-login-toggle').addEventListener('click', function(){ showSub('login-bale-phone'); });

	var bl_phone = '';
	var bl_inputs = $$('#cptt-bl-otp-inputs input');
	var bl_timerInt = null;

	function startTimer(timerEl, sec, resendEl){
		clearInterval(bl_timerInt);
		var t = sec; if (resendEl) resendEl.style.display = 'none';
		function tick(){
			if (t <= 0) { timerEl.textContent = ''; if (resendEl) resendEl.style.display = 'inline-block'; clearInterval(bl_timerInt); return; }
			var m = Math.floor(t/60), s = t%60;
			timerEl.textContent = '⏱ مهلت کد: ' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
			t--;
		}
		tick(); bl_timerInt = setInterval(tick, 1000);
	}

	function blSendOtp(){
		var msgEl = $('#cptt-msg-bl-phone'); clrMsg(msgEl);
		var phone = toEn($('#cptt-bl-phone').value).trim();
		if (!/^09\d{9}$/.test(phone)) { showMsg(msgEl, '✕ شماره ۱۱ رقمی معتبر وارد کنید.', 'err'); return; }
		bl_phone = phone;
		var btn = $('#cptt-bl-send'); setBtn(btn, true, 'ارسال کد در بله');
		var fd = new FormData(); fd.append('action','cptt_auth_request_otp'); fd.append('nonce',NONCE); fd.append('phone',phone);
		fetch(AJAX, {method:'POST', credentials:'same-origin', body:fd}).then(function(r){return r.json();}).then(function(j){
			setBtn(btn, false, 'ارسال کد در بله');
			if (j && j.success) { showSub('login-bale-otp'); startTimer($('#cptt-bl-timer'), 300, $('#cptt-bl-resend')); return; }
			var data = j && j.data ? j.data : {};
			if (typeof data === 'object' && data.need_link) { showSub('login-bale-link'); return; }
			var t = (typeof data === 'string') ? data : (data.message || 'خطا');
			showMsg(msgEl, '✕ ' + t, 'err');
		}).catch(function(){ setBtn(btn, false, 'ارسال کد در بله'); showMsg(msgEl, '✕ خطای شبکه.', 'err'); });
	}
	$('#cptt-bl-send').addEventListener('click', blSendOtp);
	$('#cptt-bl-resend').addEventListener('click', blSendOtp);

	bl_inputs.forEach(function(inp, idx){
		inp.addEventListener('input', function(){
			this.value = toEn(this.value).replace(/[^\d]/g,'').slice(0,1);
			if (this.value && idx < bl_inputs.length-1) bl_inputs[idx+1].focus();
			if (bl_inputs.map(function(i){return i.value;}).join('').length === 5) $('#cptt-bl-verify').click();
		});
		inp.addEventListener('keydown', function(e){ if (e.key==='Backspace'&&!this.value&&idx>0) bl_inputs[idx-1].focus(); });
		inp.addEventListener('paste', function(e){
			var digits = toEn((e.clipboardData||window.clipboardData).getData('text')).replace(/[^\d]/g,'').slice(0,5);
			if (digits.length){ e.preventDefault(); for (var i=0;i<digits.length&&i<5;i++) bl_inputs[i].value=digits[i]; if (digits.length===5) $('#cptt-bl-verify').click(); }
		});
	});

	$('#cptt-bl-verify').addEventListener('click', function(){
		var msgEl = $('#cptt-msg-bl-otp'); clrMsg(msgEl);
		var code = bl_inputs.map(function(i){return i.value;}).join('');
		if (!/^\d{5}$/.test(code)) { showMsg(msgEl, '✕ کد ۵ رقمی را وارد کنید.', 'err'); return; }
		var btn = this; setBtn(btn, true, 'تأیید و ورود');
		var fd = new FormData(); fd.append('action','cptt_auth_verify_otp'); fd.append('nonce',NONCE); fd.append('phone',bl_phone); fd.append('code',code);
		if (REDIR) fd.append('redirect_to', REDIR);
		fetch(AJAX, {method:'POST', credentials:'same-origin', body:fd}).then(function(r){return r.json();}).then(function(j){
			setBtn(btn, false, 'تأیید و ورود');
			if (j && j.success){ showMsg(msgEl, '✓ ورود موفق...', 'ok'); clearInterval(bl_timerInt); setTimeout(function(){ window.location.href = j.data.redirect || '/'; }, 500); return; }
			var t = (j && j.data) ? (typeof j.data === 'string' ? j.data : j.data.message) : 'کد اشتباه';
			showMsg(msgEl, '✕ ' + t, 'err');
			bl_inputs.forEach(function(i){ i.classList.add('is-error'); });
			setTimeout(function(){ bl_inputs.forEach(function(i){ i.classList.remove('is-error'); }); }, 600);
		}).catch(function(){ setBtn(btn, false, 'تأیید و ورود'); showMsg(msgEl, '✕ خطای شبکه.', 'err'); });
	});

	/* ============== REGISTER ============== */
	$('#cptt-form-register').addEventListener('submit', function(e){
		e.preventDefault();
		var f = e.target; var btn = f.querySelector('.cptt-btn'); var msgEl = $('#cptt-msg-register');
		clrMsg(msgEl);
		var fd = new FormData(f); fd.append('action','cptt_auth_register'); fd.append('nonce',NONCE);
		if (REDIR) fd.append('redirect_to', REDIR);
		setBtn(btn, true, 'ایجاد حساب کاربری');
		fetch(AJAX, {method:'POST', credentials:'same-origin', body:fd})
		.then(function(r){return r.json();}).then(function(j){
			setBtn(btn, false, 'ایجاد حساب کاربری');
			if (j && j.success){
				showMsg(msgEl, '✓ ثبت‌نام موفق. در حال انتقال به پنل...', 'ok');
				setTimeout(function(){ window.location.href = j.data.redirect || '/'; }, 700);
			} else {
				var t = (j && j.data) ? (typeof j.data === 'string' ? j.data : j.data.message) : 'خطا';
				showMsg(msgEl, '✕ ' + t, 'err');
			}
		}).catch(function(){ setBtn(btn, false, 'ایجاد حساب کاربری'); showMsg(msgEl, '✕ خطای شبکه.', 'err'); });
	});

	/* ============== FORGOT PASSWORD ============== */
	var fp_phone = '';
	var fp_inputs = $$('#cptt-fp-otp input');
	var fp_timerInt = null;

	function fpStartTimer(sec){
		clearInterval(fp_timerInt);
		var t = sec, el = $('#cptt-fp-timer');
		function tick(){ if (t<=0){ el.textContent=''; clearInterval(fp_timerInt); return; } var m=Math.floor(t/60),s=t%60; el.textContent='⏱ '+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0'); t--; }
		tick(); fp_timerInt = setInterval(tick, 1000);
	}

	$('#cptt-fp-send').addEventListener('click', function(){
		var msgEl = $('#cptt-msg-fp-phone'); clrMsg(msgEl);
		var phone = toEn($('#cptt-fp-phone').value).trim();
		if (!/^09\d{9}$/.test(phone)){ showMsg(msgEl, '✕ شماره ۱۱ رقمی معتبر وارد کنید.', 'err'); return; }
		fp_phone = phone;
		var btn = this; setBtn(btn, true, 'ارسال کد بازیابی');
		var fd = new FormData(); fd.append('action','cptt_auth_forgot_request'); fd.append('nonce',NONCE); fd.append('phone',phone);
		fetch(AJAX, {method:'POST', credentials:'same-origin', body:fd}).then(function(r){return r.json();}).then(function(j){
			setBtn(btn, false, 'ارسال کد بازیابی');
			if (j && j.success){ showSub('forgot-reset'); fpStartTimer(300); return; }
			var data = j && j.data ? j.data : {};
			if (typeof data === 'object' && data.need_link) { showSub('forgot-link'); return; }
			var t = (typeof data === 'string') ? data : (data.message || 'خطا');
			showMsg(msgEl, '✕ ' + t, 'err');
		}).catch(function(){ setBtn(btn, false, 'ارسال کد بازیابی'); showMsg(msgEl, '✕ خطای شبکه.', 'err'); });
	});

	fp_inputs.forEach(function(inp, idx){
		inp.addEventListener('input', function(){
			this.value = toEn(this.value).replace(/[^\d]/g,'').slice(0,1);
			if (this.value && idx < fp_inputs.length-1) fp_inputs[idx+1].focus();
		});
		inp.addEventListener('keydown', function(e){ if (e.key==='Backspace'&&!this.value&&idx>0) fp_inputs[idx-1].focus(); });
		inp.addEventListener('paste', function(e){
			var digits = toEn((e.clipboardData||window.clipboardData).getData('text')).replace(/[^\d]/g,'').slice(0,5);
			if (digits.length){ e.preventDefault(); for (var i=0;i<digits.length&&i<5;i++) fp_inputs[i].value=digits[i]; }
		});
	});

	$('#cptt-fp-verify').addEventListener('click', function(){
		var msgEl = $('#cptt-msg-fp-reset'); clrMsg(msgEl);
		var code = fp_inputs.map(function(i){return i.value;}).join('');
		var pass = $('#cptt-fp-pass').value, pass2 = $('#cptt-fp-pass2').value;
		if (!/^\d{5}$/.test(code)){ showMsg(msgEl, '✕ کد ۵ رقمی را کامل وارد کنید.', 'err'); return; }
		if (pass.length < 6){ showMsg(msgEl, '✕ رمز حداقل ۶ کاراکتر.', 'err'); return; }
		if (pass !== pass2){ showMsg(msgEl, '✕ رمز و تکرار یکسان نیست.', 'err'); return; }
		var btn = this; setBtn(btn, true, 'ثبت رمز جدید و ورود');
		var fd = new FormData();
		fd.append('action','cptt_auth_forgot_verify'); fd.append('nonce',NONCE);
		fd.append('phone',fp_phone); fd.append('code',code); fd.append('password',pass); fd.append('password2',pass2);
		if (REDIR) fd.append('redirect_to', REDIR);
		fetch(AJAX, {method:'POST', credentials:'same-origin', body:fd}).then(function(r){return r.json();}).then(function(j){
			setBtn(btn, false, 'ثبت رمز جدید و ورود');
			if (j && j.success){ showMsg(msgEl, '✓ رمز تغییر کرد. در حال ورود...', 'ok'); clearInterval(fp_timerInt); setTimeout(function(){ window.location.href = j.data.redirect || '/'; }, 600); return; }
			var t = (j && j.data) ? (typeof j.data === 'string' ? j.data : j.data.message) : 'خطا';
			showMsg(msgEl, '✕ ' + t, 'err');
		}).catch(function(){ setBtn(btn, false, 'ثبت رمز جدید و ورود'); showMsg(msgEl, '✕ خطای شبکه.', 'err'); });
	});
})();
</script>
</body>
</html><?php
	}
}
