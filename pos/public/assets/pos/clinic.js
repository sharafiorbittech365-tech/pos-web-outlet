/*
 * Clinic module: auto-submitting filters, the patient picker (appointment dialog, new prescription),
 * and the prescription editor (the pad on the left is the form: medicine lines with search, keyboard
 * moves, pick-list chips, vitals mirrored from the side card, paper / design switch, copy medicines from
 * an earlier visit on request). Everything still posts as a normal form; without JavaScript the pages
 * keep working (type the values).
 */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var getJson = function (url) {
    return fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : { data: [] }; }).catch(function () { return { data: [] }; });
  };
  var debounce = function (fn, ms) { var t; return function () { var a = arguments, self = this; clearTimeout(t); t = setTimeout(function () { fn.apply(self, a); }, ms); }; };
  var toast = function (m, k) { if (window.OmniUI) window.OmniUI.toast(m, k); };

  $$('[data-cl-autosubmit]').forEach(function (el) { el.addEventListener('change', function () { if (el.form) el.form.submit(); }); });

  /* ---------- patient picker + appointment slots ---------- */
  $$('form[data-cl-appt]').forEach(function (form) {
    var doctor = $('[data-cl-doctor]', form), date = $('[data-cl-date]', form), walk = $('[data-cl-walkin]', form);
    var slotBox = $('[data-cl-slotbox]', form), slots = $('[data-cl-slots]', form);
    var search = $('[data-cl-psearch]', form), results = $('[data-cl-presults]', form), pid = $('[data-cl-pid]', form), picked = $('[data-cl-picked]', form), newp = $('[data-cl-newp]', form);

    function loadSlots() {
      if (!slots || !form.dataset.slotsUrl || (walk && walk.checked)) return;
      slots.innerHTML = '<span class="muted">Loading…</span>';
      getJson(form.dataset.slotsUrl + '?doctor=' + encodeURIComponent(doctor.value) + '&date=' + encodeURIComponent(date.value)).then(function (r) {
        var d = r.data || {}, list = d.slots || [], free = 0;
        if (d.on_leave) { slots.innerHTML = '<span class="muted">The doctor is on leave on this day.</span>'; return; }
        if (!list.length) { slots.innerHTML = '<span class="muted">The doctor has no session on this day.</span>'; return; }
        slots.innerHTML = list.map(function (s) {
          if (s.free) free++;
          var why = s.reason === 'booked' ? 'booked' : (s.reason === 'full' ? 'session full' : (s.reason === 'past' ? 'passed' : ''));
          return '<label class="cl-slot ' + (s.free ? '' : 'off') + '" title="' + esc(why) + '"><input type="radio" name="starts_at" value="' + esc(s.starts_at) + '"' + (s.free ? '' : ' disabled') + ' required><span>' + esc(s.time) + '</span></label>';
        }).join('') + (free ? '' : '<span class="muted">No free slot left on this day.</span>');
      });
    }
    if (slots) {
      var toggleWalk = function () {
        var on = walk && walk.checked;
        slotBox.hidden = on;
        $$('input[name=starts_at]', slots).forEach(function (i) { i.required = !on; });
        if (!on) loadSlots();
      };
      if (doctor) doctor.addEventListener('change', loadSlots);
      if (date) date.addEventListener('change', loadSlots);
      if (walk) walk.addEventListener('change', toggleWalk);
      var dlg = form.closest('dialog');
      if (dlg) new MutationObserver(function () { if (dlg.open) loadSlots(); }).observe(dlg, { attributes: true, attributeFilter: ['open'] });
    }
    if (!search) return;
    function setNewRequired(on) { $$('[data-cl-req]', newp).forEach(function (i) { i.required = on; }); }
    setNewRequired(!pid.value);
    function choose(p) {
      pid.value = p.id;
      picked.hidden = false;
      picked.innerHTML = '<span><b>' + esc(p.name) + '</b> <small class="muted">' + esc([p.code, p.age != null ? p.age + ' y' : '', p.phone].filter(Boolean).join(' · ')) + '</small></span><button class="btn sm" type="button" data-cl-unpick>Change</button>';
      newp.hidden = true; setNewRequired(false);
      results.hidden = true; search.value = ''; search.hidden = true;
    }
    picked.addEventListener('click', function (e) {
      if (!e.target.closest('[data-cl-unpick]')) return;
      pid.value = ''; picked.hidden = true; newp.hidden = false; search.hidden = false; setNewRequired(true); search.focus();
    });
    search.addEventListener('input', debounce(function () {
      var q = search.value.trim();
      if (q.length < 2) { results.hidden = true; return; }
      getJson(form.dataset.searchUrl + '?q=' + encodeURIComponent(q)).then(function (r) {
        var list = r.data || [];
        results.hidden = false;
        results.innerHTML = list.length ? list.map(function (p, i) {
          return '<button type="button" data-i="' + i + '"><span><b>' + esc(p.name) + '</b> <small class="muted">' + esc(p.code) + '</small></span><small class="muted">' + esc([p.age != null ? p.age + ' y' : '', p.phone].filter(Boolean).join(' · ')) + '</small></button>';
        }).join('') : '<div class="muted" style="padding:8px 10px">No match — add the new patient below.</div>';
        $$('button', results).forEach(function (b) { b.addEventListener('click', function () { choose(list[+b.dataset.i]); }); });
      });
    }, 250));
  });

  /* ---------- prescription editor ---------- */
  var rxe = $('#rxe');
  if (!rxe) return;
  var form = $('form[data-rx]');
  var wrap = $('[data-paper]', rxe), pad = $('.rxp', rxe);

  // tabs (tablet / phone)
  $$('.rxe-tabs [role=tab]', rxe).forEach(function (t, i, all) {
    t.addEventListener('click', function () {
      rxe.dataset.view = t.dataset.view;
      all.forEach(function (o) { var on = o === t; o.setAttribute('aria-selected', on ? 'true' : 'false'); o.tabIndex = on ? 0 : -1; });
      fit();
    });
    t.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
      var n = all[(i + (e.key === 'ArrowRight' ? 1 : all.length - 1)) % all.length]; n.focus(); n.click();
    });
  });

  // the paper keeps its real size (mm) and is zoomed to fit; narrow screens get a fluid single column
  function fit() {
    if (!wrap || !pad) return;
    pad.style.zoom = '';
    rxe.classList.remove('rxe-fluid');
    var w = wrap.clientWidth;
    if (!w) return;
    if (w < 560) { rxe.classList.add('rxe-fluid'); return; }
    var natural = pad.offsetWidth;
    if (natural > w) pad.style.zoom = String(Math.max(0.5, (w - 2) / natural));
  }
  window.addEventListener('resize', debounce(fit, 120));
  fit();

  $$('[data-paper-opt]', rxe).forEach(function (r) { r.addEventListener('change', function () { pad.classList.remove('rxp-a4', 'rxp-a5'); pad.classList.add('rxp-' + r.value); fit(); }); });
  $$('[data-style-opt]', rxe).forEach(function (r) { r.addEventListener('change', function () { pad.classList.remove('rxp-classic', 'rxp-modern', 'rxp-minimal'); pad.classList.add('rxp-' + r.value); }); });

  if (!form) return;
  var dirty = false;
  form.addEventListener('input', function () { dirty = true; });
  form.addEventListener('submit', function () { dirty = false; });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  // side-card fields mirror the pad's fields (the pad holds the form values)
  $$('[data-bind]', rxe).forEach(function (side) {
    var main = form.elements.namedItem(side.dataset.bind);
    if (!main || main === side) return;
    side.addEventListener('input', function () { main.value = side.value; dirty = true; });
    main.addEventListener('input', function () { side.value = main.value; });
  });

  // pick-list chips: add the clinic's own phrase to a text block (explicit click only)
  rxe.addEventListener('click', function (e) {
    var chip = e.target.closest('[data-insert]');
    if (!chip) return;
    var ta = form.elements.namedItem(chip.dataset.insert);
    if (!ta) return;
    ta.value = ta.value.trim() === '' ? chip.dataset.value : ta.value.replace(/\s+$/, '') + '\n' + chip.dataset.value;
    ta.dispatchEvent(new Event('input', { bubbles: true }));
    ta.focus();
  });

  // ---- medicine lines
  var list = $('#rxp-meds', form), tpl = $('#rxp-med-tpl');
  function addLine(data, focus) {
    var n = parseInt(list.dataset.next || '0', 10) || 0;
    list.dataset.next = String(n + 1);
    list.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__i__/g, 'n' + n));
    var li = list.lastElementChild;
    if (data) {
      ['medicine_id', 'brand_name', 'generic', 'strength', 'dosage_form', 'dose', 'frequency', 'duration', 'route', 'instructions'].forEach(function (k) {
        var f = li.querySelector('[name$="[' + k + ']"]'); if (f && data[k] != null) f.value = data[k];
      });
    }
    if (focus) $('[data-brand]', li).focus();
    dirty = true;
    return li;
  }
  function move(li, dir) {
    var to = dir < 0 ? li.previousElementSibling : li.nextElementSibling;
    if (!to) return;
    if (dir < 0) list.insertBefore(li, to); else list.insertBefore(to, li);
    dirty = true;
  }
  $('[data-medadd]', form).addEventListener('click', function () { addLine(null, true); });
  list.addEventListener('click', function (e) {
    var li = e.target.closest('[data-med]');
    if (!li) return;
    var mv = e.target.closest('[data-move]');
    if (mv) { move(li, +mv.dataset.move); mv.focus(); }
    if (e.target.closest('[data-del]')) {
      var next = li.nextElementSibling || li.previousElementSibling;
      li.remove(); dirty = true;
      var f = next ? $('[data-brand]', next) : $('[data-medadd]', form); if (f) f.focus();
    }
  });
  list.addEventListener('keydown', function (e) {
    var li = e.target.closest('[data-med]');
    if (!li || e.target.tagName !== 'INPUT') return;
    if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) { e.preventDefault(); move(li, e.key === 'ArrowUp' ? -1 : 1); e.target.focus(); return; }
    var box = $('[data-sugg]', li);
    if (e.target.matches('[data-brand]') && box && !box.hidden) {
      var opts = $$('button', box), cur = opts.indexOf($('button.on', box));
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); if (cur >= 0) opts[cur].classList.remove('on'); cur = (cur + (e.key === 'ArrowDown' ? 1 : opts.length - 1)) % opts.length; opts[cur].classList.add('on'); opts[cur].scrollIntoView({ block: 'nearest' }); return; }
      if (e.key === 'Enter' && cur >= 0) { e.preventDefault(); opts[cur].dispatchEvent(new MouseEvent('mousedown')); return; }
      if (e.key === 'Escape') { box.hidden = true; return; }
    }
    if (e.key === 'Enter') {
      e.preventDefault();
      var fields = $$('input:not([type=hidden])', li), i = fields.indexOf(e.target);
      if (i === fields.length - 1) { var nxt = li.nextElementSibling; if (nxt) $('[data-brand]', nxt).focus(); else addLine(null, true); } else { fields[i + 1].focus(); }
    }
  });
  // no Enter-submits from the pad's one-line fields
  form.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.matches('.rxd:not(textarea)') && !e.target.closest('[data-med]')) e.preventDefault(); });

  // medicine search (the platform medicines list; typed names are kept as typed)
  if (form.dataset.medOn === '1') {
    var lookup = debounce(function (input) {
      var li = input.closest('[data-med]'), box = $('[data-sugg]', li), q = input.value.trim();
      if (q.length < 2) { box.hidden = true; return; }
      getJson(form.dataset.medUrl + '?q=' + encodeURIComponent(q)).then(function (r) {
        var meds = r.data || [];
        if (!meds.length || document.activeElement !== input) { box.hidden = true; return; }
        box.hidden = false;
        box.innerHTML = meds.map(function (m, i) {
          return '<button type="button" role="option" tabindex="-1" data-i="' + i + '"><b>' + esc(m.brand_name) + '</b> ' + esc(m.strength) + ' <small>' + esc([m.dosage_form, m.generic, m.manufacturer].filter(Boolean).join(' · ')) + '</small></button>';
        }).join('');
        $$('button', box).forEach(function (b) {
          b.addEventListener('mousedown', function (ev) {
            ev.preventDefault();
            var m = meds[+b.dataset.i];
            input.value = m.brand_name;
            $('[data-mid]', li).value = m.id;
            $('[data-generic]', li).value = m.generic || '';
            $('[data-strength]', li).value = m.strength || '';
            $('[data-form]', li).value = m.dosage_form || '';
            box.hidden = true;
            var dose = li.querySelector('[name$="[dose]"]'); if (dose) dose.focus();
          });
        });
      });
    }, 220);
    list.addEventListener('input', function (e) {
      if (!e.target.matches('[data-brand]')) return;
      $('[data-mid]', e.target.closest('[data-med]')).value = ''; // typed by hand: no longer the listed medicine
      lookup(e.target);
    });
    list.addEventListener('focusout', function (e) {
      if (e.target.matches('[data-brand]')) setTimeout(function () { var b = $('[data-sugg]', e.target.closest('[data-med]') || document); if (b) b.hidden = true; }, 150);
    });
  }

  // typed investigations
  var xt = $('[data-xtests]', form);
  if (xt) {
    $('[data-xadd]', form).addEventListener('click', function () {
      var n = parseInt(xt.dataset.next || '0', 10) || 0; xt.dataset.next = String(n + 1);
      xt.insertAdjacentHTML('beforeend', '<li><input class="rxd" name="extra_tests[n' + n + '][name]" maxlength="160" aria-label="Test" placeholder="Test name"><input type="hidden" name="extra_tests[n' + n + '][category]" value="other"><button class="rxp-tb del" type="button" data-xdel aria-label="Remove test">&#215;</button></li>');
      $('input', xt.lastElementChild).focus();
    });
    xt.addEventListener('click', function (e) { var d = e.target.closest('[data-xdel]'); if (d) { d.closest('li').remove(); dirty = true; } });
  }

  // copy the medicines of an earlier visit — only when the clinician asks; every line stays editable
  $$('[data-copy-from]', rxe).forEach(function (b) {
    b.addEventListener('click', function () {
      if (!window.confirm('Add the medicines of that visit to this prescription? Check every line before you finalize.')) return;
      getJson(form.dataset.copyUrl + '/' + b.dataset.copyFrom + '/items?into=' + form.dataset.id).then(function (r) {
        var items = r.data || [];
        items.forEach(function (it) { addLine(it, false); });
        toast(items.length ? items.length + ' medicine line(s) added — review them on the pad.' : 'That visit has no medicines.');
        rxe.dataset.view = 'doc';
        var tab = $('#rxe-tab-doc'); if (tab) tab.click();
      });
    });
  });
})();
