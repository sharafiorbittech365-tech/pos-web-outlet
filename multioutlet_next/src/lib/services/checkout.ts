/**
 * Checkout rules on the website side: required inputs for the cart outlet's checkout settings, and the
 * POST /v1/multisite/orders body. Prices are never sent: the API re-prices the items in that outlet and
 * returns the authoritative totals. Port of Modules/Checkout/Services/CheckoutService.php (pure functions).
 */
import type { PricedCart } from './cart';

const PHONE = /^\+?[0-9][0-9\- ]{6,18}$/;
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

type Input = Record<string, string | undefined>;
const s = (in_: Input, k: string): string => String(in_[k] ?? '').trim();
const len = (v: string): number => Array.from(v).length;

export const normalisePhone = (phone: string): string => phone.replace(/[\s()]+/g, ' ').trim();

/** field → message */
export function validate(in_: Input, cart: Pick<PricedCart, 'outlet' | 'lines' | 'orderable' | 'rx'>, methods: string[]): Record<string, string> {
  const ck = cart.outlet?.checkout ?? {};
  const e: Record<string, string> = {};
  if (s(in_, 'name') === '' || len(s(in_, 'name')) > 100) e.name = 'Please enter your full name.';
  if (!PHONE.test(normalisePhone(s(in_, 'phone')))) e.phone = 'Please enter a valid mobile number, e.g. 01711000000.';
  if (s(in_, 'email') !== '' && (!EMAIL.test(s(in_, 'email')) || len(s(in_, 'email')) > 150)) e.email = 'Please enter a valid email address.';
  const delivery = s(in_, 'delivery');
  if (!['delivery', 'pickup'].includes(delivery) || (delivery === 'pickup' && !ck.pickup)) e.delivery = 'Please choose delivery or pickup.';
  if (delivery === 'delivery' && (len(s(in_, 'address')) < 5 || len(s(in_, 'address')) > 220)) e.address = 'Please enter the delivery address.';
  const payment = s(in_, 'payment');
  if (!methods.includes(payment)) e.payment = 'Please choose a payment method.';
  else if (['bkash', 'card'].includes(payment) && !/^[A-Za-z0-9-]{6,32}$/.test(s(in_, 'trx_id'))) e.trx_id = 'Please enter the transaction ID (6–32 letters or digits).';
  if (len(s(in_, 'note')) > 500) e.note = 'The note is too long.';
  if (cart.rx && s(in_, 'rx_confirm') === '') e.rx_confirm = 'Please confirm you have a valid prescription for the Rx items.';
  if (cart.lines.length === 0) e.items = 'Your cart is empty.';
  else if (!cart.orderable) e.items = 'Some items in your cart are not available any more. Please review your cart.';
  return e;
}

/** Optional delivery location from the form (filled by the location helper in the browser). */
export function location(in_: Input): { lat: number; lng: number; area: string; city: string; source: string } | null {
  const lat = Number(in_.lat);
  const lng = Number(in_.lng);
  if (String(in_.lat ?? '').trim() === '' || String(in_.lng ?? '').trim() === '' || !Number.isFinite(lat) || !Number.isFinite(lng)) return null;
  if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return null;
  const source = ['gps', 'area', 'manual'].includes(String(in_.loc_source ?? '')) ? String(in_.loc_source) : 'manual';
  const cut = (v: unknown, n: number): string => Array.from(String(v ?? '').trim()).slice(0, n).join('');
  return { lat: Math.round(lat * 1e6) / 1e6, lng: Math.round(lng * 1e6) / 1e6, area: cut(in_.loc_area, 64), city: cut(in_.loc_city, 80), source };
}

export function payload(in_: Input, cart: Pick<PricedCart, 'outlet' | 'lines' | 'rx'>): Record<string, any> {
  const delivery = s(in_, 'delivery') === 'pickup' ? 'pickup' : 'delivery';
  const payment = s(in_, 'payment');
  const notes = [s(in_, 'note')].filter((x) => x !== '');
  if (cart.rx) {
    const rx = Array.from(s(in_, 'rx_note')).slice(0, 200).join('');
    notes.push(`Prescription items: customer will show the prescription${rx !== '' ? ` (${rx})` : ''}.`);
  }
  return {
    outlet_id: Math.trunc(Number(cart.outlet?.id ?? 0)),
    items: cart.lines.map((l) => ({ product_id: l.product_id, variant_id: l.variant_id, qty: l.qty })),
    customer: {
      name: s(in_, 'name'),
      phone: normalisePhone(s(in_, 'phone')),
      email: s(in_, 'email'),
      address: delivery === 'delivery' ? Array.from([s(in_, 'address'), s(in_, 'area')].filter((x) => x !== '').join(', ')).slice(0, 255).join('') : '',
    },
    delivery,
    payment,
    trx_id: ['bkash', 'card'].includes(payment) ? s(in_, 'trx_id') : null,
    note: notes.join(' '),
    location: delivery === 'delivery' ? location(in_) : null,
  };
}
