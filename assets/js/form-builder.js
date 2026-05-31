/* HAM Bale Form Builder v5.4.28 */
(function($){
  if(typeof CPTT_FB==='undefined') return; var FB=CPTT_FB;
  function uid(){return 'f_'+Math.random().toString(36).slice(2,9)}
  function esc(s){return String(s||'').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}
  function def(type){return (FB.types&&FB.types[type])||FB.types.text}
  function row(f){f=f||{};var type=f.type||'text',d=def(type),id=f.id||uid();var body='<label><span>پیام راهنما در بله</span><input class="cptt-fb-field-help" value="'+esc(f.help||'')+'"></label>';
    if(['text','textarea','number','phone','email','date','address'].indexOf(type)>-1) body+='<label><span>نمونه/Placeholder</span><input class="cptt-fb-field-placeholder" value="'+esc(f.placeholder||'')+'"></label>';
    if(['buttons','multi'].indexOf(type)>-1) body+='<label class="full"><span>گزینه‌های دکمه شیشه‌ای — هر گزینه در یک خط</span><textarea class="cptt-fb-field-options" rows="4">'+esc(f.options||'')+'</textarea></label><label><span>چیدمان</span><select class="cptt-fb-field-layout"><option value="grid">شبکه‌ای</option><option value="list">لیستی</option></select></label>';
    if(type==='file') body+='<label><input type="checkbox" class="cptt-fb-field-multiple" '+(f.multiple?'checked':'')+'> چند فایل مجاز باشد</label><label><span>حداکثر فایل</span><input type="number" class="cptt-fb-field-max-files" value="'+esc(f.max_files||5)+'"></label>';
    if(type==='payment') body+='<label><span>مبلغ به تومان (اختیاری)</span><input class="cptt-fb-field-amount" value="'+esc(f.amount||'')+'"></label><label><input type="checkbox" class="cptt-fb-field-allow-later" '+(f.allow_later?'checked':'')+'> امکان پرداخت بعداً</label>';
    if(type==='intro'||type==='confirm') body+='<label class="full"><span>متن پیام</span><textarea class="cptt-fb-field-message" rows="3">'+esc(f.message||'')+'</textarea></label>';
    return '<li class="cptt-fb-field" data-id="'+esc(id)+'" data-type="'+esc(type)+'"><div class="cptt-fb-fieldHead"><span class="cptt-fb-drag">⠿</span><b>'+esc(d.label)+'</b><input class="cptt-fb-field-label" value="'+esc(f.label||'')+'" placeholder="عنوان مرحله"><label><input type="checkbox" class="cptt-fb-field-required" '+(f.required?'checked':'')+'> اجباری</label><button class="cptt-fb-field-remove">×</button></div><div class="cptt-fb-fieldBody">'+body+'</div></li>';
  }
  function sortable(){var $u=$('#cptt-fb-fields'); if($u.length&&$.fn.sortable){ if($u.data('ui-sortable')) $u.sortable('destroy'); $u.sortable({handle:'.cptt-fb-drag',placeholder:'cptt-fb-placeholder',forcePlaceholderSize:true,tolerance:'pointer'}); }}
  function collect(){var arr=[];$('#cptt-fb-fields .cptt-fb-field').each(function(){var $f=$(this);arr.push({id:$f.data('id'),type:$f.data('type'),label:$f.find('.cptt-fb-field-label').val(),required:$f.find('.cptt-fb-field-required').is(':checked')?1:0,placeholder:$f.find('.cptt-fb-field-placeholder').val()||'',help:$f.find('.cptt-fb-field-help').val()||'',options:$f.find('.cptt-fb-field-options').val()||'',layout:$f.find('.cptt-fb-field-layout').val()||'grid',multiple:$f.find('.cptt-fb-field-multiple').is(':checked')?1:0,max_files:$f.find('.cptt-fb-field-max-files').val()||5,amount:$f.find('.cptt-fb-field-amount').val()||'',allow_later:$f.find('.cptt-fb-field-allow-later').is(':checked')?1:0,message:$f.find('.cptt-fb-field-message').val()||''});});return arr;}
  sortable();
  $(document).on('click','.cptt-fb-add-field',function(e){e.preventDefault();$('#cptt-fb-fields').append(row({type:$(this).data('type')}));sortable();});
  $(document).on('click','.cptt-fb-field-remove',function(e){e.preventDefault(); if(confirm('این مرحله حذف شود؟')) $(this).closest('.cptt-fb-field').remove();});
  $(document).on('click','.cptt-fb-templates button',function(e){e.preventDefault();var t=$(this).data('template'); if(!confirm('مراحل فعلی با تمپلیت جایگزین شود؟')) return; $('#cptt-fb-fields').html((FB.templates[t]||[]).map(row).join('')); sortable();});
  $(document).on('click','#cptt-fb-new-form',function(e){e.preventDefault();var title=prompt('نام فرم جدید','فرم سفارش بله'); if(!title)return; $.post(FB.ajax,{action:'cptt_form_save',nonce:FB.nonce,id:0,title:title,fields:[]},function(r){if(r&&r.success) location.href=location.pathname+'?post_type=cptt_project&page=cptt-form-builder&form_id='+r.data.id;});});
  $(document).on('click','#cptt-fb-save',function(e){e.preventDefault();var $b=$(this).prop('disabled',true).text('در حال ذخیره...'); $.post(FB.ajax,{action:'cptt_form_save',nonce:FB.nonce,id:$('#cptt-fb-form-id').val(),title:$('#cptt-fb-form-title').val(),target_product_id:$('#cptt-fb-target-product').val()||0,target_cat_id:$('#cptt-fb-target-cat').val()||0,fields:collect()},function(r){$b.prop('disabled',false).text(r&&r.success?'ذخیره شد':'خطا'); setTimeout(function(){$b.text('ذخیره فرم')},1200);});});
  $(document).on('click','#cptt-fb-set-active',function(e){e.preventDefault();$.post(FB.ajax,{action:'cptt_form_activate',nonce:FB.nonce,id:$('#cptt-fb-form-id').val()},function(r){if(r&&r.success) location.reload();});});
  $(document).on('click','#cptt-fb-deactivate',function(e){e.preventDefault();$.post(FB.ajax,{action:'cptt_form_deactivate',nonce:FB.nonce},function(r){if(r&&r.success) location.reload();});});
  $(document).on('click','#cptt-fb-delete',function(e){e.preventDefault(); if(!confirm('حذف شود؟')) return; $.post(FB.ajax,{action:'cptt_form_delete',nonce:FB.nonce,id:$('#cptt-fb-form-id').val()},function(){location.href=location.pathname+'?post_type=cptt_project&page=cptt-form-builder';});});
})(jQuery);

/* v5.4.31 target product filtering by selected category */
(function($){
  function filterTargetProducts(){
    var cat = String($('#cptt-fb-target-cat').val() || '0');
    var $prod = $('#cptt-fb-target-product');
    if(!$prod.length) return;
    var current = $prod.val();
    var currentVisible = true;
    $prod.find('option').each(function(){
      if(!this.value || this.value === '0'){ this.hidden = false; return; }
      var cats = String($(this).data('cats') || '').split(',');
      var show = (cat === '0' || cats.indexOf(cat) !== -1);
      this.hidden = !show;
      if(this.value === current && !show) currentVisible = false;
    });
    if(!currentVisible) $prod.val('0');
  }
  $(document).on('change', '#cptt-fb-target-cat', filterTargetProducts);
  $(filterTargetProducts);
})(jQuery);
