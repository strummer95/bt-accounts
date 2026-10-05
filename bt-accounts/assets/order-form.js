/* BT Accounts — new order form.
   Line items, size grids, print/embroidery locations, live estimate,
   catalogue autocomplete, art-label wiring.
   Vanilla JS, no dependencies, all ids/classes bta- prefixed. */
(function () {
  'use strict';

  var cfgEl = document.getElementById('btaFormData');
  if (!cfgEl) return;
  var CFG = JSON.parse(cfgEl.textContent || '{}');

  var itemRows = document.getElementById('btaItemRows');
  var artRows  = document.getElementById('btaArtRows');
  var itemIdx  = 0;

  /* ── helpers ─────────────────────────────────────────────────────────── */

  function el(tag, cls, html) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (html !== undefined) n.innerHTML = html;
    return n;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /** Every logo name currently filled in, for the item art dropdowns. */
  function artLabels() {
    var out = [];
    artRows.querySelectorAll('input[name="art_label[]"]').forEach(function (i) {
      if (i.value.trim()) out.push(i.value.trim());
    });
    return out;
  }

  /** Repopulate every item's logo dropdown, preserving the current choice. */
  function refreshArtSelects() {
    var labels = artLabels();
    itemRows.querySelectorAll('select.bta-art-select').forEach(function (sel) {
      var cur = sel.value;
      sel.innerHTML = '<option value="">— none —</option>' +
        labels.map(function (l) { return '<option value="' + esc(l) + '">' + esc(l) + '</option>'; }).join('');
      if (labels.indexOf(cur) !== -1) sel.value = cur;
    });
  }

  /* ── size grid ───────────────────────────────────────────────────────── */

  function sizeGrid(i, sizes) {
    var html = '<div class="bta-sizes">';
    sizes.forEach(function (s) {
      html += '<label class="bta-size"><span>' + esc(s) + '</span>' +
              '<input type="number" min="0" step="1" inputmode="numeric" ' +
              'name="item[' + i + '][sizes][' + esc(s) + ']" placeholder="0"></label>';
    });
    html += '</div><div class="bta-sizetotal">Total: <strong data-total="' + i + '">0</strong></div>';
    return html;
  }

  function wireTotals(row, i) {
    var out = row.querySelector('[data-total="' + i + '"]');
    row.addEventListener('input', function (e) {
      if (e.target.type !== 'number') return;
      var n = 0;
      row.querySelectorAll('.bta-sizes input').forEach(function (inp) {
        n += parseInt(inp.value, 10) || 0;
      });
      out.textContent = n;
      row.classList.toggle('bta-row-empty', n === 0);
      estimate(row);
    });
  }

  function rowQty(row) {
    var n = 0;
    row.querySelectorAll('.bta-sizes input').forEach(function (inp) { n += parseInt(inp.value, 10) || 0; });
    return n;
  }

  /* ── locations ───────────────────────────────────────────────────────── */

  function opts(list, cur) {
    return list.map(function (o) {
      var v = Array.isArray(o) ? o[0] : o, t = Array.isArray(o) ? o[1] : o;
      return '<option value="' + esc(v) + '"' + (v === cur ? ' selected' : '') + '>' + esc(t) + '</option>';
    }).join('');
  }

  function isEmb(row) { return row.querySelector('.bta-deco').value === 'embroidery'; }

  function addLocation(row, i) {
    var wrap = row.querySelector('.bta-locs');
    var k = parseInt(wrap.getAttribute('data-next') || '0', 10);
    wrap.setAttribute('data-next', k + 1);
    var used = [];
    wrap.querySelectorAll('.bta-loc-place').forEach(function (s) { used.push(s.value); });
    var pick = CFG.placements.filter(function (p) { return used.indexOf(p) === -1; })[0] || CFG.placements[0];
    var embs = Object.keys(CFG.embTypes).map(function (key) { return [key, CFG.embTypes[key]]; });
    var base = 'item[' + i + '][loc][' + k + ']';

    var loc = el('div', 'bta-loc');
    loc.innerHTML =
      '<div class="bta-field"><label class="bta-label">Location</label>' +
        '<select class="bta-input bta-loc-place" name="' + base + '[placement]">' + opts(CFG.placements, pick) + '</select></div>' +
      '<div class="bta-field"><label class="bta-label">Logo</label>' +
        '<select class="bta-input bta-art-select" name="' + base + '[art]"></select></div>' +
      '<div class="bta-field bta-loc-emb"' + (isEmb(row) ? '' : ' hidden') + '><label class="bta-label">Embroidery</label>' +
        '<select class="bta-input bta-emb" name="' + base + '[emb]">' + opts(embs, 'logo') + '</select></div>' +
      '<button type="button" class="bta-x" aria-label="Remove location">&times;</button>';
    wrap.appendChild(loc);

    loc.querySelector('.bta-x').addEventListener('click', function () {
      if (wrap.children.length === 1) return;     // a line always has one location
      loc.remove();
      estimate(row);
    });
    refreshArtSelects();
    estimate(row);
  }

  /* ── live estimate ───────────────────────────────────────────────────────
     Same endpoint as the Quote tab. Print prices the line on its number of
     locations; embroidery prices each location and adds them up. */

  function money(n) { return '$' + Number(n).toFixed(2); }

  function quote(params) {
    var qs = new URLSearchParams(params).toString();
    return fetch(CFG.quoteUrl + '?' + qs, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .catch(function () { return null; });
  }

  function estimate(row) {
    var out = row.querySelector('.bta-est');
    if (!out || !CFG.quoteUrl) return;
    clearTimeout(row._estTimer);
    row._estTimer = setTimeout(function () {
      var qty = rowQty(row);
      var locs = row.querySelectorAll('.bta-loc');
      row._estUnit = null;
      if (!qty || !locs.length) { out.textContent = ''; orderTotal(); return; }
      var mine = row._estSeq = (row._estSeq || 0) + 1;
      var calls;
      if (isEmb(row)) {
        // The catalogue garment's price rides on the first location only.
        calls = Array.prototype.map.call(locs, function (l, k) {
          return quote({ qty: qty, method: 'embroidery', embType: l.querySelector('.bta-emb').value, retail: k === 0 ? retail(row) : 0 });
        });
      } else {
        calls = [quote({ qty: qty, method: 'print', locations: Math.min(3, locs.length), retail: retail(row) })];
      }
      Promise.all(calls).then(function (res) {
        if (mine !== row._estSeq) return;
        var unit = 0, byShop = false;
        res.forEach(function (d) {
          if (!d || d.quote || d.perShirt == null) byShop = true; else unit += Number(d.perShirt);
        });
        if (byShop) {
          out.innerHTML = '<strong>Estimate:</strong> priced by the shop at this quantity';
        } else {
          row._estUnit = unit;
          out.innerHTML = '<strong>Estimate:</strong> ' + money(unit) + ' each &middot; ' + money(unit * qty)
            + (!isEmb(row) && locs.length > 3 ? ' <span class="bta-sub">+ ' + (locs.length - 3) + ' more locations priced by the shop</span>' : '');
        }
        orderTotal();
      });
    }, 250);
  }

  /** Retail of the catalogue garment picked on this line; 0 for a typed-in style they send us. */
  function retail(row) {
    return row.querySelector('.bta-cid').value !== '0' ? (parseFloat(row.getAttribute('data-retail')) || 0) : 0;
  }

  function orderTotal() {
    var box = document.getElementById('btaOrderEst');
    if (!box) return;
    var sum = 0, any = false;
    itemRows.querySelectorAll('.bta-itemrow').forEach(function (r) {
      if (r._estUnit != null) { sum += r._estUnit * rowQty(r); any = true; }
    });
    box.innerHTML = any ? 'Estimated decoration total: <strong>' + money(sum) + '</strong>' : '';
  }

  /* ── item row ────────────────────────────────────────────────────────── */

  function addItem() {
    var i = itemIdx++;
    var row = el('div', 'bta-itemrow bta-row-empty');

    var decs = Object.keys(CFG.decorations).map(function (k) {
      return '<option value="' + esc(k) + '">' + esc(CFG.decorations[k]) + '</option>';
    }).join('');
    var base = 'item[' + i + ']';

    row.innerHTML =
      '<div class="bta-itemhead"><span class="bta-itemnum">Item ' + (i + 1) + '</span>' +
      '<button type="button" class="bta-x" aria-label="Remove item">&times;</button></div>' +

      '<div class="bta-grid">' +
        '<div class="bta-field bta-ac">' +
          '<label class="bta-label">Style number or name</label>' +
          '<input class="bta-input bta-style" name="' + base + '[style]" autocomplete="off" placeholder="e.g. 5000">' +
          '<div class="bta-aclist" hidden></div>' +
        '</div>' +
        '<div class="bta-field">' +
          '<label class="bta-label">Description</label>' +
          '<input class="bta-input bta-name" name="' + base + '[name]" placeholder="Filled in automatically">' +
        '</div>' +
        '<div class="bta-field">' +
          '<label class="bta-label">Colour</label>' +
          '<input class="bta-input bta-color" name="' + base + '[color]" list="bta-colors-' + i + '" placeholder="e.g. Black">' +
          '<datalist id="bta-colors-' + i + '"></datalist>' +
        '</div>' +
      '</div>' +

      '<input type="hidden" name="' + base + '[catalog_id]" class="bta-cid" value="0">' +
      '<input type="hidden" name="' + base + '[brand]" class="bta-brand" value="">' +

      '<label class="bta-label" style="margin-top:14px">Sizes and quantities</label>' +
      sizeGrid(i, CFG.sizes) +

      '<div class="bta-grid" style="margin-top:14px">' +
        '<div class="bta-field"><label class="bta-label">Decoration</label>' +
          '<select class="bta-input bta-deco" name="' + base + '[decoration]">' + decs + '</select></div>' +
      '</div>' +
      '<div class="bta-locs"></div>' +
      '<button type="button" class="bta-btn-ghost bta-addloc">+ Add another location</button>' +
      '<div class="bta-est" aria-live="polite"></div>' +

      '<div class="bta-field" style="margin-top:4px">' +
        '<label class="bta-label">Notes for this item</label>' +
        '<input class="bta-input" name="' + base + '[notes]" placeholder="Optional">' +
      '</div>';

    itemRows.appendChild(row);
    wireTotals(row, i);
    wireAutocomplete(row, i);
    addLocation(row, i);

    row.querySelector('.bta-addloc').addEventListener('click', function () { addLocation(row, i); });
    row.querySelector('.bta-deco').addEventListener('change', function () {
      row.querySelectorAll('.bta-loc-emb').forEach(function (f) { f.hidden = !isEmb(row); });
      estimate(row);
    });
    row.querySelector('.bta-locs').addEventListener('change', function (e) {
      if (e.target.classList.contains('bta-emb')) estimate(row);
    });

    row.querySelector('.bta-x').addEventListener('click', function () {
      if (itemRows.children.length === 1) return;   // never remove the last row
      row.remove();
      renumber();
      orderTotal();
    });

    refreshArtSelects();
    return row;
  }

  function renumber() {
    itemRows.querySelectorAll('.bta-itemnum').forEach(function (n, k) {
      n.textContent = 'Item ' + (k + 1);
    });
  }

  /* ── catalogue autocomplete ──────────────────────────────────────────── */

  function wireAutocomplete(row, i) {
    var input = row.querySelector('.bta-style');
    var list  = row.querySelector('.bta-aclist');
    var timer = null;
    var seq   = 0;

    function close() { list.hidden = true; list.innerHTML = ''; }

    input.addEventListener('input', function () {
      var q = input.value.trim();
      row.querySelector('.bta-cid').value = '0';
      row.removeAttribute('data-retail');
      estimate(row);
      clearTimeout(timer);
      if (q.length < 2) { close(); return; }

      timer = setTimeout(function () {
        var mine = ++seq;
        fetch(CFG.searchUrl + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : []; })
          .then(function (rows) {
            if (mine !== seq) return;              // a newer keystroke won
            if (!rows || !rows.length) { close(); return; }
            list.innerHTML = rows.map(function (r, n) {
              return '<button type="button" class="bta-acitem" data-n="' + n + '">' +
                     '<strong>' + esc(r.style) + '</strong> ' + esc(r.brand + ' ' + r.name) + '</button>';
            }).join('');
            list.hidden = false;
            list.querySelectorAll('.bta-acitem').forEach(function (b) {
              b.addEventListener('click', function () {
                pick(row, i, rows[parseInt(b.dataset.n, 10)]);
                close();
              });
            });
          })
          .catch(close);
      }, 220);
    });

    input.addEventListener('blur', function () { setTimeout(close, 180); });
  }

  function pick(row, i, r) {
    row.querySelector('.bta-style').value = r.style;
    row.querySelector('.bta-name').value  = r.name;
    row.querySelector('.bta-brand').value = r.brand;
    row.querySelector('.bta-cid').value   = r.id;
    row.setAttribute('data-retail', r.price || 0);
    estimate(row);

    var dl = row.querySelector('#bta-colors-' + i);
    if (dl) dl.innerHTML = (r.colors || []).map(function (c) {
      return '<option value="' + esc(c) + '">';
    }).join('');

    // Rebuild the size grid from the style's real size run, keeping anything
    // already typed for a size that still exists.
    if (r.sizes && r.sizes.length) {
      var keep = {};
      row.querySelectorAll('.bta-sizes input').forEach(function (inp) {
        var m = inp.name.match(/\[([^\]]+)\]$/);
        if (m && inp.value) keep[m[1]] = inp.value;
      });
      var wrap = row.querySelector('.bta-sizes');
      var tot  = row.querySelector('.bta-sizetotal');
      var tmp  = el('div', null, sizeGrid(i, r.sizes));
      wrap.replaceWith(tmp.firstElementChild);
      tot.replaceWith(tmp.lastElementChild);
      row.querySelectorAll('.bta-sizes input').forEach(function (inp) {
        var m = inp.name.match(/\[([^\]]+)\]$/);
        if (m && keep[m[1]]) inp.value = keep[m[1]];
      });
      row.querySelector('.bta-sizes').dispatchEvent(new Event('input', { bubbles: true }));
    }
  }

  /* ── art rows ────────────────────────────────────────────────────────── */

  function addArt() {
    var row = el('div', 'bta-artrow');
    row.innerHTML =
      '<div class="bta-field"><label class="bta-label">Logo name</label>' +
        '<input class="bta-input" name="art_label[]" placeholder="e.g. Acme Left Chest"></div>' +
      '<div class="bta-field"><label class="bta-label">File</label>' +
        '<input class="bta-input bta-file" type="file" name="art_file[]"></div>' +
      '<button type="button" class="bta-x" aria-label="Remove">&times;</button>';
    artRows.appendChild(row);
    wireArtRow(row);
  }

  function wireArtRow(row) {
    var label = row.querySelector('input[name="art_label[]"]');
    label.addEventListener('input', refreshArtSelects);
    row.querySelector('.bta-x').addEventListener('click', function () {
      if (artRows.children.length === 1) {
        label.value = '';
        row.querySelector('input[type=file]').value = '';
      } else {
        row.remove();
      }
      refreshArtSelects();
    });
  }

  /* ── init ────────────────────────────────────────────────────────────── */

  artRows.querySelectorAll('.bta-artrow').forEach(wireArtRow);
  document.getElementById('btaAddArt').addEventListener('click', addArt);
  document.getElementById('btaAddItem').addEventListener('click', function () { addItem(); });

  addItem();   // always start with one item row

  // In-hands has to be a weekday; the server checks too, this just says so sooner.
  var ih = document.getElementById('f-in_hands_date');
  if (ih) ih.addEventListener('change', function () {
    var d = ih.value ? new Date(ih.value + 'T12:00:00') : null;
    var wkend = d && (d.getDay() === 0 || d.getDay() === 6);
    ih.setCustomValidity(wkend ? 'Pick a weekday. We don\'t deliver on weekends.' : '');
    if (wkend) ih.reportValidity();
  });

  // Guard against a mis-click losing a part-filled order.
  var dirty = false;
  document.getElementById('btaOrderForm').addEventListener('input', function () { dirty = true; });
  document.getElementById('btaOrderForm').addEventListener('submit', function () { dirty = false; });
  window.addEventListener('beforeunload', function (e) {
    if (!dirty) return;
    e.preventDefault();
    e.returnValue = '';
  });
})();
