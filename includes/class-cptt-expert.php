<?php
if ( ! defined('ABSPATH') ) exit;

if (!function_exists('get_expert_mood_emoji')) {
	function get_expert_mood_emoji($user_id) {
		$user_id = (int)$user_id;
		if (!$user_id) return '';
		$today = current_time('Y-m-d');
		if ((string)get_user_meta($user_id, 'pm_last_mood_check', true) !== $today) return '';
		$mood = (int)get_user_meta($user_id, 'pm_current_mood', true);
		$defaults = [1 => '😟', 2 => '😐', 3 => '😄'];
		$custom = get_user_meta($user_id, 'pm_mood_emojis', true);
		if (!is_array($custom)) $custom = [];
		return isset($defaults[$mood]) ? (string)($custom[$mood] ?? $defaults[$mood]) : '';
	}
}

class CPTT_Expert {
	private function get_persian_day_of_week($timestamp) {
		$days = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه', 'شنبه'];
		$w = (int)date('w', $timestamp);
		return $days[$w] ?? '';
	}
	private static $instance = null;
	const QUERY_VAR = 'cptt_expert_dashboard';
	const PUBLIC_QUERY_VAR = 'cptt_experts_hub';
	const REWRITE_OPTION = 'cptt_expert_rewrite_version';
	const SETTINGS_OPTION = 'cptt_expert_public_settings';

	public static function instance() {
		if (self::$instance === null) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_shortcode('cptt_expert_dashboard', [$this, 'shortcode_dashboard']);
		add_shortcode('cptt_experts_hub', [$this, 'shortcode_public_hub']);
		add_action('init', [$this, 'register_rewrite']);
		add_action('init', [$this, 'maybe_flush_rewrite'], 30);
		add_filter('query_vars', [$this, 'query_vars']);
		add_action('template_redirect', [$this, 'maybe_render_virtual_dashboard']);
		add_action('wp_enqueue_scripts', [$this, 'register_assets']);
		add_action('admin_menu', [$this, 'menu']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('show_user_profile', [$this, 'render_expert_profile_fields']);
		add_action('edit_user_profile', [$this, 'render_expert_profile_fields']);
		add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
		add_action('personal_options_update', [$this, 'save_expert_profile_fields']);
		add_action('edit_user_profile_update', [$this, 'save_expert_profile_fields']);
		add_action('wp_ajax_cptt_expert_save_project', [$this, 'ajax_save_project']);
		add_action('wp_ajax_cptt_expert_update_step_status', [$this, 'ajax_update_step_status']);
		add_action('wp_ajax_cptt_expert_save_step_experts', [$this, 'ajax_save_step_experts']);
		add_action('wp_ajax_cptt_expert_create_project', [$this, 'ajax_create_project']);
		add_action('wp_ajax_cptt_expert_create_customer', [$this, 'ajax_create_customer']);
		add_action('wp_ajax_cptt_expert_send_message', [$this, 'ajax_send_message']);
		add_action('wp_ajax_cptt_expert_fetch_messages', [$this, 'ajax_fetch_messages']);
		add_action('wp_ajax_cptt_expert_fetch_notifications', [$this, 'ajax_fetch_notifications']);
		add_action('wp_ajax_cptt_expert_mark_notifications_read', [$this, 'ajax_mark_notifications_read']);
		add_action('wp_ajax_cptt_expert_mark_single_notification_read', [$this, 'ajax_mark_single_notification_read']);
		add_action('wp_ajax_cptt_expert_delete_notification', [$this, 'ajax_delete_notification']);
		add_action('wp_ajax_cptt_expert_fetch_all_notifications', [$this, 'ajax_fetch_all_notifications']);

		add_action('wp_ajax_cptt_expert_send_direct_message', [$this, 'ajax_send_direct_message']);
		add_action('wp_ajax_cptt_expert_fetch_direct_messages', [$this, 'ajax_fetch_direct_messages']);
		add_action('wp_ajax_cptt_expert_get_expert_info', [$this, 'ajax_get_expert_info']);

		add_action('wp_ajax_cptt_expert_delete_project', [$this, 'ajax_delete_project']);
		add_action('wp_ajax_cptt_expert_delete_step', [$this, 'ajax_delete_step']);
		add_action('wp_ajax_cptt_expert_save_profile', [$this, 'ajax_save_own_profile']);
		add_action('wp_ajax_cptt_expert_upload_avatar', [$this, 'ajax_upload_avatar']);
		add_action('wp_ajax_cptt_expert_save_mood', [$this, 'ajax_save_mood']);
		add_action('wp_ajax_cptt_expert_save_theme', [$this, 'ajax_save_theme']);
		add_action('admin_init', [$this, 'redirect_experts_from_admin'], 1);
		add_filter('body_class', [$this, 'add_isolation_body_class']);
	}

	public static function dashboard_url() {
		return add_query_arg(self::QUERY_VAR, 1, home_url('/'));
	}

	public static function public_hub_url() {
		return add_query_arg(self::PUBLIC_QUERY_VAR, 1, home_url('/'));
	}

	public function register_rewrite() {
		add_rewrite_rule('^cptt-expert-dashboard/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
		add_rewrite_rule('^cptt-experts/?$', 'index.php?' . self::PUBLIC_QUERY_VAR . '=1', 'top');
	}

	public function maybe_flush_rewrite() {
		if (get_option(self::REWRITE_OPTION) === CPTT_VERSION) return;
		flush_rewrite_rules(false);
		update_option(self::REWRITE_OPTION, CPTT_VERSION, false);
	}

	public function query_vars($vars) {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::PUBLIC_QUERY_VAR;
		return $vars;
	}

	private function current_user_is_expert_only() {
		$user = wp_get_current_user();
		$roles = ($user instanceof WP_User) ? (array)$user->roles : [];
		return in_array('cptt_expert', $roles, true) && !in_array('administrator', $roles, true);
	}

	private function current_user_can_view_dashboard() {
		if (!is_user_logged_in()) return false;
		$user = wp_get_current_user();
		$roles = ($user instanceof WP_User) ? (array)$user->roles : [];
		return in_array('cptt_expert', $roles, true) || in_array('administrator', $roles, true);
	}

	public function redirect_experts_from_admin() {
		if (!is_admin()) return;
		if (!is_user_logged_in()) return;
		if (!$this->current_user_is_expert_only()) return;
		if (wp_doing_ajax()) return;
		$script = isset($_SERVER['PHP_SELF']) ? basename((string)$_SERVER['PHP_SELF']) : '';
		if (in_array($script, ['admin-ajax.php', 'admin-post.php', 'async-upload.php'], true)) return;
		wp_safe_redirect(self::dashboard_url());
		exit;
	}

	public function register_assets() {
		wp_register_script('cptt-expert', CPTT_URL . 'assets/js/expert.js', [], CPTT_VERSION, true);
		wp_localize_script('cptt-expert', 'CPTT_CURRENCY', class_exists('CPTT_Currency') ? ['unit'=>CPTT_Currency::current_unit(), 'label'=>CPTT_Currency::label(), 'factor'=>CPTT_Currency::from_base(1), 'decimals'=>(int)CPTT_Currency::get_settings()['decimals']] : ['unit'=>'toman','label'=>'تومان','factor'=>1,'decimals'=>0]);
		wp_localize_script('cptt-expert', 'CPTT_EXPERT', [
			'ajax' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('cptt_expert_nonce'),
			'dashboardUrl' => self::dashboard_url(),
			'publicHubUrl' => self::public_hub_url(),
			'dashboardLoginUrl' => wp_login_url(self::dashboard_url()),
			'wpUserId' => get_current_user_id(),
			'userTheme' => is_user_logged_in() ? (string)get_user_meta(get_current_user_id(), 'cptt_expert_dashboard_theme', true) : '',
			'texts' => [
				'saving' => 'در حال ذخیره...',
				'saved' => 'تغییرات با موفقیت ذخیره شد.',
				'error' => 'خطا در ذخیره اطلاعات',
			],
		]);
	}

	private function enqueue_assets() {
		if (!wp_style_is('cptt-frontend', 'registered')) {
			wp_register_style('cptt-frontend', CPTT_URL . 'assets/css/frontend.css', [], CPTT_VERSION);
		}
		if (!wp_style_is('cptt-expert-css', 'registered')) {
			wp_register_style('cptt-expert-css', CPTT_URL . 'assets/css/expert.css', ['cptt-frontend'], CPTT_VERSION);
		}
		if (!wp_script_is('cptt-frontend', 'registered')) {
			wp_register_script('cptt-frontend', CPTT_URL . 'assets/js/frontend.js', [], CPTT_VERSION, true);
			wp_localize_script('cptt-frontend', 'CPTT_FRONTEND', [
				'ajax' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('cptt_frontend_nonce'),
			]);
		}
		if (!wp_script_is('cptt-expert', 'registered')) {
			$this->register_assets();
		}
		wp_enqueue_style('cptt-frontend');
		wp_enqueue_style('cptt-expert-css');
		wp_enqueue_script('cptt-frontend');
		wp_enqueue_script('cptt-expert');
	}

	public function maybe_render_virtual_dashboard() {
		if (get_query_var(self::PUBLIC_QUERY_VAR)) {
			$this->enqueue_assets();
			status_header(200);
			nocache_headers();
			?><!doctype html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class('cptt-expert-hub-page'); ?>>
	<?php if (function_exists('wp_body_open')) wp_body_open(); ?>
	<div class="cptt-shell-page cptt-shell-page--hub">
		<?php echo $this->shortcode_public_hub([]); ?>
	</div>
	<?php wp_footer(); ?>
</body>
</html><?php
		exit;
		}

		if (!get_query_var(self::QUERY_VAR)) return;
		if (!is_user_logged_in()) {
			auth_redirect();
		}
		if (!$this->current_user_can_view_dashboard()) {
			wp_die('شما به داشبورد کارشناس دسترسی ندارید.');
		}

		$this->enqueue_assets();
		status_header(200);
		nocache_headers();
		?><!doctype html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class('cptt-expert-dashboard-page'); ?>>
	<?php if (function_exists('wp_body_open')) wp_body_open(); ?>
	<div class="cptt-shell-page">
		<?php echo $this->shortcode_dashboard([]); ?>
	</div>
	<?php wp_footer(); ?>
</body>
</html><?php
		exit;
	}

	private function get_all_project_users() {
		$users = get_users(['fields' => ['ID','display_name','user_email']]);
		$out = [];
		foreach ($users as $u) {
			$userdata = get_userdata($u->ID);
			$roles = $userdata ? (array)$userdata->roles : [];
			if (user_can($u->ID, 'edit_cptt_projects') || in_array('cptt_expert', $roles, true)) {
				$out[] = $u;
			}
		}
		return $out;
	}

	private function get_user_role_label($user_id) {
		$user = get_user_by('id', $user_id);
		if (!$user || !($user instanceof WP_User)) return '';
		$roles = (array)$user->roles;
		if (in_array('administrator', $roles, true)) return 'مدیر سایت';
		if (in_array('cptt_expert', $roles, true)) return 'کارشناس';
		$map = ['editor'=>'ویرایشگر', 'author'=>'نویسنده', 'shop_manager'=>'مدیر فروشگاه'];
		foreach ($roles as $r) { if (isset($map[$r])) return $map[$r]; }
		return 'کاربر';
	}


	private function get_expert_projects($user_id) {
		$user_id = (int) $user_id;
		$user = get_user_by('id', $user_id);
		if (!$user) return [];
		$args = [
			'post_type' => 'cptt_project',
			'post_status' => 'any',
			'numberposts' => -1,
			'orderby' => 'date',
			'order' => 'DESC',
			'meta_query' => [[
				'key' => '_cptt_experts_csv',
				'value' => ',' . $user_id . ',',
				'compare' => 'LIKE',
			]]
		];
		return get_posts($args);
	}

	private function can_manage_project($project_id, $user_id) {
		$project_id = (int) $project_id;
		$user_id = (int) $user_id;
		if (!$project_id || !$user_id) return false;
		if (user_can($user_id, 'manage_options')) return true;
		if (!class_exists('CPTT_Core') || !method_exists('CPTT_Core', 'get_project_expert_ids')) return false;
		return in_array($user_id, CPTT_Core::get_project_expert_ids($project_id), true);
	}


	private static function get_existing_experts($project_id) {
		if (class_exists('CPTT_Core') && method_exists('CPTT_Core', 'get_project_expert_ids')) return CPTT_Core::get_project_expert_ids($project_id);
		return [];
	}

	private function progress_data($project_id) {
		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps) || empty($steps)) {
			return ['percent' => 0, 'done' => 0, 'total' => 0, 'status' => 'in_progress', 'label' => 'در حال انجام'];
		}
		$total = count($steps);
		$done = 0;
		foreach ($steps as $s) if (($s['status'] ?? '') === 'done') $done++;
		$percent = $total ? (int) round(($done / $total) * 100) : 0;
		$status = ($total > 0 && $done >= $total) ? 'completed' : 'in_progress';
		return ['percent' => $percent, 'done' => $done, 'total' => $total, 'status' => $status, 'label' => $status === 'completed' ? 'تکمیل شده' : 'در حال انجام'];
	}

