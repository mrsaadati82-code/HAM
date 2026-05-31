<?php
if ( ! defined('ABSPATH') ) exit;

class CPTT_Report {

	private static $instance = null;

	public static function instance() {
		if (self::$instance === null) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action('admin_post_cptt_view_report', [$this, 'render_report_page']);
		add_action('admin_post_cptt_view_invoice', [$this, 'render_invoice_page']);
	}

	public static function is_project_complete($project_id) {
		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps) || empty($steps)) return false;

		foreach ($steps as $s) {
			if (($s['status'] ?? 'todo') !== 'done') return false;
		}
		return true;
	}

	public static function user_can_access($project_id, $user_id) {
		$user_id = (int)$user_id;
		if (!$user_id) return false;

		if (user_can($user_id, 'manage_options')) return true;

		$client_id = (int)get_post_meta($project_id, '_cptt_client_user_id', true);
		if ($client_id && $client_id === $user_id) return true;

		if (class_exists('CPTT_Core') && method_exists('CPTT_Core', 'get_project_expert_ids')) {
			$experts = CPTT_Core::get_project_expert_ids($project_id);
			if (in_array($user_id, $experts, true)) return true;
		}

		return false;
	}

	private function user_name($id) {
		$u = $id ? get_user_by('id', (int)$id) : null;
		return $u ? $u->display_name : '';
	}

	private function branding() {
		if (class_exists('CPTT_Settings') && method_exists('CPTT_Settings', 'get')) {
			return CPTT_Settings::get();
		}

		return [
			'brand_name'    => get_bloginfo('name'),
			'site_url'      => home_url('/'),
			'primary_color' => '#6366f1',
			'footer_text'   => 'این گزارش به صورت خودکار تولید شده است.',
			'logo_id'       => 0,
			'sign_id'       => 0,
			'stamp_id'      => 0,
			'manager_name'  => '',
			'manager_title' => '',
		];
	}

	private function att_url($id) {
		$id = (int)$id;
		if (!$id) return '';
		$url = wp_get_attachment_image_url($id, 'large');
		return $url ? $url : '';
	}

	public function render_report_page() {
		if (!is_user_logged_in()) wp_die('برای مشاهده گزارش باید وارد شوید.');

		$project_id = isset($_GET['project_id']) ? absint($_GET['project_id']) : 0;
		if (!$project_id || get_post_type($project_id) !== 'cptt_project') wp_die('پروژه معتبر نیست.');

		check_admin_referer('cptt_view_report_' . $project_id);

		if (!self::user_can_access($project_id, get_current_user_id())) wp_die('شما به این گزارش دسترسی ندارید.');
		if (!self::is_project_complete($project_id)) wp_die('گزارش فقط پس از تکمیل پروژه قابل مشاهده است.');

		$brand = $this->branding();
		$primary = $brand['primary_color'] ?: '#6366f1';

		$title = get_the_title($project_id);
		$created_fa = class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime((int) current_time('timestamp', true)) : date('Y-m-d H:i');

		$client_id = (int)get_post_meta($project_id, '_cptt_client_user_id', true);
		$client_name = $client_id ? $this->user_name($client_id) : '';

		$expert_names = [];
		if (class_exists('CPTT_Core') && method_exists('CPTT_Core', 'get_project_expert_ids')) {
			$ids = CPTT_Core::get_project_expert_ids($project_id);
			foreach ($ids as $id) {
				$n = $this->user_name($id);
				if ($n) $expert_names[] = $n;
			}
		}

		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps)) $steps = [];

		$last_update = (string)get_post_meta($project_id, '_cptt_last_update_fa', true);

		$logo = $this->att_url($brand['logo_id'] ?? 0);
		$sign = $this->att_url($brand['sign_id'] ?? 0);
		$stamp = $this->att_url($brand['stamp_id'] ?? 0);

		if (class_exists('CPTT_Core')) {
			$total_for_invoice = 0; foreach ($steps as $_s) $total_for_invoice += (float)($_s['cost'] ?? 0);
			CPTT_Core::ensure_invoice($project_id, !empty($_GET['final']) ? 'final' : 'proforma', $total_for_invoice);
		}

		// Get toggles
		$toggles = class_exists('CPTT_Settings') && method_exists('CPTT_Settings', 'get_branding_toggles') ? CPTT_Settings::get_branding_toggles() : [];

		header('Content-Type: text/html; charset=utf-8');
		?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html('گزارش پروژه - ' . $title); ?></title>
	<style>
		:root{
			--primary: <?php echo esc_html($primary); ?>;
			--primary-light: rgba(99, 102, 241, 0.08);
			--text: #1e293b;
			--muted: #475569;
			--border: #cbd5e1;
			--bg: #f8fafc;
			--white: #ffffff;
		}
		*{ box-sizing:border-box; margin: 0; padding: 0; }
		body{
			background: var(--bg);
			color: var(--text);
			font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
			font-size: 13px;
			line-height: 1.7;
			padding: 20px 10px;
		}
		.wrap{
			max-width: 800px;
			margin: 0 auto;
			background: var(--white);
			border: 2px solid var(--border);
			border-radius: 16px;
			box-shadow: 0 10px 30px rgba(0,0,0,0.03);
			overflow: hidden;
		}
		.print-header {
			background: linear-gradient(135deg, var(--primary), #4f46e5);
			color: var(--white);
			padding: 20px 24px;
			display: flex;
			align-items: center;
			justify-content: space-between;
			flex-wrap: wrap;
			gap: 15px;
		}
		.brand-info {
			display: flex;
			align-items: center;
			gap: 14px;
		}
		.brand-logo-img {
			max-height: 50px;
			max-width: 150px;
			object-fit: contain;
			background: var(--white);
			border-radius: 8px;
			padding: 4px;
		}
		.brand-title {
			font-size: 16px;
			font-weight: 950;
		}
		.brand-url {
			font-size: 11px;
			opacity: 0.85;
		}
		.document-title-box {
			text-align: left;
		}
		.document-title {
			font-size: 18px;
			font-weight: 950;
			letter-spacing: -0.5px;
		}
		.document-date {
			font-size: 11px;
			opacity: 0.9;
			margin-top: 4px;
		}
		.actions-bar {
			background: #f1f5f9;
			border-bottom: 1px solid var(--border);
			padding: 10px 24px;
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 10px;
			flex-wrap: wrap;
		}
		.btn {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 8px 14px;
			font-size: 12px;
			font-weight: 800;
			color: #334155;
			background: var(--white);
			border: 1px solid #cbd5e1;
			border-radius: 8px;
			cursor: pointer;
			text-decoration: none;
			transition: all 0.15s ease;
		}
		.btn:hover {
			background: #e2e8f0;
			border-color: #94a3b8;
		}
		.btn-primary {
			background: var(--primary);
			color: var(--white);
			border-color: transparent;
		}
		.btn-primary:hover {
			background: #4f46e5;
			color: var(--white);
		}
		.main-content {
			padding: 24px;
		}
		.metadata-grid {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 12px;
			margin-bottom: 20px;
		}
		@media (max-width: 580px) {
			.metadata-grid {
				grid-template-columns: 1fr;
			}
		}
		.metadata-box {
			border: 1px solid #e2e8f0;
			background: #f8fafc;
			border-radius: 12px;
			padding: 12px 16px;
		}
		.metadata-item {
			margin-bottom: 8px;
			font-size: 13px;
		}
		.metadata-item:last-child {
			margin-bottom: 0;
		}
		.metadata-item b {
			color: #0f172a;
			font-weight: 900;
		}
		.section-title {
			font-size: 15px;
			font-weight: 950;
			color: #0f172a;
			margin-bottom: 14px;
			padding-bottom: 6px;
			border-bottom: 2px solid var(--primary);
			display: inline-block;
		}
		.step-card {
			border: 1px solid #e2e8f0;
			border-radius: 12px;
			padding: 14px 18px;
			margin-bottom: 12px;
			background: var(--white);
		}
		.step-card:last-child {
			margin-bottom: 0;
		}
		.step-header {
			display: flex;
			align-items: center;
			justify-content: space-between;
			flex-wrap: wrap;
			gap: 10px;
			margin-bottom: 8px;
		}
		.step-name {
			font-size: 14px;
			font-weight: 950;
			color: #0f172a;
		}
		.badge {
			display: inline-flex;
			padding: 4px 10px;
			border-radius: 999px;
			font-size: 11px;
			font-weight: 900;
			border: 1px solid transparent;
		}
		.badge-done { background: rgba(34,197,94,0.12); color:#065f46; border-color: rgba(34,197,94,0.2); }
		.badge-current { background: rgba(245,158,11,0.12); color:#92400e; border-color: rgba(245,158,11,0.2); }
		.badge-todo { background: rgba(148,163,184,0.14); color:#475569; border-color: rgba(148,163,184,0.2); }
		
		.step-desc {
			color: #334155;
			font-size: 12.5px;
			margin: 8px 0;
			background: #f8fafc;
			padding: 10px 14px;
			border-radius: 8px;
			border-right: 3px solid #cbd5e1;
		}
		.step-meta-row {
			font-size: 11.5px;
			color: var(--muted);
			margin-top: 6px;
		}
		.list-title {
			font-weight: 900;
			font-size: 12px;
			color: #334155;
			margin: 12px 0 6px;
			display: flex;
			align-items: center;
			gap: 6px;
		}
		.items-list {
			list-style: none;
			padding: 0;
			margin: 0;
			display: grid;
			gap: 6px;
		}
		.items-list li {
			padding: 8px 12px;
			background: #fafafa;
			border: 1px solid #f1f5f9;
			border-radius: 8px;
			display: flex;
			justify-content: space-between;
			align-items: center;
			flex-wrap: wrap;
			gap: 10px;
		}
		.items-list li.done-item {
			background: #f0fdf4;
			border-color: #d1fae5;
			color: #065f46;
		}
		.item-text {
			font-weight: 700;
			font-size: 12.5px;
		}
		.item-meta {
			font-size: 11px;
			color: var(--muted);
		}
		.item-link {
			color: var(--primary);
			text-decoration: underline;
			font-weight: 800;
			font-size: 11px;
			margin-right: 6px;
		}
		.final-sign-box {
			margin-top: 24px;
			border: 1px solid #e2e8f0;
			border-radius: 12px;
			padding: 16px 20px;
			background: #f8fafc;
		}
		.sign-stamp-grid {
			display: flex;
			justify-content: space-between;
			align-items: center;
			flex-wrap: wrap;
			gap: 20px;
		}
		.sign-stamp-grid > div {
			flex: 1 1 200px;
			text-align: center;
		}
		.sign-img, .stamp-img {
			max-height: 80px;
			max-width: 180px;
			object-fit: contain;
			margin: 0 auto;
			display: block;
		}
		.sign-label {
			font-size: 12px;
			font-weight: bold;
			color: #334155;
			margin-top: 8px;
		}
		.sign-title {
			font-size: 11px;
			color: var(--muted);
			margin-top: 2px;
		}
		.print-footer {
			text-align: center;
			padding: 15px 24px;
			background: #f8fafc;
			border-top: 1px solid var(--border);
			font-size: 11px;
			color: var(--muted);
		}
		@media print {
			body { background: #fff; padding: 0; }
			.wrap { border: none; box-shadow: none; max-width: 100%; border-radius: 0; }
			.actions-bar { display: none !important; }
			.step-card { break-inside: avoid; page-break-inside: avoid; }
			.final-sign-box { break-inside: avoid; page-break-inside: avoid; }
		}
	</style>
</head>
<body>
	<div class="wrap">
		<!-- Header -->
		<div class="print-header">
			<div class="brand-info">
				<?php if ($logo && ($toggles['report_show_logo'] ?? '1') === '1'): ?>
					<img src="<?php echo esc_url($logo); ?>" class="brand-logo-img" alt="logo" />
				<?php endif; ?>
				<div>
					<div class="brand-title"><?php echo esc_html($brand['brand_name'] ?? get_bloginfo('name')); ?></div>
					<div class="brand-url"><?php echo esc_html($brand['site_url'] ?? home_url('/')); ?></div>
				</div>
			</div>
			<div class="document-title-box">
				<div class="document-title">گزارش نهایی پروژه</div>
				<?php if (($toggles['report_show_dates'] ?? '1') === '1'): ?>
					<div class="document-date">تاریخ صدور: <?php echo esc_html($created_fa); ?></div>
				<?php endif; ?>
			</div>
		</div>

		<!-- Actions -->
		<div class="actions-bar">
			<a class="btn" href="<?php echo esc_url(wp_get_referer() ?: home_url('/')); ?>">◀ بازگشت</a>
			<div>
				<a class="btn" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cptt_view_invoice&project_id=' . $project_id . '&final=1'), 'cptt_view_invoice_' . $project_id)); ?>" target="_blank">🧾 فاکتور</a>
				<button class="btn btn-primary" type="button" onclick="window.print()">🖨 چاپ گزارش</button>
			</div>
		</div>

		<!-- Main -->
		<div class="main-content">
			<!-- Meta details -->
			<div class="metadata-grid">
				<div class="metadata-box">
					<div class="metadata-item"><b>عنوان پروژه:</b> <?php echo esc_html($title); ?></div>
					<?php if ($client_name && ($toggles['report_show_client'] ?? '1') === '1'): ?>
						<div class="metadata-item"><b>مشتری:</b> <?php echo esc_html($client_name); ?></div>
					<?php endif; ?>
				</div>
				<div class="metadata-box">
					<?php if (!empty($expert_names) && ($toggles['report_show_experts'] ?? '1') === '1'): ?>
						<div class="metadata-item"><b>کارشناسان:</b> <?php echo esc_html(implode('، ', $expert_names)); ?></div>
					<?php endif; ?>
					<?php if ($last_update && (($toggles['report_show_dates'] ?? '1') === '1')): ?>
						<div class="metadata-item"><b>آخرین تغییر:</b> <?php echo esc_html($last_update); ?></div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Steps -->
			<div class="section-title">روند انجام مراحل پروژه</div>
			<div class="steps-container">
				<?php foreach ($steps as $idx => $s):
					$st = $s['status'] ?? 'todo';
					$badgeClass = $st === 'done' ? 'badge-done' : ($st === 'current' ? 'badge-current' : 'badge-todo');
					$badgeText  = $st === 'done' ? 'انجام‌شده' : ($st === 'current' ? 'در حال انجام' : 'انجام‌نشده');

					$step_due_fa = (string)($s['due_at_fa'] ?? '');
					$desc = trim(wp_strip_all_tags((string)($s['desc'] ?? '')));

					$cl = isset($s['checklist']) && is_array($s['checklist']) ? $s['checklist'] : [];
					$uts = isset($s['user_tasks']) && is_array($s['user_tasks']) ? $s['user_tasks'] : [];
				?>
					<div class="step-card">
						<div class="step-header">
							<div class="step-name"><?php echo esc_html(($idx+1) . '. ' . ($s['title'] ?? '')); ?></div>
							<span class="badge <?php echo esc_attr($badgeClass); ?>"><?php echo esc_html($badgeText); ?></span>
						</div>

						<?php if ($step_due_fa && (($toggles['report_show_dates'] ?? '1') === '1')): ?>
							<div class="step-meta-row">📅 <b>مهلت مرحله:</b> <?php echo esc_html($step_due_fa); ?></div>
						<?php endif; ?>

						<?php if ($desc !== ''): ?>
							<div class="step-desc"><?php echo esc_html($desc); ?></div>
						<?php endif; ?>

						<!-- Checklist -->
						<?php if (!empty($cl) && ($toggles['report_show_checklist'] ?? '1') === '1'): ?>
							<div class="list-title">📋 چک‌لیست مرحله:</div>
							<ul class="items-list">
								<?php foreach ($cl as $it):
									$text = (string)($it['text'] ?? '');
									if ($text === '') continue;

									$done = !empty($it['done']);
									$done_at = (string)($it['done_at_fa'] ?? '');
									$url = trim((string)($it['url'] ?? ''));
								?>
									<li class="<?php echo $done ? 'done-item' : ''; ?>">
										<span class="item-text"><?php echo esc_html($text); ?></span>
										<div class="item-meta">
											<?php if ($done): ?>
												<span>✓ انجام شد</span>
												<?php if ($done_at && (($toggles['report_show_dates'] ?? '1') === '1')): ?>
													<span>(<?php echo esc_html($done_at); ?>)</span>
												<?php endif; ?>
												<?php if ($url && (strpos($url, 'http') === 0)): ?>
													<a href="<?php echo esc_url($url); ?>" class="item-link" target="_blank" rel="noopener">دانلود نتیجه</a>
												<?php endif; ?>
											<?php else: ?>
												<span>—</span>
											<?php endif; ?>
										</div>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>

						<!-- Customer Tasks -->
						<?php if (!empty($uts) && ($toggles['report_show_usertasks'] ?? '1') === '1'): ?>
							<div class="list-title">👤 تسک‌های سمت مشتری:</div>
							<ul class="items-list">
								<?php foreach ($uts as $ut):
									$ut_title = (string)($ut['title'] ?? '');
									if ($ut_title === '') continue;
									$ut_done = !empty($ut['done']);
									$ut_resp = (string)($ut['response'] ?? '');
									$ut_completed = (string)($ut['completed_at_fa'] ?? '');
									$ut_file_url = trim((string)($ut['response_file_url'] ?? ''));
									$ut_files = isset($ut['response_files']) && is_array($ut['response_files']) ? $ut['response_files'] : [];
								?>
									<li class="<?php echo $ut_done ? 'done-item' : ''; ?>">
										<div>
											<span class="item-text"><?php echo esc_html($ut_title); ?></span>
											<?php if ($ut_resp): ?><div style="font-size:11px; margin-top:3px; opacity:0.8;"><?php echo esc_html($ut_resp); ?></div><?php endif; ?>
										</div>
										<div class="item-meta">
											<?php if ($ut_done): ?>
												<span>✓ تکمیل شد</span>
												<?php if ($ut_completed && (($toggles['report_show_dates'] ?? '1') === '1')): ?>
													<span>(<?php echo esc_html($ut_completed); ?>)</span>
												<?php endif; ?>
												<?php if ($ut_file_url && (strpos($ut_file_url, 'http') === 0)): ?>
													<a href="<?php echo esc_url($ut_file_url); ?>" class="item-link" target="_blank" rel="noopener">مشاهده فایل</a>
												<?php endif; ?>
												<?php if (!empty($ut_files)): foreach ($ut_files as $rf): if (empty($rf['url'])) continue; ?>
													<a href="<?php echo esc_url($rf['url']); ?>" class="item-link" target="_blank" rel="noopener"><?php echo esc_html(!empty($rf['name']) ? $rf['name'] : 'فایل ارسالی'); ?></a>
												<?php endforeach; endif; ?>
											<?php else: ?>
												<span>در انتظار اقدام مشتری</span>
											<?php endif; ?>
										</div>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- Approvals sign/stamp -->
			<?php if ((($toggles['report_show_sign_stamp'] ?? '1') === '1') && ($sign || $stamp || !empty($brand['manager_name']) || !empty($brand['manager_title']))): ?>
				<div class="final-sign-box">
					<div class="sign-stamp-grid">
						<div>
							<?php if ($sign): ?>
								<img src="<?php echo esc_url($sign); ?>" class="sign-img" alt="signature" />
							<?php endif; ?>
							<div class="sign-label"><?php echo esc_html($brand['manager_name'] ?: 'امضا مدیر مسئول'); ?></div>
							<?php if (!empty($brand['manager_title'])): ?>
								<div class="sign-title"><?php echo esc_html($brand['manager_title']); ?></div>
							<?php endif; ?>
						</div>
						<div>
							<?php if ($stamp): ?>
								<img src="<?php echo esc_url($stamp); ?>" class="stamp-img" alt="stamp" />
							<?php endif; ?>
						</div>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<!-- Footer -->
		<div class="print-footer">
			<?php echo esc_html($brand['footer_text'] ?? ''); ?>
		</div>
	</div>
</body>
</html>
		<?php
		exit;
	}

	public function render_invoice_page() {
		if (!is_user_logged_in()) wp_die('برای مشاهده پیش‌فاکتور باید وارد شوید.');

		$project_id = isset($_GET['project_id']) ? absint($_GET['project_id']) : 0;
		if (!$project_id || get_post_type($project_id) !== 'cptt_project') wp_die('پروژه معتبر نیست.');

		check_admin_referer('cptt_view_invoice_' . $project_id);

		if (!self::user_can_access($project_id, get_current_user_id())) wp_die('شما به این پیش‌فاکتور دسترسی ندارید.');
		$is_final_invoice = !empty($_GET['final']);
		$invoice_title = $is_final_invoice ? 'فاکتور نهایی پروژه' : 'پیش‌فاکتور پروژه';
		$invoice_short = $is_final_invoice ? 'فاکتور' : 'پیش‌فاکتور';

		$brand = $this->branding();
		$primary = $brand['primary_color'] ?: '#6366f1';

		$title = get_the_title($project_id);
		$created_fa = class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime((int) current_time('timestamp', true)) : date('Y-m-d H:i');

		$client_id = (int)get_post_meta($project_id, '_cptt_client_user_id', true);
		$client_name = $client_id ? $this->user_name($client_id) : '';
		$client_phone = $client_id ? get_user_meta($client_id, 'billing_phone', true) : '';

		$expert_names = [];
		if (class_exists('CPTT_Core') && method_exists('CPTT_Core', 'get_project_expert_ids')) {
			$ids = CPTT_Core::get_project_expert_ids($project_id);
			foreach ($ids as $id) {
				$n = $this->user_name($id);
				if ($n) $expert_names[] = $n;
			}
		}

		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps)) $steps = [];

		$logo = $this->att_url($brand['logo_id'] ?? 0);
		$sign = $this->att_url($brand['sign_id'] ?? 0);
		$stamp = $this->att_url($brand['stamp_id'] ?? 0);

		// Get delivery details
		$delivery_method = (string)get_post_meta($project_id, '_cptt_delivery_method', true);
		$delivery_method_label = $delivery_method === 'shipping' ? 'ارسال با پست/باربری' : ($delivery_method === 'in_person' ? 'تحویل حضوری' : '—');
		$delivery_province = (string)get_post_meta($project_id, '_cptt_delivery_province', true);
		$delivery_city = (string)get_post_meta($project_id, '_cptt_delivery_city', true);
		$delivery_address = (string)get_post_meta($project_id, '_cptt_delivery_address', true);

		// Get toggles
		$toggles = class_exists('CPTT_Settings') && method_exists('CPTT_Settings', 'get_branding_toggles') ? CPTT_Settings::get_branding_toggles() : [];

		header('Content-Type: text/html; charset=utf-8');
		?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html($invoice_short . ' - ' . $title); ?></title>
	<style>
		:root{
			--primary: <?php echo esc_html($primary); ?>;
			--text: #1e293b;
			--muted: #475569;
			--border: #cbd5e1;
			--bg: #f8fafc;
			--white: #ffffff;
		}
		*{ box-sizing:border-box; margin: 0; padding: 0; }
		body{
			background: var(--bg);
			color: var(--text);
			font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
			font-size: 13px;
			line-height: 1.7;
			padding: 20px 10px;
		}
		.wrap{
			max-width: 800px;
			margin: 0 auto;
			background: var(--white);
			border: 2px solid var(--border);
			border-radius: 16px;
			box-shadow: 0 10px 30px rgba(0,0,0,0.03);
			overflow: hidden;
		}
		.print-header {
			background: linear-gradient(135deg, var(--primary), #4f46e5);
			color: var(--white);
			padding: 20px 24px;
			display: flex;
			align-items: center;
			justify-content: space-between;
			flex-wrap: wrap;
			gap: 15px;
		}
		.brand-info {
			display: flex;
			align-items: center;
			gap: 14px;
		}
		.brand-logo-img {
			max-height: 50px;
			max-width: 150px;
			object-fit: contain;
			background: var(--white);
			border-radius: 8px;
			padding: 4px;
		}
		.brand-title {
			font-size: 16px;
			font-weight: 950;
		}
		.brand-url {
			font-size: 11px;
			opacity: 0.85;
		}
		.document-title-box {
			text-align: left;
		}
		.document-title {
			font-size: 18px;
			font-weight: 950;
			letter-spacing: -0.5px;
		}
		.document-date {
			font-size: 11px;
			opacity: 0.9;
			margin-top: 4px;
		}
		.actions-bar {
			background: #f1f5f9;
			border-bottom: 1px solid var(--border);
			padding: 10px 24px;
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 10px;
			flex-wrap: wrap;
		}
		.btn {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 8px 14px;
			font-size: 12px;
			font-weight: 800;
			color: #334155;
			background: var(--white);
			border: 1px solid #cbd5e1;
			border-radius: 8px;
			cursor: pointer;
			text-decoration: none;
			transition: all 0.15s ease;
		}
		.btn:hover {
			background: #e2e8f0;
			border-color: #94a3b8;
		}
		.btn-primary {
			background: var(--primary);
			color: var(--white);
			border-color: transparent;
		}
		.btn-primary:hover {
			background: #4f46e5;
			color: var(--white);
		}
		.main-content {
			padding: 24px;
		}
		.metadata-grid {
			display: grid;
			grid-template-columns: 1.2fr 0.8fr;
			gap: 12px;
			margin-bottom: 20px;
		}
		@media (max-width: 580px) {
			.metadata-grid {
				grid-template-columns: 1fr;
			}
		}
		.metadata-box {
			border: 1px solid #e2e8f0;
			background: #f8fafc;
			border-radius: 12px;
			padding: 12px 16px;
		}
		.metadata-item {
			margin-bottom: 6px;
			font-size: 13px;
		}
		.metadata-item:last-child {
			margin-bottom: 0;
		}
		.metadata-item b {
			color: #0f172a;
			font-weight: 900;
		}
		.invoice-table {
			width: 100%;
			border-collapse: collapse;
			margin: 20px 0;
		}
		.invoice-table th, .invoice-table td {
			border: 1px solid var(--border);
			padding: 10px 14px;
			text-align: right;
		}
		.invoice-table th {
			background: #f1f5f9;
			font-weight: 900;
			color: #0f172a;
		}
		.text-left { text-align: left !important; }
		.text-center { text-align: center !important; }
		.totals-row {
			font-weight: bold;
			background: #f8fafc;
		}
		.totals-row td {
			font-size: 13.5px;
			border-top: 2px solid var(--primary);
		}
		.final-sign-box {
			margin-top: 24px;
			border: 1px solid #e2e8f0;
			border-radius: 12px;
			padding: 16px 20px;
			background: #f8fafc;
		}
		.sign-stamp-grid {
			display: flex;
			justify-content: space-between;
			align-items: center;
			flex-wrap: wrap;
			gap: 20px;
		}
		.sign-stamp-grid > div {
			flex: 1 1 200px;
			text-align: center;
		}
		.sign-img, .stamp-img {
			max-height: 80px;
			max-width: 180px;
			object-fit: contain;
			margin: 0 auto;
			display: block;
		}
		.sign-label {
			font-size: 12px;
			font-weight: bold;
			color: #334155;
			margin-top: 8px;
		}
		.sign-title {
			font-size: 11px;
			color: var(--muted);
			margin-top: 2px;
		}
		.print-footer {
			text-align: center;
			padding: 15px 24px;
			background: #f8fafc;
			border-top: 1px solid var(--border);
			font-size: 11px;
			color: var(--muted);
		}
		@media print {
			body { background: #fff; padding: 0; }
			.wrap { border: none; box-shadow: none; max-width: 100%; border-radius: 0; }
			.actions-bar { display: none !important; }
			.invoice-table { break-inside: avoid; page-break-inside: avoid; }
			.final-sign-box { break-inside: avoid; page-break-inside: avoid; }
		}
	</style>
</head>
<body>
	<div class="wrap">
		<!-- Header -->
		<div class="print-header">
			<div class="brand-info">
				<?php if ($logo && ($toggles['invoice_show_logo'] ?? '1') === '1'): ?>
					<img src="<?php echo esc_url($logo); ?>" class="brand-logo-img" alt="logo" />
				<?php endif; ?>
				<div>
					<div class="brand-title"><?php echo esc_html($brand['brand_name'] ?? get_bloginfo('name')); ?></div>
					<div class="brand-url"><?php echo esc_html($brand['site_url'] ?? home_url('/')); ?></div>
				</div>
			</div>
			<div class="document-title-box">
				<div class="document-title"><?php echo esc_html($invoice_title); ?></div>
				<?php if (($toggles['invoice_show_dates'] ?? '1') === '1'): ?>
					<div class="document-date">تاریخ صدور: <?php echo esc_html($created_fa); ?></div>
				<?php endif; ?>
			</div>
		</div>

		<!-- Actions -->
		<div class="actions-bar">
			<a class="btn" href="<?php echo esc_url(wp_get_referer() ?: home_url('/')); ?>">◀ بازگشت</a>
			<div>
				<button class="btn btn-primary" type="button" onclick="window.print()">🖨 چاپ <?php echo esc_html($invoice_short); ?></button>
			</div>
		</div>

		<!-- Main -->
		<div class="main-content">
			<!-- Meta details -->
			<div class="metadata-grid">
				<div class="metadata-box">
					<div class="metadata-item"><b>عنوان پروژه:</b> <?php echo esc_html($title); ?></div>
					<?php if ($client_name && ($toggles['invoice_show_client'] ?? '1') === '1'): ?>
						<div class="metadata-item"><b>خریدار / مشتری:</b> <?php echo esc_html($client_name); ?></div>
					<?php endif; ?>
					<?php if ($client_phone && ($toggles['invoice_show_client'] ?? '1') === '1'): ?>
						<div class="metadata-item"><b>تلفن تماس:</b> <span style="direction:ltr; display:inline-block;"><?php echo esc_html($client_phone); ?></span></div>
					<?php endif; ?>
				</div>
				<div class="metadata-box">
					<?php if (!empty($expert_names) && ($toggles['invoice_show_experts'] ?? '1') === '1'): ?>
						<div class="metadata-item"><b>کارشناسان پروژه:</b> <?php echo esc_html(implode('، ', $expert_names)); ?></div>
					<?php endif; ?>
					<?php if ($delivery_method): ?>
						<div class="metadata-item"><b>روش تحویل:</b> <?php echo esc_html($delivery_method_label); ?></div>
					<?php endif; ?>
				</div>
			</div>

			<?php if ($delivery_method === 'shipping' && ($delivery_province || $delivery_address)): ?>
				<div class="metadata-box" style="margin-bottom:20px;">
					<b>📍 آدرس تحویل و ارسال:</b>
					<span style="font-size:12.5px; display:block; margin-top:4px;">
						<?php echo esc_html(($delivery_province ? $delivery_province : '') . ($delivery_city ? ' - ' . $delivery_city : '') . ($delivery_address ? ' - ' . $delivery_address : '')); ?>
					</span>
				</div>
			<?php endif; ?>

			<!-- Invoice Items table -->
			<?php if (($toggles['invoice_show_step_breakdown'] ?? '1') === '1'): ?>
				<table class="invoice-table">
					<thead>
						<tr>
							<th class="text-center" style="width:50px;">ردیف</th>
							<th>شرح خدمات / مرحله</th>
							<th class="text-left" style="width:140px;">هزینه (ریال)</th>
							<th class="text-left" style="width:140px;">دریافتی (ریال)</th>
							<th class="text-left" style="width:140px;">مانده (ریال)</th>
						</tr>
					</thead>
					<tbody>
						<?php 
						$total_cost = 0;
						$total_paid = 0;
						$total_remain = 0;
						$row_idx = 1;
						foreach ($steps as $s):
							$st_title = trim((string)($s['title'] ?? ''));
							if ($st_title === '') continue;
							$cost = (float)($s['cost'] ?? 0);
							$paid = (float)($s['paid'] ?? 0);
							$remain = $cost - $paid;

							$total_cost += $cost;
							$total_paid += $paid;
							$total_remain += $remain;
						?>
							<tr>
								<td class="text-center"><?php echo esc_html($row_idx++); ?></td>
								<td>
									<div style="font-weight:700; color:#0f172a;"><?php echo esc_html($st_title); ?></div>
									<?php /* v5.4.17: نمایش توضیحات مرحله از فاکتور حذف شد. */ ?>
								</td>
								<td class="text-left"><?php echo esc_html(number_format($cost)); ?></td>
								<td class="text-left" style="color:#059669;"><?php echo esc_html(number_format($paid)); ?></td>
								<td class="text-left" style="color:<?php echo $remain > 0 ? '#dc2626' : '#059669'; ?>; font-weight:700;"><?php echo esc_html(number_format($remain)); ?></td>
							</tr>
						<?php endforeach; ?>

						<!-- Totals -->
						<tr class="totals-row">
							<td colspan="2" style="text-align:left;">جمع کل <?php echo esc_html($invoice_short); ?> (ریال):</td>
							<td class="text-left"><?php echo esc_html(number_format($total_cost)); ?></td>
							<td class="text-left" style="color:#059669;"><?php echo esc_html(number_format($total_paid)); ?></td>
							<td class="text-left" style="color:<?php echo $total_remain > 0 ? '#dc2626' : '#059669'; ?>;"><?php echo esc_html(number_format($total_remain)); ?></td>
						</tr>
					</tbody>
				</table>
			<?php else: 
				// Just print total summary if step breakdown is disabled
				$total_cost = 0;
				$total_paid = 0;
				foreach ($steps as $s) {
					$total_cost += (float)($s['cost'] ?? 0);
					$total_paid += (float)($s['paid'] ?? 0);
				}
				$total_remain = $total_cost - $total_paid;
			?>
				<div class="metadata-box" style="margin-bottom:20px; display:flex; justify-content:space-between; gap:20px; text-align:center;">
					<div><span>جمع کل هزینه:</span> <strong style="font-size:16px; display:block; margin-top:4px;"><?php echo esc_html(number_format($total_cost)); ?> ریال</strong></div>
					<div><span>جمع پرداختی:</span> <strong style="font-size:16px; display:block; margin-top:4px; color:#059669;"><?php echo esc_html(number_format($total_paid)); ?> ریال</strong></div>
					<div><span>مانده حساب:</span> <strong style="font-size:16px; display:block; margin-top:4px; color:<?php echo $total_remain > 0 ? '#dc2626' : '#059669'; ?>;"><?php echo esc_html(number_format($total_remain)); ?> ریال</strong></div>
				</div>
			<?php endif; ?>

			<!-- Approvals sign/stamp -->
			<?php if ((($toggles['invoice_show_sign_stamp'] ?? '1') === '1') && ($sign || $stamp || !empty($brand['manager_name'] || !empty($brand['manager_title'])))): ?>
				<div class="final-sign-box">
					<div class="sign-stamp-grid">
						<div>
							<?php if ($sign): ?>
								<img src="<?php echo esc_url($sign); ?>" class="sign-img" alt="signature" />
							<?php endif; ?>
							<div class="sign-label"><?php echo esc_html($brand['manager_name'] ?: ($is_final_invoice ? 'امضا صادرکننده فاکتور' : 'امضا صادرکننده پیش‌فاکتور')); ?></div>
							<?php if (!empty($brand['manager_title'])): ?>
								<div class="sign-title"><?php echo esc_html($brand['manager_title']); ?></div>
							<?php endif; ?>
						</div>
						<div>
							<?php if ($stamp): ?>
								<img src="<?php echo esc_url($stamp); ?>" class="stamp-img" alt="stamp" />
							<?php endif; ?>
						</div>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<!-- Footer -->
		<div class="print-footer">
			<?php echo esc_html($brand['footer_text'] ?? ''); ?>
		</div>
	</div>
</body>
</html>
		<?php
		exit;
	}
}
