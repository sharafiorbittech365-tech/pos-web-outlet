/*
 * BizPilot360X Geo — location helpers shared by the platform, the storefront and the marketplace.
 *
 *   OmniGeo.detect()               device location (asks the user for permission) → { lat, lng, accuracy, area, city, source: 'gps' }
 *   OmniGeo.nearestArea(lat, lng)  the closest known area (works offline — no map service is called)
 *   OmniGeo.fromText('… Dhanmondi …') area found in an address
 *   OmniGeo.distanceKm(a, b)       straight-line distance in km
 *   OmniGeo.remember(loc) / last() the visitor's last chosen location (this browser only)
 *
 * Production: the same API, with reverse geocoding by the platform's own geo service.
 */
(function () {
  'use strict';
  // [area, city, lat, lng] — Dhaka areas first, then major towns
  const AREAS = [
    ['Gulshan', 'Dhaka', 23.7925, 90.4078], ['Banani', 'Dhaka', 23.7937, 90.4066], ['Baridhara', 'Dhaka', 23.8003, 90.4209], ['Bashundhara R/A', 'Dhaka', 23.8193, 90.4526],
    ['Uttara', 'Dhaka', 23.8759, 90.3795], ['Mirpur', 'Dhaka', 23.8223, 90.3654], ['Pallabi', 'Dhaka', 23.8256, 90.3654], ['Mohammadpur', 'Dhaka', 23.7662, 90.3589],
    ['Dhanmondi', 'Dhaka', 23.7461, 90.3742], ['Panthapath', 'Dhaka', 23.7516, 90.3862], ['Farmgate', 'Dhaka', 23.7561, 90.3872], ['Karwan Bazar', 'Dhaka', 23.7515, 90.3936],
    ['Tejgaon', 'Dhaka', 23.7639, 90.3925], ['Mohakhali', 'Dhaka', 23.7779, 90.4048], ['Badda', 'Dhaka', 23.7806, 90.4265], ['Rampura', 'Dhaka', 23.7612, 90.4214],
    ['Malibagh', 'Dhaka', 23.7480, 90.4120], ['Moghbazar', 'Dhaka', 23.7489, 90.4045], ['Shantinagar', 'Dhaka', 23.7398, 90.4140], ['Motijheel', 'Dhaka', 23.7330, 90.4172],
    ['Paltan', 'Dhaka', 23.7363, 90.4125], ['Khilgaon', 'Dhaka', 23.7516, 90.4265], ['Jatrabari', 'Dhaka', 23.7104, 90.4349], ['Old Dhaka', 'Dhaka', 23.7104, 90.4074],
    ['Chawkbazar', 'Dhaka', 23.7179, 90.3960], ['Lalbagh', 'Dhaka', 23.7193, 90.3880], ['Azimpur', 'Dhaka', 23.7276, 90.3858], ['New Market', 'Dhaka', 23.7330, 90.3846],
    ['Shahbagh', 'Dhaka', 23.7383, 90.3958], ['Kakrail', 'Dhaka', 23.7381, 90.4074], ['Wari', 'Dhaka', 23.7167, 90.4207], ['Moulvibazar', 'Dhaka', 23.7158, 90.4004], ['Baitul Mukarram', 'Dhaka', 23.7296, 90.4130], ['Banglabazar', 'Dhaka', 23.7076, 90.4115], ['Sutrapur', 'Dhaka', 23.7090, 90.4170], ['Gulistan', 'Dhaka', 23.7237, 90.4114],
    ['Khilkhet', 'Dhaka', 23.8290, 90.4235], ['Banasree', 'Dhaka', 23.7637, 90.4333], ['Aftabnagar', 'Dhaka', 23.7698, 90.4446], ['Shyamoli', 'Dhaka', 23.7745, 90.3657],
    ['Agargaon', 'Dhaka', 23.7778, 90.3794], ['Kallyanpur', 'Dhaka', 23.7814, 90.3606], ['Savar', 'Dhaka', 23.8583, 90.2667], ['Keraniganj', 'Dhaka', 23.6983, 90.3456],
    ['Narayanganj', 'Narayanganj', 23.6238, 90.5000], ['Gazipur', 'Gazipur', 23.9999, 90.4203], ['Tongi', 'Gazipur', 23.8915, 90.4023],
    ['Agrabad', 'Chattogram', 22.3263, 91.8123], ['GEC Circle', 'Chattogram', 22.3590, 91.8215], ['Nasirabad', 'Chattogram', 22.3666, 91.8239], ['Halishahar', 'Chattogram', 22.3285, 91.7810],
    ['Chawkbazar (Ctg)', 'Chattogram', 22.3533, 91.8370], ['Khulshi', 'Chattogram', 22.3606, 91.8090], ['Panchlaish', 'Chattogram', 22.3685, 91.8344],
    ['Sylhet Sadar', 'Sylhet', 24.8949, 91.8687], ['Zindabazar', 'Sylhet', 24.8966, 91.8713], ['Rajshahi Sadar', 'Rajshahi', 24.3745, 88.6042], ['Khulna Sadar', 'Khulna', 22.8456, 89.5403],
    ['Barishal Sadar', 'Barishal', 22.7010, 90.3535], ['Rangpur Sadar', 'Rangpur', 25.7439, 89.2752], ['Mymensingh Sadar', 'Mymensingh', 24.7471, 90.4203], ['Cumilla Sadar', 'Cumilla', 23.4607, 91.1809],
    ['Cox\'s Bazar', 'Cox\'s Bazar', 21.4272, 92.0058], ['Bogura Sadar', 'Bogura', 24.8465, 89.3773], ['Jashore Sadar', 'Jashore', 23.1664, 89.2081],
  ];
  const rad = (d) => (d * Math.PI) / 180;
  function distanceKm(a, b) {
    if (!a || !b || a.lat == null || b.lat == null) return null;
    const R = 6371, dLat = rad(b.lat - a.lat), dLng = rad(b.lng - a.lng);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) ** 2;
    return Math.round(2 * R * Math.asin(Math.sqrt(h)) * 10) / 10;
  }
  const toLoc = (x, source) => (x ? { area: x[0], city: x[1], lat: x[2], lng: x[3], source } : null);
  function nearestArea(lat, lng) {
    let best = null, bd = Infinity;
    AREAS.forEach((x) => { const d = distanceKm({ lat, lng }, { lat: x[2], lng: x[3] }); if (d < bd) { bd = d; best = x; } });
    return best ? Object.assign(toLoc(best, 'nearest'), { km: bd }) : null;
  }
  // city centres, used when an address names only the city
  const CITY = { Dhaka: [23.8103, 90.4125], Chattogram: [22.3569, 91.7832], Sylhet: [24.8949, 91.8687], Rajshahi: [24.3745, 88.6042], Khulna: [22.8456, 89.5403], Barishal: [22.7010, 90.3535], Rangpur: [25.7439, 89.2752], Mymensingh: [24.7471, 90.4203], Cumilla: [23.4607, 91.1809], Gazipur: [23.9999, 90.4203], Narayanganj: [23.6238, 90.5000] };
  function fromText(text) {
    const t = String(text || '').toLowerCase();
    if (!t) return null;
    const city = Object.keys(CITY).find((c) => t.includes(c.toLowerCase()) || (c === 'Chattogram' && /chittagong|\bctg\b/.test(t)));
    const hits = AREAS.filter((x) => t.includes(x[0].toLowerCase().replace(/\s*\(.*\)/, '')));
    // prefer an area in the city the address names, then the longest (most specific) name
    hits.sort((a, b) => (city ? (b[1] === city) - (a[1] === city) : 0) || b[0].length - a[0].length);
    if (hits.length) return toLoc(hits[0], 'address');
    return city ? { area: '', city, lat: CITY[city][0], lng: CITY[city][1], source: 'address' } : null;
  }
  const byName = (name) => toLoc(AREAS.find((x) => x[0].toLowerCase() === String(name || '').toLowerCase()), 'manual');
  const MSG = {
    1: 'Location permission was denied. Choose your area instead, or allow location for this site in your browser settings.',
    2: 'Your location could not be found right now. Choose your area instead.',
    3: 'Finding your location took too long. Choose your area instead.',
    insecure: 'Location needs a secure (https) page. Choose your area instead.',
    unsupported: 'This device or browser cannot share its location. Choose your area instead.',
  };
  function detect(opts = {}) {
    return new Promise((resolve, reject) => {
      if (!('geolocation' in navigator)) return reject(Object.assign(new Error(MSG.unsupported), { code: 'unsupported' }));
      if (window.isSecureContext === false && !/^(file:|http:\/\/localhost|http:\/\/127\.)/.test(location.href)) return reject(Object.assign(new Error(MSG.insecure), { code: 'insecure' }));
      navigator.geolocation.getCurrentPosition(
        (p) => { const lat = +p.coords.latitude.toFixed(5), lng = +p.coords.longitude.toFixed(5), n = nearestArea(lat, lng);
          resolve({ lat, lng, accuracy: Math.round(p.coords.accuracy || 0), area: n && n.km < 25 ? n.area : '', city: n && n.km < 60 ? n.city : '', source: 'gps', at: Date.now() }); },
        (e) => reject(Object.assign(new Error(MSG[e.code] || MSG[2]), { code: e.code === 1 ? 'denied' : e.code === 3 ? 'timeout' : 'unavailable' })),
        { enableHighAccuracy: !!opts.precise, timeout: opts.timeout || 12000, maximumAge: opts.maxAge == null ? 300000 : opts.maxAge },
      );
    });
  }
  async function permission() {
    try { if (navigator.permissions) return (await navigator.permissions.query({ name: 'geolocation' })).state; } catch (e) { /* not supported */ }
    return 'prompt';
  }
  const KEY = 'omnigeo.last';
  const remember = (loc) => { try { localStorage.setItem(KEY, JSON.stringify(Object.assign({}, loc, { at: Date.now() }))); } catch (e) { /* private mode */ } return loc; };
  const last = () => { try { return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return null; } };
  const label = (loc) => (!loc ? 'Choose location' : [loc.area, loc.city].filter(Boolean).join(', ') || `${(+loc.lat).toFixed(3)}, ${(+loc.lng).toFixed(3)}`);
  const cities = () => [...new Set(AREAS.map((x) => x[1]))];
  const areasIn = (city) => AREAS.filter((x) => !city || x[1] === city).map((x) => toLoc(x, 'manual'));
  const mapUrl = (loc) => (loc && loc.lat != null ? `https://www.openstreetmap.org/?mlat=${loc.lat}&mlon=${loc.lng}#map=16/${loc.lat}/${loc.lng}` : '');
  window.OmniGeo = { AREAS, detect, permission, nearestArea, fromText, byName, distanceKm, remember, last, label, cities, areasIn, mapUrl, version: '1.0.0' };
})();
