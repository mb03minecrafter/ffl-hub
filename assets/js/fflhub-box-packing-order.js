(function () {
    'use strict';

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
            return;
        }

        callback();
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setButtonBusy(button, isBusy) {
        if (!button) {
            return;
        }

        if (isBusy) {
            button.dataset.fflhubOriginalText = button.tagName === 'INPUT' ? button.value : button.textContent;
            button.disabled = true;
            if (button.tagName === 'INPUT') {
                button.value = FFLHubBoxPacking.runningText || 'Packing...';
            } else {
                button.textContent = FFLHubBoxPacking.runningText || 'Packing...';
            }
            return;
        }

        button.disabled = false;
        var original = button.dataset.fflhubOriginalText || FFLHubBoxPacking.buttonText || 'Run Box Packing Test';
        if (button.tagName === 'INPUT') {
            button.value = original;
        } else {
            button.textContent = original;
        }
    }

    function errorHtml(message) {
        return '<section class="fflhub-box-pack-result is-warning">' +
            '<div class="fflhub-box-pack-result-head"><h4>' + escapeHtml(FFLHubBoxPacking.errorText || 'Box packing test failed.') + '</h4></div>' +
            '<div class="fflhub-box-pack-errors"><p>' + escapeHtml(message || FFLHubBoxPacking.errorText || 'Box packing test failed.') + '</p></div>' +
            '</section>';
    }

    ready(function () {
        if (!window.FFLHubBoxPacking || !FFLHubBoxPacking.ajaxUrl) {
            return;
        }

        document.querySelectorAll('[data-fflhub-box-pack-form]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();

                var panel = form.closest('.fflhub-box-pack-panel');
                var resultSlot = panel ? panel.querySelector('[data-fflhub-box-pack-result]') : null;
                var button = form.querySelector('input[type="submit"], button[type="submit"]');
                var formData = new FormData(form);

                formData.set('action', FFLHubBoxPacking.action || 'fflhub_test_order_box_packing');

                setButtonBusy(button, true);
                if (resultSlot) {
                    resultSlot.innerHTML = '<div class="fflhub-box-pack-empty">' + escapeHtml(FFLHubBoxPacking.runningText || 'Packing...') + '</div>';
                }

                fetch(FFLHubBoxPacking.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                    .then(function (response) {
                        return response.text().then(function (text) {
                            var payload = null;
                            try {
                                payload = text ? JSON.parse(text) : null;
                            } catch (parseError) {
                                throw new Error(text ? text.slice(0, 300) : 'Server returned an empty response.');
                            }

                            if (!response.ok || !payload || !payload.success) {
                                var message = payload && payload.data && payload.data.message ? payload.data.message : FFLHubBoxPacking.errorText;
                                throw new Error(message || 'Box packing test failed.');
                            }

                            return payload;
                        });
                    })
                    .then(function (payload) {
                        if (resultSlot) {
                            resultSlot.innerHTML = payload.data && payload.data.html ? payload.data.html : '';
                            resultSlot.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        }
                    })
                    .catch(function (error) {
                        if (resultSlot) {
                            resultSlot.innerHTML = errorHtml(error && error.message ? error.message : '');
                            resultSlot.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        }
                    })
                    .finally(function () {
                        setButtonBusy(button, false);
                    });
            });
        });
    });
}());
