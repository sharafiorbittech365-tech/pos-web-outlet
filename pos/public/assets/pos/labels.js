/*!
 * BizPilot360X POS — Products › Print labels. Builds barcode / QR labels in the browser from #lbl-config
 * (LabelService) and prints them with assets/pos/print.js (in-page print, the label size as @page).
 *
 *   Code 128  own encoder below: code set C for even-length digit codes (EAN-style), else set B
 *   QR        qrcode-generator 1.4.4 (vendor/, MIT), drawn as an SVG path
 * The code on a label is the one the till looks up: product barcode / SKU, variant SKU.
 */
(function () {
  'use strict';
  var CFG = JSON.parse(document.getElementById('lbl-config').textContent);
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ESC[c]; }); };
  var CUR = CFG.currency || {}, DEC = CUR.decimals != null ? +CUR.decimals : 2, SYM = CUR.symbol || '৳';
  function money(n) {
    var parts = Math.abs(+n || 0).toFixed(DEC).split('.'), i = parts[0], last3 = i.slice(-3), rest = i.slice(0, -3);
    return (n < 0 ? '-' : '') + SYM + (rest ? rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + last3 : last3) + (parts[1] ? '.' + parts[1] : '');
  }

  /* ------------------------------------------------------------------ Code 128 */
  var C128 = ['212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
    '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321', '232121',
    '111323', '131123', '131321', '112313', '132113', '132311', '211313', '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
    '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224', '111422', '121124', '121421',
    '141122', '141221', '112214', '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111', '111242', '121142', '121241', '114212',
    '124112', '124211', '411212', '421112', '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141', '114131',
    '311141', '411131', '211412', '211214', '211232', '2331112'];
  /** Code 128 symbol values (start, data, checksum, stop) for a printable-ASCII code; null if it cannot be encoded. */
  function code128(text) {
    if (!text || !/^[\x20-\x7E]+$/.test(text)) return null;
    var vals;
    if (/^\d+$/.test(text) && text.length % 2 === 0 && text.length >= 4) {
      vals = [105];
      for (var i = 0; i < text.length; i += 2) vals.push(+text.substr(i, 2));
    } else {
      vals = [104];
      for (var j = 0; j < text.length; j++) vals.push(text.charCodeAt(j) - 32);
    }
    var sum = vals[0];
    for (var k = 1; k < vals.length; k++) sum += vals[k] * k;
    vals.push(sum % 103, 106);
    return vals;
  }
  /** Barcode as SVG (bars as one path), filling its box. */
  function barcodeSvg(text, color) {
    var vals = code128(text);
    if (!vals) return '<div class="lbl-bad">Code cannot be printed as a barcode</div>';
    var x = 10, d = '';
    vals.forEach(function (v) {
      var p = C128[v];
      for (var i = 0; i < p.length; i++) {
        var w = +p[i];
        if (i % 2 === 0) d += 'M' + x + ' 0h' + w + 'v1h-' + w + 'z';
        x += w;
      }
    });
    return '<svg class="lbl-bc" viewBox="0 0 ' + (x + 10) + ' 1" preserveAspectRatio="none" aria-hidden="true"><path fill="' + esc(color) + '" d="' + d + '"/></svg>';
  }
  function qrSvg(text, color) {
    if (typeof window.qrcode !== 'function' || !text) return '';
    var q = window.qrcode(0, 'M');
    q.addData(unescape(encodeURIComponent(text)), 'Byte');
    q.make();
    var n = q.getModuleCount(), d = '';
    for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) if (q.isDark(r, c)) d += 'M' + c + ' ' + r + 'h1v1h-1z';
    return '<svg class="lbl-qr" viewBox="-1 -1 ' + (n + 2) + ' ' + (n + 2) + '" aria-hidden="true"><path fill="' + esc(color) + '" d="' + d + '"/></svg>';
  }

  /* ------------------------------------------------------------------ state */
  var items = CFG.items, byKey = {}, qty = {}, t = CFG.template;
  items.forEach(function (it) { byKey[it.key] = it; });
  if (CFG.selected && CFG.selected.length) {
    items.forEach(function (it) { if (CFG.selected.indexOf(it.product_id) >= 0) qty[it.key] = 1; });
  }
  var q = '', serials = [];

  function readForm() {
    var f = $('#lbl-form'), fd = new FormData(f), out = { fields: {}, colors: {} };
    fd.forEach(function (v, k) {
      var m = /^(fields|colors)\[(\w+)\]$/.exec(k);
      if (m) out[m[1]][m[2]] = m[1] === 'fields' ? true : v; else out[k] = v;
    });
    var p = CFG.presets[out.preset] || CFG.presets['roll-50x30'];
    out.border = !!out.border;
    var cust = out.preset === 'custom' || out.preset === 'sheet-custom';
    out.w = cust ? Math.max(15, Math.min(120, +out.w || 50)) : p.w;
    out.h = cust ? Math.max(10, Math.min(120, +out.h || 30)) : p.h;
    out.sheet = !!p.sheet;
    out.cols = out.preset === 'sheet-custom' ? Math.max(1, Math.min(8, parseInt(out.cols, 10) || 3)) : (p.cols || 1);
    out.start = out.sheet ? Math.max(1, Math.min(200, parseInt(out.start, 10) || 1)) : 1;
    out.fontSize = Math.max(6, Math.min(16, +out.fontSize || 9));
    $$('.lbl-custom').forEach(function (el) { el.hidden = !cust; });
    $$('.lbl-sheetcustom').forEach(function (el) { el.hidden = out.preset !== 'sheet-custom'; });
    $$('.lbl-sheetonly').forEach(function (el) { el.hidden = !out.sheet; });
    return out;
  }

  /* ------------------------------------------------------------------ one label */
  function labelHTML(it, d) {
    var F = d.fields, fs = d.fontSize, code = it.code || '';
    var showBc = (d.codeType === 'barcode' || d.codeType === 'both') && code, showQr = (d.codeType === 'qr' || d.codeType === 'both') && code;
    var lines = [];
    if (F.business && CFG.shop) lines.push('<div class="lbl-shop">' + esc(CFG.shop) + '</div>');
    if (it.serial) { if (it.name) lines.push('<div class="lbl-name">' + esc(it.name) + '</div>'); }
    else if (F.name) lines.push('<div class="lbl-name">' + esc(it.name) + '</div>');
    if (F.variant && it.variant && !it.serial) lines.push('<div class="lbl-var">' + esc(it.variant) + '</div>');
    var price = '';
    if (F.price && !it.serial) price = '<span class="lbl-price" style="color:' + esc(d.colors.price) + '">' + money(it.price) + (F.unit && it.unit ? '<small>/' + esc(it.unit) + '</small>' : '') + '</span>';
    if (F.compare && !it.serial && it.compare && it.compare > it.price) price += ' <s class="lbl-old">' + money(it.compare) + '</s>';
    var codeTxt = (F.code || it.serial) && code ? '<div class="lbl-code">' + esc(code) + '</div>' : '';
    var style = 'width:' + d.w + 'mm;height:' + d.h + 'mm;font-size:' + fs + 'pt;color:' + esc(d.colors.text) + ';background:' + esc(d.colors.bg) + ';' + (d.border ? 'border:0.3mm solid ' + esc(d.colors.border) + ';' : '');
    var body;
    if (showQr && !showBc) {
      body = '<div class="lbl-row"><div class="lbl-qrbox">' + qrSvg(code, d.colors.text) + '</div><div class="lbl-txt">' + lines.join('') + (price ? '<div>' + price + '</div>' : '') + codeTxt + '</div></div>';
    } else {
      body = lines.join('') + (price || showQr ? '<div class="lbl-mid">' + (price ? '<div>' + price + '</div>' : '') + (showQr ? '<div class="lbl-qrbox sm">' + qrSvg(code, d.colors.text) + '</div>' : '') + '</div>' : '') +
        (showBc ? '<div class="lbl-bcbox">' + barcodeSvg(code, d.colors.text) + '</div>' : '') + codeTxt;
    }
    return '<div class="lbl" style="' + style + '">' + body + '</div>';
  }
  var LBL_CSS = '.lbl{box-sizing:border-box;overflow:hidden;display:flex;flex-direction:column;justify-content:center;gap:.4mm;padding:1.2mm 1.6mm;font-family:Inter,Arial,sans-serif;line-height:1.15;text-align:center}' +
    '.lbl-shop{font-size:.8em;font-weight:600;opacity:.8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
    '.lbl-name{font-weight:700;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}' +
    '.lbl-var{font-size:.9em}.lbl-price{font-weight:800;font-size:1.25em}.lbl-price small{font-size:.65em;font-weight:600}.lbl-old{font-size:.85em;opacity:.7}' +
    '.lbl-code{font:600 .85em/1 ui-monospace,Consolas,monospace;letter-spacing:.06em}' +
    '.lbl-bcbox{flex:1 1 auto;min-height:5mm;max-height:14mm;display:flex}.lbl-bc{width:100%;height:100%;display:block}' +
    '.lbl-mid{display:flex;align-items:center;justify-content:center;gap:2mm}.lbl-qrbox{flex:none}.lbl-qrbox .lbl-qr{display:block;width:100%;height:100%}' +
    '.lbl-qrbox.sm{width:9mm;height:9mm}.lbl-row{display:flex;align-items:center;gap:1.6mm;height:100%;text-align:left}' +
    '.lbl-skip{box-sizing:border-box}.lbl-row .lbl-qrbox{height:100%;aspect-ratio:1}.lbl-txt{min-width:0;display:grid;gap:.4mm}.lbl-bad{font-size:.75em;color:#b91c1c}';

  /* ------------------------------------------------------------------ list */
  function total() { return Object.keys(qty).reduce(function (a, k) { return a + (+qty[k] || 0); }, 0) + serials.length; }
  function renderList() {
    var ql = q.toLowerCase();
    var shown = items.filter(function (it) { return !ql || (it.name + ' ' + it.variant + ' ' + it.code + ' ' + it.category).toLowerCase().indexOf(ql) >= 0; });
    var html = shown.slice(0, 400).map(function (it) {
      var on = qty[it.key] > 0;
      return '<div class="lbl-item' + (on ? ' on' : '') + '" role="listitem"><label class="lbl-pick"><input type="checkbox" data-key="' + esc(it.key) + '"' + (on ? ' checked' : '') + ' aria-label="Print labels for ' + esc(it.name) + '">' +
        '<span class="grow"><b>' + esc(it.name) + '</b>' + (it.variant ? ' <span class="muted">· ' + esc(it.variant) + '</span>' : '') + '<small>' + (it.code ? '<code>' + esc(it.code) + '</code>' : '<span class="neg">no code</span>') + ' · ' + money(it.price) + (it.stock != null ? ' · ' + (+it.stock) + ' in stock' : '') + '</small></span></label>' +
        '<input class="input lbl-qty" type="number" min="0" max="999" data-qty="' + esc(it.key) + '" value="' + (qty[it.key] || 0) + '" aria-label="Number of labels"></div>';
    }).join('');
    $('#lbl-list').innerHTML = html || '<div class="empty"><b>Nothing found</b></div>';
    if (shown.length > 400) $('#lbl-list').insertAdjacentHTML('beforeend', '<p class="muted" style="padding:8px 12px">Showing 400 of ' + shown.length + ' — search to narrow down.</p>');
    renderTotals();
  }
  function renderTotals() {
    var n = total();
    $('#lbl-count').textContent = n + ' label' + (n === 1 ? '' : 's');
    var d0 = readForm(), perPage = d0.sheet ? Math.floor((297 - 10) / d0.h) * d0.cols : 0;
    var pg = $('#lbl-pages'), sheets = d0.sheet ? Math.ceil((n + d0.start - 1) / perPage) : 0;
    if (pg) pg.textContent = !n ? '' : d0.sheet ? n + ' label' + (n === 1 ? '' : 's') + ' \u00b7 ' + perPage + ' per A4 sheet \u00b7 ' + sheets + ' sheet' + (sheets === 1 ? '' : 's') + ' (printed in order, left to right)' : n + ' label' + (n === 1 ? '' : 's') + ' \u00b7 one per page on the label roll';
    var btn = $('[data-lbl="print"]');
    btn.disabled = n === 0;
    btn.querySelector('span').textContent = n ? 'Print ' + n + ' label' + (n === 1 ? '' : 's') : 'Print labels';
    var missing = Object.keys(qty).filter(function (k) { return qty[k] > 0 && !byKey[k].code; });
    var form = $('#lbl-codes');
    if (form) {
      form.hidden = !missing.length;
      $('#lbl-missing-n').textContent = missing.length;
      $$('input[name="keys[]"], input[name="ids[]"]', form).forEach(function (x) { x.remove(); });
      missing.forEach(function (k) { form.insertAdjacentHTML('beforeend', '<input type="hidden" name="keys[]" value="' + esc(k) + '">'); });
      var pids = {}; Object.keys(qty).forEach(function (k) { if (qty[k] > 0) pids[byKey[k].product_id] = 1; });
      Object.keys(pids).forEach(function (id) { form.insertAdjacentHTML('beforeend', '<input type="hidden" name="ids[]" value="' + esc(id) + '">'); });
    }
  }
  function renderPreview() {
    var d = readForm();
    var first = items.filter(function (it) { return qty[it.key] > 0; })[0] || serials[0] || items[0] || { name: 'Sample product', variant: 'Size 42', code: '2000100000017', price: 1250, compare: 1500, unit: 'pcs' };
    $('#lbl-preview').innerHTML = '<style>' + LBL_CSS + '</style>' + labelHTML(first, d);
  }

  /* ------------------------------------------------------------------ print */
  function print() {
    var d = readForm(), labels = [];
    items.forEach(function (it) { for (var i = 0; i < (+qty[it.key] || 0); i++) labels.push(labelHTML(it, d)); });
    serials.forEach(function (it) { labels.push(labelHTML(it, d)); });
    if (!labels.length) return;
    var html, pageCss;
    if (d.sheet) {
      // A4 sheet: labels in a grid, centred on the page
      var rowsPerPage = Math.floor((297 - 10) / d.h), perPage = rowsPerPage * d.cols, pages = [];
      // "start at label no. N": skip the first N-1 places (a sheet that is already part used)
      for (var sk = 1; sk < d.start; sk++) labels.unshift('<div class="lbl-skip" style="width:' + d.w + 'mm;height:' + d.h + 'mm"></div>');
      for (var i = 0; i < labels.length; i += perPage) pages.push('<div class="lbl-sheet" style="grid-template-columns:repeat(' + d.cols + ',' + d.w + 'mm);grid-auto-rows:' + d.h + 'mm">' + labels.slice(i, i + perPage).join('') + '</div>');
      html = '<style>' + LBL_CSS + '.lbl-sheet{display:grid;justify-content:center;align-content:start}.lbl-sheet+.lbl-sheet{break-before:page}</style>' + pages.join('');
      pageCss = '@page{size:A4;margin:5mm 0}';
    } else {
      // roll: one label per page, the page is the label
      html = '<style>' + LBL_CSS + '.lbl+.lbl{break-before:page}</style>' + labels.join('');
      pageCss = '@page{size:' + d.w + 'mm ' + d.h + 'mm;margin:0}';
    }
    if (window.OmniPrint) window.OmniPrint.printHtml(html, { kind: 'label', pageCss: pageCss });
  }
  function save() {
    var d = readForm(), body = { preset: d.preset, w: d.w, h: d.h, cols: d.cols, codeType: d.codeType, fontSize: d.fontSize, fields: d.fields, colors: d.colors, border: d.border ? 1 : 0 };
    var csrf = ($('meta[name="X-CSRF-TOKEN"]') || {}).content || '';
    fetch(CFG.saveUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body) })
      .then(function (r) {
        var nt = r.headers.get('X-CSRF-TOKEN'); if (nt) { var m = $('meta[name="X-CSRF-TOKEN"]'); if (m) m.content = nt; }
        if (window.OmniUI) window.OmniUI.toast(r.ok ? 'Label design saved' : 'Could not save the design', r.ok ? '' : 'err');
      });
  }

  /* ------------------------------------------------------------------ serial codes */
  function serialSpec() {
    var f = $('#lbl-serial'), g = function (n) { return f.elements[n].value; };
    var pad = Math.max(0, Math.min(10, parseInt(g('pad'), 10) || 0));
    return { prefix: g('prefix').replace(/[^\x20-\x7E]/g, '').slice(0, 16), from: Math.max(0, parseInt(g('from'), 10) || 0), count: Math.max(1, Math.min(2000, parseInt(g('count'), 10) || 1)),
      pad: pad, step: Math.max(1, Math.min(1000, parseInt(g('step'), 10) || 1)), copies: Math.max(1, Math.min(20, parseInt(g('copies'), 10) || 1)), caption: g('caption').trim() };
  }
  function serialCode(sp, i) { var n = String(sp.from + i * sp.step); while (n.length < sp.pad) n = '0' + n; return sp.prefix + n; }
  function serialPreview() {
    var sp = serialSpec(), el = $('#lbl-serial-prev'); if (!el) return;
    var show = []; for (var i = 0; i < Math.min(3, sp.count); i++) show.push(serialCode(sp, i));
    el.textContent = show.join(', ') + (sp.count > 3 ? ' \u2026 ' + serialCode(sp, sp.count - 1) : '');
  }
  function serialAdd() {
    var sp = serialSpec(), made = [];
    for (var i = 0; i < sp.count; i++) { var c = serialCode(sp, i); for (var k = 0; k < sp.copies; k++) made.push({ key: 'serial:' + c + ':' + k, serial: true, name: sp.caption, variant: '', code: c, price: 0 }); }
    if (serials.length + made.length > 4000) { if (window.OmniUI) window.OmniUI.toast('Up to 4,000 serial labels at a time', 'warn'); return; }
    serials = serials.concat(made);
    serialStatus();
  }
  function serialStatus() {
    var el = $('#lbl-serial-n'); if (el) el.textContent = serials.length ? serials.length + ' queued \u00b7 ' + serials[0].code + (serials.length > 1 ? ' \u2192 ' + serials[serials.length - 1].code : '') : 'none queued';
    renderTotals(); renderPreview();
  }

  /* ------------------------------------------------------------------ events */
  document.addEventListener('change', function (e) {
    var k = e.target.dataset.key;
    if (k) { qty[k] = e.target.checked ? Math.max(1, +qty[k] || 0) : 0; renderList(); renderPreview(); return; }
    if (e.target.dataset.qty) { qty[e.target.dataset.qty] = Math.max(0, Math.min(999, parseInt(e.target.value, 10) || 0)); renderList(); return; }
    if (e.target.closest('#lbl-form')) { renderPreview(); renderTotals(); }
  });
  document.addEventListener('input', function (e) {
    if (e.target.id === 'lbl-q') { q = e.target.value.trim(); renderList(); return; }
    if (e.target.closest('#lbl-serial')) { serialPreview(); return; }
    if (e.target.closest('#lbl-form')) renderPreview();
  });
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-lbl]'); if (!b) return;
    var a = b.dataset.lbl, ql = q.toLowerCase();
    var shown = items.filter(function (it) { return !ql || (it.name + ' ' + it.variant + ' ' + it.code + ' ' + it.category).toLowerCase().indexOf(ql) >= 0; });
    if (a === 'pick-shown') shown.forEach(function (it) { if (!(qty[it.key] > 0)) qty[it.key] = 1; });
    if (a === 'qty-stock') Object.keys(qty).forEach(function (k) { if (qty[k] > 0 && byKey[k].stock != null) qty[k] = Math.max(1, Math.min(999, Math.round(byKey[k].stock))); });
    if (a === 'clear') qty = {};
    if (a === 'serial-add') return serialAdd();
    if (a === 'serial-clear') { serials = []; return serialStatus(); }
    if (a === 'print') return print();
    if (a === 'save') return save();
    renderList(); renderPreview();
  });

  renderList();
  serialPreview();
  // the QR library loads with defer before this script; draw the preview once it is there
  renderPreview();
  window.OmniLabels = { code128: code128, barcodeSvg: barcodeSvg, qrSvg: qrSvg };
})();
