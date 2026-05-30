(function () {
  'use strict';

  function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

  function escapeHtml(str) {
    return String(str || '').replace(/[&<>"']/g, function (m) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[m];
    });
  }

  /* =========================================================
     NOTIFICATION POLLING
     ========================================================= */
  /* ---------------------------------------------------------------
     Push Notification (v5.4.3)
     - استفاده از آی‌دی هر اعلان برای جلوگیری از تکرار
     - عنوان نوتیفیکیشن بر اساس نوع/متن واقعی هر اعلان
     - ذخیره‌ی آی‌دی‌های نمایش‌داده‌شده در localStorage تا بعد از رفرش هم تکرار نشود
  --------------------------------------------------------------- */
  var CPTT_PUSH_STORAGE_KEY = 'cptt_pushed_notif_ids_v1';
  var CPTT_PUSH_MAX_KEEP = 200;

  function loadPushedIds() {
    try {
      var raw = localStorage.getItem(CPTT_PUSH_STORAGE_KEY);
      if (!raw) return {};
      var obj = JSON.parse(raw);
      return (obj && typeof obj === 'object') ? obj : {};
    } catch (e) { return {}; }
  }
  function savePushedIds(obj) {
    try {
      // محدودسازی حجم: اگر بیش از حد بزرگ شد، فقط جدیدترین‌ها نگه داشته شوند
      var keys = Object.keys(obj);
      if (keys.length > CPTT_PUSH_MAX_KEEP) {
        keys.sort(function(a,b){ return (obj[a]||0) - (obj[b]||0); });
        var drop = keys.length - CPTT_PUSH_MAX_KEEP;
        for (var i = 0; i < drop; i++) delete obj[keys[i]];
      }
      localStorage.setItem(CPTT_PUSH_STORAGE_KEY, JSON.stringify(obj));
    } catch (e) {}
  }

  // نگاشت نوع اعلان به عنوان فارسی مناسب push
  var CPTT_NOTIF_TITLES = {
    project_assigned:  '📌 پروژه‌ی جدید به شما واگذار شد',
    project_removed:   '🚫 از پروژه حذف شدید',
    step_completed:    '✅ یک مرحله انجام شد',
    project_note:      '📝 یادداشت جدید در پروژه',
    project_completed: '🎉 پروژه تکمیل شد',
    project_chat:      '💬 پیام جدید در چت پروژه',
    direct_chat:       '📨 پیام مستقیم جدید',
    expert_payout:     '💰 تسویه حساب جدید',
    user_task_done:    '🧩 پاسخ مشتری ثبت شد'
  };
  function titleForNotif(type, message) {
    if (type && CPTT_NOTIF_TITLES[type]) return CPTT_NOTIF_TITLES[type];
    // fallback: چند کلمه‌ی اول پیام
    var m = String(message || '').replace(/\s+/g, ' ').trim();
    if (!m) return '🔔 اعلان جدید';
    return m.length > 50 ? m.slice(0, 50) + '…' : m;
  }

  var cpttPollInFlight = false;
  var cpttFirstPoll = true; // پولینگ اولِ بار: فقط ID ها را seed کن، push نزن

  function pollNotifications() {
    if (!qs('.cptt-notification-bell')) return;
    if (cpttPollInFlight) return; // جلوگیری از تداخل درخواست‌های همزمان
    cpttPollInFlight = true;

    var fd = new FormData();
    fd.append('action', 'cptt_expert_fetch_notifications');
    fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
    fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', {
      method: 'POST', credentials: 'same-origin', body: fd
    }).then(function(r){ return r.json(); }).then(function(json){
      if (!json || !json.success) return;
      var data = json.data || {};
      var badge = qs('.cptt-bell-badge');
      var list = qs('.cptt-notifications-list');

      var currentUnread = parseInt(data.unread || 0, 10);
      var items = Array.isArray(data.items) ? data.items : [];

      // Push برای هر اعلان خوانده‌نشده‌ی جدید (که قبلا push نشده)
      if (window.Notification && Notification.permission === 'granted' && items.length) {
        var pushed = loadPushedIds();
        var now = Date.now();
        var changed = false;
        // مرتب‌سازی صعودی بر اساس id تا اگر چند تا جدید باشد به ترتیب push شوند
        items.slice().sort(function(a,b){ return (parseInt(a.id,10)||0) - (parseInt(b.id,10)||0); })
        .forEach(function(it){
          var nid = String(it.id || '');
          if (!nid) return;
          if (pushed[nid]) return;
          // در پولینگ اول، فقط آی‌دی‌ها را seed کن تا اعلان‌های قدیمی موجود در دیتابیس
          // بعد از باز کردن صفحه دوباره به‌صورت push نمایش داده نشوند.
          if (cpttFirstPoll) {
            pushed[nid] = now;
            changed = true;
            return;
          }
          if (parseInt(it.is_read, 10) === 1) {
            // قبلا خوانده شده؛ push نمی‌کنیم ولی به دفتر اضافه می‌کنیم تا بعدا تکرار نشود
            pushed[nid] = now;
            changed = true;
            return;
          }
          try {
            var n = new Notification(titleForNotif(it.type, it.message), {
              body: String(it.message || ''),
              dir: 'rtl',
              lang: 'fa',
              tag: 'cptt-notif-' + nid,  // tag یکتا → جلوگیری از تکرار توسط مرورگر
              renotify: false,
              icon: (window.CPTT_EXPERT && CPTT_EXPERT.notif_icon) ? CPTT_EXPERT.notif_icon : undefined
            });
            if (it.link) {
              n.onclick = function(){
                try { window.focus(); window.location.href = it.link; } catch(e){}
                this.close();
              };
            }
          } catch(e) {}
          pushed[nid] = now;
          changed = true;
        });
        if (changed) savePushedIds(pushed);
      }
      cpttFirstPoll = false;

      if (badge) {
        if (currentUnread > 0) { badge.textContent = currentUnread; badge.style.display = 'flex'; }
        else { badge.style.display = 'none'; }
      }
      if (list && data.html) {
        list.innerHTML = data.html;
      }
    }).catch(function(e){ /* silently ignore polling errors */ })
    .finally(function(){ cpttPollInFlight = false; });
  }

  /* =========================================================
     DARK MODE
     ========================================================= */
  function initDarkMode() {
    if (!document.body.classList.contains('cptt-expert-dashboard-page')) {
        return;
    }
    var saved = localStorage.getItem('cptt_dark_mode');
    if (saved === '1') document.body.classList.add('cptt-dark');
    
    qsa('.cptt-dark-toggle-icon').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.body.classList.toggle('cptt-dark');
            var on = document.body.classList.contains('cptt-dark');
            localStorage.setItem('cptt_dark_mode', on ? '1' : '0');
        });
    });
  }

  /* =========================================================
     JALALI DATE PICKER
     ========================================================= */
  function initJalaliPicker() {
    function toFa(str) { return String(str || '').replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
    function toEn(str) { var fa = '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', en = '01234567890123456789'; return String(str || '').replace(/[۰-۹٠-٩]/g, function (ch) { return en[fa.indexOf(ch)] || ch; }); }
    function g2j(gy, gm, gd) {
      var gdm = [0,31,59,90,120,151,181,212,243,273,304,334];
      var gy2 = (gm > 2) ? gy + 1 : gy;
      var days = 355666 + (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) + gd + gdm[gm - 1];
      var jy = -1595 + 33 * Math.floor(days / 12053); days %= 12053;
      jy += 4 * Math.floor(days / 1461); days %= 1461;
      if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
      var jm, jd;
      if (days < 186) { jm = 1 + Math.floor(days / 31); jd = 1 + (days % 31); }
      else { jm = 7 + Math.floor((days - 186) / 30); jd = 1 + ((days - 186) % 30); }
      return [jy, jm, jd];
    }
    function j2g(jy, jm, jd) {
      jy = parseInt(jy, 10) + 1595;
      var days = -355668 + (365 * jy) + Math.floor(jy / 33) * 8 + Math.floor(((jy % 33) + 3) / 4) + parseInt(jd, 10);
      days += (jm < 7) ? ((jm - 1) * 31) : (((jm - 7) * 30) + 186);
      var gy = 400 * Math.floor(days / 146097); days %= 146097;
      if (days > 36524) { gy += 100 * Math.floor(--days / 36524); days %= 36524; if (days >= 365) days++; }
      gy += 4 * Math.floor(days / 1461); days %= 1461;
      if (days > 365) { gy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
      var gd = days + 1;
      var sal = [0,31,(((gy % 4 === 0) && (gy % 100 !== 0)) || (gy % 400 === 0)) ? 29 : 28,31,30,31,30,31,31,30,31,30,31];
      var gm = 1; for (; gm <= 12; gm++) { if (gd <= sal[gm]) break; gd -= sal[gm]; }
      return [gy, gm, gd];
    }
    function monthLen(jy, jm) { return jm <= 6 ? 31 : (jm <= 11 ? 30 : 30); }
    var monthNames = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    var activeInput = null, viewJ = null;
    var cal = document.createElement('div');
    cal.className = 'cptt-jdp';
    cal.dir = 'rtl';
    cal.style.display = 'none';
    document.body.appendChild(cal);
    function draw() {
      if (!activeInput || !viewJ) return;
      var ml = monthLen(viewJ.jy, viewJ.jm);
      var g = j2g(viewJ.jy, viewJ.jm, 1);
      var first = new Date(g[0], g[1] - 1, g[2]).getDay();
      var start = (first + 1) % 7;
      var now = new Date(); var tj = g2j(now.getFullYear(), now.getMonth() + 1, now.getDate());
      var html = '<div class="cptt-jdp__head"><button type="button" data-nav="prev">‹</button><strong>' + monthNames[viewJ.jm - 1] + ' ' + toFa(viewJ.jy) + '</strong><button type="button" data-nav="next">›</button></div>';
      html += '<div class="cptt-jdp__week"><span>ش</span><span>ی</span><span>د</span><span>س</span><span>چ</span><span>پ</span><span>ج</span></div><div class="cptt-jdp__days">';
      for (var i = 0; i < start; i++) html += '<span></span>';
      for (var d = 1; d <= ml; d++) {
        var cls = '';
        if (viewJ.jd === d) cls += ' is-selected';
        if (tj[0] === viewJ.jy && tj[1] === viewJ.jm && tj[2] === d) cls += ' is-today';
        html += '<button type="button" class="' + cls.trim() + '" data-day="' + d + '">' + toFa(d) + '</button>';
      }
      html += '</div><div class="cptt-jdp__time"><input type="number" min="0" max="23" value="' + String(viewJ.hh || 12).padStart(2, '0') + '"><span>:</span><input type="number" min="0" max="59" value="' + String(viewJ.ii || 0).padStart(2, '0') + '"></div><div class="cptt-jdp__foot"><button type="button" data-today="1">امروز</button><button type="button" data-close="1">بستن</button></div>';
      cal.innerHTML = html;
    }
    function open(input) {
      activeInput = input;
      var m = String(input.value || '').match(/(\d{4})\/(\d{1,2})\/(\d{1,2})(?:\s+(\d{1,2}):(\d{1,2}))?/);
      if (m) { viewJ = { jy: +toEn(m[1]), jm: +toEn(m[2]), jd: +toEn(m[3]), hh: m[4] ? +toEn(m[4]) : 12, ii: m[5] ? +toEn(m[5]) : 0 }; }
      else { var n = new Date(); var j = g2j(n.getFullYear(), n.getMonth() + 1, n.getDate()); viewJ = { jy: j[0], jm: j[1], jd: j[2], hh: n.getHours(), ii: n.getMinutes() }; }
      draw();
      var r = input.getBoundingClientRect();
      cal.style.top = (window.scrollY + r.bottom + 6) + 'px';
      cal.style.left = (window.scrollX + r.left) + 'px';
      cal.style.display = 'block';
    }
    function setDate(day) {
      var inputs = cal.querySelectorAll('.cptt-jdp__time input');
      var hh = Math.max(0, Math.min(23, parseInt(inputs[0].value || '0', 10)));
      var ii = Math.max(0, Math.min(59, parseInt(inputs[1].value || '0', 10)));
      viewJ.jd = day; viewJ.hh = hh; viewJ.ii = ii;
      activeInput.value = toFa(viewJ.jy + '/' + String(viewJ.jm).padStart(2, '0') + '/' + String(day).padStart(2, '0') + ' ' + String(hh).padStart(2, '0') + ':' + String(ii).padStart(2, '0'));
      cal.style.display = 'none';
    }
    document.addEventListener('click', function (e) {
      var input = e.target.closest('.cptt-jalali-datetime');
      if (input) { open(input); return; }
      if (!cal.contains(e.target)) { cal.style.display = 'none'; }
    });
    cal.addEventListener('click', function (e) {
      e.stopPropagation();
      var target = (e.target.nodeType === 3) ? e.target.parentElement : e.target;
      var nav = target.closest ? target.closest('[data-nav]') : null;
      if (nav) { 
        viewJ.jm += nav.getAttribute('data-nav') === 'next' ? 1 : -1; 
        if (viewJ.jm > 12) { viewJ.jm = 1; viewJ.jy++; } 
        if (viewJ.jm < 1) { viewJ.jm = 12; viewJ.jy--; } 
        draw(); return; 
      }
      var dayEl = target.closest ? target.closest('[data-day]') : null;
      if (dayEl) { setDate(parseInt(dayEl.dataset.day, 10)); return; }
      if (target.closest && target.closest('[data-close]')) { cal.style.display = 'none'; return; }
      if (target.closest && target.closest('[data-today]')) { 
        var n = new Date(); var j = g2j(n.getFullYear(), n.getMonth() + 1, n.getDate()); 
        viewJ = { jy: j[0], jm: j[1], jd: j[2], hh: n.getHours(), ii: n.getMinutes() }; 
        setDate(j[2]); return; 
      }
    });
  }

  /* =========================================================
     UTILITIES & UI
     ========================================================= */

  function initRealtimeClock() {
      function update() {
          var now = new Date();
          var faTime = now.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
          qsa('.cptt-realtime-clock').forEach(function(el) { el.textContent = faTime; });
      }
      setInterval(update, 10000);
      update();
  }

  function initMobileUI() {
      var mobileBell = qs('.cptt-mobile-bell-btn');
      if (mobileBell) {
          mobileBell.addEventListener('click', function(e) {
              e.preventDefault();
              var menu = qs('.cptt-mobile-menu');
              if (menu) { menu.setAttribute('hidden', ''); document.body.style.overflow = ''; }
              
              var notifModal = qs('.cptt-all-notifs-modal');
              if (notifModal) {
                  notifModal.removeAttribute('hidden');
                  var list = qs('.cptt-all-notifs-list', notifModal);
                  var fd = new FormData();
                  fd.append('action', 'cptt_expert_fetch_all_notifications');
                  fd.append('nonce', window.CPTT_EXPERT ? CPTT_EXPERT.nonce : window.CPTT_ADMIN.nonce);
                  fetch(window.CPTT_EXPERT ? CPTT_EXPERT.ajax : window.CPTT_ADMIN.ajax, { method: 'POST', body: fd })
                  .then(r => r.json()).then(data => {
                      if (data.success && list) {
                          list.innerHTML = data.data.html;
                      }
                  });
              }
          });
      }

      var fab = qs('.cptt-mobile-fab');
      var menu = qs('.cptt-mobile-menu');
      if (fab && menu) {
          fab.addEventListener('click', function() {
            menu.removeAttribute('hidden');
            document.body.style.overflow = 'hidden';
            document.body.classList.add('cptt-mobile-menu-open');
          });
          var close = qs('.cptt-mobile-menu__close', menu);
          var backdrop = qs('.cptt-mobile-menu__backdrop', menu);
          function closeMenu() {
            menu.setAttribute('hidden', '');
            document.body.style.overflow = '';
            document.body.classList.remove('cptt-mobile-menu-open');
          }
          if (close) close.addEventListener('click', closeMenu);
          if (backdrop) backdrop.addEventListener('click', closeMenu);
      }
      
      var filterBtn = qs('#cptt-mobile-filter-btn');
      var filterWrap = qs('#cptt-expert-filters-wrap');
      if (filterBtn && filterWrap) {
          filterBtn.addEventListener('click', function() {
              filterWrap.classList.toggle('is-open');
          });
      }
  }

  /* =========================================================
     STAGE NUMBERING & REORDERING
     ========================================================= */
  function refreshStepNumbers(container) {
    if (!container) return;
    var steps = qsa('.cptt-expert-step', container);
    steps.forEach(function (step, index) {
      var num = index + 1;
      var titleStrong = qs('.cptt-expert-step__toggleMain strong', step);
      if (titleStrong) {
        var currentTitle = titleStrong.textContent.replace(/^\d+\.\s*/, '');
        titleStrong.textContent = num + '. ' + currentTitle;
      }
    });
  }

  function initStepReordering() {
    document.addEventListener('dragstart', function(e) {
      var handle = e.target.closest('.cptt-step-reorder-handle');
      if (!handle) return;
      var step = handle.closest('.cptt-expert-step');
      if (step) {
        step.classList.add('is-dragging-step');
        e.dataTransfer.setData('text/plain', '');
      } else {
          e.preventDefault();
      }
    });
    document.addEventListener('dragend', function(e) {
      var step = e.target.closest('.cptt-expert-step');
      if (step) step.classList.remove('is-dragging-step');
    });
    document.addEventListener('dragover', function(e) {
      e.preventDefault();
      var container = e.target.closest('.cptt-expert-steps');
      if (!container) return;
      var dragging = qs('.is-dragging-step');
      if (!dragging) return;
      var afterElement = getDragAfterElement(container, e.clientY);
      if (afterElement == null) {
        container.appendChild(dragging);
      } else {
        container.insertBefore(dragging, afterElement);
      }
    });
    document.addEventListener('drop', function(e) {
      var container = e.target.closest('.cptt-expert-steps');
      if (container) refreshStepNumbers(container);
    });

    function getDragAfterElement(container, y) {
      var draggableElements = qsa('.cptt-expert-step:not(.is-dragging-step)', container);
      return draggableElements.reduce(function(closest, child) {
        var box = child.getBoundingClientRect();
        var offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
          return { offset: offset, element: child };
        } else {
          return closest;
        }
      }, { offset: Number.NEGATIVE_INFINITY }).element;
    }
  }

  /* =========================================================
     HASH ACTION HANDLER
     ========================================================= */
  function parseHashAction() {
      var hash = window.location.hash;
      if (!hash) return;
      
      var pid = '';
      var openChat = false;
      var openDirect = false;
      var expertId = '';

      if (hash.startsWith('#project-')) {
          pid = hash.replace('#project-', '').split('#')[0];
          if (hash.indexOf('#chat-') > -1) openChat = true;
      } else if (hash.startsWith('#chat-')) {
          pid = hash.replace('#chat-', '');
          openChat = true;
      } else if (hash.startsWith('#directchat-')) {
          expertId = hash.replace('#directchat-', '');
          openDirect = true;
      }

      if (pid) {
          var card = null;
          qsa('.cptt-expertCard').forEach(function(c) {
             var f = qs('form[data-project-id="'+pid+'"]', c);
             if (f || c.getAttribute('data-project-id') === pid) card = c;
          });
          
          if (card) {
              var btn = qs('.cptt-expert-toggleProject', card);
              if (btn) {
                  setTimeout(function(){ 
                      btn.click(); 
                      if (openChat) {
                          setTimeout(function() {
                              var chatBtn = qs('.cptt-expert-chat-launch', card);
                              if (chatBtn) chatBtn.click();
                          }, 600);
                      }
                  }, 500);
              }
          }
      } else if (openDirect && expertId) {
          var expertItem = qs('.cptt-expert-list-item[data-expert-id="'+expertId+'"]');
          if (expertItem) setTimeout(function(){ expertItem.click(); }, 500);
      }
  }

  /* =========================================================
     FILTERS
     ========================================================= */

  function updateVisibility() {
    var search = (qs('#cptt-expert-search') || {}).value ? qs('#cptt-expert-search').value.toLowerCase().trim() : '';
    var status = (qs('#cptt-expert-status') || {}).value || '';
    var settled = (qs('#cptt-expert-settled') || {}).value || '';
    var client = (qs('#cptt-expert-client') || {}).value || '';
    var product = (qs('#cptt-expert-product') || {}).value || '';
    var cat = (qs('#cptt-expert-cat') || {}).value || '';
    var visible = 0;
    qsa('.cptt-expertCard').forEach(function (card) {
      var ok = true;
      var dataSearch = String(card.getAttribute('data-search') || '').toLowerCase();
      var dataStatus = String(card.getAttribute('data-status') || '');
      var dataSettled = String(card.getAttribute('data-settled') || '');
      var dataClient = String(card.getAttribute('data-client') || '');
      var dataProduct = String(card.getAttribute('data-product') || '');
      var dataCats = String(card.getAttribute('data-cats') || '');
      if (search && dataSearch.indexOf(search) === -1) ok = false;
      if (status && dataStatus !== status) ok = false;
      if (settled !== '' && dataSettled !== settled) ok = false;
      if (client && dataClient !== client) ok = false;
      if (product && dataProduct !== product) ok = false;
      if (cat && dataCats.indexOf(',' + cat + ',') === -1) ok = false;
      card.hidden = !ok;
      card.style.display = ok ? '' : 'none';
      if (ok) visible++;
    });
    var empty = qs('#cptt-expert-empty');
    if (empty) empty.hidden = visible !== 0;
    document.dispatchEvent(new CustomEvent('cptt:expertFiltersChanged', { detail: { visible: visible } }));
  }

  function bindProjectToggles() {
    qsa('.cptt-expert-toggleProject').forEach(function (btn) {
      if (btn.dataset.bound) return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', function () {
        var card = btn.closest('.cptt-expertCard');
        if (!card) return;
        var details = qs('.cptt-expertCard__details', card);
        if (!details) return;
        var isOpen = !details.hidden;
        qsa('.cptt-expertCard').forEach(function (c) {
          var d = qs('.cptt-expertCard__details', c);
          var b = qs('.cptt-expert-toggleProject', c);
          if (d) d.hidden = true;
          c.classList.remove('is-expanded');
          if (b) b.textContent = 'مدیریت پروژه';
        });
        if (!isOpen) {
          details.hidden = false;
          card.classList.add('is-expanded');
          btn.textContent = 'بستن مدیریت';
          setTimeout(function () { card.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
          bindStepAccordions(card);
          refreshStepNumbers(qs('.cptt-expert-steps', card));
        }
      });
    });
  }

  function bindStepAccordions(scope) {
    qsa('.cptt-expert-step', scope || document).forEach(function (step) {
      var toggle = qs('.cptt-expert-step__toggle', step);
      var body = qs('.cptt-expert-step__body', step);
      if (!toggle || !body) return;
      if (toggle.dataset.bound) return;
      toggle.dataset.bound = '1';
      var isExpanded = toggle.getAttribute('aria-expanded') === 'true';
      if (isExpanded) { step.classList.add('is-open'); body.hidden = false; }
      else { step.classList.remove('is-open'); body.hidden = true; }
      toggle.addEventListener('click', function (e) {
        if (e.target.closest('.cptt-step-reorder-handle')) return;
        e.preventDefault(); e.stopPropagation();
        var stepsContainer = step.parentElement;
        var willOpen = body.hidden;
        if (stepsContainer) {
          qsa('.cptt-expert-step', stepsContainer).forEach(function (other) {
            if (other === step) return;
            var ob = qs('.cptt-expert-step__body', other);
            var ot = qs('.cptt-expert-step__toggle', other);
            if (ob) ob.hidden = true;
            if (ot) ot.setAttribute('aria-expanded', 'false');
            other.classList.remove('is-open');
          });
        }
        if (willOpen) { body.hidden = false; step.classList.add('is-open'); toggle.setAttribute('aria-expanded', 'true'); }
        else { body.hidden = true; step.classList.remove('is-open'); toggle.setAttribute('aria-expanded', 'false'); }
      });
    });
  }

  function applySummary(card, data) {
    if (!card || !data || !data.progress) return;
    var bar = qs('.cptt-expertCard__progress span', card);
    if (bar) bar.style.width = Math.max(0, Math.min(100, parseInt(data.progress.percent || 0, 10))) + '%';
    var badge = qs('.cptt-expertStatusBadge', card);
    if (badge) {
      badge.textContent = data.progress.label || 'در حال انجام';
      badge.className = 'cptt-expertStatusBadge cptt-expertStatusBadge--' + (data.progress.status || 'in_progress');
    }
    var last = qs('.cptt-expert-last-update', card);
    if (last) last.textContent = data.last_update_fa || '—';
    var stat = qsa('.cptt-expertCard__stats strong', card);
    if (stat[0]) stat[0].textContent = (data.progress.percent || 0) + '%';
  }

  function bindCreateForm() {
    qsa('.cptt-expert-create-form').forEach(function (form) {
      if (form.dataset.bound) return;
      form.dataset.bound = '1';
      bindChatEnhancements(form);
      form.addEventListener('submit', async function (e) {
        e.preventDefault();
        var msg = qs('.cptt-expert-formMsg', form);
        var btn = qs('button[type="submit"]', form);
        if (msg) msg.textContent = '';
        if (btn) { btn.disabled = true; btn.textContent = 'در حال ایجاد...'; }
        try {
          var fd = new FormData(form);
          fd.append('action', 'cptt_expert_create_project');
          fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
          var res = await fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', {
            method: 'POST', credentials: 'same-origin', body: fd
          });
          var json = await res.json();
          if (!json || !json.success) throw new Error((json && json.data) ? json.data : 'خطا در ایجاد پروژه');
          if (msg) msg.textContent = 'پروژه با موفقیت ایجاد شد.';
          window.setTimeout(function () { window.location.href = (json.data && json.data.redirect) ? json.data.redirect : window.location.href; }, 500);
        } catch (err) {
          if (msg) msg.textContent = err.message || 'خطا در ایجاد پروژه';
        } finally {
          if (btn) { btn.disabled = false; btn.textContent = 'ایجاد پروژه'; }
        }
      });
    });
  }

  /* مودال تداخل ویرایش همزمان (v5.4.3) */
  function showConflictModal(message) {
    if (document.getElementById('cptt-conflict-modal')) return;
    var overlay = document.createElement('div');
    overlay.id = 'cptt-conflict-modal';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:2147483646;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(15,23,42,.65);backdrop-filter:blur(4px);direction:rtl;';
    overlay.innerHTML =
      '<div style="background:#fff;border-radius:18px;max-width:460px;width:100%;padding:24px;box-shadow:0 30px 60px rgba(0,0,0,.3);text-align:center;font-family:inherit;">' +
        '<div style="font-size:42px;margin-bottom:8px;">⚠️</div>' +
        '<h3 style="margin:0 0 10px;color:#0f172a;font-size:17px;font-weight:900;">تداخل در ویرایش همزمان</h3>' +
        '<p style="color:#475569;font-size:13px;line-height:1.8;margin:0 0 18px;">' + (message || 'این پروژه در حین کار شما توسط کارشناس دیگری ویرایش و ذخیره شده است.') + '</p>' +
        '<p style="color:#64748b;font-size:12px;line-height:1.7;margin:0 0 18px;">برای جلوگیری از خراب شدن اطلاعات، لطفاً صفحه را بروزرسانی کنید و تغییرات خود را دوباره اعمال نمایید.</p>' +
        '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center;">' +
          '<button type="button" id="cptt-conflict-refresh" style="flex:1;min-width:140px;padding:12px 18px;border-radius:12px;border:none;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;font-size:13px;font-weight:900;cursor:pointer;">🔄 بروزرسانی صفحه</button>' +
          '<button type="button" id="cptt-conflict-close" style="flex:1;min-width:140px;padding:12px 18px;border-radius:12px;border:1px solid #cbd5e1;background:#fff;color:#334155;font-size:13px;font-weight:900;cursor:pointer;">بستن</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(overlay);
    var close = function(){ try{ overlay.remove(); }catch(e){} };
    overlay.addEventListener('click', function(e){ if (e.target === overlay) close(); });
    var rBtn = document.getElementById('cptt-conflict-refresh');
    var cBtn = document.getElementById('cptt-conflict-close');
    if (rBtn) rBtn.addEventListener('click', function(){ window.location.reload(); });
    if (cBtn) cBtn.addEventListener('click', close);
  }

  function bindSaveForms() {
    qsa('.cptt-expert-project-form').forEach(function (form) {
      if (form.dataset.bound) return;
      form.dataset.bound = '1';
      bindChatEnhancements(form);
      form.addEventListener('submit', async function (e) {
        e.preventDefault();
        var msg = qs('.cptt-expert-formMsg', form);
        var btn = qs('button[type="submit"]', form);
        if (msg) { msg.textContent = ''; msg.style.color = ''; }
        if (btn) { btn.disabled = true; btn.textContent = (window.CPTT_EXPERT && CPTT_EXPERT.texts && CPTT_EXPERT.texts.saving) || 'در حال ذخیره...'; }
        try {
          var fd = new FormData(form);
          fd.append('action', 'cptt_expert_save_project');
          fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
          var res = await fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', {
            method: 'POST', credentials: 'same-origin', body: fd
          });
          var json = await res.json();
          if (!json || !json.success) {
            // تشخیص خطای تداخل ویرایش همزمان (HTTP 409 یا پیام مشخص)
            var errMsg = '';
            if (json && json.data) {
              errMsg = (typeof json.data === 'string') ? json.data : (json.data.message || '');
            }
            var isConflict = (res.status === 409) || (errMsg && errMsg.indexOf('کارشناس دیگری ویرایش') !== -1);
            if (isConflict) {
              showConflictModal(errMsg || 'این پروژه توسط کارشناس دیگری ویرایش شده است.');
              if (msg) { msg.style.color = '#dc2626'; msg.textContent = 'برای ادامه، صفحه را بروزرسانی کنید.'; }
              return;
            }
            throw new Error(errMsg || (window.CPTT_EXPERT && CPTT_EXPERT.texts && CPTT_EXPERT.texts.error) || 'خطا در ذخیره اطلاعات');
          }
          if (msg) { msg.style.color = '#047857'; msg.textContent = (window.CPTT_EXPERT && CPTT_EXPERT.texts && CPTT_EXPERT.texts.saved) || 'تغییرات با موفقیت ذخیره شد.'; }
          var card = form.closest('.cptt-expertCard');
          applySummary(card, json.data || {});
          var textarea = qs('textarea[name="note"]', form);
          if (textarea) textarea.value = '';
          window.setTimeout(function () { window.location.reload(); }, 700);
        } catch (err) {
          if (msg) { msg.style.color = '#dc2626'; msg.textContent = err.message || 'خطا در ذخیره اطلاعات'; }
        } finally {
          if (btn) { btn.disabled = false; btn.textContent = 'ذخیره تغییرات'; }
        }
      });
    });
  }

  function renderMessages(items, container, myUserId) {
    if (!Array.isArray(items) || !items.length) {
      container.innerHTML = '<div class="cptt-expert-emptyMini">پیامی ثبت نشده است.</div>';
      return;
    }
    var html = items.map(function (message) {
      var isMe = parseInt(message.sender_id, 10) === parseInt(myUserId || 0, 10);
      var head = escapeHtml((message.sender_name || 'کاربر') + (message.recipient_name && message.recipient_name !== 'همه' ? ' → ' + message.recipient_name : ''));
      var time = escapeHtml(message.time_fa || '');
      
      var rawBody = message.content || '';
      var linkMatch = rawBody.match(/href=(?:&quot;|"|')?([^"'>\s&]+)(?:&quot;|"|')?[^>]*class=(?:&quot;|"|')?cptt-chat-file-link/i);
      var fileUrl = linkMatch ? linkMatch[1] : '';
      var cleanText = rawBody.replace(/<a[^>]*cptt-chat-file-link.*?<\/a>/gi, '').replace(/&lt;a[^&]*cptt-chat-file-link.*?&lt;\/a&gt;/gi, '');
      
      var body = escapeHtml(cleanText.trim()).replace(/\n/g, '<br>');
      if (fileUrl) {
          body += '<br><a href="' + escapeHtml(fileUrl) + '" target="_blank" class="cptt-chat-file-btn">👁 مشاهده فایل ضمیمه</a>';
      }

      var cls = isMe ? 'cptt-chat-bubble--me' : 'cptt-chat-bubble--other';
      return '<div class="cptt-chat-bubble ' + cls + '"><div class="cptt-chat-bubble__head"><strong>' + head + '</strong><span>' + time + '</span></div><div class="cptt-chat-bubble__body">' + body + '</div></div>';
    }).join('');
    container.innerHTML = html;
    container.scrollTop = container.scrollHeight;
  }

  const emojis = ['👍','✅','😊','🙏','👏','❤️','💡','🚀','👀','⚠️'];
  
  function bindChatEnhancements(form) {
    if (form.dataset.enhanced) return;
    form.dataset.enhanced = '1';
    
    var ta = form.querySelector('textarea');
    if (!ta) return;
    
    var emojiWrap = document.createElement('div');
    emojiWrap.className = 'cptt-emoji-picker';
    emojis.forEach(function(emoji) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'cptt-emoji-btn';
      btn.textContent = emoji;
      btn.addEventListener('click', function() {
        ta.value += emoji;
        ta.focus();
      });
      emojiWrap.appendChild(btn);
    });
    ta.parentNode.insertBefore(emojiWrap, ta);
    
    var fileInput = form.querySelector('input[type="file"]');
    if (fileInput) {
      var previewWrap = document.createElement('div');
      previewWrap.className = 'cptt-chat-file-preview-wrap';
      ta.parentNode.insertBefore(previewWrap, ta.nextSibling);
      
      fileInput.addEventListener('change', function() {
        if (this.files && this.files.length > 0) {
          previewWrap.innerHTML = '<div class="cptt-chat-file-preview">' + escapeHtml(this.files[0].name) + ' <button type="button" title="حذف">×</button></div>';
          previewWrap.querySelector('button').addEventListener('click', function() {
            fileInput.value = '';
            previewWrap.innerHTML = '';
          });
        } else {
          previewWrap.innerHTML = '';
        }
      });
      
      form.addEventListener('cptt-chat-sent', function() {
        fileInput.value = '';
        previewWrap.innerHTML = '';
      });
    }
  }

  function bindMessageForms() {
    qsa('.cptt-expert-message-form').forEach(function (form) {
      if (form.dataset.bound) return;
      form.dataset.bound = '1';
      bindChatEnhancements(form);
      form.addEventListener('submit', async function (e) {
        e.preventDefault();
        var msg = qs('.cptt-expert-formMsg', form);
        var btn = qs('button[type="submit"]', form);
        if (msg) msg.textContent = '';
        if (btn) { btn.disabled = true; btn.textContent = 'در حال ارسال...'; }
        try {
          var fd = new FormData(form);
          fd.append('action', 'cptt_expert_send_message');
          fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
          var res = await fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', { method: 'POST', credentials: 'same-origin', body: fd });
          var json = await res.json();
          if (!json || !json.success) throw new Error((json && json.data) ? json.data : 'خطا در ارسال پیام');
          if (msg) msg.textContent = 'پیام ارسال شد.';
          var ta = qs('textarea[name="content"]', form); if (ta) ta.value = ''; form.dispatchEvent(new Event('cptt-chat-sent'));
          var wrap = form.parentElement.querySelector('.cptt-expert-messagesWrap');
          var myId = (window.CPTT_EXPERT && CPTT_EXPERT.wpUserId) ? CPTT_EXPERT.wpUserId : 0;
          if (wrap) renderMessages((json.data && json.data.messages) || [], wrap, myId);
        } catch (err) {
          if (msg) msg.textContent = err.message || 'خطا در ارسال پیام';
        } finally {
          if (btn) { btn.disabled = false; btn.textContent = 'ارسال پیام'; }
        }
      });
    });
  }

  async function refreshMessages(form, myUserId) {
    if (!form) return;
    var projectId = form.getAttribute('data-project-id') || '';
    var wrap = form.parentElement.querySelector('.cptt-expert-messagesWrap');
    if (!projectId || !wrap) return;
    var fd = new FormData();
    fd.append('action', 'cptt_expert_fetch_messages');
    fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
    fd.append('project_id', projectId);
    var res = await fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', { method: 'POST', credentials: 'same-origin', body: fd });
    var json = await res.json();
    if (json && json.success && wrap) renderMessages((json.data && json.data.messages) || [], wrap, myUserId);
  }

  function bindChatModals() {
    qsa('.cptt-expert-chatModal').forEach(function (modal) {
      if (modal.dataset.bound) return;
      modal.dataset.bound = '1';
      var card = modal.closest('.cptt-expertCard');
      var openBtn = card ? qs('.cptt-expert-chat-launch', card) : null;
      var closeBtn = qs('.cptt-expert-chatModal__close', modal);
      var backdrop = qs('.cptt-expert-chatModal__backdrop', modal);
      var form = qs('.cptt-expert-message-form', modal);
      var timer = null;
      function close() { modal.hidden = true; if (timer) { window.clearInterval(timer); timer = null; } }
      async function open() { modal.hidden = false; await refreshMessages(form); if (timer) window.clearInterval(timer); timer = window.setInterval(function () { refreshMessages(form); }, 8000); }
      if (openBtn) openBtn.addEventListener('click', open);
      if (closeBtn) closeBtn.addEventListener('click', close);
      if (backdrop) backdrop.addEventListener('click', close);
    });
  }

  function renderDirectMessages(items, myUserId) {
    var wrap = qs('#direct-chat-messages-container');
    if (!wrap) return;
    if (!Array.isArray(items) || !items.length) {
      wrap.innerHTML = '<div class="cptt-expert-emptyMini">پیامی وجود ندارد.</div>';
      return;
    }
    var html = items.map(function (m) {
      var isMe = parseInt(m.sender_id, 10) === parseInt(myUserId || 0, 10);
      var time = escapeHtml(m.time_fa || '');
      
      var rawBody = m.content || '';
      var linkMatch = rawBody.match(/href=(?:&quot;|"|')?([^"'>\s&]+)(?:&quot;|"|')?[^>]*class=(?:&quot;|"|')?cptt-chat-file-link/i);
      var fileUrl = linkMatch ? linkMatch[1] : '';
      var cleanText = rawBody.replace(/<a[^>]*cptt-chat-file-link.*?<\/a>/gi, '').replace(/&lt;a[^&]*cptt-chat-file-link.*?&lt;\/a&gt;/gi, '');
      
      var body = escapeHtml(cleanText.trim()).replace(/\n/g, '<br>');
      if (fileUrl) {
          body += '<br><a href="' + escapeHtml(fileUrl) + '" target="_blank" class="cptt-chat-file-btn">👁 مشاهده فایل ضمیمه</a>';
      }

      var cls = isMe ? 'cptt-chat-bubble--me' : 'cptt-chat-bubble--other';
      return '<div class="cptt-chat-bubble ' + cls + '"><div class="cptt-chat-bubble__head"><strong>' + escapeHtml(m.sender_name || 'کاربر') + '</strong><span>' + time + '</span></div><div class="cptt-chat-bubble__body">' + body + '</div></div>';
    }).join('');
    wrap.innerHTML = html;
    wrap.scrollTop = wrap.scrollHeight;
  }

  function bindNewProjectModal() {
    var modal = qs('#cptt-new-project-modal');
    if (!modal) return;
    var openers = qsa('.cptt-newProjectCta, [data-cptt-open-newproject]');
    var closeBtns = qsa('.cptt-newProjectModal__close, [data-cptt-close-newproject]', modal);
    var backdrop = qs('.cptt-newProjectModal__backdrop', modal);
    function open() { modal.classList.add('is-open'); document.body.style.overflow = 'hidden'; modal.removeAttribute('aria-hidden'); }
    function close() { modal.classList.remove('is-open'); document.body.style.overflow = ''; modal.setAttribute('aria-hidden', 'true'); }
    openers.forEach(function (btn) {
      if (btn.dataset.npmBound) return;
      btn.dataset.npmBound = '1';
      btn.addEventListener('click', function (e) { e.preventDefault(); open(); });
    });
    closeBtns.forEach(function (b) {
      if (b.dataset.npmBound) return;
      b.dataset.npmBound = '1';
      b.addEventListener('click', function (e) { e.preventDefault(); close(); });
    });
    if (backdrop) backdrop.addEventListener('click', close);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('is-open')) close(); });
  }

  function bindDeleteActions() {
    document.addEventListener('click', async function (e) {
      var btn = e.target.closest('.cptt-expert-delete-project');
      if (btn) {
        e.preventDefault();
        if (!confirm('آیا از حذف این پروژه اطمینان دارید؟ این عمل غیرقابل بازگشت است.')) return;
        var projectId = btn.getAttribute('data-project-id');
        try {
          var fd = new FormData();
          fd.append('action', 'cptt_expert_delete_project');
          fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
          fd.append('project_id', projectId);
          var res = await fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', { method: 'POST', credentials: 'same-origin', body: fd });
          var json = await res.json();
          if (json && json.success) {
            var card = btn.closest('.cptt-expertCard');
            if (card) { card.style.opacity = '0'; setTimeout(function(){ card.remove(); updateVisibility(); }, 300); }
          } else {
            alert((json && json.data) ? json.data : 'خطا در حذف پروژه');
          }
        } catch (err) { alert('خطا در ارتباط'); }
        return;
      }
      var sbtn = e.target.closest('.cptt-expert-remove-step');
      if (sbtn) {
        var stepEl = sbtn.closest('.cptt-expert-step');
        if (!stepEl) return;
        var stepId = stepEl.getAttribute('data-step-id');
        var form = sbtn.closest('.cptt-expert-project-form');
        var container = stepEl.parentElement;
        var projectId = form ? form.getAttribute('data-project-id') : '';
        if (projectId && stepId && stepId.indexOf('step_') === -1 && confirm('این مرحله حذف شود؟')) {
          try {
            var fd2 = new FormData();
            fd2.append('action', 'cptt_expert_delete_step');
            fd2.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
            fd2.append('project_id', projectId);
            fd2.append('step_id', stepId);
            var res2 = await fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', { method: 'POST', credentials: 'same-origin', body: fd2 });
            var json2 = await res2.json();
            if (json2 && json2.success) { stepEl.remove(); if (container) refreshStepNumbers(container); }
            else { alert((json2 && json2.data) ? json2.data : 'خطا در حذف مرحله'); }
          } catch (err) { alert('خطا در ارتباط'); }
        } else {
          stepEl.remove();
          if (container) refreshStepNumbers(container);
        }
      }
    });
  }


  function initClientSearchPickers() { /* disabled: search is only for create form and is bound by isolated v5.4.10 code */ }

  function initKanban() {
    var dataEl = qs('#cptt-kanban-data');
    if (!dataEl) return;
    var steps = [];
    try {
      var raw = dataEl.getAttribute('data-kanban');
      var bin = atob(raw);
      var bytes = Uint8Array.from(bin, function (c) { return c.charCodeAt(0); });
      var txt = window.TextDecoder ? new TextDecoder('utf-8').decode(bytes) : decodeURIComponent(escape(bin));
      steps = JSON.parse(txt);
    } catch (e) { console.error(e); return; }
    if (!Array.isArray(steps)) return;

    var grid = qs('#cptt-expert-grid');
    var kanbanWrap = qs('#cptt-kanban-board');
    if (!grid || !kanbanWrap) return;

    function buildKanban() {
      var cols = { todo: [], current: [], done: [] };
      steps.forEach(function (s) {
        var projectCard = document.querySelector('.cptt-expertCard[data-project-id="' + String(s.project_id) + '"]');
        if (projectCard && (projectCard.hidden || projectCard.style.display === 'none')) return;
        cols[s.status || 'todo'].push(s);
      });
      var html = '<div class="cptt-kanban">' +
        '<div class="cptt-kanban__col" data-status="todo"><div class="cptt-kanban__head">🔵 انجام‌نشده</div><div class="cptt-kanban__dropzone">' + renderCol(cols.todo) + '</div></div>' +
        '<div class="cptt-kanban__col" data-status="current"><div class="cptt-kanban__head">🟡 در حال انجام</div><div class="cptt-kanban__dropzone">' + renderCol(cols.current) + '</div></div>' +
        '<div class="cptt-kanban__col" data-status="done"><div class="cptt-kanban__head">🟢 انجام‌شده</div><div class="cptt-kanban__dropzone">' + renderCol(cols.done) + '</div></div>' +
        '</div>';
      kanbanWrap.innerHTML = html;
      initDnD();
    }
    function renderCol(items) {
      return items.map(function (s) {
        return '<div class="cptt-kanban__card" draggable="true" data-step-id="' + escapeHtml(s.step_id) + '" data-project-id="' + escapeHtml(s.project_id) + '">' +
          '<div class="cptt-kanban__cardTitle">' + escapeHtml(s.title || 'بدون عنوان') + '</div>' +
          '<div class="cptt-kanban__cardMeta">' + escapeHtml(s.project_title || '') + '</div>' +
          '</div>';
      }).join('');
    }
    function initDnD() {
      var dragCard = null;
      qsa('.cptt-kanban__card').forEach(function (card) {
        card.addEventListener('dragstart', function (e) { dragCard = card; e.dataTransfer.effectAllowed = 'move'; card.classList.add('is-dragging'); });
        card.addEventListener('dragend', function () { card.classList.remove('is-dragging'); dragCard = null; });
      });
      qsa('.cptt-kanban__dropzone').forEach(function (zone) {
        zone.addEventListener('dragover', function (e) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; });
        zone.addEventListener('drop', async function (e) {
          e.preventDefault();
          if (!dragCard) return;
          zone.appendChild(dragCard);
          var newStatus = zone.closest('.cptt-kanban__col').getAttribute('data-status');
          var stepId = dragCard.getAttribute('data-step-id');
          var projectId = dragCard.getAttribute('data-project-id');
          steps.forEach(function (s) { if (s.step_id === stepId) s.status = newStatus; });
          try {
            var fd = new FormData();
            fd.append('action', 'cptt_expert_update_step_status');
            fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '');
            fd.append('project_id', projectId);
            fd.append('step_id', stepId);
            fd.append('status', newStatus);
            var kr = await fetch((window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '', { method: 'POST', credentials: 'same-origin', body: fd });
            var kj = await kr.json().catch(function(){ return null; });
            if (!kj || !kj.success) throw new Error((kj && kj.data) ? kj.data : 'خطا در تغییر وضعیت');
            window.location.reload();
          } catch (err) { console.error(err); }
        });
      });
    }
    buildKanban();

    var toggle = qs('#cptt-kanban-toggle');
    if (toggle) {
      toggle.addEventListener('click', function () {
        var show = kanbanWrap.hidden;
        kanbanWrap.hidden = !show;
        grid.hidden = show;
        toggle.textContent = show ? '📋 نمایش لیست' : '📌 نمایش Kanban';
        if (show) buildKanban();
      });
      document.addEventListener('cptt:expertFiltersChanged', function(){ if (!kanbanWrap.hidden) buildKanban(); });
    }
  }

  /* =========================================================
     INITIALIZE
     ========================================================= */
  document.addEventListener('DOMContentLoaded', function () {
    /* ===== Web Notifications Permission Request ===== */
    if (window.Notification && Notification.permission === 'default') {
      Notification.requestPermission();
    }

    /* ===== Back to Top Button Handler ===== */
    var btt = document.getElementById('cptt-back-to-top');
    if (btt) {
      window.addEventListener('scroll', function() {
        if (window.scrollY > 300) {
          btt.style.display = 'flex';
        } else {
          btt.style.display = 'none';
        }
      });
      btt.addEventListener('click', function() {
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    }

    var search = qs('#cptt-expert-search');
    var status = qs('#cptt-expert-status');
    var settled = qs('#cptt-expert-settled');
    var client = qs('#cptt-expert-client');
    var product = qs('#cptt-expert-product');
    var cat = qs('#cptt-expert-cat');
    [search, status, settled, client, product, cat].forEach(function (el) {
      if (!el) return;
      el.addEventListener('input', updateVisibility);
      el.addEventListener('change', updateVisibility);
    });
    var reset = qs('#cptt-expert-reset');
    if (reset) {
      reset.addEventListener('click', function () {
        if (search) search.value = '';
        if (status) status.value = '';
        if (settled) settled.value = '';
        if (client) client.value = '';
        if (product) product.value = '';
        if (cat) cat.value = '';
        updateVisibility();
      });
    }
    bindProjectToggles();
    bindStepAccordions();
    bindCreateForm();
    bindSaveForms();
    bindMessageForms();
    bindChatModals();
    bindNewProjectModal();
    bindDeleteActions();
    initStepReordering();
    
    initJalaliPicker();
    initRealtimeClock();
    initMobileUI();
    parseHashAction();
    initDarkMode();
    initKanban();
    updateVisibility();
    setInterval(pollNotifications, 30000);
  });

  document.addEventListener('DOMContentLoaded', function() {
    var qs2 = function(s, ctx) { return (ctx || document).querySelector(s); };
    var qsa2 = function(s, ctx) { return Array.from((ctx || document).querySelectorAll(s)); };

    var openExpertsBtn = qs2('.cptt-open-experts-modal-btn');
    var expertsModal = qs2('.cptt-experts-mobile-modal');
    if (openExpertsBtn && expertsModal) {
      qsa2('.cptt-open-experts-modal-btn').forEach(btn => btn.addEventListener('click', function() { 
          expertsModal.removeAttribute('hidden'); 
          var menu = document.querySelector('.cptt-mobile-menu');
          if (menu) { menu.setAttribute('hidden', ''); document.body.style.overflow = ''; }
      }));

      var closeMod = qs2('.cptt-experts-mobile-modal__close', expertsModal);
      var backMod = qs2('.cptt-experts-mobile-modal__backdrop', expertsModal);
      if (closeMod) closeMod.addEventListener('click', function() { expertsModal.setAttribute('hidden', ''); });
      if (backMod) backMod.addEventListener('click', function() { expertsModal.setAttribute('hidden', ''); });
    }

    var directChatModal = qs2('.cptt-direct-chat-modal');
    if (directChatModal) {
      var closeDc = qs2('.cptt-direct-chat-modal__close', directChatModal);
      var backDc = qs2('.cptt-direct-chat-modal__backdrop', directChatModal);
      if (closeDc) closeDc.addEventListener('click', function() { directChatModal.setAttribute('hidden', ''); });
      if (backDc) backDc.addEventListener('click', function() { directChatModal.setAttribute('hidden', ''); });

      qsa2('.cptt-expert-list-item').forEach(function(item) {
        item.addEventListener('click', async function() {
          var expertId = this.getAttribute('data-expert-id');
          if (expertsModal) expertsModal.setAttribute('hidden', '');
          qs2('#direct-chat-receiver-id', directChatModal).value = expertId;
          qs2('#direct-chat-messages-container', directChatModal).innerHTML = '<p>در حال بارگذاری...</p>';
          qs2('.cptt-direct-chat-form', directChatModal).reset();
          qs2('#direct-chat-file-name', directChatModal).textContent = '';
          directChatModal.removeAttribute('hidden');
          try {
            var fd = new FormData();
            fd.append('action', 'cptt_expert_get_expert_info');
            fd.append('nonce', CPTT_EXPERT.nonce);
            fd.append('expert_id', expertId);
            var res = await fetch(CPTT_EXPERT.ajax, { method: 'POST', body: fd });
            var json = await res.json();
            if (json.success) {
              qs2('#direct-chat-avatar', directChatModal).src = json.data.avatar;
              qs2('#direct-chat-name', directChatModal).textContent = json.data.name;
              qs2('#direct-chat-stats', directChatModal).textContent = json.data.stats;
            }
            var fd2 = new FormData();
            fd2.append('action', 'cptt_expert_fetch_direct_messages');
            fd2.append('nonce', CPTT_EXPERT.nonce);
            fd2.append('receiver_id', expertId);
            var res2 = await fetch(CPTT_EXPERT.ajax, { method: 'POST', body: fd2 });
            var json2 = await res2.json();
            if (json2.success) renderDirectMessages(json2.data, (window.CPTT_EXPERT && CPTT_EXPERT.wpUserId) ? CPTT_EXPERT.wpUserId : 0);
          } catch(e) { console.error(e); }
        });
      });

      var dcForm = qs2('.cptt-direct-chat-form', directChatModal);
      if (dcForm) {
        var fileInput = qs2('#direct-chat-file', dcForm);
        if (fileInput) {
          fileInput.addEventListener('change', function() {
            qs2('#direct-chat-file-name', dcForm).textContent = this.files.length > 0 ? this.files[0].name : '';
          });
        }
        bindChatEnhancements(dcForm);
        dcForm.addEventListener('submit', async function(e) {
          e.preventDefault();
          var msg = qs2('#direct-chat-form-msg', dcForm);
          var btn = qs2('button[type="submit"]', dcForm);
          msg.textContent = '';
          btn.disabled = true; btn.textContent = 'در حال ارسال...';
          try {
            var fd = new FormData(dcForm);
            fd.append('action', 'cptt_expert_send_direct_message');
            fd.append('nonce', CPTT_EXPERT.nonce);
            var res = await fetch(CPTT_EXPERT.ajax, { method: 'POST', body: fd });
            var json = await res.json();
            if (json.success) {
              renderDirectMessages(json.data, (window.CPTT_EXPERT && CPTT_EXPERT.wpUserId) ? CPTT_EXPERT.wpUserId : 0);
              dcForm.reset(); dcForm.dispatchEvent(new Event('cptt-chat-sent'));
              qs2('#direct-chat-file-name', dcForm).textContent = '';
            } else { msg.textContent = json.data || 'خطا در ارسال'; }
          } catch(e) { msg.textContent = 'خطا در ارتباط'; }
          btn.disabled = false; btn.textContent = 'ارسال';
        });
      }
    }
  });

  document.addEventListener('keyup', function(e) {
    if (e.target && e.target.classList.contains('cptt-currency-input')) {
      var val = e.target.value.replace(/[^\d]/g, '');
      if (val) { e.target.value = parseInt(val, 10).toLocaleString('en-US'); }
      else { e.target.value = ''; }
    }
  });

  document.addEventListener('DOMContentLoaded', function() {
    var qs3 = function(s, ctx) { return (ctx || document).querySelector(s); };
    var catSelect = qs3('#cptt-create-cat-select');
    var prodWrap = qs3('#cptt-create-product-wrap');
    var prodSelect = qs3('#cptt-create-product-select');
    if (catSelect && prodWrap && prodSelect) {
      catSelect.addEventListener('change', function() {
        var cid = this.value;
        if (!cid) { prodWrap.style.display = 'none'; prodSelect.value = ''; }
        else {
          prodWrap.style.display = '';
          Array.from(prodSelect.options).forEach(function(opt) {
            if (!opt.value) return;
            var cats = opt.getAttribute('data-cats') || '';
            var catArr = cats.split(',');
            opt.style.display = (catArr.indexOf(cid) > -1) ? '' : 'none';
          });
          prodSelect.value = '';
        }
      });
    }
  });

  document.addEventListener('DOMContentLoaded', function() {
    var qs3 = function(s, ctx) { return (ctx || document).querySelector(s); };
    var bellBtn = qs3('.cptt-bell-btn');
    var notifDrop = qs3('.cptt-notifications-dropdown');
    if (bellBtn && notifDrop) {
      bellBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        if (notifDrop.hasAttribute('hidden')) notifDrop.removeAttribute('hidden');
        else notifDrop.setAttribute('hidden', '');
      });
      document.addEventListener('click', function(e) {
        if (!notifDrop.contains(e.target) && !bellBtn.contains(e.target)) notifDrop.setAttribute('hidden', '');
      });
      var markRead = qs3('#cptt-mark-all-read');
      if (markRead) {
        markRead.addEventListener('click', async function(e) {
          e.preventDefault(); e.stopPropagation();
          var badge = qs3('.cptt-bell-badge');
          if (badge) badge.style.display = 'none';
          document.querySelectorAll('.cptt-notification-item:not(.is-read)').forEach(function(item) { item.classList.add('is-read'); });
          var fd = new FormData();
          fd.append('action', 'cptt_expert_mark_notifications_read');
          fd.append('nonce', CPTT_EXPERT.nonce);
          try { await fetch(CPTT_EXPERT.ajax, { method: 'POST', body: fd }); } catch(err) {}
        });
      }
    }
  });

  document.addEventListener('DOMContentLoaded', function() {
    function randId(prefix) { return prefix + '_' + Math.floor(Math.random()*10000); }
    document.addEventListener('click', function(e) {
      if (e.target.classList.contains('cptt-expert-add-step')) {
        var card = e.target.closest('.cptt-expertCard');
        var container = qs('.cptt-expert-steps', card);
        if (!container) return;
        var stepId = randId('step');
        var idx = container.querySelectorAll('.cptt-expert-step').length + 1;
        var html = '<div class="cptt-expert-step is-open" draggable="true" data-step-id="'+stepId+'"><button type="button" class="cptt-expert-step__toggle" aria-expanded="true"><span class="cptt-step-reorder-handle" title="تغییر ترتیب">⠿</span><div class="cptt-expert-step__toggleMain"><strong>' + idx + '. مرحله جدید</strong><span>چک‌لیست: 0/0</span></div><div class="cptt-expert-step__toggleSide"><span class="cptt-expert-status cptt-expert-status--todo">انجام‌نشده</span><span class="cptt-expert-step__chevron">⌄</span></div></button><div class="cptt-expert-step__body"><div class="cptt-expert-step__metaGrid"><label><span>عنوان مرحله</span><input type="text" name="steps['+stepId+'][title]" value="مرحله جدید"></label><label><span>وضعیت مرحله</span><select name="steps['+stepId+'][status]"><option value="todo">انجام‌نشده</option><option value="current">در حال انجام</option><option value="done">انجام‌شده</option></select></label><label><span>مهلت مرحله</span><input type="text" class="cptt-jalali-datetime" name="steps['+stepId+'][due_at_local]" value=""></label></div><div class="cptt-expert-step__metaGrid"><label><span>هزینه مرحله</span><input type="text" class="cptt-currency-input" name="steps['+stepId+'][cost]" value="0"></label><label><span>دریافتی مرحله</span><input type="text" class="cptt-currency-input" name="steps['+stepId+'][paid]" value="0"></label></div><div class="cptt-step-expert-wrap"><input type="hidden" class="cptt-step-expert-primary" name="steps['+stepId+'][assigned_expert_id]" value=""><div class="cptt-step-expert-hidden-list"></div><button type="button" class="cptt-step-expert-btn">👥 انتخاب کارشناسان مرحله</button></div><label class="cptt-expert-noteField"><span>توضیحات (اختیاری)</span><textarea name="steps['+stepId+'][desc]" rows="2"></textarea></label><div class="cptt-expert-checklist"><div class="cptt-expert-sectionTitle">چک‌لیست مرحله</div><div class="cptt-expert-checklist-items"></div><button type="button" class="button button-small cptt-expert-add-checkitem" style="margin-top:10px;">+ افزودن آیتم چک‌لیست</button></div><div class="cptt-expert-userTasks"><div class="cptt-expert-sectionTitle">تسک‌های سمت مشتری</div><div class="cptt-expert-usertasks-items"></div><button type="button" class="button button-small cptt-expert-add-usertask" style="margin-top:10px;">+ افزودن تسک مشتری</button></div><div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;"><button type="button" class="button button-small cptt-expert-add-checkitem" style="flex:1;">+ افزودن چک‌لیست</button><button type="button" class="button button-link-delete cptt-expert-remove-step" style="flex:1;color:#b91c1c;">× حذف مرحله</button></div></div></div>';
        container.insertAdjacentHTML('beforeend', html);
        bindStepAccordions(container);
        refreshStepNumbers(container);
      }
      if (e.target.classList.contains('cptt-expert-add-checkitem')) {
        var btn = e.target;
        var step = btn.closest('.cptt-expert-step');
        if (!step) return;
        var stepId = step.getAttribute('data-step-id');
        var checkId = randId('chk');
        var html = '<div class="cptt-expert-checkRow"><label class="cptt-expert-checkItem"><input type="checkbox" name="steps['+stepId+'][checklist]['+checkId+'][done]" value="1"><span>انجام شد</span></label><input type="text" name="steps['+stepId+'][checklist]['+checkId+'][text]" value="" placeholder="متن آیتم"><input type="url" name="steps['+stepId+'][checklist]['+checkId+'][url]" value="" placeholder="لینک نتیجه (اختیاری)"><button type="button" class="button button-small cptt-expert-remove-checkitem">×</button></div>';
        var chkWrap = step.querySelector('.cptt-expert-checklist-items');
        if (!chkWrap) {
            var itemsDiv = document.createElement('div');
            itemsDiv.className = 'cptt-expert-checklist-items';
            var sectionTitle = step.querySelector('.cptt-expert-checklist .cptt-expert-sectionTitle');
            if (sectionTitle) sectionTitle.parentNode.insertBefore(itemsDiv, btn);
            chkWrap = itemsDiv;
        }
        chkWrap.insertAdjacentHTML('beforeend', html);
      }
      if (e.target.classList.contains('cptt-expert-add-usertask')) {
        var btn = e.target;
        var step = btn.closest('.cptt-expert-step');
        if (!step) return;
        var stepId = step.getAttribute('data-step-id');
        var taskId = randId('ut');
        var html = '<div class="cptt-expert-userTask"><div class="cptt-expert-userTask__fields"><input type="text" name="steps['+stepId+'][user_tasks]['+taskId+'][title]" value="" placeholder="عنوان تسک"><textarea name="steps['+stepId+'][user_tasks]['+taskId+'][desc]" rows="2" placeholder="توضیحات تسک"></textarea><input type="text" class="cptt-jalali-datetime" name="steps['+stepId+'][user_tasks]['+taskId+'][due_at_local]" value="" placeholder="مهلت"><button type="button" class="button button-small cptt-expert-remove-usertask">×</button></div></div>';
        var itemsWrap = step.querySelector('.cptt-expert-usertasks-items');
        if (!itemsWrap) {
            itemsWrap = document.createElement('div');
            itemsWrap.className = 'cptt-expert-usertasks-items';
            btn.parentNode.insertBefore(itemsWrap, btn);
        }
        itemsWrap.insertAdjacentHTML('beforeend', html);
      }
      if (e.target.classList.contains('cptt-expert-remove-checkitem')) e.target.closest('.cptt-expert-checkRow').remove();
      if (e.target.classList.contains('cptt-expert-remove-usertask')) e.target.closest('.cptt-expert-userTask').remove();
    });
  });

  document.addEventListener('click', function(e) {
      var notifLink = e.target.closest('.cptt-notification-item');
      if (notifLink) {
          if (!notifLink.classList.contains('is-read')) {
              notifLink.classList.add('is-read');
              var id = notifLink.getAttribute('data-id');
              if (id) {
                  var fd = new FormData();
                  fd.append('action', 'cptt_expert_mark_single_notification_read');
                  fd.append('nonce', window.CPTT_EXPERT ? CPTT_EXPERT.nonce : window.CPTT_ADMIN.nonce);
                  fd.append('id', id);
                  fetch(window.CPTT_EXPERT ? CPTT_EXPERT.ajax : window.CPTT_ADMIN.ajax, { method: 'POST', body: fd, keepalive: true });
              }
          }
      }

      var delBtn = e.target.closest('.cptt-delete-notif-btn');
      if (delBtn) {
          e.preventDefault(); e.stopPropagation();
          if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
          var id = delBtn.getAttribute('data-id');
          if (id) {
              var wrap = delBtn.closest('.cptt-notification-item-wrap');
              // optimistic UI: حذف فوری از DOM
              if (wrap) {
                  wrap.style.transition = 'opacity .15s ease, transform .15s ease';
                  wrap.style.opacity = '0';
                  wrap.style.transform = 'translateX(-12px)';
                  setTimeout(function(){ if (wrap.parentNode) wrap.parentNode.removeChild(wrap); }, 160);
              }
              var fd = new FormData();
              fd.append('action', 'cptt_expert_delete_notification');
              fd.append('nonce', (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : (window.CPTT_ADMIN && CPTT_ADMIN.nonce) ? CPTT_ADMIN.nonce : '');
              fd.append('id', id);
              var ajaxUrl = (window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : (window.CPTT_ADMIN && CPTT_ADMIN.ajax) ? CPTT_ADMIN.ajax : '/wp-admin/admin-ajax.php';
              fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).catch(function(){});
              // کاهش badge اگر اعلان خوانده‌نشده بود
              var anchor = wrap ? wrap.querySelector('.cptt-notification-item') : null;
              if (anchor && !anchor.classList.contains('is-read')) {
                  var badge = document.querySelector('.cptt-bell-badge');
                  if (badge) {
                      var n = parseInt(badge.textContent || '0', 10) - 1;
                      if (n > 0) { badge.textContent = String(n); }
                      else { badge.style.display = 'none'; badge.textContent = '0'; }
                  }
              }
          }
          return;
      }
      if (e.target.hasAttribute('data-cptt-open-all-notifs')) {
          var modal = qs('.cptt-all-notifs-modal');
          if (modal) {
              modal.removeAttribute('hidden');
              var list = qs('.cptt-all-notifs-list', modal);
              var fd = new FormData();
              fd.append('action', 'cptt_expert_fetch_all_notifications');
              fd.append('nonce', window.CPTT_EXPERT ? CPTT_EXPERT.nonce : window.CPTT_ADMIN.nonce);
              fetch(window.CPTT_EXPERT ? CPTT_EXPERT.ajax : window.CPTT_ADMIN.ajax, { method: 'POST', body: fd })
              .then(r => r.json()).then(data => {
                  if (data.success && list) {
                      list.innerHTML = data.data.html;
                  }
              });
          }
      }
      if (e.target.classList.contains('cptt-all-notifs-modal__close') || e.target.classList.contains('cptt-all-notifs-modal__backdrop')) {
          var modal = qs('.cptt-all-notifs-modal');
          if (modal) modal.setAttribute('hidden', '');
      }
  });

})();

