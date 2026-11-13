#!/usr/bin/env node
/**
 * A tiny stand-in for the API Service (apps/api) so the storefront can be developed and tested
 * without the database: `node tests/mock-api.mjs` (port 8083, or MOCK_API_PORT).
 *
 *   POS_API_URL=http://localhost:8083/  STOREFRONT_GATEWAY_TOKEN=test-token
 *
 * Shops (picked with X-Storefront-Host, i.e. ?site=<slug> on localhost):
 *   techshop  – electronics shop (delivery, variants, offers)      foodie   – restaurant menu with table QR
 *   salonx    – salon with appointments + stylists                 cliniq   – clinic with doctors
 *   closed    – suspended shop (503 page)                          anything else – 404 "store not found"
 * Not a faithful copy of the real API: just enough shape to exercise every storefront route.
 */
import { createServer } from 'node:http';

const TOKEN = process.env.MOCK_API_TOKEN || 'test-token';
const PORT = Number(process.env.MOCK_API_PORT || 8083);
const cur = { symbol: '৳', decimals: 0, position: 'before', code: 'BDT' };

const mk = (id, name, price, extra = {}) => ({ id, name, price, in_stock: true, stock: null, featured: false, category_id: 1, description: `${name} — sample description.`, image: null, ...extra });
const catalogs = {
  techshop: [
    mk(1, 'Galaxy Phone X', 48990, { compare_at: 54990, featured: true, badge: 'New', brand: 'Samsung', stock: 12, warranty: ['1 year'] }),
    mk(2, 'Wireless Earbuds', 3990, { compare_at: 4990, featured: true, stock: 4 }),
    mk(3, 'Smart Watch', 8990, { category_id: 2, featured: true }),
    mk(4, 'USB-C Charger', 990, { category_id: 2, unit: 'pc', stock: 0, in_stock: false }),
    mk(5, 'T-Shirt', 790, { category_id: 3, variants: [
      { id: 51, name: 'M / Red', price: 790, in_stock: true, attributes: { Size: 'M', Colour: 'Red' } },
      { id: 52, name: 'L / Red', price: 790, in_stock: false, attributes: { Size: 'L', Colour: 'Red' } },
      { id: 53, name: 'M / Blue', price: 850, in_stock: true, attributes: { Size: 'M', Colour: 'Blue' } },
    ] }),
    mk(6, 'Prescription Pad Rx', 150, { category_id: 3, prescription_required: true }),
  ],
  foodie: [
    mk(11, 'Kacchi Biryani', 380, { category_id: 1, featured: true, badge: 'Popular' }),
    mk(12, 'Beef Tehari', 260, { category_id: 1 }),
    mk(13, 'Borhani', 60, { category_id: 2, unit: 'glass' }),
    mk(14, 'Firni', 90, { category_id: 2, variants: [{ id: 141, name: 'Small', price: 90, in_stock: true }, { id: 142, name: 'Large', price: 160, in_stock: true }] }),
  ],
  salonx: [
    mk(21, 'Haircut', 400, { category_id: 1, duration_min: 30, featured: true }),
    mk(22, 'Beard Trim', 200, { category_id: 1, duration_min: 20 }),
    mk(23, 'Facial', 1200, { category_id: 2, duration_min: 60 }),
  ],
  cliniq: [],
};

const shops = {
  techshop: {
    business: { name: 'Tech Zone', type: 'electronics', phone: '01711000000', address: 'Gulshan 2, Dhaka', area: 'Gulshan', city: 'Dhaka', currency: cur, lat: 23.79, lng: 90.41, labels: {} },
    site: { slug: 'techshop', kind: 'shop', theme: 'bold', mode: 'storefront', host: 'techshop.localhost', booking_kind: 'repair', bookings_enabled: true, checkout: { cod: true, bkash: true, card: false, pickup: true, delivery: 60, freeAbove: 5000 }, brand: { sub: 'Phones, gadgets and accessories', whatsapp: '8801711000000', hoursText: '10 AM – 9 PM' }, sections: { newsletter: true, reviews: true }, seo: {} },
    categories: [{ id: 1, name: 'Phones', icon: 'phone', products: 2 }, { id: 2, name: 'Accessories', icon: 'watch', products: 2 }, { id: 3, name: 'Fashion', icon: 'shirt', products: 2 }],
  },
  foodie: {
    business: { name: 'Biryani House', type: 'restaurant', phone: '01822000000', address: 'Dhanmondi 27, Dhaka', currency: cur, labels: { catalog: 'Menu' } },
    site: { slug: 'foodie', kind: 'menu', theme: 'bistro', mode: 'storefront', booking_kind: 'table', bookings_enabled: true, checkout: { cod: true, pickup: true, delivery: 50, freeAbove: 0 }, brand: { sub: 'Slow-cooked, family recipes' }, sections: { newsletter: true }, seo: {} },
    categories: [{ id: 1, name: 'Mains', icon: 'utensils', products: 2 }, { id: 2, name: 'Drinks & sweets', icon: 'coffee', products: 2 }],
  },
  salonx: {
    business: { name: 'Style Cut', type: 'salon', phone: '01933000000', address: 'Banani, Dhaka', currency: cur, labels: { staff: 'Stylist' } },
    site: { slug: 'salonx', kind: 'booking', theme: 'luxe', mode: 'storefront', booking_kind: 'appointment', bookings_enabled: true, staff: [{ name: 'Rafi' }, { name: 'Sadia' }], checkout: {}, brand: { sub: 'Barber & salon' }, sections: { newsletter: true, reviews: true }, seo: {} },
    categories: [{ id: 1, name: 'Hair', icon: 'scissors', products: 2 }, { id: 2, name: 'Skin', icon: 'sparkles', products: 1 }],
  },
  cliniq: {
    business: { name: 'City Care Clinic', type: 'clinic', phone: '01644000000', address: 'Uttara, Dhaka', currency: cur, labels: {} },
    site: { slug: 'cliniq', kind: 'booking', theme: 'care', mode: 'storefront', booking_kind: 'appointment', bookings_enabled: true, clinic_enabled: true, checkout: {}, brand: { sub: "Doctor's chamber" }, sections: {}, seo: {} },
    categories: [],
  },
  closed: { business: { name: 'Closed Shop', type: 'grocery', currency: cur }, site: { slug: 'closed', kind: 'shop', mode: 'maintenance' }, categories: [] },
};

