(function () {
	'use strict';
	function message(button, text) {
		var section = button.closest('.rr-customer-card');
		var status = section && section.querySelector('.rr-customer-copy-status');
		if (status) status.textContent = text;
	}
	async function copy(button, input) {
		var strings = window.RRStorefrontConfig || {};
		try {
			if (!navigator.clipboard || !window.isSecureContext) throw new Error('Clipboard unavailable');
			await navigator.clipboard.writeText(input.value);
			message(button, strings.copied || 'Referral link copied.');
		} catch (error) {
			input.focus(); input.select();
			message(button, strings.copyFailed || 'Select the link and copy it using your device controls.');
		}
	}
	document.addEventListener('click', async function (event) {
		var button = event.target.closest('[data-rr-copy], [data-rr-share]');
		if (!button) return;
		var input = document.getElementById(button.getAttribute('data-rr-copy') || button.getAttribute('data-rr-share'));
		if (!input) return;
		if (button.hasAttribute('data-rr-share') && navigator.share) {
			try { await navigator.share({ title: (window.RRStorefrontConfig || {}).shareTitle || 'Join me and earn rewards', url: input.value }); }
			catch (error) { if (error.name !== 'AbortError') await copy(button, input); }
		} else { await copy(button, input); }
	});
}());
