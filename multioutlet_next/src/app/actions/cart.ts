'use server';
/**
 * Cart Server Actions (replace Cart::add / update / remove / clear of the PHP app). Forms post here directly
 * and always end in a redirect (303 → a normal GET), like the PHP controllers.
 */
import { redirect } from 'next/navigation';
import { isApiError } from '@/lib/api/errors';
import { getContext } from '@/lib/context';
import { setFlash } from '@/lib/flash';
import { sameSiteBack } from '@/lib/request';
import { addFrom, cartOutlet, clearCart, readCart, setQty } from '@/lib/services/cart';
import { throttle, TOO_MANY } from '@/lib/shield';

const int = (v: FormDataEntryValue | null, d = 0): number => {
  const n = Math.trunc(Number(typeof v === 'string' ? v : d));
  return Number.isFinite(n) ? n : d;
};

async function limited(back: string): Promise<void> {
  const wait = await throttle('post:cart', 60, 60);
  if (wait > 0) {
    await setFlash({ error: TOO_MANY(wait) });
    redirect(back);
  }
}

/** item_id, outlet_id, variant_id?, qty?, go?="checkout" */
export async function addToCart(fd: FormData): Promise<void> {
  const { api, site, host } = await getContext();
  const back = await sameSiteBack();
  await limited(back);
  const itemId = int(fd.get('item_id'));
  const outletId = int(fd.get('outlet_id'));
  const vid = int(fd.get('variant_id'));
  const qty = int(fd.get('qty'), 1) || 1;
  if (itemId < 1 || outletId < 1) {
    await setFlash({ error: 'Choose the outlet you want to order from.' });
    redirect(back);
  }
  let item;
  try {
    item = await api.product(itemId);
  } catch (e) {
    if (!isApiError(e) || e.status !== 404) throw e;
    await setFlash({ error: 'Sorry, that item is no longer available.' });
    redirect(back);
  }
  const error = await addFrom(host, site, item, outletId, vid > 0 ? vid : null, qty);
  if (error !== null) {
    const current = await cartOutlet(host);
    await setFlash({ error, switchTo: current !== null && current !== outletId ? outletId : undefined });
    redirect(back);
  }
  if (fd.get('go') === 'checkout') redirect('/checkout');
  await setFlash({ ok: `${String(item.name)} added to your cart · ${site.outletName(outletId)}` });
  redirect(back);
}

/** index + d (±1) or qty */
export async function updateCart(fd: FormData): Promise<void> {
  const { host } = await getContext();
  await limited('/cart');
  const index = int(fd.get('index'));
  const line = (await readCart(host)).lines[index];
  if (line) {
    const delta = int(fd.get('d'));
    await setQty(host, index, delta !== 0 ? line.qty + delta : int(fd.get('qty')));
  }
  redirect('/cart');
}

export async function removeFromCart(fd: FormData): Promise<void> {
  const { host } = await getContext();
  await limited('/cart');
  await setQty(host, int(fd.get('index')), 0);
  redirect('/cart');
}

/** Empty the cart — e.g. to order from another outlet (outlet_id = the outlet the visitor wants next). */
export async function clearCartAction(fd: FormData): Promise<void> {
  const { site, host } = await getContext();
  const back = await sameSiteBack();
  await limited(back);
  await clearCart(host);
  const to = int(fd.get('outlet_id'));
  await setFlash({ ok: to > 0 && site.outlet(to) !== null ? `Cart emptied — you can now order from ${site.outletName(to)}.` : 'Your cart is empty.' });
  redirect(back);
}
