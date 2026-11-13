/** Marketplace view helpers (ports of app/Helpers/market_helper.php). Isomorphic. */
import { money } from './format';

export const DEMO_IMG = '/assets/omnipos/img/demo.svg';
export const ICON_IMG = '/assets/omnipos/img/icon.svg';

/** 850 m · 2.3 km */
export function kmStr(km: number | null | undefined): string {
  if (km === null || km === undefined || !Number.isFinite(Number(km))) return '';
  const k = Number(km);
  return k < 1 ? `${Math.round(k * 1000)} m` : `${k.toFixed(1).replace(/\.0$/, '')} km`;
}

/** Price as the buyer pays it, without trailing ".00" (৳52,999 · ৳1.20). */
export function mkPrice(amount: number | string | null | undefined, currency: { symbol?: string; decimals?: number; position?: string } = {}): string {
  return money(amount, { symbol: currency.symbol ?? '৳', decimals: currency.decimals ?? 2, position: currency.position ?? 'before' }).replace(/\.0+(?=\D*$)/, '');
}

/** Picture of a listing; the demo image when it has none. */
export const listingImg = (url: string | null | undefined): string => (url && /^(https?:\/\/|\/)/i.test(url) ? url : DEMO_IMG);

/** A colour that is safe in a style attribute (css_color). */
export function cssColor(v: unknown, fallback = '#64748B'): string {
  const s = String(v ?? '').trim();
  return /^#[0-9a-fA-F]{3,8}$/.test(s) || /^var\(--[a-z0-9-]{1,40}\)$/.test(s) || /^[a-z]{3,20}$/.test(s) ? s : fallback;
}

export function bookingLabel(kind: string): string {
  return (
    { room: 'Book a room', table: 'Reserve a table', pickup: 'Schedule a pickup', quote: 'Request a quote', visit: 'Book a visit', repair: 'Book a repair' } as Record<string, string>
  )[kind] ?? 'Book an appointment';
}

export type Q = Record<string, string>;

/** The current query with some parameters changed (null/'' removes one); page resets unless it is one of the changes (mk_url). */
export function mkUrl(path: string, current: Q, changes: Record<string, string | null>): string {
  const q: Q = { ...current };
  if (!('page' in changes)) delete q.page;
  for (const [k, v] of Object.entries(changes)) {
    if (v === null || v === '') delete q[k];
    else q[k] = v;
  }
  const s = new URLSearchParams(q).toString();
  return path + (s ? '?' + s : '');
}

/** searchParams → plain strings (first value of repeated keys). */
export function plainQuery(sp: Record<string, string | string[] | undefined>): Q {
  const out: Q = {};
  for (const [k, v] of Object.entries(sp)) {
    const x = Array.isArray(v) ? v[0] : v;
    if (typeof x === 'string') out[k] = x;
  }
  return out;
}

/** "path?query" of the current page, for "return here" fields (back_path). */
export const backPath = (path: string, q: Q): string => path + (Object.keys(q).length ? '?' + new URLSearchParams(q).toString() : '');
