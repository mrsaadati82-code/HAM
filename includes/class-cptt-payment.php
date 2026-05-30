<?php
/**
 * CPTT Payment Manager — v5.5.0
 * 
 * سیستم پرداخت چندروشه با معماری Driver/Adapter:
 *  - چند درگاه آنلاین فعال هم‌زمان (زرین‌پال، زیبال، آیدی‌پی، نکست‌پی، پی‌پینگ، پی‌استار، جیبیت)
 *  - چند کارت بانکی فعال هم‌زمان (کارت به کارت)
 *  - افزودن/حذف/فعال/غیرفعال پویا
 *  - صفحه‌ی پرداخت حرفه‌ای زیبا با انتخاب روش
 *  - رسید کارت به کارت با ویزارد UX خوب
 *  - یکپارچه با ربات بله (لیست روش‌ها + لینک پرداخت)
 */

if (!defined('ABSPATH')) exit;

class CPTT_Payment {
	private static $instance = null;
	const OPT  = 'cptt_payment_settings';     // v5.5: ساختار جدید
	const OPT_LEGACY = 'cptt_payment_settings_v1';

	public static function instance() {
		if (self::$instance === null) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		// admin menu
		add_action('admin_menu', [$this, 'menu']);
		// AJAX admin
		add_action('wp_ajax_cptt_payment_save', [$this, 'ajax_save']);
		add_action('wp_ajax_cptt_payment_add_gateway', [$this, 'ajax_add_gateway']);
		add_action('wp_ajax_cptt_payment_remove_gateway', [$this, 'ajax_remove_gateway']);
		add_action('wp_ajax_cptt_payment_toggle_gateway', [$this, 'ajax_toggle_gateway']);
		add_action('wp_ajax_cptt_payment_add_card', [$this, 'ajax_add_card']);
		add_action('wp_ajax_cptt_payment_remove_card', [$this, 'ajax_remove_card']);
		add_action('wp_ajax_cptt_payment_toggle_card', [$this, 'ajax_toggle_card']);
		add_action('wp_ajax_cptt_approve_receipt', [$this, 'ajax_approve_receipt']);
		add_action('wp_ajax_cptt_reject_receipt', [$this, 'ajax_reject_receipt']);

		// public payment page
		add_action('admin_post_cptt_pay_project', [$this, 'render_pay_page']);
		add_action('admin_post_nopriv_cptt_pay_project', [$this, 'render_pay_page']);
		add_action('admin_post_cptt_select_method', [$this, 'handle_method_select']);
		add_action('admin_post_nopriv_cptt_select_method', [$this, 'handle_method_select']);
		add_action('admin_post_cptt_submit_card_receipt', [$this, 'submit_card_receipt']);
		add_action('admin_post_nopriv_cptt_submit_card_receipt', [$this, 'submit_card_receipt']);
		add_action('admin_post_cptt_gateway_callback', [$this, 'handle_gateway_callback']);
		add_action('admin_post_nopriv_cptt_gateway_callback', [$this, 'handle_gateway_callback']);

		// admin assets
		add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
	}

	/* =========================================================
	   نصب / دیفالت
	   ========================================================= */
	public static function install_defaults() {
		if (get_option(self::OPT) !== false) return;
		$default = [
			'enabled_gateways' => [],   // [ ['id'=>uniqid,'driver'=>'zarinpal','title'=>'..','merchant'=>'..','active'=>1,'sandbox'=>0], ... ]
			'cards'            => [],   // [ ['id'=>uniqid,'bank'=>'ملت','number'=>'1234..','owner'=>'..','sheba'=>'..','active'=>1], ... ]
			'default_method'   => '',   // پیش‌فرض هیچ
			'page_intro'       => 'لطفاً روش پرداخت موردنظرتان را انتخاب کنید.',
		];
		add_option(self::OPT, $default, '', false);
	}

	public static function get_settings() {
		$default = ['enabled_gateways' => [], 'cards' => [], 'default_method' => '', 'page_intro' => 'لطفاً روش پرداخت موردنظرتان را انتخاب کنید.'];
		$opt = get_option(self::OPT, []);
		if (!is_array($opt)) $opt = [];
		return wp_parse_args($opt, $default);
	}

	public static function update_settings($data) {
		$old = self::get_settings();
		$new = wp_parse_args($data, $old);
		update_option(self::OPT, $new, false);
		return $new;
	}

	/* =========================================================
	   درایورهای موجود
	   ========================================================= */
	public static function available_drivers() {
		return [
			'zarinpal' => ['title' => 'زرین‌پال',  'url' => 'https://zarinpal.com',  'fields' => ['merchant' => 'Merchant ID']],
			'zibal'    => ['title' => 'زیبال',     'url' => 'https://zibal.ir',      'fields' => ['merchant' => 'Merchant Key']],
			'idpay'    => ['title' => 'آیدی‌پی',   'url' => 'https://idpay.ir',      'fields' => ['merchant' => 'API Key']],
			'nextpay'  => ['title' => 'نکست‌پی',   'url' => 'https://nextpay.org',   'fields' => ['merchant' => 'API Key']],
			'payping'  => ['title' => 'پی‌پینگ',   'url' => 'https://payping.ir',    'fields' => ['merchant' => 'Token']],
			'paystar'  => ['title' => 'پی‌استار',  'url' => 'https://paystar.ir',    'fields' => ['merchant' => 'Gateway ID', 'merchant_secret' => 'Secret Key']],
			'jibit'    => ['title' => 'جیبیت',     'url' => 'https://jibit.ir',      'fields' => ['merchant' => 'API Key', 'merchant_secret' => 'Secret Key']],
			'sep'      => ['title' => 'سامان (SEP)','url' => 'https://www.sep.ir',  'fields' => ['merchant' => 'Terminal ID']],
			'mellat'   => ['title' => 'بانک ملت',  'url' => 'https://behpardakht.com','fields' => ['merchant' => 'Terminal ID', 'merchant_secret' => 'Username:Password']],
		];
	}

	/* =========================================================
	   لینک‌های کمکی
	   ========================================================= */
	public static function payment_url($project_id, $amount = 0) {
		return add_query_arg([
			'action' => 'cptt_pay_project',
			'project_id' => (int)$project_id,
			'amount' => (float)$amount,
			'_t' => substr(md5($project_id . '-' . $amount . '-' . NONCE_SALT), 0, 12),
		], admin_url('admin-post.php'));
	}

	public static function callback_url($txn_id) {
		return add_query_arg([
			'action' => 'cptt_gateway_callback',
			'txn' => (string)$txn_id,
		], admin_url('admin-post.php'));
	}

	/**
	 * لیست روش‌های پرداخت فعال — برای استفاده در صفحه پرداخت و ربات بله
	 */
	public static function active_methods() {
		$s = self::get_settings();
		$out = [];
		foreach ((array)($s['enabled_gateways'] ?? []) as $g) {
			if (empty($g['active'])) continue;
			$drv = self::available_drivers();
			$title = $g['title'] ?: ($drv[$g['driver']]['title'] ?? $g['driver']);
			$out[] = ['type' => 'gateway', 'id' => $g['id'], 'driver' => $g['driver'], 'title' => $title, 'icon' => self::driver_icon($g['driver'])];
		}
		foreach ((array)($s['cards'] ?? []) as $c) {
			if (empty($c['active'])) continue;
			$title = ($c['bank'] ?: 'کارت بانکی') . ' — ' . self::mask_card($c['number'] ?? '');
			$out[] = ['type' => 'card', 'id' => $c['id'], 'driver' => 'card', 'title' => $title, 'icon' => '💳', 'bank' => $c['bank'] ?? '', 'number' => $c['number'] ?? '', 'owner' => $c['owner'] ?? '', 'sheba' => $c['sheba'] ?? ''];
		}
		return $out;
	}

