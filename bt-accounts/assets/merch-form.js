/* BT Accounts — bulk / on-demand order form for merch-store accounts.
 *
 * Built line by line. "Add item" opens a card: pick the garment by its
 * picture, pick the design, then fill a size grid with one row per colour,
 * so several colours of the same shirt and design go in at once.
 *
 * Fields: line[i][product], line[i][art], line[i][qty][colour][size], with an
 * explicit index per card so removing one never shifts another's quantities.
 * Prices here are only a running total; the server prices from the product list. */
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
  var addBtn = document.getElementById('btaMerchAdd');
  var idx = 0;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function money(n) { return '$' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  function pic(src, label) {
    return src ? '<img src="' + esc(src) + '" alt="" loading="lazy">' : '<span>' + esc(label) + '</span>';
  }

  /* A mockup: the garment photo with the art contain-fitted into its print
     location's box (x / y / w / h as % of the photo, as in PresStora). The
     wrapper takes the photo's own shape so the percentages line up. */
  function mock(garment, art, zone, label) {
    if (!garment) return pic('', label);
    var a = art && zone ? '<img class="bta-mock-art" src="' + esc(art) + '" alt="" style="left:' + zone.x + '%;top:' + zone.y
      + '%;width:' + zone.w + '%;height:' + zone.h + '%">' : '';
    return '<span class="bta-mock"><img src="' + esc(garment) + '" alt="" loading="lazy">' + a + '</span>';
  }
  function zoneFor(p, design) {
    return design && design.zone && p.zones ? p.zones[design.zone] : null;
  }
  /* The art for one garment colour: its version's preview, the design's own
     picture when no version applies, nothing when the version has no file yet. */
  function artFor(design, color) {
    if (!design) return '';
    var v = design.versions && design.versions[color];
    if (v && v.label) return v.img || '';
    return design.img || '';
  }

  function priceText(p) {
    var vals = Object.keys(p.prices).map(function (k) { return p.prices[k]; }).filter(function (v) { return v !== null; });
    if (!vals.length) return 'Price set by the shop';
    var lo = Math.min.apply(null, vals), hi = Math.max.apply(null, vals);
    return money(lo) + (hi > lo ? '–' + money(hi) : '') + ' each';
  }

  /* ── One item card ── */

  function addCard(saved) {
    var card = document.createElement('div');
    card.className = 'bta-mcard';
    card.dataset.n = 'line[' + (idx++) + ']';
    wrap.appendChild(card);
    if (saved && byId[saved.product]) showGrid(card, byId[saved.product], saved);
    else showProducts(card);
    renumber();
    return card;
  }

  function head(card, title, back) {
    var n = Array.prototype.indexOf.call(wrap.children, card) + 1;
    return '<div class="bta-mcard-head"><span class="bta-itemnum">Item ' + n + '</span>'
      + '<span class="bta-mcard-title">' + title + '</span>'
      + (back ? '<button type="button" class="bta-linkbtn" data-act="' + back + '">Change</button>' : '')
      + '<button type="button" class="bta-x" data-act="remove" aria-label="Remove item">&times;</button></div>';
  }

  /* Step 1: the garment, by picture. */
  function showProducts(card) {
    card.dataset.product = '';
    card.innerHTML = head(card, 'Choose the item', '')
      + '<div class="bta-tiles">' + products.map(function (p) {
        return '<button type="button" class="bta-tile" data-pick-product="' + p.id + '">'
          + '<div class="bta-tile-img">' + pic(p.img, p.brand || p.name) + '</div>'
          + '<div class="bta-tile-name">' + esc(p.name) + '</div>'
          + '<div class="bta-tile-sub">' + esc(p.brand) + '</div>'
          + '<div class="bta-tile-sub">' + esc(priceText(p)) + '</div></button>';
      }).join('') + '</div>';
    recalc();
  }

  /* Step 2: the design. Skipped when the item has one design or none on file. */
  function showDesigns(card, p, keep) {
    if (p.art.length < 2) return showGrid(card, p, { art: p.art.length ? p.art[0].id : 0, qty: keep });
    card.dataset.product = p.id;
    card.innerHTML = head(card, esc(p.name) + ' &middot; choose the design', 'product')
      + '<div class="bta-tiles">' + p.art.map(function (a) {
        return '<button type="button" class="bta-tile" data-pick-art="' + a.id + '">'
          + '<div class="bta-tile-img">' + (p.img ? mock(p.img, artFor(a, 'Black') || a.img, zoneFor(p, a), 'Design') : pic(a.img, 'Design')) + '</div>'
          + '<div class="bta-tile-name">' + esc(a.name) + '</div>'
          + (a.back ? '<div class="bta-tile-sub">+ ' + esc(a.back.name) + ' on the back</div>' : '') + '</button>';
      }).join('') + '</div>';
    card._keep = keep || null;
    recalc();
  }

  /* Step 3: one row per colour, a box per size. */
  function showGrid(card, p, saved) {
    var n = card.dataset.n;
    var art = saved && saved.art ? saved.art : 0;
    var design = null;
    p.art.forEach(function (a) { if (String(a.id) === String(art)) design = a; });
    var qty = (saved && saved.qty) || {};
    // A design can come with its own back print (the tour fronts carry the
    // tour dates). It is part of the design, not a separate choice.
    var extra = design && design.back ? design.back : null;
    card.dataset.extra = extra ? String(extra.id) : '';
    // Colours every chosen design is allowed on (white-text art: black shirts only).
    var colors = (p.colors.length ? p.colors : ['']).filter(function (c) {
      return [design, extra].every(function (a) { return !a || !a.only || !a.only.length || !c || a.only.indexOf(c) !== -1; });
    });
    var limited = colors.length < (p.colors.length || 1);
    var bigPic = design && !zoneFor(p, design) && design.img
      ? pic(design.img, design.name)
      : mock(p.img, artFor(design, colors[0] || 'Black'), zoneFor(p, design), p.brand || p.name);

    card.dataset.product = p.id;
    card.innerHTML = head(card, esc(p.name), 'product')
      + '<input type="hidden" name="' + n + '[product]" value="' + p.id + '">'
      + '<input type="hidden" name="' + n + '[art]" value="' + esc(art || '') + '">'
      + '<div class="bta-mcard-sel">'
      + '<div class="bta-mcard-pic">' + bigPic + '</div>'
      + '<div><div class="bta-tile-sub">' + esc(p.brand) + ' &middot; ' + esc(priceText(p)) + '</div>'
      + (design ? '<div class="bta-mcard-design">' + (design.img ? '<img src="' + esc(design.img) + '" alt="">' : '')
          + 'Design: <strong>' + esc(design.name) + '</strong>' + (design.place ? ' &middot; ' + esc(design.place) : '')
          + (p.art.length > 1 ? ' <button type="button" class="bta-linkbtn" data-act="design">Change</button>' : '') + '</div>'
        : '<div class="bta-tile-sub">Design: the shop will confirm it with you.</div>')
      + (extra ? '<div class="bta-mcard-extra">' + (extra.img ? '<img src="' + esc(extra.img) + '" alt="">' : '')
          + 'Back: <strong>' + esc(extra.name) + '</strong>'
          + '<span class="bta-tile-sub">' + (p.extra !== null && p.extra !== undefined ? '+' + money(p.extra) + ' each' : 'back print priced by the shop') + '</span></div>' : '')
      + (limited ? '<div class="bta-tile-sub" style="margin-top:6px">Only on ' + esc(colors.join(', ') || 'none of this item\'s colours') + ' with this design.</div>' : '')
      + '</div></div>'
      + '<div class="bta-qtywrap"><table class="bta-qtygrid"><thead><tr><th>Colour</th>'
      + p.sizes.map(function (s) { return '<th>' + esc(s) + '</th>'; }).join('') + '<th>Pcs</th></tr></thead><tbody>'
      + colors.map(function (c) {
        var row = qty[c] || {};
        var ci = p.colorImgs && p.colorImgs[c] ? '<span class="bta-rowpic">' + mock(p.colorImgs[c], artFor(design, c), zoneFor(p, design), '') + '</span>' : '';
        var v = design && design.versions && design.versions[c];
        var vi = v && v.label ? '<span class="bta-rowver">' + (v.img ? '<img src="' + esc(v.img) + '" alt="">' : '')
          + 'Version ' + esc(v.label) + (v.note ? ': ' + esc(v.note) : '') + '</span>' : '';
        return '<tr data-color="' + esc(c) + '"><th scope="row">' + ci + esc(c || 'Qty') + vi + '</th>' + p.sizes.map(function (s) {
          return '<td><input type="number" min="0" inputmode="numeric" aria-label="' + esc(c + ' ' + s) + '"'
            + ' name="' + n + '[qty][' + esc(c) + '][' + esc(s) + ']" data-size="' + esc(s) + '" value="' + esc(row[s] || '') + '"></td>';
        }).join('') + '<td class="bta-rowpcs"></td></tr>';
      }).join('') + '</tbody></table></div>'
      + '<div class="bta-mcard-foot"><span class="bta-line-total"></span></div>';
    recalc();
  }

  function renumber() {
    Array.prototype.forEach.call(wrap.children, function (card, i) {
      var el = card.querySelector('.bta-itemnum');
      if (el) el.textContent = 'Item ' + (i + 1);
    });
  }

  /* ── Totals ── */

  function recalc() {
    var total = 0, pieces = 0, unpriced = false;
    Array.prototype.forEach.call(wrap.children, function (card) {
      var p = byId[card.dataset.product];
      var out = card.querySelector('.bta-line-total');
      if (!p || !out) return;
      var cardPcs = 0, cardTotal = 0;
      var hasExtra = !!card.dataset.extra;
      var add = hasExtra && p.extra !== null && p.extra !== undefined ? p.extra : 0;
      var extraUnpriced = hasExtra && !add;
      card.querySelectorAll('.bta-qtygrid tbody tr').forEach(function (tr) {
        var rowPcs = 0;
        tr.querySelectorAll('input[data-size]').forEach(function (inp) {
          var q = parseInt(inp.value, 10) || 0;
          if (q < 1) return;
          rowPcs += q;
          var each = p.prices[inp.dataset.size];
          if (each === null || each === undefined || extraUnpriced) unpriced = true; else cardTotal += (each + add) * q;
        });
        tr.querySelector('.bta-rowpcs').textContent = rowPcs || '';
        cardPcs += rowPcs;
      });
      pieces += cardPcs; total += cardTotal;
      out.textContent = cardPcs ? cardPcs + ' piece' + (cardPcs === 1 ? '' : 's') + (cardTotal ? ' · ' + money(cardTotal) : '') : '';
    });
    totalEl.innerHTML = pieces
      ? '<strong>' + pieces + ' piece' + (pieces === 1 ? '' : 's') + '</strong>' + (total ? ' · ' + money(total) : '')
        + (unpriced ? ' <span class="bta-sub">+ items the shop will price</span>' : '') + ' <span class="bta-sub">plus shipping</span>'
      : '';
  }

  /* ── Events ── */

  wrap.addEventListener('click', function (e) {
    var t = e.target.closest('button');
    if (!t || !wrap.contains(t)) return;
    var card = t.closest('.bta-mcard');
    if (t.dataset.pickProduct) return showDesigns(card, byId[t.dataset.pickProduct]);
    if (t.dataset.pickArt) {
      return showGrid(card, byId[card.dataset.product], { art: t.dataset.pickArt, qty: card._keep });
    }
    if (t.dataset.act === 'product') return showProducts(card);
    if (t.dataset.act === 'design') return showDesigns(card, byId[card.dataset.product], gridQty(card));
    if (t.dataset.act === 'remove') { card.remove(); renumber(); recalc(); }
  });

  /* Quantities already typed, so changing the design does not wipe them. */
  function gridQty(card) {
    var q = {};
    card.querySelectorAll('.bta-qtygrid tbody tr').forEach(function (tr) {
      var c = tr.dataset.color;
      tr.querySelectorAll('input[data-size]').forEach(function (inp) {
        if ((parseInt(inp.value, 10) || 0) > 0) { (q[c] = q[c] || {})[inp.dataset.size] = inp.value; }
      });
    });
    return q;
  }

  wrap.addEventListener('input', recalc);
  addBtn.addEventListener('click', function () {
    var card = addCard(null);
    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  (D.lines && D.lines.length ? D.lines : [null]).forEach(addCard);
})();
