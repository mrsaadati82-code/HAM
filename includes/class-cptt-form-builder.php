<?php
/**
 * CPTT Form Builder — v5.5.0
 * 
 * فرم‌ساز سفارش اختصاصی برای ربات بله (MVP).
 * - مدیر در ادمین می‌تواند فرم بسازد با فیلدهای: متن، عدد، آپلود فایل، انتخابی،
 *   تاریخ، آدرس، چک‌باکس، textarea، شماره تماس، ایمیل.
 * - فرم فعال در منوی مشتری ربات بله نشان داده می‌شود.
 * - ربات بله مرحله‌به‌مرحله از کاربر فیلد به فیلد می‌پرسد.
 * - داده‌ها در پست cptt_order ذخیره می‌شوند با کلید _cptt_order_form_data.
 */

if (!defined('ABSPATH')) exit;

class CPTT_Form_Builder {
	private static $instance = null;
	const OPT_ACTIVE = 'cptt_active_order_form_id';

	public static function instance() {
		if (self::$instance === null) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action('admin_menu', [$this, 'menu']);
		add_action('wp_ajax_cptt_form_save', [$this, 'ajax_save_form']);
		add_action('wp_ajax_cptt_form_delete', [$this, 'ajax_delete_form']);
		add_action('wp_ajax_cptt_form_activate', [$this, 'ajax_activate_form']);
		add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
	}

	public static function install_defaults() {
		// در صورت نبود هیچ فرمی، یک فرم نمونه بساز
		$q = new WP_Query(['post_type' => 'cptt_order_form', 'posts_per_page' => 1]);
		if ($q->have_posts()) return;
		$id = wp_insert_post([
			'post_type' => 'cptt_order_form', 'post_status' => 'publish',
			'post_title' => 'فرم سفارش پیش‌فرض',
		]);
		if ($id && !is_wp_error($id)) {
			update_post_meta($id, '_cptt_form_fields', self::default_fields());
			update_option(self::OPT_ACTIVE, (int)$id, false);
		}
	}

	public static function default_fields() {
		return [
			['id' => 'f_' . wp_generate_password(6, false, false), 'type' => 'select', 'label' => 'نوع سفارش', 'required' => 1, 'options' => "حضوری\nبا ارسال"],
			['id' => 'f_' . wp_generate_password(6, false, false), 'type' => 'textarea', 'label' => 'توضیحات سفارش', 'required' => 0, 'placeholder' => 'توضیح کامل سفارش خود را بنویسید...'],
			['id' => 'f_' . wp_generate_password(6, false, false), 'type' => 'file', 'label' => 'بارگذاری فایل / تصویر', 'required' => 0, 'help' => 'می‌توانید چند فایل ارسال کنید. پس از اتمام، روی «ادامه» بزنید.'],
		];
	}

	public static function available_types() {
		return [
			'text'     => ['label' => '📝 متن کوتاه', 'desc' => 'یک خط متن'],
			'textarea' => ['label' => '📄 متن چندخطی', 'desc' => 'متن طولانی'],
			'number'   => ['label' => '🔢 عدد', 'desc' => 'فقط عدد'],
			'phone'    => ['label' => '📞 شماره تماس', 'desc' => 'با اعتبارسنجی'],
			'email'    => ['label' => '📧 ایمیل', 'desc' => 'با اعتبارسنجی'],
			'select'   => ['label' => '🔽 انتخابی', 'desc' => 'لیست کشویی / دکمه‌ها'],
			'checkbox' => ['label' => '☑️ چک‌باکس', 'desc' => 'بله / خیر'],
			'date'     => ['label' => '📅 تاریخ', 'desc' => 'انتخاب تاریخ'],
			'address'  => ['label' => '📍 آدرس', 'desc' => 'آدرس پستی کامل'],
			'file'     => ['label' => '📎 بارگذاری فایل', 'desc' => 'چند فایل / عکس'],
		];
	}

	public static function get_forms() {
		$q = new WP_Query(['post_type' => 'cptt_order_form', 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'DESC']);
		return $q->posts;
	}

	public static function get_form($id) {
		$id = (int)$id;
		if (!$id) return null;
		$p = get_post($id);
		if (!$p || $p->post_type !== 'cptt_order_form') return null;
		$fields = get_post_meta($id, '_cptt_form_fields', true);
		if (!is_array($fields)) $fields = [];
		return ['id' => $id, 'title' => $p->post_title, 'fields' => $fields];
	}

	public static function get_active_form() {
		$id = (int)get_option(self::OPT_ACTIVE, 0);
		$form = $id ? self::get_form($id) : null;
		if ($form) return $form;
		// fallback: اولین فرم
		$forms = self::get_forms();
		if (!empty($forms)) {
			$form = self::get_form($forms[0]->ID);
			if ($form) update_option(self::OPT_ACTIVE, $form['id'], false);
			return $form;
		}
		return null;
	}

