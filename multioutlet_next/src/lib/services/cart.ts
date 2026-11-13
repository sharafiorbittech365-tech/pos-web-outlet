/**
 * The visitor's cart. The PHP app kept it in the session; here it lives in an httpOnly cookie per website
 * host. An order goes to ONE outlet, so the cart belongs to one outlet at a time: adding an item from another
 * outlet is refused with a clear message until the visitor empties the cart.
 *
 * Only ids and quantities are stored (item id, the outlet's product id, option id); names, prices and stock
 * always come from the API. The cart id doubles as the Idempotency-Key of the order, so a double click never
 * creates two orders. Mutations must run in Server Actions (cookies are read-only while rendering).
 * Port of Modules/Cart/Services/CartService.php.
 */
import 'server-only';
import { randomBytes } from 'node:crypto';
import { isApiError } from '../api/errors';
import { type Json } from '../api/multisite-api';
import { readJson, setCookie, sha, writeJson } from '../cookies';
import { type Ctx } from '../context';
import { type Outlet, type Site } from '../site';

export const MAX_LINES = 50;
export const MAX_QTY = 99;
export const COUNT_COOKIE = 'ms_cart_n';
const TTL = 7 * 86400;

export interface CartLine {
  item_id: number;
  product_id: number;
  variant_id: number | null;
  qty: number;
}

export interface CartData {
  id: string;
  outlet_id: number | null;
  lines: CartLine[];
}

export interface PricedLine extends CartLine {
  index: number;
  name: string;
  variant: string;
  unit: number;
  line_total: number;
  in_stock: boolean;
  max: number;
  product: Json;
  rx: boolean;
}

export interface PricedCart {
  outlet: Outlet | null;
  lines: PricedLine[];
  count: number;
  subtotal: number;
  shipping: number;
  ship_delivery: number;
  free_left: number;
  free_progress: number;
  total: number;
  issues: string[];
  orderable: boolean;
  rx: boolean;
}

const key = (host: string): string => 'ms_cart_' + sha(host, 16);
const newId = (): string => 'm' + randomBytes(12).toString('hex');
const int = (v: unknown): number => Math.trunc(Number(v ?? 0)) || 0;
const round2 = (n: number): number => Math.round(n * 100) / 100;

export async function readCart(host: string): Promise<CartData> {
  const raw = await readJson<{ i?: unknown; o?: unknown; l?: unknown }>(key(host));
  if (!raw || typeof raw.i !== 'string' || !Array.isArray(raw.l)) return { id: newId(), outlet_id: null, lines: [] };
  const lines: CartLine[] = [];
  for (const t of raw.l.slice(0, MAX_LINES)) {
    if (!Array.isArray(t)) continue;
    const [iid, pid, vid, q] = t.map(int);
    if (iid > 0 && pid > 0 && q > 0) lines.push({ item_id: iid, product_id: pid, variant_id: vid > 0 ? vid : null, qty: Math.min(MAX_QTY, q) });
  }
  const oid = int(raw.o);
  return { id: raw.i, outlet_id: lines.length > 0 && oid > 0 ? oid : null, lines };
}

async function save(host: string, d: CartData): Promise<void> {
  await writeJson(key(host), { i: d.id, o: d.outlet_id, l: d.lines.map((l) => [l.item_id, l.product_id, l.variant_id ?? 0, l.qty]) }, TTL);
  // readable by the browser, carries only the number of items
  await setCookie(COUNT_COOKIE, String(d.lines.reduce((n, l) => n + l.qty, 0)), { maxAge: 7200, httpOnly: false });
}

export async function cartCount(host: string): Promise<number> {
  return (await readCart(host)).lines.reduce((n, l) => n + l.qty, 0);
}

/** The outlet the cart belongs to (null when empty). */
export async function cartOutlet(host: string): Promise<number | null> {
  return (await readCart(host)).outlet_id;
}

/** The cart id (Idempotency-Key of the order). */
export async function cartId(host: string): Promise<string> {
  const d = await readCart(host);
  if ((await readJson(key(host))) === null) await save(host, d);
  return d.id;
}

/** The item's offer at one outlet. */
export function offerAt(item: Json, outletId: number): Json | null {
  return (Array.isArray(item.offers) ? item.offers : []).find((o: any) => o && typeof o === 'object' && int(o.outlet_id) === outletId) ?? null;
}

/**
 * Add an item from one outlet, checked against its live API data.
 * Returns why it could not be added, or null when added.
 */
