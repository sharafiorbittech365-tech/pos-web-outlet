/**
 * The shop and its API client for the current request (port of the "store" filter + StoreContext).
 * The proxy (src/proxy.ts) resolves which shop the request is for and passes it in the x-sf-host
 * header; this loads the shop profile from the API and memoises it for the whole render.
 */
import 'server-only';
import { cache } from 'react';
import { headers } from 'next/headers';
import { ApiError } from './api/errors';
import { StoreApi } from './api/storefront-api';
import { config } from './config';
import { HOST_HEADER } from './host';
import { Store } from './store';

export type StoreErrorKind = 'unavailable' | 'not_found' | 'temporary' | 'choose' | 'domain';

/** Thrown when the shop cannot be shown; the root layout turns it into a friendly page. */
export class StoreError extends Error {
  constructor(
    public readonly kind: StoreErrorKind,
    public readonly status: number,
    public readonly store: Store | null = null,
    public readonly requestId = '',
  ) {
    super('store_' + kind);
    this.name = 'StoreError';
  }
}

export function isStoreError(e: unknown): e is StoreError {
  return e instanceof StoreError;
}

export interface Ctx {
  host: string;
  store: Store;
  api: StoreApi;
}

/** Same mapping as ErrorPage::forApiError(). */
function forApiError(e: ApiError): [number, StoreErrorKind] {
  if (e.status === 402 || e.status === 403) return [503, 'unavailable'];
  if (e.status === 404) return [404, 'not_found'];
  return [503, 'temporary']; // 0 (network), 401 (gateway misconfigured), 429, 5xx
}

export const getContext = cache(async (): Promise<Ctx> => {
  const h = await headers();
  const host = h.get(HOST_HEADER) ?? '';
  if (host === '') {
    // self-hosted sites never show the platform's "choose a store" page
    throw config.isSelfHosted ? new StoreError('not_found', 404) : new StoreError('choose', 200);
  }
  const api = StoreApi.forHost(host);
  let profile;
  try {
    profile = await api.storefront();
  } catch (e) {
    if (e instanceof ApiError) {
      let [status, kind] = forApiError(e);
      if (e.status === 403 && e.code === 'forbidden' && config.isSelfHosted) {
        // the API refused this domain for this CLIENT_ID (not verified, disabled or blocked)
        console.warn(`[storefront] domain ${host} is not authorised: ${e.message} (request ${e.requestId})`);
        [status, kind] = [403, 'domain'];
      }
      if (e.status === 401) console.error(`[storefront] gateway token rejected by the API for ${host}: ${e.message} (request ${e.requestId})`);
      if (kind === 'temporary') console.error(`[storefront] profile for ${host} failed: ${e.status} ${e.code} ${e.message} (request ${e.requestId})`);
      throw new StoreError(kind, status, null, kind === 'temporary' ? e.requestId : '');
    }
    throw e;
  }
  const store = new Store(profile, host);
  if (!store.isOpen()) throw new StoreError('unavailable', 503, store);
  return { host, store, api };
});
