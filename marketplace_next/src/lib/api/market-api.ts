/**
 * The website's only data source: the Marketplace API (/v1/market). Server only.
 * Every call carries the shared API token; per-visitor state goes along: the location (query string on
 * GET, "location" in POST bodies), the buyer token (X-Buyer-Token) and the visitor's IP (X-Visitor-IP,
 * for sign-in throttling).
 */
import 'server-only';
import { cache } from 'react';
import { ApiClient, type Query } from './client';
import { config } from '../config';
import { clientIp } from '../shield';
import { getBuyerToken, getLocation, type Cart, type Loc } from '../visitor';

export type Json = Record<string, any>;

export class MarketApi {
  private constructor(
    private readonly client: ApiClient,
    private readonly loc: Loc | null,
  ) {}

  private locQuery(): Query {
    const l = this.loc;
    return l ? { lat: l.lat, lng: l.lng, area: l.area, city: l.city, src: l.source } : {};
  }

  private async get<T = Json>(path: string, query: Query = {}, revalidate = 0, withLoc = true): Promise<T> {
    return (await this.client.get<T>(path, withLoc ? { ...query, ...this.locQuery() } : query, { revalidate })).data as T;
  }

  private async post<T = Json>(path: string, body: Json): Promise<T> {
    return (await this.client.post<T>(path, { ...body, location: this.loc })).data as T;
  }

  /** radius chips, default radius, the offline area list, number of listed shops, POS address. */
  meta(): Promise<Json> {
    return this.get('/v1/market/meta', {}, 300, false);
  }

  listings(query: Query): Promise<Json> {
    return this.get('/v1/market/listings', query);
  }

  shops(query: Query): Promise<Json> {
    return this.get('/v1/market/shops', query);
  }

  shop(slug: string, query: Query): Promise<Json> {
    return this.get(`/v1/market/shops/${encodeURIComponent(slug)}`, query);
  }

  listing(businessId: number, productId: number, date = ''): Promise<Json> {
    return this.get(`/v1/market/listings/${Math.trunc(businessId)}/${Math.trunc(productId)}`, { date });
  }

  resolveLocation(body: { lat?: number; lng?: number; area?: string }): Promise<{ location: Loc; label: string }> {
    return this.post('/v1/market/location', body);
  }

  checkItem(body: Json): Promise<{ business_id: number; key: string; qty: number; name: string }> {
    return this.post('/v1/market/cart/check', body);
  }

  quote(cart: Cart, fulfilment: Record<string, string> = {}): Promise<{ groups: Json[]; count: number }> {
    return this.post('/v1/market/cart/quote', { cart, fulfilment });
  }

  checkout(body: Json): Promise<{ token: string; results: Json[] }> {
    return this.post('/v1/market/checkout', body);
  }

  checkoutResult(token: string): Promise<{ token: string; results: Json[] }> {
    return this.get(`/v1/market/checkout/${encodeURIComponent(token)}`);
  }

  book(body: Json): Promise<Json> {
    return this.post('/v1/market/bookings', body);
  }

  booking(token: string): Promise<Json> {
    return this.get(`/v1/market/bookings/${encodeURIComponent(token)}`);
  }

  login(phone: string, password: string): Promise<{ buyer: Json; token: string; expires_at: number }> {
    return this.post('/v1/market/buyers/login', { phone, password });
  }

  register(body: Json): Promise<{ buyer: Json; token: string; expires_at: number }> {
    return this.post('/v1/market/buyers', body);
  }

  me(): Promise<Json> {
    return this.get('/v1/market/me');
  }

  myOrders(page: number): Promise<Json> {
    return this.get('/v1/market/me/orders', { page });
  }

  myOrder(id: number): Promise<Json> {
    return this.get(`/v1/market/me/orders/${Math.trunc(id)}`);
  }

  myBookings(): Promise<Json[]> {
    return this.get('/v1/market/me/bookings');
  }

  /** For this request: the visitor's location, buyer token and IP go along with every call. */
  static async forRequest(): Promise<MarketApi> {
    const [loc, buyer, ip] = await Promise.all([getLocation(), getBuyerToken(), clientIp()]);
    const headers: Record<string, string> = {};
    if (buyer !== '') headers['X-Buyer-Token'] = buyer;
    if (ip !== 'unknown') headers['X-Visitor-IP'] = ip;
    return new MarketApi(new ApiClient(config.apiUrl, config.apiToken, headers, config.apiTimeoutMs, 'market'), loc);
  }
}

/** One client per request (memoised for the render). */
export const marketApi = cache(() => MarketApi.forRequest());