export async function addFrom(host: string, site: Site, item: Json, outletId: number, variantId: number | null, qty: number): Promise<string | null> {
  const name = String(item.name ?? 'This item');
  const offer = offerAt(item, outletId);
  if (offer === null || site.outlet(outletId) === null) return `${name} is not sold at that outlet. Choose one of the outlets listed on the product page.`;
  const d = await readCart(host);
  if (d.outlet_id !== null && d.outlet_id !== outletId) {
    return `Your cart has items from ${site.outletName(d.outlet_id) || 'another outlet'}. An order is prepared by one outlet, so empty your cart (or finish that order) before adding items from ${String(offer.outlet)}.`;
  }
  const variants: Json[] = (Array.isArray(offer.variants) ? offer.variants : []).filter((v: unknown) => v && typeof v === 'object');
  if (variants.length > 0 && variantId === null) return `Please choose an option for ${name}.`;
  if (variantId !== null) {
    const v = variants.find((x) => int(x.id) === variantId);
    if (!v) return `That option is not available at ${String(offer.outlet)}.`;
    if ('in_stock' in v && !v.in_stock) return `Sorry, ${name} (${String(v.name ?? '')}) is out of stock at ${String(offer.outlet)}.`;
  }
  if (!(offer.in_stock ?? true)) return `Sorry, ${name} is out of stock at ${String(offer.outlet)}.`;
  qty = Math.max(1, Math.min(MAX_QTY, qty));
  const pid = int(offer.product_id);
  if (variantId === null && offer.stock !== undefined && offer.stock !== null) {
    const inCart = d.lines.filter((l) => l.product_id === pid).reduce((n, l) => n + l.qty, 0);
    const left = Math.floor(Number(offer.stock)) - inCart;
    if (left < qty) return left > 0 ? `Only ${left} more of ${name} available at ${String(offer.outlet)}.` : `Sorry, you already have all available ${name} in your cart.`;
  }

  d.outlet_id = outletId;
  const hit = d.lines.find((l) => l.product_id === pid && l.variant_id === variantId);
  if (hit) {
    hit.qty = Math.min(MAX_QTY, hit.qty + qty);
  } else {
    if (d.lines.length >= MAX_LINES) return `Your cart is full (${MAX_LINES} different items).`;
    d.lines.push({ item_id: int(item.id), product_id: pid, variant_id: variantId, qty });
  }
  await save(host, d);
  return null;
}

export async function setQty(host: string, index: number, qty: number): Promise<void> {
  const d = await readCart(host);
  if (!d.lines[index]) return;
  if (qty < 1) d.lines.splice(index, 1);
  else d.lines[index].qty = Math.min(MAX_QTY, qty);
  if (d.lines.length === 0) d.outlet_id = null;
  await save(host, d);
}

/** Empty the cart and start a new cart id (the next order gets a new idempotency key). */
export async function clearCart(host: string): Promise<void> {
  await save(host, { id: newId(), outlet_id: null, lines: [] });
}

/**
 * The cart priced with live API data for its outlet: lines with the outlet's price and stock, subtotal,
 * the outlet's delivery charge (free above its threshold) and total. Null when empty.
 */
export async function priced(ctx: Pick<Ctx, 'api' | 'site' | 'host'>, delivery: 'delivery' | 'pickup' = 'delivery'): Promise<PricedCart | null> {
  const d = await readCart(ctx.host);
  if (d.lines.length === 0) return null;
  const outlet = d.outlet_id !== null ? ctx.site.outlet(d.outlet_id) : null;
  const items: Record<number, Json> = {};
  await Promise.all(
    [...new Set(d.lines.map((l) => l.item_id))].map(async (iid) => {
      try {
        items[iid] = await ctx.api.product(iid);
      } catch (e) {
        if (!isApiError(e) || e.status !== 404) throw e;
      }
    }),
  );

  const out: PricedLine[] = [];
  const issues: string[] = [];
  let subtotal = 0;
  d.lines.forEach((l, i) => {
    const item = items[l.item_id] ?? null;
    const offer = item !== null && outlet !== null ? offerAt(item, int(outlet.id)) : null;
    if (item === null || offer === null || int(offer.product_id) !== l.product_id) {
      issues.push(`An item in your cart is no longer sold${outlet !== null ? ` at ${String(outlet.name)}` : ''}. Please remove it.`);
      out.push({ ...l, index: i, name: 'Unavailable item', variant: '', unit: 0, line_total: 0, in_stock: false, max: 0, product: {}, rx: false });
      return;
    }
    let unit = Number(offer.price ?? 0);
    let vName = '';
    let inStock = Boolean(offer.in_stock ?? true);
    let max = offer.stock !== undefined && offer.stock !== null ? Math.max(0, Math.floor(Number(offer.stock))) : MAX_QTY;
    if (l.variant_id !== null) {
      const v = (Array.isArray(offer.variants) ? offer.variants : []).find((x: any) => x && typeof x === 'object' && int(x.id) === l.variant_id);
      if (!v) {
        inStock = false;
      } else {
        unit = Number(v.price ?? unit);
        vName = String(v.name ?? '');
        inStock = Boolean(v.in_stock ?? inStock);
        max = v.stock !== undefined && v.stock !== null ? Math.max(0, Math.floor(Number(v.stock))) : MAX_QTY;
      }
    }
    if (!inStock || l.qty > max) issues.push(String(item.name) + (inStock ? `: only ${max} available.` : ` is out of stock at ${String(outlet!.name)}.`));
    const line = round2(unit * l.qty);
    subtotal += line;
    out.push({ ...l, index: i, name: String(item.name), variant: vName, unit, line_total: line, in_stock: inStock, max: Math.min(MAX_QTY, max), product: item, rx: Boolean(item.prescription_required) });
  });

  const ck = outlet?.checkout && typeof outlet.checkout === 'object' ? outlet.checkout : {};
  const charge = Math.max(0, Number(ck.delivery ?? 0) || 0);
  const freeAbove = Number(ck.freeAbove ?? 0) || 0;
  const free = freeAbove > 0 && subtotal >= freeAbove;
  const shipping = delivery === 'pickup' || free ? 0 : charge;
  subtotal = round2(subtotal);

  return {
    outlet,
    lines: out,
    count: out.reduce((n, x) => n + x.qty, 0),
    subtotal,
    shipping,
    ship_delivery: free ? 0 : charge,
    free_left: freeAbove > 0 && charge > 0 ? Math.max(0, freeAbove - subtotal) : 0,
    free_progress: freeAbove > 0 ? Math.min(100, Math.round((subtotal / freeAbove) * 100)) : 100,
    total: round2(subtotal + shipping),
    issues: [...new Set(issues)],
    orderable: outlet !== null && issues.length === 0 && out.length > 0,
    rx: out.some((x) => x.rx),
  };
}
