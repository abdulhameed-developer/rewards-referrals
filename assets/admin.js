(function () {
	'use strict';

	var main = document.getElementById('rr-dashboard-content');
	var tabs = document.querySelector('.rr-tabs');
	var config = window.RRAdminConfig;
	if (!main || !tabs || !config || !window.fetch) return;
	var hero = document.querySelector('.rr-hero');
	function normalizeAdminNotices() {
		if (!hero) return;
		hero.querySelectorAll('.notice, .updated, .error').forEach(function (notice) { hero.parentNode.insertBefore(notice, hero); });
	}
	normalizeAdminNotices();
	if (hero && window.MutationObserver) new MutationObserver(normalizeAdminNotices).observe(hero, { childList: true, subtree: true });

	var cache = new Map();
	var pending = new Map();
	var activeController = null;
	var activeRouteKey = '';
	var navigationId = 0;
	var cacheLife = 10000;
	var currentRoute = new URL(window.location.href);
	var dirtyForm = false;
	var formBaselines = new WeakMap();
	var selectSequence = 0;
	function formState(form) { return JSON.stringify(Array.from(new FormData(form).entries())); }
	function rememberForms() {
		main.querySelectorAll('.rr-settings-form').forEach(function (form) { formBaselines.set(form, formState(form)); });
	}

	function closeCustomSelects(except) {
		document.querySelectorAll('.rr-select.is-open').forEach(function (wrapper) {
			if (wrapper === except) return;
			wrapper.classList.remove('is-open');
			var trigger = wrapper.querySelector('.rr-select-trigger');
			var menu = wrapper.querySelector('.rr-select-menu');
			if (trigger) trigger.setAttribute('aria-expanded', 'false');
			if (menu) menu.hidden = true;
		});
	}

	function enhanceSelects(root) {
		if (!root) return;
		root.querySelectorAll('select.rr-control:not([multiple]):not([data-rr-select-ready])').forEach(function (select) {
			if (select.disabled || select.size > 1) return;
			select.setAttribute('data-rr-select-ready', 'true');
			select.classList.add('rr-native-select');
			select.tabIndex = -1;
			select.setAttribute('aria-hidden', 'true');
			var id = select.id || ('rr-native-select-' + (++selectSequence));
			select.id = id;
			var wrapper = document.createElement('div');
			wrapper.className = 'rr-select';
			wrapper.setAttribute('data-rr-for', id);
			select.parentNode.insertBefore(wrapper, select);
			wrapper.appendChild(select);
			var trigger = document.createElement('button');
			trigger.type = 'button';
			trigger.className = 'rr-select-trigger';
			trigger.setAttribute('aria-haspopup', 'listbox');
			trigger.setAttribute('aria-expanded', 'false');
			trigger.setAttribute('aria-controls', id + '-menu');
			var label = select.getAttribute('aria-label') || '';
			document.querySelectorAll('label[for="' + id.replace(/"/g, '\\"') + '"]').forEach(function (node) { if (!label) label = node.textContent.trim(); });
			if (!label) {
				var enclosingLabel = select.closest('label');
				var labelText = enclosingLabel && enclosingLabel.querySelector('.screen-reader-text');
				if (labelText) label = labelText.textContent.trim();
				else if (enclosingLabel) label = Array.from(enclosingLabel.childNodes).filter(function (node) { return node.nodeType === 3; }).map(function (node) { return node.textContent; }).join(' ').replace(/\s+/g, ' ').trim();
			}
			if (label) trigger.setAttribute('aria-label', label + ': ' + (select.options[select.selectedIndex] || {}).text);
			trigger.innerHTML = '<span class="rr-select-value"></span><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"></path></svg>';
			var menu = document.createElement('div');
			menu.className = 'rr-select-menu';
			menu.id = id + '-menu';
			menu.setAttribute('role', 'listbox');
			menu.setAttribute('aria-label', label || config.options);
			menu.hidden = true;
			wrapper.appendChild(trigger);
			wrapper.appendChild(menu);

			function sync() {
				var option = select.options[select.selectedIndex];
				var value = wrapper.querySelector('.rr-select-value');
				if (value) value.textContent = option ? option.text : '';
				if (label) trigger.setAttribute('aria-label', label + ': ' + (option ? option.text : ''));
				menu.querySelectorAll('[role="option"]').forEach(function (item) {
					item.setAttribute('aria-selected', item.getAttribute('data-value') === select.value ? 'true' : 'false');
					item.classList.toggle('is-selected', item.getAttribute('data-value') === select.value);
				});
			}
			Array.prototype.forEach.call(select.options, function (option) {
				var item = document.createElement('button');
				item.type = 'button';
				item.className = 'rr-select-option';
				item.setAttribute('role', 'option');
				item.tabIndex = -1;
				item.setAttribute('data-value', option.value);
				item.textContent = option.text;
				item.disabled = option.disabled;
				item.addEventListener('click', function () {
					if (option.disabled) return;
					select.value = option.value;
					select.dispatchEvent(new Event('change', { bubbles: true }));
					sync();
					closeCustomSelects();
					trigger.focus();
				});
				menu.appendChild(item);
			});
			function open() {
				closeCustomSelects(wrapper);
				wrapper.classList.add('is-open');
				menu.hidden = false;
				trigger.setAttribute('aria-expanded', 'true');
				var selected = menu.querySelector('.is-selected');
				if (selected && !selected.disabled) { selected.scrollIntoView({ block: 'nearest' }); selected.focus(); }
				else { var firstOption = menu.querySelector('.rr-select-option:not(:disabled)'); if (firstOption) firstOption.focus(); }
			}
			trigger.addEventListener('click', function () { wrapper.classList.contains('is-open') ? closeCustomSelects() : open(); });
			trigger.addEventListener('keydown', function (event) {
				var options = Array.prototype.filter.call(menu.querySelectorAll('.rr-select-option'), function (item) { return !item.disabled; });
				var current = options.findIndex(function (item) { return item.getAttribute('data-value') === select.value; });
				if (event.key === 'Escape') { closeCustomSelects(); return; }
				if (event.key === 'Enter' || event.key === ' ' || event.key === 'ArrowDown' || event.key === 'ArrowUp') {
					event.preventDefault();
					if (!wrapper.classList.contains('is-open')) { open(); return; }
					if (!options.length) return;
					var next = current < 0 ? 0 : current + (event.key === 'ArrowUp' ? -1 : 1);
					next = Math.max(0, Math.min(options.length - 1, next));
					options[next].focus();
				}
			});
			menu.addEventListener('keydown', function (event) {
				var options = Array.prototype.filter.call(menu.querySelectorAll('.rr-select-option'), function (item) { return !item.disabled; });
				var current = options.indexOf(document.activeElement);
				if (event.key === 'Escape') { event.preventDefault(); closeCustomSelects(); trigger.focus(); }
				if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && current >= 0) { event.preventDefault(); options[Math.max(0, Math.min(options.length - 1, current + (event.key === 'ArrowDown' ? 1 : -1)))].focus(); }
				if (event.key === 'Home' && options.length) { event.preventDefault(); options[0].focus(); }
				if (event.key === 'End' && options.length) { event.preventDefault(); options[options.length - 1].focus(); }
				if (event.key === 'Tab') closeCustomSelects();
			});
			select.addEventListener('change', sync);
			sync();
		});
	}

	document.addEventListener('pointerdown', function (event) { if (!event.target.closest('.rr-select')) closeCustomSelects(); });

	function routeKey(url) {
		return [ 'page', 'tab', 'paged', 's', 'category', 'product_type', 'product_status', 'status', 'customer', 'q', 'from', 'to', 'report_type', 'rr_view', 'event', 'actor', 'before', 'version_from', 'version_to', 'pending_before', 'approved_before', 'referral_search', 'preflight_order', 'preflight_date', 'after_customer', 'rr_mail_status', 'rr_mail_before' ].map(function (key) {
			return key + '=' + (url.searchParams.get(key) || '');
		}).join('&');
	}

	function statusMessage(message, isError) {
		var status = main.querySelector('[data-rr-status]');
		if (!status) {
			status = document.createElement('p');
			status.className = 'rr-sr-only';
			status.setAttribute('data-rr-status', '');
			status.setAttribute('role', 'status');
			status.setAttribute('aria-live', 'polite');
			main.prepend(status);
		}
		status.textContent = message;
		status.setAttribute('data-error', isError ? 'true' : 'false');
		status.className = isError ? 'rr-route-error' : 'rr-sr-only';
		status.setAttribute('role', isError ? 'alert' : 'status');
	}

	function activeTab(url) {
		var name = url.searchParams.get('tab') || config.defaultTab || 'overview';
		tabs.querySelectorAll('.rr-tab').forEach(function (link) {
			var target = new URL(link.href, window.location.href);
			var selected = (target.searchParams.get('tab') || 'overview') === name;
			link.classList.toggle('is-active', selected);
			if (selected) link.setAttribute('aria-current', 'page');
			else link.removeAttribute('aria-current');
			if (selected && tabs.scrollWidth > tabs.clientWidth) {
				var bounds = tabs.getBoundingClientRect();
				var item = link.getBoundingClientRect();
				if (item.left < bounds.left) tabs.scrollBy({ left: item.left - bounds.left - 8, behavior: 'auto' });
				else if (item.right > bounds.right) tabs.scrollBy({ left: item.right - bounds.right + 8, behavior: 'auto' });
			}
			var sectionLabel = document.querySelector('[data-rr-section-label]');
			if (selected && sectionLabel) sectionLabel.textContent = link.textContent.trim();
		});
	}

	function setBusy(busy) {
		main.setAttribute('aria-busy', busy ? 'true' : 'false');
		tabs.setAttribute('aria-busy', busy ? 'true' : 'false');
		main.classList.toggle('is-loading', busy);
	}

	function initDurationFields(root) {
		var selector = root.querySelector('#commission_duration');
		if (!selector) return;
		var fields = root.querySelectorAll('.rr-dependent-field');
		function update() {
			fields.forEach(function (field) {
				var visible = field.getAttribute('data-show-for') === selector.value;
				field.hidden = !visible;
				field.querySelectorAll('input').forEach(function (input) { input.disabled = !visible; });
			});
		}
		selector.addEventListener('change', update);
		update();
	}

	function syncSaveBar() {
		var wrap = document.querySelector('.rr-wrap');
		var bounds = wrap ? wrap.getBoundingClientRect() : null;
		main.querySelectorAll('[data-rr-save-bar]').forEach(function (bar) {
			var form = bar.closest('form');
			var changed = form && formBaselines.has(form) && formBaselines.get(form) !== formState(form);
			if (bounds) {
				bar.style.setProperty('--rr-save-left', Math.max(10, bounds.left) + 'px');
				bar.style.setProperty('--rr-save-right', Math.max(10, window.innerWidth - bounds.right) + 'px');
			}
			bar.classList.toggle('is-visible', Boolean(changed));
			bar.hidden = !changed;
			bar.setAttribute('data-unsaved-label', config.unsaved);
			bar.setAttribute('aria-hidden', changed ? 'false' : 'true');
		});
	}

	function markDirty() {
		dirtyForm = Array.from(main.querySelectorAll('.rr-settings-form')).some(function (form) {
			return formBaselines.has(form) && formBaselines.get(form) !== formState(form);
		});
		syncSaveBar();
	}

	function render(html, url) {
		main.innerHTML = html;
		activeTab(url);
		enhanceSelects(main);
		initDurationFields(main);
		rememberForms();
		syncSaveBar();
		var heading = main.querySelector('.rr-page-heading h2, .rr-welcome h2');
		if (heading) {
			heading.setAttribute('tabindex', '-1');
			heading.focus({ preventScroll: true });
		}
		statusMessage(config.sectionLoaded + ' ' + ((tabs.querySelector('.rr-tab.is-active span') || {}).textContent || ''), false);
		window.dispatchEvent(new CustomEvent('rewards-referrals:section-loaded', { detail: { tab: url.searchParams.get('tab') || 'overview' } }));
	}

	function fetchRoute(url, key, requestId) {
		if (pending.has(key) && !pending.get(key).controller.signal.aborted) return pending.get(key).promise;
		pending.delete(key);
		var body = new URLSearchParams();
		body.set('action', 'rr_admin_route');
		body.set('nonce', config.nonce);
		[ 'tab', 'paged', 's', 'category', 'product_type', 'product_status', 'status', 'customer', 'q', 'from', 'to', 'report_type', 'rr_view', 'event', 'actor', 'before', 'version_from', 'version_to', 'pending_before', 'approved_before', 'referral_search', 'preflight_order', 'preflight_date', 'after_customer', 'rr_mail_status', 'rr_mail_before' ].forEach(function (name) {
			if (url.searchParams.has(name)) body.set(name, url.searchParams.get(name));
		});
		var controller = new AbortController();
		activeController = controller;
		activeRouteKey = key;
		var request = window.fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'Accept': 'application/json' },
			body: body.toString(),
			signal: controller.signal
		}).then(function (response) {
			return response.json().then(function (payload) {
				if (!response.ok || !payload || !payload.success || !payload.data || typeof payload.data.html !== 'string') {
					throw new Error(payload && payload.data && payload.data.message ? payload.data.message : config.sectionFailed);
				}
				return payload.data.html;
			});
		}).then(function (html) {
			cache.set(key, { html: html, time: Date.now() });
			return html;
		});
		var entry = { promise: request, controller: controller };
		request = request.finally(function () {
			if (pending.get(key) === entry) pending.delete(key);
			if (requestId === navigationId) {
				activeController = null;
				activeRouteKey = '';
				setBusy(false);
			}
		});
		entry.promise = request;
		pending.set(key, entry);
		return request;
	}

	async function navigate(destination, addHistory, force) {
		var url;
		try { url = new URL(destination, window.location.href); } catch (error) { return false; }
		/* Inner section changes are instant. Crossing a WordPress sidebar area uses
		 * its native page request so the active admin submenu remains truthful. */
		if (url.origin !== window.location.origin || url.pathname !== window.location.pathname || url.searchParams.get('page') !== config.page) return false;
		var key = routeKey(url);
		if (activeController && activeRouteKey === key && !activeController.signal.aborted) return false;
		if (dirtyForm && !window.confirm(config.discardChanges)) {
			activeTab(currentRoute);
			if (!addHistory) window.history.pushState({ rrDashboard: true }, '', currentRoute.href);
			return false;
		}
		dirtyForm = false;
		var id = ++navigationId;
		var cached = cache.get(key);
		if (!force && cached && Date.now() - cached.time < cacheLife) {
			if (activeController) activeController.abort();
			activeTab(url);
			render(cached.html, url);
			if (addHistory) window.history.pushState({ rrDashboard: true }, '', url.href);
			currentRoute = url;
			setBusy(false);
			return true;
		}
		activeTab(url);
		setBusy(true);
		if (activeController) activeController.abort();
		statusMessage(config.loadingSection, false);
		try {
			var html = await fetchRoute(url, key, id);
			if (id !== navigationId) return false;
			render(html, url);
			if (addHistory) window.history.pushState({ rrDashboard: true }, '', url.href);
			currentRoute = url;
			return true;
		} catch (error) {
			if (id !== navigationId || error.name === 'AbortError') return false;
			activeTab(currentRoute);
			statusMessage(error.message || config.sectionFailed, true);
			return false;
		} finally {
			if (id === navigationId) setBusy(false);
		}
	}

	document.addEventListener('click', function (event) {
		var link = event.target.closest && event.target.closest('a[data-rr-nav]');
		if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target === '_blank') return;
		var url = new URL(link.href, window.location.href);
		if (url.origin !== window.location.origin || url.pathname !== window.location.pathname) return;
		event.preventDefault();
		navigate(url.href, true, false);
	});

	document.addEventListener('input', function (event) {
		if (event.target.closest && event.target.closest('.rr-settings-form')) markDirty();
	});
	document.addEventListener('change', function (event) {
		if (event.target.closest && event.target.closest('.rr-settings-form')) markDirty();
	});
	document.addEventListener('submit', function (event) {
		if (event.target.matches && event.target.matches('.rr-preview-form')) {
			event.preventDefault();
			var output = event.target.querySelector('output');
			var previewButton = event.target.querySelector('button');
			var previewBody = new URLSearchParams(new FormData(event.target));
			previewBody.set('action', 'rr_rule_preview');
			previewBody.set('nonce', config.nonce);
			output.textContent = config.calculating;
			previewButton.disabled = true;
			fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: previewBody }).then(function (response) { return response.json(); }).then(function (payload) { output.textContent = payload.data && payload.data.message ? payload.data.message : config.previewFailed; }).catch(function () { output.textContent = config.previewFailed; }).finally(function () { previewButton.disabled = false; });
			return;
		}
		if (event.target.matches && event.target.matches('.rr-settings-form')) { formBaselines.set(event.target, formState(event.target)); dirtyForm = false; syncSaveBar(); }
		if (!event.target.matches || (!event.target.matches('.rr-catalog-filter') && !event.target.matches('.rr-ledger-filter') && !event.target.matches('.rr-wallet-filter') && !event.target.matches('.rr-report-filter') && !event.target.matches('.rr-evidence-filter'))) return;
		event.preventDefault();
		var formData = new FormData(event.target);
		var url = new URL(window.location.href);
		[ 'page', 'tab', 's', 'category', 'product_type', 'product_status', 'status', 'customer', 'q', 'from', 'to', 'report_type', 'rr_view', 'event', 'actor', 'before', 'version_from', 'version_to', 'pending_before', 'approved_before', 'referral_search', 'preflight_order', 'preflight_date', 'after_customer', 'rr_mail_status', 'rr_mail_before' ].forEach(function (name) {
			var value = formData.get(name);
			if (value) url.searchParams.set(name, value);
			else url.searchParams.delete(name);
		});
		url.searchParams.delete('paged');
		navigate(url.href, true, true);
	});
	document.addEventListener('change', function (event) {
		if (!event.target.matches || event.target.id !== 'rr-select-all') return;
		main.querySelectorAll('.rr-catalog-bulk input[name="product_ids[]"]').forEach(function (input) { input.checked = event.target.checked; });
	});
	window.addEventListener('beforeunload', function (event) {
		if (!dirtyForm) return;
		event.preventDefault();
		event.returnValue = '';
	});
	window.addEventListener('popstate', function () { navigate(window.location.href, false, false); });
	window.addEventListener('scroll', function () {
		var offset = parseFloat(getComputedStyle(tabs).top) || 0;
		tabs.classList.toggle('is-stuck', tabs.getBoundingClientRect().top <= offset + 1);
	}, { passive: true });
	window.addEventListener('resize', syncSaveBar);
	initDurationFields(main);
	enhanceSelects(main);
	rememberForms();
	activeTab(currentRoute);
	syncSaveBar();
}());
