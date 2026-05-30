<?php
if ( ! defined('ABSPATH') ) exit;

class CPTT_Settings {
	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action('admin_menu', [$this, 'menu']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('admin_enqueue_scripts', [$this, 'assets']);
		add_action('wp_head', [$this, 'inject_dynamic_css'], 5);
		add_action('admin_head', [$this, 'inject_dynamic_css'], 5);
	}

	public static function get() {
		$opt = get_option('cptt_branding', []);
		return array_merge([
			'brand_name'    => get_bloginfo('name'),
			'site_url'      => home_url('/'),
			'primary_color' => '#6366f1',
			'footer_text'   => 'این گزارش به صورت خودکار تولید شده است.',
			'logo_id'       => 0,
			'sign_id'       => 0,
			'stamp_id'      => 0,
			'manager_name'  => '',
			'manager_title' => '',
		], is_array($opt) ? $opt : []);
	}

	public static function get_branding_toggles() {
		$opt = get_option('cptt_branding_toggles', []);
		return array_merge([
			'report_show_logo' => '1',
			'report_show_client' => '1',
			'report_show_experts' => '1',
			'report_show_dates' => '1',
			'report_show_checklist' => '1',
			'report_show_usertasks' => '1',
			'report_show_sign_stamp' => '1',
			
			'invoice_show_logo' => '1',
			'invoice_show_client' => '1',
			'invoice_show_experts' => '1',
			'invoice_show_dates' => '1',
			'invoice_show_sign_stamp' => '1',
			'invoice_show_step_breakdown' => '1',
		], is_array($opt) ? $opt : []);
	}

	public static function get_styles() {
		$opt = get_option('cptt_styles', []);
		return array_merge([
			'primary_color'   => '#6366f1',
			'secondary_color' => '#8b5cf6',
			'success_color'   => '#22c55e',
			'warning_color'   => '#f59e0b',
			'danger_color'    => '#ef4444',
			'bg_color'        => '#f8fbff',
			'text_color'      => '#1e293b',
			'border_radius'   => '16',
			'font_size_base'  => '13',
			'label_size'      => '14',
			'font_family'     => 'dana', 
		], is_array($opt) ? $opt : []);
	}

	public static function get_fields_visibility() {
		$opt = get_option('cptt_fields', []);
		return array_merge([
			'client' => '1',
			'category' => '1',
			'product' => '1',
			'experts' => '1',
			'template' => '1',
			'deadline' => '1',
			'delivery' => '1',
			'financial' => '1',
		], is_array($opt) ? $opt : []);
	}

	public function menu() {
		add_submenu_page('edit.php?post_type=cptt_project', 'تنظیمات CPTT', 'تنظیمات افزونه', 'manage_options', 'cptt-settings', [$this, 'page']);
	}

	public function register_settings() {
		register_setting('cptt_settings_group', 'cptt_branding');
		register_setting('cptt_settings_group', 'cptt_styles');
		register_setting('cptt_settings_group', 'cptt_advanced');
		register_setting('cptt_settings_group', 'cptt_fields');
		register_setting('cptt_settings_group', 'cptt_branding_toggles');
		register_setting('cptt_settings_group', 'cptt_bale_settings');
		register_setting('cptt_settings_group', 'cptt_currency_settings', [
			'sanitize_callback' => function($val){
				if (!is_array($val)) $val = [];
				$val['unit'] = class_exists('CPTT_Currency') ? CPTT_Currency::sanitize_unit($val['unit'] ?? 'toman') : 'toman';
				$val['usd_rate'] = max(1, (float)($val['usd_rate'] ?? 700000));
				$val['eur_rate'] = max(1, (float)($val['eur_rate'] ?? 760000));
				$val['decimals'] = max(0, min(4, (int)($val['decimals'] ?? 0)));
				return $val;
			},
		]);
	}

	public function assets($hook) {
		if (strpos($hook, 'cptt-settings') === false) return;
		wp_enqueue_media();
		wp_enqueue_style('wp-color-picker');
		wp_enqueue_script('cptt-settings-js', CPTT_URL . 'assets/js/settings.js', ['jquery', 'wp-color-picker'], CPTT_VERSION, true);
		wp_enqueue_style('cptt-settings-css', CPTT_URL . 'assets/css/settings.css', [], CPTT_VERSION);
	}

