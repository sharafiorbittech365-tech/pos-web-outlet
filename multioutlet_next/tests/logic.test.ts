/** Unit tests for the pure parts: host resolution, the Site helpers and the checkout rules. */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { normaliseHost, resolveHost, slug } from '../src/lib/host';
import { Site } from '../src/lib/site';
import { location, payload, validate } from '../src/lib/services/checkout';

const dev = { devHosts: ['localhost', '127.0.0.1', '::1'], siteKey: false };

test('host: ?site= only on development hosts, remembered in the cookie', () => {
  assert.deepEqual(resolveHost('Digital-Zone', null, 'localhost:8089', dev), { host: 'digital-zone', remember: 'digital-zone' });
  assert.deepEqual(resolveHost(null, 'digital-zone', 'localhost:8089', dev), { host: 'digital-zone', remember: null });
  assert.deepEqual(resolveHost('other', 'x', 'shop.stores.example.com', dev), { host: 'shop.stores.example.com', remember: null });
  assert.deepEqual(resolveHost(null, null, 'localhost', dev), { host: null, remember: null });
});

test('host: a website key fixes the website', () => {
  assert.deepEqual(resolveHost('other', null, 'localhost', { ...dev, siteKey: true }), { host: 'localhost', remember: null });
  assert.deepEqual(resolveHost(null, null, '', { ...dev, siteKey: true }), { host: 'site', remember: null });
  assert.equal(slug('../x'), null);
  assert.equal(normaliseHost('[::1]:8089'), '::1');
  assert.equal(normaliseHost('a..b'), null);
});

test('site: theme colours, outlets and payment methods', () => {
  const s = new Site({ website: { name: 'Group', theme: 'nope', brand: { color: 'red' } }, outlets: [{ id: 3, name: 'A', checkout: { bkash: true } }, { id: 4, name: 'B', checkout: { cod: false, card: true } }] });
  assert.equal(s.theme(), 'fresh');
  assert.equal(s.brandColor(), '#2E7D32');
  assert.equal(s.outletName(4), 'B');
  assert.deepEqual(Site.paymentMethods(s.outlet(3)!), ['cod', 'bkash']);
  assert.deepEqual(Site.paymentMethods(s.outlet(4)!), ['card']);
  assert.equal(Site.mapUrl({ lat: 23.1, lng: 90.2 }), 'https://www.google.com/maps/search/?api=1&query=23.100000%2C90.200000');
  assert.equal(new Site({ outlets: [] }).isOpen(), false);
});

test('checkout: validation, payload never carries prices', () => {
  const cart = { outlet: { id: 3, checkout: { pickup: false } }, lines: [{ product_id: 9, variant_id: null, qty: 2, unit: 50 }] as any[], orderable: true, rx: true };
  const bad = validate({ name: '', phone: 'x', delivery: 'pickup', payment: 'bkash', trx_id: '1' }, cart as any, ['cod', 'bkash']);
  assert.deepEqual(Object.keys(bad).sort(), ['delivery', 'name', 'phone', 'rx_confirm', 'trx_id']);
  const ok = { name: 'A B', phone: '01711 000000', delivery: 'delivery', address: 'House 1, Road 2', area: 'Banani', payment: 'cod', rx_confirm: '1', lat: '23.79', lng: '90.40', loc_source: 'gps' };
  assert.deepEqual(validate(ok, cart as any, ['cod']), {});
  const body = payload(ok, cart as any);
  assert.deepEqual(body.items, [{ product_id: 9, variant_id: null, qty: 2 }]);
  assert.equal(body.customer.address, 'House 1, Road 2, Banani');
  assert.equal(body.trx_id, null);
  assert.match(body.note, /Prescription items/);
  assert.equal(location({ lat: '200', lng: '1' }), null);
});
