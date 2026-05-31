<?php
/**
 * CPTT Payment v5.4.17 — سیستم پرداخت چند درگاهه‌ی حرفه‌ای
 *
 * قابلیت‌ها:
 *  - چند روش پرداخت همزمان (add/remove/enable/disable per method)
 *  - کارت‌به‌کارت با چند کارت بانکی + آپلود رسید + تایید/رد ادمین
 *  - درگاه‌های آنلاین: زرین‌پال، زیبال، آیدی‌پی، نکست‌پی، پی‌پینگ، درگاه بانک
 *  - صفحه‌ی پرداخت زیبا با انتخاب روش پرداخت توسط مشتری
 *  - یکپارچگی کامل با ربات بله (لینک یک‌کلیکی پرداخت)
 *  - پنل ادمین مدیریت رسیدها (تایید/رد، اعمال خودکار به پروژه)
 *  - Activity log
 */

if (!defined('ABSPATH')) exit;

class CPTT_Payment {

	const OPT          = 'cptt_payment_settings';
	const NONCE_SAVE   = 'cptt_payment_save';
	const NONCE_AJAX   = 'cptt_payment_nonce';
	const NONCE_PAY    = 'cptt_pay_project';
	const NONCE_RCPT   = 'cptt_submit_card_receipt';
	const CPT_RECEIPT  = 'cptt_payment_receipt';

	private static $instance = null;

	/** Default gateway types & their meta */
	public static function gateway_types() {
		return [
			'card'     => [
				'label'   => 'کارت به کارت',
				'icon'    => '💳',
				'color'   => '#16a34a',
				'desc'    => 'مشتری به کارت‌های شما واریز می‌کند و رسید آپلود می‌کند. شما تایید می‌کنید.',
				'fields'  => ['cards'], // آرایه‌ای از کارت‌ها
			],
			'zarinpal' => [
				'label'   => 'زرین‌پال',
				'icon'    => '🟪',
				'color'   => '#7c3aed',
				'desc'    => 'درگاه آنلاین زرین‌پال (Zarinpal). به مرچنت کد نیاز دارد.',
				'fields'  => ['merchant_id', 'sandbox'],
			],
			'zibal'    => [
				'label'   => 'زیبال',
				'icon'    => '🟦',
				'color'   => '#2563eb',
				'desc'    => 'درگاه آنلاین زیبال (Zibal). به مرچنت کد نیاز دارد.',
				'fields'  => ['merchant_id', 'sandbox'],
			],
			'idpay'    => [
				'label'   => 'آیدی‌پی',
				'icon'    => '🟧',
				'color'   => '#ea580c',
				'desc'    => 'درگاه آنلاین IDPay. به API Key نیاز دارد.',
				'fields'  => ['api_key', 'sandbox'],
			],
			'nextpay'  => [
				'label'   => 'نکست‌پی',
				'icon'    => '🟨',
				'color'   => '#ca8a04',
				'desc'    => 'درگاه آنلاین NextPay.',
				'fields'  => ['api_key'],
			],
			'payping'  => [
				'label'   => 'پی‌پینگ',
				'icon'    => '🟢',
				'color'   => '#059669',
				'desc'    => 'درگاه آنلاین PayPing.',
				'fields'  => ['token'],
			],
			'bank'     => [
				'label'   => 'درگاه بانک مستقیم',
				'icon'    => '🏦',
				'color'   => '#0ea5e9',
				'desc'    => 'لینک سفارشی درگاه بانک (به‌پرداخت ملت، سامان، ملی، پاسارگاد...).',
				'fields'  => ['bank_url', 'bank_name'],
			],
		];
	}

	public static function instance() {
		if (self::$instance === null) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		// CPT برای رسیدها (در class-cptt-core ممکن است نباشد، اینجا register می‌کنیم)
		add_action('init', [$this, 'register_cpt']);

		add_action('admin_menu', [$this, 'menu']);

		// صفحه‌ی پرداخت برای مشتری (هم برای لاگین‌شده هم بدون لاگین)
		add_action('admin_post_cptt_pay_project',          [$this, 'render_pay_page']);
		add_action('admin_post_nopriv_cptt_pay_project',   [$this, 'render_pay_page']);

		// ارسال رسید کارت‌به‌کارت
		add_action('admin_post_cptt_submit_card_receipt',        [$this, 'submit_card_receipt']);
		add_action('admin_post_nopriv_cptt_submit_card_receipt', [$this, 'submit_card_receipt']);

		// شروع پرداخت آنلاین + callback
		add_action('admin_post_cptt_start_online_pay',         [$this, 'start_online_pay']);
		add_action('admin_post_nopriv_cptt_start_online_pay',  [$this, 'start_online_pay']);
		add_action('admin_post_cptt_online_callback',          [$this, 'online_callback']);
		add_action('admin_post_nopriv_cptt_online_callback',   [$this, 'online_callback']);

		// AJAX
		add_action('wp_ajax_cptt_approve_receipt', [$this, 'ajax_approve_receipt']);
		add_action('wp_ajax_cptt_reject_receipt',  [$this, 'ajax_reject_receipt']);
		add_action('wp_ajax_cptt_pay_save',        [$this, 'ajax_save_settings']);
	}

	public function register_cpt() {
		register_post_type(self::CPT_RECEIPT, [
			'public'              => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_admin_bar'   => false,
			'supports'            => ['title'],
			'capability_type'     => 'post',
		]);
	}

	/* =====================================================================
	 * SETTINGS
	 * ===================================================================== */

	public static function defaults() {
		return [
			'gateways'         => [
				[
					'id'      => 'g_card_default',
					'type'    => 'card',
					'name'    => 'کارت به کارت',
					'enabled' => 1,
					'cards'   => [
						// ['number'=>'6037-9971-...', 'owner'=>'...', 'bank'=>'ملی'],
					],
					'note'    => 'پس از واریز، رسید را آپلود کنید. سفارش پس از تایید مدیر فعال می‌شود.',
				],
			],
			'default_gateway'  => 'g_card_default',
			'currency_label'   => 'تومان',
			'currency_factor'  => 1, // 1 = تومان، 10 = ریال
			'success_message'  => '✅ پرداخت با موفقیت ثبت شد. سپاسگزاریم!',
			'pending_message'  => '⏳ رسید شما ثبت شد و در انتظار تایید مدیر است.',
		];
	}

	public static function get_settings() {
		$opt = get_option(self::OPT, []);
		if (!is_array($opt)) $opt = [];
		$out = wp_parse_args($opt, self::defaults());
		if (!is_array($out['gateways']) || empty($out['gateways'])) {
			$out['gateways'] = self::defaults()['gateways'];
		}
		// نرمال‌سازی gateway ها
		foreach ($out['gateways'] as &$g) {
			if (!is_array($g)) continue;
			$g['id']      = isset($g['id']) ? sanitize_key($g['id']) : ('g_' . wp_generate_password(6, false));
			$g['type']    = isset($g['type']) ? sanitize_key($g['type']) : 'card';
			$g['name']    = isset($g['name']) ? (string)$g['name'] : '';
			$g['enabled'] = !empty($g['enabled']) ? 1 : 0;
			if ($g['type'] === 'card' && (!isset($g['cards']) || !is_array($g['cards']))) $g['cards'] = [];
		}
		unset($g);
		return $out;
	}

	public static function enabled_gateways() {
		$s = self::get_settings();
		$out = [];
		foreach ($s['gateways'] as $g) {
			if (!empty($g['enabled'])) $out[] = $g;
		}
		return $out;
	}

	public static function find_gateway($gid) {
		$s = self::get_settings();
		foreach ($s['gateways'] as $g) {
			if (isset($g['id']) && (string)$g['id'] === (string)$gid) return $g;
		}
		return null;
	}

	/**
	 * URL پرداخت پروژه (لینک یکپارچه‌ی صفحه پرداخت برای ربات بله / فاکتور / فرانت)
	 */
	public static function payment_url($project_id, $amount = 0) {
		return wp_nonce_url(
			admin_url('admin-post.php?action=cptt_pay_project&project_id=' . (int)$project_id . '&amount=' . (float)$amount),
			self::NONCE_PAY . '_' . (int)$project_id
		);
	}

	public static function project_remaining($project_id) {
		$steps = get_post_meta((int)$project_id, '_cptt_steps', true);
		$remain = 0;
		if (is_array($steps)) {
			foreach ($steps as $st) {
				$remain += max(0, (float)($st['cost'] ?? 0) - (float)($st['paid'] ?? 0));
			}
		}
		return $remain;
	}

	/* =====================================================================
	 * ADMIN MENU + SETTINGS UI
	 * ===================================================================== */

	public function menu() {
		add_submenu_page(
			'edit.php?post_type=cptt_project',
			'پرداخت‌ها',
			'پرداخت‌ها 💳',
			'manage_options',
			'cptt-payments',
			[$this, 'page']
		);
	}

