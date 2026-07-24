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

    function buildFormData(controls) {
        var formData = new FormData();

        controls.querySelectorAll('input[name], select[name], textarea[name]').forEach(function (field) {
            if (field.disabled) {
                return;
            }

            var type = field.type ? field.type.toLowerCase() : '';
            if ((type === 'checkbox' || type === 'radio') && !field.checked) {
                return;
            }

            formData.append(field.name, field.value);
        });

        formData.set('action', FFLHubBoxPacking.action || 'fflhub_test_order_box_packing');
        return formData;
    }

    ready(function () {
        if (!window.FFLHubBoxPacking || !FFLHubBoxPacking.ajaxUrl) {
            return;
        }

        document.querySelectorAll('[data-fflhub-box-pack-form]').forEach(function (controls) {
            var button = controls.querySelector('[data-fflhub-box-pack-run]');
            if (!button) {
                return;
            }

            button.addEventListener('click', function (event) {
                event.preventDefault();

                var panel = controls.closest('.fflhub-box-pack-panel');
                var resultSlot = panel ? panel.querySelector('[data-fflhub-box-pack-result]') : null;
                var formData = buildFormData(controls);

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
