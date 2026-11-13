/* Setup › Outlets: the three-step "add outlet" wizard (progressive: without JS all steps show). */
(function () {
  'use strict';
  var form = document.querySelector('[data-outlet-wizard]');
  if (!form) { return; }
  form.classList.add('js');
  var steps = form.querySelectorAll('[data-step]');
  var tabs = form.querySelectorAll('[data-step-tab]');

  function show(n) {
    steps.forEach(function (s) { s.classList.toggle('on', s.getAttribute('data-step') === String(n)); });
    tabs.forEach(function (t) {
      var k = Number(t.getAttribute('data-step-tab'));
      t.classList.toggle('on', k === Number(n));
      t.classList.toggle('done', k < Number(n));
    });
    var first = form.querySelector('[data-step="' + n + '"] input:not([type=hidden]), [data-step="' + n + '"] button');
    if (first && n !== 1) { first.focus({ preventScroll: true }); }
  }

  function stepOf(el) {
    var s = el.closest('[data-step]');
    return s ? Number(s.getAttribute('data-step')) : 1;
  }

  function validStep(n) {
    var ok = true;
    form.querySelectorAll('[data-step="' + n + '"] input').forEach(function (i) {
      if (ok && !i.checkValidity()) { ok = false; i.reportValidity(); }
    });
    return ok;
  }

  form.addEventListener('click', function (e) {
    var b = e.target.closest('[data-step-go]');
    if (!b) { return; }
    var to = Number(b.getAttribute('data-step-go'));
    var cur = stepOf(b);
    if (to > cur && !validStep(cur)) { return; }
    show(to);
  });

  // a hidden invalid field (browser validation on submit): open its step
  form.addEventListener('invalid', function (e) { show(stepOf(e.target)); }, true);

  // "copy products from": only outlets of the chosen business type; the outlet list is required only when copying
  var copySel = form.querySelector('.out-copy-from');
  function syncCopy() {
    if (!copySel) { return; }
    var t = form.querySelector('input[name=type]:checked');
    var type = t ? t.value : '';
    var any = false;
    Array.prototype.forEach.call(copySel.options, function (o) {
      if (!o.value) { return; }
      var ok = o.getAttribute('data-type') === type;
      o.hidden = !ok; o.disabled = !ok;
      any = any || ok;
      if (!ok && o.selected) { copySel.value = ''; }
    });
    var radio = form.querySelector('input[name=products][value=copy]');
    form.querySelector('.out-copy-none').hidden = any;
    copySel.hidden = !any;
    radio.disabled = !any;
    if (!any && radio.checked) { form.querySelector('input[name=products][value=template]').checked = true; }
    copySel.required = radio.checked && any;
    form.querySelectorAll('.out-opt').forEach(function (l) { var r = l.querySelector('input[type=radio]'); l.classList.toggle('on', r.checked); l.classList.toggle('off', r.disabled); });
  }

  // recommended modules follow the chosen business type; the chosen card is highlighted
  form.addEventListener('change', function (e) {
    if (e.target.name === 'products') { syncCopy(); if (e.target.value === 'copy' && copySel && !copySel.value) { copySel.focus(); } return; }
    if (e.target === copySel && copySel.value) { form.querySelector('input[name=products][value=copy]').checked = true; syncCopy(); return; }
    if (e.target.name !== 'type') { return; }
    form.querySelectorAll('.tpl').forEach(function (l) { l.classList.toggle('on', l.contains(e.target)); });
    form.querySelectorAll('[data-rec-for]').forEach(function (d) { d.hidden = d.getAttribute('data-rec-for') !== e.target.value; });
    syncCopy();
  });
  syncCopy();

  var err = form.querySelector('.field-err');
  show(err ? stepOf(err) : 1);
})();