	/* =========================================================
	   منو
	   ========================================================= */
	public function menu() {
		add_submenu_page('edit.php?post_type=cptt_project', 'فرم‌ساز سفارش', '📋 فرم‌ساز سفارش', 'manage_options', 'cptt-form-builder', [$this, 'page']);
	}

	public function admin_assets($hook) {
		if (strpos((string)$hook, 'cptt-form-builder') === false) return;
		wp_enqueue_style('cptt-form-builder', CPTT_URL . 'assets/css/form-builder.css', [], CPTT_VERSION);
		wp_enqueue_script('cptt-form-builder', CPTT_URL . 'assets/js/form-builder.js', ['jquery','jquery-ui-sortable'], CPTT_VERSION, true);
		wp_localize_script('cptt-form-builder', 'CPTT_FB', [
			'ajax'  => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('cptt_form_builder'),
			'types' => self::available_types(),
		]);
	}

	public function page() {
		if (!current_user_can('manage_options')) return;
		$forms = self::get_forms();
		$active_id = (int)get_option(self::OPT_ACTIVE, 0);
		$editing = null;
		$edit_id = isset($_GET['form_id']) ? (int)$_GET['form_id'] : 0;
		if ($edit_id) $editing = self::get_form($edit_id);
		elseif (!empty($forms)) $editing = self::get_form($forms[0]->ID);
		?>
		<div class="wrap cptt-fb-wrap" dir="rtl">
			<div class="cptt-fb-hero">
				<h1>📋 فرم‌ساز سفارش</h1>
				<p>برای ربات بله فرم سفارش اختصاصی بسازید. کاربر در منوی «ثبت سفارش جدید» مرحله‌به‌مرحله این فیلدها را پر می‌کند.</p>
			</div>

			<div class="cptt-fb-layout">
				<!-- Sidebar: forms list -->
				<aside class="cptt-fb-sidebar">
					<div class="cptt-fb-side-head">
						<h3>فرم‌های شما</h3>
						<button class="button button-primary button-small" id="cptt-fb-new-form">+ فرم جدید</button>
					</div>
					<ul class="cptt-fb-list">
						<?php foreach ($forms as $f): $is_active = ((int)$f->ID === $active_id); $is_editing = $editing && (int)$f->ID === $editing['id']; ?>
						<li class="<?php echo $is_editing ? 'is-editing' : ''; ?>">
							<a href="<?php echo esc_url(add_query_arg(['form_id' => $f->ID])); ?>" class="cptt-fb-list-link">
								<span class="cptt-fb-list-title"><?php echo esc_html(get_the_title($f)); ?></span>
								<?php if ($is_active): ?><span class="cptt-fb-badge">فعال</span><?php endif; ?>
							</a>
						</li>
						<?php endforeach; ?>
						<?php if (empty($forms)): ?><li class="empty">هنوز فرمی نساخته‌اید.</li><?php endif; ?>
					</ul>
				</aside>

				<!-- Builder -->
				<section class="cptt-fb-builder">
					<?php if ($editing): ?>
					<div class="cptt-fb-builder-head">
						<input type="text" id="cptt-fb-form-title" value="<?php echo esc_attr($editing['title']); ?>" placeholder="عنوان فرم">
						<input type="hidden" id="cptt-fb-form-id" value="<?php echo (int)$editing['id']; ?>">
						<div class="cptt-fb-builder-actions">
							<?php if ($active_id !== (int)$editing['id']): ?>
								<button class="button" id="cptt-fb-set-active">✓ فعال کن</button>
							<?php else: ?>
								<span class="cptt-fb-active-tag">⭐ فرم فعال در بات بله</span>
							<?php endif; ?>
							<button class="button button-link-delete" id="cptt-fb-delete">× حذف فرم</button>
							<button class="button button-primary" id="cptt-fb-save">💾 ذخیره</button>
						</div>
					</div>

					<div class="cptt-fb-stage">
						<aside class="cptt-fb-palette">
							<h4>افزودن فیلد:</h4>
							<div class="cptt-fb-palette-grid">
								<?php foreach (self::available_types() as $tk => $t): ?>
								<button class="cptt-fb-add-field" data-type="<?php echo esc_attr($tk); ?>">
									<b><?php echo esc_html($t['label']); ?></b>
									<small><?php echo esc_html($t['desc']); ?></small>
								</button>
								<?php endforeach; ?>
							</div>
						</aside>
						<div class="cptt-fb-canvas">
							<div class="cptt-fb-canvas-head">فیلدهای فرم (با درگ مرتب کنید)</div>
							<ul id="cptt-fb-fields" class="cptt-fb-fields">
							<?php foreach ($editing['fields'] as $field): $this->render_field_row($field); endforeach; ?>
							</ul>
							<?php if (empty($editing['fields'])): ?>
								<div class="cptt-fb-empty">از پنل سمت راست، فیلد اضافه کنید.</div>
							<?php endif; ?>
						</div>
					</div>
					<?php else: ?>
						<div class="cptt-fb-empty-big">
							<h2>هنوز فرمی نساخته‌اید</h2>
							<p>روی «+ فرم جدید» در پنل کناری بزنید.</p>
						</div>
					<?php endif; ?>
				</section>
			</div>
		</div>
		<?php
	}

