/** Unit tests for the pure helpers: prices, distances, URLs and the "return here" guard. */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { bookingLabel, cssColor, kmStr, listingImg, mkPrice, mkUrl, plainQuery, DEMO_IMG } from '../src/lib/market';
import { afterLogin, cartCount, locLabel, relative } from '../src/lib/visitor';

test('prices without trailing zeros, South Asian grouping', () => {
  assert.equal(mkPrice(52999, { symbol: '৳', decimals: 2 }), '৳52,999');
  assert.equal(mkPrice(1.2, { symbol: '৳', decimals: 2 }), '৳1.20');
  assert.equal(mkPrice(1234567.5, { symbol: '৳', decimals: 2 }), '৳12,34,567.50');
  assert.equal(mkPrice(10), '৳10');
});

test('distances', () => {
  assert.equal(kmStr(0.85), '850 m');
  assert.equal(kmStr(2.0), '2 km');
  assert.equal(kmStr(2.34), '2.3 km');
  assert.equal(kmStr(null), '');
});

test('query-string links keep filters and reset the page', () => {
  assert.equal(mkUrl('/', { q: 'tea', page: '3', kind: 'product' }, { kind: 'service', category: null }), '/?q=tea&kind=service');
  assert.equal(mkUrl('/shops', { radius: '5' }, { page: '2' }), '/shops?radius=5&page=2');
  assert.deepEqual(plainQuery({ a: ['1', '2'], b: 'x', c: undefined }), { a: '1', b: 'x' });
});

test('only same-site paths come back after sign-in', () => {
  assert.equal(relative('//evil.example/x'), '/');
  assert.equal(relative('https://evil.example'), '/');
  assert.equal(relative('/a\\b'), '/');
  assert.equal(relative('/checkout'), '/checkout');
  assert.equal(afterLogin('/login'), '/account/orders');
  assert.equal(afterLogin(null), '/account/orders');
  assert.equal(afterLogin('/checkout'), '/checkout');
});

test('misc helpers', () => {
  assert.equal(cssColor('red; background:url(x)'), '#64748B');
  assert.equal(cssColor('#16A34A'), '#16A34A');
  assert.equal(listingImg('javascript:alert(1)'), DEMO_IMG);
  assert.equal(listingImg('https://cdn.example/p.png'), 'https://cdn.example/p.png');
  assert.equal(bookingLabel('table'), 'Reserve a table');
  assert.equal(bookingLabel('other'), 'Book an appointment');
  assert.equal(cartCount({ '4': { '75:0': 2, '76:0': 0.5 }, '5': { '1:0': 1 } }), 4);
  assert.equal(locLabel(null), 'Choose your location');
  assert.equal(locLabel({ lat: 23.7, lng: 90.4, area: 'Dhanmondi', city: 'Dhaka', source: 'manual' }), 'Dhanmondi, Dhaka');
});