const doctors = [
  { id: 1, name: 'Dr. Ayesha Rahman', degrees: 'MBBS, FCPS', specialty: 'Cardiology', designation: 'Consultant', fee: 800, photo: null, schedule: [{ day: 'Monday', from: '16:00', to: '20:00' }, { day: 'Wednesday', from: '16:00', to: '20:00' }] },
  { id: 2, name: 'Dr. Imran Hossain', degrees: 'MBBS, MD', specialty: 'Medicine', designation: 'Assistant professor', fee: 600, photo: null, schedule: [{ day: 'Saturday', from: '10:00', to: '14:00' }] },
];

const orders = new Map();
const send = (res, status, body, headers = {}) => {
  res.writeHead(status, { 'Content-Type': 'application/json', 'X-Request-Id': 'mock-' + Math.random().toString(36).slice(2, 8), ...headers });
  res.end(JSON.stringify(body));
};
const err = (res, status, code, message, details = {}) => send(res, status, { error: { code, message, details, request_id: 'mock' } });
const body = (req) => new Promise((ok) => { let s = ''; req.on('data', (c) => (s += c)); req.on('end', () => { try { ok(JSON.parse(s || '{}')); } catch { ok({}); } }); });

const pad = (n) => String(n).padStart(2, '0');
function slotsFor(date, step = 30) {
  const out = [];
  for (let h = 10; h < 20; h++) for (const m of [0, 30]) {
    if (step === 60 && m) continue;
    const t = `${pad(h)}:${pad(m)}`;
    out.push({ time: new Date(`${date}T${t}:00`).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }), starts_at: `${date}T${t}:00`, available: (h + m) % 5 !== 0, staff: ['Rafi', 'Sadia'] });
  }
  return out.filter((s) => s.available);
}

