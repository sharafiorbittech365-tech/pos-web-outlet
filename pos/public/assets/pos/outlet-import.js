/* Setup › Outlets › Import products: select all / filter / bulk opening stock, sent as one JSON field.
   Without JS the form still posts ids[] + stock[id] + vstock[id][variant]. */
(function () {
  'use strict';
  var form = document.querySelector('[data-import]');
  if (!form) { return; }
  var rows = Array.prototype.slice.call(form.querySelectorAll('[data-row]'));
  var alls = Array.prototype.slice.call(form.querySelectorAll('[data-imp-all]')); // table header + toolbar (phones)
  var q = form.querySelector('[data-imp-q]');
  var cat = form.querySelector('[data-imp-cat]');
  var count = form.querySelector('[data-imp-count]');
  var go = form.querySelector('[data-imp-go]');
  var goT = form.querySelector('[data-imp-go-t]');
  var none = form.querySelector('[data-imp-none]');

  function ck(r) { return r.querySelector('[data-imp-ck]'); }
  function selectable(r) { var c = ck(r); return c && !c.disabled; }
  function visible(r) { return !r.hidden; }
  function chosen() { return rows.filter(function (r) { return selectable(r) && ck(r).checked; }); }

  function vsum(r) {
    var s = r.querySelector('[data-imp-vsum]');
    if (!s) { return; }
    var t = 0;
    r.querySelectorAll('[data-imp-v]').forEach(function (i) { t += Number(i.value) || 0; });
    s.textContent = String(Math.round(t * 1000) / 1000);
  }

  function refresh() {
    var n = chosen().length;
    count.textContent = String(n);
    go.disabled = n === 0;
    goT.textContent = n === 0 ? 'Import products' : 'Import ' + n + ' product' + (n === 1 ? '' : 's');
    rows.forEach(function (r) { r.classList.toggle('on', selectable(r) && ck(r).checked); });
    var vis = rows.filter(function (r) { return visible(r) && selectable(r); });
    var on = vis.filter(function (r) { return ck(r).checked; }).length;
    alls.forEach(function (all) {
      all.checked = vis.length > 0 && on === vis.length;
      all.indeterminate = on > 0 && on < vis.length;
      all.disabled = vis.length === 0;
    });
  }

  function filter() {
    var term = q ? q.value.trim().toLowerCase() : '';
    var c = cat ? cat.value : '';
    var shown = 0;
    rows.forEach(function (r) {
      var ok = (!term || r.getAttribute('data-q').indexOf(term) !== -1) && (!c || r.getAttribute('data-cat') === c);
      r.hidden = !ok;
      if (ok) { shown++; }
    });
    if (none) { none.hidden = shown > 0; }
    refresh();
  }

  // select all = every product currently shown (search / category) that is not already in the outlet
  alls.forEach(function (all) {
    all.addEventListener('change', function () {
      rows.forEach(function (r) { if (visible(r) && selectable(r)) { ck(r).checked = all.checked; } });
      refresh();
    });
  });
  if (q) { q.addEventListener('input', filter); }
  if (cat) { cat.addEventListener('change', filter); }

  form.addEventListener('change', function (e) {
    if (e.target.matches('[data-imp-ck]')) { refresh(); }
  });
  // typing an opening stock selects the product
  form.addEventListener('input', function (e) {
    var r = e.target.closest('[data-row]');
    if (!r || !e.target.matches('.imp-q')) { return; }
    vsum(r);
    if (Number(e.target.value) > 0 && selectable(r) && !ck(r).checked) { ck(r).checked = true; refresh(); }
  });

  function setQty(fromSource) {
    var bulk = form.querySelector('[data-imp-bulk]');
    var v = Math.max(0, Number(bulk.value) || 0);
    var list = chosen();
    if (list.length === 0) { alert('Select the products first.'); return; }
    list.forEach(function (r) {
      r.querySelectorAll('.imp-q').forEach(function (i) { i.value = fromSource ? (i.getAttribute('data-src') || '0') : String(v); });
      vsum(r);
    });
  }
  form.querySelector('[data-imp-apply]').addEventListener('click', function () { setQty(false); });
  form.querySelector('[data-imp-copy]').addEventListener('click', function () { setQty(true); });

  form.addEventListener('submit', function (e) {
    var list = chosen();
    if (list.length === 0) { e.preventDefault(); return; }
    var items = {};
    list.forEach(function (r) {
      var it = {};
      var simple = r.querySelector('[data-imp-q-in]');
      if (simple) { it.q = Math.max(0, Number(simple.value) || 0); }
      var vs = r.querySelectorAll('[data-imp-v]');
      if (vs.length) {
        it.v = {};
        vs.forEach(function (i) { it.v[i.getAttribute('data-imp-v')] = Math.max(0, Number(i.value) || 0); });
      }
      items[r.getAttribute('data-id')] = it;
    });
    form.querySelector('[data-items]').value = JSON.stringify(items);
    // one field instead of thousands (PHP max_input_vars)
    form.querySelectorAll('[data-imp-ck], .imp-q').forEach(function (i) { i.disabled = true; });
    go.disabled = true;
    goT.textContent = 'Importing ' + list.length + ' product' + (list.length === 1 ? '' : 's') + '…';
  });

  // back to this page from the browser history: the inputs disabled on submit work again
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) { return; }
    form.querySelectorAll('[data-imp-ck]:not([data-has]), .imp-q').forEach(function (i) { i.disabled = false; });
    refresh();
  });

  rows.forEach(vsum);
  filter();
})();
