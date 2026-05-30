/* CPTT Form Builder — v5.5.0 */
(function($){
	if (typeof CPTT_FB === 'undefined') return;
	var FB = CPTT_FB;

	function uid(){ return 'f_' + Math.random().toString(36).substr(2, 7); }
	function escH(s){ return String(s||'').replace(/[&<>"']/g, function(c){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];}); }

	function fieldRow(field){
		field = field || {};
		var fid = field.id || uid();
		var type = field.type || 'text';
		var label = field.label || '';
		var required = field.required ? 'checked' : '';
		var placeholder = field.placeholder || '';
		var help = field.help || '';
		var options = field.options || '';
		var typeLabel = (FB.types[type] && FB.types[type].label) || type;
		var body = '';
		if (['text','textarea','number','phone','email','address'].indexOf(type) !== -1) {
			body += '<label class="cptt-fb-mini"><span>متن راهنما داخل فیلد (placeholder)</span><input type="text" class="cptt-fb-field-placeholder" value="'+escH(placeholder)+'"></label>';
		}
		if (type === 'select') {
			body += '<label class="cptt-fb-mini"><span>گزینه‌ها (هر گزینه در یک خط)</span><textarea class="cptt-fb-field-options" rows="3">'+escH(options)+'</textarea></label>';
		}
		body += '<label class="cptt-fb-mini"><span>متن راهنما / پیام در بات</span><input type="text" class="cptt-fb-field-help" value="'+escH(help)+'"></label>';
		return '<li class="cptt-fb-field" data-id="'+escH(fid)+'" data-type="'+escH(type)+'">' +
			'<div class="cptt-fb-field-head">' +
				'<span class="cptt-fb-handle" title="جابجا کن">⠿</span>' +
				'<span class="cptt-fb-field-type">'+escH(typeLabel)+'</span>' +
				'<input type="text" class="cptt-fb-field-label" placeholder="برچسب فیلد" value="'+escH(label)+'">' +
				'<label class="cptt-fb-required"><input type="checkbox" class="cptt-fb-field-required" '+required+'><span>اجباری</span></label>' +
				'<button class="cptt-fb-field-remove" title="حذف">×</button>' +
			'</div>' +
			'<div class="cptt-fb-field-body">' + body + '</div>' +
		'</li>';
	}

	function initSortable(){
		var $ul = $('#cptt-fb-fields');
		if ($ul.length && $.fn.sortable) {
			$ul.sortable({handle: '.cptt-fb-handle', placeholder: 'cptt-fb-field', forcePlaceholderSize: true, tolerance: 'pointer'});
		}
	}
	initSortable();

	$(document).on('click', '.cptt-fb-add-field', function(){
		var type = $(this).data('type');
		$('#cptt-fb-fields').append(fieldRow({type: type})).find('.cptt-fb-empty').remove();
		$('.cptt-fb-empty').remove();
	});

	$(document).on('click', '.cptt-fb-field-remove', function(){
		if (!confirm('این فیلد حذف شود؟')) return;
		$(this).closest('.cptt-fb-field').remove();
	});

	$(document).on('click', '#cptt-fb-new-form', function(){
		var t = prompt('نام فرم جدید:', 'فرم سفارش جدید');
		if (!t) return;
		$.post(FB.ajax, {action:'cptt_form_save', nonce: FB.nonce, id: 0, title: t, fields: []}, function(r){
			if (r && r.success) location.href = location.pathname + '?post_type=cptt_project&page=cptt-form-builder&form_id=' + r.data.id;
		});
	});

	$(document).on('click', '#cptt-fb-save', function(){
		var $btn = $(this); var t = $btn.text(); $btn.prop('disabled', true).text('در حال ذخیره...');
		var fields = [];
		$('#cptt-fb-fields .cptt-fb-field').each(function(){
			var $f = $(this);
			fields.push({
				id: $f.data('id'),
				type: $f.data('type'),
				label: $f.find('.cptt-fb-field-label').val(),
				required: $f.find('.cptt-fb-field-required').is(':checked') ? 1 : 0,
				placeholder: $f.find('.cptt-fb-field-placeholder').val() || '',
				help: $f.find('.cptt-fb-field-help').val() || '',
				options: $f.find('.cptt-fb-field-options').val() || ''
			});
		});
		$.post(FB.ajax, {
			action: 'cptt_form_save', nonce: FB.nonce,
			id: $('#cptt-fb-form-id').val(),
			title: $('#cptt-fb-form-title').val(),
			fields: fields
		}, function(r){
			if (r && r.success) {
				$btn.text('✓ ذخیره شد');
				setTimeout(function(){ $btn.text(t).prop('disabled', false); }, 1500);
			} else {
				$btn.text('خطا').prop('disabled', false);
			}
		});
	});

	$(document).on('click', '#cptt-fb-delete', function(){
		if (!confirm('این فرم برای همیشه حذف شود؟')) return;
		$.post(FB.ajax, {action:'cptt_form_delete', nonce: FB.nonce, id: $('#cptt-fb-form-id').val()}, function(r){
			if (r && r.success) location.href = location.pathname + '?post_type=cptt_project&page=cptt-form-builder';
		});
	});

	$(document).on('click', '#cptt-fb-set-active', function(){
		var $b = $(this); $b.prop('disabled', true).text('...');
		$.post(FB.ajax, {action:'cptt_form_activate', nonce: FB.nonce, id: $('#cptt-fb-form-id').val()}, function(r){
			if (r && r.success) location.reload();
		});
	});

})(jQuery);
