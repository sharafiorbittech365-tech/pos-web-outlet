/*!
 * BizPilot360X — POS till (server edition).
 *
 * The browser keeps only the cart: product ids, options (variant / IMEI), quantities, customer,
 * order type, table, discount. Every price and total is computed by the server:
 *   POST sell/quote     on each cart change  → OrderService::quote (lines, VAT buckets, totals)
 *   POST sell/checkout  to finalize          → OrderService::create (one idempotency token per cart)
 *   POST sell/open      send to kitchen / table now, pay later (status "open")
 *   POST sell/hold      suspend / draft / quotation (held_carts) · GET sell/parked · POST sell/resume/:id
 *   POST sell/pay/:id   take payment for an open order
 * POSTs run one after another (CSRF tokens are regenerated after every POST; the fresh token comes
 * back in the X-CSRF-TOKEN response header).
 *
 * Phones (html.app-mode, see App\Views\_app_mode): the same DOM becomes an app screen — the catalogue fills the
 * screen with search and category chips on top, a floating cart bar opens the cart as a full-screen
 * sheet (#m-sheet) with the actions and pay buttons at the bottom.
 */
(function () {
  'use strict';

  var CFG = JSON.parse(document.getElementById('pos-config').textContent);
  var B = CFG.business, F = B.features || {}, L = B.labels || {}, CAN = CFG.can || {};
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ESC[c]; }); };
  var icon = function (n, cls) { return '<svg class="ic ' + (cls || '') + '" viewBox="0 0 24 24" aria-hidden="true">' + ((window.OMNI_ICONS || {})[n] || '') + '</svg>'; };
  var DEC = (B.currency && B.currency.decimals != null) ? +B.currency.decimals : 2;
  var SYM = (B.currency && B.currency.symbol) || '৳';
  var r2 = function (n) { var p = Math.pow(10, DEC); return Math.round((+n || 0) * p) / p; };
  var isApp = function () { return document.documentElement.classList.contains('app-mode'); };

  function fmt(n) {
    var v = +n || 0, neg = v < 0 ? '-' : '';
    var parts = Math.abs(v).toFixed(DEC).split('.');
    var i = parts[0], last3 = i.slice(-3), rest = i.slice(0, -3);
    var g = rest ? rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + last3 : last3;
    return neg + SYM + g + (parts[1] ? '.' + parts[1] : '');
  }
  function num(q) { return String(Math.round((+q || 0) * 1000) / 1000); }
  function uid() { var a = new Uint8Array(12); (window.crypto || window.msCrypto).getRandomValues(a); return Array.prototype.map.call(a, function (x) { return ('0' + x.toString(16)).slice(-2); }).join(''); }
  function toast(msg, kind) { if (window.OmniUI) return window.OmniUI.toast(msg, kind); var box = $('#toasts'); if (!box) return; var n = document.createElement('div'); n.className = 'toast ' + (kind || ''); n.textContent = msg; box.appendChild(n); setTimeout(function () { n.remove(); }, 3200); }
  function payLabel(m) { return m === 'mobile' && B.code === 'BDT' ? 'bKash / Nagad / Rocket' : ({ cash: 'Cash', card: 'Card', mobile: 'Mobile money', bank: 'Bank Transfer', cheque: 'Cheque', credit: 'Credit Sale', exchange: 'Exchange credit' }[m] || m); }

  /* ------------------------------------------------------------------ server calls */
  var csrf = ($('meta[name="X-CSRF-TOKEN"]') || {}).content || '';
  var queue = Promise.resolve();
  // the token changes after every verified POST: keep the page's own forms (Lock, logout …) in step with it
  function syncCsrf(t) {
    var m = $('meta[name="X-CSRF-TOKEN"]'); if (m) m.content = t;
    document.querySelectorAll('input[name="csrf_test_name"]').forEach(function (i) { i.value = t; });
  }
  function api(method, path, body) {
    var run = function () {
      var h = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
      if (method !== 'GET') { h['Content-Type'] = 'application/json'; h['X-CSRF-TOKEN'] = csrf; }
      return fetch(CFG.base + path, { method: method, headers: h, credentials: 'same-origin', body: body ? JSON.stringify(body) : undefined })
        .then(function (res) {
          var t = res.headers.get('X-CSRF-TOKEN'); if (t) { csrf = t; syncCsrf(t); }
          return res.text().then(function (txt) {
            var j = null; try { j = txt ? JSON.parse(txt) : null; } catch (e) { j = null; }
            if (!res.ok) {
              if (res.status === 401) { location.href = CFG.base + 'login'; }
              var err = new Error((j && j.error && j.error.message) || (res.status === 403 ? 'Not allowed (or the page expired — reload).' : 'Request failed (' + res.status + ')'));
              err.status = res.status; throw err;
            }
            return j ? j.data : null;
          });
        });
    };
    if (method === 'GET') return run();
    var p = queue.then(run, run);
    queue = p.catch(function () { /* keep the queue going */ });
    return p;
  }

  /* ------------------------------------------------------------------ state */
  var KEY = 'omnipos.till.' + B.id;
  function newCart() {
    return { token: uid(), lines: [], customer: CFG.walkIn ? { id: CFG.walkIn.id, name: CFG.walkIn.name, walkIn: true, due: 0, exchange: 0, limit: 0 } : null,
      order_type: (F.orderTypes || [])[0] || '', table_ref: '', checkin: 0, staff_name: '', kitchen: !!F.kitchen, express: false,
      discount: { type: 'percent', value: 0 }, tax_override: '', shipping: { charge: 0, address: '' }, note: '', due_date: '' };
  }
  function load() { try { var c = JSON.parse(localStorage.getItem(KEY) || 'null'); return c && c.token && Array.isArray(c.lines) ? c : null; } catch (e) { return null; } }
  function save() { try { localStorage.setItem(KEY, JSON.stringify(S.cart)); } catch (e) { /* storage blocked */ } }
  var S = { cart: load() || newCart(), quote: null, products: [], more: false, page: 1, cat: 0, q: '', featured: false, panel: '', loading: false, lastLine: null };

  function payload() {
    var c = S.cart;
    return {
      lines: c.lines.map(function (l) { var x = { product_id: l.product_id, qty: l.qty }; if (l.variant_id) x.variant_id = l.variant_id; if (l.serial) x.serial = l.serial; if (l.note) x.note = l.note; if (l.unit_price != null && l.unit_price !== '') x.unit_price = l.unit_price; return x; }),
      customer: c.customer && !c.customer.walkIn ? { id: c.customer.id } : {},
      order_type: c.order_type, table_ref: c.table_ref, checkin: c.checkin, staff_name: c.staff_name, kitchen: c.kitchen, express: c.express,
      discount: c.discount, tax_override: c.tax_override, shipping: c.shipping, note: c.note, due_date: c.due_date,
    };
  }
  var canCharge = function () { return S.cart.lines.length > 0 && S.quote && S.quote.totals; };

  /* ------------------------------------------------------------------ quote (debounced) */
  var qTimer = 0, qSeq = 0;
  function changed(opts) {
    save();
    if (opts && opts.fields) renderFields();
    renderCart();
    renderGrid();
    clearTimeout(qTimer);
    qTimer = setTimeout(requote, 160);
  }
  function requote() {
    var seq = ++qSeq;
    if (!S.cart.lines.length) { S.quote = null; renderCart(); renderBottom(); return Promise.resolve(); }
    return api('POST', 'sell/quote', { cart: payload() }).then(function (q) {
      if (seq !== qSeq) return;
      S.quote = q;
      if (q && q.warning) toast(q.warning, 'warn');
      renderCart(); renderBottom();
    }).catch(function (e) {
      if (seq !== qSeq) return;
      toast(e.message, 'err');
      if (/no longer available|not found|Option not found/i.test(e.message)) { /* leave it for the cashier to remove */ }
    });
  }

  /* ------------------------------------------------------------------ fields (customer, search, type, seat) */
  function renderFields() {
    var c = S.cart, cells = [];
    var cu = c.customer || { name: L.walkIn || 'Walk-in customer', walkIn: true };
    cells.push('<div class="ig" id="cust-ig"><span class="ig-ic">' + icon('user', 'sm') + '</span>' +
      '<input id="cust-q" type="text" value="' + esc(cu.name) + '" placeholder="' + esc((L.customer || 'Customer') + ' name or phone') + '" autocomplete="off" aria-label="' + esc(L.customer || 'Customer') + '">' +
      (CAN.addCustomer ? '<button class="ig-btn" type="button" data-act="add-customer" title="Add customer">' + icon('plusc') + '</button>' : '') +
      (!cu.walkIn && CAN.customers ? '<a class="ig-btn" href="' + esc(CFG.base + 'customers/' + cu.id) + '" title="Due book" style="color:#2563EB">' + icon('handcoin') + '</a>' : '') +
      '<div class="ac" id="cust-ac" hidden></div></div>');
    if (!isApp()) cells.push('<div class="ig span2" id="search-ig"><span class="ig-ic">' + icon('search', 'sm') + '</span>' +
      '<input id="q" type="text" placeholder="Enter product name / SKU' + (F.batches ? ' / generic' : '') + (F.serials ? ' / IMEI' : '') + (F.barcode ? ' / Scan bar code' : '') + '" value="' + esc(S.q) + '" autocomplete="off" aria-label="Search products"></div>');
    var types = F.orderTypes || [];
    if (types.length) {
      var info = types.map(function (t) { var x = (B.typeCharges || {})[t]; return t + ': ' + (x ? (x.kind === 'percent' ? x.value + '%' : fmt(x.value)) + ' charge' : 'no charge'); }).join('\n');
      cells.push('<div class="ig"><span class="ig-ic">' + icon('service', 'sm') + '</span><select data-bind="order_type" aria-label="Types of service">' +
        types.map(function (t) { return '<option ' + (c.order_type === t ? 'selected' : '') + '>' + esc(t) + '</option>'; }).join('') + '</select>' +
        '<span class="ig-btn" title="' + esc(info) + '" style="color:#06B6D4">' + icon('info') + '</span></div>');
    }
    if (F.seats && CFG.tables.length) {
      cells.push('<div class="ig"><span class="ig-ic">' + icon('table', 'sm') + '</span><select data-bind="table_ref" aria-label="' + esc(L.seat) + '"><option value="">Select ' + esc(L.seat) + '</option>' +
        CFG.tables.map(function (t) { return '<option value="' + esc(t.name) + '" ' + (c.table_ref === t.name ? 'selected' : '') + '>' + esc(t.name) + (t.rate ? ' · ' + fmt(t.rate) + '/h' : '') + '</option>'; }).join('') + '</select></div>');
    }
    if ((B.serviceStaff || []).length) {
      cells.push('<div class="ig"><span class="ig-ic">' + icon('staff', 'sm') + '</span><select data-bind="staff_name" aria-label="Service staff"><option value="">Select service staff</option>' +
        B.serviceStaff.map(function (s) { return '<option ' + (c.staff_name === s ? 'selected' : '') + '>' + esc(s) + '</option>'; }).join('') + '</select></div>');
    }
    // kitchen order sits in the field grid, next to service staff
    if (F.kitchen) cells.push('<label class="ig ig-check"><input type="checkbox" data-bind="kitchen" ' + (c.kitchen ? 'checked' : '') + '><span class="grow">' + esc(L.kitchen) + ' order</span><span class="ig-btn" title="Sends the order to the ' + esc(String(L.kitchen).toLowerCase()) + ' screen" style="color:#06B6D4">' + icon('info') + '</span></label>');
    if (F.dueDate) cells.push('<div class="ig"><span class="ig-ic" title="' + esc(L.dueDate) + '">' + icon('calendar', 'sm') + '</span><input type="date" data-bind="due_date" value="' + esc(c.due_date) + '" aria-label="' + esc(L.dueDate) + '"></div>');
    var opts = [];
    if (F.express) opts.push('<label class="check big"><input type="checkbox" data-bind="express" ' + (c.express ? 'checked' : '') + '>Express service <small class="muted">+' + esc(F.expressRate) + '%</small></label>');
    // the customer's phone & due sits in the first free cell of the field grid (beside the order type …); with no free cell it wraps to the next row
    if (!cu.walkIn) cells.push('<span class="cust-chip ig-chip">' + (cu.phone ? icon('phone', 'sm') + ' ' + esc(cu.phone) + ' · ' : '') + 'Due <b class="' + (cu.due ? 'neg' : '') + '">' + fmt(cu.due || 0) + '</b>' + (+cu.limit ? ' / limit ' + fmt(cu.limit) : '') + '</span>');
    if (!cu.walkIn && +cu.exchange > 0) cells.push('<span class="cust-chip ig-chip" title="Value of returned items, can pay this invoice">' + icon('undo', 'sm') + ' Exchange credit <b class="pos">' + fmt(cu.exchange) + '</b></span>');
    var seat = tableOf(c.table_ref);
    if (F.hourly && seat && seat.rate && c.checkin) {
      var d = new Date(c.checkin * 1000);
      opts.push('<div class="timebox"><div><small>Check-in</small><b>' + esc(d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })) + '</b></div><div><small>Elapsed</small><b data-since="' + c.checkin + '">' + elapsed(c.checkin) + '</b></div><div><small>Rate</small><b>' + fmt(seat.rate) + '/h</b></div></div>');
    } else if (F.hourly) opts.push('<span class="muted">Select a ' + esc(String(L.seat).toLowerCase()) + ' to start the time charge.</span>');
    if (opts.length) cells.push('<div class="up-opts">' + opts.join('') + '</div>');
    $('#up-fields').innerHTML = cells.join('');
    renderMSearch();
  }
  function renderMSearch() {
    var el = $('#m-search-slot'); if (!el) return;
    if (!isApp()) { el.innerHTML = ''; return; }
    if ($('#q', el)) return;
    el.innerHTML = '<div class="m-search">' + icon('search', 'sm') +
      '<input id="q" type="search" placeholder="Search ' + esc(String(L.catalog || 'products').toLowerCase()) + (F.serials ? ', IMEI' : '') + (F.barcode ? ' or scan' : '') + '" value="' + esc(S.q) + '" autocomplete="off" enterkeyhint="search" aria-label="Search products"></div>';
  }
  function tableOf(name) { for (var i = 0; i < CFG.tables.length; i++) if (CFG.tables[i].name === name) return CFG.tables[i]; return null; }
  function elapsed(ts) { var s = Math.max(0, Math.floor(Date.now() / 1000 - ts)), h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60); return (h ? h + ':' : '') + (m < 10 && h ? '0' : '') + m + ':' + ('0' + (s % 60)).slice(-2); }

  /* ------------------------------------------------------------------ catalogue */
  function renderTabs() {
    var cat = null;
    CFG.categories.forEach(function (c) { if (c.id === S.cat) cat = c; });
    var tabs = $('#rp-tabs');
    tabs.classList.toggle('m-chips', isApp());
    if (isApp()) {
      tabs.innerHTML = '<button type="button" class="chip ' + (!S.cat ? 'on' : '') + '" data-act="pick-cat" data-v="0">All</button>' +
        CFG.categories.map(function (c) { return '<button type="button" class="chip ' + (S.cat === c.id ? 'on' : '') + '" data-act="pick-cat" data-v="' + c.id + '">' + icon(c.icon || 'tag', 'sym sm') + esc(c.name) + '</button>'; }).join('') +
        '<button type="button" class="chip ' + (S.featured ? 'on' : '') + '" data-act="featured">' + icon('star', 'sm') + 'Featured</button>';
      $('#rp-filters').innerHTML = S.q ? '<button type="button" class="chip sm on" data-act="clear-q">“' + esc(S.q) + '” ' + icon('x', 'sm') + '</button>' : '';
      return;
    }
    var drop = S.panel === 'cat' ? '<div class="rp-drop">' + [{ id: 0, name: 'All ' + L.catalog, icon: 'grid', n: CFG.categories.reduce(function (a, c) { return a + c.n; }, 0) }].concat(CFG.categories).map(function (c) {
      return '<button type="button" class="' + (S.cat === c.id ? 'on' : '') + '" data-act="pick-cat" data-v="' + c.id + '">' + icon(c.icon || 'tag', 'sym sm') + '<span class="grow">' + esc(c.name) + '</span><small>' + c.n + '</small></button>';
    }).join('') + '</div>' : '';
    $('#rp-tabs').innerHTML = '<button type="button" class="rp-tab ' + (S.panel === 'cat' || cat ? 'on' : '') + '" data-act="panel" data-v="cat">' + icon('grid') + '<span class="t">' + (cat ? esc(cat.name) : 'Category') + '</span><span class="n">' + CFG.categories.length + '</span></button>' +
      '<button type="button" class="rp-tab ' + (S.featured ? 'on' : '') + '" data-act="featured"><span style="color:#F59E0B">' + icon('star') + '</span><span class="t">Featured ' + esc(L.catalog || 'Products') + '</span></button>' +
      '<button type="button" class="rp-tab" data-act="refresh-grid" title="Refresh stock">' + icon('refresh') + '<span class="t">Refresh</span></button>' + drop;
    var chips = [];
    if (cat) chips.push('<button type="button" class="chip sm on" data-act="pick-cat" data-v="0">' + esc(cat.name) + ' ' + icon('x', 'sm') + '</button>');
    if (S.featured) chips.push('<button type="button" class="chip sm on" data-act="featured">Featured ' + icon('x', 'sm') + '</button>');
    if (S.q) chips.push('<button type="button" class="chip sm on" data-act="clear-q">“' + esc(S.q) + '” ' + icon('x', 'sm') + '</button>');
    $('#rp-filters').innerHTML = chips.join('');
  }
  var gTimer = 0, gSeq = 0;
  function loadProducts(append) {
    var seq = ++gSeq;
    var page = append ? S.page + 1 : 1;
    S.loading = true;
    var qs = 'sell/products?page=' + page + (S.cat ? '&cat=' + S.cat : '') + (S.featured ? '&featured=1' : '') + (S.q ? '&q=' + encodeURIComponent(S.q) : '');
    return fetch(CFG.base + qs, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { if (r.status === 401) location.href = CFG.base + 'login'; return r.json(); })
      .then(function (j) {
        if (seq !== gSeq) return;
        S.loading = false;
        S.page = page;
        S.more = !!(j.meta && j.meta.more);
        S.products = append ? S.products.concat(j.data || []) : (j.data || []);
        renderGrid();
      }).catch(function () { S.loading = false; toast('Could not load products', 'err'); });
  }
  var inCart = function (pid) { return S.cart.lines.reduce(function (a, l) { return a + (l.product_id === pid ? (+l.qty || 0) * (l.mult || 1) : 0); }, 0); };
  function renderGrid() {
    var el = $('#grid');
    if (!S.products.length) {
      el.innerHTML = S.loading ? '<div class="empty"><span class="empty-ic">' + icon('search') + '</span><b>Loading…</b></div>' : '<div class="empty"><span class="empty-ic">' + icon('search-x') + '</span><b>No matches</b><span>Try another search or category.</span></div>';
      return;
    }
    el.innerHTML = S.products.map(tileHTML).join('') + (S.more ? '<button type="button" class="btn soft block" data-act="more" style="grid-column:1/-1">Show more</button>' : '');
  }
  function tileHTML(p) {
    var q = inCart(p.id), unit = p.unit || '';
    var left = p.tracked && p.stock != null ? p.stock - q : null;
    var stock = '';
    if (p.tracked && p.stock != null) stock = left > 0 ? num(left) + ' ' + (unit || 'pcs') + ' in stock' : 'Out of stock';
    if (p.variants.length) stock = (stock ? stock + ' · ' : '') + p.variants.length + ' options';
    else if (p.duration) stock = p.duration + ' min';
    var sub = p.generic || p.sku || p.code || '';
    var flags = (p.rx ? '<span class="flag rx">Rx</span>' : '') + (p.warranty && (F.warranty || F.serials) ? '<span class="flag">' + (p.warranty >= 9999 ? 'Lifetime' : p.warranty % 12 === 0 ? p.warranty / 12 + 'Y' : p.warranty + 'M') + ' warranty</span>' : '') + (p.low ? '<span class="flag warn">Low</span>' : '');
    var oos = p.tracked && p.stock != null && left <= 0 && !p.variants.length;
    return '<button type="button" class="tile ' + (q ? 'in' : '') + ' ' + (oos ? 'oos' : '') + '" data-act="p-add" data-id="' + p.id + '" style="--h:' + ((p.cat * 47) % 360) + '" title="' + esc(p.name) + '">' +
      (q ? '<span class="qbadge">' + num(q) + '</span>' : '') + (p.badge ? '<span class="ribbon">' + esc(p.badge) + '</span>' : '') +
      '<span class="tile-img has-img">' + (p.img ? '<img src="' + esc(p.img) + '" alt="" loading="lazy">' : '<img class="demo-img" src="' + esc(CFG.demoImg) + '" alt="" loading="lazy">') + '</span>' +
      '<span class="tile-name">' + esc(p.name) + '</span>' + (sub ? '<span class="tile-sku">(' + esc(sub) + ')</span>' : '') +
      '<span class="tile-price">' + (p.compare ? '<s>' + fmt(p.compare) + '</s> ' : '') + fmt(p.price) + (unit && p.weighed ? '/' + esc(unit) : '') + '</span>' +
      (stock ? '<span class="tile-stock">' + esc(stock) + '</span>' : '') + (flags ? '<span class="tile-flags">' + flags + '</span>' : '') + '</button>';
  }
  function productById(id) { for (var i = 0; i < S.products.length; i++) if (S.products[i].id === id) return S.products[i]; return null; }

  /* ------------------------------------------------------------------ adding to the cart */
  function onProduct(p, opts) {
    opts = opts || {};
    if (opts.serial) return addLine(p, { serial: opts.serial });
    if (opts.variant_id) { var v = (p.variants || []).filter(function (x) { return x.id === opts.variant_id; })[0]; return addLine(p, { variant: v }); }
    if (p.variants && p.variants.length) return openVariant(p);
    if (p.type === 'serial' && p.tracked) return openSerial(p);
    if (p.weighed) return openWeigh(p);
    addLine(p, {});
  }
  function addLine(p, o) {
    var v = o.variant || null, serial = o.serial || '';
    var key = [p.id, v ? v.id : '', serial].join('|');
    var mult = v && v.mult ? v.mult : 1;
    var qty = o.qty != null ? +o.qty : 1;
    if (p.tracked && p.stock != null && !serial) {
      var avail = v && v.stock != null ? v.stock : p.stock;
      var used = v && v.stock != null ? S.cart.lines.filter(function (l) { return l.variant_id === v.id; }).reduce(function (a, l) { return a + l.qty; }, 0) : inCart(p.id);
      if (used + qty * (v && v.stock != null ? 1 : mult) > avail + 1e-9) { toast(p.name + ': only ' + num(Math.max(0, avail - used)) + ' in stock', 'warn'); return; }
    }
    var line = null;
    S.cart.lines.forEach(function (l) { if (l.key === key && !serial) line = l; });
    if (serial && S.cart.lines.some(function (l) { return l.serial === serial; })) { toast('IMEI / serial ' + serial + ' is already in the cart', 'warn'); return; }
    if (line) line.qty = Math.round((line.qty + qty) * 1000) / 1000;
    else {
      line = { key: key, product_id: p.id, variant_id: v ? v.id : null, serial: serial || null, qty: qty, note: '', name: p.name, variant_name: v ? v.name : '',
        unit: p.unit || '', decimal: !!p.decimal, weighed: !!p.weighed, price: p.price + (v ? v.delta : 0), mult: mult, sku: p.sku || p.code || '' };
      S.cart.lines.push(line);
    }
    S.lastLine = line.key;
    if (window.OmniSound) window.OmniSound.click();
    changed();
    bumpBar();
  }
  function setQty(l, q) {
    q = Math.round((+q || 0) * 1000) / 1000;
    if (!l.decimal) q = Math.round(q);
    if (q <= 0) { S.cart.lines = S.cart.lines.filter(function (x) { return x !== l; }); }
    else l.qty = q;
    changed();
  }
  function lineByKey(k) { for (var i = 0; i < S.cart.lines.length; i++) if (S.cart.lines[i].key === k) return S.cart.lines[i]; return null; }

  /* ------------------------------------------------------------------ cart table & summary */
  function renderCart() {
    var c = S.cart, q = S.quote, el = $('#cart-lines');
    if (!c.lines.length) {
      el.innerHTML = '<div class="cart-empty"><span class="circle">' + icon('cart') + '</span><b>Your cart is empty</b><span>' + (F.serials ? 'Scan an IMEI / serial or tap a product tile.' : F.barcode ? 'Scan a barcode, tap a product tile, or type to search.' : F.hourly ? 'Pick a ' + esc(String(L.seat).toLowerCase()) + ' to start the timer, then add items.' : 'Tap a product tile or type to search.') + '</span></div>';
    } else {
      var priced = q && q.lines && q.lines.length === c.lines.length ? q.lines : null;
      el.innerHTML = '<table class="ctable"><thead><tr><th>' + esc(L.item || 'Product') + '</th><th style="width:170px">Quantity</th><th class="num" style="width:120px">Subtotal</th><th style="width:40px"></th></tr></thead><tbody>' +
        c.lines.map(function (l, i) { return lineHTML(l, priced ? priced[i] : null); }).join('') + '</tbody></table>';
    }
    renderSum();
    S.lastLine = null;
  }
  function lineHTML(l, p) {
    var unit = p ? p.unit_price : (l.unit_price != null && l.unit_price !== '' ? +l.unit_price : l.price);
    var amount = p ? p.amount : unit * l.qty;
    var uom = l.weighed ? l.unit : (l.unit || '');
    /* a pack variant (Crate (24), Carton (72)…) is sold by the pack: its price and quantity are per crate, not per bottle */
    if (!l.weighed && l.mult > 1 && l.variant_name) uom = String(l.variant_name).replace(/\s*[(\[].*$/, '').trim() || uom;
    var qty;
    if (l.serial) qty = '<div class="qty fixed"><input value="1" disabled aria-label="Quantity"><span class="u">' + esc(uom || 'pc') + '</span></div>';
    else if (l.weighed) qty = '<div class="qty"><button type="button" data-act="line-weigh" data-k="' + esc(l.key) + '" title="Weigh">' + icon('scale', 'sm') + '</button><input type="number" step="0.001" min="0" data-line-qty="' + esc(l.key) + '" value="' + l.qty + '" aria-label="Weight"><span class="u">' + esc(l.unit) + '</span></div>';
    else qty = '<div class="qty"><button type="button" data-act="line-dec" data-k="' + esc(l.key) + '" aria-label="Decrease">' + icon('minus', 'sm') + '</button><input type="number" step="' + (l.decimal ? 'any' : 1) + '" min="0" data-line-qty="' + esc(l.key) + '" value="' + l.qty + '" aria-label="Quantity"><button type="button" data-act="line-inc" data-k="' + esc(l.key) + '" aria-label="Increase">' + icon('plus', 'sm') + '</button>' + (uom ? '<span class="u">' + esc(uom) + '</span>' : '') + '</div>';
    return '<tr class="' + (S.lastLine === l.key ? 'flash' : '') + '"><td><div class="pcell"><span class="lthumb has-img"><img class="demo-img" src="' + esc(CFG.demoImg) + '" alt=""></span><div class="grow">' +
      '<button type="button" class="pname" data-act="line-edit" data-k="' + esc(l.key) + '" title="Edit price, note">' + esc(l.name) + ' ' + icon('edit', 'sm') + '</button>' +
      '<div class="psub">' + (l.sku ? '<span>' + esc(l.sku) + '</span>' : '') + (l.variant_name ? '<span>' + esc(l.variant_name) + '</span>' : '') + '<span>' + (l.unit_price != null && l.unit_price !== '' && +l.unit_price !== l.price ? '<s>' + fmt(l.price) + '</s> ' : '') + fmt(unit) + (uom ? '/' + esc(uom) : '') + '</span>' + (p ? '<span>' + esc(B.taxLabel) + ' ' + esc(p.tax) + '</span>' : '') + '</div>' +
      (l.serial ? '<div class="psub"><span class="flag">IMEI/SN</span><b>' + esc(l.serial) + '</b></div>' : '') +
      (l.note ? '<div class="line-note">' + icon('edit', 'sm') + ' ' + esc(l.note) + '</div>' : '') +
      '</div></div></td><td>' + qty + '</td><td class="num"><b>' + fmt(amount) + '</b></td><td><button type="button" class="x-btn" data-act="line-del" data-k="' + esc(l.key) + '" aria-label="Remove">' + icon('xc', 'sm') + '</button></td></tr>';
  }
  function renderSum() {
    var q = S.quote, c = S.cart, el = $('#cart-sum');
    var count = c.lines.reduce(function (a, l) { return a + (+l.qty || 0); }, 0);
    var cells = ['<div><span>Items:</span><b>' + c.lines.length + ' (' + num(count) + ')</b></div>'];
    var editable = { discount: CAN.discount && F.discount !== false, tax: CAN.discount, shipping: true };
    if (q && q.rows) {
      q.rows.forEach(function (r) {
        cells.push('<div><span>' + esc(r[0]) + ':' + (r[2] && editable[r[2]] ? ' <button type="button" class="edit-i" data-act="edit-' + r[2] + '" title="Edit">' + icon('editbox', 'sm') + '</button>' : '') + '</span><b>' + esc(r[1]) + '</b></div>');
      });
    } else {
      cells.push('<div><span>Total:</span><b>' + fmt(0) + '</b></div>');
    }
    el.innerHTML = cells.join('');
    var tot = q && q.totals ? fmt(q.totals.total) : fmt(0);
    $$('.js-total').forEach(function (x) { x.textContent = tot; });
    $$('[data-needs-cart]').forEach(function (x) { x.disabled = !canCharge(); });
    var bar = $('#m-cartbar');
    if (bar) {
      var n = c.lines.length;
      bar.classList.toggle('empty', !n);
      $('#cb-n').textContent = n;
      $('#cb-items').textContent = n + ' item' + (n === 1 ? '' : 's') + ' · ' + ((c.customer || {}).name || L.walkIn || 'Walk-in customer');
      $('#cb-total').textContent = tot;
      $('#upos').classList.toggle('has-cart', !!n);
      $('#m-sheet-count').textContent = n ? n + ' item' + (n === 1 ? '' : 's') : '';
    }
  }

  /* ------------------------------------------------------------------ bottom bar */
  function renderBottom() {
    var can = canCharge(), pays = B.payments || ['cash'];
    var act = function (a, ic, label, color, key) { return '<button type="button" class="bb-act" data-act="' + a + '" data-needs-cart style="--c:' + color + '" ' + (can ? '' : 'disabled') + ' title="' + label + (key ? ' (' + key + ')' : '') + '">' + icon(ic) + '<span>' + label + '</span></button>'; };
    $('#up-bottom').innerHTML = '<button type="button" class="bb-cancel" data-act="clear-cart">' + icon('xc') + '<span>Cancel</span></button><span class="bb-sep"></span>' +
      (CAN.quotation ? act('draft', 'draft', 'Draft', '#2563EB') + act('quotation', 'quote', 'Quotation', '#F59E0B') : '') +
      act('suspend', 'pause', 'Suspend', '#E5484D', 'F8') +
      (F.kitchen || F.seats ? act('pay-later', 'kitchen', F.kitchen ? 'Send to ' + L.kitchen : 'Pay later', '#0EA5E9') : '') +
      (pays.indexOf('credit') >= 0 && CAN.credit ? act('credit-sale', 'check', 'Credit Sale', '#6366F1') : '') +
      (pays.indexOf('card') >= 0 ? act('pay-card', 'card', 'Card', '#E5484D') : '') +
      '<span class="bb-sep"></span>' +
      '<button type="button" class="bb-pay bb-multi" data-act="pay-multi" data-needs-cart ' + (can ? '' : 'disabled') + ' title="Multiple Pay (F7)">' + icon('multi') + '<span>Multiple Pay</span></button>' +
      '<button type="button" class="bb-pay bb-cash" data-act="pay-cash" data-needs-cart ' + (can ? '' : 'disabled') + ' title="Cash (F4)">' + icon('cash') + '<span>Cash</span></button>' +
      '<div class="bb-total"><small>Total Payable</small><b id="bb-total" class="js-total">' + (S.quote && S.quote.totals ? fmt(S.quote.totals.total) : fmt(0)) + '</b></div>' +
      (CAN.orders ? '<a class="bb-recent" href="' + esc(CFG.base + 'orders') + '">' + icon('clock') + '<span>Recent Transactions</span></a>' : '');
    var ma = $('#m-acts'), mp = $('#m-pay');
    if (!ma || !mp) return;
    var mact = function (a, ic, label, color) { return '<button type="button" class="m-act" data-act="' + a + '" data-needs-cart style="--c:' + color + '" ' + (can ? '' : 'disabled') + '>' + icon(ic) + '<span>' + label + '</span></button>'; };
    ma.innerHTML = (CAN.quotation ? mact('draft', 'draft', 'Draft', '#2563EB') + mact('quotation', 'quote', 'Quotation', '#F59E0B') : '') +
      mact('suspend', 'pause', 'Suspend', '#E5484D') +
      (F.kitchen || F.seats ? mact('pay-later', 'kitchen', F.kitchen ? 'Send to ' + esc(L.kitchen) : 'Pay later', '#0EA5E9') : '') +
      (pays.indexOf('credit') >= 0 && CAN.credit ? mact('credit-sale', 'check', 'Credit Sale', '#6366F1') : '') +
      (pays.indexOf('card') >= 0 ? mact('pay-card', 'card', 'Card', '#E5484D') : '') +
      '<button type="button" class="m-act" data-act="clear-cart" style="--c:var(--danger)">' + icon('xc') + '<span>Cancel</span></button>';
    mp.innerHTML = '<div class="m-pay-total"><span>Total payable</span><b class="js-total">' + (S.quote && S.quote.totals ? fmt(S.quote.totals.total) : fmt(0)) + '</b></div>' +
      '<div class="m-pay-btns"><button type="button" class="bb-pay bb-multi" data-act="pay-multi" data-needs-cart ' + (can ? '' : 'disabled') + '>' + icon('multi') + '<span>Multiple Pay</span></button>' +
      '<button type="button" class="bb-pay bb-cash" data-act="pay-cash" data-needs-cart ' + (can ? '' : 'disabled') + '>' + icon('cash') + '<span>Cash</span></button></div>';
  }

  /* ------------------------------------------------------------------ phone cart sheet */
  var sheetOpen = false;
  function setSheet(open) {
    if (open === sheetOpen) return;
    sheetOpen = open;
    $('#upos').classList.toggle('cart-open', open);
    if (open) { try { history.pushState({ sheet: 1 }, ''); } catch (e) { /* ignore */ } }
    else if (history.state && history.state.sheet) { try { history.back(); } catch (e) { /* ignore */ } }
  }
  window.addEventListener('popstate', function () { if (sheetOpen) { sheetOpen = false; $('#upos').classList.remove('cart-open'); } });
  function bumpBar() { var b = $('#m-cartbar'); if (!b || !isApp()) return; b.classList.remove('bump'); void b.offsetWidth; b.classList.add('bump'); }
  function layoutMode() {
    if (!isApp()) setSheet(false);
    renderFields(); renderTabs(); renderBottom(); renderSum();
  }
  document.addEventListener('omni:appmode', layoutMode);

  /* ------------------------------------------------------------------ modals */
  var M = null;
  function openModal(html, size) {
    var root = $('#modal');
    root.innerHTML = '<div class="modal-backdrop" data-act="modal-close"></div><div class="modal ' + (size || '') + '" role="dialog" aria-modal="true">' + html + '</div>';
    root.classList.add('show');
    setTimeout(function () { var a = root.querySelector('[autofocus]'); if (a) { a.focus(); if (a.select) a.select(); } }, 40);
  }
  function closeModal() { var root = $('#modal'); root.classList.remove('show'); root.innerHTML = ''; M = null; }
  var head = function (title, extra) { return '<div class="modal-h">' + (extra || '') + '<h3>' + title + '</h3><button type="button" class="icon-btn" data-act="modal-close" aria-label="Close">' + icon('x') + '</button></div>'; };
  var foot = function (okAct, okLabel, extra) { return '<div class="modal-f">' + (extra || '') + '<button type="button" class="btn" data-act="modal-close">Cancel</button><button type="button" class="btn primary" data-act="' + okAct + '">' + okLabel + '</button></div>'; };

  function openVariant(p) {
    M = { kind: 'variant', p: p };
    openModal(head(esc(p.name)) + '<div class="modal-b"><div class="opt-grid">' + p.variants.map(function (v) {
      var out = p.tracked && v.stock != null && v.stock <= 0;
      return '<button type="button" class="opt" data-act="pick-variant" data-id="' + v.id + '" ' + (out ? 'disabled style="opacity:.5"' : '') + '><b>' + esc(v.name) + '</b><span>' + fmt(p.price + v.delta) + (p.tracked && v.stock != null ? ' · ' + (v.stock > 0 ? num(v.stock) + ' left' : 'out of stock') : '') + (v.mult > 1 ? ' · ' + num(v.mult) + ' ' + esc(p.unit || 'pcs') : '') + '</span></button>';
    }).join('') + '</div></div>', 'md');
  }
  function openSerial(p) {
    M = { kind: 'serial', p: p };
    openModal(head('IMEI / serial · ' + esc(p.name)) + '<div class="modal-b"><div class="row-add"><input class="input" id="sn-q" placeholder="Type or scan the IMEI / serial" autofocus autocomplete="off"><button type="button" class="btn primary" data-act="serial-ok">' + icon('check', 'sm') + 'Add</button></div><div class="opt-grid" id="sn-list"><span class="muted">Loading…</span></div></div>', 'md');
    loadSerials('');
  }
  function loadSerials(q) {
    fetch(CFG.base + 'sell/serials/' + M.p.id + (q ? '?q=' + encodeURIComponent(q) : ''), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); }).then(function (j) {
        var el = $('#sn-list'); if (!el || !M || M.kind !== 'serial') return;
        var used = S.cart.lines.map(function (l) { return l.serial; });
        var list = (j.data || []).filter(function (s) { return used.indexOf(s) < 0; });
        el.innerHTML = list.length ? list.map(function (s) { return '<button type="button" class="opt" data-act="pick-serial" data-v="' + esc(s) + '"><b>' + esc(s) + '</b><span>In stock</span></button>'; }).join('') : '<span class="muted">No serials in stock' + (q ? ' matching “' + esc(q) + '”' : '') + '.</span>';
      });
  }
  function openWeigh(p, line) {
    M = { kind: 'weigh', p: p, line: line || null };
    openModal(head('Weight · ' + esc(p ? p.name : line.name)) + '<div class="modal-b"><label class="field">Quantity (' + esc((p || line).unit || 'kg') + ')<input class="input" id="wg" type="number" step="0.001" min="0" inputmode="decimal" value="' + (line ? line.qty : 1) + '" autofocus style="height:48px;font-size:20px;font-weight:700"></label>' +
      '<div class="quick">' + [0.25, 0.5, 1, 1.5, 2, 5].map(function (x) { return '<button type="button" class="chip" data-act="wg-set" data-v="' + x + '">' + x + ' ' + esc((p || line).unit || 'kg') + '</button>'; }).join('') + '</div></div>' + foot('wg-ok', icon('check', 'sm') + 'Add'), '');
  }
  function openLineEdit(l) {
    M = { kind: 'line', line: l };
    openModal(head(esc(l.name)) + '<div class="modal-b"><div class="form-grid">' +
      (CAN.price ? '<label class="field">Unit price<input class="input" id="le-price" type="number" step="any" min="0" value="' + (l.unit_price != null && l.unit_price !== '' ? l.unit_price : l.price) + '"></label>' : '<div class="field"><span>Unit price</span><b>' + fmt(l.price) + '</b><small class="muted">Your role cannot change prices.</small></div>') +
      (l.serial ? '' : '<label class="field">Quantity<input class="input" id="le-qty" type="number" step="' + (l.decimal ? 'any' : 1) + '" min="0" value="' + l.qty + '"></label>') +
      '<label class="field span2">Note<input class="input" id="le-note" maxlength="255" value="' + esc(l.note) + '" placeholder="e.g. less sugar, gift wrap"></label></div></div>' +
      foot('le-ok', icon('check', 'sm') + 'Update', '<button type="button" class="btn danger" data-act="le-del" style="margin-right:auto">' + icon('trash', 'sm') + 'Remove</button>'), 'md');
  }
  function openDiscount() {
    var d = S.cart.discount || { type: 'percent', value: 0 };
    M = { kind: 'discount' };
    openModal(head('Discount') + '<div class="modal-b"><div class="seg full"><button type="button" data-act="dt" data-v="percent" class="' + (d.type !== 'fixed' ? 'on' : '') + '">Percent (%)</button><button type="button" data-act="dt" data-v="fixed" class="' + (d.type === 'fixed' ? 'on' : '') + '">Fixed (' + esc(SYM) + ')</button></div>' +
      '<label class="field">Discount<input class="input" id="dc-v" type="number" step="any" min="0" value="' + (+d.value || '') + '" autofocus></label>' +
      '<div class="muted" style="font-size:12.5px">Your role can give up to <b>' + esc(CFG.user.maxDiscount) + '%</b> discount.</div></div>' + foot('dc-ok', icon('check', 'sm') + 'Apply'), '');
    M.type = d.type === 'fixed' ? 'fixed' : 'percent';
  }
  function openTax() {
    M = { kind: 'tax' };
    openModal(head(esc(B.taxLabel) + ' rate') + '<div class="modal-b"><p class="muted" style="margin:0">Each product follows its own ' + esc(B.taxLabel) + ' rule. Choose a rate to apply it to the whole order, or keep the product rates.</p>' +
      '<div class="opt-grid"><button type="button" class="opt ' + (S.cart.tax_override === '' ? 'on' : '') + '" data-act="tax-set" data-v=""><b>Product rates</b><span>Default</span></button>' +
      (B.rates || []).map(function (r) { return '<button type="button" class="opt ' + (String(S.cart.tax_override) === String(r) ? 'on' : '') + '" data-act="tax-set" data-v="' + r + '"><b>' + r + '%</b><span>All lines</span></button>'; }).join('') + '</div></div>', '');
  }
  function openShipping() {
    var s = S.cart.shipping || { charge: 0, address: '' };
    M = { kind: 'shipping' };
    openModal(head('Shipping') + '<div class="modal-b"><div class="form-grid"><label class="field">Shipping charge<input class="input" id="sh-c" type="number" step="any" min="0" value="' + (+s.charge || '') + '" autofocus></label>' +
      '<label class="field span2">Delivery address<textarea class="input" id="sh-a" maxlength="255">' + esc(s.address) + '</textarea></label></div></div>' + foot('sh-ok', icon('check', 'sm') + 'Apply'), 'md');
  }
  function openAddCustomer() {
    M = { kind: 'customer' };
    openModal(head('Add customer') + '<div class="modal-b"><div class="form-grid"><label class="field span2">Name *<input class="input" id="nc-name" maxlength="120" autofocus></label>' +
      '<label class="field">Mobile<input class="input" id="nc-phone" inputmode="tel" maxlength="20" placeholder="01XXX-XXXXXX"></label><label class="field">Address<input class="input" id="nc-addr" maxlength="255"></label></div></div>' + foot('nc-ok', icon('userplus', 'sm') + 'Add customer'), 'md');
  }

  /* payments */
  function total() { return S.quote && S.quote.totals ? +S.quote.totals.total : 0; }
  function quickCash(due) {
    var step = DEC === 0 ? [10000, 50000, 100000, 500000] : B.code === 'BDT' ? [10, 50, 100, 500, 1000] : [5, 10, 20, 50, 100];
    var out = [due]; step.forEach(function (s) { var v = Math.ceil(due / s) * s; if (out.indexOf(v) < 0) out.push(v); });
    return out.slice(0, 5);
  }
  function openCash(order) {
    var due = order ? order.due : total();
    M = { kind: 'cash', order: order || null, due: due };
    openModal(head(order ? 'Cash payment · ' + esc(order.doc_no) : 'Cash payment') + '<div class="modal-b"><div class="due-box"><small>Total payable</small><b>' + fmt(due) + '</b></div>' +
      '<label class="field">Amount tendered<input class="input" id="tender" type="number" step="any" min="0" inputmode="decimal" placeholder="' + r2(due) + '" autofocus style="height:48px;font-size:20px;font-weight:700"></label>' +
      '<div class="quick">' + quickCash(due).map(function (q) { return '<button type="button" class="chip" data-act="tender" data-v="' + q + '">' + fmt(q) + '</button>'; }).join('') + '</div>' +
      '<div class="change-box" id="change-box"><span>Change return</span><b>' + fmt(0) + '</b></div></div>' + foot('cash-ok', icon('check', 'sm') + 'Finalize payment'), 'md');
  }
  function openCard(order) {
    var due = order ? order.due : total();
    M = { kind: 'card', order: order || null, due: due };
    openModal(head('Card transaction') + '<div class="modal-b"><div class="due-box"><small>Total payable</small><b>' + fmt(due) + '</b></div><div class="form-grid">' +
      '<label class="field">Card last 4 digits<input class="input" id="cd-num" inputmode="numeric" maxlength="4" placeholder="4242" autofocus></label><label class="field">Card transaction no.<input class="input" id="cd-ref" maxlength="40"></label>' +
      '<label class="field">Card type<select class="select" id="cd-type">' + ['Visa', 'MasterCard', 'Amex', 'UnionPay', 'Other'].map(function (x) { return '<option>' + x + '</option>'; }).join('') + '</select></label></div>' +
      '<div class="muted" style="font-size:12.5px">Only the last 4 digits are stored. Never record the security code.</div></div>' + foot('card-ok', icon('check', 'sm') + 'Finalize payment'), 'md');
  }
  function openMulti(order) {
    var methods = (B.payments || ['cash']).filter(function (m) { return m !== 'credit'; });
    var due = order ? order.due : total();
    var cu = S.cart.customer, ex = !order && cu && !cu.walkIn ? +cu.exchange || 0 : 0;
    var rows = [{ method: methods[0] || 'cash', amount: String(r2(due)), ref: '' }];
    if (ex > 0) {
      // the customer's exchange credit pays first; the rest is cash / card / due
      methods = ['exchange'].concat(methods);
      var useEx = r2(Math.min(ex, due));
      rows = [{ method: 'exchange', amount: String(useEx), ref: '' }];
      if (due - useEx > 0.004) rows.push({ method: methods[1] || 'cash', amount: String(r2(due - useEx)), ref: '' });
    }
    M = { kind: 'multi', order: order || null, due: due, methods: methods, rows: rows, exchange: ex };
    renderMulti();
  }
  function renderMulti() {
    var ph = function (m) { return ({ card: 'Card last 4 / slip no.', cheque: 'Cheque no. & bank', bank: 'Bank & transaction ID', mobile: 'Trx ID (bKash / Nagad)' }[m] || 'Optional'); };
    openModal(head(M.order ? 'Collect payment · ' + esc(M.order.doc_no) : 'Payment') + '<div class="modal-b"><div class="multi"><div class="multi-rows">' +
      M.rows.map(function (r, i) {
        return '<div class="pay-row"><div class="pay-row-h"><b>Payment ' + (i + 1) + '</b>' + (M.rows.length > 1 ? '<button type="button" class="x-btn" data-act="mp-del" data-i="' + i + '" aria-label="Remove">' + icon('x', 'sm') + '</button>' : '') + '</div><div class="form-grid">' +
          '<label class="field">Amount<input class="input" type="number" step="any" min="0" data-mp="' + i + '" data-mf="amount" value="' + esc(r.amount) + '"' + (i === M.rows.length - 1 ? ' autofocus' : '') + '></label>' +
          '<label class="field">Payment method<select class="select" data-mp="' + i + '" data-mf="method">' + M.methods.map(function (m) { return '<option value="' + m + '" ' + (r.method === m ? 'selected' : '') + '>' + esc(payLabel(m)) + '</option>'; }).join('') + '</select></label>' +
          '<label class="field span2">Reference<input class="input" data-mp="' + i + '" data-mf="ref" maxlength="64" value="' + esc(r.ref) + '" placeholder="' + ph(r.method) + '"></label></div></div>';
      }).join('') + '<button type="button" class="btn soft block" data-act="mp-add">' + icon('plus', 'sm') + 'Add payment row</button>' +
      (M.order ? '' : '<label class="field">Sell note<textarea class="input" id="mp-note" maxlength="500">' + esc(S.cart.note) + '</textarea></label>') +
      '</div><div class="multi-side" id="mp-side"></div></div></div>' + foot('mp-ok', icon('check', 'sm') + 'Finalize payment'), 'lg');
    updateMulti();
  }
  function multiSums() {
    var paying = 0, cash = 0;
    M.rows.forEach(function (r) { var a = parseFloat(r.amount) || 0; paying += a; if (r.method === 'cash') cash += a; });
    return { paying: r2(paying), change: r2(Math.max(0, paying - M.due)), balance: r2(Math.max(0, M.due - paying)), cash: cash };
  }
  function updateMulti() {
    var el = $('#mp-side'); if (!el) return;
    var s = multiSums();
    el.innerHTML = '<div><small>Total payable</small><b>' + fmt(M.due) + '</b></div><div><small>Total paying</small><b>' + fmt(s.paying) + '</b></div>' +
      '<div><small>Change return</small><b class="' + (s.change ? 'pos' : '') + '">' + fmt(s.change) + '</b></div><div><small>Balance (due)</small><b class="' + (s.balance ? 'neg' : '') + '">' + fmt(s.balance) + '</b></div>' +
      (M.exchange ? '<div><small>Exchange credit available</small><b class="pos">' + fmt(M.exchange) + '</b></div>' : '') +
      (s.balance > 0 && !M.order ? '<div><small>' + (CAN.credit ? 'The balance goes on ' + esc((S.cart.customer || {}).name || 'the customer') + '\'s due book' : 'Your role cannot leave a due') + '</small></div>' : '');
  }
  function openCredit() {
    var cu = S.cart.customer;
    if (!cu || cu.walkIn) { toast('Choose the customer to sell on credit (বাকি)', 'err'); var i = $('#cust-q'); if (i) { i.focus(); i.select(); } return; }
    M = { kind: 'credit' };
    var after = (+cu.due || 0) + total();
    openModal(head('Credit sale · ' + esc(cu.name)) + '<div class="modal-b"><div class="due-box"><small>On the due book</small><b>' + fmt(total()) + '</b></div>' +
      '<div class="change-box"><span>Current due</span><b>' + fmt(cu.due || 0) + '</b></div><div class="change-box ' + (+cu.limit && after > cu.limit ? 'neg' : '') + '"><span>Due after this sale' + (+cu.limit ? ' (limit ' + fmt(cu.limit) + ')' : '') + '</span><b>' + fmt(after) + '</b></div></div>' +
      foot('credit-ok', icon('check', 'sm') + 'Sell on credit'), 'md');
  }
  function openSuspend(kind) {
    M = { kind: 'suspend', mode: kind || 'hold' };
    var title = kind === 'draft' ? 'Save draft' : kind === 'quotation' ? 'Save quotation' : 'Suspend sale';
    openModal(head(title) + '<div class="modal-b"><label class="field">Note<textarea class="input" id="sus-note" maxlength="255" placeholder="e.g. ' + esc(S.cart.table_ref || 'customer will return') + '" autofocus></textarea></label>' +
      '<div class="muted" style="font-size:12.5px">' + (kind ? 'Saved without stock or payment; load it again from Suspended sales.' : 'Parks the cart without stock or payment so you can serve the next customer.') + '</div></div>' + foot('suspend-ok', icon('pause', 'sm') + title), '');
  }
  function openPayLater() {
    M = { kind: 'later' };
    openModal(head(F.kitchen ? 'Send to ' + esc(L.kitchen) : 'Pay later') + '<div class="modal-b"><p class="muted" style="margin:0">The order is saved now' + (S.cart.kitchen && F.kitchen ? ' and sent to the ' + esc(String(L.kitchen).toLowerCase()) + ' screen' : '') + '. It stays open' + (S.cart.table_ref ? ' on <b>' + esc(S.cart.table_ref) + '</b>' : '') + ' until it is paid from Suspended sales or the order page.</p>' +
      '<div class="due-box"><small>Order total</small><b>' + fmt(total()) + '</b></div></div>' + foot('later-ok', icon('kitchen', 'sm') + 'Send order'), 'md');
  }
  function openParked() {
    M = { kind: 'parked' };
    openModal(head('Suspended sales') + '<div class="modal-b" id="parked-b"><span class="muted">Loading…</span></div>', 'md');
    api('GET', 'sell/parked').then(function (d) {
      var el = $('#parked-b'); if (!el) return;
      var row = function (title, sub, amount, actions) { return '<div class="line" style="align-items:center"><span class="lthumb has-img"><img class="demo-img" src="' + esc(CFG.demoImg) + '" alt=""></span><div class="line-main"><b>' + title + '</b><div class="line-sub">' + sub + '</div></div><b>' + amount + '</b>' + actions + '</div>'; };
      var html = '';
      if (!d.held.length && !d.open.length) html = '<div class="cart-empty"><span class="circle">' + icon('pause') + '</span><b>Nothing suspended</b><span>Use Suspend to park a sale and serve the next customer.</span></div>';
      html += d.held.map(function (h) {
        var tag = h.kind === 'hold' ? '' : '<span class="tag ' + (h.kind === 'quotation' ? 'primary' : '') + '">' + (h.kind === 'quotation' ? 'Quotation' : 'Draft') + '</span> ';
        return row(tag + esc(h.customer_name || 'Walk-in') + (h.table_ref ? ' · ' + esc(h.table_ref) : ''), '<span>' + num(h.item_count) + ' items</span><span>' + esc(String(h.created_at).slice(11, 16)) + '</span>' + (h.note ? '<span>' + icon('edit', 'sm') + ' ' + esc(h.note) + '</span>' : '') + (h.user_name ? '<span>' + esc(h.user_name) + '</span>' : ''), fmt(h.total),
          '<button type="button" class="btn sm soft" data-act="resume" data-id="' + h.id + '">Resume</button><button type="button" class="x-btn" data-act="held-del" data-id="' + h.id + '" aria-label="Delete">' + icon('trash', 'sm') + '</button>');
      }).join('');
      if (d.open.length) html += '<div class="field">Open orders (sent to the ' + esc(String(L.kitchen).toLowerCase()) + ' / table, not paid yet)</div>';
      html += d.open.map(function (o) {
        return row(esc(o.doc_no) + ' · ' + esc(o.customer_name) + (o.table_ref ? ' · ' + esc(o.table_ref) : ''), '<span>' + num(o.item_count) + ' items</span><span>' + esc(String(o.created_at).slice(11, 16)) + '</span>' + (o.kitchen_status !== 'none' ? '<span class="tag">' + esc(o.kitchen_status) + '</span>' : ''), fmt(o.due_total),
          '<a class="btn sm" href="' + esc(CFG.base + 'orders/' + o.id) + '">View</a><button type="button" class="btn sm primary" data-act="pay-open" data-id="' + o.id + '" data-no="' + esc(o.doc_no) + '" data-due="' + o.due_total + '">Pay</button>');
      }).join('');
      el.innerHTML = html;
      setHeld(d.held.length + d.open.length);
    }).catch(function (e) { toast(e.message, 'err'); closeModal(); });
  }
  function showReceipt(r) {
    M = { kind: 'receipt', r: r };
    var o = r.order;
    var title = o.status === 'open' ? 'Order ' + esc(o.doc_no) + ' sent' : o.due > 0 ? 'Sale saved with balance due' : 'Payment complete';
    var pr = r.print || {};
    var link = function (href, ic, label, cls) { return href ? '<a class="btn ' + (cls || '') + '" href="' + esc(href) + '" target="_blank" rel="noopener">' + icon(ic, 'sm') + label + '</a>' : ''; };
    var P = window.OmniPrint;
    openModal(head(title, '<span class="tag ' + (o.status === 'paid' ? 'ok' : o.status === 'open' ? 'warn' : 'danger') + '">' + esc(o.status === 'paid' ? 'Paid' : o.status === 'open' ? 'Open' : o.status === 'partial' ? 'Partially paid' : 'Due') + '</span>') +
      (r.change > 0 ? '<div class="change-box pos" style="margin:12px 18px 0"><span>Change return</span><b>' + esc(r.change_text) + '</b></div>' : '') +
      '<div class="modal-b rc-wrap">' + r.receipt_html + '</div>' +
      '<div class="modal-f">' + link(pr.challan, 'truck', 'Challan', '') + link(pr.kot, 'kitchen', String(L.kitchen) + ' ticket', '') + link(pr.invoice, 'print', L.invoice || 'Invoice', '') +
      (P ? '<button type="button" class="btn" data-act="print-receipt">' + icon('print', 'sm') + 'Print receipt</button><button type="button" class="icon-btn" data-print-setup title="Printer setup" aria-label="Printer setup">' + icon('setup', 'sm') + '</button>' : link(pr.receipt, 'print', 'Print (80 mm)', '')) +
      '<button type="button" class="btn primary" data-act="modal-close" autofocus>New sale</button></div>', '');
    // "Print after every sale" (Printer setup, per device): receipt for a sale, kitchen ticket when asked
    var ps = P ? P.settings() : null;
    if (ps && (ps.auto || ps.kot)) {
      var job = ps.auto && o.status !== 'open' ? receiptJob(r) : Promise.resolve();
      if (ps.kot && pr.kot) job = job.then(function () { return P.printUrl(pr.kot); });
      job.catch(function (e) { toast(e.message || String(e), 'err'); });
    }
  }
  // the receipt in the paper set up for it (Setup › Invoice): a thermal roll prints the dialog's copy (it
  // has the cash tendered / change); a page size or several copies print the server's document
  function receiptJob(r) {
    var rs = (B.print || {}).receipt || { size: '80', copies: 1 };
    if (rs.size === 'a4' || rs.size === 'a5' || rs.copies > 1) return window.OmniPrint.printUrl(r.print.receipt);
    return window.OmniPrint.printHtml(r.receipt_html, { kind: 'receipt', paper: rs.size });
  }
  function printReceipt() {
    if (!M || M.kind !== 'receipt' || !window.OmniPrint) return;
    receiptJob(M.r).catch(function (e) { toast(e.message || String(e), 'err'); });
  }

  /* ------------------------------------------------------------------ actions */
  function resetCart() { S.cart = newCart(); S.quote = null; save(); setSheet(false); renderFields(); renderCart(); renderGrid(); renderBottom(); }
  function busy(on) { $$('.modal .btn.primary, .bb-pay, .bb-act').forEach(function (b) { if (on) b.setAttribute('data-busy', '1'); else b.removeAttribute('data-busy'); }); document.body.classList.toggle('till-busy', !!on); }
  function checkout(payments) {
    if (!canCharge()) return toast('Add items first', 'warn');
    busy(true);
    return api('POST', 'sell/checkout', { cart: payload(), payments: payments, token: S.cart.token }).then(function (r) {
      busy(false);
      resetCart();
      reloadSoon();
      showReceipt(r);
    }).catch(function (e) { busy(false); toast(e.message, 'err'); });
  }
  function payOrder(order, payments) {
    busy(true);
    return api('POST', 'sell/pay/' + order.id, { payments: payments }).then(function (r) { busy(false); showReceipt(r); refreshHeldCount(); }).catch(function (e) { busy(false); toast(e.message, 'err'); });
  }
  var reloadTimer = 0;
  function reloadSoon() { clearTimeout(reloadTimer); reloadTimer = setTimeout(function () { loadProducts(false); refreshHeldCount(); }, 400); }
  function refreshHeldCount() { api('GET', 'sell/parked').then(function (d) { setHeld(d.held.length + d.open.length); }).catch(function () {}); }
  function setHeld(n) { ['#held-n', '#m-held-n'].forEach(function (id) { var b = $(id); if (b) { b.hidden = !n; b.textContent = n; } }); }
  function pickCustomer(c) {
    S.cart.customer = c.walkIn ? { id: c.id, name: c.name, walkIn: true, due: 0, exchange: 0, limit: 0 } : { id: c.id, name: c.name, phone: c.phone, due: c.due, exchange: c.exchange || 0, limit: c.limit, walkIn: false };
    changed({ fields: true });
  }
  function lookupCode(code) {
    return fetch(CFG.base + 'sell/lookup?code=' + encodeURIComponent(code), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); }).then(function (j) {
        var d = j && j.data;
        if (!d) return false;
        onProduct(d.product, { serial: d.serial, variant_id: d.variant_id });
        return true;
      });
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-act]');
    if (!t || t.disabled || t.getAttribute('data-busy')) return;
    var a = t.dataset.act;
    var k = t.dataset.k ? lineByKey(t.dataset.k) : null;
    var dlg = t.closest('dialog[open]'); if (dlg) dlg.close();
    switch (a) {
      case 'modal-close': closeModal(); break;
      case 'm-cart': setSheet(true); break;
      case 'm-cart-close': setSheet(false); break;
      case 'print-receipt': printReceipt(); break;
      case 'panel': S.panel = S.panel === t.dataset.v ? '' : t.dataset.v; renderTabs(); break;
      case 'pick-cat': S.cat = +t.dataset.v; S.panel = ''; renderTabs(); loadProducts(false); break;
      case 'featured': S.featured = !S.featured; renderTabs(); loadProducts(false); break;
      case 'clear-q': S.q = ''; var qi = $('#q'); if (qi) qi.value = ''; renderTabs(); loadProducts(false); break;
      case 'refresh-grid': loadProducts(false); break;
      case 'more': loadProducts(true); break;
      case 'p-add': { var p = productById(+t.dataset.id); if (p) onProduct(p); break; }
      case 'pick-variant': { var pv = M.p, vv = pv.variants.filter(function (x) { return x.id === +t.dataset.id; })[0]; closeModal(); addLine(pv, { variant: vv }); break; }
      case 'pick-serial': { var ps = M.p; closeModal(); addLine(ps, { serial: t.dataset.v }); break; }
      case 'serial-ok': { var sv = ($('#sn-q') || {}).value || ''; if (!sv.trim()) return toast('Enter the IMEI / serial', 'warn'); var pp = M.p; closeModal(); addLine(pp, { serial: sv.trim() }); break; }
      case 'wg-set': $('#wg').value = t.dataset.v; break;
      case 'wg-ok': { var w = parseFloat(($('#wg') || {}).value) || 0; if (w <= 0) return toast('Enter the weight', 'warn'); var mp = M.p, ml = M.line; closeModal(); if (ml) setQty(ml, w); else addLine(mp, { qty: w }); break; }
      case 'line-inc': if (k) setQty(k, k.qty + 1); break;
      case 'line-dec': if (k) setQty(k, k.qty - 1); break;
      case 'line-del': if (k) setQty(k, 0); break;
      case 'line-weigh': if (k) openWeigh(null, k); break;
      case 'line-edit': if (k) openLineEdit(k); break;
      case 'le-del': { var dl = M.line; closeModal(); setQty(dl, 0); break; }
      case 'le-ok': {
        var l = M.line, pr = $('#le-price'), qn = $('#le-qty');
        if (pr) { var v = parseFloat(pr.value); l.unit_price = !isNaN(v) && Math.abs(v - l.price) > 1e-9 ? v : null; }
        l.note = ($('#le-note') || {}).value || '';
        closeModal();
        if (qn) setQty(l, parseFloat(qn.value) || 0); else changed();
        break;
      }
      case 'edit-discount': openDiscount(); break;
      case 'dt': M.type = t.dataset.v; $$('[data-act="dt"]').forEach(function (b) { b.classList.toggle('on', b === t); }); break;
      case 'dc-ok': { var dv = Math.max(0, parseFloat(($('#dc-v') || {}).value) || 0); if (M.type === 'percent' && dv > 100) dv = 100; S.cart.discount = { type: M.type, value: dv }; closeModal(); changed(); break; }
      case 'edit-tax': openTax(); break;
      case 'tax-set': S.cart.tax_override = t.dataset.v === '' ? '' : +t.dataset.v; closeModal(); changed(); break;
      case 'edit-shipping': openShipping(); break;
      case 'sh-ok': S.cart.shipping = { charge: Math.max(0, parseFloat(($('#sh-c') || {}).value) || 0), address: ($('#sh-a') || {}).value || '' }; closeModal(); changed(); break;
      case 'add-customer': openAddCustomer(); break;
      case 'nc-ok': {
        var nm = (($('#nc-name') || {}).value || '').trim(); if (!nm) return toast('Enter the customer name', 'warn');
        api('POST', 'sell/customer', { name: nm, phone: ($('#nc-phone') || {}).value || '', address: ($('#nc-addr') || {}).value || '' })
          .then(function (c) { closeModal(); pickCustomer(c); toast((L.customer || 'Customer') + ' added'); }).catch(function (er) { toast(er.message, 'err'); });
        break;
      }
      case 'pick-cust': { var cc = JSON.parse(t.dataset.c); $('#cust-ac').hidden = true; pickCustomer(cc); break; }
      case 'clear-cart': if (!S.cart.lines.length || window.confirm('Clear the cart?')) resetCart(); break;
      case 'pay-cash': if (canCharge()) openCash(); break;
      case 'pay-card': if (canCharge()) openCard(); break;
      case 'pay-multi': if (canCharge()) openMulti(); break;
      case 'credit-sale': if (canCharge()) openCredit(); break;
      case 'tender': $('#tender').value = t.dataset.v; updateChange(); break;
      case 'cash-ok': {
        var tv = parseFloat(($('#tender') || {}).value); var amt = isNaN(tv) || tv <= 0 ? M.due : tv;
        if (amt + 1e-9 < M.due && !M.order && !CAN.credit) return toast('Amount is less than the total', 'err');
        var pays = [{ method: 'cash', amount: amt }], ord = M.order; closeModal();
        if (ord) payOrder(ord, pays); else checkout(pays);
        break;
      }
      case 'card-ok': {
        var last4 = (($('#cd-num') || {}).value || '').replace(/\D/g, '').slice(-4), ref = (($('#cd-ref') || {}).value || '').trim();
        var reference = [($('#cd-type') || {}).value || '', last4 ? '••' + last4 : '', ref].filter(Boolean).join(' ').slice(0, 64);
        var cp = [{ method: 'card', amount: M.due, reference: reference }], co = M.order; closeModal();
        if (co) payOrder(co, cp); else checkout(cp);
        break;
      }
      case 'mp-add': M.rows.push({ method: M.methods.filter(function (x) { return x !== 'exchange'; })[0] || 'cash', amount: String(multiSums().balance || ''), ref: '' }); renderMulti(); break;
      case 'mp-del': M.rows.splice(+t.dataset.i, 1); renderMulti(); break;
      case 'mp-ok': {
        var s = multiSums();
        if (s.change > 0 && s.cash + 1e-9 < s.change) return toast('Change can only be returned from a cash payment', 'err');
        var exUsed = 0; M.rows.forEach(function (r) { if (r.method === 'exchange') exUsed += parseFloat(r.amount) || 0; });
        if (exUsed > (M.exchange || 0) + 1e-9) return toast('Exchange credit available: ' + fmt(M.exchange || 0), 'err');
        if (s.balance > 0 && !M.order) {
          if (!CAN.credit) return toast('Your role cannot leave a balance due', 'err');
          if (!S.cart.customer || S.cart.customer.walkIn) return toast('Select a customer to leave a balance due', 'err');
        }
        var mps = M.rows.map(function (r) { return { method: r.method, amount: r2(parseFloat(r.amount) || 0), reference: r.ref }; }).filter(function (p) { return p.amount > 0; });
        var mo = M.order; if (!mo) S.cart.note = ($('#mp-note') || {}).value || '';
        closeModal();
        if (mo) payOrder(mo, mps); else checkout(mps);
        break;
      }
      case 'credit-ok': closeModal(); checkout([{ method: 'credit', amount: total() }]); break;
      case 'suspend': if (S.cart.lines.length) openSuspend(); else openParked(); break;
      case 'suspended': openParked(); break;
      case 'draft': openSuspend('draft'); break;
      case 'quotation': openSuspend('quotation'); break;
      case 'suspend-ok': {
        var note = ($('#sus-note') || {}).value || '', kind = M.mode; closeModal();
        api('POST', 'sell/hold', { cart: payload(), note: note, kind: kind }).then(function () { resetCart(); refreshHeldCount(); toast(kind === 'hold' ? 'Sale suspended' : (kind === 'draft' ? 'Draft' : 'Quotation') + ' saved'); }).catch(function (er) { toast(er.message, 'err'); });
        break;
      }
      case 'resume': {
        var hid = +t.dataset.id;
        if (S.cart.lines.length && !window.confirm('Replace the current cart? Suspend it first to keep it.')) return;
        api('POST', 'sell/resume/' + hid).then(function (d) { closeModal(); var c = d.cart || {}; S.cart = Object.assign(newCart(), c, { token: uid() }); save(); renderFields(); changed(); refreshHeldCount(); toast('Sale resumed'); }).catch(function (er) { toast(er.message, 'err'); });
        break;
      }
      case 'held-del': if (window.confirm('Delete this suspended sale?')) api('POST', 'sell/held/' + t.dataset.id + '/delete').then(openParked).catch(function (er) { toast(er.message, 'err'); }); break;
      case 'pay-open': { var o = { id: +t.dataset.id, doc_no: t.dataset.no, due: +t.dataset.due }; closeModal(); openMulti(o); break; }
      case 'pay-later': if (canCharge()) openPayLater(); break;
      case 'later-ok': closeModal(); busy(true); api('POST', 'sell/open', { cart: payload(), token: S.cart.token }).then(function (r) { busy(false); resetCart(); reloadSoon(); showReceipt(r); }).catch(function (er) { busy(false); toast(er.message, 'err'); }); break;
      case 'fullscreen': if (!document.fullscreenElement) { if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen(); } else if (document.exitFullscreen) document.exitFullscreen(); break;
      case 'dark': { var root = document.documentElement, d2 = root.dataset.theme === 'dark' ? 'light' : 'dark'; root.dataset.theme = d2; try { localStorage.setItem('omnipos.theme', d2); } catch (er) { /* ignore */ } break; }
      default: break;
    }
  });

  function updateChange() {
    if (!M || M.kind !== 'cash') return;
    var v = parseFloat(($('#tender') || {}).value) || 0, box = $('#change-box');
    var ch = v - M.due;
    box.className = 'change-box ' + (ch < 0 && v > 0 ? 'neg' : ch > 0 ? 'pos' : '');
    box.innerHTML = '<span>' + (ch < 0 && v > 0 ? 'Short by' : 'Change return') + '</span><b>' + fmt(Math.abs(v > 0 ? ch : 0)) + '</b>';
  }

  var custTimer = 0;
  document.addEventListener('input', function (e) {
    var t = e.target;
    if (t.id === 'tender') return updateChange();
    if (t.dataset.mp != null) { M.rows[+t.dataset.mp][t.dataset.mf] = t.value; updateMulti(); return; }
    if (t.id === 'q') {
      S.q = t.value.trim();
      clearTimeout(gTimer);
      gTimer = setTimeout(function () { renderTabs(); loadProducts(false); }, 250);
      return;
    }
    if (t.id === 'sn-q') { clearTimeout(gTimer); gTimer = setTimeout(function () { loadSerials(t.value.trim()); }, 250); return; }
    if (t.id === 'cust-q') {
      clearTimeout(custTimer);
      custTimer = setTimeout(function () {
        fetch(CFG.base + 'sell/customers?q=' + encodeURIComponent(t.value.trim()), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
          .then(function (r) { return r.json(); }).then(function (j) {
            var ac = $('#cust-ac'); if (!ac) return;
            var list = j.data || [];
            ac.innerHTML = list.length ? list.map(function (c) { return '<button type="button" data-act="pick-cust" data-c="' + esc(JSON.stringify(c)) + '"><span class="av sm" style="--ac:#64748B">' + esc((c.name || '?').slice(0, 1)) + '</span><span class="grow"><b>' + esc(c.name) + '</b><br><small class="muted">' + esc(c.phone || (c.walkIn ? 'Walk-in' : '')) + (c.due ? ' · due ' + fmt(c.due) : '') + (c.exchange ? ' · exchange credit ' + fmt(c.exchange) : '') + '</small></span></button>'; }).join('') :
              '<div class="muted" style="padding:8px">No customer found.' + (CAN.addCustomer ? ' <button type="button" class="link" data-act="add-customer">Add new</button>' : '') + '</div>';
            ac.hidden = false;
          });
      }, 200);
    }
  });
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.dataset.lineQty) { var l = lineByKey(t.dataset.lineQty); if (l) setQty(l, parseFloat(t.value) || 0); return; }
    if (t.dataset.mp != null) { M.rows[+t.dataset.mp][t.dataset.mf] = t.value; if (t.dataset.mf === 'method') renderMulti(); else updateMulti(); return; }
    var bind = t.dataset.bind;
    if (!bind) return;
    var c = S.cart;
    if (bind === 'kitchen' || bind === 'express') c[bind] = t.checked;
    else c[bind] = t.value;
    if (bind === 'table_ref') {
      var tb = tableOf(t.value);
      c.checkin = tb && tb.rate && F.hourly ? Math.floor(Date.now() / 1000) : 0;
    }
    changed({ fields: bind === 'table_ref' || bind === 'kitchen' });
  });
  document.addEventListener('keydown', function (e) {
    var t = e.target;
    if (t.id === 'q' && e.key === 'Enter') {
      e.preventDefault();
      var code = t.value.trim(); if (!code) return;
      lookupCode(code).then(function (found) {
        if (found) { t.value = ''; S.q = ''; renderTabs(); loadProducts(false); }
        else if (S.products.length === 1) { onProduct(S.products[0]); t.value = ''; S.q = ''; renderTabs(); loadProducts(false); }
        else if (!S.products.length) toast('No product with code “' + code + '”', 'warn');
      });
      return;
    }
    if (t.id === 'tender' && e.key === 'Enter') { e.preventDefault(); var ok = $('[data-act="cash-ok"]'); if (ok) ok.click(); return; }
    if (t.id === 'sn-q' && e.key === 'Enter') { e.preventDefault(); var so = $('[data-act="serial-ok"]'); if (so) so.click(); return; }
    if (t.id === 'wg' && e.key === 'Enter') { e.preventDefault(); var wo = $('[data-act="wg-ok"]'); if (wo) wo.click(); return; }
    if (e.key === 'Escape' && M) { closeModal(); return; }
    if (M) return;
    if (e.key === 'F4') { e.preventDefault(); if (canCharge()) openCash(); }
    else if (e.key === 'F7') { e.preventDefault(); if (canCharge()) openMulti(); }
    else if (e.key === 'F8') { e.preventDefault(); if (S.cart.lines.length) openSuspend(); else openParked(); }
    else if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(t.tagName)) { e.preventDefault(); var qi = $('#q'); if (qi) qi.focus(); }
  });
  document.addEventListener('focusout', function (e) {
    if (e.target.id === 'cust-q') setTimeout(function () { var ac = $('#cust-ac'); if (ac) ac.hidden = true; var i = $('#cust-q'); if (i && S.cart.customer && document.activeElement !== i) i.value = S.cart.customer.name; }, 200);
  });
  document.addEventListener('focusin', function (e) { if (e.target.id === 'cust-q') e.target.select(); });

  /* ------------------------------------------------------------------ clock & timers */
  setInterval(function () {
    var d = new Date(), el = $('#pill-date');
    if (el) el.textContent = d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) + ', ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    $$('[data-since]').forEach(function (x) { x.textContent = elapsed(+x.dataset.since); });
  }, 1000);
  // the room / table time charge grows: re-price once a minute
  setInterval(function () { if (F.hourly && S.cart.checkin && S.cart.lines.length && !M) requote(); }, 60000);

  /* ------------------------------------------------------------------ boot */
  try { if (localStorage.getItem('omnipos.theme') === 'dark') document.documentElement.dataset.theme = 'dark'; } catch (e) { /* ignore */ }
  var st = CFG.start || {};
  if (st.table) {
    var tb0 = tableOf(st.table);
    if (tb0 && S.cart.table_ref !== tb0.name) {
      if (S.cart.lines.length) toast('The current cart was kept — suspend it to start a new sale on ' + tb0.name, 'warn');
      else { S.cart.table_ref = tb0.name; S.cart.checkin = tb0.rate && F.hourly ? Math.floor(Date.now() / 1000) : 0; save(); }
    }
    try { history.replaceState(null, '', CFG.base + 'sell'); } catch (e) { /* ignore */ }
  }
  renderFields(); renderTabs(); renderCart(); renderBottom();
  loadProducts(false);
  if (S.cart.lines.length) requote();
  refreshHeldCount();
  if (st.resume) {
    api('POST', 'sell/resume/' + st.resume).then(function (d) { S.cart = Object.assign(newCart(), d.cart || {}, { token: uid() }); save(); renderFields(); changed(); toast('Sale resumed'); }).catch(function (er) { toast(er.message, 'err'); });
    try { history.replaceState(null, '', CFG.base + 'sell'); } catch (e) { /* ignore */ }
  }
  if (st.pay) {
    api('GET', 'sell/parked').then(function (d) {
      var o = d.open.filter(function (x) { return +x.id === +st.pay; })[0];
      if (o) openMulti({ id: +o.id, doc_no: o.doc_no, due: +o.due_total }); else toast('That order is not open any more', 'warn');
    });
    try { history.replaceState(null, '', CFG.base + 'sell'); } catch (e) { /* ignore */ }
  }
})();
