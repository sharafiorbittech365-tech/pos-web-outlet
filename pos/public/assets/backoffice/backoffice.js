/*!
 * BizPilot360X back office — progressive enhancement for the server-rendered forms.
 * Every form posts and validates on the server; this only adds:
 *   [data-tabs]        tab buttons [data-tab=x] show panes [data-pane=x] (remembered in the URL hash)
 *   [data-row-add=x]   append a copy of <template data-row-tpl=x> to [data-rows=x] (__i__ = next index)
 *   [data-row-del]     remove the closest [data-row]
 *   [data-show-for]    show an element only when a radio/select named in data-show-name has one of the values
 *   [data-vat]         live "price before VAT / VAT / customer pays" box and margin preview
 *   [data-chip-check]  chip label toggles "on" with its checkbox
 *   [data-print]       print the page
 *   [data-copy]        copy a value to the clipboard
 *   [data-auto-submit] submit the form when a control changes
 */
(function () {
  'use strict';
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ---------- tabs ---------- */
  function showTab(box, key) {
    var found = false;
    $$('[data-tab]', box).forEach(function (b) { var on = b.dataset.tab === key; b.classList.toggle('on', on); if (on) found = true; });
    if (!found) return false;
    $$('[data-pane]', box).forEach(function (p) { p.hidden = p.dataset.pane !== key; });
    var keep = box.querySelector('input[name=_tab]');
    if (keep) keep.value = key;
    return true;
  }
  $$('[data-tabs]').forEach(function (box) {
    var h = (location.hash || '').replace('#', '');
    var first = box.querySelector('[data-tab]');
    if (!(h && showTab(box, h)) && first) showTab(box, box.dataset.tabs || first.dataset.tab);
  });
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-tab]');
    if (t) {
      e.preventDefault();
      var box = t.closest('[data-tabs]');
      if (box && showTab(box, t.dataset.tab)) { try { history.replaceState(null, '', '#' + t.dataset.tab); } catch (x) { /* ignore */ } }
    }
    var add = e.target.closest('[data-row-add]');
    if (add) {
      e.preventDefault();
      var name = add.dataset.rowAdd;
      var tpl = document.querySelector('template[data-row-tpl="' + name + '"]');
      var list = document.querySelector('[data-rows="' + name + '"]');
      if (tpl && list) {
        var i = parseInt(list.dataset.next || String(list.children.length), 10) || 0;
        list.dataset.next = String(i + 1);
        var html = tpl.innerHTML.replace(/__i__/g, String(i));
        if (add.dataset.value) html = html.replace(/__v__/g, add.dataset.value.replace(/"/g, '&quot;'));
        else html = html.replace(/__v__/g, '');
        list.insertAdjacentHTML('beforeend', html);
        var empty = list.parentNode.querySelector('[data-rows-empty="' + name + '"]');
        if (empty) empty.hidden = true;
        var f = list.lastElementChild && list.lastElementChild.querySelector('input,select');
        if (f) f.focus();
      }
    }
    var del = e.target.closest('[data-row-del]');
    if (del) { e.preventDefault(); var row = del.closest('[data-row]'); if (row) row.remove(); }
    var set = e.target.closest('[data-set]');
    if (set && set.form) {
      e.preventDefault();
      var target = set.form.querySelector('[name="' + set.dataset.set + '"]');
      if (target) { target.value = set.dataset.v; target.dispatchEvent(new Event('change', { bubbles: true })); }
      $$('[data-set="' + set.dataset.set + '"]', set.form).forEach(function (b) { b.classList.toggle('on', b === set); });
    }
    var fill = e.target.closest('[data-fill]');
    if (fill) {
      try {
        var map = JSON.parse(fill.dataset.fill);
        Object.keys(map).forEach(function (sel) {
          var el = document.querySelector(sel);
          if (!el) return;
          if ('value' in el && el.tagName !== 'B' && el.tagName !== 'SPAN') el.value = map[sel]; else el.textContent = map[sel];
        });
      } catch (x) { /* ignore */ }
    }
    var geo = e.target.closest('[data-geo]');
    if (geo && geo.form && navigator.geolocation) {
      e.preventDefault();
      navigator.geolocation.getCurrentPosition(function (p) {
        geo.form.querySelector('[name=lat]').value = p.coords.latitude.toFixed(6);
        geo.form.querySelector('[name=lng]').value = p.coords.longitude.toFixed(6);
      }, function () { if (window.OmniUI) window.OmniUI.toast('Location is not available on this device', 'err'); });
    }
    var pr = e.target.closest('[data-print]');
    if (pr) { e.preventDefault(); document.body.classList.add('bo-print'); window.print(); setTimeout(function () { document.body.classList.remove('bo-print'); }, 500); }
    var cp = e.target.closest('[data-copy]');
    if (cp) {
      e.preventDefault();
      var v = cp.dataset.copy;
      if (navigator.clipboard) navigator.clipboard.writeText(v).then(function () { if (window.OmniUI) window.OmniUI.toast('Copied'); });
    }
  });

  /* ---------- conditional sections ---------- */
  function valueOf(name) {
    var els = $$('[name="' + name + '"]');
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (el.type === 'radio' || el.type === 'checkbox') { if (el.checked) return el.value; } else return el.value;
    }
    return '';
  }
  function syncShow() {
    $$('[data-show-for]').forEach(function (el) {
      var vals = el.dataset.showFor.split(',');
      el.hidden = vals.indexOf(valueOf(el.dataset.showName)) < 0;
    });
    $$('.opt input[type=radio]').forEach(function (r) { var l = r.closest('.opt'); if (l) l.classList.toggle('on', r.checked); });
    $$('[data-chip-check]').forEach(function (l) { var c = l.querySelector('input'); if (c) l.classList.toggle('on', c.checked); });
  }

  /* ---------- VAT / margin preview ---------- */
  function fmt(n, box) {
    var dec = parseInt(box.dataset.dec || '2', 10), sym = box.dataset.sym || '';
    var s = Math.abs(n).toFixed(dec).split('.');
    var int = s[0], last3 = int.slice(-3), rest = int.slice(0, -3);
    var g = rest ? rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + last3 : last3;
    return (n < 0 ? '-' : '') + sym + g + (s[1] ? '.' + s[1] : '');
  }
  function syncVat() {
    $$('[data-vat]').forEach(function (box) {
      var form = box.closest('form') || document;
      var price = parseFloat((form.querySelector('[name=price]') || {}).value) || 0;
      var cost = parseFloat((form.querySelector('[name=cost]') || {}).value) || 0;
      var mode = (form.querySelector('[name=tax_mode]') || {}).value || 'default';
      var rateV = (form.querySelector('[name=tax_rate]') || {}).value;
      if (mode === 'default') mode = box.dataset.inclusive === '1' ? 'inclusive' : 'exclusive';
      var rate = rateV === '' || rateV == null ? parseFloat(box.dataset.rate) || 0 : parseFloat(rateV) || 0;
      if (mode === 'exempt') rate = 0;
      var net = mode === 'inclusive' ? price / (1 + rate / 100) : price;
      var vat = mode === 'inclusive' ? price - net : mode === 'exclusive' ? price * rate / 100 : 0;
      var o = function (k) { return box.querySelector('[data-o=' + k + ']'); };
      if (o('net')) o('net').textContent = fmt(net, box);
      if (o('vat')) o('vat').textContent = fmt(vat, box);
      if (o('rate')) o('rate').textContent = mode === 'exempt' ? '(exempt)' : rate + '%';
      if (o('gross')) o('gross').textContent = fmt(net + vat, box);
      var m = document.querySelector('[data-margin]');
      if (m) {
        var pct = price > 0 ? Math.round(((net - cost) / net) * 100) : 0;
        var b = m.querySelector('b'), sp = m.querySelector('span');
        if (b) { b.textContent = pct + '%'; b.className = pct < 10 ? 'neg' : pct >= 30 ? 'pos' : ''; }
        if (sp) sp.textContent = fmt(net - cost, box) + ' profit per unit (before ' + (box.dataset.label || 'VAT') + ')';
      }
    });
  }
  document.addEventListener('input', function (e) { if (e.target.closest('form')) { syncVat(); } });
  document.addEventListener('change', function (e) {
    syncShow(); syncVat();
    var as = e.target.closest('[data-auto-submit]');
    if (as && as.form) as.form.submit();
  });
  syncShow(); syncVat();
})();
