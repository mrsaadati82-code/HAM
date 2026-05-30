<?php
if ( ! defined('ABSPATH') ) exit;

class CPTT_WooCommerce {
	private static $instance = null;
	private $meta_key = '_cptt_wc_project_configs';

	public static function instance() {
		if (self::$instance === null) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_filter('woocommerce_product_data_tabs', [$this, 'product_data_tab']);
		add_action('woocommerce_product_data_panels', [$this, 'product_data_panel']);
		add_action('woocommerce_process_product_meta', [$this, 'save_product_meta']);
		add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

		add_action('woocommerce_order_status_processing', [$this, 'maybe_create_projects_for_order'], 20, 1);
		add_action('woocommerce_order_status_completed', [$this, 'maybe_create_projects_for_order'], 20, 1);
	}

	public function admin_assets($hook) {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || $screen->post_type !== 'product') return;
		wp_enqueue_style('cptt-admin', CPTT_URL . 'assets/css/admin.css', [], CPTT_VERSION);
		wp_enqueue_script('cptt-wc-product', CPTT_URL . 'assets/js/wc-product.js', ['jquery'], CPTT_VERSION, true);
	}

	public function product_data_tab($tabs) {
		$tabs['cptt_projects'] = [
			'label' => 'پروژه‌ها',
			'target' => 'cptt_project_product_data',
			'class' => [],
			'priority' => 80,
		];
		return $tabs;
	}

	private function templates() {
		return get_posts([
			'post_type' => 'cptt_template',
			'numberposts' => -1,
			'orderby' => 'title',
			'order' => 'ASC',
		]);
	}

	private function experts() {
		return get_users([
			'role__in' => ['cptt_expert', 'administrator'],
			'fields' => ['ID','display_name','user_email'],
		]);
	}

	private function template_options($selected = 0) {
		$out = '<option value="">— بدون تمپلیت —</option>';
		foreach ($this->templates() as $t) {
			$out .= sprintf('<option value="%d" %s>%s</option>', (int)$t->ID, selected((int)$selected, (int)$t->ID, false), esc_html(get_the_title($t)));
		}
		return $out;
	}

	private function expert_options($selected = []) {
		if (!is_array($selected)) $selected = [];
		$selected = array_map('intval', $selected);
		$out = '';
		foreach ($this->experts() as $u) {
			$out .= sprintf('<option value="%d" %s>%s</option>', (int)$u->ID, in_array((int)$u->ID, $selected, true) ? 'selected' : '', esc_html($u->display_name . ' (' . $u->user_email . ')'));
		}
		return $out;
	}

	public function product_data_panel() {
		global $post;
		if (!$post) return;
		$configs = get_post_meta($post->ID, $this->meta_key, true);
		if (!is_array($configs)) $configs = [];
		?>
		<div id="cptt_project_product_data" class="panel woocommerce_options_panel cptt-wc-panel" dir="rtl">
			<div class="options_group">
				<p class="form-field" style="padding-left:12px;">
					<strong>ساخت خودکار پروژه پس از خرید محصول</strong><br>
					<span class="description">برای این محصول می‌توانید یک یا چند پروژه تعریف کنید. بعد از رسیدن سفارش به وضعیت «در حال انجام» یا «تکمیل شده»، پروژه‌ها برای کاربر سفارش ساخته می‌شوند.</span>
				</p>

				<div id="cptt-wc-project-rows" class="cptt-wc-projectRows">
					<?php foreach ($configs as $i => $cfg): ?>
						<?php $this->render_project_row((int)$i, $cfg); ?>
					<?php endforeach; ?>
				</div>

				<p style="padding: 0 12px 12px;">
					<button type="button" class="button button-primary" id="cptt-wc-add-project-row">+ افزودن پروژه خودکار</button>
				</p>

				<p class="description" style="padding:0 12px 14px;">
					متغیرهای عنوان: <code>{product}</code> <code>{order}</code> <code>{customer}</code> <code>{site_name}</code>
				</p>
			</div>

			<script type="text/template" id="cptt-wc-project-row-template">
				<?php $this->render_project_row('__i__', []); ?>
			</script>
		</div>
		<?php
	}

	private function render_project_row($i, $cfg) {
		$title = isset($cfg['title_pattern']) ? (string)$cfg['title_pattern'] : '{product} - سفارش #{order}';
		$template_id = isset($cfg['template_id']) ? (int)$cfg['template_id'] : 0;
		$experts = isset($cfg['expert_user_ids']) && is_array($cfg['expert_user_ids']) ? $cfg['expert_user_ids'] : [];
		$deadline_days = isset($cfg['deadline_days']) ? (int)$cfg['deadline_days'] : 0;
		?>
		<div class="cptt-wc-projectRow">
			<div class="cptt-wc-projectRow__head">
				<strong>پروژه خودکار</strong>
				<button type="button" class="button cptt-wc-remove-project-row">× حذف</button>
			</div>
			<div class="cptt-wc-projectRow__grid">
				<label>
					<span>عنوان پروژه</span>
					<input type="text" name="cptt_wc_projects[<?php echo esc_attr($i); ?>][title_pattern]" value="<?php echo esc_attr($title); ?>" placeholder="{product} - سفارش #{order}">
				</label>
				<label>
					<span>تمپلیت مراحل</span>
					<select name="cptt_wc_projects[<?php echo esc_attr($i); ?>][template_id]">
						<?php echo $this->template_options($template_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</select>
				</label>
				<label>
					<span>کارشناسان پروژه</span>
					<select multiple name="cptt_wc_projects[<?php echo esc_attr($i); ?>][expert_user_ids][]" class="cptt-wc-experts-select">
						<?php echo $this->expert_options($experts); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</select>
				</label>
				<label>
					<span>مهلت پروژه پس از خرید</span>
					<input type="number" min="0" name="cptt_wc_projects[<?php echo esc_attr($i); ?>][deadline_days]" value="<?php echo esc_attr($deadline_days); ?>" placeholder="مثلاً 7">
					<small>تعداد روز. صفر یعنی بدون مهلت.</small>
				</label>
			</div>
		</div>
		<?php
	}

	public function save_product_meta($post_id) {
		if (!current_user_can('edit_post', $post_id)) return;

		$rows = isset($_POST['cptt_wc_projects']) && is_array($_POST['cptt_wc_projects']) ? $_POST['cptt_wc_projects'] : [];
		$out = [];
		foreach ($rows as $row) {
			if (!is_array($row)) continue;
			$title = sanitize_text_field($row['title_pattern'] ?? '');
			$template_id = absint($row['template_id'] ?? 0);
			$deadline_days = absint($row['deadline_days'] ?? 0);
			$experts = isset($row['expert_user_ids']) && is_array($row['expert_user_ids']) ? array_map('absint', $row['expert_user_ids']) : [];
			$experts = array_values(array_filter(array_unique($experts)));

			if ($title === '' && !$template_id && empty($experts)) continue;
			if ($title === '') $title = '{product} - سفارش #{order}';

			$out[] = [
				'title_pattern' => $title,
				'template_id' => $template_id,
				'expert_user_ids' => $experts,
				'deadline_days' => $deadline_days,
			];
		}

		update_post_meta($post_id, $this->meta_key, $out);
	}

	private function get_product_configs($product_id, $variation_id = 0) {
		$configs = [];
		if ($variation_id) {
			$configs = get_post_meta($variation_id, $this->meta_key, true);
		}
		if (!is_array($configs) || empty($configs)) {
			$configs = get_post_meta($product_id, $this->meta_key, true);
		}
		return is_array($configs) ? $configs : [];
	}

	private function find_customer_user_id($order) {
		$user_id = (int)$order->get_user_id();
		if ($user_id) return $user_id;
		$email = $order->get_billing_email();
		if ($email) {
			$user = get_user_by('email', $email);
			if ($user) return (int)$user->ID;
		}
		return 0;
	}

	public function maybe_create_projects_for_order($order_id) {
		if (!function_exists('wc_get_order')) return;
		$order = wc_get_order($order_id);
		if (!$order) return;

		$user_id = $this->find_customer_user_id($order);
		if (!$user_id) {
			$order->add_order_note('CPTT: برای ساخت پروژه خودکار، کاربر وردپرس مرتبط با سفارش پیدا نشد.');
			return;
		}

		$created_keys = $order->get_meta('_cptt_created_project_keys', true);
		if (!is_array($created_keys)) $created_keys = [];
		$created_project_ids = $order->get_meta('_cptt_created_project_ids', true);
		if (!is_array($created_project_ids)) $created_project_ids = [];

		foreach ($order->get_items() as $item_id => $item) {
			if (!is_a($item, 'WC_Order_Item_Product')) continue;
			$product_id = (int)$item->get_product_id();
			$variation_id = (int)$item->get_variation_id();
			$configs = $this->get_product_configs($product_id, $variation_id);
			if (empty($configs)) continue;

			foreach ($configs as $idx => $cfg) {
				$key = $item_id . ':' . $idx;
				if (in_array($key, $created_keys, true)) continue;

				$project_id = $this->create_project_from_config($order, $item, $user_id, $cfg);
				if ($project_id) {
					$created_keys[] = $key;
					$created_project_ids[] = $project_id;
					$order->add_order_note(sprintf('CPTT: پروژه #%d برای محصول «%s» ساخته شد.', $project_id, $item->get_name()));
				}
			}
		}

		$order->update_meta_data('_cptt_created_project_keys', array_values(array_unique($created_keys)));
		$order->update_meta_data('_cptt_created_project_ids', array_values(array_unique(array_map('intval', $created_project_ids))));
		$order->save();
	}

	private function create_project_from_config($order, $item, $user_id, $cfg) {
		$title_pattern = sanitize_text_field($cfg['title_pattern'] ?? '{product} - سفارش #{order}');
		$customer = get_user_by('id', $user_id);
		$title = strtr($title_pattern, [
			'{product}' => $item->get_name(),
			'{order}' => (string)$order->get_id(),
			'{customer}' => $customer ? $customer->display_name : '',
			'{site_name}' => get_bloginfo('name'),
		]);
		$title = trim($title) ?: ('پروژه سفارش #' . $order->get_id());

		$project_id = wp_insert_post([
			'post_type' => 'cptt_project',
			'post_status' => 'publish',
			'post_title' => $title,
			'post_author' => 0,
		], true);

		if (is_wp_error($project_id) || !$project_id) return 0;
		$project_id = (int)$project_id;

		$experts = isset($cfg['expert_user_ids']) && is_array($cfg['expert_user_ids']) ? array_map('absint', $cfg['expert_user_ids']) : [];
		$experts = array_values(array_filter(array_unique($experts)));

		update_post_meta($project_id, '_cptt_client_user_id', (int)$user_id);
		update_post_meta($project_id, '_cptt_expert_user_ids', $experts);
		update_post_meta($project_id, '_cptt_expert_user_id', !empty($experts) ? (int)$experts[0] : 0);
		update_post_meta($project_id, '_cptt_experts_csv', ',' . implode(',', $experts) . ',');
		update_post_meta($project_id, '_cptt_wc_order_id', (int)$order->get_id());
		update_post_meta($project_id, '_cptt_wc_product_id', (int)$item->get_product_id());
		update_post_meta($project_id, '_cptt_is_settled', (method_exists($order, 'is_paid') && $order->is_paid()) ? 1 : 0);
		update_post_meta($project_id, '_cptt_product_id', (int)$item->get_product_id());
		$this->sync_project_categories_from_product($project_id, (int)$item->get_product_id());

		$template_id = absint($cfg['template_id'] ?? 0);
		if ($template_id && get_post_type($template_id) === 'cptt_template') {
			$steps = get_post_meta($template_id, '_cptt_template_steps', true);
			$steps = $this->prepare_template_steps_for_project($steps);
			update_post_meta($project_id, '_cptt_steps', $steps);
		}

		$deadline_days = absint($cfg['deadline_days'] ?? 0);
		if ($deadline_days > 0) {
			$deadline = (int)current_time('timestamp', true) + ($deadline_days * DAY_IN_SECONDS);
			update_post_meta($project_id, '_cptt_deadline_at', $deadline);
			update_post_meta($project_id, '_cptt_deadline_at_fa', class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($deadline) : date('Y-m-d H:i', $deadline));
		}

		$now = (int)current_time('timestamp', true);
		update_post_meta($project_id, '_cptt_last_update', $now);
		update_post_meta($project_id, '_cptt_last_update_fa', class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now));

		return $project_id;
	}

	private function sync_project_categories_from_product($project_id, $product_id) {
		$project_id = (int)$project_id;
		$product_id = (int)$product_id;
		if (!$project_id || !$product_id || !taxonomy_exists('product_cat')) return;

		$product_terms = get_the_terms($product_id, 'product_cat');
		if (is_wp_error($product_terms) || empty($product_terms)) return;

		$target_ids = [];
		foreach ($product_terms as $pt) {
			$target_ids[] = (int)$pt->term_id;
		}
		$target_ids = array_values(array_filter(array_unique($target_ids)));

		if (!empty($target_ids)) {
			update_post_meta($project_id, '_cptt_wc_cat_ids', $target_ids);
			update_post_meta($project_id, '_cptt_wc_cats_csv', ',' . implode(',', $target_ids) . ',');
		}
	}

	private function prepare_template_steps_for_project($steps) {
		if (!is_array($steps)) return [];
		$out = [];
		foreach ($steps as $s) {
			if (!is_array($s)) continue;
			$s['id'] = !empty($s['id']) ? sanitize_text_field($s['id']) : (function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : ('st_' . wp_rand(1000,9999)));
			$s['status'] = 'todo';
			unset($s['updated_at'], $s['updated_at_fa'], $s['updated_by']);

			if (!empty($s['checklist']) && is_array($s['checklist'])) {
				foreach ($s['checklist'] as &$ci) {
					$ci['done'] = 0;
					unset($ci['done_at'], $ci['done_at_fa'], $ci['done_by']);
				}
				unset($ci);
			}

			if (!empty($s['user_tasks']) && is_array($s['user_tasks'])) {
				foreach ($s['user_tasks'] as &$ut) {
					$ut['done'] = 0;
					unset($ut['response'], $ut['response_url'], $ut['response_file_url'], $ut['response_file_name'], $ut['response_file_type'], $ut['response_files'], $ut['completed_at'], $ut['completed_at_fa'], $ut['completed_by'], $ut['last_reminder_at'], $ut['last_reminder_at_fa']);
				}
				unset($ut);
			}

			$out[] = $s;
		}
		if (!empty($out)) $out[0]['status'] = 'current';
		return $out;
	}
}
