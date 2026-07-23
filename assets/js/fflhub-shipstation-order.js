(function () {
  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function money(value) {
    var num = Number(value || 0);
    return '$' + num.toFixed(2);
  }

  function setNested(obj, path, value) {
    var parts = path.split('.');
    var cursor = obj;
    for (var i = 0; i < parts.length - 1; i++) {
      cursor[parts[i]] = cursor[parts[i]] || {};
      cursor = cursor[parts[i]];
    }
    cursor[parts[parts.length - 1]] = value;
  }

  function getNested(obj, path) {
    return path.split('.').reduce(function (cursor, part) {
      return cursor && Object.prototype.hasOwnProperty.call(cursor, part) ? cursor[part] : undefined;
    }, obj);
  }

  function readAddress(panel, group) {
    var out = {};
    panel.querySelectorAll('[data-address-group="' + group + '"] [data-field]').forEach(function (field) {
      out[field.dataset.field] = field.value;
    });
    return out;
  }

  function readPackages(panel) {
    var rows = [];
    panel.querySelectorAll('.fflhub-ss-package-row').forEach(function (row) {
      var pkg = {
        weight: { unit: 'ounce' },
        dimensions: { unit: 'inch' },
        insured_value: { currency: 'usd' }
      };
      row.querySelectorAll('[data-field]').forEach(function (field) {
        var value = field.value;
        if (field.type === 'number') {
          value = Number(value || 0);
        }
        setNested(pkg, field.dataset.field, value);
      });
      rows.push(pkg);
    });
    return rows;
  }

  function buildPayload(panel) {
    return {
      destination: readAddress(panel, 'destination'),
      packages: readPackages(panel),
      ship_date: panel.querySelector('.fflhub-ss-ship-date').value,
      confirmation: panel.querySelector('.fflhub-ss-confirmation').value
    };
  }

  function setMessage(panel, message, type) {
    var box = panel.querySelector('.fflhub-ss-message');
    box.textContent = message || '';
    box.className = 'fflhub-ss-message' + (type ? ' is-' + type : '');
  }

  function setLoading(panel, loading) {
    panel.classList.toggle('is-loading', loading);
    panel.querySelectorAll('button').forEach(function (button) {
      if (!button.classList.contains('fflhub-ss-remove-package')) {
        button.disabled = loading;
      }
    });
  }

  function request(panel, path, body) {
    var root = (window.FFLHubShipStation && window.FFLHubShipStation.restRoot) || '';
    var nonce = (window.FFLHubShipStation && window.FFLHubShipStation.nonce) || '';
    var orderId = panel.dataset.orderId;

    return fetch(root + orderId + path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce
      },
      body: JSON.stringify(body || {})
    }).then(function (response) {
      return response.json().then(function (json) {
        if (!response.ok) {
          var message = json && json.message ? json.message : 'ShipStation request failed.';
          throw new Error(message);
        }
        return json;
      });
    });
  }

  function invalidateRates(panel) {
    panel.dataset.shipmentHash = '';
    panel.dataset.selectedRateId = '';
    panel.querySelector('.fflhub-ss-rates').innerHTML = '';
    panel.querySelector('.fflhub-ss-diagnostics-body').innerHTML = '';
  }

  function renderDiagnostics(panel, invalidRates) {
    var target = panel.querySelector('.fflhub-ss-diagnostics-body');
    if (!invalidRates || !invalidRates.length) {
      target.innerHTML = '<p>No invalid rates returned.</p>';
      return;
    }

    target.innerHTML = invalidRates.map(function (rate) {
      var errors = (rate.error_messages || []).map(function (msg) {
        return '<li>' + escapeHtml(String(msg)) + '</li>';
      }).join('');
      return '<div class="fflhub-ss-invalid-rate"><strong>' +
        escapeHtml(rate.carrier_nickname || rate.carrier_code || 'Carrier') +
        '</strong><ul>' + errors + '</ul></div>';
    }).join('');
  }

  function sortRates(rates, key) {
    var sorted = rates.slice();
    sorted.sort(function (a, b) {
      if (key === 'carrier') {
        return String(a.carrier_nickname || a.carrier_code).localeCompare(String(b.carrier_nickname || b.carrier_code));
      }
      if (key === 'service') {
        return String(a.service_type || a.service_code).localeCompare(String(b.service_type || b.service_code));
      }
      if (key === 'delivery_date') {
        return String(a.estimated_delivery_date || '9999').localeCompare(String(b.estimated_delivery_date || '9999'));
      }
      if (key === 'delivery_days') {
        return Number(a.delivery_days || 9999) - Number(b.delivery_days || 9999);
      }
      return Number(a.total_amount || 0) - Number(b.total_amount || 0);
    });
    return sorted;
  }

  function renderRates(panel, rates, invalidRates) {
    var target = panel.querySelector('.fflhub-ss-rates');
    var currentSort = 'total';
    var currentCarrier = '';
    var currentMaxDays = '';
    var carrierOptions = {};
    (rates || []).forEach(function (rate) {
      var key = rate.carrier_id || rate.carrier_code || '';
      if (key) {
        carrierOptions[key] = rate.carrier_nickname || rate.carrier_friendly_name || rate.carrier_code || key;
      }
    });

    function paint() {
      var rows = (rates || []).filter(function (rate) {
        var carrierKey = rate.carrier_id || rate.carrier_code || '';
        if (currentCarrier && carrierKey !== currentCarrier) {
          return false;
        }
        if (currentMaxDays && Number(rate.delivery_days || 9999) > Number(currentMaxDays)) {
          return false;
        }
        return true;
      });
      rows = sortRates(rows, currentSort);
      var fastestDays = rows.length ? Math.min.apply(null, rows.map(function (r) { return Number(r.delivery_days || 9999); })) : 9999;
      var cheapest = rows.length ? Math.min.apply(null, rows.map(function (r) { return Number(r.total_amount || 0); })) : 0;

      var html = '<div class="fflhub-ss-rate-toolbar"><label>Sort <select class="fflhub-ss-rate-sort">' +
        '<option value="total">Total price</option>' +
        '<option value="carrier">Carrier</option>' +
        '<option value="service">Service</option>' +
        '<option value="delivery_date">Estimated delivery</option>' +
        '<option value="delivery_days">Transit days</option>' +
        '</select></label><label>Carrier <select class="fflhub-ss-rate-carrier"><option value="">All carriers</option>';
      Object.keys(carrierOptions).sort(function (a, b) {
        return carrierOptions[a].localeCompare(carrierOptions[b]);
      }).forEach(function (key) {
        html += '<option value="' + escapeHtml(key) + '">' + escapeHtml(carrierOptions[key]) + '</option>';
      });
      html += '</select></label><label>Speed <select class="fflhub-ss-rate-days">' +
        '<option value="">Any speed</option>' +
        '<option value="1">1 day or less</option>' +
        '<option value="2">2 days or less</option>' +
        '<option value="3">3 days or less</option>' +
        '<option value="5">5 days or less</option>' +
        '</select></label></div>';
      if (!rows.length) {
        html += '<div class="fflhub-ss-empty">No valid rates match the current filters.</div>';
      } else {
        html += '<table class="widefat striped fflhub-ss-rate-table"><thead><tr>' +
          '<th></th><th>Carrier</th><th>Service</th><th>Base</th><th>Conf.</th><th>Ins.</th><th>Other</th><th>Total</th><th>Transit</th><th>Warnings</th>' +
          '</tr></thead><tbody>';
        rows.forEach(function (rate) {
        var badges = [];
        if (Number(rate.total_amount || 0) === cheapest) badges.push('<span class="fflhub-ss-badge">Cheapest</span>');
        if (Number(rate.delivery_days || 9999) === fastestDays && fastestDays !== 9999) badges.push('<span class="fflhub-ss-badge">Fastest</span>');
        if (Number(rate.total_amount || 0) === cheapest && Number(rate.delivery_days || 9999) === fastestDays && fastestDays !== 9999) badges.push('<span class="fflhub-ss-badge">Best value</span>');
        if (rate.guaranteed_service) badges.push('<span class="fflhub-ss-badge is-guaranteed">Guaranteed</span>');

        html += '<tr>' +
          '<td><input type="radio" name="fflhub_ss_rate" value="' + escapeHtml(rate.rate_id) + '" /></td>' +
          '<td><strong>' + escapeHtml(rate.carrier_nickname || rate.carrier_friendly_name || rate.carrier_code || '') + '</strong><br><code>' + escapeHtml(rate.carrier_code || '') + '</code></td>' +
          '<td>' + escapeHtml(rate.service_type || '') + '<br><code>' + escapeHtml(rate.service_code || '') + '</code><div>' + badges.join(' ') + '</div></td>' +
          '<td>' + money(rate.shipping_amount) + '</td>' +
          '<td>' + money(rate.confirmation_amount) + '</td>' +
          '<td>' + money(rate.insurance_amount) + '</td>' +
          '<td>' + money(rate.other_amount) + '</td>' +
          '<td><strong>' + money(rate.total_amount) + '</strong></td>' +
          '<td>' + escapeHtml(rate.delivery_days || '') + '<br>' + escapeHtml(rate.estimated_delivery_date || '') + '</td>' +
          '<td>' + escapeHtml((rate.warning_messages || []).join('; ')) + '</td>' +
          '</tr>';
        });
        html += '</tbody></table><button type="button" class="button button-primary fflhub-ss-purchase">Purchase Selected Label</button>';
      }
      target.innerHTML = html;
      target.querySelector('.fflhub-ss-rate-sort').value = currentSort;
      target.querySelector('.fflhub-ss-rate-carrier').value = currentCarrier;
      target.querySelector('.fflhub-ss-rate-days').value = currentMaxDays;
      target.querySelector('.fflhub-ss-rate-sort').addEventListener('change', function (event) {
        currentSort = event.target.value;
        paint();
      });
      target.querySelector('.fflhub-ss-rate-carrier').addEventListener('change', function (event) {
        currentCarrier = event.target.value;
        paint();
      });
      target.querySelector('.fflhub-ss-rate-days').addEventListener('change', function (event) {
        currentMaxDays = event.target.value;
        paint();
      });
    }

    paint();
    renderDiagnostics(panel, invalidRates || []);
  }

  function addPackage(panel) {
    var list = panel.querySelector('.fflhub-ss-package-list');
    var first = list.querySelector('.fflhub-ss-package-row');
    if (!first) return;
    var copy = first.cloneNode(true);
    copy.querySelectorAll('input').forEach(function (input) {
      if (input.dataset.field === 'package_code') {
        input.value = 'package';
      } else if (input.dataset.field === 'insured_value.amount') {
        input.value = '0';
      } else {
        input.value = '';
      }
    });
    list.appendChild(copy);
    invalidateRates(panel);
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  ready(function () {
    document.querySelectorAll('.fflhub-ss-panel').forEach(function (panel) {
      panel.addEventListener('input', function (event) {
        if (event.target.matches('input,select')) {
          invalidateRates(panel);
        }
      });

      panel.addEventListener('click', function (event) {
        if (event.target.matches('.fflhub-ss-add-package')) {
          event.preventDefault();
          addPackage(panel);
        }

        if (event.target.matches('.fflhub-ss-remove-package')) {
          event.preventDefault();
          var rows = panel.querySelectorAll('.fflhub-ss-package-row');
          if (rows.length > 1) {
            event.target.closest('.fflhub-ss-package-row').remove();
            invalidateRates(panel);
          }
        }

        if (event.target.matches('.fflhub-ss-copy')) {
          event.preventDefault();
          navigator.clipboard && navigator.clipboard.writeText(event.target.dataset.copy || '');
          setMessage(panel, 'Copied tracking number.', 'success');
        }

        if (event.target.matches('.fflhub-ss-validate-address')) {
          event.preventDefault();
          setLoading(panel, true);
          request(panel, '/validate-address', { address: readAddress(panel, 'destination') })
            .then(function () { setMessage(panel, 'ShipStation address validation returned successfully.', 'success'); })
            .catch(function (error) { setMessage(panel, error.message, 'error'); })
            .finally(function () { setLoading(panel, false); });
        }

        if (event.target.matches('.fflhub-ss-get-rates')) {
          event.preventDefault();
          setLoading(panel, true);
          setMessage(panel, 'Requesting ShipStation rates...', '');
          request(panel, '/rates', buildPayload(panel))
            .then(function (data) {
              panel.dataset.shipmentHash = data.shipment_hash || '';
              renderRates(panel, data.rates || [], data.invalid_rates || []);
              setMessage(panel, 'Rates refreshed. Pick a rate before purchasing.', 'success');
            })
            .catch(function (error) { setMessage(panel, error.message, 'error'); })
            .finally(function () { setLoading(panel, false); });
        }

        if (event.target.matches('.fflhub-ss-purchase')) {
          event.preventDefault();
          var selected = panel.querySelector('input[name="fflhub_ss_rate"]:checked');
          if (!selected) {
            setMessage(panel, 'Select a rate first.', 'error');
            return;
          }
          var row = selected.closest('tr');
          if (!window.confirm('Purchase this ShipStation label?\n\n' + row.innerText)) {
            return;
          }
          setLoading(panel, true);
          request(panel, '/purchase', {
            rate_id: selected.value,
            shipment_hash: panel.dataset.shipmentHash || '',
            shipment: buildPayload(panel)
          })
            .then(function () {
              setMessage(panel, 'Label purchased. Reloading order panel...', 'success');
              window.location.reload();
            })
            .catch(function (error) { setMessage(panel, error.message, 'error'); })
            .finally(function () { setLoading(panel, false); });
        }

        if (event.target.matches('.fflhub-ss-void-label')) {
          event.preventDefault();
          var labelId = event.target.dataset.labelId || '';
          if (!labelId || !window.confirm('Void label ' + labelId + '? This cannot be undone.')) {
            return;
          }
          setLoading(panel, true);
          request(panel, '/void', { label_id: labelId })
            .then(function () {
              setMessage(panel, 'Label voided. Reloading order panel...', 'success');
              window.location.reload();
            })
            .catch(function (error) { setMessage(panel, error.message, 'error'); })
            .finally(function () { setLoading(panel, false); });
        }
      });
    });
  });
})();
