/* POS › Website › Pages: rich-text editor + ready-made page designs. The server sanitizes whatever is saved. */
(function () {
  'use strict';
  var root = document.querySelector('[data-pgb]');
  if (!root) return;
  var ed = root.querySelector('[data-pg-ed]');
  var src = root.querySelector('[data-pg-body]');
  var form = root.closest('form');
  var tpls = {};
  try { tpls = JSON.parse(document.getElementById('pg-tpls').textContent) || {}; } catch (e) { tpls = {}; }
  var sourceMode = false;

  var blocks = {
    note: '<div class="pg-note"><b>Note:</b> write something important here.</div><p></p>',
    cols2: '<div class="pg-cols pg-cols-2"><div class="pg-card"><h4>First</h4><p>Text</p></div><div class="pg-card"><h4>Second</h4><p>Text</p></div></div><p></p>',
    cols3: '<div class="pg-cols pg-cols-3"><div class="pg-card"><h4>First</h4><p>Text</p></div><div class="pg-card"><h4>Second</h4><p>Text</p></div><div class="pg-card"><h4>Third</h4><p>Text</p></div></div><p></p>',
    steps: '<ol class="pg-steps"><li><b>Step one</b> Describe it.</li><li><b>Step two</b> Describe it.</li><li><b>Step three</b> Describe it.</li></ol><p></p>',
    checks: '<ul class="pg-check"><li>First point</li><li>Second point</li><li>Third point</li></ul><p></p>',
    table: '<table class="pg-table"><thead><tr><th>Item</th><th>Details</th></tr></thead><tbody><tr><td>Row one</td><td>Text</td></tr><tr><td>Row two</td><td>Text</td></tr></tbody></table><p></p>',
    faq: '<details class="pg-faq" open><summary>Your question?</summary><p>Your answer.</p></details><p></p>',
    cta: '<div class="pg-cta"><h3>Ready to order?</h3><p>Short line that invites the customer.</p><p><a href="/all">Shop now</a></p></div><p></p>',
    hero: '<div class="pg-hero"><h2>Big title</h2><p>One line that explains the page.</p></div><p></p>',
    stats: '<div class="pg-stats"><div><b>100+</b><span>Label</span></div><div><b>24h</b><span>Label</span></div><div><b>4.8</b><span>Label</span></div></div><p></p>'
  };

  function focusEd() { if (!sourceMode) ed.focus(); }
  function exec(cmd, val) { focusEd(); try { document.execCommand('styleWithCSS', false, false); } catch (e) {} document.execCommand(cmd, false, val || null); }
  function html(h) { focusEd(); document.execCommand('insertHTML', false, h); }
  function hasContent() { return (ed.textContent || '').trim() !== '' || !!ed.querySelector('img,hr,table'); }
  function sync() { if (!sourceMode) src.value = ed.innerHTML; }

  root.addEventListener('mousedown', function (e) { if (e.target.closest('.pgb-bar button')) e.preventDefault(); });
  root.addEventListener('click', function (e) {
    var b = e.target.closest('.pgb-bar button');
    if (!b) return;
    if (b.dataset.src !== undefined) { toggleSource(); return; }
    if (sourceMode) return;
    if (b.dataset.link !== undefined) {
      var u = window.prompt('Link address (https://…, tel:, mailto: or /page)');
      if (u) { exec('createLink', u.trim()); }
      return;
    }
    var c = b.dataset.cmd || '';
    if (c.indexOf('formatBlock:') === 0) exec('formatBlock', c.split(':')[1]); else if (c) exec(c);
    sync();
  });
  root.querySelector('[data-fmt]').addEventListener('change', function () { exec('formatBlock', this.value); this.value = 'p'; sync(); });
  root.querySelector('[data-block]').addEventListener('change', function () { var h = blocks[this.value]; this.value = ''; if (h && !sourceMode) { html(h); sync(); } });

  function toggleSource() {
    sourceMode = !sourceMode;
    root.classList.toggle('pgb-source', sourceMode);
    if (sourceMode) { src.value = ed.innerHTML; src.hidden = false; ed.hidden = true; src.focus(); }
    else { ed.innerHTML = src.value; src.hidden = true; ed.hidden = false; }
  }

  // ready-made designs
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-tpl]');
    if (!t || !tpls[t.dataset.tpl]) return;
    if (hasContent() && !window.confirm('Replace the current content with this design?')) return;
    if (sourceMode) toggleSource();
    ed.innerHTML = tpls[t.dataset.tpl];
    var title = form && form.querySelector('[name=title]');
    if (title && !title.value.trim() && t.dataset.tpl !== 'blank') title.value = t.querySelector('b').textContent;
    sync();
    var box = t.closest('details'); if (box) box.open = false;
    ed.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  });

  ed.addEventListener('input', sync);
  ed.addEventListener('paste', function (e) {
    // keep pasted text clean (no foreign fonts/colours); the server sanitizes anyway
    var d = e.clipboardData; if (!d) return;
    var h = d.getData('text/html');
    if (h) { e.preventDefault(); var tmp = document.createElement('div'); tmp.innerHTML = h; tmp.querySelectorAll('script,style,iframe,object').forEach(function (n) { n.remove(); }); tmp.querySelectorAll('*').forEach(function (n) { n.removeAttribute('style'); n.removeAttribute('id'); }); document.execCommand('insertHTML', false, tmp.innerHTML); }
  });
  if (form) form.addEventListener('submit', function () { if (sourceMode) { ed.innerHTML = src.value; } src.value = ed.innerHTML; src.hidden = false; });
})();
