'use server';
/**
 * Checkout Server Action (replaces Checkout::place): validate → human check → POST /v1/multisite/orders with
 * the cart id as Idempotency-Key → remember the order for the confirmation page → empty the cart.
 * API 422 maps onto the form fields; 409 out_of_stock sends the visitor back to the cart.
 */
import { redirect } from 'next/navigation';
import { isApiError } from '@/lib/api/errors';
import { getContext } from '@/lib/context';
import { rememberOrder } from '@/lib/done';
import { setFlash } from '@/lib/flash';
import { failed, fromApi, type FormState } from '@/lib/form-errors';
import { formToObject } from '@/lib/request';
import { cartId, clearCart, priced } from '@/lib/services/cart';
import { payload, validate } from '@/lib/services/checkout';
import { throttle, TOO_MANY, verifyHuman } from '@/lib/shield';
import { Site } from '@/lib/site';

export async function placeOrder(prev: FormState, fd: FormData): Promise<FormState> {
  const values = formToObject(fd);
  const wait = await throttle('post:checkout', 8, 120);
  if (wait > 0) return failed(prev, values, {}, TOO_MANY(wait));

  const ctx = await getContext();
  const delivery = values.delivery === 'pickup' ? 'pickup' : 'delivery';
  const cart = await priced(ctx, delivery);
  if (cart === null) redirect('/cart');

  const errors = validate(values, cart, Site.paymentMethods(cart.outlet ?? {}));
  if (Object.keys(errors).length > 0) return failed(prev, values, errors);
  const human = await verifyHuman(values);
  if (human !== null) return failed(prev, values, { shield: human }, human);

  const body = payload(values, cart);
  let order: Record<string, any>;
  try {
    order = await ctx.api.createOrder(body, await cartId(ctx.host));
  } catch (e) {
    if (!isApiError(e)) throw e;
    if (e.status === 409 && e.code === 'out_of_stock') {
      await setFlash({ error: `${e.message} Please review your cart.` });
      redirect('/cart');
    }
    if (e.status === 422 || e.status === 409) return failed(prev, values, fromApi(e.details), e.message);
    throw e;
  }

  const ref = String(order.reference ?? '');
  await rememberOrder(ref, {
    ...order,
    // kept in a cookie: short names, first lines only (the totals come from the API's answer)
    items: cart.lines.slice(0, 12).map((l) => ({ name: Array.from(l.name + (l.variant !== '' ? ` (${l.variant})` : '')).slice(0, 60).join(''), qty: l.qty, line_total: l.line_total })),
    payment: body.payment,
    delivery: body.delivery,
    outlet_id: body.outlet_id,
  });
  await clearCart(ctx.host);
  redirect('/order/' + encodeURIComponent(ref));
}
