/**
 * The website's only data source: the Multi-outlet API (/v1/multisite). Port of app/Libraries/SiteApi.php.
 * Server only. The profile and product detail are revalidated on every view (switching the website off
 * or running out of stock shows at once); listings may be reused for a few seconds.
 */
import 'server-only';
import { ApiClient, type Query } from './client';
import { config } from '../config';

export type Json = Record<string, any>;

export interface Paged<T = Json> {
  data: T[];
  meta: Record<string, any>;
}

export class MultisiteApi {
  private constructor(
    private readonly client: ApiClient,
    public readonly host: string,
  ) {}

  static forHost(host: string): MultisiteApi {
    // a website key names the business itself; the platform gateway token needs the host
    const headers: Record<string, string> = config.isSiteKey ? {} : { 'X-Multisite-Host': host };
    return new MultisiteApi(new ApiClient(config.apiUrl, config.key, headers, config.apiTimeoutMs, 'ms:' + host), host);
  }

  /** {website, outlets, categories} */
  async profile(): Promise<Json> {
    return (await this.client.get<Json>('/v1/multisite')).data ?? {};
  }

  /** One outlet (404 for an outlet that is not on this website). */
  async outlet(id: number): Promise<Json> {
    return (await this.client.get<Json>(`/v1/multisite/outlets/${Math.trunc(id)}`)).data ?? {};
  }

  /** q, category, outlet, in_stock, min_price, max_price, featured, sort, page, per_page */
  async products(query: Query = {}): Promise<Paged> {
    const r = await this.client.get<Json[]>('/v1/multisite/products', query, { revalidate: 15 });
    return { data: Array.isArray(r.data) ? r.data : [], meta: r.meta ?? {} };
  }

  /** One item with every outlet's offer (stock: never cached). */
  async product(id: number): Promise<Json> {
    return (await this.client.get<Json>(`/v1/multisite/products/${Math.trunc(id)}`)).data ?? {};
  }

  /** POST /v1/multisite/orders → {reference, number, status, subtotal, tax, shipping, total, outlet}. */
  async createOrder(payload: Json, idempotencyKey: string): Promise<Json> {
    return (await this.client.post<Json>('/v1/multisite/orders', payload, idempotencyKey)).data ?? {};
  }

  /** Tracking: {reference, status, ship_status, total, items, created_at, outlet}. */
  async order(reference: string): Promise<Json> {
    return (await this.client.get<Json>(`/v1/multisite/orders/${encodeURIComponent(reference)}`)).data ?? {};
  }
}