createServer(async (req, res) => {
  const u = new URL(req.url, 'http://x');
  const p = u.pathname.replace(/\/+$/, '');
  if (req.headers.authorization !== `Bearer ${TOKEN}`) return err(res, 401, 'unauthorized', 'Invalid token.');
  const slug = String(req.headers['x-storefront-host'] || '').split('.')[0].toLowerCase();
  const shop = shops[slug];
  if (!shop) return err(res, 404, 'not_found', 'No shop at this address.');
  if (shop.site.mode !== 'storefront' && p !== '/v1/storefront') return err(res, 403, 'forbidden', 'Closed.');
  const list = catalogs[slug] || [];

  if (p === '/v1/storefront') return send(res, 200, { data: shop }, { 'Cache-Control': 'max-age=10' });

  if (p === '/v1/products') {
    let l = list.filter((x) => (!u.searchParams.get('category') || x.category_id === +u.searchParams.get('category')) && (!u.searchParams.get('q') || x.name.toLowerCase().includes(u.searchParams.get('q').toLowerCase())));
    const sort = u.searchParams.get('sort');
    if (sort === 'price') l.sort((a, b) => a.price - b.price);
    if (sort === '-price') l.sort((a, b) => b.price - a.price);
    if (sort === 'name') l.sort((a, b) => a.name.localeCompare(b.name));
    const per = +u.searchParams.get('per_page') || 24;
    const page = +u.searchParams.get('page') || 1;
    const last = Math.max(1, Math.ceil(l.length / per));
    const slice = l.slice((page - 1) * per, page * per);
    return send(res, 200, { data: slice, meta: { page, per_page: per, total: l.length, last_page: last, from: slice.length ? (page - 1) * per + 1 : 0, to: (page - 1) * per + slice.length } });
  }
  let m;
  if ((m = /^\/v1\/products\/(\d+)$/.exec(p))) {
    const x = list.find((y) => y.id === +m[1]);
    return x ? send(res, 200, { data: x }) : err(res, 404, 'not_found', 'Product not found.');
  }
  if (p === '/v1/locations/countries') return send(res, 200, { data: [{ code: 'BD', name: 'Bangladesh', city_label: 'District', area_label: 'Thana', has_dataset: true }] });
  if (p === '/v1/locations/cities') return send(res, 200, { data: [{ id: 1, name: 'Dhaka', kind: 'district', region: 'Dhaka', areas: 3 }, { id: 2, name: 'Chattogram', kind: 'district', region: 'Chattogram', areas: 2 }] });
  if (p === '/v1/locations/areas') return send(res, 200, { data: +u.searchParams.get('city') === 1 ? [{ id: 11, name: 'Gulshan', kind: 'thana', postal_code: '1212' }, { id: 12, name: 'Banani', kind: 'thana', postal_code: '1213' }, { id: 13, name: 'Mirpur', kind: 'thana', postal_code: '1216' }] : [{ id: 21, name: 'Pahartali', kind: 'thana', postal_code: '4202' }] });
  if ((m = /^\/v1\/tables\/(.+)$/.exec(p))) return /^[a-f0-9]{24}$/.test(m[1]) ? send(res, 200, { data: { name: 'Table 5', area: 'Ground floor', seats: 4 } }) : err(res, 404, 'not_found', 'Unknown table.');

  if (p === '/v1/orders' && req.method === 'POST') {
    const b = await body(req);
    const errors = {};
    if (!b.customer?.name && b.delivery !== 'table') errors['customer.name'] = 'The name is required.';
    if (!Array.isArray(b.items) || !b.items.length) errors.items = 'No items.';
    if (Object.keys(errors).length) return err(res, 422, 'validation_failed', 'The given data was invalid.', errors);
    let subtotal = 0;
    for (const it of b.items) { const x = list.find((y) => y.id === it.product_id); if (!x) return err(res, 422, 'validation_failed', 'Unknown product.', { items: 'Unknown product.' }); if (x.in_stock === false) return err(res, 409, 'out_of_stock', 'Out of stock.'); subtotal += (x.variants?.find((v) => v.id === it.variant_id)?.price ?? x.price) * it.qty; }
    const shipping = b.delivery === 'delivery' && subtotal < 5000 ? 60 : 0;
    const ref = 'MK' + Math.random().toString(36).slice(2, 7).toUpperCase();
    const o = { reference: ref, number: 1000 + orders.size, status: 'pending', subtotal, tax: 0, shipping, total: subtotal + shipping, items: b.items.map((it) => ({ name: list.find((y) => y.id === it.product_id).name, qty: it.qty, line_total: 0 })), created_at: new Date().toISOString(), type: 'order' };
    orders.set(ref, o);
    return send(res, 201, { data: o });
  }
  if ((m = /^\/v1\/orders\/([A-Za-z0-9-]+)$/.exec(p))) return orders.has(m[1]) ? send(res, 200, { data: orders.get(m[1]) }) : err(res, 404, 'not_found', 'Not found.');

  if (p === '/v1/bookings/slots') { const d = u.searchParams.get('date'); return send(res, 200, { data: slotsFor(d), meta: { staff: ['Any', 'Rafi', 'Sadia'], duration_min: 30 } }); }
  if (p === '/v1/bookings' && req.method === 'POST') {
    const b = await body(req);
    if (!b.customer?.name) return err(res, 422, 'validation_failed', 'Invalid.', { 'customer.name': 'The name is required.' });
    const ref = 'BK' + Math.random().toString(36).slice(2, 7).toUpperCase();
    const o = { reference: ref, status: 'pending', starts_at: b.starts_at, ends_at: b.starts_at, type: 'booking' };
    orders.set(ref, o);
    return send(res, 201, { data: o });
  }
  if (p === '/v1/doctors') return slug === 'cliniq' ? send(res, 200, { data: doctors }) : err(res, 403, 'forbidden', 'Not a clinic.');
  if ((m = /^\/v1\/doctors\/(\d+)\/slots$/.exec(p))) return send(res, 200, { data: slotsFor(u.searchParams.get('date'), 30).map((s) => ({ ...s, available: true })), meta: {} });
  if ((m = /^\/v1\/doctors\/(\d+)\/photo$/.exec(p))) return err(res, 404, 'not_found', 'No photo.');
  if ((m = /^\/v1\/doctors\/(\d+)$/.exec(p))) {
    const d = doctors.find((x) => x.id === +m[1]);
    if (!d) return err(res, 404, 'not_found', 'Not found.');
    const open = []; for (let i = 0; i < 7; i++) { const t = new Date(); t.setDate(t.getDate() + i); open.push(`${t.getFullYear()}-${pad(t.getMonth() + 1)}-${pad(t.getDate())}`); }
    return send(res, 200, { data: { ...d, open_dates: open } });
  }
  return err(res, 404, 'not_found', 'Unknown endpoint ' + p);
}).listen(PORT, () => console.log(`mock API on http://localhost:${PORT}  (token: ${TOKEN})`));