/* =========================================================
   MANAGE FORM: DELIVERY CHAIN (Province → City → Address)
   Isolated per-card, no conflicts with create form
   ========================================================= */
(function() {
  'use strict';

  // Full provinces & cities map (same as create form)
  var PC = {
    "تهران": ["تهران","ورامین","شهریار","قدس","ملارد","پاکدشت","اسلامشهر","رباط کریم","فیروزکوه","دماوند","بومهن","پردیس","نسیم‌شهر","چهاردانگه","باغستان"],
    "البرز": ["کرج","فردیس","نظرآباد","هشتگرد","طالقان","چهارباغ","اشتهارد","ماهدشت","گرمدره"],
    "اصفهان": ["اصفهان","کاشان","خمینی‌شهر","نجف‌آباد","شهرضا","فلاورجان","مبارکه","زرین‌شهر","آران و بیدگل","اردستان","سمیرم","گلپایگان","تیران","شاهین‌شهر","دهاقان","نطنز","فریدن","برخوار","لنجان"],
    "فارس": ["شیراز","مرودشت","جهرم","کازرون","فسا","داراب","لارستان","آباده","اقلید","ممسنی","نی‌ریز","فیروزآباد","سپیدان"],
    "خراسان رضوی": ["مشهد","نیشابور","سبزوار","تربت حیدریه","قوچان","گناباد","کاشمر","تایباد","درگز","تربت جام","فریمان","خواف","چناران","جوین"],
    "آذربایجان شرقی": ["تبریز","مراغه","میانه","اهر","مرند","سراب","هریس","بناب","ملکان","عجبشیر","بستان‌آباد","کلیبر","شبستر","اسکو"],
    "آذربایجان غربی": ["ارومیه","خوی","مهاباد","بوکان","سلماس","میاندوآب","نقده","پیرانشهر","شاهین‌دژ","سردشت","اشنویه","تکاب","ماکو","چالدران"],
    "کرمان": ["کرمان","رفسنجان","جیرفت","بم","زرند","سیرجان","بافت","شهربابک","انار","راور","کوهبنان","قلعه‌گنج","عنبرآباد","منوجان"],
    "خوزستان": ["اهواز","آبادان","دزفول","خرمشهر","ماهشهر","شوشتر","ایذه","بهبهان","اندیمشک","مسجد سلیمان","امیدیه","رامهرمز","دشت‌آزادگان","حمیدیه","کارون"],
    "مازندران": ["ساری","آمل","بابل","قائمشهر","نوشهر","چالوس","تنکابن","رامسر","بهشهر","نکا","جویبار","بابلسر","محمودآباد","فریدونکنار","گلوگاه"],
    "گیلان": ["رشت","انزلی","لاهیجان","لنگرود","صومعه‌سرا","تالش","آستارا","رودبار","فومن","رودسر","آستانه اشرفیه","شفت","ماسال","سیاهکل","رضوانشهر"],
    "هرمزگان": ["بندرعباس","میناب","قشم","بندرلنگه","حاجی‌آباد","رودان","جاسک","بشاگرد","سیریک","خمیر","پارسیان","بستک"],
    "سیستان و بلوچستان": ["زاهدان","زابل","چابهار","ایرانشهر","سراوان","نیکشهر","خاش","سرباز","دلگان","هیرمند","قصرقند"],
    "کرمانشاه": ["کرمانشاه","اسلام‌آباد","هرسین","کنگاور","سنقر","جوانرود","پاوه","دالاهو","قصرشیرین","گیلانغرب","روانسر"],
    "گلستان": ["گرگان","گنبد کاووس","علی‌آباد","مینودشت","بندرگز","رامیان","آق‌قلا","ترکمن","کردکوی","گالیکش","کلاله","آزادشهر"],
    "لرستان": ["خرم‌آباد","بروجرد","کوهدشت","دورود","الیگودرز","ازنا","نورآباد","پلدختر","سلسله"],
    "همدان": ["همدان","ملایر","تویسرکان","نهاوند","بهار","رزن","اسدآباد","کبودرآهنگ","فامنین"],
    "قم": ["قم","کهک","دستجرد","جعفریه"],
    "قزوین": ["قزوین","البرز","بویین‌زهرا","تاکستان","آبیک","اقبالیه","محمدیه","شال","آوج"],
    "زنجان": ["زنجان","ابهر","خرمدره","ایجرود","طارم","ماهنشان","سلطانیه"],
    "اردبیل": ["اردبیل","مشکین‌شهر","پارس‌آباد","خلخال","بیله‌سوار","نمین","نیر","گرمی","سرعین"],
    "بوشهر": ["بوشهر","برازجان","گناوه","دیلم","خورموج","عسلویه","جم","دشتی","تنگستان","دشتستان"],
    "مرکزی": ["اراک","ساوه","محلات","خمین","دلیجان","تفرش","آشتیان","کمیجان","شازند","زرندیه"],
    "ایلام": ["ایلام","مهران","دره‌شهر","آبدانان","دهلران","ایوان","سیروان","ملکشاهی","بدره"],
    "کهگیلویه و بویراحمد": ["یاسوج","گچساران","دوگنبدان","دنا","بهمئی","لنده","چرام","باشت"],
    "خراسان شمالی": ["بجنورد","شیروان","اسفراین","قوچان","مانه و سملقان","جاجرم","فاروج","گرمه","راز و جرگلان"],
    "خراسان جنوبی": ["بیرجند","قاین","طبس","فردوس","بشرویه","درمیان","سربیشه","خوسف","نهبندان"],
    "سمنان": ["سمنان","گرمسار","شاهرود","دامغان","مهدی‌شهر","سرخه","آرادان","میامی"],
    "چهارمحال و بختیاری": ["شهرکرد","بروجن","فارسان","لردگان","سامان","کوهرنگ","کیار","اردل"],
    "کردستان": ["سنندج","سقز","مریوان","بانه","قروه","کامیاران","دیواندره","بیجار","سروآباد","دهگلان"],
    "یزد": ["یزد","میبد","اردکان","بافق","ابرکوه","تفت","خاتم","مهریز","بهاباد"]
  };

  function initManageDelivery(form) {
    var deliverySelect = form.querySelector('.cptt-manage-delivery-method');
    var provinceWrap = form.querySelector('.cptt-manage-province-wrap');
    var provinceSelect = form.querySelector('.cptt-manage-province');
    var cityWrap = form.querySelector('.cptt-manage-city-wrap');
    var citySelect = form.querySelector('.cptt-manage-city');
    var addressWrap = form.querySelector('.cptt-manage-address-wrap');

    if (!deliverySelect) return;

    // Populate cities based on saved province
    function populateCities(prov, selectedCity) {
      if (!citySelect) return;
      citySelect.innerHTML = '<option value="">— انتخاب شهر —</option>';
      if (prov && PC[prov]) {
        PC[prov].forEach(function(city) {
          var opt = document.createElement('option');
          opt.value = city;
          opt.textContent = city;
          if (city === selectedCity) opt.selected = true;
          citySelect.appendChild(opt);
        });
      }
    }

    // Populate provinces
    if (provinceSelect) {
      var currentProv = provinceSelect.value;
      provinceSelect.innerHTML = '<option value="">— انتخاب استان —</option>';
      Object.keys(PC).sort().forEach(function(prov) {
        var opt = document.createElement('option');
        opt.value = prov;
        opt.textContent = prov;
        if (prov === currentProv) opt.selected = true;
        provinceSelect.appendChild(opt);
      });

      // If province already selected, populate cities
      if (currentProv && PC[currentProv]) {
        var savedCity = citySelect ? (citySelect.querySelector('option[selected]') || {value:''}).value : '';
        populateCities(currentProv, savedCity);
      }
    }

    // Province change → load cities
    if (provinceSelect) {
      provinceSelect.addEventListener('change', function() {
        var prov = provinceSelect.value;
        if (prov) {
          populateCities(prov, '');
          if (cityWrap) cityWrap.style.display = 'block';
          if (addressWrap) addressWrap.style.display = 'none';
        } else {
          if (cityWrap) cityWrap.style.display = 'none';
          if (addressWrap) addressWrap.style.display = 'none';
        }
      });
    }

    // City change → show address
    if (citySelect) {
      citySelect.addEventListener('change', function() {
        if (citySelect.value) {
          if (addressWrap) addressWrap.style.display = 'block';
        } else {
          if (addressWrap) addressWrap.style.display = 'none';
        }
      });
    }

    // Delivery method change
    deliverySelect.addEventListener('change', function() {
      if (deliverySelect.value === 'shipping') {
        if (provinceWrap) provinceWrap.style.display = 'block';
        if (provinceSelect && provinceSelect.value) {
          if (cityWrap) cityWrap.style.display = 'block';
        }
      } else {
        if (provinceWrap) provinceWrap.style.display = 'none';
        if (cityWrap) cityWrap.style.display = 'none';
        if (addressWrap) addressWrap.style.display = 'none';
      }
    });

    // Initialize visibility on load
    if (deliverySelect.value === 'shipping') {
      if (provinceWrap) provinceWrap.style.display = 'block';
      if (provinceSelect && provinceSelect.value && cityWrap) cityWrap.style.display = 'block';
      if (citySelect && citySelect.value && addressWrap) addressWrap.style.display = 'block';
    }
  }

  /* =========================================================
     NEW CUSTOMER MODAL - works for BOTH create & manage forms
     ========================================================= */
  function initNewCustomerModals() {
    // For create form: inject trigger option into client selects
    document.querySelectorAll('.cptt-expert-create-form select[name="client_user_id"]').forEach(function(sel) {
      if (!sel.querySelector('option[value="new_customer_trigger"]')) {
        var opt = document.createElement('option');
        opt.value = 'new_customer_trigger';
        opt.textContent = '+ ثبت مشتری جدید —';
        opt.style.fontWeight = 'bold';
        opt.style.color = '#6366f1';
        sel.insertBefore(opt, sel.firstChild);
      }
      sel.addEventListener('change', function() {
        if (sel.value === 'new_customer_trigger') {
          openNewCustomerModal(sel);
          sel.value = '';
        }
      });
    });

    // For manage forms: inject trigger option into client selects
    document.querySelectorAll('.cptt-expert-project-form select[name="client_user_id"]').forEach(function(sel) {
      if (!sel.querySelector('option[value="new_customer_trigger"]')) {
        var opt = document.createElement('option');
        opt.value = 'new_customer_trigger';
        opt.textContent = '+ ثبت مشتری جدید —';
        opt.style.fontWeight = 'bold';
        opt.style.color = '#6366f1';
        sel.insertBefore(opt, sel.firstChild);
      }
      sel.addEventListener('change', function() {
        if (sel.value === 'new_customer_trigger') {
          openNewCustomerModal(sel);
          sel.value = '';
        }
      });
    });
  }

  var _activeClientSelect = null;

  function openNewCustomerModal(triggerSelect) {
    _activeClientSelect = triggerSelect;
    var modal = document.getElementById('cptt-new-customer-modal');
    if (modal) {
      modal.style.display = 'flex';
      var fnInput = document.getElementById('cptt-cust-fullname');
      if (fnInput) fnInput.focus();
    }
  }

  function bindNewCustomerSubmit() {
    var custSubmit = document.getElementById('cptt-cust-submit');
    var custClose = document.getElementById('cptt-cust-close');
    var custModal = document.getElementById('cptt-new-customer-modal');
    if (!custSubmit || !custModal) return;

    if (custClose) {
      custClose.addEventListener('click', function() {
        custModal.style.display = 'none';
        _activeClientSelect = null;
      });
    }

    // Close on backdrop click
    custModal.addEventListener('click', function(e) {
      if (e.target === custModal) {
        custModal.style.display = 'none';
        _activeClientSelect = null;
      }
    });

    custSubmit.addEventListener('click', function() {
      var fullname = (document.getElementById('cptt-cust-fullname') || {}).value;
      var phone = (document.getElementById('cptt-cust-phone') || {}).value;
      var msg = document.getElementById('cptt-cust-msg');
      if (fullname) fullname = fullname.trim();
      if (phone) phone = phone.trim();

      if (!fullname || !phone) {
        if (msg) { msg.textContent = 'نام و شماره موبایل الزامی است.'; msg.style.color = '#ef4444'; }
        return;
      }
      if (msg) { msg.textContent = 'در حال ثبت...'; msg.style.color = '#475569'; }

      var ajax = (window.CPTT_EXPERT && CPTT_EXPERT.ajax) ? CPTT_EXPERT.ajax : '';
      var nonce = (window.CPTT_EXPERT && CPTT_EXPERT.nonce) ? CPTT_EXPERT.nonce : '';

      var fd = new FormData();
      fd.append('action', 'cptt_expert_create_customer');
      fd.append('nonce', nonce);
      fd.append('full_name', fullname);
      fd.append('phone', phone);

      fetch(ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (res.success) {
            if (msg) { msg.textContent = 'مشتری با موفقیت ثبت شد!'; msg.style.color = '#047857'; }

            // Add to ALL client selects on page
            document.querySelectorAll('select[name="client_user_id"]').forEach(function(sel) {
              // Remove trigger option temporarily
              var existing = sel.querySelector('option[value="' + res.data.ID + '"]');
              if (!existing) {
                var opt = document.createElement('option');
                opt.value = res.data.ID;
                opt.textContent = res.data.display_name + ' (' + res.data.user_email + ')';
                sel.appendChild(opt);
              }
            });

            // Select in triggering select
            if (_activeClientSelect) {
              _activeClientSelect.value = res.data.ID;
            }

            setTimeout(function() {
              custModal.style.display = 'none';
              var fn = document.getElementById('cptt-cust-fullname');
              var ph = document.getElementById('cptt-cust-phone');
              if (fn) fn.value = '';
              if (ph) ph.value = '';
              if (msg) msg.textContent = '';
              _activeClientSelect = null;
            }, 1200);
          } else {
            if (msg) { msg.textContent = (res.data || 'خطا در ثبت مشتری'); msg.style.color = '#ef4444'; }
          }
        })
        .catch(function() {
          if (msg) { msg.textContent = 'خطای شبکه'; msg.style.color = '#ef4444'; }
        });
    });
  }

  /* =========================================================
     MANAGE FORM: FINANCE SUMMARY (live update)
     ========================================================= */
  function updateManageFinanceSummary(form) {
    var costEl = form.querySelector('.cptt-manage-fin-cost');
    var paidEl = form.querySelector('.cptt-manage-fin-paid');
    var remainEl = form.querySelector('.cptt-manage-fin-remain');
    if (!costEl || !paidEl || !remainEl) return;

    var totalCost = 0, totalPaid = 0;
    form.querySelectorAll('.cptt-currency-input[name*="[cost]"]').forEach(function(inp) {
      totalCost += parseFloat(inp.value.replace(/,/g,'')) || 0;
    });
    form.querySelectorAll('.cptt-currency-input[name*="[paid]"]').forEach(function(inp) {
      totalPaid += parseFloat(inp.value.replace(/,/g,'')) || 0;
    });

    var remain = totalCost - totalPaid;
    costEl.textContent = totalCost.toLocaleString('en');
    paidEl.textContent = totalPaid.toLocaleString('en');
    remainEl.textContent = remain.toLocaleString('en');
    remainEl.style.color = remain > 0 ? '#dc2626' : '#059669';
  }

  /* =========================================================
     DOMContentLoaded INIT
     ========================================================= */
  document.addEventListener('DOMContentLoaded', function() {

    // Init delivery for all manage forms already in DOM
    document.querySelectorAll('.cptt-expert-project-form').forEach(function(form) {
      initManageDelivery(form);
    });

    // Init new customer modal trigger for existing forms
    initNewCustomerModals();
    initClientSearchPickers();
    bindNewCustomerSubmit();

    // Watch for dynamically opened project cards (delegation)
    var expertGrid = document.getElementById('cptt-expert-grid');
    if (expertGrid) {
      var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
          m.addedNodes.forEach(function(node) {
            if (node.nodeType !== 1) return;
            node.querySelectorAll && node.querySelectorAll('.cptt-expert-project-form').forEach(function(form) {
              initManageDelivery(form);
            });
            // Re-init new customer triggers
            if (node.querySelector && node.querySelector('select[name="client_user_id"]')) {
              initNewCustomerModals();
              initClientSearchPickers();
            }
          });
        });
      });
      observer.observe(expertGrid, { childList: true, subtree: true });
    }

    // Live finance summary update on step cost/paid change
    document.addEventListener('input', function(e) {
      if (e.target.classList.contains('cptt-currency-input')) {
        var form = e.target.closest('.cptt-expert-project-form');
        if (form) updateManageFinanceSummary(form);
      }
    });

    // Finance update after project form save
    document.addEventListener('cptt:projectSaved', function(e) {
      if (e.detail && e.detail.projectId) {
        var card = document.querySelector('.cptt-expertCard[data-project-id="' + e.detail.projectId + '"]');
        if (card) {
          var form = card.querySelector('.cptt-expert-project-form');
          if (form) updateManageFinanceSummary(form);
        }
      }
    });

  });

})();