	public static function driver_icon($driver) {
		$map = [
			'zarinpal' => '🟡', 'zibal' => '🔵', 'idpay' => '🟢', 'nextpay' => '🟣',
			'payping' => '🟠', 'paystar' => '🔴', 'jibit' => '🟤', 'sep' => '🏦', 'mellat' => '🏛',
		];
		return $map[$driver] ?? '💼';
	}

	public static function mask_card($number) {
		$n = preg_replace('/\D+/', '', (string)$number);
		if (strlen($n) < 12) return $n;
		return substr($n, 0, 4) . '-' . substr($n, 4, 4) . '-' . substr($n, 8, 4) . '-' . substr($n, 12, 4);
	}

	/* =========================================================
	   منوی ادمین
	   ========================================================= */
	public function menu() {
		add_submenu_page('edit.php?post_type=cptt_project', 'پرداخت‌ها', '💳 پرداخت‌ها', 'manage_options', 'cptt-payments', [$this, 'page']);
	}

	public function admin_assets($hook) {
		if (strpos((string)$hook, 'cptt-payments') === false) return;
		wp_enqueue_style('cptt-payment-admin', CPTT_URL . 'assets/css/payment-admin.css', [], CPTT_VERSION);
		wp_enqueue_script('cptt-payment-admin', CPTT_URL . 'assets/js/payment-admin.js', ['jquery'], CPTT_VERSION, true);
		wp_localize_script('cptt-payment-admin', 'CPTT_PAY_ADMIN', [
			'ajax' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('cptt_payment_admin'),
			'drivers' => self::available_drivers(),
		]);
	}

	public function page() {
		if (!current_user_can('manage_options')) return;
		$s = self::get_settings();
		$drivers = self::available_drivers();
		$active_methods = self::active_methods();
		?>
		<div class="wrap cptt-pay-wrap" dir="rtl">
			<div class="cptt-pay-hero">
				<div class="cptt-pay-hero__title">
					<h1>💳 مدیریت روش‌های پرداخت</h1>
					<p>افزودن، حذف و فعال‌سازی چند درگاه آنلاین و چند کارت بانکی به‌صورت هم‌زمان. تمام روش‌ها به‌صورت یکپارچه در سایت و ربات بله نمایش داده می‌شوند.</p>
				</div>
				<div class="cptt-pay-hero__kpis">
					<div class="cptt-pay-kpi"><span><?php echo count(array_filter($s['enabled_gateways'], fn($g)=>!empty($g['active']))); ?></span><small>درگاه فعال</small></div>
					<div class="cptt-pay-kpi"><span><?php echo count(array_filter($s['cards'], fn($c)=>!empty($c['active']))); ?></span><small>کارت فعال</small></div>
					<div class="cptt-pay-kpi"><span><?php echo count($active_methods); ?></span><small>روش کل قابل ارائه</small></div>
				</div>
			</div>

			<!-- Tabs -->
			<div class="cptt-pay-tabs">
				<button class="cptt-pay-tab is-active" data-tab="gateways">🌐 درگاه‌های آنلاین</button>
				<button class="cptt-pay-tab" data-tab="cards">💳 کارت‌به‌کارت</button>
				<button class="cptt-pay-tab" data-tab="receipts">🧾 رسیدهای کارت‌به‌کارت</button>
				<button class="cptt-pay-tab" data-tab="settings">⚙️ تنظیمات کلی</button>
			</div>

			<!-- GATEWAYS -->
			<div class="cptt-pay-panel is-active" data-panel="gateways">
				<div class="cptt-pay-panel__head">
					<h2>درگاه‌های آنلاین</h2>
					<button class="button button-primary" id="cptt-pay-add-gateway">+ افزودن درگاه</button>
				</div>
				<div class="cptt-pay-grid" id="cptt-pay-gateways-grid">
					<?php if (empty($s['enabled_gateways'])): ?>
						<div class="cptt-pay-empty">هنوز هیچ درگاهی اضافه نشده. روی «افزودن درگاه» بزنید.</div>
					<?php else: foreach ($s['enabled_gateways'] as $g):
						$drv = $drivers[$g['driver']] ?? ['title' => $g['driver'], 'fields' => []]; ?>
						<div class="cptt-pay-card cptt-pay-card--<?php echo esc_attr($g['driver']); ?> <?php echo !empty($g['active']) ? 'is-active' : 'is-off'; ?>" data-id="<?php echo esc_attr($g['id']); ?>">
							<div class="cptt-pay-card__head">
								<div class="cptt-pay-card__icon"><?php echo self::driver_icon($g['driver']); ?></div>
								<div class="cptt-pay-card__title">
									<b><?php echo esc_html($g['title'] ?: $drv['title']); ?></b>
									<small><?php echo esc_html($drv['title']); ?></small>
								</div>
								<label class="cptt-pay-switch" title="فعال/غیرفعال">
									<input type="checkbox" class="cptt-toggle-gateway" data-id="<?php echo esc_attr($g['id']); ?>" <?php checked(!empty($g['active'])); ?>>
									<span></span>
								</label>
							</div>
							<div class="cptt-pay-card__body">
								<?php foreach ($drv['fields'] as $fk => $fl): ?>
								<label>
									<span><?php echo esc_html($fl); ?></span>
									<input type="text" class="cptt-pay-field" data-id="<?php echo esc_attr($g['id']); ?>" data-key="<?php echo esc_attr($fk); ?>" value="<?php echo esc_attr($g[$fk] ?? ''); ?>" placeholder="<?php echo esc_attr($fl); ?>">
								</label>
								<?php endforeach; ?>
								<label class="cptt-pay-checkbox">
									<input type="checkbox" class="cptt-pay-field" data-id="<?php echo esc_attr($g['id']); ?>" data-key="sandbox" <?php checked(!empty($g['sandbox'])); ?>>
									<span>حالت تست (Sandbox)</span>
								</label>
							</div>
							<div class="cptt-pay-card__foot">
								<button class="button button-link-delete cptt-remove-gateway" data-id="<?php echo esc_attr($g['id']); ?>">× حذف</button>
								<button class="button button-primary cptt-save-gateway" data-id="<?php echo esc_attr($g['id']); ?>">💾 ذخیره</button>
							</div>
						</div>
					<?php endforeach; endif; ?>
				</div>
			</div>

			<!-- CARDS -->
			<div class="cptt-pay-panel" data-panel="cards">
				<div class="cptt-pay-panel__head">
					<h2>کارت‌های بانکی (کارت به کارت)</h2>
					<button class="button button-primary" id="cptt-pay-add-card">+ افزودن کارت</button>
				</div>
				<div class="cptt-pay-grid cptt-pay-grid--cards" id="cptt-pay-cards-grid">
					<?php if (empty($s['cards'])): ?>
						<div class="cptt-pay-empty">هنوز هیچ کارتی اضافه نشده.</div>
					<?php else: foreach ($s['cards'] as $c): ?>
						<div class="cptt-pay-bankcard <?php echo !empty($c['active']) ? 'is-active' : 'is-off'; ?>" data-id="<?php echo esc_attr($c['id']); ?>">
							<div class="cptt-pay-bankcard__top">
								<span class="cptt-pay-bankcard__bank"><?php echo esc_html($c['bank'] ?? '—'); ?></span>
								<label class="cptt-pay-switch">
									<input type="checkbox" class="cptt-toggle-card" data-id="<?php echo esc_attr($c['id']); ?>" <?php checked(!empty($c['active'])); ?>>
									<span></span>
								</label>
							</div>
							<div class="cptt-pay-bankcard__number"><?php echo esc_html(self::mask_card($c['number'] ?? '')); ?></div>
							<div class="cptt-pay-bankcard__owner"><?php echo esc_html($c['owner'] ?? ''); ?></div>
							<?php if (!empty($c['sheba'])): ?><div class="cptt-pay-bankcard__sheba">IR <?php echo esc_html($c['sheba']); ?></div><?php endif; ?>
							<div class="cptt-pay-bankcard__fields">
								<label><span>نام بانک</span><input type="text" class="cptt-card-field" data-id="<?php echo esc_attr($c['id']); ?>" data-key="bank" value="<?php echo esc_attr($c['bank'] ?? ''); ?>" placeholder="ملت، ملی، سامان..."></label>
								<label><span>شماره کارت</span><input type="text" class="cptt-card-field" data-id="<?php echo esc_attr($c['id']); ?>" data-key="number" value="<?php echo esc_attr($c['number'] ?? ''); ?>" placeholder="۱۶ رقم"></label>
								<label><span>نام صاحب کارت</span><input type="text" class="cptt-card-field" data-id="<?php echo esc_attr($c['id']); ?>" data-key="owner" value="<?php echo esc_attr($c['owner'] ?? ''); ?>"></label>
								<label><span>شماره شبا (اختیاری)</span><input type="text" class="cptt-card-field" data-id="<?php echo esc_attr($c['id']); ?>" data-key="sheba" value="<?php echo esc_attr($c['sheba'] ?? ''); ?>" placeholder="۲۴ رقم بدون IR"></label>
							</div>
							<div class="cptt-pay-bankcard__foot">
								<button class="button button-link-delete cptt-remove-card" data-id="<?php echo esc_attr($c['id']); ?>">× حذف</button>
								<button class="button button-primary cptt-save-card" data-id="<?php echo esc_attr($c['id']); ?>">💾 ذخیره</button>
							</div>
						</div>
					<?php endforeach; endif; ?>
				</div>
			</div>

			<!-- RECEIPTS -->
			<div class="cptt-pay-panel" data-panel="receipts">
				<h2>🧾 رسیدهای کارت‌به‌کارت</h2>
				<?php $this->receipts_table(); ?>
			</div>

			<!-- SETTINGS -->
			<div class="cptt-pay-panel" data-panel="settings">
				<h2>⚙️ تنظیمات کلی صفحه‌ی پرداخت</h2>
				<table class="form-table">
					<tr><th><label for="cptt-pay-intro">متن معرفی صفحه‌ی پرداخت</label></th>
						<td><textarea id="cptt-pay-intro" rows="3" class="large-text"><?php echo esc_textarea($s['page_intro']); ?></textarea>
							<p class="description">این متن بالای لیست روش‌های پرداخت نمایش داده می‌شود.</p></td></tr>
				</table>
				<p><button class="button button-primary" id="cptt-save-settings">💾 ذخیره تنظیمات</button></p>
			</div>
		</div>
		<?php
	}

