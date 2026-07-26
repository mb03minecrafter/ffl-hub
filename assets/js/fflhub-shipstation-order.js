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

  function writeAddress(panel, group, address) {
    panel.querySelectorAll('[data-address-group="' + group + '"] [data-field]').forEach(function (field) {
      if (Object.prototype.hasOwnProperty.call(address || {}, field.dataset.field)) {
        field.value = address[field.dataset.field] || '';
      }
    });
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
      if (row.dataset.fflhubAutoPackedShape === '1') {
        pkg.auto_packed_shape = true;
      }
      rows.push(pkg);
    });
    return rows;
  }

  function readPackageItems(panel) {
    var packages = [];
    panel.querySelectorAll('.fflhub-ss-package-row').forEach(function (row) {
      var items = [];
      row.querySelectorAll('[data-package-item-qty]').forEach(function (input) {
        var itemId = Number(input.dataset.itemId || 0) || 0;
        var quantity = Number(input.value || 0) || 0;
        if (itemId > 0 && quantity > 0) {
          items.push({
            item_id: itemId,
            quantity: Math.max(0, Math.floor(quantity))
          });
        }
      });
      packages.push(items);
    });
    return packages;
  }

  function context(panel) {
    if (panel.fflhubShipStationContext) {
      return panel.fflhubShipStationContext;
    }

    try {
      panel.fflhubShipStationContext = JSON.parse(panel.dataset.context || '{}') || {};
    } catch (error) {
      panel.fflhubShipStationContext = {};
    }

    return panel.fflhubShipStationContext;
  }

  function packagePreset(panel, presetId) {
    var presets = context(panel).package_presets || [];
    for (var i = 0; i < presets.length; i++) {
      if (String(presets[i].id || '') === String(presetId || '')) {
        return presets[i];
      }
    }
    return null;
  }

  function field(row, name) {
    return row.querySelector('[data-field="' + name + '"]');
  }

  function setField(row, name, value) {
    var input = field(row, name);
    if (input) {
      if (input.tagName === 'SELECT' && value && !input.querySelector('option[value="' + String(value).replace(/"/g, '\\"') + '"]')) {
        var option = document.createElement('option');
        option.value = value;
        option.textContent = value;
        input.appendChild(option);
      }
      input.value = value || '';
    }
  }

  function numericInput(row, role) {
    return row.querySelector('[data-weight-role="' + role + '"]');
  }

  function numberValue(input) {
    return Number(input && input.value ? input.value : 0) || 0;
  }

  function positiveNumber(value) {
    var num = Number(value || 0);
    return num > 0 ? num : 0;
  }

  function formatWeight(value) {
    var rounded = Math.round(Math.max(0, Number(value || 0)) * 100) / 100;
    return rounded.toFixed(2).replace(/\.?0+$/, '');
  }

  function recalcPackageWeight(row) {
    var total = numericInput(row, 'total');
    if (!total) {
      return;
    }

    total.value = formatWeight(
      numberValue(numericInput(row, 'content')) + numberValue(numericInput(row, 'package'))
    );
  }

  function orderItemIndex(panel) {
    var index = {};
    (context(panel).order_items || []).forEach(function (item) {
      var itemId = Number(item.item_id || 0) || 0;
      if (itemId > 0) {
        index[itemId] = item;
      }
    });
    return index;
  }

  function packageCode(row) {
    var input = field(row, 'package_code');
    return rateKeyText(input ? input.value : '');
  }

  function maxAssignedItemHeight(panel, row) {
    var itemsById = orderItemIndex(panel);
    var maxHeight = 0;

    row.querySelectorAll('[data-package-item-qty]').forEach(function (input) {
      var qty = Number(input.value || 0) || 0;
      var itemId = Number(input.dataset.itemId || 0) || 0;
      var item = itemId > 0 ? itemsById[itemId] : null;
      if (qty <= 0 || !item) {
        return;
      }

      maxHeight = Math.max(maxHeight, positiveNumber(item.height_in));
    });

    return maxHeight;
  }

  function rememberEnvelopeBaseHeight(row) {
    var height = field(row, 'dimensions.height');
    if (height && packageCode(row) === 'thick_envelope') {
      row.dataset.envelopeBaseHeight = height.value || '';
    }
  }

  function syncPackageShapeForRow(panel, row) {
    if (!row || packageCode(row) !== 'thick_envelope') {
      return;
    }

    if (row.dataset.fflhubAutoPackedShape === '1') {
      return;
    }

    var height = field(row, 'dimensions.height');
    if (!height) {
      return;
    }

    var baseHeight = positiveNumber(row.dataset.envelopeBaseHeight || height.value);
    var itemHeight = maxAssignedItemHeight(panel, row);
    height.value = formatWeight(Math.max(baseHeight, itemHeight));
  }

  function syncPackageWeights(panel) {
    panel.querySelectorAll('.fflhub-ss-package-row').forEach(recalcPackageWeight);
  }

  function syncPackageShapes(panel) {
    panel.querySelectorAll('.fflhub-ss-package-row').forEach(function (row) {
      syncPackageShapeForRow(panel, row);
    });
  }

  function packageItemsFromPackage(pkg) {
    return (pkg && Array.isArray(pkg.items) ? pkg.items : []).map(function (item) {
      return {
        item_id: Number(item.item_id || item.order_item_id || 0) || 0,
        quantity: Math.max(0, Math.floor(Number(item.quantity || 0) || 0))
      };
    }).filter(function (item) {
      return item.item_id > 0 && item.quantity > 0;
    });
  }

  function resetPackageRow(row) {
    row.querySelectorAll('input').forEach(function (input) {
      if (input.dataset.field === 'insured_value.amount') {
        input.value = '0';
      } else {
        input.value = '';
      }
    });
    row.querySelectorAll('select[data-field="package_code"]').forEach(function (select) {
      select.value = 'package';
    });
    row.querySelectorAll('[data-weight-role]').forEach(function (input) {
      input.value = '';
    });
    row.querySelectorAll('[data-package-item-qty]').forEach(function (input) {
      input.value = '0';
    });
    row.querySelectorAll('.fflhub-ss-package-preset').forEach(function (select) {
      select.value = '';
    });
    row.dataset.envelopeBaseHeight = '';
    row.dataset.fflhubAutoPackedShape = '';
  }

  function ensurePackageRows(panel, count) {
    var list = panel.querySelector('.fflhub-ss-package-list');
    var rows = Array.prototype.slice.call(list.querySelectorAll('.fflhub-ss-package-row'));
    if (!rows.length) {
      return [];
    }

    while (rows.length < count) {
      var copy = rows[0].cloneNode(true);
      resetPackageRow(copy);
      list.appendChild(copy);
      rows.push(copy);
    }

    while (rows.length > count && rows.length > 1) {
      var row = rows.pop();
      row.parentNode.removeChild(row);
    }

    rows.forEach(function (row, index) {
      row.dataset.packageIndex = String(index);
    });

    return rows;
  }

  function setPackagePreset(row, presetId) {
    var select = row.querySelector('.fflhub-ss-package-preset');
    if (!select) {
      return;
    }

    if (presetId && select.querySelector('option[value="' + String(presetId).replace(/"/g, '\\"') + '"]')) {
      select.value = presetId;
    } else {
      select.value = '';
    }
  }

  function setPackageItemQuantities(row, items) {
    var wanted = {};
    (items || []).forEach(function (item) {
      var itemId = Number(item.item_id || 0) || 0;
      if (itemId > 0) {
        wanted[itemId] = (wanted[itemId] || 0) + Math.max(0, Math.floor(Number(item.quantity || 0) || 0));
      }
    });

    row.querySelectorAll('[data-package-item-qty]').forEach(function (input) {
      var itemId = Number(input.dataset.itemId || 0) || 0;
      input.value = String(Math.max(0, wanted[itemId] || 0));
    });
  }

  function applyPackageToRow(panel, row, pkg) {
    pkg = pkg || {};
    var weight = pkg.weight || {};
    var dims = pkg.dimensions || {};
    var insured = pkg.insured_value || {};

    setPackagePreset(row, pkg.preset_id || '');
    setField(row, 'package_code', pkg.package_code || 'package');
    setField(row, 'dimensions.length', dims.length || '');
    setField(row, 'dimensions.width', dims.width || '');
    setField(row, 'dimensions.height', dims.height || '');
    setField(row, 'insured_value.amount', insured.amount || '0');

    var contentWeight = numericInput(row, 'content');
    var packageWeight = numericInput(row, 'package');
    var totalWeight = numericInput(row, 'total');
    if (contentWeight) {
      contentWeight.value = formatWeight(pkg.content_weight_oz || weight.value || 0);
    }
    if (packageWeight) {
      packageWeight.value = formatWeight(pkg.package_weight_oz || 0);
    }
    if (totalWeight) {
      totalWeight.value = formatWeight(weight.value || 0);
    }

    setPackageItemQuantities(row, packageItemsFromPackage(pkg));
    row.dataset.envelopeBaseHeight = dims.height || '';
    row.dataset.fflhubAutoPackedShape = '1';
    syncPackageShapeForRow(panel, row);
    recalcPackageWeight(row);
  }

  function applyPackingPlan(panel, data) {
    var packages = data && Array.isArray(data.label_packages) ? data.label_packages : [];
    if (!packages.length) {
      setMessage(panel, (data && data.message) || 'No packages were produced by the box packer.', 'warning');
      renderDiagnostics(panel, (data && data.errors || []).map(function (message) {
        return { error_messages: [String(message)] };
      }), []);
      return;
    }

    var rows = ensurePackageRows(panel, packages.length);
    packages.forEach(function (pkg, index) {
      if (rows[index]) {
        applyPackageToRow(panel, rows[index], pkg);
      }
    });

    invalidateRates(panel);
    var message = (data && data.message) || ('Auto packed ' + packages.length + ' package(s).');
    if (data && Number(data.unpacked_item_count || 0) > 0) {
      message += ' Some items could not be packed; check diagnostics.';
      setMessage(panel, message, 'warning');
    } else {
      setMessage(panel, message + ' Review the packages, then get rates.', 'success');
    }
  }

  function applyPackagePreset(panel, select) {
    var preset = packagePreset(panel, select.value);
    var row = select.closest('.fflhub-ss-package-row');
    if (!preset || !row) {
      return;
    }

    setField(row, 'package_code', preset.package_code || 'package');
    setField(row, 'dimensions.length', preset.length || '');
    setField(row, 'dimensions.width', preset.width || '');
    setField(row, 'dimensions.height', preset.height || '');
    row.dataset.envelopeBaseHeight = preset.height || '';
    row.dataset.fflhubAutoPackedShape = '';

    var packageWeight = numericInput(row, 'package');
    if (packageWeight) {
      packageWeight.value = preset.weight_oz || '';
    }
    recalcPackageWeight(row);
    syncPackageShapeForRow(panel, row);

    invalidateRates(panel);
  }

  function buildPayload(panel) {
    syncPackageWeights(panel);
    syncPackageShapes(panel);

    return {
      destination: readAddress(panel, 'destination'),
      packages: readPackages(panel),
      package_items: readPackageItems(panel),
      ship_date: panel.querySelector('.fflhub-ss-ship-date').value,
      confirmation: panel.querySelector('.fflhub-ss-confirmation').value
    };
  }

  function packageAssignmentErrors(panel) {
    var orderItems = context(panel).order_items || [];
    if (!orderItems.length) {
      return [];
    }

    var packages = readPackageItems(panel);
    var errors = [];
    var expected = {};
    var actual = {};

    packages.forEach(function (items, index) {
      if (!items.length) {
        errors.push('Package ' + (index + 1) + ' must have at least one assigned Woo order item.');
      }
    });

    orderItems.forEach(function (item) {
      var itemId = Number(item.item_id || 0) || 0;
      if (itemId <= 0) {
        return;
      }
      expected[itemId] = Number(item.quantity || 0) || 0;
      actual[itemId] = 0;
    });

    packages.forEach(function (items) {
      items.forEach(function (item) {
        var itemId = Number(item.item_id || 0) || 0;
        if (Object.prototype.hasOwnProperty.call(actual, itemId)) {
          actual[itemId] += Number(item.quantity || 0) || 0;
        }
      });
    });

    Object.keys(expected).forEach(function (itemId) {
      if (Number(actual[itemId] || 0) !== Number(expected[itemId] || 0)) {
        errors.push('Order item ' + itemId + ' must be assigned exactly ' + expected[itemId] + ' time(s); currently assigned ' + (actual[itemId] || 0) + '.');
      }
    });

    return errors;
  }

  function setMessage(panel, message, type) {
    var box = panel.querySelector('.fflhub-ss-message');
    box.textContent = message || '';
    box.className = 'fflhub-ss-message' + (type ? ' is-' + type : '');
  }

  function setHtmlMessage(panel, html, type) {
    var box = panel.querySelector('.fflhub-ss-message');
    box.innerHTML = html || '';
    box.className = 'fflhub-ss-message' + (type ? ' is-' + type : '');
  }

  function formatAddress(address) {
    address = address || {};
    return [
      address.company_name || address.name || '',
      address.address_line1 || '',
      address.address_line2 || '',
      [
        address.city_locality || '',
        address.state_province || '',
        address.postal_code || ''
      ].filter(Boolean).join(', '),
      address.country_code || ''
    ].filter(Boolean).join(' | ');
  }

  function renderValidationResult(panel, data) {
    data = data || {};
    var type = data.validation_status === 'validated' ? 'success' : 'warning';
    var message = data.message || 'Address validation completed.';
    var html = '<div>' + escapeHtml(message) + '</div>';
    var recommended = data.recommended_address || null;

    if (recommended) {
      panel.fflhubShipStationValidatedAddress = recommended;
      html += '<div class="fflhub-ss-validation-address">' + escapeHtml(formatAddress(recommended)) + '</div>';
      if (data.validation_status === 'validated') {
        html += '<button type="button" class="button button-small fflhub-ss-apply-address">Apply suggested address</button>';
      }
    }

    if (data.validation_status === 'unavailable') {
      html += '<div class="description">This does not block rates or label purchase. Labels are still created with ShipStation address validation disabled.</div>';
    }

    setHtmlMessage(panel, html, type);
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

  function renderDiagnostics(panel, invalidRates, duplicateRateGroups) {
    var target = panel.querySelector('.fflhub-ss-diagnostics-body');
    invalidRates = invalidRates || [];
    duplicateRateGroups = duplicateRateGroups || [];

    if (!invalidRates.length && !duplicateRateGroups.length) {
      target.innerHTML = '<p>No invalid or duplicate rates returned.</p>';
      return;
    }

    var html = invalidRates.map(function (rate) {
      var errors = (rate.error_messages || []).map(function (msg) {
        return '<li>' + escapeHtml(String(msg)) + '</li>';
      }).join('');
      return '<div class="fflhub-ss-invalid-rate"><strong>' +
        escapeHtml(rate.provider_label || rate.carrier_nickname || rate.carrier_code || 'Carrier') +
        '</strong><ul>' + errors + '</ul></div>';
    }).join('');

    if (duplicateRateGroups.length) {
      html += '<h5>Hidden duplicate rates</h5>';
      html += duplicateRateGroups.map(function (group) {
        var duplicateIds = (group.duplicate_rate_ids || []).filter(Boolean).join(', ');
        var duplicateRows = (group.duplicates || []).map(function (duplicate) {
          var diffs = (duplicate.raw_differences || []).map(function (diff) {
            return '<li><code>' + escapeHtml(diff.field || '') + '</code>: kept <code>' +
              escapeHtml(diff.kept || '') + '</code>, duplicate <code>' +
              escapeHtml(diff.duplicate || '') + '</code></li>';
          }).join('');
          if (!diffs) {
            diffs = '<li>No raw top-level differences except the rate identity.</li>';
          }
          return '<details><summary>Duplicate rate ' + escapeHtml(duplicate.rate_id || '') + '</summary><ul>' + diffs + '</ul></details>';
        }).join('');

        return '<div class="fflhub-ss-invalid-rate fflhub-ss-duplicate-rate">' +
          '<strong>' + escapeHtml(group.service_type || group.service_code || 'Service') + '</strong>' +
          '<p>Kept <code>' + escapeHtml(group.kept_rate_id || '') + '</code>; hidden duplicate(s) <code>' +
          escapeHtml(duplicateIds || 'none') + '</code>.</p>' +
          '<dl><div><dt>Carrier</dt><dd>' + escapeHtml(group.carrier_nickname || group.carrier_code || '') + '</dd></div>' +
          '<div><dt>Service</dt><dd>' + escapeHtml(group.service_code || '') + '</dd></div>' +
          '<div><dt>Package</dt><dd>' + escapeHtml(group.package_type || '') + '</dd></div>' +
          '<div><dt>Total</dt><dd>' + money(group.total_amount) + '</dd></div></dl>' +
          duplicateRows +
        '</div>';
      }).join('');
    }

    target.innerHTML = html;
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

  function carrierBrand(rate) {
    var text = String([
      rate.carrier_code || '',
      rate.carrier_nickname || '',
      rate.carrier_friendly_name || '',
      rate.service_code || '',
      rate.service_type || ''
    ].join(' ')).toLowerCase();

    if (text.indexOf('usps') !== -1 || text.indexOf('stamps') !== -1) {
      return { slug: 'usps', label: 'USPS' };
    }
    if (text.indexOf('fedex') !== -1 || text.indexOf('federal express') !== -1) {
      return { slug: 'fedex', label: 'FedEx' };
    }
    if (text.indexOf('ups') !== -1 || text.indexOf('united parcel') !== -1) {
      return { slug: 'ups', label: 'UPS' };
    }
    if (text.indexOf('dhl') !== -1) {
      return { slug: 'dhl', label: 'DHL' };
    }

    return { slug: 'generic', label: 'Ship' };
  }

  function carrierLogoHtml(brand) {
    var logos = (window.FFLHubShipStation && window.FFLHubShipStation.carrierLogos) || {};
    var url = logos[brand.slug] || '';
    if (!url) {
      return escapeHtml(brand.label);
    }

    return '<img src="' + escapeHtml(url) + '" alt="' + escapeHtml(brand.label) + '" loading="lazy" />' +
      '<span class="screen-reader-text">' + escapeHtml(brand.label) + '</span>';
  }

  function showDebugFields(panel) {
    return !!(context(panel).settings && context(panel).settings.show_debug_fields);
  }

  function transitLabel(rate) {
    var days = Number(rate.delivery_days || 0);
    if (days > 0 && days < 9999) {
      return days === 1 ? '1 day' : days + ' days';
    }
    return 'Transit TBD';
  }

  function rateCostBreakdown(rate) {
    var parts = [
      ['Base', rate.shipping_amount],
      ['Conf.', rate.confirmation_amount],
      ['Ins.', rate.insurance_amount],
      ['Other', rate.other_amount]
    ];

    return parts.map(function (part) {
      return '<div><dt>' + escapeHtml(part[0]) + '</dt><dd>' + money(part[1]) + '</dd></div>';
    }).join('');
  }

  function packageTypeLabel(value) {
    var code = rateKeyText(value);
    var labels = {
      package: 'Package',
      thick_envelope: 'Thick Envelope',
      large_envelope_or_flat: 'Large Envelope / Flat',
      letter: 'Letter',
      large_package: 'Large Package',
      flat_rate_envelope: 'Flat Rate Envelope',
      flat_rate_legal_envelope: 'Legal Flat Rate Envelope',
      flat_rate_padded_envelope: 'Padded Flat Rate Envelope',
      small_flat_rate_box: 'Small Flat Rate Box',
      medium_flat_rate_box: 'Medium Flat Rate Box',
      large_flat_rate_box: 'Large Flat Rate Box',
      regional_rate_box_a: 'Regional Rate Box A',
      regional_rate_box_b: 'Regional Rate Box B'
    };

    return labels[code] || String(value || '').replace(/_/g, ' ').trim();
  }

  function renderRateCard(rate, cheapest, fastestDays, debug) {
    var brand = carrierBrand(rate);
    var badges = [];
    if (Number(rate.total_amount || 0) === cheapest) badges.push('<span class="fflhub-ss-badge">Cheapest</span>');
    if (Number(rate.delivery_days || 9999) === fastestDays && fastestDays !== 9999) badges.push('<span class="fflhub-ss-badge">Fastest</span>');
    if (Number(rate.total_amount || 0) === cheapest && Number(rate.delivery_days || 9999) === fastestDays && fastestDays !== 9999) badges.push('<span class="fflhub-ss-badge">Best value</span>');
    if (rate.guaranteed_service) badges.push('<span class="fflhub-ss-badge is-guaranteed">Guaranteed</span>');

    var warnings = (rate.warning_messages || []).join('; ');
    var carrierName = !debug && brand.slug !== 'generic'
      ? brand.label
      : (rate.carrier_nickname || rate.carrier_friendly_name || rate.carrier_code || 'Carrier');
    var providerName = rate.provider_label || '';
    var serviceName = rate.service_type || rate.service_code || 'Service';
    var packageName = packageTypeLabel(rate.package_type);

    return '<label class="fflhub-ss-rate-card">' +
      '<input class="fflhub-ss-rate-radio" type="radio" name="fflhub_ss_rate" value="' + escapeHtml(rate.rate_id) + '" />' +
      '<span class="fflhub-ss-rate-select-dot" aria-hidden="true"></span>' +
      '<div class="fflhub-ss-rate-carrier-block">' +
        '<span class="fflhub-ss-carrier-mark is-' + escapeHtml(brand.slug) + (brand.slug !== 'generic' ? ' has-logo' : '') + '">' + carrierLogoHtml(brand) + '</span>' +
        '<div><strong>' + escapeHtml(carrierName) + '</strong>' +
        (providerName ? '<small>' + escapeHtml(providerName) + '</small>' : '') +
        (debug ? '<code>' + escapeHtml(rate.carrier_code || '') + '</code>' : '') + '</div>' +
      '</div>' +
      '<div class="fflhub-ss-rate-service-block">' +
        '<strong>' + escapeHtml(serviceName) + '</strong>' +
        (packageName ? '<small>' + escapeHtml(packageName) + '</small>' : '') +
        (debug ? '<code>' + escapeHtml(rate.service_code || '') + '</code>' : '') +
        '<div class="fflhub-ss-rate-badges">' + badges.join(' ') + '</div>' +
      '</div>' +
      '<div class="fflhub-ss-rate-transit-block">' +
        '<span>' + escapeHtml(transitLabel(rate)) + '</span>' +
        (debug ? '<small>' + escapeHtml(rate.estimated_delivery_date || 'No delivery date') + '</small>' : '') +
      '</div>' +
      '<div class="fflhub-ss-rate-price-block">' +
        '<strong>' + money(rate.total_amount) + '</strong>' +
        '<span>Total</span>' +
        (debug ? '<dl>' + rateCostBreakdown(rate) + '</dl>' : '') +
      '</div>' +
      (warnings ? '<div class="fflhub-ss-rate-warning">' + escapeHtml(warnings) + '</div>' : '') +
    '</label>';
  }

  function rateProviderId(rate) {
    var provider = rateKeyText(rate && rate.provider_id ? rate.provider_id : '');
    if (provider) {
      return provider;
    }

    var label = rateKeyText(rate && rate.provider_label ? rate.provider_label : '');
    if (label.indexOf('easy') !== -1) {
      return 'easypost';
    }

    return 'shipstation';
  }

  function rateProviderLabel(providerId, rates) {
    for (var i = 0; i < (rates || []).length; i++) {
      if (rateProviderId(rates[i]) === providerId && rates[i].provider_label) {
        return String(rates[i].provider_label);
      }
    }

    if (providerId === 'easypost') {
      return 'EasyPost';
    }

    if (providerId === 'shipstation') {
      return 'ShipStation';
    }

    return providerId ? providerId.replace(/_/g, ' ') : 'Provider';
  }

  function providerRateGroups(rates) {
    var order = ['shipstation', 'easypost'];
    var groups = {};

    (rates || []).forEach(function (rate) {
      var providerId = rateProviderId(rate);
      if (!groups[providerId]) {
        groups[providerId] = [];
        if (order.indexOf(providerId) === -1) {
          order.push(providerId);
        }
      }

      groups[providerId].push(rate);
    });

    return order.filter(function (providerId) {
      return groups[providerId] && groups[providerId].length;
    }).map(function (providerId) {
      return {
        id: providerId,
        label: rateProviderLabel(providerId, groups[providerId]),
        rates: groups[providerId]
      };
    });
  }

  function selectRateCard(target, rateId) {
    target.querySelectorAll('.fflhub-ss-rate-card').forEach(function (card) {
      var radio = card.querySelector('input[name="fflhub_ss_rate"]');
      var selected = radio && String(radio.value || '') === String(rateId || '');
      if (radio) {
        radio.checked = selected;
      }
      card.classList.toggle('is-selected', !!selected);
    });
  }

  function rateKeyText(value) {
    return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
  }

  function rateKeyMoney(value) {
    return Number(value || 0).toFixed(4);
  }

  function displayRateKey(rate) {
    return JSON.stringify({
      provider_id: rateKeyText(rate.provider_id || ''),
      carrier_code: rateKeyText(rate.carrier_code),
      carrier_name: rateKeyText(rate.carrier_nickname || rate.carrier_friendly_name),
      service_code: rateKeyText(rate.service_code),
      service_type: rateKeyText(rate.service_type),
      package_type: rateKeyText(rate.package_type),
      shipping_amount: rateKeyMoney(rate.shipping_amount),
      insurance_amount: rateKeyMoney(rate.insurance_amount),
      confirmation_amount: rateKeyMoney(rate.confirmation_amount),
      other_amount: rateKeyMoney(rate.other_amount),
      total_amount: rateKeyMoney(rate.total_amount),
      currency: rateKeyText(rate.currency),
      delivery_days: rate.delivery_days || null,
      estimated_delivery_date: String(rate.estimated_delivery_date || '')
    });
  }

  function selectedPackageCodes(panel) {
    var codes = [];
    readPackages(panel).forEach(function (pkg) {
      var code = rateKeyText(pkg.package_code || '');
      if (code && codes.indexOf(code) === -1) {
        codes.push(code);
      }
    });
    return codes;
  }

  function filterRatesForSelectedPackages(panel, rates, invalidRates) {
    var codes = selectedPackageCodes(panel);
    if (!codes.length) {
      return rates || [];
    }

    return (rates || []).filter(function (rate) {
      var packageType = rateKeyText(rate.package_type || '');
      if (!packageType || codes.indexOf(packageType) !== -1) {
        return true;
      }

      invalidRates.push({
        carrier_code: rate.carrier_code || '',
        carrier_nickname: rate.carrier_nickname || '',
        service_code: rate.service_code || '',
        service_type: rate.service_type || '',
        error_messages: [
          'Hidden because package type "' + (rate.package_type || '') + '" does not match selected package code(s): ' + codes.join(', ') + '.'
        ]
      });
      return false;
    });
  }

  function dedupeRatesForDisplay(rates) {
    var seen = {};
    return (rates || []).filter(function (rate) {
      var key = displayRateKey(rate || {});
      if (seen[key]) {
        return false;
      }
      seen[key] = true;
      return true;
    });
  }

  function renderRates(panel, rates, invalidRates, duplicateRateGroups) {
    invalidRates = invalidRates || [];
    rates = filterRatesForSelectedPackages(panel, rates || [], invalidRates);
    rates = dedupeRatesForDisplay(rates || []);
    var target = panel.querySelector('.fflhub-ss-rates');
    var currentSort = 'total';
    var currentCarrier = '';
    var currentMaxDays = '';
    var carrierOptions = {};
    var debug = showDebugFields(panel);
    panel.fflhubShipStationRatesById = {};
    (rates || []).forEach(function (rate) {
      var key = rate.carrier_id || rate.carrier_code || '';
      if (key) {
        carrierOptions[key] = rate.carrier_nickname || rate.carrier_friendly_name || rate.carrier_code || key;
      }
      if (rate.rate_id) {
        panel.fflhubShipStationRatesById[String(rate.rate_id)] = rate;
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
      var cheapestRate = rows.reduce(function (best, rate) {
        if (!best || Number(rate.total_amount || 0) < Number(best.total_amount || 0)) {
          return rate;
        }
        return best;
      }, null);

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
        html += '<div class="fflhub-ss-provider-rate-grid">';
        providerRateGroups(rows).forEach(function (group) {
          var providerCheapest = Math.min.apply(null, group.rates.map(function (rate) {
            return Number(rate.total_amount || 0);
          }));

          html += '<section class="fflhub-ss-provider-rate-column is-' + escapeHtml(group.id) + '">' +
            '<div class="fflhub-ss-provider-rate-heading">' +
              '<div><strong>' + escapeHtml(group.label) + '</strong><span>' + group.rates.length + ' rate' + (group.rates.length === 1 ? '' : 's') + '</span></div>' +
              '<em>Best ' + money(providerCheapest) + '</em>' +
            '</div>' +
            '<div class="fflhub-ss-rate-list">';
          group.rates.forEach(function (rate) {
            html += renderRateCard(rate, cheapest, fastestDays, debug);
          });
          html += '</div></section>';
        });
        html += '</div><button type="button" class="button button-primary fflhub-ss-purchase">Purchase Selected Label</button>';
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
      target.querySelectorAll('input[name="fflhub_ss_rate"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
          selectRateCard(target, radio.value);
        });
      });

      if (cheapestRate && cheapestRate.rate_id) {
        selectRateCard(target, cheapestRate.rate_id);
      }
    }

    paint();
    renderDiagnostics(panel, invalidRates || [], duplicateRateGroups || []);
  }

  function addPackage(panel) {
    var list = panel.querySelector('.fflhub-ss-package-list');
    var first = list.querySelector('.fflhub-ss-package-row');
    if (!first) return;
    var copy = first.cloneNode(true);
    copy.querySelectorAll('input').forEach(function (input) {
      if (input.dataset.field === 'insured_value.amount') {
        input.value = '0';
      } else {
        input.value = '';
      }
    });
    copy.querySelectorAll('select[data-field="package_code"]').forEach(function (select) {
      select.value = 'package';
    });
    copy.querySelectorAll('[data-weight-role]').forEach(function (input) {
      input.value = '';
    });
    copy.querySelectorAll('[data-package-item-qty]').forEach(function (input) {
      input.value = '0';
    });
    copy.querySelectorAll('.fflhub-ss-package-preset').forEach(function (select) {
      select.value = '';
    });
    recalcPackageWeight(copy);
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
      panel.querySelectorAll('.fflhub-ss-package-row').forEach(rememberEnvelopeBaseHeight);
      syncPackageShapes(panel);

      panel.addEventListener('input', function (event) {
        if (event.target.closest('.fflhub-ss-rates')) {
          return;
        }

        if (event.target.matches('input,select')) {
          var row = event.target.closest('.fflhub-ss-package-row');
          if (row && event.target.matches('[data-weight-role="content"],[data-weight-role="package"]')) {
            recalcPackageWeight(row);
          }
          if (row && event.target.matches('[data-package-item-qty]')) {
            row.dataset.fflhubAutoPackedShape = '';
            syncPackageShapeForRow(panel, row);
          }
          if (row && event.target.matches('[data-field="dimensions.height"]')) {
            row.dataset.fflhubAutoPackedShape = '';
            rememberEnvelopeBaseHeight(row);
            syncPackageShapeForRow(panel, row);
          }
          invalidateRates(panel);
        }
      });

      panel.addEventListener('change', function (event) {
        if (event.target.matches('.fflhub-ss-package-preset')) {
          applyPackagePreset(panel, event.target);
        }

        var row = event.target.closest('.fflhub-ss-package-row');
        if (row && event.target.matches('[data-field="package_code"]')) {
          row.dataset.fflhubAutoPackedShape = '';
          rememberEnvelopeBaseHeight(row);
          syncPackageShapeForRow(panel, row);
          invalidateRates(panel);
        }
      });

      panel.addEventListener('click', function (event) {
        if (event.target.matches('.fflhub-ss-add-package')) {
          event.preventDefault();
          addPackage(panel);
        }

        if (event.target.matches('.fflhub-ss-auto-pack')) {
          event.preventDefault();
          invalidateRates(panel);
          setLoading(panel, true);
          setMessage(panel, 'Running box packer...', '');
          request(panel, '/auto-pack', {})
            .then(function (data) { applyPackingPlan(panel, data); })
            .catch(function (error) { setMessage(panel, error.message, 'error'); })
            .finally(function () { setLoading(panel, false); });
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
            .then(function (data) { renderValidationResult(panel, data); })
            .catch(function (error) { setMessage(panel, error.message, 'error'); })
            .finally(function () { setLoading(panel, false); });
        }

        if (event.target.matches('.fflhub-ss-apply-address')) {
          event.preventDefault();
          if (panel.fflhubShipStationValidatedAddress) {
            writeAddress(panel, 'destination', panel.fflhubShipStationValidatedAddress);
            invalidateRates(panel);
            setMessage(panel, 'Suggested address applied. Refresh rates before purchasing.', 'success');
          }
        }

        if (event.target.matches('.fflhub-ss-get-rates')) {
          event.preventDefault();
          invalidateRates(panel);
          var packageErrors = packageAssignmentErrors(panel);
          if (packageErrors.length) {
            setMessage(panel, packageErrors.join(' '), 'error');
            return;
          }
          setLoading(panel, true);
          setMessage(panel, 'Requesting shipping rates...', '');
          request(panel, '/rates', buildPayload(panel))
            .then(function (data) {
              panel.dataset.shipmentHash = data.shipment_hash || '';
              renderRates(panel, data.rates || [], data.invalid_rates || [], data.duplicate_rate_groups || []);
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
          var row = selected.closest('.fflhub-ss-rate-card');
          if (!window.confirm('Purchase this shipping label?\n\n' + (row ? row.innerText : selected.value))) {
            return;
          }
          setLoading(panel, true);
          var rateId = selected.value;
          var selectedRate = panel.fflhubShipStationRatesById && panel.fflhubShipStationRatesById[String(rateId)]
            ? panel.fflhubShipStationRatesById[String(rateId)]
            : null;
          request(panel, '/purchase', {
            rate_id: rateId,
            shipment_hash: panel.dataset.shipmentHash || '',
            shipment: buildPayload(panel),
            selected_rate: selectedRate
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
