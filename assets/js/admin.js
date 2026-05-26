(function ($) {
    function getZandersStatus($button) {
        return $button
            .closest('.fflhub-distributor-settings-wrapper')
            .find('.fflhub-zanders-test-status')
            .first();
    }

    function setZandersStatus($status, state, message) {
        $status
            .removeClass('is-pending is-success is-error')
            .addClass('is-' + state)
            .text(message || '');
    }

    function collectZandersFields($button) {
        var fields = {};
        var $form = $button
            .closest('.fflhub-distributor-settings-wrapper')
            .find('.fflhub-distributor-settings-form')
            .first();

        $.each($form.serializeArray(), function (_idx, item) {
            fields[item.name] = item.value;
        });

        return fields;
    }

    function formatZandersCredentialResult(data) {
        var message = data && data.message ? data.message : 'Credential test finished.';
        var details = [];

        if (data && data.httpStatus) {
            details.push('HTTP ' + data.httpStatus);
        }
        if (data && typeof data.returnCode !== 'undefined') {
            details.push('returnCode ' + data.returnCode);
        }
        if (data && data.fakeOrder) {
            details.push('fake order ' + data.fakeOrder);
        }

        return details.length ? message + ' (' + details.join(', ') + ')' : message;
    }

    function testZandersCredentials($button) {
        var $status = getZandersStatus($button);
        var profile = $button.data('profile') || '';

        if (!window.FFLHubAdmin || !window.FFLHubAdmin.ajaxUrl || !window.FFLHubAdmin.zandersSoapNonce) {
            setZandersStatus($status, 'error', 'Credential test is not configured on this page.');
            return;
        }

        setZandersStatus($status, 'pending', 'Testing Zanders SOAP credentials...');
        $button.prop('disabled', true).addClass('is-busy');

        $.post(window.FFLHubAdmin.ajaxUrl, {
            action: 'fflhub_test_zanders_soap_credentials',
            nonce: window.FFLHubAdmin.zandersSoapNonce,
            profile: profile,
            fields: collectZandersFields($button)
        })
            .done(function (response) {
                var data = response && response.data ? response.data : {};
                if (!response || response.success !== true) {
                    setZandersStatus($status, 'error', data.message || 'Credential test failed.');
                    return;
                }

                setZandersStatus(
                    $status,
                    data.ok ? 'success' : 'error',
                    formatZandersCredentialResult(data)
                );
            })
            .fail(function (xhr) {
                var message = 'Credential test failed.';
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                }
                setZandersStatus($status, 'error', message);
            })
            .always(function () {
                $button.prop('disabled', false).removeClass('is-busy');
            });
    }

    function openModal(panelId) {
        var $modal = $('#fflhub-modal');
        var $panels = $modal.find('.fflhub-modal-panel');

        // Hide all panels first.
        $panels
            .removeClass('is-active')
            .attr('aria-hidden', 'true');

        // Show the requested panel.
        var $targetPanel = $('#' + panelId);
        if ($targetPanel.length) {
            $targetPanel
                .addClass('is-active')
                .attr('aria-hidden', 'false');
        }

        // Open the modal.
        $modal
            .addClass('is-open')
            .attr('aria-hidden', 'false');

        // Lock body scroll (simple way).
        $('body').addClass('fflhub-modal-open');
    }

    function closeModal() {
        var $modal = $('#fflhub-modal');

        $modal
            .removeClass('is-open')
            .attr('aria-hidden', 'true');

        $('body').removeClass('fflhub-modal-open');
    }

    $(document).ready(function () {
        // Open modal when clicking a distributor card.
        $('.fflhub-distributor-card').on('click', function () {
            var panelId = $(this).data('fflhub-target');
            if (panelId) {
                openModal(panelId);
            }
        });

        // Close modal when clicking overlay or close button.
        $(document).on('click', '[data-fflhub-close="true"]', function () {
            closeModal();
        });

        // Close on ESC key.
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        $(document).on('click', '.fflhub-zanders-test-credentials', function (e) {
            e.preventDefault();
            testZandersCredentials($(this));
        });
    });
})(jQuery);
