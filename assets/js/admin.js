/**
 * LeadBridge – Admin JavaScript
 * Requires: jQuery (bundled with WordPress admin)
 */
/* global LeadBridge, jQuery */
(function ($) {
  'use strict';

  // ── Endpoint counter (for unique index on new endpoints) ──────────────────
  var epCount = $('#lb-endpoints-container .lb-endpoint-card').length;

  // =========================================================================
  // Endpoint: Toggle collapse
  // =========================================================================
  $(document).on('click', '.lb-endpoint-header', function (e) {
    if ($(e.target).is('button, select, input')) return;
    $(this).closest('.lb-endpoint-card').toggleClass('lb-collapsed');
  });

  $(document).on('click', '.lb-toggle-endpoint', function (e) {
    e.stopPropagation();
    $(this).closest('.lb-endpoint-card').toggleClass('lb-collapsed');
  });

  // =========================================================================
  // Endpoint: Add new endpoint
  // =========================================================================
  $(document).on('click', '.lb-add-endpoint', function () {
    var template = document.getElementById('lb-ep-template');
    if (!template) return;

    var html = template.innerHTML.replace(/__IDX__/g, epCount).replace(/__RIDX__/g, 0);
    var $ep  = $(html);

    $('#lb-no-endpoints').hide();
    $('#lb-endpoints-container').append($ep);
    $ep.find('.lb-endpoint-body').show();

    // Update data-idx
    $(this).attr('data-idx', ++epCount);
  });

  // =========================================================================
  // Endpoint: Remove
  // =========================================================================
  $(document).on('click', '.lb-remove-endpoint', function (e) {
    e.stopPropagation();
    if (!confirm(LeadBridge.strings.confirmDelete)) return;
    $(this).closest('.lb-endpoint-card').remove();
    if ($('.lb-endpoint-card').length === 0) {
      $('#lb-no-endpoints').show();
    }
  });

  // =========================================================================
  // Endpoint: Type selector → show/hide sections
  // =========================================================================
  $(document).on('change', '.lb-ep-type-select', function () {
    var $card = $(this).closest('.lb-endpoint-card');
    var type  = $(this).val();

    $card.attr('data-type', type);
    $card.find('.lb-endpoint-type-badge')
         .removeClass('lb-ep-badge--dashboard lb-ep-badge--bridge lb-ep-badge--culligan')
         .addClass('lb-ep-badge--' + type)
         .text({ dashboard: 'Dashboard Webylead', bridge: 'Applicatif Webylead', culligan: 'Culligan / Pardot' }[type] || type);

    $card.find('.lb-section--dashboard').toggle(type === 'dashboard');
    $card.find('.lb-section--bridge').toggle(type === 'bridge');
    $card.find('.lb-section--culligan').toggle(type === 'culligan');
  });

  // =========================================================================
  // Endpoint: Label live preview
  // =========================================================================
  $(document).on('input', '.lb-ep-label-input', function () {
    $(this).closest('.lb-endpoint-card').find('.lb-endpoint-label-preview').text($(this).val());
  });

  // =========================================================================
  // Field mapping rows – Dashboard
  // =========================================================================
  $(document).on('click', '.lb-add-mapping', function () {
    var $btn    = $(this);
    var idx     = $btn.attr('data-idx');
    var ridx    = parseInt($btn.attr('data-ridx'), 10);
    var tmpl    = document.getElementById('lb-mapping-row-template');
    var html    = tmpl.innerHTML
      .replace(/__IDX__/g, idx)
      .replace(/__RIDX__/g, ridx);
    $btn.prev('.lb-mapping-table').find('tbody').append(html);
    $btn.attr('data-ridx', ridx + 1);
  });

  // Fixed field rows
  $(document).on('click', '.lb-add-fixed', function () {
    var $btn    = $(this);
    var idx     = $btn.attr('data-idx');
    var ridx    = parseInt($btn.attr('data-ridx'), 10);
    var tmpl    = document.getElementById('lb-fixed-row-template');
    var html    = tmpl.innerHTML
      .replace(/__IDX__/g, idx)
      .replace(/__RIDX__/g, ridx);
    $btn.prev('.lb-fixed-table').find('tbody').append(html);
    $btn.attr('data-ridx', ridx + 1);
  });

  // Culligan field rows
  $(document).on('click', '.lb-add-culligan', function () {
    var $btn    = $(this);
    var idx     = $btn.attr('data-idx');
    var ridx    = parseInt($btn.attr('data-ridx'), 10);
    var tmpl    = document.getElementById('lb-culligan-row-template');
    var html    = tmpl.innerHTML
      .replace(/__IDX__/g, idx)
      .replace(/__RIDX__/g, ridx);
    $btn.prev('.lb-culligan-table').find('tbody').append(html);
    $btn.attr('data-ridx', ridx + 1);
  });

  // Remove any field table row
  $(document).on('click', '.lb-remove-row', function () {
    $(this).closest('tr.lb-row').remove();
  });

  // =========================================================================
  // Template loader
  // =========================================================================
  $('#lb-load-tpl').on('click', function () {
    var key = $('#lb-tpl-select').val();
    if (!key) return;

    if (!confirm(LeadBridge.strings.loadTplConfirm)) return;

    $.post(LeadBridge.ajaxurl, {
      action:   'lb_load_template',
      nonce:    LeadBridge.nonce,
      template: key
    }, function (resp) {
      if (!resp.success) {
        alert(resp.data || 'Erreur lors du chargement du template.');
        return;
      }
      var tpl = resp.data;

      // Set name (only if currently empty)
      if (!$('#lb-name').val()) {
        $('#lb-name').val(tpl.name || '');
      }

      // Clear existing endpoints
      $('#lb-endpoints-container').empty();
      $('#lb-no-endpoints').hide();
      epCount = 0;

      // Build endpoints from template
      $.each(tpl.endpoints || [], function (i, ep) {
        appendEndpointFromData(i, ep);
        epCount = i + 1;
      });

      if (epCount === 0) {
        $('#lb-no-endpoints').show();
      }
    });
  });

  /**
   * Build and append an endpoint card from a data object (template or saved config).
   */
  function appendEndpointFromData(idx, ep) {
    var tmpl = document.getElementById('lb-ep-template');
    if (!tmpl) return;

    var html = tmpl.innerHTML.replace(/__IDX__/g, idx).replace(/__RIDX__/g, 0);
    var $ep  = $(html);

    // Set type
    $ep.find('.lb-ep-type-select').val(ep.type || 'dashboard').trigger('change');
    $ep.attr('data-type', ep.type || 'dashboard');

    // Set label, url, enabled
    $ep.find('[name*="[label]"]').val(ep.label || '').trigger('input');
    $ep.find('[name*="[url]"]').val(ep.url || '');
    $ep.find('[name*="[enabled]"]').prop('checked', ep.enabled !== false);

    // Populate mapping rows (dashboard)
    if (ep.mapping && ep.type === 'dashboard') {
      var $tbody = $ep.find('.lb-mapping-rows');
      $tbody.empty();
      var mapRidx = 0;
      $.each(ep.mapping, function (slug, label) {
        appendMappingRow($tbody, idx, mapRidx, slug, label);
        mapRidx++;
      });
      $ep.find('.lb-add-mapping').attr('data-ridx', mapRidx);
    }

    // Populate bridge slugs
    if (ep.slugs && ep.type === 'bridge') {
      $ep.find('.lb-bridge-slugs').val(ep.slugs);
    }

    // Populate culligan rows
    if (ep.fields && ep.type === 'culligan') {
      var $ctbody = $ep.find('.lb-culligan-rows');
      $ctbody.empty();
      var cRidx = 0;
      $.each(ep.fields, function (slug, role) {
        appendCulliganRow($ctbody, idx, cRidx, slug, role);
        cRidx++;
      });
      $ep.find('.lb-add-culligan').attr('data-ridx', cRidx);

      if (ep.question_tpl) {
        $ep.find('[name*="[question_tpl]"]').val(ep.question_tpl);
      }
    }

    // Populate fixed fields (all types)
    if (ep.fixed) {
      var $ftbody = $ep.find('.lb-fixed-rows');
      $ftbody.empty();
      var fRidx = 0;
      $.each(ep.fixed, function (key, value) {
        appendFixedRow($ftbody, idx, fRidx, key, value);
        fRidx++;
      });
      $ep.find('.lb-add-fixed').attr('data-ridx', fRidx);
    }

    $('#lb-endpoints-container').append($ep);
  }

  function appendMappingRow($tbody, idx, ridx, slug, label) {
    var prefix = 'lb_form[endpoints][' + idx + '][mapping_rows][' + ridx + ']';
    var row = '<tr class="lb-row">'
      + '<td><input type="text" name="' + prefix + '[slug]" value="' + escAttr(slug) + '" class="regular-text" placeholder="ex: nom"></td>'
      + '<td><input type="text" name="' + prefix + '[label]" value="' + escAttr(label) + '" class="regular-text" placeholder="ex: Nom"></td>'
      + '<td><button type="button" class="lb-remove-row lb-btn-icon" title="Supprimer">✕</button></td>'
      + '</tr>';
    $tbody.append(row);
  }

  function appendFixedRow($tbody, idx, ridx, key, value) {
    var prefix = 'lb_form[endpoints][' + idx + '][fixed_rows][' + ridx + ']';
    var row = '<tr class="lb-row">'
      + '<td><input type="text" name="' + prefix + '[key]" value="' + escAttr(key) + '" class="regular-text" placeholder="ex: utm_source"></td>'
      + '<td><input type="text" name="' + prefix + '[value]" value="' + escAttr(value) + '" class="regular-text" placeholder="ex: Affiliation"></td>'
      + '<td><button type="button" class="lb-remove-row lb-btn-icon" title="Supprimer">✕</button></td>'
      + '</tr>';
    $tbody.append(row);
  }

  function appendCulliganRow($tbody, idx, ridx, slug, role) {
    var prefix = 'lb_form[endpoints][' + idx + '][culligan_rows][' + ridx + ']';
    var roles  = ['nom','prenom','email','telephone','societe','cp','salaries','visiteurs','delai'];
    var opts   = roles.map(function (r) {
      return '<option value="' + r + '"' + (r === role ? ' selected' : '') + '>' + r + '</option>';
    }).join('');
    var row = '<tr class="lb-row">'
      + '<td><input type="text" name="' + prefix + '[slug]" value="' + escAttr(slug) + '" class="regular-text" placeholder="ex: nom"></td>'
      + '<td><select name="' + prefix + '[role]">' + opts + '</select></td>'
      + '<td><button type="button" class="lb-remove-row lb-btn-icon" title="Supprimer">✕</button></td>'
      + '</tr>';
    $tbody.append(row);
  }

  function escAttr(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  // =========================================================================
  // Endpoint: Test connection
  // =========================================================================
  $(document).on('click', '.lb-test-btn', function () {
    var $btn    = $(this);
    var $card   = $btn.closest('.lb-endpoint-card');
    var url     = $card.find('[name*="[url]"]').val();
    var type    = $card.find('.lb-ep-type-select').val();
    var $result = $card.find('.lb-test-result');

    if (!url) {
      $result.text(LeadBridge.strings.noUrl).removeClass('ok fail');
      return;
    }

    $btn.prop('disabled', true);
    $result.text(LeadBridge.strings.testSending).removeClass('ok fail');

    $.post(LeadBridge.ajaxurl, {
      action: 'lb_test_endpoint',
      nonce:  LeadBridge.nonce,
      url:    url,
      type:   type
    }, function (resp) {
      $btn.prop('disabled', false);
      if (resp.success && resp.data.ok) {
        $result.text(LeadBridge.strings.testOk + ' (HTTP ' + resp.data.code + ')').addClass('ok').removeClass('fail');
      } else {
        var code = (resp.data && resp.data.code) ? resp.data.code : '?';
        var err  = (resp.data && resp.data.body) ? ' – ' + resp.data.body.substring(0, 60) : '';
        $result.text(LeadBridge.strings.testFail + ' (HTTP ' + code + err + ')').addClass('fail').removeClass('ok');
      }
    }).fail(function () {
      $btn.prop('disabled', false);
      $result.text('Erreur réseau.').addClass('fail').removeClass('ok');
    });
  });

  // =========================================================================
  // Log viewer
  // =========================================================================

  // Expand/collapse log detail row
  $(document).on('click', '.lb-expand-btn', function () {
    var $btn     = $(this);
    var $details = $btn.next('.lb-log-details');
    var payload  = $btn.data('payload');
    var preview  = $btn.data('preview');

    if ($details.is(':visible')) {
      $details.hide();
      $btn.text($btn.text().replace('▴', '▾'));
    } else {
      if (!$details.html()) {
        var content = '=== PAYLOAD ===\n' + JSON.stringify(payload, null, 2);
        if (preview) {
          content += '\n\n=== RÉPONSE ===\n' + preview;
        }
        $details.text(content);
      }
      $details.show();
      $btn.text($btn.text().replace('▾', '▴'));
    }
  });

  // Refresh logs
  function refreshLogs(filter) {
    $.post(LeadBridge.ajaxurl, {
      action: 'lb_get_logs',
      nonce:  LeadBridge.nonce,
      filter: filter || ''
    }, function (resp) {
      if (!resp.success) return;
      var entries = resp.data;
      $('#lb-log-count').text(entries.length);
      renderLogTable(entries);
    });
  }

  function renderLogTable(entries) {
    if (!entries || !entries.length) {
      $('#lb-log-container').html('<p class="lb-muted lb-empty-log">Aucune entrée de log.</p>');
      return;
    }

    var rows = entries.map(function (e) {
      var ok      = e.ok;
      var rowCls  = ok ? 'lb-row--ok' : 'lb-row--fail';
      var badge   = ok ? '<span class="lb-badge lb-badge--ok">OK</span>' : '<span class="lb-badge lb-badge--fail">FAIL</span>';
      var ts      = (e.ts || '').substring(0, 19);
      var errHtml = e.error ? '<span class="lb-log-error">' + escAttr(e.error) + '</span>' : '';
      var payload = JSON.stringify(e.payload || {});
      var preview = escAttr(e.preview || '');

      return '<tr class="lb-log-row ' + rowCls + '">'
        + '<td class="lb-log-ts">' + escAttr(ts) + '</td>'
        + '<td>' + badge + '</td>'
        + '<td><code>' + escAttr(String(e.code || '–')) + '</code></td>'
        + '<td><code>#' + escAttr(String(e.form_id || '–')) + '</code></td>'
        + '<td>'
        + '<strong>' + escAttr(e.target || '') + '</strong> '
        + errHtml
        + ' <button type="button" class="lb-expand-btn button-link" data-payload=\'' + payload.replace(/'/g, '&#39;') + '\' data-preview="' + preview + '">Détails ▾</button>'
        + '<div class="lb-log-details" style="display:none"></div>'
        + '</td>'
        + '</tr>';
    }).join('');

    var table = '<table class="wp-list-table widefat fixed striped lb-table lb-log-table">'
      + '<thead><tr>'
      + '<th style="width:160px">Date</th>'
      + '<th style="width:80px">Statut</th>'
      + '<th style="width:80px">Code</th>'
      + '<th style="width:100px">Formulaire</th>'
      + '<th>Endpoint → Payload → Réponse</th>'
      + '</tr></thead>'
      + '<tbody>' + rows + '</tbody></table>';

    $('#lb-log-container').html(table);
  }

  $('#lb-refresh-logs').on('click', function () {
    refreshLogs($('#lb-filter').val());
  });

  $('#lb-filter').on('change', function () {
    refreshLogs($(this).val());
  });

  // Clear logs
  $('#lb-clear-logs').on('click', function () {
    if (!confirm(LeadBridge.strings.confirmClear)) return;

    $.post(LeadBridge.ajaxurl, {
      action: 'lb_clear_logs',
      nonce:  LeadBridge.nonce
    }, function (resp) {
      if (resp.success) {
        $('#lb-log-container').html('<p class="lb-muted lb-empty-log">Logs vidés.</p>');
        $('#lb-log-count').text('0');
      }
    });
  });

  // Auto-refresh
  var autoRefreshTimer = null;
  $('#lb-autorefresh').on('change', function () {
    if ($(this).is(':checked')) {
      autoRefreshTimer = setInterval(function () {
        refreshLogs($('#lb-filter').val());
      }, 10000);
    } else {
      clearInterval(autoRefreshTimer);
    }
  });

  // =========================================================================
  // Retry queue
  // =========================================================================

  // Run cron now
  $('#lb-run-cron').on('click', function () {
    var $btn = $(this).prop('disabled', true).text('Traitement…');
    $.post(LeadBridge.ajaxurl, {
      action: 'lb_run_cron',
      nonce:  LeadBridge.nonce
    }, function () {
      $btn.prop('disabled', false).text('Traiter maintenant');
      location.reload();
    });
  });

  // Retry individual item
  $(document).on('click', '.lb-retry-btn', function () {
    if (!confirm(LeadBridge.strings.confirmRetry)) return;
    var id  = $(this).data('id');
    var $tr = $('#lb-qi-' + id);

    $.post(LeadBridge.ajaxurl, {
      action:  'lb_retry_item',
      nonce:   LeadBridge.nonce,
      item_id: id
    }, function (resp) {
      if (resp.success) {
        $tr.fadeOut(400, function () { $(this).remove(); });
      }
    });
  });

  // Dismiss individual item
  $(document).on('click', '.lb-dismiss-btn', function () {
    if (!confirm(LeadBridge.strings.confirmDismiss)) return;
    var id  = $(this).data('id');
    var $tr = $('#lb-qi-' + id);

    $.post(LeadBridge.ajaxurl, {
      action:  'lb_dismiss_item',
      nonce:   LeadBridge.nonce,
      item_id: id
    }, function (resp) {
      if (resp.success) {
        $tr.fadeOut(400, function () { $(this).remove(); });
      }
    });
  });

  // Show error history for queue item
  $(document).on('click', '.lb-show-errors', function () {
    var errors = $(this).data('errors') || [];
    if (typeof errors === 'string') {
      try { errors = JSON.parse(errors); } catch (e) { errors = [errors]; }
    }
    alert('Historique des erreurs :\n\n' + errors.join('\n'));
  });

})(jQuery);
