jQuery(function ($) {
  const $rows = $('#cptt-steps-rows');
  const stepTpl = $('#cptt-step-template').html() || '';
  const checkItemTpl = $('#cptt-checkitem-template').html() || '';
  const userTaskTpl = $('#cptt-usertask-template').html() || '';

  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'cptt_' + Math.random().toString(16).slice(2) + '_' + Date.now();
  }

  function reindexAll() {
    $rows.find('.cptt-step-row').each(function (i) {
      $(this).find('input, select, textarea').each(function () {
        const $el = $(this);
        const name = $el.attr('name');
        if (!name) return;
        $el.attr('name', name.replace(/cptt_steps\[\d+\]/, 'cptt_steps[' + i + ']'));
      });
      $(this).find('.cptt-checkitems').attr('data-step-index', i);
      $(this).find('.cptt-checkitem-row').each(function (j) {
        $(this).find('input').each(function () {
          const $el = $(this);
          const name = $el.attr('name');
          if (!name) return;
          $el.attr('name', name
            .replace(/cptt_steps\[\d+\]/, 'cptt_steps[' + i + ']')
            .replace(/\[checklist\]\[\d+\]/, '[checklist][' + j + ']')
          );
        });
      });
      $(this).find('.cptt-usertasks').attr('data-step-index', i);
      $(this).find('.cptt-usertask-row').each(function (k) {
        $(this).find('input, textarea, select').each(function () {
          const $el = $(this);
          const name = $el.attr('name');
          if (!name) return;
          $el.attr('name', name
            .replace(/cptt_steps\[\d+\]/, 'cptt_steps[' + i + ']')
            .replace(/\[user_tasks\]\[\d+\]/, '[user_tasks][' + k + ']')
          );
        });
      });
    });
  }


  function fillStepExpertSelects(context) {
    const experts = [];
    $('input[name="cptt_expert_user_ids[]"]').each(function(){
      const $cb = $(this);
      const txt = $.trim($cb.closest('label').text()) || $cb.val();
      experts.push({id: String($cb.val()), name: txt});
    });
    $(context || document).find('select.cptt-step-assigned-experts').each(function(){
      const $sel = $(this);
      if ($sel.children().length) return;
      experts.forEach(function(ex){ $sel.append($('<option/>').val(ex.id).text(ex.name)); });
      if (!experts.length) $sel.append($('<option/>').val('').text('ابتدا کارشناسان پروژه را انتخاب/ذخیره کنید'));
    });
  }
  fillStepExpertSelects(document);
  $(document).on('change', 'select[name*="[assigned_expert_ids]"]', function(){
    const $sel = $(this);
    const first = ($sel.val() || [])[0] || '0';
    $sel.closest('.cptt-stepCard__expertAssign').find('input[name*="[assigned_expert_id]"]').val(first);
  });

  $('#cptt-add-step').on('click', function () {
    const i = $rows.find('.cptt-step-row').length;
    const id = uuid();
    const html = stepTpl.replaceAll('{{i}}', i).replaceAll('{{uuid}}', id);
    $rows.append(html);
    fillStepExpertSelects($rows.children().last());
    reindexAll(); $(document).trigger('cptt:stepsChanged');
  });

  $rows.on('click', '.cptt-remove-step', function () {
    $(this).closest('.cptt-step-row').remove();
    reindexAll(); $(document).trigger('cptt:stepsChanged');
  });

  $rows.sortable({
    items: '.cptt-step-row',
    handle: '.cptt-drag-handle',
    axis: 'y',
    tolerance: 'pointer',
    update: function () { reindexAll(); $(document).trigger('cptt:stepsChanged'); }
  });

  $rows.on('click', '.cptt-toggle-checklist', function () {
    const $wrap = $(this).closest('.cptt-step-row').find('.cptt-checklist-wrap').first();
    $wrap.toggle();
  });

  $rows.on('click', '.cptt-add-checkitem', function () {
    const $step = $(this).closest('.cptt-step-row');
    const i = $step.index();
    const $list = $step.find('.cptt-checkitems').first();
    const j = $list.find('.cptt-checkitem-row').length;
    const cid = uuid();
    const html = checkItemTpl
      .replaceAll('{{i}}', i)
      .replaceAll('{{j}}', j)
      .replaceAll('{{cid}}', cid);
    $list.append(html);
    if (!$list.data('sortable')) {
      $list.sortable({
        items: '.cptt-checkitem-row',
        handle: '.cptt-checkitem-handle',
        axis: 'y',
        update: function () { reindexAll(); $(document).trigger('cptt:stepsChanged'); }
      });
      $list.data('sortable', true);
    }
    reindexAll(); $(document).trigger('cptt:stepsChanged');
  });

  $rows.on('click', '.cptt-remove-checkitem', function () {
    $(this).closest('.cptt-checkitem-row').remove();
    reindexAll(); $(document).trigger('cptt:stepsChanged');
  });

  $rows.find('.cptt-checkitems').each(function () {
    const $list = $(this);
    $list.sortable({
      items: '.cptt-checkitem-row',
      handle: '.cptt-checkitem-handle',
      axis: 'y',
      update: function () { reindexAll(); $(document).trigger('cptt:stepsChanged'); }
    });
    $list.data('sortable', true);
  });

  $('#cptt_apply_template_btn').on('click', function () {
    const tplId = $('#cptt_template_select').val();
    if (!tplId) return;
    if (!confirm('اعمال تمپلیت، مراحل فعلی را جایگزین می‌کند. ادامه می‌دهید؟')) return;
    $.get(CPTT_ADMIN.ajax, {
      action: 'cptt_get_template_steps',
      nonce: CPTT_ADMIN.nonce,
      template_id: tplId
    }).done(function (res) {
      if (!res || !res.success) return alert('خطا در دریافت تمپلیت');
      const steps = res.data || [];
      $rows.empty();
      steps.forEach(function (s, idx) {
        const id = s.id || uuid();
        const html = stepTpl.replaceAll('{{i}}', idx).replaceAll('{{uuid}}', id);
        const $row = $(html);
        $row.find('input[name^="cptt_steps[' + idx + '][title]"]').val(s.title || '');
        $row.find('select[name^="cptt_steps[' + idx + '][status]"]').val(s.status || 'todo');
        $row.find('textarea[name^="cptt_steps[' + idx + '][desc]"]').val(s.desc || '');
        $row.find('input[name^="cptt_steps[' + idx + '][due_at_local]"]').val(s.due_at_local || '');
        $row.find('input[name^="cptt_steps[' + idx + '][cost]"]').val(s.cost || 0);
        $row.find('input[name^="cptt_steps[' + idx + '][paid]"]').val(s.paid || 0);
        const cl = Array.isArray(s.checklist) ? s.checklist : [];
        const $list = $row.find('.cptt-checkitems').first();
        cl.forEach(function (it, j) {
          const cid = it.id || uuid();
          const itemHtml = checkItemTpl
            .replaceAll('{{i}}', idx)
            .replaceAll('{{j}}', j)
            .replaceAll('{{cid}}', cid);
          const $itRow = $(itemHtml);
          $itRow.find('input[type="text"]').val(it.text || '');
          $itRow.find('input[type="url"]').val(it.url || '');
          $itRow.find('input[type="checkbox"]').prop('checked', !!it.done);
          $list.append($itRow);
        });
        const uts = Array.isArray(s.user_tasks) ? s.user_tasks : [];
        const $utList = $row.find('.cptt-usertasks').first();
        uts.forEach(function (ut, k) {
          const tid = ut.id || uuid();
          const taskHtml = userTaskTpl
            .replaceAll('{{i}}', idx)
            .replaceAll('{{k}}', k)
            .replaceAll('{{tid}}', tid);
          const $taskRow = $(taskHtml);
          $taskRow.find('input[type="text"]').val(ut.title || '');
          $taskRow.find('textarea').val(ut.desc || '');
          $taskRow.find('input[name$="[due_at_local]"]').val(ut.due_at_local || '');
          $taskRow.find('input[type="checkbox"]').prop('checked', ut.sms_remind !== 0);
          $utList.append($taskRow);
        });
        $rows.append($row);
      });
      fillStepExpertSelects($rows);
      reindexAll(); $(document).trigger('cptt:stepsChanged');
      $rows.sortable('refresh');
      $rows.find('.cptt-checkitems').each(function () {
        const $list = $(this);
        if ($list.data('sortable')) return;
        $list.sortable({
          items: '.cptt-checkitem-row',
          handle: '.cptt-checkitem-handle',
          axis: 'y',
          update: function () { reindexAll(); $(document).trigger('cptt:stepsChanged'); }
        });
        $list.data('sortable', true);
      });
      $rows.find('.cptt-step-cost, .cptt-step-paid').trigger('input');
    });
  });

  $rows.on('click', '.cptt-apply-checktpl', function () {
    const $step = $(this).closest('.cptt-step-row');
    const tplId = $step.find('.cptt-checktpl-select').val();
    if (!tplId) return;
    $.get(CPTT_ADMIN.ajax, {
      action: 'cptt_get_checklist_tpl',
      nonce: CPTT_ADMIN.nonce,
      checktpl_id: tplId
    }).done(function (res) {
      if (!res || !res.success) return alert('خطا در دریافت تمپلیت چک‌لیست');
      const items = res.data || [];
      const $list = $step.find('.cptt-checkitems').first();
      $list.empty();
      items.forEach(function (text, j) {
        const idx = $step.index();
        const cid = uuid();
        const html = checkItemTpl
          .replaceAll('{{i}}', idx)
          .replaceAll('{{j}}', j)
          .replaceAll('{{cid}}', cid);
        const $itRow = $(html);
        $itRow.find('input[type="text"]').val(text || '');
        $itRow.find('input[type="checkbox"]').prop('checked', false);
        $list.append($itRow);
      });
      reindexAll(); $(document).trigger('cptt:stepsChanged');
    });
  });

  $rows.on('click', '.cptt-add-usertask', function () {
    if (!userTaskTpl) return;
    const $step = $(this).closest('.cptt-step-row');
    const i = $step.index();
    const $list = $step.find('.cptt-usertasks').first();
    const k = $list.find('.cptt-usertask-row').length;
    const tid = uuid();
    const html = userTaskTpl
      .replaceAll('{{i}}', i)
      .replaceAll('{{k}}', k)
      .replaceAll('{{tid}}', tid);
    $list.append(html);
    reindexAll(); $(document).trigger('cptt:stepsChanged');
  });

  $rows.on('click', '.cptt-remove-usertask', function () {
    $(this).closest('.cptt-usertask-row').remove();
    reindexAll(); $(document).trigger('cptt:stepsChanged');
  });

  $('#cptt-add-checktpl-row').on('click', function () {
    const $c = $('#cptt-checktpl-rows');
    const tpl = $('#cptt-checktpl-row-template').html() || '';
    const i = $c.find('.cptt-checktpl-row').length;
    $c.append(tpl.replaceAll('{{i}}', i));
  });

  $(document).on('click', '.cptt-remove-checktpl-row', function () {
    $(this).closest('.cptt-checktpl-row').remove();
  });

  $(document).on('change', '.cptt-expertOpt input[type="checkbox"]', function(){
    $(this).closest('.cptt-expertOpt').toggleClass('is-checked', this.checked);
  });

  /* ===== Jalali datetime picker ===== */
  function cpttToEn(str){
    const fa='۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', en='01234567890123456789';
    return String(str||'').replace(/[۰-۹٠-٩]/g, ch => en[fa.indexOf(ch)] || ch);
  }
  function cpttToFa(str){ return String(str||'').replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]); }
  function g2j(gy, gm, gd){
    const gdm=[0,31,59,90,120,151,181,212,243,273,304,334];
    const gy2=(gm>2)?gy+1:gy;
    let days=355666+(365*gy)+Math.floor((gy2+3)/4)-Math.floor((gy2+99)/100)+Math.floor((gy2+399)/400)+gd+gdm[gm-1];
    let jy=-1595+33*Math.floor(days/12053); days%=12053; jy+=4*Math.floor(days/1461); days%=1461;
    if(days>365){ jy+=Math.floor((days-1)/365); days=(days-1)%365; }
    let jm,jd;
    if(days<186){ jm=1+Math.floor(days/31); jd=1+(days%31); }
    else { jm=7+Math.floor((days-186)/30); jd=1+((days-186)%30); }
    return [jy,jm,jd];
  }
  function j2g(jy,jm,jd){
    jy = parseInt(jy,10) + 1595;
    let days = -355668 + (365*jy) + Math.floor(jy/33)*8 + Math.floor(((jy%33)+3)/4) + parseInt(jd,10);
    days += (jm < 7) ? ((jm-1)*31) : (((jm-7)*30)+186);
    let gy = 400*Math.floor(days/146097); days%=146097;
    if(days>36524){ gy += 100*Math.floor(--days/36524); days%=36524; if(days>=365) days++; }
    gy += 4*Math.floor(days/1461); days%=1461;
    if(days>365){ gy += Math.floor((days-1)/365); days=(days-1)%365; }
    let gd=days+1;
    const sal=[0,31,(((gy%4===0)&&(gy%100!==0))||(gy%400===0))?29:28,31,30,31,30,31,31,30,31,30,31];
    let gm=1; for(;gm<=12;gm++){ if(gd<=sal[gm]) break; gd-=sal[gm]; }
    return [gy,gm,gd];
  }
  function monthLen(jy,jm){ return jm<=6?31:(jm<=11?30:30); }
  const monthNames=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
  let activeDateInput=null, viewJ=null;
  const $cal = $('<div class="cptt-jdp" dir="rtl" style="display:none"></div>').appendTo('body');

  function drawCal(){
    if(!activeDateInput || !viewJ) return;
    const ml=monthLen(viewJ.jy, viewJ.jm);
    const g=j2g(viewJ.jy, viewJ.jm, 1);
    const first=new Date(g[0], g[1]-1, g[2]).getDay();
    const start=(first+1)%7;
    const n=new Date(); const tj=g2j(n.getFullYear(),n.getMonth()+1,n.getDate());
    let html='<div class="cptt-jdp__head"><button type="button" data-nav="prev">‹</button><strong>'+monthNames[viewJ.jm-1]+' '+cpttToFa(viewJ.jy)+'</strong><button type="button" data-nav="next">›</button></div>';
    html+='<div class="cptt-jdp__week"><span>ش</span><span>ی</span><span>د</span><span>س</span><span>چ</span><span>پ</span><span>ج</span></div><div class="cptt-jdp__days">';
    for(let i=0;i<start;i++) html+='<span></span>';
    for(let d=1; d<=ml; d++) {
      let cls='';
      if (viewJ.jd===d) cls+=' is-selected';
      if (tj[0]===viewJ.jy && tj[1]===viewJ.jm && tj[2]===d) cls+=' is-today';
      html+='<button type="button" class="'+cls.trim()+'" data-day="'+d+'">'+cpttToFa(d)+'</button>';
    }
    html+='</div><div class="cptt-jdp__time"><input type="number" min="0" max="23" value="'+String(viewJ.hh||12).padStart(2,'0')+'"><span>:</span><input type="number" min="0" max="59" value="'+String(viewJ.ii||0).padStart(2,'0')+'"></div><div class="cptt-jdp__foot"><button type="button" data-today="1">امروز</button><button type="button" data-close="1">بستن</button></div>';
    $cal.html(html);
  }
  function openCal(input){
    activeDateInput=input;
    const parsed=$(input).val().match(/(\d{4})\/(\d{1,2})\/(\d{1,2})(?:\s+(\d{1,2}):(\d{1,2}))?/);
    if(parsed){ viewJ={jy:+cpttToEn(parsed[1]),jm:+cpttToEn(parsed[2]),jd:+cpttToEn(parsed[3]),hh:parsed[4]?+parsed[4]:12,ii:parsed[5]?+parsed[5]:0}; }
    else { const n=new Date(); const j=g2j(n.getFullYear(),n.getMonth()+1,n.getDate()); viewJ={jy:j[0],jm:j[1],jd:j[2],hh:n.getHours(),ii:n.getMinutes()}; }
    drawCal();
    const off=$(input).offset();
    $cal.css({top:off.top+$(input).outerHeight()+6,left:off.left,display:'block'});
  }
  function setInputDate(day){
    const h=Math.max(0,Math.min(23,parseInt($cal.find('.cptt-jdp__time input').eq(0).val()||'0',10)));
    const m=Math.max(0,Math.min(59,parseInt($cal.find('.cptt-jdp__time input').eq(1).val()||'0',10)));
    const val=viewJ.jy+'/'+String(viewJ.jm).padStart(2,'0')+'/'+String(day).padStart(2,'0')+' '+String(h).padStart(2,'0')+':'+String(m).padStart(2,'0');
    $(activeDateInput).val(cpttToFa(val)).trigger('change');
    $cal.hide();
  }
  $(document).on('focus click', '.cptt-jalali-datetime', function(){ openCal(this); });
  $cal.on('click','[data-nav]',function(){ const dir=$(this).data('nav'); viewJ.jm += dir==='next'?1:-1; if(viewJ.jm>12){viewJ.jm=1;viewJ.jy++;} if(viewJ.jm<1){viewJ.jm=12;viewJ.jy--;} drawCal(); });
  $cal.on('click','[data-day]',function(){ setInputDate(parseInt($(this).data('day'),10)); });
  $cal.on('click','[data-today]',function(){ const n=new Date(); const j=g2j(n.getFullYear(),n.getMonth()+1,n.getDate()); viewJ={jy:j[0],jm:j[1],jd:j[2],hh:n.getHours(),ii:n.getMinutes()}; setDateCal(j[2]); });
  $cal.on('click','[data-close]',function(){ $cal.hide(); });
  $(document).on('mousedown', function(e){ if(!$(e.target).closest('.cptt-jdp,.cptt-jalali-datetime').length) $cal.hide(); });

  /* ===== Dashboard filters ===== */
  function filterDashboard(){
    const q=($('#cptt-dash-search').val()||'').toLowerCase().trim();
    const expert=$('#cptt-dash-expert').val()||'';
    const status=$('#cptt-dash-status').val()||'';
    const client=$('#cptt-dash-client').val()||'';
    const settled=$('#cptt-dash-settled').val();
    const cat=$('#cptt-dash-cat').val()||'';
    const product=$('#cptt-dash-product').val()||'';
    let count=0;
    $('.cptt-project-card').each(function(){
      const $c=$(this); let ok=true;
      if(q && ($c.data('search')||'').indexOf(q)===-1) ok=false;
      if(expert && String($c.attr('data-experts')||'').indexOf(','+expert+',')===-1) ok=false;
      if(status && $c.attr('data-status')!==status) ok=false;
      if(client && $c.attr('data-client')!==client) ok=false;
      if(settled!=='' && settled!=null && $c.attr('data-settled')!==settled) ok=false;
      if(cat && String($c.attr('data-cats')||'').indexOf(','+cat+',')===-1) ok=false;
      if(product && $c.attr('data-product')!==product) ok=false;
      $c.toggle(ok);
      if(ok) count++;
    });
    $('#cptt-dash-count').text(count);
    $('#cptt-dash-empty').toggle(count===0);
  }
  $(document).on('input change', '#cptt-dash-search,#cptt-dash-expert,#cptt-dash-status,#cptt-dash-client,#cptt-dash-settled,#cptt-dash-cat,#cptt-dash-product', filterDashboard);
  $(document).on('click', '#cptt-dash-reset', function(){ $('.cptt-dashboard__filters input,.cptt-dashboard__filters select').val(''); filterDashboard(); });

  /* ===== Accounting filters ===== */
  function parseFaDateToTs(faStr) {
    if (!faStr) return 0;
    const s = cpttToEn(String(faStr)).trim();
    const m = s.match(/(\d{4})\/(\d{1,2})\/(\d{1,2})(?:\s+(\d{1,2}):(\d{1,2}))?/);
    if (!m) return 0;
    const jy = parseInt(m[1], 10), jm = parseInt(m[2], 10), jd = parseInt(m[3], 10);
    const hh = m[4] ? parseInt(m[4], 10) : 0, ii = m[5] ? parseInt(m[5], 10) : 0;
    const g = j2g(jy, jm, jd);
    const d = new Date(g[0], g[1] - 1, g[2], hh, ii, 0);
    return Math.floor(d.getTime() / 1000);
  }

  function filterAccounting(){
    const q=($('#cptt-acct-search').val()||'').toLowerCase().trim();
    const client=$('#cptt-acct-client').val()||'';
    const settled=$('#cptt-acct-settled').val();
    const status=$('#cptt-acct-status').val()||'';
    
    const fromStr = $('#cptt-acct-from').val();
    const toStr = $('#cptt-acct-to').val();
    const from = fromStr ? parseFaDateToTs(fromStr) : 0;
    const to = toStr ? parseFaDateToTs(toStr) + 86399 : 0;

    let count=0;
    $('.cptt-acct-row').each(function(){
      const $r=$(this); let ok=true;
      if(q && ($r.data('search')||'').indexOf(q)===-1) ok=false;
      if(client && $r.attr('data-client')!==client) ok=false;
      if(settled!=='' && settled!=null && $r.attr('data-settled')!==settled) ok=false;
      if(status && $r.attr('data-status')!==status) ok=false;
      const cts=parseInt($r.attr('data-created-ts')||'0',10);
      if(from && cts && cts<from) ok=false;
      if(to && cts && cts>to) ok=false;
      $r.toggle(ok);
      if(ok) count++;
    });
    $('#cptt-acct-empty').toggle(count===0);
    if (window.cpttRenderAccountingCharts) window.cpttRenderAccountingCharts();
  }
  $(document).on('input change', '#cptt-acct-search,#cptt-acct-client,#cptt-acct-settled,#cptt-acct-status,#cptt-acct-from,#cptt-acct-to', filterAccounting);
  $(document).on('click', '#cptt-acct-reset', function(){ $('.cptt-acct-filters input,.cptt-acct-filters select').val(''); filterAccounting(); });

  /* ===== Print accounting report ===== */
  $(document).on('click', '#cptt-acct-print', function() {
    var $visibleRows = $('.cptt-acct-row:visible');
    if (!$visibleRows.length) {
      alert('هیچ پروژه‌ای در لیست برای چاپ وجود ندارد.');
      return;
    }
    
    var brand = CPTT_ADMIN.branding || {};
    var brandName = brand.brand_name || 'مدیریت پروژه‌ها';
    var logoUrl = CPTT_ADMIN.logo_url || '';
    var siteUrl = brand.site_url || '';
    var dateFa = cpttToFa(new Date().toLocaleDateString('fa-IR'));
    
    var html = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">';
    html += '<title>گزارش مالی پروژه‌ها</title>';
    html += '<style>';
    html += 'body{ font-family: Tahoma, sans-serif; font-size:12px; line-height:1.6; color:#333; margin:20px; direction: rtl; text-align: right; }';
    html += '.header-table{ width:100%; border-collapse:collapse; margin-bottom:20px; }';
    html += '.header-table td{ padding:6px; border:none; vertical-align:middle; }';
    html += '.brand-logo{ max-height:60px; max-width:180px; object-fit:contain; }';
    html += '.report-title{ font-size:18px; font-weight:bold; color:#111; margin:0; }';
    html += '.report-meta{ font-size:11px; color:#666; margin-top:4px; }';
    html += '.report-table{ width:100%; border-collapse:collapse; margin-top:15px; }';
    html += '.report-table th, .report-table td{ border:1px solid #ddd; padding:8px 10px; text-align:right; }';
    html += '.report-table th{ background:#f5f5f5; font-weight:bold; }';
    html += '.text-left{ text-align:left !important; }';
    html += '.text-center{ text-align:center !important; }';
    html += '.total-row{ font-weight:bold; background:#fafafa; }';
    html += '@media print{ .no-print{ display:none; } }';
    html += '</style></head><body>';
    
    html += '<table class="header-table"><tr>';
    if (logoUrl) {
      html += '<td style="width:120px;"><img src="' + logoUrl + '" class="brand-logo" /></td>';
    }
    html += '<td><div class="report-title">گزارش مالی پروژه‌ها</div><div class="report-meta">نام برند: ' + brandName + ' | آدرس سایت: ' + siteUrl + '</div></td>';
    html += '<td style="text-align:left; vertical-align:top;"><div class="report-meta">تاریخ گزارش: ' + dateFa + '</div><div class="report-meta no-print" style="margin-top:10px;"><button onclick="window.print()" style="padding:6px 12px; background:#059669; border:none; color:#fff; font-weight:bold; border-radius:6px; cursor:pointer;">🖨 چاپ</button></div></td>';
    html += '</tr></table>';
    
    html += '<table class="report-table"><thead><tr>';
    html += '<th>ردیف</th>';
    html += '<th>عنوان پروژه</th>';
    html += '<th>مشتری</th>';
    html += '<th>شماره موبایل</th>';
    html += '<th>تاریخ ایجاد</th>';
    html += '<th class="text-left">کل هزینه (تومان)</th>';
    html += '<th class="text-left">دریافتی (تومان)</th>';
    html += '<th class="text-left">مانده (تومان)</th>';
    html += '</tr></thead><tbody>';
    
    var totalCost = 0;
    var totalPaid = 0;
    var totalRemain = 0;
    
    $visibleRows.each(function(index) {
      var $row = $(this);
      var title = $row.find('.cptt-acct-title').text().trim();
      var experts = $row.find('.cptt-acct-meta').text().trim();
      var client = $row.find('td').eq(1).text().trim();
      var phone = $row.attr('data-phone') || '';
      var created = $row.attr('data-created') || '';
      
      var cost = parseFloat($row.find('td').eq(4).text().replace(/,/g, '')) || 0;
      var paid = parseFloat($row.find('td').eq(5).text().replace(/,/g, '')) || 0;
      var remain = parseFloat($row.find('td').eq(6).text().replace(/,/g, '')) || 0;
      
      totalCost += cost;
      totalPaid += paid;
      totalRemain += remain;
      
      html += '<tr>';
      html += '<td class="text-center">' + cpttToFa(index + 1) + '</td>';
      html += '<td><b>' + title + '</b><div style="font-size:10px; color:#666; margin-top:2px;">' + experts + '</div></td>';
      html += '<td>' + client + '</td>';
      html += '<td>' + phone + '</td>';
      html += '<td>' + created + '</td>';
      html += '<td class="text-left">' + cpttToFa(cost.toLocaleString('en')) + '</td>';
      html += '<td class="text-left" style="color:#15803d;">' + cpttToFa(paid.toLocaleString('en')) + '</td>';
      html += '<td class="text-left" style="color:' + (remain > 0 ? '#b91c1c' : '#15803d') + '; font-weight:bold;">' + cpttToFa(remain.toLocaleString('en')) + '</td>';
      html += '</tr>';
    });
    
    html += '<tr class="total-row">';
    html += '<td colspan="5" style="text-align:left;">جمع کل گزارش:</td>';
    html += '<td class="text-left">' + cpttToFa(totalCost.toLocaleString('en')) + '</td>';
    html += '<td class="text-left" style="color:#15803d;">' + cpttToFa(totalPaid.toLocaleString('en')) + '</td>';
    html += '<td class="text-left" style="color:' + (totalRemain > 0 ? '#b91c1c' : '#15803d') + ';">' + cpttToFa(totalRemain.toLocaleString('en')) + '</td>';
    html += '</tr>';
    
    html += '</tbody></table>';
    html += '<div style="margin-top:40px; text-align:center; font-size:10px; color:#999;">تولید شده توسط سیستم حسابداری Client Project Tracker</div>';
    html += '</body></html>';
    
    var w = window.open('', '_blank');
    w.document.write(html);
    w.document.close();
    w.focus();
  });

  /* ===== Product filter by WC category ===== */
  $(document).on('change', '#cptt_wc_cats', function(){
    var selected = $(this).val() || [];
    if (!selected.length) { $('#cptt_product_id option').show(); return; }
    $('#cptt_product_id option').each(function(){
      var $opt = $(this);
      var cats = String($opt.data('cats')||'').split(',').map(Number).filter(Boolean);
      var show = selected.some(function(id){ return cats.indexOf(parseInt(id,10)) !== -1; });
      $opt.toggle(show);
    });
    var current = $('#cptt_product_id').val();
    if (current && $('#cptt_product_id option[value="'+current+'"]').is(':hidden')) {
      $('#cptt_product_id').val('');
    }
  });
  (function(){ $('#cptt_wc_cats').trigger('change'); })();

  /* ===== Step billing live remain ===== */
  $(document).on('input', '.cptt-step-cost, .cptt-step-paid', function(){
    var $card = $(this).closest('.cptt-stepCard');
    var cost = parseFloat($card.find('.cptt-step-cost').val()) || 0;
    var paid = parseFloat($card.find('.cptt-step-paid').val()) || 0;
    var remain = cost - paid;
    $card.find('.cptt-step-remain').text('مانده: ' + remain.toLocaleString('fa-IR'));
  });

});

