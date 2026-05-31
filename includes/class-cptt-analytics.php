<?php
if ( ! defined('ABSPATH') ) exit;

class CPTT_Analytics {
	private static $instance = null;

	public static function instance() {
		if (self::$instance === null) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action('admin_menu', [$this, 'admin_menu']);
		add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
		add_action('wp_ajax_cptt_expert_analytics_data', [$this, 'ajax_expert_analytics']);
	}

	public function admin_menu() {
		add_submenu_page(
			'edit.php?post_type=cptt_project',
			'گزارش‌گیری عملکرد',
			'📊 گزارش‌گیری',
			'edit_cptt_projects',
			'cptt-analytics',
			[$this, 'render_admin_page']
		);
	}

	public function admin_assets($hook) {
		if (strpos($hook, 'cptt-analytics') === false) return;
		wp_enqueue_style('cptt-admin', CPTT_URL . 'assets/css/admin.css', [], CPTT_VERSION);
	}

	/* =========================================================
	   DATA COLLECTION HELPERS
	   ========================================================= */

	private function get_all_experts() {
		$users = get_users(['role' => 'cptt_expert', 'orderby' => 'display_name', 'order' => 'ASC']);
		$admins = get_users(['role' => 'administrator', 'orderby' => 'display_name', 'order' => 'ASC']);
		$all = array_merge($users, $admins);
		$seen = [];
		$out = [];
		foreach ($all as $u) {
			if (isset($seen[$u->ID])) continue;
			$seen[$u->ID] = true;
			$out[] = $u;
		}
		return $out;
	}

	private function get_expert_projects($user_id) {
		return get_posts([
			'post_type' => 'cptt_project',
			'post_status' => 'any',
			'numberposts' => -1,
			'meta_query' => [[
				'key' => '_cptt_experts_csv',
				'value' => ',' . (int)$user_id . ',',
				'compare' => 'LIKE',
			]],
		]);
	}

