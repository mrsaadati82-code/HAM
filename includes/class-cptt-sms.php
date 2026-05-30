<?php
if ( ! defined('ABSPATH') ) exit;

class CPTT_SMS {
	private static $instance = null;
	private $option_key = 'cptt_sms_settings';
	const CRON_HOOK = 'cptt_sms_cron_check';

	public static function instance() {
		if (self::$instance === null) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action('admin_menu', [$this, 'menu']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action(self::CRON_HOOK, [$this, 'cron_check']);

		// Ensure cron exists even if plugin was updated without re-activation.
		if ( ! wp_next_scheduled(self::CRON_HOOK) ) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK);
		}
	}

	public static function activate() {
		if ( ! wp_next_scheduled(self::CRON_HOOK) ) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK);
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook(self::CRON_HOOK);
	}

	public static function get_settings() {
		$defaults = [
			'enabled' => 0,
			'webhook_url' => '',
			'admin_phones' => '',
			'user_phone_meta_keys' => 'billing_phone,mobile,phone',
			'task_reminder_interval_hours' => 24,
			'tpl_project_complete_customer' => '{client_name} عزیز، پروژه «{project_title}» با موفقیت تکمیل شد.',
			'tpl_project_complete_admin' => 'پروژه «{project_title}» برای {client_name} تکمیل شد.',
			'tpl_user_task_reminder' => '{client_name} عزیز، لطفاً تسک «{task_title}» در پروژه «{project_title}» را تکمیل کنید.',
			'tpl_deadline_missed' => 'مهلت پروژه «{project_title}» به پایان رسید و پروژه هنوز تکمیل نشده است.',
			'tpl_step_deadline_missed' => 'مهلت مرحله «{step_title}» از پروژه «{project_title}» به پایان رسید و هنوز انجام نشده است.',
		];

		$opt = get_option('cptt_sms_settings', []);
		if (!is_array($opt)) $opt = [];
		return array_merge($defaults, $opt);
	}

	public function menu() {
		add_submenu_page(
			'edit.php?post_type=cptt_project',
			'تنظیمات پیامک',
			'تنظیمات پیامک',
			'manage_options',
			'cptt-sms-settings',
			[$this, 'page']
		);
	}

	public function register_settings() {
		register_setting('cptt_sms_group', $this->option_key, [
			'sanitize_callback' => [$this, 'sanitize']
		]);
	}

	public function sanitize($in) {
		if (!is_array($in)) $in = [];
		$out = [];
		$out['enabled'] = !empty($in['enabled']) ? 1 : 0;
		$out['webhook_url'] = esc_url_raw($in['webhook_url'] ?? '');
		$out['admin_phones'] = sanitize_text_field($in['admin_phones'] ?? '');
		$out['user_phone_meta_keys'] = sanitize_text_field($in['user_phone_meta_keys'] ?? 'billing_phone,mobile,phone');
		$out['task_reminder_interval_hours'] = max(1, absint($in['task_reminder_interval_hours'] ?? 24));

		$out['tpl_project_complete_customer'] = sanitize_textarea_field($in['tpl_project_complete_customer'] ?? '');
		$out['tpl_project_complete_admin'] = sanitize_textarea_field($in['tpl_project_complete_admin'] ?? '');
		$out['tpl_user_task_reminder'] = sanitize_textarea_field($in['tpl_user_task_reminder'] ?? '');
		$out['tpl_deadline_missed'] = sanitize_textarea_field($in['tpl_deadline_missed'] ?? '');
		$out['tpl_step_deadline_missed'] = sanitize_textarea_field($in['tpl_step_deadline_missed'] ?? '');
		return $out;
	}

	public function page() {
		if (!current_user_can('manage_options')) return;
		$opt = self::get_settings();
		?>
		<div class="wrap" dir="rtl">
			<h1>تنظیمات پیامک CPTT</h1>
			<p>ارسال پیامک از طریق یک Webhook عمومی انجام می‌شود. اگر پنل پیامکی خاصی دارید، آدرس وبهوک/واسط خودتان را وارد کنید. پلاگین به این آدرس یک درخواست JSON ارسال می‌کند.</p>

			<form method="post" action="options.php">
				<?php settings_fields('cptt_sms_group'); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">فعال‌سازی ارسال پیامک</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr($this->option_key); ?>[enabled]" value="1" <?php checked(!empty($opt['enabled'])); ?>> فعال باشد</label></td>
					</tr>
					<tr>
						<th scope="row">Webhook URL</th>
						<td>
							<input type="url" class="regular-text" name="<?php echo esc_attr($this->option_key); ?>[webhook_url]" value="<?php echo esc_attr($opt['webhook_url']); ?>" placeholder="https://example.com/sms-webhook">
							<p class="description">بدنه ارسالی: <code>{phone,message,event,context}</code></p>
						</td>
					</tr>
					<tr>
						<th scope="row">شماره‌های مدیر</th>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr($this->option_key); ?>[admin_phones]" value="<?php echo esc_attr($opt['admin_phones']); ?>" placeholder="0912...,0913..."></td>
					</tr>
					<tr>
						<th scope="row">کلیدهای متای شماره کاربر</th>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr($this->option_key); ?>[user_phone_meta_keys]" value="<?php echo esc_attr($opt['user_phone_meta_keys']); ?>"><p class="description">با کاما جدا کنید. پیش‌فرض: billing_phone,mobile,phone</p></td>
					</tr>
					<tr>
						<th scope="row">فاصله یادآوری تسک‌های تکمیل‌نشده</th>
						<td><input type="number" min="1" name="<?php echo esc_attr($this->option_key); ?>[task_reminder_interval_hours]" value="<?php echo esc_attr((int)$opt['task_reminder_interval_hours']); ?>"> ساعت</td>
					</tr>
					<tr><th scope="row">متن پیامک تکمیل پروژه به مشتری</th><td><textarea class="large-text" rows="3" name="<?php echo esc_attr($this->option_key); ?>[tpl_project_complete_customer]"><?php echo esc_textarea($opt['tpl_project_complete_customer']); ?></textarea></td></tr>
					<tr><th scope="row">متن پیامک تکمیل پروژه به مدیر</th><td><textarea class="large-text" rows="3" name="<?php echo esc_attr($this->option_key); ?>[tpl_project_complete_admin]"><?php echo esc_textarea($opt['tpl_project_complete_admin']); ?></textarea></td></tr>
					<tr><th scope="row">متن یادآوری تسک مشتری</th><td><textarea class="large-text" rows="3" name="<?php echo esc_attr($this->option_key); ?>[tpl_user_task_reminder]"><?php echo esc_textarea($opt['tpl_user_task_reminder']); ?></textarea></td></tr>
					<tr><th scope="row">متن هشدار اتمام مهلت پروژه</th><td><textarea class="large-text" rows="3" name="<?php echo esc_attr($this->option_key); ?>[tpl_deadline_missed]"><?php echo esc_textarea($opt['tpl_deadline_missed']); ?></textarea></td></tr>
					<tr><th scope="row">متن هشدار اتمام مهلت مرحله</th><td><textarea class="large-text" rows="3" name="<?php echo esc_attr($this->option_key); ?>[tpl_step_deadline_missed]"><?php echo esc_textarea($opt['tpl_step_deadline_missed']); ?></textarea></td></tr>
				</table>
				<p class="description">متغیرها: <code>{site_name}</code> <code>{project_title}</code> <code>{client_name}</code> <code>{task_title}</code> <code>{step_title}</code> <code>{deadline}</code></p>
				<?php submit_button('ذخیره تنظیمات پیامک'); ?>
			</form>
		</div>
		<?php
	}

	private static function phones_from_csv($csv) {
		$phones = preg_split('/[,،\s]+/u', (string)$csv);
		$out = [];
		foreach ($phones as $p) {
			$p = trim($p);
			if ($p !== '') $out[] = $p;
		}
		return array_values(array_unique($out));
	}

	public static function get_user_phone($user_id) {
		$user_id = (int)$user_id;
		if (!$user_id) return '';
		$opt = self::get_settings();
		$keys = preg_split('/[,،\s]+/u', (string)$opt['user_phone_meta_keys']);
		foreach ($keys as $key) {
			$key = trim($key);
			if ($key === '') continue;
			$phone = trim((string)get_user_meta($user_id, $key, true));
			if ($phone !== '') return $phone;
		}
		return '';
	}

	private static function context($project_id, $extra = []) {
		$client_id = (int)get_post_meta($project_id, '_cptt_client_user_id', true);
		$client = $client_id ? get_user_by('id', $client_id) : null;
		$deadline = (string)get_post_meta($project_id, '_cptt_deadline_at_fa', true);

		$base = [
			'site_name' => get_bloginfo('name'),
			'project_title' => get_the_title($project_id),
			'client_name' => $client ? $client->display_name : '',
			'deadline' => $deadline,
		];
		return array_merge($base, $extra);
	}

	private static function render_template($tpl, $context) {
		$replace = [];
		foreach ((array)$context as $k => $v) {
			$replace['{' . $k . '}'] = (string)$v;
		}
		return strtr((string)$tpl, $replace);
	}

	public static function send_sms($phone, $message, $event = 'general', $context = []) {
		$opt = self::get_settings();
		$phone = trim((string)$phone);
		$message = trim((string)$message);
		if ($phone === '' || $message === '') return false;

		/**
		 * Fires before internal webhook sending. Developers can connect any SMS gateway here.
		 */
		do_action('cptt_sms_send', $phone, $message, $event, $context);

		if (empty($opt['enabled'])) return false;
		$url = trim((string)$opt['webhook_url']);
		if ($url === '') return false;

		$response = wp_remote_post($url, [
			'timeout' => 15,
			'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
			'body' => wp_json_encode([
				'phone' => $phone,
				'message' => $message,
				'event' => $event,
				'context' => $context,
			], JSON_UNESCAPED_UNICODE),
		]);

		if (is_wp_error($response)) {
			if (defined('WP_DEBUG') && WP_DEBUG) error_log('CPTT SMS error: ' . $response->get_error_message());
			return false;
		}

		$code = (int)wp_remote_retrieve_response_code($response);
		return $code >= 200 && $code < 300;
	}

	public static function maybe_notify_project_completed($project_id) {
		$project_id = (int)$project_id;
		if (!$project_id || get_post_type($project_id) !== 'cptt_project') return;

		$complete = class_exists('CPTT_Report') && method_exists('CPTT_Report', 'is_project_complete')
			? CPTT_Report::is_project_complete($project_id)
			: self::is_project_complete_fallback($project_id);

		if (!$complete) {
			delete_post_meta($project_id, '_cptt_complete_sms_sent');
			return;
		}

		if (get_post_meta($project_id, '_cptt_complete_sms_sent', true)) return;

		$opt = self::get_settings();
		$ctx = self::context($project_id);

		$client_id = (int)get_post_meta($project_id, '_cptt_client_user_id', true);
		$client_phone = self::get_user_phone($client_id);
		if ($client_phone) {
			self::send_sms($client_phone, self::render_template($opt['tpl_project_complete_customer'], $ctx), 'project_completed_customer', ['project_id' => $project_id] + $ctx);
		}

		foreach (self::phones_from_csv($opt['admin_phones']) as $phone) {
			self::send_sms($phone, self::render_template($opt['tpl_project_complete_admin'], $ctx), 'project_completed_admin', ['project_id' => $project_id] + $ctx);
		}

		update_post_meta($project_id, '_cptt_complete_sms_sent', (int)current_time('timestamp', true));
	}

	private static function is_project_complete_fallback($project_id) {
		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps) || empty($steps)) return false;
		foreach ($steps as $s) {
			if (($s['status'] ?? 'todo') !== 'done') return false;
		}
		return true;
	}

	public function cron_check() {
		$ids = get_posts([
			'post_type' => 'cptt_project',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields' => 'ids',
		]);

		foreach ($ids as $project_id) {
			$this->check_user_task_reminders((int)$project_id);
			$this->check_project_deadline((int)$project_id);
			$this->check_step_deadlines((int)$project_id);
		}
	}

	private function check_user_task_reminders($project_id) {
		$opt = self::get_settings();
		$now = (int)current_time('timestamp', true);
		$interval = max(1, (int)$opt['task_reminder_interval_hours']) * HOUR_IN_SECONDS;

		$client_id = (int)get_post_meta($project_id, '_cptt_client_user_id', true);
		$phone = self::get_user_phone($client_id);
		if (!$phone) return;

		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps) || empty($steps)) return;

		$changed = false;
		foreach ($steps as &$step) {
			if (empty($step['user_tasks']) || !is_array($step['user_tasks'])) continue;
			foreach ($step['user_tasks'] as &$task) {
				if (!is_array($task)) continue;
				if (!empty($task['done'])) continue;
				if (empty($task['sms_remind'])) continue;
				$due = !empty($task['due_at']) ? (int)$task['due_at'] : 0;
				if (!$due || $due > $now) continue;

				$last = !empty($task['last_reminder_at']) ? (int)$task['last_reminder_at'] : 0;
				if ($last && ($now - $last) < $interval) continue;

				$ctx = self::context($project_id, [
					'task_title' => (string)($task['title'] ?? ''),
				]);
				self::send_sms($phone, self::render_template($opt['tpl_user_task_reminder'], $ctx), 'user_task_reminder', ['project_id'=>$project_id, 'task_id'=>($task['id'] ?? '')] + $ctx);
				$task['last_reminder_at'] = $now;
				$task['last_reminder_at_fa'] = class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now);
				$changed = true;
			}
			unset($task);
		}
		unset($step);

		if ($changed) update_post_meta($project_id, '_cptt_steps', $steps);
	}

	private function check_project_deadline($project_id) {
		$deadline = (int)get_post_meta($project_id, '_cptt_deadline_at', true);
		if (!$deadline) return;
		if ($deadline > (int)current_time('timestamp', true)) return;
		if (get_post_meta($project_id, '_cptt_deadline_sms_sent', true)) return;

		$complete = class_exists('CPTT_Report') && method_exists('CPTT_Report', 'is_project_complete')
			? CPTT_Report::is_project_complete($project_id)
			: self::is_project_complete_fallback($project_id);
		if ($complete) return;

		$opt = self::get_settings();
		$ctx = self::context($project_id);
		$message = self::render_template($opt['tpl_deadline_missed'], $ctx);

		$phones = $this->responsible_phones($project_id);

		foreach ($phones as $phone) {
			self::send_sms($phone, $message, 'project_deadline_missed', ['project_id'=>$project_id] + $ctx);
		}

		update_post_meta($project_id, '_cptt_deadline_sms_sent', (int)current_time('timestamp', true));
	}
	private function responsible_phones($project_id) {
		$opt = self::get_settings();
		$phones = [];
		if (class_exists('CPTT_Core') && method_exists('CPTT_Core', 'get_project_expert_ids')) {
			foreach (CPTT_Core::get_project_expert_ids($project_id) as $uid) {
				$p = self::get_user_phone($uid);
				if ($p) $phones[] = $p;
			}
		}
		$phones = array_merge($phones, self::phones_from_csv($opt['admin_phones']));
		return array_values(array_unique(array_filter($phones)));
	}

	private function check_step_deadlines($project_id) {
		$now = (int)current_time('timestamp', true);
		$steps = get_post_meta($project_id, '_cptt_steps', true);
		if (!is_array($steps) || empty($steps)) return;

		$complete = class_exists('CPTT_Report') && method_exists('CPTT_Report', 'is_project_complete')
			? CPTT_Report::is_project_complete($project_id)
			: self::is_project_complete_fallback($project_id);
		if ($complete) return;

		$opt = self::get_settings();
		$changed = false;
		foreach ($steps as &$step) {
			if (!is_array($step)) continue;
			if (($step['status'] ?? 'todo') === 'done') continue;
			$due = !empty($step['due_at']) ? (int)$step['due_at'] : 0;
			if (!$due || $due > $now) continue;
			if (!empty($step['deadline_sms_sent'])) continue;

			$ctx = self::context($project_id, [
				'step_title' => (string)($step['title'] ?? ''),
				'deadline' => (string)($step['due_at_fa'] ?? ''),
			]);
			$message = self::render_template($opt['tpl_step_deadline_missed'], $ctx);
			foreach ($this->responsible_phones($project_id) as $phone) {
				self::send_sms($phone, $message, 'step_deadline_missed', ['project_id'=>$project_id, 'step_id'=>($step['id'] ?? '')] + $ctx);
			}
			$step['deadline_sms_sent'] = $now;
			$step['deadline_sms_sent_fa'] = class_exists('CPTT_Core') ? CPTT_Core::jalali_datetime($now) : date('Y-m-d H:i', $now);
			$changed = true;
		}
		unset($step);

		if ($changed) update_post_meta($project_id, '_cptt_steps', $steps);
	}

}
