/**
 * Checkout: validates the form, builds the POST /v1/orders body and loads the address pickers.
 * Port of Modules/Checkout/Services/CheckoutService.php.
 */
import { isApiError } from '../api/errors';
import { type Json, type StoreApi } from '../api/storefront-api';
import { type Store } from '../store';
import { type PricedCart } from './calculator';
import { normalisePhone, validEmail, validPhone } from './validate';

export interface Places {
  countries: Json[];
  country: string;
  cities: Json[];
  areas: Json[];
}

export const NO_PLACES: Places = { countries: [], country: '', cities: [], areas: [] };

type Table = { code: string; name: string; area?: string | null } | null;
const len = (s: string): number => Array.from(s).length;

export class CheckoutService {
  constructor(private readonly api: StoreApi, private readonly store: Store) {}

  /** Field → message, empty when the order can be placed. */
  validate(input: Record<string, unknown>, cart: PricedCart, table: Table = null): Record<string, string> {
    const ck = this.store.checkout();
    const e: Record<string, string> = {};
    const s = (k: string): string => String(input[k] ?? '').trim();

    if ((table === null && s('name') === '') || len(s('name')) > 100) e.name = 'Please enter your full name.';
    if ((table === null || s('phone') !== '') && !validPhone(s('phone'))) e.phone = 'Please enter a valid mobile number, e.g. 01711000000.';
    if (s('email') !== '' && (!validEmail(s('email')) || len(s('email')) > 150)) e.email = 'Please enter a valid email address.';

    const delivery = table !== null ? 'table' : s('delivery');
    if (table === null && (!['delivery', 'pickup'].includes(delivery) || (delivery === 'pickup' && !ck.pickup))) e.delivery = 'Please choose delivery or pickup.';
    if (delivery === 'delivery' && (s('address') === '' || len(s('address')) > 300)) e.address = 'Please enter the delivery address.';

    const payment = s('payment');
    if (!this.store.paymentMethods().includes(payment)) e.payment = 'Please choose a payment method.';
    else if (['bkash', 'card'].includes(payment) && !/^[A-Za-z0-9-]{6,32}$/.test(s('trx_id'))) e.trx_id = 'Please enter the transaction ID (6–32 letters or digits).';

    if (delivery === 'delivery') {
      if (s('country_code') !== '' && !/^[A-Za-z]{2}$/.test(s('country_code'))) e.country_code = 'Please choose a country.';
      for (const [k, label] of [['city_id', 'city'], ['area_id', 'area']] as const) {
        if (s(k) !== '' && s(k) !== 'other' && (!/^\d+$/.test(s(k)) || s(k).length > 10)) e[k] = `Please choose your ${label} from the list.`;
      }
    }
    if (len(s('note')) > 500) e.note = 'The note is too long.';
    if (cart.rx && s('rx_confirm') === '') e.rx_confirm = 'Please confirm you have a valid prescription for the Rx items.';
    if (cart.lines.length === 0) e.items = 'Your cart is empty.';
    else if (!cart.orderable) e.items = 'Some items in your cart are not available any more. Please review your cart.';
    return e;
  }

  /** The POST /v1/orders body. Prices are never sent: the API prices the order itself. */
  payload(input: Record<string, unknown>, cart: PricedCart, table: Table = null): Json {
    const s = (k: string): string => String(input[k] ?? '').trim();
    const delivery = table !== null ? 'table' : s('delivery') === 'pickup' ? 'pickup' : 'delivery';
    const payment = s('payment');
    const notes: string[] = [];
    if (s('note') !== '') notes.push(s('note'));
    if (cart.rx) notes.push('Prescription items: customer will show the prescription' + (s('rx_note') !== '' ? ` (${Array.from(s('rx_note')).slice(0, 200).join('')})` : '') + '.');

    const customer: Json = {
      name: s('name'),
      phone: s('phone') !== '' ? normalisePhone(s('phone')) : '',
      email: s('email'),
      address: delivery === 'delivery' ? [s('address'), s('area')].filter(Boolean).join(', ') : '',
    };
    const id = (k: string): number | null => (/^\d+$/.test(s(k)) && Number(s(k)) > 0 ? Number(s(k)) : null);
    if (delivery === 'delivery' && /^[A-Za-z]{2}$/.test(s('country_code'))) {
      Object.assign(customer, { country_code: s('country_code').toUpperCase(), city_id: id('city_id'), area_id: id('area_id') });
    }
    const out: Json = {
      items: cart.lines.map((l) => ({ product_id: l.product_id, variant_id: l.variant_id, qty: l.qty })),
      customer,
      delivery,
      payment,
      trx_id: ['bkash', 'card'].includes(payment) ? s('trx_id') : null,
      note: notes.join(' '),
      location: delivery === 'delivery' ? CheckoutService.location(input) : null,
      channel: 'storefront',
    };
    if (table !== null) out.table = String(table.code);
    return out;
  }

  /** Country / city / area catalogue for the pickers, narrowed to what the visitor already chose. */
  async places(old: { country_code?: string; city_id?: string } = {}): Promise<Places> {
    const out: Places = { countries: [], country: '', cities: [], areas: [] };
    try {
      out.countries = (await this.api.locationCountries()).filter((c) => c.has_dataset);
      if (out.countries.length === 0) return out;
      const codes = out.countries.map((c) => String(c.code));
      const want = String(old.country_code ?? '').trim().toUpperCase();
      out.country = codes.includes(want) ? want : codes.length === 1 ? codes[0] : '';
      if (out.country !== '') {
        out.cities = await this.api.locationCities(out.country);
        const city = Math.trunc(Number(old.city_id ?? 0));
        if (city > 0 && out.cities.map((c) => Number(c.id)).includes(city)) out.areas = await this.api.locationAreas(city);
      }
    } catch (e) {
      if (isApiError(e)) return { ...NO_PLACES };
      throw e;
    }
    return out;
  }

  place(payload: Json, idempotencyKey: string): Promise<Json> {
    return this.api.createOrder(payload, idempotencyKey);
  }

  /** Delivery pin from the hidden fields lat / lng / loc_area / loc_city / loc_source (null when absent or invalid). */
  static location(input: Record<string, unknown>): Json | null {
    const rawLat = input.lat ?? '';
    const rawLng = input.lng ?? '';
    if (rawLat === '' || rawLng === '' || !Number.isFinite(Number(rawLat)) || !Number.isFinite(Number(rawLng))) return null;
    const lat = Number(rawLat);
    const lng = Number(rawLng);
    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return null;
    const src = String(input.loc_source ?? '');
    return {
      lat: Math.round(lat * 1e6) / 1e6,
      lng: Math.round(lng * 1e6) / 1e6,
      area: Array.from(String(input.loc_area ?? '').trim()).slice(0, 80).join(''),
      city: Array.from(String(input.loc_city ?? '').trim()).slice(0, 80).join(''),
      source: ['gps', 'area', 'manual'].includes(src) ? src : 'manual',
    };
  }
}
