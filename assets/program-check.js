(function () {
    'use strict';
    var config = window.RRAdminConfig;
    if (!config || !window.fetch) return;
    function post(body) {
        body.set('nonce', config.nonce);
        return fetch(config.ajaxUrl, {method: 'POST', credentials: 'same-origin', body: body}).then(function (response) {
            return response.json().then(function (payload) {
                if (!payload || typeof payload !== 'object') throw new Error(config.previewFailed);
                if (!response.ok || !payload.success) throw new Error(payload.data && payload.data.message ? payload.data.message : config.previewFailed);
                return payload.data;
            }, function () { throw new Error(config.previewFailed); });
        }, function () { throw new Error(config.previewFailed); });
    }
    function setup() {
        document.querySelectorAll('.rr-purchase-example').forEach(function (form) {
            if (form.dataset.rrBound) return;
            form.dataset.rrBound = '1';
            var query = form.elements.product_query;
            var search = form.querySelector('[data-rr-product-search]');
            var status = form.querySelector('[data-rr-product-status]');
            var results = form.querySelector('[data-rr-product-results]');
            var selected = form.querySelector('[data-rr-product-selected]');
            var output = form.querySelector('output');
            var submit = form.querySelector('button[type="submit"]');
            var revision = 0;
            var searchRevision = 0;
            function changed() {
                revision++;
                if (output.textContent) output.textContent = config.previewStale;
            }
            form.addEventListener('input', changed);
            form.addEventListener('change', changed);
            query.addEventListener('input', function () {
                searchRevision++;
                form.elements.product_id.value = '';
                selected.textContent = '';
                results.replaceChildren();
            });
            search.addEventListener('click', function () {
                var request = ++searchRevision;
                results.replaceChildren();
                status.textContent = config.searching;
                search.disabled = true;
                post(new URLSearchParams({action: 'rr_preview_product_search', q: query.value})).then(function (data) {
                    if (!form.isConnected || request !== searchRevision) return;
                    status.textContent = data.products.length ? '' : config.noProducts;
                    data.products.forEach(function (product) {
                        var choice = document.createElement('button');
                        choice.type = 'button';
                        choice.className = 'rr-button rr-button-light';
                        choice.textContent = product.label;
                        choice.addEventListener('click', function () {
                            form.elements.product_id.value = product.id;
                            selected.textContent = product.label;
                            results.replaceChildren();
                            changed();
                            form.elements.quantity.focus();
                        });
                        results.appendChild(choice);
                    });
                }).catch(function (error) {
                    if (form.isConnected && request === searchRevision) status.textContent = error.message;
                }).finally(function () { search.disabled = false; });
            });
            query.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') { event.preventDefault(); search.click(); }
            });
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!form.elements.product_id.value) { status.textContent = config.chooseProduct; query.focus(); return; }
                var request = revision;
                var body = new URLSearchParams(new FormData(form));
                body.set('action', 'rr_preview_purchase');
                output.textContent = config.calculating;
                submit.disabled = true;
                post(body).then(function (data) {
                    if (!form.isConnected) return;
                    output.textContent = request === revision ? data.message + ' ' + config.previewContext : config.previewStale;
                }).catch(function (error) {
                    if (form.isConnected) output.textContent = request === revision ? error.message : config.previewStale;
                }).finally(function () { submit.disabled = false; });
            });
        });
    }
    setup();
    window.addEventListener('rewards-referrals:section-loaded', setup);
}());
