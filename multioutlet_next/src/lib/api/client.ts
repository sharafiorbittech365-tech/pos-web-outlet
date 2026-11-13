/**
 * Minimal HTTP client for the API Service (port of OmniPOS\Sdk\Client).
 *
 * Every response is an envelope {data, meta} or {error: {code, message, details, request_id}}.
 * GETs go through Next's data cache (`revalidate` seconds); when the API is briefly unreachable
 * (network error / 5xx) the last good copy of the same GET is served instead of an error page.
 */
import 'server-only';
import { ApiError } from './errors';

export type Query = Record<string, string | number | boolean | null | undefined>;
export interface ApiResult<T = unknown> {
  data: T;
  meta: Record<string, any>;
}
export interface GetOptions {
  /** seconds the response may be reused; 0 / undefined = never cached */
  revalidate?: number;
}

const STALE_MAX = 500;
const stale = new Map<string, ApiResult>();

function remember(key: string, value: ApiResult): void {
  stale.delete(key);
  stale.set(key, value);
  if (stale.size > STALE_MAX) {
    const first = stale.keys().next().value;
    if (first !== undefined) stale.delete(first);
  }
}

export class ApiClient {
  constructor(
    private readonly baseUrl: string,
    private readonly key: string,
    private readonly headers: Record<string, string> = {},
    private readonly timeoutMs = 8000,
    private readonly tag = '',
  ) {}

  private url(path: string, query: Query = {}): string {
    const qs = new URLSearchParams();
    for (const [k, v] of Object.entries(query)) {
      if (v !== null && v !== undefined && v !== '') qs.set(k, String(v));
    }
    const q = qs.toString();
    return this.baseUrl.replace(/\/+$/, '') + '/' + path.replace(/^\/+/, '') + (q ? '?' + q : '');
  }

  private baseHeaders(extra: Record<string, string> = {}): Record<string, string> {
    return {
      Authorization: 'Bearer ' + this.key,
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'User-Agent': 'omnipos-multioutlet-next/1.0.0',
      ...this.headers,
      ...extra,
    };
  }

  private async decode(res: Response): Promise<ApiResult> {
    const requestId = res.headers.get('x-request-id') ?? '';
    let json: any;
    try {
      json = await res.json();
    } catch {
      throw new ApiError(res.status, 'bad_response', `The API returned an unreadable response (HTTP ${res.status}).`, {}, requestId);
    }
    if (json === null || typeof json !== 'object' || Array.isArray(json)) {
      throw new ApiError(res.status, 'bad_response', `The API returned an unreadable response (HTTP ${res.status}).`, {}, requestId);
    }
    if (res.status >= 400 || json.error) {
      const e = (json.error ?? {}) as Record<string, any>;
      throw new ApiError(res.status, String(e.code ?? 'error'), String(e.message ?? 'Request failed.'), (e.details ?? {}) as Record<string, unknown>, String(e.request_id ?? requestId));
    }
    return { data: json.data ?? null, meta: (json.meta ?? {}) as Record<string, any> };
  }

  private async send(method: 'GET' | 'POST', url: string, body: unknown, headers: Record<string, string>, revalidate?: number): Promise<Response> {
    const init: RequestInit & { next?: { revalidate?: number | false; tags?: string[] } } = {
      method,
      headers,
      signal: AbortSignal.timeout(this.timeoutMs),
    };
    if (body !== undefined) init.body = JSON.stringify(body);
    if (method === 'GET') {
      if (revalidate && revalidate > 0) {
        init.next = { revalidate, tags: this.tag ? [this.tag] : [] };
      } else {
        init.cache = 'no-store';
      }
    } else {
      init.cache = 'no-store';
    }
    try {
      return await fetch(url, init);
    } catch (e) {
      throw new ApiError(0, 'network_error', 'Could not reach the API: ' + (e instanceof Error ? e.message : String(e)));
    }
  }

  async get<T = any>(path: string, query: Query = {}, opts: GetOptions = {}): Promise<ApiResult<T>> {
    const url = this.url(path, query);
    const ck = this.key.slice(0, 12) + '|' + JSON.stringify(this.headers) + '|' + url;
    try {
      let res: Response;
      try {
        res = await this.send('GET', url, undefined, this.baseHeaders(), opts.revalidate);
      } catch (e) {
        // a kept-alive socket the server had just closed (e.g. `php spark serve`): a GET is safe to send once more
        if (!(e instanceof ApiError) || e.status !== 0) throw e;
        res = await this.send('GET', url, undefined, this.baseHeaders(), opts.revalidate);
      }
      const out = await this.decode(res);
      if (opts.revalidate) remember(ck, out);
      return out as ApiResult<T>;
    } catch (e) {
      const last = stale.get(ck);
      if (last && e instanceof ApiError && (e.status === 0 || e.status >= 500)) return last as ApiResult<T>;
      throw e;
    }
  }

  async post<T = any>(path: string, body: unknown, idempotencyKey?: string): Promise<ApiResult<T>> {
    const extra: Record<string, string> = {};
    if (idempotencyKey) extra['Idempotency-Key'] = idempotencyKey.replace(/[^A-Za-z0-9_-]/g, '').slice(0, 64);
    const res = await this.send('POST', this.url(path), body, this.baseHeaders(extra));
    return (await this.decode(res)) as ApiResult<T>;
  }
}
