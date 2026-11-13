/*!
 * BizPilot360X POS — menu unread badges. Polls GET nav/counts (App\Modules\BackOffice\Support\NavCounts)
 * every 20 s while the page is visible, and at once when it becomes visible again, then updates
 *   [data-nav-count="orders"]           one page's badge (sidebar, phone tabs, More sheet, till)
 *   [data-nav-sum="orders,bookings"]    the sum of several (the phone "More" tab)
 * A new online order, booking or table order also shows a short notice.
 */
(function () {
  'use strict';
  var meta = document.querySelector('meta[name="omni-base"]');
  var base = meta ? meta.content : '/';
  var last = null, timer = 0;
  var NOTICE = { orders: 'New online order', floor: 'New table order', bookings: 'New booking request', kitchen: 'New kitchen ticket' };

  function show(el, n) {
    el.textContent = n > 99 ? '99+' : String(n);
    el.hidden = !(n > 0);
  }
  function apply(c) {
    Array.prototype.forEach.call(document.querySelectorAll('[data-nav-count]'), function (el) {
      var k = el.getAttribute('data-nav-count');
      if (k in c) show(el, +c[k] || 0);
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-nav-sum]'), function (el) {
      show(el, el.getAttribute('data-nav-sum').split(',').reduce(function (a, k) { return a + (+c[k] || 0); }, 0));
    });
    if (last) {
      Object.keys(NOTICE).forEach(function (k) {
        if ((+c[k] || 0) > (+last[k] || 0) && window.OmniUI) window.OmniUI.toast(NOTICE[k]);
      });
    }
    last = c;
  }
  function poll() {
    clearTimeout(timer);
    if (document.hidden) return;
    fetch(base + 'nav/counts', { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (j && j.data) apply(j.data); })
      .catch(function () { /* offline: try again on the next tick */ })
      .then(function () { timer = setTimeout(poll, 20000); });
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
  poll();
})();
