/*!
 * BizPilot360X POS — direct printing (window.OmniPrint). Set up once per device (phone, tab, laptop);
 * the choice is kept in this browser (localStorage "omnipos.printer").
 *
 *   browser    the page prints itself (no new tab): the system print dialog — any printer the
 *              device knows (laptop printers, AirPrint, Android print services). On a laptop,
 *              Chrome / Edge started with --kiosk-printing prints with no dialog at all.
 *   bluetooth  a Bluetooth (BLE) thermal printer, straight from the browser with no dialog
 *              (Web Bluetooth: Chrome / Edge on Android, Windows, macOS, ChromeOS).
 *   rawbt      the RawBT app on Android (Play Store) — Bluetooth classic, USB and Wi-Fi printers.
 *
 * Thermal printers get ESC/POS built from the receipt markup (Orders\Views\docs\receipt / kot):
 * plain lines go as printer text, lines with Bangla or other non-ASCII text as a small raster
 * image, so names print the way they are written. A4 documents always use the print dialog.
 *
 * Links to orders/:id/print?… are printed in place (a link with data-preview opens the preview page
 * instead); showReceipt() in pos.js calls printHtml()
 * after a sale when "Print after every sale" is on.
 */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var KEY = 'omnipos.printer';
  // paper: '' = the document's own size (Setup › Invoice), '58' / '80' = this device's roll width
  var DEF = { mode: 'browser', paper: '', auto: false, kot: false, device: '', name: '' };
  var ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ESC[c]; }); };
  var toast = function (m, k) { if (window.OmniUI) window.OmniUI.toast(m, k); };
  var android = /Android/i.test(navigator.userAgent);
  var hasBle = !!(navigator.bluetooth && navigator.bluetooth.requestDevice);

  // the outlet's default (Setup › Printing) sits under whatever this device changed in its own Printer setup
  function base() { return Object.assign({}, DEF, window.OMNI_PRINTER_DEFAULT || {}); }
  function own() { try { return JSON.parse(localStorage.getItem(KEY) || 'null') || {}; } catch (e) { return {}; } }
  function settings() { return Object.assign(base(), own()); }
  // only what differs from the outlet default is kept on the device, so a later change in Setup still reaches it
  function save(s) {
    try {
      var b = base(), o = {};
      Object.keys(s).forEach(function (k) { if ((k === 'device' || k === 'name') ? s[k] !== '' : s[k] !== b[k]) o[k] = s[k]; });
      if (Object.keys(o).length) localStorage.setItem(KEY, JSON.stringify(o)); else localStorage.removeItem(KEY);
    } catch (e) { /* storage blocked */ }
  }
  function usesDefault() { var o = own(); return !Object.keys(o).some(function (k) { return k !== 'device' && k !== 'name'; }); }

  /* ------------------------------------------------------------------ browser (in-page) */
  var css = '#omni-print{display:none}' +
    '@media print{html,body{height:auto!important;overflow:visible!important;background:#fff!important}' +
    'body.omni-printing>*:not(#omni-print){display:none!important}body.omni-printing #omni-print{display:block!important}' +
    '#omni-print .receipt{border:0;border-radius:0;padding:0;max-width:none;width:100%;box-shadow:none}' +
    '#omni-print .a4{box-shadow:none;padding:0;max-width:none;border-radius:0}' +
    '#omni-print .copy-mark{font:600 10px sans-serif;color:#64748b;text-align:right}#omni-print .print-copy+.print-copy{break-before:page}}';
  function area() {
    var a = $('#omni-print');
    if (!a) {
      var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
      a = document.createElement('div'); a.id = 'omni-print'; document.body.appendChild(a);
    }
    return a;
  }
  // kind: receipt | a4 | a5 | label (labels bring their own @page rules in pageCss)
  function browserPrint(html, kind, paper, pageCss) {
    return new Promise(function (resolve) {
      var a = area();
      a.innerHTML = html;
      var rc = a.querySelector('.receipt');
      if (kind === 'receipt') Array.prototype.forEach.call(a.querySelectorAll('.receipt'), function (rc) { rc.classList.remove('w58', 'w80'); rc.classList.add('w' + paper); });
      var page = $('#omni-print-page') || document.head.appendChild(Object.assign(document.createElement('style'), { id: 'omni-print-page' }));
      page.textContent = kind === 'label' ? (pageCss || '') : '@page{' + (kind === 'a4' ? 'size:A4;margin:8mm' : kind === 'a5' ? 'size:A5;margin:8mm' : 'size:' + paper + 'mm auto;margin:3mm') + '}' +
        (kind === 'a5' ? '@media print{#omni-print .a4{zoom:.71}}' : '');
      document.body.classList.add('omni-printing');
      var done = false, finish = function () { if (done) return; done = true; document.body.classList.remove('omni-printing'); resolve(); };
      window.addEventListener('afterprint', finish, { once: true });
      setTimeout(function () { window.print(); setTimeout(finish, 1500); }, 80);
    });
  }

  /* ------------------------------------------------------------------ receipt markup → lines */
  var MAP = { '৳': 'Tk ', '×': 'x', '·': '-', '—': '-', '–': '-', '“': '"', '”': '"', '‘': "'", '’': "'", '•': '*', '…': '...', ' ': ' ' };
  var clean = function (s) { return String(s).replace(/[৳×·—–“”‘’•… ]/g, function (c) { return MAP[c]; }).replace(/\s+/g, ' ').trim(); };
  // text of an element split at <br> and block children; <small> lines are indented
  function texts(el) {
    var out = [], cur = '', small = false;
    var flush = function () { var t = clean(cur); if (t) out.push({ text: (small ? '  ' : '') + t, big: false }); cur = ''; small = false; };
    (function walk(n, inSmall) {
      Array.prototype.forEach.call(n.childNodes, function (c) {
        if (c.nodeType === 3) { cur += c.nodeValue; if (inSmall && clean(c.nodeValue)) small = true; return; }
        if (c.nodeType !== 1 || c.tagName === 'svg' || c.tagName === 'SVG' || c.tagName === 'STYLE') return;
        if (c.tagName === 'BR') { flush(); return; }
        if (c.tagName === 'DIV' || c.tagName === 'P') {
          flush();
          if (c.classList.contains('big')) { var t = clean(c.textContent); if (t) out.push({ text: t, big: true }); return; }
          walk(c, inSmall); flush(); return;
        }
        if (c.classList && c.classList.contains('stamp')) { flush(); out.push({ text: '[ ' + clean(c.textContent) + ' ]', big: false, bold: true }); return; }
        walk(c, inSmall || c.tagName === 'SMALL');
      });
    })(el, false);
    flush();
    return out;
  }
  function linesOf(html) {
    var box = document.createElement('div'); box.innerHTML = html;
    var rc = box.querySelector('.receipt') || box, L = [];
    Array.prototype.forEach.call(rc.children, function (el) {
      if (el.tagName === 'HR') { L.push({ t: 'hr' }); return; }
      var center = el.classList.contains('c') || el.classList.contains('rc-title');
      if (el.classList.contains('r')) {
        var sp = el.children, left = texts(sp[0] || el), right = clean((sp[1] || {}).textContent || ''), tot = el.classList.contains('tot');
        if (!left.length) left = [{ text: '' }];
        L.push({ t: 'row', l: left[0].text, r: right, bold: tot, big: tot });
        left.slice(1).forEach(function (x) { L.push({ t: 'text', text: x.text, align: 'l' }); });
        return;
      }
      texts(el).forEach(function (x) { L.push({ t: 'text', text: x.text, align: center ? 'c' : 'l', big: x.big, bold: x.big || x.bold || el.classList.contains('rc-title') }); });
    });
    return L;
  }

  /* ------------------------------------------------------------------ lines → ESC/POS */
  function wrap(s, n) {
    var lead = /^ */.exec(s)[0];
    if (lead) return wrap(s.slice(lead.length), n - lead.length).map(function (x) { return lead + x; });
    var out = [], words = String(s).split(' '), cur = '';
    words.forEach(function (w) {
      while (w.length > n) { if (cur) { out.push(cur); cur = ''; } out.push(w.slice(0, n)); w = w.slice(n); }
      if (!cur) cur = w; else if ((cur + ' ' + w).length <= n) cur += ' ' + w; else { out.push(cur); cur = w; }
    });
    if (cur || !out.length) out.push(cur);
    return out;
  }
  var ascii = function (s) { return /^[\x20-\x7E]*$/.test(s); };
  function raster(line, dots) {
    var big = !!line.big, fs = big ? 30 : 22, h = big ? 42 : 30;
    var cv = document.createElement('canvas'); cv.width = dots; cv.height = h;
    var g = cv.getContext('2d');
    g.fillStyle = '#fff'; g.fillRect(0, 0, dots, h); g.fillStyle = '#000'; g.textBaseline = 'middle';
    g.font = (line.bold ? '700 ' : '500 ') + fs + "px 'Hind Siliguri','Noto Sans Bengali','Nirmala UI',sans-serif";
    if (line.t === 'row') {
      var rw = line.r ? g.measureText(line.r).width : 0;
      g.fillText(line.l, 0, h / 2, Math.max(40, dots - rw - 12));
      if (line.r) { g.textAlign = 'right'; g.fillText(line.r, dots, h / 2); }
    } else {
      g.textAlign = line.align === 'c' ? 'center' : 'left';
      g.fillText(line.text, line.align === 'c' ? dots / 2 : 0, h / 2, dots);
    }
    var px = g.getImageData(0, 0, dots, h).data, bw = dots / 8, out = [0x1d, 0x76, 0x30, 0x00, bw & 255, bw >> 8, h & 255, h >> 8];
    for (var y = 0; y < h; y++) {
      for (var x = 0; x < bw; x++) {
        var byte = 0;
        for (var b = 0; b < 8; b++) { var i = (y * dots + x * 8 + b) * 4; if (px[i] * 0.3 + px[i + 1] * 0.59 + px[i + 2] * 0.11 < 150) byte |= 0x80 >> b; }
        out.push(byte);
      }
    }
    return out;
  }
  function escpos(html, paper) {
    var cols = paper === '58' ? 32 : 48, dots = paper === '58' ? 384 : 576;
    var B = [0x1b, 0x40, 0x1b, 0x74, 0x00];
    var txt = function (s) { for (var i = 0; i < s.length; i++) B.push(s.charCodeAt(i) & 0x7f); B.push(0x0a); };
    var style = function (align, bold, big) { B.push(0x1b, 0x61, align === 'c' ? 1 : align === 'r' ? 2 : 0, 0x1b, 0x45, bold ? 1 : 0, 0x1d, 0x21, big ? 0x01 : 0x00); };
    linesOf(html).forEach(function (ln) {
      if (ln.t === 'hr') { style('l', false, false); txt(new Array(cols + 1).join('-')); return; }
      var plain = ln.t === 'row' ? ascii(ln.l) && ascii(ln.r) : ascii(ln.text);
      if (!plain) { style('l', false, false); Array.prototype.push.apply(B, raster(ln, dots)); return; }
      if (ln.t === 'row') {
        style('l', ln.bold, ln.big);
        var r = ln.r, room = Math.max(8, cols - r.length - 1), parts = wrap(ln.l, room);
        parts.forEach(function (p, i) { txt(i === parts.length - 1 ? p + new Array(Math.max(1, cols - p.length - r.length) + 1).join(' ') + r : p); });
        return;
      }
      style(ln.align, ln.bold, ln.big);
      wrap(ln.text, cols).forEach(txt);
    });
    style('l', false, false);
    B.push(0x1b, 0x64, 0x04, 0x1d, 0x56, 0x42, 0x00);
    return new Uint8Array(B);
  }

  /* ------------------------------------------------------------------ Bluetooth (BLE) */
  var SERVICES = ['000018f0-0000-1000-8000-00805f9b34fb', 'e7810a71-73ae-499d-8c15-faa9aef0c3f2', '49535343-fe7d-4ae5-8fa9-9fafd205e455',
    '0000ff00-0000-1000-8000-00805f9b34fb', '0000ffe0-0000-1000-8000-00805f9b34fb', '0000fee7-0000-1000-8000-00805f9b34fb', '0000ae30-0000-1000-8000-00805f9b34fb'];
  var ble = { device: null, ch: null, chunk: 100 };
  function findWritable(server) {
    return server.getPrimaryServices().then(function (list) {
      var i = 0;
      var next = function () {
        if (i >= list.length) throw new Error('This printer has no writable channel');
        return list[i++].getCharacteristics().then(function (cs) {
          for (var k = 0; k < cs.length; k++) if (cs[k].properties.writeWithoutResponse || cs[k].properties.write) return cs[k];
          return next();
        });
      };
      return next();
    });
  }
  function connect(device) {
    return device.gatt.connect().then(findWritable).then(function (ch) {
      ble.device = device; ble.ch = ch;
      device.addEventListener('gattserverdisconnected', function () { ble.ch = null; });
      return ch;
    });
  }
  function pair() {
    if (!hasBle) return Promise.reject(new Error('This browser cannot reach Bluetooth printers — use Chrome or Edge'));
    return navigator.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: SERVICES }).then(function (d) {
      return connect(d).then(function () { var s = settings(); s.device = d.id; s.name = d.name || 'Bluetooth printer'; save(s); return s.name; });
    });
  }
  function bleChannel() {
    if (ble.ch && ble.device && ble.device.gatt.connected) return Promise.resolve(ble.ch);
    if (ble.device) return connect(ble.device);
    var s = settings();
    if (s.device && navigator.bluetooth.getDevices) {
      return navigator.bluetooth.getDevices().then(function (list) {
        var d = list.filter(function (x) { return x.id === s.device; })[0];
        if (!d) throw new Error('reconnect');
        return connect(d);
      });
    }
    return Promise.reject(new Error('reconnect'));
  }
  function bleWrite(bytes) {
    return bleChannel().then(function (ch) {
      var send = function (size) {
        var off = 0;
        var step = function () {
          if (off >= bytes.length) return Promise.resolve();
          var part = bytes.slice(off, off + size); off += size;
          var p = ch.properties.writeWithoutResponse && ch.writeValueWithoutResponse ? ch.writeValueWithoutResponse(part) : ch.writeValue(part);
          return p.then(function () { return new Promise(function (r) { setTimeout(r, 12); }); }).then(step);
        };
        return step();
      };
      return send(ble.chunk).catch(function (e) { if (ble.chunk > 20) { ble.chunk = 20; return send(20); } throw e; });
    });
  }

  /* ------------------------------------------------------------------ RawBT (Android app) */
  function rawbt(bytes) {
    var bin = ''; for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    location.href = 'intent:base64,' + btoa(bin) + '#Intent;scheme=rawbt;package=ru.a402d.rawbtprinter;end;';
    return Promise.resolve();
  }

  /* ------------------------------------------------------------------ public */
  function printHtml(html, opts) {
    opts = opts || {};
    var s = settings(), kind = opts.kind || 'receipt', paper = s.paper || opts.paper || '80';
    if (kind !== 'receipt' || s.mode === 'browser') return browserPrint(html, kind, paper, opts.pageCss);
    var bytes = escposCopies(html, paper);
    if (s.mode === 'rawbt') return rawbt(bytes);
    return bleWrite(bytes).catch(function (e) {
      if (e && e.message === 'reconnect') {
        toast('Printer not connected — tap Connect in Printer setup. Using the print dialog this time.', 'warn');
      } else toast('Bluetooth print failed: ' + (e && e.message ? e.message : e) + ' — using the print dialog.', 'err');
      return browserPrint(html, kind, paper);
    });
  }
  // several copies (Setup › Invoice) arrive as .print-copy blocks: one ESC/POS job each, back to back
  function escposCopies(html, paper) {
    var box = document.createElement('div'); box.innerHTML = html;
    var copies = box.querySelectorAll('.print-copy');
    if (!copies.length) return escpos(html, paper);
    var parts = Array.prototype.map.call(copies, function (c) { return escpos(c.innerHTML, paper); });
    var out = new Uint8Array(parts.reduce(function (a, p) { return a + p.length; }, 0)), at = 0;
    parts.forEach(function (p) { out.set(p, at); at += p.length; });
    return out;
  }
  // the server picks the document's paper (X-Print-Kind receipt | a4 | a5, X-Print-Size 58 | 80 | a4 | a5)
  function printUrl(url) {
    var u = url.replace(/([?&])auto=1&?/, '$1').replace(/[?&]$/, '');
    u += (u.indexOf('?') < 0 ? '?' : '&') + 'fragment=1';
    return fetch(u, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) {
        if (!r.ok) throw new Error('Could not load the document (' + r.status + ')');
        var kind = r.headers.get('X-Print-Kind') || 'receipt', size = r.headers.get('X-Print-Size') || '80';
        return r.text().then(function (html) { return printHtml(html, { kind: kind, paper: kind === 'receipt' ? size : '' }); });
      });
  }

  var SAMPLE = '<div class="receipt"><div class="c"><div class="big">' + esc(document.documentElement.getAttribute('data-brand') || document.title) + '</div><div>Printer test · প্রিন্টার টেস্ট</div></div><hr>' +
    '<div class="r"><span>Item one<br><small>2 × ৳50.00</small></span><span>৳100.00</span></div><div class="r"><span>বিরিয়ানি</span><span>৳380.00</span></div><hr>' +
    '<div class="r tot"><span>TOTAL</span><span>৳480.00</span></div><hr><div class="c">If you can read this, printing works.</div></div>';

  /* setup sheet (works on every page: built here, not in the views) */
  function setup() {
    var s = settings(), d = $('#omni-printer');
    if (d) d.remove();
    d = document.createElement('dialog');
    d.className = 'modal-dlg m-dlg'; d.id = 'omni-printer';
    var opt = function (v, title, sub, off) { return '<label class="opt' + (s.mode === v ? ' on' : '') + (off ? ' off' : '') + '"><input type="radio" name="pr-mode" value="' + v + '"' + (s.mode === v ? ' checked' : '') + (off ? ' disabled' : '') + '><b>' + title + '</b><span>' + sub + '</span></label>'; };
    d.innerHTML = '<div class="modal-h"><h3>Printer setup</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close">✕</button></div>' +
      '<div class="modal-b"><p class="muted" style="margin:0;font-size:13px">' + (usesDefault() ? 'This device uses the outlet default (Setup › Printing). Change it here only if this device needs something different.' : 'This device has its own settings.') + ' <button type="button" class="link" data-pr="reset">Use the outlet default</button></p>' +
      '<div class="opt-grid pr-modes">' +
      opt('browser', 'Print dialog', 'Any printer this device knows — laptop printers, AirPrint, Android print service.') +
      opt('bluetooth', 'Bluetooth thermal printer', hasBle ? 'Prints straight away, no dialog. Chrome / Edge.' : 'Not available in this browser — open the POS in Chrome or Edge.', !hasBle) +
      (android ? opt('rawbt', 'RawBT app (Android)', 'Bluetooth classic, USB or Wi-Fi printers through the free RawBT app.') : '') + '</div>' +
      '<div class="pr-ble" ' + (s.mode === 'bluetooth' ? '' : 'hidden') + '><div class="pr-dev"><span id="pr-dev">' + (s.name ? esc(s.name) : 'No printer connected') + '</span><button class="btn sm primary" type="button" data-pr="pair">Connect printer</button></div></div>' +
      '<div class="field">Receipt roll on this device<div class="seg full"><button type="button" data-pr="paper" data-v="" class="' + (!s.paper ? 'on' : '') + '">As set up</button><button type="button" data-pr="paper" data-v="58" class="' + (s.paper === '58' ? 'on' : '') + '">58 mm</button><button type="button" data-pr="paper" data-v="80" class="' + (s.paper === '80' ? 'on' : '') + '">80 mm</button></div>' +
      '<small class="muted">Paper sizes per document (receipt, invoice, challan, kitchen ticket) are set in Setup › Printing.</small></div>' +
      '<label class="check"><input type="checkbox" id="pr-auto"' + (s.auto ? ' checked' : '') + '>Print the receipt after every sale</label>' +
      '<label class="check"><input type="checkbox" id="pr-kot"' + (s.kot ? ' checked' : '') + '>Also print the kitchen ticket (kitchen orders)</label>' +
      '<details class="pr-help"><summary>No dialog on a laptop?</summary><p>Make a shortcut to Chrome or Edge with <code>--kiosk-printing</code> added (e.g. <code>chrome.exe --kiosk-printing http://your-pos/sell</code>). The print dialog is then skipped and receipts go straight to the default printer. Set the receipt printer as the default printer in Windows.</p></details>' +
      '</div><div class="modal-f"><button class="btn" type="button" data-pr="test">Test print</button><button class="btn primary" type="button" data-ui="modal-close">Done</button></div>';
    document.body.appendChild(d);
    d.addEventListener('change', function (e) {
      var t = e.target, st = settings();
      if (t.name === 'pr-mode') { st.mode = t.value; d.querySelector('.pr-ble').hidden = t.value !== 'bluetooth'; Array.prototype.forEach.call(d.querySelectorAll('.pr-modes .opt'), function (o) { o.classList.toggle('on', o.querySelector('input').checked); }); }
      if (t.id === 'pr-auto') st.auto = t.checked;
      if (t.id === 'pr-kot') st.kot = t.checked;
      save(st);
    });
    d.addEventListener('click', function (e) {
      var b = e.target.closest('[data-pr]'); if (!b) return;
      var st = settings();
      if (b.dataset.pr === 'paper') { st.paper = b.dataset.v; save(st); Array.prototype.forEach.call(d.querySelectorAll('[data-pr=paper]'), function (x) { x.classList.toggle('on', x === b); }); }
      if (b.dataset.pr === 'pair') pair().then(function (n) { $('#pr-dev').textContent = n; toast('Connected to ' + n); }).catch(function (er) { if (er && er.name !== 'NotFoundError') toast(er.message || String(er), 'err'); });
      if (b.dataset.pr === 'reset') { var keep = own(); try { localStorage.setItem(KEY, JSON.stringify({ device: keep.device || '', name: keep.name || '' })); } catch (er) { /* storage blocked */ } setup(); return; }
      if (b.dataset.pr === 'test') printHtml(SAMPLE).catch(function (er) { toast(er.message || String(er), 'err'); });
    });
    d.showModal();
  }

  // print links (orders/:id/print?…) print in place instead of opening a tab
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href*="/print?"]:not([data-preview])');
    if (a && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
      e.preventDefault();
      printUrl(a.href).catch(function (er) { toast(er.message, 'err'); window.open(a.href, '_blank', 'noopener'); });
      return;
    }
    if (e.target.closest('[data-print-setup]')) { e.preventDefault(); var dl = e.target.closest('dialog[open]'); if (dl) dl.close(); setup(); }
  });
  // a paired printer is reconnected in the background so the first receipt prints straight away
  if (settings().mode === 'bluetooth' && hasBle) bleChannel().catch(function () { /* connect from setup */ });

  window.OmniPrint = { settings: settings, printHtml: printHtml, printUrl: printUrl, setup: setup, escpos: escpos };
})();
