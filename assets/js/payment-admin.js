/* CPTT Payment Admin — v5.5.0 */
(function($){
	if (typeof CPTT_PAY_ADMIN === 'undefined') return;
	var P = CPTT_PAY_ADMIN;

	function ajax(action, data){
		return $.post(P.ajax, $.extend({action: action, nonce: P.nonce}, data || {}));
	}

	// Tabs
	$(document).on('click', '.cptt-pay-tab', function(){
		var $b = $(this), tab = $b.data('tab');
		$('.cptt-pay-tab').removeClass('is-active');
		$b.addClass('is-active');
		$('.cptt-pay-panel').removeClass('is-active');
		$('.cptt-pay-panel[data-panel="'+tab+'"]').addClass('is-active');
	});

	// Add Gateway — open driver picker modal
	$(document).on('click', '#cptt-pay-add-gateway', function(){
		if (!$('#cptt-pay-driver-modal').length) {
			var html = '<div id="cptt-pay-driver-modal" class="cptt-pay-modal"><div class="cptt-pay-modal__box">' +
				'<button class="cptt-pay-modal__close">×</button>' +
				'<h3 class="cptt-pay-modal__title">انتخاب درگاه</h3>' +
				'<div class="cptt-pay-modal__list">';
			Object.keys(P.drivers).forEach(function(k){
				var d = P.drivers[k];
				html += '<div class="cptt-pay-modal__item" data-driver="'+k+'"><div class="ic">'+driverIcon(k)+'</div><b>'+d.title+'</b><small>کلیک برای افزودن</small></div>';
			});
			html += '</div></div></div>';
			$('body').append(html);
		}
		$('#cptt-pay-driver-modal').addClass('is-open');
	});
	$(document).on('click', '.cptt-pay-modal__close, .cptt-pay-modal', function(e){
		if (e.target === this) $('.cptt-pay-modal').removeClass('is-open');
	});
	$(document).on('click', '.cptt-pay-modal__item', function(){
		var drv = $(this).data('driver');
		$('.cptt-pay-modal').removeClass('is-open');
		ajax('cptt_payment_add_gateway', {driver: drv}).done(function(r){
			if (r && r.success) location.reload();
			else alert((r && r.data) || 'خطا');
		});
	});

	function driverIcon(d){
		var map = {zarinpal:'🟡',zibal:'🔵',idpay:'🟢',nextpay:'🟣',payping:'🟠',paystar:'🔴',jibit:'🟤',sep:'🏦',mellat:'🏛'};
		return map[d] || '💼';
	}

	// Toggle gateway
	$(document).on('change', '.cptt-toggle-gateway', function(){
		var id = $(this).data('id'), active = $(this).is(':checked') ? 1 : 0;
		ajax('cptt_payment_toggle_gateway', {id: id, active: active}).done(function(){
			$('.cptt-pay-card[data-id="'+id+'"]').toggleClass('is-active', !!active).toggleClass('is-off', !active);
		});
	});

	// Save gateway fields
	$(document).on('click', '.cptt-save-gateway', function(){
		var id = $(this).data('id');
		var $card = $('.cptt-pay-card[data-id="'+id+'"]');
		var payload = {};
		$card.find('.cptt-pay-field').each(function(){
			var k = $(this).data('key');
			if (this.type === 'checkbox') payload[k] = this.checked ? 1 : 0;
			else payload[k] = this.value;
		});
		var $btn = $(this); var t = $btn.text(); $btn.prop('disabled', true).text('در حال ذخیره...');
		ajax('cptt_payment_save', {type: 'gateway', id: id, payload: payload}).done(function(r){
			if (r && r.success) { $btn.text('✓ ذخیره شد'); setTimeout(function(){ $btn.text(t).prop('disabled', false); }, 1200); }
			else { $btn.text('خطا').prop('disabled', false); }
		});
	});

	// Remove gateway
	$(document).on('click', '.cptt-remove-gateway', function(){
		if (!confirm('این درگاه حذف شود؟')) return;
		var id = $(this).data('id');
		ajax('cptt_payment_remove_gateway', {id: id}).done(function(){ location.reload(); });
	});

	// Add card
	$(document).on('click', '#cptt-pay-add-card', function(){
		ajax('cptt_payment_add_card').done(function(r){
			if (r && r.success) location.reload();
		});
	});
	$(document).on('change', '.cptt-toggle-card', function(){
		var id = $(this).data('id'), active = $(this).is(':checked') ? 1 : 0;
		ajax('cptt_payment_toggle_card', {id: id, active: active}).done(function(){
			$('.cptt-pay-bankcard[data-id="'+id+'"]').toggleClass('is-active', !!active).toggleClass('is-off', !active);
		});
	});
	$(document).on('click', '.cptt-save-card', function(){
		var id = $(this).data('id');
		var $card = $('.cptt-pay-bankcard[data-id="'+id+'"]');
		var payload = {};
		$card.find('.cptt-card-field').each(function(){
			payload[$(this).data('key')] = this.value;
		});
		var $btn = $(this); var t = $btn.text(); $btn.prop('disabled', true).text('در حال ذخیره...');
		ajax('cptt_payment_save', {type: 'card', id: id, payload: payload}).done(function(r){
			if (r && r.success) {
				$btn.text('✓ ذخیره شد');
				$card.find('.cptt-pay-bankcard__bank').text(payload.bank || '—');
				var n = (payload.number||'').replace(/\D+/g,'');
				if (n.length >= 12) $card.find('.cptt-pay-bankcard__number').text(n.substr(0,4)+'-'+n.substr(4,4)+'-'+n.substr(8,4)+'-'+n.substr(12,4));
				$card.find('.cptt-pay-bankcard__owner').text(payload.owner || '');
				setTimeout(function(){ $btn.text(t).prop('disabled', false); }, 1200);
			}
		});
	});
	$(document).on('click', '.cptt-remove-card', function(){
		if (!confirm('این کارت حذف شود؟')) return;
		var id = $(this).data('id');
		ajax('cptt_payment_remove_card', {id: id}).done(function(){ location.reload(); });
	});

	// Settings save
	$(document).on('click', '#cptt-save-settings', function(){
		var $b = $(this); var t = $b.text(); $b.prop('disabled', true).text('در حال ذخیره...');
		ajax('cptt_payment_save', {type: 'settings', id: 'global', payload: {page_intro: $('#cptt-pay-intro').val()}}).done(function(r){
			if (r && r.success) { $b.text('✓ ذخیره شد'); setTimeout(function(){ $b.text(t).prop('disabled', false); }, 1200); }
		});
	});

	// Receipts approve/reject
	$(document).on('click', '.cptt-approve-receipt, .cptt-reject-receipt', function(){
		var $b = $(this), id = $b.data('id');
		var act = $b.hasClass('cptt-approve-receipt') ? 'cptt_approve_receipt' : 'cptt_reject_receipt';
		if (!confirm($b.hasClass('cptt-approve-receipt') ? 'این رسید تایید و پرداخت روی پروژه اعمال شود؟' : 'این رسید رد شود؟')) return;
		ajax(act, {id: id}).done(function(r){
			if (r && r.success) location.reload();
			else alert((r && r.data) || 'خطا');
		});
	});

})(jQuery);
