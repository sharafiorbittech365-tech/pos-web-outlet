'use server';
/**
 * Checkout (CheckoutController::place): human check → POST /v1/market/checkout with the one-time token of
 * the form (a resubmit returns the same result instead of ordering twice) → the shops that were placed
 * leave the cart → the confirmation page.
 */
import { redirect } from 'next/navigation';
import { isApiError } from '@/lib/api/errors';
import { marketApi } from '@/lib/api/market-api';
import { failed, type FormState } from '@/lib/form-errors';
import { formToObject } from '@/lib/request';
import { throttle, TOO_MANY, verifyHuman } from '@/lib/shield';
import { getCart, saveCart } from '@/lib/visitor';

export async function placeCheckout(prev: FormState, fd: FormData): Promise<FormState> {
  const v = formToObject(fd);
  const wait = await throttle('post:checkout', 8, 120);
  if (wait > 0) return failed(prev, v, {}, TOO_MANY(wait));
  const errors: Record<string, string> = {};
  if (Array.from((v.name ?? '').trim()).length < 2) errors.name = 'Please enter your name.';
  if ((v.phone ?? '').trim() === '') errors.phone = 'Use a mobile number like 01712345678.';
  if (Object.keys(errors).length > 0) return failed(prev, v, errors);
  const human = await verifyHuman(v);
  if (human !== null) return failed(prev, v, { shield: human }, human);

  // shop[<id>][fulfilment|payment|trx] → {id: {…}}
  const shops: Record<string, Record<string, string>> = {};
  for (const [k, val] of Object.entries(v)) {
    const m = /^shop\[(\d+)\]\[(fulfilment|payment|trx)\]$/.exec(k);
    if (m) (shops[m[1]] ??= {})[m[2]] = val;
  }
  const cart = await getCart();
  let results: Record<string, any>[];
  try {
    const r = await (await marketApi()).checkout({ token: v.token, name: v.name, phone: v.phone, address: v.address ?? '', note: v.note ?? '', shops, cart });
    results = r.results;
  } catch (e) {
    if (isApiError(e) && [409, 422].includes(e.status)) {
      const fields: Record<string, string> = {};
      for (const [k, msg] of Object.entries(e.details ?? {})) fields[k.replace(/^shop\.(\d+)\.(\w+)$/, 'shop[$1][$2]')] = String(msg);
      return failed(prev, v, fields, e.message);
    }
    throw e;
  }
  for (const r of results) if (r.ok) delete cart[String(r.business_id)];
  await saveCart(cart);
  redirect('/checkout/done/' + encodeURIComponent(String(v.token)));
}
