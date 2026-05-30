jQuery(document).ready(function($) {
    // فعال‌سازی انتخابگر رنگ وردپرس
    $('.cptt-color-picker').wpColorPicker();

    // مدیریت آپلود فایل با مدیا لایبرری وردپرس
    $(document).on('click', '.cptt-media-select', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $wrapper = $btn.closest('.cptt-set-field');
        var frame = wp.media({
            title: 'انتخاب تصویر',
            button: { text: 'استفاده از این تصویر' },
            multiple: false
        });

        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            $wrapper.find('input[type="hidden"]').val(attachment.id);
            $wrapper.find('.cptt-media-preview').html('<img src="' + attachment.url + '" />');
        });
        frame.open();
    });

    // حذف تصویر
    $(document).on('click', '.cptt-media-remove', function(e) {
        e.preventDefault();
        var $wrapper = $(this).closest('.cptt-set-field');
        $wrapper.find('input[type="hidden"]').val('');
        $wrapper.find('.cptt-media-preview').html('<span class="dashicons dashicons-format-image"></span>');
    });
});
