'use server';
/** Checkout::place — POST /checkout. Returns the form state on errors, redirects to the confirmation on success. */
import { redirect } from 'next/navigation';
import { isApiError } from '@/lib/api/errors';
import { getContext } from '@/lib/context';
import { sha } from '@/lib/cookies';
import { rememberOrder } from '@/lib/done';
import { failed, fromApi, type FormState } from '@/lib/form-errors';
import { formToObject } from '@/lib/request';
import { cartCount, cartId, clearCart, priced } from '@/lib/services/cart';
import { CheckoutService } from '@/lib/services/checkout';
import { throttle, TOO_MANY, verifyHuman } from '@/lib/shield';
import { clearTable, getTable } from '@/lib/table-session';

export async function placeOrder(prev: FormState, fd: FormData): Promise<FormState> {
  const ctx = await getContext();
  const { store, api, host } = ctx;
  const input = formToObject(fd);
  const wait = await throttle('post:checkout', 8, 120);
  if (wait > 0) return failed(prev, input, {}, TOO_MANY(wait));
  if (!store.sells() || (await cartCount(host)) === 0) redirect('/cart');

  const table = await getTable(host);
  const delivery = table !== null || input.delivery === 'pickup' ? 'pickup' : 'delivery';
  const cart = await priced(ctx, delivery);
  const svc = new CheckoutService(api, store);
  const errors = svc.validate(input, cart, table);
  if (Object.keys(errors).length > 0) return failed(prev, input, errors, errors.items ?? 'Please check the highlighted fields.');

  const human = await verifyHuman(input);
  if (human !== null) return failed(prev, input, {}, human);

  const payload = svc.payload(input, cart, table);
  // same cart + same details → same key: a double submit or a retry never creates a second order
  const key = (await cartId(host)) + '-' + sha(JSON.stringify(payload), 12);

  let order: Record<string, any>;
  try {
    order = await svc.place(payload, key);
  } catch (e) {
    if (isApiError(e)) {
      if (e.status === 422) {
        // the table's QR was retired or the table removed: order as a normal guest instead
        if (e.details && 'table' in e.details) await clearTable(host);
        return failed(prev, input, fromApi(e.details), e.message);
      }
      if (e.status === 409) {
        return failed(prev, input, {}, e.code === 'out_of_stock' ? 'Sorry — some items just sold out. Please review your cart.' : e.message);
      }
    }
    throw e;
  }

  const ref = String(order.reference ?? '');
  await rememberOrder(ref, {
    items: cart.lines.map((l) => ({ name: l.name + (l.variant !== '' ? ` (${l.variant})` : ''), qty: l.qty, line_total: l.line_total })),
    delivery: payload.delivery,
    payment: payload.payment,
    placed_at: new Date().toISOString(),
    table: table?.name ?? null,
    ...order,
  });
  await clearCart(host);
  redirect('/done/' + encodeURIComponent(ref));
}
