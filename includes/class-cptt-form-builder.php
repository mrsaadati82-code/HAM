<?php
/**
 * HAM Bale Order Flow Builder
 * فرم‌ساز اختصاصی مراحل سفارش در ربات بله — Neumorphism UI
 */
if (!defined('ABSPATH')) exit;

class CPTT_Form_Builder {
    private static $instance = null;
    const OPT_ACTIVE = 'cptt_active_order_form_id';
    const OPT_DISABLED = 'cptt_order_form_disabled';

    public static function instance() { if (self::$instance === null) self::$instance = new self(); return self::$instance; }

    private function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('wp_ajax_cptt_form_save', [$this, 'ajax_save_form']);
        add_action('wp_ajax_cptt_form_delete', [$this, 'ajax_delete_form']);
        add_action('wp_ajax_cptt_form_activate', [$this, 'ajax_activate_form']);
        add_action('wp_ajax_cptt_form_deactivate', [$this, 'ajax_deactivate_form']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
    }

    public static function install_defaults() {
        $q = new WP_Query(['post_type'=>'cptt_order_form','posts_per_page'=>1,'fields'=>'ids']);
        if ($q->have_posts()) return;
        $id = wp_insert_post(['post_type'=>'cptt_order_form','post_status'=>'publish','post_title'=>'فرم سفارش بله — پیش‌فرض حرفه‌ای']);
        if ($id && !is_wp_error($id)) { update_post_meta($id, '_cptt_form_fields', self::template_service()); update_option(self::OPT_ACTIVE, (int)$id, false); delete_option(self::OPT_DISABLED); }
    }

    public static function available_types() {
        return [
            'intro'    => ['label'=>'✨ پیام/راهنما','desc'=>'نمایش متن بدون دریافت پاسخ','icon'=>'✨'],
            'text'     => ['label'=>'📝 پاسخ کوتاه','desc'=>'نام، عنوان، کد، توضیح کوتاه','icon'=>'📝'],
            'textarea' => ['label'=>'📄 توضیح کامل','desc'=>'پاسخ طولانی در یک پیام','icon'=>'📄'],
            'number'   => ['label'=>'🔢 عدد/تعداد','desc'=>'تعداد، بودجه، متراژ و...','icon'=>'🔢'],
            'phone'    => ['label'=>'📞 شماره تماس','desc'=>'دریافت شماره معتبر','icon'=>'📞'],
            'email'    => ['label'=>'📧 ایمیل','desc'=>'دریافت ایمیل اختیاری/اجباری','icon'=>'📧'],
            'buttons'  => ['label'=>'🧊 دکمه‌های شیشه‌ای','desc'=>'انتخاب یک گزینه با inline keyboard','icon'=>'🧊'],
            'multi'    => ['label'=>'☑️ چندانتخابی','desc'=>'انتخاب چند گزینه با دکمه شیشه‌ای','icon'=>'☑️'],
            'date'     => ['label'=>'📅 تاریخ/مهلت','desc'=>'دریافت تاریخ به‌صورت متن راهنمایی‌شده','icon'=>'📅'],
            'address'  => ['label'=>'📍 آدرس','desc'=>'آدرس کامل یا لوکیشن متنی','icon'=>'📍'],
            'file'     => ['label'=>'📎 فایل/تصویر','desc'=>'یک یا چند فایل/عکس','icon'=>'📎'],
            'payment'  => ['label'=>'💳 پرداخت','desc'=>'نمایش کارت‌به‌کارت/درگاه و دریافت رسید','icon'=>'💳'],
            'confirm'  => ['label'=>'✅ تأیید نهایی','desc'=>'پیش‌نمایش و تأیید قبل از ثبت','icon'=>'✅'],
        ];
    }

