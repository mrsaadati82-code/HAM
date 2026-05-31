<?php
if ( ! defined('ABSPATH') ) exit;

class CPTT_Admin {
	private static $instance = null;
	public static function instance() {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}
	private function __construct() {
		add_action('admin_menu', [$this, 'reorder_menu'], 999);
		add_action('add_meta_boxes', [$this, 'add_metaboxes']);
		add_action('admin_menu', [$this, 'dashboard_menu']);
		add_action('save_post_cptt_project', [$this, 'save_project_meta'], 10, 2);
		add_action('save_post_cptt_template', [$this, 'save_template_meta'], 10, 2);
		add_action('save_post_cptt_checklist_tpl', [$this, 'save_checklist_tpl_meta'], 10, 2);
		add_action('save_post_cptt_order', [$this, 'save_order_meta'], 10, 2);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
		add_filter('manage_cptt_project_posts_columns', [$this, 'columns']);
		add_action('manage_cptt_project_posts_custom_column', [$this, 'column_content'], 10, 2);
		add_action('wp_ajax_cptt_get_template_steps', [$this, 'ajax_get_template_steps']);
		add_action('wp_ajax_cptt_get_checklist_tpl', [$this, 'ajax_get_checklist_tpl']);
		add_action('wp_ajax_cptt_quick_pay', [$this, 'ajax_quick_pay']);
		add_action('wp_ajax_cptt_expert_payout', [$this, 'ajax_expert_payout']);
		add_action('wp_ajax_cptt_step_settle', [$this, 'ajax_step_settle']);
		add_action('wp_ajax_cptt_step_settlement_adjust', [$this, 'ajax_step_settlement_adjust']);
		add_action('wp_ajax_cptt_manual_expert_payment', [$this, 'ajax_manual_expert_payment']);
	}
	public function reorder_menu() {
		global $submenu;
		$parent = 'edit.php?post_type=cptt_project';
		if (empty($submenu[$parent])) return;
		$items = [];
		foreach ($submenu[$parent] as $item) { $items[$item[2]] = $item; }
		$ordered = [];
		if (isset($items['cptt-project-dashboard'])) { $ordered[] = $items['cptt-project-dashboard']; unset($items['cptt-project-dashboard']); }
		if (isset($items[$parent])) { $ordered[] = $items[$parent]; unset($items[$parent]); }
		if (isset($items['post-new.php?post_type=cptt_project'])) { $ordered[] = $items['post-new.php?post_type=cptt_project']; unset($items['post-new.php?post_type=cptt_project']); }
		if (isset($items['edit.php?post_type=cptt_template'])) { $ordered[] = $items['edit.php?post_type=cptt_template']; unset($items['edit.php?post_type=cptt_template']); }
		if (isset($items['edit.php?post_type=cptt_checklist_tpl'])) { $ordered[] = $items['edit.php?post_type=cptt_checklist_tpl']; unset($items['edit.php?post_type=cptt_checklist_tpl']); }
		if (isset($items['cptt-accounting'])) { $ordered[] = $items['cptt-accounting']; unset($items['cptt-accounting']); }
		if (isset($items['cptt-sms-settings'])) { $ordered[] = $items['cptt-sms-settings']; unset($items['cptt-sms-settings']); }
		if (isset($items['cptt-branding'])) { $ordered[] = $items['cptt-branding']; unset($items['cptt-branding']); }
		foreach ($items as $item) { $ordered[] = $item; }
		$submenu[$parent] = $ordered;
	}
	public function enqueue_admin_assets($hook) {
		global $post_type;
		$is_dashboard = ($hook === 'cptt_project_page_cptt-project-dashboard' || $hook === 'cptt_project_page_cptt-accounting');
		if ( ! $is_dashboard && ! in_array($post_type, ['cptt_project','cptt_template','cptt_checklist_tpl'], true) ) return;
		wp_enqueue_style('cptt-admin', CPTT_URL . 'assets/css/admin.css', [], CPTT_VERSION);
		wp_enqueue_script('jquery-ui-sortable');
		wp_enqueue_script('cptt-admin', CPTT_URL . 'assets/js/admin.js', ['jquery','jquery-ui-sortable'], CPTT_VERSION, true);
		wp_localize_script('cptt-admin', 'CPTT_ADMIN', [
			'ajax'=>admin_url('admin-ajax.php'),
			'nonce'=>wp_create_nonce('cptt_admin_nonce'),
			'branding' => class_exists('CPTT_Settings') ? CPTT_Settings::get() : [],
			'logo_url' => class_exists('CPTT_Settings') ? wp_get_attachment_image_url(CPTT_Settings::get()['logo_id'] ?? 0, 'large') : '',
		]);
	}
	public function dashboard_menu() {
		add_submenu_page('edit.php?post_type=cptt_project','داشبورد پروژه‌ها','داشبورد پروژه‌ها','edit_cptt_projects','cptt-project-dashboard',[$this,'render_dashboard_page']);
		add_submenu_page('edit.php?post_type=cptt_project','حساب و کتاب','حساب و کتاب','edit_cptt_projects','cptt-accounting',[$this,'render_accounting_page']);
		add_submenu_page('edit.php?post_type=cptt_project','لیبل‌های پروژه','لیبل‌های پروژه','manage_options','cptt-project-labels',[$this,'render_labels_page']);
		add_submenu_page('edit.php?post_type=cptt_project','مشتریان','مشتریان','manage_options','cptt-customers',[$this,'render_customers_page']);
		add_submenu_page('edit.php?post_type=cptt_project','گزارش فعالیت‌ها','گزارش فعالیت‌ها','manage_options','cptt-activity-log',[$this,'render_activity_log_page']);
	}

	public function render_labels_page() {
		if (!current_user_can('manage_options')) return;
		if (!empty($_POST['cptt_labels_nonce']) && wp_verify_nonce($_POST['cptt_labels_nonce'], 'cptt_save_labels')) {
			$names = isset($_POST['label_name']) && is_array($_POST['label_name']) ? $_POST['label_name'] : [];
			$colors = isset($_POST['label_color']) && is_array($_POST['label_color']) ? $_POST['label_color'] : [];
			$ids = isset($_POST['label_id']) && is_array($_POST['label_id']) ? $_POST['label_id'] : [];
			$out = [];
			foreach ($names as $i => $name) {
				$name = sanitize_text_field($name);
				if ($name === '') continue;
				$id = sanitize_key($ids[$i] ?? '');
				if ($id === '') $id = 'lbl_' . substr(md5($name . microtime(true) . wp_rand()), 0, 10);
				$color = (string)($colors[$i] ?? '#6366f1');
				if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#6366f1';
				$out[] = ['id'=>$id,'name'=>$name,'color'=>$color];
			}
			update_option('cptt_project_labels', $out, false);
			echo '<div class="notice notice-success"><p>لیبل‌ها ذخیره شدند.</p></div>';
		}
		$labels = class_exists('CPTT_Core') ? CPTT_Core::get_project_labels() : [];
		?>
		<div class="wrap" dir="rtl"><h1>لیبل‌های پروژه</h1>
		<form method="post">
			<?php wp_nonce_field('cptt_save_labels','cptt_labels_nonce'); ?>
			<table class="widefat striped" id="cptt-labels-table"><thead><tr><th>نام لیبل</th><th>رنگ</th><th>حذف</th></tr></thead><tbody>
			<?php foreach ($labels as $l): ?>
			<tr><td><input type="hidden" name="label_id[]" value="<?php echo esc_attr($l['id']); ?>"><input type="text" name="label_name[]" value="<?php echo esc_attr($l['name']); ?>" class="regular-text"></td><td><input type="color" name="label_color[]" value="<?php echo esc_attr($l['color']); ?>"></td><td><button type="button" class="button cptt-remove-label">×</button></td></tr>
			<?php endforeach; ?>
			</tbody></table>
			<p><button type="button" class="button" id="cptt-add-label">+ افزودن لیبل</button> <button class="button button-primary">ذخیره لیبل‌ها</button></p>
		</form></div>
		<script>
		jQuery(function($){ $('#cptt-add-label').on('click',function(){ $('#cptt-labels-table tbody').append('<tr><td><input type="hidden" name="label_id[]" value=""><input type="text" name="label_name[]" class="regular-text" placeholder="مثلاً فوری"></td><td><input type="color" name="label_color[]" value="#6366f1"></td><td><button type="button" class="button cptt-remove-label">×</button></td></tr>'); }); $(document).on('click','.cptt-remove-label',function(){ $(this).closest('tr').remove(); }); });
		</script>
		<?php
	}



