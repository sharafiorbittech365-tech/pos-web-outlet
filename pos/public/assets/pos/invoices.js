/*!
 * BizPilot360X POS — Invoices: the editor (New / Edit) and the invoice page (tabs, document viewer).
 *
 * Editor: the invoice on the left is editable in place and the form on the right holds the same
 * fields. The form fields are the one store: an edit on the document writes the form field, an
 * edit in the form refreshes the document, and both re-render from the form. Totals are calculated
 * here with OrderCalculator's rules (line discount, invoice discount spread over the lines, service
 * charge, VAT per rule inclusive / added / exempt, rounding), then replaced by the server's figures
 * (GET invoices/quote). Saving always re-prices on the server — nothing calculated here is trusted.
 */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ------------------------------------------------------------------ tabs (tablet / phone) — editor and invoice page */
  (function () {
    var root = document.getElementById('ivx');
    if (!root) return;
    var tabs = $$('.ivx-tabs [role="tab"]', root);
    function fitFrames() {
      $$('iframe.ivx-frame').forEach(function (f) {
        try { var ev = f.contentDocument.getElementById('ev'); var h = ev ? Math.ceil(ev.getBoundingClientRect().height) + 4 : f.contentDocument.body.scrollHeight; if (h > 200) f.style.height = h + 'px'; } catch (e) { /* not loaded yet */ }
      });
    }
    function select(tab) {
      tabs.forEach(function (t) { var on = t === tab; t.setAttribute('aria-selected', on ? 'true' : 'false'); t.tabIndex = on ? 0 : -1; });
      root.setAttribute('data-view', tab.getAttribute('data-view'));
      if (tab.getAttribute('data-view') === 'doc') fitFrames();
    }
    tabs.forEach(function (t, i) {
      t.addEventListener('click', function () { select(t); });
      t.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
        var n = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length]; select(n); n.focus();
      });
    });
    if (tabs[0]) root.setAttribute('data-view', tabs[0].getAttribute('data-view'));
    $$('iframe.ivx-frame').forEach(function (f) { f.addEventListener('load', function () { fitFrames(); setTimeout(fitFrames, 400); }); });
    window.addEventListener('resize', function () { setTimeout(fitFrames, 100); });
  })();

  var form = document.getElementById('inv-form');
  if (!form) return;
  var ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ESC[c]; }); };
  var cfg = {};
  try { cfg = JSON.parse(($('#ivx-cfg') || {}).textContent || '{}'); } catch (e) { cfg = {}; }
  var DEC = typeof cfg.decimals === 'number' ? cfg.decimals : 2;
  var CAN_PRICE = form.getAttribute('data-price') === '1';
  var rows = $('#inv-rows');
  var pbody = $('#ivx-p-rows');
  var preview = $('#ivx-preview');
  var seq = 1000;
  var qTimer = null;
  var qSeq = 0;

  /* ------------------------------------------------------------------ helpers */
  // PHP round(): half away from zero, with a nudge for binary fractions (1.005 → 1.01)
  function rnd(n, d) { d = d == null ? DEC : d; var f = Math.pow(10, d); var x = Math.abs(n) * f; return Math.sign(n) * Math.round(x + 1e-8 * Math.max(1, x)) / f; }
  function money(n) { return (cfg.symbol || '') + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: DEC, maximumFractionDigits: DEC }); }
  function num(v) { var n = parseFloat(v); return isFinite(n) ? n : 0; }
  function rateStr(r) { return String(Math.round(r * 100) / 100); }
  function debounce(fn, ms) { var t; return function () { var a = arguments; clearTimeout(t); t = setTimeout(function () { fn.apply(null, a); }, ms); }; }
  function field(name) { return form.querySelector('[name="' + name + '"]'); }
  function val(name) { var el = field(name); return el ? el.value.trim() : ''; }
  function lines() { return $$('.inv-line', rows); }
  function key(row) { if (!row.getAttribute('data-key')) row.setAttribute('data-key', 'n' + (seq++)); return row.getAttribute('data-key'); }
  function rowByKey(k) { return rows.querySelector('.inv-line[data-key="' + k + '"]'); }
  function lf(row, f) { return row.querySelector('[name$="[' + f + ']"]'); }
  function fire(el, type) { el.dispatchEvent(new Event(type, { bubbles: true })); }

  /* ------------------------------------------------------------------ OrderCalculator (browser copy) */
  function rule(mode, rate) {
    if (!mode || mode === 'default') mode = cfg.inclusive ? 'inclusive' : 'exclusive';
    if (mode === 'exempt') return { mode: 'exempt', rate: 0 };
    return { mode: mode, rate: rate === null || rate === '' || rate === undefined ? num(cfg.defaultRate) : num(rate) };
  }
  function taxOn(r, gross) {
    var k = r.rate / 100;
    if (r.mode === 'inclusive') return gross - gross / (1 + k);
    if (r.mode === 'exclusive') return gross * k;
    return 0;
  }
  function lineRule(row) {
    var mode = row.getAttribute('data-mode') || 'default';
    var rate = row.getAttribute('data-rate');
    var v = lf(row, 'vat');
    if (v && v.value !== '') { var p = v.value.split('|'); mode = p[0]; rate = p[0] === 'exempt' ? null : p[1]; }
    return rule(mode, rate);
  }
  function linePrice(row) { var p = lf(row, 'unit_price'); return p && p.value !== '' ? num(p.value) : num(row.getAttribute('data-price')); }
  function lineState(row) {
    var dv = lf(row, 'discount_value');
    var dt = lf(row, 'discount_type');
    var nm = row.getAttribute('data-custom') === '1' && lf(row, 'name') && lf(row, 'name').type !== 'hidden' ? lf(row, 'name').value : row.getAttribute('data-name');
    var u = lf(row, 'unit');
    return {
      name: nm || '', unitName: u ? u.value : (row.getAttribute('data-unitname') || ''),
      qty: num((lf(row, 'qty') || {}).value), price: linePrice(row),
      dtype: dt ? dt.value : (row.getAttribute('data-dtype') || 'percent'),
      dval: dv ? num(dv.value) : num(row.getAttribute('data-dval')),
      rule: lineRule(row),
    };
  }
  function calculate() {
    var ls = lines().map(lineState);
    var sub = 0;
    ls.forEach(function (l) {
      var off = l.dtype === 'fixed' ? l.dval : l.price * Math.max(0, Math.min(100, l.dval)) / 100;
      l.amount = Math.max(0, l.price - off) * l.qty;
      sub += l.amount;
    });
    var base = sub;
    var dType = val('discount_type') || 'percent';
    var dVal = num(val('discount_value'));
    var disc = 0;
    if (cfg.discountAllowed !== false && dVal > 0) disc = dType === 'fixed' ? Math.min(base, dVal) : base * Math.max(0, Math.min(100, dVal)) / 100;
    var after = base - disc;
    var svc = after * num(cfg.serviceCharge) / 100;
    var factor = base > 0 ? after / base : 0;
    var buckets = {};
    var add = function (r, amount) { if (Math.abs(amount) <= 1e-9) return; var k = r.mode + '|' + r.rate; buckets[k] = buckets[k] || { rule: r, gross: 0 }; buckets[k].gross += amount; };
    ls.forEach(function (l) { var g = l.amount * factor; add(l.rule, g); l.tax = rnd(taxOn(l.rule, g)); });
    add(rule('default', null), svc);
    var taxIn = 0, taxEx = 0, taxLines = [];
    Object.keys(buckets).forEach(function (k) {
      var b = buckets[k], t = taxOn(b.rule, b.gross);
      if (b.rule.mode === 'inclusive') taxIn += t; else taxEx += t;
      taxLines.push({ mode: b.rule.mode, rate: b.rule.rate, tax: rnd(t) });
    });
    taxLines.sort(function (a, b) { return b.rate - a.rate || (a.mode < b.mode ? -1 : 1); });
    var total = after + svc + taxEx, roundOff = 0;
    if (num(cfg.roundTo) > 0 && base > 0) { var r = Math.round(total / cfg.roundTo) * cfg.roundTo; roundOff = r - total; total = r; }
    return { lines: ls, subtotal: rnd(sub), discount: rnd(disc), service: rnd(svc), tax: rnd(taxIn + taxEx), taxLines: taxLines, round: rnd(roundOff), total: rnd(total) };
  }

  /* ------------------------------------------------------------------ the document (editable preview) */
  function setPv(k, text) { $$('[data-pv="' + k + '"]').forEach(function (el) { el.textContent = text; }); }
  function showPv(k, on) { $$('[data-pv-wrap="' + k + '"]').forEach(function (el) { el.hidden = !on; }); }
  function vatLabel(r) { return r.mode === 'exempt' ? 'Exempt' : rateStr(r.rate) + '%' + (r.mode === 'inclusive' ? ' incl.' : ''); }
  function vatOptions(current) {
    return Object.keys(cfg.vat || {}).map(function (k) { return '<option value="' + esc(k) + '"' + (k === current ? ' selected' : '') + '>' + esc(k === '' ? 'Auto' : cfg.vat[k]) + '</option>'; }).join('');
  }
  function isCustomPrice(row) {
    var p = lf(row, 'unit_price'), cat = row.getAttribute('data-catalog');
    return CAN_PRICE && p && p.value !== '' && cat !== null && cat !== '' && Math.abs(num(p.value) - num(cat)) > 0.004;
  }
  var ICON = {
    grip: '<svg class="ic sm" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="18" r="1"/></svg>',
    up: '<svg class="ic sm" viewBox="0 0 24 24" aria-hidden="true"><path d="m18 15-6-6-6 6"/></svg>',
    down: '<svg class="ic sm" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
    x: '<svg class="ic sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>',
    reset: '<svg class="ic sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>',
  };
  function rowHtml(row, i) {
    var k = key(row), custom = row.getAttribute('data-custom') === '1', ro = row.getAttribute('data-ro') === '1';
    var nm = lf(row, 'name');
    var variant = row.querySelector('.ivx-variant');
    var unitIn = lf(row, 'unit');
    var label = esc(row.getAttribute('data-name') || (nm ? nm.value : '') || 'Item');
    var h = '<tr data-key="' + k + '">';
    h += '<td class="c-h" data-label=""><span class="ivx-grip" draggable="true" tabindex="0" role="button" aria-label="Line ' + (i + 1) + ': drag to reorder, Alt+arrow keys to move, Delete to remove" title="Drag to reorder">' + ICON.grip + '</span>' +
      (CAN_PRICE ? '<input type="checkbox" class="dx-check" data-sel aria-label="Select line ' + (i + 1) + ' for Adjust prices">' : '') + '</td>';
    h += '<td class="c-item" data-label="Item">';
    if (custom && CAN_PRICE && !ro) h += '<input class="dx dx-strong" data-lf="name" placeholder="Description" aria-label="Line ' + (i + 1) + ' description" maxlength="200">';
    else h += '<b>' + label + '</b>' + (variant ? ' <small>' + esc(variant.options[variant.selectedIndex].text) + '</small>' : (row.getAttribute('data-sku') ? ' <small>' + esc(row.getAttribute('data-sku')) + '</small>' : ''));
    h += '<input class="dx dx-note" data-lf="note" placeholder="+ details" aria-label="Line ' + (i + 1) + ' details" maxlength="255"' + (ro ? ' readonly' : '') + '></td>';
    h += '<td class="num c-qty" data-label="Qty"><input class="dx dx-num" type="number" step="any" min="0.001" data-lf="qty" aria-label="Line ' + (i + 1) + ' quantity"' + (ro ? ' readonly' : '') + '>' +
      (unitIn ? '<input class="dx dx-unit" data-lf="unit" placeholder="unit" aria-label="Line ' + (i + 1) + ' unit" maxlength="16">' : '<span class="dx-u">' + esc(row.getAttribute('data-unitname') || '') + '</span>') + '</td>';
    h += '<td class="num c-price" data-label="Price">';
    if (CAN_PRICE && !ro) {
      h += '<input class="dx dx-num" type="number" step="any" min="0" data-lf="unit_price" aria-label="Line ' + (i + 1) + ' unit price" placeholder="' + esc(row.getAttribute('data-catalog') || '') + '">' +
        '<span class="dx-cp" data-cp hidden><s data-cp-cat></s><span class="ivx-badge">Custom price</span><button type="button" class="dx-btn" data-reset-price title="Reset to catalogue price" aria-label="Reset line ' + (i + 1) + ' to the catalogue price">' + ICON.reset + '</button></span>' +
        '<span class="dx-disc"><span>less</span><input class="dx dx-num dx-sm" type="number" step="any" min="0" data-lf="discount_value" placeholder="0" aria-label="Line ' + (i + 1) + ' discount">' +
        '<select class="dx dx-sel" data-lf="discount_type" aria-label="Line ' + (i + 1) + ' discount type"><option value="percent">%</option><option value="fixed">' + esc(cfg.symbol || '') + '</option></select></span>';
    } else {
      h += '<span data-ro-price></span><small data-ro-disc></small>';
    }
    h += '</td><td class="num c-vat" data-label="' + esc(cfg.taxLabel || 'VAT') + '">';
    if (CAN_PRICE && !ro) {
      h += '<select class="dx dx-sel" data-lf="vat" aria-label="Line ' + (i + 1) + ' VAT">' + vatOptions('') + '</select>' +
        '<button type="button" class="dx-btn dx-incl" data-incl aria-label="Line ' + (i + 1) + ': switch price including / excluding VAT"></button>';
    } else {
      h += '<span data-ro-vat></span>';
    }
    h += '</td><td class="num c-amt" data-label="Amount" data-li="' + i + '">—</td>';
    h += '<td class="c-x" data-label=""><button type="button" class="dx-btn" data-pmove="-1" aria-label="Move line ' + (i + 1) + ' up"' + (i === 0 ? ' disabled' : '') + '>' + ICON.up + '</button>' +
      '<button type="button" class="dx-btn" data-pmove="1" aria-label="Move line ' + (i + 1) + ' down"' + (i === lines().length - 1 ? ' disabled' : '') + '>' + ICON.down + '</button>' +
      '<button type="button" class="dx-btn dx-del" data-premove aria-label="Remove line ' + (i + 1) + '">' + ICON.x + '</button></td>';
    return h + '</tr>';
  }
  function buildPreview() {
    if (!pbody) return;
    var ls = lines();
    pbody.innerHTML = ls.length ? ls.map(rowHtml).join('') : '<tr class="ivx-p-empty"><td colspan="7">No lines yet — add one below.</td></tr>';
    markErrors();
  }
  function syncPreview(t) {
    if (!preview) return;
    var active = document.activeElement;
    $$('[data-bind]', preview).forEach(function (el) {
      var f = field(el.getAttribute('data-bind'));
      if (f && el !== active && el.value !== f.value) el.value = f.value;
    });
    $$('tr[data-key]', pbody).forEach(function (tr, i) {
      var row = rowByKey(tr.getAttribute('data-key'));
      if (!row) return;
      $$('[data-lf]', tr).forEach(function (el) {
        var f = lf(row, el.getAttribute('data-lf'));
        if (f && el !== active && el.value !== f.value) el.value = f.value;
      });
      var sel = row.querySelector('.inv-sel'), psel = tr.querySelector('[data-sel]');
      if (sel && psel) psel.checked = sel.checked;
      var l = t.lines[i];
      var cp = tr.querySelector('[data-cp]');
      var custom = isCustomPrice(row);
      tr.classList.toggle('is-custom', custom);
      tr.classList.toggle('has-disc', !!(l && l.dval > 0));
      if (cp) { cp.hidden = !custom; cp.querySelector('[data-cp-cat]').textContent = money(row.getAttribute('data-catalog')); }
      var badge = row.querySelector('[data-custom-badge]');
      if (badge) { badge.hidden = !custom; badge.querySelector('[data-cat-text]').textContent = money(row.getAttribute('data-catalog')); }
      var inc = tr.querySelector('[data-incl]');
      if (inc && l) { inc.textContent = l.rule.mode === 'exempt' ? '—' : (l.rule.mode === 'inclusive' ? 'incl.' : 'excl.'); inc.disabled = l.rule.mode === 'exempt'; inc.title = l.rule.mode === 'inclusive' ? 'Price includes VAT — click: VAT added on top' : 'VAT added on top — click: price includes VAT'; }
      var rp = tr.querySelector('[data-ro-price]'); if (rp && l) rp.textContent = money(l.price);
      var rd = tr.querySelector('[data-ro-disc]'); if (rd && l) rd.textContent = l.dval > 0 ? 'less ' + (l.dtype === 'fixed' ? money(l.dval) : rateStr(l.dval) + '%') : '';
      var rv = tr.querySelector('[data-ro-vat]'); if (rv && l) rv.textContent = vatLabel(l.rule);
      var amt = tr.querySelector('[data-li]'); if (amt && l) amt.textContent = money(l.amount);
    });
  }
  function renderTotals(t) {
    setPv('subtotal', money(t.subtotal));
    setPv('discount', '-' + money(t.discount)); showPv('discount', t.discount > 0);
    setPv('service', money(t.service)); showPv('service', t.service > 0);
    setPv('round', money(t.round)); showPv('round', Math.abs(t.round) >= 0.005);
    setPv('total', money(t.total));
    var tr = $('#ivx-p-taxrows');
    if (tr) {
      tr.innerHTML = t.taxLines.filter(function (x) { return x.tax > 0; }).map(function (x) {
        return '<div><dt>' + esc(cfg.taxLabel || 'VAT') + ' ' + esc(rateStr(x.rate)) + '% ' + (x.mode === 'inclusive' ? '(incl.)' : '(added)') + '</dt><dd>' + esc(money(x.tax)) + '</dd></div>';
      }).join('');
    }
    var paid = Math.min(num(val('pay_amount')), t.total);
    setPv('paid', money(paid)); showPv('paid', paid > 0);
    setPv('balance', money(Math.max(0, t.total - paid))); showPv('balance', paid > 0 && t.total - paid > 0.004);
  }
  function render() {
    var t = calculate();
    lines().forEach(function (row, i) { var c = row.querySelector('.inv-amt'); if (c) c.textContent = money(t.lines[i].amount); });
    renderTotals(t);
    syncPreview(t);
    $('#inv-empty').hidden = lines().length > 0;
    quote();
  }
  var tilesMark = null;
  function addedSummary() {
    var box = $('#ivx-added'); if (!box) return;
    var n = lines().length, qty = lines().reduce(function (a, r) { return a + (parseFloat((lf(r, 'qty') || {}).value) || 0); }, 0);
    box.hidden = n === 0;
    $('#ivx-added-t').textContent = n + ' item' + (n === 1 ? '' : 's') + ' on the invoice' + (qty && qty !== n ? ' · ' + (Math.round(qty * 1000) / 1000) + ' in all' : '');
  }
  function rebuild() { buildPreview(); render(); if (tilesMark) tilesMark(); addedSummary(); schedulePaginate(); }

  /* ------------------------------------------------------------------ validation errors (from the last save) */
  function markErrors() {
    var errs = cfg.errors || {};
    Object.keys(errs).forEach(function (k) {
      var m = /^lines\.(\d+)\.([a-z_]+)$/.exec(k);
      if (m) {
        var row = lines()[+m[1]];
        if (!row) return;
        var f = lf(row, m[2]); if (f) { f.classList.add('is-invalid'); f.title = errs[k]; f.setAttribute('aria-invalid', 'true'); }
        var tr = pbody && pbody.querySelector('tr[data-key="' + key(row) + '"]');
        var cell = tr && (tr.querySelector('[data-lf="' + m[2] + '"]') || tr.querySelector('[data-lf="qty"]'));
        if (cell) { cell.classList.add('is-invalid'); cell.title = errs[k]; cell.setAttribute('aria-invalid', 'true'); }
        return;
      }
      $$('[data-bind="' + k + '"]', preview).forEach(function (el) { el.classList.add('is-invalid'); el.title = errs[k]; el.setAttribute('aria-invalid', 'true'); });
      var f = field(k); if (f) f.setAttribute('aria-invalid', 'true');
    });
  }
  form.addEventListener('input', function (e) { if (e.target.classList.contains('is-invalid')) { e.target.classList.remove('is-invalid'); e.target.removeAttribute('aria-invalid'); } }, true);

  /* ------------------------------------------------------------------ server totals */
  function quote() {
    clearTimeout(qTimer);
    qTimer = setTimeout(function () {
      var err = $('#q-err');
      if (!lines().length) { if (err) err.hidden = true; return; }
      var params = new URLSearchParams();
      new FormData(form).forEach(function (v, k) { if (typeof v === 'string' && k.indexOf('csrf') < 0 && ['pay_amount', 'pay_method', 'pay_reference', 'notes', 'terms'].indexOf(k) < 0) params.append(k, v); });
      var mine = ++qSeq;
      fetch(form.getAttribute('data-quote') + '?' + params.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (mine !== qSeq) return; // an older answer
          var d = j.data || {};
          var msg = d.error || (j.error && j.error.message);
          if (msg) { err.textContent = msg; err.hidden = false; return; }
          err.hidden = true;
          renderTotals({ subtotal: d.subtotal, discount: d.discount, service: d.service, round: d.round_off, total: d.total,
            taxLines: (d.taxLines || []).map(function (x) { return { mode: x.mode, rate: num(x.rate), tax: num(x.tax) }; }) });
          (d.lines || []).forEach(function (l, i) {
            var c = pbody && pbody.querySelector('[data-li="' + i + '"]'); if (c) c.textContent = money(l.amount);
            var row = lines()[i]; var a = row && row.querySelector('.inv-amt'); if (a) a.textContent = money(l.amount);
          });
        })
        .catch(function () { /* offline: the browser figures stay */ });
    }, 450);
  }

  /* ------------------------------------------------------------------ lines: add / remove / move */
  function bind(row) {
    var p = 'lines[' + (seq++) + ']';
    $$('[data-n]', row).forEach(function (el) { el.name = p + '[' + el.getAttribute('data-n') + ']'; el.removeAttribute('data-n'); });
    key(row);
    rows.appendChild(row);
    return row;
  }
  function addProduct(prod, focusPreview, vid) {
    var row = $('#inv-tpl-product').content.firstElementChild.cloneNode(true);
    $('[data-f="name"]', row).textContent = prod.name;
    $('[data-f="sku"]', row).textContent = [prod.sku, prod.vat].filter(Boolean).join(' · ');
    $('[data-n="product_id"]', row).value = prod.id;
    $('[data-n="name"]', row).value = prod.name;
    row.setAttribute('data-name', prod.name);
    row.setAttribute('data-sku', prod.sku || '');
    row.setAttribute('data-unitname', prod.unit || '');
    row.setAttribute('data-price', prod.price);
    row.setAttribute('data-catalog', prod.price);
    row.setAttribute('data-mode', prod.tax_mode || 'default');
    row.setAttribute('data-rate', prod.tax_rate === null || prod.tax_rate === undefined ? '' : prod.tax_rate);
    var priceCell = $('[data-f="price"]', row);
    if (priceCell) priceCell.textContent = money(prod.price);
    var price = $('[data-n="unit_price"]', row);
    if (price) price.placeholder = prod.price;
    bind(row);
    if (prod.variants && prod.variants.length) {
      var sel = document.createElement('select');
      sel.className = 'select ivx-variant';
      sel.setAttribute('aria-label', 'Option of ' + prod.name);
      sel.innerHTML = prod.variants.map(function (v) { return '<option value="' + esc(v.id) + '" data-price="' + esc(v.price) + '">' + esc(v.name) + '</option>'; }).join('');
      var hid = row.querySelector('input[name$="[variant_id]"]');
      var setV = function () {
        var pr = sel.options[sel.selectedIndex].getAttribute('data-price');
        hid.value = sel.value; row.setAttribute('data-price', pr); row.setAttribute('data-catalog', pr);
        if (priceCell) priceCell.textContent = money(pr);
        var up = row.querySelector('.inv-price'); if (up) up.placeholder = pr;
      };
      sel.addEventListener('change', function () { setV(); rebuild(); });
      $('[data-f="variants"]', row).appendChild(sel);
      if (vid) sel.value = String(vid);
      setV();
    }
    rebuild();
    if (window.OmniSound) window.OmniSound.click();
    focusLine(row, focusPreview, 'qty');
  }

  /* A product with options (size / colour …): ask which one first — the same choice the POS till (/sell) gives. */
  function chooseProduct(prod, focusPreview) {
    if (!prod.variants || !prod.variants.length) return addProduct(prod, focusPreview);
    var dlg = $('#ivx-var-dlg');
    if (!dlg) {
      dlg = document.createElement('dialog'); dlg.className = 'modal-dlg'; dlg.id = 'ivx-var-dlg'; dlg.setAttribute('aria-labelledby', 'ivx-var-t');
      dlg.innerHTML = '<div class="modal-h"><h3 id="ivx-var-t"></h3><button class="icon-btn" type="button" data-var-x aria-label="Close">&times;</button></div><div class="modal-b"><div class="opt-grid" id="ivx-var-list"></div></div>';
      document.body.appendChild(dlg);
      dlg.addEventListener('click', function (e) {
        if (e.target === dlg || e.target.closest('[data-var-x]')) { dlg.close(); return; }
        var b = e.target.closest('[data-vid]'); if (!b || b.disabled) return;
        var pr = dlg._p, vid = b.getAttribute('data-vid'), fp = dlg._f;
        dlg.close();
        // the same product + option already on the invoice: one more of it
        var same = lines().filter(function (r) { var f = lf(r, 'product_id'), v = r.querySelector('input[name$="[variant_id]"]'); return f && f.value === String(pr.id) && v && v.value === String(vid); })[0];
        if (same) { var q = lf(same, 'qty'); if (q && !q.readOnly) { q.value = (parseFloat(q.value) || 0) + 1; fire(q, 'input'); rebuild(); } }
        else addProduct(pr, fp, vid);
        if (tilesMark) tilesMark();
      });
    }
    dlg._p = prod; dlg._f = focusPreview;
    $('#ivx-var-t').textContent = prod.name;
    $('#ivx-var-list').innerHTML = prod.variants.map(function (v) {
      var out = v.stock !== undefined && v.stock !== null && v.stock <= 0;
      return '<button type="button" class="opt" data-vid="' + esc(v.id) + '"' + (out ? ' disabled style="opacity:.5"' : '') + '><b>' + esc(v.name) + '</b><span>' + esc(money(v.price)) + (v.stock !== undefined && v.stock !== null ? ' · ' + (v.stock > 0 ? esc(v.stock) + ' left' : 'out of stock') : '') + '</span></button>';
    }).join('');
    dlg.showModal();
  }

  /* ------------------------------------------------------------------ A4 pages in the editable preview
     The sheet is always whole A4 pages. When the lines (or the totals) do not fit, a page break is drawn: the
     footer of the page, a gap, then the header (and the column heads) of the next page — so every page carries the
     configured header and footer. The real footer closes the last page. */
  var paper = $('.ivx-paper'), pgBusy = false, pgTimer = 0, GAP = 26;
  function schedulePaginate() { if (!paper) return; cancelAnimationFrame(pgTimer); pgTimer = requestAnimationFrame(paginate); }
  function pgDrop(el) { if (el && el.parentNode) el.parentNode.removeChild(el); }
  function pgClone(sel) {
    var n = paper.querySelector(sel); if (!n) return null;
    var c = n.cloneNode(true);
    $$('[id]', c).forEach(function (e) { e.removeAttribute('id'); });
    $$('.ivx-settings-link', c).forEach(pgDrop);
    $$('input, textarea, select, button', c).forEach(function (e) { var t = document.createElement('span'); t.textContent = e.value || ''; e.parentNode.replaceChild(t, e); });
    c.classList.remove('ivx-settings-zone');
    return c;
  }
  function pgParts() {
    var hi = paper.classList.contains('ivd-hi'), fi = paper.classList.contains('ivd-fi');
    var head = document.createElement('div'), foot = document.createElement('div');
    head.className = 'pgb-head'; foot.className = 'pgb-foot';
    if (hi) { var hImg = pgClone('.inv-himg'); if (hImg) head.appendChild(hImg); }
    else { var br = pgClone('.ivd-brand'); if (br) { var w = document.createElement('div'); w.className = 'pgb-brand'; w.appendChild(br); head.appendChild(w); } }
    if (fi) { var fImg = pgClone('.inv-fimg'); if (fImg) foot.appendChild(fImg); }
    else {
      var th = (paper.querySelector('.ivd-thanks') || {}).value; if (th) { var t = document.createElement('div'); t.className = 'pgb-thanks'; t.textContent = th; foot.appendChild(t); }
      var bar = pgClone('.ivx-p-bar'); if (bar) foot.appendChild(bar);
    }
    var no = document.createElement('div'); no.className = 'pgb-no'; foot.appendChild(no);
    return { head: head, foot: foot };
  }
  function pgBreak(kind, fill, parts, padL, padR, padT, padB, withHead) {
    var wrap = document.createElement(kind === 'tr' ? 'tr' : 'div'); wrap.className = 'ivx-pgbreak'; wrap.setAttribute('aria-hidden', 'true');
    var inner = document.createElement('div'); inner.className = 'pgb';
    inner.style.margin = '0 ' + (-padR) + 'px 0 ' + (-padL) + 'px';
    var sp = function (h, c) { var d = document.createElement('div'); d.style.height = Math.max(0, h) + 'px'; if (c) d.className = c; return d; };
    var f = parts.foot.cloneNode(true), h = parts.head.cloneNode(true);
    f.style.padding = '0 ' + padR + 'px 0 ' + padL + 'px'; h.style.padding = '0 ' + padR + 'px 0 ' + padL + 'px';
    inner.appendChild(sp(fill, 'pgb-fill')); inner.appendChild(f); inner.appendChild(sp(padB)); inner.appendChild(sp(GAP, 'pgb-gap')); inner.appendChild(sp(padT));
    inner.appendChild(h);
    if (withHead) {
      var th = paper.querySelector('.ivx-wtbl thead');
      if (th) { var tb = document.createElement('table'); tb.className = 'ivd-items ivx-wtbl pgb-thead'; tb.style.margin = '0 ' + padR + 'px 0 ' + padL + 'px'; tb.style.width = 'calc(100% - ' + (padL + padR) + 'px)'; tb.appendChild(th.cloneNode(true)); inner.appendChild(tb); }
    }
    if (kind === 'tr') { var td = document.createElement('td'); td.colSpan = 7; td.appendChild(inner); wrap.appendChild(td); } else wrap.appendChild(inner);
    return wrap;
  }
  function paginate() {
    if (!paper || !pbody || pgBusy) return;
    pgBusy = true;
    try {
      $$('.ivx-pgbreak, .pgb-no-last', paper).forEach(pgDrop);
      paper.classList.remove('ivx-multi'); paper.style.minHeight = '';
      var pr = paper.getBoundingClientRect(), W = pr.width;
      if (W < 560 || paper.offsetParent === null) return;   // phones: the sheet just flows (the printed A4 still paginates)
      var H = W * 297 / 210, cs = getComputedStyle(paper);
      var padL = parseFloat(cs.paddingLeft) || 0, padR = parseFloat(cs.paddingRight) || 0, padT = parseFloat(cs.paddingTop) || 0, padB = parseFloat(cs.paddingBottom) || 0;
      var parts = pgParts();
      var probe = pgBreak('tr', 0, parts, padL, padR, padT, padB, true); pbody.appendChild(probe);
      var fh = probe.querySelector('.pgb-foot').offsetHeight;
      pgDrop(probe);
      var page = 0;
      var pageEnd = function () { return page * (H + GAP) + H; };
      var rows = Array.prototype.slice.call(pbody.children);
      rows.forEach(function (tr) {
        var r = tr.getBoundingClientRect(), y0 = r.top - pr.top, y1 = r.bottom - pr.top;
        if (y1 > pageEnd() - padB - fh && y0 > page * (H + GAP) + padT + 40) {
          pbody.insertBefore(pgBreak('tr', pageEnd() - padB - fh - y0, parts, padL, padR, padT, padB, true), tr); page++;
        }
      });
      var sum = paper.querySelector('.ivd-sum'), foot = paper.querySelector('.ivx-p-foot');
      if (sum && foot) {
        var tail = sum.getBoundingClientRect(), nbr = paper.querySelector('.ivd-nbr');
        var tb = (nbr ? nbr.getBoundingClientRect().bottom : tail.bottom) - pr.top;
        if (tb + foot.offsetHeight + padB > pageEnd()) {
          sum.parentNode.insertBefore(pgBreak('div', pageEnd() - padB - fh - (tail.top - pr.top), parts, padL, padR, padT, padB, false), sum); page++;
        }
      }
      var pages = page + 1;
      if (pages > 1) { paper.classList.add('ivx-multi'); paper.style.minHeight = (pages * H + (pages - 1) * GAP) + 'px'; }
      $$('.pgb-no', paper).forEach(function (el, i) { el.textContent = pages > 1 ? 'Page ' + (i + 1) + ' of ' + pages : ''; });
      if (pages > 1 && foot) { var last = document.createElement('div'); last.className = 'pgb-no pgb-no-last'; last.textContent = 'Page ' + pages + ' of ' + pages; foot.appendChild(last); }
    } finally { pgBusy = false; }
  }
  if (paper) {
    window.addEventListener('resize', schedulePaginate);
    paper.addEventListener('input', schedulePaginate);
    paper.addEventListener('load', schedulePaginate, true);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(schedulePaginate);
    document.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('[data-view]')) setTimeout(schedulePaginate, 60); });
    setTimeout(schedulePaginate, 300);
  }
  function addFree(focusPreview) {
    var row = bind($('#inv-tpl-free').content.firstElementChild.cloneNode(true));
    rebuild();
    focusLine(row, focusPreview, 'name');
  }
  function focusLine(row, inPreview, f) {
    var el = inPreview && pbody ? pbody.querySelector('tr[data-key="' + key(row) + '"] [data-lf="' + f + '"]') : lf(row, f);
    if (el) { el.focus(); if (el.select) el.select(); }
  }
  function removeLine(row, inPreview) {
    var i = lines().indexOf(row);
    row.remove();
    rebuild();
    var next = lines()[Math.min(i, lines().length - 1)];
    if (next) focusLine(next, inPreview, 'qty');
    else { var s = inPreview ? $('#ivx-p-search') : $('#inv-prod'); if (s) s.focus(); }
  }
  function moveLine(row, dir, inPreview) {
    var ls = lines(), i = ls.indexOf(row), j = i + dir;
    if (j < 0 || j >= ls.length) return;
    if (dir < 0) rows.insertBefore(row, ls[j]); else rows.insertBefore(ls[j], row);
    rebuild();
    var tr = pbody && pbody.querySelector('tr[data-key="' + key(row) + '"]');
    var focus = inPreview && tr ? tr.querySelector('.ivx-grip') : row.querySelector('[data-move="' + dir + '"]');
    if (focus) focus.focus();
  }
  $$('[data-free-line]').forEach(function (b) { b.addEventListener('click', function () { addFree(!!b.closest('#ivx-preview')); }); });

  // form side
  rows.addEventListener('click', function (e) {
    var row = e.target.closest('.inv-line');
    if (!row) return;
    if (e.target.closest('[data-remove]')) removeLine(row, false);
    else if (e.target.closest('[data-move]')) moveLine(row, +e.target.closest('[data-move]').getAttribute('data-move'), false);
    else if (e.target.closest('[data-reset-price]')) { var p = lf(row, 'unit_price'); p.value = ''; fire(p, 'input'); }
  });
  rows.addEventListener('change', function (e) { if (e.target.classList.contains('inv-sel')) render(); });
  form.addEventListener('input', debounce(function (e) { if (e.target.closest('#inv-prod, #ivx-p-search') || e.target.closest('#ivx-preview')) return; render(); }, 120));
  form.addEventListener('change', function (e) { if (e.target.closest('#ivx-preview')) return; if (e.target.tagName === 'SELECT' || e.target.type === 'date' || e.target.type === 'radio') render(); });

  // document side: edits write the form field
  if (preview) {
    var fromDoc = debounce(function () { render(); }, 120);
    var writeBack = function (e) {
      var el = e.target;
      if (el.matches('[data-bind]')) {
        var f = field(el.getAttribute('data-bind'));
        if (f && !f.readOnly) { f.value = el.value; }
        fromDoc();
        return;
      }
      if (el.matches('[data-lf]')) {
        var tr = el.closest('tr[data-key]'), row = tr && rowByKey(tr.getAttribute('data-key'));
        var f2 = row && lf(row, el.getAttribute('data-lf'));
        if (f2 && !f2.readOnly) f2.value = el.value;
        if (el.getAttribute('data-lf') === 'name' && row) row.setAttribute('data-name', el.value);
        fromDoc();
        return;
      }
      if (el.matches('[data-sel]')) {
        var r2 = rowByKey(el.closest('tr').getAttribute('data-key'));
        var s2 = r2 && r2.querySelector('.inv-sel'); if (s2) s2.checked = el.checked;
      }
    };
    preview.addEventListener('input', writeBack);
    preview.addEventListener('change', writeBack);
    preview.addEventListener('click', function (e) {
      var tr = e.target.closest('tr[data-key]');
      var row = tr && rowByKey(tr.getAttribute('data-key'));
      if (!row) return;
      if (e.target.closest('[data-premove]')) removeLine(row, true);
      else if (e.target.closest('[data-pmove]')) moveLine(row, +e.target.closest('[data-pmove]').getAttribute('data-pmove'), true);
      else if (e.target.closest('[data-reset-price]')) { var p = lf(row, 'unit_price'); p.value = ''; render(); var c = tr.querySelector('[data-lf="unit_price"]'); if (c) c.focus(); }
      else if (e.target.closest('[data-incl]')) {
        var r = lineRule(row), v = lf(row, 'vat');
        if (!v || r.mode === 'exempt') return;
        var want = (r.mode === 'inclusive' ? 'exclusive' : 'inclusive') + '|' + rateStr(r.rate);
        if (!Array.prototype.some.call(v.options, function (o) { return o.value === want; })) return;
        v.value = want; render();
      }
    });
    preview.addEventListener('keydown', function (e) {
      var el = e.target;
      var tr = el.closest('tr[data-key]');
      if (e.key === 'Enter' && el.tagName === 'INPUT' && el.closest('#ivx-preview') && el.id !== 'ivx-p-search') {
        e.preventDefault(); // never submit the form from the document
        if (!tr) return;
        var trs = $$('tr[data-key]', pbody);
        var cells = $$('input:not([type=checkbox]):not([readonly]), select', tr);
        if (tr === trs[trs.length - 1] && el === cells[cells.length - 1]) {
          if (CAN_PRICE) addFree(true); else { var s = $('#ivx-p-search'); if (s) s.focus(); }
        } else {
          var nextTr = trs[trs.indexOf(tr) + 1];
          var same = nextTr && nextTr.querySelector('[data-lf="' + el.getAttribute('data-lf') + '"]');
          if (same) same.focus(); else if (cells[cells.indexOf(el) + 1]) cells[cells.indexOf(el) + 1].focus();
        }
        return;
      }
      if (tr && el.classList.contains('ivx-grip')) {
        var row = rowByKey(tr.getAttribute('data-key'));
        if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); removeLine(row, true); }
        if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) { e.preventDefault(); moveLine(row, e.key === 'ArrowUp' ? -1 : 1, true); }
      }
    });
    // drag to reorder (mouse / pen; touch uses the up / down buttons)
    var dragKey = null;
    preview.addEventListener('dragstart', function (e) {
      var g = e.target.closest && e.target.closest('.ivx-grip'); if (!g) return;
      dragKey = g.closest('tr').getAttribute('data-key');
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', dragKey); } catch (x) { /* old browsers */ }
      g.closest('tr').classList.add('is-drag');
    });
    preview.addEventListener('dragover', function (e) {
      if (!dragKey) return;
      var tr = e.target.closest('tr[data-key]'); if (!tr) return;
      e.preventDefault();
      $$('tr.is-over', pbody).forEach(function (t) { t.classList.remove('is-over'); });
      tr.classList.add('is-over');
    });
    preview.addEventListener('drop', function (e) {
      var tr = e.target.closest('tr[data-key]');
      if (!dragKey || !tr) return;
      e.preventDefault();
      var from = rowByKey(dragKey), to = rowByKey(tr.getAttribute('data-key'));
      if (from && to && from !== to) {
        var ls = lines();
        if (ls.indexOf(from) < ls.indexOf(to)) rows.insertBefore(from, to.nextSibling); else rows.insertBefore(from, to);
      }
      dragKey = null;
      rebuild();
    });
    preview.addEventListener('dragend', function () { dragKey = null; $$('tr.is-drag, tr.is-over', pbody).forEach(function (t) { t.classList.remove('is-drag', 'is-over'); }); });
  }

  /* ------------------------------------------------------------------ payment terms → due date */
  var terms = $('#f-terms-days'), invDate = field('invoice_date'), due = field('due_date');
  function applyTerms() {
    if (!terms || terms.value === '' || !invDate || !invDate.value) return;
    var d = new Date(invDate.value + 'T00:00:00');
    d.setDate(d.getDate() + parseInt(terms.value, 10));
    due.value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    render();
  }
  if (terms) {
    terms.addEventListener('change', applyTerms);
    if (invDate) invDate.addEventListener('change', applyTerms);
    if (due) due.addEventListener('input', function () { terms.value = ''; });
    if (due && due.value && invDate && invDate.value) {
      var days = Math.round((new Date(due.value) - new Date(invDate.value)) / 864e5);
      if ([0, 7, 15, 30, 45].indexOf(days) >= 0) terms.value = String(days);
    }
  }

  /* ------------------------------------------------------------------ pickers */
  function picker(input, drop, url, renderItem, pick) {
    var items = [], active = -1;
    var paint = function () { $$('.inv-opt', drop).forEach(function (b, i) { b.classList.toggle('on', i === active); b.setAttribute('aria-selected', i === active ? 'true' : 'false'); }); };
    var run = debounce(function () {
      fetch(url + '?q=' + encodeURIComponent(input.value.trim()), { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          items = j.data || []; active = -1;
          if (!items.length) { drop.hidden = true; input.setAttribute('aria-expanded', 'false'); return; }
          drop.innerHTML = items.map(function (it, i) { return '<button type="button" role="option" class="inv-opt" data-i="' + i + '">' + renderItem(it) + '</button>'; }).join('');
          drop.hidden = false; input.setAttribute('aria-expanded', 'true');
        }).catch(function () { drop.hidden = true; });
    }, 220);
    input.setAttribute('aria-expanded', 'false');
    input.addEventListener('input', run);
    input.addEventListener('focus', function () { if (input.value.trim() !== '' || input.id !== 'inv-cust') run(); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') e.preventDefault();
      if (drop.hidden) return;
      if (e.key === 'Escape') { drop.hidden = true; return; }
      if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(items.length - 1, active + 1); paint(); }
      if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(0, active - 1); paint(); }
      if (e.key === 'Enter' && items.length) { pick(items[active < 0 ? 0 : active]); drop.hidden = true; }
    });
    drop.addEventListener('click', function (e) { var b = e.target.closest('[data-i]'); if (!b) return; pick(items[+b.getAttribute('data-i')]); drop.hidden = true; });
    document.addEventListener('click', function (e) { if (!e.target.closest('.inv-ac')) drop.hidden = true; });
  }
  var prodRender = function (p) { return '<b>' + esc(p.name) + '</b> <small>' + esc(p.sku || '') + '</small><span>' + esc(money(p.price)) + ' · ' + esc(p.vat) + (p.stock !== null ? ' · stock ' + esc(p.stock) : '') + '</span>'; };
  var prod = $('#inv-prod');
  if (prod) picker(prod, $('#inv-prod-drop'), form.getAttribute('data-products'), prodRender, function (p) { chooseProduct(p, false); prod.value = ''; });
  var psearch = $('#ivx-p-search');
  if (psearch) picker(psearch, $('#ivx-p-drop'), form.getAttribute('data-products'), prodRender, function (p) { chooseProduct(p, true); psearch.value = ''; });
  /* ------------------------------------------------------------------ product tiles: tap a product to add it (again = one more) */
  var goBtn = $('#ivx-added-go');
  if (goBtn) goBtn.addEventListener('click', function () {
    var tab = $('#ivx-tab-prev'); if (tab && tab.offsetParent) tab.click();
    var paper = $('.ivx-paper') || $('#ivx-preview'); if (paper) paper.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
  addedSummary();
  var posBox = $('#ivx-pos');
  if (posBox) (function () {
    var grid = $('#ivx-grid', posBox), cats = $('#ivx-cats', posBox), all = [], cat = 0, term = '', demo = posBox.getAttribute('data-demo');
    function inCart() {
      var m = {};
      lines().forEach(function (r) { var f = lf(r, 'product_id'); if (f && f.value) m[f.value] = (m[f.value] || 0) + (parseFloat((lf(r, 'qty') || {}).value) || 0); });
      return m;
    }
    function tile(p, qty) {
      var oos = p.stock !== null && p.stock <= 0;
      return '<button type="button" class="tile' + (qty ? ' in' : '') + (oos ? ' oos' : '') + '" data-id="' + p.id + '" style="--h:' + ((p.cat * 47) % 360) + '" title="' + esc(p.name) + '">' +
        (qty ? '<span class="tile-qty">' + esc(qty) + '</span>' : '') +
        '<span class="tile-img has-img"><img ' + (p.img ? '' : 'class="demo-img" ') + 'src="' + esc(p.img || demo) + '" alt="" loading="lazy"></span>' +
        '<span class="tile-name">' + esc(p.name) + '</span>' + (p.sku ? '<span class="tile-sku">' + esc(p.sku) + '</span>' : '') +
        '<span class="tile-price">' + esc(money(p.price)) + '</span>' +
        (oos ? '<span class="tile-stock">Out of stock</span>' : p.stock !== null ? '<span class="tile-stock">' + esc(p.stock) + (p.unit ? ' ' + esc(p.unit) : '') + ' in stock</span>' : '') + '</button>';
    }
    function paint() {
      var have = inCart(), t = term.toLowerCase();
      var list = all.filter(function (p) { return (!cat || p.cat === cat) && (!t || (p.name + ' ' + (p.sku || '')).toLowerCase().indexOf(t) >= 0); });
      grid.innerHTML = list.length ? list.map(function (p) { return tile(p, have[p.id] || 0); }).join('') : '<div class="empty"><b>No products found</b></div>';
    }
    function mark() {
      var have = inCart();
      $$('.tile', grid).forEach(function (el) {
        var q = have[el.getAttribute('data-id')] || 0, badge = $('.tile-qty', el);
        el.classList.toggle('in', q > 0);
        if (q > 0) { if (!badge) { badge = document.createElement('span'); badge.className = 'tile-qty'; el.insertBefore(badge, el.firstChild); } badge.textContent = q; }
        else if (badge) badge.remove();
      });
    }
    tilesMark = mark;
    rows.addEventListener('input', mark);
    grid.addEventListener('click', function (e) {
      var el = e.target.closest('.tile'); if (!el) return;
      var p = all.filter(function (x) { return String(x.id) === el.getAttribute('data-id'); })[0]; if (!p) return;
      if (p.stock !== null && p.stock <= 0) { if (window.OmniUI) window.OmniUI.toast(p.name + ' is out of stock', 'warn'); return; }
      if (p.variants && p.variants.length) { chooseProduct(p, false); return; }
      var row = (!p.variants || !p.variants.length) && lines().filter(function (r) { var f = lf(r, 'product_id'); return f && f.value === String(p.id); })[0];
      if (row) {
        var q = lf(row, 'qty'); if (q && !q.readOnly) { q.value = (parseFloat(q.value) || 0) + 1; fire(q, 'input'); rebuild(); }
      } else addProduct(p, false);
      mark();
    });
    cats.addEventListener('click', function (e) {
      var b = e.target.closest('[data-c]'); if (!b) return;
      cat = +b.getAttribute('data-c');
      $$('.chip', cats).forEach(function (c) { c.classList.toggle('on', c === b); });
      paint();
    });
    // the search box above also narrows the tiles
    var s = $('#inv-prod'); if (s) s.addEventListener('input', function () { term = s.value.trim(); paint(); });
    fetch(posBox.getAttribute('data-catalog'), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var d = j.data || {};
        all = d.items || [];
        if ((d.cats || []).length > 1) {
          cats.innerHTML = '<button type="button" class="chip on" data-c="0">All <span class="n">' + all.length + '</span></button>' + d.cats.map(function (c) { return '<button type="button" class="chip" data-c="' + c.id + '">' + esc(c.name) + ' <span class="n">' + c.n + '</span></button>'; }).join('');
          cats.hidden = false;
        }
        paint();
      })
      .catch(function () { grid.innerHTML = '<div class="empty"><b>Products could not be loaded — use the search above.</b></div>'; });
  })();
  var cust = $('#inv-cust'), cid = $('#inv-cust-id'), picked = $('#inv-cust-picked');
  var custRender = function (c) { return '<b>' + esc(c.name) + '</b> <small>' + esc(c.phone || '') + '</small>' + (num(c.balance_due) > 0 ? '<span>Owes ' + esc(money(c.balance_due)) + '</span>' : ''); };
  function pickCustomer(c) {
    cid.value = c.id; cust.value = c.name; if (picked) picked.hidden = false;
    var dxIn = $('#dx-cust'); if (dxIn && document.activeElement === dxIn) dxIn.blur();   // so the paper shows the picked name
    [['bill_phone', c.phone], ['bill_email', c.email], ['bill_address', c.address]].forEach(function (x) { var el = field(x[0]); if (el) el.value = x[1] || ''; });
    render();
  }
  if (cust && !cust.readOnly) {
    cust.addEventListener('input', function () { cid.value = ''; if (picked) picked.hidden = true; });
    picker(cust, $('#inv-cust-drop'), form.getAttribute('data-customers'), custRender, pickCustomer);
    // the customer is picked on the invoice itself: type in the Bill to name to find an existing customer
    var dx = $('#dx-cust'), dxDrop = $('#dx-cust-drop');
    if (dx && dxDrop) picker(dx, dxDrop, form.getAttribute('data-customers'), custRender, pickCustomer);
    var clear = $('#inv-cust-clear');
    if (clear) clear.addEventListener('click', function () { cid.value = ''; picked.hidden = true; cust.value = ''; ['bill_phone', 'bill_email', 'bill_address'].forEach(function (n) { var el = field(n); if (el) el.value = ''; }); render(); var d = $('#dx-cust'); if (d) d.focus(); });
  }
  // typing a new name on the document also starts a new customer
  var docName = preview && preview.querySelector('[data-bind="bill_name"]');
  if (docName && cust && !cust.readOnly) docName.addEventListener('input', function () { cid.value = ''; if (picked) picked.hidden = true; });

  /* ------------------------------------------------------------------ Adjust prices (invoices.edit_price) */
  var adj = $('#adj-dlg');
  if (adj) {
    var plan = null;
    var scopeLines = function () {
      var all = (adj.querySelector('[name="adj_scope"]:checked') || {}).value === 'all';
      return lines().filter(function (r) { return r.getAttribute('data-ro') !== '1' && (all || (r.querySelector('.inv-sel') || {}).checked); });
    };
    var refresh = function () {
      var n = lines().filter(function (r) { return (r.querySelector('.inv-sel') || {}).checked; }).length;
      $('#adj-nsel').textContent = n;
      if (n === 0) adj.querySelector('[value="all"]').checked = true;
      plan = null; $('#adj-apply').disabled = true;
      $('#adj-unit').disabled = $('#adj-mode').value === 'target';
    };
    $('#adj-open').addEventListener('click', refresh);
    ['change', 'input'].forEach(function (t) { adj.addEventListener(t, function (e) { if (e.target.id !== 'adj-apply') { plan = null; $('#adj-apply').disabled = true; $('#adj-unit').disabled = $('#adj-mode').value === 'target'; } }); });
    $('#adj-preview').addEventListener('click', function () {
      var ls = scopeLines(), box = $('#adj-prev');
      if (!ls.length) { box.innerHTML = '<p class="ivx-hint">Tick the lines to change (or choose All lines).</p>'; return; }
      var params = new URLSearchParams({ mode: $('#adj-mode').value, unit: $('#adj-unit').value, value: $('#adj-value').value || '0', round: $('#adj-round').value });
      ls.forEach(function (r, i) {
        var s = lineState(r);
        params.append('lines[' + i + '][unit_price]', s.price); params.append('lines[' + i + '][qty]', s.qty);
        params.append('lines[' + i + '][discount_type]', s.dtype); params.append('lines[' + i + '][discount_value]', s.dval);
      });
      fetch(form.getAttribute('data-adjust') + '?' + params.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j.error) { box.innerHTML = '<p class="ivx-p-hint">' + esc(j.error.message) + '</p>'; return; }
          plan = { rows: ls, res: j.data || [] };
          box.innerHTML = '<table class="ivx-adj-tbl"><thead><tr><th>Line</th><th class="num">Price now</th><th class="num">New price</th><th class="num">New line total</th></tr></thead><tbody>' +
            plan.res.map(function (x, i) { return '<tr><td>' + esc(lineState(ls[i]).name) + '</td><td class="num">' + esc(money(x.old)) + '</td><td class="num"><b>' + esc(money(x.new)) + '</b></td><td class="num">' + esc(money(x.total)) + '</td></tr>'; }).join('') + '</tbody></table>';
          $('#adj-apply').disabled = false;
        })
        .catch(function () { box.innerHTML = '<p class="ivx-p-hint">Could not calculate — check your connection.</p>'; });
    });
    $('#adj-apply').addEventListener('click', function () {
      if (!plan) return;
      plan.rows.forEach(function (r, i) { var p = lf(r, 'unit_price'); if (p) p.value = plan.res[i].new; });
      plan = null;
      if (adj.close) adj.close();
      render();
    });
  }

  rebuild();
})();