/* =========================================================
   PUBLIC HUB: Expert Badge Modal + Project Detail Modal
   + Filters + روزهای هفته فارسی
   ========================================================= */
(function () {
  'use strict';

  /** UTF-8 safe base64 decode - fixes Persian/Arabic garbled text */
  function b64DecodeUtf8(b64) {
    try {
      var bin = atob(b64);
      var bytes = new Uint8Array(bin.length);
      for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
      if (window.TextDecoder) return new TextDecoder('utf-8').decode(bytes);
      // fallback for very old browsers
      return decodeURIComponent(escape(bin));
    } catch(e) { return ''; }
  }

  var PERSIAN_DAYS = ['یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنج‌شنبه','جمعه','شنبه'];

  /* ---------- Jalali helpers (mini) ---------- */
  function j2g(jy,jm,jd){
    jy=parseInt(jy,10)+1595;
    var days=-355668+(365*jy)+Math.floor(jy/33)*8+Math.floor(((jy%33)+3)/4)+parseInt(jd,10);
    days+=(jm<7)?((jm-1)*31):(((jm-7)*30)+186);
    var gy=400*Math.floor(days/146097);days%=146097;
    if(days>36524){gy+=100*Math.floor(--days/36524);days%=36524;if(days>=365)days++;}
    gy+=4*Math.floor(days/1461);days%=1461;
    if(days>365){gy+=Math.floor((days-1)/365);days=(days-1)%365;}
    var gd=days+1;
    var sal=[0,31,((gy%4===0&&gy%100!==0)||(gy%400===0))?29:28,31,30,31,30,31,31,30,31,30,31];
    var gm=1;for(;gm<=12;gm++){if(gd<=sal[gm])break;gd-=sal[gm];}
    return [gy,gm,gd];
  }

  function toEn(s){
    var fa='۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩',en='01234567890123456789';
    return String(s||'').replace(/[۰-۹٠-٩]/g,function(c){return en[fa.indexOf(c)]||c;});
  }

  /** Extract day-of-week from a jalali datetime string like "۱۴۰۳/۰۲/۱۵ ۱۴:۳۰" */
  function jalaliDayOfWeek(faDateStr) {
    if (!faDateStr) return '';
    var s = toEn(String(faDateStr));
    var m = s.match(/(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/);
    if (!m) return '';
    var jy=parseInt(m[1],10), jm=parseInt(m[2],10), jd=parseInt(m[3],10);
    var g = j2g(jy,jm,jd);
    var date = new Date(g[0], g[1]-1, g[2]);
    return PERSIAN_DAYS[date.getDay()] || '';
  }

  /** Append day-of-week to all date elements with class cptt-show-day */
  function appendDaysOfWeek() {
    document.querySelectorAll('.cptt-show-day').forEach(function(el) {
      var txt = el.getAttribute('data-date') || el.textContent;
      var day = jalaliDayOfWeek(txt);
      if (day && !el.querySelector('.cptt-dow')) {
        var span = document.createElement('span');
        span.className = 'cptt-dow';
        span.textContent = ' (' + day + ')';
        span.style.cssText = 'font-size:11px;opacity:0.7;font-weight:500;';
        el.appendChild(span);
      }
    });
  }

  /* =========================================================
     HUB MODAL for Project Details
     ========================================================= */
  function initHubProjectModal() {
    var modal = document.getElementById('cptt-hub-modal');
    if (!modal) return;

    var backdrop = modal.querySelector('.cptt-hubModal__backdrop');
    var closeBtn = modal.querySelector('.cptt-hubModal__close');
    var titleEl  = modal.querySelector('#cptt-hub-modal-title');
    var metaEl   = modal.querySelector('#cptt-hub-modal-meta');
    var bodyEl   = modal.querySelector('#cptt-hub-modal-body');

    function openModal(project) {
      if (titleEl) titleEl.textContent = project.title || '';

      // Meta chips
      var meta = [];
      if (project.customer) meta.push('👤 مشتری: ' + project.customer);
      if (project.experts && project.experts.length) meta.push('🧑‍💼 کارشناسان: ' + project.experts.join('، '));
      if (project.deadline) {
        var dlDay = jalaliDayOfWeek(project.deadline);
        meta.push('📅 مهلت: ' + project.deadline + (dlDay?' ('+dlDay+')':''));
      }
      if (project.product) meta.push('📦 محصول: ' + project.product);
      if (project.categories && project.categories.length) meta.push('🏷 دسته‌بندی: ' + project.categories.join('، '));
      if (metaEl) metaEl.innerHTML = meta.map(function(m){ return '<span class="cptt-hubModal__metaItem">' + escH(m) + '</span>'; }).join('');

      var html = '';

      // پیشرفت کلی
      var progress = project.progress || {};
      var pct = progress.percent || 0;
      html += '<div class="cptt-hubModal__progress">';
      html += '<div class="cptt-hubModal__progressBar"><div class="cptt-hubModal__progressFill" style="width:'+escH(String(pct))+'%"></div></div>';
      html += '<div class="cptt-hubModal__progressLabel">'+escH(String(pct))+'% پیشرفت — '+escH(progress.done||0)+'/'+escH(progress.total||0)+' مرحله</div>';
      html += '</div>';

      // خلاصه مالی (اگر full_details)
      if (project.full_details && project.financial) {
        var fin = project.financial;
        if (fin.cost > 0) {
          html += '<div class="cptt-hubModal__finRow">';
          html += '<div class="cptt-hubModal__finBox"><span>جمع هزینه</span><strong>'+escH(Number(fin.cost).toLocaleString('en'))+'</strong></div>';
          html += '<div class="cptt-hubModal__finBox"><span>دریافتی</span><strong style="color:#059669">'+escH(Number(fin.paid).toLocaleString('en'))+'</strong></div>';
          html += '<div class="cptt-hubModal__finBox"><span>مانده</span><strong style="color:'+(fin.remain>0?'#dc2626':'#059669')+'">'+escH(Number(fin.remain).toLocaleString('en'))+'</strong></div>';
          if (project.settled) html += '<div class="cptt-hubModal__finBox"><span>وضعیت</span><strong style="color:#059669">تسویه شده</strong></div>';
          html += '</div>';
        }
      }

      // مراحل
      var steps = (project.steps && Array.isArray(project.steps)) ? project.steps : [];
      if (steps.length) {
        html += '<div class="cptt-hubModal__stepsTitle">مراحل پروژه</div>';
        html += '<div class="cptt-hubModal__steps">';
        steps.forEach(function(s) {
          var st = s.status || 'todo';
          var stLabel = st==='done'?'انجام‌شده':st==='current'?'در حال انجام':'انجام‌نشده';
          var updDay = jalaliDayOfWeek(s.updated_at_fa || '');
          var dueDay = jalaliDayOfWeek(s.due_fa || '');

          html += '<div class="cptt-hubModal__step cptt-hubModal__step--'+escH(st)+'">';
          html += '<div class="cptt-hubModal__stepHead">';
          html += '<strong>'+escH(s.index||'')+'&nbsp;'+escH(s.title||'')+'</strong>';
          html += '<span class="cptt-expertStatusBadge cptt-expertStatusBadge--'+escH(st)+'">'+escH(stLabel)+'</span>';
          html += '</div>';

          if (s.due_fa)
            html += '<div class="cptt-hubModal__stepMeta">📅 مهلت: '+escH(s.due_fa+(dueDay?' ('+dueDay+')':''))+'</div>';
          if (s.updated_at_fa)
            html += '<div class="cptt-hubModal__stepMeta">🕐 آخرین بروزرسانی: '+escH(s.updated_at_fa+(updDay?' ('+updDay+')':''))+'</div>';
          if (s.desc)
            html += '<div class="cptt-hubModal__stepDesc">'+escH(s.desc)+'</div>';

          // چک‌لیست
          if (s.checklist_total > 0) {
            html += '<div class="cptt-hubModal__stepChecklist">';
            html += '<span>✅ چک‌لیست: '+escH(String(s.checklist_done))+'/'+escH(String(s.checklist_total))+'</span>';
            if (s.checklist_items && s.checklist_items.length) {
              html += '<ul class="cptt-hubModal__checkItems">';
              s.checklist_items.forEach(function(ci) {
                html += '<li class="'+(ci.done?'is-done':'')+'">';
                html += escH(ci.text||'');
                if (ci.done && ci.url) html += ' <a href="'+escH(ci.url)+'" target="_blank" rel="noopener">مشاهده نتیجه</a>';
                html += '</li>';
              });
              html += '</ul>';
            }
            html += '</div>';
          }

          // تسک مشتری
          if (s.user_tasks_total > 0) {
            html += '<div class="cptt-hubModal__stepChecklist" style="margin-top:10px;">';
            html += '<span>📋 تسک‌های سمت مشتری: ' + escH(String(s.user_tasks_done)) + '/' + escH(String(s.user_tasks_total)) + '</span>';
            if (s.user_tasks_items && s.user_tasks_items.length) {
              html += '<ul class="cptt-hubModal__checkItems" style="margin-top:6px; list-style:circle; padding-right:15px;">';
              s.user_tasks_items.forEach(function(ut) {
                var taskStatus = ut.done ? '<span style="color:#059669; font-weight:bold;">[تکمیل شده]</span>' : '<span style="color:#f59e0b; font-weight:bold;">[در انتظار پاسخ]</span>';
                html += '<li style="margin-bottom:6px;">';
                html += '<strong style="color:#0f172a;">' + escH(ut.title) + '</strong> ' + taskStatus;
                if (ut.desc) html += '<div style="font-size:11px; color:#64748b; margin-top:2px;">' + escH(ut.desc) + '</div>';
                if (ut.due_fa) html += '<div style="font-size:11px; color:#dc2626; margin-top:2px;">📅 مهلت تسک: ' + escH(ut.due_fa) + '</div>';
                if (ut.done && ut.response) html += '<div style="font-size:12px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:6px; margin-top:4px; color:#047857;">💬 پاسخ مشتری: ' + escH(ut.response) + '</div>';
                html += '</li>';
              });
              html += '</ul>';
            }
            html += '</div>';
          }

          if (project.full_details && (s.cost > 0 || s.paid > 0)) {
            var stepRemain = s.cost - s.paid;
            html += '<div style="font-size:11px; color:#475569; margin-top:8px; padding-top:6px; border-top:1px dashed #cbd5e1; display:flex; gap:12px; flex-wrap:wrap;">';
            html += '<span>💰 هزینه مرحله: <b>' + Number(s.cost).toLocaleString('en') + '</b> ریال</span>';
            html += '<span>💳 دریافتی: <b>' + Number(s.paid).toLocaleString('en') + '</b> ریال</span>';
            html += '<span>⏳ مانده: <b style="color:' + (stepRemain > 0 ? '#dc2626' : '#059669') + '">' + Number(stepRemain).toLocaleString('en') + '</b> ریال</span>';
            html += '</div>';
          }

          html += '</div>';
        });
        html += '</div>';
      } else {
        html += '<div class="cptt-empty">جزئیات مراحل در دسترس نیست.</div>';
      }

      if (bodyEl) bodyEl.innerHTML = html;
      modal.removeAttribute('hidden');
      document.body.style.overflow = 'hidden';
    }

    function closeModal() {
      modal.setAttribute('hidden', '');
      document.body.style.overflow = '';
    }

    if (backdrop) backdrop.addEventListener('click', closeModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeModal(); });

    // Delegate clicks for project open buttons
    document.addEventListener('click', function(e) {
      var btn = e.target.closest('.cptt-publicProject__open');
      if (!btn) return;
      var b64 = btn.getAttribute('data-project');
      if (!b64) return;
      try {
        var project = JSON.parse(b64DecodeUtf8(b64));
        openModal(project);
      } catch(ex) { console.error('CPTT hub modal parse error', ex); }
    });
  }

  /* =========================================================
     HUB MODAL for Expert Profile
     ========================================================= */
  function initHubExpertModal() {
    // Create expert modal if not exists
    var existingModal = document.getElementById('cptt-expert-profile-modal');
    if (!existingModal) {
      var m = document.createElement('div');
      m.id = 'cptt-expert-profile-modal';
      m.className = 'cptt-hubModal cptt-expertProfileModal';
      m.setAttribute('hidden', '');
      m.innerHTML = '<div class="cptt-hubModal__backdrop"></div>' +
        '<div class="cptt-hubModal__dialog" role="dialog" aria-modal="true">' +
          '<button type="button" class="cptt-hubModal__close" aria-label="بستن">×</button>' +
          '<div class="cptt-expertProfileModal__inner" id="cptt-expert-profile-content"></div>' +
        '</div>';
      document.body.appendChild(m);
      existingModal = m;
    }

    var modal = existingModal;
    var backdrop = modal.querySelector('.cptt-hubModal__backdrop');
    var closeBtn = modal.querySelector('.cptt-hubModal__close');
    var content  = modal.querySelector('#cptt-expert-profile-content');

    function openExpertModal(expert) {
      var html = '<div class="cptt-expertProfile">';
      
      // بخش بالا: آواتار + اسم + سمت
      html += '<div class="cptt-expertProfile__header">';
      html += '<div class="cptt-expertProfile__avatar">';
      var avatarSrc = expert.avatar || expert.avatar_url || '';
      if (avatarSrc) {
        html += '<img src="'+escH(avatarSrc)+'" alt="'+escH(expert.name||'')+'" loading="lazy">';
      } else {
        html += '<div class="cptt-expertProfile__avatarDefault">'+escH((expert.name||'?').charAt(0))+'</div>';
      }
      html += '</div>';
      html += '<div class="cptt-expertProfile__headerInfo">';
      html += '<h2 class="cptt-expertProfile__name">'+escH(expert.name||'کارشناس')+'</h2>';
      var title = expert.title || '';
      if (title) html += '<div class="cptt-expertProfile__title">'+escH(title)+'</div>';
      html += '</div></div>';

      // بیوگرافی
      var bio = expert.bio || '';
      if (bio) {
        html += '<div class="cptt-expertProfile__section">';
        html += '<div class="cptt-expertProfile__sectionTitle">درباره من</div>';
        html += '<div class="cptt-expertProfile__bio">'+escH(bio)+'</div>';
        html += '</div>';
      }

      // آمار پروژه‌ها
      var hasStats = (expert.active_projects !== undefined || expert.completed_projects !== undefined || expert.done_steps !== undefined);
      if (hasStats) {
        html += '<div class="cptt-expertProfile__section">';
        html += '<div class="cptt-expertProfile__sectionTitle">آمار پروژه‌ها</div>';
        html += '<div class="cptt-expertProfile__stats">';
        if (expert.active_projects !== undefined)
          html += '<div class="cptt-expertProfile__statBox"><strong>'+escH(String(expert.active_projects))+'</strong><span>پروژه فعال</span></div>';
        if (expert.completed_projects !== undefined)
          html += '<div class="cptt-expertProfile__statBox"><strong>'+escH(String(expert.completed_projects))+'</strong><span>پروژه تکمیل‌شده</span></div>';
        if (expert.done_steps !== undefined)
          html += '<div class="cptt-expertProfile__statBox"><strong>'+escH(String(expert.done_steps))+'</strong><span>مرحله انجام‌شده</span></div>';
        html += '</div></div>';
      }

      // تخصص‌ها
      var skills = expert.specialties || expert.skills || [];
      if (skills && skills.length) {
        html += '<div class="cptt-expertProfile__section">';
        html += '<div class="cptt-expertProfile__sectionTitle">تخصص‌ها</div>';
        html += '<div class="cptt-expertProfile__skills">';
        skills.forEach(function(sk){
          if (sk) html += '<span class="cptt-expertProfile__skill">'+escH(String(sk))+'</span>';
        });
        html += '</div></div>';
      }

      html += '</div>'; // end cptt-expertProfile

      if (content) content.innerHTML = html;
      modal.removeAttribute('hidden');
      document.body.style.overflow = 'hidden';
    }

    function closeModal() {
      modal.setAttribute('hidden','');
      document.body.style.overflow = '';
    }

    if (backdrop) backdrop.addEventListener('click', closeModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);

    // Delegate for expert badge clicks
    document.addEventListener('click', function(e) {
      var btn = e.target.closest('.cptt-expertBadge');
      if (!btn) return;
      var b64 = btn.getAttribute('data-expert');
      if (!b64) return;
      try {
        var expert = JSON.parse(b64DecodeUtf8(b64));
        openExpertModal(expert);
      } catch(ex) { console.error('CPTT expert modal parse error', ex); }
    });
  }

  /* =========================================================
     HUB FILTERS (search, expert, product, cat, deadline)
     ========================================================= */
  function initHubFilters() {
    var grid   = document.getElementById('cptt-hub-grid');
    var empty  = document.getElementById('cptt-hub-empty');
    var count  = document.getElementById('cptt-hub-count');
    var search = document.getElementById('cptt-hub-search');
    var selExp = document.getElementById('cptt-hub-expert');
    var selProd= document.getElementById('cptt-hub-product');
    var selCat = document.getElementById('cptt-hub-cat');
    var selDl  = document.getElementById('cptt-hub-deadline');
    var reset  = document.getElementById('cptt-hub-reset');

    if (!grid) return;

    function filter() {
      var cards = Array.prototype.slice.call(grid.querySelectorAll('.cptt-publicProject'));
      var q   = search ? search.value.toLowerCase() : '';
      var exp = selExp  ? selExp.value  : '';
      var prod= selProd ? selProd.value : '';
      var cat = selCat  ? selCat.value  : '';
      var dl  = selDl   ? selDl.value   : '';
      var vis = 0;

      cards.forEach(function(c) {
        var show = true;
        if (q && !(c.getAttribute('data-search')||'').toLowerCase().includes(q)) show = false;
        if (show && exp  && !(c.getAttribute('data-experts')||'').includes(','+exp+',')) show = false;
        if (show && prod && c.getAttribute('data-product') !== prod) show = false;
        if (show && cat  && !(c.getAttribute('data-cats')||'').includes(','+cat+',')) show = false;
        if (show && dl   && c.getAttribute('data-deadline') !== dl) show = false;
        c.style.display = show ? '' : 'none';
        if (show) vis++;
      });

      if (count) count.textContent = vis;
      if (empty) empty[vis === 0 ? 'removeAttribute' : 'setAttribute']('hidden','');
    }

    [search, selExp, selProd, selCat, selDl].forEach(function(el){
      if (el) el.addEventListener('input', filter);
    });

    if (reset) {
      reset.addEventListener('click', function() {
        [search, selExp, selProd, selCat, selDl].forEach(function(el){ if(el) el.value=''; });
        filter();
      });
    }
  }

  /* =========================================================
     روزهای هفته در کارت‌های پروژه داشبورد کارشناس
     ========================================================= */
  function injectDaysOfWeekInCards() {
    // Expert dashboard cards
    document.querySelectorAll('.cptt-expertCard__infoGrid [data-date], .cptt-expertCard__meta[data-date]').forEach(function(el){
      var txt = el.getAttribute('data-date') || el.textContent;
      var day = jalaliDayOfWeek(txt);
      if (day && !el.querySelector('.cptt-dow')) {
        var sp = document.createElement('span');
        sp.className = 'cptt-dow';
        sp.textContent = ' (' + day + ')';
        el.appendChild(sp);
      }
    });

    // Public hub project cards
    document.querySelectorAll('.cptt-project__meta[data-date], .cptt-publicProject__meta[data-date]').forEach(function(el){
      var txt = el.getAttribute('data-date') || el.textContent;
      var day = jalaliDayOfWeek(txt);
      if (day && !el.querySelector('.cptt-dow')) {
        var sp = document.createElement('span');
        sp.className = 'cptt-dow';
        sp.textContent = ' (' + day + ')';
        el.appendChild(sp);
      }
    });
  }

  /* =========================================================
     STEP ASSIGNED EXPERT MODAL
     ========================================================= */
  function initStepExpertModal() {
    if (!document.getElementById('cptt-step-expert-modal')) {
      var m = document.createElement('div');
      m.id = 'cptt-step-expert-modal';
      m.setAttribute('hidden','');
      m.innerHTML =
        '<div class="cptt-stepExpertModal__backdrop"></div>' +
        '<div class="cptt-stepExpertModal__dialog">' +
          '<button type="button" class="cptt-stepExpertModal__close" aria-label="بستن">×</button>' +
          '<div class="cptt-stepExpertModal__title">انتخاب کارشناسان مسئول مرحله</div>' +
          '<div class="cptt-stepExpertModal__list" id="cptt-sep-list"></div>' +
        '</div>';
      document.body.appendChild(m);
      var bd = m.querySelector('.cptt-stepExpertModal__backdrop');
      var cl = m.querySelector('.cptt-stepExpertModal__close');
      function closeModal(){ m.setAttribute('hidden',''); document.body.style.overflow=''; m._hiddenInput=null; m._stepEl=null; m._experts=[]; }
      if (bd) bd.addEventListener('click', closeModal);
      if (cl) cl.addEventListener('click', closeModal);
    }

    document.addEventListener('click', function(e) {
      var btn = e.target.closest('.cptt-step-expert-btn');
      if (!btn) return;

      var stepEl = btn.closest('[data-step-id]');
      if (!stepEl) return;
      var stepId = stepEl.getAttribute('data-step-id');

      // hidden input با نام صحیح
      var hiddenInput = stepEl.querySelector('input[name*="[assigned_expert_id]"]');
      var currentVals = Array.prototype.map.call(stepEl.querySelectorAll('.cptt-step-expert-hidden-list input[type="hidden"]'), function(inp){ return String(inp.value); });
      if (!currentVals.length && hiddenInput && hiddenInput.value) currentVals = [String(hiddenInput.value)];

      // گرفتن لیست کارشناسان از article card
      var card = btn.closest('[data-project-experts]');
      var experts = [];
      if (card) {
        var b64 = card.getAttribute('data-project-experts');
        if (b64) {
          try { experts = JSON.parse(b64DecodeUtf8(b64)); } catch(e) {}
        }
      }

      // fallback: از checkboxهای کارشناس در همان فرم
      if (!experts.length) {
        var form = btn.closest('form');
        if (form) {
          form.querySelectorAll('input[name="expert_user_ids[]"]').forEach(function(cb) {
            var lbl = cb.closest('label');
            var nm = lbl ? (lbl.querySelector('span')||{textContent:cb.value}).textContent.trim() : cb.value;
            experts.push({ id: String(cb.value), name: nm });
          });
        }
      }

      if (!experts.length) {
        alert('هیچ کارشناسی برای این پروژه تعیین نشده است.');
        return;
      }

      var modal = document.getElementById('cptt-step-expert-modal');
      var listEl = document.getElementById('cptt-sep-list');

      var html = '<div class="cptt-sep-option cptt-sep-option--hint">یک یا چند کارشناس را انتخاب کنید. برای پاک کردن انتخاب، همه را بردارید.</div>';
      experts.forEach(function(ex) {
        var chk = (currentVals.indexOf(String(ex.id)) !== -1) ? ' checked' : '';
        html += '<div class="cptt-sep-option">' +
          '<label><input type="checkbox" name="sep_choice[]" value="'+escH(String(ex.id))+'"'+chk+'> ' +
          '<span>'+escH(ex.name||String(ex.id))+'</span></label>' +
          '</div>';
      });

      html += '<div class="cptt-sep-actions"><button type="button" class="cptt-btn cptt-btn--primary cptt-sep-confirm">✔ تأیید انتخاب</button></div>';

      if (listEl) listEl.innerHTML = html;
      modal._hiddenInput = hiddenInput;
      modal._stepEl = stepEl;
      modal._stepId = stepId;
      modal._experts = experts;
      modal.removeAttribute('hidden');
      document.body.style.overflow = 'hidden';
    });

    // تأیید انتخاب
    document.addEventListener('click', function(e) {
      if (!e.target.classList.contains('cptt-sep-confirm')) return;
      var modal = document.getElementById('cptt-step-expert-modal');
      if (!modal) return;
      var checked = Array.prototype.slice.call(modal.querySelectorAll('input[name="sep_choice[]"]:checked'));
      var vals = checked.map(function(ch){ return String(ch.value); });
      var val = vals.length ? vals[0] : '';
      var experts = modal._experts || [];

      // ذخیره در hidden inputها
      if (modal._hiddenInput) { modal._hiddenInput.value = val; }
      if (modal._stepEl) {
        var list = modal._stepEl.querySelector('.cptt-step-expert-hidden-list');
        if (list) {
          var stepId = modal._stepEl.getAttribute('data-step-id') || '';
          list.innerHTML = vals.map(function(v){ return '<input type="hidden" name="steps[' + escH(stepId) + '][assigned_expert_ids][]" value="' + escH(v) + '">'; }).join('');
        }
      }

      // آپدیت متن دکمه
      if (modal._stepEl) {
        var dispBtn = modal._stepEl.querySelector('.cptt-step-expert-btn');
        if (dispBtn) {
          if (vals.length) {
            var names = vals.map(function(v){ var found = experts.filter(function(ex){ return String(ex.id)===String(v); }); return found.length ? found[0].name : v; });
            dispBtn.textContent = '👥 ' + names.join('، ');
            dispBtn.classList.add('has-expert');
            var titleStrong = modal._stepEl.querySelector('.cptt-expert-step__toggleMain strong');
            if (titleStrong) {
              var oldAv = titleStrong.querySelector('.cptt-step-toggle-avatars'); if (oldAv) oldAv.remove();
              var avWrap = document.createElement('span'); avWrap.className = 'cptt-step-toggle-avatars';
              vals.forEach(function(v){ var found = experts.filter(function(ex){ return String(ex.id)===String(v); })[0]; if(found && found.avatar){ var img=document.createElement('img'); img.src=found.avatar; img.alt=''; avWrap.appendChild(img); } });
              if (avWrap.children.length) titleStrong.appendChild(avWrap);
            }
          } else {
            dispBtn.textContent = '👥 انتخاب کارشناسان مرحله';
            dispBtn.classList.remove('has-expert');
          }
        }
      }

      modal.setAttribute('hidden','');
      document.body.style.overflow='';
    });
  }

  function escH(s) {
    return String(s||'').replace(/[&<>"']/g,function(c){
      return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[c];
    });
  }

  /* =========================================================
     DOMContentLoaded
     ========================================================= */
  document.addEventListener('DOMContentLoaded', function() {
    initHubProjectModal();
    initHubExpertModal();
    initHubFilters();
    initStepExpertModal();
    injectDaysOfWeekInCards();
    appendDaysOfWeek();

    // Re-run on card expand (MutationObserver for dynamic content)
    var grid = document.getElementById('cptt-expert-grid');
    if (grid) {
      var obs = new MutationObserver(function(muts) {
        muts.forEach(function(m) {
          if (m.type === 'attributes' && m.attributeName === 'hidden') {
            injectDaysOfWeekInCards();
          }
          if (m.type === 'childList') injectDaysOfWeekInCards();
        });
      });
      obs.observe(grid, { attributes: true, childList: true, subtree: true });
    }
  });

})();

