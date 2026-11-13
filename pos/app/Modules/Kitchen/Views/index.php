<?php
/**
 * @var array{html: string, counts: array<string, int>, left: float} $board
 * @var string                                                       $filter
 */
helper('ui');
$b = service('posSession')->business();
$words = \OmniPOS\Core\Services\BusinessTypeSetup::terms((string) ($b['business_type'] ?? ''), (array) ($b['settings']['labels'] ?? []));
$kitchen = $words['kitchen'];
// the finished state in this business's words (Served / Delivered / Dispensed / Done …)
$chips = [['active', 'Active', ''], ['new', 'New', 'var(--slate)'], ['cooking', 'In progress', '#F0A93B'], ['delayed', 'Delayed', 'var(--danger)'], ['ready', 'Ready', 'var(--ok)'], ['served', $words['served'], 'var(--faint)']];
?>
<div class="page">
  <div class="page-h"><h2><?= esc($kitchen) ?></h2>
    <a class="icon-btn" href="<?= esc(site_url('kitchen?f=' . $filter), 'attr') ?>" title="Refresh"><?= icon('refresh', 'sm') ?></a>
    <?php foreach ($chips as [$k, $l, $c]): ?>
      <a class="chip <?= $filter === $k ? 'on' : '' ?>" href="<?= esc(site_url('kitchen?f=' . $k), 'attr') ?>"><?php if ($c): ?><span style="width:8px;height:8px;border-radius:50%;background:<?= css_color($c) ?>"></span><?php endif ?><?= esc($l) ?> <span class="n" data-kc="<?= esc($k, 'attr') ?>"><?= (int) ($board['counts'][$k] ?? 0) ?></span></a>
    <?php endforeach ?>
    <div class="spacer"></div>
    <span class="kds-live" title="The board refreshes every 10 seconds"><i></i>Live</span>
  </div>
  <div class="kds-wrap" id="kds-board" data-feed="<?= esc(site_url('kitchen/feed?f=' . $filter), 'attr') ?>"><?= $board['html'] ?></div>
</div>
<script>
(function () {
  var board = document.getElementById('kds-board');
  var csrf = (document.querySelector('meta[name="X-CSRF-TOKEN"]') || {}).content || '';
  var busy = false, queued = false;
  function headers(post) { var h = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }; if (post) { h['Content-Type'] = 'application/json'; h['X-CSRF-TOKEN'] = csrf; } return h; }
  function keep(res) { var t = res.headers.get('X-CSRF-TOKEN'); if (t) csrf = t; return res.json().then(function (j) { if (!res.ok) throw new Error((j && j.error && j.error.message) || 'Request failed'); return j.data; }); }
  function refresh() {
    if (busy) { queued = true; return; }
    busy = true;
    fetch(board.dataset.feed, { headers: headers(false), credentials: 'same-origin' }).then(keep).then(function (d) {
      board.innerHTML = d.html;
      Object.keys(d.counts).forEach(function (k) { var n = document.querySelector('[data-kc="' + k + '"]'); if (n) n.textContent = d.counts[k]; });
    }).catch(function () { /* offline: try again on the next tick */ }).then(function () { busy = false; if (queued) { queued = false; refresh(); } });
  }
  function post(url, body) {
    busy = true;
    return fetch(url, { method: 'POST', headers: headers(true), credentials: 'same-origin', body: JSON.stringify(body) }).then(keep)
      .catch(function (e) { if (window.OmniUI) window.OmniUI.toast(e.message, 'err'); })
      .then(function (r) { busy = false; refresh(); return r; });
  }
  board.addEventListener('change', function (e) {
    var t = e.target; if (!t.dataset.kdsItem) return;
    t.closest('li').classList.toggle('done', t.checked);
    post(<?= json_encode(site_url('kitchen/items/')) ?> + t.dataset.kdsItem, { done: t.checked }).then(function (r) { if (r && r.status === 'ready' && window.OmniUI) window.OmniUI.toast('Ticket ready'); });
  });
  board.addEventListener('submit', function (e) {
    var f = e.target.closest('[data-kds-form]'); if (!f) return;
    e.preventDefault();
    post(f.action, { status: f.querySelector('[name=status]').value });
  });
  setInterval(refresh, 10000);
  setInterval(function () {
    document.querySelectorAll('#kds-board [data-since]').forEach(function (x) { var s = Math.max(0, Math.floor(Date.now() / 1000 - x.dataset.since)), h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60); x.textContent = (h ? h + ':' + ('0' + m).slice(-2) : ('0' + m).slice(-2)) + ':' + ('0' + (s % 60)).slice(-2); });
  }, 1000);
})();
</script>