    public static function template_service() {
        return [
            ['id'=>self::fid(),'type'=>'intro','label'=>'شروع سفارش','message'=>'سلام 👋 برای ثبت سفارش، چند سؤال کوتاه از شما می‌پرسیم.'],
            ['id'=>self::fid(),'type'=>'buttons','label'=>'نوع سفارش','required'=>1,'options'=>"خدمات طراحی\nپشتیبانی\nمشاوره\nسفارش اختصاصی",'layout'=>'grid'],
            ['id'=>self::fid(),'type'=>'textarea','label'=>'توضیحات سفارش','required'=>1,'placeholder'=>'لطفاً نیازتان را کامل توضیح دهید...'],
            ['id'=>self::fid(),'type'=>'number','label'=>'بودجه تقریبی به تومان','required'=>0],
            ['id'=>self::fid(),'type'=>'date','label'=>'مهلت مدنظر','required'=>0,'placeholder'=>'مثلاً ۱۴۰۳/۰۷/۱۵'],
            ['id'=>self::fid(),'type'=>'file','label'=>'فایل یا نمونه کار','required'=>0,'multiple'=>1,'max_files'=>5],
            ['id'=>self::fid(),'type'=>'payment','label'=>'پرداخت بیعانه','required'=>0,'amount'=>'','allow_later'=>1],
            ['id'=>self::fid(),'type'=>'confirm','label'=>'تأیید نهایی سفارش','message'=>'اطلاعات را بررسی کنید و سفارش را ثبت کنید.'],
        ];
    }
    public static function template_product() {
        return [
            ['id'=>self::fid(),'type'=>'buttons','label'=>'نوع درخواست محصول','required'=>1,'options'=>"خرید محصول\nاستعلام قیمت\nسفارش عمده\nسفارشی‌سازی"],
            ['id'=>self::fid(),'type'=>'text','label'=>'نام محصول/مدل','required'=>1],
            ['id'=>self::fid(),'type'=>'number','label'=>'تعداد','required'=>1],
            ['id'=>self::fid(),'type'=>'address','label'=>'آدرس ارسال','required'=>0],
            ['id'=>self::fid(),'type'=>'phone','label'=>'شماره تماس','required'=>1],
            ['id'=>self::fid(),'type'=>'payment','label'=>'پرداخت سفارش','required'=>0,'amount'=>'','allow_later'=>1],
            ['id'=>self::fid(),'type'=>'confirm','label'=>'ثبت سفارش','message'=>'در صورت تأیید سفارش ثبت می‌شود.'],
        ];
    }
    public static function template_support() {
        return [
            ['id'=>self::fid(),'type'=>'buttons','label'=>'نوع درخواست','required'=>1,'options'=>"گزارش مشکل\nدرخواست تغییر\nارسال فایل\nپیگیری سفارش"],
            ['id'=>self::fid(),'type'=>'textarea','label'=>'شرح درخواست','required'=>1],
            ['id'=>self::fid(),'type'=>'multi','label'=>'اولویت‌ها','required'=>0,'options'=>"فوری\nنیاز به تماس\nنیاز به فاکتور\nنیاز به ارسال فایل"],
            ['id'=>self::fid(),'type'=>'file','label'=>'تصویر/فایل مرتبط','required'=>0,'multiple'=>1,'max_files'=>10],
            ['id'=>self::fid(),'type'=>'confirm','label'=>'تأیید درخواست','message'=>'درخواست شما برای تیم ارسال می‌شود.'],
        ];
    }
    private static function fid(){ return 'f_' . wp_generate_password(7, false, false); }

    public static function get_forms() { return get_posts(['post_type'=>'cptt_order_form','post_status'=>'any','numberposts'=>-1,'orderby'=>'date','order'=>'DESC']); }
    public static function get_form($id) { $p=get_post((int)$id); if(!$p || $p->post_type!=='cptt_order_form') return null; $f=get_post_meta($p->ID,'_cptt_form_fields',true); return ['id'=>(int)$p->ID,'title'=>$p->post_title,'fields'=>is_array($f)?$f:[],'target_product_id'=>(int)get_post_meta($p->ID,'_cptt_form_target_product_id',true),'target_cat_id'=>(int)get_post_meta($p->ID,'_cptt_form_target_cat_id',true)]; }
    public static function get_active_form() { if (get_option(self::OPT_DISABLED,0)) return null; $id=(int)get_option(self::OPT_ACTIVE,0); $f=$id?self::get_form($id):null; if($f && !empty($f['fields'])) return $f; foreach(self::get_forms() as $p){ $f=self::get_form($p->ID); if($f && !empty($f['fields'])) return $f; } return null; }
    public static function get_form_for_product($product_id) { if (get_option(self::OPT_DISABLED,0)) return null; $product_id=(int)$product_id; $cats = $product_id ? wp_get_post_terms($product_id, 'product_cat', ['fields'=>'ids']) : []; foreach (self::get_forms() as $p) { $f=self::get_form($p->ID); if (!$f) continue; if (!empty($f['target_product_id']) && (int)$f['target_product_id']===$product_id) return $f; if (!empty($f['target_cat_id']) && in_array((int)$f['target_cat_id'], array_map('intval',(array)$cats), true)) return $f; } return self::get_active_form(); }

