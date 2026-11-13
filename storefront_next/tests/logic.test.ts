/**
 * Unit tests for the framework-free logic ported from PHP (run: npm test).
 * They mirror the PHPUnit cases of apps/storefront/tests/unit where the behaviour is shared.
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { normaliseHost, resolveHost, slug } from '../src/lib/host';
import { accentSplit, dur, initials, money, pOff, phpDate, sfMoney, sfUrl, strim, validDate } from '../src/lib/format';
import { MAX_QTY, priceCart, shipping, type CartLine } from '../src/lib/services/calculator';
import { normalisePhone, validEmail, validPhone } from '../src/lib/services/validate';
import { normaliseRef, step, statusText } from '../src/lib/services/track';
import { fromApi } from '../src/lib/form-errors';
import { Store } from '../src/lib/store';
import { CatalogService, featuredFirst, parseSort, pickFeatured } from '../src/lib/services/catalog';
import { BookingService } from '../src/lib/services/booking';
import { CheckoutService } from '../src/lib/services/checkout';

const dev = { devHosts: ['localhost', '127.0.0.1', '::1'], selfHosted: false, siteKey: false };

describe('host resolution', () => {
  it('normalises host headers', () => {
    assert.equal(normaliseHost('Shop.Example.com:8080'), 'shop.example.com');
    assert.equal(normaliseHost('[::1]:8084'), '::1');
    assert.equal(normaliseHost('bad host'), null);
    assert.equal(normaliseHost('a..b'), null);
    assert.equal(normaliseHost(''), null);
  });
  it('validates slugs', () => {
    assert.equal(slug('Digital-Zone'), 'digital-zone');
    assert.equal(slug('-bad'), null);
    assert.equal(slug('a'.repeat(64)), null);
    assert.equal(slug(null), null);
  });
  it('?site= works only on development hosts', () => {
    assert.deepEqual(resolveHost('freshmart', null, 'localhost:8084', dev), { host: 'freshmart', remember: 'freshmart' });
    assert.deepEqual(resolveHost(null, 'cafe', 'localhost:8084', dev), { host: 'cafe', remember: null });
    assert.deepEqual(resolveHost('evil', 'evil', 'shop.example.com', dev), { host: 'shop.example.com', remember: null });
    assert.deepEqual(resolveHost(null, null, 'localhost', dev), { host: null, remember: null });
  });
  it('self-hosted ignores ?site= and the cookie', () => {
    assert.deepEqual(resolveHost('x', 'y', 'shop.mine.com', { ...dev, selfHosted: true }), { host: 'shop.mine.com', remember: null });
  });
  it('site-key mode always has a shop', () => {
    assert.deepEqual(resolveHost('x', null, '', { ...dev, siteKey: true }), { host: 'site', remember: null });
  });
});

describe('formatting', () => {
  it('groups digits the South Asian way', () => {
    assert.equal(money(1234567, { symbol: '৳', decimals: 0 }), '৳12,34,567');
    assert.equal(money(-1500.5, { symbol: '৳', decimals: 2 }), '-৳1,500.50');
    assert.equal(money(999, { symbol: '৳', decimals: 0 }), '৳999');
    assert.equal(money(5, { symbol: '$', decimals: 2, position: 'after' }), '5.00 $');
  });
  it('sfMoney drops .00 for whole amounts', () => {
    assert.equal(sfMoney(760, { symbol: '৳', decimals: 2, position: 'before', code: 'BDT' }), '৳760');
    assert.equal(sfMoney(760.5, { symbol: '৳', decimals: 2, position: 'before', code: 'BDT' }), '৳760.50');
  });
  it('formats dates like PHP date()', () => {
    assert.equal(phpDate('Y-m-d', '2026-03-09T14:05:00'), '2026-03-09');
    assert.equal(phpDate('D j M, h:i A', '2026-03-09T14:05:00'), 'Mon 9 Mar, 02:05 PM');
    assert.equal(phpDate('l, j F', '2026-03-09 08:00:00'), 'Monday, 9 March');
    assert.equal(phpDate('g:i A', '2026-03-09T00:30:00'), '12:30 AM');
    assert.equal(phpDate('\\Y Y', '2026-01-01'), 'Y 2026');
  });
  it('small helpers', () => {
    assert.equal(initials('Ayesha Rahman Khan'), 'AR');
    assert.equal(initials(''), '?');
    assert.equal(dur(90), '1 h 30 min');
    assert.equal(dur(45), '45 min');
    assert.equal(pOff({ price: 75, compare_at: 100 }), 25);
    assert.equal(pOff({ price: 100 }), 0);
    assert.deepEqual(accentSplit('About Fresh Mart'), ['About Fresh', 'Mart']);
    assert.equal(strim('abcdefghij', 6), 'abcde…');
    assert.equal(sfUrl('/c/3', { page: 2, q: '', sort: null }), '/c/3?page=2');
    assert.ok(validDate('2026-02-28'));
    assert.ok(!validDate('2026-02-30'));
  });
});

describe('cart calculator', () => {
  const products: Record<number, any> = {
    1: { id: 1, name: 'Phone', price: 1000, in_stock: true, stock: 5 },
    2: { id: 2, name: 'Shirt', price: 500, in_stock: true, variants: [{ id: 20, name: 'M', price: 550, in_stock: true }, { id: 21, name: 'L', price: 550, in_stock: false }] },
    3: { id: 3, name: 'Gone', price: 10, in_stock: false },
    4: { id: 4, name: 'Pills', price: 20, in_stock: true, prescription_required: true },
  };
  const ck = { delivery: 60, freeAbove: 2000 };
  const L = (product_id: number, qty: number, variant_id: number | null = null): CartLine => ({ product_id, variant_id, qty });

  it('prices lines, shipping and the free-delivery progress', () => {
    const c = priceCart([L(1, 1), L(2, 2, 20)], products, ck);
    assert.equal(c.subtotal, 2100);
    assert.equal(c.shipping, 0);
    assert.equal(c.total, 2100);
    assert.equal(c.count, 3);
    assert.ok(c.orderable);
    const small = priceCart([L(1, 1)], products, ck);
    assert.equal(small.shipping, 60);
    assert.equal(small.free_left, 1000);
    assert.equal(small.free_progress, 50);
  });
  it('flags stock problems and unknown products', () => {
    assert.ok(!priceCart([L(1, 9)], products, ck).orderable);
    assert.ok(!priceCart([L(3, 1)], products, ck).orderable);
    assert.ok(!priceCart([L(2, 1, 21)], products, ck).orderable);
    assert.ok(!priceCart([L(2, 1, 99)], products, ck).orderable);
    const missing = priceCart([L(404, 1), L(1, 1)], products, ck);
    assert.equal(missing.lines.length, 1);
    assert.equal(missing.issues.length, 1);
    assert.equal(missing.lines[0].index, 1); // index refers to the original cart line
  });
  it('caps quantity and detects prescription items', () => {
    assert.equal(priceCart([L(2, 500)], products, ck).lines[0].qty, MAX_QTY);
    assert.ok(priceCart([L(4, 1)], products, ck).rx);
    assert.ok(!priceCart([], products, ck).orderable);
  });
  it('pickup and tiny carts ship free', () => {
    assert.equal(shipping(100, ck, 'pickup'), 0);
    assert.equal(shipping(0, ck, 'delivery'), 0);
    assert.equal(shipping(1999, ck, 'delivery'), 60);
    assert.equal(shipping(2000, ck, 'delivery'), 0);
  });
});

describe('validation', () => {
  it('accepts Bangladeshi and international numbers', () => {
    assert.ok(validPhone('01711000000'));
    assert.ok(validPhone('+8801711000000'));
    assert.ok(validPhone('0171 100-0000'));
    assert.ok(validPhone('+14155552671'));
    assert.ok(!validPhone('12345'));
    assert.ok(!validPhone('01211000000'));
    assert.equal(normalisePhone(' 0171-100 (0000) '), '01711000000');
  });
  it('emails', () => {
    assert.ok(validEmail('a@b.co'));
    assert.ok(!validEmail('a@b'));
    assert.ok(!validEmail('a b@c.d'));
  });
});

describe('tracking', () => {
  it('normalises references', () => {
    assert.equal(normaliseRef(' w8k-2qx '), 'W8K-2QX');
    assert.equal(normaliseRef('ab'), null);
    assert.equal(normaliseRef('x'.repeat(33)), null);
  });
  it('maps statuses to steps', () => {
    assert.equal(step({ status: 'pending' }), 0);
    assert.equal(step({ status: 'confirmed' }), 1);
    assert.equal(step({ ship_status: 'out_for_delivery' }), 1);
    assert.equal(step({ ship_status: 'delivered' }), 2);
    assert.equal(step({ status: 'done' }), 2);
    assert.equal(step({ status: 'void' }), -1);
    assert.equal(step({ ship_status: 'returned' }), -1);
    assert.match(statusText({ status: 'void' }, 'booking'), /booking was cancelled/);
    assert.match(statusText({ ship_status: 'in_transit' }), /in transit/);
  });
});

describe('API validation errors', () => {
  it('maps detail keys to form fields', () => {
    assert.deepEqual(fromApi({ 'customer.name': 'Bad name', customer_phone: 'Bad phone', 'items.0.qty': 'Bad qty', starts_at: 'Taken', party_size: 'Too many', 'location.lat': 'x' }), {
      name: 'Bad name', phone: 'Bad phone', items: 'Bad qty', time: 'Taken', party: 'Too many', location: 'x',
    });
    assert.deepEqual(fromApi({ note: ['a', 'b'] }), { note: 'a b' });
  });
});

const profile = (site: Record<string, any> = {}, business: Record<string, any> = {}) => ({
  business: { name: 'Shop', type: 'electronics', ...business },
  site: { mode: 'storefront', checkout: { cod: true, bkash: true, pickup: true, delivery: 60, freeAbove: 2000 }, ...site },
  categories: [{ id: 1, name: 'Phones', products: 3 }, { id: 2, name: 'Empty', products: 0 }],
});

describe('Store', () => {
  it('derives the template from the business type', () => {
    const s = new Store(profile({ bookings_enabled: true }), 'x');
    assert.equal(s.kind(), 'shop');
    assert.equal(s.bookingKind(), 'repair');
    assert.ok(s.canBook());
    assert.equal(s.cta(), 'Book a repair');
    assert.ok(s.sells());
    assert.equal(new Store(profile({}, { type: 'salon' })).kind(), 'booking');
    assert.ok(!new Store(profile({}, { type: 'salon' })).sells());
    assert.ok(!new Store(profile({}, { type: 'agency' })).sells());
    assert.equal(new Store(profile({ kind: 'menu' })).kind(), 'menu');
    assert.equal(new Store(profile({}, { type: 'bakery' })).cta(), 'Order a custom cake');
    assert.equal(new Store(profile({}, { type: 'agency' })).cta(), 'Request a proposal');
  });
  it('colours: only safe literals pass', () => {
    assert.equal(new Store(profile({ brand: { color: '#abc' } })).brandColor(), '#abc');
    assert.equal(new Store(profile({ brand: { color: 'red;background:url(x)' }, theme: 'luxe' })).brandColor(), '#C9A45C');
    assert.equal(new Store(profile({ brand: { accent: 'javascript:1' } })).accentColor(), null);
  });
  it('payment methods, live categories, closed shops, staff', () => {
    const s = new Store(profile({ staff: [{ name: 'Rafi' }, 'Any', { name: 'Rafi' }, { name: 'Sadia' }] }));
    assert.deepEqual(s.paymentMethods(), ['cod', 'bkash']);
    assert.deepEqual(s.liveCategories().map((c) => c.id), [1]);
    assert.deepEqual(s.staff(), ['Rafi', 'Sadia']);
    assert.ok(!new Store(profile({ mode: 'maintenance' })).isOpen());
    assert.equal(s.money(1234), '৳1,234');
    assert.equal(new Store(profile({}, { currency: { symbol: '$', decimals: 2, position: 'before', code: 'USD' } })).money(1234.5), '$1,234.50');
  });
});

describe('catalog helpers', () => {
  it('sort keys and featured ordering', () => {
    assert.equal(parseSort('low'), 'low');
    assert.equal(parseSort('nope'), 'featured');
    const l = featuredFirst([{ id: 1 }, { id: 2, featured: true, in_stock: false }, { id: 3, featured: true, in_stock: true }, { id: 4, in_stock: true }]);
    assert.deepEqual(l.map((p) => p.id), [3, 2, 4, 1]);
    assert.equal(pickFeatured([{ id: 1, in_stock: true, price: 5 }, { id: 2, in_stock: true, price: 9, badge: 'x' }]).length, 2);
    assert.equal(typeof CatalogService, 'function');
  });
});

describe('booking + checkout payloads', () => {
  const store = new Store(profile({ bookings_enabled: true }, { type: 'salon' }));
  const svc = new BookingService({} as never, store);
  const future = new Date(Date.now() + 86400000).toISOString().slice(0, 19);

  it('validates an appointment', () => {
    const e = svc.validate({ name: '', phone: 'x', starts_at: '' });
    assert.ok(e.name && e.phone && e.time);
    assert.deepEqual(svc.validate({ name: 'Ann', phone: '01711000000', starts_at: future }), {});
    assert.ok(svc.validate({ name: 'Ann', phone: '01711000000', starts_at: '2020-01-01T10:00:00' }).time);
  });
  it('builds the booking payload without empty fields', () => {
    const p = svc.payload({ name: ' Ann ', phone: '0171-100-0000', email: '', service: '7', staff: 'any', starts_at: future, note: '' });
    assert.deepEqual(p, { kind: 'appointment', customer: { name: 'Ann', phone: '01711000000' }, product_id: 7, starts_at: future });
  });
  it('checkout validates required fields and the payment method', () => {
    const shop = new Store(profile());
    const c = new CheckoutService({} as never, shop);
    const cart = { lines: [{}], orderable: true, rx: false } as never;
    const bad = c.validate({ delivery: 'delivery', payment: 'bkash' }, cart);
    assert.ok(bad.name && bad.phone && bad.address && bad.trx_id);
    const ok = c.validate({ name: 'Ann', phone: '01711000000', delivery: 'delivery', address: 'Road 1', payment: 'cod' }, cart);
    assert.deepEqual(ok, {});
    assert.ok(c.validate({ name: 'Ann', phone: '01711000000', delivery: 'pickup', payment: 'cod' }, { lines: [], orderable: false, rx: false } as never).items);
    assert.ok(c.validate({ name: 'A', phone: '01711000000', delivery: 'delivery', address: 'x', payment: 'card' }, cart).payment);
  });
  it('checkout payload never contains prices and keeps the location', () => {
    const c = new CheckoutService({} as never, new Store(profile()));
    const cart = { lines: [{ product_id: 1, variant_id: null, qty: 2, unit: 999, name: 'P' }], orderable: true, rx: false } as never;
    const p = c.payload({ name: 'Ann', phone: '0171 1000000', delivery: 'delivery', address: 'Road 1', area: 'Gulshan', payment: 'cod', lat: '23.79', lng: '90.41', loc_source: 'gps' }, cart);
    assert.deepEqual(p.items, [{ product_id: 1, variant_id: null, qty: 2 }]);
    assert.equal(p.customer.address, 'Road 1, Gulshan');
    assert.equal(p.customer.phone, '01711000000');
    assert.equal(p.location.source, 'gps');
    assert.equal(p.channel, 'storefront');
    assert.ok(!JSON.stringify(p).includes('999'));
    assert.equal(CheckoutService.location({ lat: '200', lng: '1' }), null);
    assert.equal(c.payload({ name: 'A', delivery: 'pickup', payment: 'cod' }, cart).location, null);
  });
});