	public function render_field_row($field) {
		$fid = $field['id'] ?? ('f_' . wp_generate_password(6, false, false));
		$type = $field['type'] ?? 'text';
		$label = $field['label'] ?? '';
		$required = !empty($field['required']) ? 1 : 0;
		$placeholder = $field['placeholder'] ?? '';
		$help = $field['help'] ?? '';
		$options = $field['options'] ?? '';
		$types = self::available_types();
		$type_label = $types[$type]['label'] ?? $type;
		?>
		<li class="cptt-fb-field" data-id="<?php echo esc_attr($fid); ?>" data-type="<?php echo esc_attr($type); ?>">
			<div class="cptt-fb-field-head">
				<span class="cptt-fb-handle" title="جابجا کن">⠿</span>
				<span class="cptt-fb-field-type"><?php echo esc_html($type_label); ?></span>
				<input type="text" class="cptt-fb-field-label" placeholder="برچسب فیلد" value="<?php echo esc_attr($label); ?>">
				<label class="cptt-fb-required"><input type="checkbox" class="cptt-fb-field-required" <?php checked($required); ?>><span>اجباری</span></label>
				<button class="cptt-fb-field-remove" title="حذف">×</button>
			</div>
			<div class="cptt-fb-field-body">
				<?php if (in_array($type, ['text','textarea','number','phone','email','address'], true)): ?>
					<label class="cptt-fb-mini"><span>متن راهنما داخل فیلد (placeholder)</span><input type="text" class="cptt-fb-field-placeholder" value="<?php echo esc_attr($placeholder); ?>"></label>
				<?php endif; ?>
				<?php if ($type === 'select'): ?>
					<label class="cptt-fb-mini"><span>گزینه‌ها (هر گزینه در یک خط)</span><textarea class="cptt-fb-field-options" rows="3"><?php echo esc_textarea($options); ?></textarea></label>
				<?php endif; ?>
				<label class="cptt-fb-mini"><span>متن راهنما / پیام در بات</span><input type="text" class="cptt-fb-field-help" value="<?php echo esc_attr($help); ?>"></label>
			</div>
		</li>
		<?php
	}

	/* =========================================================
	   AJAX
	   ========================================================= */
	public function ajax_save_form() {
		check_ajax_referer('cptt_form_builder', 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error('no_access');

		$id = absint($_POST['id'] ?? 0);
		$title = sanitize_text_field($_POST['title'] ?? '');
		if ($title === '') $title = 'فرم بدون عنوان';
		$raw_fields = isset($_POST['fields']) && is_array($_POST['fields']) ? wp_unslash($_POST['fields']) : [];

		$fields = [];
		foreach ($raw_fields as $f) {
			if (!is_array($f) || empty($f['type'])) continue;
			$type = sanitize_key($f['type']);
			if (!isset(self::available_types()[$type])) continue;
			$fields[] = [
				'id'          => sanitize_text_field($f['id'] ?? ('f_' . wp_generate_password(6, false, false))),
				'type'        => $type,
				'label'       => sanitize_text_field($f['label'] ?? ''),
				'required'    => !empty($f['required']) ? 1 : 0,
				'placeholder' => sanitize_text_field($f['placeholder'] ?? ''),
				'help'        => sanitize_text_field($f['help'] ?? ''),
				'options'     => sanitize_textarea_field($f['options'] ?? ''),
			];
		}

		if (!$id) {
			$id = wp_insert_post(['post_type' => 'cptt_order_form', 'post_status' => 'publish', 'post_title' => $title]);
			if (is_wp_error($id)) wp_send_json_error('insert_failed');
		} else {
			wp_update_post(['ID' => $id, 'post_title' => $title]);
		}
		update_post_meta($id, '_cptt_form_fields', $fields);
		wp_send_json_success(['id' => $id]);
	}

	public function ajax_delete_form() {
		check_ajax_referer('cptt_form_builder', 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error('no_access');
		$id = absint($_POST['id'] ?? 0);
		if (!$id) wp_send_json_error('no_id');
		wp_delete_post($id, true);
		if ((int)get_option(self::OPT_ACTIVE, 0) === $id) delete_option(self::OPT_ACTIVE);
		wp_send_json_success();
	}

	public function ajax_activate_form() {
		check_ajax_referer('cptt_form_builder', 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error('no_access');
		$id = absint($_POST['id'] ?? 0);
		if (!$id) wp_send_json_error('no_id');
		update_option(self::OPT_ACTIVE, $id, false);
		wp_send_json_success();
	}
}
