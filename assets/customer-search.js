(function () {
    'use strict';
    function setup() {
        if (!window.RRAdminConfig || !window.fetch) return;
        document.querySelectorAll('.rr-adjust-form input[name="customer_id"], .rr-adjust-form input[name="referrer_id"]').forEach(function (input) {
            if (input.dataset.rrLookup || input.type === 'hidden') return;
            input.dataset.rrLookup = 'yes';
            var parent = input.closest('label');
            if (!parent) return;
            var box = document.createElement('div'); box.className = 'rr-customer-lookup';
            var label = document.createElement('label'); label.textContent = RRAdminConfig.findCustomer || 'Find a customer by name or email';
            var query = document.createElement('input'); query.type = 'search'; query.className = 'rr-control'; query.maxLength = 80;
            label.appendChild(query); box.appendChild(label);
            var button = document.createElement('button'); button.type = 'button'; button.className = 'rr-button rr-button-light'; button.textContent = RRAdminConfig.searchCustomers || 'Search customers'; box.appendChild(button);
            var status = document.createElement('p'); status.setAttribute('role', 'status'); box.appendChild(status);
            var results = document.createElement('div'); results.className = 'rr-customer-results'; box.appendChild(results);
            parent.insertAdjacentElement('afterend', box);
            button.addEventListener('click', function () {
                results.replaceChildren(); button.disabled = true; status.textContent = RRAdminConfig.searching || 'Searching…';
                var body = new URLSearchParams({action: 'rr_find_customer', nonce: RRAdminConfig.nonce, q: query.value});
                fetch(RRAdminConfig.ajaxUrl, {method: 'POST', credentials: 'same-origin', body: body}).then(function (r) { return r.json(); }).then(function (payload) {
                    if (!payload.success) { status.textContent = payload.data.message; return; }
                    status.textContent = payload.data.customers.length ? payload.data.message : (RRAdminConfig.noCustomers || 'No matching customers.');
                    payload.data.customers.forEach(function (customer) {
                        var choice = document.createElement('button'); choice.type = 'button'; choice.className = 'rr-button rr-button-light'; choice.textContent = customer.label;
                        choice.addEventListener('click', function () { input.value = customer.id; input.dispatchEvent(new Event('change', {bubbles: true})); status.textContent = customer.label; results.replaceChildren(); input.focus(); });
                        results.appendChild(choice);
                    });
                }).catch(function () { status.textContent = RRAdminConfig.searchFailed || 'Search failed. Try again or enter the customer ID.'; }).finally(function () { button.disabled = false; });
            });
            query.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); button.click(); } });
        });
    }
    setup(); window.addEventListener('rewards-referrals:section-loaded', setup);
}());