	public function collect_expert_stats($user_id) {
		$projects = $this->get_expert_projects($user_id);
		$now = (int) current_time('timestamp', true);

		$total_projects = count($projects);
		$completed = 0;
		$in_progress = 0;
		$total_steps = 0;
		$done_steps = 0;
		$overdue_steps = 0;
		$total_checklist = 0;
		$done_checklist = 0;
		$total_user_tasks = 0;
		$done_user_tasks = 0;
		$pending_user_tasks = 0;
		$total_cost = 0;
		$total_paid = 0;
		$settled_projects = 0;
		$unsettled_projects = 0;
		$overdue_projects = 0;
		$on_time_completed = 0;
		$late_completed = 0;
		$total_notes = 0;
		$step_completion_times = [];
		$project_list = [];

		foreach ($projects as $p) {
			$steps = get_post_meta($p->ID, '_cptt_steps', true);
			if (!is_array($steps)) $steps = [];

			$p_done = 0;
			$p_total = count($steps);
			$total_steps += $p_total;

			$p_cost = 0;
			$p_paid = 0;
			$p_overdue_steps = 0;

			foreach ($steps as $s) {
				$status = $s['status'] ?? 'todo';
				if ($status === 'done') {
					$done_steps++;
					$p_done++;
				}

				$due = (int)($s['due_at'] ?? 0);
				if ($due && $due < $now && $status !== 'done') {
					$overdue_steps++;
					$p_overdue_steps++;
				}

				// Checklist
				if (!empty($s['checklist']) && is_array($s['checklist'])) {
					foreach ($s['checklist'] as $ci) {
						if (empty($ci['text'])) continue;
						$total_checklist++;
						if (!empty($ci['done'])) $done_checklist++;
					}
				}

				// User Tasks
				if (!empty($s['user_tasks']) && is_array($s['user_tasks'])) {
					foreach ($s['user_tasks'] as $ut) {
						if (empty($ut['title'])) continue;
						$total_user_tasks++;
						if (!empty($ut['done'])) $done_user_tasks++;
						else $pending_user_tasks++;
					}
				}

				$p_cost += (float)($s['cost'] ?? 0);
				$p_paid += (float)($s['paid'] ?? 0);

				// Step completion time
				if ($status === 'done' && !empty($s['updated_at']) && !empty($s['due_at'])) {
					$diff = (int)$s['updated_at'] - (int)$s['due_at'];
					$step_completion_times[] = $diff;
				}
			}

			$total_cost += $p_cost;
			$total_paid += $p_paid;

			$is_complete = ($p_total > 0 && $p_done >= $p_total);
			if ($is_complete) $completed++;
			else $in_progress++;

			$is_settled = (int) get_post_meta($p->ID, '_cptt_is_settled', true);
			if ($is_settled) $settled_projects++;
			else $unsettled_projects++;

			$deadline = (int) get_post_meta($p->ID, '_cptt_deadline_at', true);
			if ($deadline && $deadline < $now && !$is_complete) $overdue_projects++;
			if ($is_complete && $deadline) {
				$last_update = (int) get_post_meta($p->ID, '_cptt_last_update', true);
				if ($last_update && $last_update <= $deadline) $on_time_completed++;
				else $late_completed++;
			}

			$notes = get_post_meta($p->ID, '_cptt_project_notes', true);
			if (is_array($notes)) {
				foreach ($notes as $note) {
					if ((int)($note['user_id'] ?? 0) === (int)$user_id) $total_notes++;
				}
			}

			$percent = $p_total ? round(($p_done / $p_total) * 100) : 0;
			$project_list[] = [
				'id' => $p->ID,
				'title' => get_the_title($p->ID),
				'progress' => $percent,
				'done_steps' => $p_done,
				'total_steps' => $p_total,
				'overdue_steps' => $p_overdue_steps,
				'cost' => $p_cost,
				'paid' => $p_paid,
				'remain' => $p_cost - $p_paid,
				'settled' => $is_settled,
				'complete' => $is_complete,
				'deadline_fa' => (string) get_post_meta($p->ID, '_cptt_deadline_at_fa', true),
				'last_update_fa' => (string) get_post_meta($p->ID, '_cptt_last_update_fa', true),
			];
		}

		$total_remain = $total_cost - $total_paid;
		$avg_step_delay = !empty($step_completion_times) ? round(array_sum($step_completion_times) / count($step_completion_times) / 3600, 1) : 0;

		$completion_rate = $total_projects > 0 ? round(($completed / $total_projects) * 100, 1) : 0;
		$checklist_rate = $total_checklist > 0 ? round(($done_checklist / $total_checklist) * 100, 1) : 0;
		$step_rate = $total_steps > 0 ? round(($done_steps / $total_steps) * 100, 1) : 0;
		$financial_rate = $total_cost > 0 ? round(($total_paid / $total_cost) * 100, 1) : 0;

		// Performance score (0-100) - weighted by available data
		$score = 0;
		$w = 0;
		if ($total_projects > 0) { $score += $completion_rate * 30; $w += 30; }
		if ($total_steps > 0) { $score += $step_rate * 25; $w += 25; }
		if ($total_checklist > 0) { $score += $checklist_rate * 15; $w += 15; }
		if ($total_cost > 0) { $score += $financial_rate * 15; $w += 15; }
		$on_time_rate = ($on_time_completed + $late_completed) > 0 ? ($on_time_completed / ($on_time_completed + $late_completed)) * 100 : 0;
		if (($on_time_completed + $late_completed) > 0) { $score += $on_time_rate * 15; $w += 15; }
		$score = $w > 0 ? round($score / $w, 1) : 0;
		$score = round(min(100, max(0, $score)), 1);

		return [
			'user_id' => (int)$user_id,
			'total_projects' => $total_projects,
			'completed' => $completed,
			'in_progress' => $in_progress,
			'total_steps' => $total_steps,
			'done_steps' => $done_steps,
			'overdue_steps' => $overdue_steps,
			'total_checklist' => $total_checklist,
			'done_checklist' => $done_checklist,
			'total_user_tasks' => $total_user_tasks,
			'done_user_tasks' => $done_user_tasks,
			'pending_user_tasks' => $pending_user_tasks,
			'total_cost' => $total_cost,
			'total_paid' => $total_paid,
			'total_remain' => $total_remain,
			'settled_projects' => $settled_projects,
			'unsettled_projects' => $unsettled_projects,
			'overdue_projects' => $overdue_projects,
			'on_time_completed' => $on_time_completed,
			'late_completed' => $late_completed,
			'completion_rate' => $completion_rate,
			'step_rate' => $step_rate,
			'checklist_rate' => $checklist_rate,
			'financial_rate' => $financial_rate,
			'on_time_rate' => round($on_time_rate, 1),
			'avg_step_delay_hours' => $avg_step_delay,
			'total_notes' => $total_notes,
			'score' => $score,
			'projects' => $project_list,
		];
	}

	/* =========================================================
	   AJAX for Expert Dashboard
	   ========================================================= */
	public function ajax_expert_analytics() {
		if (!is_user_logged_in()) wp_send_json_error('login', 401);
		check_ajax_referer('cptt_expert_nonce', 'nonce');
		$user_id = get_current_user_id();
		$stats = $this->collect_expert_stats($user_id);
		wp_send_json_success($stats);
	}

