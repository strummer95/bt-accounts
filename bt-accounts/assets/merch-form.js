/* BT Accounts — bulk / on-demand order form for merch-store accounts.
 *
 * Bulk:      one block per product, a size grid per colour. Leave blank what you don't want.
 * On demand: one line per item the customer bought — product, colour, design, size, qty.
 *
 * Every field sits under line[i][...] with an explicit index, so removing a
 * line can never shift its sizes onto another. Prices shown here are only a
 * running total; the server prices the order from the product list. */
(function () {
  'use strict';
  var form = document.getElementById('btaMerchForm');
  var dataEl = document.getElementById('btaMerchData');
  if (!form || !dataEl) return;

  var D = JSON.parse(dataEl.textContent || '{}');
  var products = D.products || [];
  var byId = {};
  products.forEach(function (p) { byId[p.id] = p; });
  var wrap = document.getElementById('btaMerchLines');
  var totalEl = document.getElementById('btaMerchTotal');
  var idx = 0;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function money(n) { return '$' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

  function artSelect(p, name, chosen, optional) {
    if (!p.art.length) return '';
    if (p.art.length === 1) {
      return '<input type="hidden" name="' + name + '" value="' + p.art[0].id + '">'
        + '<div class="bta-sub">Design: ' + esc(p.art[0].name) + '</div>';
    }
    return '<select class="bta-input" name="' + name + '"' + (optional ? '' : ' required') + '><option value="">Design…</option>'
      + p.art.map(function (a) {
        return '<option value="' + a.id + '"' + (String(chosen) === String(a.id) ? ' selected' : '') + '>' + esc(a.name) + '</option>';
      }).join('') + '</select>';
  }

  /* ── Bulk ── */

  function bulkRow(p, color, saved) {
    var i = idx++;
    var n = 'line[' + i + ']';
    var sizes = p.sizes.map(function (s) {
      var q = saved && saved.sizes && saved.sizes[s] ? saved.sizes[s] : '';
      return '<label class="bta-size"><span>' + esc(s) + '</span><input type="number" min="0" inputmode="numeric" name="' + n + '[sizes][' + esc(s) + ']" value="' + esc(q) + '" data-size="' + esc(s) + '"></label>';
    }).join('');
    return '<div class="bta-bulkrow" data-product="' + p.id + '">'
      + '<input type="hidden" name="' + n + '[product]" value="' + p.id + '">'
      + '<input type="hidden" name="' + n + '[color]" value="' + esc(color) + '">'
      + '<div class="bta-bulkrow-head"><span class="bta-colorchip">' + esc(color || 'Colour') + '</span>'
      + (p.art.length > 1 ? artSelect(p, n + '[art]', saved ? saved.art : '', true) : (p.art.length ? '<input type="hidden" name="' + n + '[art]" value="' + p.art[0].id + '">' : ''))
      + '<span class="bta-line-total"></span></div>'
      + '<div class="bta-sizes">' + sizes + '</div></div>';
  }

  function renderBulk() {
    var saved = D.lines || [];
    wrap.innerHTML = products.map(function (p) {
      var colors = p.colors.length ? p.colors : [''];
      var rows = colors.map(function (c) {
        var s = null;
        saved.forEach(function (l) { if (l.product === p.id && l.color === c) s = l; });
        return bulkRow(p, c, s);
      }).join('');
      return '<div class="bta-bulkprod">'
        + '<div class="bta-bulkprod-head">' + (p.img ? '<img src="' + esc(p.img) + '" alt="">' : '')
        + '<div><div class="bta-prod-name">' + esc(p.name) + '</div><div class="bta-sub">' + esc(p.brand) + priceText(p) + '</div>'
        + (p.art.length === 1 ? '<div class="bta-sub">Design: ' + esc(p.art[0].name) + '</div>' : '') + '</div></div>'
        + rows + '</div>';
    }).join('');
  }

  function priceText(p) {
    var vals = Object.keys(p.prices).map(function (k) { return p.prices[k]; }).filter(function (v) { return v !== null; });
    if (!vals.length) return ' · price set by the shop';
    var lo = Math.min.apply(null, vals), hi = Math.max.apply(null, vals);
    return ' · ' + money(lo) + (hi > lo ? '–' + money(hi) : '') + ' each';
  }

  /* ── On demand ── */

  function odLine(saved) {
    var i = idx++;
    var n = 'line[' + i + ']';
    var el = document.createElement('div');
    el.className = 'bta-odline';
    el.dataset.n = n;
    el.innerHTML = '<div class="bta-field"><label class="bta-label">Item</label><select class="bta-input bta-od-product" name="' + n + '[product]" required>'
      + '<option value="">Choose…</option>' + products.map(function (p) {
        return '<option value="' + p.id + '">' + esc(p.name) + (p.brand ? ' (' + esc(p.brand) + ')' : '') + '</option>';
      }).join('') + '</select></div>'
      + '<div class="bta-field bta-od-color"></div><div class="bta-field bta-od-art"></div>'
      + '<div class="bta-field bta-od-size"></div>'
      + '<div class="bta-field"><label class="bta-label">Qty</label><input class="bta-input bta-od-qty" type="number" min="1" value="1" inputmode="numeric"></div>'
      + '<span class="bta-line-total"></span>'
      + '<button type="button" class="bta-x" aria-label="Remove">&times;</button>';
    wrap.appendChild(el);
    if (saved && byId[saved.product]) {
      el.querySelector('.bta-od-product').value = saved.product;
      fillOd(el, saved);
    }
    return el;
  }

  function fillOd(el, saved) {
    var p = byId[el.querySelector('.bta-od-product').value];
    var n = el.dataset.n;
    var c = el.querySelector('.bta-od-color'), a = el.querySelector('.bta-od-art'), s = el.querySelector('.bta-od-size');
    if (!p) { c.innerHTML = a.innerHTML = s.innerHTML = ''; return; }
    c.innerHTML = p.colors.length ? '<label class="bta-label">Colour</label><select class="bta-input" name="' + n + '[color]" required>'
      + (p.colors.length > 1 ? '<option value="">Choose…</option>' : '')
      + p.colors.map(function (x) { return '<option' + (saved && saved.color === x ? ' selected' : '') + '>' + esc(x) + '</option>'; }).join('') + '</select>' : '';
    a.innerHTML = p.art.length ? '<label class="bta-label">Design</label>' + artSelect(p, n + '[art]', saved ? saved.art : '') : '';
    var sz = saved && saved.sizes ? Object.keys(saved.sizes)[0] : '';
    s.innerHTML = '<label class="bta-label">Size</label><select class="bta-input bta-od-sizesel" required>'
      + (p.sizes.length > 1 ? '<option value="">Choose…</option>' : '')
      + p.sizes.map(function (x) { return '<option' + (x === sz ? ' selected' : '') + '>' + esc(x) + '</option>'; }).join('') + '</select>';
    if (saved && sz) el.querySelector('.bta-od-qty').value = saved.sizes[sz];
  }

  /* The size and qty pickers write one line[i][sizes][SIZE] field on submit. */
  function syncOd(el) {
    var old = el.querySelector('input.bta-od-hidden');
    if (old) old.remove();
    var sel = el.querySelector('.bta-od-sizesel');
    var q = parseInt(el.querySelector('.bta-od-qty').value, 10) || 0;
    if (!sel || !sel.value || q < 1) return;
    var h = document.createElement('input');
    h.type = 'hidden';
    h.className = 'bta-od-hidden';
    h.name = el.dataset.n + '[sizes][' + sel.value + ']';
    h.value = q;
    el.appendChild(h);
  }

  /* ── Totals ── */

  function recalc() {
    var total = 0, pieces = 0, unpriced = false;
    if (D.type === 'bulk') {
      wrap.querySelectorAll('.bta-bulkrow').forEach(function (row) {
        var p = byId[row.dataset.product], line = 0, n = 0;
        row.querySelectorAll('input[data-size]').forEach(function (inp) {
          var q = parseInt(inp.value, 10) || 0;
          if (q < 1) return;
          n += q;
          var each = p.prices[inp.dataset.size];
          if (each === null || each === undefined) unpriced = true; else line += each * q;
        });
        pieces += n; total += line;
        row.querySelector('.bta-line-total').textContent = n ? n + ' pcs' + (line ? ' · ' + money(line) : '') : '';
      });
    } else {
      wrap.querySelectorAll('.bta-odline').forEach(function (el) {
        syncOd(el);
        var p = byId[el.querySelector('.bta-od-product').value];
        var sel = el.querySelector('.bta-od-sizesel');
        var q = parseInt(el.querySelector('.bta-od-qty').value, 10) || 0;
        var out = el.querySelector('.bta-line-total');
        if (!p || !sel || !sel.value || q < 1) { out.textContent = ''; return; }
        var each = p.prices[sel.value];
        pieces += q;
        if (each === null || each === undefined) { unpriced = true; out.textContent = 'priced by shop'; }
        else { total += each * q; out.textContent = money(each * q); }
      });
    }
    totalEl.innerHTML = pieces
      ? '<strong>' + pieces + ' piece' + (pieces === 1 ? '' : 's') + '</strong>' + (total ? ' · ' + money(total) : '')
        + (unpriced ? ' <span class="bta-sub">+ items the shop will price</span>' : '') + ' <span class="bta-sub">plus shipping</span>'
      : '';
  }

  if (D.type === 'bulk') {
    renderBulk();
  } else {
    (D.lines && D.lines.length ? D.lines : [null]).forEach(odLine);
    document.getElementById('btaMerchAdd').addEventListener('click', function () { odLine(null); recalc(); });
    wrap.addEventListener('change', function (e) {
      if (e.target.classList.contains('bta-od-product')) fillOd(e.target.closest('.bta-odline'), null);
    });
    wrap.addEventListener('click', function (e) {
      if (!e.target.classList.contains('bta-x')) return;
      var lines = wrap.querySelectorAll('.bta-odline');
      if (lines.length > 1) e.target.closest('.bta-odline').remove();
      recalc();
    });
  }
  wrap.addEventListener('input', recalc);
  wrap.addEventListener('change', recalc);
  form.addEventListener('submit', recalc);
  recalc();
})();
