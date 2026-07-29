(function ($) {
    'use strict';

    var config = window.FFLHubReceivingLocalStock || {};
    var ajaxUrl = config.ajaxUrl || window.ajaxurl || '';
    var nonce = config.nonce || '';

    $(function () {
        var $app = $('[data-local-receiving-app]');
        if (!$app.length) {
            return;
        }

        var items = {};
        var pendingProduct = null;
        var busy = false;

        var $upc = $app.find('[data-upc-input]');
        var $serial = $app.find('[data-serial-input]');
        var $serialWrap = $app.find('[data-serial-wrap]');
        var $feedback = $app.find('[data-feedback]');
        var $table = $app.find('[data-scanned-table]');
        var $matches = $app.find('[data-matches]');
        var $result = $app.find('[data-result]');
        var $source = $app.find('[data-source-contact]');
        var $firearmCard = $app.find('[data-firearm-fields-card]');
        var $firearmFields = $app.find('[data-firearm-fields]');

        focusUpc();

        $app.find('[data-add-scan]').on('click', function () {
            handleScanSubmit();
        });

        $upc.on('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                handleScanSubmit();
            }
        });

        $serial.on('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                handleScanSubmit();
            }
        });

        $app.find('[data-commit-stock]').on('click', function () {
            finalize('commit');
        });

        $app.find('[data-load-matches]').on('click', function () {
            loadMatches();
        });

        $matches.on('click', '[data-assign-selected]', function () {
            finalize('assign');
        });

        $table.on('click', '[data-remove-upc]', function () {
            delete items[String($(this).data('remove-upc') || '')];
            pendingProduct = null;
            setSerialMode(false);
            renderAll();
            focusUpc();
        });

        $table.on('click', '[data-dec-upc]', function () {
            var upc = String($(this).data('dec-upc') || '');
            if (!items[upc] || items[upc].serial_required) {
                return;
            }
            items[upc].qty = Math.max(0, Number(items[upc].qty || 0) - 1);
            if (items[upc].qty < 1) {
                delete items[upc];
            }
            renderAll();
        });

        $table.on('click', '[data-remove-serial]', function () {
            var upc = String($(this).data('serial-upc') || '');
            var serial = normalizeSerial(String($(this).data('remove-serial') || ''));
            if (!items[upc]) {
                return;
            }
            items[upc].serials = (items[upc].serials || []).filter(function (value) {
                return normalizeSerial(value) !== serial;
            });
            items[upc].qty = items[upc].serials.length;
            if (items[upc].qty < 1) {
                delete items[upc];
            }
            renderAll();
        });

        function handleScanSubmit() {
            if (busy) {
                return;
            }

            if (pendingProduct && Number(pendingProduct.serial_required || 0) === 1) {
                addPendingSerial();
                return;
            }

            var scan = normalizeUpc($upc.val());
            if (!scan) {
                showFeedback('Scan or enter a UPC.', 'error');
                focusUpc();
                return;
            }

            lookupProduct(scan);
        }

        function lookupProduct(upc) {
            busy = true;
            showFeedback('Looking up UPC ' + upc + '...', 'working');

            post('fflhub_receiving_local_lookup_product', { upc: upc })
                .done(function (payload) {
                    var data = responseData(payload);
                    if (!data.ok) {
                        showFeedback(data.message || 'UPC was not found.', 'error');
                        focusUpc();
                        return;
                    }

                    var product = data.product || {};
                    if (Number(product.serial_required || 0) === 1) {
                        pendingProduct = product;
                        setSerialMode(true);
                        showFeedback('UPC matched. Scan the serial number for ' + (product.name || upc) + '.', 'working');
                        $serial.focus();
                        return;
                    }

                    addProduct(product, '');
                    showFeedback('Added ' + (product.name || upc) + '.', 'ok');
                    clearScanFields();
                    renderAll();
                    focusUpc();
                })
                .fail(function (xhr) {
                    showFeedback(xhrMessage(xhr) || 'Lookup failed.', 'error');
                })
                .always(function () {
                    busy = false;
                });
        }

        function addPendingSerial() {
            var serial = normalizeSerial($serial.val());
            if (!serial) {
                showFeedback('Scan or enter the serial number before adding this firearm.', 'error');
                $serial.focus();
                return;
            }

            addProduct(pendingProduct, serial);
            showFeedback('Added serial ' + serial + ' for ' + (pendingProduct.name || pendingProduct.upc) + '.', 'ok');
            pendingProduct = null;
            setSerialMode(false);
            clearScanFields();
            renderAll();
            focusUpc();
        }

        function addProduct(product, serial) {
            var upc = normalizeUpc(product.upc || '');
            if (!upc) {
                return;
            }

            if (!items[upc]) {
                items[upc] = {
                    upc: upc,
                    product_id: Number(product.product_id || 0),
                    name: String(product.name || ('UPC ' + upc)),
                    edit_url: String(product.edit_url || ''),
                    ffl_required: Number(product.ffl_required || 0),
                    serial_required: Number(product.serial_required || 0),
                    local_stock_qty: Number(product.local_stock_qty || 0),
                    qty: 0,
                    serials: []
                };
            }

            if (Number(items[upc].serial_required || 0) === 1) {
                serial = normalizeSerial(serial);
                if (serial && items[upc].serials.indexOf(serial) === -1) {
                    items[upc].serials.push(serial);
                }
                items[upc].qty = items[upc].serials.length;
            } else {
                items[upc].qty = Number(items[upc].qty || 0) + 1;
            }
        }

        function loadMatches() {
            var scanned = itemList();
            if (!scanned.length) {
                showFeedback('Scan at least one item first.', 'error');
                focusUpc();
                return;
            }

            showResult('');
            showFeedback('Looking for matching successful local-stock order rows...', 'working');
            post('fflhub_receiving_local_matching_orders', {
                upcs: JSON.stringify(scanned.map(function (item) { return item.upc; }))
            }).done(function (payload) {
                var data = responseData(payload);
                if (!data.ok) {
                    showFeedback(data.message || 'Could not load matches.', 'error');
                    return;
                }
                renderMatches(data.matches || []);
                showFeedback('Matching order rows loaded.', 'ok');
            }).fail(function (xhr) {
                showFeedback(xhrMessage(xhr) || 'Could not load matching orders.', 'error');
            });
        }

        function finalize(mode) {
            var scanned = itemList();
            if (!scanned.length) {
                showFeedback('Scan at least one item first.', 'error');
                focusUpc();
                return;
            }

            var source = selectedSource();
            if (hasSerializedItems() && (!source.contact_id || !source.distributor_id)) {
                showFeedback('Select the distributor/FastBound source contact first.', 'error');
                $source.focus();
                return;
            }

            var action = mode === 'assign'
                ? 'fflhub_receiving_local_assign_order'
                : 'fflhub_receiving_local_commit_stock';
            var data = {
                items: JSON.stringify(scanned),
                source_distributor_id: source.distributor_id,
                source_contact_id: source.contact_id,
                firearm_fields: JSON.stringify(firearmFieldsPayload())
            };

            if (mode === 'assign') {
                data.assignments = JSON.stringify(selectedAssignments());
                if (JSON.parse(data.assignments).length < 1) {
                    showFeedback('Select at least one matching order row.', 'error');
                    return;
                }
            }

            busy = true;
            showFeedback(mode === 'assign' ? 'Assigning scans to orders...' : 'Committing local stock...', 'working');

            post(action, data).done(function (payload) {
                var response = responseData(payload);
                if (!response.ok) {
                    showResult(response.message || 'Local-stock receiving failed.', 'error', response);
                    showFeedback(response.message || 'Local-stock receiving failed.', 'error');
                    return;
                }

                showResult(response.message || 'Done.', 'ok', response);
                showFeedback(response.message || 'Done.', 'ok');
                items = {};
                pendingProduct = null;
                setSerialMode(false);
                renderAll();
                renderMatches([]);
                clearScanFields();
                focusUpc();
            }).fail(function (xhr) {
                showFeedback(xhrMessage(xhr) || 'Request failed.', 'error');
            }).always(function () {
                busy = false;
            });
        }

        function selectedAssignments() {
            var out = [];
            $matches.find('[data-match-row]').each(function () {
                var $row = $(this);
                var checked = $row.find('[data-match-check]').prop('checked');
                if (!checked) {
                    return;
                }

                out.push({
                    upc: String($row.data('upc') || ''),
                    job_id: Number($row.data('job-id') || 0),
                    order_id: Number($row.data('order-id') || 0),
                    order_item_id: Number($row.data('order-item-id') || 0),
                    qty: Number($row.find('[data-assign-qty]').val() || 0)
                });
            });

            return out;
        }

        function selectedSource() {
            var $option = $source.find('option:selected');
            return {
                contact_id: String($option.val() || ''),
                distributor_id: String($option.data('distributor-id') || '')
            };
        }

        function firearmFieldsPayload() {
            var out = {};
            $firearmFields.find('[data-firearm-row]').each(function () {
                var $row = $(this);
                var upc = String($row.data('firearm-upc') || '');
                if (!upc) {
                    return;
                }

                out[upc] = {
                    upc: upc,
                    manufacturer: String($row.find('[data-firearm-field="manufacturer"]').val() || ''),
                    model: String($row.find('[data-firearm-field="model"]').val() || ''),
                    caliber: String($row.find('[data-firearm-field="caliber"]').val() || ''),
                    firearm_type: String($row.find('[data-firearm-field="firearm_type"]').val() || '')
                };
            });
            return out;
        }

        function itemList() {
            return Object.keys(items).sort().map(function (upc) {
                return items[upc];
            });
        }

        function hasSerializedItems() {
            return itemList().some(function (item) {
                return Number(item.serial_required || 0) === 1;
            });
        }

        function renderAll() {
            renderTable();
            renderFirearmFields();
            $matches.empty();
        }

        function renderTable() {
            var scanned = itemList();
            if (!scanned.length) {
                $table.html('<div class="fflhub-local-empty">No items scanned yet.</div>');
                return;
            }

            var html = '<table class="widefat striped fflhub-local-table"><thead><tr>' +
                '<th>UPC</th><th>Product</th><th>Received</th><th>Current Local</th><th>Serials</th><th></th>' +
                '</tr></thead><tbody>';

            scanned.forEach(function (item) {
                var serialHtml = Number(item.serial_required || 0) === 1
                    ? serialChips(item)
                    : '<span class="fflhub-local-muted">Not serialized</span>';
                var name = item.edit_url
                    ? '<a href="' + escapeAttr(item.edit_url) + '" target="_blank" rel="noopener">' + escapeHtml(item.name) + '</a>'
                    : escapeHtml(item.name);
                var dec = Number(item.serial_required || 0) === 1
                    ? ''
                    : '<button type="button" class="button button-small" data-dec-upc="' + escapeAttr(item.upc) + '">-1</button> ';

                html += '<tr>' +
                    '<td><code>' + escapeHtml(item.upc) + '</code></td>' +
                    '<td>' + name + (Number(item.ffl_required || 0) === 1 ? ' <span class="fflhub-local-badge">FFL</span>' : '') + '</td>' +
                    '<td><strong>' + Number(item.qty || 0) + '</strong></td>' +
                    '<td>' + Number(item.local_stock_qty || 0) + '</td>' +
                    '<td>' + serialHtml + '</td>' +
                    '<td class="fflhub-local-row-actions">' + dec +
                    '<button type="button" class="button button-small" data-remove-upc="' + escapeAttr(item.upc) + '">Remove</button></td>' +
                    '</tr>';
            });

            html += '</tbody></table>';
            $table.html(html);
        }

        function serialChips(item) {
            var serials = item.serials || [];
            if (!serials.length) {
                return '<span class="fflhub-local-muted">Serial required</span>';
            }

            return serials.map(function (serial) {
                return '<span class="fflhub-local-chip">' + escapeHtml(serial) +
                    '<button type="button" data-serial-upc="' + escapeAttr(item.upc) + '" data-remove-serial="' + escapeAttr(serial) + '">x</button>' +
                    '</span>';
            }).join(' ');
        }

        function renderFirearmFields() {
            var firearms = itemList().filter(function (item) {
                return Number(item.serial_required || 0) === 1;
            });
            var existing = firearmFieldsPayload();

            if (!firearms.length) {
                $firearmCard.prop('hidden', true);
                $firearmFields.empty();
                return;
            }

            var html = '';
            firearms.forEach(function (item) {
                var fields = existing[item.upc] || {};
                html += '<div class="fflhub-local-firearm-row" data-firearm-row data-firearm-upc="' + escapeAttr(item.upc) + '">' +
                    '<div class="fflhub-local-firearm-title"><strong>' + escapeHtml(item.name) + '</strong><code>' + escapeHtml(item.upc) + '</code></div>' +
                    '<label><span>Manufacturer</span><input type="text" data-firearm-field="manufacturer" value="' + escapeAttr(fields.manufacturer || '') + '" autocomplete="off" /></label>' +
                    '<label><span>Model</span><input type="text" data-firearm-field="model" value="' + escapeAttr(fields.model || item.name) + '" autocomplete="off" /></label>' +
                    '<label><span>Caliber</span><input type="text" data-firearm-field="caliber" value="' + escapeAttr(fields.caliber || '') + '" autocomplete="off" /></label>' +
                    '<label><span>Firearm Type</span><input type="text" data-firearm-field="firearm_type" value="' + escapeAttr(fields.firearm_type || '') + '" autocomplete="off" placeholder="Pistol, Rifle, Shotgun..." /></label>' +
                    '</div>';
            });

            $firearmFields.html(html);
            $firearmCard.prop('hidden', false);
        }

        function renderMatches(matches) {
            if (!matches.length) {
                $matches.html('<div class="fflhub-local-empty">No open successful local-stock job rows matched the scanned UPCs.</div>');
                return;
            }

            var scannedQtyByUpc = {};
            itemList().forEach(function (item) {
                scannedQtyByUpc[item.upc] = Number(item.qty || 0);
            });

            var html = '<h3>Matching Local-Stock Orders</h3>' +
                '<table class="widefat striped fflhub-local-table"><thead><tr>' +
                '<th></th><th>UPC</th><th>Order</th><th>Customer</th><th>Item</th><th>Open Qty</th><th>Assign Qty</th>' +
                '</tr></thead><tbody>';

            matches.forEach(function (match) {
                var maxQty = Math.min(Number(match.remaining_qty || 0), Number(scannedQtyByUpc[match.upc] || 0));
                html += '<tr data-match-row data-upc="' + escapeAttr(match.upc) + '" data-job-id="' + Number(match.job_id || 0) + '" data-order-id="' + Number(match.order_id || 0) + '" data-order-item-id="' + Number(match.order_item_id || 0) + '">' +
                    '<td><input type="checkbox" data-match-check ' + (maxQty > 0 ? '' : 'disabled') + ' /></td>' +
                    '<td><code>' + escapeHtml(match.upc || '') + '</code></td>' +
                    '<td><a href="' + escapeAttr(match.order_edit_url || '#') + '" target="_blank" rel="noopener">#' + escapeHtml(match.order_number || match.order_id || '') + '</a></td>' +
                    '<td>' + escapeHtml(match.customer_name || '') + '</td>' +
                    '<td>' + escapeHtml(match.item_name || '') + '</td>' +
                    '<td>' + Number(match.remaining_qty || 0) + '</td>' +
                    '<td><input type="number" min="1" max="' + maxQty + '" value="' + (maxQty > 0 ? maxQty : 0) + '" data-assign-qty /></td>' +
                    '</tr>';
            });

            html += '</tbody></table>' +
                '<button type="button" class="button button-primary fflhub-local-assign-button" data-assign-selected>Assign Selected to Orders</button>';

            $matches.html(html);
        }

        function post(action, data) {
            return $.post(ajaxUrl, $.extend({
                action: action,
                nonce: nonce
            }, data || {}));
        }

        function responseData(payload) {
            if (payload && payload.success && payload.data) {
                return payload.data;
            }
            if (payload && payload.data) {
                return payload.data;
            }
            return {};
        }

        function xhrMessage(xhr) {
            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                return xhr.responseJSON.data.message;
            }
            return '';
        }

        function setSerialMode(enabled) {
            $serial.prop('disabled', !enabled);
            $serialWrap.toggleClass('is-active', !!enabled);
            if (!enabled) {
                $serial.val('');
            }
        }

        function clearScanFields() {
            $upc.val('');
            $serial.val('');
        }

        function focusUpc() {
            window.setTimeout(function () {
                $upc.focus();
            }, 80);
        }

        function showFeedback(message, type) {
            $feedback.removeClass('is-error is-ok is-working').addClass('is-' + (type || 'working')).text(message || '');
        }

        function showResult(message, type, payload) {
            if (!message) {
                $result.empty();
                return;
            }

            var html = '<div class="fflhub-local-result-box is-' + escapeAttr(type || 'ok') + '"><strong>' + escapeHtml(message) + '</strong>';
            if (payload) {
                html += '<pre>' + escapeHtml(JSON.stringify(payload, null, 2)) + '</pre>';
            }
            html += '</div>';
            $result.html(html);
        }

        function normalizeUpc(value) {
            return String(value || '').replace(/[\s\r\n\t]+/g, '').replace(/[^0-9A-Za-z]/g, '');
        }

        function normalizeSerial(value) {
            return String(value || '').replace(/[\s\r\n\t]+/g, '').toUpperCase();
        }

        function escapeHtml(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function escapeAttr(value) {
            return escapeHtml(value);
        }

        renderAll();
    });
})(jQuery);