	/* =========================================================
	   ADMIN PAGE - All Experts Analytics
	   ========================================================= */
	public function render_admin_page() {
		if (!current_user_can('edit_cptt_projects')) return;

		$experts = $this->get_all_experts();
		$current_user = wp_get_current_user();
		$is_admin = in_array('administrator', (array)$current_user->roles, true);

		// Collect stats for accessible experts
		$all_stats = [];
		if ($is_admin) {
			foreach ($experts as $expert) {
				$stats = $this->collect_expert_stats($expert->ID);
				$stats['display_name'] = $expert->display_name;
				$stats['user_email'] = $expert->user_email;
				$all_stats[] = $stats;
			}
		} else {
			$stats = $this->collect_expert_stats($current_user->ID);
			$stats['display_name'] = $current_user->display_name;
			$stats['user_email'] = $current_user->user_email;
			$all_stats[] = $stats;
		}

		// Global totals
		$g_projects = 0; $g_completed = 0; $g_steps = 0; $g_done_steps = 0;
		$g_overdue = 0; $g_cost = 0; $g_paid = 0; $g_notes = 0;
		foreach ($all_stats as $s) {
			$g_projects += $s['total_projects'];
			$g_completed += $s['completed'];
			$g_steps += $s['total_steps'];
			$g_done_steps += $s['done_steps'];
			$g_overdue += $s['overdue_steps'];
			$g_cost += $s['total_cost'];
			$g_paid += $s['total_paid'];
			$g_notes += $s['total_notes'];
		}
		$g_remain = $g_cost - $g_paid;

		?>
		<div class="wrap cptt-analytics-wrap" dir="rtl">
			<style>
				.cptt-analytics-wrap { font-family: inherit; }
				.cptt-an-hero { background: linear-gradient(135deg, #4f46e5, #7c3aed, #ec4899); border-radius: 20px; padding: 28px; color: #fff; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; }
				.cptt-an-hero h1 { margin: 0; font-size: 22px; font-weight: 950; }
				.cptt-an-hero p { margin: 6px 0 0; opacity: 0.85; font-size: 13px; }
				.cptt-an-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 24px; }
				.cptt-an-kpi { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 18px; text-align: center; box-shadow: 0 4px 12px rgba(0,0,0,0.04); position: relative; overflow: hidden; }
				.cptt-an-kpi::before { content: ''; position: absolute; top: -20px; right: -20px; width: 60px; height: 60px; border-radius: 50%; opacity: 0.15; }
				.cptt-an-kpi:nth-child(1)::before { background: #6366f1; }
				.cptt-an-kpi:nth-child(2)::before { background: #22c55e; }
				.cptt-an-kpi:nth-child(3)::before { background: #f59e0b; }
				.cptt-an-kpi:nth-child(4)::before { background: #ef4444; }
				.cptt-an-kpi:nth-child(5)::before { background: #3b82f6; }
				.cptt-an-kpi:nth-child(6)::before { background: #8b5cf6; }
				.cptt-an-kpi__icon { font-size: 28px; margin-bottom: 8px; }
				.cptt-an-kpi__value { font-size: 26px; font-weight: 950; color: #0f172a; line-height: 1.2; }
				.cptt-an-kpi__label { font-size: 12px; color: #64748b; font-weight: 700; margin-top: 4px; }

				.cptt-an-picker{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:18px 0}.cptt-an-pick-card{border:1px solid #e0e7ff;background:#fff;border-radius:16px;padding:12px;display:flex;align-items:center;gap:10px;cursor:pointer;box-shadow:0 5px 16px rgba(15,23,42,.05)}.cptt-an-pick-card.is-active{background:linear-gradient(135deg,#eef2ff,#f5f3ff);border-color:#6366f1}.cptt-an-pick-card img{width:42px;height:42px;border-radius:50%;object-fit:cover}.cptt-an-experts { display: grid; gap: 20px; }
				.cptt-an-expert-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 20px; padding: 22px; box-shadow: 0 6px 18px rgba(0,0,0,0.04); }
				.cptt-an-expert-header { display: flex; align-items: center; gap: 16px; margin-bottom: 18px; padding-bottom: 16px; border-bottom: 1px solid #f1f5f9; flex-wrap: wrap; }
				.cptt-an-expert-header h3 { margin: 0; font-size: 18px; font-weight: 950; color: #0f172a; }
				.cptt-an-expert-header small { color: #64748b; font-size: 12px; }

				.cptt-an-score { display: inline-flex; align-items: center; justify-content: center; width: 56px; height: 56px; border-radius: 50%; font-size: 18px; font-weight: 950; color: #fff; flex-shrink: 0; }
				.cptt-an-score--high { background: linear-gradient(135deg, #22c55e, #16a34a); }
				.cptt-an-score--mid { background: linear-gradient(135deg, #f59e0b, #d97706); }
				.cptt-an-score--low { background: linear-gradient(135deg, #ef4444, #dc2626); }

				.cptt-an-metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 16px; }
				.cptt-an-metric { background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 12px; padding: 12px; text-align: center; }
				.cptt-an-metric__val { font-size: 20px; font-weight: 950; color: #0f172a; }
				.cptt-an-metric__lbl { font-size: 11px; color: #64748b; font-weight: 700; margin-top: 3px; }

				.cptt-an-bars { display: grid; gap: 10px; margin-bottom: 16px; }
				.cptt-an-bar-item { display: grid; grid-template-columns: 120px 1fr 60px; gap: 10px; align-items: center; }
				.cptt-an-bar-item span:first-child { font-size: 12px; font-weight: 800; color: #334155; text-align: right; }
				.cptt-an-bar-track { height: 12px; background: #f1f5f9; border-radius: 999px; overflow: hidden; }
				.cptt-an-bar-fill { height: 100%; border-radius: 999px; transition: width 0.4s ease; }
				.cptt-an-bar-fill--blue { background: linear-gradient(90deg, #6366f1, #8b5cf6); }
				.cptt-an-bar-fill--green { background: linear-gradient(90deg, #22c55e, #16a34a); }
				.cptt-an-bar-fill--orange { background: linear-gradient(90deg, #f59e0b, #d97706); }
				.cptt-an-bar-fill--red { background: linear-gradient(90deg, #ef4444, #f87171); }
				.cptt-an-bar-item span:last-child { font-size: 13px; font-weight: 900; color: #0f172a; text-align: left; }

				.cptt-an-fin { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; padding: 14px; background: linear-gradient(135deg, #f8fafc, #f0f4ff); border: 1px solid #e0e8ff; border-radius: 14px; margin-bottom: 16px; }
				.cptt-an-fin-box { text-align: center; }
				.cptt-an-fin-box span { display: block; font-size: 11px; color: #64748b; font-weight: 600; }
				.cptt-an-fin-box strong { display: block; font-size: 16px; font-weight: 950; color: #0f172a; direction: ltr; }

				.cptt-an-projects { margin-top: 12px; }
				.cptt-an-projects h4 { font-size: 14px; font-weight: 900; color: #334155; margin: 0 0 10px; }
				.cptt-an-ptable { width: 100%; border-collapse: collapse; font-size: 12px; }
				.cptt-an-ptable th { background: #f8fafc; padding: 10px; text-align: right; font-weight: 900; color: #475569; border-bottom: 2px solid #e5e7eb; }
				.cptt-an-ptable td { padding: 10px; border-bottom: 1px solid #f1f5f9; color: #334155; }
				.cptt-an-ptable tr:hover td { background: #eff6ff; }
				.cptt-an-ptable .cptt-an-minibar { width: 80px; height: 8px; background: #f1f5f9; border-radius: 999px; display: inline-block; overflow: hidden; vertical-align: middle; margin-left: 6px; }
				.cptt-an-ptable .cptt-an-minibar span { display: block; height: 100%; background: linear-gradient(90deg, #6366f1, #8b5cf6); border-radius: 999px; }
				.cptt-an-badge { display: inline-flex; padding: 3px 8px; border-radius: 999px; font-size: 10px; font-weight: 900; }
				.cptt-an-badge--done { background: #dcfce7; color: #166534; }
				.cptt-an-badge--progress { background: #dbeafe; color: #1e40af; }
				.cptt-an-badge--overdue { background: #fee2e2; color: #991b1b; }

				@media (max-width: 768px) {
					.cptt-an-kpis { grid-template-columns: repeat(2, 1fr); }
					.cptt-an-bar-item { grid-template-columns: 1fr; gap: 4px; }
					.cptt-an-ptable { display: block; overflow-x: auto; }
				}
			</style>

			<div class="cptt-an-hero">
				<div>
					<h1>📊 گزارش‌گیری عملکرد کارشناسان</h1>
					<p>تحلیل جامع عملکرد، پیشرفت پروژه‌ها، حساب و کتاب و بهره‌وری تیم کارشناسی</p>
				</div>
				<div style="background:rgba(255,255,255,0.15);border-radius:14px;padding:12px 16px;max-width:320px;">
					<div style="font-size:12px;font-weight:900;margin-bottom:6px;">📐 معیار امتیازدهی:</div>
					<div style="font-size:11px;line-height:1.8;opacity:0.9;">
						تکمیل پروژه: ۳۰٪ | مراحل: ۲۵٪<br>
						چک‌لیست: ۱۵٪ | مالی: ۱۵٪ | به‌موقع: ۱۵٪
					</div>
				</div>
			</div>

			<!-- Global KPIs -->
			<div class="cptt-an-kpis">
				<div class="cptt-an-kpi"><div class="cptt-an-kpi__icon">📁</div><div class="cptt-an-kpi__value"><?php echo number_format($g_projects); ?></div><div class="cptt-an-kpi__label">کل پروژه‌ها</div></div>
				<div class="cptt-an-kpi"><div class="cptt-an-kpi__icon">✅</div><div class="cptt-an-kpi__value"><?php echo number_format($g_completed); ?></div><div class="cptt-an-kpi__label">تکمیل شده</div></div>
				<div class="cptt-an-kpi"><div class="cptt-an-kpi__icon">📋</div><div class="cptt-an-kpi__value"><?php echo number_format($g_done_steps); ?>/<?php echo number_format($g_steps); ?></div><div class="cptt-an-kpi__label">مراحل انجام‌شده</div></div>
				<div class="cptt-an-kpi"><div class="cptt-an-kpi__icon">⚠️</div><div class="cptt-an-kpi__value"><?php echo number_format($g_overdue); ?></div><div class="cptt-an-kpi__label">مراحل عقب‌افتاده</div></div>
				<div class="cptt-an-kpi"><div class="cptt-an-kpi__icon">💰</div><div class="cptt-an-kpi__value"><?php echo number_format($g_cost); ?></div><div class="cptt-an-kpi__label">کل هزینه (ریال)</div></div>
				<div class="cptt-an-kpi"><div class="cptt-an-kpi__icon"><?php echo $g_remain > 0 ? '📊' : '💚'; ?></div><div class="cptt-an-kpi__value" style="color:<?php echo $g_remain > 0 ? '#dc2626' : '#059669'; ?>"><?php echo number_format($g_remain); ?></div><div class="cptt-an-kpi__label">مانده کل (ریال)</div></div>
			</div>


			<div class="cptt-an-picker" id="cptt-an-picker">
				<?php foreach ($all_stats as $ps): $av=''; $aid=(int)get_user_meta($ps['user_id'],'cptt_expert_avatar_id',true); if($aid)$av=wp_get_attachment_image_url($aid,'thumbnail'); if(!$av)$av=get_avatar_url($ps['user_id'],['size'=>80]); ?>
				<button type="button" class="cptt-an-pick-card" data-expert="<?php echo esc_attr($ps['user_id']); ?>"><img src="<?php echo esc_url($av); ?>" alt=""><span><strong><?php echo esc_html($ps['display_name']); ?></strong><br><small>امتیاز: <?php echo esc_html($ps['score']); ?></small></span></button>
				<?php endforeach; ?>
			</div>

			<!-- Per Expert -->
			<div class="cptt-an-experts">
			<?php foreach ($all_stats as $s):
				$scoreClass = $s['score'] >= 70 ? 'high' : ($s['score'] >= 40 ? 'mid' : 'low');
			?>
				<div class="cptt-an-expert-card" style="display:none" data-expert="<?php echo esc_attr($s['user_id']); ?>">
					<div class="cptt-an-expert-header">
						<?php
						$expert_avatar = '';
						$avatar_id = (int) get_user_meta($s['user_id'], 'cptt_expert_avatar_id', true);
						if ($avatar_id) {
							$expert_avatar = wp_get_attachment_image_url($avatar_id, 'thumbnail');
						}
						if (!$expert_avatar) $expert_avatar = get_avatar_url($s['user_id'], ['size' => 80]);
						?>
						<img src="<?php echo esc_url($expert_avatar); ?>" alt="" style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:3px solid #c7d2fe;flex-shrink:0;">
						<div style="flex:1;">
							<h3><?php echo esc_html($s['display_name']); ?></h3>
							<small><?php echo esc_html($s['user_email']); ?></small>
						</div>
						<div style="text-align:center;background:<?php echo $scoreClass==='high'?'linear-gradient(135deg,#dcfce7,#f0fdf4)':($scoreClass==='mid'?'linear-gradient(135deg,#fef3c7,#fffbeb)':'linear-gradient(135deg,#fee2e2,#fef2f2)'); ?>;border:1px solid <?php echo $scoreClass==='high'?'#86efac':($scoreClass==='mid'?'#fde68a':'#fca5a5'); ?>;border-radius:14px;padding:10px 16px;min-width:80px;">
							<div style="font-size:24px;font-weight:950;color:<?php echo $scoreClass==='high'?'#15803d':($scoreClass==='mid'?'#b45309':'#dc2626'); ?>;"><?php echo esc_html($s['score']); ?></div>
							<div style="font-size:10px;font-weight:800;color:#64748b;">امتیاز از ۱۰۰</div>
						</div>
					</div>

					<!-- Performance Metrics -->
					<div class="cptt-an-metrics">
						<div class="cptt-an-metric"><div class="cptt-an-metric__val"><?php echo esc_html($s['total_projects']); ?></div><div class="cptt-an-metric__lbl">کل پروژه</div></div>
						<div class="cptt-an-metric"><div class="cptt-an-metric__val"><?php echo esc_html($s['completed']); ?></div><div class="cptt-an-metric__lbl">تکمیل شده</div></div>
						<div class="cptt-an-metric"><div class="cptt-an-metric__val"><?php echo esc_html($s['in_progress']); ?></div><div class="cptt-an-metric__lbl">در حال انجام</div></div>
						<div class="cptt-an-metric"><div class="cptt-an-metric__val"><?php echo esc_html($s['done_steps']); ?>/<?php echo esc_html($s['total_steps']); ?></div><div class="cptt-an-metric__lbl">مراحل انجام‌شده</div></div>
						<div class="cptt-an-metric"><div class="cptt-an-metric__val"><?php echo esc_html($s['overdue_steps']); ?></div><div class="cptt-an-metric__lbl">مراحل تاخیری</div></div>
						<div class="cptt-an-metric"><div class="cptt-an-metric__val"><?php echo esc_html($s['overdue_projects']); ?></div><div class="cptt-an-metric__lbl">پروژه‌های دیرکرد</div></div>
						<div class="cptt-an-metric"><div class="cptt-an-metric__val"><?php echo esc_html($s['on_time_completed']); ?></div><div class="cptt-an-metric__lbl">تکمیل به‌موقع</div></div>
						<div class="cptt-an-metric"><div class="cptt-an-metric__val"><?php echo esc_html($s['total_notes']); ?></div><div class="cptt-an-metric__lbl">یادداشت‌ها</div></div>
					</div>

					<!-- Progress Bars -->
					<div class="cptt-an-bars">
						<div class="cptt-an-bar-item">
							<span>نرخ تکمیل پروژه</span>
							<div class="cptt-an-bar-track"><div class="cptt-an-bar-fill cptt-an-bar-fill--blue" style="width:<?php echo esc_attr($s['completion_rate']); ?>%"></div></div>
							<span><?php echo esc_html($s['completion_rate']); ?>%</span>
						</div>
						<div class="cptt-an-bar-item">
							<span>پیشرفت مراحل</span>
							<div class="cptt-an-bar-track"><div class="cptt-an-bar-fill cptt-an-bar-fill--green" style="width:<?php echo esc_attr($s['step_rate']); ?>%"></div></div>
							<span><?php echo esc_html($s['step_rate']); ?>%</span>
						</div>
						<div class="cptt-an-bar-item">
							<span>چک‌لیست‌ها</span>
							<div class="cptt-an-bar-track"><div class="cptt-an-bar-fill cptt-an-bar-fill--orange" style="width:<?php echo esc_attr($s['checklist_rate']); ?>%"></div></div>
							<span><?php echo esc_html($s['checklist_rate']); ?>%</span>
						</div>
						<div class="cptt-an-bar-item">
							<span>وصول مالی</span>
							<div class="cptt-an-bar-track"><div class="cptt-an-bar-fill cptt-an-bar-fill--<?php echo $s['financial_rate'] >= 80 ? 'green' : ($s['financial_rate'] >= 50 ? 'orange' : 'red'); ?>" style="width:<?php echo esc_attr($s['financial_rate']); ?>%"></div></div>
							<span><?php echo esc_html($s['financial_rate']); ?>%</span>
						</div>
						<div class="cptt-an-bar-item">
							<span>تحویل به‌موقع</span>
							<div class="cptt-an-bar-track"><div class="cptt-an-bar-fill cptt-an-bar-fill--<?php echo $s['on_time_rate'] >= 70 ? 'green' : 'red'; ?>" style="width:<?php echo esc_attr($s['on_time_rate']); ?>%"></div></div>
							<span><?php echo esc_html($s['on_time_rate']); ?>%</span>
						</div>
					</div>

					<!-- Financial Summary -->
					<div class="cptt-an-fin">
						<div class="cptt-an-fin-box"><span>کل هزینه</span><strong><?php echo number_format($s['total_cost']); ?></strong></div>
						<div class="cptt-an-fin-box"><span>دریافتی</span><strong style="color:#059669"><?php echo number_format($s['total_paid']); ?></strong></div>
						<div class="cptt-an-fin-box"><span>مانده</span><strong style="color:<?php echo $s['total_remain'] > 0 ? '#dc2626' : '#059669'; ?>"><?php echo number_format($s['total_remain']); ?></strong></div>
						<div class="cptt-an-fin-box"><span>تسویه‌شده</span><strong><?php echo $s['settled_projects']; ?> / <?php echo $s['total_projects']; ?></strong></div>
					</div>

					<!-- Projects Table -->
					<?php if (!empty($s['projects'])): ?>
					<div class="cptt-an-projects">
						<h4>📋 لیست پروژه‌ها</h4>
						<table class="cptt-an-ptable">
							<thead>
								<tr>
									<th>پروژه</th>
									<th>پیشرفت</th>
									<th>وضعیت</th>
									<th>تاخیر</th>
									<th>هزینه</th>
									<th>مانده</th>
									<th>مهلت</th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($s['projects'] as $proj): ?>
								<tr>
									<td><a href="<?php echo esc_url(get_edit_post_link($proj['id'])); ?>"><?php echo esc_html($proj['title']); ?></a></td>
									<td>
										<div class="cptt-an-minibar"><span style="width:<?php echo esc_attr($proj['progress']); ?>%"></span></div>
										<?php echo esc_html($proj['progress']); ?>%
									</td>
									<td>
										<?php if ($proj['complete']): ?>
											<span class="cptt-an-badge cptt-an-badge--done">تکمیل</span>
										<?php else: ?>
											<span class="cptt-an-badge cptt-an-badge--progress">فعال</span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ($proj['overdue_steps'] > 0): ?>
											<span class="cptt-an-badge cptt-an-badge--overdue"><?php echo esc_html($proj['overdue_steps']); ?> مرحله</span>
										<?php else: ?>
											—
										<?php endif; ?>
									</td>
									<td style="direction:ltr;text-align:left;"><?php echo number_format($proj['cost']); ?></td>
									<td style="direction:ltr;text-align:left;color:<?php echo $proj['remain'] > 0 ? '#dc2626' : '#059669'; ?>;font-weight:900;"><?php echo number_format($proj['remain']); ?></td>
									<td><?php echo esc_html($proj['deadline_fa'] ?: '—'); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
			</div>
		</div>
		<script>document.addEventListener('click',function(e){var b=e.target.closest('.cptt-an-pick-card');if(!b)return;document.querySelectorAll('.cptt-an-pick-card').forEach(x=>x.classList.remove('is-active'));b.classList.add('is-active');var id=b.getAttribute('data-expert')||'';document.querySelectorAll('.cptt-an-expert-card').forEach(function(c){c.style.display=(id&&c.getAttribute('data-expert')===id)?'':'none';});});</script>
		<?php
	}

	/* =========================================================
	   EXPERT DASHBOARD ANALYTICS SHORTCODE SECTION
	   Returns HTML for embedding in expert dashboard
	   ========================================================= */
	public function render_expert_dashboard_analytics($user_id) {
		$s = $this->collect_expert_stats($user_id);
		$scoreClass = $s['score'] >= 70 ? 'high' : ($s['score'] >= 40 ? 'mid' : 'low');

		ob_start();
		?>
		<div class="cptt-expert-analytics-section" dir="rtl">
			<div class="cptt-expert-sectionTitle" style="margin-top:0;">📊 گزارش عملکرد من</div>

			<div style="display:flex;align-items:center;gap:14px;margin-bottom:16px;padding:16px;background:linear-gradient(135deg,#eef2ff,#f5f3ff);border-radius:16px;border:1px solid #c7d2fe;flex-wrap:wrap;">
				<div style="width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:950;color:#fff;flex-shrink:0;background:<?php echo $scoreClass==='high'?'linear-gradient(135deg,#22c55e,#16a34a)':($scoreClass==='mid'?'linear-gradient(135deg,#f59e0b,#d97706)':'linear-gradient(135deg,#ef4444,#dc2626)'); ?>;"><?php echo esc_html($s['score']); ?></div>
				<div>
					<div style="font-size:16px;font-weight:950;color:#0f172a;">امتیاز عملکرد: <?php echo esc_html($s['score']); ?> از ۱۰۰</div>
					<div style="font-size:12px;color:#64748b;margin-top:2px;">بر اساس تکمیل پروژه، مراحل، چک‌لیست، مالی و تحویل به‌موقع</div>
				</div>
			</div>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:16px;">
				<div style="background:#f8fafc;border:1px solid #f1f5f9;border-radius:12px;padding:12px;text-align:center;"><div style="font-size:22px;font-weight:950;color:#4f46e5;"><?php echo esc_html($s['total_projects']); ?></div><div style="font-size:11px;color:#64748b;font-weight:700;">کل پروژه</div></div>
				<div style="background:#f8fafc;border:1px solid #f1f5f9;border-radius:12px;padding:12px;text-align:center;"><div style="font-size:22px;font-weight:950;color:#059669;"><?php echo esc_html($s['completed']); ?></div><div style="font-size:11px;color:#64748b;font-weight:700;">تکمیل‌شده</div></div>
				<div style="background:#f8fafc;border:1px solid #f1f5f9;border-radius:12px;padding:12px;text-align:center;"><div style="font-size:22px;font-weight:950;color:#d97706;"><?php echo esc_html($s['overdue_steps']); ?></div><div style="font-size:11px;color:#64748b;font-weight:700;">تاخیر مراحل</div></div>
				<div style="background:#f8fafc;border:1px solid #f1f5f9;border-radius:12px;padding:12px;text-align:center;"><div style="font-size:22px;font-weight:950;color:#0f172a;"><?php echo esc_html($s['done_steps']); ?>/<?php echo esc_html($s['total_steps']); ?></div><div style="font-size:11px;color:#64748b;font-weight:700;">مراحل</div></div>
			</div>

			<!-- Bars -->
			<div style="display:grid;gap:8px;margin-bottom:16px;">
				<?php
				$bars = [
					['نرخ تکمیل', $s['completion_rate'], '#6366f1'],
					['پیشرفت مراحل', $s['step_rate'], '#22c55e'],
					['چک‌لیست‌ها', $s['checklist_rate'], '#f59e0b'],
					['وصول مالی', $s['financial_rate'], $s['financial_rate']>=80?'#22c55e':'#ef4444'],
					['تحویل به‌موقع', $s['on_time_rate'], $s['on_time_rate']>=70?'#22c55e':'#ef4444'],
				];
				foreach ($bars as $bar):
				?>
				<div style="display:grid;grid-template-columns:100px 1fr 50px;gap:8px;align-items:center;">
					<span style="font-size:11px;font-weight:800;color:#334155;"><?php echo esc_html($bar[0]); ?></span>
					<div style="height:10px;background:#f1f5f9;border-radius:999px;overflow:hidden;"><div style="height:100%;width:<?php echo esc_attr($bar[1]); ?>%;background:<?php echo esc_attr($bar[2]); ?>;border-radius:999px;"></div></div>
					<span style="font-size:12px;font-weight:900;color:#0f172a;"><?php echo esc_html($bar[1]); ?>%</span>
				</div>
				<?php endforeach; ?>
			</div>

			<!-- Financial -->
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:8px;padding:14px;background:linear-gradient(135deg,#f8fafc,#f0f4ff);border:1px solid #e0e8ff;border-radius:14px;">
				<div style="text-align:center;"><span style="display:block;font-size:10px;color:#64748b;">کل هزینه</span><strong style="font-size:14px;font-weight:950;"><?php echo number_format($s['total_cost']); ?></strong></div>
				<div style="text-align:center;"><span style="display:block;font-size:10px;color:#64748b;">دریافتی</span><strong style="font-size:14px;font-weight:950;color:#059669;"><?php echo number_format($s['total_paid']); ?></strong></div>
				<div style="text-align:center;"><span style="display:block;font-size:10px;color:#64748b;">مانده</span><strong style="font-size:14px;font-weight:950;color:<?php echo $s['total_remain']>0?'#dc2626':'#059669'; ?>"><?php echo number_format($s['total_remain']); ?></strong></div>
				<div style="text-align:center;"><span style="display:block;font-size:10px;color:#64748b;">تسویه</span><strong style="font-size:14px;font-weight:950;"><?php echo $s['settled_projects']; ?>/<?php echo $s['total_projects']; ?></strong></div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