	private function financial_data($project_id) {
		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps)) $steps = [];
		$cost = 0;
		$paid = 0;
		foreach ($steps as $s) {
			$cost += (float)($s['cost'] ?? 0);
			$paid += (float)($s['paid'] ?? 0);
		}
		$remain = $cost - $paid;
		$percent = $cost > 0 ? round(($paid / $cost) * 100, 1) : 0;
		return ['cost' => $cost, 'paid' => $paid, 'remain' => $remain, 'percent' => $percent];
	}

	private function get_product_title($product_id) {
		$product_id = (int) $product_id;
		if (!$product_id) return '—';
		$title = get_the_title($product_id);
		return $title ? $title : ('#' . $product_id);
	}

	private function get_customer_name($project_id) {
		$uid = (int) get_post_meta($project_id, '_cptt_client_user_id', true);
		$u = $uid ? get_user_by('id', $uid) : null;
		return $u ? $u->display_name : '—';
	}

	private function get_expert_names($project_id) {
		$names = [];
		if (class_exists('CPTT_Core') && method_exists('CPTT_Core', 'get_project_expert_ids')) {
			foreach (CPTT_Core::get_project_expert_ids($project_id) as $id) {
				$u = get_user_by('id', (int)$id);
				if ($u) $names[] = $u->display_name;
			}
		}
		return array_values(array_unique(array_filter($names)));
	}

	private function get_recent_notes($project_id, $limit = 4) {
		$notes = get_post_meta($project_id, '_cptt_project_notes', true);
		if (!is_array($notes)) return [];
		$notes = array_reverse($notes);
		return array_slice($notes, 0, $limit);
	}

	private function collect_dashboard_stats($projects) {
		$total = count($projects);
		$completed = 0;
		$in_progress = 0;
		$today = [];
		$overdue = [];
		$customer_pending = 0;
		$open_steps = 0;
		$today_start = strtotime('today midnight');
		$today_end = strtotime('tomorrow midnight') - 1;
		$now = (int) current_time('timestamp', true);

		foreach ($projects as $p) {
			$progress = $this->progress_data($p->ID);
			if ($progress['status'] === 'completed') $completed++; else $in_progress++;
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (!is_array($steps)) $steps = [];
			foreach ($steps as $step) {
				if (($step['status'] ?? 'todo') !== 'done') $open_steps++;
				$due = (int)($step['due_at'] ?? 0);
				if ($due && ($step['status'] ?? 'todo') !== 'done') {
					$item = [
						'project_id' => $p->ID,
						'project_title' => get_the_title($p->ID),
						'step_title' => (string)($step['title'] ?? ''),
						'due_fa' => (string)($step['due_at_fa'] ?? ''),
					];
					if ($due >= $today_start && $due <= $today_end) $today[] = $item;
					elseif ($due < $now) $overdue[] = $item;
				}
				if (!empty($step['user_tasks']) && is_array($step['user_tasks'])) {
					foreach ($step['user_tasks'] as $task) {
						if (!is_array($task)) continue;
						if (empty($task['done'])) $customer_pending++;
					}
				}
			}
		}

		return [
			'total' => $total,
			'completed' => $completed,
			'in_progress' => $in_progress,
			'open_steps' => $open_steps,
			'customer_pending' => $customer_pending,
			'today' => $today,
			'overdue' => $overdue,
		];
	}

	private function normalize_expert_posted_steps($raw) {
		$out = [];
		if (!is_array($raw)) return $out;
		foreach ($raw as $step_id => $row) {
			$step_id = sanitize_text_field((string)$step_id);
			if ($step_id === '' || !is_array($row)) continue;
			$status = sanitize_key($row['status'] ?? 'todo');
			if (!in_array($status, ['todo', 'current', 'done'], true)) $status = 'todo';
			$checklist = [];
			if (!empty($row['checklist']) && is_array($row['checklist'])) {
				foreach ($row['checklist'] as $check_id => $item) {
					$check_id = sanitize_text_field((string)$check_id);
					if ($check_id === '') continue;
					if (is_array($item)) {
						$checklist[$check_id] = [
							'done' => !empty($item['done']) ? 1 : 0,
							'text' => sanitize_text_field($item['text'] ?? ''),
							'url' => esc_url_raw($item['url'] ?? ''),
						];
					} else {
						$checklist[$check_id] = ['done' => !empty($item) ? 1 : 0, 'text' => '', 'url' => ''];
					}
				}
			}
			$user_tasks = [];
			if (!empty($row['user_tasks']) && is_array($row['user_tasks'])) {
				foreach ($row['user_tasks'] as $task_id => $task) {
					$task_id = sanitize_text_field((string)$task_id);
					if ($task_id === '' || !is_array($task)) continue;
					$user_tasks[$task_id] = [
						'title' => sanitize_text_field($task['title'] ?? ''),
						'desc' => wp_kses_post($task['desc'] ?? ''),
						'due_at_local' => sanitize_text_field($task['due_at_local'] ?? ''),
						'sms_remind' => !empty($task['sms_remind']) ? 1 : 0,
					];
				}
			}
			$normalized = [
				'status' => $status,
				'title' => sanitize_text_field($row['title'] ?? ''),
				'desc' => wp_kses_post($row['desc'] ?? ''),
				'due_at_local' => sanitize_text_field($row['due_at_local'] ?? ''),
				'cost' => isset($row['cost']) ? (class_exists('CPTT_Currency') ? CPTT_Currency::parse_input($row['cost']) : (float)str_replace(",", "", (string)$row['cost'])) : 0,
				'paid' => isset($row['paid']) ? (class_exists('CPTT_Currency') ? CPTT_Currency::parse_input($row['paid']) : (float)str_replace(",", "", (string)$row['paid'])) : 0,
				'expert_share' => isset($row['expert_share']) ? (float)str_replace(",", "", (string)$row['expert_share']) : 0,
				'expert_paid' => isset($row['expert_paid']) ? (float)str_replace(",", "", (string)$row['expert_paid']) : 0,
				'checklist' => $checklist,
				'user_tasks' => $user_tasks,
			];
			// v5.4.17: فقط در صورتی که assigned_expert_ids واقعاً در فرم set شده باشد، آن را اعمال می‌کنیم
			// در غیر اینصورت در save_expert_project از مقدار قدیمی استفاده می‌شود تا انتخاب کارشناسان مرحله پاک نشود.
			if (isset($row['assigned_expert_ids']) && is_array($row['assigned_expert_ids'])) {
				$normalized['assigned_expert_ids'] = array_values(array_filter(array_unique(array_map('absint', $row['assigned_expert_ids']))));
			}
			if (isset($row['assigned_expert_id'])) {
				$normalized['assigned_expert_id'] = absint($row['assigned_expert_id']);
			}
			$out[$step_id] = $normalized;
		}
		return $out;
	}

	private function apply_checklist_timestamps(&$current_item, $new_done, $old_item, $now, $user_id) {
		if ($new_done) {
			$current_item['done'] = 1;
			if (!empty($old_item['done']) && !empty($old_item['done_at'])) {
				$current_item['done_at'] = (int)$old_item['done_at'];
				$current_item['done_at_fa'] = !empty($old_item['done_at_fa']) ? (string)$old_item['done_at_fa'] : (class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime((int)$old_item['done_at']) : date('Y-m-d H:i', (int)$old_item['done_at']));
				if (!empty($old_item['done_by'])) $current_item['done_by'] = (int)$old_item['done_by'];
			} else {
				$current_item['done_at'] = $now;
				$current_item['done_at_fa'] = class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now);
				$current_item['done_by'] = $user_id;
			}
		} else {
			$current_item['done'] = 0;
			unset($current_item['done_at'], $current_item['done_at_fa'], $current_item['done_by']);
		}
	}

	private function apply_step_status_from_checklist($step, $fallback_status = 'todo') {
		$checklist = isset($step['checklist']) && is_array($step['checklist']) ? $step['checklist'] : [];
		$total = 0;
		$done = 0;
		foreach ($checklist as $it) {
			if (!is_array($it) || empty($it['text'])) continue;
			$total++;
			if (!empty($it['done'])) $done++;
		}
		if ($total > 0) {
			if ($done >= $total) return 'done';
			return $fallback_status === 'done' ? 'current' : $fallback_status;
		}
		return $fallback_status;
	}

	private function save_expert_project($project_id, $posted_steps, $note, $meta = []) {
		$old_experts = self::get_existing_experts($project_id);

		$project_id = (int) $project_id;
		$user_id = (int) get_current_user_id();
		$old_steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($old_steps)) $old_steps = [];
		$now = (int) current_time('timestamp', true);
		$changed = false;

		$project_title = sanitize_text_field($meta['project_title'] ?? '');
		if ($project_title !== '' && $project_title !== get_the_title($project_id)) {
			wp_update_post(['ID' => $project_id, 'post_title' => $project_title]);
			$changed = true;
		}
		$client_id = isset($meta['client_user_id']) ? absint($meta['client_user_id']) : (int)get_post_meta($project_id, '_cptt_client_user_id', true);
		$product_id = isset($meta['product_id']) ? absint($meta['product_id']) : (int)get_post_meta($project_id, '_cptt_product_id', true);
		$product_ids = isset($meta['product_ids']) && is_array($meta['product_ids']) ? array_values(array_filter(array_unique(array_map('absint', $meta['product_ids'])))) : [];
		if ($product_id) array_unshift($product_ids, $product_id);
		$product_ids = array_values(array_filter(array_unique($product_ids)));
		if (!$product_id && !empty($product_ids)) $product_id = (int)$product_ids[0];
		$is_settled = !empty($meta['is_settled']) ? 1 : 0;
		$expert_ids = isset($meta['expert_user_ids']) && is_array($meta['expert_user_ids']) ? array_values(array_filter(array_unique(array_map('absint', $meta['expert_user_ids'])))) : self::get_existing_experts($project_id);
		
		$wc_cat_ids = []; if (isset($meta['wc_cat_id'])) $wc_cat_ids[] = absint($meta['wc_cat_id']); if (isset($meta['wc_cat_ids']) && is_array($meta['wc_cat_ids'])) $wc_cat_ids = array_merge($wc_cat_ids, (array)$meta['wc_cat_ids']);
		$cat_ids = array_values(array_filter(array_unique(array_map('intval', $wc_cat_ids))));

		if (class_exists('CPTT_Core')) CPTT_Core::get_project_code($project_id);
		update_post_meta($project_id, '_cptt_client_user_id', $client_id);
		update_post_meta($project_id, '_cptt_product_id', $product_id);
		update_post_meta($project_id, '_cptt_product_ids', $product_ids);
		update_post_meta($project_id, '_cptt_wc_product_ids_csv', ',' . implode(',', $product_ids) . ',');
		
		$added_experts = array_diff($expert_ids, $old_experts);
		foreach($added_experts as $ae_id) {
			if ((int)$ae_id !== (int)$user_id) {
				$this->insert_notification($ae_id, 'project_assigned', 'شما به پروژه ' . get_the_title($project_id) . ' اضافه شدید.', $project_id, self::dashboard_url() . "#project-" . $project_id);
			}
		}
		$removed_experts = array_diff($old_experts, $expert_ids);
		foreach($removed_experts as $re_id) {
			if ((int)$re_id !== (int)$user_id) {
				$this->insert_notification($re_id, 'project_removed', 'شما از پروژه ' . get_the_title($project_id) . ' حذف شدید.', $project_id, self::dashboard_url() . "#project-" . $project_id);
			}
		}
		update_post_meta($project_id, '_cptt_expert_user_ids', $expert_ids);
		update_post_meta($project_id, '_cptt_expert_user_id', !empty($expert_ids) ? (int)$expert_ids[0] : 0);
		update_post_meta($project_id, '_cptt_experts_csv', ',' . implode(',', $expert_ids) . ',');
        
		update_post_meta($project_id, '_cptt_wc_cat_ids', $cat_ids);
		update_post_meta($project_id, '_cptt_wc_cats_csv', ',' . implode(',', $cat_ids) . ',');
		$changed = true;
		if ($product_id) {
			update_post_meta($project_id, '_cptt_wc_product_id', $product_id);
		}
		$deadline_local = trim((string)($meta['deadline_local'] ?? ''));
		if (isset($meta['project_label_id'])) update_post_meta($project_id, '_cptt_project_label_id', sanitize_key((string)$meta['project_label_id']));
		if (class_exists('CPTT_Core')) CPTT_Core::get_project_code($project_id);

		if ($deadline_local !== '' && class_exists('CPTT_Core') && method_exists('CPTT_Core', 'parse_jalali_datetime')) {
			$deadline = (int) CPTT_Core::parse_jalali_datetime($deadline_local);
			if ($deadline) {
				update_post_meta($project_id, '_cptt_deadline_at', $deadline);
				update_post_meta($project_id, '_cptt_deadline_at_fa', CPTT_Core::jalali_datetime($deadline));
			}
		} else {
			delete_post_meta($project_id, '_cptt_deadline_at');
			delete_post_meta($project_id, '_cptt_deadline_at_fa');
			delete_post_meta($project_id, '_cptt_deadline_sms_sent');
		}

		$new_steps = [];
		if (isset($meta['steps']) && is_array($meta['steps'])) {
			foreach ($meta['steps'] as $posted_id => $row) {
				$posted_id = sanitize_text_field((string)$posted_id);
				if ($posted_id === '' || !is_array($row)) continue;

				$old_step = null;
				foreach ($old_steps as $old_idx => $os) {
					$os_id = !empty($os['id']) ? (string)$os['id'] : ('step_' . $old_idx);
					if ($os_id === $posted_id) {
						$old_step = $os;
						break;
					}
				}

				$status = sanitize_key($row['status'] ?? 'todo');
				if (!in_array($status, ['todo', 'current', 'done'], true)) $status = 'todo';

				$title = sanitize_text_field($row['title'] ?? '');
				$desc = wp_kses_post($row['desc'] ?? '');
				$qty = isset($row['qty']) ? max(0.01, (float)$row['qty']) : (float)($old_step['qty'] ?? 1.0);
				$unit_price = isset($row['unit_price']) ? (float)str_replace(",", "", (string)$row['unit_price']) : (float)($old_step['unit_price'] ?? 0);
				$cost = isset($row['cost']) ? (class_exists('CPTT_Currency') ? CPTT_Currency::parse_input($row['cost']) : (float)str_replace(",", "", (string)$row['cost'])) : ($unit_price * $qty);
				$paid = isset($row['paid']) ? (class_exists('CPTT_Currency') ? CPTT_Currency::parse_input($row['paid']) : (float)str_replace(",", "", (string)$row['paid'])) : 0;
				$expert_share = isset($row['expert_share']) ? (float)str_replace(",", "", (string)$row['expert_share']) : 0;
				$expert_paid = isset($row['expert_paid']) ? (float)str_replace(",", "", (string)$row['expert_paid']) : 0;

				// تسویه مالی مرحله دیگر وضعیت اجرایی مرحله را خودکار تغییر نمی‌دهد.

				$checklist = [];
				if (!empty($row['checklist']) && is_array($row['checklist'])) {
					foreach ($row['checklist'] as $check_id => $item) {
						$check_id = sanitize_text_field((string)$check_id);
						if ($check_id === '') continue;

						$p_done = !empty($item['done']) ? 1 : 0;
						$p_text = sanitize_text_field($item['text'] ?? '');
						$p_url = esc_url_raw($item['url'] ?? '');

						$old_check = null;
						if ($old_step && !empty($old_step['checklist']) && is_array($old_step['checklist'])) {
							foreach ($old_step['checklist'] as $oc) {
								$oc_id = !empty($oc['id']) ? (string)$oc['id'] : '';
								if ($oc_id === $check_id) {
									$old_check = $oc;
									break;
								}
							}
						}

						$chk_item = [
							'id' => $check_id,
							'done' => $p_done,
							'text' => $p_text,
							'url' => $p_url,
						];

						if ($p_done) {
							if ($old_check && !empty($old_check['done']) && !empty($old_check['done_at'])) {
								$chk_item['done_at'] = (int)$old_check['done_at'];
								$chk_item['done_at_fa'] = $old_check['done_at_fa'];
								$chk_item['done_by'] = (int)($old_check['done_by'] ?? $user_id);
							} else {
								$chk_item['done_at'] = $now;
								$chk_item['done_at_fa'] = class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now);
								$chk_item['done_by'] = $user_id;
							}
						}

						$checklist[] = $chk_item;
					}
				}

				$user_tasks = [];
				if (!empty($row['user_tasks']) && is_array($row['user_tasks'])) {
					foreach ($row['user_tasks'] as $task_id => $task) {
						$task_id = sanitize_text_field((string)$task_id);
						if ($task_id === '' || !is_array($task)) continue;

						$t_title = sanitize_text_field($task['title'] ?? '');
						$t_desc = wp_kses_post($task['desc'] ?? '');
						$t_sms = !empty($task['sms_remind']) ? 1 : 0;
						$t_due_local = trim((string)($task['due_at_local'] ?? ''));

						$old_ut = null;
						if ($old_step && !empty($old_step['user_tasks']) && is_array($old_step['user_tasks'])) {
							foreach ($old_step['user_tasks'] as $out_task) {
								$out_task_id = !empty($out_task['id']) ? (string)$out_task['id'] : '';
								if ($out_task_id === $task_id) {
									$old_ut = $out_task;
									break;
								}
							}
						}

						$ut_item = [
							'id' => $task_id,
							'title' => $t_title,
							'desc' => $t_desc,
							'sms_remind' => $t_sms,
						];

						if ($t_due_local !== '' && class_exists('CPTT_Core') && method_exists('CPTT_Core', 'parse_jalali_datetime')) {
							$t_due = (int) CPTT_Core::parse_jalali_datetime($t_due_local);
							if ($t_due) {
								$ut_item['due_at'] = $t_due;
								$ut_item['due_at_fa'] = CPTT_Core::jalali_datetime($t_due);
							}
						} elseif ($old_ut && isset($old_ut['due_at'])) {
							$ut_item['due_at'] = $old_ut['due_at'];
							$ut_item['due_at_fa'] = $old_ut['due_at_fa'];
						}

						if ($old_ut) {
							$preserve_keys = [
								'done', 'response', 'response_url', 'response_file_url', 
								'response_file_name', 'response_file_type', 'response_files', 
								'completed_at', 'completed_at_fa', 'completed_by', 
								'last_reminder_at', 'last_reminder_at_fa'
							];
							foreach ($preserve_keys as $pk) {
								if (isset($old_ut[$pk])) {
									$ut_item[$pk] = $old_ut[$pk];
								}
							}
						}

						$user_tasks[] = $ut_item;
					}
				}

				$new_id = $posted_id;
				if (strpos($posted_id, 'step_') === 0 || $posted_id === '') {
					$new_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : ('st_' . wp_rand(1000, 9999));
				}

				$assigned_expert_ids = [];
				if (isset($row['assigned_expert_ids']) && is_array($row['assigned_expert_ids'])) {
					$assigned_expert_ids = array_values(array_filter(array_unique(array_map('absint', $row['assigned_expert_ids']))));
				} elseif ($old_step) {
					$assigned_expert_ids = isset($old_step['assigned_expert_ids']) && is_array($old_step['assigned_expert_ids']) ? array_values(array_filter(array_unique(array_map('absint', $old_step['assigned_expert_ids'])))) : [];
					if (empty($assigned_expert_ids) && !empty($old_step['assigned_expert_id'])) $assigned_expert_ids = [(int)$old_step['assigned_expert_id']];
				}

				$step_data = [
					'id' => $new_id,
					'status' => $status,
					'title' => $title,
					'desc' => $desc,
					'unit_price' => $fee,
					'qty' => $qty,
					'unit_price' => $unit_price,
					'qty' => $qty,
					'cost' => $cost,
					'paid' => $paid,
					'expert_share' => $expert_share,
					'expert_paid' => $expert_paid,
					'checklist' => $checklist,
					'user_tasks' => $user_tasks,
					'assigned_expert_ids' => $assigned_expert_ids,
				];
				$step_data['assigned_expert_id'] = !empty($step_data['assigned_expert_ids']) ? (int)$step_data['assigned_expert_ids'][0] : 0;
				// v5.4.3: حفظ متاهای تسویه‌ی مرحله‌ای (که از صفحه‌ی حساب و کتاب توسط مدیر مدیریت می‌شوند)
				if ($old_step) {
					foreach (['admin_received','step_settled','settle_at','settle_at_fa','settled_by'] as $_pk) {
						if (isset($old_step[$_pk])) $step_data[$_pk] = $old_step[$_pk];
					}
				}

				$due_local = trim((string)($row['due_at_local'] ?? ''));
				if ($due_local !== '' && class_exists('CPTT_Core') && method_exists('CPTT_Core', 'parse_jalali_datetime')) {
					$due = (int) CPTT_Core::parse_jalali_datetime($due_local);
					if ($due) {
						$step_data['due_at'] = $due;
						$step_data['due_at_fa'] = CPTT_Core::jalali_datetime($due);
					}
				}

				$old_status = $old_step ? ($old_step['status'] ?? 'todo') : 'todo';
				if ($old_status !== 'done' && $status === 'done') {
					$this->notify_project_experts($project_id, $user_id, 'step_completed', 'مرحله ' . $title . ' در پروژه ' . get_the_title($project_id) . ' انجام شد.', self::dashboard_url() . "#project-" . $project_id);
				}

				if ($old_step) {
					$step_data['updated_at'] = ($old_status !== $status) ? $now : ($old_step['updated_at'] ?? $now);
					$step_data['updated_at_fa'] = ($old_status !== $status) ? (class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now)) : ($old_step['updated_at_fa'] ?? '');
					$step_data['updated_by'] = ($old_status !== $status) ? $user_id : ($old_step['updated_by'] ?? $user_id);
					foreach (['admin_received','step_settled','settle_at','settle_at_fa','settled_by','expert_settlements'] as $_pk) {
						if (array_key_exists($_pk, $old_step)) $step_data[$_pk] = $old_step[$_pk];
					}
				} else {
					$step_data['updated_at'] = $now;
					$step_data['updated_at_fa'] = class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now);
					$step_data['updated_by'] = $user_id;
				}

				$new_steps[] = $step_data;
			}
		}

		update_post_meta($project_id, '_cptt_steps', $new_steps);
		// v5.4.4: تشخیص تسک‌های جدید مشتری → trigger hook برای ربات بله
		if (is_array($old_steps)) {
			$old_ut_ids = [];
			foreach ($old_steps as $_os) {
				if (!empty($_os['user_tasks']) && is_array($_os['user_tasks'])) {
					foreach ($_os['user_tasks'] as $_ot) if (!empty($_ot['id'])) $old_ut_ids[$_ot['id']] = true;
				}
			}
			foreach ($new_steps as $_ns) {
				$_sid = $_ns['id'] ?? '';
				if (!$_sid || empty($_ns['user_tasks']) || !is_array($_ns['user_tasks'])) continue;
				foreach ($_ns['user_tasks'] as $_nt) {
					$_tid = $_nt['id'] ?? '';
					if ($_tid && empty($old_ut_ids[$_tid]) && empty($_nt['done'])) {
						do_action('cptt_user_task_assigned', $project_id, $_sid, $_tid);
					}
				}
			}
		}
		$fin_data_temp = $this->financial_data($project_id);
		$is_settled_calc = ($fin_data_temp['remain'] <= 0 && $fin_data_temp['cost'] > 0) ? 1 : 0;
		update_post_meta($project_id, '_cptt_is_settled', $is_settled_calc);

		$note = trim((string)$note);
		if ($note !== '') {
			$notes = get_post_meta($project_id, '_cptt_project_notes', true);
			if (!is_array($notes)) $notes = [];
			$notes[] = ['user_id' => $user_id, 'time' => $now, 'content' => sanitize_textarea_field($note)];
			update_post_meta($project_id, '_cptt_project_notes', $notes);
			$changed = true;
			$notifier = get_user_by('id', $user_id);
			$this->notify_project_experts($project_id, $user_id, 'project_note', ($notifier ? $notifier->display_name : 'کارشناس') . ' یادداشتی ثبت کرد.', self::dashboard_url() . "#project-" . $project_id . '#chat-' . $project_id);
		}

		update_post_meta($project_id, '_cptt_last_update', $now);
		update_post_meta($project_id, '_cptt_last_update_fa', class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now));
		if (class_exists('CPTT_SMS') && method_exists('CPTT_SMS', 'maybe_notify_project_completed')) CPTT_SMS::maybe_notify_project_completed($project_id);

		$progress = $this->progress_data($project_id);
		$old_status = get_post_meta($project_id, '_cptt_progress_status_cache', true);
		if ($old_status !== 'completed' && $progress['status'] === 'completed') {
			$this->notify_project_experts($project_id, get_current_user_id(), 'project_completed', 'پروژه ' . get_the_title($project_id) . ' تکمیل شد.', self::dashboard_url() . "#project-" . $project_id);
		}
		update_post_meta($project_id, '_cptt_progress_status_cache', $progress['status']);
		
		return ['changed' => true, 'last_update_fa' => (string)get_post_meta($project_id, '_cptt_last_update_fa', true), 'progress' => $progress,
			'code' => class_exists('CPTT_Core') ? CPTT_Core::get_project_code($project_id) : str_pad((string)$project_id, 5, '0', STR_PAD_LEFT),
			'label' => class_exists('CPTT_Core') ? CPTT_Core::get_project_label($project_id) : null, 'notes' => $this->get_recent_notes($project_id, 4)];
	}

	public function ajax_save_project() {
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		$project_id = isset($_POST['project_id']) ? absint($_POST['project_id']) : 0;
		if (!$project_id || get_post_type($project_id) !== 'cptt_project') wp_send_json_error('invalid_project', 400);
		if (!$this->can_manage_project($project_id, get_current_user_id())) wp_send_json_error('no_access', 403);

		$loaded_last_update = isset($_POST['loaded_last_update']) ? (int)$_POST['loaded_last_update'] : 0;
		$db_last_update = (int)get_post_meta($project_id, '_cptt_last_update', true);
		if ($loaded_last_update && $db_last_update && $loaded_last_update !== $db_last_update) {
			wp_send_json_error('تغییرات شما ذخیره نشد! این پروژه در حین کار شما توسط کارشناس دیگری ویرایش و ذخیره شده است. برای جلوگیری از خراب شدن اطلاعات، لطفا صفحه را مجدداً رفرش کنید تا آخرین تغییرات را ببینید و سپس مجدداً اقدام نمایید.', 409);
		}
		$posted_steps = $this->normalize_expert_posted_steps(isset($_POST['steps']) ? $_POST['steps'] : []);
		$note = isset($_POST['note']) ? wp_unslash((string)$_POST['note']) : '';
		$result = $this->save_expert_project($project_id, $posted_steps, $note, $_POST);
		wp_send_json_success($result);
	}


	public function ajax_update_step_status() {
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		$project_id = isset($_POST['project_id']) ? absint($_POST['project_id']) : 0;
		$step_id = isset($_POST['step_id']) ? sanitize_text_field((string)$_POST['step_id']) : '';
		$status = isset($_POST['status']) ? sanitize_key((string)$_POST['status']) : 'todo';
		if (!$project_id || get_post_type($project_id) !== 'cptt_project') wp_send_json_error('invalid_project', 400);
		if (!$this->can_manage_project($project_id, get_current_user_id())) wp_send_json_error('no_access', 403);
		if ($step_id === '' || !in_array($status, ['todo','current','done'], true)) wp_send_json_error('invalid_status', 400);
		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps)) $steps = [];
		$found = false; $now = (int) current_time('timestamp', true);
		foreach ($steps as &$st) {
			if (!is_array($st)) continue;
			$sid = !empty($st['id']) ? (string)$st['id'] : '';
			if ($sid !== $step_id) continue;
			$old = (string)($st['status'] ?? 'todo');
			$st['status'] = $status;
			if ($old !== $status) {
				$st['updated_at'] = $now;
				$st['updated_at_fa'] = class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now);
				$st['updated_by'] = (int)get_current_user_id();
			}
			$found = true; break;
		}
		unset($st);
		if (!$found) wp_send_json_error('step_not_found', 404);
		update_post_meta($project_id, '_cptt_steps', $steps);
		update_post_meta($project_id, '_cptt_last_update', $now);
		update_post_meta($project_id, '_cptt_last_update_fa', class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now));
		$progress = $this->progress_data($project_id);
		update_post_meta($project_id, '_cptt_progress_status_cache', $progress['status']);
		wp_send_json_success(['progress' => $progress]);
	}

	/**
	 * v5.4.17: ذخیره فوری انتخاب کارشناسان یک مرحله (auto-save)
	 * تا اگر کاربر کل پروژه را Save نکرد، انتخاب مرحله از بین نرود و عکس کنار عنوان مرحله بعد رفرش بماند.
	 */
	public function ajax_save_step_experts() {
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		$project_id = isset($_POST['project_id']) ? absint($_POST['project_id']) : 0;
		$step_id    = isset($_POST['step_id']) ? sanitize_text_field((string)$_POST['step_id']) : '';
		$raw_ids    = isset($_POST['expert_ids']) ? (array)$_POST['expert_ids'] : [];
		$expert_ids = array_values(array_filter(array_unique(array_map('absint', $raw_ids))));

		if (!$project_id || get_post_type($project_id) !== 'cptt_project') wp_send_json_error('invalid_project', 400);
		if (!$this->can_manage_project($project_id, get_current_user_id())) wp_send_json_error('no_access', 403);
		if ($step_id === '') wp_send_json_error('invalid_step', 400);

		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps)) $steps = [];
		$found = false;
		foreach ($steps as &$st) {
			if (!is_array($st)) continue;
			$sid = !empty($st['id']) ? (string)$st['id'] : '';
			if ($sid !== $step_id) continue;
			$st['assigned_expert_ids'] = $expert_ids;
			$st['assigned_expert_id']  = !empty($expert_ids) ? (int)$expert_ids[0] : 0;
			$found = true;
			break;
		}
		unset($st);
		if (!$found) wp_send_json_error('step_not_found', 404);

		update_post_meta($project_id, '_cptt_steps', $steps);
		$now = (int) current_time('timestamp', true);
		update_post_meta($project_id, '_cptt_last_update', $now);
		update_post_meta($project_id, '_cptt_last_update_fa', class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now));

		// آماده کردن لیست avatar برای پاسخ
		$avatars = [];
		foreach ($expert_ids as $eid) {
			$u = get_user_by('id', $eid);
			if (!$u) continue;
			$av = $this->get_expert_avatar_url($eid);
			if (!$av) $av = get_avatar_url($eid);
			$avatars[] = ['id' => $eid, 'name' => $u->display_name, 'avatar' => $av];
		}
		wp_send_json_success(['expert_ids' => $expert_ids, 'avatars' => $avatars, 'last_update' => $now]);
	}

	private function project_card_data($project_id) {
		$product_id = (int) get_post_meta($project_id, '_cptt_product_id', true);
		if (!$product_id) $product_id = (int) get_post_meta($project_id, '_cptt_wc_product_id', true);
		$progress = $this->progress_data($project_id);
		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps)) $steps = [];
		$cat_ids = get_post_meta($project_id, '_cptt_wc_cat_ids', true);
		if (!is_array($cat_ids)) $cat_ids = [];
		$cat_names = [];
		foreach ($cat_ids as $cid) {
			$term = get_term((int)$cid, 'product_cat');
			if ($term && !is_wp_error($term)) $cat_names[] = $term->name;
		}
		$checklist_total = 0;
		$checklist_done = 0;
		$user_tasks_total = 0;
		$user_tasks_done = 0;
		$timeline_steps = [];
		foreach ($steps as $idx => $step) {
			if (is_array($step)) $timeline_steps[] = ['title' => (string)($step['title'] ?? ('مرحله ' . ($idx + 1))), 'status' => (string)($step['status'] ?? 'todo')];
			if (!empty($step['checklist']) && is_array($step['checklist'])) {
				foreach ($step['checklist'] as $it) {
					if (!is_array($it) || empty($it['text'])) continue;
					$checklist_total++;
					if (!empty($it['done'])) $checklist_done++;
				}
			}
			if (!empty($step['user_tasks']) && is_array($step['user_tasks'])) {
				foreach ($step['user_tasks'] as $task) {
					if (!is_array($task) || empty($task['title'])) continue;
					$user_tasks_total++;
					if (!empty($task['done'])) $user_tasks_done++;
				}
			}
		}
		$delivery_method_raw = (string)get_post_meta($project_id, '_cptt_delivery_method', true);
		$delivery_method_label = $delivery_method_raw === 'shipping' ? 'ارسال' : ($delivery_method_raw === 'in_person' ? 'حضوری' : '—');
		return [
			'progress' => $progress,
			'code' => class_exists('CPTT_Core') ? CPTT_Core::get_project_code($project_id) : str_pad((string)$project_id, 5, '0', STR_PAD_LEFT),
			'label' => class_exists('CPTT_Core') ? CPTT_Core::get_project_label($project_id) : null,
			'financial' => $this->financial_data($project_id),
			'customer' => $this->get_customer_name($project_id),
			'customer_id' => (int) get_post_meta($project_id, '_cptt_client_user_id', true),
			'product' => $this->get_product_title($product_id),
			'product_id' => $product_id,
			'term_ids' => $cat_ids,
			'term_names' => $cat_names,
			'experts' => $this->get_expert_names($project_id),
			'deadline' => (string) get_post_meta($project_id, '_cptt_deadline_at_fa', true),
			'last_update' => (string) get_post_meta($project_id, '_cptt_last_update_fa', true),
			'settled' => (int) get_post_meta($project_id, '_cptt_is_settled', true),
			'checklist_total' => $checklist_total,
			'checklist_done' => $checklist_done,
			'user_tasks_total' => $user_tasks_total,
			'user_tasks_done' => $user_tasks_done,
			'notes' => $this->get_recent_notes($project_id, 4),
			'messages' => $this->get_recent_messages($project_id, 8),
			'delivery_method' => $delivery_method_raw,
			'delivery_method_label' => $delivery_method_label,
			'delivery_province' => (string)get_post_meta($project_id, '_cptt_delivery_province', true),
			'delivery_city' => (string)get_post_meta($project_id, '_cptt_delivery_city', true),
			'delivery_address' => (string)get_post_meta($project_id, '_cptt_delivery_address', true),
			'timeline_steps' => $timeline_steps,
		];
	}

	private function render_note_list($notes) {
		if (empty($notes) || !is_array($notes)) {
			echo '<div class="cptt-expert-emptyMini">یادداشتی ثبت نشده است.</div>';
			return;
		}
		echo '<div class="cptt-expert-noteList">';
		foreach ($notes as $note) {
			$user = !empty($note['user_id']) ? get_user_by('id', (int)$note['user_id']) : null;
			$name = $user ? $user->display_name : 'کاربر';
			$time = !empty($note['time']) && class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime((int)$note['time']) : '';
			echo '<div class="cptt-expert-noteItem">';
			echo '<div class="cptt-expert-noteItem__head"><strong>' . esc_html($name) . '</strong><span>' . esc_html($time) . '</span></div>';
			echo '<div class="cptt-expert-noteItem__body">' . nl2br(esc_html((string)($note['content'] ?? ''))) . '</div>';
			echo '</div>';
		}
		echo '</div>';
	}

	private function render_project_manage_form($project_id) {
		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps)) $steps = [];
		$project_title = get_the_title($project_id);
		$client_id = (int)get_post_meta($project_id, '_cptt_client_user_id', true);
		$product_id = (int)get_post_meta($project_id, '_cptt_product_id', true);
		if (!$product_id) $product_id = (int)get_post_meta($project_id, '_cptt_wc_product_id', true);
		$selected_cats = get_post_meta($project_id, '_cptt_wc_cat_ids', true);
		if (!is_array($selected_cats)) $selected_cats = [];
		$experts_curr = self::get_existing_experts($project_id);
		$deadline_at = (int)get_post_meta($project_id, '_cptt_deadline_at', true);
		$deadline_local = $deadline_at && class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($deadline_at) : '';
		$is_settled = (int)get_post_meta($project_id, '_cptt_is_settled', true);
		$customers = $this->customer_users();
		$products = $this->products();
		$cats = $this->category_terms();
		$experts_all = $this->get_all_project_users();
		$project_labels = class_exists('CPTT_Core') ? CPTT_Core::get_project_labels() : [];
		$current_label_id = (string)get_post_meta($project_id, '_cptt_project_label_id', true);
		?>
		<form class="cptt-expert-project-form" data-project-id="<?php echo esc_attr($project_id); ?>">
			<input type="hidden" name="project_id" value="<?php echo esc_attr($project_id); ?>">
			<input type="hidden" name="loaded_last_update" value="<?php echo esc_attr((string)get_post_meta($project_id, '_cptt_last_update', true)); ?>">

			<!-- Project Meta -->
			<div class="cptt-expert-projectMeta">
				<div class="cptt-expert-sectionTitle">اطلاعات پروژه</div>
				<div class="cptt-createProjectGrid">
					<label>
						<span>عنوان پروژه</span>
						<input type="text" name="project_title" value="<?php echo esc_attr($project_title); ?>">
					</label>
					<label>
						<span>مشتری</span>
						<select name="client_user_id">
							<option value="">— انتخاب مشتری —</option>
							<?php foreach ($customers as $u): ?>
								<option value="<?php echo esc_attr($u->ID); ?>" data-search="<?php echo esc_attr($this->customer_option_search($u)); ?>" <?php selected($client_id, $u->ID); ?>>
									<?php echo esc_html($this->customer_option_label($u)); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<span>دسته‌بندی محصول</span>
						<select name="wc_cat_id">
							<option value="">— انتخاب دسته‌بندی —</option>
							<?php foreach ($cats as $cat): ?>
								<option value="<?php echo esc_attr($cat->term_id); ?>" <?php echo in_array((int)$cat->term_id, array_map('intval',$selected_cats), true) ? 'selected' : ''; ?>>
									<?php echo esc_html($cat->name); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<span>محصول مرتبط</span>
						<select name="product_id">
							<option value="">— بدون محصول —</option>
							<?php foreach ($products as $product): ?>
								<option value="<?php echo esc_attr($product->ID); ?>" <?php selected($product_id, $product->ID); ?>>
									<?php echo esc_html(get_the_title($product->ID)); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
					<div style="grid-column: 1 / -1; margin-top: 10px;">
						<span style="font-size: 11px; font-weight: 900; color: #475569; display: block; margin-bottom: 6px;">کارشناسان پروژه</span>
						<div class="cptt-experts-card-list">
							<?php foreach ($experts_all as $u): 
								$is_sel = in_array((int)$u->ID, array_map('intval', (array)$experts_curr), true);
								$u_avatar = $this->get_expert_avatar_url($u->ID);
							?>
							<label class="cptt-expert-card-item">
								<input type="checkbox" name="expert_user_ids[]" value="<?php echo esc_attr($u->ID); ?>" <?php echo $is_sel ? 'checked' : ''; ?>>
								<img src="<?php echo esc_url($u_avatar); ?>" alt="">
								<span><?php echo esc_html($u->display_name); ?></span>
							</label>
							<?php endforeach; ?>
						</div>
					</div>
					<label>
						<span>مهلت پروژه</span>
						<input type="text" class="cptt-jalali-datetime" name="deadline_local" value="<?php echo esc_attr($deadline_local); ?>" placeholder="۱۴۰۳/۰۱/۳۱ ۱۴:۳۰">
					</label>
					<label>
						<span>لیبل پروژه</span>
						<select name="project_label_id"><option value="">— بدون لیبل —</option><?php foreach ($project_labels as $lbl): ?><option value="<?php echo esc_attr($lbl['id']); ?>" <?php selected($current_label_id, $lbl['id']); ?>><?php echo esc_html($lbl['name']); ?></option><?php endforeach; ?></select>
					</label>
				</div>
				<!-- Automatic status calculated from stage financial values -->
			</div>

			<!-- Steps Accordion -->
			<div class="cptt-expert-sectionTitle" style="margin-top:8px;">مراحل پروژه</div>
			<div class="cptt-expert-steps">
				<?php if (empty($steps)): ?>
					<div class="cptt-expert-emptyMini">برای این پروژه هنوز مرحله‌ای ثبت نشده است.</div>
				<?php endif; ?>
				<?php foreach ($steps as $index => $step):
					if (!is_array($step)) continue;
					$step_id = !empty($step['id']) ? (string)$step['id'] : ('step_' . $index);
					$title = (string)($step['title'] ?? ('مرحله ' . ($index + 1)));
					$status = (string)($step['status'] ?? 'todo');
					if (!in_array($status, ['todo', 'current', 'done'], true)) $status = 'todo';
					$done_count = 0; $all_count = 0;
					if (!empty($step['checklist']) && is_array($step['checklist'])) {
						foreach ($step['checklist'] as $it) {
							if (!is_array($it) || empty($it['text'])) continue;
							$all_count++;
							if (!empty($it['done'])) $done_count++;
						}
					}
					$user_tasks = !empty($step['user_tasks']) && is_array($step['user_tasks']) ? $step['user_tasks'] : [];
					$status_label = $status === 'done' ? 'انجام‌شده' : ($status === 'current' ? 'در حال انجام' : 'انجام‌نشده');
					$badge_class = $status === 'done' ? 'done' : ($status === 'current' ? 'current' : 'todo');
					$due_local = !empty($step['due_at_fa']) ? (string)$step['due_at_fa'] : '';
					$is_first = false;
				$toggle_assigned_ids = isset($step['assigned_expert_ids']) && is_array($step['assigned_expert_ids']) ? array_values(array_filter(array_unique(array_map('intval',$step['assigned_expert_ids'])))) : [];
				if (empty($toggle_assigned_ids) && !empty($step['assigned_expert_id'])) $toggle_assigned_ids = [(int)$step['assigned_expert_id']];
				?>
				<div class="cptt-expert-step <?php echo $is_first ? 'is-open' : ''; ?>" data-step-id="<?php echo esc_attr($step_id); ?>" draggable="true">
					<button type="button" class="cptt-expert-step__toggle" aria-expanded="<?php echo $is_first ? 'true' : 'false'; ?>">
						<span class="cptt-step-reorder-handle" title="تغییر ترتیب">⠿</span>
						<div class="cptt-expert-step__toggleMain">
							<strong><?php echo esc_html(($index + 1) . '. ' . $title); ?><?php if (!empty($toggle_assigned_ids)): ?><span class="cptt-step-toggle-avatars"><?php foreach ($toggle_assigned_ids as $taid): $av=$this->get_expert_avatar_url($taid); if(!$av)$av=get_avatar_url($taid); ?><img src="<?php echo esc_url($av); ?>" alt=""><?php endforeach; ?></span><?php endif; ?></strong>
							<span><?php echo esc_html($all_count ? ('چک‌لیست: ' . $done_count . '/' . $all_count) : 'بدون چک‌لیست'); ?><?php echo !empty($user_tasks) ? esc_html(' • تسک مشتری: ' . count($user_tasks)) : ''; ?></span>
						</div>
						<div class="cptt-expert-step__toggleSide">
							<span class="cptt-expert-status cptt-expert-status--<?php echo esc_attr($badge_class); ?>"><?php echo esc_html($status_label); ?></span>
							<span class="cptt-expert-step__chevron">⌄</span>
						</div>
					</button>
					<div class="cptt-expert-step__body" <?php echo $is_first ? '' : 'hidden'; ?>>
						<!-- Step Meta -->
						<div class="cptt-expert-step__metaGrid">
							<label>
								<span>عنوان مرحله</span>
								<input type="text" name="steps[<?php echo esc_attr($step_id); ?>][title]" value="<?php echo esc_attr($title); ?>">
							</label>
							<label>
								<span>وضعیت مرحله</span>
								<select name="steps[<?php echo esc_attr($step_id); ?>][status]">
									<option value="todo" <?php selected($status, 'todo'); ?>>انجام‌نشده</option>
									<option value="current" <?php selected($status, 'current'); ?>>در حال انجام</option>
									<option value="done" <?php selected($status, 'done'); ?>>انجام‌شده</option>
								</select>
							</label>
							<label>
								<span>مهلت مرحله</span>
								<input type="text" class="cptt-jalali-datetime" name="steps[<?php echo esc_attr($step_id); ?>][due_at_local]" value="<?php echo esc_attr($due_local); ?>">
							</label>
							<label>
								<span>تعداد</span>
								<input type="number" step="any" min="0.01" name="steps[<?php echo esc_attr($step_id); ?>][qty]" value="<?php echo esc_attr((float)($step['qty'] ?? 1.0)); ?>">
							</label>
							<input type="hidden" name="steps[<?php echo esc_attr($step_id); ?>][unit_price]" value="<?php echo esc_attr((float)($step['unit_price'] ?? 0)); ?>">
							<label>
								<span>هزینه مرحله (ریال)</span>
								<input type="text" name="steps[<?php echo esc_attr($step_id); ?>][cost]" class="cptt-currency-input" value="<?php echo esc_attr(number_format($step['cost'] ?? 0)); ?>">
							</label>
							<label>
								<span>دریافتی مرحله (ریال)</span>
								<input type="text" name="steps[<?php echo esc_attr($step_id); ?>][paid]" class="cptt-currency-input" value="<?php echo esc_attr(number_format($step['paid'] ?? 0)); ?>">
							</label>
							<?php /* فیلدهای سهم/پرداختی کارشناس از UI حذف شدند (v5.4.3) - تسویه از طریق صفحه‌ی حساب و کتاب انجام می‌شود. مقادیر فعلی به‌صورت hidden حفظ می‌شوند تا با ذخیره فرم پاک نشوند. */ ?>
							<input type="hidden" name="steps[<?php echo esc_attr($step_id); ?>][expert_share]" value="<?php echo esc_attr((float)($step['expert_share'] ?? 0)); ?>">
							<input type="hidden" name="steps[<?php echo esc_attr($step_id); ?>][expert_paid]" value="<?php echo esc_attr((float)($step['expert_paid'] ?? 0)); ?>">
							<?php
							// v5.4.3: نمایش وضعیت تسویه‌ی مرحله برای کارشناس
							$_ep_paid = (float)($step['expert_paid'] ?? 0);
							$_step_settled = !empty($step['step_settled']) ? 1 : 0;
							$_settle_fa = isset($step['settle_at_fa']) ? (string)$step['settle_at_fa'] : '';
							if ($_ep_paid > 0 || $_step_settled): ?>
							<div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:6px; padding:8px 10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; font-size:12px;">
								<span style="color:#475569;">💼 پرداخت به شما برای این مرحله:</span>
								<b style="color:#2563eb;"><?php echo number_format($_ep_paid); ?> تومان</b>
								<?php if ($_step_settled): ?>
									<span style="background:rgba(34,197,94,.12); color:#065f46; border:1px solid rgba(34,197,94,.22); padding:2px 8px; border-radius:8px; font-weight:800;">✓ تسویه نهایی<?php if ($_settle_fa) echo ' — ' . esc_html($_settle_fa); ?></span>
								<?php else: ?>
									<span style="background:rgba(245,158,11,.12); color:#92400e; border:1px solid rgba(245,158,11,.22); padding:2px 8px; border-radius:8px; font-weight:800;">⏳ پرداخت بدون تسویه نهایی</span>
								<?php endif; ?>
							</div>
							<?php endif; ?>
						</div>
						<?php
						$_proj_expert_ids_step = CPTT_Core::get_project_expert_ids($project_id);
						$_assigned_expert_ids_step = isset($step['assigned_expert_ids']) && is_array($step['assigned_expert_ids']) ? array_values(array_filter(array_unique(array_map('intval',$step['assigned_expert_ids'])))) : [];
						if (empty($_assigned_expert_ids_step) && !empty($step['assigned_expert_id'])) $_assigned_expert_ids_step = [(int)$step['assigned_expert_id']];
						$_assigned_names_step = [];
						foreach ($_assigned_expert_ids_step as $_ae_id) { $_ae_u = get_user_by('id', (int)$_ae_id); if ($_ae_u) $_assigned_names_step[] = $_ae_u->display_name; }
						if (count($_proj_expert_ids_step) >= 1): ?>
						<div class="cptt-step-expert-wrap">
							<input type="hidden" class="cptt-step-expert-primary" name="steps[<?php echo esc_attr($step_id); ?>][assigned_expert_id]" value="<?php echo esc_attr(!empty($_assigned_expert_ids_step) ? (string)$_assigned_expert_ids_step[0] : ''); ?>">
							<div class="cptt-step-expert-hidden-list">
								<?php foreach ($_assigned_expert_ids_step as $_ae_id): ?><input type="hidden" name="steps[<?php echo esc_attr($step_id); ?>][assigned_expert_ids][]" value="<?php echo esc_attr($_ae_id); ?>"><?php endforeach; ?>
							</div>
							<button type="button" class="cptt-step-expert-btn <?php echo !empty($_assigned_expert_ids_step) ? 'has-expert' : ''; ?>">
								👥 <?php echo !empty($_assigned_names_step) ? esc_html(implode('، ', $_assigned_names_step)) : 'انتخاب کارشناسان مرحله'; ?>
							</button>
						</div>
						<?php endif; ?>

						<!-- Step Description -->
						<label class="cptt-expert-noteField">
							<span>توضیحات مرحله (نمایش در پاپ‌آپ مشتری)</span>
							<textarea name="steps[<?php echo esc_attr($step_id); ?>][desc]" rows="3"><?php echo esc_textarea((string)($step['desc'] ?? '')); ?></textarea>
						</label>

						<!-- Checklist -->
						<div class="cptt-expert-checklist">
							<div class="cptt-expert-sectionTitle">چک‌لیست داخلی کارشناس</div>
							<div class="cptt-expert-checklist-items">
							<?php if (!empty($step['checklist']) && is_array($step['checklist'])): ?>
								<?php foreach ($step['checklist'] as $item):
									if (!is_array($item) || empty($item['text'])) continue;
									$check_id = !empty($item['id']) ? (string)$item['id'] : ('chk_' . $index . '_' . wp_rand(1000,9999));
									$is_done = !empty($item['done']);
								?>
									<div class="cptt-expert-checkRow <?php echo $is_done ? 'is-done' : ''; ?>">
										<label class="cptt-expert-checkItem">
											<input type="checkbox" name="steps[<?php echo esc_attr($step_id); ?>][checklist][<?php echo esc_attr($check_id); ?>][done]" value="1" <?php checked($is_done); ?>>
											<span>انجام شد</span>
										</label>
										<input type="text" name="steps[<?php echo esc_attr($step_id); ?>][checklist][<?php echo esc_attr($check_id); ?>][text]" value="<?php echo esc_attr((string)($item['text'] ?? '')); ?>" placeholder="متن آیتم">
										<input type="url" name="steps[<?php echo esc_attr($step_id); ?>][checklist][<?php echo esc_attr($check_id); ?>][url]" value="<?php echo esc_attr((string)($item['url'] ?? '')); ?>" placeholder="لینک نتیجه (اختیاری)">
										<button type="button" class="button button-small cptt-expert-remove-checkitem">×</button>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
							</div>
							<button type="button" class="button button-small cptt-expert-add-checkitem" style="margin-top:10px;">+ افزودن آیتم چک‌لیست</button>
						</div>

						<!-- User Tasks -->
						<div class="cptt-expert-userTasks">
							<div class="cptt-expert-sectionTitle">تسک‌های سمت مشتری</div>
							<div class="cptt-expert-usertasks-items">
							<?php if (!empty($user_tasks)): foreach ($user_tasks as $utask):
								if (!is_array($utask) || empty($utask['title'])) continue;
								$task_id = !empty($utask['id']) ? (string)$utask['id'] : ('ut_' . wp_rand(1000,9999));
								$task_due = !empty($utask['due_at_fa']) ? (string)$utask['due_at_fa'] : '';
								$is_task_done = !empty($utask['done']);
							?>
								<div class="cptt-expert-userTask">
									<div class="cptt-expert-userTask__fields">
										<input type="text" name="steps[<?php echo esc_attr($step_id); ?>][user_tasks][<?php echo esc_attr($task_id); ?>][title]" value="<?php echo esc_attr((string)($utask['title'] ?? '')); ?>" placeholder="عنوان تسک">
										<textarea name="steps[<?php echo esc_attr($step_id); ?>][user_tasks][<?php echo esc_attr($task_id); ?>][desc]" rows="2" placeholder="توضیحات تسک"><?php echo esc_textarea((string)($utask['desc'] ?? '')); ?></textarea>
										<input type="text" class="cptt-jalali-datetime" name="steps[<?php echo esc_attr($step_id); ?>][user_tasks][<?php echo esc_attr($task_id); ?>][due_at_local]" value="<?php echo esc_attr($task_due); ?>" placeholder="مهلت">
										<button type="button" class="button button-small cptt-expert-remove-usertask">×</button>
									</div>
								</div>
							<?php endforeach; endif; ?>
							</div>
							<button type="button" class="button button-small cptt-expert-add-usertask" style="margin-top:10px;">+ افزودن تسک مشتری</button>
						</div>
						
						<div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
							<button type="button" class="cptt-btn cptt-btn--danger cptt-expert-remove-step" style="flex:1;">× حذف مرحله</button>
						</div>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
            <button type="button" class="cptt-btn cptt-expert-add-step" style="margin: 20px 0; width:100%; background:#f1f5f9; color:#334155; border:2px dashed #cbd5e1; padding:12px; border-radius:12px;">+ افزودن مرحله جدید</button>

			<!-- Delivery Section in manage form -->
			<?php
			$mgmt_delivery_method = (string)get_post_meta($project_id, '_cptt_delivery_method', true);
			$mgmt_delivery_province = (string)get_post_meta($project_id, '_cptt_delivery_province', true);
			$mgmt_delivery_city = (string)get_post_meta($project_id, '_cptt_delivery_city', true);
			$mgmt_delivery_address = (string)get_post_meta($project_id, '_cptt_delivery_address', true);
			?>
			<div class="cptt-expert-sectionTitle" style="margin-top:16px;">روش تحویل و آدرس ارسال</div>
			<div class="cptt-createProjectGrid cptt-delivery-section" data-project-id="<?php echo esc_attr($project_id); ?>">
				<label>
					<span>روش تحویل</span>
					<select name="delivery_method" class="cptt-manage-delivery-method" style="width:100%;">
						<option value="in_person" <?php selected($mgmt_delivery_method, 'in_person'); ?>>حضوری</option>
						<option value="shipping" <?php selected($mgmt_delivery_method, 'shipping'); ?>>ارسال</option>
					</select>
				</label>
				<label class="cptt-manage-province-wrap" style="<?php echo $mgmt_delivery_method === 'shipping' ? '' : 'display:none;'; ?>">
					<span>استان</span>
					<select name="delivery_province" class="cptt-manage-province" style="width:100%;">
						<option value="">— انتخاب استان —</option>
						<?php
						$all_provinces = ['تهران','البرز','اصفهان','آذربایجان شرقی','آذربایجان غربی','اردبیل','بوشهر','چهارمحال و بختیاری','فارس','گیلان','گلستان','همدان','هرمزگان','ایلام','کرمان','کرمانشاه','خراسان شمالی','خراسان رضوی','خراسان جنوبی','خوزستان','کهگیلویه و بویراحمد','کردستان','لرستان','مازندران','مرکزی','قم','قزوین','سمنان','سیستان و بلوچستان','خراسان','زنجان','یزد'];
						sort($all_provinces);
						foreach ($all_provinces as $prov):
						?>
						<option value="<?php echo esc_attr($prov); ?>" <?php selected($mgmt_delivery_province, $prov); ?>><?php echo esc_html($prov); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="cptt-manage-city-wrap" style="<?php echo ($mgmt_delivery_method === 'shipping' && $mgmt_delivery_province) ? '' : 'display:none;'; ?>">
					<span>شهر</span>
					<select name="delivery_city" class="cptt-manage-city" style="width:100%;">
						<option value="">— انتخاب شهر —</option>
						<?php if ($mgmt_delivery_province): ?>
						<option value="<?php echo esc_attr($mgmt_delivery_city); ?>" selected><?php echo esc_html($mgmt_delivery_city); ?></option>
						<?php endif; ?>
					</select>
				</label>
				<label class="cptt-manage-address-wrap" style="grid-column:1/-1; <?php echo ($mgmt_delivery_method === 'shipping' && $mgmt_delivery_city) ? '' : 'display:none;'; ?>">
					<span>آدرس تکمیلی</span>
					<textarea name="delivery_address" rows="2" placeholder="خیابان، کوچه، پلاک، واحد..."><?php echo esc_textarea($mgmt_delivery_address); ?></textarea>
				</label>
			</div>

			<!-- Financial Summary in manage form -->
			<?php $mgmt_fin = $this->financial_data($project_id); ?>
			<div class="cptt-expert-sectionTitle" style="margin-top:16px;">خلاصه حساب و کتاب پروژه</div>
			<div class="cptt-createProjectGrid cptt-manage-finance-summary">
				<div class="cptt-manage-fin-box">
					<span>جمع هزینه‌ها (ریال)</span>
					<strong class="cptt-manage-fin-cost"><?php echo esc_html(number_format((int)$mgmt_fin['cost'])); ?></strong>
				</div>
				<div class="cptt-manage-fin-box">
					<span>دریافت‌شده (ریال)</span>
					<strong class="cptt-manage-fin-paid" style="color:#059669;"><?php echo esc_html(number_format((int)$mgmt_fin['paid'])); ?></strong>
				</div>
				<div class="cptt-manage-fin-box">
					<span>مانده (ریال)</span>
					<strong class="cptt-manage-fin-remain" style="color:<?php echo $mgmt_fin['remain'] > 0 ? '#dc2626' : '#059669'; ?>"><?php echo esc_html(number_format((int)$mgmt_fin['remain'])); ?></strong>
				</div>
				<div class="cptt-manage-fin-box">
					<span>درصد پرداخت</span>
					<strong><?php echo esc_html($mgmt_fin['percent']); ?>%</strong>
				</div>
			</div>

			<!-- Form Footer -->
			<div class="cptt-expert-formFooter">
				<label class="cptt-expert-noteField">
					<span>یادداشت داخلی برای تیم</span>
					<textarea name="note" rows="3" placeholder="اگر لازم است توضیح یا گزارش کوتاه ثبت کنید..."></textarea>
				</label>
				<div class="cptt-expert-formActions">
					<button type="submit" class="cptt-btn cptt-btn--primary cptt-expert-save-floating">ذخیره تغییرات</button>
					<div class="cptt-expert-formMsg" aria-live="polite"></div>
				</div>
			</div>
		</form>
		<?php
	}

	public static function get_public_settings() {
		$defaults = [
			'visibility_mode' => 'public_limited',
			'page_title' => 'تیم کارشناسان و پروژه‌های فعال',
			'page_text' => 'قبل از ورود به پنل، با کارشناسان مجموعه آشنا شوید و وضعیت پروژه‌های در حال انجام را در یک نمای حرفه‌ای و فقط‌خواندنی ببینید.',
		];
		$opt = get_option(self::SETTINGS_OPTION, []);
		if (!is_array($opt)) $opt = [];
		$opt = array_merge($defaults, $opt);
		if (!in_array($opt['visibility_mode'], ['public_all', 'public_limited', 'login_only'], true)) $opt['visibility_mode'] = 'public_limited';
		return $opt;
	}

	public function menu() {
		add_submenu_page('edit.php?post_type=cptt_project', 'تنظیمات ویترین کارشناسان', 'ویترین کارشناسان', 'manage_options', 'cptt-experts-hub-settings', [$this, 'render_settings_page']);
		add_submenu_page('edit.php?post_type=cptt_project', 'افزودن کارشناس', 'افزودن کارشناس', 'manage_options', 'cptt-experts-manage', [$this, 'render_experts_manage_page']);
	}

	public function register_settings() {
		register_setting('cptt_expert_public_group', self::SETTINGS_OPTION, [$this, 'sanitize_public_settings']);
	}

	public function admin_assets($hook) {
		if ($hook !== 'cptt_project_page_cptt-experts-manage' && $hook !== 'cptt_project_page_cptt-experts-hub-settings') return;
		wp_enqueue_media();
		wp_enqueue_style('cptt-admin', CPTT_URL . 'assets/css/admin.css', [], CPTT_VERSION);
	}

	public function sanitize_public_settings($input) {
		if (!is_array($input)) $input = [];
		$mode = sanitize_key($input['visibility_mode'] ?? 'public_limited');
		if (!in_array($mode, ['public_all', 'public_limited', 'login_only'], true)) $mode = 'public_limited';
		return [
			'visibility_mode' => $mode,
			'page_title' => sanitize_text_field($input['page_title'] ?? ''),
			'page_text' => sanitize_textarea_field($input['page_text'] ?? ''),
		];
	}

	public function render_settings_page() {
		if (!current_user_can('manage_options')) return;
		$opt = self::get_public_settings();
		?>
		<div class="wrap" dir="rtl">
			<h1>تنظیمات ویترین کارشناسان</h1>
			<p>این تنظیمات برای صفحه معرفی کارشناسان و نمایش پروژه‌های ناقص قبل از ورود به پنل استفاده می‌شود.</p>
			<form method="post" action="options.php">
				<?php settings_fields('cptt_expert_public_group'); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">نحوه نمایش پروژه‌ها</th>
						<td>
							<select name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[visibility_mode]">
								<option value="public_all" <?php selected($opt['visibility_mode'], 'public_all'); ?>>عمومی کامل</option>
								<option value="public_limited" <?php selected($opt['visibility_mode'], 'public_limited'); ?>>عمومی محدود</option>
								<option value="login_only" <?php selected($opt['visibility_mode'], 'login_only'); ?>>فقط پس از ورود</option>
							</select>
							<p class="description">عمومی کامل: کارت‌ها و جزئیات فقط‌خواندنی برای همه. عمومی محدود: فقط اطلاعات خلاصه و جزئیات محدود. فقط پس از ورود: پروژه‌ها فقط برای کارشناس/مدیر لاگین‌شده.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">عنوان هدر</th>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[page_title]" value="<?php echo esc_attr($opt['page_title']); ?>"></td>
					</tr>
					<tr>
						<th scope="row">متن معرفی</th>
						<td><textarea class="large-text" rows="4" name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[page_text]"><?php echo esc_textarea($opt['page_text']); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row">آدرس‌ها</th>
						<td>
							<p><strong>مسیر اختصاصی پلاگین:</strong> <code><?php echo esc_html(self::public_hub_url()); ?></code></p>
							<p><strong>شورتکد:</strong> <code>[cptt_experts_hub]</code></p>
						</td>
					</tr>
				</table>
				<?php submit_button('ذخیره تنظیمات ویترین'); ?>
		</form>
		<?php
	}

	private function is_expert_profile_supported($user) {
		if (!$user || !($user instanceof WP_User)) return false;
		$roles = ($user instanceof WP_User) ? (array)$user->roles : [];
		return in_array('cptt_expert', $roles, true) || in_array('administrator', $roles, true);
	}

	public function render_expert_profile_fields($user) {
		if (!$this->is_expert_profile_supported($user)) return;
		$title = get_user_meta($user->ID, 'cptt_expert_title', true);
		$bio = get_user_meta($user->ID, 'cptt_expert_short_bio', true);
		$specialties = get_user_meta($user->ID, 'cptt_expert_specialties', true);
		$mood_emojis = $this->get_mood_emojis($user->ID);
		?>
		<h2>اطلاعات ویترین کارشناس</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="cptt_expert_title">سمت / عنوان</label></th>
				<td><input type="text" id="cptt_expert_title" name="cptt_expert_title" value="<?php echo esc_attr($title); ?>" class="regular-text"><p class="description">مثلاً کارشناس فروش، مشاور مهاجرت، مدیر پروژه</p></td>
			</tr>
			<tr>
				<th><label for="cptt_expert_short_bio">بیو کوتاه</label></th>
				<td><textarea id="cptt_expert_short_bio" name="cptt_expert_short_bio" rows="4" class="large-text"><?php echo esc_textarea($bio); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="cptt_expert_specialties">تخصص‌ها</label></th>
				<td><input type="text" id="cptt_expert_specialties" name="cptt_expert_specialties" value="<?php echo esc_attr($specialties); ?>" class="regular-text"><p class="description">با ویرگول جدا کنید. مثال: طراحی سایت، سئو، پشتیبانی</p></td>
			</tr>
			<tr>
				<th>ایموجی وضعیت روزانه</th>
				<td><input type="text" name="pm_mood_emoji_1" value="<?php echo esc_attr($mood_emojis[1]); ?>" style="width:60px;text-align:center"> بد &nbsp; <input type="text" name="pm_mood_emoji_2" value="<?php echo esc_attr($mood_emojis[2]); ?>" style="width:60px;text-align:center"> معمولی &nbsp; <input type="text" name="pm_mood_emoji_3" value="<?php echo esc_attr($mood_emojis[3]); ?>" style="width:60px;text-align:center"> عالی</td>
			</tr>
		</table>
		<input type="hidden" name="cptt_expert_profile_fields_present" value="1">
		<?php
	}

	public function save_expert_profile_fields($user_id) {
		if (!current_user_can('edit_user', $user_id)) return;
		if (empty($_POST['cptt_expert_profile_fields_present'])) return;
		update_user_meta($user_id, 'cptt_expert_title', sanitize_text_field($_POST['cptt_expert_title'] ?? ''));
		update_user_meta($user_id, 'cptt_expert_short_bio', sanitize_textarea_field($_POST['cptt_expert_short_bio'] ?? ''));
		update_user_meta($user_id, 'cptt_expert_specialties', sanitize_text_field($_POST['cptt_expert_specialties'] ?? ''));
		if (isset($_POST['cptt_expert_avatar_id'])) update_user_meta($user_id, 'cptt_expert_avatar_id', absint($_POST['cptt_expert_avatar_id']));
		update_user_meta($user_id, 'pm_mood_emojis', [1 => sanitize_text_field($_POST['pm_mood_emoji_1'] ?? '😟'), 2 => sanitize_text_field($_POST['pm_mood_emoji_2'] ?? '😐'), 3 => sanitize_text_field($_POST['pm_mood_emoji_3'] ?? '😄')]);
	}

	private function get_expert_avatar_url($user_id, $size = 160) {
		$avatar_id = (int) get_user_meta($user_id, 'cptt_expert_avatar_id', true);
		if ($avatar_id) {
			$url = wp_get_attachment_image_url($avatar_id, $size >= 120 ? 'medium' : 'thumbnail');
			if ($url) return $url;
		}
		return get_avatar_url($user_id, ['size' => $size]);
	}

	private function get_expert_avatar_markup($user_id, $size = 80, $class = '') {
		$avatar_id = (int) get_user_meta($user_id, 'cptt_expert_avatar_id', true);
		if ($avatar_id) {
			return wp_get_attachment_image($avatar_id, $size >= 120 ? 'medium' : 'thumbnail', false, ['class' => trim($class), 'style' => 'width:' . (int) $size . 'px;height:' . (int) $size . 'px;border-radius:999px;object-fit:cover;']);
		}
		return get_avatar($user_id, $size, '', '', ['class' => trim($class)]);
	}

	private function get_manage_expert_user() {
		$expert_id = isset($_GET['expert_id']) ? absint($_GET['expert_id']) : 0;
		if (!$expert_id) return null;
		$user = get_user_by('id', $expert_id);
		return $this->is_expert_profile_supported($user) ? $user : null;
	}

	private function handle_manage_expert_submission() {
		if (!current_user_can('manage_options')) return null;
		// حذف کارشناس
		if (!empty($_GET['delete_expert_id'])) {
			$del_id = absint($_GET['delete_expert_id']);
			if ($del_id && check_admin_referer('cptt_delete_expert_' . $del_id)) {
				$user = get_user_by('id', $del_id);
				if ($user) {
					$user->remove_role('cptt_expert');
					// اگر نقش دیگری نداره حذف کامل کن
					$remaining = array_filter((array)$user->roles, function($r){ return $r !== 'cptt_expert'; });
					if (empty($remaining)) {
						require_once ABSPATH . 'wp-admin/includes/user.php';
						wp_delete_user($del_id);
					}
				}
				return ['type' => 'success', 'message' => 'کارشناس با موفقیت حذف شد.'];
			}
		}
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') return null;
		if (empty($_POST['cptt_expert_manage_submit'])) return null;
		check_admin_referer('cptt_expert_manage_action', 'cptt_expert_manage_nonce');

		$expert_id = absint($_POST['cptt_expert_id'] ?? 0);
		$username = sanitize_user((string) ($_POST['cptt_expert_username'] ?? ''), true);
		$email = sanitize_email((string) ($_POST['cptt_expert_email'] ?? ''));
		$display_name = sanitize_text_field((string) ($_POST['cptt_expert_display_name'] ?? ''));
		$password = (string) ($_POST['cptt_expert_password'] ?? '');
		$title = sanitize_text_field((string) ($_POST['cptt_expert_title'] ?? ''));
		$bio = sanitize_textarea_field((string) ($_POST['cptt_expert_short_bio'] ?? ''));
		$specialties = sanitize_text_field((string) ($_POST['cptt_expert_specialties'] ?? ''));
		$avatar_id = absint($_POST['cptt_expert_avatar_id'] ?? 0);

		if ($email === '' || !is_email($email)) return ['type' => 'error', 'message' => 'ایمیل معتبر وارد کنید.'];
		if ($display_name === '') return ['type' => 'error', 'message' => 'نام نمایشی کارشناس را وارد کنید.'];

		$generated_password = '';
		if ($expert_id) {
			$existing = get_user_by('id', $expert_id);
			if (!$this->is_expert_profile_supported($existing)) return ['type' => 'error', 'message' => 'کارشناس موردنظر پیدا نشد.'];
			$email_owner = email_exists($email);
			if ($email_owner && (int) $email_owner !== (int) $expert_id) return ['type' => 'error', 'message' => 'این ایمیل قبلاً استفاده شده است.'];
			$data = ['ID' => $expert_id, 'user_email' => '', 'display_name' => $display_name, 'nickname' => $display_name];
			if ($password !== '') $data['user_pass'] = $password;
			$result = wp_update_user($data);
			if (is_wp_error($result)) return ['type' => 'error', 'message' => $result->get_error_message()];
		} else {
			if ($username === '') return ['type' => 'error', 'message' => 'نام کاربری را وارد کنید.'];
			if (username_exists($username)) return ['type' => 'error', 'message' => 'این نام کاربری قبلاً استفاده شده است.'];
			if (email_exists($email)) return ['type' => 'error', 'message' => 'این ایمیل قبلاً استفاده شده است.'];
			if ($password === '') {
				$generated_password = wp_generate_password(12, true, false);
				$password = $generated_password;
			}
			$expert_id = wp_create_user($username, $password, $email);
			if (is_wp_error($expert_id)) return ['type' => 'error', 'message' => $expert_id->get_error_message()];
			wp_update_user(['ID' => $expert_id, 'display_name' => $display_name, 'nickname' => $display_name]);
		}

		$user = get_user_by('id', $expert_id);
		if ($user instanceof WP_User && !in_array('cptt_expert', (array) $user->roles, true)) $user->set_role('cptt_expert');
		update_user_meta($expert_id, 'cptt_expert_title', $title);
		update_user_meta($expert_id, 'cptt_expert_short_bio', $bio);
		update_user_meta($expert_id, 'cptt_expert_specialties', $specialties);
		update_user_meta($expert_id, 'cptt_expert_avatar_id', $avatar_id);

		$message = $generated_password ? ('کارشناس با موفقیت ایجاد شد. رمز عبور موقت: ' . $generated_password) : 'اطلاعات کارشناس با موفقیت ذخیره شد.';
		return ['type' => 'success', 'message' => $message, 'expert_id' => $expert_id];
	}

	public function render_experts_manage_page() {
		if (!current_user_can('manage_options')) return;
		$notice = $this->handle_manage_expert_submission();
		$editing = $this->get_manage_expert_user();
		$values = [
			'id' => $editing ? (int) $editing->ID : 0,
			'username' => $editing ? $editing->user_login : '',
			'email' => $editing ? $editing->user_email : '',
			'display_name' => $editing ? $editing->display_name : '',
			'title' => $editing ? (string) get_user_meta($editing->ID, 'cptt_expert_title', true) : '',
			'bio' => $editing ? (string) get_user_meta($editing->ID, 'cptt_expert_short_bio', true) : '',
			'specialties' => $editing ? (string) get_user_meta($editing->ID, 'cptt_expert_specialties', true) : '',
			'avatar_id' => $editing ? (int) get_user_meta($editing->ID, 'cptt_expert_avatar_id', true) : 0,
		];
		$avatar_preview = $values['avatar_id'] ? wp_get_attachment_image_url($values['avatar_id'], 'medium') : '';
		$experts = [];
		foreach ($this->get_expert_directory_users() as $expert) {
			$experts[] = ['user' => $expert, 'profile' => $this->get_expert_profile_data($expert->ID)];
		}
		?>
		<div class="wrap cptt-adminExperts" dir="rtl">
			<h1><?php echo $editing ? 'ویرایش کارشناس' : 'افزودن کارشناس'; ?></h1>
			<?php if ($notice): ?><div class="notice notice-<?php echo esc_attr($notice['type'] === 'success' ? 'success' : 'error'); ?> is-dismissible"><p><?php echo esc_html($notice['message']); ?></p></div><?php endif; ?>
			<style>
				.cptt-adminExperts__layout{display:grid;grid-template-columns:minmax(0,.95fr) minmax(320px,1.05fr);gap:18px;align-items:start;}
				.cptt-adminExperts__panel{background:#fff;border:1px solid #e5edf8;border-radius:22px;padding:18px;box-shadow:0 14px 34px rgba(15,23,42,.05);}
				.cptt-adminExperts__panel h2{margin:0 0 14px;font-size:18px;font-weight:950;color:#0f172a;}
				.cptt-adminExperts__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}
				.cptt-adminExperts__field{display:block;}
				.cptt-adminExperts__field span{display:block;font-size:12px;font-weight:900;color:#334155;margin-bottom:6px;}
				.cptt-adminExperts__field input,.cptt-adminExperts__field textarea{width:100%;border:1px solid #cbd5e1;border-radius:14px;padding:11px 12px;background:#fff;font:inherit;color:#0f172a;box-shadow:0 1px 2px rgba(15,23,42,.03);}
				.cptt-adminExperts__field textarea{min-height:110px;resize:vertical;}
				.cptt-adminExperts__field--full{grid-column:1 / -1;}
				.cptt-adminExperts__avatar{display:flex;align-items:center;gap:14px;grid-column:1 / -1;padding:14px;border:1px dashed #dbeafe;border-radius:18px;background:#f8fbff;}
				.cptt-adminExperts__avatarPreview{width:88px;height:88px;border-radius:999px;background:#eff6ff;display:flex;align-items:center;justify-content:center;overflow:hidden;border:3px solid #dbeafe;flex-shrink:0;}
				.cptt-adminExperts__avatarPreview img{width:100%;height:100%;object-fit:cover;}
				.cptt-adminExperts__avatarMeta{display:grid;gap:8px;}
				.cptt-adminExperts__actions{margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;}
				.cptt-adminExperts__list{display:grid;gap:12px;}
				.cptt-adminExperts__card{display:grid;grid-template-columns:64px 1fr auto;gap:12px;align-items:center;padding:12px;border:1px solid #e5edf8;border-radius:18px;background:#fbfdff;}
				.cptt-adminExperts__card img{width:64px;height:64px;border-radius:999px;display:block;object-fit:cover;border:3px solid #e0ecff;}
				.cptt-adminExperts__card strong{display:block;font-size:14px;color:#0f172a;font-weight:950;}
				.cptt-adminExperts__card small{display:block;color:#64748b;font-size:11px;line-height:1.8;margin-top:4px;}
				.cptt-adminExperts__tags{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;}
				.cptt-adminExperts__tags span{display:inline-flex;padding:5px 8px;border-radius:999px;background:#eff6ff;border:1px solid #dbeafe;font-size:10px;font-weight:900;color:#1d4ed8;}
				.cptt-adminExperts__stats{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;}
				.cptt-adminExperts__stats span{display:inline-flex;padding:5px 8px;border-radius:999px;background:#fff;border:1px solid #e5e7eb;font-size:10px;font-weight:900;color:#475569;}
				@media(max-width:1100px){.cptt-adminExperts__layout{grid-template-columns:1fr;}.cptt-adminExperts__grid{grid-template-columns:1fr;}.cptt-adminExperts__card{grid-template-columns:64px 1fr;}.cptt-adminExperts__card a{grid-column:1 / -1;justify-self:start;}}
			</style>
			<div class="cptt-adminExperts__layout">
				<div class="cptt-adminExperts__panel">
					<h2><?php echo $editing ? 'ویرایش اطلاعات کارشناس' : 'ساخت حساب کارشناس جدید'; ?></h2>
					<form method="post">
						<?php wp_nonce_field('cptt_expert_manage_action', 'cptt_expert_manage_nonce'); ?>
						<input type="hidden" name="cptt_expert_manage_submit" value="1">
						<input type="hidden" name="cptt_expert_id" value="<?php echo esc_attr((string) $values['id']); ?>">
						<div class="cptt-adminExperts__grid">
							<label class="cptt-adminExperts__field"><span>نام کاربری</span><input type="text" name="cptt_expert_username" value="<?php echo esc_attr($values['username']); ?>" <?php disabled((bool)$editing); ?>></label>
							<label class="cptt-adminExperts__field"><span>ایمیل</span><input type="email" name="cptt_expert_email" value="<?php echo esc_attr($values['email']); ?>"></label>
							<label class="cptt-adminExperts__field"><span>نام نمایشی</span><input type="text" name="cptt_expert_display_name" value="<?php echo esc_attr($values['display_name']); ?>"></label>
							<label class="cptt-adminExperts__field"><span><?php echo $editing ? 'رمز عبور جدید (اختیاری)' : 'رمز عبور (در صورت خالی بودن، خودکار ساخته می‌شود)'; ?></span><input type="text" name="cptt_expert_password" value=""></label>
							<label class="cptt-adminExperts__field"><span>سمت / عنوان</span><input type="text" name="cptt_expert_title" value="<?php echo esc_attr($values['title']); ?>"></label>
							<label class="cptt-adminExperts__field"><span>تخصص‌ها</span><input type="text" name="cptt_expert_specialties" value="<?php echo esc_attr($values['specialties']); ?>" placeholder="طراحی سایت، سئو، پشتیبانی"></label>
							<label class="cptt-adminExperts__field cptt-adminExperts__field--full"><span>بیو کوتاه</span><textarea name="cptt_expert_short_bio"><?php echo esc_textarea($values['bio']); ?></textarea></label>
							<div class="cptt-adminExperts__avatar">
								<div class="cptt-adminExperts__avatarPreview" id="cptt-expert-avatar-preview">
									<?php if ($avatar_preview): ?><img src="<?php echo esc_url($avatar_preview); ?>" alt="avatar"><?php else: ?><span>بدون عکس</span><?php endif; ?>
								</div>
								<div class="cptt-adminExperts__avatarMeta">
									<input type="hidden" id="cptt-expert-avatar-id" name="cptt_expert_avatar_id" value="<?php echo esc_attr((string) $values['avatar_id']); ?>">
									<strong>عکس پروفایل کارشناس</strong>
									<div class="cptt-adminExperts__actions">
										<button type="button" class="button button-primary" id="cptt-expert-avatar-select">انتخاب / آپلود عکس</button>
										<button type="button" class="button" id="cptt-expert-avatar-remove">حذف عکس</button>
									</div>
								</div>
							</div>
						</div>
						<div class="cptt-adminExperts__actions">
							<button type="submit" class="button button-primary"><?php echo $editing ? 'ذخیره تغییرات کارشناس' : 'ایجاد حساب کارشناس'; ?></button>
							<?php if ($editing): ?><a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=cptt_project&page=cptt-experts-manage')); ?>">ساخت کارشناس جدید</a><?php endif; ?>
						</div>
					</form>
				</div>
				<div class="cptt-adminExperts__panel">
					<h2>فهرست کارشناسان</h2>
					<div class="cptt-adminExperts__list">
						<?php if (empty($experts)): ?><div class="cptt-empty">هنوز کارشناسی ثبت نشده است.</div><?php else: foreach ($experts as $row): $expert = $row['user']; $profile = $row['profile']; ?>
							<div class="cptt-adminExperts__card">
								<div><?php echo $this->get_expert_avatar_markup($expert->ID, 64); ?></div>
								<div>
									<strong><?php echo esc_html($expert->display_name); ?></strong>
									<small><?php echo esc_html($expert->user_email); ?></small>
									<small><?php echo esc_html($profile['title'] ?? 'کارشناس'); ?></small>
									<div class="cptt-adminExperts__stats"><span>فعال: <?php echo esc_html((string) ($profile['active_projects'] ?? 0)); ?></span><span>تکمیل‌شده: <?php echo esc_html((string) ($profile['completed_projects'] ?? 0)); ?></span></div>
									<?php if (!empty($profile['specialties'])): ?><div class="cptt-adminExperts__tags"><?php foreach ((array) $profile['specialties'] as $tag): ?><span><?php echo esc_html($tag); ?></span><?php endforeach; ?></div><?php endif; ?>
								</div>
								<div style="display:flex;gap:6px;flex-direction:column;">
									<a class="button button-primary" href="<?php echo esc_url(add_query_arg(['post_type' => 'cptt_project', 'page' => 'cptt-experts-manage', 'expert_id' => $expert->ID], admin_url('edit.php'))); ?>">✏️ ویرایش</a>
									<a class="button button-link-delete" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['post_type' => 'cptt_project', 'page' => 'cptt-experts-manage', 'delete_expert_id' => $expert->ID], admin_url('edit.php')), 'cptt_delete_expert_' . $expert->ID)); ?>" onclick="return confirm('آیا از حذف این کارشناس مطمئنید؟ این کار قابل برگشت نیست.');" style="color:#b91c1c;text-align:center;">🗑 حذف</a>
								</div>
							</div>
						<?php endforeach; endif; ?>
					</div>
				</div>
			</div>
			<script>
			(function($){
				$(function(){
					var frame;
					$('#cptt-expert-avatar-select').on('click', function(e){
						e.preventDefault();
						if(frame){ frame.open(); return; }
						frame = wp.media({title:'انتخاب عکس کارشناس', button:{text:'انتخاب'}, multiple:false});
						frame.on('select', function(){
							var att = frame.state().get('selection').first().toJSON();
							$('#cptt-expert-avatar-id').val(att.id);
							$('#cptt-expert-avatar-preview').html('<img src="'+att.url+'" alt="avatar">');
						});
						frame.open();
					});
					$('#cptt-expert-avatar-remove').on('click', function(e){
						e.preventDefault();
						$('#cptt-expert-avatar-id').val('');
						$('#cptt-expert-avatar-preview').html('<span>بدون عکس</span>');
					});
				});
			})(jQuery);
			</script>
		</div>
		<?php
	}

	private function get_expert_directory_users() {
		return get_users([
			'role' => 'cptt_expert',
			'orderby' => 'display_name',
			'order' => 'ASC',
		]);
	}

	private function split_specialties($value) {
		$parts = preg_split('/[,،

]+/u', (string) $value);
		$out = [];
		foreach ((array) $parts as $part) {
			$part = trim(wp_strip_all_tags($part));
			if ($part === '') continue;
			$out[] = $part;
		}
		return array_values(array_unique($out));
	}

	private function get_expert_project_stats($user_id) {
		$projects = get_posts([
			'post_type' => 'cptt_project',
			'post_status' => 'any',
			'numberposts' => -1,
			'meta_query' => [[
				'key' => '_cptt_experts_csv',
				'value' => ',' . (int) $user_id . ',',
				'compare' => 'LIKE',
			]],
		]);
		$active = 0;
		$completed = 0;
		$terms = [];
		foreach ($projects as $project) {
			$progress = $this->progress_data($project->ID);
			if (($progress['status'] ?? 'in_progress') === 'completed') $completed++; else $active++;
			$cat_ids = get_post_meta($project->ID, '_cptt_wc_cat_ids', true);
			if (is_array($cat_ids)) {
				foreach ($cat_ids as $cid) {
					$term = get_term((int) $cid, 'product_cat');
					if ($term && !is_wp_error($term)) $terms[$term->name] = $term->name;
				}
			}
		}
		return ['active' => $active, 'completed' => $completed, 'specialties' => array_values($terms)];
	}

	private function get_expert_profile_data($user_id) {
		$user = get_user_by('id', (int) $user_id);
		if (!$this->is_expert_profile_supported($user)) return null;
		$stats = $this->get_expert_project_stats($user_id);
		$specialties = $this->split_specialties((string) get_user_meta($user_id, 'cptt_expert_specialties', true));
		if (empty($specialties)) $specialties = array_slice($stats['specialties'], 0, 6);
		$bio = trim((string) get_user_meta($user_id, 'cptt_expert_short_bio', true));
		if ($bio === '') $bio = trim((string) $user->description);
		$title = trim((string) get_user_meta($user_id, 'cptt_expert_title', true));
		if ($title === '') $title = 'کارشناس';
		return [
			'id' => (int) $user_id,
			'name' => $user->display_name,
			'title' => $title,
			'bio' => $bio,
			'avatar' => $this->get_expert_avatar_url($user_id, 160),
			'active_projects' => (int) $stats['active'],
			'completed_projects' => (int) $stats['completed'],
			'specialties' => $specialties,
		];
	}

	private function project_start_fa($project_id) {
		$ts = (int) get_post_time('U', true, $project_id);
		return ($ts && class_exists('CPTT_Core')) ? CPTT_Core::jalali_datetime($ts) : '';
	}

	private function step_status_label_public($status) {
		$map = ['done' => 'انجام‌شده', 'current' => 'در حال انجام', 'todo' => 'انجام‌نشده'];
		return $map[$status] ?? $map['todo'];
	}

	private function build_public_project_payload($project_id, $full_details = false) {
		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps)) $steps = [];
		$items = [];
		foreach ($steps as $index => $step) {
			if (!is_array($step)) continue;
			$status = (string) ($step['status'] ?? 'todo');
			$checklist = isset($step['checklist']) && is_array($step['checklist']) ? $step['checklist'] : [];
			$checklist_total = 0;
			$checklist_done = 0;
			$checklist_items = [];
			foreach ($checklist as $item) {
				if (!is_array($item) || empty($item['text'])) continue;
				$checklist_total++;
				if (!empty($item['done'])) $checklist_done++;
				if ($full_details) {
					$checklist_items[] = [
						'text' => (string) $item['text'],
						'done' => !empty($item['done']),
						'url' => !empty($item['done']) ? (string) ($item['url'] ?? '') : '',
					];
				}
			}
			$user_tasks = isset($step['user_tasks']) && is_array($step['user_tasks']) ? $step['user_tasks'] : [];
			$user_tasks_total = 0;
			$user_tasks_done = 0;
			foreach ($user_tasks as $task) {
				if (!is_array($task) || empty($task['title'])) continue;
				$user_tasks_total++;
				if (!empty($task['done'])) $user_tasks_done++;
			}
			$user_tasks_items = [];
			if ($full_details) {
				foreach ($user_tasks as $task) {
					if (!is_array($task) || empty($task['title'])) continue;
					$user_tasks_items[] = [
						'title' => (string) ($task['title'] ?? ''),
						'desc' => (string) ($task['desc'] ?? ''),
						'done' => !empty($task['done']),
						'due_fa' => (string) ($task['due_at_fa'] ?? ''),
						'response' => (string) ($task['response'] ?? ''),
					];
				}
			}

			$step_experts = [];
			if ($full_details) {
				$assigned_ids = isset($step['assigned_expert_ids']) && is_array($step['assigned_expert_ids']) ? $step['assigned_expert_ids'] : [];
				if (empty($assigned_ids) && !empty($step['assigned_expert_id'])) $assigned_ids = [(int)$step['assigned_expert_id']];
				foreach ($assigned_ids as $eid) {
					$u = get_user_by('id', (int)$eid);
					if ($u) {
						$av = $this->get_expert_avatar_url($eid, 64);
						$step_experts[] = ['name' => $u->display_name, 'avatar' => $av];
					}
				}
			}

			$items[] = [
				'index' => $index + 1,
				'title' => (string) ($step['title'] ?? ('مرحله ' . ($index + 1))),
				'status' => $status,
				'label' => $this->step_status_label_public($status),
				'due_fa' => (string) ($step['due_at_fa'] ?? ''),
				'desc' => $full_details ? wp_strip_all_tags((string) ($step['desc'] ?? '')) : '',
				'checklist_total' => $checklist_total,
				'checklist_done' => $checklist_done,
				'checklist_items' => $checklist_items,
				'user_tasks_total' => $user_tasks_total,
				'user_tasks_done' => $user_tasks_done,
				'user_tasks_items' => $user_tasks_items,
				'cost' => $full_details ? (float) ($step['cost'] ?? 0) : 0,
				'paid' => $full_details ? (float) ($step['paid'] ?? 0) : 0,
				'experts' => $step_experts,
			];
		}
		$card = $this->project_card_data($project_id);
		return [
			'id' => (int) $project_id,
			'code' => (string)($card['code'] ?? ''),
			'label' => $card['label'] ?? null,
			'title' => get_the_title($project_id),
			'start_fa' => $this->project_start_fa($project_id),
			'last_update' => (string) ($card['last_update'] ?? ''),
			'deadline' => (string) ($card['deadline'] ?? ''),
			'customer' => $full_details ? (string) ($card['customer'] ?? '') : '',
			'product' => (string) ($card['product'] ?? ''),
			'categories' => isset($card['term_names']) && is_array($card['term_names']) ? $card['term_names'] : [],
			'experts' => isset($card['experts']) && is_array($card['experts']) ? $card['experts'] : [],
			'progress' => $card['progress'] ?? ['percent' => 0, 'status' => 'in_progress', 'label' => 'در حال انجام', 'done' => 0, 'total' => 0],
			'checklist_total' => (int) ($card['checklist_total'] ?? 0),
			'checklist_done' => (int) ($card['checklist_done'] ?? 0),
			'user_tasks_total' => (int) ($card['user_tasks_total'] ?? 0),
			'user_tasks_done' => (int) ($card['user_tasks_done'] ?? 0),
			'financial' => $full_details ? ($card['financial'] ?? ['cost' => 0, 'paid' => 0, 'remain' => 0]) : ['cost' => 0, 'paid' => 0, 'remain' => 0],
			'settled' => $full_details ? (int) ($card['settled'] ?? 0) : 0,
			'steps' => $items,
			'full_details' => $full_details,
			'delivery_method' => $card['delivery_method'] ?? '',
			'delivery_method_label' => $card['delivery_method_label'] ?? '',
			'delivery_province' => $card['delivery_province'] ?? '',
			'delivery_city' => $card['delivery_city'] ?? '',
			'delivery_address' => $card['delivery_address'] ?? '',
		];
	}

	private function get_hub_projects($allow_projects, $full_details = false) {
		if (!$allow_projects) return [];
		$posts = get_posts([
			'post_type' => 'cptt_project',
			'post_status' => 'any',
			'numberposts' => -1,
			'orderby' => 'date',
			'order' => 'DESC',
		]);
		$out = [];
		$now = (int) current_time('timestamp', true);
		$week = $now + WEEK_IN_SECONDS;
		foreach ($posts as $post) {
			$card = $this->project_card_data($post->ID);
			if (($card['progress']['status'] ?? 'in_progress') === 'completed') continue;
			$deadline_ts = (int) get_post_meta($post->ID, '_cptt_deadline_at', true);
			if (!$deadline_ts) $deadline_state = 'none';
			elseif ($deadline_ts < $now) $deadline_state = 'overdue';
			elseif ($deadline_ts <= $week) $deadline_state = 'soon';
			else $deadline_state = 'future';
			$payload = $this->build_public_project_payload($post->ID, $full_details);
			$mini_steps = [];
			foreach (array_slice($payload['steps'], 0, 3) as $step) {
				$mini_steps[] = ['title' => $step['title'], 'status' => $step['status']];
			}
			$out[] = [
				'id' => $post->ID,
				'code' => $payload['code'] ?? '',
				'label' => $payload['label'] ?? null,
				'title' => get_the_title($post->ID),
				'start_fa' => $payload['start_fa'],
				'last_update' => $payload['last_update'],
				'deadline' => $payload['deadline'],
				'deadline_state' => $deadline_state,
				'customer' => $payload['customer'],
				'product' => $payload['product'],
				'categories' => $payload['categories'],
				'experts' => $payload['experts'],
				'expert_ids' => self::get_existing_experts($post->ID),
				'progress' => $payload['progress'],
				'steps_total' => count($payload['steps']),
				'checklist_total' => (int) $payload['checklist_total'],
				'checklist_done' => (int) $payload['checklist_done'],
				'user_tasks_total' => (int) $payload['user_tasks_total'],
				'user_tasks_done' => (int) $payload['user_tasks_done'],
				'financial' => $payload['financial'],
				'mini_steps' => $mini_steps,
				'payload' => $payload,
				'cat_ids' => isset($card['term_ids']) && is_array($card['term_ids']) ? $card['term_ids'] : [],
				'product_id' => (int) ($card['product_id'] ?? 0),
			];
		}
		return $out;
	}

	private function render_public_project_card($project, $limited_public = false) {
		$search = strtolower(trim($project['title'] . ' ' . implode(' ', (array) $project['experts']) . ' ' . $project['product'] . ' ' . implode(' ', (array) $project['categories']) . ' ' . ($project['code'] ?? '') . ' ' . (!$limited_public ? $project['customer'] : '')));
		$payload_b64 = base64_encode(wp_json_encode($project['payload'], JSON_UNESCAPED_UNICODE));
		$timeline_steps = isset($project['payload']['steps']) && is_array($project['payload']['steps']) ? $project['payload']['steps'] : [];
		$label_id = is_array($project['label'] ?? null) ? (string)$project['label']['id'] : '';
		$timeline_count = count($timeline_steps);
		$timeline_progress = 0;
		if ($timeline_count > 1) {
			$last_index = 0;
			foreach ($timeline_steps as $i => $step) {
				$status = (string) ($step['status'] ?? 'todo');
				if ($status === 'done' || $status === 'current') $last_index = $i;
			}
			$timeline_progress = ($last_index / max(1, $timeline_count - 1)) * 100;
		} elseif ($timeline_count === 1) {
			$timeline_progress = 100;
		}
		?>
		<article class="cptt-publicProject cptt-publicProject--<?php echo esc_attr($project['progress']['status']); ?> cptt-publicProject--deadline-<?php echo esc_attr($project['deadline_state']); ?>" data-search="<?php echo esc_attr($search); ?>" data-experts=",<?php echo esc_attr(implode(',', array_map('intval', (array) $project['expert_ids']))); ?>," data-product="<?php echo esc_attr((string) $project['product_id']); ?>" data-cats=",<?php echo esc_attr(implode(',', array_map('intval', (array) $project['cat_ids']))); ?>," data-deadline="<?php echo esc_attr($project['deadline_state']); ?>" data-label="<?php echo esc_attr($label_id); ?>">
			<div class="cptt-publicProject__top">
				<div>
					<h3><?php echo esc_html($project['title']); ?> <span class="cptt-project-code">#<?php echo esc_html($project['code'] ?? ''); ?></span></h3>
					<div class="cptt-project__meta cptt-show-day" data-date="<?php echo esc_attr($project['start_fa'] ?: ''); ?>">تاریخ شروع: <?php echo esc_html($project['start_fa'] ?: '—'); ?></div>
					<?php if ($project['last_update']): ?><div class="cptt-project__meta cptt-show-day" data-date="<?php echo esc_attr($project['last_update']); ?>">آخرین بروزرسانی: <?php echo esc_html($project['last_update']); ?></div><?php endif; ?>
				</div>
				<div class="cptt-publicProject__statusWrap">
					<?php $_hub_lbl = is_array($project['label'] ?? null) ? $project['label'] : null; $_hub_style = $_hub_lbl ? 'style="background:'.esc_attr($_hub_lbl['color']).'22;color:'.esc_attr($_hub_lbl['color']).';border-color:'.esc_attr($_hub_lbl['color']).'55;"' : ''; ?><?php if ($_hub_lbl): ?><span class="cptt-expertStatusBadge cptt-projectLabelBadge" <?php echo $_hub_style; ?>><?php echo esc_html($_hub_lbl['name']); ?></span><?php endif; ?>
					<?php if (!empty($project['experts'])): ?><span class="cptt-publicProject__experts"><?php echo esc_html(implode('، ', $project['experts'])); ?></span><?php endif; ?>
				</div>
			</div>
			<div class="cptt-publicProject__stats cptt-publicProject__stats--compact">
				<div><strong><?php echo esc_html((int) $project['checklist_done']); ?>/<?php echo esc_html((int) $project['checklist_total']); ?></strong><span>چک‌لیست</span></div>
				<div><strong><?php echo esc_html((int) $project['user_tasks_done']); ?>/<?php echo esc_html((int) $project['user_tasks_total']); ?></strong><span>تسک مشتری</span></div>
			</div>
			<div class="cptt-publicProject__metaGrid cptt-publicProject__metaGrid--compact">
				<div><span>مهلت پروژه</span><strong><?php echo esc_html($project['deadline'] ?: '—'); ?></strong></div>
				<div><span>محصول</span><strong><?php echo esc_html($project['product'] ?: '—'); ?></strong></div>
				<div><span>دسته‌بندی</span><strong><?php echo esc_html(!empty($project['categories']) ? implode('، ', $project['categories']) : '—'); ?></strong></div>
				<?php if (!$limited_public): ?><div><span>مشتری</span><strong><?php echo esc_html($project['customer'] ?: '—'); ?></strong></div><?php endif; ?>
			</div>
			<?php if (!empty($timeline_steps)): ?>
				<div class="cptt-publicTimeline" style="--cptt-hub-progress:<?php echo esc_attr(number_format((float) $timeline_progress, 2, '.', '')); ?>%;">
					<div class="cptt-publicTimeline__track"><span></span></div>
					<div class="cptt-publicTimeline__items">
						<?php foreach ($timeline_steps as $step): ?>
							<div class="cptt-publicTimeline__item cptt-publicTimeline__item--<?php echo esc_attr($step['status']); ?>">
								<span class="cptt-publicTimeline__dot"></span>
								<span class="cptt-publicTimeline__label"><?php echo esc_html($step['title']); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
			<div class="cptt-publicProject__foot">
				<button type="button" class="cptt-btn cptt-btn--primary cptt-publicProject__open" data-project="<?php echo esc_attr($payload_b64); ?>">مشاهده جزئیات</button>
			</div>
		</article>
		<?php
	}

	private function render_public_hub_modal() {
		?>
		<div class="cptt-hubModal" id="cptt-hub-modal" hidden>
			<div class="cptt-hubModal__backdrop"></div>
			<div class="cptt-hubModal__dialog" role="dialog" aria-modal="true" aria-labelledby="cptt-hub-modal-title">
				<button type="button" class="cptt-hubModal__close" aria-label="بستن">×</button>
				<div class="cptt-hubModal__title" id="cptt-hub-modal-title"></div>
				<div class="cptt-hubModal__meta" id="cptt-hub-modal-meta"></div>
				<div class="cptt-hubModal__body" id="cptt-hub-modal-body"></div>
			</div>
		</div>
		<?php
	}

	public function shortcode_public_hub($atts) {
		$this->enqueue_assets();
		$settings = self::get_public_settings();
		$mode = $settings['visibility_mode'];
		$viewer_can_private = $this->current_user_can_view_dashboard();
		$allow_projects = ($mode !== 'login_only') || $viewer_can_private;
		$full_details = $viewer_can_private || $mode === 'public_all';
		$limited_public = !$viewer_can_private && $mode === 'public_limited';
		$experts = [];
		foreach ($this->get_expert_directory_users() as $expert) {
			$data = $this->get_expert_profile_data($expert->ID);
			if ($data) $experts[] = $data;
		}
		$projects = $this->get_hub_projects($allow_projects, $full_details);
		$expert_map = []; $product_map = []; $cat_map = [];
		foreach ($projects as $project) {
			foreach ((array) $project['expert_ids'] as $id) {
				$u = get_user_by('id', (int) $id);
				if ($u) $expert_map[(int) $id] = $u->display_name;
			}
			if (!empty($project['product_id']) && !empty($project['product'])) $product_map[(int) $project['product_id']] = $project['product'];
			foreach ((array) $project['cat_ids'] as $idx => $cid) {
				$name = $project['categories'][$idx] ?? '';
				if ($name !== '') $cat_map[(int) $cid] = $name;
			}
		}
		asort($expert_map); asort($product_map); asort($cat_map);
		$login_url = $viewer_can_private ? self::dashboard_url() : wp_login_url(self::dashboard_url());
		$login_label = $viewer_can_private ? 'ورود به داشبورد کارشناس' : 'ورود به حساب کارشناس';
		ob_start();
		?>
		<div class="cptt-wrap cptt-expertsHub cptt-v2-scope" dir="rtl">
			<section class="cptt-expertsHero">
				<div class="cptt-expertsHero__content">
					<span class="cptt-expertsHero__eyebrow">ویترین حرفه‌ای کارشناسان</span>
					<h1 class="cptt-expertsHero__title"><?php echo esc_html($settings['page_title']); ?></h1>
					<p class="cptt-expertsHero__text"><?php echo esc_html($settings['page_text']); ?></p>
					<div class="cptt-expertsHero__actions">
						<a class="cptt-btn cptt-btn--primary" href="<?php echo esc_url($login_url); ?>"><?php echo esc_html($login_label); ?></a>
						<a class="cptt-btn" href="<?php echo esc_url(self::public_hub_url()); ?>">بروزرسانی صفحه</a>
					</div>
				</div>
				<div class="cptt-expertsHero__stats">
					<div class="cptt-expertsHero__stat"><strong><?php echo esc_html(number_format_i18n(count($experts))); ?></strong><span>کارشناس فعال</span></div>
					<div class="cptt-expertsHero__stat"><strong><?php echo esc_html(number_format_i18n(count($projects))); ?></strong><span>پروژه ناقص</span></div>
				</div>
			</section>

			<section class="cptt-expertsDirectory">
				<div class="cptt-expertsDirectory__head">
					<h2>اعضای تیم کارشناسی</h2>
					<p>روی هر کارشناس کلیک کنید تا پروفایل و آمار پروژه‌های او را ببینید.</p>
				</div>
				<div class="cptt-expertsStrip">
					<?php if (empty($experts)): ?>
						<div class="cptt-empty">فعلاً کارشناس فعالی برای نمایش ثبت نشده است.</div>
					<?php else: foreach ($experts as $expert): ?>
						<?php $expert_b64 = base64_encode(wp_json_encode($expert, JSON_UNESCAPED_UNICODE)); ?>
						<button type="button" class="cptt-expertBadge" data-expert="<?php echo esc_attr($expert_b64); ?>">
							<span class="cptt-expertBadge__avatar"><?php echo $this->get_expert_avatar_markup($expert['id'], 80); ?></span>
							<span class="cptt-expertBadge__name"><?php echo esc_html($expert['name']); ?></span>
							<span class="cptt-expertBadge__title"><?php echo esc_html($expert['title']); ?></span>
						</button>
					<?php endforeach; endif; ?>
				</div>
			</section>

			<section class="cptt-hubProjects">
				<div class="cptt-hubProjects__head">
					<div>
						<h2>پروژه‌های تکمیل‌نشده</h2>
						<p>نمای فقط‌خواندنی از پروژه‌های در حال انجام با طراحی الهام‌گرفته از پنل مشتری.</p>
					</div>
					<div class="cptt-hubProjects__count"><strong id="cptt-hub-count"><?php echo esc_html(number_format_i18n(count($projects))); ?></strong><span>پروژه</span></div>
				</div>

				<div class="cptt-hubFilters">
					<label>جستجو<input type="search" id="cptt-hub-search" placeholder="نام پروژه، کارشناس، محصول..."></label>
					<label>کارشناس<select id="cptt-hub-expert"><option value="">همه</option><?php foreach ($expert_map as $id => $name): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
					<label>محصول<select id="cptt-hub-product"><option value="">همه</option><?php foreach ($product_map as $id => $name): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
					<label>دسته‌بندی<select id="cptt-hub-cat"><option value="">همه</option><?php foreach ($cat_map as $id => $name): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
					<label>لیبل<select id="cptt-hub-label"><option value="">همه</option><?php foreach ((class_exists('CPTT_Core') ? CPTT_Core::get_project_labels() : []) as $l): ?><option value="<?php echo esc_attr($l['id']); ?>"><?php echo esc_html($l['name']); ?></option><?php endforeach; ?></select></label>
					<label>مهلت<select id="cptt-hub-deadline"><option value="">همه</option><option value="overdue">دیرکرد</option><option value="soon">نزدیک به سررسید</option><option value="future">دارای مهلت</option><option value="none">بدون مهلت</option></select></label>
					<button type="button" class="cptt-btn" id="cptt-hub-reset">پاک کردن فیلترها</button>
				</div>

				<?php if (!$allow_projects): ?>
					<div class="cptt-notice cptt-notice--lock">نمایش پروژه‌ها توسط مدیر فقط برای کاربران واردشده فعال شده است. برای مشاهده، وارد حساب کارشناس شوید.</div>
				<?php else: ?>
					<div class="cptt-publicProjectsGrid" id="cptt-hub-grid">
						<?php if (empty($projects)): ?>
							<div class="cptt-empty">در حال حاضر پروژه‌ی ناقصی برای نمایش وجود ندارد.</div>
						<?php else: foreach ($projects as $project) { $this->render_public_project_card($project, $limited_public); } endif; ?>
					</div>
					<div class="cptt-empty cptt-empty--hub" id="cptt-hub-empty" hidden>هیچ پروژه‌ای با این فیلترها پیدا نشد.</div>
				<?php endif; ?>
			</section>

			<?php $this->render_public_hub_modal(); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private function templates() {
		return get_posts([
			'post_type' => 'cptt_template',
			'numberposts' => -1,
			'orderby' => 'title',
			'order' => 'ASC',
		]);
	}

	private function products() {
		if (!post_type_exists('product')) return [];
		return get_posts([
			'post_type' => 'product',
			'post_status' => ['publish','draft','private'],
			'numberposts' => 300,
			'orderby' => 'title',
			'order' => 'ASC',
		]);
	}

	private function customer_users() {
		return get_users(['fields' => ['ID','display_name','user_email','user_login']]);
	}

	private function customer_option_search($user) {
		$uid = (int)($user->ID ?? 0);
		$parts = [
			$user->display_name ?? '', $user->user_email ?? '', $user->user_login ?? '',
			get_user_meta($uid, 'first_name', true), get_user_meta($uid, 'last_name', true),
			get_user_meta($uid, 'billing_first_name', true), get_user_meta($uid, 'billing_last_name', true),
			get_user_meta($uid, 'billing_phone', true), get_user_meta($uid, 'cptt_phone', true), get_user_meta($uid, 'mobile', true),
		];
		return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter(array_map('strval', $parts)))));
	}

	private function customer_option_label($user) {
		$uid = (int)($user->ID ?? 0);
		$phone = (string)get_user_meta($uid, 'billing_phone', true);
		if ($phone === '') $phone = (string)get_user_meta($uid, 'cptt_phone', true);
		if ($phone === '') $phone = (string)get_user_meta($uid, 'mobile', true);
		$extra = $phone ?: ($user->user_email ?? '');
		return (string)($user->display_name ?? ('#'.$uid)) . ($extra ? ' (' . $extra . ')' : '');
	}

	private function category_terms() {
		if (!taxonomy_exists('product_cat')) return [];
		$terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
		return is_wp_error($terms) ? [] : $terms;
	}

	private function get_recent_messages($project_id, $limit = 8) {
		$messages = get_post_meta($project_id, '_cptt_expert_messages', true);
		if (!is_array($messages)) return [];
		$messages = array_reverse($messages);
		$messages = array_slice($messages, 0, $limit);
		$out = [];
		foreach ($messages as $message) {
			$sender = !empty($message['sender_id']) ? get_user_by('id', (int)$message['sender_id']) : null;
			$recipient = !empty($message['recipient_id']) ? get_user_by('id', (int)$message['recipient_id']) : null;
			$out[] = [
				'sender_id' => (int)($message['sender_id'] ?? 0),
				'recipient_id' => (int)($message['recipient_id'] ?? 0),
				'sender_name' => $sender ? $sender->display_name : 'کاربر',
				'recipient_name' => $recipient ? $recipient->display_name : 'همه',
				'time' => (int)($message['time'] ?? 0),
				'time_fa' => !empty($message['time']) && class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime((int)$message['time']) : '',
				'content' => (string)($message['content'] ?? ''),
			];
		}
		return $out;
	}

	private function render_message_list($messages) {
		if (empty($messages) || !is_array($messages)) {
			echo '<div class="cptt-expert-emptyMini">پیامی ثبت نشده است.</div>';
			return;
		}
		echo '<div class="cptt-expert-noteList">';
		foreach ($messages as $message) {
			$sender = !empty($message['sender_id']) ? get_user_by('id', (int)$message['sender_id']) : null;
			$recipient = !empty($message['recipient_id']) ? get_user_by('id', (int)$message['recipient_id']) : null;
			$sender_name = $sender ? $sender->display_name : 'کاربر';
			$recipient_name = $recipient ? $recipient->display_name : 'همه';
			$time = !empty($message['time']) && class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime((int)$message['time']) : '';
			echo '<div class="cptt-expert-noteItem">';
			echo '<div class="cptt-expert-noteItem__head"><strong>' . esc_html($sender_name . ' → ' . $recipient_name) . '</strong><span>' . esc_html($time) . '</span></div>';
			echo '<div class="cptt-expert-noteItem__body">' . nl2br(esc_html((string)($message['content'] ?? ''))) . '</div>';
			echo '</div>';
		}
		echo '</div>';
	}

		public function ajax_send_message() {
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		$project_id = isset($_POST['project_id']) ? absint($_POST['project_id']) : 0;
		$recipient_id = isset($_POST['recipient_id']) ? absint($_POST['recipient_id']) : 0;
		$content = sanitize_textarea_field((string)($_POST['content'] ?? ''));
		
		if (!$project_id || get_post_type($project_id) !== 'cptt_project') wp_send_json_error('invalid_project', 400);
		if (!$this->can_manage_project($project_id, get_current_user_id())) wp_send_json_error('no_access', 403);
		if ($content === '' && empty($_FILES['chat_file']['name'])) wp_send_json_error('empty', 400);
		
		$file_url = '';
		if (!empty($_FILES['chat_file']['name'])) {
			require_once(ABSPATH . 'wp-admin/includes/file.php');
			$uploaded = wp_handle_upload($_FILES['chat_file'], ['test_form' => false]);
			if ($uploaded && !isset($uploaded['error'])) {
				$file_url = $uploaded['url'];
			}
		}

		if ($file_url) {
			$content .= "
" . '<a href="' . esc_url($file_url) . '" target="_blank" class="cptt-chat-file-link">دانلود فایل پیوست</a>';
		}

		$messages = get_post_meta($project_id, '_cptt_expert_messages', true);
		if (!is_array($messages)) $messages = [];
		$messages[] = ['sender_id' => get_current_user_id(), 'recipient_id' => $recipient_id, 'time' => (int)current_time('timestamp', true), 'content' => $content];
		update_post_meta($project_id, '_cptt_expert_messages', $messages);
		$sender = get_user_by('id', get_current_user_id());
		$this->notify_project_experts($project_id, get_current_user_id(), 'project_chat', ($sender ? $sender->display_name : 'کارشناس') . ' پیامی در چت پروژه ارسال کرد.', self::dashboard_url() . "#project-" . $project_id . '#chat-' . $project_id);
		wp_send_json_success(['messages' => $this->get_recent_messages($project_id, 8)]);
	}

	
	public function ajax_send_direct_message() {
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		$receiver_id = isset($_POST['receiver_id']) ? absint($_POST['receiver_id']) : 0;
		$message = sanitize_textarea_field((string)($_POST['message'] ?? ''));
		
		if (!$receiver_id) wp_send_json_error('invalid_receiver', 400);
		if ($message === '' && empty($_FILES['chat_file']['name'])) wp_send_json_error('empty', 400);
		
		$file_url = '';
		if (!empty($_FILES['chat_file']['name'])) {
			require_once(ABSPATH . 'wp-admin/includes/file.php');
			$uploaded = wp_handle_upload($_FILES['chat_file'], ['test_form' => false]);
			if ($uploaded && !isset($uploaded['error'])) {
				$file_url = $uploaded['url'];
			}
		}

		global $wpdb;
		$this->insert_notification($receiver_id, 'direct_chat', get_user_by('id', get_current_user_id())->display_name . ' برای شما پیام مستقیم فرستاد.', 0, self::dashboard_url() . "#directchat-" . get_current_user_id());
		$wpdb->insert(
			$wpdb->prefix . 'cptt_expert_chats',
			[
				'sender_id' => get_current_user_id(),
				'receiver_id' => $receiver_id,
				'message' => $message,
				'file_url' => $file_url,
				'created_at' => current_time('mysql')
			],
			['%d', '%d', '%s', '%s', '%s']
		);

		wp_send_json_success($this->get_direct_messages($receiver_id));
	}

	public function ajax_fetch_direct_messages() {
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		$receiver_id = isset($_POST['receiver_id']) ? absint($_POST['receiver_id']) : 0;
		if (!$receiver_id) wp_send_json_error('invalid_receiver', 400);
		
		wp_send_json_success($this->get_direct_messages($receiver_id));
	}
	
	public function ajax_get_expert_info() {
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		$expert_id = isset($_POST['expert_id']) ? absint($_POST['expert_id']) : 0;
		if (!$expert_id) wp_send_json_error('invalid_expert', 400);
		
		$user = get_user_by('id', $expert_id);
		if (!$user) wp_send_json_error('not_found', 404);
		
		$avatar = $this->get_expert_avatar_url($expert_id);
		if (!$avatar) $avatar = get_avatar_url($expert_id);
		
		$current_id = get_current_user_id();
		$args = [
			'post_type' => 'cptt_project',
			'posts_per_page' => -1,
			'post_status' => 'any',
			'meta_query' => [
				'relation' => 'AND',
				[ 'key' => '_cptt_experts_csv', 'value' => ',' . $current_id . ',', 'compare' => 'LIKE' ],
				[ 'key' => '_cptt_experts_csv', 'value' => ',' . $expert_id . ',', 'compare' => 'LIKE' ]
			]
		];
		$query = new WP_Query($args);
		$total_shared = $query->found_posts;
		$completed = 0;
		$in_progress = 0;
		foreach ($query->posts as $p) {
			$settled = (int)get_post_meta($p->ID, '_cptt_is_settled', true);
			if ($settled) $completed++; else $in_progress++;
		}
		
		wp_send_json_success([
			'name' => $user->display_name,
			'avatar' => $avatar,
			'stats' => "پروژه های مشترک: {$total_shared} (در حال انجام: {$in_progress} ، تکمیل شده: {$completed})"
		]);
	}

	private function get_direct_messages($other_id) {
		global $wpdb;
		$current_id = get_current_user_id();
		$table = $wpdb->prefix . 'cptt_expert_chats';
		$results = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$table} WHERE (sender_id = %d AND receiver_id = %d) OR (sender_id = %d AND receiver_id = %d) ORDER BY id ASC LIMIT 50",
			$current_id, $other_id, $other_id, $current_id
		));
		
		$messages = [];
		foreach ($results as $row) {
			$msg_content = $row->message;
			if (!empty($row->file_url)) {
				$msg_content .= "
" . '<a href="' . esc_url($row->file_url) . '" target="_blank" class="cptt-chat-file-link">دانلود فایل</a>';
			}
			$messages[] = [
				'sender_id' => $row->sender_id,
				'content' => $msg_content,
				'time_fa' => CPTT_Core::jalali_datetime(strtotime(get_gmt_from_date($row->created_at))),
				'sender_name' => ($row->sender_id == $current_id) ? 'شما' : get_user_by('id', $row->sender_id)->display_name
			];
		}
		return $messages;
	}

	public function ajax_delete_notification() {
		if (!is_user_logged_in()) wp_send_json_error();
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
		if ($id) {
			global $wpdb;
			$wpdb->delete($wpdb->prefix . 'cptt_notifications', ['id' => $id, 'user_id' => get_current_user_id()]);
		}
		wp_send_json_success();
	}

	public function ajax_fetch_all_notifications() {
		if (!is_user_logged_in()) wp_send_json_error();
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		global $wpdb;
		$user_id = get_current_user_id();
		$notifications = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cptt_notifications WHERE user_id = %d ORDER BY created_at DESC LIMIT 50", $user_id));
		ob_start();
		if (empty($notifications)) {
			echo '<div style="padding:15px;text-align:center;color:#666;font-size:13px;">اعلانی وجود ندارد.</div>';
		} else {
			foreach ($notifications as $notif) {
				echo '<div class="cptt-notification-item-wrap" style="position:relative;border-bottom:1px solid #eee;">';
				echo '<a href="' . esc_url($notif->link ?: '#') . '" class="cptt-notification-item ' . ($notif->is_read ? 'is-read' : '') . '" data-id="' . esc_attr($notif->id) . '" style="padding-left:35px;display:block;">';
				echo '<span class="cptt-notification-msg">' . esc_html($notif->message) . '</span>';
				echo '<span class="cptt-notification-time">' . esc_html(class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime(strtotime(get_gmt_from_date($notif->created_at))) : '') . '</span>';
				echo '</a>';
				echo '<button type="button" class="cptt-delete-notif-btn" data-id="' . esc_attr($notif->id) . '" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#ef4444;cursor:pointer;font-size:16px;" title="حذف اعلان">🗑</button>';
				echo '</div>';
			}
		}
		wp_send_json_success(['html' => ob_get_clean()]);
	}

	public function ajax_mark_single_notification_read() {
		if (!is_user_logged_in()) wp_send_json_error();
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
		if ($id) {
			global $wpdb;
			$wpdb->update($wpdb->prefix . 'cptt_notifications', ['is_read' => 1], ['id' => $id, 'user_id' => get_current_user_id()]);
		}
		wp_send_json_success();
	}

	public function ajax_mark_notifications_read() {
		if (!is_user_logged_in()) wp_send_json_error();
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		global $wpdb;
		$wpdb->update($wpdb->prefix . 'cptt_notifications', ['is_read' => 1], ['user_id' => get_current_user_id()]);
		wp_send_json_success();
	}

	public function ajax_fetch_messages() {
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		$project_id = isset($_POST['project_id']) ? absint($_POST['project_id']) : 0;
		if (!$project_id || get_post_type($project_id) !== 'cptt_project') wp_send_json_error('invalid_project', 400);
		if (!$this->can_manage_project($project_id, get_current_user_id())) wp_send_json_error('no_access', 403);
		wp_send_json_success(['messages' => $this->get_recent_messages($project_id, 8)]);
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

	private function sync_project_categories_from_product($project_id, $product_id) {
		$project_id = (int)$project_id;
		$product_id = (int)$product_id;
		if (!$project_id || !$product_id || !taxonomy_exists('product_cat')) return;
		$product_terms = get_the_terms($product_id, 'product_cat');
		if (is_wp_error($product_terms) || empty($product_terms)) return;
		$target_ids = [];
		foreach ($product_terms as $pt) $target_ids[] = (int)$pt->term_id;
		$target_ids = array_values(array_filter(array_unique($target_ids)));
		if (!empty($target_ids)) {
			update_post_meta($project_id, '_cptt_wc_cat_ids', $target_ids);
			update_post_meta($project_id, '_cptt_wc_cats_csv', ',' . implode(',', $target_ids) . ',');
		}
	}

		private function create_project($data) {
		$user_id = (int)get_current_user_id();
		$title = sanitize_text_field($data['title'] ?? '');
		$client_id = absint($data['client_user_id'] ?? 0);
		$product_id = absint($data['product_id'] ?? 0);
		$product_ids = isset($data['product_ids']) && is_array($data['product_ids']) ? array_values(array_filter(array_unique(array_map('absint', $data['product_ids'])))) : [];
		if ($product_id) array_unshift($product_ids, $product_id);
		$product_ids = array_values(array_filter(array_unique($product_ids)));
		if (!$product_id && !empty($product_ids)) $product_id = (int)$product_ids[0];
		$template_id = absint($data['template_id'] ?? 0);
		$deadline_local = trim((string)($data['deadline_local'] ?? ''));
		$note = sanitize_textarea_field((string)($data['note'] ?? ''));
		$project_label_id = sanitize_key((string)($data['project_label_id'] ?? ''));
		$expert_ids = isset($data['expert_user_ids']) && is_array($data['expert_user_ids']) ? array_values(array_filter(array_unique(array_map('absint', $data['expert_user_ids'])))) : [$user_id];
		if (empty($expert_ids)) $expert_ids = [$user_id];

		$wc_cat_ids = []; if (isset($data['wc_cat_id'])) $wc_cat_ids[] = absint($data['wc_cat_id']); if (isset($data['wc_cat_ids']) && is_array($data['wc_cat_ids'])) $wc_cat_ids = array_merge($wc_cat_ids, (array)$data['wc_cat_ids']);
		$cat_ids = array_values(array_filter(array_unique(array_map('intval', $wc_cat_ids))));

		$delivery_method = sanitize_text_field($data['delivery_method'] ?? 'in_person');
		$delivery_province = sanitize_text_field($data['delivery_province'] ?? '');
		$delivery_city = sanitize_text_field($data['delivery_city'] ?? '');
		$delivery_address = sanitize_textarea_field($data['delivery_address'] ?? '');

		if ($title === '') return new WP_Error('invalid', 'عنوان پروژه الزامی است.');
		$project_id = wp_insert_post([
			'post_type' => 'cptt_project',
			'post_status' => 'publish',
			'post_title' => $title,
			'post_author' => $user_id,
		], true);
		if (is_wp_error($project_id) || !$project_id) return new WP_Error('insert_failed', 'خطا در ایجاد پروژه');
		$project_id = (int)$project_id;
		update_post_meta($project_id, '_cptt_client_user_id', $client_id);
		update_post_meta($project_id, '_cptt_product_id', $product_id);

		$posted_titles = isset($data['create_step_titles']) ? $data['create_step_titles'] : [];
		$posted_fees = isset($data['create_step_fees']) ? $data['create_step_fees'] : [];
		$posted_qty = isset($data['create_step_qty']) ? $data['create_step_qty'] : [];
		$posted_paids = isset($data['create_step_paids']) ? $data['create_step_paids'] : [];

		$total_price = 0;
		$paid_amount = 0;
		$steps = [];

		if (!empty($posted_titles)) {
			foreach ($posted_titles as $i => $st_title) {
				$st_title = sanitize_text_field($st_title);
				if ($st_title === '') continue;

				$fee = isset($posted_fees[$i]) ? (class_exists('CPTT_Currency') ? CPTT_Currency::parse_input($posted_fees[$i]) : (float)str_replace(",", "", (string)$posted_fees[$i])) : 0;
				$qty = isset($posted_qty[$i]) ? (float)$posted_qty[$i] : 1.0;
				if ($qty < 0.01) $qty = 1.0;
				$cost = $fee * $qty;
				$paid = isset($posted_paids[$i]) ? (class_exists('CPTT_Currency') ? CPTT_Currency::parse_input($posted_paids[$i]) : (float)str_replace(",", "", (string)$posted_paids[$i])) : 0;

				$total_price += $cost;
				$paid_amount += $paid;

				$steps[] = [
					'id' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : ('st_' . wp_rand(1000, 9999)),
					'title' => $st_title,
					'status' => ($i === 0) ? 'current' : 'todo',
					'cost' => $cost,
					'paid' => $paid,
					'checklist' => [],
					'user_tasks' => [],
				];
			}
		}

		if (empty($steps)) {
			// fallback default
			$steps = [[
				'id' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : ('st_' . wp_rand(1000, 9999)),
				'title' => 'مرحله اول',
				'status' => 'current',
				'cost' => 0,
				'paid' => 0,
				'checklist' => [],
				'user_tasks' => [],
			]];
		}

		update_post_meta($project_id, '_cptt_steps', $steps);
		
		$remain_amount = $total_price - $paid_amount;
		$is_settled = ($remain_amount <= 0 && $total_price > 0) ? 1 : 0;

		update_post_meta($project_id, '_cptt_unit_price', $total_price);
		update_post_meta($project_id, '_cptt_quantity', 1);
		update_post_meta($project_id, '_cptt_total_price', $total_price);
		update_post_meta($project_id, '_cptt_paid_amount', $paid_amount);
		update_post_meta($project_id, '_cptt_remain_amount', $remain_amount);
		update_post_meta($project_id, '_cptt_is_settled', $is_settled);

		update_post_meta($project_id, '_cptt_delivery_method', $delivery_method);
		update_post_meta($project_id, '_cptt_delivery_province', $delivery_province);
		update_post_meta($project_id, '_cptt_delivery_city', $delivery_city);
		update_post_meta($project_id, '_cptt_delivery_address', $delivery_address);
		
		update_post_meta($project_id, '_cptt_expert_user_ids', $expert_ids);
		update_post_meta($project_id, '_cptt_expert_user_id', !empty($expert_ids) ? (int)$expert_ids[0] : $user_id);
		update_post_meta($project_id, '_cptt_experts_csv', ',' . implode(',', $expert_ids) . ',');
        
		foreach($expert_ids as $eid) {
			$this->insert_notification($eid, 'project_assigned', 'شما به پروژه جدید ' . $title . ' اضافه شدید.', $project_id, self::dashboard_url() . "#project-" . $project_id);
		}

		if (!empty($cat_ids)) {
			update_post_meta($project_id, '_cptt_wc_cat_ids', $cat_ids);
			update_post_meta($project_id, '_cptt_wc_cats_csv', ',' . implode(',', $cat_ids) . ',');
		}
		if ($product_id) {
			update_post_meta($project_id, '_cptt_wc_product_id', $product_id);
			update_post_meta($project_id, '_cptt_product_ids', $product_ids);
			update_post_meta($project_id, '_cptt_wc_product_ids_csv', ',' . implode(',', $product_ids) . ',');
			if (empty($cat_ids)) $this->sync_project_categories_from_product($project_id, $product_id);
		}

		// Also sync these meta values into linked WooCommerce order if present
		$order_id = (int)get_post_meta($project_id, '_cptt_wc_order_id', true);
		if ($order_id && function_exists('wc_get_order')) {
			$order = wc_get_order($order_id);
			if ($order) {
				$order->update_meta_data('_cptt_unit_price', $total_price);
				$order->update_meta_data('_cptt_quantity', 1);
				$order->update_meta_data('_cptt_total_price', $total_price);
				$order->update_meta_data('_cptt_paid_amount', $paid_amount);
				$order->update_meta_data('_cptt_remain_amount', $remain_amount);
				$order->update_meta_data('_cptt_delivery_method', $delivery_method);
				$order->update_meta_data('_cptt_delivery_province', $delivery_province);
				$order->update_meta_data('_cptt_delivery_city', $delivery_city);
				$order->update_meta_data('_cptt_delivery_address', $delivery_address);
				$order->save();
			}
		}

		if ($deadline_local !== '' && class_exists('CPTT_Core') && method_exists('CPTT_Core', 'parse_jalali_datetime')) {
			$deadline = (int) CPTT_Core::parse_jalali_datetime($deadline_local);
			if ($deadline) {
				update_post_meta($project_id, '_cptt_deadline_at', $deadline);
				update_post_meta($project_id, '_cptt_deadline_at_fa', CPTT_Core::jalali_datetime($deadline));
			}
		}
		$now = (int) current_time('timestamp', true);
		update_post_meta($project_id, '_cptt_last_update', $now);
		update_post_meta($project_id, '_cptt_last_update_fa', class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now));
		if ($note !== '') {
			update_post_meta($project_id, '_cptt_project_notes', [[
				'user_id' => $user_id,
				'time' => $now,
				'content' => $note,
			]]);
		}
		return $project_id;
	}

		public function ajax_create_customer() {
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		if (!$this->current_user_can_view_dashboard()) wp_send_json_error('no_access', 403);

		$first_name = sanitize_text_field($_POST['first_name'] ?? '');
		$last_name = sanitize_text_field($_POST['last_name'] ?? '');
		$phone = sanitize_text_field($_POST['phone'] ?? '');

		if ($first_name === '' || $last_name === '' || $phone === '') {
			wp_send_json_error('نام، نام خانوادگی و شماره موبایل الزامی است.', 400);
		}

		$full_name = trim($first_name . ' ' . $last_name);

		// Clean phone number (keep only digits)
		$phone = preg_replace('/\D/', '', $phone);
		if (strlen($phone) < 10) {
			wp_send_json_error('شماره موبایل معتبر نیست.', 400);
		}

		// Check if this phone already belongs to a customer/user. We still return success
		// so the UI can auto-select the existing customer, but we include a clear message.
		$existing_user = username_exists($phone) ? get_user_by('login', $phone) : null;
		if (!$existing_user) {
			$phone_variants = array_values(array_unique([$phone, '0' . ltrim($phone, '0'), ltrim($phone, '0')]));
			foreach (['billing_phone', 'cptt_phone', 'cptt_user_phone', 'mobile'] as $meta_key) {
				foreach ($phone_variants as $pv) {
					if ($pv === '') continue;
					$users = get_users(['meta_key' => $meta_key, 'meta_value' => $pv, 'number' => 1, 'fields' => 'all']);
					if (!empty($users)) { $existing_user = $users[0]; break 2; }
				}
			}
		}
		if ($existing_user) {
			wp_send_json_success([
				'ID' => (int)$existing_user->ID,
				'display_name' => $existing_user->display_name,
				'user_email' => $existing_user->user_email,
				'existing' => true,
				'message' => 'این شماره قبلاً ثبت‌نام شده بود؛ مشتری موجود انتخاب شد.',
			]);
		}

		$password = wp_generate_password(12, true, false);
		$user_id = wp_create_user($phone, $password, '');

		if (is_wp_error($user_id)) {
			wp_send_json_error($user_id->get_error_message(), 400);
		}

		$user_id = (int)$user_id;
		wp_update_user([
			'ID' => $user_id,
			'display_name' => $full_name,
			'nickname' => $full_name,
			'first_name' => $first_name,
			'last_name' => $last_name,
		]);

		update_user_meta($user_id, 'first_name', $first_name);
		update_user_meta($user_id, 'last_name', $last_name);
		update_user_meta($user_id, 'billing_first_name', $first_name);
		update_user_meta($user_id, 'billing_last_name', $last_name);
		update_user_meta($user_id, 'billing_phone', $phone);
		
		$user = get_user_by('id', $user_id);
		if ($user) {
			$user->set_role('customer');
		}

		wp_send_json_success([
			'ID' => $user_id,
			'display_name' => $full_name,
			'user_email' => '',
		]);
	}

	public function ajax_create_project() {
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		if (!$this->current_user_can_view_dashboard()) wp_send_json_error('no_access', 403);
		$project_id = $this->create_project($_POST);
		if (is_wp_error($project_id)) {
			wp_send_json_error($project_id->get_error_message(), 400);
		}
		wp_send_json_success(['project_id' => (int)$project_id, 'redirect' => self::dashboard_url()]);
	}

			private function render_create_project_form() {
		$customers = $this->customer_users();
		$products = $this->products();
		$templates = $this->templates();
		$cats = $this->category_terms();
		$experts_all = $this->get_all_project_users();
		$project_labels = class_exists('CPTT_Core') ? CPTT_Core::get_project_labels() : [];
		
		$vis = class_exists('CPTT_Settings') ? CPTT_Settings::get_fields_visibility() : [
			'client' => '1', 'category' => '1', 'product' => '1', 'experts' => '1', 'template' => '1', 'deadline' => '1', 'delivery' => '1', 'financial' => '1'
		];
		?>
		<form class="cptt-expert-create-form">
				<div class="cptt-createProjectGrid">
					<label><span>عنوان پروژه</span><input type="text" name="title" placeholder="مثلاً پروژه طراحی سایت مشتری"></label>
					
					<?php if (($vis['client'] ?? '1') === '1'): ?>
					<label><span>مشتری</span><select name="client_user_id"><option value="">— انتخاب مشتری —</option><?php foreach ($customers as $u): ?><option value="<?php echo esc_attr($u->ID); ?>" data-search="<?php echo esc_attr($this->customer_option_search($u)); ?>"><?php echo esc_html($this->customer_option_label($u)); ?></option><?php endforeach; ?></select></label>
					<?php endif; ?>
					
					<?php if (($vis['category'] ?? '1') === '1'): ?>
					<label><span>دسته‌بندی</span>
						<select name="wc_cat_id" id="cptt-create-cat-select">
							<option value="">— انتخاب دسته‌بندی —</option>
							<?php foreach ($cats as $cat): ?>
							<option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_html($cat->name); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<?php endif; ?>

					<?php if (($vis['product'] ?? '1') === '1'): ?>
					<label id="cptt-create-product-wrap" style="display:none;">
						<span>محصول مرتبط</span>
						<div style="display:flex; gap:6px; align-items:stretch;">
							<select name="product_id" id="cptt-create-product-select" style="flex:1;">
								<option value="">— بدون محصول —</option>
								<?php foreach ($products as $product): 
									$prod_cats = wp_get_post_terms($product->ID, 'product_cat', ['fields' => 'ids']);
									$product_obj = function_exists('wc_get_product') ? wc_get_product($product->ID) : null;
									$price = $product_obj ? $product_obj->get_price() : 0;
								?>
								<option value="<?php echo esc_attr($product->ID); ?>" data-cats="<?php echo esc_attr(implode(',', $prod_cats)); ?>" data-price="<?php echo esc_attr($price); ?>">
									<?php echo esc_html(get_the_title($product->ID)); ?>
								</option>
								<?php endforeach; ?>
							</select>
							<button type="button" id="cptt-add-product-to-budget" title="افزودن این محصول به بودجه‌بندی" aria-label="افزودن این محصول به بودجه‌بندی" style="min-width:42px; height:42px; padding:0; border-radius:12px; background:linear-gradient(135deg,#4f46e5,#7c3aed); color:#fff; border:none; font-size:22px; font-weight:900; cursor:pointer; display:flex; align-items:center; justify-content:center; box-shadow:0 4px 12px rgba(79,70,229,.25); transition:transform .15s ease, box-shadow .15s ease;" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 6px 18px rgba(79,70,229,.35)';" onmouseout="this.style.transform=''; this.style.boxShadow='0 4px 12px rgba(79,70,229,.25)';">+</button>
						</div>
					</label>
					<div id="cptt-selected-products-chips" style="grid-column:1/-1; display:flex; flex-wrap:wrap; gap:6px; margin-top:-6px;"></div>
					<?php endif; ?>

					<?php if (($vis['template'] ?? '1') === '1'): ?>
					<label><span>تمپلیت مراحل</span>
						<select name="template_id" id="cptt-create-template-select">
							<option value="">— بدون تمپلیت —</option>
							<?php foreach ($templates as $t): 
								$steps = get_post_meta($t->ID, '_cptt_template_steps', true);
								if (!is_array($steps)) $steps = [];
								$step_titles = [];
								foreach ($steps as $s) { $step_titles[] = (string)($s['title'] ?? ''); }
								$steps_json = wp_json_encode($step_titles, JSON_UNESCAPED_UNICODE);
							?>
							<option value="<?php echo esc_attr($t->ID); ?>" data-steps="<?php echo esc_attr($steps_json); ?>"><?php echo esc_html(get_the_title($t->ID)); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<?php endif; ?>

					<?php if (($vis['experts'] ?? '1') === '1'): ?>
					<div style="grid-column: 1 / -1;">
						<span>کارشناسان پروژه</span>
						<div class="cptt-experts-card-list">
							<?php foreach ($experts_all as $u): 
								$is_curr = ((int)$u->ID === (int)get_current_user_id());
								$u_avatar = $this->get_expert_avatar_url($u->ID);
							?>
							<label class="cptt-expert-card-item">
								<input type="checkbox" name="expert_user_ids[]" value="<?php echo esc_attr($u->ID); ?>" <?php echo $is_curr ? 'checked' : ''; ?>>
								<img src="<?php echo esc_url($u_avatar); ?>" alt="">
								<span><?php echo esc_html($u->display_name); ?></span>
							</label>
							<?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>

					<?php if (($vis['deadline'] ?? '1') === '1'): ?>
					<label><span>مهلت پروژه</span><input type="text" class="cptt-jalali-datetime" name="deadline_local" placeholder="۱۴۰۳/۰۱/۳۱ ۱۴:۳۰"></label>
					<?php endif; ?>
					<label><span>لیبل پروژه</span><select name="project_label_id"><option value="">— بدون لیبل —</option><?php foreach ($project_labels as $lbl): ?><option value="<?php echo esc_attr($lbl['id']); ?>"><?php echo esc_html($lbl['name']); ?></option><?php endforeach; ?></select></label>
					
					<label class="cptt-createProjectCheck" style="display:none;"><span>وضعیت مالی</span><label><input type="checkbox" name="is_settled" value="1"> تسویه شده</label></label>
				</div>

				<?php if (($vis['financial'] ?? '1') === '1'): ?>
				<!-- Dynamic Step-by-Step Financial Budget Breakdown -->
				<div id="cptt-create-steps-finance-wrap" style="grid-column: 1 / -1; margin-top: 15px; border-top: 1px dashed #cbd5e1; padding-top: 15px;">
					<span style="font-size: 13px; font-weight: bold; color: #475569; display: block; margin-bottom: 8px;">بودجه‌بندی مراحل پروژه</span>
					<div id="cptt-create-finance-header" style="display:grid; grid-template-columns: 2.2fr 1.2fr 80px 1.4fr 1.4fr auto; gap:6px; align-items:center; margin-bottom:6px; font-size:11px; font-weight:bold; color:#64748b; padding-left:30px;">
						<span>عنوان مرحله</span>
						<span>مبلغ فی (ریال)</span>
						<span>تعداد</span>
						<span>مبلغ کل (ریال)</span>
						<span>پرداختی (ریال)</span>
					</div>
					<div id="cptt-create-steps-finance-list" style="display:grid; gap:6px;"></div>
					<button type="button" id="cptt-create-add-finance-row" class="cptt-btn" style="margin-top:10px; font-size:11px; padding:6px 12px; min-height: 30px; height: 30px; background:#f1f5f9; color:#475569; border: 1px dashed #cbd5e1; border-radius:8px; width:100%;">+ افزودن ردیف بودجه جدید</button>
				</div>

				<!-- Total Project Billing Summary (Single Row) -->
				<div class="cptt-createProjectGrid" style="grid-column: 1 / -1; border-top: 1px dashed #cbd5e1; padding-top: 15px; margin-top: 10px; grid-template-columns: 1fr 1fr 1fr auto; gap:12px; align-items:center; padding-bottom:5px;">
					<span style="font-size: 13px; font-weight: 900; color: #475569; grid-column: 1 / -1; margin-bottom: 4px;">خلاصه حساب و کتاب کل پروژه</span>
					<label style="margin:0;">
						<span>جمع کل</span>
						<input type="text" id="cptt-create-total-price" value="0" readonly style="background:#f1f5f9; width:100%;">
					</label>
					<label style="margin:0;">
						<span>پرداختی</span>
						<input type="text" id="cptt-create-paid-amount" value="0" readonly style="background:#f1f5f9; width:100%;">
					</label>
					<label style="margin:0;">
						<span>مانده</span>
						<input type="text" id="cptt-create-remaining" value="0" readonly style="background:#f1f5f9; width:100%;">
					</label>
					<div style="align-self:end; margin:0;">
						<span id="cptt-create-finance-status" class="cptt-expert-status cptt-expert-status--done" style="display:inline-flex; align-items:center; height:44px; margin:0; box-sizing:border-box;">تسویه شده</span>
					</div>
				</div>
				<?php endif; ?>

				<?php if (($vis['delivery'] ?? '1') === '1'): ?>
				<!-- Delivery Section inside popup -->
				<div id="cptt-create-address-wrap" style="grid-column: 1 / -1; border-top: 1px dashed #cbd5e1; padding-top: 15px; margin-top: 10px;">
					<span style="font-size: 13px; font-weight: 900; color: #475569; display: block; margin-bottom: 6px;">تحویل پروژه</span>
					<div class="cptt-createProjectGrid" style="grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:10px; padding:0;">
						<label style="margin:0;">
							<span>روش تحویل</span>
							<select name="delivery_method" id="cptt-create-delivery-method" style="width:100%;">
								<option value="in_person">حضوری</option>
								<option value="shipping">ارسال</option>
							</select>
						</label>
						<label id="cptt-create-province-wrap" style="display:none; margin:0;">
							<span>استان</span>
							<select name="delivery_province" id="cptt-create-province" style="width:100%;">
								<option value="">— انتخاب استان —</option>
							</select>
						</label>
					</div>
					<div class="cptt-createProjectGrid" style="grid-template-columns: 1fr 1fr; gap:10px; padding:0;">
						<label id="cptt-create-city-wrap" style="display:none; margin:0;">
							<span>شهر</span>
							<select name="delivery_city" id="cptt-create-city" style="width:100%;">
								<option value="">— انتخاب شهر —</option>
							</select>
						</label>
						<label id="cptt-create-detail-address-wrap" style="display:none; margin:0; grid-column:1/-1;">
							<span>آدرس تکمیلی</span>
							<textarea name="delivery_address" id="cptt-create-detail-address" rows="2" placeholder="خیابان، کوچه، پلاک، واحد..."></textarea>
						</label>
					</div>
				</div>
				<?php endif; ?>

				<label class="cptt-expert-noteField"><span>یادداشت اولیه</span><textarea name="note" rows="3" placeholder="در صورت نیاز توضیح اولیه ثبت کنید..."></textarea></label>
				<div class="cptt-expert-formActions">
					<button type="submit" class="cptt-btn cptt-btn--primary">ایجاد پروژه</button>
					<div class="cptt-expert-formMsg" aria-live="polite"></div>
				</div>
		</form>

		<!-- Nested New Customer Modal -->
		<div id="cptt-new-customer-modal" class="cptt-new-customer-modal-overlay" style="display:none;">
			<div class="cptt-new-customer-modal-dialog">
				<div class="cptt-ncm-header">
					<h3>👤 ثبت مشتری جدید</h3>
					<button type="button" id="cptt-cust-close" onclick="window.cpttQuickCustomerClose&&window.cpttQuickCustomerClose(event)" style="background:#f1f5f9;border:1px solid #e2e8f0;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px;color:#475569;padding:0;box-shadow:none;min-width:0;margin:0;line-height:1;">×</button>
				</div>
				<div class="cptt-ncm-body" style="display: grid; gap: 12px;">
					<div class="cptt-ncm-field">
						<label for="cptt-cust-firstname">نام</label>
						<input type="text" id="cptt-cust-firstname" placeholder="مثال: علیرضا" autocomplete="given-name">
					</div>
					<div class="cptt-ncm-field">
						<label for="cptt-cust-lastname">نام خانوادگی</label>
						<input type="text" id="cptt-cust-lastname" placeholder="مثال: محمدی" autocomplete="family-name">
					</div>
					<div class="cptt-ncm-field">
						<label for="cptt-cust-phone">شماره موبایل</label>
						<input type="tel" id="cptt-cust-phone" placeholder="مثال: ۰۹۱۲۳۴۵۶۷۸۹" autocomplete="tel">
					</div>
				</div>
				<div id="cptt-cust-msg"></div>
				<div class="cptt-ncm-footer">
					<button type="button" id="cptt-cust-submit" onclick="window.cpttQuickCustomerSubmit&&window.cpttQuickCustomerSubmit(event)" class="cptt-btn cptt-btn--primary">✔ ثبت مشتری</button>
				</div>
			</div>
		</div>

		<script>
		(function(){
			if (window.cpttQuickCustomerFallbackReady) return;
			window.cpttQuickCustomerFallbackReady = true;
			function modal(){ return document.getElementById('cptt-new-customer-modal'); }
			function parentModal(){ return document.getElementById('cptt-new-project-modal'); }
			window.cpttQuickCustomerClose = function(ev){
				if(ev){ ev.preventDefault(); ev.stopPropagation(); }
				var m=modal(); if(m){ m.style.display='none'; m.setAttribute('aria-hidden','true'); }
				var p=parentModal(); if(p){ p.style.filter=''; p.style.pointerEvents=''; p.classList.remove('cptt-modal-blurred-behind'); }
			};
			window.cpttQuickCustomerSubmit = function(ev){
				if(ev){ ev.preventDefault(); ev.stopPropagation(); }
				var btn=document.getElementById('cptt-cust-submit'), msg=document.getElementById('cptt-cust-msg');
				if(btn && btn.disabled) return;
				var fn=(document.getElementById('cptt-cust-firstname')||{}).value||'';
				var ln=(document.getElementById('cptt-cust-lastname')||{}).value||'';
				var ph=(document.getElementById('cptt-cust-phone')||{}).value||'';
				fn=fn.trim(); ln=ln.trim(); ph=ph.trim();
				if(!fn||!ln||!ph){ if(msg){msg.textContent='نام، نام خانوادگی و شماره موبایل الزامی است.'; msg.style.color='#ef4444';} return; }
				if(btn) btn.disabled=true; if(msg){msg.textContent='در حال ثبت مشتری...'; msg.style.color='#475569';}
				var fd=new FormData(); fd.append('action','cptt_expert_create_customer'); fd.append('nonce',(window.CPTT_EXPERT&&CPTT_EXPERT.nonce)||''); fd.append('first_name',fn); fd.append('last_name',ln); fd.append('phone',ph);
				fetch((window.CPTT_EXPERT&&CPTT_EXPERT.ajax)||'',{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(j){
					if(btn) btn.disabled=false;
					if(j&&j.success){
						document.querySelectorAll('select[name="client_user_id"]').forEach(function(sel){ var opt=sel.querySelector('option[value="'+j.data.ID+'"]'); if(!opt){ opt=document.createElement('option'); opt.value=j.data.ID; opt.textContent=j.data.display_name; opt.dataset.search=(j.data.display_name||'')+' '+ph; sel.appendChild(opt); } sel.value=String(j.data.ID); sel.dispatchEvent(new Event('change',{bubbles:true})); });
						if(msg){msg.textContent=(j.data&&j.data.message)||'مشتری انتخاب شد.'; msg.style.color=(j.data&&j.data.existing)?'#b45309':'#047857';}
						setTimeout(function(){ window.cpttQuickCustomerClose(); }, 450);
					}else{ if(msg){msg.textContent=(j&&j.data)?j.data:'خطا در ثبت مشتری'; msg.style.color='#ef4444';} }
				}).catch(function(){ if(btn) btn.disabled=false; if(msg){msg.textContent='خطای شبکه یا اتصال به AJAX'; msg.style.color='#ef4444';} });
			};
			document.addEventListener('click',function(e){ if(e.target&&e.target.closest&&e.target.closest('#cptt-cust-close')) window.cpttQuickCustomerClose(e); if(e.target&&e.target.closest&&e.target.closest('#cptt-cust-submit')) window.cpttQuickCustomerSubmit(e); }, true);
		})();
		</script>

		<script>
		(function() {
			var provincesAndCities = {
				"تهران": ["تهران","ورامین","شهریار","قدس","ملارد","پاکدشت","اسلامشهر","رباط کریم","فیروزکوه","دماوند","بومهن","پردیس","نسیم‌شهر","چهاردانگه","باغستان"],
				"البرز": ["کرج","فردیس","نظرآباد","هشتگرد","طالقان","چهارباغ","اشتهارد","ماهدشت","گرمدره","شهر جدید هشتگرد"],
				"اصفهان": ["اصفهان","کاشان","خمینی‌شهر","نجف‌آباد","شهرضا","فلاورجان","مبارکه","زرین‌شهر","آران و بیدگل","اردستان","سمیرم","گلپایگان","تیران","شاهین‌شهر","دهاقان","نطنز","آران","فریدن","چادگان","برخوار","لنجان"],
				"فارس": ["شیراز","مرودشت","جهرم","کازرون","فسا","داراب","لارستان","آباده","اقلید","ممسنی","خرامه","نی‌ریز","اوز","خفر","فیروزآباد","سپیدان"],
				"خراسان رضوی": ["مشهد","نیشابور","سبزوار","تربت حیدریه","قوچان","گناباد","کاشمر","تایباد","درگز","تربت جام","فریمان","خواف","رشتخوار","مه‌ولات","بردسکن","چناران","جوین"],
				"آذربایجان شرقی": ["تبریز","مراغه","میانه","اهر","مرند","سراب","هریس","بناب","ملکان","عجبشیر","بستان‌آباد","کلیبر","چاراویماق","ورزقان","شبستر","اسکو","ترکمانچای"],
				"آذربایجان غربی": ["ارومیه","خوی","مهاباد","بوکان","سلماس","میاندوآب","نقده","پیرانشهر","شاهین‌دژ","سردشت","اشنویه","تکاب","ماکو","چالدران","پلدشت"],
				"کرمان": ["کرمان","رفسنجان","جیرفت","بم","زرند","سیرجان","بافت","شهربابک","انار","راور","کوهبنان","رودبار جنوب","قلعه‌گنج","ریگان","عنبرآباد","منوجان"],
				"خوزستان": ["اهواز","آبادان","دزفول","خرمشهر","ماهشهر","شوشتر","ایذه","بهبهان","اندیمشک","مسجد سلیمان","امیدیه","رامهرمز","دشت‌آزادگان","هندیجان","اغاجاری","لالی","حمیدیه","کارون"],
				"مازندران": ["ساری","آمل","بابل","قائمشهر","نوشهر","چالوس","تنکابن","رامسر","بهشهر","نکا","جویبار","سیمرغ","بابلسر","محمودآباد","فریدونکنار","گلوگاه","مرزیکلا"],
				"گیلان": ["رشت","انزلی","لاهیجان","لنگرود","صومعه‌سرا","تالش","آستارا","رودبار","فومن","رودسر","آستانه اشرفیه","شفت","ماسال","سیاهکل","رضوانشهر","بندرانزلی"],
				"هرمزگان": ["بندرعباس","میناب","قشم","بندرلنگه","حاجی‌آباد","رودان","جاسک","ابوموسی","بشاگرد","کهنوج","سیریک","خمیر","پارسیان","بستک"],
				"سیستان و بلوچستان": ["زاهدان","زابل","چابهار","ایرانشهر","سراوان","نیکشهر","خاش","سرباز","دلگان","هیرمند","هامون","مهرستان","قصرقند"],
				"کرمانشاه": ["کرمانشاه","اسلام‌آباد","هرسین","کنگاور","سنقر","جوانرود","پاوه","دالاهو","ثلاث‌باباجانی","قصرشیرین","گیلانغرب","روانسر"],
				"گلستان": ["گرگان","گنبد کاووس","علی‌آباد","مینودشت","بندرگز","رامیان","آق‌قلا","ترکمن","کردکوی","گالیکش","کلاله","آزادشهر","مراوه‌تپه"],
				"لرستان": ["خرم‌آباد","بروجرد","کوهدشت","دورود","الیگودرز","ازنا","نورآباد","پلدختر","سلسله","رومشکان"],
				"همدان": ["همدان","ملایر","تویسرکان","نهاوند","بهار","رزن","اسدآباد","کبودرآهنگ","فامنین","قروه‌درجزین"],
				"قم": ["قم","کهک","دستجرد","جعفریه"],
				"قزوین": ["قزوین","البرز","بویین‌زهرا","تاکستان","آبیک","اقبالیه","محمدیه","شال","آوج"],
				"زنجان": ["زنجان","ابهر","خرمدره","ایجرود","طارم","ماهنشان","سلطانیه"],
				"اردبیل": ["اردبیل","مشکین‌شهر","پارس‌آباد","خلخال","بیله‌سوار","نمین","نیر","کوثر","گرمی","سرعین"],
				"بوشهر": ["بوشهر","برازجان","گناوه","دیلم","خورموج","عسلویه","جم","دشتی","تنگستان","دشتستان"],
				"مرکزی": ["اراک","ساوه","محلات","خمین","دلیجان","تفرش","آشتیان","کمیجان","شازند","زرندیه"],
				"ایلام": ["ایلام","مهران","دره‌شهر","آبدانان","دهلران","ایوان","سیروان","ملکشاهی","بدره"],
				"کهگیلویه و بویراحمد": ["یاسوج","گچساران","دوگنبدان","دنا","بهمئی","لنده","چرام","باشت"],
				"خراسان شمالی": ["بجنورد","شیروان","اسفراین","قوچان","مانه و سملقان","جاجرم","فاروج","گرمه","راز و جرگلان"],
				"خراسان جنوبی": ["بیرجند","قاین","طبس","فردوس","بشرویه","درمیان","سربیشه","خوسف","نهبندان"],
				"سمنان": ["سمنان","گرمسار","شاهرود","دامغان","مهدی‌شهر","سرخه","آرادان","میامی","شاهرود"],
				"چهارمحال و بختیاری": ["شهرکرد","بروجن","فارسان","لردگان","سامان","کوهرنگ","کیار","اردل"],
				"کردستان": ["سنندج","سقز","مریوان","بانه","قروه","کامیاران","دیواندره","بیجار","سروآباد","دهگلان"],
				"یزد": ["یزد","میبد","اردکان","بافق","ابرکوه","تفت","خاتم","مهریز","بهاباد"],
				"خراسان": ["مشهد","نیشابور","سبزوار","قوچان","درگز","گناباد","فریمان"],
				"خراسان جنوبی": ["بیرجند","قاین","طبس","فردوس","بشرویه"],
				"گیلان": ["رشت","انزلی","لاهیجان","لنگرود","صومعه‌سرا","تالش"],
				"سیستان و بلوچستان": ["زاهدان","زابل","چابهار","ایرانشهر","سراوان"]
			};

			function escapeHtml(text) {
				return text
					.replace(/&/g, "&amp;")
					.replace(/</g, "&lt;")
					.replace(/>/g, "&gt;")
					.replace(/"/g, "&quot;")
					.replace(/'/g, "&#039;");
			}

			function formatWithCommas(num) {
				var clean = num.toString().replace(/\D/g, "");
				return clean.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
			}

			function parseFromCommas(str) {
				return parseFloat(str.toString().replace(/,/g, "")) || 0;
			}

			function setupCreateFinance() {
				var templateSelect = document.getElementById('cptt-create-template-select');
				var productSelect = document.getElementById('cptt-create-product-select');
				var totalPriceInput = document.getElementById('cptt-create-total-price');
				var paidAmountInput = document.getElementById('cptt-create-paid-amount');
				var remainingInput = document.getElementById('cptt-create-remaining');
				var financeStatus = document.getElementById('cptt-create-finance-status');
				
				// Delivery chain for CREATE form
				var deliverySelect = document.getElementById('cptt-create-delivery-method');
				var provinceWrap = document.getElementById('cptt-create-province-wrap');
				var provinceSelect = document.getElementById('cptt-create-province');
				var citySelect = document.getElementById('cptt-create-city');
				var cityWrap = document.getElementById('cptt-create-city-wrap');
				var detailAddressWrap = document.getElementById('cptt-create-detail-address-wrap');

				// target ONLY the create form's client select (first match inside create form)
				var createForm = document.querySelector('.cptt-expert-create-form');
				var clientSelect = createForm ? createForm.querySelector('select[name="client_user_id"]') : null;

				// Populate provinces dropdown for create form
				if (provinceSelect) {
					provinceSelect.innerHTML = '<option value="">— انتخاب استان —</option>';
					Object.keys(provincesAndCities).sort().forEach(function(prov) {
						var opt = document.createElement('option');
						opt.value = prov;
						opt.textContent = prov;
						provinceSelect.appendChild(opt);
					});

					provinceSelect.addEventListener('change', function() {
						var prov = provinceSelect.value;
						if (prov && provincesAndCities[prov]) {
							if (citySelect) {
								citySelect.innerHTML = '<option value="">— انتخاب شهر —</option>';
								provincesAndCities[prov].forEach(function(city) {
									var opt = document.createElement('option');
									opt.value = city;
									opt.textContent = city;
									citySelect.appendChild(opt);
								});
							}
							if (cityWrap) { cityWrap.style.display = 'block'; cityWrap.style.removeProperty('display'); cityWrap.removeAttribute('style'); cityWrap.style.display = ''; }
							if (cityWrap) cityWrap.style.display = 'block';
						} else {
							if (cityWrap) cityWrap.style.display = 'none';
							if (detailAddressWrap) detailAddressWrap.style.display = 'none';
						}
					});
				}

				if (citySelect) {
					citySelect.addEventListener('change', function() {
						if (citySelect.value) {
							if (detailAddressWrap) detailAddressWrap.style.display = 'block';
						} else {
							if (detailAddressWrap) detailAddressWrap.style.display = 'none';
						}
					});
				}

				// Delivery method toggle for CREATE form (province/city chain)
				if (deliverySelect) {
					deliverySelect.addEventListener('change', function() {
						if (deliverySelect.value === 'shipping') {
							if (provinceWrap) provinceWrap.style.display = 'block';
						} else {
							if (provinceWrap) provinceWrap.style.display = 'none';
							if (cityWrap) cityWrap.style.display = 'none';
							if (detailAddressWrap) detailAddressWrap.style.display = 'none';
						}
					});
				}

				if (templateSelect) {
					templateSelect.addEventListener('change', function() {
						var selectedOpt = templateSelect.options[templateSelect.selectedIndex];
						var stepsData = selectedOpt ? selectedOpt.getAttribute('data-steps') : '';
						var steps = [];
						if (stepsData) {
							try { steps = JSON.parse(stepsData); } catch(err) {}
						}
						appendTemplateFinanceRows(steps);
					});
				}

				function appendTemplateFinanceRows(stepTitles) {
					var list = document.getElementById('cptt-create-steps-finance-list');
					if (!list) return;

					if (!stepTitles || !stepTitles.length) {
						// Always show at least 1 empty row by default
						addStepsFinanceRow('مرحله اول', 0, 1, 0);
						calculateFromStepBreakdown();
						return;
					}

					stepTitles.forEach(function(title) {
						addStepsFinanceRow(title, 0, 1, 0);
					});
					calculateFromStepBreakdown();
				}

				function addStepsFinanceRow(title, fee, qty, paid) {
					var list = document.getElementById('cptt-create-steps-finance-list');
					if (!list) return;

					var rowId = 'row_' + Math.floor(Math.random() * 100000);
					var html = '<div class="cptt-create-finance-row" id="' + rowId + '" style="display:grid; grid-template-columns: 2.2fr 1.2fr 80px 1.4fr 1.4fr auto; gap:6px; align-items:center; margin-bottom:5px;">' +
						'  <div class="cptt-bf"><span class="cptt-bf-lbl">عنوان مرحله</span><input type="text" name="create_step_titles[]" value="' + escapeHtml(title) + '" placeholder="عنوان مرحله" style="font-size:13px; padding:8px 10px; min-height:38px; height:38px; border-radius:10px; border:1px solid #cbd5e1; box-sizing:border-box; width:100%;" /></div>' +
						'  <div class="cptt-bf"><span class="cptt-bf-lbl">مبلغ فی (ریال)</span><input type="text" class="cptt-create-step-fee cptt-num-format" name="create_step_fees[]" value="' + formatWithCommas(fee) + '" placeholder="مبلغ فی" style="font-size:13px; padding:8px 10px; min-height:38px; height:38px; border-radius:10px; border:1px solid #cbd5e1; box-sizing:border-box; width:100%;" /></div>' +
						'  <div class="cptt-bf cptt-bf--qty"><span class="cptt-bf-lbl">تعداد</span><input type="number" step="any" class="cptt-create-step-qty" name="create_step_qty[]" value="' + qty + '" min="0.01" placeholder="تعداد" style="font-size:13px; padding:8px 10px; min-height:38px; height:38px; border-radius:10px; border:1px solid #cbd5e1; box-sizing:border-box; width:100%; text-align:center;" /></div>' +
						'  <div class="cptt-bf cptt-bf--total"><span class="cptt-bf-lbl">مبلغ کل</span><input type="text" class="cptt-create-step-cost" readonly style="font-size:13px; padding:8px 10px; min-height:38px; height:38px; border-radius:10px; border:1px solid #cbd5e1; box-sizing:border-box; width:100%; background:#f1f5f9; font-weight:700;" value="' + formatWithCommas(fee * qty) + '" /></div>' +
						'  <div class="cptt-bf"><span class="cptt-bf-lbl">پرداختی (ریال)</span><input type="text" class="cptt-create-step-paid cptt-num-format" name="create_step_paids[]" value="' + formatWithCommas(paid) + '" placeholder="پرداختی" style="font-size:13px; padding:8px 10px; min-height:38px; height:38px; border-radius:10px; border:1px solid #cbd5e1; box-sizing:border-box; width:100%;" /></div>' +
						'  <button type="button" class="cptt-create-remove-finance-row" style="color:#ef4444; background:#fee2e2; border:none; border-radius:50%; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; font-size:14px; font-weight:bold; padding:0; line-height:1;">×</button>' +
						'</div>';
					list.insertAdjacentHTML('beforeend', html);

					var row = document.getElementById(rowId);
					
					// Format commas on typing
					row.querySelectorAll('.cptt-num-format').forEach(function(inp) {
						inp.addEventListener('input', function(e) {
							var cursor = e.target.selectionStart;
							var oldLen = e.target.value.length;
							var clean = e.target.value.replace(/\D/g, "");
							e.target.value = formatWithCommas(clean);
							var newLen = e.target.value.length;
							e.target.setSelectionRange(cursor + (newLen - oldLen), cursor + (newLen - oldLen));
							
							calculateRowTotal(row);
						});
					});

					row.querySelector('.cptt-create-step-qty').addEventListener('input', function() {
						calculateRowTotal(row);
					});

					row.querySelector('.cptt-create-remove-finance-row').addEventListener('click', function() {
						row.remove();
						calculateFromStepBreakdown();
					});
				}

				function calculateRowTotal(row) {
					var fee = parseFromCommas(row.querySelector('.cptt-create-step-fee').value);
					var qty = parseFloat(row.querySelector('.cptt-create-step-qty').value) || 1.0;
					if (qty < 0.01) qty = 1.0;
					row.querySelector('.cptt-create-step-cost').value = formatWithCommas(fee * qty);
					calculateFromStepBreakdown();
				}

				var addBudgetBtn = document.getElementById('cptt-create-add-finance-row');
				if (addBudgetBtn) {
					addBudgetBtn.addEventListener('click', function() {
						addStepsFinanceRow('مرحله جدید', 0, 1, 0);
						calculateFromStepBreakdown();
					});
				}

				function calculateFromStepBreakdown() {
					var list = document.getElementById('cptt-create-steps-finance-list');
					if (!list) return;

					var totalCost = 0;
					var totalPaid = 0;
					var costs = list.querySelectorAll('.cptt-create-step-cost');
					var paids = list.querySelectorAll('.cptt-create-step-paid');
					
					costs.forEach(function(inp) {
						totalCost += parseFromCommas(inp.value);
					});
					paids.forEach(function(inp) {
						totalPaid += parseFromCommas(inp.value);
					});

					if (totalPriceInput) totalPriceInput.value = formatWithCommas(totalCost);
					if (paidAmountInput) paidAmountInput.value = formatWithCommas(totalPaid);

					var remain = totalCost - totalPaid;
					if (remainingInput) remainingInput.value = formatWithCommas(remain);

					if (financeStatus) {
						if (remain <= 0 && totalCost > 0) {
							financeStatus.textContent = 'تسویه شده';
							financeStatus.className = 'cptt-expert-status cptt-expert-status--done';
							financeStatus.style.background = '';
							financeStatus.style.color = '';
							financeStatus.style.borderColor = '';
						} else {
							financeStatus.textContent = 'تسویه نشده';
							financeStatus.className = 'cptt-expert-status';
							financeStatus.style.background = 'rgba(239, 68, 68, 0.14)';
							financeStatus.style.color = '#ef4444';
							financeStatus.style.borderColor = 'rgba(239, 68, 68, 0.28)';
						}
					}
				}

				// v5.4.17: دکمه «+» کنار select محصول
				// با کلیک: محصول انتخاب‌شده به بودجه‌بندی اضافه می‌شود + chip در chips نشان داده می‌شود
				// + product_ids[] و wc_cat_ids[] به‌صورت hidden ثبت می‌شوند
				// + select های دسته/محصول reset می‌شوند.
				var addProductBtn = document.getElementById('cptt-add-product-to-budget');
				var chipsWrap = document.getElementById('cptt-selected-products-chips');
				var catMain = document.getElementById('cptt-create-cat-select');

				function addProductToBudget() {
					if (!productSelect) return false;
					var opt = productSelect.options[productSelect.selectedIndex];
					if (!opt || !opt.value) return false;
					var pid = opt.value;
					var title = opt.textContent.trim();
					var price = parseFloat(opt.getAttribute('data-price')) || 0;
					var catId = catMain ? catMain.value : '';
					// جلوگیری از تکرار همان محصول
					if (chipsWrap && chipsWrap.querySelector('[data-pid="' + pid + '"]')) {
						// به جای افزودن دوباره، یک ردیف اضافه با عنوان مشابه به بودجه اضافه نکن
						return false;
					}
					addStepsFinanceRow(title, price, 1, 0);
					calculateFromStepBreakdown();
					// chip
					if (chipsWrap) {
						var chip = document.createElement('span');
						chip.setAttribute('data-pid', pid);
						chip.style.cssText = 'display:inline-flex; align-items:center; gap:6px; background:#eef2ff; color:#4338ca; border:1px solid #c7d2fe; border-radius:999px; padding:5px 10px; font-size:12px; font-weight:700;';
						chip.innerHTML = '🛒 ' + escapeHtml(title) +
							' <input type="hidden" name="product_ids[]" value="' + escapeHtml(pid) + '">' +
							(catId ? '<input type="hidden" name="wc_cat_ids[]" value="' + escapeHtml(catId) + '">' : '') +
							' <button type="button" aria-label="حذف" style="background:transparent;border:none;color:#b91c1c;font-size:14px;cursor:pointer;padding:0 2px;line-height:1;">×</button>';
						chipsWrap.appendChild(chip);
						chip.querySelector('button').addEventListener('click', function(){ chip.remove(); });
					}
					// reset انتخاب
					try { productSelect.value = ''; } catch(e){}
					try { if (catMain) catMain.value = ''; } catch(e){}
					// تریگر change برای فیلتر محصول
					try {
						var ev = document.createEvent('Event'); ev.initEvent('change', true, true);
						if (catMain) catMain.dispatchEvent(ev);
					} catch(e){}
					return true;
				}

				if (addProductBtn) {
					addProductBtn.addEventListener('click', function(e) {
						e.preventDefault();
						addProductToBudget();
					});
				}

				// فیلتر محصولات بر اساس دسته‌بندی + نمایش/پنهان product wrap
				var productWrap = document.getElementById('cptt-create-product-wrap');
				function filterProductsByCat() {
					if (!productSelect || !catMain) return;
					var cid = catMain.value;
					var hasAny = false;
					Array.prototype.forEach.call(productSelect.options, function(opt) {
						if (!opt.value) return;
						var cats = opt.getAttribute('data-cats') || '';
						var match = !cid || cats.split(',').indexOf(cid) > -1;
						opt.style.display = match ? '' : 'none';
						if (match) hasAny = true;
					});
					if (productWrap) productWrap.style.display = (cid && hasAny) ? '' : (cid ? '' : 'none');
					if (!cid && productWrap) productWrap.style.display = 'none';
					// reset انتخاب اگر دیگر مطابق نیست
					if (productSelect.value) {
						var curOpt = productSelect.options[productSelect.selectedIndex];
						if (curOpt && curOpt.style.display === 'none') productSelect.value = '';
					}
				}
				if (catMain) {
					catMain.addEventListener('change', filterProductsByCat);
					filterProductsByCat();
				}

				// New customer trigger is handled by expert.js initNewCustomerModals()
				// Generate default first row
				appendTemplateFinanceRows([]);
			}

			// New Customer modal is handled by expert.js (bindNewCustomerSubmit)
			
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', setupCreateFinance);
			} else {
				setupCreateFinance();
			}
		})();
		</script>
		<?php
	}

	private function should_show_mood_modal($user_id) {
		$user_id = (int)$user_id;
		if (!$user_id || !$this->current_user_can_view_dashboard()) return false;
		return (string)get_user_meta($user_id, 'pm_last_mood_check', true) !== current_time('Y-m-d');
	}

	private function get_mood_emojis($user_id) {
		$defaults = [1 => '😟', 2 => '😐', 3 => '😄'];
		$custom = get_user_meta((int)$user_id, 'pm_mood_emojis', true);
		if (!is_array($custom)) $custom = [];
		return [
			1 => sanitize_text_field($custom[1] ?? $defaults[1]),
			2 => sanitize_text_field($custom[2] ?? $defaults[2]),
			3 => sanitize_text_field($custom[3] ?? $defaults[3]),
		];
	}

	private function render_mood_modal($user_id) {
		if (!$this->should_show_mood_modal($user_id)) return;
		$emojis = $this->get_mood_emojis($user_id);
		?>
		<div class="cptt-moodOverlay" id="cptt-mood-modal" data-em1="<?php echo esc_attr($emojis[1]); ?>" data-em2="<?php echo esc_attr($emojis[2]); ?>" data-em3="<?php echo esc_attr($emojis[3]); ?>" aria-modal="true" role="dialog">
			<div class="cptt-moodCard" data-mood="2">
				<div class="cptt-moodInfo">i</div>
				<div class="cptt-moodTop">امروز حال و حوصله‌ات چطوره؟</div>
				<svg class="cptt-moodFace" viewBox="0 0 220 150" aria-hidden="true">
					<g id="cptt-mood-eyes">
						<circle id="cptt-mood-eye-l" cx="76" cy="58" r="18"></circle>
						<circle id="cptt-mood-eye-r" cx="144" cy="58" r="18"></circle>
					</g>
					<path id="cptt-mood-mouth" d="M82 105 Q110 105 138 105" fill="none" stroke="currentColor" stroke-width="10" stroke-linecap="round"></path>
				</svg>
				<div class="cptt-moodEmoji" id="cptt-mood-emoji"><?php echo esc_html($emojis[2]); ?></div>
				<div class="cptt-moodText" id="cptt-mood-text">بد نیستم/معمولی</div>
				<div class="cptt-moodSliderWrap">
					<input type="range" min="1" max="3" step="0.01" value="2" id="cptt-mood-range" class="cptt-moodRange" dir="ltr">
					<div class="cptt-moodTicks"><span>بد</span><span>بد نیستم</span><span>عالی</span></div>
				</div>
				<input type="text" id="cptt-mood-note" class="cptt-moodNote" placeholder="یادداشت اختیاری؛ مثلاً امروز سرم شلوغه" hidden>
				<div class="cptt-moodActions"><button type="button" class="cptt-moodNoteBtn" id="cptt-mood-note-toggle">یادداشت</button><button type="button" class="cptt-moodSubmit" id="cptt-mood-submit">ثبت حال امروز ←</button></div>
				<div class="cptt-moodMsg" id="cptt-mood-msg"></div>
			</div>
		</div>
		<?php
	}

	public function ajax_save_theme() {
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		if (!$this->current_user_can_view_dashboard()) wp_send_json_error('no_access', 403);
		$theme = sanitize_key((string)($_POST['theme'] ?? 'classic'));
		$allowed = ['classic','skeuo','three-d','glass','neumorph','minimal','dark'];
		if (!in_array($theme, $allowed, true)) $theme = 'classic';
		update_user_meta(get_current_user_id(), 'cptt_expert_dashboard_theme', $theme);
		wp_send_json_success(['theme' => $theme]);
	}

	public function ajax_save_mood() {
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		if (!$this->current_user_can_view_dashboard()) wp_send_json_error('no_access', 403);
		$uid = (int)get_current_user_id();
		$mood = isset($_POST['mood']) ? absint($_POST['mood']) : 2;
		if (!in_array($mood, [1,2,3], true)) $mood = 2;
		$note = sanitize_text_field((string)($_POST['note'] ?? ''));
		$closed = !empty($_POST['closed']) ? 1 : 0;
		update_user_meta($uid, 'pm_last_mood_check', current_time('Y-m-d'));
		update_user_meta($uid, 'pm_current_mood', $mood);
		update_user_meta($uid, 'pm_current_mood_note', $note);
		update_user_meta($uid, 'pm_current_mood_closed', $closed);
		update_user_meta($uid, 'pm_current_mood_at', current_time('mysql'));
		wp_send_json_success(['emoji' => get_expert_mood_emoji($uid)]);
	}

	public function ajax_save_own_profile() {
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		if (!$this->current_user_can_view_dashboard()) wp_send_json_error('no_access', 403);
		$uid = get_current_user_id();
		$display_name = sanitize_text_field($_POST['display_name'] ?? '');
		$title = sanitize_text_field($_POST['title'] ?? '');
		$bio = sanitize_textarea_field($_POST['bio'] ?? '');
		$specialties = sanitize_text_field($_POST['specialties'] ?? '');
		$email = sanitize_email($_POST['email'] ?? '');
		$avatar_id = absint($_POST['avatar_id'] ?? 0);
		$password = $_POST['password'] ?? '';
		$mood_emojis = [1 => sanitize_text_field($_POST['mood_emoji_1'] ?? '😟'), 2 => sanitize_text_field($_POST['mood_emoji_2'] ?? '😐'), 3 => sanitize_text_field($_POST['mood_emoji_3'] ?? '😄')];
		$update = ['ID' => $uid];
		if ($display_name) $update['display_name'] = $display_name;
		if ($email && is_email($email)) $update['user_email'] = $email;
		if ($password !== '') $update['user_pass'] = $password;
		wp_update_user($update);
		update_user_meta($uid, 'cptt_expert_title', $title);
		update_user_meta($uid, 'cptt_expert_short_bio', $bio);
		update_user_meta($uid, 'cptt_expert_specialties', $specialties);
		if ($avatar_id) update_user_meta($uid, 'cptt_expert_avatar_id', $avatar_id);
		update_user_meta($uid, 'pm_mood_emojis', $mood_emojis);
		wp_send_json_success(['message' => 'پروفایل با موفقیت ذخیره شد.']);
	}

	public function ajax_upload_avatar() {
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		if (!$this->current_user_can_view_dashboard()) wp_send_json_error('no_access', 403);
		if (empty($_FILES['avatar_file']['name'])) wp_send_json_error('no_file', 400);
		require_once(ABSPATH . 'wp-admin/includes/file.php');
		require_once(ABSPATH . 'wp-admin/includes/image.php');
		require_once(ABSPATH . 'wp-admin/includes/media.php');
		$upload = wp_handle_upload($_FILES['avatar_file'], ['test_form' => false]);
		if (isset($upload['error'])) wp_send_json_error($upload['error'], 400);
		$attachment_id = wp_insert_attachment([
			'post_mime_type' => $upload['type'],
			'post_title' => 'avatar-' . get_current_user_id(),
			'post_status' => 'inherit',
		], $upload['file']);
		if (is_wp_error($attachment_id)) wp_send_json_error('upload_fail', 400);
		$metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
		wp_update_attachment_metadata($attachment_id, $metadata);
		update_user_meta(get_current_user_id(), 'cptt_expert_avatar_id', $attachment_id);
		wp_send_json_success(['id' => $attachment_id, 'url' => $upload['url']]);
	}


	public function shortcode_dashboard($atts) {
		if (!is_user_logged_in()) {
			return '<div class="cptt-notice">برای مشاهده داشبورد کارشناس باید وارد حساب کاربری شوید.</div>';
		}
		if (!$this->current_user_can_view_dashboard()) {
			return '<div class="cptt-notice">این صفحه فقط برای کارشناسان و مدیران قابل مشاهده است.</div>';
		}

		$this->enqueue_assets();
		$user_id = (int) get_current_user_id();
		$projects = $this->get_expert_projects($user_id);
		$stats = $this->collect_dashboard_stats($projects);
		$clients_map = []; $products_map = []; $cats_map = [];
		foreach ($projects as $__p) {
			$__data = $this->project_card_data($__p->ID);
			$clients_map[(int)$__data['customer_id']] = $__data['customer'];
			$products_map[(int)$__data['product_id']] = $__data['product'];
			if (!empty($__data['term_ids']) && !empty($__data['term_names'])) {
				foreach ($__data['term_ids'] as $__i => $__tid) { if (!empty($__data['term_names'][$__i])) $cats_map[(int)$__tid] = $__data['term_names'][$__i]; }
			}
		}
		asort($clients_map); asort($products_map); asort($cats_map);
		$current_user = wp_get_current_user();
		ob_start();
		?>
		<div class="cptt-wrap cptt-expertWrap cptt-v2-scope" dir="rtl">
			<?php $this->render_mood_modal($user_id); ?>
			<div class="cptt-expertLayout">
				<aside class="cptt-expertSidebar">

					<!-- دکمه‌های ویرایش پروفایل و خروج -->
					<div class="cptt-sidebar-userActions" style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
						<a href="<?php echo esc_url(wp_nonce_url(self::dashboard_url() . '&cptt_edit_profile=1', 'cptt_edit_profile')); ?>" class="cptt-btn cptt-btn--secondary" style="flex:1;font-size:12px;padding:8px 10px;min-height:36px;text-align:center;">✏️ ویرایش پروفایل</a>
						<a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>" class="cptt-btn" style="flex:1;font-size:12px;padding:8px 10px;min-height:36px;text-align:center;background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;" onclick="return confirm('از حساب کاربری خارج می‌شوید؟');">🚪 خروج</a>
					</div>

					<div class="cptt-sidebar-controls desktop-only" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; background: #fff; padding: 12px; border-radius: 16px; border: 1px solid #e6ebf2; box-shadow: 0 4px 10px rgba(0,0,0,0.03); position:relative; z-index: 10000000 !important;">
						<button type="button" class="cptt-dark-toggle-icon" title="تغییر حالت شب/روز" style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:50%;width:42px;height:42px;display:flex;align-items:center;justify-content:center;cursor:pointer;">
							<svg class="cptt-svg-moon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
							<svg class="cptt-svg-sun" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
						</button>
						<select class="cptt-theme-select" title="انتخاب تم داشبورد" aria-label="انتخاب تم داشبورد">
							<option value="classic">پیش‌فرض</option>
							<option value="skeuo">اسکئومورفیسم</option>
							<option value="three-d">سه‌بعدی</option>
							<option value="glass">گلس مورفیسم</option>
							<option value="neumorph">نئومورفیک</option>
							<option value="minimal">مینیمال</option>
							<option value="dark">تاریک</option>
						</select>
						<div class="cptt-notification-bell" style="margin-bottom:0;">
							<?php
								global $wpdb;
								$unread_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM {$wpdb->prefix}cptt_notifications WHERE user_id = %d AND is_read = 0", get_current_user_id()));
								$notifications = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cptt_notifications WHERE user_id = %d ORDER BY created_at DESC LIMIT 5", get_current_user_id()));
							?>
							<button type="button" class="cptt-bell-btn" style="background:#f8fafc;border:1px solid #cbd5e1;">
								<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
								<?php if ($unread_count > 0): ?><span class="cptt-bell-badge"><?php echo esc_html($unread_count); ?></span><?php endif; ?>
							</button>
							<div class="cptt-notifications-dropdown" hidden>
								<div class="cptt-notifications-header">
									<strong>اعلان‌ها</strong>
									<button type="button" id="cptt-mark-all-read" style="background:none;border:none;color:#2271b1;cursor:pointer;font-size:12px;">خواندن همه</button>
								</div>
								<div class="cptt-notifications-list">
									<?php if (empty($notifications)): ?>
										<div style="padding:15px;text-align:center;color:#666;font-size:13px;">اعلان جدیدی وجود ندارد.</div>
									<?php else: foreach ($notifications as $notif): ?>
										<div class="cptt-notification-item-wrap" style="position:relative;border-bottom:1px solid #f1f5f9;">
											<a href="<?php echo esc_url($notif->link ?: '#'); ?>" class="cptt-notification-item <?php echo $notif->is_read ? 'is-read' : ''; ?>" data-id="<?php echo esc_attr($notif->id); ?>" style="padding-left:35px;display:block;">
												<span class="cptt-notification-msg"><?php echo esc_html($notif->message); ?></span>
												<span class="cptt-notification-time"><?php echo esc_html(class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime(strtotime(get_gmt_from_date($notif->created_at))) : ''); ?></span>
											</a>
											<button type="button" class="cptt-delete-notif-btn" data-id="<?php echo esc_attr($notif->id); ?>" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#ef4444;cursor:pointer;font-size:16px;" title="حذف اعلان">🗑</button>
										</div>
									<?php endforeach; endif; ?>
								</div>
								<div style="padding:10px; border-top:1px solid #e2e8f0; text-align:center;">
									<button type="button" class="cptt-btn cptt-btn--secondary" data-cptt-open-all-notifs style="width:100%;font-size:12px;padding:8px;">مشاهده تمام اعلان‌ها</button>
								</div>
							</div>
						</div>
					</div>

					<div class="cptt-sideBox cptt-sideBox--profile">
						<div class="cptt-sideBox__title" style="display:flex;justify-content:space-between;align-items:center;">
							<span>پروفایل کارشناس</span>
							<span style="font-size:11px;font-weight:normal;color:#64748b;"><?php 
								$now_ts = current_time('timestamp', true);
								echo esc_html($this->get_persian_day_of_week($now_ts) . ' ' . (class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now_ts) : date('Y-m-d H:i'))); 
							?></span>
						</div>
						<div class="cptt-expertProfile" style="display:flex; align-items:center; gap:10px;">
							<?php
								$curr_avatar = $this->get_expert_avatar_url($current_user->ID);
								if (!$curr_avatar) $curr_avatar = get_avatar_url($current_user->ID);
							?>
							<span class="cptt-moodAvatarWrap"><img src="<?php echo esc_url($curr_avatar); ?>" alt="avatar" style="width:65px; height:65px; border-radius:50%; object-fit:cover;"><?php if (function_exists('get_expert_mood_emoji') && get_expert_mood_emoji($current_user->ID)): ?><b class="cptt-moodAvatarBadge"><?php echo esc_html(get_expert_mood_emoji($current_user->ID)); ?></b><?php endif; ?></span>
							<div>
								<strong><?php echo esc_html($current_user->display_name); ?></strong>
								<span style="display:block; font-size:12px; color:#666;"><?php echo esc_html($current_user->user_email); ?></span>
							</div>
						</div>
					</div>

					<div class="cptt-sideBox">
						<div class="cptt-sideBox__title">خلاصه سریع</div>
						<ul class="cptt-sideList">
							<li><span>کل پروژه‌ها</span><strong><?php echo esc_html(number_format_i18n($stats['total'])); ?></strong></li>
							<li><span>پروژه فعال</span><strong><?php echo esc_html(number_format_i18n($stats['in_progress'])); ?></strong></li>
							<li><span>تکمیل‌شده</span><strong><?php echo esc_html(number_format_i18n($stats['completed'])); ?></strong></li>
							<li><span>تاخیرها</span><strong><?php echo esc_html(number_format_i18n(count($stats['overdue']))); ?></strong></li>
						</ul>
					</div>
					<button type="button" class="cptt-newProjectCta desktop-only" data-cptt-open-newproject>
						ایجاد پروژه جدید
					</button>

					<?php
					$other_experts = $this->get_all_project_users();
								$current_uid = get_current_user_id();
								$other_experts = array_values(array_filter($other_experts, function($u) use ($current_uid) { return (int)$u->ID !== (int)$current_uid; }));
					if (!empty($other_experts)):
					?>
					<div class="cptt-experts-list-container desktop-only">
						<div class="cptt-sideBox__title" style="margin-top:20px;">همکاران کارشناس</div>
						<div class="cptt-experts-vertical-list">
							<?php foreach($other_experts as $oe): 
								$oe_avatar = $this->get_expert_avatar_url($oe->ID);
								if (!$oe_avatar) $oe_avatar = get_avatar_url($oe->ID);
							?>
							<div class="cptt-expert-list-item" data-expert-id="<?php echo esc_attr($oe->ID); ?>">
								<span class="cptt-moodAvatarWrap cptt-moodAvatarWrap--small"><img src="<?php echo esc_url($oe_avatar); ?>" alt="<?php echo esc_attr($oe->display_name); ?>"><?php if (function_exists('get_expert_mood_emoji') && get_expert_mood_emoji($oe->ID)): ?><b class="cptt-moodAvatarBadge"><?php echo esc_html(get_expert_mood_emoji($oe->ID)); ?></b><?php endif; ?></span>
								<span><?php echo esc_html($oe->display_name); ?></span><small style="font-size:11px;color:#666;"><?php echo esc_html($this->get_user_role_label($oe->ID)); ?></small>
							</div>
							<?php endforeach; ?>
						</div>
					</div>
					

					<!-- Mobile Experts Modal -->
					<div class="cptt-experts-mobile-modal" hidden>
						<div class="cptt-experts-mobile-modal__backdrop"></div>
						<div class="cptt-experts-mobile-modal__dialog">
							<button type="button" class="cptt-experts-mobile-modal__close">×</button>
							<div class="cptt-sideBox__title">همکاران کارشناس</div>
							<div class="cptt-experts-vertical-list">
								<?php foreach($other_experts as $oe): 
									$oe_avatar = $this->get_expert_avatar_url($oe->ID);
									if (!$oe_avatar) $oe_avatar = get_avatar_url($oe->ID);
								?>
								<div class="cptt-expert-list-item" data-expert-id="<?php echo esc_attr($oe->ID); ?>">
									<span class="cptt-moodAvatarWrap cptt-moodAvatarWrap--small"><img src="<?php echo esc_url($oe_avatar); ?>" alt="<?php echo esc_attr($oe->display_name); ?>"><?php if (function_exists('get_expert_mood_emoji') && get_expert_mood_emoji($oe->ID)): ?><b class="cptt-moodAvatarBadge"><?php echo esc_html(get_expert_mood_emoji($oe->ID)); ?></b><?php endif; ?></span>
									<span><?php echo esc_html($oe->display_name); ?></span>
								</div>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
					<?php endif; ?>

				</aside>
				<div class="cptt-expertMain">
			<!-- Mobile FAB & Menu -->
			<button type="button" class="cptt-mobile-fab mobile-only">☰</button>
			<div class="cptt-mobile-menu" hidden>
			    <div class="cptt-mobile-menu__backdrop"></div>
			    <div class="cptt-mobile-menu__dialog">
			        <div class="cptt-mobile-menu__header">
			            <div style="display:flex; align-items:center; gap:16px;">
			                <button type="button" class="cptt-dark-toggle-icon" style="background:none;border:none;cursor:pointer;color:#475569;display:flex;align-items:center;justify-content:center;">
			                <svg class="cptt-svg-moon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
			                <svg class="cptt-svg-sun" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
			            </button>
			                <div class="cptt-notification-bell" style="margin-bottom:0;display:flex;align-items:center;">
			                    <?php
			                    	$unread_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM {$wpdb->prefix}cptt_notifications WHERE user_id = %d AND is_read = 0", get_current_user_id()));
			                    ?>
			                    <button type="button" class="cptt-bell-btn cptt-mobile-bell-btn" style="background:none;border:none;position:relative;cursor:pointer;color:#475569;display:flex;align-items:center;">
			                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
			                        <?php if ($unread_count > 0): ?><span class="cptt-bell-badge" style="position:absolute;top:-5px;right:-5px;background:#ef4444;color:#fff;font-size:10px;border-radius:50%;width:16px;height:16px;display:flex;align-items:center;justify-content:center;font-weight:bold;"><?php echo esc_html($unread_count); ?></span><?php endif; ?>
			                    </button>
			                </div>
			            </div>
                        <button type="button" class="cptt-mobile-menu__close" style="margin-left:0;background:none;border:none;font-size:26px;color:#64748b;cursor:pointer;">×</button>
			        </div>
			        
                    <div style="padding:15px 0; border-bottom:1px solid #e2e8f0; margin-bottom:15px; display:flex; justify-content:center; text-align:center; width:100%;">
						<div class="cptt-expertProfileMobile" style="display:flex; flex-direction:column; align-items:center; gap:10px; text-align:center; width:100%;">
							<img src="<?php echo esc_url($curr_avatar); ?>" alt="avatar" style="width:60px; height:60px; border-radius:50%; object-fit:cover; display:block; margin:0 auto;">
							<div>
								<strong style="display:block;font-size:14px;"><?php echo esc_html($current_user->display_name); ?></strong>
								<span style="display:block; font-size:11px; color:#666;"><?php echo esc_html($current_user->user_email); ?></span>
							</div>
						</div>
                    </div>

			        <div style="margin-top:20px; display:grid; gap:10px;">
			            <button type="button" class="cptt-btn cptt-btn--secondary cptt-open-experts-modal-btn" style="width:100%;">💬 گفتگو با کارشناسان</button>
			            <button type="button" class="cptt-newProjectCta" data-cptt-open-newproject style="width:100%;">ایجاد پروژه جدید</button>
                        <a href="<?php echo esc_url(self::public_hub_url()); ?>" class="cptt-btn cptt-btn--secondary" style="width:100%;">مشاهده تمام پروژه‌ها</a>
			        </div>
			    </div>
			</div>
			
			<!-- All Notifications Modal -->
			<div class="cptt-all-notifs-modal" hidden>
			    <div class="cptt-all-notifs-modal__backdrop" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999998;backdrop-filter:blur(4px);"></div>
			    <div class="cptt-all-notifs-modal__dialog" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(500px, 90vw);max-height:80vh;background:#fff;border-radius:16px;z-index:9999999;display:flex;flex-direction:column;box-shadow:0 20px 40px rgba(0,0,0,0.2);">
			        <div style="padding:15px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;">
			            <strong style="font-size:16px;">تمام اعلان‌ها</strong>
			            <button type="button" class="cptt-all-notifs-modal__close" style="background:none;border:none;font-size:24px;cursor:pointer;">×</button>
			        </div>
			        <div class="cptt-all-notifs-list" style="overflow-y:auto;flex:1;padding:10px;">در حال بارگذاری...</div>
			    </div>
			</div>

			<section class="cptt-proHero cptt-proHero--expert">
				<div class="cptt-proHero__content">
					<span class="cptt-proHero__eyebrow">داشبورد حرفه‌ای کارشناس</span>
					<h1 class="cptt-proHero__title">سلام <?php echo esc_html($current_user->display_name); ?> 👋</h1>
					<p class="cptt-proHero__text">تمام پروژه‌های محول‌شده، مراحل باز، یادداشت‌ها و وضعیت مشتری‌ها را در یک صفحه یکپارچه مدیریت کنید.</p>
					<div class="cptt-proHero__actions">
                        <button type="button" class="cptt-btn cptt-btn--primary mobile-only" data-cptt-open-newproject style="width:100%;">ایجاد پروژه جدید</button>
						<a class="cptt-btn cptt-btn--primary" href="<?php echo esc_url(self::dashboard_url()); ?>">بروزرسانی داشبورد</a>
						<a class="cptt-btn cptt-btn--secondary" href="<?php echo esc_url(self::public_hub_url()); ?>">مشاهده تمام پروژه‌ها</a>
					</div>
				</div>
				<div class="cptt-proHero__visual">
					<div class="cptt-proHero__metric"><strong><?php echo esc_html(number_format_i18n($stats['total'])); ?></strong><span>کل پروژه‌های شما</span></div>
					<div class="cptt-proHero__metric"><strong><?php echo esc_html(number_format_i18n($stats['today'] ? count($stats['today']) : 0)); ?></strong><span>کارهای امروز</span></div>
					<div class="cptt-proHero__metric"><strong><?php echo esc_html(number_format_i18n($stats['overdue'] ? count($stats['overdue']) : 0)); ?></strong><span>تاخیرها</span></div>
				</div>
			</section>

			<div class="cptt-kpiGrid cptt-kpiGrid--expert">
				<div class="cptt-kpiCard"><div class="cptt-kpiCard__label">پروژه‌های فعال</div><div class="cptt-kpiCard__value"><?php echo esc_html(number_format_i18n($stats['in_progress'])); ?></div></div>
				<div class="cptt-kpiCard"><div class="cptt-kpiCard__label">پروژه‌های تکمیل‌شده</div><div class="cptt-kpiCard__value"><?php echo esc_html(number_format_i18n($stats['completed'])); ?></div></div>
				<div class="cptt-kpiCard"><div class="cptt-kpiCard__label">مراحل باز</div><div class="cptt-kpiCard__value"><?php echo esc_html(number_format_i18n($stats['open_steps'])); ?></div></div>
				<div class="cptt-kpiCard"><div class="cptt-kpiCard__label">تسک‌های باز مشتری</div><div class="cptt-kpiCard__value"><?php echo esc_html(number_format_i18n($stats['customer_pending'])); ?></div></div>
			</div>

			<div class="cptt-expertInsights">
				<div class="cptt-insightBox">
					<div class="cptt-insightBox__title">کارهای امروز</div>
					<?php if (!empty($stats['today'])): ?><ul><?php foreach (array_slice($stats['today'], 0, 3) as $item): ?><li><strong><?php echo esc_html($item['project_title']); ?></strong><span><?php echo esc_html($item['step_title']); ?></span></li><?php endforeach; ?></ul><?php else: ?><div class="cptt-expert-emptyMini">برای امروز مرحله سررسیدشده‌ای ثبت نشده است.</div><?php endif; ?>
				</div>
				<div class="cptt-insightBox cptt-insightBox--warn">
					<div class="cptt-insightBox__title">مراحل عقب‌افتاده</div>
					<?php if (!empty($stats['overdue'])): ?><ul><?php foreach (array_slice($stats['overdue'], 0, 3) as $item): ?><li><strong><?php echo esc_html($item['project_title']); ?></strong><span><?php echo esc_html($item['step_title'] . ($item['due_fa'] ? ' — ' . $item['due_fa'] : '')); ?></span></li><?php endforeach; ?></ul><?php else: ?><div class="cptt-expert-emptyMini">هیچ مرحله عقب‌افتاده‌ای ندارید.</div><?php endif; ?>
				</div>
			</div>

			

			<?php if (class_exists('CPTT_Analytics')): ?>
			<details class="cptt-analytics-toggle" style="background:#fff;border:1px solid #e6ebf2;border-radius:20px;padding:0;box-shadow:0 6px 18px rgba(15,23,42,0.04);margin-bottom:8px;">
				<summary style="padding:16px 20px;cursor:pointer;font-size:15px;font-weight:950;color:#0f172a;list-style:none;display:flex;align-items:center;justify-content:space-between;">
					<span>📊 گزارش عملکرد من</span>
					<span style="font-size:20px;transition:transform 0.2s;">⌄</span>
				</summary>
				<div style="padding:0 20px 20px;">
					<?php echo CPTT_Analytics::instance()->render_expert_dashboard_analytics($user_id); ?>
				</div>
			</details>
			<?php endif; ?>

			<div class="cptt-mobile-filter-trigger mobile-only">
			    <button type="button" class="cptt-btn cptt-btn--secondary" id="cptt-mobile-filter-btn">جستجو و فیلتر پروژه‌ها 🔍</button>
			</div>

			<div class="cptt-expertFilters" id="cptt-expert-filters-wrap">
				<label>جستجو<input type="search" id="cptt-expert-search" placeholder="عنوان پروژه، مشتری، محصول..."></label>
				<label>وضعیت<select id="cptt-expert-status"><option value="">همه</option><option value="completed">تکمیل شده</option><option value="in_progress">در حال انجام</option></select></label>
				<label>تسویه<select id="cptt-expert-settled"><option value="">همه</option><option value="1">تسویه شده</option><option value="0">تسویه نشده</option></select></label>
				<label>مشتری<select id="cptt-expert-client"><option value="">همه</option><?php foreach ($clients_map as $id => $name): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
				<label>محصول<select id="cptt-expert-product"><option value="">همه</option><?php foreach ($products_map as $id => $name): if (!$id) continue; ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
				<label>دسته‌بندی<select id="cptt-expert-cat"><option value="">همه</option><?php foreach ($cats_map as $id => $name): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
				<label>لیبل<select id="cptt-expert-label"><option value="">همه</option><?php foreach ((class_exists('CPTT_Core') ? CPTT_Core::get_project_labels() : []) as $l): ?><option value="<?php echo esc_attr($l['id']); ?>"><?php echo esc_html($l['name']); ?></option><?php endforeach; ?></select></label>
				<button type="button" class="cptt-btn" id="cptt-expert-reset">پاک کردن فیلترها</button>
				<button type="button" class="cptt-btn" id="cptt-kanban-toggle">📌 نمایش Kanban</button>
			</div>

			<?php
				$kanban_steps = [];
				foreach ($projects as $__p) {
					$steps = get_post_meta($__p->ID, '_cptt_steps', true);
					if (!is_array($steps)) $steps = [];
					foreach ($steps as $__s) {
						if (!is_array($__s) || empty($__s['id'])) continue;
						$kanban_steps[] = [
							'project_id' => $__p->ID,
							'project_title' => get_the_title($__p->ID),
							'step_id' => $__s['id'],
							'title' => $__s['title'] ?? '',
							'status' => $__s['status'] ?? 'todo',
						];
					}
				}
			?>
			<div id="cptt-kanban-data" data-kanban="<?php echo esc_attr(base64_encode(wp_json_encode($kanban_steps, JSON_UNESCAPED_UNICODE))); ?>" hidden></div>
			<div id="cptt-kanban-board" hidden></div>
			<div class="cptt-expertGrid" id="cptt-expert-grid">
				<?php if (empty($projects)): ?>
					<div class="cptt-empty">در حال حاضر پروژه‌ای به شما اختصاص داده نشده است.</div>
				<?php else: foreach ($projects as $p):
					$data = $this->project_card_data($p->ID);
					$label_id = is_array($data['label']) ? (string)$data['label']['id'] : '';
					$search = strtolower(get_the_title($p->ID) . ' ' . $data['customer'] . ' ' . $data['product'] . ' ' . implode(' ', $data['experts']) . ' ' . ($data['code'] ?? ''));
				?>
				<?php
				$_card_experts_for_step = [];
				foreach ((array)$data['experts'] as $_exp_name) {
					// match name to ID via expert_ids from card
				}
				// build expert list for step-expert modal
				$_step_experts_raw = CPTT_Core::get_project_expert_ids($p->ID);
				$_step_experts_list = [];
				foreach ($_step_experts_raw as $_se_id) {
					$_se_user = get_user_by('id', (int)$_se_id);
					if ($_se_user) { $_av = $this->get_expert_avatar_url((int)$_se_id); if (!$_av) $_av = get_avatar_url((int)$_se_id); $_step_experts_list[] = ['id' => (int)$_se_id, 'name' => $_se_user->display_name, 'avatar' => $_av]; }
				}
				$_step_experts_b64 = base64_encode(wp_json_encode($_step_experts_list, JSON_UNESCAPED_UNICODE));
			$_proj_label = is_array($data['label'] ?? null) ? $data['label'] : null;
			$_status_text = $_proj_label ? $_proj_label['name'] : '';
			$_status_style = $_proj_label ? 'style="background:'.esc_attr($_proj_label['color']).'22;color:'.esc_attr($_proj_label['color']).';border-color:'.esc_attr($_proj_label['color']).'55;"' : '';
			$_tl_steps = isset($data['timeline_steps']) && is_array($data['timeline_steps']) ? $data['timeline_steps'] : [];
			$_tl_count = count($_tl_steps); $_tl_progress = 0;
			if ($_tl_count > 1) { $_last = 0; foreach ($_tl_steps as $_i => $_st) { if (in_array((string)($_st['status'] ?? 'todo'), ['done','current'], true)) $_last = $_i; } $_tl_progress = ($_last / max(1, $_tl_count - 1)) * 100; } elseif ($_tl_count === 1) { $_tl_progress = 100; }
			?>
			<article class="cptt-expertCard" data-project-id="<?php echo esc_attr($p->ID); ?>" data-search="<?php echo esc_attr($search); ?>" data-status="<?php echo esc_attr($data['progress']['status']); ?>" data-settled="<?php echo esc_attr((string)$data['settled']); ?>" data-client="<?php echo esc_attr((string)$data['customer_id']); ?>" data-product="<?php echo esc_attr((string)$data['product_id']); ?>" data-cats=",<?php echo esc_attr(implode(',', array_map('intval', (array)$data['term_ids']))); ?>," data-label="<?php echo esc_attr($label_id); ?>" data-project-experts="<?php echo esc_attr($_step_experts_b64); ?>">
					<div class="cptt-expertCard__top">
						<div>
							<h3><?php echo esc_html(get_the_title($p->ID)); ?> <span class="cptt-project-code">#<?php echo esc_html($data['code'] ?? (class_exists('CPTT_Core') ? CPTT_Core::get_project_code($p->ID) : '')); ?></span></h3>
							<div class="cptt-expertCard__meta">مشتری: <?php echo esc_html($data['customer']); ?><?php echo $data['product'] !== '—' ? esc_html(' — محصول: ' . $data['product']) : ''; ?></div>
							<?php if (!empty($data['experts'])): ?><div class="cptt-expertCard__meta">کارشناسان: <?php echo esc_html(implode('، ', $data['experts'])); ?></div><?php endif; ?>
						</div>
						<?php if ($_proj_label): ?><span class="cptt-expertStatusBadge cptt-projectLabelBadge" <?php echo $_status_style; ?>><?php echo esc_html($_status_text); ?></span><?php endif; ?>
					</div>
					<?php /* <div class="cptt-expertCard__progress"><span style="width:<?php echo esc_attr($data['progress']['percent']); ?>%"></span></div> */ ?>

					<?php if (!empty($_tl_steps)): ?>
					<div class="cptt-publicTimeline cptt-expertCardTimeline" style="--cptt-hub-progress:<?php echo esc_attr(number_format((float)$_tl_progress, 2, '.', '')); ?>%;">
						<div class="cptt-publicTimeline__track"><span></span></div>
						<div class="cptt-publicTimeline__items">
							<?php foreach ($_tl_steps as $_step): ?>
							<div class="cptt-publicTimeline__item cptt-publicTimeline__item--<?php echo esc_attr($_step['status']); ?>"><span class="cptt-publicTimeline__dot"></span><span class="cptt-publicTimeline__label"><?php echo esc_html($_step['title']); ?></span></div>
							<?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>

					<div class="cptt-expertCard__stats">
						<div><strong><?php echo esc_html($data['progress']['percent']); ?>%</strong><span>پیشرفت</span></div>
						<div><strong><?php echo esc_html(number_format_i18n($data['checklist_done'])); ?>/<?php echo esc_html(number_format_i18n($data['checklist_total'])); ?></strong><span>چک‌لیست</span></div>
						<div><strong><?php echo esc_html(number_format_i18n($data['user_tasks_done'])); ?>/<?php echo esc_html(number_format_i18n($data['user_tasks_total'])); ?></strong><span>تسک مشتری</span></div>
						<div><strong><?php echo esc_html(number_format_i18n((int)$data['financial']['remain'])); ?></strong><span>مانده مالی</span></div>
					</div>
					<div class="cptt-expertCard__infoGrid">
						<div><span>مهلت پروژه</span><strong class="cptt-show-day" data-date="<?php echo esc_attr($data['deadline']); ?>"><?php echo esc_html($data['deadline'] ?: '—'); ?></strong></div>
						<div><span>آخرین بروزرسانی</span><strong class="cptt-expert-last-update cptt-show-day" data-date="<?php echo esc_attr($data['last_update']); ?>"><?php echo esc_html($data['last_update'] ?: '—'); ?></strong></div>
						<div><span>وضعیت مالی</span><strong><?php echo $data['settled'] ? 'تسویه شده' : 'تسویه نشده'; ?></strong></div>
						<div><span>هزینه / دریافتی</span><strong><?php echo esc_html(number_format_i18n((int)$data['financial']['cost']) . ' / ' . number_format_i18n((int)$data['financial']['paid'])); ?></strong></div>
						<div><span>روش تحویل</span><strong><?php echo esc_html($data['delivery_method_label'] ?: '—'); ?></strong></div>
						<?php if (!empty($data['delivery_province'])): ?>
						<div><span>استان / شهر</span><strong><?php echo esc_html($data['delivery_province'] . ($data['delivery_city'] ? ' / ' . $data['delivery_city'] : '')); ?></strong></div>
						<?php endif; ?>
						<?php if (!empty($data['delivery_address'])): ?>
						<div style="grid-column:1/-1;"><span>آدرس ارسال</span><strong><?php echo esc_html($data['delivery_address']); ?></strong></div>
						<?php endif; ?>
					</div>
					<div class="cptt-expertCard__actions">
						<button type="button" class="cptt-btn cptt-btn--primary cptt-expert-toggleProject">مدیریت پروژه</button>
						<?php if (current_user_can("delete_cptt_project")): ?><button type="button" class="cptt-btn cptt-btn--danger cptt-expert-delete-project" data-project-id="<?php echo esc_attr($p->ID); ?>">🗑 حذف پروژه</button><?php endif; ?>
						
						<!-- Proforma Invoice Button -->
						<a class="cptt-btn cptt-btn--secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cptt_view_invoice&project_id=' . $p->ID), 'cptt_view_invoice_' . $p->ID)); ?>" target="_blank" rel="noopener noreferrer">📄 پیش‌فاکتور</a>

						<?php if (class_exists('CPTT_Report') && CPTT_Report::is_project_complete($p->ID)): ?>
							<a class="cptt-btn" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cptt_view_report&project_id=' . $p->ID), 'cptt_view_report_' . $p->ID)); ?>" target="_blank" rel="noopener noreferrer">مشاهده گزارش</a>
						<?php endif; ?>
					</div>
					<div class="cptt-expertCard__details" hidden>
						<div class="cptt-expertCard__panels">
							<div class="cptt-expertCard__mainPanel">
								<?php $this->render_project_manage_form($p->ID); ?>
							</div>
							<aside class="cptt-expertCard__sidePanel">
								<div class="cptt-sideBox">
									<div class="cptt-sideBox__title">آخرین یادداشت‌ها</div>
									<div class="cptt-expert-notesWrap">
										<?php $this->render_note_list($data['notes']); ?>
									</div>
								</div>
								<div class="cptt-sideBox">
									<div class="cptt-sideBox__title">پیام بین کارشناسان</div>
									<button type="button" class="cptt-btn cptt-expert-chat-launch">باز کردن چت پروژه</button>
									<div class="cptt-expert-chatModal" hidden>
										<div class="cptt-expert-chatModal__backdrop"></div>
										<div class="cptt-expert-chatModal__dialog">
											<button type="button" class="cptt-expert-chatModal__close">×</button>
											<div class="cptt-sideBox__title">چت کارشناسان پروژه</div>
											
											<form class="cptt-expert-message-form" data-project-id="<?php echo esc_attr($p->ID); ?>">
												<input type="hidden" name="project_id" value="<?php echo esc_attr($p->ID); ?>">
												<select name="recipient_id">
													<option value="0">همه کارشناسان پروژه</option>
													<?php foreach (self::get_existing_experts($p->ID) as $eid): if ((int)$eid === (int)get_current_user_id()) continue; $eu = get_user_by('id', (int)$eid); if (!$eu) continue; ?><option value="<?php echo esc_attr($eid); ?>"><?php echo esc_html($eu->display_name); ?></option><?php endforeach; ?>
												</select>
												<textarea name="content" rows="3" placeholder="پیام کوتاه برای کارشناس دیگر..."></textarea>
												<input type="file" name="chat_file" id="project-chat-file-<?php echo esc_attr($p->ID); ?>" style="display:none;" />
												<div class="cptt-expert-formActions">
													<button type="button" class="cptt-btn cptt-btn--secondary" onclick="document.getElementById('project-chat-file-<?php echo esc_attr($p->ID); ?>').click();">پیوست فایل</button>
													<button type="submit" class="cptt-btn">ارسال پیام</button>
												</div>
												<div class="cptt-expert-formMsg" aria-live="polite"></div>
											</form>

											<div class="cptt-expert-notesWrap cptt-expert-messagesWrap">
												<?php $this->render_message_list($data['messages']); ?>
											</div>
										</div>
									</div>
								</div>
								<div class="cptt-sideBox">
									<div class="cptt-sideBox__title">خلاصه پروژه</div>
									<ul class="cptt-sideList">
										<li><span>کل مراحل</span><strong><?php echo esc_html(number_format_i18n($data['progress']['total'])); ?></strong></li>
										<li><span>مرحله‌های تکمیل‌شده</span><strong><?php echo esc_html(number_format_i18n($data['progress']['done'])); ?></strong></li>
										<li><span>چک‌لیست انجام‌شده</span><strong><?php echo esc_html(number_format_i18n($data['checklist_done'])); ?></strong></li>
										<li><span>تسک مشتری باز</span><strong><?php echo esc_html(number_format_i18n(max(0, $data['user_tasks_total'] - $data['user_tasks_done']))); ?></strong></li>
									</ul>
								</div>
							</aside>
						</div>
					</div>
				</article>
				<?php endforeach; endif; ?>
			</div>
			<div class="cptt-dashboard__empty" id="cptt-expert-empty" hidden>هیچ پروژه‌ای با این فیلترها پیدا نشد.</div>
				</div>
			</div>

			<!-- ========== EDIT PROFILE MODAL ========== -->
			<?php
			$cur_user = wp_get_current_user();
			$cur_title = (string)get_user_meta($current_user->ID, 'cptt_expert_title', true);
			$cur_bio = (string)get_user_meta($current_user->ID, 'cptt_expert_short_bio', true);
			$cur_specialties = (string)get_user_meta($current_user->ID, 'cptt_expert_specialties', true);
			$cur_avatar_id = (int)get_user_meta($current_user->ID, 'cptt_expert_avatar_id', true);
			$cur_avatar_url = $cur_avatar_id ? wp_get_attachment_image_url($cur_avatar_id, 'medium') : $this->get_expert_avatar_url($current_user->ID, 80);
			$cur_mood_emojis = $this->get_mood_emojis($current_user->ID);
			?>
			<div class="cptt-editProfileModal" id="cptt-edit-profile-modal" hidden>
				<div class="cptt-editProfileModal__backdrop" id="cptt-edit-profile-backdrop"></div>
				<div class="cptt-editProfileModal__dialog" role="dialog" aria-modal="true" aria-labelledby="cptt-ep-title">
					<div class="cptt-editProfileModal__header">
						<h3 id="cptt-ep-title">ویرایش پروفایل</h3>
						<button type="button" class="cptt-editProfileModal__close" aria-label="بستن">×</button>
					</div>
					<form class="cptt-editProfileForm" id="cptt-edit-profile-form">
						<div class="cptt-editProfile__avatarWrap">
							<div class="cptt-editProfile__avatarPreview" id="cptt-ep-avatar-preview">
								<?php if ($cur_avatar_url): ?><img src="<?php echo esc_url($cur_avatar_url); ?>" alt="avatar"><?php else: ?><span><?php echo esc_html(mb_substr($current_user->display_name,0,1)); ?></span><?php endif; ?>
							</div>
							<div>
								<input type="hidden" id="cptt-ep-avatar-id" name="avatar_id" value="<?php echo esc_attr((string)$cur_avatar_id); ?>">
								<button type="button" class="cptt-btn cptt-btn--secondary" id="cptt-ep-avatar-btn" style="font-size:12px;padding:6px 12px;min-height:32px;">📷 تغییر عکس</button>
							</div>
						</div>
						<div class="cptt-editProfile__grid">
							<label><span>نام نمایشی</span><input type="text" name="display_name" value="<?php echo esc_attr($current_user->display_name); ?>"></label>
							<label><span>ایمیل</span><input type="email" name="email" value="<?php echo esc_attr($current_user->user_email); ?>"></label>
							<label><span>سمت / عنوان</span><input type="text" name="title" value="<?php echo esc_attr($cur_title); ?>" placeholder="مثلاً کارشناس طراحی سایت"></label>
							<label><span>تخصص‌ها (با کاما جدا کنید)</span><input type="text" name="specialties" value="<?php echo esc_attr($cur_specialties); ?>" placeholder="طراحی سایت، سئو، پشتیبانی"></label>
							<label class="cptt-editProfile__fullWidth"><span>بیوگرافی کوتاه</span><textarea name="bio" rows="3" placeholder="چند جمله درباره خودتان..."><?php echo esc_textarea($cur_bio); ?></textarea></label>
							<div class="cptt-editProfile__fullWidth cptt-moodEmojiSettings"><span>ایموجی‌های وضعیت روزانه</span><label>بد <input type="text" name="mood_emoji_1" value="<?php echo esc_attr($cur_mood_emojis[1]); ?>"></label><label>معمولی <input type="text" name="mood_emoji_2" value="<?php echo esc_attr($cur_mood_emojis[2]); ?>"></label><label>عالی <input type="text" name="mood_emoji_3" value="<?php echo esc_attr($cur_mood_emojis[3]); ?>"></label></div>
							<label class="cptt-editProfile__fullWidth"><span>رمز عبور جدید (اگر نمی‌خواهید تغییر دهید خالی بگذارید)</span><input type="password" name="password" value="" autocomplete="new-password"></label>
						</div>
						<div class="cptt-editProfile__actions">
							<button type="submit" class="cptt-btn cptt-btn--primary">💾 ذخیره تغییرات</button>
							<div class="cptt-editProfile__msg" id="cptt-ep-msg" aria-live="polite"></div>
						</div>
					</form>
				</div>
			</div>

			<!-- ========== NEW PROJECT MODAL ========== -->
			<div class="cptt-newProjectModal" id="cptt-new-project-modal" aria-hidden="true" style="z-index: 9999999999 !important;">
				<div class="cptt-newProjectModal__backdrop" data-cptt-close-newproject></div>
				<div class="cptt-newProjectModal__dialog" role="dialog" aria-modal="true" aria-labelledby="cptt-new-project-modal-title">
					<div class="cptt-newProjectModal__header">
						<div>
							<h2 class="cptt-newProjectModal__title" id="cptt-new-project-modal-title">ایجاد پروژه جدید</h2>
							<p class="cptt-newProjectModal__subtitle">اطلاعات پروژه را وارد کنید. کارشناسان و مشتری بعداً قابل ویرایش هستند.</p>
						</div>
						<button type="button" class="cptt-newProjectModal__close" data-cptt-close-newproject aria-label="بستن">×</button>
					</div>
					<div class="cptt-newProjectModal__body">
						<?php $this->render_create_project_form(); ?>
					</div>
				</div>
			</div>
		</div>

	<!-- Direct Expert Chat Modal -->
	<div class="cptt-direct-chat-modal" hidden>
		<div class="cptt-direct-chat-modal__backdrop"></div>
		<div class="cptt-direct-chat-modal__dialog">
			<button type="button" class="cptt-direct-chat-modal__close">×</button>
			<div class="cptt-direct-chat-header">
				<img id="direct-chat-avatar" src="" alt="Avatar">
				<div class="cptt-direct-chat-info">
					<strong id="direct-chat-name"></strong>
					<span id="direct-chat-stats"></span>
				</div>
			</div>
			<div class="cptt-direct-chat-messages" id="direct-chat-messages-container"></div>
			<form class="cptt-direct-chat-form">
				<input type="hidden" name="receiver_id" id="direct-chat-receiver-id" value="">
				<textarea name="message" rows="2" placeholder="پیام خود را بنویسید..."></textarea>
				<input type="file" name="chat_file" id="direct-chat-file" style="display:none;" />
				<div class="cptt-expert-formActions">
					<button type="button" class="cptt-btn cptt-btn--secondary" onclick="document.getElementById('direct-chat-file').click();">پیوست فایل</button>
					<button type="submit" class="cptt-btn cptt-btn--primary">ارسال</button>
				</div>
				<div id="direct-chat-file-name" style="font-size:12px; margin-top:5px; color:#555;"></div>
				<div class="cptt-expert-formMsg" id="direct-chat-form-msg" aria-live="polite"></div>
			</form>
		</div>
	</div>

	<!-- Floating Back to Top Button -->
	<button type="button" id="cptt-back-to-top" style="display:none;" title="برو به بالا">▲</button>

		<?php
		return ob_get_clean();
	}

	public function ajax_delete_project() {
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		$project_id = isset($_POST['project_id']) ? absint($_POST['project_id']) : 0;
		if (!$project_id || get_post_type($project_id) !== 'cptt_project') wp_send_json_error('invalid_project', 400);
		if (!current_user_can('delete_cptt_project')) wp_send_json_error('no_access', 403);
		wp_delete_post($project_id, true);
		wp_send_json_success(['deleted' => true]);
	}

	public function ajax_delete_step() {
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		$project_id = isset($_POST['project_id']) ? absint($_POST['project_id']) : 0;
		$step_id = isset($_POST['step_id']) ? sanitize_text_field($_POST['step_id']) : '';
		if (!$project_id || get_post_type($project_id) !== 'cptt_project' || $step_id === '') wp_send_json_error('invalid', 400);
		if (!$this->can_manage_project($project_id, get_current_user_id())) wp_send_json_error('no_access', 403);
		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps)) $steps = [];
		$new_steps = [];
		foreach ($steps as $s) {
			if (!is_array($s) || (string)($s['id'] ?? '') === $step_id) continue;
			$new_steps[] = $s;
		}
		update_post_meta($project_id, '_cptt_steps', $new_steps);
		$fin_data_temp = $this->financial_data($project_id);
		$is_settled_calc = ($fin_data_temp['remain'] <= 0 && $fin_data_temp['cost'] > 0) ? 1 : 0;
		update_post_meta($project_id, '_cptt_is_settled', $is_settled_calc);
		$now = (int)current_time('timestamp', true);
		update_post_meta($project_id, '_cptt_last_update', $now);
		update_post_meta($project_id, '_cptt_last_update_fa', CPTT_Core::jalali_datetime($now));
		wp_send_json_success(['step_id' => $step_id]);
	}

	public function insert_notification($user_id, $type, $message, $reference_id = 0, $link = '') {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'cptt_notifications',
			[
				'user_id' => (int)$user_id,
				'type' => sanitize_key($type),
				'reference_id' => (int)$reference_id,
				'message' => sanitize_text_field($message),
				'link' => esc_url_raw($link),
				'is_read' => 0,
				'created_at' => current_time('mysql')
			],
			['%d', '%s', '%d', '%s', '%s', '%d', '%s']
		);

		// Send Bale Messenger Notification if connected
		if (class_exists('CPTT_Bale')) {
			CPTT_Bale::notify_via_bale($user_id, $message);
		}

		// Send Email Notification to the expert/administrator
		$user_obj = get_user_by('id', (int)$user_id);
		if ($user_obj && !empty($user_obj->user_email)) {
			$subject = '🔔 اعلان جدید در سیستم مدیریت پروژه: ' . $message;
			$body = '<div dir="rtl" style="font-family:Tahoma, sans-serif; text-align:right; padding:20px; border:1px solid #e2e8f0; border-radius:12px; background:#fff; max-width:600px; margin:0 auto; box-shadow:0 4px 12px rgba(0,0,0,0.03);">';
			$body .= '<h3 style="color:#0f172a; margin:0 0 10px;">سلام ' . esc_html($user_obj->display_name) . ' عزیز</h3>';
			$body .= '<p style="color:#475569; font-size:14px; line-height:1.6; margin:0 0 15px;">یک اعلان جدید برای شما در سیستم ثبت شده است:</p>';
			$body .= '<blockquote style="background:#f1f5f9; padding:15px; border-right:4px solid #6366f1; margin:15px 0; border-radius:4px; font-weight:bold; color:#1e293b;">' . esc_html($message) . '</blockquote>';
			if ($link) {
				$body .= '<p style="margin-top:20px;"><a href="' . esc_url($link) . '" style="display:inline-block; padding:10px 20px; background:#6366f1; color:#fff !important; text-decoration:none; border-radius:8px; font-weight:bold; font-size:13px; box-shadow:0 4px 10px rgba(99,102,241,0.25);">🔗 مشاهده جزئیات و اقدام</a></p>';
			}
			$body .= '<hr style="border:none; border-top:1px solid #e2e8f0; margin-top:25px; margin-bottom:15px;">';
			$body .= '<p style="font-size:11px; color:#94a3b8; margin:0;">این ایمیل به صورت خودکار توسط سیستم Client Project Tracker ارسال شده است.</p>';
			$body .= '</div>';
			
			$headers = ['Content-Type: text/html; charset=UTF-8'];
			@wp_mail($user_obj->user_email, $subject, $body, $headers);
		}
	}

	public function notify_project_experts($project_id, $exclude_user_id, $type, $message, $link = '') {
		$expert_ids = CPTT_Core::get_project_expert_ids($project_id);
		foreach ($expert_ids as $eid) {
			if ((int)$eid === (int)$exclude_user_id) continue;
			$this->insert_notification($eid, $type, $message, $project_id, $link);
		}
	}


	public function ajax_fetch_notifications() {
		if (!is_user_logged_in()) wp_send_json_error('login_required', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		global $wpdb;
		$user_id = get_current_user_id();
		$unread = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM {$wpdb->prefix}cptt_notifications WHERE user_id = %d AND is_read = 0", $user_id));
		$notifications = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cptt_notifications WHERE user_id = %d ORDER BY created_at DESC LIMIT 5", $user_id));
		ob_start();
		if (empty($notifications)) {
			echo '<div style="padding:15px;text-align:center;color:#666;font-size:13px;">اعلان جدیدی وجود ندارد.</div>';
		} else {
			foreach ($notifications as $notif) {
				echo '<div class="cptt-notification-item-wrap" style="position:relative;border-bottom:1px solid #f1f5f9;">';
				echo '<a href="' . esc_url($notif->link ?: '#') . '" class="cptt-notification-item ' . ($notif->is_read ? 'is-read' : '') . '" data-id="' . esc_attr($notif->id) . '" style="padding-left:35px;display:block;">';
				echo '<span class="cptt-notification-msg">' . esc_html($notif->message) . '</span>';
				echo '<span class="cptt-notification-time">' . esc_html(class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime(strtotime(get_gmt_from_date($notif->created_at))) : '');
				echo '</span></a>';
				echo '<button type="button" class="cptt-delete-notif-btn" data-id="' . esc_attr($notif->id) . '" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#ef4444;cursor:pointer;font-size:16px;" title="حذف اعلان">🗑</button>';
				echo '</div>';
			}
		}
		$html = ob_get_clean();
		// آرایه‌ی items برای پوش‌نوتیفیکیشن فرانت (شامل id/type/message/link/is_read)
		$items = [];
		if (!empty($notifications)) {
			foreach ($notifications as $n) {
				$items[] = [
					'id'      => (int)$n->id,
					'type'    => (string)$n->type,
					'message' => (string)$n->message,
					'link'    => (string)$n->link,
					'is_read' => (int)$n->is_read,
				];
			}
		}
		wp_send_json_success(['unread' => $unread, 'html' => $html, 'items' => $items]);
	}


	public function add_isolation_body_class($classes) {
		if (get_query_var(self::QUERY_VAR) || get_query_var(self::PUBLIC_QUERY_VAR) || is_singular('cptt_project')) {
			$classes[] = 'cptt-v2-scope';
		}
		return $classes;
	}

}