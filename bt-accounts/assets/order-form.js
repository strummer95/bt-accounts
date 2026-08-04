/* BT Accounts — new order form.
   Line items, size grids, catalogue autocomplete, art-label wiring.
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
              'name="item_sizes[' + i + '][' + esc(s) + ']" placeholder="0"></label>';
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
    });
  }

  /* ── item row ────────────────────────────────────────────────────────── */

  function addItem() {
    var i = itemIdx++;
    var row = el('div', 'bta-itemrow bta-row-empty');

    var placements = CFG.placements.map(function (p) {
      return '<option value="' + esc(p) + '">' + esc(p) + '</option>';
    }).join('');

    var decs = Object.keys(CFG.decorations).map(function (k) {
      return '<option value="' + esc(k) + '">' + esc(CFG.decorations[k]) + '</option>';
    }).join('');

    row.innerHTML =
      '<div class="bta-itemhead"><span class="bta-itemnum">Item ' + (i + 1) + '</span>' +
      '<button type="button" class="bta-x" aria-label="Remove item">&times;</button></div>' +

      '<div class="bta-grid">' +
        '<div class="bta-field bta-ac">' +
          '<label class="bta-label">Style number or name</label>' +
          '<input class="bta-input bta-style" name="item_style[]" autocomplete="off" placeholder="e.g. 5000">' +
          '<div class="bta-aclist" hidden></div>' +
        '</div>' +
        '<div class="bta-field">' +
          '<label class="bta-label">Description</label>' +
          '<input class="bta-input bta-name" name="item_name[]" placeholder="Filled in automatically">' +
        '</div>' +
        '<div class="bta-field">' +
          '<label class="bta-label">Colour</label>' +
          '<input class="bta-input bta-color" name="item_color[]" list="bta-colors-' + i + '" placeholder="e.g. Black">' +
          '<datalist id="bta-colors-' + i + '"></datalist>' +
        '</div>' +
      '</div>' +

      '<input type="hidden" name="item_catalog_id[]" class="bta-cid" value="0">' +
      '<input type="hidden" name="item_brand[]" class="bta-brand" value="">' +

      '<label class="bta-label" style="margin-top:14px">Sizes and quantities</label>' +
      sizeGrid(i, CFG.sizes) +

      '<div class="bta-grid" style="margin-top:14px">' +
        '<div class="bta-field"><label class="bta-label">Decoration</label>' +
          '<select class="bta-input" name="item_decoration[]">' + decs + '</select></div>' +
        '<div class="bta-field"><label class="bta-label">Placement</label>' +
          '<select class="bta-input" name="item_placement[]">' + placements + '</select></div>' +
        '<div class="bta-field"><label class="bta-label">Logo</label>' +
          '<select class="bta-input bta-art-select" name="item_art[]"></select></div>' +
      '</div>' +

      '<div class="bta-field" style="margin-top:4px">' +
        '<label class="bta-label">Notes for this item</label>' +
        '<input class="bta-input" name="item_notes[]" placeholder="Optional">' +
      '</div>';

    itemRows.appendChild(row);
    wireTotals(row, i);
    wireAutocomplete(row, i);

    row.querySelector('.bta-x').addEventListener('click', function () {
      if (itemRows.children.length === 1) return;   // never remove the last row
      row.remove();
      renumber();
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