	public function inject_dynamic_css() {
		$s = self::get_styles();
		$adv = get_option('cptt_advanced', []);
		$base = CPTT_URL . 'assets/fonts/';
		
		$f = $s['font_family'];
		$faces = '';
		$font_stack = '';

		if ($f === 'dana') {
			$faces = "
				@font-face { font-family: 'Dana'; src: url('{$base}Dana/Dana-FaNum-Regular.ttf') format('truetype'); font-weight: 400; }
				@font-face { font-family: 'Dana'; src: url('{$base}Dana/Dana-FaNum-Medium.ttf') format('truetype'); font-weight: 500; }
				@font-face { font-family: 'Dana'; src: url('{$base}Dana/Dana-FaNum-Bold.ttf') format('truetype'); font-weight: 700; }
			";
			$font_stack = "'Dana', Tahoma, sans-serif";
		} elseif ($f === 'iransans') {
			$faces = "
				@font-face { font-family: 'IranSans'; src: url('{$base}iransans/IRANSansWeb(FaNum).woff2') format('woff2'); font-weight: 400; }
				@font-face { font-family: 'IranSans'; src: url('{$base}iransans/IRANSansWeb(FaNum)_Medium.woff2') format('woff2'); font-weight: 500; }
				@font-face { font-family: 'IranSans'; src: url('{$base}iransans/IRANSansWeb(FaNum)_Bold.woff2') format('woff2'); font-weight: 700; }
			";
			$font_stack = "'IranSans', Tahoma, sans-serif";
		} elseif ($f === 'iranyekan') {
			$faces = "
				@font-face { font-family: 'IranYekan'; src: url('{$base}iranyekan/IRANYekanX-Regular.woff2') format('woff2'); font-weight: 400; }
				@font-face { font-family: 'IranYekan'; src: url('{$base}iranyekan/IRANYekanX-Bold.woff2') format('woff2'); font-weight: 700; }
			";
			$font_stack = "'IranYekan', Tahoma, sans-serif";
		} elseif ($f === 'kalameh') {
			$faces = "
				@font-face { font-family: 'Kalameh'; src: url('{$base}kalameh/KalamehWebFaNum-Medium.woff2') format('woff2'); font-weight: 400; }
				@font-face { font-family: 'Kalameh'; src: url('{$base}kalameh/KalamehWebFaNum-Bold.woff2') format('woff2'); font-weight: 700; }
			";
			$font_stack = "'Kalameh', Tahoma, sans-serif";
		} elseif ($f === 'peyda') {
			$faces = "
				@font-face { font-family: 'Peyda'; src: url('{$base}peyda/PeydaWeb-Regular.woff') format('woff'); font-weight: 400; }
				@font-face { font-family: 'Peyda'; src: url('{$base}peyda/PeydaWeb-Bold.woff') format('woff'); font-weight: 700; }
			";
			$font_stack = "'Peyda', Tahoma, sans-serif";
		} else {
			$font_stack = "'Vazirmatn', Tahoma, sans-serif";
		}

		echo '<style id="cptt-v2-absolute-isolation">
			' . $faces . '
			body.cptt-v2-scope, .cptt-v2-scope {
				--cptt-primary: ' . esc_attr($s['primary_color']) . ';
				--cptt-secondary: ' . esc_attr($s['secondary_color']) . ';
				--cptt-radius: ' . esc_attr($s['border_radius']) . 'px;
				--cptt-font-main: ' . $font_stack . ' !important;
				font-family: var(--cptt-font-main) !important;
				background-color: ' . esc_attr($s['bg_color']) . ' !important;
				font-size: ' . esc_attr($s['font_size_base']) . 'px !important;
				color: ' . esc_attr($s['text_color']) . ' !important;
			}
			body.cptt-v2-scope *, body.cptt-v2-scope *::before, body.cptt-v2-scope *::after {
				font-family: var(--cptt-font-main) !important;
				box-sizing: border-box !important;
			}
			body.cptt-v2-scope .cptt-btn, 
			body.cptt-v2-scope .cptt-btn-primary, 
			body.cptt-v2-scope .cptt-newProjectCta {
				background: linear-gradient(135deg, var(--cptt-primary), var(--cptt-secondary)) !important;
				color: #ffffff !important;
				border-radius: var(--cptt-radius) !important;
				border: none !important;
				padding: 10px 24px !important;
				font-weight: 700 !important;
				font-size: ' . esc_attr($s['font_size_base']) . 'px !important;
				min-height: 44px !important;
				display: inline-flex !important;
				align-items: center !important;
				justify-content: center !important;
				box-shadow: 0 10px 25px rgba(0,0,0,0.1) !important;
				cursor: pointer !important;
			}
			body.cptt-v2-scope input[type="text"], 
			body.cptt-v2-scope input[type="url"], 
			body.cptt-v2-scope select, 
			body.cptt-v2-scope textarea {
				background: #ffffff !important;
				border: 1px solid #d1d5db !important;
				border-radius: var(--cptt-radius) !important;
				padding: 10px 14px !important;
				font-size: ' . esc_attr($s['font_size_base']) . 'px !important;
				height: auto !important;
				min-height: 44px !important;
				box-shadow: none !important;
			}
			body.cptt-v2-scope label span, 
			body.cptt-v2-scope .cptt-expert-sectionTitle {
				font-size: ' . esc_attr($s['label_size']) . 'px !important;
				font-weight: 700 !important;
				margin-bottom: 8px !important;
				display: block !important;
				color: #475569 !important;
			}

			/* Robust override rules for specific classes */
			body.cptt-v2-scope .cptt-notification-bell,
			.cptt-notification-bell {
				position: relative !important;
				margin: 0 !important;
				padding: 0 !important;
				border: none !important;
				background: transparent !important;
				box-shadow: none !important;
				width: auto !important;
				height: auto !important;
				display: inline-flex !important;
			}
			body.cptt-v2-scope .cptt-bell-btn,
			.cptt-bell-btn {
				background: #f8fafc !important;
				border: 1px solid #cbd5e1 !important;
				border-radius: 50% !important;
				width: 42px !important;
				height: 42px !important;
				display: flex !important;
				align-items: center !important;
				justify-content: center !important;
				cursor: pointer !important;
				position: relative !important;
				color: #475569 !important;
				padding: 0 !important;
				margin: 0 !important;
				box-shadow: none !important;
				outline: none !important;
				min-width: 0 !important;
				min-height: 0 !important;
			}
			body.cptt-v2-scope .cptt-expert-steps,
			.cptt-expert-steps {
				display: grid !important;
				gap: 10px !important;
				margin: 0 !important;
				padding: 0 !important;
				background: transparent !important;
				border: none !important;
			}
			body.cptt-v2-scope .cptt-expert-step,
			.cptt-expert-step {
				background: #ffffff !important;
				border: 1px solid #e6ebf2 !important;
				border-radius: 16px !important;
				overflow: hidden !important;
				transition: box-shadow 0.2s ease, border-color 0.2s ease !important;
				margin-bottom: 10px !important;
			}
			body.cptt-v2-scope .cptt-expert-step.is-open,
			.cptt-expert-step.is-open {
				box-shadow: 0 12px 30px rgba(99, 102, 241, 0.12) !important;
				border-color: #c7d2fe !important;
			}
			body.cptt-v2-scope .cptt-expert-step__toggle,
			.cptt-expert-step__toggle {
				width: 100% !important;
				border: none !important;
				background: linear-gradient(135deg, #f8fafc, #ffffff) !important;
				padding: 14px 16px !important;
				cursor: pointer !important;
				display: flex !important;
				align-items: center !important;
				justify-content: space-between !important;
				gap: 12px !important;
				text-align: right !important;
				transition: background 0.15s ease !important;
				min-height: 0 !important;
				line-height: normal !important;
				font-size: 13px !important;
				box-shadow: none !important;
				margin: 0 !important;
				border-radius: 0 !important;
				outline: none !important;
				text-transform: none !important;
			}
			body.cptt-v2-scope .cptt-expert-step.is-open .cptt-expert-step__toggle,
			.cptt-expert-step.is-open .cptt-expert-step__toggle {
				background: linear-gradient(135deg, #eef2ff, #f5f3ff) !important;
			}
			body.cptt-v2-scope .cptt-emoji-btn,
			.cptt-emoji-btn {
				background: transparent !important;
				border: 1px solid transparent !important;
				border-radius: 6px !important;
				cursor: pointer !important;
				padding: 4px !important;
				transition: transform 0.15s ease !important;
				min-width: 0 !important;
				min-height: 0 !important;
				box-shadow: none !important;
				outline: none !important;
				margin: 0 !important;
				display: inline-flex !important;
			}
			body.cptt-v2-scope .cptt-emoji-btn:hover,
			.cptt-emoji-btn:hover {
				transform: scale(1.2) !important;
				border-color: #cbd5e1 !important;
				background: #f8fafc !important;
			}
			body.cptt-v2-scope .cptt-emoji-picker,
			.cptt-emoji-picker {
				display: flex !important;
				gap: 6px !important;
				margin-bottom: 8px !important;
				overflow-x: auto !important;
				padding-bottom: 4px !important;
				border: none !important;
				background: transparent !important;
			}
			body.cptt-v2-scope .cptt-expert-add-checkitem,
			body.cptt-v2-scope .cptt-expert-add-usertask,
			.cptt-expert-add-checkitem,
			.cptt-expert-add-usertask {
				background: #f1f5f9 !important;
				color: #475569 !important;
				border: 1px solid #cbd5e1 !important;
				border-radius: 8px !important;
				padding: 8px 16px !important;
				font-size: 12px !important;
				font-weight: 700 !important;
				cursor: pointer !important;
				display: inline-flex !important;
				align-items: center !important;
				justify-content: center !important;
				box-shadow: none !important;
				margin-top: 10px !important;
				min-height: 32px !important;
				height: auto !important;
				transition: all 0.15s ease !important;
				outline: none !important;
			}
			body.cptt-v2-scope .cptt-expert-add-checkitem:hover,
			body.cptt-v2-scope .cptt-expert-add-usertask:hover,
			.cptt-expert-add-checkitem:hover,
			.cptt-expert-add-usertask:hover {
				background: #e2e8f0 !important;
				color: #0f172a !important;
				border-color: #94a3b8 !important;
			}
			body.cptt-v2-scope .cptt-expert-remove-checkitem,
			body.cptt-v2-scope .cptt-expert-remove-usertask,
			.cptt-expert-remove-checkitem,
			.cptt-expert-remove-usertask {
				color: #ef4444 !important;
				background: #fee2e2 !important;
				border: none !important;
				border-radius: 50% !important;
				width: 24px !important;
				height: 24px !important;
				display: inline-flex !important;
				align-items: center !important;
				justify-content: center !important;
				font-size: 14px !important;
				font-weight: bold !important;
				cursor: pointer !important;
				padding: 0 !important;
				margin: 0 !important;
				min-width: 0 !important;
				min-height: 0 !important;
				box-shadow: none !important;
				transition: all 0.15s ease !important;
				outline: none !important;
			}
			body.cptt-v2-scope .cptt-expert-remove-checkitem:hover,
			body.cptt-v2-scope .cptt-expert-remove-usertask:hover,
			.cptt-expert-remove-checkitem:hover,
			.cptt-expert-remove-usertask:hover {
				background: #fecaca !important;
				color: #b91c1c !important;
			}
			body.cptt-v2-scope .cptt-direct-chat-modal__close,
			body.cptt-v2-scope .cptt-newProjectModal__close,
			body.cptt-v2-scope .cptt-experts-mobile-modal__close,
			body.cptt-v2-scope .cptt-expert-chatModal__close,
			.cptt-direct-chat-modal__close,
			.cptt-newProjectModal__close,
			.cptt-experts-mobile-modal__close,
			.cptt-expert-chatModal__close {
				position: absolute !important;
				top: 16px !important;
				right: 16px !important;
				width: 32px !important;
				height: 32px !important;
				border: none !important;
				background: #f0f0f1 !important;
				border-radius: 50% !important;
				display: flex !important;
				align-items: center !important;
				justify-content: center !important;
				cursor: pointer !important;
				color: #646970 !important;
				font-size: 20px !important;
				font-weight: bold !important;
				line-height: 1 !important;
				padding: 0 !important;
				margin: 0 !important;
				box-shadow: none !important;
				outline: none !important;
				min-width: 0 !important;
				min-height: 0 !important;
				text-shadow: none !important;
				text-transform: none !important;
			}
			body.cptt-v2-scope .cptt-direct-chat-modal__close:hover,
			body.cptt-v2-scope .cptt-newProjectModal__close:hover,
			body.cptt-v2-scope .cptt-experts-mobile-modal__close:hover,
			body.cptt-v2-scope .cptt-expert-chatModal__close:hover,
			.cptt-direct-chat-modal__close:hover,
			.cptt-newProjectModal__close:hover,
			.cptt-experts-mobile-modal__close:hover,
			.cptt-expert-chatModal__close:hover {
				background: #dcdcde !important;
				color: #d63638 !important;
			}
			body.cptt-v2-scope .cptt-mobile-fab,
			.cptt-mobile-fab {
				position: fixed !important;
				bottom: 20px !important;
				right: 20px !important;
				width: 60px !important;
				height: 60px !important;
				border-radius: 50% !important;
				background: #4f46e5 !important;
				color: #ffffff !important;
				border: none !important;
				box-shadow: 0 10px 25px rgba(79, 70, 229, 0.4) !important;
				z-index: 9999999 !important;
				cursor: pointer !important;
				display: flex !important;
				align-items: center !important;
				justify-content: center !important;
				padding: 0 !important;
				margin: 0 !important;
				min-width: 0 !important;
				min-height: 0 !important;
				font-size: 24px !important;
				outline: none !important;
			}
			body.cptt-v2-scope .cptt-jdp,
			.cptt-jdp {
				z-index: 100000000000 !important;
			}
			/* Handle desktop-only and mobile-only buttons consistently */
			@media (max-width: 980px) {
				body.cptt-v2-scope .desktop-only,
				.desktop-only {
					display: none !important;
				}
				body.cptt-v2-scope .mobile-only,
				.mobile-only {
					display: block !important;
				}
				body.cptt-v2-scope button.mobile-only,
				button.mobile-only {
					display: inline-flex !important;
				}
			}
			@media (min-width: 981px) {
				body.cptt-v2-scope .mobile-only,
				.mobile-only {
					display: none !important;
				}
				body.cptt-v2-scope .desktop-only,
				.desktop-only {
					display: block !important;
				}
			}

			/* Robust Dark/Light mode SVG icons toggling */
			body:not(.cptt-dark) .cptt-dark-toggle-icon .cptt-svg-moon {
				display: block !important;
			}
			body:not(.cptt-dark) .cptt-dark-toggle-icon .cptt-svg-sun {
				display: none !important;
			}
			body.cptt-dark .cptt-dark-toggle-icon .cptt-svg-moon {
				display: none !important;
			}
			body.cptt-dark .cptt-dark-toggle-icon .cptt-svg-sun {
				display: block !important;
			}

			/* Control bar layout next to each other */
			body.cptt-v2-scope .cptt-sidebar-controls {
				display: flex !important;
				flex-direction: row !important;
				justify-content: space-between !important;
				align-items: center !important;
				gap: 12px !important;
			}

			/* Robust Dark Mode overrides */
			body.cptt-dark,
			body.cptt-dark .cptt-wrap,
			body.cptt-dark .cptt-v2-scope,
			.cptt-v2-scope.cptt-dark,
			body.cptt-dark .cptt-expertSidebar,
			body.cptt-dark .cptt-sideBox,
			body.cptt-dark .cptt-kpiCard,
			body.cptt-dark .cptt-insightBox,
			body.cptt-dark .cptt-expertCard,
			body.cptt-dark .cptt-expert-step,
			body.cptt-dark .cptt-expertFilters,
			body.cptt-dark .cptt-expert-projectMeta,
			body.cptt-dark .cptt-newProjectModal__dialog,
			body.cptt-dark .cptt-expert-chatModal__dialog,
			body.cptt-dark .cptt-direct-chat-modal__dialog,
			body.cptt-dark .cptt-expertsDirectory,
			body.cptt-dark .cptt-hubProjects,
			body.cptt-dark .cptt-publicProject,
			body.cptt-dark .cptt-hubStep,
			body.cptt-dark .cptt-hubModal__dialog,
			body.cptt-dark .cptt-expertBadge,
			body.cptt-dark .cptt-kanban__col,
			body.cptt-dark .cptt-kanban__card {
				background-color: #1e293b !important;
				background: #1e293b !important;
				border-color: #334155 !important;
				color: #e2e8f0 !important;
			}
			body.cptt-dark .cptt-expertProfile strong,
			body.cptt-dark .cptt-expertCard__top h3,
			body.cptt-dark .cptt-sideBox__title,
			body.cptt-dark .cptt-kpiCard__value,
			body.cptt-dark .cptt-insightBox__title,
			body.cptt-dark .cptt-expert-step__toggleMain strong,
			body.cptt-dark .cptt-expert-step__metaGrid label > span,
			body.cptt-dark .cptt-expert-sectionTitle,
			body.cptt-dark .cptt-hubStep__head strong,
			body.cptt-dark .cptt-publicProject__top h3,
			body.cptt-dark .cptt-expertsHero__title,
			body.cptt-dark .cptt-hubModal__title,
			body.cptt-dark .cptt-direct-chat-info strong,
			body.cptt-dark .cptt-kanban__head,
			body.cptt-dark .cptt-kanban__cardTitle {
				color: #f8fafc !important;
			}
			body.cptt-dark .cptt-expertCard__meta,
			body.cptt-dark .cptt-expert-step__toggleMain span,
			body.cptt-dark .cptt-expert-step__metaItem > span,
			body.cptt-dark .cptt-hubStep__meta,
			body.cptt-dark .cptt-publicProject__experts,
			body.cptt-dark .cptt-hubMetaChip,
			body.cptt-dark .cptt-kanban__cardMeta {
				color: #94a3b8 !important;
			}
			body.cptt-dark .cptt-expert-step__body,
			body.cptt-dark .cptt-expert-noteField textarea,
			body.cptt-dark .cptt-createProjectGrid input,
			body.cptt-dark .cptt-createProjectGrid select,
			body.cptt-dark .cptt-expertFilters input,
			body.cptt-dark .cptt-expertFilters select,
			body.cptt-dark .cptt-expert-step__metaGrid input,
			body.cptt-dark .cptt-expert-step__metaGrid select,
			body.cptt-dark .cptt-expert-checkRow input,
			body.cptt-dark .cptt-expert-userTask input,
			body.cptt-dark .cptt-expert-userTask textarea {
				background-color: #0f172a !important;
				background: #0f172a !important;
				color: #e2e8f0 !important;
				border-color: #334155 !important;
			}
			body.cptt-dark .cptt-jdp {
				background-color: #1e293b !important;
				background: #1e293b !important;
				border-color: #334155 !important;
			}
			body.cptt-dark .cptt-jdp__head strong,
			body.cptt-dark .cptt-jdp__days button {
				color: #f1f5f9 !important;
			}
			body.cptt-dark .cptt-jdp__days button:hover {
				background: #334155 !important;
			}
			body.cptt-dark .cptt-jdp__days button.is-selected {
				background: linear-gradient(135deg,#6366f1,#8b5cf6) !important;
			}
			body.cptt-dark .cptt-notification-item {
				background: #1e293b !important;
				border-color: #334155 !important;
				color: #e2e8f0 !important;
			}
			body.cptt-dark .cptt-notification-item.is-read {
				background: #0f172a !important;
			}
			body.cptt-dark .cptt-notifications-header {
				background: #1e293b !important;
				border-bottom-color: #334155 !important;
			}
			body.cptt-dark .cptt-expert-list-item {
				background: #1e293b !important;
				border-color: #334155 !important;
			}
			body.cptt-dark .cptt-expert-list-item span {
				color: #e2e8f0 !important;
			}
			body.cptt-dark .cptt-empty,
			body.cptt-dark .cptt-dashboard__empty,
			body.cptt-dark .cptt-expert-emptyMini {
				background: #1e293b !important;
				border-color: #334155 !important;
				color: #94a3b8 !important;
			}
			body.cptt-dark .cptt-chat-bubble {
				background: #1e293b !important;
				border-color: #334155 !important;
				color: #e2e8f0 !important;
			}
			body.cptt-dark .cptt-chat-bubble__body {
				color: #e2e8f0 !important;
			}
			body.cptt-dark .cptt-newProjectModal__header {
				background: linear-gradient(135deg,#312e81,#4c1d95,#be185d) !important;
			}
			body.cptt-dark .cptt-hubChecklist li {
				background: #1e293b !important;
				border-color: #334155 !important;
			}
			body.cptt-dark .cptt-hubChecklist li.is-done {
				background: #064e3b !important;
				border-color: #065f46 !important;
			}
			body.cptt-dark .cptt-hubModal__stats div,
			body.cptt-dark .cptt-hubModal__detailsGrid div {
				background: #1e293b !important;
				border-color: #334155 !important;
			}
			body.cptt-dark .cptt-bell-btn,
			body.cptt-dark .cptt-dark-toggle-icon {
				background: #1e293b !important;
				border-color: #334155 !important;
				color: #cbd5e1 !important;
			}

			/* Robust button sizing & SVG scale overrides for Hello Elementor */
			body.cptt-v2-scope .cptt-dark-toggle-icon,
			.cptt-dark-toggle-icon {
				background: #f8fafc !important;
				border: 1px solid #cbd5e1 !important;
				border-radius: 50% !important;
				width: 42px !important;
				height: 42px !important;
				display: inline-flex !important;
				align-items: center !important;
				justify-content: center !important;
				cursor: pointer !important;
				position: relative !important;
				color: #475569 !important;
				padding: 0 !important;
				margin: 0 !important;
				box-shadow: none !important;
				outline: none !important;
				min-width: 42px !important;
				min-height: 42px !important;
				max-width: 42px !important;
				max-height: 42px !important;
			}
			body.cptt-v2-scope .cptt-dark-toggle-icon svg,
			.cptt-dark-toggle-icon svg {
				width: 22px !important;
				height: 22px !important;
				display: block !important;
				margin: 0 !important;
				padding: 0 !important;
				max-width: 22px !important;
				max-height: 22px !important;
				min-width: 22px !important;
				min-height: 22px !important;
			}
			body.cptt-dark .cptt-expertCard__details,
			body.cptt-dark .cptt-expertCard__panels,
			body.cptt-dark .cptt-expertCard__mainPanel,
			body.cptt-dark .cptt-expertCard__sidePanel {
				background-color: #1e293b !important;
				background: #1e293b !important;
			}
			body.cptt-dark .cptt-expert-step__toggle {
				background: linear-gradient(135deg, #1e293b, #111827) !important;
				border-color: #334155 !important;
			}
			body.cptt-dark .cptt-expert-step.is-open .cptt-expert-step__toggle {
				background: linear-gradient(135deg, #312e81, #1e293b) !important;
			}
			body.cptt-dark .cptt-expert-card-item {
				background: #111827 !important;
				border-color: #334155 !important;
				color: #e2e8f0 !important;
			}
			body.cptt-dark .cptt-expert-card-item span {
				color: #e2e8f0 !important;
			}
			body.cptt-dark .cptt-experts-card-list {
				background: transparent !important;
			}
			body.cptt-dark .cptt-expert-notesWrap,
			body.cptt-dark .cptt-expert-messagesWrap,
			body.cptt-dark .cptt-expert-noteItem {
				background-color: #0f172a !important;
				background: #0f172a !important;
				border-color: #334155 !important;
			}
			body.cptt-dark .cptt-expert-noteItem__head strong {
				color: #f8fafc !important;
			}
			body.cptt-dark .cptt-expert-noteItem__body {
				color: #e2e8f0 !important;
			}
			body.cptt-dark .cptt-expertFilters {
				background: #1e293b !important;
			}

			/* Robust Expert Strip Centering */
			body.cptt-v2-scope .cptt-expertsStrip,
			.cptt-expertsStrip {
				display: flex !important;
				flex-wrap: wrap !important;
				justify-content: center !important;
				gap: 14px !important;
				margin: 0 auto !important;
				padding: 0 !important;
				width: 100% !important;
			}

			/* Robust Expert Badge isolated styling */
			body.cptt-v2-scope .cptt-expertBadge,
			.cptt-expertBadge {
				appearance: none !important;
				-webkit-appearance: none !important;
				border: 1px solid #e5edf8 !important;
				background: linear-gradient(180deg,#ffffff,#f8fbff) !important;
				border-radius: 22px !important;
				padding: 16px 12px !important;
				display: flex !important;
				flex-direction: column !important;
				align-items: center !important;
				gap: 8px !important;
				cursor: pointer !important;
				box-shadow: 0 14px 28px rgba(15,23,42,.05) !important;
				transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease !important;
				width: 100% !important;
				height: auto !important;
				flex: 1 1 150px !important;
				max-width: 180px !important;
				min-width: 130px !important;
				margin: 0 !important;
				text-transform: none !important;
				line-height: normal !important;
				font-family: var(--cptt-font-main) !important;
				outline: none !important;
			}

			/* Touch Carousel on Mobile (CSS Only, ultra-smooth) */
			@media (max-width: 600px) {
				body.cptt-v2-scope .cptt-expertsStrip,
				.cptt-expertsStrip {
					display: flex !important;
					flex-wrap: nowrap !important;
					overflow-x: auto !important;
					justify-content: flex-start !important;
					padding-bottom: 12px !important;
					scroll-snap-type: x mandatory !important;
					-webkit-overflow-scrolling: touch !important;
				}
				body.cptt-v2-scope .cptt-expertBadge,
				.cptt-expertBadge {
					flex: 0 0 calc(50% - 7px) !important;
					min-width: calc(50% - 7px) !important;
					max-width: calc(50% - 7px) !important;
					scroll-snap-align: start !important;
				}
			}
			body.cptt-v2-scope .cptt-expertBadge:hover,
			.cptt-expertBadge:hover {
				transform: translateY(-3px) !important;
				box-shadow: 0 20px 36px rgba(15,23,42,.1) !important;
				border-color: #c7ddff !important;
				background: linear-gradient(180deg,#ffffff,#eff6ff) !important;
			}
			body.cptt-v2-scope .cptt-expertBadge__avatar img,
			.cptt-expertBadge__avatar img {
				width: 72px !important;
				height: 72px !important;
				border-radius: 999px !important;
				display: block !important;
				border: 3px solid #e0ecff !important;
				box-shadow: 0 10px 20px rgba(37,99,235,.12) !important;
				margin: 0 auto !important;
				object-fit: cover !important;
				max-width: none !important;
				max-height: none !important;
			}
			body.cptt-v2-scope .cptt-expertBadge__name,
			.cptt-expertBadge__name {
				font-size: 14px !important;
				font-weight: 950 !important;
				color: #0f172a !important;
				line-height: 1.7 !important;
				text-align: center !important;
				display: block !important;
				margin: 0 !important;
				padding: 0 !important;
			}
			body.cptt-v2-scope .cptt-expertBadge__title,
			.cptt-expertBadge__title {
				font-size: 11px !important;
				font-weight: 900 !important;
				color: #64748b !important;
				text-align: center !important;
				line-height: 1.8 !important;
				display: block !important;
				margin: 0 !important;
				padding: 0 !important;
			}
			body.cptt-dark .cptt-expertBadge {
				background: #1e293b !important;
				border-color: #334155 !important;
				color: #e2e8f0 !important;
			}
			body.cptt-dark .cptt-expertBadge__name {
				color: #f8fafc !important;
			}
			body.cptt-dark .cptt-expertBadge__title {
				color: #94a3b8 !important;
			}
			body.cptt-dark .cptt-expertBadge:hover {
				background: #1e293b !important;
				border-color: #475569 !important;
			}
			' . ($adv['custom_css'] ?? '') . '
		</style>';
	}

	public function page() {
		$style = self::get_styles();
		$brand = self::get();
		$tab = $_GET['tab'] ?? 'style';
		?>
		<div class="cptt-settings-wrap cptt-v2-scope" dir="rtl">
			<header class="cptt-set-header">
				<div class="cptt-set-logo"><h1>تنظیمات هماهنگ v5.4.2</h1></div>
				<button type="submit" form="cptt-settings-form" class="cptt-btn-primary">ذخیره تغییرات</button>
			</header>
			<div class="cptt-set-body">
				<aside class="cptt-set-sidebar">
					<a href="?post_type=cptt_project&page=cptt-settings&tab=style" class="<?php echo $tab==='style'?'is-active':'';?>">استایل و فونت</a>
					<a href="?post_type=cptt_project&page=cptt-settings&tab=branding" class="<?php echo $tab==='branding'?'is-active':'';?>">برندینگ</a>
					<a href="?post_type=cptt_project&page=cptt-settings&tab=currency" class="<?php echo $tab==='currency'?'is-active':'';?>">💰 واحد مالی</a>
					<a href="?post_type=cptt_project&page=cptt-settings&tab=fields" class="<?php echo $tab==='fields'?'is-active':'';?>">تنظیمات فیلدها</a>
					<a href="?post_type=cptt_project&page=cptt-settings&tab=advanced" class="<?php echo $tab==='advanced'?'is-active':'';?>">تنظیمات سیستمی</a>
					<a href="?post_type=cptt_project&page=cptt-settings&tab=bale" class="<?php echo $tab==='bale'?'is-active':'';?>">ربات بله</a>
				</aside>
				<main class="cptt-set-main">
					<form method="post" action="options.php" id="cptt-settings-form">
						<?php settings_fields('cptt_settings_group'); ?>
						<?php if ($tab === 'style'): ?>
							<div class="cptt-set-grid">
								<div class="cptt-set-field"><label>انتخاب فونت</label>
									<select name="cptt_styles[font_family]">
										<option value="dana" <?php selected($style['font_family'], 'dana');?>>دانا (Dana)</option>
										<option value="iransans" <?php selected($style['font_family'], 'iransans');?>>ایران سنس (IranSans)</option>
										<option value="iranyekan" <?php selected($style['font_family'], 'iranyekan');?>>ایران یکان (IranYekan)</option>
										<option value="kalameh" <?php selected($style['font_family'], 'kalameh');?>>کلمه (Kalameh)</option>
										<option value="peyda" <?php selected($style['font_family'], 'peyda');?>>پیدا (Peyda)</option>
										<option value="vazir" <?php selected($style['font_family'], 'vazir');?>>وزیر (Vazirmatn)</option>
									</select>
								</div>
								<div class="cptt-set-field"><label>رنگ دکمه (۱)</label><input type="text" class="cptt-color-picker" name="cptt_styles[primary_color]" value="<?php echo esc_attr($style['primary_color']); ?>" /></div>
								<div class="cptt-set-field"><label>رنگ دکمه (۲)</label><input type="text" class="cptt-color-picker" name="cptt_styles[secondary_color]" value="<?php echo esc_attr($style['secondary_color']); ?>" /></div>
								<div class="cptt-set-field"><label>گردی گوشه‌ها</label><input type="number" name="cptt_styles[border_radius]" value="<?php echo esc_attr($style['border_radius']); ?>" /></div>
								<div class="cptt-set-field"><label>سایز متن پایه</label><input type="number" name="cptt_styles[font_size_base]" value="<?php echo esc_attr($style['font_size_base']); ?>" /></div>
								<div class="cptt-set-field"><label>سایز تیتر فیلدها</label><input type="number" name="cptt_styles[label_size]" value="<?php echo esc_attr($style['label_size']); ?>" /></div>
							</div>
						<?php elseif ($tab === 'branding'): 
							$toggles = self::get_branding_toggles();
						?>
							<div class="cptt-set-grid">
								<div class="cptt-set-field">
									<label>نام برند / عنوان سایت</label>
									<input type="text" name="cptt_branding[brand_name]" value="<?php echo esc_attr($brand['brand_name']); ?>" />
								</div>
								<div class="cptt-set-field">
									<label>آدرس سایت</label>
									<input type="url" name="cptt_branding[site_url]" value="<?php echo esc_attr($brand['site_url']); ?>" />
								</div>
								<div class="cptt-set-field">
									<label>رنگ برند اصلی</label>
									<input type="text" class="cptt-color-picker" name="cptt_branding[primary_color]" value="<?php echo esc_attr($brand['primary_color']); ?>" />
								</div>
								<div class="cptt-set-field">
									<label>نام مدیر عامل / مسئول</label>
									<input type="text" name="cptt_branding[manager_name]" value="<?php echo esc_attr($brand['manager_name']); ?>" />
								</div>
								<div class="cptt-set-field">
									<label>سمت مدیر / مسئول</label>
									<input type="text" name="cptt_branding[manager_title]" value="<?php echo esc_attr($brand['manager_title']); ?>" />
								</div>
								<div class="cptt-set-field full">
									<label>متن فوتر گزارش</label>
									<input type="text" name="cptt_branding[footer_text]" value="<?php echo esc_attr($brand['footer_text']); ?>" style="width:100%;" />
								</div>

								<!-- Logo Upload -->
								<div class="cptt-set-field">
									<label>لوگو برند</label>
									<div class="cptt-media-preview" style="margin-bottom:10px; max-width:150px;">
										<?php if ($brand['logo_id']): ?>
											<img src="<?php echo esc_url(wp_get_attachment_image_url($brand['logo_id'], 'medium')); ?>" style="max-width:100%; height:auto;" />
										<?php else: ?>
											<span class="dashicons dashicons-format-image" style="font-size:40px; width:40px; height:40px;"></span>
										<?php endif; ?>
									</div>
									<input type="hidden" name="cptt_branding[logo_id]" value="<?php echo esc_attr($brand['logo_id']); ?>" />
									<button type="button" class="button cptt-media-select">انتخاب تصویر</button>
									<button type="button" class="button cptt-media-remove">حذف</button>
								</div>

								<!-- Signature Upload -->
								<div class="cptt-set-field">
									<label>تصویر امضا</label>
									<div class="cptt-media-preview" style="margin-bottom:10px; max-width:150px;">
										<?php if ($brand['sign_id']): ?>
											<img src="<?php echo esc_url(wp_get_attachment_image_url($brand['sign_id'], 'medium')); ?>" style="max-width:100%; height:auto;" />
										<?php else: ?>
											<span class="dashicons dashicons-format-image" style="font-size:40px; width:40px; height:40px;"></span>
										<?php endif; ?>
									</div>
									<input type="hidden" name="cptt_branding[sign_id]" value="<?php echo esc_attr($brand['sign_id']); ?>" />
									<button type="button" class="button cptt-media-select">انتخاب تصویر</button>
									<button type="button" class="button cptt-media-remove">حذف</button>
								</div>

								<!-- Stamp Upload -->
								<div class="cptt-set-field">
									<label>تصویر مهر</label>
									<div class="cptt-media-preview" style="margin-bottom:10px; max-width:150px;">
										<?php if ($brand['stamp_id']): ?>
											<img src="<?php echo esc_url(wp_get_attachment_image_url($brand['stamp_id'], 'medium')); ?>" style="max-width:100%; height:auto;" />
										<?php else: ?>
											<span class="dashicons dashicons-format-image" style="font-size:40px; width:40px; height:40px;"></span>
										<?php endif; ?>
									</div>
									<input type="hidden" name="cptt_branding[stamp_id]" value="<?php echo esc_attr($brand['stamp_id']); ?>" />
									<button type="button" class="button cptt-media-select">انتخاب تصویر</button>
									<button type="button" class="button cptt-media-remove">حذف</button>
								</div>
							</div>

							<!-- branding toggles (switches) -->
							<div class="cptt-settings-fields-tab" style="margin-top:40px; border-top:1px solid #cbd5e1; padding-top:20px;">
								<span style="font-size:14px;font-weight:bold;margin-bottom:15px;display:block;color:#0f172a;">تنظیمات فعال/غیرفعال‌سازی نمایش بخش‌های گزارش نهایی و پیش‌فاکتور:</span>
								
								<style>
								.cptt-switch-container {
									display: grid;
									grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
									gap: 14px;
									margin-top: 15px;
								}
								.cptt-switch-item {
									display: flex;
									justify-content: space-between;
									align-items: center;
									background: #ffffff;
									border: 1px solid #e2e8f0;
									padding: 12px 16px;
									border-radius: 12px;
									box-shadow: 0 1px 3px rgba(0,0,0,0.02);
								}
								.cptt-switch-item label {
									font-weight: 700;
									color: #334155;
									margin: 0;
								}
								.cptt-switch {
									position: relative;
									display: inline-block;
									width: 50px;
									height: 26px;
								}
								.cptt-switch input {
									opacity: 0;
									width: 0;
									height: 0;
								}
								.cptt-slider {
									position: absolute;
									cursor: pointer;
									top: 0;
									left: 0;
									right: 0;
									bottom: 0;
									background-color: #cbd5e1;
									transition: .3s;
									border-radius: 34px;
								}
								.cptt-slider:before {
									position: absolute;
									content: "";
									height: 20px;
									width: 20px;
									left: 3px;
									bottom: 3px;
									background-color: white;
									transition: .3s;
									border-radius: 50%;
								}
								.cptt-switch input:checked + .cptt-slider {
									background-color: #6366f1;
								}
								.cptt-switch input:checked + .cptt-slider:before {
									transform: translateX(24px);
								}
								</style>

								<h3 style="margin-top:20px; font-weight:900; color:#1e3a8a;">📋 گزارش نهایی پروژه</h3>
								<div class="cptt-switch-container">
									<?php 
									$rep_labels = [
										'report_show_logo' => 'نمایش لوگوی برند',
										'report_show_client' => 'نمایش نام مشتری',
										'report_show_experts' => 'نمایش نام کارشناسان',
										'report_show_dates' => 'نمایش تاریخ تولید گزارش و بروزرسانی',
										'report_show_checklist' => 'نمایش آیتم‌های چک‌لیست مراحل',
										'report_show_usertasks' => 'نمایش تسک‌های سمت مشتری',
										'report_show_sign_stamp' => 'نمایش امضا و مهر تایید نهایی',
									];
									foreach ($rep_labels as $key => $lbl):
										$val = $toggles[$key] ?? '1';
									?>
									<div class="cptt-switch-item">
										<label><?php echo esc_html($lbl); ?></label>
										<label class="cptt-switch">
											<input type="hidden" name="cptt_branding_toggles[<?php echo esc_attr($key); ?>]" value="0" />
											<input type="checkbox" name="cptt_branding_toggles[<?php echo esc_attr($key); ?>]" value="1" <?php checked($val, '1'); ?> />
											<span class="cptt-slider"></span>
										</label>
									</div>
									<?php endforeach; ?>
								</div>

								<h3 style="margin-top:30px; font-weight:900; color:#1e3a8a;">📄 پیش‌فاکتور پروژه</h3>
								<div class="cptt-switch-container">
									<?php 
									$inv_labels = [
										'invoice_show_logo' => 'نمایش لوگوی برند',
										'invoice_show_client' => 'نمایش نام مشتری و موبایل',
										'invoice_show_experts' => 'نمایش نام کارشناسان',
										'invoice_show_dates' => 'نمایش تاریخ صدور پیش‌فاکتور',
										'invoice_show_sign_stamp' => 'نمایش امضا و مهر تایید',
										'invoice_show_step_breakdown' => 'نمایش جدول ریز قیمت مراحل',
									];
									foreach ($inv_labels as $key => $lbl):
										$val = $toggles[$key] ?? '1';
									?>
									<div class="cptt-switch-item">
										<label><?php echo esc_html($lbl); ?></label>
										<label class="cptt-switch">
											<input type="hidden" name="cptt_branding_toggles[<?php echo esc_attr($key); ?>]" value="0" />
											<input type="checkbox" name="cptt_branding_toggles[<?php echo esc_attr($key); ?>]" value="1" <?php checked($val, '1'); ?> />
											<span class="cptt-slider"></span>
										</label>
									</div>
									<?php endforeach; ?>
								</div>
							</div>
						<?php elseif ($tab === 'currency'):
							$cur = class_exists('CPTT_Currency') ? CPTT_Currency::get_settings() : ['unit'=>'toman','usd_rate'=>700000,'eur_rate'=>760000,'decimals'=>0];
						?>
							<div class="cptt-set-grid">
								<div class="cptt-set-field" style="grid-column:1/-1;background:linear-gradient(135deg,#eef2ff,#fdf2f8);padding:14px;border-radius:14px;border:1px solid #c7d2fe">
									<b>💰 واحد مالی نمایش</b>
									<p style="color:#475569;font-size:12.5px;line-height:1.8;margin:6px 0 0">واحد مالی پیش‌فرض برای نمایش مبالغ در سراسر پلاگین. تمام برچسب‌های «تومان/ریال» و اعداد به‌صورت خودکار به این واحد تبدیل می‌شوند. مقادیر ذخیره‌شده در دیتابیس تغییر نمی‌کنند و فقط در نمایش تبدیل می‌شوند (بنابراین در هر زمان می‌توانید واحد را عوض کنید).</p>
								</div>
								<div class="cptt-set-field">
									<label>واحد پولی پیش‌فرض</label>
									<select name="cptt_currency_settings[unit]">
										<option value="toman" <?php selected($cur['unit'],'toman');?>>تومان (پیش‌فرض)</option>
										<option value="rial"  <?php selected($cur['unit'],'rial');?>>ریال</option>
										<option value="usd"   <?php selected($cur['unit'],'usd');?>>دلار ($)</option>
										<option value="eur"   <?php selected($cur['unit'],'eur');?>>یورو (€)</option>
									</select>
								</div>
								<div class="cptt-set-field">
									<label>نرخ هر ۱ دلار (به تومان)</label>
									<input type="number" min="1" step="any" name="cptt_currency_settings[usd_rate]" value="<?php echo esc_attr($cur['usd_rate']); ?>">
								</div>
								<div class="cptt-set-field">
									<label>نرخ هر ۱ یورو (به تومان)</label>
									<input type="number" min="1" step="any" name="cptt_currency_settings[eur_rate]" value="<?php echo esc_attr($cur['eur_rate']); ?>">
								</div>
								<div class="cptt-set-field">
									<label>تعداد ارقام اعشار (نمایش)</label>
									<input type="number" min="0" max="4" name="cptt_currency_settings[decimals]" value="<?php echo esc_attr($cur['decimals']); ?>">
								</div>
								<div class="cptt-set-field" style="grid-column:1/-1;background:#fef3c7;border:1px solid #fde68a;padding:12px;border-radius:12px">
									<b style="color:#92400e">⚙️ مثال تبدیل:</b>
									<p style="color:#78350f;font-size:12.5px;line-height:1.9;margin:6px 0 0">مبلغ ۳۵٬۰۰۰ تومان در دیتابیس:<br>
									• حالت <b>تومان</b>: ۳۵٬۰۰۰ تومان<br>
									• حالت <b>ریال</b>: ۳۵۰٬۰۰۰ ریال<br>
									• حالت <b>دلار</b> (نرخ ۷۰۰٬۰۰۰): ۰٫۰۵ دلار<br>
									• حالت <b>یورو</b> (نرخ ۷۶۰٬۰۰۰): ۰٫۰۵ یورو</p>
								</div>
							</div>
						<?php elseif ($tab === 'fields'): 
							$fields = self::get_fields_visibility();
						?>
							<div class="cptt-settings-fields-tab">
								<span style="font-size:14px;font-weight:bold;margin-bottom:15px;display:block;color:#0f172a;">با دکمه‌های سوئیچ زیر، فیلدهای فرم ایجاد پروژه را روشن یا خاموش کنید:</span>
								
								<style>
								.cptt-switch-container {
									display: grid;
									grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
									gap: 14px;
									margin-top: 15px;
								}
								.cptt-switch-item {
									display: flex;
									justify-content: space-between;
									align-items: center;
									background: #ffffff;
									border: 1px solid #e2e8f0;
									padding: 12px 16px;
									border-radius: 12px;
									box-shadow: 0 1px 3px rgba(0,0,0,0.02);
								}
								.cptt-switch-item label {
									font-weight: 700;
									color: #334155;
									margin: 0;
								}
								.cptt-switch {
									position: relative;
									display: inline-block;
									width: 50px;
									height: 26px;
								}
								.cptt-switch input {
									opacity: 0;
									width: 0;
									height: 0;
								}
								.cptt-slider {
									position: absolute;
									cursor: pointer;
									top: 0;
									left: 0;
									right: 0;
									bottom: 0;
									background-color: #cbd5e1;
									transition: .3s;
									border-radius: 34px;
								}
								.cptt-slider:before {
									position: absolute;
									content: "";
									height: 20px;
									width: 20px;
									left: 3px;
									bottom: 3px;
									background-color: white;
									transition: .3s;
									border-radius: 50%;
								}
								.cptt-switch input:checked + .cptt-slider {
									background-color: #6366f1;
								}
								.cptt-switch input:checked + .cptt-slider:before {
									transform: translateX(24px);
								}
								</style>

								<div class="cptt-switch-container">
								<?php 
								$labels = [
									'client' => 'انتخاب مشتری',
									'category' => 'انتخاب دسته‌بندی محصول',
									'product' => 'انتخاب محصول مرتبط',
									'experts' => 'انتخاب کارشناسان پروژه',
									'template' => 'انتخاب تمپلیت مراحل',
									'deadline' => 'انتخاب مهلت کل پروژه',
									'delivery' => 'انتخاب روش تحویل و آدرس',
									'financial' => 'بخش اطلاعات مالی (بودجه‌بندی کل و جزئی)',
								];
								foreach ($labels as $key => $lbl):
									$val = $fields[$key] ?? '1';
								?>
								<div class="cptt-switch-item">
									<label><?php echo esc_html($lbl); ?></label>
									<label class="cptt-switch">
										<input type="hidden" name="cptt_fields[<?php echo esc_attr($key); ?>]" value="0" />
										<input type="checkbox" name="cptt_fields[<?php echo esc_attr($key); ?>]" value="1" <?php checked($val, '1'); ?> />
										<span class="cptt-slider"></span>
									</label>
								</div>
								<?php endforeach; ?>
								</div>
							</div>
						<?php elseif ($tab === 'advanced'): ?>
							<div class="cptt-set-field full">
								<label>CSS سفارشی</label>
								<textarea name="cptt_advanced[custom_css]" style="height:300px;"><?php echo esc_textarea(get_option('cptt_advanced')['custom_css'] ?? ''); ?></textarea>
							</div>
						<?php elseif ($tab === 'bale'): 
							$bale = get_option('cptt_bale_settings', []);
							$webhook_url = admin_url('admin-ajax.php?action=cptt_bale_webhook');
						?>
							<!-- Settings fields for token and admin ID -->
							<div class="cptt-set-grid">
								<div class="cptt-set-field">
									<label>توکن ربات بله (Bale Bot Token)</label>
									<input type="text" name="cptt_bale_settings[token]" value="<?php echo esc_attr($bale['token'] ?? ''); ?>" placeholder="123456789:ABCdefGhI..." style="width:100%;" />
								</div>
								<div class="cptt-set-field">
									<label>آیدی عددی ادمین (Admin Chat ID)</label>
									<input type="text" name="cptt_bale_settings[admin_id]" value="<?php echo esc_attr($bale['admin_id'] ?? ''); ?>" placeholder="e.g. 987654321" />
								</div>
								<div class="cptt-set-field full" style="margin-top:20px; background:#f8fafc; padding:15px; border-radius:12px; border:1px solid #e2e8f0; width:100%;">
									<h4 style="margin:0 0 10px; color:#1e3a8a;">🔗 آدرس وب‌هوک ربات شما:</h4>
									<code style="display:block; background:#fff; padding:10px; border-radius:8px; border:1px solid #cbd5e1; direction:ltr; text-align:left; font-size:12px;"><?php echo esc_html($webhook_url); ?></code>
									<p style="margin-top:10px; font-size:12px; color:#64748b; font-family:sans-serif;">پس از ذخیره توکن، افزونه به صورت خودکار وب‌هوک ربات بله شما را تنظیم خواهد کرد.</p>
								</div>
							</div>

							<!-- Toggles for Bale notifications -->
							<div class="cptt-settings-fields-tab" style="margin-top:40px; border-top:1px solid #cbd5e1; padding-top:20px;">
								<span style="font-size:14px;font-weight:bold;margin-bottom:15px;display:block;color:#0f172a;">تنظیمات فعال/غیرفعال‌سازی ارسال پیام‌های ربات بله:</span>
								
								<div class="cptt-switch-container">
									<?php 
									$bale_labels = [
										'expert_assign' => 'ارسال پیام در واگذاری پروژه به کارشناس',
										'expert_chat' => 'ارسال پیام بابت چت جدید به کارشناس',
										'expert_payout' => 'ارسال پیام بابت تسویه حساب به کارشناس',
										'client_complete' => 'ارسال پیام بابت تکمیل پروژه به مشتری',
										'client_task' => 'ارسال پیام بابت تسک مشتری جدید به مشتری',
										'daily_report' => 'ارسال گزارش روزانه به مدیر و کارشناسان (صبح)',
										'weekly_report' => 'ارسال گزارش هفتگی به مدیر (هر هفته)',
									];
									foreach ($bale_labels as $key => $lbl):
										$val = $bale[$key] ?? '1';
									?>
									<div class="cptt-switch-item">
										<label><?php echo esc_html($lbl); ?></label>
										<label class="cptt-switch">
											<input type="hidden" name="cptt_bale_settings[<?php echo esc_attr($key); ?>]" value="0" />
											<input type="checkbox" name="cptt_bale_settings[<?php echo esc_attr($key); ?>]" value="1" <?php checked($val, '1'); ?> />
											<span class="cptt-slider"></span>
										</label>
									</div>
									<?php endforeach; ?>
								</div>
							</div>

							<!-- v5.4.4: OTP Login via Bale -->
							<div class="cptt-settings-fields-tab" style="margin-top:30px; border-top:1px solid #cbd5e1; padding-top:20px;">
								<span style="font-size:14px;font-weight:bold;margin-bottom:6px;display:block;color:#0f172a;">🔐 ورود/ثبت‌نام اختصاصی با شماره موبایل + کد بله:</span>
								<p style="color:#64748b; font-size:12px; margin:0 0 12px;">با فعال‌کردن این گزینه، صفحه‌ی ورود اختصاصی افزونه روی آدرس <code>/cptt-login</code> در دسترس قرار می‌گیرد. کاربران با شماره موبایل وارد می‌شوند و کد یک‌بارمصرف از ربات بله دریافت می‌کنند. در صورت فعال‌سازی «اعمال به‌جای فرم وردپرس»، صفحه‌ی <code>wp-login.php</code> هم به این فرم ریدایرکت می‌شود.</p>
								<div class="cptt-switch-container">
									<?php
									$otp_labels = [
										'enable_otp_login' => 'فعال‌سازی صفحه‌ی ورود اختصاصی (/cptt-login)',
										'otp_login_only'   => 'ریدایرکت wp-login.php به صفحه‌ی اختصاصی',
									];
									foreach ($otp_labels as $key => $lbl):
										$val = $bale[$key] ?? '0';
									?>
									<div class="cptt-switch-item">
										<label><?php echo esc_html($lbl); ?></label>
										<label class="cptt-switch">
											<input type="hidden" name="cptt_bale_settings[<?php echo esc_attr($key); ?>]" value="0" />
											<input type="checkbox" name="cptt_bale_settings[<?php echo esc_attr($key); ?>]" value="1" <?php checked($val, '1'); ?> />
											<span class="cptt-slider"></span>
										</label>
									</div>
									<?php endforeach; ?>
								</div>
								<div style="margin-top:12px; padding:10px 14px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; font-size:12px; color:#1e40af;">
									<b>🔗 آدرس صفحه ورود:</b> <code style="background:#fff; padding:2px 8px; border-radius:4px;"><?php echo esc_html(home_url('/cptt-login/')); ?></code>
									<br><small>⚠️ پس از فعال‌سازی، یک‌بار از تنظیمات وردپرس → پیوندهای یکتا، روی «ذخیره تغییرات» بزنید (یا افزونه را غیرفعال/فعال کنید) تا rewrite ها رفرش شوند.</small>
								</div>
							</div>

							<!-- v5.4.7: کارشناسان دریافت‌کننده‌ی سفارش‌های بله -->
							<div class="cptt-settings-fields-tab" style="margin-top:30px; border-top:1px solid #cbd5e1; padding-top:20px;">
								<span style="font-size:14px;font-weight:bold;margin-bottom:6px;display:block;color:#0f172a;">🛒 کارشناسان دریافت‌کننده‌ی سفارش‌های جدید مشتری</span>
								<p style="color:#64748b; font-size:12px; margin:0 0 12px;">انتخاب کنید کدام کارشناسان هنگام ثبت سفارش جدید توسط مشتری در ربات بله، نوتیف دریافت کنند. اگر چیزی انتخاب نکنید، فقط مدیر سایت پیام را می‌گیرد.</p>
								<?php
								$order_experts = isset($bale['order_expert_ids']) && is_array($bale['order_expert_ids']) ? array_map('intval', $bale['order_expert_ids']) : [];
								$all_experts = get_users(['role' => 'cptt_expert', 'orderby' => 'display_name', 'order' => 'ASC']);
								?>
								<input type="hidden" name="cptt_bale_settings[order_expert_ids][]" value="" />
								<?php if (empty($all_experts)): ?>
									<div style="padding:10px; background:#fef3c7; border:1px solid #fcd34d; border-radius:8px; font-size:12px; color:#92400e;">هیچ کارشناسی در سیستم ثبت نشده است.</div>
								<?php else: ?>
								<div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:8px; max-height:240px; overflow:auto; padding:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px;">
									<?php foreach ($all_experts as $exp): $checked = in_array((int)$exp->ID, $order_experts, true); ?>
										<label style="display:flex; align-items:center; gap:8px; padding:8px 10px; background:#fff; border:1px solid <?php echo $checked ? '#6366f1' : '#e2e8f0'; ?>; border-radius:8px; cursor:pointer; font-size:13px;">
											<input type="checkbox" name="cptt_bale_settings[order_expert_ids][]" value="<?php echo (int)$exp->ID; ?>" <?php checked($checked); ?> />
											<span><?php echo esc_html($exp->display_name); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
								<?php endif; ?>
							</div>

							<!-- Manual Webhook Actions -->
							<div class="cptt-settings-fields-tab" style="margin-top:30px; border-top:1px solid #cbd5e1; padding-top:20px;">
								<span style="font-size:14px;font-weight:bold;margin-bottom:15px;display:block;color:#0f172a;">عملیات دستی ربات بله:</span>
								<div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;">
									<a href="<?php echo esc_url(add_query_arg('bale_action', 'set_webhook')); ?>" class="button button-primary" style="background:#2271b1; border-color:#2271b1; color:#fff; padding:6px 14px; font-weight:bold; text-decoration:none; border-radius:4px;">تنظیم مجدد وب‌هوک</a>
									<a href="<?php echo esc_url(add_query_arg('bale_action', 'delete_webhook')); ?>" class="button" style="color:#b91c1c; border-color:#b91c1c; padding:6px 14px; text-decoration:none; border-radius:4px;">حذف وب‌هوک ربات</a>
									<a href="<?php echo esc_url(add_query_arg('bale_action', 'test_connection')); ?>" class="button" style="padding:6px 14px; text-decoration:none; border-radius:4px;">تست اتصال ربات (getMe)</a>
								</div>
							</div>
						<?php endif; ?>
					</form>
				</main>
			</div>
		</div>
		<?php
	}
}
