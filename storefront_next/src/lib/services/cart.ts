/**
 * The shopping cart. The PHP app kept it in the session; here it lives in an httpOnly cookie per shop
 * holding only {product, option, quantity} lines plus a cart id — prices are never stored and are
 * re-read from the API on every view (see calculator.ts), so tampering with the cookie changes nothing
 * that is charged. Mutations must run in Server Actions / Route Handlers (cookies are read-only while
 * rendering). Port of Modules/Cart/Services/CartService.php.
 */
import 'server-only';
import { randomBytes } from 'node:crypto';
import { isApiError } from '../api/errors';
import { type Product, type StoreApi } from '../api/storefront-api';
import { readJson, setCookie, sha, writeJson } from '../cookies';
import { type Store } from '../store';
import { type CartLine, MAX_QTY, type PricedCart, priceCart } from './calculator';

export const MAX_LINES = 50;
export const COUNT_COOKIE = 'sf_cart_n';
const TTL = 7 * 86400;

export interface CartData {
  id: string;
  lines: CartLine[];
}

const key = (host: string): string => 'sf_cart_' + sha(host, 16);
const newId = (): string => 'c' + randomBytes(12).toString('hex');

export async function readCart(host: string): Promise<CartData> {
  const raw = await readJson<{ i?: unknown; l?: unknown }>(key(host));
  if (!raw || typeof raw.i !== 'string' || !Array.isArray(raw.l)) return { id: newId(), lines: [] };
  const lines: CartLine[] = [];
  for (const t of raw.l.slice(0, MAX_LINES)) {
    if (!Array.isArray(t)) continue;
    const pid = Math.trunc(Number(t[0]));
    const vid = Math.trunc(Number(t[1]));
    const q = Math.trunc(Number(t[2]));
    if (pid > 0 && q > 0) lines.push({ product_id: pid, variant_id: vid > 0 ? vid : null, qty: Math.min(MAX_QTY, q) });
  }
  return { id: raw.i, lines };
}

async function save(host: string, d: CartData): Promise<void> {
  await writeJson(key(host), { i: d.id, l: d.lines.map((l) => [l.product_id, l.variant_id ?? 0, l.qty]) }, TTL);
  // readable by the browser (header badge / page cache), carries only the number of items
  await setCookie(COUNT_COOKIE, String(d.lines.reduce((n, l) => n + l.qty, 0)), { maxAge: 7200, httpOnly: false });
}

export async function cartLines(host: string): Promise<CartLine[]> {
  return (await readCart(host)).lines;
}

export async function cartCount(host: string): Promise<number> {
  return (await cartLines(host)).reduce((n, l) => n + l.qty, 0);
}

/** The cart id (used for idempotent order keys); saves a new cart when there is none yet. */
export async function cartId(host: string): Promise<string> {
  const d = await readCart(host);
  if ((await readJson(key(host))) === null) await save(host, d);
  return d.id;
}

async function add(host: string, productId: number, variantId: number | null, qty: number): Promise<void> {
  const d = await readCart(host);
  qty = Math.max(1, Math.min(MAX_QTY, qty));
  const hit = d.lines.find((l) => l.product_id === productId && l.variant_id === variantId);
  if (hit) hit.qty = Math.min(MAX_QTY, hit.qty + qty);
  else if (d.lines.length < MAX_LINES) d.lines.push({ product_id: productId, variant_id: variantId, qty });
  await save(host, d);
}

/** Validates against the product the API just returned, then adds. Returns an error message or null. */
export async function addProduct(host: string, p: Product, variantId: number | null, qty: number): Promise<string | null> {
  const name = String(p.name ?? 'This item');
  const variants: any[] = (Array.isArray(p.variants) ? p.variants : []).filter((v: unknown) => v && typeof v === 'object');
  if (variants.length > 0 && variantId === null) return `Please choose an option for ${name}.`;
  if (variantId !== null) {
    const v = variants.find((x) => Number(x.id ?? 0) === variantId);
    if (!v) return 'That option is not available.';
    if ('in_stock' in v && !v.in_stock) return `Sorry, ${name} (${String(v.name ?? '')}) is out of stock.`;
  }
  if ('in_stock' in p && !p.in_stock) return `Sorry, ${name} is out of stock.`;
  qty = Math.max(1, Math.min(MAX_QTY, qty));
  if (variantId === null && p.stock !== undefined && p.stock !== null) {
    const inCart = (await cartLines(host)).filter((l) => l.product_id === Number(p.id)).reduce((n, l) => n + l.qty, 0);
    const left = Math.floor(Number(p.stock)) - inCart;
    if (left < qty) return left > 0 ? `Only ${left} more of ${name} available.` : `Sorry, you already have all available ${name} in your cart.`;
  }
  await add(host, Number(p.id), variantId, qty);
  return null;
}

export async function setQty(host: string, index: number, qty: number): Promise<void> {
  const d = await readCart(host);
  if (!d.lines[index]) return;
  if (qty < 1) d.lines.splice(index, 1);
  else d.lines[index].qty = Math.min(MAX_QTY, qty);
  await save(host, d);
}

export const removeLine = (host: string, index: number): Promise<void> => setQty(host, index, 0);

export async function clearCart(host: string): Promise<void> {
  await save(host, { id: newId(), lines: [] });
}

/** Drop lines whose product no longer exists (Server Action / Route Handler only). */
export async function pruneCart(host: string, keep: (line: CartLine) => boolean): Promise<void> {
  const d = await readCart(host);
  const kept = d.lines.filter(keep);
  if (kept.length !== d.lines.length) await save(host, { id: d.id, lines: kept });
}

/**
 * The cart priced with the live product data. Lines whose product is gone are left out of the result
 * (with a notice) but not removed from the cookie here — see pruneCart.
 */
export async function priced(ctx: { api: StoreApi; store: Store; host: string }, delivery: 'delivery' | 'pickup' = 'delivery'): Promise<PricedCart> {
  const lines = await cartLines(ctx.host);
  const ids = [...new Set(lines.map((l) => l.product_id))];
  const found = await Promise.all(
    ids.map(async (id) => {
      try {
        return [id, await ctx.api.product(id)] as const;
      } catch (e) {
        if (isApiError(e) && e.status === 404) return null;
        throw e;
      }
    }),
  );
  const products: Record<number, Product> = {};
  for (const f of found) if (f) products[f[0]] = f[1];
  return priceCart(lines, products, ctx.store.checkout(), delivery);
}