	public function page() {
		if (!current_user_can('manage_options')) return;

		// ذخیره تنظیمات (POST سنتی)
		if (!empty($_POST['cptt_payment_nonce']) && wp_verify_nonce(wp_unslash($_POST['cptt_payment_nonce']), self::NONCE_SAVE)) {
			$this->save_from_post();
			echo '<div class="notice notice-success is-dismissible"><p><strong>✅ تنظیمات با موفقیت ذخیره شد.</strong></p></div>';
		}

		$s     = self::get_settings();
		$types = self::gateway_types();
		?>
		<div class="wrap cptt-pay-wrap" dir="rtl">
			<style><?php $this->print_admin_css(); ?></style>

			<div class="cptt-pay-hero">
				<div class="cptt-pay-hero__bg"></div>
				<div class="cptt-pay-hero__content">
					<div class="cptt-pay-hero__icon">💳</div>
					<div>
						<h1 class="cptt-pay-hero__title">مرکز پرداخت‌ها</h1>
						<p class="cptt-pay-hero__desc">روش‌های پرداخت پروژه‌ها را مدیریت کنید — کارت‌به‌کارت، درگاه‌های آنلاین، و درگاه بانک مستقیم. همه روش‌ها در یک صفحه‌ی پرداخت زیبا و یکپارچه به مشتری نمایش داده می‌شوند.</p>
						<div class="cptt-pay-hero__stats">
							<span>🔌 درگاه‌های تعریف‌شده: <b><?php echo count($s['gateways']); ?></b></span>
							<span>✅ فعال: <b><?php echo count(self::enabled_gateways()); ?></b></span>
							<span>🧾 رسیدهای ثبت‌شده: <b><?php echo (int)wp_count_posts(self::CPT_RECEIPT)->publish; ?></b></span>
						</div>
					</div>
				</div>
			</div>

			<div class="cptt-pay-tabs">
				<button type="button" class="cptt-pay-tabbtn active" data-tab="gateways">⚙️ روش‌های پرداخت</button>
				<button type="button" class="cptt-pay-tabbtn" data-tab="receipts">🧾 رسیدها</button>
				<button type="button" class="cptt-pay-tabbtn" data-tab="general">🛠 تنظیمات کلی</button>
			</div>

			<form method="post" id="cptt-pay-form">
				<?php wp_nonce_field(self::NONCE_SAVE, 'cptt_payment_nonce'); ?>

				<!-- TAB: GATEWAYS -->
				<div class="cptt-pay-tab" data-tab="gateways">
					<div class="cptt-pay-add-wrap">
						<label class="cptt-pay-add-label">➕ افزودن روش پرداخت جدید</label>
						<div class="cptt-pay-add-row">
							<select id="cptt-pay-newtype" class="cptt-pay-input">
								<?php foreach ($types as $tkey => $t): ?>
									<option value="<?php echo esc_attr($tkey); ?>"><?php echo esc_html($t['icon'] . ' ' . $t['label']); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="button" class="cptt-pay-btn cptt-pay-btn--primary" id="cptt-pay-add-gateway">+ افزودن</button>
						</div>
					</div>

					<div class="cptt-pay-gateways" id="cptt-pay-gateways-list">
						<?php foreach ($s['gateways'] as $idx => $g): ?>
							<?php $this->render_gateway_card($g, $idx); ?>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- TAB: RECEIPTS -->
				<div class="cptt-pay-tab" data-tab="receipts" style="display:none;">
					<?php $this->receipts_panel(); ?>
				</div>

				<!-- TAB: GENERAL -->
				<div class="cptt-pay-tab" data-tab="general" style="display:none;">
					<div class="cptt-pay-card">
						<h3 class="cptt-pay-card__title">🛠 تنظیمات عمومی پرداخت</h3>
						<div class="cptt-pay-grid">
							<label class="cptt-pay-field">
								<span>واحد پول</span>
								<select name="currency_label" class="cptt-pay-input">
									<option value="تومان" <?php selected($s['currency_label'], 'تومان'); ?>>تومان</option>
									<option value="ریال" <?php selected($s['currency_label'], 'ریال'); ?>>ریال</option>
								</select>
							</label>
							<label class="cptt-pay-field">
								<span>ضریب تبدیل به ریال برای درگاه‌ها</span>
								<select name="currency_factor" class="cptt-pay-input">
									<option value="1"  <?php selected((int)$s['currency_factor'], 1); ?>>۱ (مبلغ همان واحد به درگاه ارسال می‌شود)</option>
									<option value="10" <?php selected((int)$s['currency_factor'], 10); ?>>۱۰ (تومان → ریال)</option>
								</select>
								<small style="color:#64748b;">اگر مبالغ سیستم تومان است و درگاه ریال می‌خواهد، روی ۱۰ بگذارید.</small>
							</label>
							<label class="cptt-pay-field cptt-pay-field--wide">
								<span>پیام موفقیت پرداخت</span>
								<input type="text" name="success_message" value="<?php echo esc_attr($s['success_message']); ?>" class="cptt-pay-input">
							</label>
							<label class="cptt-pay-field cptt-pay-field--wide">
								<span>پیام رسید در انتظار تایید</span>
								<input type="text" name="pending_message" value="<?php echo esc_attr($s['pending_message']); ?>" class="cptt-pay-input">
							</label>
						</div>
					</div>
				</div>

				<div class="cptt-pay-actions">
					<button type="submit" class="cptt-pay-btn cptt-pay-btn--save">💾 ذخیره همه تغییرات</button>
				</div>
			</form>
		</div>

		<script><?php $this->print_admin_js(); ?></script>
		<?php
	}

	private function render_gateway_card($g, $idx) {
		$types  = self::gateway_types();
		$type   = isset($g['type']) ? $g['type'] : 'card';
		$tdef   = isset($types[$type]) ? $types[$type] : $types['card'];
		$gid    = isset($g['id']) ? $g['id'] : ('g_' . wp_generate_password(6, false));
		$name   = isset($g['name']) ? $g['name'] : $tdef['label'];
		$enabled = !empty($g['enabled']);
		?>
		<div class="cptt-pay-gw" data-gw-id="<?php echo esc_attr($gid); ?>" style="--gw-color: <?php echo esc_attr($tdef['color']); ?>;">
			<div class="cptt-pay-gw__head">
				<div class="cptt-pay-gw__title">
					<span class="cptt-pay-gw__icon"><?php echo esc_html($tdef['icon']); ?></span>
					<div>
						<input type="text" name="gateways[<?php echo $idx; ?>][name]" value="<?php echo esc_attr($name); ?>" class="cptt-pay-gw__nameInput" placeholder="نام نمایشی روش">
						<small class="cptt-pay-gw__type"><?php echo esc_html($tdef['label']); ?></small>
					</div>
				</div>
				<div class="cptt-pay-gw__actions">
					<label class="cptt-pay-switch" title="فعال/غیرفعال">
						<input type="checkbox" name="gateways[<?php echo $idx; ?>][enabled]" value="1" <?php checked($enabled); ?>>
						<span class="cptt-pay-switch__slider"></span>
					</label>
					<button type="button" class="cptt-pay-gw__remove" title="حذف این روش">🗑</button>
				</div>
				<input type="hidden" name="gateways[<?php echo $idx; ?>][id]"   value="<?php echo esc_attr($gid); ?>">
				<input type="hidden" name="gateways[<?php echo $idx; ?>][type]" value="<?php echo esc_attr($type); ?>">
			</div>
			<div class="cptt-pay-gw__desc"><?php echo esc_html($tdef['desc']); ?></div>
			<div class="cptt-pay-gw__body">
				<?php
				if ($type === 'card') {
					$this->render_card_fields($g, $idx);
				} else {
					$this->render_online_fields($g, $idx, $type, $tdef);
				}
				?>
				<label class="cptt-pay-field cptt-pay-field--wide">
					<span>یادداشت/راهنما برای مشتری (اختیاری)</span>
					<textarea name="gateways[<?php echo $idx; ?>][note]" rows="2" class="cptt-pay-input" placeholder="مثلاً: پس از واریز، شماره پیگیری را در رسید بنویسید."><?php echo esc_textarea($g['note'] ?? ''); ?></textarea>
				</label>
			</div>
		</div>
		<?php
	}

	private function render_card_fields($g, $idx) {
		$cards = isset($g['cards']) && is_array($g['cards']) ? $g['cards'] : [];
		if (empty($cards)) $cards[] = ['number'=>'', 'owner'=>'', 'bank'=>''];
		?>
		<div class="cptt-pay-cards" data-idx="<?php echo $idx; ?>">
			<div class="cptt-pay-cards__head">💳 کارت‌های بانکی این روش (می‌توانید چند کارت اضافه کنید — به مشتری همه نشان داده می‌شوند)</div>
			<div class="cptt-pay-cards__list">
				<?php foreach ($cards as $ci => $c): ?>
					<div class="cptt-pay-card-row">
						<input type="text" name="gateways[<?php echo $idx; ?>][cards][<?php echo $ci; ?>][number]" value="<?php echo esc_attr($c['number'] ?? ''); ?>" placeholder="شماره کارت ۱۶ رقمی" class="cptt-pay-input cptt-pay-card-num" inputmode="numeric">
						<input type="text" name="gateways[<?php echo $idx; ?>][cards][<?php echo $ci; ?>][owner]" value="<?php echo esc_attr($c['owner'] ?? ''); ?>" placeholder="نام صاحب کارت" class="cptt-pay-input">
						<input type="text" name="gateways[<?php echo $idx; ?>][cards][<?php echo $ci; ?>][bank]" value="<?php echo esc_attr($c['bank'] ?? ''); ?>" placeholder="بانک (مثلاً ملی)" class="cptt-pay-input">
						<button type="button" class="cptt-pay-card-del" title="حذف کارت">×</button>
					</div>
				<?php endforeach; ?>
			</div>
			<button type="button" class="cptt-pay-btn cptt-pay-btn--ghost cptt-pay-card-add" data-idx="<?php echo $idx; ?>">+ افزودن کارت دیگر</button>
		</div>
		<?php
	}

	private function render_online_fields($g, $idx, $type, $tdef) {
		$fields = $tdef['fields'] ?? [];
		?>
		<div class="cptt-pay-grid">
			<?php foreach ($fields as $f):
				$label = $this->field_label($f);
				$val   = isset($g[$f]) ? $g[$f] : '';
				if ($f === 'sandbox'): ?>
					<label class="cptt-pay-field">
						<span><?php echo esc_html($label); ?></span>
						<label class="cptt-pay-switch">
							<input type="checkbox" name="gateways[<?php echo $idx; ?>][<?php echo esc_attr($f); ?>]" value="1" <?php checked(!empty($val)); ?>>
							<span class="cptt-pay-switch__slider"></span>
						</label>
					</label>
				<?php else: ?>
					<label class="cptt-pay-field <?php echo in_array($f, ['bank_url']) ? 'cptt-pay-field--wide' : ''; ?>">
						<span><?php echo esc_html($label); ?></span>
						<input type="text" name="gateways[<?php echo $idx; ?>][<?php echo esc_attr($f); ?>]" value="<?php echo esc_attr($val); ?>" class="cptt-pay-input" placeholder="<?php echo esc_attr($this->field_placeholder($f, $type)); ?>">
					</label>
				<?php endif;
			endforeach; ?>
		</div>
		<?php
	}

	private function field_label($f) {
		$map = [
			'merchant_id' => 'کد مرچنت / Merchant ID',
			'api_key'     => 'API Key',
			'token'       => 'توکن دسترسی',
			'sandbox'     => 'حالت تست (Sandbox)',
			'bank_url'    => 'لینک درگاه بانک',
			'bank_name'   => 'نام بانک',
		];
		return $map[$f] ?? $f;
	}
	private function field_placeholder($f, $type) {
		if ($f === 'merchant_id' && $type === 'zarinpal') return 'مثلاً: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx';
		if ($f === 'merchant_id' && $type === 'zibal')    return 'مثلاً: zibal-merchant-key';
		if ($f === 'bank_url')                            return 'https://my-bank.example/pay?to=...';
		return '';
	}

	private function save_from_post() {
		$gateways_in = isset($_POST['gateways']) && is_array($_POST['gateways']) ? wp_unslash($_POST['gateways']) : [];
		$gateways    = [];
		foreach ($gateways_in as $g) {
			if (!is_array($g)) continue;
			$type = isset($g['type']) ? sanitize_key($g['type']) : 'card';
			$entry = [
				'id'      => isset($g['id']) ? sanitize_key($g['id']) : ('g_' . wp_generate_password(6, false)),
				'type'    => $type,
				'name'    => sanitize_text_field($g['name'] ?? ''),
				'enabled' => !empty($g['enabled']) ? 1 : 0,
				'note'    => sanitize_textarea_field($g['note'] ?? ''),
			];
			if ($type === 'card') {
				$cards = [];
				if (isset($g['cards']) && is_array($g['cards'])) {
					foreach ($g['cards'] as $c) {
						if (!is_array($c)) continue;
						$num = preg_replace('/\D+/', '', (string)($c['number'] ?? ''));
						if ($num === '' && empty($c['owner']) && empty($c['bank'])) continue;
						$cards[] = [
							'number' => $num,
							'owner'  => sanitize_text_field($c['owner'] ?? ''),
							'bank'   => sanitize_text_field($c['bank'] ?? ''),
						];
					}
				}
				$entry['cards'] = $cards;
			} else {
				$tdef = self::gateway_types()[$type] ?? null;
				if ($tdef) {
					foreach ($tdef['fields'] as $f) {
						if ($f === 'sandbox') {
							$entry[$f] = !empty($g[$f]) ? 1 : 0;
						} else {
							$entry[$f] = sanitize_text_field($g[$f] ?? '');
						}
					}
				}
			}
			$gateways[] = $entry;
		}

		$out = [
			'gateways'        => $gateways,
			'default_gateway' => sanitize_key($_POST['default_gateway'] ?? ''),
			'currency_label'  => sanitize_text_field($_POST['currency_label'] ?? 'تومان'),
			'currency_factor' => max(1, (int)($_POST['currency_factor'] ?? 1)),
			'success_message' => sanitize_text_field($_POST['success_message'] ?? '✅ پرداخت با موفقیت ثبت شد.'),
			'pending_message' => sanitize_text_field($_POST['pending_message'] ?? '⏳ رسید شما در انتظار تایید است.'),
		];
		update_option(self::OPT, $out, false);
	}