	public function render_activity_log_page() {
		if (!current_user_can('manage_options')) return;
		global $wpdb;
		$rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}cptt_activity ORDER BY id DESC LIMIT 300");
		?>
		<div class="wrap cptt-activity-admin" dir="rtl"><style>.cptt-act-hero{background:linear-gradient(135deg,#111827,#2563eb);color:#fff;border-radius:22px;padding:22px;margin:18px 0}.cptt-act-list{display:grid;gap:8px}.cptt-act-item{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:12px;display:grid;grid-template-columns:140px 1fr 160px;gap:10px;align-items:center;box-shadow:0 4px 14px rgba(15,23,42,.04)}.cptt-act-badge{background:#eef2ff;color:#4338ca;border-radius:999px;padding:4px 9px;font-weight:800;font-size:11px}@media(max-width:700px){.cptt-act-item{grid-template-columns:1fr}}</style><div class="cptt-act-hero"><h1>🧾 گزارش فعالیت‌ها</h1><p>ردیابی تغییرات مهم مالی، فاکتورها، مشتریان و پروژه‌ها بدون شلوغ کردن صفحات اصلی.</p></div><div class="cptt-act-list">
		<?php foreach ($rows as $r): $u=$r->user_id?get_user_by('id',(int)$r->user_id):null; ?>
		<div class="cptt-act-item"><div><span class="cptt-act-badge"><?php echo esc_html($r->action); ?></span></div><div><b><?php echo esc_html($r->message); ?></b><br><small><?php echo esc_html($r->object_type.' #'.$r->object_id); ?></small></div><div><small><?php echo esc_html($u?$u->display_name:'سیستم'); ?><br><?php echo esc_html($r->created_at); ?></small></div></div>
		<?php endforeach; if(empty($rows)): ?><div class="cptt-act-item">فعلاً فعالیتی ثبت نشده است.</div><?php endif; ?></div></div><?php
	}

	public function render_customers_page() {
		if (!current_user_can('manage_options')) return;
		if (!empty($_GET['cptt_delete_customer'])) {
			$del = absint($_GET['cptt_delete_customer']);
			if ($del && check_admin_referer('cptt_delete_customer_'.$del) && get_current_user_id() !== $del) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user($del);
				echo '<div class="notice notice-success"><p>مشتری حذف شد.</p></div>';
			}
		}
		if (!empty($_POST['cptt_customer_nonce']) && wp_verify_nonce($_POST['cptt_customer_nonce'], 'cptt_save_customer')) {
			$uid = absint($_POST['customer_id'] ?? 0);
			if ($uid) {
				wp_update_user(['ID'=>$uid,'display_name'=>sanitize_text_field($_POST['display_name'] ?? ''),'user_email'=>sanitize_email($_POST['email'] ?? '')]);
				update_user_meta($uid,'first_name',sanitize_text_field($_POST['first_name'] ?? ''));
				update_user_meta($uid,'last_name',sanitize_text_field($_POST['last_name'] ?? ''));
				update_user_meta($uid,'billing_phone',sanitize_text_field($_POST['phone'] ?? ''));
				echo '<div class="notice notice-success"><p>اطلاعات مشتری ذخیره شد.</p></div>';
			}
		}

		$search_query = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
		$args = [
			'orderby' => 'registered',
			'order'   => 'DESC',
			'number'  => 500,
		];
		if ($search_query !== '') {
			$args['search'] = '*' . $search_query . '*';
			$args['search_columns'] = ['user_login', 'user_nicename', 'user_email', 'display_name'];
		}
		$users = get_users($args);
		?>
		<div class="cptt-customers-admin" dir="rtl"><style>
		.cptt-customers-admin{margin:20px 20px 20px 0;font-family:inherit}.cptt-cust-hero{background:linear-gradient(135deg,#0f172a,#4f46e5);color:#fff;border-radius:24px;padding:24px;margin-bottom:18px}.cptt-cust-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px}.cptt-cust-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:16px;box-shadow:0 8px 22px rgba(15,23,42,.06)}.cptt-cust-card h3{margin:0 0 10px;color:#0f172a}.cptt-cust-form{display:grid;grid-template-columns:1fr 1fr;gap:8px}.cptt-cust-form input{width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:8px}.cptt-cust-actions{grid-column:1/-1;display:flex;gap:8px;justify-content:flex-end}.cptt-bale-ok{color:#059669;font-weight:900}.cptt-bale-no{color:#dc2626;font-weight:900}
		.cptt-cust-search-bar{margin-bottom:16px; display:flex; gap:8px;}
		.cptt-cust-search-bar input{flex:1; border:1px solid #cbd5e1; border-radius:12px; padding:10px 15px;}
		</style>
		<div class="cptt-cust-hero">
			<h1>👥 مشتریان</h1>
			<p>مدیریت اختصاصی مشتریان، اطلاعات تماس و وضعیت عضویت در بله</p>
		</div>

		<form method="get" class="cptt-cust-search-bar">
			<input type="hidden" name="post_type" value="cptt_project">
			<input type="hidden" name="page" value="cptt-customers">
			<input type="search" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="جستجوی نام، ایمیل یا نام کاربری مشتری...">
			<button type="submit" class="button button-primary" style="height:auto; border-radius:12px; padding:0 20px;">جستجو</button>
		</form>

		<div class="cptt-cust-grid">
		<?php foreach ($users as $u): $roles=(array)$u->roles; if (in_array('administrator',$roles,true) || in_array('cptt_expert',$roles,true)) continue; $phone=(string)get_user_meta($u->ID,'billing_phone',true); if($phone==='')$phone=(string)get_user_meta($u->ID,'cptt_phone',true); $bale=(string)get_user_meta($u->ID,'_cptt_bale_chat_id',true); ?>
		<div class="cptt-cust-card"><h3><?php echo esc_html($u->display_name ?: $u->user_login); ?></h3><div style="font-size:12px;color:#64748b;margin-bottom:10px;">عضویت بله: <?php echo $bale?'<span class="cptt-bale-ok">دارد</span> <code>'.esc_html($bale).'</code>':'<span class="cptt-bale-no">ندارد</span>'; ?></div>
		<form method="post" class="cptt-cust-form"><?php wp_nonce_field('cptt_save_customer','cptt_customer_nonce'); ?><input type="hidden" name="customer_id" value="<?php echo esc_attr($u->ID); ?>"><label>نام<input name="first_name" value="<?php echo esc_attr(get_user_meta($u->ID,'first_name',true)); ?>"></label><label>نام خانوادگی<input name="last_name" value="<?php echo esc_attr(get_user_meta($u->ID,'last_name',true)); ?>"></label><label>نام نمایشی<input name="display_name" value="<?php echo esc_attr($u->display_name); ?>"></label><label>موبایل<input name="phone" value="<?php echo esc_attr($phone); ?>"></label><label style="grid-column:1/-1;">ایمیل<input name="email" value="<?php echo esc_attr($u->user_email); ?>"></label><div class="cptt-cust-actions"><button class="button button-primary">ذخیره</button><a class="button" href="<?php echo esc_url(get_edit_user_link($u->ID)); ?>">وردپرس</a><a class="button button-link-delete" onclick="return confirm('حذف شود؟')" href="<?php echo esc_url(wp_nonce_url(admin_url('edit.php?post_type=cptt_project&page=cptt-customers&cptt_delete_customer='.$u->ID),'cptt_delete_customer_'.$u->ID)); ?>">حذف</a></div></form></div>
		<?php endforeach; ?></div></div><?php
	}

	public function add_metaboxes() {
		add_meta_box('cptt_project_details','اطلاعات پروژه',[$this,'render_details_metabox'],'cptt_project','normal','high');
		add_meta_box('cptt_project_steps','مراحل پروژه (Stepper)',[$this,'render_steps_metabox_project'],'cptt_project','normal','high');
		add_meta_box('cptt_template_steps','مراحل تمپلیت',[$this,'render_steps_metabox_template'],'cptt_template','normal','high');
		add_meta_box('cptt_checklist_tpl_items','آیتم‌های تمپلیت چک‌لیست',[$this,'render_checklist_tpl_metabox'],'cptt_checklist_tpl','normal','high');
		add_meta_box('cptt_project_notes','یادداشت‌های کارشناسان',[$this,'render_notes_metabox'],'cptt_project','side','default');
		add_meta_box('cptt_project_accounting','حساب و کتاب پروژه',[$this,'render_accounting_metabox'],'cptt_project','side','default');

		// === v5.4.7: Order metaboxes ===
		add_meta_box('cptt_order_details','جزئیات سفارش',[$this,'render_order_details_metabox'],'cptt_order','normal','high');
		add_meta_box('cptt_order_actions','عملیات سفارش',[$this,'render_order_actions_metabox'],'cptt_order','side','default');
	}

	/* v5.4.7: metabox برای cptt_order */
	public function render_order_details_metabox($post) {
		$client_id = (int) get_post_meta($post->ID, '_cptt_order_client_id', true);
		$client = $client_id ? get_user_by('id', $client_id) : null;
		$type   = (string) get_post_meta($post->ID, '_cptt_order_type', true);
		$desc   = (string) get_post_meta($post->ID, '_cptt_order_description', true);
		$addr   = (string) get_post_meta($post->ID, '_cptt_order_address', true);
		$files  = get_post_meta($post->ID, '_cptt_order_files', true);
		$files  = is_array($files) ? $files : [];
		$status = (string) get_post_meta($post->ID, '_cptt_order_status', true) ?: 'pending';
		$created_fa = (string) get_post_meta($post->ID, '_cptt_order_created_at_fa', true);
		$assigned_exp = (int) get_post_meta($post->ID, '_cptt_order_assigned_expert', true);
		$proj_id = (int) get_post_meta($post->ID, '_cptt_order_project_id', true);

		$phone = $client ? (string) get_user_meta($client->ID, 'billing_phone', true) : '';
		$bale  = $client ? (string) get_user_meta($client->ID, '_cptt_bale_chat_id', true) : '';

		$type_label = $type === 'ship' ? '🚚 ارسال به آدرس' : '🏬 حضوری';
		$status_map = ['pending'=>'⏳ در انتظار بررسی','assigned'=>'🧑‍💼 تخصیص داده‌شده','project'=>'📁 تبدیل به پروژه','cancelled'=>'✖ لغو شده'];
		$status_label = $status_map[$status] ?? $status;
		?>
		<div dir="rtl" style="font-family:inherit;">
			<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:14px;background:linear-gradient(135deg,#f0f9ff,#f8fafc);border:1px solid #cbd5e1;border-radius:12px;">
				<div><b>🧾 شناسه:</b> #<?php echo (int)$post->ID; ?></div>
				<div><b>📅 زمان ثبت:</b> <?php echo esc_html($created_fa ?: '—'); ?></div>
				<div><b>📌 وضعیت:</b> <?php echo esc_html($status_label); ?></div>
				<div><b>🛒 نوع:</b> <?php echo esc_html($type_label); ?></div>
			</div>

			<h3 style="margin:18px 0 8px;border-bottom:2px solid #6366f1;padding-bottom:6px;">👤 مشخصات مشتری</h3>
			<table class="widefat striped">
				<tr><th style="width:160px;">نام</th><td><?php echo $client ? esc_html($client->display_name) : '—'; ?></td></tr>
				<tr><th>شماره موبایل</th><td><?php echo esc_html($phone ?: '—'); ?></td></tr>
				<tr><th>آیدی کاربری</th><td>#<?php echo (int)$client_id; ?></td></tr>
				<tr><th>آیدی بله</th><td><?php echo $bale ? '<code>'.esc_html($bale).'</code>' : '—'; ?></td></tr>
				<?php if ($client && $client->user_email): ?>
				<tr><th>ایمیل</th><td><?php echo esc_html($client->user_email); ?></td></tr>
				<?php endif; ?>
			</table>

			<h3 style="margin:18px 0 8px;border-bottom:2px solid #6366f1;padding-bottom:6px;">📝 توضیحات سفارش</h3>
			<div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;min-height:80px;white-space:pre-wrap;line-height:1.8;">
				<?php echo $desc !== '' ? nl2br(esc_html($desc)) : '<em style="color:#94a3b8;">توضیحی ثبت نشده است.</em>'; ?>
			</div>

			<?php if ($type === 'ship'): ?>
			<h3 style="margin:18px 0 8px;border-bottom:2px solid #6366f1;padding-bottom:6px;">🏠 آدرس ارسال</h3>
			<div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;min-height:40px;white-space:pre-wrap;line-height:1.8;">
				<?php echo $addr !== '' ? nl2br(esc_html($addr)) : '<em style="color:#94a3b8;">آدرس ثبت نشده — هماهنگی بعدی.</em>'; ?>
			</div>
			<?php endif; ?>

			<h3 style="margin:18px 0 8px;border-bottom:2px solid #6366f1;padding-bottom:6px;">📎 فایل‌های پیوست (<?php echo count($files); ?>)</h3>
			<?php if (empty($files)): ?>
				<div style="padding:12px;color:#64748b;background:#f8fafc;border-radius:8px;">فایلی پیوست نشده است.</div>
			<?php else: ?>
				<div style="display:grid;gap:8px;">
					<?php foreach ($files as $f):
						$url = isset($f['url']) ? (string)$f['url'] : '';
						$nm  = isset($f['name']) ? (string)$f['name'] : 'فایل';
						$ext = strtolower(pathinfo($nm, PATHINFO_EXTENSION));
						$is_img = in_array($ext, ['jpg','jpeg','png','gif','webp'], true);
					?>
						<div style="display:flex;align-items:center;gap:10px;padding:10px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;">
							<?php if ($is_img && $url): ?>
								<a href="<?php echo esc_url($url); ?>" target="_blank"><img src="<?php echo esc_url($url); ?>" style="width:56px;height:56px;object-fit:cover;border-radius:8px;" alt=""></a>
							<?php else: ?>
								<div style="width:56px;height:56px;background:#f1f5f9;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:22px;">📎</div>
							<?php endif; ?>
							<div style="flex:1;min-width:0;">
								<div style="font-weight:700;color:#0f172a;overflow:hidden;text-overflow:ellipsis;"><?php echo esc_html($nm); ?></div>
								<a href="<?php echo esc_url($url); ?>" target="_blank" style="font-size:12px;color:#6366f1;">دانلود/مشاهده ↗</a>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ($assigned_exp): $ue = get_user_by('id', $assigned_exp); ?>
			<h3 style="margin:18px 0 8px;border-bottom:2px solid #10b981;padding-bottom:6px;">🧑‍💼 کارشناس مسئول</h3>
			<div style="padding:10px 14px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;">
				<?php echo $ue ? esc_html($ue->display_name) : 'کاربر #' . (int)$assigned_exp; ?>
			</div>
			<?php endif; ?>

			<?php if ($proj_id): ?>
			<h3 style="margin:18px 0 8px;border-bottom:2px solid #2563eb;padding-bottom:6px;">📁 پروژه‌ی متناظر</h3>
			<div style="padding:10px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;display:flex;justify-content:space-between;align-items:center;">
				<span><?php echo esc_html(get_the_title($proj_id)); ?> (#<?php echo (int)$proj_id; ?>)</span>
				<a class="button button-primary" href="<?php echo esc_url(get_edit_post_link($proj_id)); ?>">ورود به پروژه ↗</a>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_order_actions_metabox($post) {
		$status = (string) get_post_meta($post->ID, '_cptt_order_status', true) ?: 'pending';
		$assigned_exp = (int) get_post_meta($post->ID, '_cptt_order_assigned_expert', true);
		$proj_id = (int) get_post_meta($post->ID, '_cptt_order_project_id', true);
		?>
		<div dir="rtl">
			<p><b>📌 وضعیت فعلی:</b><br>
				<?php
				$status_map = ['pending'=>'⏳ در انتظار بررسی','assigned'=>'🧑‍💼 تخصیص داده‌شده','project'=>'📁 تبدیل به پروژه','cancelled'=>'✖ لغو شده'];
				echo esc_html($status_map[$status] ?? $status);
				?>
			</p>

			<?php if ($status !== 'project'): ?>
			<p style="margin-top:10px;">
				<label><b>تخصیص کارشناس:</b></label><br>
				<select name="cptt_order_assigned_expert" style="width:100%;">
					<option value="0">— انتخاب نشده —</option>
					<?php
					$experts = get_users(['role' => 'cptt_expert', 'orderby' => 'display_name']);
					foreach ($experts as $e) {
						echo '<option value="' . (int)$e->ID . '" ' . selected($assigned_exp, $e->ID, false) . '>' . esc_html($e->display_name) . '</option>';
					}
					?>
				</select>
			</p>
			<p>
				<label><b>تغییر وضعیت:</b></label><br>
				<select name="cptt_order_status" style="width:100%;">
					<?php foreach ($status_map as $k => $lbl): ?>
						<option value="<?php echo esc_attr($k); ?>" <?php selected($status, $k); ?>><?php echo esc_html($lbl); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<?php wp_nonce_field('cptt_order_save', 'cptt_order_nonce'); ?>
			<p style="color:#64748b;font-size:11px;">با ذخیره‌ی پست، تغییرات اعمال می‌شود.</p>
			<?php endif; ?>

			<?php if ($proj_id): ?>
			<p style="margin-top:10px;">
				<a class="button button-primary" href="<?php echo esc_url(get_edit_post_link($proj_id)); ?>" style="width:100%;text-align:center;">📁 ورود به پروژه</a>
			</p>
			<?php endif; ?>
		</div>
		<?php
	}
	private function product_select_html($name,$selected=0,$class='cptt-select') {
		$selected=(int)$selected;
		if (!post_type_exists('product')) return '<select class="'.esc_attr($class).'" disabled><option>ووکامرس/محصولی یافت نشد</option></select>';
		$products=get_posts(['post_type'=>'product','post_status'=>['publish','draft','private'],'numberposts'=>500,'orderby'=>'title','order'=>'ASC']);
		$html='<select id="'.esc_attr(str_replace(['[',']'],'',$name)).'" name="'.esc_attr($name).'" class="'.esc_attr($class).'">';
		$html.='<option value="">— بدون محصول —</option>';
		foreach ($products as $product) {
			$terms=get_the_terms($product->ID,'product_cat'); $cat_ids=[];
			if (!is_wp_error($terms)&&!empty($terms)) foreach($terms as $t)$cat_ids[]=(int)$t->term_id;
			$data_cats=!empty($cat_ids)?' data-cats="'.esc_attr(implode(',',$cat_ids)).'"':'';
			$html.=sprintf('<option value="%d"%s%s>%s</option>',(int)$product->ID,selected($selected,(int)$product->ID,false),$data_cats,esc_html(get_the_title($product)));
		}
		$html.='</select>'; return $html;
	}
	private function product_title($product_id) {
		$product_id=(int)$product_id; if(!$product_id)return '—';
		$title=get_the_title($product_id); return $title?:('#'.$product_id);
	}
	private function project_progress_data($project_id) {
		$steps=get_post_meta($project_id,'_cptt_steps',true);
		if (!is_array($steps)||empty($steps)) return ['percent'=>0,'done'=>0,'total'=>0,'status'=>'in_progress','label'=>'در حال انجام'];
		$total=count($steps); $done=0;
		foreach ($steps as $s) if (($s['status']??'')==='done') $done++;
		$percent=$total?(int)round(($done/$total)*100):0;
		$status=($total>0&&$done>=$total)?'completed':'in_progress';
		return ['percent'=>$percent,'done'=>$done,'total'=>$total,'status'=>$status,'label'=>$status==='completed'?'تکمیل شده':'در حال انجام'];
	}
	private function project_financial_data($project_id) {
		$steps=get_post_meta($project_id,'_cptt_steps',true);
		if (!is_array($steps)) $steps=[];
		$tc=0; $tp=0;
		foreach ($steps as $s){ $tc+=(float)($s['cost']??0); $tp+=(float)($s['paid']??0); }
		$tr=$tc-$tp;
		$percent=$tc>0?round(($tp/$tc)*100,1):0;
		return ['cost'=>$tc,'paid'=>$tp,'remain'=>$tr,'percent'=>$percent];
	}
	public function render_dashboard_page() {
		if (!current_user_can('edit_cptt_projects')) return;
		$query_args=['post_type'=>'cptt_project','post_status'=>'any','numberposts'=>-1,'orderby'=>'date','order'=>'DESC'];
		$user=wp_get_current_user();
		if (!in_array('administrator',(array)$user->roles,true)&&in_array('cptt_expert',(array)$user->roles,true)) {
			$query_args['meta_query']=[['key'=>'_cptt_experts_csv','value'=>','.get_current_user_id().',','compare'=>'LIKE']];
		}
		$projects=get_posts($query_args);
		$clients=[]; $experts_map=[]; $products_map=[]; $cards=[];
		$wc_cats_map=[]; $today_start=strtotime('today midnight'); $today_end=strtotime('tomorrow midnight')-1; $now=current_time('timestamp',true);
		$insight_today=[]; $insight_overdue=[]; $insight_follow=[];
		foreach ($projects as $p) {
			$client_id=(int)get_post_meta($p->ID,'_cptt_client_user_id',true);
			$client=$client_id?get_user_by('id',$client_id):null;
			if ($client) $clients[$client_id]=$client->display_name;
			$expert_ids=class_exists('CPTT_Core')?CPTT_Core::get_project_expert_ids($p->ID):[];
			$expert_names=[];
			foreach ($expert_ids as $eid) {
				$u=get_user_by('id',(int)$eid);
				if ($u) { $experts_map[(int)$eid]=$u->display_name; $expert_names[]=$u->display_name; }
			}
			$product_id=(int)get_post_meta($p->ID,'_cptt_product_id',true);
			if (!$product_id) $product_id=(int)get_post_meta($p->ID,'_cptt_wc_product_id',true);
			if ($product_id) $products_map[$product_id]=$this->product_title($product_id);
			$cat_ids=get_post_meta($p->ID,'_cptt_wc_cat_ids',true);
			$cat_names=[];
			if (is_array($cat_ids)) {
				foreach ($cat_ids as $cid) {
					$term=get_term((int)$cid,'product_cat');
					if ($term&&!is_wp_error($term)) { $cat_names[]=$term->name; $wc_cats_map[(int)$cid]=$term->name; }
				}
			}
			$progress=$this->project_progress_data($p->ID);
			$settled=(int)get_post_meta($p->ID,'_cptt_is_settled',true);
			$deadline=(string)get_post_meta($p->ID,'_cptt_deadline_at_fa',true);
			$last_update=(string)get_post_meta($p->ID,'_cptt_last_update_fa',true);
			$cards[]=['post'=>$p,'client_id'=>$client_id,'client_name'=>$client?$client->display_name:'—','expert_ids'=>$expert_ids,'expert_names'=>$expert_names,'product_id'=>$product_id,'product_title'=>$product_id?$this->product_title($product_id):'—','term_ids'=>$cat_ids?:[],'term_names'=>$cat_names,'progress'=>$progress,'settled'=>$settled,'deadline'=>$deadline,'last_update'=>$last_update];
			$steps=get_post_meta($p->ID,'_cptt_steps',true);
			if (is_array($steps)) {
				$assigned=in_array('administrator',(array)$user->roles,true)||in_array(get_current_user_id(),$expert_ids,true);
				foreach ($steps as $s) {
					if (($s['status']??'todo')==='done') continue;
					if (!$assigned) continue;
					$due=(int)($s['due_at']??0);
					$item=['project_title'=>get_the_title($p),'project_id'=>$p->ID,'step_title'=>($s['title']??''),'due_fa'=>($s['due_at_fa']??'')];
					if ($due&&$due>=$today_start&&$due<=$today_end) $insight_today[]=$item;
					elseif ($due&&$due<$now) $insight_overdue[]=$item;
					else $insight_follow[]=$item;
				}
			}
		}
		asort($clients); asort($experts_map); asort($products_map); asort($wc_cats_map);
		?>
		<div class="wrap cptt-dashboard" dir="rtl">
			<div class="cptt-dashboard__hero">
				<div><h1>داشبورد پروژه‌ها</h1><p>نمای اختصاصی پروژه‌ها با فیلتر سریع، بدون بارگذاری مجدد صفحه.</p></div>
				<a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=cptt_project')); ?>">+ پروژه جدید</a>
			</div>
			<div class="cptt-dashboard__insights">
				<div class="cptt-insight">
					<div class="cptt-insight__title">کارهای امروز</div>
					<div class="cptt-insight__count"><?php echo count($insight_today); ?></div>
					<?php if ($insight_today): ?>
					<ul class="cptt-insight__list">
						<?php foreach (array_slice($insight_today,0,3) as $it): ?>
						<li><a href="<?php echo esc_url(get_edit_post_link($it['project_id'])); ?>"><?php echo esc_html($it['project_title']); ?></a> — <?php echo esc_html($it['step_title']); ?></li>
						<?php endforeach; ?>
					</ul><?php endif; ?>
				</div>
				<div class="cptt-insight cptt-insight--warn">
					<div class="cptt-insight__title">تاخیر</div>
					<div class="cptt-insight__count"><?php echo count($insight_overdue); ?></div>
					<?php if ($insight_overdue): ?>
					<ul class="cptt-insight__list">
						<?php foreach (array_slice($insight_overdue,0,3) as $it): ?>
						<li><a href="<?php echo esc_url(get_edit_post_link($it['project_id'])); ?>"><?php echo esc_html($it['project_title']); ?></a> — <?php echo esc_html($it['step_title']); ?><?php if ($it['due_fa']){ ?> <small>(<?php echo esc_html($it['due_fa']); ?>)</small><?php } ?></li>
						<?php endforeach; ?>
					</ul><?php endif; ?>
				</div>
				<div class="cptt-insight">
					<div class="cptt-insight__title">قابل پیگیری</div>
					<div class="cptt-insight__count"><?php echo count($insight_follow); ?></div>
					<?php if ($insight_follow): ?>
					<ul class="cptt-insight__list">
						<?php foreach (array_slice($insight_follow,0,3) as $it): ?>
						<li><a href="<?php echo esc_url(get_edit_post_link($it['project_id'])); ?>"><?php echo esc_html($it['project_title']); ?></a> — <?php echo esc_html($it['step_title']); ?></li>
						<?php endforeach; ?>
					</ul><?php endif; ?>
				</div>
			</div>
			<div class="cptt-dashboard__filters">
				<label>جستجو<input type="search" id="cptt-dash-search" placeholder="عنوان، مشتری، کارشناس، محصول..."></label>
				<label>کارشناس<select id="cptt-dash-expert"><option value="">همه</option><?php foreach ($experts_map as $id=>$name): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
				<label>وضعیت<select id="cptt-dash-status"><option value="">همه</option><option value="completed">تکمیل شده</option><option value="in_progress">در حال انجام</option></select></label>
				<label>مشتری<select id="cptt-dash-client"><option value="">همه</option><?php foreach ($clients as $id=>$name): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
				<label>تسویه<select id="cptt-dash-settled"><option value="">همه</option><option value="1">تسویه شده</option><option value="0">تسویه نشده</option></select></label>
				<label>دسته‌بندی<select id="cptt-dash-cat"><option value="">همه</option><?php foreach ($wc_cats_map as $id=>$name): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
				<label>محصول<select id="cptt-dash-product"><option value="">همه</option><?php foreach ($products_map as $id=>$name): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
				<button type="button" class="button" id="cptt-dash-reset">پاک کردن فیلترها</button>
			</div>
			<div class="cptt-dashboard__summary">
				<span><b id="cptt-dash-count"><?php echo count($cards); ?></b> پروژه نمایش داده می‌شود</span>
			</div>
			<div class="cptt-dashboard__grid" id="cptt-dash-grid">
				<?php foreach ($cards as $c):
					$p=$c['post'];
					$search=strtolower(get_the_title($p).' '.$c['client_name'].' '.implode(' ',$c['expert_names']).' '.$c['product_title'].' '.implode(' ',$c['term_names']));
				?>
					<article class="cptt-project-card"
						data-search="<?php echo esc_attr($search); ?>"
						data-client="<?php echo esc_attr($c['client_id']); ?>"
						data-experts=",<?php echo esc_attr(implode(',',array_map('intval',$c['expert_ids']))); ?>,"
						data-status="<?php echo esc_attr($c['progress']['status']); ?>"
						data-settled="<?php echo esc_attr($c['settled']); ?>"
						data-cats=",<?php echo esc_attr(implode(',',array_map('intval',(array)$c['term_ids']))); ?>,"
						data-product="<?php echo esc_attr($c['product_id']); ?>">
						<div class="cptt-project-card__top">
							<h2><?php echo esc_html(get_the_title($p)); ?></h2>
							<span class="cptt-chip cptt-chip--<?php echo esc_attr($c['progress']['status']); ?>"><?php echo esc_html($c['progress']['label']); ?></span>
						</div>
						<div class="cptt-project-card__bar"><span style="width:<?php echo esc_attr($c['progress']['percent']); ?>%"></span></div>
						<div class="cptt-project-card__percent"><?php echo esc_html($c['progress']['percent'].'% ('.$c['progress']['done'].'/'.$c['progress']['total'].')'); ?></div>
						<div class="cptt-project-card__meta">
							<div><b>مشتری:</b> <?php echo esc_html($c['client_name']); ?></div>
							<div><b>کارشناسان:</b> <?php echo esc_html($c['expert_names']?implode('، ',$c['expert_names']):'—'); ?></div>
							<div><b>محصول:</b> <?php echo esc_html($c['product_title']); ?></div>
							<div><b>دسته‌بندی:</b> <?php echo esc_html($c['term_names']?implode('، ',$c['term_names']):'—'); ?></div>
							<div><b>تسویه:</b> <?php echo $c['settled']?'تسویه شده':'تسویه نشده'; ?></div>
							<?php if ($c['deadline']): ?><div><b>مهلت:</b> <?php echo esc_html($c['deadline']); ?></div><?php endif; ?>
							<?php if ($c['last_update']): ?><div><b>آخرین بروزرسانی:</b> <?php echo esc_html($c['last_update']); ?></div><?php endif; ?>
						</div>
						<div class="cptt-project-card__actions">
							<a class="button button-primary" href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>">ویرایش پروژه</a>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
			<div class="cptt-dashboard__empty" id="cptt-dash-empty" style="display:none;">هیچ پروژه‌ای با این فیلترها پیدا نشد.</div>
		</div>
		<?php
	}
	public function render_accounting_page() {
		if (!current_user_can('edit_cptt_projects')) return;
		$query_args=['post_type'=>'cptt_project','post_status'=>'any','numberposts'=>-1,'orderby'=>'date','order'=>'DESC'];
		$user=wp_get_current_user();
		if (!in_array('administrator',(array)$user->roles,true)&&in_array('cptt_expert',(array)$user->roles,true)) {
			$query_args['meta_query']=[['key'=>'_cptt_experts_csv','value'=>','.get_current_user_id().',','compare'=>'LIKE']];
		}
		$projects=get_posts($query_args);
		$total_cost_all=0; $total_paid_all=0; $total_projects=count($projects); $settled_count=0; $unsettled_count=0;
		$rows=[]; $clients_map=[]; $experts_map=[];
		foreach ($projects as $p) {
			$fin=$this->project_financial_data($p->ID);
			$total_cost_all+=$fin['cost']; $total_paid_all+=$fin['paid'];
			if ((int)get_post_meta($p->ID,'_cptt_is_settled',true)) $settled_count++; else $unsettled_count++;
			$client_id=(int)get_post_meta($p->ID,'_cptt_client_user_id',true);
			$client=$client_id?get_user_by('id',$client_id):null;
			if ($client) $clients_map[$client_id]=$client->display_name;
			$expert_ids=class_exists('CPTT_Core')?CPTT_Core::get_project_expert_ids($p->ID):[];
			$expert_names=[];
			foreach ($expert_ids as $eid) {
				$u=get_user_by('id',$eid);
				if ($u) { $experts_map[(int)$eid]=$u->display_name; $expert_names[]=$u->display_name; }
			}
			$progress=$this->project_progress_data($p->ID);
			$client_phone=''; if($client){ $client_phone=(string)get_user_meta($client_id,'billing_phone',true); if($client_phone==='')$client_phone=(string)get_user_meta($client_id,'cptt_phone',true); if($client_phone==='')$client_phone=(string)get_user_meta($client_id,'mobile',true); }
			$created_ts=(int)get_post_time('U',true,$p->ID);
			$created_fa=class_exists('CPTT_Core')?CPTT_Core::jalali_datetime($created_ts):get_the_date('Y/m/d',$p);
			$created_month=date('Y-m',$created_ts);
			$steps_for_profit=get_post_meta($p->ID,'_cptt_steps',true); $expert_paid_sum=0; if(is_array($steps_for_profit)){ foreach($steps_for_profit as $_st){ $expert_paid_sum+=(float)($_st['expert_paid']??0); } }
			$gross_profit=(float)$fin['paid']-$expert_paid_sum;
			$rows[]=['post'=>$p,'client'=>($client?$client->display_name:'—'),'client_id'=>$client_id,'client_phone'=>$client_phone,'created_fa'=>$created_fa,'created_ts'=>$created_ts,'created_month'=>$created_month,'gross_profit'=>$gross_profit,'expert_ids'=>$expert_ids,'experts'=>implode('، ',$expert_names),'fin'=>$fin,'progress'=>$progress,'settled'=>(int)get_post_meta($p->ID,'_cptt_is_settled',true)];
		}
		$total_remain_all=$total_cost_all-$total_paid_all;
		$clients_map=[]; // rebuild for unique
		foreach ($rows as $r) { if ($r['client_id']) $clients_map[$r['client_id']]=$r['client']; }
		asort($clients_map); asort($experts_map);
		?>
		<div class="wrap cptt-dashboard cptt-accounting" dir="rtl">
			<div class="cptt-dashboard__hero">
				<div><h1>حساب و کتاب</h1><p>مدیریت مالی پروژه‌ها، تسویه حساب و ثبت دریافتی.</p></div>
				<a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=cptt_project')); ?>">+ پروژه جدید</a>
			</div>

			<div class="cptt-acct-kpi-grid">
				<div class="cptt-acct-kpi">
					<div class="cptt-acct-kpi__icon" style="background:linear-gradient(135deg,#dbeafe,#eff6ff);color:#1d4ed8;">📁</div>
					<div class="cptt-acct-kpi__body">
						<div class="cptt-acct-kpi__label">تعداد پروژه‌ها</div>
						<div class="cptt-acct-kpi__value"><?php echo number_format($total_projects); ?></div>
					</div>
				</div>
				<div class="cptt-acct-kpi">
					<div class="cptt-acct-kpi__icon" style="background:linear-gradient(135deg,#fef3c7,#fffbeb);color:#b45309;">💰</div>
					<div class="cptt-acct-kpi__body">
						<div class="cptt-acct-kpi__label">کل هزینه</div>
						<div class="cptt-acct-kpi__value"><?php echo number_format($total_cost_all); ?></div>
						<small>تومان</small>
					</div>
				</div>
				<div class="cptt-acct-kpi">
					<div class="cptt-acct-kpi__icon" style="background:linear-gradient(135deg,#dcfce7,#f0fdf4);color:#15803d;">💳</div>
					<div class="cptt-acct-kpi__body">
						<div class="cptt-acct-kpi__label">کل دریافتی</div>
						<div class="cptt-acct-kpi__value"><?php echo number_format($total_paid_all); ?></div>
						<small>تومان</small>
					</div>
				</div>
				<div class="cptt-acct-kpi <?php echo $total_remain_all>0?'is-remain':'is-done'; ?>">
					<div class="cptt-acct-kpi__icon" style="background:linear-gradient(135deg,#fee2e2,#fef2f2);color:#b91c1c;">📊</div>
					<div class="cptt-acct-kpi__body">
						<div class="cptt-acct-kpi__label">طلب از مشتریان</div>
						<div class="cptt-acct-kpi__value"><?php echo number_format($total_remain_all); ?></div>
						<small>تومان</small>
					</div>
				</div>
				<div class="cptt-acct-kpi">
					<div class="cptt-acct-kpi__icon" style="background:linear-gradient(135deg,#dcfce7,#f0fdf4);color:#15803d;">✅</div>
					<div class="cptt-acct-kpi__body">
						<div class="cptt-acct-kpi__label">تسویه شده</div>
						<div class="cptt-acct-kpi__value"><?php echo number_format($settled_count); ?></div>
						<small>پروژه</small>
					</div>
				</div>
				<div class="cptt-acct-kpi">
					<div class="cptt-acct-kpi__icon" style="background:linear-gradient(135deg,#fee2e2,#fef2f2);color:#b91c1c;">⏳</div>
					<div class="cptt-acct-kpi__body">
						<div class="cptt-acct-kpi__label">تسویه نشده</div>
						<div class="cptt-acct-kpi__value"><?php echo number_format($unsettled_count); ?></div>
						<small>پروژه</small>
					</div>
				</div>
			</div>


			<div class="cptt-finance-pro-panel">
				<div class="cptt-fin-chart"><div class="cptt-fin-chart__head"><b>📈 درآمد ماهانه</b><small>بر اساس ردیف‌های قابل مشاهده</small></div><div id="cptt-chart-monthly" class="cptt-chart-bars"></div></div>
				<div class="cptt-fin-chart"><div class="cptt-fin-chart__head"><b>👥 بدهی مشتریان</b><small>بیشترین طلب از مشتری</small></div><div id="cptt-chart-debtors" class="cptt-chart-bars"></div></div>
				<div class="cptt-fin-chart"><div class="cptt-fin-chart__head"><b>💼 پرداخت به کارشناسان</b><small>از تسویه‌های ثبت‌شده</small></div><div id="cptt-chart-experts-pay" class="cptt-chart-bars"></div></div>
			</div>

			<div class="cptt-acct-filters cptt-acct-filters--sticky">
				<label>جستجو<input type="search" id="cptt-acct-search" placeholder="عنوان پروژه، مشتری..."></label>
				<label>مشتری<select id="cptt-acct-client"><option value="">همه</option><?php foreach ($clients_map as $id=>$name): ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
				<label>وضعیت مالی<select id="cptt-acct-settled"><option value="">همه</option><option value="1">تسویه شده</option><option value="0">تسویه نشده</option></select></label>
				<label>وضعیت پروژه<select id="cptt-acct-status"><option value="">همه</option><option value="completed">تکمیل شده</option><option value="in_progress">در حال انجام</option></select></label>
				<label>از تاریخ<input type="text" class="cptt-jalali-datetime" id="cptt-acct-from" placeholder="انتخاب تاریخ"></label>
				<label>تا تاریخ<input type="text" class="cptt-jalali-datetime" id="cptt-acct-to" placeholder="انتخاب تاریخ"></label>
				<button type="button" class="button" id="cptt-acct-reset">پاک کردن</button>
				<button type="button" class="button" id="cptt-acct-print" style="background:#059669; border-color:#059669; color:#fff; font-weight:bold; margin-right:5px; height:30px; align-self:end;">🖨 چاپ گزارش مالی</button>
				<button type="button" class="cptt-debtors-trigger" id="cptt-acct-debtors">👥 لیست بدهکاران <span class="cptt-debtors-count" id="cptt-debtors-count-badge">0</span></button>
			</div>

			<div class="cptt-acct-table-wrap">
				<table class="cptt-acct-table" id="cptt-acct-table">
					<thead>
						<tr>
							<th style="width:28%">پروژه</th>
							<th>مشتری</th>
							<th>پیشرفت</th>
							<th>وضعیت</th>
							<th style="text-align:left">کل هزینه</th>
							<th style="text-align:left">دریافتی</th>
							<th style="text-align:left">طلب از مشتری</th>
							<th style="text-align:left">سود ناخالص</th>
							<th>روند</th>
							<th style="width:110px">عملیات</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ($rows as $r):
						$p=$r['post']; $f=$r['fin']; $prog=$r['progress'];
						$barColor=$f['percent']>=100?'#22c55e':($f['percent']>=50?'#f59e0b':'#ef4444');
						$search=strtolower(get_the_title($p).' '.$r['client']);
					?>
						<tr class="cptt-acct-row"
							data-search="<?php echo esc_attr($search); ?>"
							data-client="<?php echo esc_attr($r['client_id']); ?>"
							data-settled="<?php echo esc_attr($r['settled']); ?>"
							data-status="<?php echo esc_attr($prog['status']); ?>" data-phone="<?php echo esc_attr($r['client_phone']); ?>" data-created="<?php echo esc_attr($r['created_fa']); ?>" data-created-ts="<?php echo esc_attr($r['created_ts']); ?>" data-month="<?php echo esc_attr($r['created_month']); ?>" data-cost="<?php echo esc_attr($f['cost']); ?>" data-paid="<?php echo esc_attr($f['paid']); ?>" data-remain="<?php echo esc_attr($f['remain']); ?>" data-profit="<?php echo esc_attr($r['gross_profit']); ?>">
							<td>
								<a class="cptt-acct-title" href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>"><?php echo esc_html(get_the_title($p)); ?></a>
								<div class="cptt-acct-meta"><?php echo esc_html($r['experts']); ?></div>
							</td>
							<td><?php echo esc_html($r['client']); ?></td>
							<td>
								<div class="cptt-acct-minibar"><span style="width:<?php echo (int)$prog['percent']; ?>%;"></span></div>
								<small><?php echo (int)$prog['percent']; ?>%</small>
							</td>
							<td><?php echo $r['settled']?'<span class="cptt-chip cptt-chip--completed">تسویه</span>':'<span class="cptt-chip cptt-chip--in_progress">تسویه نشده</span>'; ?></td>
							<td style="text-align:left; font-weight:800;"><?php echo number_format($f['cost']); ?></td>
							<td style="text-align:left; color:#15803d; font-weight:800;"><?php echo number_format($f['paid']); ?></td>
							<td style="text-align:left; color:<?php echo $f['remain']>0?'#b91c1c':'#15803d'; ?>; font-weight:900;"><?php echo number_format($f['remain']); ?></td>
							<td style="text-align:left; color:<?php echo $r['gross_profit']>=0?'#15803d':'#b91c1c'; ?>; font-weight:900;"><?php echo number_format($r['gross_profit']); ?></td>
							<td>
								<div class="cptt-acct-bar-wrap">
									<div class="cptt-acct-bar" style="--bar-color:<?php echo esc_attr($barColor); ?>"><span style="width:<?php echo min(100,(float)$f['percent']); ?>%;"></span></div>
									<small><?php echo (float)$f['percent']; ?>%</small>
								</div>
							</td>
							<td>
								<a class="button" href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>" style="padding:4px 10px; font-size:12px;">ویرایش</a>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if (empty($rows)): ?>
					<tr><td colspan="10" style="text-align:center; padding:28px;">پروژه‌ای یافت نشد.</td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="cptt-acct-empty" id="cptt-acct-empty" style="display:none;">
				<div>هیچ پروژه‌ای با این فیلترها پیدا نشد.</div>
			</div>

			<?php
			// === v5.4.3: New step-by-step settlement table ===
			// هر ردیف = یک مرحله از یک پروژه که paid > 0 دارد. وضعیت تسویه از _cptt_step_settled مرحله خوانده می‌شود.
			$step_settlement_rows = [];
			$sum_paid = 0; $sum_to_expert = 0; $sum_to_admin = 0; $sum_unsettled_to_expert = 0;
			foreach ($projects as $proj) {
				$stps = get_post_meta($proj->ID, '_cptt_steps', true);
				if (!is_array($stps)) continue;
				foreach ($stps as $sk => $st) {
					$paid = (float)($st['paid'] ?? 0);
					if ($paid <= 0) continue;
					$ae_id = isset($st['assigned_expert_id']) ? (int)$st['assigned_expert_id'] : 0;
					// اگر کارشناس مرحله مشخص نیست، فقط اولین کارشناس پروژه را پیشنهاد می‌کنیم.
					if (!$ae_id) {
						$_eids = class_exists('CPTT_Core') ? CPTT_Core::get_project_expert_ids($proj->ID) : [];
						if (!empty($_eids)) $ae_id = (int)$_eids[0];
					}
					$exp_to_expert = (float)($st['expert_paid'] ?? 0);
					$admin_received = (float)($st['admin_received'] ?? 0);
					$settled = !empty($st['step_settled']) ? 1 : 0;
					$settle_at_fa = isset($st['settle_at_fa']) ? (string)$st['settle_at_fa'] : '';
					$step_id = isset($st['id']) ? (string)$st['id'] : (string)$sk;
					$step_title = (string)($st['title'] ?? '—');
					$sum_paid += $paid;
					if ($settled) {
						$sum_to_expert += $exp_to_expert;
						$sum_to_admin  += $admin_received;
					} else {
						$sum_unsettled_to_expert += $exp_to_expert; // مبلغی که قبلاً صرفا به کارشناس واریز شده بدون تسویه نهایی
					}
					$step_settlement_rows[] = [
						'project_id' => (int)$proj->ID,
						'project_title' => get_the_title($proj),
						'step_id' => $step_id,
						'step_title' => $step_title,
						'expert_id' => $ae_id,
						'expert_name' => $ae_id ? (($u=get_user_by('id',$ae_id))?$u->display_name:'—') : '—',
						'paid' => $paid,
						'exp_to_expert' => $exp_to_expert,
						'admin_received' => $admin_received,
						'settled' => $settled,
						'settle_at_fa' => $settle_at_fa,
					];
				}
			}
			?>
			<h2 style="margin-top:40px; font-weight:950; color:#0f172a; font-size:18px;">💼 تسویه مراحل با کارشناسان</h2>
			<p style="color:#64748b; margin-top:4px; margin-bottom:8px; font-size:12px;">برای هر مرحله‌ای که دریافتی دارد می‌توانید با کلیک روی «تسویه»، مبلغ پرداختی به کارشناس را تعیین کنید. اگر «تسویه نهایی» بزنید، باقی‌مانده به دریافتی‌های مدیر سایت منتقل و مرحله تسویه‌شده ثبت می‌شود.</p>
			<div style="display:flex; gap:10px; flex-wrap:wrap; margin:8px 0 14px;">
				<div class="cptt-acct-kpi" style="flex:1; min-width:160px;"><div class="cptt-acct-kpi__body"><div class="cptt-acct-kpi__label">جمع دریافتی مراحل</div><div class="cptt-acct-kpi__value" style="font-size:16px;"><?php echo number_format($sum_paid); ?> <small>تومان</small></div></div></div>
				<div class="cptt-acct-kpi" style="flex:1; min-width:160px;"><div class="cptt-acct-kpi__body"><div class="cptt-acct-kpi__label">سهم تسویه‌شده به مدیر</div><div class="cptt-acct-kpi__value" style="font-size:16px; color:#15803d;"><?php echo number_format($sum_to_admin); ?> <small>تومان</small></div></div></div>
				<div class="cptt-acct-kpi" style="flex:1; min-width:160px;"><div class="cptt-acct-kpi__body"><div class="cptt-acct-kpi__label">سهم تسویه‌شده به کارشناسان</div><div class="cptt-acct-kpi__value" style="font-size:16px; color:#2563eb;"><?php echo number_format($sum_to_expert); ?> <small>تومان</small></div></div></div>
				<div class="cptt-acct-kpi" style="flex:1; min-width:160px;"><div class="cptt-acct-kpi__body"><div class="cptt-acct-kpi__label">پرداخت به کارشناس بدون تسویه نهایی</div><div class="cptt-acct-kpi__value" style="font-size:16px; color:#b45309;"><?php echo number_format($sum_unsettled_to_expert); ?> <small>تومان</small></div></div></div>
			</div>

			<?php $settle_experts = []; foreach (get_users(['role__in'=>['cptt_expert','administrator']]) as $_eu) { $settle_experts[(int)$_eu->ID]=['name'=>$_eu->display_name,'count'=>0,'remain'=>0]; } foreach ($step_settlement_rows as $_sr) { if (!empty($_sr['settled'])) continue; $eid=(int)$_sr['expert_id']; if(!$eid) continue; if(!isset($settle_experts[$eid])) $settle_experts[$eid]=['name'=>$_sr['expert_name'],'count'=>0,'remain'=>0]; $settle_experts[$eid]['count']++; $settle_experts[$eid]['remain'] += max(0, (float)$_sr['paid'] - (float)$_sr['exp_to_expert']); } ?>
			<div class="cptt-settle-expert-cards" id="cptt-settle-expert-cards">
				<?php foreach ($settle_experts as $eid=>$sx): $av=''; $aid=(int)get_user_meta($eid,'cptt_expert_avatar_id',true); if($aid)$av=wp_get_attachment_image_url($aid,'thumbnail'); if(!$av)$av=get_avatar_url($eid,['size'=>64]); ?>
				<button type="button" class="cptt-settle-expert-card" data-expert="<?php echo esc_attr($eid); ?>"><img src="<?php echo esc_url($av); ?>" alt=""><span><b><?php echo esc_html($sx['name']); ?></b><small><?php echo number_format($sx['count']); ?> مرحله — مانده <?php echo number_format($sx['remain']); ?></small></span></button>
				<?php endforeach; ?>
			</div>
			<div style="display:flex;gap:8px;align-items:center;margin:8px 0 12px;flex-wrap:wrap;"><button type="button" class="button button-primary" id="cptt-bulk-settle-selected">✓ تسویه یکجای انتخاب‌شده‌ها</button><button type="button" class="button" id="cptt-manual-payment-open">➕ پرداخت دستی</button><small style="color:#64748b;">با انتخاب چند ردیف، مانده هر مرحله به‌صورت تسویه نهایی ثبت می‌شود.</small></div>

			<button type="button" class="button" id="cptt-settled-history-open" style="margin:8px 0 12px;background:#0f172a;color:#fff;border-color:#0f172a;">📜 مشاهده تسویه‌های انجام‌شده</button>
			<div class="cptt-acct-table-wrap" style="margin-top:10px;">
				<table class="cptt-acct-table" id="cptt-step-settle-table">
					<thead>
						<tr>
							<th style="width:34px"><input type="checkbox" id="cptt-settle-check-all"></th><th>پروژه / مرحله</th>
							<th>کارشناس</th>
							<th style="text-align:left">دریافتی مرحله (تومان)</th>
							<th style="text-align:left">پرداخت به کارشناس</th>
							<th style="text-align:left">سهم مدیر</th>
							<th>وضعیت</th>
							<th style="width:200px">عملیات</th>
						</tr>
					</thead>
					<tbody>
						<?php if (empty($step_settlement_rows)): ?>
							<tr><td colspan="8" style="text-align:center; padding:20px;">هیچ مرحله‌ای با دریافتی برای تسویه ثبت نشده است.</td></tr>
						<?php else: foreach ($step_settlement_rows as $rr): if (!empty($rr['settled'])) continue;
							$remain_paid = max(0, $rr['paid'] - $rr['exp_to_expert']);
							$status_html = $rr['settled']
								? '<span class="cptt-chip cptt-chip--completed" style="background:rgba(34,197,94,0.12); color:#065f46; border:1px solid rgba(34,197,94,0.22);">✓ تسویه شده'.($rr['settle_at_fa']?' — '.esc_html($rr['settle_at_fa']):'').'</span>'
								: ($rr['exp_to_expert']>0 ? '<span class="cptt-chip" style="background:rgba(245,158,11,0.12); color:#92400e; border:1px solid rgba(245,158,11,0.22);">⏳ پرداخت به کارشناس — بدون تسویه نهایی</span>' : '<span class="cptt-chip cptt-chip--in_progress">تسویه نشده</span>');
						?>
							<tr class="cptt-step-settle-row" style="display:none" data-expert="<?php echo esc_attr($rr['expert_id']); ?>">
								<td><input type="checkbox" class="cptt-settle-row-check" data-project-id="<?php echo esc_attr($rr['project_id']); ?>" data-step-id="<?php echo esc_attr($rr['step_id']); ?>" data-expert-id="<?php echo esc_attr($rr['expert_id']); ?>" data-amount="<?php echo esc_attr(max(0, $rr['paid'] - $rr['exp_to_expert'])); ?>"></td>
								<td>
									<a href="<?php echo esc_url(get_edit_post_link($rr['project_id'])); ?>" style="font-weight:800; color:#0f172a; text-decoration:none;"><?php echo esc_html($rr['project_title']); ?></a>
									<div style="color:#64748b; font-size:12px;">مرحله: <?php echo esc_html($rr['step_title']); ?></div>
								</td>
								<td><?php echo esc_html($rr['expert_name']); ?></td>
								<td style="text-align:left; font-weight:800;"><?php echo number_format($rr['paid']); ?></td>
								<td style="text-align:left; color:#2563eb; font-weight:800;"><?php echo number_format($rr['exp_to_expert']); ?></td>
								<td style="text-align:left; color:#15803d; font-weight:800;"><?php echo number_format($rr['admin_received']); ?></td>
								<td><?php echo $status_html; ?></td>
								<td>
									<?php if (!$rr['settled']): ?>
									<button type="button" class="button cptt-step-settle-btn"
										data-project-id="<?php echo esc_attr($rr['project_id']); ?>"
										data-step-id="<?php echo esc_attr($rr['step_id']); ?>"
										data-step-title="<?php echo esc_attr($rr['step_title']); ?>"
										data-project-title="<?php echo esc_attr($rr['project_title']); ?>"
										data-paid="<?php echo esc_attr($rr['paid']); ?>"
										data-already-paid="<?php echo esc_attr($rr['exp_to_expert']); ?>"
										data-expert-id="<?php echo esc_attr($rr['expert_id']); ?>"
										data-expert-name="<?php echo esc_attr($rr['expert_name']); ?>"
										style="background:#2563eb; color:#fff; border:none; padding:6px 14px; font-weight:bold; border-radius:6px; cursor:pointer;">💳 تسویه</button>
									<?php else: ?>
									<span style="font-size:11px; color:#15803d; font-weight:bold;">✓ نهایی شده</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>

			<!-- مودال تسویه‌ی مرحله -->


			<div id="cptt-manual-payment-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.65);z-index:9999;align-items:center;justify-content:center;padding:16px;"><div style="background:#fff;border-radius:18px;width:100%;max-width:460px;padding:18px;box-shadow:0 28px 65px rgba(0,0,0,.25);"><h3 style="margin-top:0;">پرداخت دستی به کارشناس</h3><label>کارشناس<select id="cptt-manual-payment-expert" style="width:100%;margin:6px 0 10px;"><?php foreach ($settle_experts as $eid=>$sx): ?><option value="<?php echo esc_attr($eid); ?>"><?php echo esc_html($sx['name']); ?></option><?php endforeach; ?></select></label><label>مبلغ<input type="text" id="cptt-manual-payment-amount" class="cptt-currency-input" style="width:100%;margin:6px 0 10px;"></label><label>توضیح<textarea id="cptt-manual-payment-note" style="width:100%;margin:6px 0 10px;"></textarea></label><div style="display:flex;gap:8px;justify-content:flex-end"><button type="button" class="button" id="cptt-manual-payment-close">انصراف</button><button type="button" class="button button-primary" id="cptt-manual-payment-save">ثبت پرداخت</button></div></div></div>

			<div id="cptt-settled-history-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.65);z-index:9998;align-items:center;justify-content:center;padding:16px;">
				<div style="background:#fff;border-radius:18px;max-width:980px;width:100%;max-height:85vh;overflow:auto;padding:18px;box-shadow:0 30px 70px rgba(0,0,0,.28);">
					<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px;"><h3 style="margin:0;">تسویه‌های انجام‌شده</h3><button type="button" class="button" id="cptt-settled-history-close">×</button></div>
					<input type="search" id="cptt-settled-history-search" placeholder="جستجو در پروژه، مرحله یا کارشناس..." style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px;margin-bottom:10px;">
					<table class="widefat striped"><thead><tr><th>پروژه/مرحله</th><th>کارشناس</th><th>پرداخت کارشناس</th><th>سهم مدیر</th><th>تاریخ</th><th>عملیات</th></tr></thead><tbody>
					<?php
					$settled_history = [];
					foreach ($step_settlement_rows as $rr) { if (!empty($rr['settled'])) { $settled_history[] = $rr; } }
					
					// Fetch manual payments from ledger
					global $wpdb;
					$manuals = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}cptt_ledger WHERE type='expert_manual_payout' ORDER BY created_at DESC LIMIT 100");
					foreach ($manuals as $m) {
						$u = get_user_by('id', $m->user_id);
						$settled_history[] = [
							'project_id' => 0,
							'project_title' => '💸 پرداخت دستی',
							'step_id' => 'manual_'.$m->id,
							'step_title' => $m->note ?: 'بدون توضیح',
							'expert_id' => $m->user_id,
							'expert_name' => $u ? $u->display_name : '—',
							'exp_to_expert' => abs($m->amount),
							'admin_received' => 0,
							'settle_at_fa' => class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime(strtotime($m->created_at)) : $m->created_at,
							'is_manual' => true,
							'ledger_id' => $m->id
						];
					}
					// Sort history by date descending
					// (Rough sort since we have mixing formats, but better than nothing)
					
					foreach ($settled_history as $rr): $srch=mb_strtolower(($rr['project_title']??'').' '.($rr['step_title']??'').' '.($rr['expert_name']??'')); ?>
					<tr class="cptt-settled-history-row" data-search="<?php echo esc_attr($srch); ?>">
						<td><b><?php echo esc_html($rr['project_title']); ?></b><br><small><?php echo esc_html($rr['step_title']); ?></small></td>
						<td><?php echo esc_html($rr['expert_name']); ?></td>
						<td><?php if(!empty($rr['is_manual'])): ?><b><?php echo number_format($rr['exp_to_expert']); ?></b><?php else: ?><input type="text" class="cptt-settle-edit-amount" value="<?php echo esc_attr(number_format($rr['exp_to_expert'])); ?>" style="width:110px"><?php endif; ?></td>
						<td><?php echo number_format($rr['admin_received']); ?></td>
						<td><?php echo esc_html($rr['settle_at_fa']); ?></td>
						<td><?php if(empty($rr['is_manual'])): ?><button type="button" class="button cptt-settle-edit" data-project-id="<?php echo esc_attr($rr['project_id']); ?>" data-step-id="<?php echo esc_attr($rr['step_id']); ?>">ذخیره</button> <button type="button" class="button button-link-delete cptt-settle-delete" data-project-id="<?php echo esc_attr($rr['project_id']); ?>" data-step-id="<?php echo esc_attr($rr['step_id']); ?>">حذف</button><?php else: ?><span class="cptt-chip cptt-chip--completed">پرداخت دستی</span><?php endif; ?></td>
					</tr>
					<?php endforeach; ?>
					</tbody></table>
				</div>
			</div>

			<div id="cptt-bulk-settle-modal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,.6); z-index:9999; align-items:center; justify-content:center; padding:16px;">
				<div style="background:#fff; border-radius:18px; max-width:640px; width:100%; padding:24px; box-shadow:0 30px 60px rgba(0,0,0,.3); direction:rtl; display:flex; flex-direction:column; max-height:85vh;">
					<h3 style="margin:0 0 10px; font-weight:900; color:#0f172a;">📦 تسویه یکجای مراحل انتخاب‌شده</h3>
					<p style="margin:0 0 14px; color:#475569; font-size:13px;">مبالغ پرداختی برای هر مرحله را تایید یا اصلاح کنید. با تایید نهایی، همه‌ی این موارد تسویه می‌شوند.</p>
					
					<div id="cptt-bulk-settle-list" style="overflow-y:auto; flex:1; margin-bottom:16px; border:1px solid #e2e8f0; border-radius:12px;">
						<!-- Rows will be injected here -->
					</div>

					<div style="background:#f8fafc; padding:12px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center;">
						<span style="font-size:13px; font-weight:800;">جمع کل پرداختی:</span>
						<b id="cptt-bulk-settle-total" style="font-size:16px; color:#2563eb;">0 تومان</b>
					</div>

					<div style="display:flex; gap:8px;">
						<button type="button" id="cptt-bulk-settle-confirm" style="flex:1; padding:12px; border-radius:12px; border:none; background:linear-gradient(135deg,#059669,#10b981); color:#fff; font-weight:900; cursor:pointer;">✓ ثبت و تسویه نهایی همه</button>
						<button type="button" id="cptt-bulk-settle-cancel" style="padding:12px 20px; border-radius:12px; border:1px solid #cbd5e1; background:#fff; color:#334155; font-weight:800; cursor:pointer;">انصراف</button>
					</div>
					<div id="cptt-bulk-settle-msg" style="margin-top:10px; font-size:12px; text-align:center;"></div>
				</div>
			</div>

			<div id="cptt-step-settle-modal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,.6); z-index:9999; align-items:center; justify-content:center; padding:16px;">
				<div style="background:#fff; border-radius:18px; max-width:480px; width:100%; padding:24px; box-shadow:0 30px 60px rgba(0,0,0,.3); direction:rtl;">
					<h3 style="margin:0 0 8px; font-weight:900; color:#0f172a;">💳 تسویه مرحله با کارشناس</h3>
					<p id="cptt-step-settle-info" style="margin:0 0 14px; color:#475569; font-size:13px; line-height:1.8;"></p>

					<div style="background:#f8fafc; padding:12px; border-radius:10px; border:1px solid #e2e8f0; margin-bottom:14px;">
						<div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:4px;"><span>دریافتی این مرحله:</span><b id="cptt-step-settle-paid" style="color:#0f172a;">0</b></div>
						<div style="display:flex; justify-content:space-between; font-size:13px;"><span>قبلاً به کارشناس پرداخت‌شده:</span><b id="cptt-step-settle-already" style="color:#2563eb;">0</b></div>
					</div>

					<label style="display:block; font-weight:800; margin-bottom:6px; color:#0f172a; font-size:13px;">پرداخت به کارشناس (تومان)</label>
					<input type="text" id="cptt-step-settle-amount" class="cptt-currency-input" style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:10px; font-size:14px;" placeholder="مثلا 250000" />
					<div style="margin-top:6px; font-size:11px; color:#64748b;">با «تسویه نهایی» مابقی به دریافتی‌های مدیر می‌رود و مرحله بسته می‌شود. با «ثبت پرداختی» فقط مبلغ کارشناس ثبت می‌شود و مرحله باز می‌ماند.</div>

					<div style="display:flex; gap:8px; margin-top:18px; flex-wrap:wrap;">
						<button type="button" id="cptt-step-settle-final" style="flex:1; min-width:140px; padding:11px 14px; border-radius:10px; border:none; background:linear-gradient(135deg,#059669,#10b981); color:#fff; font-weight:900; cursor:pointer;">✓ تسویه نهایی با کارشناس</button>
						<button type="button" id="cptt-step-settle-partial" style="flex:1; min-width:140px; padding:11px 14px; border-radius:10px; border:none; background:linear-gradient(135deg,#f59e0b,#fbbf24); color:#fff; font-weight:900; cursor:pointer;">⏳ ثبت پرداختی (بدون تسویه)</button>
						<button type="button" id="cptt-step-settle-cancel" style="padding:11px 14px; border-radius:10px; border:1px solid #cbd5e1; background:#fff; color:#334155; font-weight:800; cursor:pointer;">انصراف</button>
					</div>
					<div id="cptt-step-settle-msg" style="margin-top:10px; font-size:12px; text-align:center;"></div>
				</div>
			</div>

			<script>
			(function(){
				var modal = document.getElementById('cptt-step-settle-modal');
				if (!modal) return;
				var info = document.getElementById('cptt-step-settle-info');
				var paidEl = document.getElementById('cptt-step-settle-paid');
				var alreadyEl = document.getElementById('cptt-step-settle-already');
				var amountEl = document.getElementById('cptt-step-settle-amount');
				var msgEl = document.getElementById('cptt-step-settle-msg');
				var btnF = document.getElementById('cptt-step-settle-final');
				var btnP = document.getElementById('cptt-step-settle-partial');
				var btnC = document.getElementById('cptt-step-settle-cancel');

				
				var mp=document.getElementById('cptt-manual-payment-modal'), mpo=document.getElementById('cptt-manual-payment-open'), mpc=document.getElementById('cptt-manual-payment-close'), mps=document.getElementById('cptt-manual-payment-save');
				if(mpo&&mp) mpo.addEventListener('click',function(){ var active=document.querySelector('.cptt-settle-expert-card.is-active[data-expert]:not([data-expert=""])'); var sel=document.getElementById('cptt-manual-payment-expert'); if(active&&sel) sel.value=active.dataset.expert; mp.style.display='flex'; });
				if(mpc&&mp) mpc.addEventListener('click',function(){mp.style.display='none';}); if(mp) mp.addEventListener('click',function(e){if(e.target===mp)mp.style.display='none';});
				if(mps) mps.addEventListener('click',function(){ var fd=new FormData(); fd.append('action','cptt_manual_expert_payment'); fd.append('nonce',(window.cpttAdminNonce||'')); fd.append('expert_id',document.getElementById('cptt-manual-payment-expert').value); fd.append('amount',document.getElementById('cptt-manual-payment-amount').value); fd.append('note',document.getElementById('cptt-manual-payment-note').value); fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd}).then(r=>r.json()).then(function(j){ if(j&&j.success) location.reload(); else alert((j&&j.data)?j.data:'خطا'); }); });

				var hist = document.getElementById('cptt-settled-history-modal');
				var histOpen = document.getElementById('cptt-settled-history-open');
				var histClose = document.getElementById('cptt-settled-history-close');
				if (histOpen && hist) histOpen.addEventListener('click', function(){ hist.style.display='flex'; });
				if (histClose && hist) histClose.addEventListener('click', function(){ hist.style.display='none'; });
				if (hist) hist.addEventListener('click', function(e){ if(e.target===hist) hist.style.display='none'; });
				var hSearch=document.getElementById('cptt-settled-history-search');
				if(hSearch) hSearch.addEventListener('input', function(){ var q=this.value.toLowerCase(); document.querySelectorAll('.cptt-settled-history-row').forEach(function(r){ r.style.display=((r.dataset.search||'').toLowerCase().indexOf(q)>-1)?'':'none'; }); });

				document.addEventListener('click', function(e){ var c=e.target.closest('.cptt-settle-expert-card'); if(!c) return; document.querySelectorAll('.cptt-settle-expert-card').forEach(function(x){x.classList.remove('is-active')}); c.classList.add('is-active'); var id=c.dataset.expert||''; document.querySelectorAll('.cptt-step-settle-row').forEach(function(r){ r.style.display=(id&&r.dataset.expert===id)?'':'none'; }); });
				var chkAll=document.getElementById('cptt-settle-check-all');
				if(chkAll) chkAll.addEventListener('change', function(){ document.querySelectorAll('.cptt-step-settle-row:not([style*="display: none"]) .cptt-settle-row-check').forEach(function(ch){ ch.checked=chkAll.checked; }); });

				var bulk=document.getElementById('cptt-bulk-settle-selected');
				var bulkModal=document.getElementById('cptt-bulk-settle-modal');
				var bulkList=document.getElementById('cptt-bulk-settle-list');
				var bulkTotal=document.getElementById('cptt-bulk-settle-total');
				var bulkConfirm=document.getElementById('cptt-bulk-settle-confirm');
				var bulkCancel=document.getElementById('cptt-bulk-settle-cancel');
				var bulkMsg=document.getElementById('cptt-bulk-settle-msg');

				if(bulk) bulk.addEventListener('click', function(){
					var checked=Array.from(document.querySelectorAll('.cptt-settle-row-check:checked'));
					if(!checked.length){ alert('هیچ ردیفی انتخاب نشده است.'); return; }
					
					var html = ''; var total = 0;
					checked.forEach(function(ch, idx){
						var row = ch.closest('tr');
						var pTitle = row.querySelector('a').textContent;
						var sTitle = row.querySelector('div').textContent;
						var amount = parseFloat(ch.dataset.amount || '0');
						total += amount;
						html += '<div class="cptt-bulk-row" style="padding:12px; border-bottom:1px solid #f1f5f9; display:grid; grid-template-columns:1fr 140px; gap:10px; align-items:center;" data-pid="'+ch.dataset.projectId+'" data-sid="'+ch.dataset.stepId+'" data-eid="'+ch.dataset.expertId+'">'+
								'<div><div style="font-weight:800; font-size:12px;">'+pTitle+'</div><div style="font-size:11px; color:#64748b;">'+sTitle+'</div></div>'+
								'<div><input type="text" class="cptt-bulk-amount cptt-currency-input" value="'+amount.toLocaleString('en-US')+'" style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; text-align:left; direction:ltr;"></div>'+
								'</div>';
					});
					bulkList.innerHTML = html;
					bulkTotal.textContent = total.toLocaleString('en-US') + ' تومان';
					bulkModal.style.display = 'flex';
					bulkMsg.textContent = '';
				});

				if(bulkCancel) bulkCancel.addEventListener('click', function(){ bulkModal.style.display='none'; });
				
				$(document).on('input', '.cptt-bulk-amount', function(){
					var sum = 0;
					$('.cptt-bulk-amount').each(function(){ sum += toNum($(this).val()); });
					bulkTotal.textContent = sum.toLocaleString('en-US') + ' تومان';
				});

				if(bulkConfirm) bulkConfirm.addEventListener('click', async function(){
					var rows = Array.from(bulkList.querySelectorAll('.cptt-bulk-row'));
					if(!rows.length) return;
					bulkConfirm.disabled = true; bulkMsg.style.color='#475569'; bulkMsg.textContent='در حال تسویه ردیف‌ها (۰ از '+rows.length+')...';
					
					for(var i=0; i<rows.length; i++){
						var r = rows[i];
						var amount = toNum(r.querySelector('.cptt-bulk-amount').value);
						var fd = new FormData();
						fd.append('action','cptt_step_settle');
						fd.append('nonce', (window.cpttAdminNonce||''));
						fd.append('project_id', r.dataset.pid);
						fd.append('step_id', r.dataset.sid);
						fd.append('expert_id', r.dataset.eid);
						fd.append('amount', String(amount));
						fd.append('mode', 'final');
						bulkMsg.textContent = 'در حال تسویه ردیف‌ها ('+(i+1)+' از '+rows.length+')...';
						await fetch(ajaxurl, {method:'POST', body:fd});
					}
					bulkMsg.style.color='#059669'; bulkMsg.textContent='✅ تمام موارد با موفقیت تسویه شدند. رفرش صفحه...';
					setTimeout(function(){ location.reload(); }, 800);
				});

				document.addEventListener('click', function(e){
					var eb=e.target.closest('.cptt-settle-edit,.cptt-settle-delete'); if(!eb) return;
					if(eb.classList.contains('cptt-settle-delete') && !confirm('این تسویه حذف شود؟')) return;
					var tr=eb.closest('tr'); var fd=new FormData(); fd.append('action','cptt_step_settlement_adjust'); fd.append('nonce',(window.cpttAdminNonce||'')); fd.append('project_id',eb.dataset.projectId); fd.append('step_id',eb.dataset.stepId); fd.append('mode', eb.classList.contains('cptt-settle-delete')?'delete':'edit'); if(tr){ var inp=tr.querySelector('.cptt-settle-edit-amount'); if(inp) fd.append('amount', inp.value); }
					fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(j){ if(j&&j.success) location.reload(); else alert((j&&j.data)?j.data:'خطا'); });
				});

				var ctx = {};
				function openModal(d){ ctx = d; info.innerHTML = 'پروژه: <b>'+d.projectTitle+'</b><br>مرحله: <b>'+d.stepTitle+'</b><br>کارشناس: <b>'+d.expertName+'</b>'; paidEl.textContent = Number(d.paid).toLocaleString('en-US'); alreadyEl.textContent = Number(d.already).toLocaleString('en-US'); amountEl.value = ''; msgEl.textContent=''; modal.style.display='flex'; }
				function closeModal(){ modal.style.display='none'; }
				function toNum(v){ return parseFloat(String(v||'').replace(/[,\s]/g,''))||0; }
				document.addEventListener('click', function(e){
					var btn = e.target.closest('.cptt-step-settle-btn'); if (!btn) return;
					openModal({ projectId: btn.dataset.projectId, projectTitle: btn.dataset.projectTitle, stepId: btn.dataset.stepId, stepTitle: btn.dataset.stepTitle, expertId: btn.dataset.expertId, expertName: btn.dataset.expertName, paid: toNum(btn.dataset.paid), already: toNum(btn.dataset.alreadyPaid) });
				});
				btnC.addEventListener('click', closeModal);
				modal.addEventListener('click', function(e){ if (e.target===modal) closeModal(); });
				function submit(mode){
					var amount = toNum(amountEl.value);
					var maxAmount = ctx.paid - ctx.already;
					if (amount <= 0) { msgEl.style.color='#dc2626'; msgEl.textContent='لطفا مبلغ پرداخت به کارشناس را وارد کنید.'; return; }
					if (amount > maxAmount + 0.001) { msgEl.style.color='#dc2626'; msgEl.textContent='مبلغ پرداختی نمی‌تواند از مانده‌ی این مرحله بیشتر باشد.'; return; }
					btnF.disabled = btnP.disabled = true; msgEl.style.color='#475569'; msgEl.textContent='در حال ذخیره...';
					var fd = new FormData();
					fd.append('action','cptt_step_settle');
					fd.append('nonce', (window.cpttAdminNonce||''));
					fd.append('project_id', ctx.projectId);
					fd.append('step_id', ctx.stepId);
					fd.append('expert_id', ctx.expertId);
					fd.append('amount', String(amount));
					fd.append('mode', mode);
					fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:fd}).then(function(r){return r.json();}).then(function(j){
						btnF.disabled = btnP.disabled = false;
						if (j && j.success) { msgEl.style.color='#059669'; msgEl.textContent='ذخیره شد. صفحه بروزرسانی می‌شود...'; setTimeout(function(){ location.reload(); }, 700); }
						else { msgEl.style.color='#dc2626'; msgEl.textContent=(j && j.data ? j.data : 'خطا در ذخیره'); }
					}).catch(function(){ btnF.disabled = btnP.disabled = false; msgEl.style.color='#dc2626'; msgEl.textContent='خطای شبکه'; });
				}
				btnF.addEventListener('click', function(){ submit('final'); });
				btnP.addEventListener('click', function(){ submit('partial'); });
			})();
			</script>

			<?php
			/* === v5.4.5: محاسبه‌ی لیست بدهکاران بر اساس مشتری === */
			$debtors_map = []; // user_id => ['name'=>, 'phone'=>, 'total_cost'=>, 'total_paid'=>, 'remain'=>, 'projects'=>[]]
			foreach ($rows as $rr) {
				$cid = (int)$rr['client_id'];
				if (!$cid) continue;
				$fin = $rr['fin'];
				$remain_p = (float)$fin['remain'];
				if ($remain_p <= 0) continue;
				if (!isset($debtors_map[$cid])) {
					$u = get_user_by('id', $cid);
					$phone = '';
					if ($u) {
						$phone = (string) get_user_meta($cid, 'billing_phone', true);
						if ($phone === '') $phone = (string) get_user_meta($cid, 'cptt_phone', true);
						if ($phone === '') $phone = (string) get_user_meta($cid, 'mobile', true);
					}
					$debtors_map[$cid] = [
						'id' => $cid,
						'name' => $rr['client'] ?: ($u ? $u->display_name : '—'),
						'phone' => $phone,
						'total_cost' => 0.0,
						'total_paid' => 0.0,
						'remain' => 0.0,
						'projects' => [],
					];
				}
				$debtors_map[$cid]['total_cost'] += (float)$fin['cost'];
				$debtors_map[$cid]['total_paid'] += (float)$fin['paid'];
				$debtors_map[$cid]['remain']    += $remain_p;
				$debtors_map[$cid]['projects'][] = [
					'id' => (int)$rr['post']->ID,
					'title' => get_the_title($rr['post']),
					'remain' => $remain_p,
				];
			}
			// مرتب‌سازی نزولی بر اساس مانده‌ی بدهی
			uasort($debtors_map, function($a,$b){ return ($b['remain'] <=> $a['remain']); });
			$debtors_total_remain = 0.0;
			foreach ($debtors_map as $dd) { $debtors_total_remain += $dd['remain']; }
			$debtors_count = count($debtors_map);
			?>

			<!-- Debtors Modal (v5.4.5) -->
			<div class="cptt-debtors-modal" id="cptt-debtors-modal" hidden>
				<div class="cptt-debtors-modal__backdrop" data-cptt-debtors-close></div>
				<div class="cptt-debtors-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="cptt-debtors-title">
					<div class="cptt-debtors-modal__header">
						<h3 class="cptt-debtors-modal__title" id="cptt-debtors-title">
							👥 لیست بدهکاران
							<span class="cptt-debtors-count"><?php echo number_format_i18n($debtors_count); ?></span>
						</h3>
						<button type="button" class="cptt-debtors-modal__close" data-cptt-debtors-close aria-label="بستن">×</button>
					</div>

					<div class="cptt-debtors-modal__summary">
						<div>
							<span>تعداد بدهکاران</span>
							<strong class="num-debtor"><?php echo number_format_i18n($debtors_count); ?> نفر</strong>
						</div>
						<div>
							<span>کل بدهی</span>
							<strong class="num-total"><?php echo number_format_i18n((int)$debtors_total_remain); ?> <small style="font-weight:700; font-size:10px; color:#94a3b8;">تومان</small></strong>
						</div>
						<div>
							<span>میانگین بدهی هر نفر</span>
							<strong><?php echo $debtors_count ? number_format_i18n((int)round($debtors_total_remain / $debtors_count)) : '0'; ?> <small style="font-weight:700; font-size:10px; color:#94a3b8;">تومان</small></strong>
						</div>
					</div>

					<div class="cptt-debtors-modal__body">
						<?php if (empty($debtors_map)): ?>
							<div class="cptt-debtors-empty">
								<span class="icn">🎉</span>
								<div style="font-weight:800; color:#15803d; margin-bottom:4px;">هیچ بدهکاری وجود ندارد!</div>
								<div style="font-size:12px;">همه‌ی مشتریان تسویه‌ی کامل دارند.</div>
							</div>
						<?php else: ?>
							<div class="cptt-debtors-modal__search">
								<input type="search" id="cptt-debtors-search" placeholder="🔎 جستجو بر اساس نام مشتری یا شماره تماس...">
							</div>
							<ul class="cptt-debtors-list" id="cptt-debtors-list">
								<?php foreach ($debtors_map as $dd):
									$search_str = mb_strtolower($dd['name'] . ' ' . $dd['phone']);
									$projects_str = '';
									foreach ($dd['projects'] as $pp) {
										$projects_str .= ' ' . $pp['title'];
									}
									$user_edit = get_edit_user_link($dd['id']);
								?>
									<li class="cptt-debtors-item" data-search="<?php echo esc_attr($search_str . ' ' . mb_strtolower($projects_str)); ?>">
										<div>
											<div class="cptt-debtors-item__name">
												<?php echo esc_html($dd['name']); ?>
												<?php if ($user_edit): ?>
													<a href="<?php echo esc_url($user_edit); ?>" target="_blank" style="font-size:11px; font-weight:600; color:#6366f1; text-decoration:none; margin-right:6px;">↗ مشاهده کاربر</a>
												<?php endif; ?>
											</div>
											<div class="cptt-debtors-item__meta">
												<?php if ($dd['phone']): ?>
													<span>📞 <?php echo esc_html($dd['phone']); ?></span>
												<?php endif; ?>
												<span>📁 <?php echo count($dd['projects']); ?> پروژه</span>
												<span>💰 پرداختی: <?php echo number_format_i18n((int)$dd['total_paid']); ?> از <?php echo number_format_i18n((int)$dd['total_cost']); ?></span>
											</div>
											<?php if (!empty($dd['projects'])): ?>
												<div class="cptt-debtors-item__meta" style="margin-top:6px;">
													<?php foreach ($dd['projects'] as $pp): ?>
														<a href="<?php echo esc_url(get_edit_post_link($pp['id'])); ?>" target="_blank" title="ویرایش پروژه">• <?php echo esc_html($pp['title']); ?> (<?php echo number_format_i18n((int)$pp['remain']); ?>)</a>
													<?php endforeach; ?>
												</div>
											<?php endif; ?>
										</div>
										<div class="cptt-debtors-item__amount">
											<?php echo number_format_i18n((int)$dd['remain']); ?>
											<small>تومان مانده</small>
										</div>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<script>
			(function(){
				// تعداد بدهکاران را روی دکمه نشان بده
				var badge = document.getElementById('cptt-debtors-count-badge');
				if (badge) badge.textContent = '<?php echo (int)$debtors_count; ?>';

				var modal = document.getElementById('cptt-debtors-modal');
				var trigger = document.getElementById('cptt-acct-debtors');
				if (!modal || !trigger) return;

				function open(){ modal.removeAttribute('hidden'); document.body.style.overflow='hidden'; var s=document.getElementById('cptt-debtors-search'); if(s) setTimeout(function(){s.focus();},100); }
				function close(){ modal.setAttribute('hidden',''); document.body.style.overflow=''; }

				trigger.addEventListener('click', open);
				modal.querySelectorAll('[data-cptt-debtors-close]').forEach(function(el){
					el.addEventListener('click', close);
				});
				document.addEventListener('keydown', function(e){
					if (e.key === 'Escape' && !modal.hasAttribute('hidden')) close();
				});

				// جستجوی زنده
				var searchInput = document.getElementById('cptt-debtors-search');
				if (searchInput) {
					searchInput.addEventListener('input', function(){
						var q = this.value.trim().toLowerCase();
						var items = modal.querySelectorAll('.cptt-debtors-item');
						items.forEach(function(it){
							var data = (it.getAttribute('data-search') || '').toLowerCase();
							it.style.display = (!q || data.indexOf(q) > -1) ? '' : 'none';
						});
					});
				}
			})();
			</script>

			<script>window.cpttAdminNonce = '<?php echo esc_js(wp_create_nonce('cptt_admin_nonce')); ?>';</script>
		</div>
		<?php
	}
	public function ajax_quick_pay() {
		if (!current_user_can('edit_cptt_projects')) wp_send_json_error('no_access',403);
		check_ajax_referer('cptt_admin_nonce','nonce');
		$project_id=isset($_POST['project_id'])?absint($_POST['project_id']):0;
		$amount=isset($_POST['amount'])?(float)$_POST['amount']:0;
		if (!$project_id||$amount<=0) wp_send_json_error('invalid',400);
		if (get_post_type($project_id)!=='cptt_project') wp_send_json_error('invalid_project',400);
		$steps=get_post_meta($project_id,'_cptt_steps',true);
		if (!is_array($steps)) $steps=[];
		$added=false;
		foreach ($steps as &$s) {
			$cost=(float)($s['cost']??0); $paid=(float)($s['paid']??0);
			if ($cost>0&&$paid<$cost) {
				$need=$cost-$paid;
				$pay=min($amount,$need);
				$s['paid']=$paid+$pay;
				$amount-=$pay;
				$added=true;
				if ($amount<=0) break;
			}
		}
		unset($s);
		if ($added) {
			update_post_meta($project_id,'_cptt_steps',$steps);
			$fin=$this->project_financial_data($project_id);
			wp_send_json_success(['fin'=>$fin]);
		}
		wp_send_json_error('no_applicable_step',400);
	}
	public function render_details_metabox($post) {
		wp_nonce_field('cptt_save_project','cptt_nonce');
		$client_id=(int)get_post_meta($post->ID,'_cptt_client_user_id',true);
		$product_id=(int)get_post_meta($post->ID,'_cptt_product_id',true);
		if (!$product_id) $product_id=(int)get_post_meta($post->ID,'_cptt_wc_product_id',true);
		$is_settled=(int)get_post_meta($post->ID,'_cptt_is_settled',true);
		$expert_ids=get_post_meta($post->ID,'_cptt_expert_user_ids',true);
		if (!is_array($expert_ids)) $expert_ids=[];
		if (empty($expert_ids)) { $legacy=(int)get_post_meta($post->ID,'_cptt_expert_user_id',true); if ($legacy) $expert_ids=[$legacy]; }
		$expert_ids=array_values(array_filter(array_map('strval',$expert_ids)));
		$selectedSet=array_fill_keys($expert_ids,true);
		$users=get_users(['fields'=>['ID','display_name','user_email','user_login']]);
		$experts=get_users(['role__in'=>['cptt_expert','administrator'],'fields'=>['ID','display_name','user_email']]);
		$shown=[]; foreach ($experts as $u) $shown[(string)$u->ID]=true;
		foreach ($expert_ids as $sid) {
			if (!isset($shown[$sid])) {
				$u=get_user_by('id',(int)$sid);
				if ($u) { $experts[]=(object)['ID'=>$u->ID,'display_name'=>$u->display_name,'user_email'=>$u->user_email]; $shown[$sid]=true; }
			}
		}
		usort($experts,function($a,$b){return strcmp($a->display_name,$b->display_name);});
		$deadline_at=(int)get_post_meta($post->ID,'_cptt_deadline_at',true);
		$deadline_local=$deadline_at?$this->datetime_local_value($deadline_at):'';
		$wc_cats=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false]);
		if (is_wp_error($wc_cats)) $wc_cats=[];
		$selected_cats=get_post_meta($post->ID,'_cptt_wc_cat_ids',true);
		if (!is_array($selected_cats)) $selected_cats=[];
		$selected_cats=array_map('intval',$selected_cats);
		$wc_order_id=(int)get_post_meta($post->ID,'_cptt_wc_order_id',true);
		$auto_settled=false;
		if ($wc_order_id && function_exists('wc_get_order')) {
			$order=wc_get_order($wc_order_id);
			if ($order && $order->is_paid()) { $auto_settled=true; $is_settled=1; }
		}
		?>
		<div class="cptt-admin-box">
			<p class="cptt-row">
				<label class="cptt-label" for="cptt_client_user_id">مشتری</label>
				<select id="cptt_client_user_id" name="cptt_client_user_id" class="cptt-select">
					<option value="">— انتخاب کنید —</option>
					<?php foreach ($users as $u): $phone=(string)get_user_meta($u->ID,'billing_phone',true); if($phone==='') $phone=(string)get_user_meta($u->ID,'cptt_phone',true); $search=trim($u->display_name.' '.$u->user_email.' '.$u->user_login.' '.get_user_meta($u->ID,'first_name',true).' '.get_user_meta($u->ID,'last_name',true).' '.get_user_meta($u->ID,'billing_first_name',true).' '.get_user_meta($u->ID,'billing_last_name',true).' '.$phone); ?><option value="<?php echo esc_attr($u->ID); ?>" data-search="<?php echo esc_attr($search); ?>" <?php selected($client_id,$u->ID); ?>><?php echo esc_html($u->display_name.($phone?' ('.$phone.')':($u->user_email?' ('.$u->user_email.')':''))); ?></option><?php endforeach; ?>
				</select>
				<span class="cptt-help">این کاربر در فرانت «پروژه‌های من» را می‌بیند.</span>
			</p>
			<p class="cptt-row">
				<label class="cptt-label" for="cptt_wc_cats">دسته‌بندی (ووکامرس)</label>
				<select id="cptt_wc_cats" name="cptt_wc_cats[]" multiple class="cptt-select" style="height:auto;min-height:90px;">
					<?php foreach ($wc_cats as $cat): ?><option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected(in_array((int)$cat->term_id,$selected_cats,true)); ?>><?php echo esc_html($cat->name); ?></option><?php endforeach; ?>
				</select>
				<span class="cptt-help">دسته‌بندی ووکامرس به‌عنوان برچسب پروژه استفاده می‌شود. انتخاب دسته‌بندی، لیست محصولات را محدود می‌کند.</span>
			</p>
			<p class="cptt-row">
				<label class="cptt-label" for="cptt_product_id">محصول مرتبط</label>
				<span><?php echo $this->product_select_html('cptt_product_id',$product_id); ?><span class="cptt-help">اگر پروژه از سفارش آنلاین ساخته شده باشد، محصول به صورت خودکار انتخاب می‌شود.</span></span>
			</p>

			<p class="cptt-row">
				<label class="cptt-label" for="cptt_project_label_id">لیبل پروژه</label>
				<span><select id="cptt_project_label_id" name="cptt_project_label_id" class="cptt-select"><option value="">— بدون لیبل —</option><?php $plabel=(string)get_post_meta($post->ID,'_cptt_project_label_id',true); foreach ((class_exists('CPTT_Core') ? CPTT_Core::get_project_labels() : []) as $lbl): ?><option value="<?php echo esc_attr($lbl['id']); ?>" <?php selected($plabel,$lbl['id']); ?>><?php echo esc_html($lbl['name']); ?></option><?php endforeach; ?></select><span class="cptt-help">این لیبل جای وضعیت پیش‌فرض روی کارت‌های پروژه نمایش داده می‌شود.</span></span>
			</p>

			<p class="cptt-row">
				<label class="cptt-label">وضعیت تسویه</label>
				<?php 
				$fin_details = $this->project_financial_data($post->ID);
				$is_settled_details = ($fin_details['remain'] <= 0 && $fin_details['cost'] > 0) ? 1 : 0;
				if ($is_settled_details): ?>
				<span class="cptt-chip cptt-chip--completed" style="background:rgba(34,197,94,0.12); color:#065f46; border:1px solid rgba(34,197,94,0.22); padding:4px 10px; border-radius:12px; font-weight:bold;">✓ تسویه شده (خودکار بر اساس مراحل)</span>
				<?php else: ?>
				<span class="cptt-chip cptt-chip--in_progress" style="background:rgba(245,158,11,0.12); color:#92400e; border:1px solid rgba(245,158,11,0.22); padding:4px 10px; border-radius:12px; font-weight:bold;">⏳ تسویه نشده (دارای مانده بدهی)</span>
				<?php endif; ?>
			</p>
			<input type="hidden" name="cptt_experts_present" value="1" />
			<div class="cptt-row cptt-row--experts">
				<div class="cptt-label">کارشناسان</div>
				<div class="cptt-expertsList">
					<?php foreach ($experts as $u):
						$checked=isset($selectedSet[(string)$u->ID]);
					?><label class="cptt-expertOpt <?php echo $checked?'is-checked':''; ?>"><input type="checkbox" name="cptt_expert_user_ids[]" value="<?php echo esc_attr($u->ID); ?>" <?php checked($checked); ?> /><span class="cptt-expertText"><span class="cptt-expertName"><?php echo esc_html($u->display_name); ?></span><small class="cptt-expertEmail"><?php echo esc_html($u->user_email); ?></small></span></label><?php endforeach; ?>
				</div>
				<span class="cptt-help">برای انتخاب چند کارشناس تیک بزنید.</span>
			</div>
			<p class="cptt-row">
				<label class="cptt-label" for="cptt_deadline_local">مهلت پروژه</label>
				<span><input type="text" class="cptt-jalali-datetime" id="cptt_deadline_local" name="cptt_deadline_local" value="<?php echo esc_attr($deadline_local); ?>" placeholder="۱۴۰۳/۰۱/۳۱ ۱۴:۳۰" /><span class="cptt-help">اگر پروژه تا این زمان تکمیل نشود، پیامک هشدار برای مسئول پروژه ارسال می‌شود.</span></span>
			</p>
		</div>
		<?php
	}
	public function render_notes_metabox($post) {
		$notes=get_post_meta($post->ID,'_cptt_project_notes',true);
		if (!is_array($notes)) $notes=[];
		?>
		<div class="cptt-notes">
			<div class="cptt-notes__list">
				<?php if (empty($notes)): ?><div class="cptt-notes__empty">هنوز یادداشتی ثبت نشده.</div><?php endif; ?>
				<?php foreach ($notes as $note):
					$u=get_user_by('id',(int)($note['user_id']??0));
					$name=$u?$u->display_name:'کارشناس';
					$time=!empty($note['time'])?CPTT_Core::jalali_datetime((int)$note['time']):'';
				?>
				<div class="cptt-note">
					<div class="cptt-note__head"><strong><?php echo esc_html($name); ?></strong><span><?php echo esc_html($time); ?></span></div>
					<div class="cptt-note__body"><?php echo nl2br(esc_html($note['content']??'')); ?></div>
				</div>
				<?php endforeach; ?>
			</div>
			<div class="cptt-notes__add">
				<label class="cptt-label" for="cptt_new_note">یادداشت جدید</label>
				<textarea id="cptt_new_note" name="cptt_new_note" rows="3" style="width:100%;" placeholder="یادداشت خود را بنویسید..."></textarea>
			</div>
		</div>
		<?php
	}
	public function render_accounting_metabox($post) {
		$fin=$this->project_financial_data($post->ID);
		$steps=get_post_meta($post->ID,'_cptt_steps',true);
		if (!is_array($steps)) $steps=[];
		?>
		<div class="cptt-acct-box">
			<div class="cptt-acct-grid cptt-acct-grid--3">
				<div class="cptt-acct-card"><div class="cptt-acct-label">کل هزینه</div><div class="cptt-acct-value"><?php echo number_format($fin['cost']); ?></div></div>
				<div class="cptt-acct-card"><div class="cptt-acct-label">کل دریافتی</div><div class="cptt-acct-value" style="color:#15803d;"><?php echo number_format($fin['paid']); ?></div></div>
				<div class="cptt-acct-card <?php echo $fin['remain']>0?'is-remain':'is-done'; ?>"><div class="cptt-acct-label">مانده</div><div class="cptt-acct-value"><?php echo number_format($fin['remain']); ?></div></div>
			</div>
			<?php if ($fin['cost']>0): ?>
			<div class="cptt-acct-bar-wrap" style="margin-top:10px;">
				<div class="cptt-acct-bar" style="--bar-color:<?php echo $fin['percent']>=100?'#22c55e':($fin['percent']>=50?'#f59e0b':'#ef4444'); ?>"><span style="width:<?php echo min(100,(float)$fin['percent']); ?>%;"></span></div>
				<small><?php echo (float)$fin['percent']; ?>% تسویه</small>
			</div>
			<?php endif; ?>
			<?php if (!empty($steps)): ?>
			<div class="cptt-acct-steps" style="margin-top:12px;">
				<div class="cptt-acct-label" style="margin-bottom:8px;">جزئیات مراحل</div>
				<?php foreach ($steps as $i=>$s):
					$sc=(float)($s['cost']??0); $sp=(float)($s['paid']??0); $sr=$sc-$sp;
				?>
				<div class="cptt-acct-step-mini">
					<div class="cptt-acct-step-mini__title"><?php echo esc_html(($i+1).'. '.($s['title']??'بدون عنوان')); ?></div>
					<div class="cptt-acct-step-mini__nums">
						<span>هزینه: <?php echo number_format($sc); ?></span>
						<span>دریافتی: <?php echo number_format($sp); ?></span>
						<span class="<?php echo $sr>0?'is-remain':'is-done'; ?>">مانده: <?php echo number_format($sr); ?></span>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
			<?php if ($fin['remain']>0): ?><p class="cptt-help">این پروژه تسویه نشده است.</p><?php else: ?><p class="cptt-help" style="color:#15803d;">وضعیت مالی این پروژه تسویه شده است.</p><?php endif; ?>
		</div>
		<?php
	}
	public function render_steps_metabox_project($post) {
		$steps=get_post_meta($post->ID,'_cptt_steps',true);
		if (!is_array($steps)) $steps=[];
		$templates=get_posts(['post_type'=>'cptt_template','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
		$check_tpls=get_posts(['post_type'=>'cptt_checklist_tpl','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
		$this->render_steps_editor($post,$steps,false,$templates,$check_tpls);
	}
	public function render_steps_metabox_template($post) {
		wp_nonce_field('cptt_save_template','cptt_template_nonce');
		$steps=get_post_meta($post->ID,'_cptt_template_steps',true);
		if (!is_array($steps)) $steps=[];
		$check_tpls=get_posts(['post_type'=>'cptt_checklist_tpl','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
		$this->render_steps_editor($post,$steps,true,[],$check_tpls);
	}
	private function render_steps_editor($post,$steps,$is_template,$templates,$check_tpls) {
		?>
		<div class="cptt-admin-steps" data-is-template="<?php echo $is_template?'1':'0'; ?>">
			<?php if (!$is_template): ?>
			<div class="cptt-toolbar">
				<div class="cptt-toolbar__left">
					<label for="cptt_template_select"><strong>بارگذاری تمپلیت مراحل:</strong></label>
					<select id="cptt_template_select"><option value="">— انتخاب تمپلیت —</option><?php foreach ($templates as $t): ?><option value="<?php echo esc_attr($t->ID); ?>"><?php echo esc_html(get_the_title($t)); ?></option><?php endforeach; ?></select>
					<button type="button" class="button" id="cptt_apply_template_btn">اعمال تمپلیت</button>
					<span class="cptt-help">اعمال تمپلیت، مراحل فعلی را جایگزین می‌کند.</span>
				</div>
			</div>
			<?php endif; ?>
			<div id="cptt-steps-rows">
				<?php foreach ($steps as $i=>$step):
					$step_id=isset($step['id'])?(string)$step['id']:(function_exists('wp_generate_uuid4')?wp_generate_uuid4():('st_'.wp_rand(1000,9999)));
					$title=isset($step['title'])?$step['title']:'';
					$status=isset($step['status'])?$step['status']:'todo';
					$desc=isset($step['desc'])?$step['desc']:'';
					$due_local=!empty($step['due_at'])?$this->datetime_local_value((int)$step['due_at']):'';
					$updated=isset($step['updated_at_fa'])?(string)$step['updated_at_fa']:'—';
					$updated_by_name='—';
					if (!empty($step['updated_by'])) { $u=get_user_by('id',(int)$step['updated_by']); if($u) $updated_by_name=$u->display_name; }
					$checklist=isset($step['checklist'])&&is_array($step['checklist'])?$step['checklist']:[];
					$user_tasks=isset($step['user_tasks'])&&is_array($step['user_tasks'])?$step['user_tasks']:[];
				?>
				<div class="cptt-step-row" data-step-id="<?php echo esc_attr($step_id); ?>">
					<div class="cptt-stepCard">
						<div class="cptt-stepCard__head">
							<div class="cptt-stepCard__drag cptt-drag-handle" title="جابجایی">⋮⋮</div>
							<div class="cptt-stepCard__title">
								<label class="cptt-fieldLabel">عنوان مرحله</label>
								<input type="hidden" name="cptt_steps[<?php echo esc_attr($i); ?>][id]" value="<?php echo esc_attr($step_id); ?>" />
								<input type="text" name="cptt_steps[<?php echo esc_attr($i); ?>][title]" value="<?php echo esc_attr($title); ?>" placeholder="عنوان مرحله" />
							</div>
							<div class="cptt-stepCard__status">
								<label class="cptt-fieldLabel">وضعیت</label>
								<select name="cptt_steps[<?php echo esc_attr($i); ?>][status]">
									<option value="todo" <?php selected($status,'todo'); ?>>انجام‌نشده</option>
									<option value="current" <?php selected($status,'current'); ?>>در حال انجام</option>
									<option value="done" <?php selected($status,'done'); ?>>انجام‌شده</option>
								</select>
							</div>
							<?php if (!$is_template): ?>
							<div class="cptt-stepCard__updated">
								<label class="cptt-fieldLabel">آخرین تغییر</label>
								<div class="cptt-updBox"><div><?php echo esc_html($updated); ?></div><small>توسط: <?php echo esc_html($updated_by_name); ?></small></div>
							</div>
							<?php endif; ?>
							<div class="cptt-stepCard__delete"><label class="cptt-fieldLabel">&nbsp;</label><button type="button" class="button cptt-remove-step">×</button></div>
						</div>
						<div class="cptt-stepCard__body">
							<div class="cptt-stepCard__desc">
								<label class="cptt-fieldLabel">توضیحات پاپ‌آپ</label>
								<textarea name="cptt_steps[<?php echo esc_attr($i); ?>][desc]" rows="3" placeholder="توضیحات..."><?php echo esc_textarea($desc); ?></textarea>
								<label class="cptt-fieldLabel" style="margin-top:10px;">مهلت مرحله</label>
								<input type="text" class="cptt-jalali-datetime" name="cptt_steps[<?php echo esc_attr($i); ?>][due_at_local]" value="<?php echo esc_attr($due_local); ?>" placeholder="۱۴۰۳/۰۱/۳۱ ۱۴:۳۰" />
								<span class="cptt-help">تاریخ و ساعت شمسی به زمان تهران.</span>
							</div>
							<div class="cptt-stepCard__checklist">
								<div class="cptt-checklist-head">
									<div class="cptt-checklist-title">چک‌لیست (متن + لینک نتیجه)</div>
									<div class="cptt-checklist-toolbar">
										<select class="cptt-checktpl-select"><option value="">— تمپلیت چک‌لیست —</option><?php foreach ($check_tpls as $ct): ?><option value="<?php echo esc_attr($ct->ID); ?>"><?php echo esc_html(get_the_title($ct)); ?></option><?php endforeach; ?></select>
										<button type="button" class="button cptt-apply-checktpl">اعمال</button>
										<button type="button" class="button button-primary cptt-add-checkitem">+ آیتم</button>
									</div>
								</div>
								<div class="cptt-checkitems" data-step-index="<?php echo esc_attr($i); ?>">
									<?php foreach ($checklist as $j=>$it):
										$cid=isset($it['id'])?(string)$it['id']:(function_exists('wp_generate_uuid4')?wp_generate_uuid4():('chk_'.wp_rand(1000,9999)));
										$text=isset($it['text'])?$it['text']:'';
										$url=isset($it['url'])?$it['url']:'';
										$done=!empty($it['done'])?1:0;
										$done_at_fa=!empty($it['done_at_fa'])?(string)$it['done_at_fa']:'';
										$done_by_name='';
										if (!empty($it['done_by'])) { $uu=get_user_by('id',(int)$it['done_by']); if($uu)$done_by_name=$uu->display_name; }
									?>
										<div class="cptt-checkitem-row" data-check-id="<?php echo esc_attr($cid); ?>">
											<span class="cptt-checkitem-handle" title="جابجایی">⋮</span>
											<input type="hidden" name="cptt_steps[<?php echo esc_attr($i); ?>][checklist][<?php echo esc_attr($j); ?>][id]" value="<?php echo esc_attr($cid); ?>" />
											<label class="cptt-checkitem-done"><input type="checkbox" name="cptt_steps[<?php echo esc_attr($i); ?>][checklist][<?php echo esc_attr($j); ?>][done]" value="1" <?php checked($done,1); ?> /> انجام شد<?php if ($done_at_fa): ?><small><?php echo esc_html($done_at_fa); ?><?php if ($done_by_name): ?><?php echo esc_html(' — توسط: '.$done_by_name); ?><?php endif; ?></small><?php endif; ?></label>
											<input type="text" name="cptt_steps[<?php echo esc_attr($i); ?>][checklist][<?php echo esc_attr($j); ?>][text]" value="<?php echo esc_attr($text); ?>" placeholder="متن آیتم..." />
											<input type="url" name="cptt_steps[<?php echo esc_attr($i); ?>][checklist][<?php echo esc_attr($j); ?>][url]" value="<?php echo esc_attr($url); ?>" placeholder="لینک نتیجه (اختیاری)..." />
											<button type="button" class="button cptt-remove-checkitem">×</button>
										</div>
									<?php endforeach; ?>
								</div>
								<p class="cptt-help" style="margin-top:8px;">اگر همه آیتم‌های چک‌لیست تیک بخورند، مرحله خودکار «انجام‌شده» می‌شود.</p>
							</div>
							<?php $this->render_user_tasks_editor($i,$user_tasks); ?>
							<?php if (!$is_template): ?>
							<div class="cptt-stepCard__billing">
								<div class="cptt-fieldLabel">حساب و کتاب مرحله</div>
								<div class="cptt-billing-row" style="grid-template-columns: repeat(4, 1fr) !important; gap:10px !important;">
									<label>هزینه (تومان) <input type="text" name="cptt_steps[<?php echo esc_attr($i); ?>][cost]" value="<?php echo esc_attr(number_format($step['cost']??0)); ?>" class="cptt-step-cost cptt-currency-input" step="any" /></label>
									<label>دریافتی (تومان) <input type="text" name="cptt_steps[<?php echo esc_attr($i); ?>][paid]" value="<?php echo esc_attr(number_format($step['paid']??0)); ?>" class="cptt-step-paid cptt-currency-input" step="any" /></label>
									<?php /* v5.4.3: فیلدهای سهم/پرداختی کارشناس از UI متاباکس حذف شدند؛ این مقادیر در صفحه‌ی «حساب و کتاب» مدیریت می‌شوند. مقادیر فعلی به‌صورت hidden حفظ می‌شوند. */ ?>
									<input type="hidden" name="cptt_steps[<?php echo esc_attr($i); ?>][expert_share]" value="<?php echo esc_attr((float)($step['expert_share']??0)); ?>" />
									<input type="hidden" name="cptt_steps[<?php echo esc_attr($i); ?>][expert_paid]" value="<?php echo esc_attr((float)($step['expert_paid']??0)); ?>" />
								</div>
								<div style="margin-top:6px; font-size:11px; color:#64748b;">
									<span class="cptt-step-remain">مانده مرحله: <?php echo number_format(floatval($step['cost']??0)-floatval($step['paid']??0)); ?> تومان</span>
								</div>
								<?php
								// v5.4.3: نمایش وضعیت تسویه‌ی مرحله (مدیریت از صفحه‌ی «حساب و کتاب»)
								$_a_ep_paid = (float)($step['expert_paid']??0);
								$_a_admin_received = (float)($step['admin_received']??0);
								$_a_settled = !empty($step['step_settled']) ? 1 : 0;
								$_a_settle_fa = isset($step['settle_at_fa']) ? (string)$step['settle_at_fa'] : '';
								if ($_a_ep_paid > 0 || $_a_settled || $_a_admin_received > 0): ?>
								<div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap; align-items:center; padding:8px 10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; font-size:11px;">
									<span style="color:#475569;">💳 وضعیت تسویه:</span>
									<span>به کارشناس: <b style="color:#2563eb;"><?php echo number_format($_a_ep_paid); ?></b></span>
									<span>به مدیر: <b style="color:#15803d;"><?php echo number_format($_a_admin_received); ?></b></span>
									<?php if ($_a_settled): ?>
										<span style="background:rgba(34,197,94,.12); color:#065f46; border:1px solid rgba(34,197,94,.22); padding:2px 8px; border-radius:6px; font-weight:800;">✓ تسویه نهایی<?php if ($_a_settle_fa) echo ' — ' . esc_html($_a_settle_fa); ?></span>
									<?php else: ?>
										<span style="background:rgba(245,158,11,.12); color:#92400e; border:1px solid rgba(245,158,11,.22); padding:2px 8px; border-radius:6px; font-weight:800;">⏳ بدون تسویه نهایی</span>
									<?php endif; ?>
									<a href="<?php echo esc_url(admin_url('edit.php?post_type=cptt_project&page=cptt-project-accounting')); ?>" style="margin-right:auto; color:#6366f1; text-decoration:none;">مدیریت در حساب و کتاب ↗</a>
								</div>
								<?php endif; ?>
							</div>
							<!-- Step Assigned Expert (فقط اگر >1 کارشناس) -->
							<?php
							$_admin_proj_experts = class_exists('CPTT_Core') ? CPTT_Core::get_project_expert_ids(get_the_ID()) : [];
							$_admin_step_assigned_ids = isset($step['assigned_expert_ids']) && is_array($step['assigned_expert_ids']) ? array_values(array_filter(array_unique(array_map('intval',$step['assigned_expert_ids'])))) : [];
							if (empty($_admin_step_assigned_ids) && !empty($step['assigned_expert_id'])) $_admin_step_assigned_ids = [(int)$step['assigned_expert_id']];
							if (count($_admin_proj_experts) >= 1): ?>
							<div class="cptt-stepCard__expertAssign" style="margin-top:10px;">
								<div class="cptt-fieldLabel">کارشناسان مسئول مرحله</div>
								<select name="cptt_steps[<?php echo esc_attr($i); ?>][assigned_expert_ids][]" multiple size="<?php echo esc_attr(min(5, max(2, count($_admin_proj_experts)))); ?>" style="width:100%;">
									<?php foreach ($_admin_proj_experts as $_ape_id): $ape_user = get_user_by('id',(int)$_ape_id); if (!$ape_user) continue; ?>
									<option value="<?php echo esc_attr($_ape_id); ?>" <?php echo in_array((int)$_ape_id, $_admin_step_assigned_ids, true) ? 'selected' : ''; ?>><?php echo esc_html($ape_user->display_name); ?></option>
									<?php endforeach; ?>
								</select>
								<input type="hidden" name="cptt_steps[<?php echo esc_attr($i); ?>][assigned_expert_id]" value="<?php echo esc_attr(!empty($_admin_step_assigned_ids) ? (int)$_admin_step_assigned_ids[0] : 0); ?>">
							</div>
							<?php endif; ?>
							<?php endif; ?>
						</div>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
			<button type="button" class="button button-primary" id="cptt-add-step">+ افزودن مرحله</button>
			<script type="text/template" id="cptt-step-template">
				<div class="cptt-step-row" data-step-id="{{uuid}}">
					<div class="cptt-stepCard">
						<div class="cptt-stepCard__head">
							<div class="cptt-stepCard__drag cptt-drag-handle" title="جابجایی">⋮⋮</div>
							<div class="cptt-stepCard__title">
								<label class="cptt-fieldLabel">عنوان مرحله</label>
								<input type="hidden" name="cptt_steps[{{i}}][id]" value="{{uuid}}" />
								<input type="text" name="cptt_steps[{{i}}][title]" value="" placeholder="عنوان مرحله" />
							</div>
							<div class="cptt-stepCard__status">
								<label class="cptt-fieldLabel">وضعیت</label>
								<select name="cptt_steps[{{i}}][status]"><option value="todo">انجام‌نشده</option><option value="current">در حال انجام</option><option value="done">انجام‌شده</option></select>
							</div>
							<?php if (!$is_template): ?>
							<div class="cptt-stepCard__updated"><label class="cptt-fieldLabel">آخرین تغییر</label><div class="cptt-updBox"><div>—</div><small>توسط: —</small></div></div>
							<?php endif; ?>
							<div class="cptt-stepCard__delete"><label class="cptt-fieldLabel">&nbsp;</label><button type="button" class="button cptt-remove-step">×</button></div>
						</div>
						<div class="cptt-stepCard__body">
							<div class="cptt-stepCard__desc">
								<label class="cptt-fieldLabel">توضیحات پاپ‌آپ</label>
								<textarea name="cptt_steps[{{i}}][desc]" rows="3" placeholder="توضیحات..."></textarea>
								<label class="cptt-fieldLabel" style="margin-top:10px;">مهلت مرحله</label>
								<input type="text" class="cptt-jalali-datetime" name="cptt_steps[{{i}}][due_at_local]" value="" placeholder="۱۴۰۳/۰۱/۳۱ ۱۴:۳۰" />
								<span class="cptt-help">تاریخ و ساعت شمسی به زمان تهران.</span>
							</div>
							<div class="cptt-stepCard__checklist">
								<div class="cptt-checklist-head">
									<div class="cptt-checklist-title">چک‌لیست (متن + لینک نتیجه)</div>
									<div class="cptt-checklist-toolbar">
										<select class="cptt-checktpl-select"><option value="">— تمپلیت چک‌لیست —</option><?php foreach ($check_tpls as $ct): ?><option value="<?php echo esc_attr($ct->ID); ?>"><?php echo esc_html(get_the_title($ct)); ?></option><?php endforeach; ?></select>
										<button type="button" class="button cptt-apply-checktpl">اعمال</button>
										<button type="button" class="button button-primary cptt-add-checkitem">+ آیتم</button>
									</div>
								</div>
								<div class="cptt-checkitems" data-step-index="{{i}}"></div>
								<p class="cptt-help" style="margin-top:8px;">اگر همه آیتم‌های چک‌لیست تیک بخورند، مرحله خودکار «انجام‌شده» می‌شود.</p>
							</div>
							<div class="cptt-stepCard__userTasks">
								<div class="cptt-userTasks-head">
									<div class="cptt-userTasks-title">تسک‌های سمت مشتری</div>
									<button type="button" class="button button-primary cptt-add-usertask">+ تسک مشتری</button>
								</div>
								<div class="cptt-usertasks" data-step-index="{{i}}"></div>
								<p class="cptt-help">برای دریافت اطلاعات از مشتری، تسک تعریف کنید. مشتری از پنل خود پاسخ را ثبت می‌کند.</p>
							</div>
							<?php if (!$is_template): ?>
							<div class="cptt-stepCard__billing">
								<div class="cptt-fieldLabel">حساب و کتاب مرحله</div>
								<div class="cptt-billing-row" style="grid-template-columns: repeat(4, 1fr) !important; gap:10px !important;">
									<label>هزینه (تومان) <input type="text" name="cptt_steps[{{i}}][cost]" value="0" class="cptt-step-cost cptt-currency-input" step="any" /></label>
									<label>دریافتی (تومان) <input type="text" name="cptt_steps[{{i}}][paid]" value="0" class="cptt-step-paid cptt-currency-input" step="any" /></label>
									<?php /* v5.4.3: حذف فیلدها از تمپلیت مرحله‌ی جدید */ ?>
									<input type="hidden" name="cptt_steps[{{i}}][expert_share]" value="0" />
									<input type="hidden" name="cptt_steps[{{i}}][expert_paid]" value="0" />
								</div>
							</div>
							<div class="cptt-stepCard__expertAssign" style="margin-top:10px;">
								<div class="cptt-fieldLabel">کارشناسان مسئول مرحله</div>
								<select class="cptt-step-assigned-experts" name="cptt_steps[{{i}}][assigned_expert_ids][]" multiple style="width:100%;"></select>
								<input type="hidden" name="cptt_steps[{{i}}][assigned_expert_id]" value="0" />
							</div>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</script>
			<script type="text/template" id="cptt-checkitem-template">
				<div class="cptt-checkitem-row" data-check-id="{{cid}}">
					<span class="cptt-checkitem-handle" title="جابجایی">⋮</span>
					<input type="hidden" name="cptt_steps[{{i}}][checklist][{{j}}][id]" value="{{cid}}" />
					<label class="cptt-checkitem-done"><input type="checkbox" name="cptt_steps[{{i}}][checklist][{{j}}][done]" value="1" /> انجام شد</label>
					<input type="text" name="cptt_steps[{{i}}][checklist][{{j}}][text]" value="" placeholder="متن آیتم..." />
					<input type="url" name="cptt_steps[{{i}}][checklist][{{j}}][url]" value="" placeholder="لینک نتیجه (اختیاری)..." />
					<button type="button" class="button cptt-remove-checkitem">×</button>
				</div>
			</script>
			<script type="text/template" id="cptt-usertask-template">
				<div class="cptt-usertask-row" data-task-id="{{tid}}">
					<input type="hidden" name="cptt_steps[{{i}}][user_tasks][{{k}}][id]" value="{{tid}}" />
					<input type="text" name="cptt_steps[{{i}}][user_tasks][{{k}}][title]" value="" placeholder="عنوان تسک برای مشتری..." />
					<textarea name="cptt_steps[{{i}}][user_tasks][{{k}}][desc]" rows="2" placeholder="توضیح یا اطلاعات موردنیاز..."></textarea>
					<label class="cptt-usertask-date">مهلت: <input type="text" class="cptt-jalali-datetime" name="cptt_steps[{{i}}][user_tasks][{{k}}][due_at_local]" value="" placeholder="۱۴۰۳/۰۱/۳۱ ۱۴:۳۰" /></label>
					<label class="cptt-usertask-remind"><input type="checkbox" name="cptt_steps[{{i}}][user_tasks][{{k}}][sms_remind]" value="1" checked /> یادآوری پیامکی</label>
					<button type="button" class="button cptt-remove-usertask">×</button>
				</div>
			</script>
		</div>
		<?php
	}
	private function render_user_tasks_editor($step_index,$tasks) {
		if (!is_array($tasks)) $tasks=[];
		?>
		<div class="cptt-stepCard__userTasks">
			<div class="cptt-userTasks-head"><div class="cptt-userTasks-title">تسک‌های سمت مشتری</div><button type="button" class="button button-primary cptt-add-usertask">+ تسک مشتری</button></div>
			<div class="cptt-usertasks" data-step-index="<?php echo esc_attr($step_index); ?>">
				<?php foreach ($tasks as $k=>$task):
					$tid=isset($task['id'])?(string)$task['id']:(function_exists('wp_generate_uuid4')?wp_generate_uuid4():('ut_'.wp_rand(1000,9999)));
					$title=isset($task['title'])?(string)$task['title']:'';
					$desc=isset($task['desc'])?(string)$task['desc']:'';
					$due_local=!empty($task['due_at'])?$this->datetime_local_value((int)$task['due_at']):'';
					$sms_remind=!isset($task['sms_remind'])||!empty($task['sms_remind']);
					$done=!empty($task['done']);
					$response=isset($task['response'])?(string)$task['response']:'';
					$response_url=isset($task['response_url'])?(string)$task['response_url']:'';
					$completed_at_fa=isset($task['completed_at_fa'])?(string)$task['completed_at_fa']:'';
				?>
				<div class="cptt-usertask-row <?php echo $done?'is-done':''; ?>" data-task-id="<?php echo esc_attr($tid); ?>">
					<input type="hidden" name="cptt_steps[<?php echo esc_attr($step_index); ?>][user_tasks][<?php echo esc_attr($k); ?>][id]" value="<?php echo esc_attr($tid); ?>" />
					<input type="text" name="cptt_steps[<?php echo esc_attr($step_index); ?>][user_tasks][<?php echo esc_attr($k); ?>][title]" value="<?php echo esc_attr($title); ?>" placeholder="عنوان تسک برای مشتری..." />
					<textarea name="cptt_steps[<?php echo esc_attr($step_index); ?>][user_tasks][<?php echo esc_attr($k); ?>][desc]" rows="2" placeholder="توضیح یا اطلاعات موردنیاز..."><?php echo esc_textarea($desc); ?></textarea>
					<label class="cptt-usertask-date">مهلت: <input type="text" class="cptt-jalali-datetime" name="cptt_steps[<?php echo esc_attr($step_index); ?>][user_tasks][<?php echo esc_attr($k); ?>][due_at_local]" value="<?php echo esc_attr($due_local); ?>" placeholder="۱۴۰۳/۰۱/۳۱ ۱۴:۳۰" /></label>
					<label class="cptt-usertask-remind"><input type="checkbox" name="cptt_steps[<?php echo esc_attr($step_index); ?>][user_tasks][<?php echo esc_attr($k); ?>][sms_remind]" value="1" <?php checked($sms_remind); ?> /> یادآوری پیامکی</label>
					<?php if ($done): ?>
					<div class="cptt-usertask-response">
						<strong>تکمیل‌شده توسط مشتری</strong>
						<?php if ($completed_at_fa): ?><small><?php echo esc_html($completed_at_fa); ?></small><?php endif; ?>
						<?php if ($response): ?><div><?php echo nl2br(esc_html($response)); ?></div><?php endif; ?>
						<?php if ($response_url): ?><a href="<?php echo esc_url($response_url); ?>" target="_blank" rel="noopener noreferrer">لینک ارسالی مشتری</a><?php endif; ?>
						<?php if (!empty($task['response_file_url'])): ?><a href="<?php echo esc_url($task['response_file_url']); ?>" target="_blank" rel="noopener noreferrer">فایل ارسالی مشتری</a><?php endif; ?>
						<?php if (!empty($task['response_files'])&&is_array($task['response_files'])): foreach ($task['response_files'] as $rf): if (empty($rf['url'])) continue; ?><a href="<?php echo esc_url($rf['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(!empty($rf['name'])?('فایل: '.$rf['name']):'فایل ارسالی مشتری'); ?></a><?php endforeach; endif; ?>
					</div>
					<?php endif; ?>
					<button type="button" class="button cptt-remove-usertask">×</button>
				</div>
				<?php endforeach; ?>
			</div>
			<p class="cptt-help">برای دریافت اطلاعات از مشتری، تسک تعریف کنید. مشتری از پنل خود پاسخ را ثبت می‌کند.</p>
		</div>
		<?php
	}
	public function render_checklist_tpl_metabox($post) {
		wp_nonce_field('cptt_save_checktpl','cptt_checktpl_nonce');
		$items=get_post_meta($post->ID,'_cptt_checklist_items',true);
		if (!is_array($items)) $items=[];
		?>
		<div class="cptt-checktpl">
			<p class="cptt-help">این تمپلیت را می‌توانید داخل هر مرحله اعمال کنید.</p>
			<div id="cptt-checktpl-rows">
				<?php foreach ($items as $i=>$text): ?>
				<div class="cptt-checktpl-row"><input type="text" name="cptt_checktpl_items[<?php echo esc_attr($i); ?>]" value="<?php echo esc_attr($text); ?>" placeholder="متن آیتم..." /><button type="button" class="button cptt-remove-checktpl-row">×</button></div>
				<?php endforeach; ?>
			</div>
			<button type="button" class="button button-primary" id="cptt-add-checktpl-row">+ افزودن آیتم</button>
			<script type="text/template" id="cptt-checktpl-row-template"><div class="cptt-checktpl-row"><input type="text" name="cptt_checktpl_items[{{i}}]" value="" placeholder="متن آیتم..." /><button type="button" class="button cptt-remove-checktpl-row">×</button></div></script>
		</div>
		<?php
	}
	private function datetime_local_value($timestamp) {
		$timestamp=(int)$timestamp; if (!$timestamp) return '';
		return class_exists('CPTT_Core')?CPTT_Core::jalali_datetime($timestamp):date('Y/m/d H:i',$timestamp);
	}
	private function parse_datetime_local($value) {
		$value=trim((string)$value); if ($value==='') return 0;
		if (class_exists('CPTT_Core')&&method_exists('CPTT_Core','parse_jalali_datetime')) return (int)CPTT_Core::parse_jalali_datetime($value);
		return 0;
	}
	private function normalize_checklist($arr) {
		if (!is_array($arr)) return [];
		$out=[];
		foreach ($arr as $it) {
			$id=isset($it['id'])?sanitize_text_field($it['id']):'';
			if ($id==='') $id=function_exists('wp_generate_uuid4')?wp_generate_uuid4():('chk_'.wp_rand(1000,9999));
			$text=isset($it['text'])?sanitize_text_field($it['text']):'';
			$url=isset($it['url'])?esc_url_raw($it['url']):'';
			$done=!empty($it['done'])?1:0;
			if ($text==='') continue;
			$out[]=['id'=>$id,'text'=>$text,'url'=>$url,'done'=>$done];
		}
		return $out;
	}
	private function normalize_user_tasks($arr) {
		if (!is_array($arr)) return [];
		$out=[];
		foreach ($arr as $task) {
			if (!is_array($task)) continue;
			$id=isset($task['id'])?sanitize_text_field($task['id']):'';
			if ($id==='') $id=function_exists('wp_generate_uuid4')?wp_generate_uuid4():('ut_'.wp_rand(1000,9999));
			$title=isset($task['title'])?sanitize_text_field($task['title']):'';
			$desc=isset($task['desc'])?wp_kses_post($task['desc']):'';
			$due_at=isset($task['due_at_local'])?$this->parse_datetime_local($task['due_at_local']):0;
			$sms_remind=!empty($task['sms_remind'])?1:0;
			if ($title===''&&$desc==='') continue;
			$row=['id'=>$id,'title'=>$title,'desc'=>$desc,'due_at'=>$due_at,'sms_remind'=>$sms_remind,'done'=>0];
			if ($due_at&&class_exists('CPTT_Core')) $row['due_at_fa']=CPTT_Core::jalali_datetime($due_at);
			$out[]=$row;
		}
		return $out;
	}
	private function preserve_user_task_state($new_steps,$old_steps) {
		if (!is_array($old_steps)) $old_steps=[];
		$old_by_step=[];
		foreach ($old_steps as $os) if (is_array($os)&&!empty($os['id'])) $old_by_step[(string)$os['id']]=$os;
		foreach ($new_steps as &$step) {
			$sid=(string)($step['id']??'');
			$old_step=($sid&&isset($old_by_step[$sid]))?$old_by_step[$sid]:null;
			if (is_array($old_step)&&!empty($old_step['deadline_sms_sent'])&&!empty($old_step['due_at'])&&!empty($step['due_at'])&&(int)$old_step['due_at']===(int)$step['due_at']) $step['deadline_sms_sent']=$old_step['deadline_sms_sent'];
			$old_tasks=[];
			if (is_array($old_step)&&!empty($old_step['user_tasks'])&&is_array($old_step['user_tasks'])) { foreach ($old_step['user_tasks'] as $ot) { if (is_array($ot)&&!empty($ot['id'])) $old_tasks[(string)$ot['id']]=$ot; } }
			if (empty($step['user_tasks'])||!is_array($step['user_tasks'])) continue;
			foreach ($step['user_tasks'] as &$task) {
				$tid=(string)($task['id']??'');
				$old=($tid&&isset($old_tasks[$tid]))?$old_tasks[$tid]:null;
				if (!$old||!is_array($old)) continue;
				foreach (['done','response','response_url','response_file_url','response_file_name','response_file_type','response_files','completed_at','completed_at_fa','completed_by','last_reminder_at','last_reminder_at_fa'] as $key) { if (array_key_exists($key,$old)) $task[$key]=$old[$key]; }
			}
			unset($task);
		}
		unset($step);
		return $new_steps;
	}
	private function normalize_steps($steps) {
		if (!is_array($steps)) return [];
		$out=[]; $current_found=false;
		foreach ($steps as $s) {
			$id=isset($s['id'])?sanitize_text_field($s['id']):'';
			if ($id==='') $id=function_exists('wp_generate_uuid4')?wp_generate_uuid4():('st_'.wp_rand(1000,9999));
			$title=isset($s['title'])?sanitize_text_field($s['title']):'';
			$desc=isset($s['desc'])?wp_kses_post($s['desc']):'';
			$status=isset($s['status'])?sanitize_key($s['status']):'todo';
			if (!in_array($status,['todo','current','done'],true)) $status='todo';
			$due_at=isset($s['due_at_local'])?$this->parse_datetime_local($s['due_at_local']):0;
			$checklist=isset($s['checklist'])?$this->normalize_checklist($s['checklist']):[];
			$user_tasks=isset($s['user_tasks'])?$this->normalize_user_tasks($s['user_tasks']):[];
			
            $cost = isset($s['cost']) ? (float)str_replace(",", "", $s['cost']) : 0;
            $paid = isset($s['paid']) ? (float)str_replace(",", "", $s['paid']) : 0;
            // تسویه مالی مرحله دیگر وضعیت اجرایی را خودکار «انجام‌شده» نمی‌کند.

			if ($title===''&&$desc===''&&empty($checklist)&&empty($user_tasks)) continue;
			if ($status==='current') { $current_found=true; }
			$assigned_expert_ids = isset($s['assigned_expert_ids']) && is_array($s['assigned_expert_ids']) ? array_values(array_filter(array_unique(array_map('absint',$s['assigned_expert_ids'])))) : [];
			if (empty($assigned_expert_ids) && isset($s['assigned_expert_id'])) $assigned_expert_ids = array_filter([absint($s['assigned_expert_id'])]);
			$assigned_expert_id = !empty($assigned_expert_ids) ? (int)$assigned_expert_ids[0] : 0;
			// v5.4.3: حفظ فیلدهای مالی/تسویه (که از UI حذف شده ولی از hidden ارسال می‌شوند)
			$expert_share = isset($s['expert_share']) ? (float)str_replace([',', ' '], '', (string)$s['expert_share']) : 0;
			$expert_paid  = isset($s['expert_paid']) ? (float)str_replace([',', ' '], '', (string)$s['expert_paid']) : 0;
			$row=['id'=>$id,'title'=>$title,'desc'=>$desc,'status'=>$status,'checklist'=>$checklist,'user_tasks'=>$user_tasks,'cost'=>$cost,'paid'=>$paid,'expert_share'=>$expert_share,'expert_paid'=>$expert_paid,'assigned_expert_id'=>$assigned_expert_id,'assigned_expert_ids'=>$assigned_expert_ids];
			if ($due_at) { $row['due_at']=$due_at; $row['due_at_fa']=class_exists('CPTT_Core')?CPTT_Core::jalali_datetime($due_at):date('Y/m/d H:i',$due_at); }
			$out[]=$row;
		}
/* Fallback for single current step disabled */
		return $out;
	}
	private function apply_step_status_from_checklist($steps) {
		foreach ($steps as &$s) {
			$cl=isset($s['checklist'])&&is_array($s['checklist'])?$s['checklist']:[];
			$total=0; $done=0;
			foreach ($cl as $it) {
				$text=(string)($it['text']??''); if ($text==='') continue;
				$total++; if (!empty($it['done'])) $done++;
			}
			if ($total>0) { if ($done>=$total) $s['status']='done'; else if (($s['status']??'')==='done') $s['status']='current'; }
		}
		unset($s);
		return $steps;
	}
	private function apply_status_timestamps($new_steps,$old_steps) {
		$now=(int)current_time('timestamp',true); $uid=(int)get_current_user_id(); $any_status_changed=false;
		if (!is_array($old_steps)) $old_steps=[];
		$old_by_id=[];
		foreach ($old_steps as $os) if (is_array($os)&&!empty($os['id'])) $old_by_id[(string)$os['id']]=$os;
		foreach ($new_steps as &$s) {
			$oid=(string)$s['id']; $old=$old_by_id[$oid]??null; $old_status=(is_array($old)&&isset($old['status']))?(string)$old['status']:null;
			if ($old_status!==null&&$old_status===$s['status']) { if (isset($old['updated_at'])) $s['updated_at']=(int)$old['updated_at']; if (isset($old['updated_at_fa'])) $s['updated_at_fa']=(string)$old['updated_at_fa']; if (isset($old['updated_by'])) $s['updated_by']=(int)$old['updated_by']; continue; }
			$s['updated_at']=$now; $s['updated_at_fa']=CPTT_Core::jalali_datetime($now); $s['updated_by']=$uid;
			if ($old_status!==null&&$old_status!==$s['status']) $any_status_changed=true;
		}
		unset($s);
		return [$new_steps,$any_status_changed];
	}
	private function apply_checklist_done_timestamps($new_steps,$old_steps) {
		$now=(int)current_time('timestamp',true); $uid=(int)get_current_user_id();
		if (!is_array($old_steps)) $old_steps=[];
		$old_by_step=[];
		foreach ($old_steps as $os) if (is_array($os)&&!empty($os['id'])) $old_by_step[(string)$os['id']]=$os;
		foreach ($new_steps as &$st) {
			$sid=(string)($st['id']??''); $old=$old_by_step[$sid]??null;
			$old_items=[];
			if (is_array($old)&&!empty($old['checklist'])&&is_array($old['checklist'])) { foreach ($old['checklist'] as $oi) if (is_array($oi)&&!empty($oi['id'])) $old_items[(string)$oi['id']]=$oi; }
			if (empty($st['checklist'])||!is_array($st['checklist'])) continue;
			foreach ($st['checklist'] as &$it) {
				$cid=(string)($it['id']??''); $ndone=!empty($it['done'])?1:0;
				$oi=($cid&&isset($old_items[$cid]))?$old_items[$cid]:null; $odone=(!empty($oi)&&!empty($oi['done']))?1:0;
				if ($ndone===1) {
					if ($odone===1&&!empty($oi['done_at'])) { $it['done_at']=(int)$oi['done_at']; $it['done_at_fa']=!empty($oi['done_at_fa'])?(string)$oi['done_at_fa']:CPTT_Core::jalali_datetime((int)$oi['done_at']); if (!empty($oi['done_by'])) $it['done_by']=(int)$oi['done_by']; }
					else { $it['done_at']=$now; $it['done_at_fa']=CPTT_Core::jalali_datetime($now); $it['done_by']=$uid; }
				} else { unset($it['done_at'],$it['done_at_fa'],$it['done_by']); }
			}
			unset($it);
		}
		unset($st);
		return $new_steps;
	}
	private function checklist_changed($new_steps,$old_steps) {
		if (!is_array($old_steps)) $old_steps=[];
		$old_by_id=[]; foreach ($old_steps as $os) if (is_array($os)&&!empty($os['id'])) $old_by_id[(string)$os['id']]=$os;
		foreach ($new_steps as $ns) {
			$id=(string)($ns['id']??''); $old=$old_by_id[$id]??null;
			$ncl=isset($ns['checklist'])&&is_array($ns['checklist'])?$ns['checklist']:[];
			$ocl=(is_array($old)&&isset($old['checklist'])&&is_array($old['checklist']))?$old['checklist']:[];
			$omap=[];
			foreach ($ocl as $oi) { if (!is_array($oi)||empty($oi['id'])) continue; $omap[(string)$oi['id']]=['text'=>(string)($oi['text']??''),'url'=>(string)($oi['url']??''),'done'=>!empty($oi['done'])?1:0]; }
			foreach ($ncl as $ni) {
				if (!is_array($ni)||empty($ni['id'])) return true;
				$key=(string)$ni['id']; $nt=(string)($ni['text']??''); $nu=(string)($ni['url']??''); $nd=!empty($ni['done'])?1:0;
				if (!isset($omap[$key])) return true; if ($omap[$key]['text']!==$nt) return true; if ($omap[$key]['url']!==$nu) return true; if ((int)$omap[$key]['done']!==(int)$nd) return true; unset($omap[$key]);
			}
			if (!empty($omap)) return true;
		}
		return false;
	}
	public function save_project_meta($post_id,$post) {
		if (defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE) return;
		if (!isset($_POST['cptt_nonce'])||!wp_verify_nonce($_POST['cptt_nonce'],'cptt_save_project')) return;
		if (!current_user_can('edit_post',$post_id)) return;
		$client_id=isset($_POST['cptt_client_user_id'])?absint($_POST['cptt_client_user_id']):0;
		update_post_meta($post_id,'_cptt_client_user_id',$client_id);
		$product_id=isset($_POST['cptt_product_id'])?absint($_POST['cptt_product_id']):0;
		update_post_meta($post_id,'_cptt_product_id',$product_id);
		if (class_exists('CPTT_Core')) CPTT_Core::get_project_code($post_id);
		update_post_meta($post_id,'_cptt_project_label_id', isset($_POST['cptt_project_label_id']) ? sanitize_key($_POST['cptt_project_label_id']) : '');
		$fin_calc = $this->project_financial_data($post_id);
		$is_settled_calc = ($fin_calc['remain'] <= 0 && $fin_calc['cost'] > 0) ? 1 : 0;
		update_post_meta($post_id, '_cptt_is_settled', $is_settled_calc);
		if (isset($_POST['cptt_experts_present'])) {
			$expert_ids=isset($_POST['cptt_expert_user_ids'])&&is_array($_POST['cptt_expert_user_ids'])?array_map('absint',$_POST['cptt_expert_user_ids']):[];
			$expert_ids=array_values(array_filter(array_unique($expert_ids)));
			update_post_meta($post_id,'_cptt_expert_user_ids',$expert_ids);
			update_post_meta($post_id,'_cptt_expert_user_id',!empty($expert_ids)?(int)$expert_ids[0]:0);
			update_post_meta($post_id,'_cptt_experts_csv',','.implode(',',$expert_ids).',');
		}
		$old_deadline=(int)get_post_meta($post_id,'_cptt_deadline_at',true);
		$deadline_at=isset($_POST['cptt_deadline_local'])?$this->parse_datetime_local($_POST['cptt_deadline_local']):0;
		if ($deadline_at) { update_post_meta($post_id,'_cptt_deadline_at',$deadline_at); update_post_meta($post_id,'_cptt_deadline_at_fa',CPTT_Core::jalali_datetime($deadline_at)); }
		else { delete_post_meta($post_id,'_cptt_deadline_at'); delete_post_meta($post_id,'_cptt_deadline_at_fa'); }
		if ($old_deadline!==$deadline_at) delete_post_meta($post_id,'_cptt_deadline_sms_sent');
		if (isset($_POST['cptt_wc_cats'])&&is_array($_POST['cptt_wc_cats'])) {
			$cat_ids=array_map('intval',$_POST['cptt_wc_cats']);
			$cat_ids=array_values(array_filter(array_unique($cat_ids)));
			update_post_meta($post_id,'_cptt_wc_cat_ids',$cat_ids);
			update_post_meta($post_id,'_cptt_wc_cats_csv',','.implode(',',$cat_ids).',');
		} else { delete_post_meta($post_id,'_cptt_wc_cat_ids'); delete_post_meta($post_id,'_cptt_wc_cats_csv'); }
		$new_note=isset($_POST['cptt_new_note'])?trim(sanitize_textarea_field($_POST['cptt_new_note'])):'';
		if ($new_note!=='') {
			$notes=get_post_meta($post_id,'_cptt_project_notes',true);
			if (!is_array($notes)) $notes=[];
			$notes[]=['user_id'=>get_current_user_id(),'time'=>current_time('timestamp',true),'content'=>$new_note];
			update_post_meta($post_id,'_cptt_project_notes',$notes);
		}
		$old_steps=get_post_meta($post_id,'_cptt_steps',true);
		$steps=isset($_POST['cptt_steps'])&&is_array($_POST['cptt_steps'])?$_POST['cptt_steps']:[];
		$steps=$this->normalize_steps($steps);
		// v5.4.3: حفظ متاهای تسویه‌ی مرحله‌ای از مقادیر قبلی
		if (is_array($old_steps)) {
			$old_by_id_settle = [];
			foreach ($old_steps as $_os) { if (is_array($_os) && !empty($_os['id'])) $old_by_id_settle[(string)$_os['id']] = $_os; }
			foreach ($steps as &$_ns) {
				$_sid = (string)($_ns['id'] ?? '');
				if ($_sid !== '' && isset($old_by_id_settle[$_sid])) {
					foreach (['admin_received','step_settled','settle_at','settle_at_fa','settled_by'] as $_pk) {
						if (isset($old_by_id_settle[$_sid][$_pk])) $_ns[$_pk] = $old_by_id_settle[$_sid][$_pk];
					}
				}
			}
			unset($_ns);
		}
		$steps=$this->preserve_user_task_state($steps,$old_steps);
		$steps=$this->apply_checklist_done_timestamps($steps,$old_steps);
		$steps=$this->apply_step_status_from_checklist($steps);
		[$steps,$any_status_changed]=$this->apply_status_timestamps($steps,$old_steps);
		$any_check_changed=$this->checklist_changed($steps,$old_steps);
		update_post_meta($post_id,'_cptt_steps',$steps);
		// v5.4.4: trigger on new user_tasks → notify customer in Bale
		if (is_array($old_steps)) {
			$_old_ut_ids = [];
			foreach ($old_steps as $_os) if (!empty($_os['user_tasks']) && is_array($_os['user_tasks'])) foreach ($_os['user_tasks'] as $_ot) if (!empty($_ot['id'])) $_old_ut_ids[$_ot['id']] = true;
			foreach ($steps as $_ns) {
				$_sid = $_ns['id'] ?? ''; if (!$_sid) continue;
				if (empty($_ns['user_tasks']) || !is_array($_ns['user_tasks'])) continue;
				foreach ($_ns['user_tasks'] as $_nt) {
					$_tid = $_nt['id'] ?? '';
					if ($_tid && empty($_old_ut_ids[$_tid]) && empty($_nt['done'])) {
						do_action('cptt_user_task_assigned', $post_id, $_sid, $_tid);
					}
				}
			}
		}
		if ($any_status_changed||$any_check_changed) {
			$now=(int)current_time('timestamp',true);
			update_post_meta($post_id,'_cptt_last_update',$now);
			update_post_meta($post_id,'_cptt_last_update_fa',CPTT_Core::jalali_datetime($now));
		}
		if (class_exists('CPTT_SMS')&&method_exists('CPTT_SMS','maybe_notify_project_completed')) CPTT_SMS::maybe_notify_project_completed($post_id);
	}
	public function save_template_meta($post_id,$post) {
		if (defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE) return;
		if (!isset($_POST['cptt_template_nonce'])||!wp_verify_nonce($_POST['cptt_template_nonce'],'cptt_save_template')) return;
		if (!current_user_can('edit_post',$post_id)) return;
		$steps=isset($_POST['cptt_steps'])&&is_array($_POST['cptt_steps'])?$_POST['cptt_steps']:[];
		$steps=$this->normalize_steps($steps);
		foreach ($steps as &$s) {
			unset($s['updated_at'],$s['updated_at_fa'],$s['updated_by']);
			$s['status']='todo';
			if (isset($s['checklist'])&&is_array($s['checklist'])) { foreach ($s['checklist'] as &$ci) { $ci['done']=0; unset($ci['done_at'],$ci['done_at_fa'],$ci['done_by']); } unset($ci); }
			if (isset($s['user_tasks'])&&is_array($s['user_tasks'])) { foreach ($s['user_tasks'] as &$ut) { $ut['done']=0; unset($ut['response'],$ut['response_url'],$ut['response_file_url'],$ut['response_file_name'],$ut['response_file_type'],$ut['response_files'],$ut['completed_at'],$ut['completed_at_fa'],$ut['completed_by'],$ut['last_reminder_at'],$ut['last_reminder_at_fa']); } unset($ut); }
		}
		unset($s);
		update_post_meta($post_id,'_cptt_template_steps',$steps);
	}
	public function save_checklist_tpl_meta($post_id,$post) {
		if (defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE) return;
		if (!isset($_POST['cptt_checktpl_nonce'])||!wp_verify_nonce($_POST['cptt_checktpl_nonce'],'cptt_save_checktpl')) return;
		if (!current_user_can('edit_post',$post_id)) return;
		$items=isset($_POST['cptt_checktpl_items'])&&is_array($_POST['cptt_checktpl_items'])?$_POST['cptt_checktpl_items']:[];
		$out=[];
		foreach ($items as $t) { $t=sanitize_text_field($t); if ($t!=='') $out[]=$t; }
		update_post_meta($post_id,'_cptt_checklist_items',$out);
	}
	public function ajax_get_template_steps() {
		if (!current_user_can('edit_cptt_projects')) wp_send_json_error('no_access',403);
		check_ajax_referer('cptt_admin_nonce','nonce');
		$id=isset($_GET['template_id'])?absint($_GET['template_id']):0;
		if (!$id||get_post_type($id)!=='cptt_template') wp_send_json_error('invalid',400);
		$steps=get_post_meta($id,'_cptt_template_steps',true);
		if (!is_array($steps)) $steps=[];
		foreach ($steps as &$s) {
			unset($s['updated_at'],$s['updated_at_fa'],$s['updated_by']);
			$s['status']='todo';
			$s['due_at_local']=!empty($s['due_at'])?$this->datetime_local_value((int)$s['due_at']):'';
			if (isset($s['checklist'])&&is_array($s['checklist'])) { foreach ($s['checklist'] as &$ci) { $ci['done']=0; unset($ci['done_at'],$ci['done_at_fa'],$ci['done_by']); } unset($ci); }
			if (isset($s['user_tasks'])&&is_array($s['user_tasks'])) { foreach ($s['user_tasks'] as &$ut) { $ut['done']=0; $ut['due_at_local']=!empty($ut['due_at'])?$this->datetime_local_value((int)$ut['due_at']):''; unset($ut['response'],$ut['response_url'],$ut['response_file_url'],$ut['response_file_name'],$ut['response_file_type'],$ut['response_files'],$ut['completed_at'],$ut['completed_at_fa'],$ut['completed_by'],$ut['last_reminder_at'],$ut['last_reminder_at_fa']); } unset($ut); }
		}
		unset($s);
		wp_send_json_success($steps);
	}
	public function ajax_get_checklist_tpl() {
		if (!current_user_can('edit_cptt_projects')) wp_send_json_error('no_access',403);
		check_ajax_referer('cptt_admin_nonce','nonce');
		$id=isset($_GET['checktpl_id'])?absint($_GET['checktpl_id']):0;
		if (!$id||get_post_type($id)!=='cptt_checklist_tpl') wp_send_json_error('invalid',400);
		$items=get_post_meta($id,'_cptt_checklist_items',true);
		if (!is_array($items)) $items=[];
		$out=[];
		foreach ($items as $t) { $t=sanitize_text_field($t); if ($t!=='') $out[]=$t; }
		wp_send_json_success($out);
	}
	public function columns($cols) {
		$new=[];
		$new['cb']=$cols['cb'];
		$new['title']='عنوان پروژه';
		$new['cptt_client']='مشتری';
		$new['cptt_expert']='کارشناسان';
		$new['cptt_cats']='دسته‌بندی';
		$new['cptt_product']='محصول';
		$new['cptt_settled']='تسویه';
		$new['cptt_progress']='پیشرفت';
		$new['cptt_last_update']='آخرین بروزرسانی';
		$new['date']=$cols['date'];
		return $new;
	}
	public function column_content($col,$post_id) {
		if ($col==='cptt_client') { $uid=(int)get_post_meta($post_id,'_cptt_client_user_id',true); $u=$uid?get_user_by('id',$uid):null; echo $u?esc_html($u->display_name):'—'; }
		if ($col==='cptt_expert') {
			$ids=get_post_meta($post_id,'_cptt_expert_user_ids',true);
			if (!is_array($ids)) $ids=[];
			if (empty($ids)) { $legacy=(int)get_post_meta($post_id,'_cptt_expert_user_id',true); if ($legacy) $ids=[$legacy]; }
			$names=[]; foreach ($ids as $id) { $u=get_user_by('id',(int)$id); if ($u) $names[]=$u->display_name; }
			echo $names?esc_html(implode('، ',$names)):'—';
		}
		if ($col==='cptt_cats') {
			$cat_ids=get_post_meta($post_id,'_cptt_wc_cat_ids',true);
			if (!is_array($cat_ids)||empty($cat_ids)) { echo '—'; return; }
			$names=[]; foreach ($cat_ids as $cid) { $term=get_term((int)$cid,'product_cat'); if ($term&&!is_wp_error($term)) $names[]=$term->name; }
			echo $names?esc_html(implode('، ',$names)):'—';
		}
		if ($col==='cptt_product') { $pid=(int)get_post_meta($post_id,'_cptt_product_id',true); if (!$pid) $pid=(int)get_post_meta($post_id,'_cptt_wc_product_id',true); echo $pid?esc_html($this->product_title($pid)):'—'; }
		if ($col==='cptt_settled') { echo get_post_meta($post_id,'_cptt_is_settled',true)?'تسویه شده':'—'; }
		if ($col==='cptt_progress') {
			$steps=get_post_meta($post_id,'_cptt_steps',true);
			if (!is_array($steps)||empty($steps)) { echo '—'; return; }
			$total=count($steps); $done=0; foreach ($steps as $s) if (($s['status']??'')==='done') $done++;
			$percent=$total?round(($done/$total)*100):0;
			echo esc_html($percent.'% ('.$done.'/'.$total.')');
		}
		if ($col==='cptt_last_update') { $fa=(string)get_post_meta($post_id,'_cptt_last_update_fa',true); echo $fa?esc_html($fa):'—'; }
	}


	public function ajax_manual_expert_payment() {
		if (!current_user_can('edit_cptt_projects')) wp_send_json_error('دسترسی ندارید.', 403);
		check_ajax_referer('cptt_admin_nonce', 'nonce');
		$expert_id = absint($_POST['expert_id'] ?? 0);
		$amount = isset($_POST['amount']) ? (float)str_replace([',',' '], '', (string)$_POST['amount']) : 0;
		$note = sanitize_textarea_field((string)($_POST['note'] ?? ''));
		if (!$expert_id || $amount <= 0) wp_send_json_error('اطلاعات نامعتبر.', 400);
		if (class_exists('CPTT_Core')) {
			CPTT_Core::ledger_add(['user_id'=>$expert_id,'type'=>'expert_manual_payout','amount'=>-$amount,'note'=>$note]);
			CPTT_Core::activity_log('user', $expert_id, 'manual_expert_payment', 'ثبت پرداخت دستی به کارشناس: ' . number_format($amount));
		}
		if (class_exists('CPTT_Bale')) CPTT_Bale::notify_via_bale($expert_id, '💸 پرداخت دستی به مبلغ ' . number_format($amount) . ' تومان ثبت شد.' . ($note ? "\n".$note : ''), 'expert_payout', 0);
		wp_send_json_success();
	}

	public function ajax_step_settlement_adjust() {
		if (!current_user_can('edit_cptt_projects')) wp_send_json_error('دسترسی ندارید.', 403);
		check_ajax_referer('cptt_admin_nonce', 'nonce');
		$project_id = absint($_POST['project_id'] ?? 0);
		$step_id = sanitize_text_field((string)($_POST['step_id'] ?? ''));
		$mode = sanitize_key((string)($_POST['mode'] ?? 'edit'));
		$amount = isset($_POST['amount']) ? (float)str_replace([',',' '], '', (string)$_POST['amount']) : 0;
		if (!$project_id || $step_id === '') wp_send_json_error('اطلاعات نامعتبر.', 400);
		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps)) wp_send_json_error('مراحل یافت نشد.', 404);
		$found = false;
		foreach ($steps as $i => $st) {
			$sid = isset($st['id']) ? (string)$st['id'] : (string)$i;
			if ($sid !== $step_id) continue;
			$found = true;
			$paid = (float)($st['paid'] ?? 0);
			if ($mode === 'delete') {
				$steps[$i]['expert_paid'] = 0;
				$steps[$i]['admin_received'] = 0;
				$steps[$i]['step_settled'] = 0;
				unset($steps[$i]['settle_at'], $steps[$i]['settle_at_fa'], $steps[$i]['settled_by']);
			} else {
				if ($amount < 0 || $amount > $paid) wp_send_json_error('مبلغ نامعتبر است.', 400);
				$steps[$i]['expert_paid'] = $amount;
				$steps[$i]['expert_share'] = max((float)($steps[$i]['expert_share'] ?? 0), $amount);
				$steps[$i]['admin_received'] = max(0, $paid - $amount);
				$steps[$i]['step_settled'] = 1;
				if (empty($steps[$i]['settle_at'])) {
					$now = (int)current_time('timestamp', true);
					$steps[$i]['settle_at'] = $now;
					$steps[$i]['settle_at_fa'] = class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now);
				}
				$steps[$i]['settled_by'] = (int)get_current_user_id();
			}
			break;
		}
		if (!$found) wp_send_json_error('مرحله یافت نشد.', 404);
		update_post_meta($project_id, '_cptt_steps', $steps);
		$all_settled = true; $has_any_paid = false;
		foreach ($steps as $st) { if ((float)($st['paid'] ?? 0) > 0) { $has_any_paid = true; if (empty($st['step_settled'])) { $all_settled = false; break; } } }
		update_post_meta($project_id, '_cptt_is_settled', ($has_any_paid && $all_settled) ? 1 : 0);
		wp_send_json_success();
	}

	public function ajax_expert_payout() {
		if (!current_user_can('edit_cptt_projects')) wp_send_json_error('no_access', 403);
		check_ajax_referer('cptt_admin_nonce', 'nonce');
		
		$expert_id = isset($_POST['expert_id']) ? absint($_POST['expert_id']) : 0;
		$amount = isset($_POST['amount']) ? (float)str_replace(",", "", $_POST['amount']) : 0;
		
		if (!$expert_id || $amount <= 0) wp_send_json_error('اطلاعات نامعتبر است.', 400);
		
		$projects = get_posts([
			'post_type' => 'cptt_project',
			'post_status' => 'any',
			'numberposts' => -1,
		]);
		
		$remaining_pay = $amount;
		$updated_projects = [];
		
		foreach ($projects as $p) {
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (!is_array($steps)) continue;
			
			$project_changed = false;
			foreach ($steps as &$s) {
				$ae_id = isset($s['assigned_expert_id']) ? (int)$s['assigned_expert_id'] : 0;
				if ($ae_id === $expert_id) {
					$share = (float)($s['expert_share'] ?? 0);
					$paid = (float)($s['expert_paid'] ?? 0);
					if ($share > $paid) {
						$due = $share - $paid;
						$pay = min($remaining_pay, $due);
						$s['expert_paid'] = $paid + $pay;
						$remaining_pay -= $pay;
						$project_changed = true;
						
						if ($remaining_pay <= 0) break;
					}
				}
			}
			unset($s);
			
			if ($project_changed) {
				update_post_meta($p->ID, '_cptt_steps', $steps);
				$updated_projects[] = $p->ID;
				if ($remaining_pay <= 0) break;
			}
		}
		
		if (empty($updated_projects) && $remaining_pay == $amount) {
			wp_send_json_error('هیچ سهم کارشناس تسویه نشده‌ای برای این همکار یافت نشد.', 400);
		}
		
		if (class_exists('CPTT_Expert')) {
			$paid_registered = $amount - $remaining_pay;
			$msg = sprintf('مبلغ %s تومان بابت تسویه حساب سهم شما به حسابتان واریز شد.', number_format($paid_registered));
			CPTT_Expert::instance()->insert_notification($expert_id, 'expert_payout', $msg, 0, CPTT_Expert::dashboard_url());
		}
		
		wp_send_json_success('تسویه حساب کارشناس با موفقیت ثبت شد.');
	}

	/**
	 * v5.4.3 - تسویه‌ی مرحله‌محور
	 * mode = 'final'   : amount به expert_paid اضافه می‌شود، (paid - new_expert_paid) به admin_received می‌رود، step_settled=1
	 * mode = 'partial' : amount به expert_paid اضافه می‌شود، admin_received بدون تغییر، step_settled=0
	 */
	public function ajax_step_settle() {
		if (!current_user_can('edit_cptt_projects')) wp_send_json_error('دسترسی ندارید.', 403);
		check_ajax_referer('cptt_admin_nonce', 'nonce');

		$project_id = isset($_POST['project_id']) ? absint($_POST['project_id']) : 0;
		$step_id    = isset($_POST['step_id']) ? sanitize_text_field((string)$_POST['step_id']) : '';
		$expert_id  = isset($_POST['expert_id']) ? absint($_POST['expert_id']) : 0;
		$amount     = isset($_POST['amount']) ? (float)str_replace([',', ' '], '', (string)$_POST['amount']) : 0;
		$mode       = isset($_POST['mode']) ? sanitize_key($_POST['mode']) : 'final';

		if (!$project_id || $step_id === '') wp_send_json_error('اطلاعات نامعتبر.', 400);
		if ($amount < 0) wp_send_json_error('مبلغ نامعتبر.', 400);
		if (!in_array($mode, ['final', 'partial'], true)) $mode = 'final';

		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps)) wp_send_json_error('مراحل پروژه یافت نشد.', 400);

		$found = false;
		foreach ($steps as $i => $st) {
			$sid = isset($st['id']) ? (string)$st['id'] : (string)$i;
			if ($sid !== (string)$step_id) continue;
			$found = true;

			$paid = (float)($st['paid'] ?? 0);
			$already_expert = (float)($st['expert_paid'] ?? 0);
			$already_admin  = (float)($st['admin_received'] ?? 0);
			$max_payable = max(0, $paid - $already_expert);
			if ($amount > $max_payable + 0.001) wp_send_json_error('مبلغ از مانده‌ی این مرحله بیشتر است.', 400);

			$new_expert = $already_expert + $amount;
			$steps[$i]['expert_paid'] = $new_expert;
			// expert_share را برای backward-compatibility هم‌سان نگه می‌داریم (تجمعی برابر expert_paid)
			$steps[$i]['expert_share'] = max((float)($steps[$i]['expert_share'] ?? 0), $new_expert);
			if ($expert_id) $steps[$i]['assigned_expert_id'] = $expert_id;

			if ($mode === 'final') {
				$steps[$i]['admin_received'] = $already_admin + max(0, $paid - $new_expert);
				$steps[$i]['step_settled'] = 1;
				$now = (int) current_time('timestamp', true);
				$steps[$i]['settle_at'] = $now;
				$steps[$i]['settle_at_fa'] = class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now);
				$steps[$i]['settled_by'] = (int) get_current_user_id();
			} else {
				$steps[$i]['step_settled'] = 0;
			}
			break;
		}
		if (!$found) wp_send_json_error('مرحله یافت نشد.', 404);

		update_post_meta($project_id, '_cptt_steps', $steps);
		if (class_exists('CPTT_Core')) { CPTT_Core::ledger_add(['project_id'=>$project_id,'step_id'=>$step_id,'user_id'=>$expert_id,'type'=>($mode==='final'?'expert_step_settlement':'expert_step_partial'),'amount'=>-$amount,'note'=>'تسویه مرحله']); CPTT_Core::activity_log('project',$project_id,'step_settlement','ثبت تسویه مرحله ' . $step_id . ' مبلغ ' . number_format($amount)); }

		// به‌روزرسانی وضعیت تسویه‌ی کلی پروژه: اگر همه‌ی مراحلِ paid > 0 تسویه شده باشند
		$all_settled = true; $has_any_paid = false;
		foreach ($steps as $st) {
			if ((float)($st['paid'] ?? 0) > 0) {
				$has_any_paid = true;
				if (empty($st['step_settled'])) { $all_settled = false; break; }
			}
		}
		update_post_meta($project_id, '_cptt_is_settled', ($has_any_paid && $all_settled) ? 1 : 0);

		// اعلان به کارشناس
		if ($expert_id && class_exists('CPTT_Expert')) {
			$title = get_the_title($project_id);
			$step_title = isset($steps[$i]['title']) ? (string)$steps[$i]['title'] : '';
			if ($mode === 'final') {
				$msg = sprintf('💰 تسویه نهایی مرحله «%s» در پروژه «%s» انجام شد. مبلغ %s تومان به حساب شما واریز شد.', $step_title, $title, number_format($amount));
			} else {
				$msg = sprintf('💸 پرداخت %s تومان بابت مرحله «%s» در پروژه «%s» به حساب شما واریز شد (تسویه نهایی هنوز انجام نشده).', number_format($amount), $step_title, $title);
			}
			CPTT_Expert::instance()->insert_notification($expert_id, 'expert_payout', $msg, $project_id, CPTT_Expert::dashboard_url() . '#project-' . $project_id);
		}

		wp_send_json_success(['msg' => 'ذخیره شد']);
	}


	/* v5.4.7: save metabox سفارش */
	public function save_order_meta($post_id, $post) {
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
		if (!isset($_POST['cptt_order_nonce']) || !wp_verify_nonce($_POST['cptt_order_nonce'], 'cptt_order_save')) return;
		if (!current_user_can('edit_post', $post_id)) return;

		$old_assigned = (int) get_post_meta($post_id, '_cptt_order_assigned_expert', true);

		$new_assigned = isset($_POST['cptt_order_assigned_expert']) ? (int)$_POST['cptt_order_assigned_expert'] : 0;
		$new_status   = isset($_POST['cptt_order_status']) ? sanitize_text_field($_POST['cptt_order_status']) : 'pending';
		if (!in_array($new_status, ['pending','assigned','project','cancelled'], true)) $new_status = 'pending';

		if ($new_assigned && $new_status === 'pending') $new_status = 'assigned';

		update_post_meta($post_id, '_cptt_order_assigned_expert', $new_assigned);
		update_post_meta($post_id, '_cptt_order_status', $new_status);

		if ($new_assigned && $new_assigned !== $old_assigned && class_exists('CPTT_Expert')) {
			CPTT_Expert::instance()->insert_notification(
				$new_assigned, 'order_assigned',
				'سفارش #' . $post_id . ' به شما تخصیص داده شد.',
				$post_id, admin_url('post.php?post=' . $post_id . '&action=edit')
			);
			if (class_exists('CPTT_Bale')) {
				$exp_chat = get_user_meta($new_assigned, '_cptt_bale_chat_id', true);
				$client_id = (int) get_post_meta($post_id, '_cptt_order_client_id', true);
				$client = $client_id ? get_user_by('id', $client_id) : null;
				if ($exp_chat && $client) {
					CPTT_Bale::send_message($exp_chat,
						"🆕 *سفارش جدید به شما تخصیص داده شد*\n\nسفارش #{$post_id} از مشتری " . $client->display_name . " توسط ادمین به شما واگذار شد.",
						['inline_keyboard' => [
							[['text' => '➕ ایجاد پروژه از این سفارش', 'callback_data' => 'order_create_proj_' . $post_id]],
							[['text' => '👁 جزئیات', 'callback_data' => 'order_view_' . $post_id]],
						]]
					);
				}
			}
		}
	}
}