/* ===== Pro UI enhancements: dashboard KPIs + step accordions ===== */
jQuery(function($){
  function buildDashboardKpis(){
    var $dash = $('.cptt-dashboard').first();
    if (!$dash.length || !$dash.find('#cptt-dash-grid').length) return;
    var total = $dash.find('.cptt-project-card').length;
    var completed = $dash.find('.cptt-project-card[data-status="completed"]').length;
    var inProgress = $dash.find('.cptt-project-card[data-status="in_progress"]').length;
    var unsettled = $dash.find('.cptt-project-card[data-settled="0"]').length;
    var html = ''+
      '<div class="cptt-proKpis">'+
        '<div class="cptt-proKpi"><span>کل پروژه‌ها</span><strong>'+ total +'</strong></div>'+
        '<div class="cptt-proKpi"><span>در حال انجام</span><strong>'+ inProgress +'</strong></div>'+
        '<div class="cptt-proKpi"><span>تکمیل‌شده</span><strong>'+ completed +'</strong></div>'+
        '<div class="cptt-proKpi"><span>تسویه‌نشده</span><strong>'+ unsettled +'</strong></div>'+
      '</div>';
    $dash.find('.cptt-proKpis').remove();
    $dash.find('.cptt-dashboard__insights').first().before(html);
  }

  function stepSummaryHtml($row){
    var $titleInput = $row.find('.cptt-stepCard__title input[type="text"]').first();
    var title = $.trim($titleInput.val() || '') || 'مرحله بدون عنوان';
    var statusText = $row.find('.cptt-stepCard__status select option:selected').text() || 'انجام‌نشده';
    var due = $.trim($row.find('input[name*="[due_at_local]"]').first().val() || '');
    var checkTotal = $row.find('.cptt-checkitem-row').length;
    var checkDone = $row.find('.cptt-checkitem-row input[type="checkbox"]:checked').length;
    var tasks = $row.find('.cptt-usertask-row').length;
    var meta = [];
    meta.push(statusText);
    if (checkTotal) meta.push('چک‌لیست ' + checkDone + '/' + checkTotal);
    if (tasks) meta.push('تسک مشتری ' + tasks);
    if (due) meta.push('مهلت ' + due);
    return ''+
      '<span class="cptt-stepCard__summaryMain">'+
        '<strong>'+ $('<div/>').text(title).html() +'</strong>'+
        '<small>'+ $('<div/>').text(meta.join(' • ')).html() +'</small>'+
      '</span>'+
      '<span class="cptt-stepCard__summarySide">'+
        '<span class="cptt-stepCard__summaryArrow">⌄</span>'+
      '</span>';
  }

  function ensureStepAccordion($row){
    if (!$row || !$row.length) return;
    var $card = $row.find('.cptt-stepCard').first();
    if (!$card.length) return;
    if (!$card.children('.cptt-stepCard__content').length) {
      var $head = $card.children('.cptt-stepCard__head');
      var $body = $card.children('.cptt-stepCard__body');
      $head.add($body).wrapAll('<div class="cptt-stepCard__content"></div>');
    }
    if (!$card.children('.cptt-stepCard__summary').length) {
      $card.prepend('<button type="button" class="cptt-stepCard__summary" aria-expanded="false"></button>');
    }
    $card.children('.cptt-stepCard__summary').html(stepSummaryHtml($row));
  }

  function refreshStepAccordions(){
    var $stepRows = $('#cptt-steps-rows .cptt-step-row');
    if (!$stepRows.length) return;
    $stepRows.each(function(){ ensureStepAccordion($(this)); });
    if (!$stepRows.filter('.is-open').length) {
      $stepRows.first().addClass('is-open');
    }
    $stepRows.each(function(){
      var $row = $(this);
      var $content = $row.find('.cptt-stepCard__content').first();
      var $summary = $row.find('.cptt-stepCard__summary').first();
      var open = $row.hasClass('is-open');
      $content.toggle(open);
      $summary.attr('aria-expanded', open ? 'true' : 'false');
    });
  }

  $(document).on('click', '.cptt-stepCard__summary', function(){
    var $row = $(this).closest('.cptt-step-row');
    var isOpen = $row.hasClass('is-open');
    $('#cptt-steps-rows .cptt-step-row').removeClass('is-open').find('.cptt-stepCard__content').slideUp(140);
    $('#cptt-steps-rows .cptt-stepCard__summary').attr('aria-expanded', 'false');
    if (!isOpen) {
      $row.addClass('is-open');
      $row.find('.cptt-stepCard__content').first().slideDown(140);
      $(this).attr('aria-expanded', 'true');
    }
  });

  $(document).on('input change', '#cptt-steps-rows .cptt-stepCard input, #cptt-steps-rows .cptt-stepCard select, #cptt-steps-rows .cptt-stepCard textarea', function(){
    var $row = $(this).closest('.cptt-step-row');
    var $summary = $row.find('.cptt-stepCard__summary').first();
    if ($summary.length) $summary.html(stepSummaryHtml($row));
  });

  var refreshTimer = null;
  function scheduleRefresh(){
    window.clearTimeout(refreshTimer);
    refreshTimer = window.setTimeout(function(){
      buildDashboardKpis();
      refreshStepAccordions();
    }, 80);
  }

  $(document).on('click', '#cptt-add-step, .cptt-remove-step, .cptt-add-checkitem, .cptt-remove-checkitem, .cptt-add-usertask, .cptt-remove-usertask, .cptt-apply-checktpl, #cptt_apply_template_btn', scheduleRefresh);
  $(document).on('cptt:stepsChanged', scheduleRefresh);

  buildDashboardKpis();
  refreshStepAccordions();
});

  // Currency formatter
  document.addEventListener('keyup', function(e) {
      if (e.target && e.target.classList.contains('cptt-currency-input')) {
          var val = e.target.value.replace(/[^\d]/g, '');
          if (val) {
              e.target.value = parseInt(val, 10).toLocaleString('en-US');
          } else {
              e.target.value = '';
          }
      }
  });


  /* ===== Register Expert Payout (v5.4.0) ===== */
  $(document).on('click', '.cptt-record-payout-btn', function(e) {
    e.preventDefault();
    var expertId = $(this).data('expert-id');
    var expertName = $(this).data('expert-name');
    var remain = $(this).data('remain');
    
    $('#cptt-payout-expert-id').val(expertId);
    $('#cptt-payout-expert-name').val(expertName);
    $('#cptt-payout-expert-remain').val(parseInt(remain).toLocaleString('en-US'));
    $('#cptt-payout-amount').val(parseInt(remain).toLocaleString('en-US')).focus();
    $('#cptt-payout-msg').text('').css('color', '');
    
    $('#cptt-payout-modal').css('display', 'flex');
  });

  $(document).on('click', '#cptt-payout-close, #cptt-payout-modal', function(e) {
    if (e.target === this || e.target.id === 'cptt-payout-close') {
      $('#cptt-payout-modal').hide();
    }
  });

  $(document).on('submit', '#cptt-payout-form', function(e) {
    e.preventDefault();
    var $msg = $('#cptt-payout-msg');
    var $btn = $(this).find('button[type="submit"]');
    
    $msg.text('در حال ثبت تسویه حساب...').css('color', '#475569');
    $btn.prop('disabled', true);
    
    var fd = new FormData(this);
    fd.append('action', 'cptt_expert_payout');
    fd.append('nonce', CPTT_ADMIN.nonce);
    
    $.ajax({
      url: CPTT_ADMIN.ajax,
      type: 'POST',
      data: fd,
      processData: false,
      contentType: false,
      success: function(res) {
        if (res.success) {
          $msg.text('✓ ' + res.data).css('color', '#059669');
          setTimeout(function() {
            window.location.reload();
          }, 1200);
        } else {
          $msg.text('✗ ' + res.data).css('color', '#dc2626');
          $btn.prop('disabled', false);
        }
      },
      error: function() {
        $msg.text('✗ خطای ارتباط با سرور').css('color', '#dc2626');
        $btn.prop('disabled', false);
      }
    });
  });

