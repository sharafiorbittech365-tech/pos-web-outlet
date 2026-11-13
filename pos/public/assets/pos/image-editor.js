/*
 * Picture editor for every image upload in the POS.
 * Choosing a picture opens an editor: crop (with ratios), rotate, flip, remove the background (AI, or "quick" for a plain
 * background), change the background (transparent, white, colour, picture), brightness / contrast / saturation.
 * "Apply" hands the form a compressed picture (WebP, scaled to the chosen size); the server compresses once more and
 * makes the thumbnail. Opt out per input with data-no-edit. API: OmniImageEditor.edit(file, {accept}) -> Promise<File|null>.
 */
(function () {
  'use strict';
  var AI_URL = 'https://cdn.jsdelivr.net/npm/@imgly/background-removal@1.5.5/+esm';
  var WORK_MAX = 2400;
  var aiModule = null;

  function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
  function canvas(w, h) { var c = document.createElement('canvas'); c.width = Math.max(1, Math.round(w)); c.height = Math.max(1, Math.round(h)); return c; }
  function clone(c) { var n = canvas(c.width, c.height); n.getContext('2d').drawImage(c, 0, 0); return n; }
  function kb(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }

  function loadImage(file) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file), img = new Image();
      img.onload = function () {
        var s = Math.min(1, WORK_MAX / Math.max(img.naturalWidth, img.naturalHeight));
        var c = canvas(img.naturalWidth * s, img.naturalHeight * s);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); // the browser applies EXIF rotation itself
        URL.revokeObjectURL(url); resolve(c);
      };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('This picture cannot be opened.')); };
      img.src = url;
    });
  }

  /** Makes the colour of the (plain) border transparent: flood fill from the edges within a tolerance. */
  function quickRemove(c, tol) {
    var w = c.width, h = c.height, ctx = c.getContext('2d'), im = ctx.getImageData(0, 0, w, h), d = im.data;
    var pts = [[0, 0], [w - 1, 0], [0, h - 1], [w - 1, h - 1], [w >> 1, 0], [w >> 1, h - 1], [0, h >> 1], [w - 1, h >> 1]], r = 0, g = 0, b = 0;
    pts.forEach(function (p) { var i = (p[1] * w + p[0]) * 4; r += d[i]; g += d[i + 1]; b += d[i + 2]; });
    r /= pts.length; g /= pts.length; b /= pts.length;
    var lim = tol * tol * 3, seen = new Uint8Array(w * h), stack = [];
    function near(i) { var dr = d[i] - r, dg = d[i + 1] - g, db = d[i + 2] - b; return d[i + 3] > 0 && dr * dr + dg * dg + db * db <= lim; }
    function push(x, y) { var k = y * w + x; if (!seen[k] && near(k * 4)) { seen[k] = 1; stack.push(k); } }
    for (var x = 0; x < w; x++) { push(x, 0); push(x, h - 1); }
    for (var y = 0; y < h; y++) { push(0, y); push(w - 1, y); }
    while (stack.length) {
      var k = stack.pop(), px = k % w, py = (k / w) | 0;
      if (px > 0) push(px - 1, py); if (px < w - 1) push(px + 1, py); if (py > 0) push(px, py - 1); if (py < h - 1) push(px, py + 1);
    }
    var cleared = 0;
    for (var i = 0; i < seen.length; i++) if (seen[i]) { d[i * 4 + 3] = 0; cleared++; }
    // soften the cut edge by one pixel
    for (var yy = 1; yy < h - 1; yy++) for (var xx = 1; xx < w - 1; xx++) {
      var kk = yy * w + xx;
      if (!seen[kk] && (seen[kk - 1] || seen[kk + 1] || seen[kk - w] || seen[kk + w])) d[kk * 4 + 3] = Math.min(d[kk * 4 + 3], 150);
    }
    ctx.putImageData(im, 0, 0);
    return cleared / (w * h);
  }

  function edit(file, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var accept = String(opts.accept || ''), tool = 'crop';
      var st = { src: null, hist: [], b: 100, c: 100, s: 100, bg: { mode: 'none', color: '#ffffff', img: null }, side: 1400, ratio: 0, crop: null };
      var ov = el('div', 'ie-ov'), box = el('div', 'ie-box'); ov.appendChild(box);
      box.innerHTML =
        '<div class="ie-head"><b class="ie-title"></b><span class="ie-info"></span><button type="button" class="ie-x" data-a="cancel" aria-label="Close">&times;</button></div>' +
        '<div class="ie-main"><div class="ie-stage"><div class="ie-cw"><canvas class="ie-cv"></canvas><div class="ie-crop" hidden><i data-h="nw"></i><i data-h="ne"></i><i data-h="sw"></i><i data-h="se"></i></div></div><div class="ie-busy" hidden></div></div>' +
        '<div class="ie-side">' +
        '<div class="ie-tabs"><button type="button" data-t="crop" class="on">Crop</button><button type="button" data-t="rotate">Rotate</button><button type="button" data-t="bg">Background</button><button type="button" data-t="adjust">Adjust</button></div>' +
        '<div class="ie-pane" data-p="crop"><label>Shape<select data-f="ratio"><option value="0">Free</option><option value="1">Square 1:1</option><option value="1.3333">4:3</option><option value="0.75">3:4</option><option value="1.7778">16:9</option><option value="orig">Original</option></select></label><button type="button" class="ie-btn" data-a="crop">Apply crop</button><small>Drag the frame, pull a corner to resize.</small></div>' +
        '<div class="ie-pane" data-p="rotate" hidden><div class="ie-row"><button type="button" class="ie-btn" data-a="rl">&#8634; Left</button><button type="button" class="ie-btn" data-a="rr">&#8635; Right</button></div><div class="ie-row"><button type="button" class="ie-btn" data-a="fh">&#8596; Flip</button><button type="button" class="ie-btn" data-a="fv">&#8597; Flip</button></div></div>' +
        '<div class="ie-pane" data-p="bg" hidden><button type="button" class="ie-btn pri" data-a="ai">Remove background (AI)</button><small>Best result; the first use downloads a model (about 40 MB) and needs internet.</small>' +
        '<label>Quick remove — plain background<input type="range" min="8" max="90" value="38" data-f="tol"></label><button type="button" class="ie-btn" data-a="quick">Quick remove</button>' +
        '<hr><b>New background</b><div class="ie-row"><button type="button" class="ie-btn" data-a="bg-none">Transparent</button><button type="button" class="ie-btn" data-a="bg-white">White</button></div>' +
        '<label>Colour<input type="color" value="#ffffff" data-f="bgcolor"></label><label>Picture<input type="file" accept="image/*" data-f="bgimg" data-no-edit></label></div>' +
        '<div class="ie-pane" data-p="adjust" hidden><label>Brightness<input type="range" min="50" max="150" value="100" data-f="b"></label><label>Contrast<input type="range" min="50" max="150" value="100" data-f="c"></label><label>Saturation<input type="range" min="0" max="200" value="100" data-f="s"></label><button type="button" class="ie-btn" data-a="adj-reset">Reset</button></div>' +
        '<div class="ie-out"><label>Final size<select data-f="side"><option value="800">Small — 800 px</option><option value="1000">1000 px</option><option value="1400" selected>Standard — 1400 px</option><option value="1920">Large — 1920 px</option></select></label><small>Saved as a compressed WebP.</small></div>' +
        '</div></div>' +
        '<div class="ie-foot"><button type="button" class="ie-btn" data-a="undo">Undo</button><button type="button" class="ie-btn" data-a="reset">Reset</button><span class="ie-sp"></span><button type="button" class="ie-btn" data-a="skip">Use original</button><button type="button" class="ie-btn" data-a="cancel">Cancel</button><button type="button" class="ie-btn pri" data-a="apply">Apply &amp; compress</button></div>';
      box.querySelector('.ie-title').textContent = (opts.label ? opts.label + ' · ' : '') + file.name;
      var cv = box.querySelector('.ie-cv'), cw = box.querySelector('.ie-cw'), cropEl = box.querySelector('.ie-crop'), busy = box.querySelector('.ie-busy'), info = box.querySelector('.ie-info');
      document.body.appendChild(ov); document.documentElement.classList.add('ie-open');

      function q(sel) { return box.querySelector(sel); }
      function setBusy(t) { busy.hidden = !t; busy.textContent = t || ''; }
      function push() { st.hist.push(clone(st.src)); if (st.hist.length > 8) st.hist.shift(); }
      function fit() { var stg = q('.ie-stage'), mw = stg.clientWidth - 16, mh = stg.clientHeight - 16, s = Math.min(mw / st.src.width, mh / st.src.height, 1); return { w: Math.max(40, Math.floor(st.src.width * s)), h: Math.max(40, Math.floor(st.src.height * s)) }; }
      function render() {
        var f = fit(); cv.width = f.w; cv.height = f.h; cw.style.width = f.w + 'px'; cw.style.height = f.h + 'px';
        var x = cv.getContext('2d'); x.clearRect(0, 0, f.w, f.h); paint(x, f.w, f.h);
        info.textContent = st.src.width + ' × ' + st.src.height + ' px';
      }
      function paint(x, w, h) {
        if (st.bg.mode === 'color') { x.fillStyle = st.bg.color; x.fillRect(0, 0, w, h); }
        else if (st.bg.mode === 'image' && st.bg.img) { var i = st.bg.img, s = Math.max(w / i.width, h / i.height); x.drawImage(i, (w - i.width * s) / 2, (h - i.height * s) / 2, i.width * s, i.height * s); }
        x.save(); x.filter = 'brightness(' + st.b + '%) contrast(' + st.c + '%) saturate(' + st.s + '%)'; x.drawImage(st.src, 0, 0, w, h); x.restore();
      }
      function hasAlpha() { return st.bg.mode === 'none'; }

      // ---- crop frame
      function showCrop(on) {
        cropEl.hidden = !on;
        if (!on) return;
        var w = cv.width, h = cv.height, cw0 = w * 0.9, ch0 = h * 0.9;
        if (st.ratio) { if (cw0 / ch0 > st.ratio) cw0 = ch0 * st.ratio; else ch0 = cw0 / st.ratio; }
        st.crop = { x: (w - cw0) / 2, y: (h - ch0) / 2, w: cw0, h: ch0 }; drawCrop();
      }
      function drawCrop() { var c = st.crop; cropEl.style.cssText = 'left:' + c.x + 'px;top:' + c.y + 'px;width:' + c.w + 'px;height:' + c.h + 'px'; }
      var drag = null;
      cropEl.addEventListener('pointerdown', function (e) {
        e.preventDefault(); cropEl.setPointerCapture(e.pointerId);
        drag = { h: e.target.getAttribute('data-h'), px: e.clientX, py: e.clientY, c: Object.assign({}, st.crop) };
      });
      cropEl.addEventListener('pointermove', function (e) {
        if (!drag) return;
        var dx = e.clientX - drag.px, dy = e.clientY - drag.py, c = Object.assign({}, drag.c), W = cv.width, H = cv.height, min = 24;
        if (!drag.h) { c.x = Math.min(W - c.w, Math.max(0, c.x + dx)); c.y = Math.min(H - c.h, Math.max(0, c.y + dy)); }
        else {
          var west = drag.h.indexOf('w') >= 0, north = drag.h[0] === 'n', nw = west ? c.w - dx : c.w + dx, nh = north ? c.h - dy : c.h + dy;
          nw = Math.max(min, nw); nh = Math.max(min, nh);
          if (st.ratio) nh = nw / st.ratio;
          var ax = west ? c.x + c.w : c.x, ay = north ? c.y + c.h : c.y; // fixed corner
          var maxW = west ? ax : W - ax, maxH = north ? ay : H - ay;
          if (nw > maxW) { nw = maxW; if (st.ratio) nh = nw / st.ratio; }
          if (nh > maxH) { nh = maxH; if (st.ratio) nw = nh * st.ratio; }
          c.w = nw; c.h = nh; c.x = west ? ax - nw : ax; c.y = north ? ay - nh : ay;
        }
        st.crop = c; drawCrop();
      });
      function endDrag() { drag = null; }
      cropEl.addEventListener('pointerup', endDrag); cropEl.addEventListener('pointercancel', endDrag);

      function applyCrop() {
        var c = st.crop, k = st.src.width / cv.width; push();
        var n = canvas(c.w * k, c.h * k); n.getContext('2d').drawImage(st.src, c.x * k, c.y * k, c.w * k, c.h * k, 0, 0, n.width, n.height);
        st.src = n; render(); showCrop(tool === 'crop');
      }
      function rotate(dir) {
        push(); var n = canvas(st.src.height, st.src.width), x = n.getContext('2d'); x.translate(n.width / 2, n.height / 2); x.rotate(dir * Math.PI / 2); x.drawImage(st.src, -st.src.width / 2, -st.src.height / 2);
        st.src = n; render(); if (tool === 'crop') showCrop(true);
      }
      function flip(h) {
        push(); var n = canvas(st.src.width, st.src.height), x = n.getContext('2d'); if (h) { x.translate(n.width, 0); x.scale(-1, 1); } else { x.translate(0, n.height); x.scale(1, -1); }
        x.drawImage(st.src, 0, 0); st.src = n; render();
      }
      function setTool(t) {
        tool = t;
        box.querySelectorAll('.ie-tabs button').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-t') === t); });
        box.querySelectorAll('.ie-pane').forEach(function (p) { p.hidden = p.getAttribute('data-p') !== t; });
        showCrop(t === 'crop');
      }
      function reset() { st.hist = []; st.b = st.c = st.s = 100; st.bg = { mode: 'none', color: '#ffffff', img: null }; box.querySelectorAll('[data-f=b],[data-f=c],[data-f=s]').forEach(function (i) { i.value = 100; }); st.src = clone(st.orig); render(); showCrop(tool === 'crop'); }

      function aiRemove() {
        setBusy('Removing background… (first time downloads the model)');
        var p = aiModule ? Promise.resolve(aiModule) : import(AI_URL).then(function (m) { aiModule = m; return m; });
        p.then(function (m) {
          var fn = m.removeBackground || (m.default && m.default.removeBackground) || m.default;
          return new Promise(function (res) { st.src.toBlob(res, 'image/png'); }).then(function (blob) {
            return fn(blob, { output: { format: 'image/png' }, progress: function (key, cur, total) { if (total) setBusy('Downloading model… ' + Math.round(cur / total * 100) + '%'); } });
          });
        }).then(function (out) {
          var u = URL.createObjectURL(out), img = new Image();
          img.onload = function () { push(); var n = canvas(st.src.width, st.src.height); n.getContext('2d').drawImage(img, 0, 0, n.width, n.height); URL.revokeObjectURL(u); st.src = n; st.bg.mode = st.bg.mode === 'none' ? 'none' : st.bg.mode; render(); setBusy(''); };
          img.onerror = function () { setBusy(''); alert('The result could not be read.'); };
          img.src = u;
        }).catch(function () { setBusy(''); alert('The AI remover could not run (it needs internet access the first time). Try "Quick remove" for pictures with a plain background.'); });
      }

      function finish(result) { document.documentElement.classList.remove('ie-open'); ov.remove(); document.removeEventListener('keydown', onKey); resolve(result); }
      function onKey(e) { if (e.key === 'Escape') finish(null); }
      document.addEventListener('keydown', onKey);

      function exportFile() {
        var s = Math.min(1, st.side / Math.max(st.src.width, st.src.height)), n = canvas(st.src.width * s, st.src.height * s), x = n.getContext('2d');
        x.imageSmoothingQuality = 'high'; paint(x, n.width, n.height);
        var alpha = hasAlpha(), okWebp = !accept || /webp|image\/\*/.test(accept), okPng = !accept || /png|image\/\*/.test(accept);
        var type = okWebp ? 'image/webp' : (alpha && okPng ? 'image/png' : 'image/jpeg');
        if (type === 'image/jpeg') { var f = canvas(n.width, n.height), fx = f.getContext('2d'); fx.fillStyle = '#fff'; fx.fillRect(0, 0, f.width, f.height); fx.drawImage(n, 0, 0); n = f; }
        return new Promise(function (res) {
          n.toBlob(function (b) {
            var ext = b.type === 'image/webp' ? 'webp' : (b.type === 'image/png' ? 'png' : 'jpg');
            res(new File([b], file.name.replace(/\.[^.]+$/, '') + '.' + ext, { type: b.type, lastModified: Date.now() }));
          }, type, 0.85);
        });
      }

      box.addEventListener('click', function (e) {
        var t = e.target.closest('[data-t]'); if (t) { setTool(t.getAttribute('data-t')); return; }
        var a = e.target.closest('[data-a]'); if (!a) return;
        switch (a.getAttribute('data-a')) {
          case 'crop': applyCrop(); break;
          case 'rl': rotate(-1); break; case 'rr': rotate(1); break;
          case 'fh': flip(true); break; case 'fv': flip(false); break;
          case 'ai': aiRemove(); break;
          case 'quick': push(); var share = quickRemove(st.src, +q('[data-f=tol]').value); render(); if (share < 0.01) { st.src = st.hist.pop(); render(); alert('Nothing was removed — the edge of the picture is not a plain colour. Try a higher strength or the AI remover.'); } break;
          case 'bg-none': st.bg.mode = 'none'; render(); break;
          case 'bg-white': st.bg = { mode: 'color', color: '#ffffff', img: null }; q('[data-f=bgcolor]').value = '#ffffff'; render(); break;
          case 'adj-reset': st.b = st.c = st.s = 100; box.querySelectorAll('[data-f=b],[data-f=c],[data-f=s]').forEach(function (i) { i.value = 100; }); render(); break;
          case 'undo': if (st.hist.length) { st.src = st.hist.pop(); render(); showCrop(tool === 'crop'); } break;
          case 'reset': reset(); break;
          case 'skip': finish(file); break;
          case 'cancel': finish(null); break;
          case 'apply': setBusy('Compressing…'); exportFile().then(function (f) { finish(f); }); break;
        }
      });
      box.addEventListener('input', function (e) {
        var f = e.target.getAttribute('data-f'); if (!f) return;
        if (f === 'b' || f === 'c' || f === 's') { st[f] = +e.target.value; render(); }
        else if (f === 'bgcolor') { st.bg = { mode: 'color', color: e.target.value, img: null }; render(); }
        else if (f === 'side') st.side = +e.target.value;
      });
      box.addEventListener('change', function (e) {
        var f = e.target.getAttribute('data-f');
        if (f === 'ratio') { st.ratio = e.target.value === 'orig' ? st.src.width / st.src.height : +e.target.value; showCrop(true); }
        if (f === 'bgimg' && e.target.files[0]) { loadImage(e.target.files[0]).then(function (c) { st.bg = { mode: 'image', color: '', img: c }; render(); }); }
      });
      window.addEventListener('resize', function r() { if (!document.body.contains(ov)) { window.removeEventListener('resize', r); return; } render(); if (tool === 'crop') showCrop(true); });

      loadImage(file).then(function (c) { st.src = c; st.orig = clone(c); render(); setTool('crop'); }).catch(function () { finish(file); });
    });
  }

  window.OmniImageEditor = { edit: edit };

  // ---- every image upload of the POS goes through the editor
  var SKIP = /^image\/(gif|svg\+xml|x-icon|vnd\.microsoft\.icon)$/;
  document.addEventListener('change', function (e) {
    var input = e.target;
    if (!input || input.tagName !== 'INPUT' || input.type !== 'file' || input._ieDone || input.hasAttribute('data-no-edit')) return;
    var files = Array.prototype.slice.call(input.files || []);
    if (!files.length || !/image\//.test(input.accept || '')) { return; }
    var todo = files.some(function (f) { return /^image\//.test(f.type) && !SKIP.test(f.type); });
    if (!todo) return;
    e.stopImmediatePropagation();
    var out = [], i = 0;
    (function next() {
      if (i >= files.length) {
        var dt = new DataTransfer(); out.forEach(function (f) { dt.items.add(f); }); input.files = dt.files;
        input._ieDone = true; input.dispatchEvent(new Event('change', { bubbles: true })); input._ieDone = false;
        var note = input.parentNode && input.parentNode.querySelector('.ie-note') || null;
        if (!note) { note = el('small', 'ie-note muted'); input.insertAdjacentElement('afterend', note); }
        var total = out.reduce(function (s, f) { return s + f.size; }, 0), was = files.reduce(function (s, f) { return s + f.size; }, 0);
        note.textContent = out.length ? out.length + ' picture' + (out.length > 1 ? 's' : '') + ' ready · ' + kb(was) + ' → ' + kb(total) : 'No picture selected.';
        return;
      }
      var f = files[i++];
      if (!/^image\//.test(f.type) || SKIP.test(f.type)) { out.push(f); next(); return; }
      var label = files.length > 1 ? 'Picture ' + i + ' of ' + files.length : '';
      edit(f, { accept: input.accept, label: label }).then(function (r) { if (r) out.push(r); next(); });
    })();
  }, true);
})();
