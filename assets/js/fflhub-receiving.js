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
        '<label class="fflhub-receiving-field fflhub-receiving-scan-field">' +
          '<span>UPC Scan</span>' +
          '<input type="text" inputmode="text" autocomplete="off" data-receiving-upc-input />' +
          '<button type="button" class="button button-primary" data-receiving-upc-submit>Receive Item</button>' +
        '</label>' +
        resultHtml +
        renderDebugOverrideButton() +
        renderProducts(shipment) +
        renderHistory(shipment.scan_history || []) +
      '</div>'
    );
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
            '<span>' + esc(event.message || '') + '</span>' +
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
    var value = $.trim($input.val());

    if (!shipment || !shipment.shipment_key) {
      setFeedback('Choose a shipment first.', 'error');
      beep('error');
      return;
    }
    if (!value || state.busy) {
      $input.trigger('focus');
      return;
    }

    state.busy = true;
    $input.prop('disabled', true);
    post('fflhub_receiving_scan_product', {
      shipment_key: shipment.shipment_key,
      scan: value,
      request_token: requestToken()
    }).then(function (payload) {
      if (payload.shipment) {
        state.shipment = payload.shipment;
        renderScanPane(payload.shipment, payload);
        renderCompletePane(payload.shipment);
      }

      if (payload.ok) {
        beep(payload.shipment_complete ? 'complete' : 'success');
        if (payload.shipment_complete) {
          setStep('complete');
        } else {
          setStep('scan');
        }
      } else {
        beep('error');
      }
    }).always(function () {
      state.busy = false;
      $('[data-receiving-upc-input]').val('').prop('disabled', false).trigger('focus');
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
      setFeedback('Enable old/completed shipment debug mode first.', 'error');
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
    $(document).on('keydown', '[data-receiving-upc-input]', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        scanProduct();
      }
    });
    $(document).on('blur', '[data-receiving-upc-input]', function () {
      if (state.shipment && !state.shipment.complete) {
        window.setTimeout(function () {
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