jQuery(function($){
  var $client = $('#cptt_client_user_id');
  if ($client.length && !$client.data('cpttClientSearch')) {
    $client.data('cpttClientSearch', 1);
    var $wrap = $('<div class="cptt-admin-client-search" style="display:grid;grid-template-columns:auto 1fr;gap:8px;max-width:420px;"></div>');
    var $btn = $('<button type="button" class="button" title="جستجوی مشتری">🔎</button>');
    var $inp = $('<input type="search" placeholder="جستجوی نام، نام خانوادگی یا شماره تماس..." style="display:none;" />');
    $client.before($wrap); $wrap.append($btn, $inp, $client);
    $client.css('grid-column','1/-1');
    $btn.on('click', function(){ $inp.toggle(); if($inp.is(':visible')) $inp.trigger('focus'); });
    $inp.on('input', function(){
      var q = String($inp.val() || '').toLowerCase();
      $client.find('option').each(function(){
        if (!this.value) { this.hidden = false; return; }
        var hay = String(($(this).data('search') || '') + ' ' + $(this).text()).toLowerCase();
        this.hidden = q && hay.indexOf(q) === -1;
      });
    });
  }
});

/* v5.4.14 professional accounting dashboard: date filters, exports, charts */
jQuery(function($){
  function money(n){ return (Number(n)||0).toLocaleString('en-US'); }
  function visibleAcctRows(){ return $('.cptt-acct-row:visible'); }
  function renderBarChart($el, data){
    if(!$el.length) return;
    var keys = Object.keys(data || {});
    if(!keys.length){ $el.html('<div class="cptt-chart-empty">داده‌ای برای نمایش نیست</div>'); return; }
    var max = Math.max.apply(null, keys.map(k=>Math.abs(data[k])||0)) || 1;
    $el.html(keys.map(function(k){ var v=data[k]||0; var w=Math.max(3, Math.round(Math.abs(v)/max*100)); return '<div class="cptt-chart-row"><span>'+k+'</span><div><i style="width:'+w+'%"></i></div><b>'+money(v)+'</b></div>'; }).join(''));
  }
  window.cpttRenderAccountingCharts = function(){
    var monthly={}, debtors={}, expertPay={};
    visibleAcctRows().each(function(){
      var $r=$(this), m=$r.attr('data-month')||'—', paid=parseFloat($r.attr('data-paid')||0), remain=parseFloat($r.attr('data-remain')||0), client=$r.find('td').eq(1).text().trim()||'—';
      monthly[m]=(monthly[m]||0)+paid; if(remain>0) debtors[client]=(debtors[client]||0)+remain;
    });
    $('.cptt-step-settle-row').each(function(){ var $r=$(this), name=$r.find('td').eq(2).text().trim()||'—', paid=parseFloat($r.find('td').eq(4).text().replace(/,/g,''))||0; if(paid>0) expertPay[name]=(expertPay[name]||0)+paid; });
    renderBarChart($('#cptt-chart-monthly'), monthly);
    renderBarChart($('#cptt-chart-debtors'), debtors);
    renderBarChart($('#cptt-chart-experts-pay'), expertPay);
  };
  var oldFilter = window.cpttAcctFilterHooked;
  $(document).on('input change', '#cptt-acct-from,#cptt-acct-to', function(){ $('#cptt-acct-search').trigger('input'); });
  // v5.4.17: دکمه‌های اکسل/PDF از UI حذف شدند (طبق درخواست). handlerها نگه‌داشته نشدند.
  setTimeout(window.cpttRenderAccountingCharts, 300);
});
