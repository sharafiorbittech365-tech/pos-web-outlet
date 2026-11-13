/**
 * Works out which multi-outlet website a request is for. The result is the value sent to the API as
 * X-Multisite-Host: "<slug>.<siteHost>" or (locally) the bare slug.
 * Port of app/Libraries/HostResolver.php — pure functions, safe in the proxy and on the server.
 *
 * Gateway mode (token sfg_…), in this order:
 *   1. ?site=<slug>            development hosts only (localhost …); remembered in a cookie
 *   2. the ms_site cookie      development hosts only, so internal links keep working
 *   3. the request Host header <slug>.<siteHost>
 * Key mode (MULTISITE_KEY = mk_live_…): the key fixes the website, so there always is one — the
 * request Host (or "site" when there is none) only names it for caching; ?site= is ignored.
 *
 * On a real domain the Host header always wins, so nobody can make one website's domain show
 * another business by adding ?site=.
 */
export const SITE_COOKIE = 'ms_site';
/** Header the proxy sets for the app (always overwritten, never trusted from the client). */
export const HOST_HEADER = 'x-ms-host';

export interface HostModes {
  devHosts: string[];
  siteKey: boolean;
}

/** A website slug: lower-case letters, digits and dashes, 1–63 characters. */
export function slug(value: string | null | undefined): string | null {
  const v = (value ?? '').trim().toLowerCase();
  return /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(v) ? v : null;
}

/** "Shop.Example.com:8080" → "shop.example.com"; null when it is not a valid host name. */
export function normaliseHost(hostHeader: string | null | undefined): string | null {
  let h = (hostHeader ?? '').trim().toLowerCase();
  if (h === '') return null;
  if (h.startsWith('[')) {
    const end = h.indexOf(']');
    return end === -1 ? null : h.slice(1, end);
  }
  h = h.replace(/:\d+$/, '').replace(/\.+$/, '');
  if (h.length > 253 || !/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/.test(h) || h.includes('..')) return null;
  return h;
}

export interface Resolved {
  /** website to load (null: none chosen) */
  host: string | null;
  /** slug to store in the cookie (null: leave the cookie alone) */
  remember: string | null;
}

export function resolveHost(siteParam: string | null, cookie: string | null, hostHeader: string, modes: HostModes): Resolved {
  const host = normaliseHost(hostHeader);
  if (modes.siteKey) return { host: host ?? 'site', remember: null };
  if (host === null || modes.devHosts.includes(host)) {
    const s = slug(siteParam);
    if (s !== null) return { host: s, remember: s };
    return { host: slug(cookie), remember: null };
  }
  return { host, remember: null };
}