/* =========================================================
   EDIT PROFILE MODAL - داشبورد کارشناس
   ========================================================= */
(function() {
  'use strict';

  document.addEventListener('DOMContentLoaded', function() {
    var modal = document.getElementById('cptt-edit-profile-modal');
    if (!modal) return;

    var backdrop = document.getElementById('cptt-edit-profile-backdrop');
    var closeBtn = modal.querySelector('.cptt-editProfileModal__close');
    var form = document.getElementById('cptt-edit-profile-form');
    var msgEl = document.getElementById('cptt-ep-msg');

    function openModal() {
      modal.removeAttribute('hidden');
      document.body.style.overflow = 'hidden';
    }
    function closeModal() {
      modal.setAttribute('hidden', '');
      document.body.style.overflow = '';
    }

    if (backdrop) backdrop.addEventListener('click', closeModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeModal(); });

    // دکمه ویرایش پروفایل در sidebar
    document.querySelectorAll('[href*="cptt_edit_profile=1"], .cptt-open-edit-profile').forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        openModal();
      });
    });

    // Avatar upload handled by separate IIFE below

    // ارسال فرم
    if (form) {
      form.addEventListener('submit', function(e) {
        e.preventDefault();
        if (msgEl) { msgEl.textContent = 'در حال ذخیره...'; msgEl.style.color = '#6366f1'; }

        var fd = new FormData(form);
        fd.append('action', 'cptt_expert_save_profile');
        fd.append('nonce', window.CPTT_EXPERT ? CPTT_EXPERT.nonce : '');

        fetch(window.CPTT_EXPERT ? CPTT_EXPERT.ajax : '', { method: 'POST', body: fd })
          .then(function(r){ return r.json(); })
          .then(function(res) {
            if (res.success) {
              if (msgEl) { msgEl.textContent = '✓ ' + (res.data.message || 'ذخیره شد!'); msgEl.style.color = '#059669'; }
              setTimeout(function() { closeModal(); }, 1500);
            } else {
              if (msgEl) { msgEl.textContent = '✗ ' + (res.data || 'خطا در ذخیره'); msgEl.style.color = '#dc2626'; }
            }
          })
          .catch(function() {
            if (msgEl) { msgEl.textContent = '✗ خطای شبکه'; msgEl.style.color = '#dc2626'; }
          });
      });
    }
  });
})();

