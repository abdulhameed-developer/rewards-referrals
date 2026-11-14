/*
 * Rewards & Referrals form adapter for the Freemius checkout interface.
 * Copyright (c) 2026 Abdul Hameed.
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Independently implemented replacement for the upstream form adapter.
 * Original upstream files and their hashes are recorded in release evidence;
 * this notice applies only to this replacement, not to the upstream snippet.
 */
(function ($) {
    'use strict';

    /** Create an attached hidden form without submitting it. */
    $.form = function (url, data, method) {
        var element = document.createElement('form');
        element.style.display = 'none';
        element.setAttribute('method', method == null ? 'POST' : String(method));
        if (url != null) {
            element.setAttribute('action', String(url));
        }

        var fields = [];
        var payload = data == null ? {} : Object(data);
        var names = Object.keys(payload);
        var position;
        for (position = names.length - 1; position >= 0; position--) {
            fields.push({ name: names[position], value: payload[names[position]], parents: [payload] });
        }

        while (fields.length) {
            var field = fields.pop();
            var value = field.value;
            if (value == null) {
                continue;
            }
            if (typeof value === 'object') {
                if (field.parents.indexOf(value) !== -1) {
                    throw new TypeError('Cannot create a form from circular data.');
                }
                var parents = field.parents.concat([value]);
                if (Array.isArray(value)) {
                    for (position = value.length - 1; position >= 0; position--) {
                        fields.push({ name: field.name + '[]', value: value[position], parents: parents });
                    }
                } else {
                    names = Object.keys(value);
                    for (position = names.length - 1; position >= 0; position--) {
                        var key = names[position];
                        fields.push({ name: field.name + '[' + key + ']', value: value[key], parents: parents });
                    }
                }
                continue;
            }
            var input = document.createElement('input');
            input.setAttribute('type', 'hidden');
            input.setAttribute('name', String(field.name));
            input.setAttribute('value', String(value));
            element.appendChild(input);
        }
        return $(element).appendTo('body');
    };
})(jQuery);
