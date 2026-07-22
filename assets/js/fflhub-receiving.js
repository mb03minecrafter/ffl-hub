(function ($) {
  'use strict';

  var cfg = window.FFLHubReceiving || {};
  var state = {
    shipment: null,
    muted: window.localStorage && window.localStorage.getItem('fflhubReceivingMuted') === '1',
    busy: false
  };

  function post(action, data) {
    return $.post(cfg.ajaxUrl, $.extend({
      action: action,
      nonce: cfg.nonce,
      debug_include_old: $('[data-receiving-debug-old]').is(':checked') ? '1' : '0'
    }, data || {})).then(function (response) {
      if (!response || response.success !== true) {
        return {
          ok: false,
          message: response && response.data && response.data.message
            ? response.data.message
            : 'Request failed.'
        };
      }

      return response.data || { ok: false, message: 'Empty response.' };
    });
  }

  function esc(value) {
    return $('<div/>').text(value === null || value === undefined ? '' : String(value)).html();
  }

  function beep(kind) {
    if (state.muted || !window.AudioContext && !window.webkitAudioContext) {
      return;
    }

    var Ctx = window.AudioContext || window.webkitAudioContext;
    var ctx = new Ctx();
    var osc = ctx.createOscillator();
    var gain = ctx.createGain();
    var tones = {
      success: [720, 0.08],
      error: [180, 0.18],
      complete: [900, 0.12],
      ready: [520, 0.08]
    };
    var tone = tones[kind] || tones.ready;

    osc.frequency.value = tone[0];
    osc.type = kind === 'error' ? 'sawtooth' : 'sine';
    gain.gain.value = kind === 'error' ? 0.08 : 0.05;
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start();
    window.setTimeout(function () {
      osc.stop();
      ctx.close();
    }, tone[1] * 1000);
  }

  function setFeedback(message, type) {
    var $box = $('[data-receiving-feedback]');
    if (!message) {
      $box.empty().removeClass('is-success is-error is-info');
      return;
    }

    $box
      .removeClass('is-success is-error is-info')
      .addClass(type === 'error' ? 'is-error' : (type === 'success' ? 'is-success' : 'is-info'))
      .text(message);
  }

  function setStep(active) {
    $('[data-step]').removeClass('is-active is-done');
    var order = ['identify', 'review', 'scan', 'complete'];
    var activeIndex = order.indexOf(active);
    order.forEach(function (step, index) {
      var $step = $('[data-step="' + step + '"]');
      if (index < activeIndex) {
        $step.addClass('is-done');
      } else if (index === activeIndex) {
        $step.addClass('is-active');
      }
    });
    scrollToStep(active);
  }

  function scrollToStep(step) {
    window.setTimeout(function () {
      var el = document.querySelector('[data-step="' + step + '"]');
      if (!el || !el.scrollIntoView) {
        return;
      }

      el.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
      });
    }, 80);
  }

  function requestToken() {
    return String(Date.now()).toString(36) + '-' + Math.random().toString(36).slice(2, 12);
  }

  function normalizeScanValue(value) {
    return String(value || '').replace(/[\t\r\n ]+/g, '').replace(/[^0-9A-Za-z]/g, '').trim();
  }

  function productForScan(value) {
    var upc = normalizeScanValue(value);
    if (!upc || !state.shipment || !state.shipment.products) {
      return null;
    }

    for (var i = 0; i < state.shipment.products.length; i++) {
      if (normalizeScanValue(state.shipment.products[i].upc) === upc) {
        return state.shipment.products[i];
      }
    }

    return null;
  }

  function serialRequiredForProduct(product) {
    return !!product && Number(product.serial_required || product.ffl_required || 0) === 1 && Number(product.remaining_qty || 0) > 0;
  }

  function updateSerialFieldState() {
    var $upc = $('[data-receiving-upc-input]');
    var $serial = $('[data-receiving-serial-input]');
    var $hint = $('[data-receiving-serial-hint]');
    var product = productForScan($upc.val());
    var required = serialRequiredForProduct(product);

    if (!$serial.length) {
      return;
    }

    $serial.prop('disabled', !required);
    $('[data-receiving-serial-wrap]').toggleClass('is-required', required);
    if (required) {
      $hint.text('Required for this serialized/FFL item.');
      $serial.attr('placeholder', 'Scan firearm serial number');
    } else {
      $hint.text('Serial capture unlocks after a serialized/FFL UPC is scanned.');
      $serial.val('').attr('placeholder', 'Not required for this UPC');
    }
  }

  function debugEnabled() {
    return $('[data-receiving-debug-old]').is(':checked');
  }

  function renderDebugOverrideButton() {
    if (!debugEnabled()) {
      return '';
    }

    return '' +
      '<div class="fflhub-receiving-debug-actions">' +
        '<strong>Debug tools</strong>' +
        '<span>For old-box testing only. This marks every remaining expected item as received without scanning products.</span>' +
        '<button type="button" class="button" data-receiving-debug-complete>Debug Complete Shipment</button>' +
      '</div>';
  }

  function shipmentTitle(shipment) {
    var tracking = (shipment.tracking_numbers || []).join(', ');
    return [
      shipment.dist_id || 'Distributor',
      shipment.merchant_po ? 'PO ' + shipment.merchant_po : '',
      tracking ? 'Tracking ' + tracking : ''
    ].filter(Boolean).join(' | ');
  }

  function renderProgress(shipment) {
    var expected = Number(shipment.expected_units || 0);
    var received = Number(shipment.received_units || 0);
    var pct = expected > 0 ? Math.min(100, Math.round((received / expected) * 100)) : 0;

    return '' +
      '<div class="fflhub-receiving-progress">' +
        '<div class="fflhub-receiving-progress-bar"><span style="width:' + pct + '%"></span></div>' +
        '<strong>' + received + ' / ' + expected + '</strong>' +
        '<span>' + esc(shipment.status || 'open') + '</span>' +
      '</div>';
  }

  function renderProducts(shipment) {
    var rows = (shipment.products || []).map(function (product) {
      var remaining = Number(product.remaining_qty || 0);
      var allocations = (product.orders || []).map(function (order) {
        return '<li>' +
          '<a href="' + esc(order.order_edit_url || '#') + '" target="_blank" rel="noopener noreferrer">Order #' + esc(order.order_number || order.order_id) + '</a>' +
          ' - Qty ' + Number(order.qty_expected || 0) +
          ' <span>(' + Number(order.qty_received || 0) + ' received, ' + Number(order.remaining_qty || 0) + ' open)</span>' +
        '</li>';
      }).join('');

      return '' +
        '<tr class="' + (remaining <= 0 ? 'is-complete' : '') + '">' +
          '<td>' +
            '<strong>' + esc(product.name || 'UPC ' + product.upc) + '</strong>' +
            '<div class="fflhub-receiving-muted">UPC ' + esc(product.upc) + '</div>' +
            (Number(product.serial_required || 0) === 1 ? '<div class="fflhub-receiving-serial-badge">Serial required</div>' : '') +
            (allocations ? '<ul class="fflhub-receiving-allocations">' + allocations + '</ul>' : '') +
          '</td>' +
          '<td>' + Number(product.expected_qty || 0) + '</td>' +
          '<td>' + Number(product.received_qty || 0) + '</td>' +
          '<td>' + remaining + '</td>' +
        '</tr>';
    }).join('');

    if (!rows) {
      rows = '<tr><td colspan="4">No expected products were found.</td></tr>';
    }

    return '' +
      '<table class="widefat striped fflhub-receiving-products">' +
        '<thead><tr><th>Product</th><th>Expected</th><th>Received</th><th>Open</th></tr></thead>' +
        '<tbody>' + rows + '</tbody>' +
      '</table>';
  }

  function renderFastBoundSourceOptions(fastbound, selected) {
    var contacts = fastbound && fastbound.source_contacts ? fastbound.source_contacts : [];
    if (!contacts.length) {
      return '<option value="">No mapped FastBound source contacts</option>';
    }

    return contacts.map(function (contact, index) {
      var value = contact.contact_id || '';
      var label = contact.label || contact.ffl_number || contact.external_id || value || 'FastBound contact';
      var isSelected = selected
        ? value === selected
        : index === 0;

      return '<option value="' + esc(value) + '"' + (isSelected ? ' selected' : '') + '>' + esc(label) + '</option>';
    }).join('');
  }

  function renderFastBoundSection(shipment) {
    var fastbound = shipment && shipment.fastbound ? shipment.fastbound : {};
    var events = (shipment && shipment.scan_history ? shipment.scan_history : []).filter(function (event) {
      return event.result === 'accepted' && event.serial_number;
    });

    if (!events.length) {
      return '';
    }

    if (Number(fastbound.enabled || 0) !== 1) {
      return '' +
        '<div class="fflhub-receiving-fastbound">' +
          '<h3>FastBound Bound Book</h3>' +
          '<p class="fflhub-receiving-muted">Serialized scans are present, but FastBound integration is disabled.</p>' +
        '</div>';
    }

    var configured = Number(fastbound.configured || 0) === 1;

    return '' +
      '<div class="fflhub-receiving-fastbound">' +
        '<div class="fflhub-receiving-fastbound-head">' +
          '<div>' +
            '<h3>FastBound Bound Book</h3>' +
            '<p>Acquire each serialized item when it is received, then dispose it when the order is packed for the selected FFL.</p>' +
          '</div>' +
          (!configured ? '<strong class="fflhub-receiving-fastbound-warning">FastBound settings incomplete</strong>' : '') +
        '</div>' +
        events.map(function (event) {
          return renderFastBoundEvent(event, fastbound, configured);
        }).join('') +
      '</div>';
  }

  function renderFastBoundEvent(event, fastbound, configured) {
    var status = event.fastbound_status || '';
    var acquired = !!event.fastbound_acquisition_item_id;
    var disposed = status === 'disposed' || !!event.fastbound_disposition_id;
    var failed = status === 'acquire_failed' || status === 'dispose_failed';
    var contacts = fastbound && fastbound.source_contacts ? fastbound.source_contacts : [];
    var sourceDisabled = !configured || !contacts.length;
    var acquireDisabled = sourceDisabled ? ' disabled' : '';
    var disposeDisabled = !configured ? ' disabled' : '';

    var statusText = disposed
      ? 'Disposed'
      : (acquired ? 'Acquired' : (failed ? 'Needs Attention' : 'Needs Acquisition'));

    var body = '';
    if (!acquired) {
      body =
        '<div class="fflhub-receiving-fastbound-form">' +
          '<label><span>Source Contact</span><select data-fastbound-source-contact' + (sourceDisabled ? ' disabled' : '') + '>' + renderFastBoundSourceOptions(fastbound, '') + '</select></label>' +
          '<label><span>Manufacturer</span><input type="text" value="' + esc(event.fastbound_manufacturer || '') + '" data-fastbound-manufacturer /></label>' +
          '<label><span>Model</span><input type="text" value="' + esc(event.fastbound_model || event.product_name || '') + '" data-fastbound-model /></label>' +
          '<label><span>Caliber</span><input type="text" value="' + esc(event.fastbound_caliber || '') + '" data-fastbound-caliber /></label>' +
          '<label><span>Firearm Type Required</span><input type="text" value="' + esc(event.fastbound_firearm_type || '') + '" placeholder="Pistol, Rifle, Shotgun, Receiver..." data-fastbound-firearm-type /></label>' +
          '<button type="button" class="button button-primary" data-fastbound-acquire' + acquireDisabled + '>Confirm Acquisition</button>' +
        '</div>';
    } else if (!disposed) {
      body =
        '<div class="fflhub-receiving-fastbound-form is-dispose">' +
          '<label><span>Destination FFL #</span><input type="text" value="' + esc(event.destination_ffl_number || '') + '" data-fastbound-destination-ffl /></label>' +
          '<button type="button" class="button button-primary" data-fastbound-dispose' + disposeDisabled + '>Confirm Disposition</button>' +
        '</div>';
    } else {
      body = '<p class="fflhub-receiving-muted">Acquisition and disposition are complete for this serial number.</p>';
    }

    return '' +
      '<div class="fflhub-receiving-fastbound-event ' + (disposed ? 'is-disposed' : (acquired ? 'is-acquired' : '')) + '" data-fastbound-event="' + esc(event.id) + '">' +
        '<div class="fflhub-receiving-fastbound-summary">' +
          '<div>' +
            '<strong>' + esc(event.product_name || ('UPC ' + event.upc)) + '</strong>' +
            '<span>UPC ' + esc(event.upc) + ' | Serial ' + esc(event.serial_number) + '</span>' +
            (event.order_number ? '<span>Order #' + esc(event.order_number) + '</span>' : '') +
          '</div>' +
          '<div>' +
            '<b>' + esc(statusText) + '</b>' +
            (event.fastbound_acquisition_item_id ? '<small>Item ' + esc(event.fastbound_acquisition_item_id) + '</small>' : '') +
            (event.fastbound_disposition_id ? '<small>Disposition ' + esc(event.fastbound_disposition_id) + '</small>' : '') +
          '</div>' +
        '</div>' +
        (event.fastbound_error ? '<div class="fflhub-receiving-fastbound-error">' + esc(event.fastbound_error) + '</div>' : '') +
        body +
      '</div>';
  }

  function renderShipment(shipment) {
    state.shipment = shipment;
    setFeedback('', 'info');
    $('[data-receiving-matches]').empty();

    $('[data-receiving-review]').html(
      '<h2>Review Expected Contents</h2>' +
      '<div class="fflhub-receiving-shipment-card">' +
        '<h3>' + esc(shipmentTitle(shipment)) + '</h3>' +
        renderProgress(shipment) +
        '<div class="fflhub-receiving-meta-grid">' +
          '<div><span>Orders</span><strong>' + Number(shipment.order_count || 0) + '</strong></div>' +
          '<div><span>Expected Units</span><strong>' + Number(shipment.expected_units || 0) + '</strong></div>' +
          '<div><span>Remaining</span><strong>' + Number(shipment.remaining_units || 0) + '</strong></div>' +
          '<div><span>Carrier Service</span><strong>' + esc(shipment.shipping_service || 'Unknown') + '</strong></div>' +
        '</div>' +
        renderProducts(shipment) +
        renderDebugOverrideButton() +
        '<button type="button" class="button button-primary button-hero" data-receiving-start-scan>Start Scanning Products</button>' +
      '</div>'
    );

    renderScanPane(shipment);
    renderCompletePane(shipment);
    setStep(shipment.complete ? 'complete' : 'review');
    beep(shipment.complete ? 'complete' : 'ready');
  }

  function renderScanPane(shipment, lastResult) {
    var resultHtml = '';
    if (lastResult) {
      var resultClass = lastResult.ok ? 'is-success' : 'is-error';
      var allocation = lastResult.allocation || {};
      var allocationHtml = '';
      if (lastResult.ok && (allocation.order_number || allocation.order_id)) {
        allocationHtml =
          '<div class="fflhub-receiving-set-aside">' +
            '<strong>Set aside for Order #' + esc(allocation.order_number || allocation.order_id) + '</strong>' +
            '<span>' + esc(allocation.customer_name || '') + '</span>' +
            (lastResult.serial_number ? '<span>Serial ' + esc(lastResult.serial_number) + '</span>' : '') +
            (lastResult.order_ready ? '<em>Order #' + esc(allocation.order_number || allocation.order_id) + ' is now ready to pack.</em>' : '') +
          '</div>';
      }

      resultHtml =
        '<div class="fflhub-receiving-scan-result ' + resultClass + '">' +
          '<div>' +
            '<strong>' + esc(lastResult.ok ? 'Product Received' : 'Scan Rejected') + '</strong>' +
            '<span>' + esc(lastResult.message || '') + '</span>' +
          '</div>' +
          allocationHtml +
        '</div>';
    }

    $('[data-receiving-scan]').html(
      '<h2>Scan Products</h2>' +
      '<div class="fflhub-receiving-shipment-card">' +
        '<h3>' + esc(shipmentTitle(shipment)) + '</h3>' +
        renderProgress(shipment) +
        '<div class="fflhub-receiving-scan-grid">' +
          '<label class="fflhub-receiving-field">' +
            '<span>UPC Scan</span>' +
            '<input type="text" inputmode="text" autocomplete="off" data-receiving-upc-input />' +
          '</label>' +
          '<label class="fflhub-receiving-field fflhub-receiving-serial-field" data-receiving-serial-wrap>' +
            '<span>Serial Number</span>' +
            '<input type="text" inputmode="text" autocomplete="off" disabled data-receiving-serial-input />' +
            '<small data-receiving-serial-hint>Serial capture unlocks after a serialized/FFL UPC is scanned.</small>' +
          '</label>' +
          '<button type="button" class="button button-primary" data-receiving-upc-submit>Receive Item</button>' +
        '</div>' +
        resultHtml +
        renderFastBoundSection(shipment) +
        renderDebugOverrideButton() +
        renderProducts(shipment) +
        renderHistory(shipment.scan_history || []) +
      '</div>'
    );
    updateSerialFieldState();
  }

  function renderCompletePane(shipment) {
    var readyRows = '';
    var waitingRows = '';
    (shipment && shipment.orders ? shipment.orders : []).forEach(function (order) {
      var row =
        '<div class="fflhub-receiving-order-result">' +
          '<strong><a href="' + esc(order.order_edit_url || '#') + '" target="_blank" rel="noopener noreferrer">Order #' + esc(order.order_number || order.order_id) + '</a></strong>' +
          '<span>' + esc(order.customer_name || '') + '</span>' +
          '<span>' + Number(order.received_units || 0) + ' / ' + Number(order.expected_units || 0) + ' units from inbound POs</span>' +
        '</div>';

      if (order.ready_to_pack) {
        readyRows += row;
      } else {
        waitingRows += row;
      }
    });

    var content = shipment && shipment.complete
      ? '<h2>Shipment Complete</h2>' +
        '<div class="fflhub-receiving-complete-card">' +
          '<h3>' + esc(shipmentTitle(shipment)) + '</h3>' +
          '<p>All expected units have been received.</p>' +
          renderProgress(shipment) +
          renderFastBoundSection(shipment) +
          '<div class="fflhub-receiving-complete-grid">' +
            '<section><h4>Ready to Pack</h4>' + (readyRows || '<p>No associated orders are fully ready yet.</p>') + '</section>' +
            '<section><h4>Still Waiting on Other Items</h4>' + (waitingRows || '<p>No associated orders are waiting on other inbound items.</p>') + '</section>' +
          '</div>' +
          '<button type="button" class="button button-primary" data-receiving-reset>Receive Another Shipment</button>' +
        '</div>'
      : '<h2>Shipment Complete</h2><p>Finish scanning all expected units to complete this shipment.</p>';

    $('[data-receiving-complete]').html(content);
  }

  function renderHistory(events) {
    if (!events.length) {
      return '<div class="fflhub-receiving-muted">No scans yet.</div>';
    }

    return '' +
      '<div class="fflhub-receiving-event-list">' +
        events.slice(0, 12).map(function (event) {
          var cls = event.result === 'accepted' ? 'is-success' : 'is-error';
          return '<div class="fflhub-receiving-event ' + cls + '">' +
            '<strong>' + esc(event.upc || event.exception_status || event.result) + '</strong>' +
            '<span>' + esc(event.message || '') + (event.serial_number ? ' Serial ' + esc(event.serial_number) : '') + '</span>' +
            '<small>' + esc(event.received_at || '') + '</small>' +
          '</div>';
        }).join('') +
      '</div>';
  }

  function renderMatches(matches) {
    if (!matches || !matches.length) {
      $('[data-receiving-matches]').empty();
      return;
    }

    $('[data-receiving-matches]').html(
      '<div class="fflhub-receiving-match-list">' +
        '<h3>Select Shipment</h3>' +
        matches.map(function (match) {
          return '<button type="button" class="button fflhub-receiving-match" data-shipment-key="' + esc(match.shipment_key) + '">' +
            '<strong>' + esc(shipmentTitle(match)) + '</strong>' +
            '<span>' + Number(match.received_units || 0) + ' / ' + Number(match.expected_units || 0) + ' received</span>' +
          '</button>';
        }).join('') +
      '</div>'
    );
  }

  function handleLookup(kind) {
    var isTracking = kind === 'tracking';
    var $input = isTracking ? $('[data-receiving-tracking-input]') : $('[data-receiving-po-input]');
    var value = $.trim($input.val());
    if (!value) {
      setFeedback(isTracking ? 'Scan a tracking number.' : 'Enter a PO or distributor order number.', 'error');
      beep('error');
      $input.trigger('focus');
      return;
    }

    setFeedback('Looking up shipment...', 'info');
    post(isTracking ? 'fflhub_receiving_lookup_tracking' : 'fflhub_receiving_lookup_po', isTracking ? { tracking: value } : { po: value })
      .then(function (payload) {
        if (payload.ok && payload.shipment) {
          renderShipment(payload.shipment);
          return;
        }

        if (payload.code === 'multiple_matches') {
          setFeedback(payload.message || 'Multiple matches found.', 'info');
          renderMatches(payload.matches || []);
          beep('ready');
          return;
        }

        setFeedback(payload.message || 'Shipment was not found.', 'error');
        beep('error');
      });
  }

  function scanProduct() {
    var shipment = state.shipment;
    var $input = $('[data-receiving-upc-input]');
    var $serial = $('[data-receiving-serial-input]');
    var value = $.trim($input.val());
    var product = productForScan(value);
    var serialRequired = serialRequiredForProduct(product);
    var serialNumber = $.trim($serial.val());

    if (!shipment || !shipment.shipment_key) {
      setFeedback('Choose a shipment first.', 'error');
      beep('error');
      return;
    }
    if (!value || state.busy) {
      $input.trigger('focus');
      return;
    }
    if (serialRequired && !serialNumber) {
      setFeedback('Scan the firearm serial number before receiving this item.', 'error');
      beep('error');
      $serial.prop('disabled', false).trigger('focus');
      return;
    }

    var wasSerializedAttempt = serialRequired && !!serialNumber;
    var keepSerializedScanVisible = false;
    state.busy = true;
    $input.prop('disabled', true);
    $serial.prop('disabled', true);
    post('fflhub_receiving_scan_product', {
      shipment_key: shipment.shipment_key,
      scan: value,
      serial_number: serialRequired ? serialNumber : '',
      request_token: requestToken()
    }).then(function (payload) {
      if (payload.shipment) {
        state.shipment = payload.shipment;
        renderScanPane(payload.shipment, payload);
        renderCompletePane(payload.shipment);
      }

      if (payload.ok) {
        keepSerializedScanVisible = !!payload.serial_number;
        setFeedback(payload.message || 'Product received.', 'success');
        beep(payload.shipment_complete ? 'complete' : 'success');
        if (payload.shipment_complete) {
          setStep('complete');
        } else {
          setStep('scan');
        }
      } else {
        setFeedback(payload.message || 'Scan rejected.', 'error');
        beep('error');
      }
    }).always(function () {
      state.busy = false;
      if (keepSerializedScanVisible || wasSerializedAttempt) {
        $('[data-receiving-upc-input]').val(value).prop('disabled', false);
        $('[data-receiving-serial-input]').val(serialNumber).prop('disabled', false);
        if (keepSerializedScanVisible) {
          $('[data-fastbound-manufacturer]').first().trigger('focus');
        } else {
          $('[data-receiving-serial-input]').trigger('focus');
        }
        return;
      }

      $('[data-receiving-upc-input]').val('').prop('disabled', false).trigger('focus');
      $('[data-receiving-serial-input]').val('').prop('disabled', true);
      updateSerialFieldState();
    });
  }

  function debugCompleteShipment() {
    var shipment = state.shipment;
    if (!shipment || !shipment.shipment_key) {
      setFeedback('Choose a shipment first.', 'error');
      beep('error');
      return;
    }
    if (!debugEnabled()) {
      setFeedback('Enable older/test shipment debug mode first.', 'error');
      beep('error');
      return;
    }
    if (state.busy) {
      return;
    }
    if (!window.confirm('Debug complete this shipment without scanning product UPCs? This writes debug receiving events.')) {
      return;
    }

    state.busy = true;
    setFeedback('Applying debug receiving override...', 'info');
    post('fflhub_receiving_debug_complete', {
      shipment_key: shipment.shipment_key
    }).then(function (payload) {
      if (payload.shipment) {
        state.shipment = payload.shipment;
        renderScanPane(payload.shipment, payload);
        renderCompletePane(payload.shipment);
      }

      if (payload.ok) {
        setFeedback(payload.message || 'Debug override complete.', 'success');
        beep(payload.shipment_complete ? 'complete' : 'success');
        setStep(payload.shipment_complete ? 'complete' : 'scan');
      } else {
        setFeedback(payload.message || 'Debug override failed.', 'error');
        beep('error');
      }
    }).always(function () {
      state.busy = false;
      $('[data-receiving-upc-input]').trigger('focus');
    });
  }

  function refreshCurrentShipment(callback) {
    if (!state.shipment || !state.shipment.shipment_key) {
      if (typeof callback === 'function') {
        callback();
      }
      return;
    }

    post('fflhub_receiving_get_shipment', { shipment_key: state.shipment.shipment_key }).then(function (payload) {
      if (payload.ok && payload.shipment) {
        state.shipment = payload.shipment;
        renderScanPane(payload.shipment);
        renderCompletePane(payload.shipment);
      }

      if (typeof callback === 'function') {
        callback(payload);
      }
    });
  }

  function fastBoundAcquire($button) {
    var $card = $button.closest('[data-fastbound-event]');
    var eventId = Number($card.data('fastbound-event') || 0);
    if (!eventId || state.busy) {
      return;
    }

    state.busy = true;
    $button.prop('disabled', true);
    setFeedback('Committing FastBound acquisition...', 'info');
    post('fflhub_receiving_fastbound_acquire', {
      event_id: eventId,
      source_contact_id: $.trim($card.find('[data-fastbound-source-contact]').val() || ''),
      manufacturer: $.trim($card.find('[data-fastbound-manufacturer]').val() || ''),
      model: $.trim($card.find('[data-fastbound-model]').val() || ''),
      caliber: $.trim($card.find('[data-fastbound-caliber]').val() || ''),
      firearm_type: $.trim($card.find('[data-fastbound-firearm-type]').val() || '')
    }).then(function (payload) {
      if (payload.ok) {
        setFeedback(payload.message || 'FastBound acquisition committed.', 'success');
        beep('success');
      } else {
        setFeedback(payload.message || 'FastBound acquisition failed.', 'error');
        beep('error');
      }

      refreshCurrentShipment();
    }).always(function () {
      state.busy = false;
      $button.prop('disabled', false);
    });
  }

  function fastBoundDispose($button) {
    var $card = $button.closest('[data-fastbound-event]');
    var eventId = Number($card.data('fastbound-event') || 0);
    var ffl = $.trim($card.find('[data-fastbound-destination-ffl]').val() || '');
    if (!eventId || state.busy) {
      return;
    }
    if (!ffl) {
      setFeedback('Enter the destination FFL number before disposition.', 'error');
      beep('error');
      $card.find('[data-fastbound-destination-ffl]').trigger('focus');
      return;
    }
    if (!window.confirm('Commit this FastBound disposition to the destination FFL?')) {
      return;
    }

    state.busy = true;
    $button.prop('disabled', true);
    setFeedback('Committing FastBound disposition...', 'info');
    post('fflhub_receiving_fastbound_dispose', {
      event_id: eventId,
      destination_ffl_number: ffl
    }).then(function (payload) {
      if (payload.ok) {
        setFeedback(payload.message || 'FastBound disposition committed.', 'success');
        beep('success');
      } else {
        setFeedback(payload.message || 'FastBound disposition failed.', 'error');
        beep('error');
      }

      refreshCurrentShipment();
    }).always(function () {
      state.busy = false;
      $button.prop('disabled', false);
    });
  }

  function refreshHistory() {
    post('fflhub_receiving_history', {}).then(function (payload) {
      var rows = (payload.history || []).map(function (row) {
        return '<div class="fflhub-receiving-history-row">' +
          '<strong>' + esc(row.dist_id || '') + ' ' + esc(row.merchant_po || '') + '</strong>' +
          '<span>' + Number(row.received_units || 0) + ' / ' + Number(row.expected_units || 0) + ' units</span>' +
          '<span>' + esc(row.status || '') + '</span>' +
          '<small>' + esc(row.last_event_at || '') + '</small>' +
        '</div>';
      }).join('');

      $('[data-receiving-history]').html(rows || '<p>No receiving history yet.</p>');
    });
  }

  function bindEvents() {
    $(document).on('click', '[data-receiving-tracking-submit]', function () {
      handleLookup('tracking');
    });
    $(document).on('click', '[data-receiving-po-submit]', function () {
      handleLookup('po');
    });
    $(document).on('keydown', '[data-receiving-tracking-input]', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        handleLookup('tracking');
      }
    });
    $(document).on('keydown', '[data-receiving-po-input]', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        handleLookup('po');
      }
    });
    $(document).on('click', '[data-receiving-match]', function () {
      var key = $(this).data('shipment-key');
      post('fflhub_receiving_get_shipment', { shipment_key: key }).then(function (payload) {
        if (payload.ok && payload.shipment) {
          renderShipment(payload.shipment);
        } else {
          setFeedback(payload.message || 'Shipment was not found.', 'error');
          beep('error');
        }
      });
    });
    $(document).on('click', '[data-receiving-start-scan]', function () {
      setStep('scan');
      window.setTimeout(function () {
        $('[data-receiving-upc-input]').trigger('focus');
      }, 50);
    });
    $(document).on('click', '[data-receiving-upc-submit]', scanProduct);
    $(document).on('click', '[data-receiving-debug-complete]', debugCompleteShipment);
    $(document).on('click', '[data-fastbound-acquire]', function () {
      fastBoundAcquire($(this));
    });
    $(document).on('click', '[data-fastbound-dispose]', function () {
      fastBoundDispose($(this));
    });
    $(document).on('input change', '[data-receiving-upc-input]', updateSerialFieldState);
    $(document).on('keydown', '[data-receiving-upc-input]', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        if (serialRequiredForProduct(productForScan($(this).val())) && !$.trim($('[data-receiving-serial-input]').val())) {
          setFeedback('Scan the firearm serial number before receiving this item.', 'info');
          $('[data-receiving-serial-input]').prop('disabled', false).trigger('focus');
          return;
        }
        scanProduct();
      }
    });
    $(document).on('keydown', '[data-receiving-serial-input]', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        window.setTimeout(scanProduct, 120);
      }
    });
    $(document).on('blur', '[data-receiving-upc-input]', function () {
      if (state.shipment && !state.shipment.complete) {
        window.setTimeout(function () {
          if ($(document.activeElement).is('[data-receiving-serial-input], [data-receiving-upc-submit]')) {
            return;
          }
          $('[data-receiving-upc-input]').trigger('focus');
        }, 250);
      }
    });
    $(document).on('click', '[data-receiving-reset]', function () {
      state.shipment = null;
      setStep('identify');
      setFeedback('', 'info');
      $('[data-receiving-tracking-input], [data-receiving-po-input]').val('');
      $('[data-receiving-review], [data-receiving-scan], [data-receiving-complete], [data-receiving-matches]').empty();
      $('[data-receiving-tracking-input]').trigger('focus');
    });
    $(document).on('click', '[data-receiving-history-refresh]', refreshHistory);
    $(document).on('click', '[data-receiving-mute]', function () {
      state.muted = !state.muted;
      if (window.localStorage) {
        window.localStorage.setItem('fflhubReceivingMuted', state.muted ? '1' : '0');
      }
      $(this).text(state.muted ? 'Enable Sounds' : 'Mute Sounds');
    });
  }

  $(function () {
    if (!$('[data-receiving-app]').length) {
      return;
    }

    bindEvents();
    $('[data-receiving-mute]').text(state.muted ? 'Enable Sounds' : 'Mute Sounds');
    $('[data-receiving-tracking-input]').trigger('focus');
    refreshHistory();
  });
})(jQuery);