/* =========================================================
   REDESIGNED Expert Profile Modal (Public Hub)
   + Avatar Upload via File Input with Crop
   ========================================================= */
(function() {
  'use strict';
  function escH(s) { return String(s||'').replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[c];}); }

  // Override the existing expert modal renderer
  var oldModal = document.getElementById('cptt-expert-profile-modal');
  if (oldModal) {
    // Rebind badge clicks to use new renderer
    document.removeEventListener('click', _cpttOldExpertHandler);
  }

  function b64DecodeUtf8(b64) {
    try {
      var bin = atob(b64);
      var bytes = new Uint8Array(bin.length);
      for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
      return window.TextDecoder ? new TextDecoder('utf-8').decode(bytes) : decodeURIComponent(escape(bin));
    } catch(e) { return ''; }
  }

  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.cptt-expertBadge');
    if (!btn) return;
    e.stopPropagation();
    var b64 = btn.getAttribute('data-expert');
    if (!b64) return;
    try {
      var expert = JSON.parse(b64DecodeUtf8(b64));
      openRedesignedExpertModal(expert);
    } catch(ex) { console.error(ex); }
  }, true); // capture phase to override old handler

  function openRedesignedExpertModal(expert) {
    var modal = document.getElementById('cptt-expert-profile-modal');
    if (!modal) return;
    var content = modal.querySelector('#cptt-expert-profile-content');
    if (!content) return;

    var avatarSrc = expert.avatar || '';
    var name = expert.name || 'کارشناس';
    var title = expert.title || '';
    var bio = expert.bio || '';
    var skills = expert.specialties || [];

    var html = '';
    // Hero section with gradient
    html += '<div class="cptt-expertProfile__hero">';
    html += '<div class="cptt-expertProfile__avatarFloat">';
    if (avatarSrc) {
      html += '<img src="'+escH(avatarSrc)+'" alt="'+escH(name)+'">';
    } else {
      html += '<div class="cptt-expertProfile__avatarDefault2">'+escH(name.charAt(0))+'</div>';
    }
    html += '</div>';
    html += '<h2 class="cptt-expertProfile__name">'+escH(name)+'</h2>';
    if (title) html += '<div class="cptt-expertProfile__title">'+escH(title)+'</div>';
    html += '</div>';

    // Body
    html += '<div class="cptt-expertProfile__body">';

    // Stats
    html += '<div class="cptt-expertProfile__statsRow">';
    html += '<div class="cptt-expertProfile__statCard"><strong>'+escH(String(expert.active_projects||0))+'</strong><span>پروژه فعال</span></div>';
    html += '<div class="cptt-expertProfile__statCard"><strong>'+escH(String(expert.completed_projects||0))+'</strong><span>تکمیل شده</span></div>';
    html += '</div>';

    // Bio
    if (bio) {
      html += '<div class="cptt-expertProfile__bioText">'+escH(bio)+'</div>';
    }

    // Skills
    if (skills.length) {
      html += '<div class="cptt-expertProfile__skillsWrap">';
      skills.forEach(function(sk) {
        if (sk) html += '<span class="cptt-expertProfile__skillTag">'+escH(sk)+'</span>';
      });
      html += '</div>';
    }

    html += '</div>';

    content.innerHTML = html;
    modal.removeAttribute('hidden');
    document.body.style.overflow = 'hidden';
  }
})();

