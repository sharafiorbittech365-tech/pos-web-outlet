/**
 * Per-visitor state the CodeIgniter marketplace kept in its session, now in httpOnly cookies:
 *
 *   mk_loc     the chosen location {lat, lng, area, city, source} (30 days) — client data: the API cleans it
 *   mk_cart    {business id: {"pid:vid": qty}} — ids and quantities only; every view is re-priced by the API
 *   mk_buyer   the signed buyer token from the API (sent back as X-Buyer-Token)
 *
 * Reads work anywhere on the server; writes only in Server Actions / Route Handlers.
 */
import 'server-only';
import { deleteCookie, getCookie, readJson, setCookie, writeJson } from './cookies';

export interface Loc {
  lat: number;
  lng: number;
  area: string;
  city: string;
  source: string;
}

export type Cart = Record<string, Record<string, number>>;

const LOC = 'mk_loc';
const CART = 'mk_cart';
const BUYER = 'mk_buyer';
export const MAX_LINES = 40;
export const MAX_QTY = 99;

export async function getLocation(): Promise<Loc | null> {
  const l = await readJson<Partial<Loc>>(LOC);
  if (!l || !Number.isFinite(Number(l.lat)) || !Number.isFinite(Number(l.lng))) return null;
  return { lat: Number(l.lat), lng: Number(l.lng), area: String(l.area ?? ''), city: String(l.city ?? ''), source: String(l.source ?? 'manual') };
}

export const saveLocation = (loc: Loc): Promise<void> => writeJson(LOC, loc, 30 * 86400);
export const forgetLocation = (): Promise<void> => deleteCookie(LOC);

/** "Dhanmondi, Dhaka" (Geo::label). */
export function locLabel(loc: Loc | null): string {
  if (loc === null) return 'Choose your location';
  const parts = [loc.area, loc.city].filter((s) => s !== '');
  return parts.length > 0 ? parts.join(', ') : `${loc.lat.toFixed(3)}, ${loc.lng.toFixed(3)}`;
}

/** The cart, cleaned (the API cleans it again). */
export async function getCart(): Promise<Cart> {
  const raw = await readJson<Record<string, unknown>>(CART);
  const out: Cart = {};
  let n = 0;
  for (const [bid, lines] of Object.entries(raw && typeof raw === 'object' ? raw : {})) {
    if (!/^[1-9]\d*$/.test(bid) || !lines || typeof lines !== 'object') continue;
    for (const [k, q] of Object.entries(lines as Record<string, unknown>)) {
      const qty = Number(q);
      if (n >= MAX_LINES || !/^[1-9]\d*:\d+$/.test(k) || !Number.isFinite(qty) || qty <= 0) continue;
      (out[bid] ??= {})[k] = Math.min(MAX_QTY, Math.round(qty * 1000) / 1000);
      n++;
    }
  }
  return out;
}

export async function saveCart(cart: Cart): Promise<void> {
  const clean = Object.fromEntries(Object.entries(cart).filter(([, l]) => Object.keys(l).length > 0));
  if (Object.keys(clean).length === 0) await deleteCookie(CART);
  else await writeJson(CART, clean, 30 * 86400);
}

export const cartLines = (cart: Cart): number => Object.values(cart).reduce((n, l) => n + Object.keys(l).length, 0);
/** Units in the cart (for the header badge), like CartService::count(). */
export const cartCount = (cart: Cart): number => Object.values(cart).reduce((n, l) => n + Math.ceil(Object.values(l).reduce((a, b) => a + b, 0)), 0);

export const getBuyerToken = async (): Promise<string> => (await getCookie(BUYER)) ?? '';
export const setBuyerToken = (token: string, expiresAt: number): Promise<void> =>
  setCookie(BUYER, token, { maxAge: Math.max(60, expiresAt - Math.floor(Date.now() / 1000)) });
export const forgetBuyer = (): Promise<void> => deleteCookie(BUYER);

/** Only same-site relative paths (no //host, no backslashes, no new lines). */
export function relative(back: string | null | undefined): string {
  const b = String(back ?? '').trim();
  if (b === '' || !b.startsWith('/') || b.startsWith('//') || b.includes('\\') || /[\r\n]/.test(b)) return '/';
  return b.slice(0, 500);
}

/** Where to go after signing in: ?next= when it is a same-site path, else My orders. */
export const afterLogin = (next: string | null | undefined): string => {
  const r = relative(next);
  return r === '/' || r.startsWith('/login') || r.startsWith('/signup') ? '/account/orders' : r;
};
