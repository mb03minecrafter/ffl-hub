(function ($) {
    function getCredentialStatus($button, selector) {
        return $button
            .closest('.fflhub-distributor-settings-wrapper')
            .find(selector)
            .first();
    }

    function setCredentialStatus($status, state, message) {
        $status
            .removeClass('is-pending is-success is-error')
            .addClass('is-' + state)
            .text(message || '');
    }

    function collectDistributorFields($button) {
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

    function getZandersStatus($button) {
        return getCredentialStatus($button, '.fflhub-zanders-test-status');
    }

    function getRsrStatus($button) {
        return getCredentialStatus($button, '.fflhub-rsr-test-status');
    }

    function getLipseysStatus($button) {
        return getCredentialStatus($button, '.fflhub-lipseys-test-status');
    }

    function getCssiStatus($button) {
        return getCredentialStatus($button, '.fflhub-cssi-test-status');
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
            setCredentialStatus($status, 'error', 'Credential test is not configured on this page.');
            return;
        }

        setCredentialStatus($status, 'pending', 'Testing Zanders SOAP credentials...');
        $button.prop('disabled', true).addClass('is-busy');

        $.post(window.FFLHubAdmin.ajaxUrl, {
            action: 'fflhub_test_zanders_soap_credentials',
            nonce: window.FFLHubAdmin.zandersSoapNonce,
            profile: profile,
            fields: collectDistributorFields($button)
        })
            .done(function (response) {
                var data = response && response.data ? response.data : {};
                if (!response || response.success !== true) {
                    setCredentialStatus($status, 'error', data.message || 'Credential test failed.');
                    return;
                }

                setCredentialStatus(
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
                setCredentialStatus($status, 'error', message);
            })
            .always(function () {
                $button.prop('disabled', false).removeClass('is-busy');
            });
    }

    function formatRsrCredentialResult(data) {
        var message = data && data.message ? data.message : 'Credential test finished.';
        var details = [];

        if (data && data.httpStatus) {
            details.push('HTTP ' + data.httpStatus);
        }
        if (data && data.fakePo) {
            details.push('fake PO ' + data.fakePo);
        }
        if (data && data.rsrStatusCode) {
            details.push('RSR status ' + data.rsrStatusCode);
        }
        if (data && typeof data.itemsCount !== 'undefined') {
            details.push('items ' + data.itemsCount);
        }
        if (data && typeof data.foundFiles !== 'undefined') {
            details.push('feed files ' + data.foundFiles);
        }
        if (data && typeof data.useSsl !== 'undefined') {
            details.push(data.useSsl ? 'FTPS' : 'FTP');
        }

        return details.length ? message + ' (' + details.join(', ') + ')' : message;
    }

    function testRsrCredentials($button) {
        var $status = getRsrStatus($button);
        var profile = $button.data('profile') || '';
        var pendingMessage = profile === 'ftp'
            ? 'Testing RSR FTP credentials...'
            : 'Testing RSR DirectConnect credentials...';

        if (!window.FFLHubAdmin || !window.FFLHubAdmin.ajaxUrl || !window.FFLHubAdmin.rsrCredentialNonce) {
            setCredentialStatus($status, 'error', 'Credential test is not configured on this page.');
            return;
        }

        setCredentialStatus($status, 'pending', pendingMessage);
        $button.prop('disabled', true).addClass('is-busy');

        $.post(window.FFLHubAdmin.ajaxUrl, {
            action: 'fflhub_test_rsr_credentials',
            nonce: window.FFLHubAdmin.rsrCredentialNonce,
            profile: profile,
            fields: collectDistributorFields($button)
        })
            .done(function (response) {
                var data = response && response.data ? response.data : {};
                if (!response || response.success !== true) {
                    setCredentialStatus($status, 'error', data.message || 'Credential test failed.');
                    return;
                }

                setCredentialStatus(
                    $status,
                    data.ok ? 'success' : 'error',
                    formatRsrCredentialResult(data)
                );
            })
            .fail(function (xhr) {
                var message = 'Credential test failed.';
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                }
                setCredentialStatus($status, 'error', message);
            })
            .always(function () {
                $button.prop('disabled', false).removeClass('is-busy');
            });
    }

    function formatLipseysCredentialResult(data) {
        var message = data && data.message ? data.message : 'Credential test finished.';
        var details = [];

        if (data && data.httpStatus) {
            details.push('HTTP ' + data.httpStatus);
        }
        if (data && data.providerCode) {
            details.push(data.providerCode);
        }
        if (data && data.likelyCause) {
            details.push(data.likelyCause);
        }

        if (details.length) {
            message += ' (' + details.join(', ') + ')';
        }

        if (data && data.rawResponse) {
            message += '\n\nRaw response:\n' + data.rawResponse;
        }

        return message;
    }

    function testLipseysCredentials($button) {
        var $status = getLipseysStatus($button);
        var profile = $button.data('profile') || '';

        if (!window.FFLHubAdmin || !window.FFLHubAdmin.ajaxUrl || !window.FFLHubAdmin.lipseysCredentialNonce) {
            setCredentialStatus($status, 'error', 'Credential test is not configured on this page.');
            return;
        }

        setCredentialStatus($status, 'pending', 'Testing Lipsey\'s login...');
        $button.prop('disabled', true).addClass('is-busy');

        $.post(window.FFLHubAdmin.ajaxUrl, {
            action: 'fflhub_test_lipseys_credentials',
            nonce: window.FFLHubAdmin.lipseysCredentialNonce,
            profile: profile,
            fields: collectDistributorFields($button)
        })
            .done(function (response) {
                var data = response && response.data ? response.data : {};
                if (!response || response.success !== true) {
                    setCredentialStatus($status, 'error', data.message || 'Credential test failed.');
                    return;
                }

                setCredentialStatus(
                    $status,
                    data.ok ? 'success' : 'error',
                    formatLipseysCredentialResult(data)
                );
            })
            .fail(function (xhr) {
                var message = 'Credential test failed.';
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                }
                setCredentialStatus($status, 'error', message);
            })
            .always(function () {
                $button.prop('disabled', false).removeClass('is-busy');
            });
    }

    function formatCssiCredentialResult(data) {
        var message = data && data.message ? data.message : 'Credential test finished.';
        var details = [];

        if (data && data.httpStatus) {
            details.push('HTTP ' + data.httpStatus);
        }
        if (data && data.providerCode) {
            details.push(data.providerCode);
        }
        if (data && typeof data.itemsCount !== 'undefined' && data.itemsCount !== null) {
            details.push('items ' + data.itemsCount);
        }
        if (data && typeof data.pageCount !== 'undefined' && data.pageCount !== null) {
            details.push('pages ' + data.pageCount);
        }

        if (details.length) {
            message += ' (' + details.join(', ') + ')';
        }

        if (data && data.rawResponse) {
            message += '\n\nRaw response:\n' + data.rawResponse;
        }

        return message;
    }

    function testCssiCredentials($button) {
        var $status = getCssiStatus($button);

        if (!window.FFLHubAdmin || !window.FFLHubAdmin.ajaxUrl || !window.FFLHubAdmin.cssiCredentialNonce) {
            setCredentialStatus($status, 'error', 'Credential test is not configured on this page.');
            return;
        }

        setCredentialStatus($status, 'pending', 'Testing CSSI REST API credentials...');
        $button.prop('disabled', true).addClass('is-busy');

        $.post(window.FFLHubAdmin.ajaxUrl, {
            action: 'fflhub_test_cssi_credentials',
            nonce: window.FFLHubAdmin.cssiCredentialNonce,
            profile: $button.data('profile') || '',
            fields: collectDistributorFields($button)
        })
            .done(function (response) {
                var data = response && response.data ? response.data : {};
                if (!response || response.success !== true) {
                    setCredentialStatus($status, 'error', data.message || 'Credential test failed.');
                    return;
                }

                setCredentialStatus(
                    $status,
                    data.ok ? 'success' : 'error',
                    formatCssiCredentialResult(data)
                );
            })
            .fail(function (xhr) {
                var message = 'Credential test failed.';
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                }
                setCredentialStatus($status, 'error', message);
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

        $(document).on('click', '.fflhub-rsr-test-credentials', function (e) {
            e.preventDefault();
            testRsrCredentials($(this));
        });

        $(document).on('click', '.fflhub-lipseys-test-credentials', function (e) {
            e.preventDefault();
            testLipseysCredentials($(this));
        });

        $(document).on('click', '.fflhub-cssi-test-credentials', function (e) {
            e.preventDefault();
            testCssiCredentials($(this));
        });
    });
})(jQuery);