/* =========================================================
   AVATAR UPLOAD v3 - Bulletproof crop with inline styles
   ========================================================= */
(function(){
  'use strict';
  document.addEventListener('DOMContentLoaded', function(){
    var btn = document.getElementById('cptt-ep-avatar-btn');
    if (!btn) return;

    var fi = document.createElement('input');
    fi.type = 'file'; fi.accept = 'image/*';
    fi.style.cssText = 'position:fixed;left:-9999px;opacity:0;';
    document.body.appendChild(fi);

    btn.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); fi.value=''; fi.click(); });

    fi.addEventListener('change', function(){
      if (!fi.files || !fi.files[0]) return;
      var rd = new FileReader();
      rd.onload = function(ev){ startCrop(ev.target.result); };
      rd.readAsDataURL(fi.files[0]);
    });

    var _m=null, _img=null, _s=1, _px=0, _py=0, _drag=false, _dx=0, _dy=0, _lp=0, RING=220, OUT=400;

    function startCrop(src){
      endCrop();
      var d = document.createElement('div');
      d.id='cptt-avm';
      // All styles inline - no CSS conflicts possible
      d.style.cssText='position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;padding:12px;';
      d.innerHTML =
        '<div style="position:absolute;inset:0;background:rgba(15,23,42,.75);backdrop-filter:blur(6px);" id="cptt-avbg"></div>'+
        '<div style="position:relative;background:#fff;border-radius:20px;padding:20px;width:320px;max-width:92vw;box-shadow:0 30px 60px rgba(0,0,0,.3);text-align:center;direction:rtl;" id="cptt-avbox">'+
          '<div style="font-size:15px;font-weight:900;color:#0f172a;margin:0 0 4px;">تنظیم عکس پروفایل</div>'+
          '<div style="font-size:11px;color:#94a3b8;margin:0 0 12px;">با انگشت یا موس جابجا کنید · اسکرول یا پینچ برای زوم</div>'+
          '<div id="cptt-avring" style="width:'+RING+'px;height:'+RING+'px;margin:0 auto 12px;border-radius:50%;overflow:hidden;border:3px solid #c7d2fe;position:relative;background:#f1f5f9;touch-action:none;cursor:grab;">'+
            '<img id="cptt-avimg" src="'+src+'" draggable="false" style="position:absolute;display:block;pointer-events:none;user-select:none;max-width:none !important;max-height:none !important;min-width:0 !important;min-height:0 !important;width:auto;height:auto;">'+
          '</div>'+
          '<input type="range" id="cptt-avzoom" min="20" max="500" value="100" style="width:90%;margin:0 auto 14px;display:block;accent-color:#6366f1;">'+
          '<div style="display:flex;gap:8px;">'+
            '<button type="button" id="cptt-avok" style="flex:1;padding:10px 0;border-radius:12px;border:none;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;font-size:14px;font-weight:800;cursor:pointer;min-height:44px;">ثبت عکس</button>'+
            '<button type="button" id="cptt-avno" style="flex:1;padding:10px 0;border-radius:12px;border:1px solid #cbd5e1;background:#fff;color:#334155;font-size:14px;font-weight:800;cursor:pointer;min-height:44px;">انصراف</button>'+
          '</div>'+
        '</div>';
      document.body.appendChild(d);
      _m = d;
      _img = document.getElementById('cptt-avimg');
      var ring = document.getElementById('cptt-avring');
      var zoom = document.getElementById('cptt-avzoom');
      _s=100; _px=0; _py=0;

      _img.onload = function(){
        var fit = RING / Math.min(_img.naturalWidth, _img.naturalHeight);
        _s = Math.max(20, Math.round(fit*100));
        zoom.value = _s;
        _px=0; _py=0;
        paint();
      };

      zoom.oninput = function(){ _s = +this.value; paint(); };

      ring.addEventListener('wheel', function(e){
        e.preventDefault();
        _s += (e.deltaY<0?8:-8);
        _s = Math.max(20,Math.min(500,_s));
        zoom.value = _s;
        paint();
      }, {passive:false});

      ring.addEventListener('mousedown', function(e){ _drag=true; _dx=e.clientX-_px; _dy=e.clientY-_py; e.preventDefault(); });
      var mmv = function(e){ if(!_drag) return; _px=e.clientX-_dx; _py=e.clientY-_dy; paint(); };
      var mup = function(){ _drag=false; };
      document.addEventListener('mousemove', mmv);
      document.addEventListener('mouseup', mup);

      ring.addEventListener('touchstart', function(e){
        if(e.touches.length===2){
          _lp=Math.hypot(e.touches[0].clientX-e.touches[1].clientX,e.touches[0].clientY-e.touches[1].clientY);
          e.preventDefault();
        } else if(e.touches.length===1){
          _drag=true; _dx=e.touches[0].clientX-_px; _dy=e.touches[0].clientY-_py; e.preventDefault();
        }
      }, {passive:false});
      ring.addEventListener('touchmove', function(e){
        if(e.touches.length===2){
          var nd=Math.hypot(e.touches[0].clientX-e.touches[1].clientX,e.touches[0].clientY-e.touches[1].clientY);
          _s+=(nd-_lp)*0.4; _s=Math.max(20,Math.min(500,_s)); zoom.value=Math.round(_s); _lp=nd; paint(); e.preventDefault();
        } else if(_drag&&e.touches.length===1){
          _px=e.touches[0].clientX-_dx; _py=e.touches[0].clientY-_dy; paint(); e.preventDefault();
        }
      }, {passive:false});
      ring.addEventListener('touchend', function(){ _drag=false; _lp=0; });

      document.getElementById('cptt-avno').onclick = endCrop;
      document.getElementById('cptt-avbg').onclick = endCrop;

      document.getElementById('cptt-avok').onclick = function(){
        var b = this; b.disabled=true; b.textContent='آپلود...';
        // Create canvas matching ring view exactly
        var c = document.createElement('canvas');
        c.width=OUT; c.height=OUT;
        var ctx = c.getContext('2d');
        var sc = _s/100;
        var iw = _img.naturalWidth*sc;
        var ih = _img.naturalHeight*sc;
        var half = RING/2;
        // Image position in ring: centered at (half+_px, half+_py) with size (iw, ih)
        // imgLeft = half - iw/2 + _px, imgTop = half - ih/2 + _py
        var ratio = OUT/RING;
        var cx = (half - iw/2 + _px)*ratio;
        var cy = (half - ih/2 + _py)*ratio;
        ctx.drawImage(_img, cx, cy, iw*ratio, ih*ratio);

        c.toBlob(function(blob){
          if(!blob){ b.disabled=false; b.textContent='ثبت عکس'; return; }
          var fd = new FormData();
          fd.append('action','cptt_expert_upload_avatar');
          fd.append('nonce', window.CPTT_EXPERT?CPTT_EXPERT.nonce:'');
          fd.append('avatar_file', blob, 'avatar.jpg');
          fetch(window.CPTT_EXPERT?CPTT_EXPERT.ajax:'',{method:'POST',credentials:'same-origin',body:fd})
          .then(function(r){return r.json();})
          .then(function(j){
            if(j.success&&j.data){
              var p=document.getElementById('cptt-ep-avatar-preview');
              if(p) p.innerHTML='<img src="'+j.data.url+'" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">';
              var h=document.getElementById('cptt-ep-avatar-id');
              if(h) h.value=j.data.id;
              endCrop();
            } else {
              alert(j.data||'خطا'); b.disabled=false; b.textContent='ثبت عکس';
            }
          }).catch(function(){ alert('خطای شبکه'); b.disabled=false; b.textContent='ثبت عکس'; });
        },'image/jpeg',0.92);
      };

      // Clean up on close
      _m._cleanup = function(){ document.removeEventListener('mousemove',mmv); document.removeEventListener('mouseup',mup); };
    }

    function paint(){
      if(!_img) return;
      var sc=_s/100;
      var w=_img.naturalWidth*sc;
      var h=_img.naturalHeight*sc;
      var half=RING/2;
      /* استفاده از setProperty با important تا قواعد img{max-width:100%;height:auto} تم وردپرس override شود
         این باگ باعث میشد عکس‌های غیرمربعی هنگام زوم کشیده شوند. */
      _img.style.setProperty('width', w+'px', 'important');
      _img.style.setProperty('height', h+'px', 'important');
      _img.style.setProperty('max-width', 'none', 'important');
      _img.style.setProperty('max-height', 'none', 'important');
      _img.style.setProperty('min-width', '0', 'important');
      _img.style.setProperty('min-height', '0', 'important');
      _img.style.setProperty('left', (half-w/2+_px)+'px', 'important');
      _img.style.setProperty('top', (half-h/2+_py)+'px', 'important');
      _img.style.setProperty('position', 'absolute', 'important');
    }

    function endCrop(){
      var m=document.getElementById('cptt-avm');
      if(m){ if(m._cleanup) m._cleanup(); m.remove(); }
      _m=null; _img=null; _drag=false; fi.value='';
    }
  });
})();

