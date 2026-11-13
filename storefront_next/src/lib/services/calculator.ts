/**
 * Prices a cart: the shop's own prices are re-read from the API every time, so nothing the browser
 * holds can change what is charged. Pure function (port of CartCalculator.php).
 */
import { type Product } from '../api/storefront-api';

export const MAX_QTY = 99;

export interface CartLine {
  product_id: number;
  variant_id: number | null;
  qty: number;
}

export interface PricedLine {
  index: number;
  product_id: number;
  variant_id: number | null;
  qty: number;
  name: string;
  variant: string;
  unit: number;
  line_total: number;
  in_stock: boolean;
  max: number;
  rx: boolean;
  product: Product;
}

export interface PricedCart {
  lines: PricedLine[];
  count: number;
  subtotal: number;
  shipping: number;
  total: number;
  free_left: number;
  free_progress: number;
  issues: string[];
  rx: boolean;
  orderable: boolean;
}

export interface CheckoutRules {
  delivery: number;
  freeAbove: number;
  [k: string]: unknown;
}

const round2 = (n: number): number => Math.round(n * 100) / 100;

export function shipping(subtotal: number, checkout: Partial<CheckoutRules>, delivery: string): number {
  if (delivery === 'pickup' || subtotal <= 0) return 0;
  const free = Number(checkout.freeAbove ?? 0);
  if (free > 0 && subtotal >= free) return 0;
  return Number(checkout.delivery ?? 0);
}

export function priceCart(lines: CartLine[], products: Record<number, Product>, checkout: Partial<CheckoutRules>, delivery = 'delivery'): PricedCart {
  const out: PricedLine[] = [];
  const issues: string[] = [];
  let subtotal = 0;
  let count = 0;
  let rx = false;
  let orderable = true;

  lines.forEach((l, i) => {
    const p = products[l.product_id];
    if (!p) {
      issues.push('An item in your cart is no longer available and was left out.');
      return;
    }
    let variant: Record<string, any> | null = null;
    if (l.variant_id !== null) {
      variant = (Array.isArray(p.variants) ? p.variants : []).find((v: any) => v && typeof v === 'object' && Number(v.id ?? 0) === l.variant_id) ?? null;
      if (variant === null) {
        issues.push(`The option you chose for ${String(p.name)} is no longer available.`);
        orderable = false;
        return;
      }
    }
    const unit = Number(variant?.price ?? p.price ?? 0);
    const inStock = Boolean(variant?.in_stock ?? p.in_stock ?? true);
    const qty = Math.max(1, Math.min(MAX_QTY, l.qty));
    const stock = variant === null && p.stock !== undefined && p.stock !== null ? Math.floor(Number(p.stock)) : null;
    if (stock !== null && stock > 0 && qty > stock) {
      issues.push(`Only ${stock} of ${String(p.name)} available.`);
      orderable = false;
    }
    if (!inStock || stock === 0) {
      issues.push(`${String(p.name)} is out of stock.`);
      orderable = false;
    }
    const lineTotal = round2(unit * qty);
    subtotal += lineTotal;
    count += qty;
    rx = rx || Boolean(p.prescription_required ?? false);
    out.push({
      index: i,
      product_id: l.product_id,
      variant_id: l.variant_id,
      qty,
      name: String(p.name ?? ''),
      variant: variant !== null ? String(variant.name ?? '') : '',
      unit,
      line_total: lineTotal,
      in_stock: inStock && stock !== 0,
      max: stock ?? MAX_QTY,
      rx: Boolean(p.prescription_required ?? false),
      product: p,
    });
  });

  subtotal = round2(subtotal);
  const ship = shipping(subtotal, checkout, delivery);
  const free = Number(checkout.freeAbove ?? 0);
  return {
    lines: out,
    count,
    subtotal,
    shipping: ship,
    total: round2(subtotal + ship),
    free_left: free > 0 && subtotal < free ? round2(free - subtotal) : 0,
    free_progress: free > 0 ? Math.min(100, Math.floor((subtotal / free) * 100)) : 100,
    issues: [...new Set(issues)],
    rx,
    orderable: orderable && out.length > 0,
  };
}
