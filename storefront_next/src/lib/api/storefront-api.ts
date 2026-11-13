/**
 * The storefront's only data source: the API Service, called as the hosted storefront of one shop
 * (gateway token + X-Storefront-Host, or CLIENT_ID/CLIENT_SECRET when self-hosted).
 * Port of app/Libraries/StoreApi.php.
 */
import 'server-only';
import { config } from '../config';
import { ApiClient, type Query } from './client';

export type Json = Record<string, any>;
export type Product = Json;
export interface Page<T = Json> {
  data: T[];
  meta: Record<string, any>;
}

const TTL = { profile: 10, list: 30, product: 30, places: 600, photo: 300 } as const;

export class StoreApi {
  constructor(private readonly client: ApiClient, public readonly host: string) {}

  static forHost(host: string): StoreApi {
    const headers: Record<string, string> = config.isSelfHosted
      ? { 'X-Storefront-Host': host, 'X-Client-Id': config.clientId }
      : config.isSiteKey
        ? {}
        : { 'X-Storefront-Host': host };
    const key = config.isSelfHosted ? config.clientSecret : config.gatewayToken;
    return new StoreApi(new ApiClient(config.apiUrl, key, headers, config.apiTimeoutMs, 'sf:' + host), host);
  }

  /** Shop profile, site settings and categories: {business, site, categories}. */
  async storefront(): Promise<Json> {
    return ((await this.client.get('/v1/storefront', {}, { revalidate: TTL.profile })).data ?? {}) as Json;
  }

  async products(query: Query = {}): Promise<Page> {
    const r = await this.client.get('/v1/products', query, { revalidate: TTL.list });
    return { data: Array.isArray(r.data) ? (r.data as Json[]) : [], meta: r.meta };
  }

  /** Every product (up to `max`), for the home page sections, menus and price lists. */
  async allProducts(max = 500): Promise<Json[]> {
    const out: Json[] = [];
    let page = 1;
    let last = 1;
    do {
      const r = await this.products({ per_page: 100, page });
      out.push(...r.data);
      last = Number(r.meta.last_page ?? 1);
      page++;
      if (r.data.length === 0) break;
    } while (page <= last && out.length < max);
    return out.slice(0, max);
  }

  /** One published information page: {slug, title, kind, body_html, seo_description}. */
  async page(slug: string): Promise<Json> {
    return ((await this.client.get('/v1/pages/' + encodeURIComponent(slug), {}, { revalidate: TTL.profile })).data ?? {}) as Json;
  }

  async product(id: number): Promise<Product> {
    return ((await this.client.get('/v1/products/' + id, {}, { revalidate: TTL.product })).data ?? {}) as Product;
  }

  async inventory(productIds: number[]): Promise<Json[]> {
    if (productIds.length === 0) return [];
    const r = await this.client.get('/v1/inventory', { product_ids: productIds.map((n) => Math.trunc(n)).join(',') });
    return Array.isArray(r.data) ? (r.data as Json[]) : [];
  }

  /** Approved reviews of a product (newest first). */
  async productReviews(id: number, perPage = 20): Promise<Json[]> {
    const r = await this.client.get(`/v1/products/${Math.trunc(id)}/reviews`, { per_page: perPage }, { revalidate: TTL.product });
    return Array.isArray(r.data) ? (r.data as Json[]) : [];
  }

  /** POST /v1/products/{id}/reviews: a customer's review, kept for the shop's approval. */
  async addReview(id: number, payload: Json, idempotencyKey: string): Promise<Json> {
    return ((await this.client.post(`/v1/products/${Math.trunc(id)}/reviews`, payload, idempotencyKey)).data ?? {}) as Json;
  }

  /** POST /v1/orders → {reference, number, status, subtotal, tax, shipping, total}. */
  async createOrder(payload: Json, idempotencyKey: string): Promise<Json> {
    return ((await this.client.post('/v1/orders', payload, idempotencyKey)).data ?? {}) as Json;
  }

  async locationCountries(): Promise<Json[]> {
    const r = await this.client.get('/v1/locations/countries', {}, { revalidate: TTL.places });
    return Array.isArray(r.data) ? (r.data as Json[]) : [];
  }

  async locationCities(country: string): Promise<Json[]> {
    const r = await this.client.get('/v1/locations/cities', { country }, { revalidate: TTL.places });
    return Array.isArray(r.data) ? (r.data as Json[]) : [];
  }

  async locationAreas(cityId: number): Promise<Json[]> {
    const r = await this.client.get('/v1/locations/areas', { city: cityId }, { revalidate: TTL.places });
    return Array.isArray(r.data) ? (r.data as Json[]) : [];
  }

  /** The table behind a table QR code: {name, area, seats} (404 when the code is not this shop's). */
  async table(code: string): Promise<Json> {
    return ((await this.client.get('/v1/tables/' + encodeURIComponent(code))).data ?? {}) as Json;
  }

  /** Order or booking tracking: {reference, status, ship_status, total, items, created_at, type?}. */
  async order(reference: string): Promise<Json> {
    return ((await this.client.get('/v1/orders/' + encodeURIComponent(reference))).data ?? {}) as Json;
  }

  /** Free booking times; availability changes with every booking so it is never cached. */
  async slots(query: Query): Promise<Page> {
    const r = await this.client.get('/v1/bookings/slots', query);
    return { data: Array.isArray(r.data) ? (r.data as Json[]) : [], meta: r.meta };
  }

  async doctors(): Promise<Json[]> {
    const r = await this.client.get('/v1/doctors', {}, { revalidate: TTL.list });
    return Array.isArray(r.data) ? (r.data as Json[]) : [];
  }

  /** One doctor with open_dates (never cached: changes with every booking). */
  async doctor(id: number): Promise<Json> {
    return ((await this.client.get('/v1/doctors/' + id)).data ?? {}) as Json;
  }

  async doctorSlots(id: number, date: string): Promise<Page> {
    const r = await this.client.get('/v1/doctors/' + id + '/slots', { date });
    return { data: Array.isArray(r.data) ? (r.data as Json[]) : [], meta: r.meta };
  }

  /** The doctor's photo {mime, base64}. */
  async doctorPhoto(id: number): Promise<Json> {
    return ((await this.client.get('/v1/doctors/' + id + '/photo', {}, { revalidate: TTL.photo })).data ?? {}) as Json;
  }

  /** POST /v1/bookings → {reference, status, starts_at, ends_at}. */
  async createBooking(payload: Json, idempotencyKey: string): Promise<Json> {
    return ((await this.client.post('/v1/bookings', payload, idempotencyKey)).data ?? {}) as Json;
  }
}
