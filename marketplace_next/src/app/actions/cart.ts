'use server';
/**
 * Cart Server Actions (CartController::add / update / removeShop). The cart cookie holds ids and
 * quantities only; an item is checked by the API (listed shop, online, option, quantity) before it is
 * added, and every view is re-priced by the API.
 */
import { redirect } from 'next/navigation';
import { isApiError } from '@/lib/api/errors';
import { marketApi } from '@/lib/api/market-api';
import { setFlash } from '@/lib/flash';
import { sameSiteBack } from '@/lib/request';
import { throttle, TOO_MANY } from '@/lib/shield';
import { cartLines, getCart, MAX_LINES, MAX_QTY, saveCart } from '@/lib/visitor';

const str = (fd: FormData, k: string): string => String(fd.get(k) ?? '').trim();

async function limited(back: string): Promise<void> {
  const wait = await throttle('post:cart', 60, 60);
  if (wait > 0) {
    await setFlash({ error: TOO_MANY(wait) });
    redirect(back);
  }
}

/** business_id, product_id, variant_id?, qty, next?="checkout" */
export async function addToCart(fd: FormData): Promise<void> {
  const back = await sameSiteBack();
  await limited(back);
  let line;
  try {
    line = await (await marketApi()).checkItem({ business_id: str(fd, 'business_id'), product_id: str(fd, 'product_id'), variant_id: str(fd, 'variant_id'), qty: str(fd, 'qty') || '1' });
  } catch (e) {
    if (!isApiError(e) || ![404, 409, 422].includes(e.status)) throw e;
    const first = Object.values(e.details ?? {})[0];
    await setFlash({ error: e.status === 422 && e.message === 'Please check the highlighted fields.' && first ? String(first) : e.message });
    redirect(back);
  }
  const cart = await getCart();
  const bid = String(line.business_id);
  if (!cart[bid]?.[line.key] && cartLines(cart) >= MAX_LINES) {
    await setFlash({ error: 'Your cart is full. Check out first, then add more.' });
    redirect(back);
  }
  (cart[bid] ??= {})[line.key] = Math.min(MAX_QTY, (cart[bid][line.key] ?? 0) + line.qty);
  await saveCart(cart);
  if (str(fd, 'next') === 'checkout') redirect('/checkout');
  await setFlash({ success: 'Added to your cart.' });
  redirect(back);
}

/** business_id, key ("pid:vid"), qty (0 removes) */
export async function updateCart(fd: FormData): Promise<void> {
  await limited('/cart');
  const cart = await getCart();
  const bid = str(fd, 'business_id');
  const key = str(fd, 'key');
  const qty = Number(str(fd, 'qty'));
  if (cart[bid]?.[key] !== undefined && Number.isFinite(qty)) {
    if (qty <= 0) delete cart[bid][key];
    else cart[bid][key] = Math.min(MAX_QTY, Math.round(qty * 1000) / 1000);
    await saveCart(cart);
  }
  redirect('/cart');
}

export async function removeShop(fd: FormData): Promise<void> {
  await limited('/cart');
  const cart = await getCart();
  delete cart[str(fd, 'business_id')];
  await saveCart(cart);
  await setFlash({ success: 'Removed from your cart.' });
  redirect('/cart');
}
