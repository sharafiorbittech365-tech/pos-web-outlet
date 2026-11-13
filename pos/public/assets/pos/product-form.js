/*!
 * BizPilot360X product form — progressive enhancement (the server validates and can do all of it alone):
 *   [data-mx]         size x colour generator: adds one option row per size and colour
 *   [data-med-pick]   "Add from medicine database" search (Pharmacy module)
 */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ---------- size x colour generator ---------- */
  function parseList(text) {
    var out = [], seen = {};
    (text || '').split(/[\r\n,;|]+/).forEach(function (part) {
      part = part.trim();
      if (!part) return;
      var m = part.match(/^(\d{1,3})\s*(?:-|–|to)\s*(\d{1,3})$/);
      if (m && +m[1] < +m[2] && +m[2] - +m[1] <= 60) {
        for (var n = +m[1]; n <= +m[2]; n++) { if (!seen[n]) { seen[n] = 1; out.push(String(n)); } }
        return;
      }
      var k = part.toLowerCase();
      if (!seen[k]) { seen[k] = 1; out.push(part.slice(0, 48)); }
    });
    return out;
  }
  // same rule as BizPilot360X\Core\Services\VariantMatrix::code()
  function code(v) {
    v = v.trim();
    if (/^[0-9.\/ ]+$/.test(v)) return v.replace(/[^0-9]/g, '');
    var words = v.split(/[^A-Za-z0-9]+/).filter(Boolean);
    if (words.length > 1) return words.map(function (w) { return w[0]; }).join('').toUpperCase();
    var w = (words[0] || '').toUpperCase();
    if (w.length <= 3) return w;
    var cons = w[0] + w.slice(1).replace(/[AEIOU]/g, '');
    if (cons.length < 3) return w.slice(0, 3);
    return cons.length === 3 ? cons : cons[0] + cons[1] + cons[cons.length - 1];
  }
  function skuBase(v) { return (v || '').replace(/[^A-Za-z0-9-]+/g, '-').replace(/^-+|-+$/g, '').toUpperCase(); }

  var mx = $('[data-mx]');
  if (mx) {
    var form = mx.closest('form');
    var sizes = $('[data-mx-sizes]', mx), colours = $('[data-mx-colours]', mx);
    if (sizes && !sizes.value && sizes.dataset.default) sizes.value = sizes.dataset.default;
    var scale = $('[data-mx-scale]', mx);
    if (scale) scale.addEventListener('change', function () {
      var o = scale.options[scale.selectedIndex];
      if (o && o.dataset.sizes) sizes.value = o.dataset.sizes;
    });
    $$('[data-mx-colour]', mx).forEach(function (b) {
      b.addEventListener('click', function () {
        var list = parseList(colours.value);
        if (list.map(function (x) { return x.toLowerCase(); }).indexOf(b.dataset.mxColour.toLowerCase()) < 0) list.push(b.dataset.mxColour);
        colours.value = list.join(', ');
      });
    });
    var add = $('[data-mx-add]', mx);
    if (add) add.addEventListener('click', function () {
      var ss = parseList(sizes.value), cs = parseList(colours.value);
      if (!ss.length) { sizes.focus(); return; }
      var tpl = $('template[data-row-tpl="variants"]'), rows = $('[data-rows="variants"]');
      if (!tpl || !rows) return;
      var have = {};
      $$('input[name$="[name]"]', rows).forEach(function (i) { have[i.value.trim().toLowerCase()] = 1; });
      var prodSku = form ? (form.querySelector('[name=sku]') || {}).value : '';
      var base = skuBase($('[data-mx-sku]', mx).value || prodSku || '');
      var stock = $('[data-mx-stock]', mx).value || '0';
      var combos = [];
      ss.forEach(function (s) { (cs.length ? cs : [null]).forEach(function (c) { combos.push(c === null ? { Size: s } : { Size: s, Colour: c }); }); });
      if (combos.length > 500) { window.alert('That makes more than 500 options — use fewer sizes or colours.'); return; }
      var added = 0;
      combos.forEach(function (c) {
        var vals = Object.keys(c).map(function (k) { return c[k]; });
        var name = vals.join(' / ');
        if (have[name.toLowerCase()]) return;
        var i = parseInt(rows.dataset.next || String(rows.children.length), 10) || 0;
        rows.dataset.next = String(i + 1);
        rows.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__i__/g, String(i)).replace(/__v__/g, ''));
        var row = rows.lastElementChild;
        var set = function (f, v) { var el = row.querySelector('[name="variants[' + i + '][' + f + ']"]'); if (el) el.value = v; };
        set('name', name);
        set('sku', base ? [base].concat(vals.map(code)).join('-').slice(0, 64) : '');
        set('attrs', Object.keys(c).map(function (k) { return k + ': ' + c[k]; }).join(', '));
        set('stock', stock);
        added++;
      });
      // the server would build the same grid on save; the rows are now in the form instead
      sizes.value = '';
      var note = $('[data-mx-note]', mx);
      if (note) note.textContent = added + ' option' + (added === 1 ? '' : 's') + ' added below. Check stock per option, then save.';
      var variant = form && form.querySelector('input[name=stock_type][value=variant]');
      var simple = form && form.querySelector('input[name=stock_type][value=simple]');
      if (variant && simple && simple.checked && !variant.disabled) { variant.checked = true; variant.dispatchEvent(new Event('change', { bubbles: true })); }
    });
  }

  /* ---------- medicine database search ---------- */
  var mp = $('[data-med-pick]');
  if (mp) {
    var q = $('[data-med-q]', mp), box = $('[data-med-results]', mp), idIn = $('[data-med-id]', mp);
    var linked = $('[data-med-linked]', mp), label = $('[data-med-label]', mp);
    var f = mp.closest('form'), timer = null, seq = 0;
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]; }); };
    var field = function (n) { return f ? f.querySelector('[name="' + n + '"]') : null; };
    var run = function () {
      var term = q.value.trim();
      if (term.length < 2) { box.hidden = true; box.innerHTML = ''; return; }
      var my = ++seq;
      fetch(mp.dataset.url + '?q=' + encodeURIComponent(term), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (my !== seq) return;
          var list = (j && j.data) || [];
          box.innerHTML = list.length ? list.map(function (m, i) {
            return '<button type="button" role="option" class="med-opt" data-i="' + i + '"><b>' + esc(m.brand_name) + (m.strength ? ' ' + esc(m.strength) : '') + '</b>'
              + '<small>' + esc([m.dosage_form, m.generic, m.manufacturer, m.package_size].filter(Boolean).join(' · ')) + '</small>'
              + (m.linked ? '<span class="tag warn">Already a product</span>' : '') + '</button>';
          }).join('') : '<div class="muted med-none">No medicine found. Import the dataset in Super Admin, or enter the product by hand.</div>';
          box.hidden = false;
          $$('.med-opt', box).forEach(function (b) {
            b.addEventListener('click', function () {
              var m = list[+b.dataset.i];
              idIn.value = m.id;
              var nm = field('name'); if (nm) nm.value = m.name;
              var gn = field('generic_name'); if (gn && m.generic) gn.value = m.generic;
              var br = field('brand'); if (br && m.manufacturer) br.value = m.manufacturer;
              var batch = f && f.querySelector('input[name=stock_type][value=batch]');
              if (batch && !batch.disabled && !f.querySelector('[data-pane=stock] .due-box')) { batch.checked = true; batch.dispatchEvent(new Event('change', { bubbles: true })); }
              label.textContent = m.label;
              linked.hidden = false;
              box.hidden = true;
              q.value = '';
            });
          });
        })
        .catch(function () { box.hidden = true; });
    };
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 250); });
    q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); run(); } if (e.key === 'Escape') box.hidden = true; });
    var clear = $('[data-med-clear]', mp);
    if (clear) clear.addEventListener('click', function () { idIn.value = '0'; linked.hidden = true; });
  }
})();
