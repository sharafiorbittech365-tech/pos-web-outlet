<?php
/**
 * Order page: the customer's delivery location — country / city / area picked at checkout and the
 * map pin shared with the customer's permission. The map (Leaflet + OpenStreetMap tiles) is loaded
 * only when the card scrolls into view (assets/pos/order-map.js); the links work without it.
 *
 * @var array<string, mixed> $place OrderLocation::for()
 */
helper('ui');
$v = static fn (string $f): string => base_url('assets/pos/' . $f) . '?v=' . (is_file(FCPATH . 'assets/pos/' . $f) ? filemtime(FCPATH . 'assets/pos/' . $f) : 0);
$kind = static fn (?string $k, string $fallback): string => $k ? ucfirst($k) : $fallback;
$pinned = $place['lat'] !== null;
$where = implode(', ', array_filter([$place['area'] ?? $place['area_text'] ?? null, $place['city'] ?? null]));
?>
<link rel="stylesheet" href="<?= esc($v('order-map.css'), 'attr') ?>">
<section class="card ord-loc" data-order-location>
  <div class="card-h"><h3><?= icon('map-pin', 'sm') ?> Customer location</h3><?php if ($pinned): ?><span class="tag ok"><?= icon('locate-fixed', 'sm') ?>Pinned</span><?php endif ?></div>
  <dl class="kv">
    <?php if ($place['country']): ?><dt>Country</dt><dd><?= esc($place['country']) ?></dd><?php endif ?>
    <?php if ($place['city']): ?><dt><?= esc($kind($place['city_kind'], 'City')) ?></dt><dd><?= esc($place['city']) ?></dd><?php endif ?>
    <?php if ($place['area']): ?><dt><?= esc($kind($place['area_kind'], 'Area')) ?></dt><dd><?= esc($place['area']) ?><?= $place['postal_code'] ? ' · ' . esc($place['postal_code']) : '' ?></dd>
    <?php elseif ($place['area_text']): ?><dt>Area</dt><dd><?= esc($place['area_text']) ?></dd><?php endif ?>
    <?php if ($pinned): ?><dt>Pin</dt><dd class="mono"><?= esc(sprintf('%.6F, %.6F', $place['lat'], $place['lng'])) ?></dd><?php endif ?>
  </dl>
  <?php if ($pinned): ?>
    <div class="ord-map" data-order-map data-lat="<?= esc(sprintf('%.6F', $place['lat']), 'attr') ?>" data-lng="<?= esc(sprintf('%.6F', $place['lng']), 'attr') ?>"
         data-label="<?= esc($where !== '' ? $where : 'Customer location', 'attr') ?>" role="region" aria-label="Map of the customer location">
      <span class="muted ord-map-wait">Loading map…</span>
    </div>
    <div class="ord-map-links">
      <a class="btn sm" href="<?= esc($place['google'], 'attr') ?>" target="_blank" rel="noopener noreferrer"><?= icon('navigation', 'sm') ?>Open in Google Maps</a>
      <a class="btn sm" href="<?= esc($place['osm'], 'attr') ?>" target="_blank" rel="noopener noreferrer"><?= icon('external-link', 'sm') ?>OpenStreetMap</a>
    </div>
    <script src="<?= esc($v('order-map.js'), 'attr') ?>" defer></script>
  <?php endif ?>
</section>
