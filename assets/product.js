(function ($) {
	'use strict';
	function formatPoints(value) { return new Intl.NumberFormat(document.documentElement.lang || undefined).format(Math.max(0, parseInt(value, 10) || 0)); }
	function updateReward(form, variation) {
		var reward = form.querySelector('[data-rr-product-reward]');
		if (!reward) return;
		var mode = variation && variation.rr_reward_mode ? variation.rr_reward_mode : reward.getAttribute('data-default-mode');
		var points = variation ? (parseInt(variation.rr_points_price, 10) || 0) : (parseInt(reward.getAttribute('data-points'), 10) || 0);
		var variableUnselected = reward.getAttribute('data-variable') === '1' && !variation;
		var label = reward.querySelector('[data-rr-points-label]');
		var choice = reward.querySelector('input[name="rr_redeem_with_points"]');
		var strings = window.RRProductConfig || {};
		if (!points || mode === 'disabled' || variableUnselected) {
			reward.hidden = true;
			if (choice) { choice.checked = false; choice.disabled = true; }
			return;
		}
		reward.hidden = false;
		if (choice) { choice.disabled = false; choice.hidden = mode !== 'cash_or_points'; choice.checked = mode !== 'cash_or_points'; }
		if (mode === 'points_plus_cash') {
			if (label) label.textContent = (strings.pointsPlusCash || 'Pay %cash plus %points points per item.').replace('%cash', variation ? variation.rr_cash_label : reward.getAttribute('data-cash')).replace('%points', formatPoints(points));
			return;
		}
		if (label) label.textContent = (mode === 'points_only' ? (strings.pointsOnly || 'This reward costs %s points.') : (strings.cashOrPoints || 'Use %s points for this item instead of paying cash')).replace('%s', formatPoints(points));
	}
	$(document.body).on('found_variation', '.variations_form', function (event, variation) { updateReward(this, variation || {}); });
	$(document.body).on('reset_data hide_variation', '.variations_form', function () { updateReward(this, null); });
	$(function () { document.querySelectorAll('form.cart').forEach(function (form) { updateReward(form, null); }); });
}(jQuery));
