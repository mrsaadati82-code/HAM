<?php
if ( ! defined('ABSPATH') ) exit;

class CPTT_Core {
	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action('init', [$this, 'register_roles']);
		add_action('init', [$this, 'register_cpt']);
		add_action('init', [$this, 'register_meta']);

		add_action('pre_get_posts', [$this, 'filter_admin_list_for_experts']);
		add_action('admin_init', [$this, 'block_edit_for_other_experts']);
		add_action('init', [$this, 'maybe_update_db']);
	}

	
	public function maybe_update_db() {
		$db_version = get_option('cptt_db_version', '1.0');
		if (version_compare($db_version, '1.8.0', '<')) {
			$this->create_custom_tables();
			update_option('cptt_db_version', '1.8.0');
		}
	}

	public function create_custom_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

		$sql = "CREATE TABLE {$wpdb->prefix}cptt_expert_chats (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sender_id bigint(20) unsigned NOT NULL,
			receiver_id bigint(20) unsigned NOT NULL,
			message text NOT NULL,
			file_url varchar(255) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id)
		) $charset_collate;";
		dbDelta($sql);

		$sql2 = "CREATE TABLE {$wpdb->prefix}cptt_notifications (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			type varchar(50) NOT NULL,
			reference_id bigint(20) unsigned DEFAULT NULL,
			message text NOT NULL,
			link varchar(255) DEFAULT NULL,
			is_read tinyint(1) DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id)
		) $charset_collate;";
		dbDelta($sql2);

		$sql3 = "CREATE TABLE {$wpdb->prefix}cptt_ledger (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned DEFAULT 0,
			step_id varchar(80) DEFAULT '',
			user_id bigint(20) unsigned DEFAULT 0,
			type varchar(50) NOT NULL,
			amount decimal(18,2) NOT NULL DEFAULT 0,
			note text NULL,
			created_by bigint(20) unsigned DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id), KEY project_id (project_id), KEY user_id (user_id), KEY type (type)
		) $charset_collate;";
		dbDelta($sql3);

		$sql4 = "CREATE TABLE {$wpdb->prefix}cptt_invoices (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned NOT NULL,
			invoice_no varchar(40) NOT NULL,
			type varchar(20) NOT NULL DEFAULT 'proforma',
			status varchar(20) NOT NULL DEFAULT 'issued',
			total decimal(18,2) DEFAULT 0,
			created_by bigint(20) unsigned DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id), UNIQUE KEY invoice_no (invoice_no), KEY project_id (project_id)
		) $charset_collate;";
		dbDelta($sql4);

		$sql5 = "CREATE TABLE {$wpdb->prefix}cptt_activity (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_type varchar(40) NOT NULL,
			object_id bigint(20) unsigned DEFAULT 0,
			action varchar(80) NOT NULL,
			message text NULL,
			user_id bigint(20) unsigned DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id), KEY object_id (object_id), KEY action (action), KEY user_id (user_id)
		) $charset_collate;";
		dbDelta($sql5);
	}



	public static function ledger_add($args) {
		global $wpdb;
		$defaults = ['project_id'=>0,'step_id'=>'','user_id'=>0,'type'=>'manual','amount'=>0,'note'=>'','created_by'=>get_current_user_id()];
		$a = wp_parse_args($args, $defaults);
		$wpdb->insert($wpdb->prefix.'cptt_ledger', [
			'project_id'=>(int)$a['project_id'], 'step_id'=>sanitize_text_field((string)$a['step_id']), 'user_id'=>(int)$a['user_id'],
			'type'=>sanitize_key((string)$a['type']), 'amount'=>(float)$a['amount'], 'note'=>sanitize_textarea_field((string)$a['note']),
			'created_by'=>(int)$a['created_by'], 'created_at'=>current_time('mysql')
		], ['%d','%s','%d','%s','%f','%s','%d','%s']);
		return (int)$wpdb->insert_id;
	}

	public static function activity_log($object_type, $object_id, $action, $message = '') {
		global $wpdb;
		$wpdb->insert($wpdb->prefix.'cptt_activity', [
			'object_type'=>sanitize_key($object_type), 'object_id'=>(int)$object_id, 'action'=>sanitize_key($action),
			'message'=>sanitize_textarea_field($message), 'user_id'=>(int)get_current_user_id(), 'created_at'=>current_time('mysql')
		], ['%s','%d','%s','%s','%d','%s']);
		return (int)$wpdb->insert_id;
	}

	public static function ensure_invoice($project_id, $type = 'proforma', $total = 0) {
		global $wpdb;
		$project_id = (int)$project_id; $type = $type === 'final' ? 'final' : 'proforma';
		$table = $wpdb->prefix.'cptt_invoices';
		$id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE project_id=%d AND type=%s ORDER BY id DESC LIMIT 1", $project_id, $type));
		if ($id) return (int)$id;
		$prefix = $type === 'final' ? 'INV' : 'PRO';
		$no = $prefix . '-' . self::get_project_code($project_id) . '-' . date('ymd-His');
		$wpdb->insert($table, ['project_id'=>$project_id,'invoice_no'=>$no,'type'=>$type,'status'=>'issued','total'=>(float)$total,'created_by'=>(int)get_current_user_id(),'created_at'=>current_time('mysql')], ['%d','%s','%s','%s','%f','%d','%s']);
		self::activity_log('invoice', (int)$wpdb->insert_id, 'invoice_issued', 'صدور ' . ($type==='final'?'فاکتور':'پیش‌فاکتور') . ' برای پروژه #' . $project_id);
		return (int)$wpdb->insert_id;
	}

	public static function get_project_code($project_id) {
		$project_id = (int)$project_id;
		if (!$project_id) return '';
		$code = (string)get_post_meta($project_id, '_cptt_project_code', true);
		if (preg_match('/^\d{5}$/', $code)) return $code;
		for ($i = 0; $i < 80; $i++) {
			$try = (string)wp_rand(10000, 99999);
			$exists = get_posts([
				'post_type' => 'cptt_project', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids',
				'exclude' => [$project_id],
				'meta_query' => [[ 'key' => '_cptt_project_code', 'value' => $try, 'compare' => '=' ]],
			]);
			if (empty($exists)) { update_post_meta($project_id, '_cptt_project_code', $try); return $try; }
		}
		for ($n = 0; $n < 100000; $n++) {
			$try = str_pad((string)(($project_id + $n) % 100000), 5, '0', STR_PAD_LEFT);
			$exists = get_posts(['post_type'=>'cptt_project','post_status'=>'any','numberposts'=>1,'fields'=>'ids','exclude'=>[$project_id],'meta_query'=>[[ 'key'=>'_cptt_project_code','value'=>$try,'compare'=>'=' ]]]);
			if (empty($exists)) { update_post_meta($project_id, '_cptt_project_code', $try); return $try; }
		}
		return '';
	}

	public static function get_project_labels() {
		$labels = get_option('cptt_project_labels', []);
		if (!is_array($labels)) $labels = [];
		$out = [];
		foreach ($labels as $l) {
			if (!is_array($l)) continue;
			$name = sanitize_text_field($l['name'] ?? '');
			if ($name === '') continue;
			$out[] = [
				'id' => sanitize_key($l['id'] ?? ('lbl_' . md5($name))),
				'name' => $name,
				'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($l['color'] ?? '')) ? (string)$l['color'] : '#6366f1',
			];
		}
		return $out;
	}

	public static function get_project_label($project_id) {
		$id = (string)get_post_meta((int)$project_id, '_cptt_project_label_id', true);
		if ($id === '') return null;
		foreach (self::get_project_labels() as $l) if ((string)$l['id'] === $id) return $l;
		return null;
	}

	public static function activate() {
		self::add_expert_role();
		$core = self::instance();
		$core->register_cpt();
		if (function_exists('add_rewrite_endpoint')) add_rewrite_endpoint('cptt-projects', EP_ROOT | EP_PAGES);
		if (class_exists('CPTT_SMS')) CPTT_SMS::activate();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		if (class_exists('CPTT_SMS')) CPTT_SMS::deactivate();
		flush_rewrite_rules();
	}

	public function register_roles() {
		self::add_expert_role();
	}

	private static function add_expert_role() {
		add_role('cptt_expert', 'کارشناس', [
			'read' => true,
			'read_cptt_project' => true,
			'read_private_cptt_projects' => true,
			'edit_cptt_project' => true,
			'edit_cptt_projects' => true,
			'publish_cptt_projects' => true,
			'delete_cptt_project' => false,
			'delete_cptt_projects' => false,
		]);

		$admin = get_role('administrator');
		if ( $admin ) {
			$caps = [
				'read_cptt_project','read_private_cptt_projects','edit_cptt_project','edit_cptt_projects',
				'edit_others_cptt_projects','edit_published_cptt_projects','publish_cptt_projects',
				'delete_cptt_project','delete_cptt_projects','delete_others_cptt_projects','delete_published_cptt_projects',
				'edit_cptt_templates','publish_cptt_templates','edit_cptt_checklist_tpls','publish_cptt_checklist_tpls',
			];
			foreach ($caps as $cap) $admin->add_cap($cap);
		}
	}

	public function register_cpt() {
		register_post_type('cptt_project', [
			'labels' => [
				'name' => 'پروژه‌ها','singular_name' => 'پروژه','add_new_item' => 'افزودن پروژه جدید',
				'edit_item' => 'ویرایش پروژه','menu_name' => 'پروژه‌ها',
			],
			'public' => false,'show_ui' => true,'show_in_menu' => true,'menu_icon' => 'dashicons-clipboard',
			'supports' => ['title'],'has_archive' => false,'show_in_rest' => false,
			'capability_type' => ['cptt_project','cptt_projects'],'map_meta_cap' => true,
			'publicly_queryable' => false,'exclude_from_search' => true,
		]);

		register_post_type('cptt_template', [
			'labels' => [
				'name' => 'تمپلیت مراحل','singular_name' => 'تمپلیت مراحل','add_new_item' => 'افزودن تمپلیت مراحل',
				'edit_item' => 'ویرایش تمپلیت مراحل','menu_name' => 'تمپلیت مراحل',
			],
			'public' => false,'show_ui' => true,'show_in_menu' => 'edit.php?post_type=cptt_project',
			'menu_icon' => 'dashicons-editor-table','supports' => ['title'],'has_archive' => false,
			'show_in_rest' => false,'publicly_queryable' => false,'exclude_from_search' => true,
		]);

		register_post_type('cptt_order', [
			'labels' => [
				'name' => 'سفارش‌های بله','singular_name' => 'سفارش بله','add_new_item' => 'افزودن سفارش جدید',
				'edit_item' => 'ویرایش سفارش','menu_name' => 'سفارش‌های بله',
			],
			'public' => false,'show_ui' => true,'show_in_menu' => 'edit.php?post_type=cptt_project',
			'menu_icon' => 'dashicons-cart','supports' => ['title'],'has_archive' => false,
			'show_in_rest' => false,'publicly_queryable' => false,'exclude_from_search' => true,
			'capability_type' => 'post','map_meta_cap' => true,
		]);


		register_post_type('cptt_order_form', [
			'labels' => [
				'name' => 'فرم‌های سفارش','singular_name' => 'فرم سفارش','add_new_item' => 'افزودن فرم سفارش',
				'edit_item' => 'ویرایش فرم سفارش','menu_name' => 'فرم‌های سفارش',
			],
			'public' => false,'show_ui' => false,'show_in_menu' => false,
			'supports' => ['title'],'has_archive' => false,'show_in_rest' => false,
			'publicly_queryable' => false,'exclude_from_search' => true,
		]);


		register_post_type('cptt_payment_receipt', [
			'labels' => ['name'=>'رسیدهای پرداخت','singular_name'=>'رسید پرداخت','menu_name'=>'رسیدهای پرداخت'],
			'public'=>false,'show_ui'=>false,'show_in_menu'=>false,'supports'=>['title'],'has_archive'=>false,'show_in_rest'=>false,
		]);

				register_post_type('cptt_checklist_tpl', [
			'labels' => [
				'name' => 'تمپلیت چک‌لیست','singular_name' => 'تمپلیت چک‌لیست','add_new_item' => 'افزودن تمپلیت چک‌لیست',
				'edit_item' => 'ویرایش تمپلیت چک‌لیست','menu_name' => 'تمپلیت چک‌لیست',
			],
			'public' => false,'show_ui' => true,'show_in_menu' => 'edit.php?post_type=cptt_project',
			'menu_icon' => 'dashicons-yes-alt','supports' => ['title'],'has_archive' => false,
			'show_in_rest' => false,'publicly_queryable' => false,'exclude_from_search' => true,
		]);
	}

	public function register_meta() {
		register_post_meta('cptt_project', '_cptt_client_user_id', [
			'type' => 'integer','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
		register_post_meta('cptt_project', '_cptt_product_id', [
			'type' => 'integer','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
		register_post_meta('cptt_project', '_cptt_is_settled', [
			'type' => 'integer','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
		register_post_meta('cptt_project', '_cptt_expert_user_ids', [
			'type' => 'array','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
		register_post_meta('cptt_project', '_cptt_experts_csv', [
			'type' => 'string','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
		register_post_meta('cptt_project', '_cptt_expert_user_id', [
			'type' => 'integer','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
		register_post_meta('cptt_project', '_cptt_steps', [
			'type' => 'array','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
		register_post_meta('cptt_project', '_cptt_last_update', [
			'type' => 'integer','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
		register_post_meta('cptt_project', '_cptt_last_update_fa', [
			'type' => 'string','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
		register_post_meta('cptt_project', '_cptt_wc_cat_ids', [
			'type' => 'array','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
		register_post_meta('cptt_project', '_cptt_wc_cats_csv', [
			'type' => 'string','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
		register_post_meta('cptt_project', '_cptt_project_notes', [
			'type' => 'array','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
		register_post_meta('cptt_template', '_cptt_template_steps', [
			'type' => 'array','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
		register_post_meta('cptt_order', '_cptt_order_client_id', [
			'type' => 'integer','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
		register_post_meta('cptt_order', '_cptt_order_type', [
			'type' => 'string','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
		register_post_meta('cptt_order', '_cptt_order_description', [
			'type' => 'string','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
		register_post_meta('cptt_order', '_cptt_order_address', [
			'type' => 'string','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
		register_post_meta('cptt_order', '_cptt_order_files', [
			'type' => 'array','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
		register_post_meta('cptt_order', '_cptt_order_status', [
			'type' => 'string','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
		register_post_meta('cptt_order', '_cptt_order_assigned_expert', [
			'type' => 'integer','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
		register_post_meta('cptt_order', '_cptt_order_project_id', [
			'type' => 'integer','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
		register_post_meta('cptt_order', '_cptt_order_created_at_fa', [
			'type' => 'string','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
				register_post_meta('cptt_checklist_tpl', '_cptt_checklist_items', [
			'type' => 'array','single' => true,'show_in_rest' => false,
			'auth_callback' => function(){ return current_user_can('edit_cptt_projects'); },
		]);
	}

	public static function get_project_expert_ids($post_id) {
		$ids = get_post_meta($post_id, '_cptt_expert_user_ids', true);
		if (!is_array($ids)) $ids = [];
		if (empty($ids)) {
			$legacy = (int) get_post_meta($post_id, '_cptt_expert_user_id', true);
			if ($legacy) $ids = [$legacy];
		}
		$out = [];
		foreach ($ids as $id) {
			$id = (int)$id;
			if ($id > 0) $out[] = $id;
		}
		return array_values(array_unique($out));
	}

	public function filter_admin_list_for_experts($query) {
		if ( ! is_admin() || ! $query->is_main_query() ) return;
		if ( $query->get('post_type') !== 'cptt_project' ) return;
		$user = wp_get_current_user();
		if ( in_array('administrator', (array) $user->roles, true ) ) return;
		if ( in_array('cptt_expert', (array) $user->roles, true ) ) {
			$needle = ',' . get_current_user_id() . ',';
			$query->set('meta_query', [
				[ 'key' => '_cptt_experts_csv', 'value' => $needle, 'compare' => 'LIKE' ]
			]);
		}
	}

	public function block_edit_for_other_experts() {
		if ( ! is_admin() ) return;
		if ( empty($_GET['post']) ) return;
		$post_id = absint($_GET['post']);
		if ( get_post_type($post_id) !== 'cptt_project' ) return;
		$user = wp_get_current_user();
		if ( in_array('administrator', (array) $user->roles, true ) ) return;
		if ( in_array('cptt_expert', (array) $user->roles, true ) ) {
			$ids = self::get_project_expert_ids($post_id);
			if ( ! in_array(get_current_user_id(), $ids, true) ) {
				wp_die('شما اجازه ویرایش این پروژه را ندارید.');
			}
		}
	}

	/* ========= Jalali helpers ========= */
	public static function to_english_digits($str) {
		$fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
		$en = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
		return str_replace($fa, $en, (string)$str);
	}
	public static function to_persian_digits($str) {
		$en = ['0','1','2','3','4','5','6','7','8','9'];
		$fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
		return str_replace($en, $fa, (string)$str);
	}
	public static function jalali_datetime($timestamp) {
		$timestamp = (int) $timestamp;
		$dt = new DateTime('@' . $timestamp);
		$dt->setTimezone(new DateTimeZone('Asia/Tehran'));
		$gy = (int) $dt->format('Y'); $gm = (int) $dt->format('n'); $gd = (int) $dt->format('j');
		$time = $dt->format('H:i');
		[$jy, $jm, $jd] = self::gregorian_to_jalali($gy, $gm, $gd);
		$out = sprintf('%04d/%02d/%02d %s', $jy, $jm, $jd, $time);
		return self::to_persian_digits($out);
	}
	public static function parse_jalali_datetime($value) {
		$value = trim(self::to_english_digits((string)$value));
		if ($value === '') return 0;
		if (!preg_match('/^(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})(?:\s+(\d{1,2})\:(\d{1,2}))?$/', $value, $m)) return 0;
		$jy = (int)$m[1]; $jm = (int)$m[2]; $jd = (int)$m[3];
		$hh = isset($m[4]) ? (int)$m[4] : 0; $ii = isset($m[5]) ? (int)$m[5] : 0;
		if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > 31 || $hh < 0 || $hh > 23 || $ii < 0 || $ii > 59) return 0;
		[$gy, $gm, $gd] = self::jalali_to_gregorian($jy, $jm, $jd);
		try {
			$dt = new DateTime('now', new DateTimeZone('Asia/Tehran'));
			$dt->setDate($gy, $gm, $gd); $dt->setTime($hh, $ii, 0);
			return (int)$dt->getTimestamp();
		} catch (Exception $e) { return 0; }
	}
	public static function jalali_to_gregorian($jy, $jm, $jd) {
		$jy = (int)$jy + 1595;
		$days = -355668 + (365 * $jy) + ((int)($jy / 33) * 8) + (int)((($jy % 33) + 3) / 4) + (int)$jd;
		if ($jm < 7) $days += ($jm - 1) * 31; else $days += (($jm - 7) * 30) + 186;
		$gy = 400 * (int)($days / 146097); $days %= 146097;
		if ($days > 36524) { $gy += 100 * (int)(--$days / 36524); $days %= 36524; if ($days >= 365) $days++; }
		$gy += 4 * (int)($days / 1461); $days %= 1461;
		if ($days > 365) { $gy += (int)(($days - 1) / 365); $days = ($days - 1) % 365; }
		$gd = $days + 1;
		$sal_a = [0,31,((($gy % 4 == 0) && ($gy % 100 != 0)) || ($gy % 400 == 0)) ? 29 : 28,31,30,31,30,31,31,30,31,30,31];
		$gm = 0;
		for ($i = 1; $i <= 12; $i++) { if ($gd <= $sal_a[$i]) { $gm = $i; break; } $gd -= $sal_a[$i]; }
		return [$gy, $gm, (int)$gd];
	}
	public static function gregorian_to_jalali($gy, $gm, $gd) {
		$g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
		$gy2 = ($gm > 2) ? ($gy + 1) : $gy;
		$days = 355666 + (365 * $gy) + (int)(($gy2 + 3) / 4) - (int)(($gy2 + 99) / 100) + (int)(($gy2 + 399) / 400) + $gd + $g_d_m[$gm - 1];
		$jy = -1595 + 33 * (int)($days / 12053); $days %= 12053;
		$jy += 4 * (int)($days / 1461); $days %= 1461;
		if ($days > 365) { $jy += (int)(($days - 1) / 365); $days = ($days - 1) % 365; }
		if ($days < 186) { $jm = 1 + (int)($days / 31); $jd = 1 + ($days % 31); }
		else { $jm = 7 + (int)(($days - 186) / 30); $jd = 1 + (($days - 186) % 30); }
		return [$jy, $jm, $jd];
	}
}