	private function receipts_table() {
		$q = new WP_Query(['post_type' => 'cptt_payment_receipt', 'post_status' => 'any', 'posts_per_page' => 50]);
		echo '<table class="cptt-pay-receipts"><thead><tr><th>پروژه</th><th>مشتری</th><th>مبلغ</th><th>کارت مقصد</th><th>رسید</th><th>تاریخ</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
		foreach ($q->posts as $p) {
			$pid = (int)get_post_meta($p->ID, 'project_id', true);
			$uid = (int)get_post_meta($p->ID, 'user_id', true);
			$amount = (float)get_post_meta($p->ID, 'amount', true);
			$att = (int)get_post_meta($p->ID, 'attachment_id', true);
			$card_id = (string)get_post_meta($p->ID, 'card_id', true);
			$st = get_post_meta($p->ID, 'status', true) ?: 'pending';
			$u = $uid ? get_user_by('id', $uid) : null;
			$st_label = ['pending'=>'⏳ در انتظار','approved'=>'✅ تأیید','rejected'=>'❌ رد'][$st] ?? $st;
			$st_class = ['pending'=>'p','approved'=>'a','rejected'=>'r'][$st] ?? 'p';
			$created = date('Y-m-d H:i', strtotime($p->post_date));
			if (class_exists('CPTT_Core') && method_exists('CPTT_Core','jalali_datetime')) $created = CPTT_Core::jalali_datetime(strtotime($p->post_date));
			echo '<tr class="cptt-rcpt-row cptt-rcpt-row--'.esc_attr($st_class).'">';
			echo '<td>'.esc_html(get_the_title($pid)).'</td>';
			echo '<td>'.esc_html($u?$u->display_name:'—').'</td>';
			echo '<td><b>'.(class_exists('CPTT_Currency')?CPTT_Currency::format($amount):number_format($amount).' تومان').'</b></td>';
			echo '<td>'.esc_html($card_id ?: '—').'</td>';
			echo '<td>'.($att ? '<a class="button button-small" href="'.esc_url(wp_get_attachment_url($att)).'" target="_blank">👁 مشاهده</a>' : '—').'</td>';
			echo '<td>'.esc_html($created).'</td>';
			echo '<td><span class="cptt-rcpt-st cptt-rcpt-st--'.esc_attr($st_class).'">'.esc_html($st_label).'</span></td>';
			echo '<td>';
			if ($st === 'pending') {
				echo '<button class="button button-primary cptt-approve-receipt" data-id="'.(int)$p->ID.'">✅ تایید</button> ';
				echo '<button class="button cptt-reject-receipt" data-id="'.(int)$p->ID.'">❌ رد</button>';
			} else { echo '—'; }
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if (empty($q->posts)) echo '<div class="cptt-pay-empty" style="margin-top:12px;">رسیدی ثبت نشده است.</div>';
	}

	/* =========================================================
	   AJAX – Gateways
	   ========================================================= */
	public function ajax_add_gateway() {
		check_ajax_referer('cptt_payment_admin', 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error('no_access');
		$driver = sanitize_key($_POST['driver'] ?? '');
		$drivers = self::available_drivers();
		if (!isset($drivers[$driver])) wp_send_json_error('invalid_driver');
		$s = self::get_settings();
		$id = 'g_' . wp_generate_password(8, false, false);
		$new = ['id' => $id, 'driver' => $driver, 'title' => $drivers[$driver]['title'], 'merchant' => '', 'merchant_secret' => '', 'active' => 0, 'sandbox' => 0];
		$s['enabled_gateways'][] = $new;
		self::update_settings($s);
		wp_send_json_success(['gateway' => $new]);
	}

	public function ajax_remove_gateway() {
		check_ajax_referer('cptt_payment_admin', 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error('no_access');
		$id = sanitize_text_field($_POST['id'] ?? '');
		$s = self::get_settings();
		$s['enabled_gateways'] = array_values(array_filter($s['enabled_gateways'], fn($g) => ($g['id'] ?? '') !== $id));
		self::update_settings($s);
		wp_send_json_success();
	}

	public function ajax_toggle_gateway() {
		check_ajax_referer('cptt_payment_admin', 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error('no_access');
		$id = sanitize_text_field($_POST['id'] ?? '');
		$active = !empty($_POST['active']) ? 1 : 0;
		$s = self::get_settings();
		foreach ($s['enabled_gateways'] as &$g) if (($g['id'] ?? '') === $id) $g['active'] = $active;
		unset($g);
		self::update_settings($s);
		wp_send_json_success();
	}

	/* =========================================================
	   AJAX – Cards
	   ========================================================= */
	public function ajax_add_card() {
		check_ajax_referer('cptt_payment_admin', 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error('no_access');
		$s = self::get_settings();
		$id = 'c_' . wp_generate_password(8, false, false);
		$new = ['id' => $id, 'bank' => '', 'number' => '', 'owner' => '', 'sheba' => '', 'active' => 0];
		$s['cards'][] = $new;
		self::update_settings($s);
		wp_send_json_success(['card' => $new]);
	}

	public function ajax_remove_card() {
		check_ajax_referer('cptt_payment_admin', 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error('no_access');
		$id = sanitize_text_field($_POST['id'] ?? '');
		$s = self::get_settings();
		$s['cards'] = array_values(array_filter($s['cards'], fn($c) => ($c['id'] ?? '') !== $id));
		self::update_settings($s);
		wp_send_json_success();
	}

	public function ajax_toggle_card() {
		check_ajax_referer('cptt_payment_admin', 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error('no_access');
		$id = sanitize_text_field($_POST['id'] ?? '');
		$active = !empty($_POST['active']) ? 1 : 0;
		$s = self::get_settings();
		foreach ($s['cards'] as &$c) if (($c['id'] ?? '') === $id) $c['active'] = $active;
		unset($c);
		self::update_settings($s);
		wp_send_json_success();
	}

	public function ajax_save() {
		check_ajax_referer('cptt_payment_admin', 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error('no_access');
		$type = sanitize_key($_POST['type'] ?? '');
		$id = sanitize_text_field($_POST['id'] ?? '');
		$payload = isset($_POST['payload']) && is_array($_POST['payload']) ? wp_unslash($_POST['payload']) : [];
		$s = self::get_settings();
		if ($type === 'gateway') {
			foreach ($s['enabled_gateways'] as &$g) {
				if (($g['id'] ?? '') === $id) {
					foreach ($payload as $k => $v) {
						$k = sanitize_key($k);
						if (in_array($k, ['driver','id'], true)) continue;
						if ($k === 'sandbox' || $k === 'active') $g[$k] = $v ? 1 : 0;
						else $g[$k] = sanitize_text_field((string)$v);
					}
				}
			}
			unset($g);
		} elseif ($type === 'card') {
			foreach ($s['cards'] as &$c) {
				if (($c['id'] ?? '') === $id) {
					foreach ($payload as $k => $v) {
						$k = sanitize_key($k);
						if ($k === 'id') continue;
						if ($k === 'active') $c[$k] = $v ? 1 : 0;
						else $c[$k] = sanitize_text_field((string)$v);
					}
				}
			}
			unset($c);
		} elseif ($type === 'settings') {
			$s['page_intro'] = sanitize_textarea_field((string)($payload['page_intro'] ?? ''));
		}
		self::update_settings($s);
		wp_send_json_success();
	}

	/* =========================================================
	   صفحه‌ی پرداخت عمومی
	   ========================================================= */
	public function render_pay_page() {
		$pid = absint($_GET['project_id'] ?? 0);
		if (!$pid) wp_die('پروژه نامعتبر است.');
		$amount = (float)($_GET['amount'] ?? 0);
		$steps = get_post_meta($pid, '_cptt_steps', true);
		if (!$amount && is_array($steps)) {
			foreach ($steps as $st) $amount += (float)($st['cost'] ?? 0) - (float)($st['paid'] ?? 0);
		}
		$amount = max(0, $amount);
		$methods = self::active_methods();
		$s = self::get_settings();
		$intro = $s['page_intro'];
		$project_title = get_the_title($pid);
		$selected = sanitize_text_field($_GET['method'] ?? '');

		$selected_method = null;
		foreach ($methods as $m) if ($m['id'] === $selected) $selected_method = $m;

		header('Content-Type: text/html; charset=utf-8');
		?><!doctype html><html dir="rtl" lang="fa"><head>
		<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
		<title>پرداخت پروژه — <?php echo esc_html($project_title); ?></title>
		<style>
			:root{--bg:#f4f6fb;--card:#fff;--ink:#0f172a;--muted:#64748b;--brand:#4f46e5;--brand2:#7c3aed;--ok:#16a34a;--warn:#b45309;--err:#dc2626;--bd:#e5e7eb}
			*{box-sizing:border-box}
			html,body{margin:0;padding:0;background:linear-gradient(160deg,#eef2ff,#fdf2f8);min-height:100vh;font-family:Tahoma,sans-serif;color:var(--ink)}
			.cptt-pay-page{max-width:760px;margin:30px auto;padding:0 16px}
			.cptt-pay-box{background:var(--card);border-radius:24px;box-shadow:0 30px 80px -20px rgba(15,23,42,.18);overflow:hidden;border:1px solid #eef2ff}
			.cptt-pay-header{background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;padding:24px 22px}
			.cptt-pay-header h1{margin:0 0 6px;font-size:22px}
			.cptt-pay-header .sub{opacity:.85;font-size:13px}
			.cptt-pay-amount-box{background:rgba(255,255,255,.12);backdrop-filter:blur(8px);border-radius:18px;padding:14px 18px;margin-top:14px;display:flex;justify-content:space-between;align-items:center;border:1px solid rgba(255,255,255,.18)}
			.cptt-pay-amount-box .lbl{font-size:13px;opacity:.85}
			.cptt-pay-amount-box .val{font-size:26px;font-weight:900;letter-spacing:.5px}
			.cptt-pay-body{padding:20px 22px}
			.cptt-pay-intro{color:var(--muted);margin:0 0 16px;font-size:13.5px;line-height:1.8}
			.cptt-pay-methods{display:grid;gap:10px}
			.cptt-pay-method{background:#fff;border:2px solid var(--bd);border-radius:16px;padding:14px;display:flex;align-items:center;gap:12px;cursor:pointer;transition:.2s;text-decoration:none;color:inherit}
			.cptt-pay-method:hover{border-color:var(--brand);transform:translateY(-2px);box-shadow:0 10px 24px -8px rgba(79,70,229,.25)}
			.cptt-pay-method__icon{width:46px;height:46px;border-radius:12px;background:linear-gradient(135deg,#eef2ff,#fdf2f8);display:flex;align-items:center;justify-content:center;font-size:22px}
			.cptt-pay-method__title{flex:1;font-weight:800}
			.cptt-pay-method__sub{font-size:11px;color:var(--muted);margin-top:2px;font-weight:500}
			.cptt-pay-method__arrow{color:var(--muted);font-size:18px}
			.cptt-pay-empty{padding:30px;text-align:center;color:var(--muted);background:#fafbff;border-radius:14px;border:1px dashed var(--bd)}
			.cptt-pay-back{display:inline-flex;align-items:center;gap:6px;color:var(--brand);text-decoration:none;font-weight:700;margin-bottom:14px;font-size:13px}
			/* CARD METHOD */
			.cptt-pay-cardbox{background:linear-gradient(135deg,#0f172a,#4f46e5);color:#fff;border-radius:18px;padding:20px;margin:6px 0 18px;position:relative;overflow:hidden;box-shadow:0 20px 50px -10px rgba(15,23,42,.4)}
			.cptt-pay-cardbox::before{content:"";position:absolute;top:-40px;right:-40px;width:160px;height:160px;background:radial-gradient(circle,rgba(255,255,255,.18),transparent);border-radius:50%}
			.cptt-pay-cardbox .bank{font-size:14px;opacity:.85;margin-bottom:14px;font-weight:700}
			.cptt-pay-cardbox .num{font-size:24px;font-weight:900;letter-spacing:4px;direction:ltr;text-align:left;font-family:'Courier New',monospace;margin-bottom:14px}
			.cptt-pay-cardbox .row{display:flex;justify-content:space-between;align-items:center;font-size:12px;opacity:.85}
			.cptt-pay-cardbox .copy{background:rgba(255,255,255,.15);border:0;color:#fff;padding:6px 12px;border-radius:8px;cursor:pointer;font-weight:700;font-size:11px;font-family:inherit}
			.cptt-pay-cardbox .copy:hover{background:rgba(255,255,255,.25)}
			.cptt-pay-steps{display:flex;gap:6px;margin:14px 0 18px}
			.cptt-pay-step{flex:1;height:6px;border-radius:99px;background:#e5e7eb}
			.cptt-pay-step.is-on{background:linear-gradient(90deg,var(--brand),var(--brand2))}
			.cptt-pay-section-title{font-size:13px;font-weight:800;color:var(--ink);margin:14px 0 8px}
			.cptt-pay-input{width:100%;padding:12px 14px;border:1.5px solid var(--bd);border-radius:12px;font-family:inherit;font-size:14px;background:#fafbff;transition:.15s}
			.cptt-pay-input:focus{outline:0;border-color:var(--brand);background:#fff;box-shadow:0 0 0 4px rgba(79,70,229,.1)}
			.cptt-pay-file-drop{border:2px dashed var(--bd);border-radius:14px;padding:30px 16px;text-align:center;background:#fafbff;cursor:pointer;transition:.2s}
			.cptt-pay-file-drop:hover{border-color:var(--brand);background:#fff}
			.cptt-pay-file-drop input{display:none}
			.cptt-pay-file-drop .icon{font-size:34px;margin-bottom:8px}
			.cptt-pay-file-drop .hint{font-size:12px;color:var(--muted);margin-top:4px}
			.cptt-pay-file-preview{margin-top:10px;font-size:12px;color:var(--ok);font-weight:700}
			.cptt-pay-btn{width:100%;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;border:0;border-radius:14px;padding:14px 18px;font-weight:900;font-size:15px;cursor:pointer;margin-top:14px;font-family:inherit;transition:.2s}
			.cptt-pay-btn:hover{transform:translateY(-2px);box-shadow:0 14px 30px -10px rgba(79,70,229,.4)}
			.cptt-pay-btn:disabled{opacity:.5;cursor:not-allowed}
			.cptt-pay-success{text-align:center;padding:30px 20px}
			.cptt-pay-success .icon{font-size:60px;margin-bottom:10px}
			.cptt-pay-success h2{color:var(--ok);margin:0 0 8px}
			.cptt-pay-success p{color:var(--muted);margin:0 0 16px;line-height:1.8}
			.cptt-pay-foot{padding:14px 22px;background:#fafbff;border-top:1px solid var(--bd);text-align:center;font-size:11px;color:var(--muted)}
		</style></head><body>
		<div class="cptt-pay-page">
			<div class="cptt-pay-box">
				<div class="cptt-pay-header">
					<h1>💳 پرداخت پروژه</h1>
					<div class="sub"><?php echo esc_html($project_title); ?></div>
					<div class="cptt-pay-amount-box">
						<span class="lbl">مبلغ قابل پرداخت</span>
						<span class="val"><?php echo class_exists('CPTT_Currency') ? esc_html(CPTT_Currency::format($amount)) : esc_html(number_format($amount) . ' تومان'); ?></span>
					</div>
				</div>
				<div class="cptt-pay-body">
				<?php if (!$selected_method): ?>
					<p class="cptt-pay-intro"><?php echo esc_html($intro); ?></p>
					<?php if (empty($methods)): ?>
						<div class="cptt-pay-empty">⚠️ هیچ روش پرداختی فعال نیست. لطفاً با مدیر سایت تماس بگیرید.</div>
					<?php else: ?>
						<div class="cptt-pay-methods">
						<?php foreach ($methods as $m):
							$url = add_query_arg(['method' => $m['id']], self::payment_url($pid, $amount)); ?>
							<a class="cptt-pay-method" href="<?php echo esc_url($url); ?>">
								<div class="cptt-pay-method__icon"><?php echo esc_html($m['icon']); ?></div>
								<div style="flex:1">
									<div class="cptt-pay-method__title"><?php echo esc_html($m['title']); ?></div>
									<div class="cptt-pay-method__sub"><?php echo $m['type'] === 'card' ? 'کارت به کارت + بارگذاری رسید' : 'پرداخت آنلاین — هدایت به درگاه'; ?></div>
								</div>
								<div class="cptt-pay-method__arrow">◀</div>
							</a>
						<?php endforeach; ?>
						</div>
					<?php endif; ?>
				<?php else: ?>
					<a class="cptt-pay-back" href="<?php echo esc_url(self::payment_url($pid, $amount)); ?>">→ تغییر روش پرداخت</a>
					<?php if ($selected_method['type'] === 'card'): ?>
						<?php $this->render_card_flow($pid, $amount, $selected_method); ?>
					<?php else: ?>
						<?php $this->render_gateway_flow($pid, $amount, $selected_method); ?>
					<?php endif; ?>
				<?php endif; ?>
				</div>
				<div class="cptt-pay-foot">
					🔒 تمام پرداخت‌ها از طریق درگاه‌های امن انجام می‌شود.
				</div>
			</div>
		</div>
		<script>
			(function(){
				document.querySelectorAll('.cptt-pay-cardbox .copy').forEach(function(b){
					b.addEventListener('click',function(){
						var t = b.getAttribute('data-copy');
						navigator.clipboard.writeText(t).then(function(){
							var o = b.textContent; b.textContent = '✓ کپی شد'; setTimeout(function(){b.textContent = o;}, 1500);
						});
					});
				});
				var drop = document.querySelector('.cptt-pay-file-drop');
				if (drop) {
					var inp = drop.querySelector('input[type=file]');
					var pv  = drop.querySelector('.cptt-pay-file-preview');
					inp && inp.addEventListener('change', function(){
						if (this.files && this.files[0]) pv.textContent = '✔ فایل انتخاب شد: ' + this.files[0].name;
					});
				}
			})();
		</script>
		</body></html><?php
		exit;
	}

	private function render_card_flow($pid, $amount, $method) {
		?>
		<div class="cptt-pay-steps">
			<div class="cptt-pay-step is-on"></div>
			<div class="cptt-pay-step is-on"></div>
			<div class="cptt-pay-step"></div>
		</div>
		<div class="cptt-pay-cardbox">
			<div class="bank"><?php echo esc_html($method['bank'] ?: 'کارت بانکی'); ?></div>
			<div class="num"><?php echo esc_html(self::mask_card($method['number'])); ?></div>
			<div class="row">
				<span><?php echo esc_html($method['owner'] ?: '—'); ?></span>
				<button class="copy" data-copy="<?php echo esc_attr(preg_replace('/\D+/','',$method['number'])); ?>" type="button">📋 کپی شماره کارت</button>
			</div>
			<?php if (!empty($method['sheba'])): ?>
			<div class="row" style="margin-top:8px"><span style="opacity:.7">شبا:</span> <span style="direction:ltr">IR<?php echo esc_html($method['sheba']); ?></span></div>
			<?php endif; ?>
		</div>

		<div style="background:#fef3c7;border:1px solid #fde68a;color:#92400e;border-radius:12px;padding:10px 14px;font-size:12.5px;line-height:1.8;margin-bottom:12px">
			۱) مبلغ <b><?php echo class_exists('CPTT_Currency')?esc_html(CPTT_Currency::format($amount)):esc_html(number_format($amount).' تومان'); ?></b> را به شماره کارت بالا واریز کنید.<br>
			۲) شماره پیگیری یا کد رهگیری تراکنش را یادداشت کنید.<br>
			۳) رسید را در فرم زیر بارگذاری کرده و ارسال نمایید.
		</div>

		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="cptt_submit_card_receipt">
			<input type="hidden" name="project_id" value="<?php echo esc_attr($pid); ?>">
			<input type="hidden" name="amount" value="<?php echo esc_attr($amount); ?>">
			<input type="hidden" name="card_id" value="<?php echo esc_attr($method['id']); ?>">
			<?php wp_nonce_field('cptt_submit_card_receipt_' . $pid, 'nonce'); ?>

			<div class="cptt-pay-section-title">📝 شماره پیگیری / کد رهگیری (اختیاری)</div>
			<input type="text" name="track_code" class="cptt-pay-input" placeholder="مثلاً ۱۲۳۴۵۶۷۸">

			<div class="cptt-pay-section-title">📎 بارگذاری رسید پرداخت</div>
			<label class="cptt-pay-file-drop">
				<div class="icon">📤</div>
				<div><b>روی این کادر بزنید یا فایل را اینجا رها کنید</b></div>
				<div class="hint">JPG / PNG / PDF — حداکثر ۵ مگابایت</div>
				<input type="file" name="receipt" required accept="image/*,application/pdf">
				<div class="cptt-pay-file-preview"></div>
			</label>

			<div class="cptt-pay-section-title">💬 توضیحات (اختیاری)</div>
			<textarea name="note" rows="2" class="cptt-pay-input" placeholder="در صورت نیاز توضیحی اضافه کنید..."></textarea>

			<button type="submit" class="cptt-pay-btn">✅ ثبت رسید برای بررسی مدیر</button>
		</form>
		<?php
	}

	private function render_gateway_flow($pid, $amount, $method) {
		// آغاز پرداخت با درایور — Redirect ساده
		$txn_id = self::create_transaction($pid, $amount, $method['id'], 'gateway', $method['driver']);
		$result = self::driver_request($method['driver'], $method['id'], $amount, $txn_id, $pid);
		?>
		<div class="cptt-pay-steps">
			<div class="cptt-pay-step is-on"></div>
			<div class="cptt-pay-step is-on"></div>
			<div class="cptt-pay-step"></div>
		</div>
		<div style="text-align:center;padding:20px 10px">
			<?php if (!empty($result['ok']) && !empty($result['redirect'])): ?>
				<div style="font-size:48px;margin-bottom:10px">🔄</div>
				<h3 style="margin:0 0 8px">در حال اتصال به <?php echo esc_html($method['title']); ?>...</h3>
				<p style="color:#64748b;margin:0 0 16px">اگر به‌صورت خودکار منتقل نشدید، روی دکمه‌ی زیر کلیک کنید.</p>
				<a class="cptt-pay-btn" style="display:inline-block;text-decoration:none;max-width:260px" href="<?php echo esc_url($result['redirect']); ?>">رفتن به درگاه پرداخت ←</a>
				<script>setTimeout(function(){location.href=<?php echo wp_json_encode($result['redirect']); ?>;}, 1500);</script>
			<?php else: ?>
				<div style="font-size:48px;margin-bottom:10px">⚠️</div>
				<h3 style="margin:0 0 8px;color:#dc2626">خطا در اتصال به درگاه</h3>
				<p style="color:#64748b;margin:0 0 16px"><?php echo esc_html($result['message'] ?? 'لطفاً دوباره تلاش کنید یا روش پرداخت دیگری انتخاب نمایید.'); ?></p>
				<a class="cptt-pay-btn" style="display:inline-block;text-decoration:none;max-width:260px" href="<?php echo esc_url(self::payment_url($pid, $amount)); ?>">انتخاب روش دیگر</a>
			<?php endif; ?>
		</div>
		<?php
	}

	/* =========================================================
	   تراکنش‌ها
	   ========================================================= */
	private static function create_transaction($pid, $amount, $method_id, $type, $driver) {
		$txn_id = wp_generate_password(20, false, false);
		$id = wp_insert_post([
			'post_type'   => 'cptt_payment_txn',
			'post_status' => 'publish',
			'post_title'  => 'تراکنش ' . $txn_id . ' — پروژه #' . $pid,
		]);
		update_post_meta($id, 'project_id', (int)$pid);
		update_post_meta($id, 'user_id', get_current_user_id());
		update_post_meta($id, 'amount', (float)$amount);
		update_post_meta($id, 'method_id', $method_id);
		update_post_meta($id, 'type', $type);
		update_post_meta($id, 'driver', $driver);
		update_post_meta($id, 'txn_ref', $txn_id);
		update_post_meta($id, 'status', 'pending');
		return $txn_id;
	}

	private static function get_transaction_by_txn($txn_id) {
		$q = new WP_Query(['post_type' => 'cptt_payment_txn', 'meta_key' => 'txn_ref', 'meta_value' => $txn_id, 'posts_per_page' => 1]);
		return $q->posts[0] ?? null;
	}

	private static function find_gateway($method_id) {
		$s = self::get_settings();
		foreach ($s['enabled_gateways'] as $g) if (($g['id'] ?? '') === $method_id) return $g;
		return null;
	}

	/* =========================================================
	   درایور درگاه — Adapter
	   ========================================================= */
	public static function driver_request($driver, $method_id, $amount, $txn_id, $project_id) {
		$gateway = self::find_gateway($method_id);
		if (!$gateway) return ['ok' => false, 'message' => 'درگاه یافت نشد.'];
		$amount_toman = (int)$amount;
		$amount_rial  = $amount_toman * 10;
		$callback     = self::callback_url($txn_id);
		$desc         = 'پرداخت پروژه #' . $project_id;

		try {
			switch ($driver) {
				case 'zarinpal':
					$endpoint = !empty($gateway['sandbox'])
						? 'https://sandbox.zarinpal.com/pg/v4/payment/request.json'
						: 'https://api.zarinpal.com/pg/v4/payment/request.json';
					$res = wp_remote_post($endpoint, [
						'timeout' => 20, 'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
						'body' => wp_json_encode([
							'merchant_id' => $gateway['merchant'],
							'amount' => $amount_toman, // ZP V4: تومان
							'callback_url' => $callback,
							'description' => $desc,
						]),
					]);
					if (is_wp_error($res)) throw new Exception($res->get_error_message());
					$body = json_decode(wp_remote_retrieve_body($res), true);
					$authority = $body['data']['authority'] ?? '';
					if (!$authority) throw new Exception($body['errors']['message'] ?? 'پاسخ نامعتبر از زرین‌پال');
					update_post_meta(self::get_transaction_by_txn($txn_id)->ID, 'authority', $authority);
					$pay_url = (!empty($gateway['sandbox']) ? 'https://sandbox.zarinpal.com/pg/StartPay/' : 'https://www.zarinpal.com/pg/StartPay/') . $authority;
					return ['ok' => true, 'redirect' => $pay_url];

				case 'zibal':
					$res = wp_remote_post('https://gateway.zibal.ir/v1/request', [
						'timeout' => 20, 'headers' => ['Content-Type' => 'application/json'],
						'body' => wp_json_encode([
							'merchant' => $gateway['merchant'],
							'amount' => $amount_rial,
							'callbackUrl' => $callback,
							'description' => $desc,
						]),
					]);
					if (is_wp_error($res)) throw new Exception($res->get_error_message());
					$body = json_decode(wp_remote_retrieve_body($res), true);
					if (($body['result'] ?? -1) != 100) throw new Exception($body['message'] ?? 'خطای زیبال');
					$track = $body['trackId'];
					update_post_meta(self::get_transaction_by_txn($txn_id)->ID, 'authority', $track);
					return ['ok' => true, 'redirect' => 'https://gateway.zibal.ir/start/' . $track];

				case 'idpay':
					$res = wp_remote_post('https://api.idpay.ir/v1.1/payment', [
						'timeout' => 20,
						'headers' => ['Content-Type' => 'application/json', 'X-API-KEY' => $gateway['merchant'], 'X-SANDBOX' => !empty($gateway['sandbox']) ? '1' : '0'],
						'body' => wp_json_encode([
							'order_id' => $txn_id,
							'amount' => $amount_rial,
							'callback' => $callback,
							'desc' => $desc,
						]),
					]);
					if (is_wp_error($res)) throw new Exception($res->get_error_message());
					$body = json_decode(wp_remote_retrieve_body($res), true);
					if (empty($body['link']) || empty($body['id'])) throw new Exception($body['error_message'] ?? 'خطای آیدی‌پی');
					update_post_meta(self::get_transaction_by_txn($txn_id)->ID, 'authority', $body['id']);
					return ['ok' => true, 'redirect' => $body['link']];

				case 'nextpay':
					$res = wp_remote_post('https://nextpay.org/nx/gateway/token', [
						'timeout' => 20,
						'body' => [
							'api_key' => $gateway['merchant'],
							'amount' => $amount_rial,
							'order_id' => $txn_id,
							'callback_uri' => $callback,
							'currency' => 'IRR',
						],
					]);
					if (is_wp_error($res)) throw new Exception($res->get_error_message());
					$body = json_decode(wp_remote_retrieve_body($res), true);
					if (($body['code'] ?? -1) != -1 || empty($body['trans_id'])) throw new Exception('خطای نکست‌پی: ' . ($body['code'] ?? '?'));
					update_post_meta(self::get_transaction_by_txn($txn_id)->ID, 'authority', $body['trans_id']);
					return ['ok' => true, 'redirect' => 'https://nextpay.org/nx/gateway/payment/' . $body['trans_id']];

				case 'payping':
					$res = wp_remote_post('https://api.payping.ir/v2/pay', [
						'timeout' => 20,
						'headers' => ['Authorization' => 'Bearer ' . $gateway['merchant'], 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
						'body' => wp_json_encode([
							'amount' => $amount_toman, // PayPing: تومان
							'returnUrl' => $callback,
							'clientRefId' => $txn_id,
							'description' => $desc,
						]),
					]);
					if (is_wp_error($res)) throw new Exception($res->get_error_message());
					$body = json_decode(wp_remote_retrieve_body($res), true);
					$code = $body['code'] ?? '';
					if (!$code) throw new Exception('خطای پی‌پینگ');
					update_post_meta(self::get_transaction_by_txn($txn_id)->ID, 'authority', $code);
					return ['ok' => true, 'redirect' => 'https://api.payping.ir/v2/pay/gotoipg/' . $code];

				default:
					return ['ok' => false, 'message' => 'درایور «' . $driver . '» هنوز فعال نیست. لطفاً روش دیگری انتخاب کنید.'];
			}
		} catch (Exception $e) {
			return ['ok' => false, 'message' => $e->getMessage()];
		}
	}

	public function handle_gateway_callback() {
		$txn_id = sanitize_text_field($_GET['txn'] ?? '');
		$txn = self::get_transaction_by_txn($txn_id);
		if (!$txn) wp_die('تراکنش یافت نشد.');
		$pid = (int)get_post_meta($txn->ID, 'project_id', true);
		$amount = (float)get_post_meta($txn->ID, 'amount', true);
		$driver = (string)get_post_meta($txn->ID, 'driver', true);
		$method_id = (string)get_post_meta($txn->ID, 'method_id', true);
		$gateway = self::find_gateway($method_id);
		$authority = (string)get_post_meta($txn->ID, 'authority', true);

		$verified = false;
		$ref_id = '';
		$err = '';

		try {
			switch ($driver) {
				case 'zarinpal':
					$status = sanitize_text_field($_GET['Status'] ?? '');
					if ($status !== 'OK') throw new Exception('پرداخت توسط کاربر لغو شد.');
					$endpoint = !empty($gateway['sandbox']) ? 'https://sandbox.zarinpal.com/pg/v4/payment/verify.json' : 'https://api.zarinpal.com/pg/v4/payment/verify.json';
					$res = wp_remote_post($endpoint, ['timeout' => 20, 'headers'=>['Content-Type'=>'application/json'], 'body' => wp_json_encode(['merchant_id'=>$gateway['merchant'],'amount'=>(int)$amount,'authority'=>$authority])]);
					$body = json_decode(wp_remote_retrieve_body($res), true);
					if (($body['data']['code'] ?? 0) == 100 || ($body['data']['code'] ?? 0) == 101) { $verified = true; $ref_id = (string)($body['data']['ref_id'] ?? ''); }
					else throw new Exception('تایید تراکنش ناموفق.');
					break;

				case 'zibal':
					$success = sanitize_text_field($_GET['success'] ?? '');
					if ($success !== '1') throw new Exception('پرداخت ناموفق.');
					$res = wp_remote_post('https://gateway.zibal.ir/v1/verify', ['timeout'=>20,'headers'=>['Content-Type'=>'application/json'],'body'=>wp_json_encode(['merchant'=>$gateway['merchant'],'trackId'=>$authority])]);
					$body = json_decode(wp_remote_retrieve_body($res), true);
					if (($body['result'] ?? 0) == 100 && (int)($body['status'] ?? 0) == 1) { $verified = true; $ref_id = (string)($body['refNumber'] ?? ''); }
					else throw new Exception($body['message'] ?? 'تایید ناموفق.');
					break;

				case 'idpay':
					$res = wp_remote_post('https://api.idpay.ir/v1.1/payment/verify', [
						'timeout'=>20,'headers'=>['Content-Type'=>'application/json','X-API-KEY'=>$gateway['merchant'],'X-SANDBOX'=>!empty($gateway['sandbox'])?'1':'0'],
						'body'=>wp_json_encode(['id'=>$authority,'order_id'=>$txn_id]),
					]);
					$body = json_decode(wp_remote_retrieve_body($res), true);
					if ((int)($body['status'] ?? 0) === 100) { $verified = true; $ref_id = (string)($body['track_id'] ?? ''); }
					else throw new Exception($body['error_message'] ?? 'ناموفق');
					break;

				case 'nextpay':
					$res = wp_remote_post('https://nextpay.org/nx/gateway/verify', ['timeout'=>20,'body'=>['api_key'=>$gateway['merchant'],'trans_id'=>$authority,'amount'=>(int)$amount*10,'currency'=>'IRR']]);
					$body = json_decode(wp_remote_retrieve_body($res), true);
					if ((int)($body['code'] ?? 0) === 0) { $verified = true; $ref_id = (string)($body['Shaparak_Ref_Id'] ?? ''); }
					else throw new Exception('کد خطا: ' . ($body['code'] ?? '?'));
					break;

				case 'payping':
					$refid = sanitize_text_field($_POST['refid'] ?? ($_GET['refid'] ?? ''));
					if (!$refid) throw new Exception('refid وجود ندارد.');
					$res = wp_remote_post('https://api.payping.ir/v2/pay/verify', ['timeout'=>20,'headers'=>['Authorization'=>'Bearer '.$gateway['merchant'],'Content-Type'=>'application/json'],'body'=>wp_json_encode(['amount'=>(int)$amount,'refId'=>$refid])]);
					$body = json_decode(wp_remote_retrieve_body($res), true);
					if (!empty($body['cardNumber']) || !empty($body['amount'])) { $verified = true; $ref_id = $refid; }
					else throw new Exception('ناموفق');
					break;

				default:
					throw new Exception('درایور پشتیبانی نمی‌شود.');
			}
		} catch (Exception $e) { $err = $e->getMessage(); }

		if ($verified) {
			update_post_meta($txn->ID, 'status', 'approved');
			update_post_meta($txn->ID, 'ref_id', $ref_id);
			update_post_meta($txn->ID, 'verified_at', current_time('mysql'));
			$this->apply_payment_to_project($pid, $amount);
			if (class_exists('CPTT_Core')) {
				CPTT_Core::ledger_add(['project_id' => $pid, 'type' => 'gateway_payment', 'amount' => $amount, 'note' => 'پرداخت موفق ' . $driver . ' — کد: ' . $ref_id]);
				CPTT_Core::activity_log('payment_txn', $txn->ID, 'gateway_verified', 'پرداخت آنلاین تایید شد. درگاه: ' . $driver);
			}
			$this->render_result_page(true, $pid, $amount, $ref_id, $driver);
		} else {
			update_post_meta($txn->ID, 'status', 'failed');
			update_post_meta($txn->ID, 'error', $err);
			$this->render_result_page(false, $pid, $amount, '', $driver, $err);
		}
	}

	private function render_result_page($ok, $pid, $amount, $ref_id, $driver, $err = '') {
		header('Content-Type: text/html; charset=utf-8');
		?><!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8"><title><?php echo $ok ? 'پرداخت موفق' : 'پرداخت ناموفق'; ?></title>
		<style>body{font-family:Tahoma;background:linear-gradient(160deg,#eef2ff,#fdf2f8);min-height:100vh;margin:0;display:flex;align-items:center;justify-content:center;padding:20px}.box{max-width:480px;width:100%;background:#fff;border-radius:24px;padding:36px 28px;text-align:center;box-shadow:0 30px 80px -20px rgba(15,23,42,.18)}.ic{font-size:80px;margin-bottom:14px}.ok{color:#16a34a}.fail{color:#dc2626}h1{margin:0 0 8px;font-size:22px}p{color:#64748b;line-height:1.9;margin:6px 0}.amt{font-size:22px;font-weight:900;color:#0f172a;margin:14px 0}.ref{background:#f1f5f9;border-radius:12px;padding:10px 14px;font-family:monospace;color:#0f172a;display:inline-block;margin:8px 0}.btn{display:inline-block;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;padding:12px 22px;border-radius:12px;text-decoration:none;font-weight:800;margin-top:16px}</style></head><body>
		<div class="box">
			<?php if ($ok): ?>
				<div class="ic ok">✅</div>
				<h1 class="ok">پرداخت موفق</h1>
				<div class="amt"><?php echo class_exists('CPTT_Currency')?esc_html(CPTT_Currency::format($amount)):esc_html(number_format($amount).' تومان'); ?></div>
				<p>پرداخت شما برای پروژه <b><?php echo esc_html(get_the_title($pid)); ?></b> با موفقیت ثبت شد.</p>
				<?php if ($ref_id): ?><div class="ref">کد پیگیری: <?php echo esc_html($ref_id); ?></div><?php endif; ?>
			<?php else: ?>
				<div class="ic fail">⚠️</div>
				<h1 class="fail">پرداخت ناموفق</h1>
				<p><?php echo esc_html($err ?: 'متاسفانه تراکنش انجام نشد. لطفاً مجدد تلاش کنید.'); ?></p>
			<?php endif; ?>
			<a class="btn" href="<?php echo esc_url(home_url('/')); ?>">بازگشت به سایت</a>
		</div></body></html><?php
		exit;
	}

	/* =========================================================
	   رسید کارت‌به‌کارت
	   ========================================================= */
	public function submit_card_receipt() {
		$pid = absint($_POST['project_id'] ?? 0);
		check_admin_referer('cptt_submit_card_receipt_' . $pid, 'nonce');
		$amount = (float)($_POST['amount'] ?? 0);
		$card_id = sanitize_text_field($_POST['card_id'] ?? '');
		$track_code = sanitize_text_field($_POST['track_code'] ?? '');
		$note = sanitize_textarea_field($_POST['note'] ?? '');

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$att = 0;
		if (!empty($_FILES['receipt']['name'])) {
			$att = media_handle_upload('receipt', 0);
			if (is_wp_error($att)) $att = 0;
		}

		$rid = wp_insert_post([
			'post_type' => 'cptt_payment_receipt', 'post_status' => 'publish',
			'post_title' => 'رسید پرداخت پروژه #' . $pid . ' — ' . date('Y-m-d H:i'),
		]);
		update_post_meta($rid, 'project_id', $pid);
		update_post_meta($rid, 'user_id', get_current_user_id());
		update_post_meta($rid, 'amount', $amount);
		update_post_meta($rid, 'attachment_id', (int)$att);
		update_post_meta($rid, 'card_id', $card_id);
		update_post_meta($rid, 'track_code', $track_code);
		update_post_meta($rid, 'note', $note);
		update_post_meta($rid, 'status', 'pending');

		if (class_exists('CPTT_Core')) {
			CPTT_Core::activity_log('payment_receipt', $rid, 'receipt_submitted', 'ثبت رسید پرداخت پروژه #' . $pid);
		}

		// Notify admin via Bale (اگر فعال)
		if (class_exists('CPTT_Bale') && method_exists('CPTT_Bale', 'notify_admin')) {
			$msg = "🧾 *رسید کارت‌به‌کارت جدید*\n\nپروژه: " . get_the_title($pid) . "\nمبلغ: " . (class_exists('CPTT_Currency')?CPTT_Currency::format($amount):number_format($amount).' تومان');
			if ($track_code) $msg .= "\nکد پیگیری: " . $track_code;
			@call_user_func(['CPTT_Bale', 'notify_admin'], $msg);
		}

		// صفحه‌ی موفقیت
		header('Content-Type: text/html; charset=utf-8');
		?><!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8"><title>رسید ثبت شد</title>
		<style>body{font-family:Tahoma;background:linear-gradient(160deg,#eef2ff,#fdf2f8);min-height:100vh;margin:0;display:flex;align-items:center;justify-content:center;padding:20px}.box{max-width:480px;width:100%;background:#fff;border-radius:24px;padding:36px 28px;text-align:center;box-shadow:0 30px 80px -20px rgba(15,23,42,.18)}.ic{font-size:70px;margin-bottom:10px}h1{color:#16a34a;margin:0 0 8px}p{color:#64748b;line-height:1.9}.btn{display:inline-block;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;padding:12px 22px;border-radius:12px;text-decoration:none;font-weight:800;margin-top:16px}</style></head><body>
		<div class="box">
			<div class="ic">✅</div>
			<h1>رسید با موفقیت ثبت شد</h1>
			<p>رسید پرداخت شما برای پروژه <b><?php echo esc_html(get_the_title($pid)); ?></b><br>به مبلغ <b><?php echo class_exists('CPTT_Currency')?esc_html(CPTT_Currency::format($amount)):esc_html(number_format($amount).' تومان'); ?></b> ثبت شد.</p>
			<p>پس از بررسی و تایید مدیر سایت، پرداخت شما در حساب پروژه اعمال خواهد شد.</p>
			<a class="btn" href="<?php echo esc_url(home_url('/')); ?>">بازگشت به سایت</a>
		</div></body></html><?php
		exit;
	}

	public function ajax_approve_receipt() {
		check_ajax_referer('cptt_payment_admin', 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error('no_access');
		$id = absint($_POST['id'] ?? 0);
		$pid = (int)get_post_meta($id, 'project_id', true);
		$amount = (float)get_post_meta($id, 'amount', true);
		update_post_meta($id, 'status', 'approved');
		update_post_meta($id, 'approved_at', current_time('mysql'));
		update_post_meta($id, 'approved_by', get_current_user_id());
		if (class_exists('CPTT_Core')) {
			CPTT_Core::ledger_add(['project_id' => $pid, 'type' => 'customer_card_payment', 'amount' => $amount, 'note' => 'تایید رسید کارت‌به‌کارت']);
			CPTT_Core::activity_log('payment_receipt', $id, 'receipt_approved', 'تایید رسید پرداخت پروژه #' . $pid);
		}
		$this->apply_payment_to_project($pid, $amount);
		wp_send_json_success();
	}

	public function ajax_reject_receipt() {
		check_ajax_referer('cptt_payment_admin', 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error('no_access');
		$id = absint($_POST['id'] ?? 0);
		update_post_meta($id, 'status', 'rejected');
		update_post_meta($id, 'rejected_at', current_time('mysql'));
		wp_send_json_success();
	}

	public function handle_method_select() {
		// fallback: redirect to pay page with method
		$pid = absint($_REQUEST['project_id'] ?? 0);
		$amount = (float)($_REQUEST['amount'] ?? 0);
		$method = sanitize_text_field($_REQUEST['method'] ?? '');
		wp_safe_redirect(add_query_arg(['method' => $method], self::payment_url($pid, $amount)));
		exit;
	}

	/* =========================================================
	   اعمال پرداخت روی پروژه
	   ========================================================= */
	private function apply_payment_to_project($pid, $amount) {
		$steps = get_post_meta($pid, '_cptt_steps', true);
		if (!is_array($steps)) return;
		$left = (float)$amount;
		foreach ($steps as &$st) {
			$remain = max(0, (float)($st['cost'] ?? 0) - (float)($st['paid'] ?? 0));
			if ($remain <= 0) continue;
			$pay = min($left, $remain);
			$st['paid'] = (float)($st['paid'] ?? 0) + $pay;
			$left -= $pay;
			if ($left <= 0) break;
		}
		unset($st);
		update_post_meta($pid, '_cptt_steps', $steps);
	}
}
