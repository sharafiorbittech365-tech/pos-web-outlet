/**
 * The website and its API client for the current request (port of the "site" filter + SiteContext).
 * The proxy (src/proxy.ts) resolves which website the request is for and passes it in the x-ms-host
 * header; this loads the profile from the API and memoises it for the whole render.
 */
import 'server-only';
import { cache } from 'react';
import { headers } from 'next/headers';
import { ApiError } from './api/errors';
import { MultisiteApi } from './api/multisite-api';
import { HOST_HEADER } from './host';
import { Site } from './site';

export type SiteErrorKind = 'unavailable' | 'not_found' | 'temporary' | 'choose';

/** Thrown when the website cannot be shown; the root layout turns it into a friendly page. */
export class SiteError extends Error {
  constructor(
    public readonly kind: SiteErrorKind,
    public readonly status: number,
    public readonly site: Site | null = null,
    public readonly requestId = '',
  ) {
    super('site_' + kind);
    this.name = 'SiteError';
  }
}

export function isSiteError(e: unknown): e is SiteError {
  return e instanceof SiteError;
}

export interface Ctx {
  host: string;
  site: Site;
  api: MultisiteApi;
}

/** Same mapping as ErrorPage::forApiError(). */
export function forApiError(e: ApiError): [number, SiteErrorKind] {
  if (e.status === 402 || e.status === 403) return [503, 'unavailable'];
  if (e.status === 404) return [404, 'not_found'];
  return [503, 'temporary']; // 0 (network), 401 (credential wrong / revoked), 429, 5xx
}

export const getContext = cache(async (): Promise<Ctx> => {
  const host = (await headers()).get(HOST_HEADER) ?? '';
  if (host === '') throw new SiteError('choose', 200);
  const api = MultisiteApi.forHost(host);
  let profile;
  try {
    profile = await api.profile();
  } catch (e) {
    if (e instanceof ApiError) {
      const [status, kind] = forApiError(e);
      if (e.status === 401) {
        // unknown websites are 404 from the API; 401 means this app's credential is wrong or revoked
        console.error(`[multioutlet] credential rejected by the API for ${host}: ${e.message} (request ${e.requestId})`);
      } else if (kind === 'temporary') {
        console.error(`[multioutlet] profile for ${host} failed: ${e.status} ${e.code} ${e.message} (request ${e.requestId})`);
      }
      throw new SiteError(kind, status, null, kind === 'temporary' ? e.requestId : '');
    }
    throw e;
  }
  const site = new Site(profile, host);
  if (!site.isOpen()) throw new SiteError('unavailable', 503, site);
  return { host, site, api };
});