/* v5.4.10 isolated client search button - create project only */
(function(){
  function bindClientSearch(){
    document.querySelectorAll('.cptt-expert-create-form select[name="client_user_id"], .cptt-expert-project-form select[name="client_user_id"]').forEach(function(sel){
      if (sel.dataset.cpttRobustClientSearch) return;
      sel.dataset.cpttRobustClientSearch = '1';
      var wrap = document.createElement('div');
      wrap.className = 'cptt-clientSearchCreate';
      var searchRow = document.createElement('div');
      searchRow.className = 'cptt-clientSearchCreate__search';
      searchRow.hidden = true;
      var inp = document.createElement('input');
      inp.type = 'search'; inp.className = 'cptt-clientSearchCreate__input'; inp.placeholder = 'جستجوی نام، نام خانوادگی یا شماره تماس...';
      var results = document.createElement('div');
      results.className = 'cptt-clientSearchCreate__results';
      results.hidden = true;
      var fieldRow = document.createElement('div');
      fieldRow.className = 'cptt-clientSearchCreate__field';
      var btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'cptt-clientSearchCreate__btn'; btn.textContent = '🔎'; btn.setAttribute('aria-label','جستجوی مشتری');
      sel.parentNode.insertBefore(wrap, sel);
      searchRow.appendChild(inp); searchRow.appendChild(results); fieldRow.appendChild(sel); fieldRow.appendChild(btn); wrap.appendChild(searchRow); wrap.appendChild(fieldRow);
      btn.addEventListener('click', function(){ searchRow.hidden = !searchRow.hidden; if (!searchRow.hidden) setTimeout(function(){ inp.focus(); }, 30); });
      function renderResults(){
        var q = String(inp.value || '').toLowerCase().trim();
        var matches = [];
        Array.prototype.forEach.call(sel.options, function(opt){
          if (!opt.value || opt.value === 'new_customer_trigger') { opt.hidden = false; return; }
          var h = String((opt.getAttribute('data-search') || '') + ' ' + opt.textContent).toLowerCase();
          var ok = !!q && h.indexOf(q) !== -1;
          opt.hidden = !!q && !ok;
          if (ok && matches.length < 12) matches.push(opt);
        });
        if (!q) { results.hidden = true; results.innerHTML = ''; return; }
        if (!matches.length) { results.hidden = false; results.innerHTML = '<div class="cptt-clientSearchCreate__empty">نتیجه‌ای یافت نشد</div>'; return; }
        results.hidden = false;
        results.innerHTML = matches.map(function(opt){ return '<button type="button" data-value="' + String(opt.value).replace(/"/g,'&quot;') + '">' + opt.textContent + '</button>'; }).join('');
      }
      inp.addEventListener('input', renderResults);
      results.addEventListener('click', function(e){
        var b = e.target.closest('button[data-value]'); if (!b) return;
        sel.value = b.getAttribute('data-value');
        sel.dispatchEvent(new Event('change', {bubbles:true}));
        inp.value = b.textContent;
        results.hidden = true;
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bindClientSearch); else bindClientSearch();
  document.addEventListener('click', function(e){ if(e.target.closest('[data-cptt-open-newproject], .cptt-newProjectCta, .cptt-expert-toggleProject')) setTimeout(bindClientSearch, 100); });
})();