	/* =====================================================================
	 * RECEIPTS ADMIN
	 * ===================================================================== */

	private function receipts_panel() {
		$q = new WP_Query([
			'post_type'      => self::CPT_RECEIPT,
			'post_status'    => 'any',
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
		]);
		?>
		<div class="cptt-pay-card">
			<h3 class="cptt-pay-card__title">🧾 رسیدهای پرداخت</h3>
			<?php if (empty($q->posts)): ?>
				<div class="cptt-pay-empty">📭 هنوز هیچ رسیدی ثبت نشده است.</div>
			<?php else: ?>
				<div class="cptt-pay-rcpts">
					<?php foreach ($q->posts as $p):
						$pid     = (int) get_post_meta($p->ID, 'project_id', true);
						$uid     = (int) get_post_meta($p->ID, 'user_id', true);
						$amount  = (float) get_post_meta($p->ID, 'amount', true);
						$att     = (int) get_post_meta($p->ID, 'attachment_id', true);
						$st      = get_post_meta($p->ID, 'status', true) ?: 'pending';
						$gw_id   = (string) get_post_meta($p->ID, 'gateway_id', true);
						$gw      = $gw_id ? self::find_gateway($gw_id) : null;
						$u       = $uid ? get_user_by('id', $uid) : null;
						$img_url = $att ? wp_get_attachment_url($att) : '';
						$is_img  = $img_url && preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $img_url);
						$badge   = ['pending'=>['⏳ در انتظار','#ca8a04','#fef9c3'],'approved'=>['✅ تایید شده','#16a34a','#dcfce7'],'rejected'=>['✖ رد شده','#dc2626','#fee2e2']];
						$b = $badge[$st] ?? $badge['pending'];
					?>
					<div class="cptt-pay-rcpt" data-id="<?php echo $p->ID; ?>">
						<div class="cptt-pay-rcpt__thumb">
							<?php if ($is_img): ?>
								<a href="<?php echo esc_url($img_url); ?>" target="_blank"><img src="<?php echo esc_url($img_url); ?>" alt="رسید"></a>
							<?php elseif ($img_url): ?>
								<a href="<?php echo esc_url($img_url); ?>" target="_blank" class="cptt-pay-rcpt__file">📎 مشاهده فایل</a>
							<?php else: ?>
								<div class="cptt-pay-rcpt__noimg">—</div>
							<?php endif; ?>
						</div>
						<div class="cptt-pay-rcpt__body">
							<div class="cptt-pay-rcpt__row">
								<strong><?php echo esc_html(get_the_title($pid) ?: ('پروژه #' . $pid)); ?></strong>
								<span class="cptt-pay-rcpt__badge" style="color:<?php echo esc_attr($b[1]); ?>;background:<?php echo esc_attr($b[2]); ?>;"><?php echo esc_html($b[0]); ?></span>
							</div>
							<div class="cptt-pay-rcpt__meta">
								<span>👤 <?php echo esc_html($u ? $u->display_name : '—'); ?></span>
								<span>💰 <?php echo esc_html(number_format($amount)); ?></span>
								<?php if ($gw): ?><span>🔌 <?php echo esc_html($gw['name'] ?: $gw['type']); ?></span><?php endif; ?>
								<span>🕐 <?php echo esc_html(get_the_date('Y/m/d H:i', $p)); ?></span>
							</div>
							<?php if ($st === 'pending'): ?>
							<div class="cptt-pay-rcpt__actions">
								<button type="button" class="cptt-pay-btn cptt-pay-btn--ok cptt-approve-receipt" data-id="<?php echo $p->ID; ?>">✅ تایید</button>
								<button type="button" class="cptt-pay-btn cptt-pay-btn--no cptt-reject-receipt"  data-id="<?php echo $p->ID; ?>">✖ رد</button>
							</div>
							<?php endif; ?>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<script>
		(function(){
			var ajaxNonce = '<?php echo esc_js(wp_create_nonce(self::NONCE_AJAX)); ?>';
			document.addEventListener('click', function(e){
				var btn = e.target.closest('.cptt-approve-receipt,.cptt-reject-receipt');
				if (!btn) return;
				e.preventDefault();
				var id = btn.getAttribute('data-id');
				var action = btn.classList.contains('cptt-approve-receipt') ? 'cptt_approve_receipt' : 'cptt_reject_receipt';
				btn.disabled = true; btn.textContent = '...';
				var fd = new FormData();
				fd.append('action', action); fd.append('id', id); fd.append('nonce', ajaxNonce);
				fetch(ajaxurl, {method:'POST', credentials:'same-origin', body: fd})
					.then(function(r){ return r.json(); })
					.then(function(j){ if (j && j.success) location.reload(); else alert((j && j.data) ? j.data : 'خطا'); });
			});
		})();
		</script>
		<?php
	}

	public function ajax_approve_receipt() {
		check_ajax_referer(self::NONCE_AJAX, 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error('no_access', 403);
		$id     = absint($_POST['id'] ?? 0);
		if (!$id) wp_send_json_error('invalid_id', 400);
		$pid    = (int) get_post_meta($id, 'project_id', true);
		$amount = (float) get_post_meta($id, 'amount', true);
		update_post_meta($id, 'status', 'approved');
		update_post_meta($id, 'approved_at', current_time('mysql'));
		update_post_meta($id, 'approved_by', get_current_user_id());
		if (class_exists('CPTT_Core')) {
			if (method_exists('CPTT_Core', 'ledger_add')) {
				CPTT_Core::ledger_add(['project_id'=>$pid,'type'=>'customer_card_payment','amount'=>$amount,'note'=>'تایید رسید پرداخت']);
			}
			if (method_exists('CPTT_Core', 'activity_log')) {
				CPTT_Core::activity_log('payment_receipt', $id, 'receipt_approved', 'تایید رسید و ثبت پرداخت پروژه #' . $pid);
			}
		}
		$this->apply_payment_to_project($pid, $amount);

		// نوتیف بله به مشتری
		$this->notify_customer_payment_status($id, 'approved');
		wp_send_json_success();
	}

	public function ajax_reject_receipt() {
		check_ajax_referer(self::NONCE_AJAX, 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error('no_access', 403);
		$id = absint($_POST['id'] ?? 0);
		if (!$id) wp_send_json_error('invalid_id', 400);
		update_post_meta($id, 'status', 'rejected');
		update_post_meta($id, 'rejected_at', current_time('mysql'));
		$this->notify_customer_payment_status($id, 'rejected');
		wp_send_json_success();
	}

	private function notify_customer_payment_status($receipt_id, $status) {
		if (!class_exists('CPTT_Bale')) return;
		$uid = (int) get_post_meta($receipt_id, 'user_id', true);
		$pid = (int) get_post_meta($receipt_id, 'project_id', true);
		$amt = (float) get_post_meta($receipt_id, 'amount', true);
		if (!$uid) return;
		$bale_id = (string) get_user_meta($uid, 'cptt_bale_id', true);
		if ($bale_id === '') return;
		$msg = $status === 'approved'
			? "✅ پرداخت شما برای پروژه «" . get_the_title($pid) . "» به مبلغ " . number_format($amt) . " تایید شد. سپاسگزاریم! 🌟"
			: "❌ متاسفانه رسید پرداخت شما برای پروژه «" . get_the_title($pid) . "» تایید نشد. لطفاً با پشتیبانی تماس بگیرید.";
		if (method_exists('CPTT_Bale', 'send_message')) {
			try { CPTT_Bale::send_message($bale_id, $msg); } catch (\Throwable $e) {}
		}
	}

	/* =====================================================================
	 * PAYMENT PAGE (FRONT)
	 * ===================================================================== */

	public function render_pay_page() {
		$pid = absint($_GET['project_id'] ?? 0);
		if (!$pid || get_post_type($pid) !== 'cptt_project') wp_die('پروژه نامعتبر است.');
		// nonce فقط برای صفحه‌ی نمایشی اختیاری است (مشتری از بله یا لینک عمومی می‌آید)
		// عملیات حساس (پرداخت/رسید) در صفحات دیگر nonce جدا دارند.

		$amount  = (float) ($_GET['amount'] ?? 0);
		if (!$amount) $amount = self::project_remaining($pid);
		$amount  = max(0, $amount);

		$s         = self::get_settings();
		$gateways  = self::enabled_gateways();
		$sel_gw    = isset($_GET['gw']) ? sanitize_key($_GET['gw']) : '';
		$status    = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
		$msg       = isset($_GET['msg']) ? wp_unslash($_GET['msg']) : '';

		header('Content-Type: text/html; charset=utf-8');
		?>
		<!doctype html>
		<html dir="rtl" lang="fa">
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width,initial-scale=1">
			<title>پرداخت پروژه — <?php echo esc_html(get_the_title($pid)); ?></title>
			<style><?php $this->print_pay_css(); ?></style>
		</head>
		<body>
		<div class="pay-page">
			<div class="pay-page__shell">
				<div class="pay-page__brand">
					<div class="pay-page__logo">💎</div>
					<div>
						<div class="pay-page__title">پرداخت امن پروژه</div>
						<div class="pay-page__sub"><?php echo esc_html(get_bloginfo('name')); ?></div>
					</div>
				</div>

				<div class="pay-page__project">
					<div class="pay-page__project-name"><?php echo esc_html(get_the_title($pid)); ?></div>
					<div class="pay-page__project-meta">شناسه پروژه: #<?php echo esc_html(class_exists('CPTT_Core') ? CPTT_Core::get_project_code($pid) : $pid); ?></div>
				</div>

				<div class="pay-page__amount">
					<div class="pay-page__amount-label">مبلغ قابل پرداخت</div>
					<div class="pay-page__amount-value">
						<?php echo esc_html(number_format($amount)); ?>
						<span><?php echo esc_html($s['currency_label']); ?></span>
					</div>
				</div>

				<?php if ($status === 'pending'): ?>
					<div class="pay-page__notice pay-page__notice--info">⏳ <?php echo esc_html($s['pending_message']); ?></div>
				<?php elseif ($status === 'success'): ?>
					<div class="pay-page__notice pay-page__notice--ok">✅ <?php echo esc_html($s['success_message']); ?></div>
				<?php elseif ($status === 'failed'): ?>
					<div class="pay-page__notice pay-page__notice--err">❌ <?php echo esc_html($msg ?: 'پرداخت ناموفق بود. لطفاً دوباره تلاش کنید.'); ?></div>
				<?php endif; ?>

				<?php if (empty($gateways)): ?>
					<div class="pay-page__notice pay-page__notice--err">⚠️ هیچ روش پرداختی فعال نیست. لطفاً با پشتیبانی تماس بگیرید.</div>
				<?php elseif (!$sel_gw): ?>
					<div class="pay-page__section-title">یک روش پرداخت را انتخاب کنید</div>
					<div class="pay-page__gws">
						<?php
						$types = self::gateway_types();
						foreach ($gateways as $g):
							$t = $types[$g['type']] ?? $types['card'];
							$url = add_query_arg([
								'action'     => 'cptt_pay_project',
								'project_id' => $pid,
								'amount'     => $amount,
								'gw'         => $g['id'],
								'_wpnonce'   => wp_create_nonce(self::NONCE_PAY . '_' . $pid),
							], admin_url('admin-post.php'));
						?>
							<a href="<?php echo esc_url($url); ?>" class="pay-gw" style="--c:<?php echo esc_attr($t['color']); ?>;">
								<div class="pay-gw__icon"><?php echo esc_html($t['icon']); ?></div>
								<div class="pay-gw__body">
									<div class="pay-gw__name"><?php echo esc_html($g['name'] ?: $t['label']); ?></div>
									<div class="pay-gw__desc"><?php echo esc_html($t['label']); ?></div>
								</div>
								<div class="pay-gw__arrow">←</div>
							</a>
						<?php endforeach; ?>
					</div>
				<?php else:
					$gw = self::find_gateway($sel_gw);
					if (!$gw || empty($gw['enabled'])) {
						echo '<div class="pay-page__notice pay-page__notice--err">⚠️ روش پرداخت انتخاب‌شده نامعتبر است.</div>';
					} else {
						$this->render_selected_gateway($gw, $pid, $amount);
					}
				endif; ?>

				<div class="pay-page__back">
					<?php if ($sel_gw): ?>
						<a href="<?php echo esc_url(self::payment_url($pid, $amount)); ?>">← بازگشت به انتخاب روش پرداخت</a>
					<?php endif; ?>
				</div>

				<div class="pay-page__footer">🔒 پرداخت امن — <?php echo esc_html(get_bloginfo('name')); ?></div>
			</div>
		</div>
		</body>
		</html>
		<?php
		exit;
	}

	private function render_selected_gateway($gw, $pid, $amount) {
		$type  = $gw['type'];
		$types = self::gateway_types();
		$tdef  = $types[$type] ?? $types['card'];

		if ($type === 'card') {
			$cards = isset($gw['cards']) && is_array($gw['cards']) ? $gw['cards'] : [];
			?>
			<div class="pay-page__section-title" style="font-size:15px; color:#1e1b4b; font-weight:900; margin-bottom:12px; display:flex; align-items:center; gap:6px;">💳 کارت‌های بانکی جهت واریز</div>
			<?php if (!empty($gw['note'])): ?>
				<div class="pay-page__notice pay-page__notice--info" style="border-radius:14px; box-shadow:0 4px 12px rgba(99, 102, 241, 0.05); border:1px solid rgba(99,102,241,0.15); margin-bottom:18px;"><?php echo esc_html($gw['note']); ?></div>
			<?php endif; ?>

			<?php if (empty($cards)): ?>
				<div class="pay-page__notice pay-page__notice--err">شماره کارتی برای این روش تعریف نشده است.</div>
			<?php else: ?>
				<div class="pay-cards" style="display:grid; gap:16px; margin-bottom:20px;">
					<?php foreach ($cards as $c):
						$num     = (string)($c['number'] ?? '');
						$num_fmt = trim(chunk_split($num, 4, ' '), ' ');
					?>
						<div class="card-mockup">
							<div class="card-mockup__top">
								<span class="card-mockup__bank">🏛️ <?php echo esc_html($c['bank'] ?? 'بانک مقصد'); ?></span>
								<span class="card-mockup__logo">💳</span>
							</div>
							<div class="card-mockup__chip"></div>
							<div class="card-mockup__num"><?php echo esc_html($num_fmt ?: '—'); ?></div>
							<div class="card-mockup__bottom">
								<div class="card-mockup__owner">
									<small style="display:block; font-size:10px; opacity:0.75; margin-bottom:2px; font-weight:normal;">صاحب حساب</small>
									👤 <?php echo esc_html($c['owner'] ?? '—'); ?>
								</div>
								<button type="button" class="pay-card__copy-btn" data-num="<?php echo esc_attr($num); ?>" style="background:rgba(255,255,255,0.18); border:1px solid rgba(255,255,255,0.25); color:#fff; border-radius:10px; padding:6px 12px; cursor:pointer; font-family:inherit; font-size:12px; font-weight:bold; display:inline-flex; align-items:center; gap:4px; transition:all 0.2s;">📋 کپی شماره کارت</button>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
				<script>
				document.addEventListener('click', function(e){
					var b = e.target.closest('.pay-card__copy-btn'); if (!b) return;
					var n = b.getAttribute('data-num') || '';
					if (!n) return;
					try { 
						navigator.clipboard.writeText(n); 
						var prevText = b.innerHTML;
						b.innerHTML = '✨ کپی شد!'; 
						b.style.background = '#10b981';
						b.style.borderColor = '#059669';
						setTimeout(function(){ 
							b.innerHTML = prevText; 
							b.style.background = 'rgba(255,255,255,0.18)';
							b.style.borderColor = 'rgba(255,255,255,0.25)';
						}, 1500); 
					} catch(e) { b.textContent='خطا در کپی'; }
				});
				</script>
			<?php endif; ?>

			<div class="pay-page__section-title" style="margin-top:28px; font-size:15px; color:#1e1b4b; font-weight:900; margin-bottom:12px; display:flex; align-items:center; gap:6px;">📤 آپلود رسید و جزئیات پرداخت</div>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="pay-form" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:24px; padding:20px; box-shadow:0 10px 30px rgba(15,23,42,0.04); display:grid; gap:16px;">
				<input type="hidden" name="action"     value="cptt_submit_card_receipt">
				<input type="hidden" name="project_id" value="<?php echo esc_attr($pid); ?>">
				<input type="hidden" name="amount"     value="<?php echo esc_attr($amount); ?>">
				<input type="hidden" name="gateway_id" value="<?php echo esc_attr($gw['id']); ?>">
				<?php wp_nonce_field(self::NONCE_RCPT . '_' . $pid, 'nonce'); ?>

				<label class="pay-field">
					<span style="font-size:13px; font-weight:bold; color:#475569; margin-bottom:4px;">مبلغ پرداختی (ریال/تومان)</span>
					<input type="text" name="paid_amount" value="<?php echo esc_attr(number_format($amount)); ?>" inputmode="numeric" style="padding:12px 14px; border:1px solid #cbd5e1; border-radius:12px; font-family:inherit; font-size:14px; width:100%; font-weight:bold; color:#1e293b; background:#f8fafc;">
				</label>
				
				<div class="pay-field">
					<span style="font-size:13px; font-weight:bold; color:#475569; margin-bottom:6px;">تصویر رسید پرداخت <span style="color:#ef4444">*</span></span>
					<div class="file-dropzone" id="cptt-dropzone" style="border:2px dashed #cbd5e1; border-radius:16px; background:#f8fafc; padding:32px 16px; text-align:center; cursor:pointer; transition:all 0.3s ease; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:10px;">
						<div class="file-dropzone__icon" style="font-size:42px;">📁</div>
						<div class="file-dropzone__text" style="font-size:13px; font-weight:bold; color:#334155;">تصویر رسید را اینجا بکشید یا کلیک کنید</div>
						<div class="file-dropzone__subtext" style="font-size:11px; color:#94a3b8;">فرمت‌های مجاز: JPG, PNG, PDF (حداکثر ۵ مگابایت)</div>
						<input type="file" name="receipt" id="receipt-file" accept="image/*,.pdf" required style="display:none;">
						
						<div class="file-dropzone__preview" id="receipt-preview" style="display:none; margin-top:12px; flex-direction:column; align-items:center; gap:8px;">
							<img src="" id="receipt-img" style="max-height:100px; border-radius:10px; box-shadow:0 8px 20px rgba(0,0,0,0.1); border:2px solid #fff; display:block;">
							<span id="receipt-filename" style="font-size:11px; font-weight:bold; color:#475569;"></span>
						</div>
					</div>
				</div>

				<script>
				(function(){
					var dz = document.getElementById('cptt-dropzone');
					var fileInp = document.getElementById('receipt-file');
					var preview = document.getElementById('receipt-preview');
					var img = document.getElementById('receipt-img');
					var filename = document.getElementById('receipt-filename');

					if (dz && fileInp) {
						dz.addEventListener('click', function(){ fileInp.click(); });
						dz.addEventListener('dragover', function(e){ e.preventDefault(); dz.style.borderColor='#6366f1'; dz.style.background='#f0f5ff'; });
						dz.addEventListener('dragleave', function(){ dz.style.borderColor='#cbd5e1'; dz.style.background='#f8fafc'; });
						dz.addEventListener('drop', function(e){
							e.preventDefault();
							dz.style.borderColor='#cbd5e1'; dz.style.background='#f8fafc';
							if (e.dataTransfer.files.length) {
								fileInp.files = e.dataTransfer.files;
								handleFileChange();
							}
						});
						fileInp.addEventListener('change', handleFileChange);
					}

					function handleFileChange() {
						var file = fileInp.files[0];
						if (file) {
							filename.textContent = file.name;
							preview.style.display = 'flex';
							if (file.type.match('image.*')) {
								var reader = new FileReader();
								reader.onload = function(e){ img.src = e.target.result; img.style.display='block'; };
								reader.readAsDataURL(file);
							} else {
								img.style.display = 'none';
							}
						} else {
							preview.style.display = 'none';
						}
					}
				})();
				</script>

				<label class="pay-field">
					<span style="font-size:13px; font-weight:bold; color:#475569; margin-bottom:4px;">توضیحات (اختیاری)</span>
					<textarea name="note" rows="3" placeholder="شماره پیگیری، ساعت واریز، چهار رقم آخر کارت و..." style="padding:12px 14px; border:1px solid #cbd5e1; border-radius:12px; font-family:inherit; font-size:13.5px; width:100%; min-height:80px; box-sizing:border-box;"></textarea>
				</label>

				<button type="submit" class="pay-submit" style="background:linear-gradient(135deg, #10b981, #059669); color:#fff; border:none; border-radius:14px; padding:14px; font-weight:900; font-size:15px; cursor:pointer; font-family:inherit; width:100%; transition:all 0.2s; box-shadow:0 8px 24px rgba(16, 185, 129, 0.2); margin-top:8px;">📨 ثبت رسید و ارسال جهت تایید</button>
			</form>
			<?php
		} else {
			// درگاه آنلاین
			$start_url = add_query_arg([
				'action'     => 'cptt_start_online_pay',
				'project_id' => $pid,
				'amount'     => $amount,
				'gw'         => $gw['id'],
				'_wpnonce'   => wp_create_nonce(self::NONCE_PAY . '_' . $pid),
			], admin_url('admin-post.php'));
			?>
			<div class="pay-page__section-title" style="font-size:15px; color:#1e1b4b; font-weight:900; margin-bottom:12px; display:flex; align-items:center; gap:6px;"><?php echo esc_html($tdef['icon'] . ' ' . ($gw['name'] ?: $tdef['label'])); ?></div>
			<?php if (!empty($gw['note'])): ?>
				<div class="pay-page__notice pay-page__notice--info" style="border-radius:14px; box-shadow:0 4px 12px rgba(99, 102, 241, 0.05); border:1px solid rgba(99,102,241,0.15); margin-bottom:18px;"><?php echo esc_html($gw['note']); ?></div>
			<?php endif; ?>

			<div class="pay-online" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:24px; padding:24px; box-shadow:0 10px 30px rgba(15,23,42,0.04); text-align:center;">
				<div class="pay-online__amount" style="font-size:14px; color:#475569; margin-bottom:16px;">مبلغ قابل پرداخت: <b style="font-size:18px; color:#1e1b4b; font-weight:900;"><?php echo esc_html(number_format($amount)); ?></b> <?php echo esc_html($s['currency_label']); ?></div>
				<a href="<?php echo esc_url($start_url); ?>" class="pay-submit pay-submit--online" style="background:linear-gradient(135deg, #6366f1, #4f46e5); color:#fff; border:none; border-radius:14px; padding:14px; font-weight:900; font-size:15px; cursor:pointer; font-family:inherit; text-decoration:none; display:block; text-align:center; transition:all 0.2s; box-shadow:0 8px 24px rgba(99, 102, 241, 0.2);">🚀 پرداخت آنلاین امن</a>
				<small class="pay-online__hint" style="display:block; margin-top:12px; color:#94a3b8; font-size:11.5px;">شما به درگاه رسمی و امن منتقل می‌شوید و پس از اتمام پرداخت مجدداً به سایت باز خواهید گشت.</small>
			</div>
			<?php
		}
	}

	/* =====================================================================
	 * SUBMIT CARD RECEIPT
	 * ===================================================================== */

	public function submit_card_receipt() {
		$pid = absint($_POST['project_id'] ?? 0);
		if (!$pid) wp_die('پروژه نامعتبر است.');
		check_admin_referer(self::NONCE_RCPT . '_' . $pid, 'nonce');

		$amount = (float) preg_replace('/[^\d.]/', '', (string)($_POST['paid_amount'] ?? $_POST['amount'] ?? 0));
		$gw_id  = sanitize_key($_POST['gateway_id'] ?? '');
		$note   = sanitize_textarea_field($_POST['note'] ?? '');

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$att = 0;
		if (!empty($_FILES['receipt']['name'])) {
			$res = media_handle_upload('receipt', 0);
			if (!is_wp_error($res)) $att = (int)$res;
		}

		$rid = wp_insert_post([
			'post_type'   => self::CPT_RECEIPT,
			'post_status' => 'publish',
			'post_title'  => 'رسید پرداخت پروژه #' . $pid . ' — ' . current_time('Y/m/d H:i'),
		]);
		if (is_wp_error($rid) || !$rid) wp_die('خطا در ثبت رسید.');

		update_post_meta($rid, 'project_id',    $pid);
		update_post_meta($rid, 'user_id',       get_current_user_id());
		update_post_meta($rid, 'amount',        $amount);
		update_post_meta($rid, 'attachment_id', $att);
		update_post_meta($rid, 'gateway_id',    $gw_id);
		update_post_meta($rid, 'note',          $note);
		update_post_meta($rid, 'status',        'pending');

		if (class_exists('CPTT_Core') && method_exists('CPTT_Core', 'activity_log')) {
			CPTT_Core::activity_log('payment_receipt', $rid, 'receipt_submitted', 'ثبت رسید پرداخت پروژه #' . $pid);
		}

		// نوتیف بله به ادمین
		$this->notify_admin_new_receipt($rid);

		// ریدایرکت به صفحه‌ی پرداخت با پیام موفقیت
		$back = add_query_arg([
			'action'     => 'cptt_pay_project',
			'project_id' => $pid,
			'amount'     => $amount,
			'status'     => 'pending',
			'_wpnonce'   => wp_create_nonce(self::NONCE_PAY . '_' . $pid),
		], admin_url('admin-post.php'));
		wp_safe_redirect($back);
		exit;
	}

	private function notify_admin_new_receipt($rid) {
		if (!class_exists('CPTT_Bale')) return;
		$pid = (int) get_post_meta($rid, 'project_id', true);
		$amt = (float) get_post_meta($rid, 'amount', true);
		$uid = (int) get_post_meta($rid, 'user_id', true);
		$u   = $uid ? get_user_by('id', $uid) : null;

		$settings = method_exists('CPTT_Bale', 'get_settings') ? CPTT_Bale::get_settings() : [];
		$admin_id = trim((string)($settings['admin_id'] ?? ''));
		if ($admin_id === '') return;

		$msg = "🧾 *رسید پرداخت جدید*\n\n"
		     . "📁 پروژه: " . get_the_title($pid) . "\n"
		     . "👤 مشتری: " . ($u ? $u->display_name : '—') . "\n"
		     . "💰 مبلغ: " . number_format($amt) . "\n"
		     . "🆔 رسید: #" . $rid . "\n\n"
		     . "از پنل مدیریت در «پرداخت‌ها → رسیدها» بررسی و تایید کنید.";
		if (method_exists('CPTT_Bale', 'send_message')) {
			try { CPTT_Bale::send_message($admin_id, $msg); } catch (\Throwable $e) {}
		}
	}

	/* =====================================================================
	 * ONLINE GATEWAYS (stub با ساختار آماده برای فعال‌سازی نهایی)
	 * ===================================================================== */

	public function start_online_pay() {
		$pid = absint($_GET['project_id'] ?? 0);
		if (!$pid) wp_die('پروژه نامعتبر است.');
		check_admin_referer(self::NONCE_PAY . '_' . $pid);

		$amount = (float)($_GET['amount'] ?? 0);
		$gw_id  = sanitize_key($_GET['gw'] ?? '');
		$gw     = self::find_gateway($gw_id);
		if (!$gw || empty($gw['enabled'])) wp_die('روش پرداخت نامعتبر است.');

		$type     = $gw['type'];
		$settings = self::get_settings();
		$factor   = max(1, (int)$settings['currency_factor']);
		$rial     = (int) round($amount * $factor);

		$callback = add_query_arg([
			'action'     => 'cptt_online_callback',
			'project_id' => $pid,
			'amount'     => $amount,
			'gw'         => $gw_id,
		], admin_url('admin-post.php'));

		switch ($type) {
			case 'zarinpal':
				$merchant = $gw['merchant_id'] ?? '';
				$sandbox  = !empty($gw['sandbox']);
				if (!$merchant) wp_die('کد مرچنت زرین‌پال تنظیم نشده است.');
				$url = $sandbox ? 'https://sandbox.zarinpal.com/pgw/v4/payment/request.json' : 'https://payment.zarinpal.com/pg/v4/payment/request.json';
				$resp = wp_remote_post($url, [
					'timeout' => 15,
					'headers' => ['Content-Type' => 'application/json'],
					'body'    => wp_json_encode([
						'merchant_id'  => $merchant,
						'amount'       => $rial,
						'callback_url' => $callback,
						'description'  => 'پرداخت پروژه #' . $pid,
					]),
				]);
				if (is_wp_error($resp)) wp_die('ارتباط با زرین‌پال برقرار نشد.');
				$body = json_decode(wp_remote_retrieve_body($resp), true);
				$auth = $body['data']['authority'] ?? '';
				if (!$auth) {
					$err = $body['errors']['message'] ?? 'پاسخ نامعتبر از زرین‌پال';
					$this->fail_redirect($pid, $amount, $err);
				}
				update_post_meta($pid, '_cptt_pay_authority_' . $gw_id, $auth);
				$gw_url = ($sandbox ? 'https://sandbox.zarinpal.com/pg/StartPay/' : 'https://payment.zarinpal.com/pg/StartPay/') . $auth;
				wp_redirect($gw_url); exit;

			case 'zibal':
				$merchant = $gw['merchant_id'] ?? '';
				if (!$merchant) wp_die('کد مرچنت زیبال تنظیم نشده است.');
				$resp = wp_remote_post('https://gateway.zibal.ir/v1/request', [
					'timeout' => 15,
					'headers' => ['Content-Type' => 'application/json'],
					'body'    => wp_json_encode([
						'merchant'    => $merchant,
						'amount'      => $rial,
						'callbackUrl' => $callback,
						'description' => 'پرداخت پروژه #' . $pid,
					]),
				]);
				if (is_wp_error($resp)) wp_die('ارتباط با زیبال برقرار نشد.');
				$body = json_decode(wp_remote_retrieve_body($resp), true);
				$track = $body['trackId'] ?? 0;
				if (!$track) $this->fail_redirect($pid, $amount, $body['message'] ?? 'پاسخ نامعتبر');
				update_post_meta($pid, '_cptt_pay_authority_' . $gw_id, (string)$track);
				wp_redirect('https://gateway.zibal.ir/start/' . $track); exit;

			case 'idpay':
				$api = $gw['api_key'] ?? '';
				$sandbox = !empty($gw['sandbox']);
				if (!$api) wp_die('API Key آیدی‌پی تنظیم نشده است.');
				$resp = wp_remote_post('https://api.idpay.ir/v1.1/payment', [
					'timeout' => 15,
					'headers' => [
						'Content-Type' => 'application/json',
						'X-API-KEY'    => $api,
						'X-SANDBOX'    => $sandbox ? '1' : '0',
					],
					'body' => wp_json_encode([
						'order_id' => 'cptt-' . $pid . '-' . time(),
						'amount'   => $rial,
						'callback' => $callback,
						'desc'     => 'پرداخت پروژه #' . $pid,
					]),
				]);
				if (is_wp_error($resp)) wp_die('ارتباط با آیدی‌پی برقرار نشد.');
				$body = json_decode(wp_remote_retrieve_body($resp), true);
				$link = $body['link'] ?? '';
				if (!$link) $this->fail_redirect($pid, $amount, $body['error_message'] ?? 'پاسخ نامعتبر');
				update_post_meta($pid, '_cptt_pay_authority_' . $gw_id, (string)($body['id'] ?? ''));
				wp_redirect($link); exit;

			case 'nextpay':
				$api = $gw['api_key'] ?? '';
				if (!$api) wp_die('API Key نکست‌پی تنظیم نشده است.');
				$resp = wp_remote_post('https://nextpay.org/nx/gateway/token', [
					'timeout' => 15,
					'body'    => [
						'api_key'      => $api,
						'amount'       => $rial,
						'order_id'     => 'cptt-' . $pid . '-' . time(),
						'callback_uri' => $callback,
						'currency'     => 'IRR',
					],
				]);
				if (is_wp_error($resp)) wp_die('ارتباط با نکست‌پی برقرار نشد.');
				$body  = json_decode(wp_remote_retrieve_body($resp), true);
				$token = $body['trans_id'] ?? '';
				if (!$token) $this->fail_redirect($pid, $amount, 'پاسخ نامعتبر');
				update_post_meta($pid, '_cptt_pay_authority_' . $gw_id, (string)$token);
				wp_redirect('https://nextpay.org/nx/gateway/payment/' . $token); exit;

			case 'payping':
				$token = $gw['token'] ?? '';
				if (!$token) wp_die('توکن PayPing تنظیم نشده است.');
				$resp = wp_remote_post('https://api.payping.ir/v2/pay', [
					'timeout' => 15,
					'headers' => [
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					],
					'body' => wp_json_encode([
						'amount'      => $rial,
						'returnUrl'   => $callback,
						'clientRefId' => 'cptt-' . $pid . '-' . time(),
						'description' => 'پرداخت پروژه #' . $pid,
					]),
				]);
				if (is_wp_error($resp)) wp_die('ارتباط با PayPing برقرار نشد.');
				$body = json_decode(wp_remote_retrieve_body($resp), true);
				$code = $body['code'] ?? '';
				if (!$code) $this->fail_redirect($pid, $amount, 'پاسخ نامعتبر');
				update_post_meta($pid, '_cptt_pay_authority_' . $gw_id, (string)$code);
				wp_redirect('https://api.payping.ir/v2/pay/gotoipg/' . $code); exit;

			case 'bank':
				$url = $gw['bank_url'] ?? '';
				if (!$url) wp_die('لینک درگاه بانک تنظیم نشده است.');
				$url = add_query_arg(['amount'=>$rial, 'project'=>$pid, 'callback'=>urlencode($callback)], $url);
				wp_redirect($url); exit;

			default:
				wp_die('این روش پرداخت پشتیبانی نمی‌شود.');
		}
	}

	private function fail_redirect($pid, $amount, $message = '') {
		$url = add_query_arg([
			'action'     => 'cptt_pay_project',
			'project_id' => $pid,
			'amount'     => $amount,
			'status'     => 'failed',
			'msg'        => $message ?: 'پرداخت ناموفق',
			'_wpnonce'   => wp_create_nonce(self::NONCE_PAY . '_' . $pid),
		], admin_url('admin-post.php'));
		wp_safe_redirect($url); exit;
	}

	public function online_callback() {
		$pid    = absint($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
		$amount = (float)($_GET['amount'] ?? $_POST['amount'] ?? 0);
		$gw_id  = sanitize_key($_GET['gw'] ?? $_POST['gw'] ?? '');
		$gw     = self::find_gateway($gw_id);
		if (!$pid || !$gw) wp_die('پارامترهای نامعتبر.');

		$type     = $gw['type'];
		$settings = self::get_settings();
		$factor   = max(1, (int)$settings['currency_factor']);
		$rial     = (int) round($amount * $factor);
		$auth     = (string) get_post_meta($pid, '_cptt_pay_authority_' . $gw_id, true);
		$verified = false;
		$ref_id   = '';

		switch ($type) {
			case 'zarinpal':
				$status_qp = sanitize_key($_GET['Status'] ?? '');
				if ($status_qp !== 'OK') { $this->fail_redirect($pid, $amount, 'پرداخت توسط کاربر لغو شد.'); }
				$merchant = $gw['merchant_id'] ?? '';
				$sandbox  = !empty($gw['sandbox']);
				$url = $sandbox ? 'https://sandbox.zarinpal.com/pgw/v4/payment/verify.json' : 'https://payment.zarinpal.com/pg/v4/payment/verify.json';
				$resp = wp_remote_post($url, [
					'timeout' => 15,
					'headers' => ['Content-Type' => 'application/json'],
					'body'    => wp_json_encode(['merchant_id'=>$merchant,'amount'=>$rial,'authority'=>$auth]),
				]);
				if (!is_wp_error($resp)) {
					$body = json_decode(wp_remote_retrieve_body($resp), true);
					$code = $body['data']['code'] ?? 0;
					if ((int)$code === 100 || (int)$code === 101) { $verified = true; $ref_id = (string)($body['data']['ref_id'] ?? ''); }
				}
				break;
			case 'zibal':
				$track = sanitize_text_field($_GET['trackId'] ?? '');
				$merchant = $gw['merchant_id'] ?? '';
				$resp = wp_remote_post('https://gateway.zibal.ir/v1/verify', [
					'timeout' => 15,
					'headers' => ['Content-Type' => 'application/json'],
					'body'    => wp_json_encode(['merchant'=>$merchant,'trackId'=>$track]),
				]);
				if (!is_wp_error($resp)) {
					$body = json_decode(wp_remote_retrieve_body($resp), true);
					if ((int)($body['result'] ?? -1) === 100) { $verified = true; $ref_id = (string)($body['refNumber'] ?? $track); }
				}
				break;
			case 'idpay':
				$id = sanitize_text_field($_POST['id'] ?? $_GET['id'] ?? '');
				$order = sanitize_text_field($_POST['order_id'] ?? $_GET['order_id'] ?? '');
				$api = $gw['api_key'] ?? '';
				$sandbox = !empty($gw['sandbox']);
				$resp = wp_remote_post('https://api.idpay.ir/v1.1/payment/verify', [
					'timeout' => 15,
					'headers' => ['Content-Type'=>'application/json','X-API-KEY'=>$api,'X-SANDBOX'=>$sandbox?'1':'0'],
					'body'    => wp_json_encode(['id'=>$id,'order_id'=>$order]),
				]);
				if (!is_wp_error($resp)) {
					$body = json_decode(wp_remote_retrieve_body($resp), true);
					if ((int)($body['status'] ?? 0) === 100) { $verified = true; $ref_id = (string)($body['track_id'] ?? $id); }
				}
				break;
			case 'nextpay':
				$trans = sanitize_text_field($_POST['trans_id'] ?? $_GET['trans_id'] ?? $auth);
				$api = $gw['api_key'] ?? '';
				$resp = wp_remote_post('https://nextpay.org/nx/gateway/verify', [
					'timeout' => 15,
					'body'    => ['api_key'=>$api, 'amount'=>$rial, 'trans_id'=>$trans, 'currency'=>'IRR'],
				]);
				if (!is_wp_error($resp)) {
					$body = json_decode(wp_remote_retrieve_body($resp), true);
					if ((int)($body['code'] ?? 0) === 0) { $verified = true; $ref_id = (string)($body['Shaparak_Ref_Id'] ?? $trans); }
				}
				break;
			case 'payping':
				$ref = sanitize_text_field($_POST['refid'] ?? $_GET['refid'] ?? '');
				$token = $gw['token'] ?? '';
				$resp = wp_remote_post('https://api.payping.ir/v2/pay/verify', [
					'timeout' => 15,
					'headers' => ['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'],
					'body'    => wp_json_encode(['amount'=>$rial, 'refId'=>$ref]),
				]);
				if (!is_wp_error($resp)) {
					$body = json_decode(wp_remote_retrieve_body($resp), true);
					if (!empty($body['cardNumber']) || !empty($body['amount'])) { $verified = true; $ref_id = $ref; }
				}
				break;
			case 'bank':
				// درگاه بانکی: فرض بر این که با status=ok بازمی‌گردد
				$verified = (sanitize_key($_REQUEST['status'] ?? '') === 'ok');
				$ref_id   = sanitize_text_field($_REQUEST['ref'] ?? '');
				break;
		}

		if ($verified) {
			$this->apply_payment_to_project($pid, $amount);
			if (class_exists('CPTT_Core')) {
				if (method_exists('CPTT_Core', 'ledger_add')) {
					CPTT_Core::ledger_add(['project_id'=>$pid,'type'=>'online_payment','amount'=>$amount,'note'=>'پرداخت آنلاین ('.$type.') ref:'.$ref_id]);
				}
				if (method_exists('CPTT_Core', 'activity_log')) {
					CPTT_Core::activity_log('payment_online', $pid, 'online_paid', 'پرداخت آنلاین موفق ('.$type.') ref:'.$ref_id);
				}
			}
			$url = add_query_arg([
				'action'=>'cptt_pay_project','project_id'=>$pid,'amount'=>$amount,'status'=>'success',
				'_wpnonce'=>wp_create_nonce(self::NONCE_PAY . '_' . $pid),
			], admin_url('admin-post.php'));
			wp_safe_redirect($url); exit;
		}
		$this->fail_redirect($pid, $amount, 'تایید پرداخت ناموفق.');
	}

	/* =====================================================================
	 * SHARED
	 * ===================================================================== */

	private function apply_payment_to_project($pid, $amount) {
		$steps = get_post_meta($pid, '_cptt_steps', true);
		if (!is_array($steps)) return;
		$left = (float) $amount;
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
		// به‌روزرسانی وضعیت تسویه
		$fin_remain = 0; $fin_cost = 0;
		foreach ($steps as $s) { $fin_cost += (float)($s['cost'] ?? 0); $fin_remain += max(0, (float)($s['cost'] ?? 0) - (float)($s['paid'] ?? 0)); }
		update_post_meta($pid, '_cptt_is_settled', ($fin_remain <= 0 && $fin_cost > 0) ? 1 : 0);
	}

	public function ajax_save_settings() {
		check_ajax_referer(self::NONCE_AJAX, 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error('no_access', 403);
		$this->save_from_post();
		wp_send_json_success(['ok'=>true]);
	}

	/* =====================================================================
	 * STYLES & SCRIPTS
	 * ===================================================================== */

	private function print_admin_css() { ?>
		.cptt-pay-wrap{font-family:Tahoma,Vazirmatn,sans-serif;}
		.cptt-pay-hero{position:relative;border-radius:24px;overflow:hidden;background:linear-gradient(135deg,#0f172a,#4f46e5 70%,#7c3aed);color:#fff;padding:28px;margin:18px 0;box-shadow:0 20px 50px rgba(15,23,42,.25);}
		.cptt-pay-hero__bg{position:absolute;inset:0;background:radial-gradient(circle at 80% 20%,rgba(255,255,255,.18),transparent 50%),radial-gradient(circle at 10% 90%,rgba(124,58,237,.4),transparent 60%);}
		.cptt-pay-hero__content{position:relative;display:flex;gap:22px;align-items:center;flex-wrap:wrap;}
		.cptt-pay-hero__icon{font-size:52px;width:88px;height:88px;border-radius:24px;background:rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;backdrop-filter:blur(8px);}
		.cptt-pay-hero__title{font-size:26px;margin:0 0 6px;font-weight:900;}
		.cptt-pay-hero__desc{margin:0;opacity:.92;font-size:13.5px;line-height:1.9;max-width:720px;}
		.cptt-pay-hero__stats{display:flex;gap:14px;flex-wrap:wrap;margin-top:12px;font-size:12.5px;}
		.cptt-pay-hero__stats span{background:rgba(255,255,255,.14);border-radius:10px;padding:6px 12px;}
		.cptt-pay-hero__stats b{margin:0 4px;}

		.cptt-pay-tabs{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;}
		.cptt-pay-tabbtn{background:#fff;border:1px solid #e5e7eb;color:#475569;border-radius:14px;padding:10px 18px;font-weight:700;cursor:pointer;font-size:13px;transition:all .15s;}
		.cptt-pay-tabbtn:hover{border-color:#c7d2fe;color:#4338ca;}
		.cptt-pay-tabbtn.active{background:linear-gradient(135deg,#4f46e5,#7c3aed);border-color:transparent;color:#fff;box-shadow:0 6px 18px rgba(79,70,229,.3);}

		.cptt-pay-card{background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:18px;box-shadow:0 6px 18px rgba(15,23,42,.04);margin-bottom:14px;}
		.cptt-pay-card__title{margin:0 0 14px;font-size:15px;color:#0f172a;font-weight:900;}

		.cptt-pay-add-wrap{background:#fff;border:1px dashed #c7d2fe;border-radius:18px;padding:14px;margin-bottom:14px;}
		.cptt-pay-add-label{display:block;font-weight:800;color:#4338ca;margin-bottom:8px;}
		.cptt-pay-add-row{display:flex;gap:8px;flex-wrap:wrap;}
		.cptt-pay-input{padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;font-family:inherit;font-size:13px;background:#fff;}
		.cptt-pay-input:focus{outline:none;border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.15);}

		.cptt-pay-btn{background:#f1f5f9;color:#334155;border:none;border-radius:10px;padding:9px 14px;font-weight:700;cursor:pointer;font-size:13px;transition:all .15s;font-family:inherit;}
		.cptt-pay-btn:hover{transform:translateY(-1px);}
		.cptt-pay-btn--primary{background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;box-shadow:0 4px 12px rgba(79,70,229,.25);}
		.cptt-pay-btn--ghost{background:#fff;border:1px dashed #cbd5e1;color:#475569;}
		.cptt-pay-btn--save{background:linear-gradient(135deg,#16a34a,#059669);color:#fff;padding:12px 24px;font-size:14px;box-shadow:0 6px 18px rgba(22,163,74,.3);}
		.cptt-pay-btn--ok{background:#dcfce7;color:#166534;}
		.cptt-pay-btn--no{background:#fee2e2;color:#991b1b;}

		.cptt-pay-gateways{display:grid;gap:14px;}
		.cptt-pay-gw{background:#fff;border:1px solid #e5e7eb;border-right:5px solid var(--gw-color, #4f46e5);border-radius:18px;padding:16px;box-shadow:0 6px 18px rgba(15,23,42,.04);}
		.cptt-pay-gw__head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;}
		.cptt-pay-gw__title{display:flex;gap:12px;align-items:center;flex:1;min-width:240px;}
		.cptt-pay-gw__icon{font-size:30px;width:54px;height:54px;border-radius:14px;background:color-mix(in srgb, var(--gw-color) 12%, white);display:flex;align-items:center;justify-content:center;}
		.cptt-pay-gw__nameInput{font-size:15px;font-weight:800;color:#0f172a;background:transparent;border:1px dashed transparent;padding:4px 6px;border-radius:6px;width:100%;}
		.cptt-pay-gw__nameInput:hover,.cptt-pay-gw__nameInput:focus{background:#f8fafc;border-color:#cbd5e1;outline:none;}
		.cptt-pay-gw__type{display:block;font-size:11.5px;color:#64748b;margin-top:2px;}
		.cptt-pay-gw__actions{display:flex;gap:10px;align-items:center;}
		.cptt-pay-gw__remove{background:#fee2e2;color:#991b1b;border:none;width:36px;height:36px;border-radius:12px;cursor:pointer;font-size:16px;transition:all .15s;}
		.cptt-pay-gw__remove:hover{background:#fca5a5;color:#fff;}
		.cptt-pay-gw__desc{margin:10px 0 12px;color:#64748b;font-size:12px;background:#f8fafc;padding:8px 12px;border-radius:10px;}
		.cptt-pay-gw__body{display:grid;gap:12px;}

		.cptt-pay-switch{position:relative;display:inline-block;width:46px;height:26px;}
		.cptt-pay-switch input{opacity:0;width:0;height:0;}
		.cptt-pay-switch__slider{position:absolute;cursor:pointer;inset:0;background:#cbd5e1;border-radius:999px;transition:.2s;}
		.cptt-pay-switch__slider:before{content:"";position:absolute;height:20px;width:20px;right:3px;top:3px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 2px 4px rgba(0,0,0,.15);}
		.cptt-pay-switch input:checked + .cptt-pay-switch__slider{background:linear-gradient(135deg,#16a34a,#059669);}
		.cptt-pay-switch input:checked + .cptt-pay-switch__slider:before{transform:translateX(-20px);}

		.cptt-pay-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;}
		.cptt-pay-field{display:flex;flex-direction:column;gap:5px;}
		.cptt-pay-field--wide{grid-column:1/-1;}
		.cptt-pay-field span{font-size:12px;font-weight:700;color:#475569;}
		.cptt-pay-field textarea.cptt-pay-input{font-family:inherit;}

		.cptt-pay-cards{background:#f8fafc;border-radius:14px;padding:12px;}
		.cptt-pay-cards__head{font-size:12px;color:#475569;margin-bottom:10px;font-weight:700;}
		.cptt-pay-cards__list{display:grid;gap:8px;margin-bottom:10px;}
		.cptt-pay-card-row{display:grid;grid-template-columns:2fr 1.4fr 1fr auto;gap:8px;align-items:center;}
		.cptt-pay-card-num{letter-spacing:1px;font-family:monospace;font-weight:700;}
		.cptt-pay-card-del{background:#fee2e2;color:#991b1b;border:none;width:34px;height:34px;border-radius:10px;cursor:pointer;}
		@media(max-width:680px){.cptt-pay-card-row{grid-template-columns:1fr;}}

		.cptt-pay-actions{position:sticky;bottom:0;background:rgba(255,255,255,.95);backdrop-filter:blur(10px);padding:14px;margin:14px -10px 0;border-top:1px solid #e5e7eb;border-radius:14px 14px 0 0;text-align:center;z-index:5;}

		.cptt-pay-rcpts{display:grid;gap:12px;}
		.cptt-pay-rcpt{display:grid;grid-template-columns:120px 1fr;gap:14px;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:12px;align-items:start;}
		.cptt-pay-rcpt__thumb{width:120px;height:120px;border-radius:12px;background:#f1f5f9;overflow:hidden;display:flex;align-items:center;justify-content:center;}
		.cptt-pay-rcpt__thumb img{width:100%;height:100%;object-fit:cover;}
		.cptt-pay-rcpt__file{color:#4338ca;font-weight:700;text-decoration:none;}
		.cptt-pay-rcpt__noimg{color:#94a3b8;font-size:20px;}
		.cptt-pay-rcpt__row{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:8px;}
		.cptt-pay-rcpt__badge{padding:4px 10px;border-radius:999px;font-size:11.5px;font-weight:800;}
		.cptt-pay-rcpt__meta{display:flex;gap:14px;flex-wrap:wrap;font-size:12.5px;color:#64748b;margin-bottom:10px;}
		.cptt-pay-rcpt__actions{display:flex;gap:8px;}
		.cptt-pay-empty{text-align:center;padding:40px;color:#94a3b8;background:#f8fafc;border-radius:14px;}

		@media(max-width:680px){.cptt-pay-rcpt{grid-template-columns:1fr;}.cptt-pay-rcpt__thumb{width:100%;height:180px;}}
	<?php }

	private function print_admin_js() { ?>
		(function(){
			// تب‌ها
			document.querySelectorAll('.cptt-pay-tabbtn').forEach(function(b){
				b.addEventListener('click', function(){
					var t = b.getAttribute('data-tab');
					document.querySelectorAll('.cptt-pay-tabbtn').forEach(function(x){ x.classList.remove('active'); });
					document.querySelectorAll('.cptt-pay-tab').forEach(function(x){ x.style.display = (x.getAttribute('data-tab')===t)?'':'none'; });
					b.classList.add('active');
				});
			});

			// حذف gateway
			document.addEventListener('click', function(e){
				var rm = e.target.closest('.cptt-pay-gw__remove');
				if (rm) {
					if (!confirm('حذف این روش پرداخت؟')) return;
					rm.closest('.cptt-pay-gw').remove();
					reindexGateways();
				}
				var addCard = e.target.closest('.cptt-pay-card-add');
				if (addCard) {
					var gw = addCard.closest('.cptt-pay-gw');
					var idx = gw ? Array.prototype.indexOf.call(gw.parentNode.children, gw) : 0;
					var list = gw.querySelector('.cptt-pay-cards__list');
					var ci = list.children.length;
					var row = document.createElement('div');
					row.className = 'cptt-pay-card-row';
					row.innerHTML =
						'<input type="text" name="gateways['+idx+'][cards]['+ci+'][number]" placeholder="شماره کارت ۱۶ رقمی" class="cptt-pay-input cptt-pay-card-num" inputmode="numeric">' +
						'<input type="text" name="gateways['+idx+'][cards]['+ci+'][owner]" placeholder="نام صاحب کارت" class="cptt-pay-input">' +
						'<input type="text" name="gateways['+idx+'][cards]['+ci+'][bank]"  placeholder="بانک" class="cptt-pay-input">' +
						'<button type="button" class="cptt-pay-card-del">×</button>';
					list.appendChild(row);
				}
				var delCard = e.target.closest('.cptt-pay-card-del');
				if (delCard) { delCard.closest('.cptt-pay-card-row').remove(); }
			});

			function reindexGateways(){
				var list = document.getElementById('cptt-pay-gateways-list');
				if (!list) return;
				Array.prototype.forEach.call(list.children, function(gw, i){
					gw.querySelectorAll('input, textarea, select').forEach(function(inp){
						var n = inp.getAttribute('name');
						if (!n) return;
						inp.setAttribute('name', n.replace(/^gateways\[\d+\]/, 'gateways['+i+']'));
					});
				});
			}

			// افزودن gateway جدید
			var addBtn = document.getElementById('cptt-pay-add-gateway');
			if (addBtn) {
				addBtn.addEventListener('click', function(){
					var sel = document.getElementById('cptt-pay-newtype');
					if (!sel) return;
					var type = sel.value;
					var list = document.getElementById('cptt-pay-gateways-list');
					var idx  = list.children.length;
					var html = buildGatewayHtml(type, idx);
					list.insertAdjacentHTML('beforeend', html);
				});
			}

			function buildGatewayHtml(type, idx){
				var typesMap = <?php echo wp_json_encode(self::gateway_types()); ?>;
				var t = typesMap[type] || typesMap.card;
				var id = 'g_' + Math.random().toString(36).slice(2, 8);
				var fieldsHtml = '';
				if (type === 'card') {
					fieldsHtml = '<div class="cptt-pay-cards">' +
						'<div class="cptt-pay-cards__head">💳 کارت‌های بانکی این روش</div>' +
						'<div class="cptt-pay-cards__list">' +
							'<div class="cptt-pay-card-row">' +
								'<input type="text" name="gateways['+idx+'][cards][0][number]" placeholder="شماره کارت ۱۶ رقمی" class="cptt-pay-input cptt-pay-card-num" inputmode="numeric">' +
								'<input type="text" name="gateways['+idx+'][cards][0][owner]"  placeholder="نام صاحب کارت" class="cptt-pay-input">' +
								'<input type="text" name="gateways['+idx+'][cards][0][bank]"   placeholder="بانک" class="cptt-pay-input">' +
								'<button type="button" class="cptt-pay-card-del">×</button>' +
							'</div>' +
						'</div>' +
						'<button type="button" class="cptt-pay-btn cptt-pay-btn--ghost cptt-pay-card-add">+ افزودن کارت دیگر</button>' +
						'</div>';
				} else {
					var fs = (t.fields || []);
					fieldsHtml = '<div class="cptt-pay-grid">';
					fs.forEach(function(f){
						if (f === 'sandbox') {
							fieldsHtml += '<label class="cptt-pay-field"><span>حالت تست (Sandbox)</span><label class="cptt-pay-switch"><input type="checkbox" name="gateways['+idx+']['+f+']" value="1"><span class="cptt-pay-switch__slider"></span></label></label>';
						} else {
							var lbl = ({merchant_id:'کد مرچنت',api_key:'API Key',token:'توکن دسترسی',bank_url:'لینک درگاه بانک',bank_name:'نام بانک'})[f] || f;
							var wide = (f === 'bank_url') ? ' cptt-pay-field--wide' : '';
							fieldsHtml += '<label class="cptt-pay-field'+wide+'"><span>'+lbl+'</span><input type="text" name="gateways['+idx+']['+f+']" class="cptt-pay-input"></label>';
						}
					});
					fieldsHtml += '</div>';
				}
				return '<div class="cptt-pay-gw" style="--gw-color:'+t.color+';">' +
					'<div class="cptt-pay-gw__head">' +
						'<div class="cptt-pay-gw__title"><span class="cptt-pay-gw__icon">'+t.icon+'</span><div><input type="text" name="gateways['+idx+'][name]" value="'+t.label+'" class="cptt-pay-gw__nameInput"><small class="cptt-pay-gw__type">'+t.label+'</small></div></div>' +
						'<div class="cptt-pay-gw__actions"><label class="cptt-pay-switch"><input type="checkbox" name="gateways['+idx+'][enabled]" value="1" checked><span class="cptt-pay-switch__slider"></span></label><button type="button" class="cptt-pay-gw__remove">🗑</button></div>' +
						'<input type="hidden" name="gateways['+idx+'][id]" value="'+id+'"><input type="hidden" name="gateways['+idx+'][type]" value="'+type+'">' +
					'</div>' +
					'<div class="cptt-pay-gw__desc">'+t.desc+'</div>' +
					'<div class="cptt-pay-gw__body">'+fieldsHtml+
						'<label class="cptt-pay-field cptt-pay-field--wide"><span>یادداشت/راهنما برای مشتری</span><textarea name="gateways['+idx+'][note]" rows="2" class="cptt-pay-input"></textarea></label>' +
					'</div>' +
				'</div>';
			}
		})();
	<?php }

	private function print_pay_css() { ?>
		*{box-sizing:border-box;}
		body{margin:0;padding:40px 12px;font-family:Tahoma,Vazirmatn,sans-serif;background:linear-gradient(135deg, #f1f5f9 0%, #e0e7ff 50%, #ddd6fe 100%);min-height:100vh;display:flex;justify-content:center;align-items:flex-start;}
		.pay-page{width:100%;max-width:560px;display:flex;justify-content:center;}
		.pay-page__shell{width:100%;background:#ffffff;border-radius:32px;padding:30px;box-shadow:0 30px 80px rgba(15,23,42,.15);border:1px solid rgba(255,255,255,0.7);transition:all 0.3s ease;}
		.pay-page__brand{display:flex;gap:16px;align-items:center;padding-bottom:20px;border-bottom:1px dashed #e2e8f0;margin-bottom:20px;}
		.pay-page__logo{width:56px;height:56px;border-radius:20px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:30px;box-shadow:0 10px 25px rgba(99,102,241,.35);animation:pulse 2s infinite;}
		@keyframes pulse {
			0% { transform: scale(1); }
			50% { transform: scale(1.03); }
			100% { transform: scale(1); }
		}
		.pay-page__title{font-size:22px;font-weight:900;color:#1e1b4b;}
		.pay-page__sub{font-size:13px;color:#64748b;margin-top:4px;}

		.pay-page__project{background:#f8fafc;border:1px solid #f1f5f9;border-radius:18px;padding:14px 18px;margin-bottom:16px;}
		.pay-page__project-name{font-weight:900;color:#0f172a;font-size:16px;}
		.pay-page__project-meta{font-size:12px;color:#64748b;margin-top:4px;}

		.pay-page__amount{background:linear-gradient(135deg,#1e1b4b,#4f46e5);color:#fff;border-radius:20px;padding:24px;margin-bottom:20px;text-align:center;box-shadow:0 12px 30px rgba(79,70,229,.25);position:relative;overflow:hidden;}
		.pay-page__amount::before {
			content:''; position:absolute; inset:0; background:linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
			transform:translateX(-100%); animation:shimmer 3s infinite;
		}
		@keyframes shimmer {
			100% { transform: translateX(100%); }
		}
		.pay-page__amount-label{font-size:13px;opacity:.85;margin-bottom:6px;font-weight:bold;}
		.pay-page__amount-value{font-size:36px;font-weight:900;letter-spacing:1px;}
		.pay-page__amount-value span{font-size:16px;opacity:.9;margin-right:6px;font-weight:bold;}

		.pay-page__notice{padding:14px 18px;border-radius:16px;margin-bottom:18px;font-size:14px;font-weight:bold;line-height:1.6;}
		.pay-page__notice--info{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;}
		.pay-page__notice--ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
		.pay-page__notice--err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}

		.pay-page__section-title{font-size:14px;color:#334155;font-weight:900;margin-bottom:12px;}

		.pay-page__gws{display:grid;gap:12px;margin-bottom:16px;}
		.pay-gw{display:flex;gap:16px;align-items:center;background:#fff;border:1.5px solid #e2e8f0;border-radius:18px;padding:16px;text-decoration:none;color:inherit;transition:all .25s cubic-bezier(0.4, 0, 0.2, 1);border-right:6px solid var(--c, #4f46e5);box-shadow:0 4px 6px -1px rgba(0, 0, 0, 0.02);}
		.pay-gw:hover{transform:translateY(-3px);border-color:var(--c, #4f46e5);box-shadow:0 12px 20px -5px rgba(0, 0, 0, 0.08);}
		.pay-gw__icon{width:50px;height:50px;border-radius:16px;background:color-mix(in srgb, var(--c) 14%, white);display:flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0;}
		.pay-gw__body{flex:1;}
		.pay-gw__name{font-weight:900;color:#1e293b;font-size:15px;}
		.pay-gw__desc{font-size:12px;color:#64748b;margin-top:4px;}
		.pay-gw__arrow{color:var(--c, #4f46e5);font-size:24px;font-weight:900;transition:transform 0.2s;}
		.pay-gw:hover .pay-gw__arrow{transform:translateX(-4px);}

		/* Card Mockup */
		.card-mockup {
			background: linear-gradient(135deg, #1e1b4b 0%, #311042 50%, #4c1d95 100%);
			border-radius: 20px;
			padding: 24px;
			color: #fff;
			position: relative;
			overflow: hidden;
			box-shadow: 0 15px 35px rgba(0,0,0,0.2);
			margin-bottom: 8px;
			aspect-ratio: 1.586/1;
			display: flex;
			flex-direction: column;
			justify-content: space-between;
			transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
		}
		.card-mockup:hover {
			transform: translateY(-5px) rotate(1deg);
			box-shadow: 0 20px 40px rgba(124, 58, 237, 0.25);
		}
		.card-mockup::before {
			content: '';
			position: absolute;
			top: -50%;
			left: -50%;
			width: 200%;
			height: 200%;
			background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 80%);
			pointer-events: none;
		}
		.card-mockup__chip {
			width: 48px;
			height: 38px;
			background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
			border-radius: 8px;
			position: relative;
			overflow: hidden;
			box-shadow: inset 0 1px 3px rgba(255,255,255,0.5);
			margin: 10px 0;
		}
		.card-mockup__chip::after {
			content: '';
			position: absolute;
			inset: 4px;
			border: 1px solid rgba(0,0,0,0.15);
			border-radius: 4px;
		}
		.card-mockup__top {
			display: flex;
			justify-content: space-between;
			align-items: flex-start;
		}
		.card-mockup__bank {
			font-size: 16px;
			font-weight: 900;
			text-shadow: 0 2px 4px rgba(0,0,0,0.2);
		}
		.card-mockup__num {
			font-size: 24px;
			font-weight: bold;
			font-family: monospace, sans-serif;
			letter-spacing: 3px;
			text-align: center;
			text-shadow: 0 2px 4px rgba(0,0,0,0.4);
			direction: ltr;
		}
		.card-mockup__bottom {
			display: flex;
			justify-content: space-between;
			align-items: flex-end;
		}
		.card-mockup__owner {
			font-size: 14px;
			font-weight: 800;
			text-shadow: 0 1px 3px rgba(0,0,0,0.2);
		}
		.card-mockup__logo {
			font-size: 26px;
			opacity: 0.9;
		}

		/* Custom File Dropzone */
		.file-dropzone {
			border: 2px dashed #cbd5e1;
			border-radius: 16px;
			background: #f8fafc;
			padding: 30px 20px;
			text-align: center;
			cursor: pointer;
			transition: all 0.3s ease;
			display: flex;
			flex-direction: column;
			align-items: center;
			justify-content: center;
			gap: 12px;
			position: relative;
		}
		.file-dropzone:hover {
			border-color: #6366f1;
			background: #f0f5ff;
			box-shadow: 0 4px 12px rgba(99, 102, 241, 0.08);
		}
		.file-dropzone__icon {
			font-size: 40px;
			transition: all 0.3s ease;
		}
		.file-dropzone:hover .file-dropzone__icon {
			transform: translateY(-4px) scale(1.1);
		}
		.file-dropzone__text {
			font-size: 13px;
			font-weight: 700;
			color: #475569;
		}
		.file-dropzone__subtext {
			font-size: 11px;
			color: #94a3b8;
		}
		.file-dropzone__preview {
			margin-top: 10px;
			display: flex;
			flex-direction: column;
			align-items: center;
			gap: 8px;
			animation: slideUp 0.3s ease;
		}
		.file-dropzone__preview img {
			max-height: 120px;
			border-radius: 12px;
			box-shadow: 0 8px 20px rgba(0,0,0,0.1);
			object-fit: contain;
			border: 2px solid #fff;
		}
		@keyframes slideUp {
			from { opacity: 0; transform: translateY(10px); }
			to { opacity: 1; transform: translateY(0); }
		}

		.pay-page__back{text-align:center;margin-top:20px;}
		.pay-page__back a{color:#4f46e5;text-decoration:none;font-size:13px;font-weight:800;transition:all 0.2s;}
		.pay-page__back a:hover{color:#4338ca;}
		.pay-page__footer{text-align:center;color:#94a3b8;font-size:11px;margin-top:24px;padding-top:16px;border-top:1px dashed #e2e8f0;}
	<?php }
}
