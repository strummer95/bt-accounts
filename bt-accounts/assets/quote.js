/* BT Accounts — account quoter.
   Talks to bt-accounts/v1/quote, which prices against this account's profile. */
(function () {
  'use strict';

  var cfgEl = document.getElementById('btaQuoteData');
  if (!cfgEl) return;
  var CFG = JSON.parse(cfgEl.textContent || '{}');

  var out    = document.getElementById('btaQuoteOut');
  var breaks = document.getElementById('btaBreaks');
  var qty    = document.getElementById('btaQty');

  var state = { method: 'print', locations: 1, embType: 'text' };
  var seq = 0, timer = null;

  function money(n) {
    return '$' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ── segmented controls ────────────────────────────────────────────────── */

  function seg(id, key, after) {
    var wrap = document.getElementById(id);
    if (!wrap) return;
    wrap.addEventListener('click', function (e) {
      var b = e.target.closest('.bta-seg-btn');
      if (!b) return;
      wrap.querySelectorAll('.bta-seg-btn').forEach(function (x) { x.classList.remove('is-on'); });
      b.classList.add('is-on');
      var v = b.dataset.v;
      state[key] = isNaN(v) ? v : parseInt(v, 10);
      if (after) after();
      request();
    });
  }

  seg('btaMethod', 'method', function () {
    var isPrint = state.method === 'print';
    document.getElementById('btaPrintOpts').hidden = !isPrint;
    document.getElementById('btaEmbOpts').hidden = isPrint;
  });
  seg('btaLocations', 'locations');
  seg('btaEmbType', 'embType');

  /* ── quantity ──────────────────────────────────────────────────────────── */

  var chips = document.getElementById('btaQtyChips');
  chips.innerHTML = (CFG.chips || []).map(function (n) {
    return '<button type="button" class="bta-chip" data-q="' + n + '">' + n + '</button>';
  }).join('');
  chips.addEventListener('click', function (e) {
    var b = e.target.closest('.bta-chip');
    if (!b) return;
    qty.value = b.dataset.q;
    request();
  });

  qty.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(request, 250);
  });

  /* ── request ───────────────────────────────────────────────────────────── */

  function request() {
    var q = parseInt(qty.value, 10);
    if (!q || q < 1) { out.innerHTML = '<div class="bta-quote-loading">Enter a quantity.</div>'; breaks.innerHTML = ''; return; }
    if (q > 1000) {
      out.innerHTML = '<div class="bta-quote-msg"><strong>Over 1,000 pieces</strong>'
                    + '<p>Give us a call and we will price it properly.</p></div>';
      breaks.innerHTML = '';
      return;
    }

    var params = new URLSearchParams({
      qty: q, method: state.method,
      locations: state.locations, embType: state.embType
    });

    var mine = ++seq;
    out.classList.add('is-busy');

    fetch(CFG.url + '?' + params.toString(), { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        if (mine !== seq) return;                 // a newer request won
        out.classList.remove('is-busy');
        if (!res.ok) { fail(res.body && res.body.message); return; }
        render(res.body, q);
      })
      .catch(function () { if (mine === seq) { out.classList.remove('is-busy'); fail(); } });
  }

  function fail(msg) {
    out.innerHTML = '<div class="bta-quote-msg"><strong>Could not price that</strong><p>'
                  + esc(msg || 'Please try again, or call the shop.') + '</p></div>';
    breaks.innerHTML = '';
  }

  function render(d, q) {
    // Embroidery above the by-quote threshold comes back without a price.
    if (d.quote) {
      out.innerHTML = '<div class="bta-quote-msg"><strong>Quote required</strong><p>'
                    + esc(d.message || 'Contact us for a quote at this quantity.')
                    + '</p><p class="bta-sub">Call the shop or email '
                    + '<a href="mailto:orders@boomerts.com">orders@boomerts.com</a> and we will price it.</p></div>';
      breaks.innerHTML = '';
      return;
    }

    out.innerHTML =
      '<div class="bta-quote-kicker">Per piece</div>' +
      '<div class="bta-quote-big">' + money(d.perShirt) + '</div>' +
      '<div class="bta-quote-total">' + q + ' pieces &middot; <strong>' + money(d.total) + '</strong></div>' +
      (d.discPct > 0 ? '<div class="bta-quote-disc">' + d.discPct + '% off the single-piece rate</div>' : '') +
      '<div class="bta-quote-note">Decoration only &mdash; you supply the garments.</div>';

    if (!d.breaks || !d.breaks.length) { breaks.innerHTML = ''; return; }

    var rows = d.breaks.map(function (b) {
      var on = b.qty === q ? ' class="is-here"' : '';
      if (b.quote || b.price === null) {
        return '<tr' + on + '><td>' + b.qty + '+</td><td colspan="2">By quote</td></tr>';
      }
      return '<tr' + on + '><td>' + b.qty + '</td><td>' + money(b.price) + '</td><td>' + money(b.total) + '</td></tr>';
    }).join('');

    breaks.innerHTML =
      '<h2 class="bta-h2" style="margin-top:28px">Quantity breaks</h2>' +
      '<div class="bta-tablewrap"><table class="bta-table bta-breaks">' +
      '<thead><tr><th>Qty</th><th>Per piece</th><th>Total</th></tr></thead>' +
      '<tbody>' + rows + '</tbody></table></div>';
  }

  request();
})();
