/* Subscription › Modules & features: live price preview (GET subscription/quote) before confirming.
   The server recomputes everything on submit; this only shows the result early. */
(function () {
  'use strict';
  var form = document.querySelector('[data-msel]');
  if (!form) { return; }
  var url = form.getAttribute('data-quote');
  var lines = form.querySelector('[data-msel-lines]');
  var delta = form.querySelector('[data-msel-delta]');
  var err = form.querySelector('[data-msel-error]');
  var submit = form.querySelector('[data-msel-submit]');
  var bar = form.querySelector('[data-msel-total]');
  var submit2 = form.querySelector('[data-msel-submit2]');
  function sync() { if (submit2) { submit2.disabled = submit.disabled; } }
  var initial = new URLSearchParams(new FormData(form)).toString();
  var timer = null;
  var seq = 0;

  function tk(n) {
    var v = Number(n) || 0;
    return (v < 0 ? '−' : '') + '৳' + Math.round(Math.abs(v)).toLocaleString('en-US');
  }
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined) { e.textContent = text; }
    return e;
  }
  function row(label, value, cls) {
    var r = el('div', 'bs-r' + (cls ? ' ' + cls : ''));
    r.appendChild(el('span', '', label));
    r.appendChild(el('b', '', value));
    return r;
  }

  function params() {
    var p = new URLSearchParams();
    new FormData(form).forEach(function (v, k) { if (k.indexOf('modules[') === 0 || k.indexOf('features[') === 0 || k === 'outlets') { p.set(k, v); } });
    return p;
  }

  function render(q) {
    err.hidden = !!q.ok;
    if (!q.ok) { err.textContent = q.error || 'This selection is not possible.'; }
    lines.textContent = '';
    if (q.breakdown && q.breakdown.rows) {
      // database-driven price: base, website, domain, multi-outlet website, extra outlets, modules, discounts, final price
      q.breakdown.rows.forEach(function (r) {
        var amount = r.type === 'minus' ? (r.amount > 0 ? tk(-r.amount) : '—') : (r.amount > 0 || r.type === 'total' ? tk(r.amount) : '—');
        var cls = r.type === 'total' ? 'tot' : (r.type === 'separate' || r.amount <= 0 ? 'muted' : '');
        var line = row(r.label + (r.note ? ' · ' + r.note : ''), amount, cls);
        lines.appendChild(line);
      });
      var note = form.querySelector('[data-outlets-note]');
      var oi = form.querySelector('input[name="outlets"]');
      if (note && oi) { note.hidden = String(q.breakdown.outlets) === oi.getAttribute('data-current'); }
    } else {
      (q.lines || []).forEach(function (l) { lines.appendChild(row(l.label, tk(l.monthly))); });
      lines.appendChild(row('Total per month', tk(q.monthly), 'tot'));
    }
    var changed = q.ok && q.changes && q.changes.length > 0;
    delta.hidden = !changed;
    delta.textContent = '';
    if (changed) {
      var d = Number(q.delta) || 0;
      delta.appendChild(el('span', '', q.changes.map(function (c) { return c.label; }).join(' · ')));
      delta.appendChild(el('b', '', (d >= 0 ? '+' : '−') + tk(Math.abs(d)).replace('−', '') + ' per month'));
      if (q.trial) {
        delta.appendChild(el('span', 'muted', 'Free during your trial.'));
      } else if (q.due_now > 0) {
        delta.appendChild(el('span', '', 'Due now: ' + tk(q.due_now) + ' for the rest of this period (' + q.left_pct + '%).'));
      } else if (d < 0) {
        delta.appendChild(el('span', 'muted', 'The lower price applies from your next renewal.'));
      }
    }
    submit.disabled = !q.ok || !changed;
    var rows = q.breakdown && q.breakdown.rows ? q.breakdown.rows.filter(function (r) { return r.type === 'total'; }) : [];
    var total = rows.length ? rows[0].amount : q.monthly;
    if (bar && total != null) { bar.textContent = tk(total); }
    sync();
  }

  function refresh() {
    var p = params();
    if (new URLSearchParams(new FormData(form)).toString() === initial) {
      delta.hidden = true; err.hidden = true; submit.disabled = true; sync();
    }
    var mine = ++seq;
    fetch(url + '?' + p.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (mine === seq && j && j.data) { render(j.data); } })
      .catch(function () { submit.disabled = false; sync(); });
  }

  submit.disabled = true; sync();
  form.addEventListener('input', function (e) { if (e.target && e.target.name === 'outlets') { clearTimeout(timer); timer = setTimeout(refresh, 250); } });
  form.addEventListener('change', function () { clearTimeout(timer); timer = setTimeout(refresh, 150); });
})();