    public function menu(){ add_submenu_page('edit.php?post_type=cptt_project','فرم‌ساز سفارش بله','📋 فرم‌ساز سفارش بله','manage_options','cptt-form-builder',[$this,'page']); }
    public function admin_assets($hook){ if(strpos((string)$hook,'cptt-form-builder')===false) return; wp_enqueue_style('cptt-form-builder',CPTT_URL.'assets/css/form-builder.css',[],CPTT_VERSION); wp_enqueue_script('jquery-ui-sortable'); wp_enqueue_script('cptt-form-builder',CPTT_URL.'assets/js/form-builder.js',['jquery','jquery-ui-sortable'],CPTT_VERSION,true); wp_localize_script('cptt-form-builder','CPTT_FB',['ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('cptt_form_builder'),'types'=>self::available_types(),'templates'=>['service'=>self::template_service(),'product'=>self::template_product(),'support'=>self::template_support()]]); }

    public function page(){
        if(!current_user_can('manage_options')) return; $forms=self::get_forms(); $active_id=(int)get_option(self::OPT_ACTIVE,0); $disabled=(int)get_option(self::OPT_DISABLED,0); $edit_id=absint($_GET['form_id']??0); $editing=$edit_id?self::get_form($edit_id):(!empty($forms)?self::get_form($forms[0]->ID):null); $cats = taxonomy_exists('product_cat') ? get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'number'=>200]) : []; $products = get_posts(['post_type'=>'product','post_status'=>'publish','numberposts'=>200,'orderby'=>'title','order'=>'ASC']);
        ?>
        <div class="cptt-fb-app" dir="rtl">
            <div class="cptt-fb-neoHero"><div><h1>فرم‌ساز سفارش ربات بله</h1><p>یک جریان سفارش حرفه‌ای با دکمه‌های شیشه‌ای، آپلود فایل، پرداخت و تأیید نهایی بسازید. این فرم فقط در ربات بله استفاده می‌شود.</p></div><button class="cptt-fb-primary" id="cptt-fb-save">ذخیره فرم</button></div>
            <div class="cptt-fb-shell">
                <aside class="cptt-fb-neoSide"><button class="cptt-fb-primary cptt-fb-wide" id="cptt-fb-new-form">+ فرم جدید</button><div class="cptt-fb-sideTitle">فرم‌ها</div><?php foreach($forms as $f): ?><a class="cptt-fb-formLink <?php echo $editing&&$editing['id']==$f->ID?'is-active':''; ?>" href="<?php echo esc_url(add_query_arg(['form_id'=>$f->ID])); ?>"><?php echo esc_html(get_the_title($f)); ?><?php if($active_id==$f->ID&&!$disabled): ?><b>فعال</b><?php endif; ?></a><?php endforeach; ?></aside>
                <main class="cptt-fb-workspace">
                    <?php if($editing): ?>
                    <div class="cptt-fb-topbar"><input id="cptt-fb-form-title" value="<?php echo esc_attr($editing['title']); ?>"><input type="hidden" id="cptt-fb-form-id" value="<?php echo (int)$editing['id']; ?>"><button class="cptt-fb-soft" id="cptt-fb-set-active">فعال در بله</button><button class="cptt-fb-soft" id="cptt-fb-deactivate">غیرفعال/فرم پیش‌فرض</button><button class="cptt-fb-danger" id="cptt-fb-delete">حذف</button><div class="cptt-fb-targets"><label><span>فرم مخصوص دسته‌بندی محصول</span><select id="cptt-fb-target-cat"><option value="0">— همه / بدون دسته —</option><?php foreach($cats as $cat): ?><option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected((int)($editing['target_cat_id'] ?? 0), (int)$cat->term_id); ?>><?php echo esc_html($cat->name); ?></option><?php endforeach; ?></select></label><label><span>فرم مخصوص محصول خاص</span><select id="cptt-fb-target-product"><option value="0">— بدون محصول خاص —</option><?php foreach($products as $prod): $pcats = taxonomy_exists('product_cat') ? wp_get_post_terms($prod->ID, 'product_cat', ['fields'=>'ids']) : []; ?><option value="<?php echo esc_attr($prod->ID); ?>" data-cats="<?php echo esc_attr(implode(',', array_map('intval', (array)$pcats))); ?>" <?php selected((int)($editing['target_product_id'] ?? 0), (int)$prod->ID); ?>><?php echo esc_html(get_the_title($prod)); ?></option><?php endforeach; ?></select></label><small>اگر فقط دسته‌بندی انتخاب شود، همه محصولات آن دسته از این فرم استفاده می‌کنند. اگر محصول هم انتخاب شود، فقط همان محصول خاص اولویت دارد.</small></div></div>
                    <div class="cptt-fb-templates"><button data-template="service">تمپلیت خدمات</button><button data-template="product">تمپلیت محصول</button><button data-template="support">تمپلیت پشتیبانی</button></div>
                    <div class="cptt-fb-builderGrid"><section class="cptt-fb-palette"><h3>افزودن مرحله</h3><?php foreach(self::available_types() as $k=>$t): ?><button class="cptt-fb-add-field" data-type="<?php echo esc_attr($k); ?>"><i><?php echo esc_html($t['icon']); ?></i><span><?php echo esc_html($t['label']); ?></span><small><?php echo esc_html($t['desc']); ?></small></button><?php endforeach; ?></section><section class="cptt-fb-canvas"><div class="cptt-fb-canvasTitle">مراحل فرم — با کشیدن مرتب کنید</div><ul id="cptt-fb-fields" class="cptt-fb-fields"><?php foreach($editing['fields'] as $field) $this->render_field_row($field); ?></ul></section></div>
                    <?php else: ?><div class="cptt-fb-emptyBig"><h2>هنوز فرمی ندارید</h2><p>یک فرم جدید بسازید یا از تمپلیت‌ها شروع کنید.</p></div><?php endif; ?>
                </main>
            </div>
        </div><?php
    }

    public function render_field_row($field){ $types=self::available_types(); $type=$field['type']??'text'; $def=$types[$type]??$types['text']; ?>
        <li class="cptt-fb-field" data-id="<?php echo esc_attr($field['id']??self::fid()); ?>" data-type="<?php echo esc_attr($type); ?>">
            <div class="cptt-fb-fieldHead"><span class="cptt-fb-drag">⠿</span><b><?php echo esc_html($def['label']); ?></b><input class="cptt-fb-field-label" value="<?php echo esc_attr($field['label']??''); ?>" placeholder="عنوان مرحله"><label><input type="checkbox" class="cptt-fb-field-required" <?php checked(!empty($field['required'])); ?>> اجباری</label><button class="cptt-fb-field-remove">×</button></div>
            <div class="cptt-fb-fieldBody">
                <label><span>پیام راهنما در بله</span><input class="cptt-fb-field-help" value="<?php echo esc_attr($field['help']??''); ?>"></label>
                <?php if(in_array($type,['text','textarea','number','phone','email','date','address'],true)): ?><label><span>نمونه/Placeholder</span><input class="cptt-fb-field-placeholder" value="<?php echo esc_attr($field['placeholder']??''); ?>"></label><?php endif; ?>
                <?php if(in_array($type,['buttons','multi'],true)): ?><label class="full"><span>گزینه‌های دکمه شیشه‌ای — هر گزینه در یک خط</span><textarea class="cptt-fb-field-options" rows="4"><?php echo esc_textarea($field['options']??''); ?></textarea></label><label><span>چیدمان</span><select class="cptt-fb-field-layout"><option value="grid" <?php selected($field['layout']??'grid','grid'); ?>>شبکه‌ای</option><option value="list" <?php selected($field['layout']??'grid','list'); ?>>لیستی</option></select></label><?php endif; ?>
                <?php if($type==='file'): ?><label><input type="checkbox" class="cptt-fb-field-multiple" <?php checked(!empty($field['multiple'])); ?>> چند فایل مجاز باشد</label><label><span>حداکثر فایل</span><input type="number" class="cptt-fb-field-max-files" value="<?php echo esc_attr($field['max_files']??5); ?>"></label><?php endif; ?>
                <?php if($type==='payment'): ?><label><span>مبلغ به تومان (اختیاری)</span><input class="cptt-fb-field-amount" value="<?php echo esc_attr($field['amount']??''); ?>"></label><label><input type="checkbox" class="cptt-fb-field-allow-later" <?php checked(!empty($field['allow_later'])); ?>> امکان پرداخت بعداً</label><?php endif; ?>
                <?php if($type==='intro'||$type==='confirm'): ?><label class="full"><span>متن پیام</span><textarea class="cptt-fb-field-message" rows="3"><?php echo esc_textarea($field['message']??''); ?></textarea></label><?php endif; ?>
            </div>
        </li><?php }

    public function ajax_save_form(){ check_ajax_referer('cptt_form_builder','nonce'); if(!current_user_can('manage_options')) wp_send_json_error('no_access'); $id=absint($_POST['id']??0); $title=sanitize_text_field($_POST['title']??'فرم سفارش بله'); $raw=isset($_POST['fields'])&&is_array($_POST['fields'])?wp_unslash($_POST['fields']):[]; $fields=[]; foreach($raw as $f){ if(!is_array($f)) continue; $type=sanitize_key($f['type']??'text'); if(!isset(self::available_types()[$type])) continue; $fields[]=['id'=>sanitize_text_field($f['id']??self::fid()),'type'=>$type,'label'=>sanitize_text_field($f['label']??''),'required'=>!empty($f['required'])?1:0,'placeholder'=>sanitize_text_field($f['placeholder']??''),'help'=>sanitize_text_field($f['help']??''),'options'=>sanitize_textarea_field($f['options']??''),'layout'=>sanitize_key($f['layout']??'grid'),'multiple'=>!empty($f['multiple'])?1:0,'max_files'=>max(1,absint($f['max_files']??5)),'amount'=>sanitize_text_field($f['amount']??''),'allow_later'=>!empty($f['allow_later'])?1:0,'message'=>sanitize_textarea_field($f['message']??'')]; } if(!$id){$id=wp_insert_post(['post_type'=>'cptt_order_form','post_status'=>'publish','post_title'=>$title]);} else wp_update_post(['ID'=>$id,'post_title'=>$title]); update_post_meta($id,'_cptt_form_fields',$fields); update_post_meta($id,'_cptt_form_target_product_id', absint($_POST['target_product_id'] ?? 0)); update_post_meta($id,'_cptt_form_target_cat_id', absint($_POST['target_cat_id'] ?? 0)); wp_send_json_success(['id'=>$id]); }
    public function ajax_delete_form(){ check_ajax_referer('cptt_form_builder','nonce'); if(!current_user_can('manage_options')) wp_send_json_error('no_access'); $id=absint($_POST['id']??0); if($id) wp_delete_post($id,true); if((int)get_option(self::OPT_ACTIVE,0)===$id) delete_option(self::OPT_ACTIVE); wp_send_json_success(); }
    public function ajax_activate_form(){ check_ajax_referer('cptt_form_builder','nonce'); if(!current_user_can('manage_options')) wp_send_json_error('no_access'); $id=absint($_POST['id']??0); update_option(self::OPT_ACTIVE,$id,false); delete_option(self::OPT_DISABLED); wp_send_json_success(); }
    public function ajax_deactivate_form(){ check_ajax_referer('cptt_form_builder','nonce'); if(!current_user_can('manage_options')) wp_send_json_error('no_access'); update_option(self::OPT_DISABLED,1,false); wp_send_json_success(); }
}
