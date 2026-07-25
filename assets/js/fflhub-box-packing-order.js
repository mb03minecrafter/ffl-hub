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

        document.addEventListener('click', function (event) {
            var tab = event.target && event.target.closest ? event.target.closest('[data-fflhub-box-pack-tab]') : null;
            if (!tab) {
                return;
            }

            event.preventDefault();

            var tabs = tab.closest('[data-fflhub-box-pack-tabs]');
            if (!tabs) {
                return;
            }

            var active = tab.getAttribute('data-fflhub-box-pack-tab') || '';
            tabs.querySelectorAll('[data-fflhub-box-pack-tab]').forEach(function (button) {
                button.classList.toggle('is-active', button === tab);
            });
            tabs.querySelectorAll('[data-fflhub-box-pack-tab-panel]').forEach(function (panel) {
                var isActive = panel.getAttribute('data-fflhub-box-pack-tab-panel') === active;
                panel.classList.toggle('is-active', isActive);
                panel.hidden = !isActive;
            });
        });

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
