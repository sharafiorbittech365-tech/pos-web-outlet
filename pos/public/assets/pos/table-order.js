/*!
 * BizPilot360X POS — public table ordering (assets/pos/table-order.js).
 * A guest scans the QR on a table, scrolls the menu (sections with a sticky category bar), taps + to add,
 * and sends the order to the kitchen. Draws the page from /menu, prices the cart with /quote, sends it with
 * /place (human check and CSRF token included). The cart is kept in this browser per table.
 */
(function () {
  'use strict';
  var CFG = JSON.parse(document.getElementById('to-config').textContent);
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ESC[c]; }); };
  var CUR = CFG.info.currency, DEC = CUR.decimals;
  var money = function (n) { return CUR.symbol + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: DEC, maximumFractionDigits: DEC }); };
  var STORE = 'to.cart.' + CFG.base.replace(/^.*\/order\//, '');

  var menu = { cats: [], items: [] }, cart = [], term = '', byId = {}, quoteTimer = null, busy = false, spy = null;

  /* ------------------------------------------------------------------ state */
  function load() { try { var s = JSON.parse(localStorage.getItem(STORE) || '[]'); cart = Array.isArray(s) ? s.filter(function (l) { return l && l.pid && l.qty > 0; }).slice(0, 50) : []; } catch (e) { cart = []; } }
  function save() { try { localStorage.setItem(STORE, JSON.stringify(cart)); } catch (e) { /* storage blocked */ } }
  function toast(msg) {
    var t = $('#to-toast'); t.textContent = msg; t.hidden = false;
    clearTimeout(toast.h); toast.h = setTimeout(function () { t.hidden = true; }, 2200);
  }
  function csrf() { return ($('meta[name="X-CSRF-TOKEN"]') || {}).content || ''; }
  function setCsrf(r) { var nt = r.headers.get('X-CSRF-TOKEN'); if (nt) { var m = $('meta[name="X-CSRF-TOKEN"]'); if (m) m.content = nt; } }
  function api(path, opts) {
    opts = opts || {};
    opts.credentials = 'same-origin';
    opts.headers = Object.assign({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() }, opts.headers || {});
    return fetch(CFG.base + path, opts).then(function (r) {
      setCsrf(r);
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { var m = j && (j.error && typeof j.error === 'object' ? j.error.message : (j.error || j.message)); var e = new Error(m || 'Something went wrong'); e.status = r.status; throw e; }
        return j.data !== undefined ? j.data : j;
      });
    });
  }
  function lock(on) { document.body.classList.toggle('to-lock', on); }
  function openDlg(d) { lock(true); d.showModal(); }

  /* ------------------------------------------------------------------ cards */
  function lineQty(pid) { return cart.reduce(function (a, l) { return a + (l.pid === pid ? l.qty : 0); }, 0); }
  function ctl(p) {
    if (p.oos) return '<span class="to-sold">Sold out</span>';
    var q = lineQty(p.id);
    if (!q) return '<button type="button" class="to-add" data-act="add" aria-label="Add ' + esc(p.name) + '">+</button>';
    if (p.variants.length) return '<button type="button" class="to-add on" data-act="add" aria-label="' + q + ' in your order, add another">' + q + '</button>';
    return '<div class="to-stepper"><button type="button" data-act="dec" aria-label="One less">−</button><b>' + q + '</b><button type="button" data-act="inc" aria-label="One more">+</button></div>';
  }
  function card(p) {
    var from = p.variants.length ? Math.min.apply(null, p.variants.map(function (v) { return p.price + v.delta; })) : p.price;
    return '<article class="to-card' + (lineQty(p.id) ? ' in' : '') + (p.oos ? ' oos' : '') + '" data-id="' + p.id + '">' +
      '<div class="to-pic"><img ' + (p.img ? '' : 'class="demo-img" ') + 'src="' + esc(p.img || CFG.demoImg) + '" alt="" loading="lazy">' +
      (p.badge ? '<span class="to-badge">' + esc(p.badge) + '</span>' : '') + '<div class="to-ctl">' + ctl(p) + '</div></div>' +
      '<div class="to-info"><h3>' + esc(p.name) + '</h3><div class="to-price">' + (p.variants.length ? '<small>from</small> ' : '') + esc(money(from)) + '</div></div></article>';
  }
  function paintMenu() {
    var box = $('#to-grid'), t = term.toLowerCase(), html = '';
    var list = menu.items.filter(function (p) { return !t || p.name.toLowerCase().indexOf(t) >= 0; });
    if (!list.length) {
      box.innerHTML = '<div class="to-empty"><b>' + (menu.items.length ? 'Nothing found' : 'The menu is empty right now') + '</b><span>' + (menu.items.length ? 'Try another word.' : 'Please ask the staff.') + '</span></div>';
    } else if (t) {
      box.innerHTML = '<section class="to-sec"><div class="to-grid">' + list.map(card).join('') + '</div></section>';
    } else {
      var seen = {};
      menu.cats.forEach(function (c) {
        var its = list.filter(function (p) { return p.cat === c.id; }); if (!its.length) return;
        its.forEach(function (p) { seen[p.id] = 1; });
        html += '<section class="to-sec" id="to-c-' + c.id + '" data-c="' + c.id + '"><h2>' + esc(c.name) + '<small>' + its.length + '</small></h2><div class="to-grid">' + its.map(card).join('') + '</div></section>';
      });
      var rest = list.filter(function (p) { return !seen[p.id]; });
      if (rest.length) html += '<section class="to-sec" id="to-c-0" data-c="0">' + (menu.cats.length ? '<h2>More<small>' + rest.length + '</small></h2>' : '') + '<div class="to-grid">' + rest.map(card).join('') + '</div></section>';
      box.innerHTML = html;
    }
    paintCats(); watch();
  }
  function paintCats() {
    var nav = $('#to-cats'), secs = $$('.to-sec[data-c]');
    if (term || secs.length < 2) { nav.hidden = true; nav.innerHTML = ''; sizeSticky(); return; }
    nav.innerHTML = secs.map(function (s, i) {
      var h = $('h2', s); return '<button type="button" class="chip' + (i === 0 ? ' on' : '') + '" data-go="' + s.getAttribute('data-c') + '">' + esc(h ? h.firstChild.textContent : 'Menu') + '</button>';
    }).join('');
    nav.hidden = false; sizeSticky();
  }
  function sizeSticky() { var s = $('#to-sticky'); document.documentElement.style.setProperty('--to-sticky', (s ? s.offsetHeight : 90) + 'px'); }
  /* the chip of the section being read stays highlighted (and in view) */
  function watch() {
    if (spy) spy.disconnect();
    if (!('IntersectionObserver' in window)) return;
    var top = ($('#to-sticky') || {}).offsetHeight || 90;
    spy = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (!e.isIntersecting) return;
        var id = e.target.getAttribute('data-c'), chip = $('#to-cats [data-go="' + id + '"]');
        if (!chip) return;
        $$('#to-cats .chip').forEach(function (c) { c.classList.toggle('on', c === chip); });
        var nav = $('#to-cats'), want = chip.offsetLeft - (nav.clientWidth - chip.offsetWidth) / 2;
        nav.scrollTo({ left: want, behavior: 'smooth' });
      });
    }, { rootMargin: '-' + (top + 8) + 'px 0px -65% 0px', threshold: 0 });
    $$('.to-sec[data-c]').forEach(function (s) { spy.observe(s); });
  }
  function repaintControls() {
    $$('.to-card').forEach(function (el) {
      var p = byId[el.getAttribute('data-id')]; if (!p) return;
      el.classList.toggle('in', lineQty(p.id) > 0);
      $('.to-ctl', el).innerHTML = ctl(p);
    });
  }

  /* ------------------------------------------------------------------ cart */
  function add(p, v) {
    var key = p.id + ':' + (v ? v.id : 0), line = cart.filter(function (l) { return l.key === key; })[0];
    if (line) line.qty = Math.min(99, line.qty + 1);
    else cart.push({ key: key, pid: p.id, vid: v ? v.id : null, name: p.name, vname: v ? v.name : '', price: p.price + (v ? v.delta : 0), qty: 1 });
    save(); refresh();
    if (navigator.vibrate) { try { navigator.vibrate(8); } catch (e) { /* not everywhere */ } }
  }
  function less(p) {
    var line = cart.filter(function (l) { return l.pid === p.id; })[0]; if (!line) return;
    line.qty -= 1; if (line.qty < 1) cart = cart.filter(function (l) { return l !== line; });
    save(); refresh();
  }
  function subtotal() { return cart.reduce(function (a, l) { return a + l.price * l.qty; }, 0); }
  function count() { return cart.reduce(function (a, l) { return a + l.qty; }, 0); }
  function refresh() {
    var n = count(), bar = $('#to-bar');
    bar.hidden = n === 0;
    $('#to-bar-n').textContent = n;
    $('#to-bar-total').textContent = money(subtotal());
    repaintControls();
    if ($('#to-cart').open) paintCart();
  }
  function paintCart() {
    var box = $('#to-lines');
    if (!cart.length) { box.innerHTML = '<div class="to-empty"><b>Your order is empty</b><span>Add something from the menu.</span></div>'; $('#to-sum').innerHTML = ''; $('#to-place').disabled = true; return; }
    $('#to-place').disabled = false;
    box.innerHTML = cart.map(function (l) {
      return '<div class="to-line" data-key="' + esc(l.key) + '"><div class="to-line-n"><b>' + esc(l.name) + '</b>' + (l.vname ? '<small>' + esc(l.vname) + '</small>' : '') + '<small>' + esc(money(l.price)) + ' each</small></div>' +
        '<div class="to-stepper"><button type="button" data-q="-1" aria-label="One less">−</button><b>' + l.qty + '</b><button type="button" data-q="1" aria-label="One more">+</button></div>' +
        '<b class="to-line-t">' + esc(money(l.price * l.qty)) + '</b></div>';
    }).join('');
    $('#to-sum').innerHTML = '<div class="r"><span>Subtotal</span><b>' + esc(money(subtotal())) + '</b></div><div class="r tot"><span>Total</span><b id="to-total">' + esc(money(subtotal())) + '</b></div>';
    clearTimeout(quoteTimer);
    quoteTimer = setTimeout(requote, 300);
  }
  function linesBody(fd) {
    cart.forEach(function (l, i) { fd.append('lines[' + i + '][product_id]', l.pid); if (l.vid) fd.append('lines[' + i + '][variant_id]', l.vid); fd.append('lines[' + i + '][qty]', l.qty); });
    return fd;
  }
  function requote() {
    if (!cart.length) return;
    api('/quote', { method: 'POST', body: linesBody(new FormData()) }).then(function (q) {
      var t = $('#to-total'); if (t) t.textContent = money(q.total);
      var sub = $('#to-sum .r b'); if (sub) sub.textContent = money(q.subtotal);
      $('#to-bar-total').textContent = money(q.total);
    }).catch(function () { /* the estimate stays */ });
  }

  /* ------------------------------------------------------------------ the quick check (one tap) */
  var SHIELD_SVG = '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="m9 12 2 2 4-4"/></svg>';
  function enhanceCheck() {
    var box = $('#to-shield .shield-q'); if (!box) return;            // Turnstile, or already one-tap
    var q = box.querySelector('b'), m = q && q.textContent.match(/(\d+)\s*\+\s*(\d+)/); if (!m) return;
    var right = +m[1] + +m[2], set = [right], t;
    while (set.length < 3) { t = right + Math.floor(Math.random() * 9) - 4; if (t > 1 && set.indexOf(t) < 0) set.push(t); }
    set.sort(function () { return Math.random() - 0.5; });
    var wrap = $('#to-shield .shield'), ch = box.querySelector('input[name="shield_ch"]').value;
    box.remove();
    wrap.insertAdjacentHTML('beforeend', '<div class="to-check" id="to-check" role="group" aria-label="Quick check">' +
      '<div class="to-check-q">' + SHIELD_SVG + '<span>Quick check: <b>' + esc(q.textContent.trim()) + '</b> = ?</span></div>' +
      '<div class="to-check-opts">' + set.map(function (n) { return '<button type="button" data-a="' + n + '">' + n + '</button>'; }).join('') + '</div>' +
      '<p class="to-check-hint" hidden>Tap the right answer to continue.</p>' +
      '<input type="hidden" name="shield_ch" value="' + esc(ch) + '"><input type="hidden" name="shield_ans" value=""></div>');
  }
  function freshCheck() {
    // a new question, and the new security token (it changes after every POST, also a refused one)
    return fetch(CFG.base + '/challenge', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function (r) { setCsrf(r); return r.text(); })
      .then(function (h) { $('#to-shield').innerHTML = h; enhanceCheck(); }).catch(function () { /* keep the old one */ });
  }
  function showErr(msg) { var e = $('#to-err'); e.textContent = msg; e.hidden = !msg; }

  /* ------------------------------------------------------------------ place */
  function place() {
    if (busy || !cart.length) return;
    var ans = $('#to-form [name="shield_ans"]');
    if (ans && !ans.value) {
      var c = $('#to-check'); if (c) { c.classList.remove('need'); void c.offsetWidth; c.classList.add('need'); $('.to-check-hint', c).hidden = false; c.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
      return;
    }
    var fd = linesBody(new FormData($('#to-form')));
    busy = true; showErr('');
    var b = $('#to-place'); b.disabled = true; b.classList.add('loading');
    api('/place', { method: 'POST', body: fd }).then(function (o) {
      cart = []; save(); refresh();
      $('#to-cart').close();
      $('#to-done-ref').textContent = o.reference;
      $('#to-done-total').textContent = money(o.total);
      $('#to-done-link').href = o.link;
      $('#to-done-msg').textContent = CFG.info.kitchen ? 'The kitchen has your order and will bring it to ' + CFG.info.table + '.' : 'The staff have your order for ' + CFG.info.table + '.';
      $('#to-done').hidden = false;
      document.body.classList.add('to-finished'); lock(true);
      $('#to-form').reset(); freshCheck();
    }).catch(function (e) {
      showErr(e.message === 'Failed to fetch' ? 'No connection — please try again.' : e.message);
      freshCheck();
    }).then(function () { busy = false; b.classList.remove('loading'); b.disabled = !cart.length; });
  }

  /* ------------------------------------------------------------------ events */
  function chooseVariant(p) {
    $('#to-opt-h').textContent = p.name;
    $('#to-opt-list').innerHTML = p.variants.map(function (v) {
      return '<button type="button" class="to-opt' + (v.oos ? ' oos' : '') + '" data-v="' + v.id + '" data-p="' + p.id + '"' + (v.oos ? ' disabled' : '') + '><span>' + esc(v.name) + '</span><b>' + esc(money(p.price + v.delta)) + '</b></button>';
    }).join('');
    openDlg($('#to-opt'));
  }
  document.addEventListener('click', function (e) {
    var an = e.target.closest('.to-check-opts [data-a]');
    if (an) {
      var box = an.closest('.to-check');
      $('[name="shield_ans"]', box).value = an.getAttribute('data-a');
      $$('[data-a]', box).forEach(function (b) { b.classList.toggle('on', b === an); });
      box.classList.remove('need'); box.classList.add('ok'); $('.to-check-hint', box).hidden = true; showErr('');
      return;
    }
    var cd = e.target.closest('.to-card');
    if (cd) {
      var p = byId[cd.getAttribute('data-id')]; if (!p) return;
      var actEl = e.target.closest('[data-act]'), act = actEl ? actEl.getAttribute('data-act') : 'add';
      if (p.oos) { toast(p.name + ' is sold out'); return; }
      if (act === 'dec') { less(p); return; }
      if (p.variants.length) { chooseVariant(p); return; }
      add(p, null); return;
    }
    var o = e.target.closest('.to-opt');
    if (o) { var pp = byId[o.getAttribute('data-p')], v = pp.variants.filter(function (x) { return String(x.id) === o.getAttribute('data-v'); })[0]; $('#to-opt').close(); add(pp, v); toast(pp.name + ' added'); return; }
    var g = e.target.closest('#to-cats [data-go]');
    if (g) {
      var sec = document.getElementById('to-c-' + g.getAttribute('data-go')); if (sec) sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }
    var q = e.target.closest('.to-line [data-q]');
    if (q) {
      var key = q.closest('.to-line').getAttribute('data-key'), line = cart.filter(function (l) { return l.key === key; })[0];
      if (line) { line.qty = Math.min(99, line.qty + +q.getAttribute('data-q')); if (line.qty < 1) cart = cart.filter(function (l) { return l !== line; }); }
      save(); refresh(); return;
    }
    var a = e.target.closest('[data-to]'); if (!a) return;
    var what = a.getAttribute('data-to');
    if (what === 'open') { paintCart(); showErr(''); openDlg($('#to-cart')); }
    if (what === 'close') $('#to-cart').close();
    if (what === 'close-opt') $('#to-opt').close();
    if (what === 'place') place();
    if (what === 'top') window.scrollTo({ top: 0, behavior: 'smooth' });
    if (what === 'more') { $('#to-done').hidden = true; document.body.classList.remove('to-finished'); lock(false); window.scrollTo(0, 0); }
  });
  // tap outside a sheet closes it; closing gives the page its scroll back
  document.addEventListener('click', function (e) {
    var d = e.target; if (d.tagName !== 'DIALOG' || !d.open) return;
    var r = d.getBoundingClientRect();
    if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) d.close();
  });
  $$('dialog').forEach(function (d) { d.addEventListener('close', function () { if (!$$('dialog[open]').length && !document.body.classList.contains('to-finished')) lock(false); }); });
  var qIn = $('#to-q'), clr = $('#to-clear');
  qIn.addEventListener('input', function () { term = qIn.value.trim(); clr.hidden = !term; paintMenu(); window.scrollTo({ top: 0 }); });
  clr.addEventListener('click', function () { qIn.value = ''; term = ''; clr.hidden = true; paintMenu(); qIn.focus(); });
  var topBtn = $('#to-top'), tick = false;
  window.addEventListener('scroll', function () {
    if (tick) return; tick = true;
    requestAnimationFrame(function () { tick = false; topBtn.hidden = window.scrollY < 700; });
  }, { passive: true });
  window.addEventListener('resize', sizeSticky);

  /* ------------------------------------------------------------------ start */
  load();
  enhanceCheck();
  sizeSticky();
  api('/menu').then(function (m) {
    menu = m; m.items.forEach(function (p) { byId[p.id] = p; });
    cart = cart.filter(function (l) { return byId[l.pid]; });     // forget products that left the menu
    paintMenu(); refresh();
  }).catch(function (e) { $('#to-grid').innerHTML = '<div class="to-empty"><b>' + esc(e.message || 'The menu could not be loaded') + '</b><span>Pull down to try again.</span></div>'; });
})();
