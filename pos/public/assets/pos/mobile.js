/*!
 * BizPilot360X POS — phone layout helpers (html.app-mode, set in <head> by App\Views\_app_mode).
 *   table.tbl with 3+ columns   cells get data-label from their header, so app.css can show each
 *                               row as a card on phones (".cardify"); 6+ columns (".tbl-wide")
 *                               are cards on tablets too, two a row (pos.css)
 *   .chip runs in .page-h       wrapped in .m-chiprow, a single swipeable row on phones
 *   <dialog> backdrop tap       closes the dialog (the "More" sheet and other bottom sheets)
 */
(function () {
  'use strict';
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  var SKIP = '.a4, .receipt, .ivd, .mk, #omni-print, .no-cardify, .debug-view-content, [data-no-cardify]';

  function labelRows(t, ths) {
    $$('tbody tr, tfoot tr', t).forEach(function (tr) {
      var col = 0;
      Array.prototype.forEach.call(tr.children, function (td) {
        if (!td.hasAttribute('data-label')) td.setAttribute('data-label', ths[col] ? ths[col].textContent.trim() : '');
        col += td.colSpan || 1;
      });
    });
  }

  function cardify(root) {
    $$('table', root).forEach(function (t) {
      if (t.matches(SKIP) || t.closest(SKIP)) return;
      var ths = $$('thead th', t);
      if (!ths.length) ths = $$('thead td', t);
      if (t.classList.contains('tbl') && ths.length >= 3) {
        t.classList.add('cardify');
        if (ths.length >= 6) t.classList.add('tbl-wide');
        labelRows(t, ths);
        return;
      }
      // anything else (narrow or unclassed tables) scrolls sideways inside its own box
      if (!t.parentNode.classList.contains('table-wrap') && !t.parentNode.classList.contains('table-scroll')) {
        var w = document.createElement('div'); w.className = 'table-scroll';
        t.parentNode.insertBefore(w, t); w.appendChild(t);
      }
    });
  }
  cardify(document);

  // tables that scripts draw later (search results, reports, modals) get the same treatment
  if (window.MutationObserver) {
    var queued = false, pending = [];
    new MutationObserver(function (list) {
      list.forEach(function (m) { Array.prototype.forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1) pending.push(n); }); });
      if (queued || !pending.length) return;
      queued = true;
      requestAnimationFrame(function () {
        queued = false;
        var nodes = pending; pending = [];
        nodes.forEach(function (n) {
          if (!document.contains(n)) return;
          var t = n.tagName === 'TR' || n.tagName === 'TBODY' ? n.closest('table') : null;
          if (t) { cardify(t.parentNode); }
          else if (n.tagName === 'TABLE' || n.querySelector('table')) cardify(n.tagName === 'TABLE' ? n.parentNode : n);
        });
      });
    }).observe(document.body, { childList: true, subtree: true });
  }

  /* a run of filter chips in a page header becomes one swipeable row (display: contents on desktop) */
  $$('.page-h').forEach(function (h) {
    var row = null;
    Array.prototype.slice.call(h.children).forEach(function (el) {
      var chip = el.classList.contains('chip') || el.classList.contains('range-custom');
      if (!chip) { row = null; return; }
      if (!row) { row = document.createElement('div'); row.className = 'm-chiprow'; h.insertBefore(row, el); }
      row.appendChild(el);
    });
  });

  document.addEventListener('click', function (e) {
    var d = e.target;
    if (d.tagName !== 'DIALOG' || !d.open) return;
    var r = d.getBoundingClientRect();
    if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) d.close();
  });
})();
