/*
 * Order page map: shows the customer's pin with Leaflet 1.9.4 (BSD-2-Clause) and OpenStreetMap
 * tiles. Leaflet is fetched from unpkg with Subresource Integrity, and only when the map scrolls
 * into view; if it cannot load (offline, blocked) the card keeps its "Open in Google Maps" link.
 */
(function () {
  'use strict';
  var LEAFLET = {
    css: 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
    cssSri: 'sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=',
    js: 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
    jsSri: 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=',
  };
  var loading = null;

  function loadLeaflet() {
    if (window.L && window.L.map) return Promise.resolve(window.L);
    if (loading) return loading;
    loading = new Promise(function (resolve, reject) {
      var link = document.createElement('link');
      link.rel = 'stylesheet'; link.href = LEAFLET.css; link.integrity = LEAFLET.cssSri; link.crossOrigin = '';
      document.head.appendChild(link);
      var s = document.createElement('script');
      s.src = LEAFLET.js; s.integrity = LEAFLET.jsSri; s.crossOrigin = ''; s.async = true;
      s.onload = function () { if (window.L) resolve(window.L); else reject(new Error('Leaflet missing')); };
      s.onerror = function () { reject(new Error('Leaflet could not be loaded')); };
      document.head.appendChild(s);
    });
    return loading;
  }

  function draw(el) {
    if (el.getAttribute('data-ready')) return;
    el.setAttribute('data-ready', '1');
    var lat = parseFloat(el.getAttribute('data-lat')), lng = parseFloat(el.getAttribute('data-lng'));
    if (!isFinite(lat) || !isFinite(lng)) return;
    loadLeaflet().then(function (L) {
      el.innerHTML = '';
      var map = L.map(el, { scrollWheelZoom: false }).setView([lat, lng], 16);
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors',
      }).addTo(map);
      // vector markers: no image assets needed
      L.circle([lat, lng], { radius: 60, weight: 1, color: '#2563eb', fillOpacity: 0.08 }).addTo(map);
      L.circleMarker([lat, lng], { radius: 9, weight: 3, color: '#fff', fillColor: '#2563eb', fillOpacity: 1 })
        .addTo(map).bindTooltip(el.getAttribute('data-label') || 'Customer location');
      // the card may change width (sidebar, rotation): keep the tiles filled
      if ('ResizeObserver' in window) new ResizeObserver(function () { map.invalidateSize(); }).observe(el);
    }).catch(function () {
      el.innerHTML = '<span class="muted ord-map-wait">The map could not be loaded. Use the links below.</span>';
    });
  }

  function init() {
    var maps = document.querySelectorAll('[data-order-map]');
    if (!maps.length) return;
    if (!('IntersectionObserver' in window)) { Array.prototype.forEach.call(maps, draw); return; }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { if (e.isIntersecting) { io.unobserve(e.target); draw(e.target); } });
    }, { rootMargin: '200px' });
    Array.prototype.forEach.call(maps, function (m) { io.observe(m); });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
