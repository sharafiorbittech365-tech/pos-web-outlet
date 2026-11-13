'use server';
/**
 * Cart Server Actions (replaces Cart::add / update / remove). Forms post here directly:
 *   <form action={addToCart}> with product_id, variant_id?, qty?, go?="checkout", stay?
 * They always end in a redirect back to the page the visitor came from (303 → a normal GET).
 */
import { redirect } from 'next/navigation';
import { isApiError } from '@/lib/api/errors';
import { getContext } from '@/lib/context';
import { setFlash } from '@/lib/flash';
import { sameSiteBack } from '@/lib/request';
import { throttle, TOO_MANY } from '@/lib/shield';
import { addProduct, cartLines, removeLine, setQty } from '@/lib/services/cart';

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

export async function addToCart(fd: FormData): Promise<void> {
  const { store, api, host } = await getContext();
  const back = await sameSiteBack();
  await limited(back);
  if (!store.sells()) redirect(back);

  const pid = int(fd.get('product_id'));
  const vid = int(fd.get('variant_id'));
  const qty = int(fd.get('qty'), 1) || 1;
  if (pid < 1) redirect(back);

  let product;
  try {
    product = await api.product(pid);
  } catch (e) {
    if (!isApiError(e) || e.status !== 404) throw e;
    await setFlash({ error: 'Sorry, that item is no longer available.' });
    redirect(back);
  }
  const error = await addProduct(host, product, vid > 0 ? vid : null, qty);
  if (error !== null) {
    await setFlash({ error });
    redirect(back);
  }
  if (fd.get('go') === 'checkout') redirect('/checkout');
  await setFlash({ ok: `${String(product.name)} added to your cart`, added: !fd.get('stay') });
  redirect(back);
}

export async function updateCart(fd: FormData): Promise<void> {
  const { api, host } = await getContext();
  const back = fd.get('stay') ? await sameSiteBack() : '/cart';
  await limited(back);

  const index = int(fd.get('index'));
  const lines = await cartLines(host);
  const line = lines[index];
  if (line) {
    const delta = int(fd.get('d'));
    const qty = delta !== 0 ? line.qty + delta : int(fd.get('qty'));
    if (delta > 0) {
      try {
        const p = await api.product(line.product_id);
        const stock = line.variant_id === null && p.stock !== undefined && p.stock !== null ? Math.floor(Number(p.stock)) : null;
        if (stock !== null && qty > stock) {
          await setFlash({ error: `Only ${stock} of ${String(p.name)} available.` });
          redirect('/cart');
        }
      } catch (e) {
        if (!isApiError(e) || e.status !== 404) throw e;
      }
    }
    await setQty(host, index, qty);
  }
  redirect(back);
}

export async function removeFromCart(fd: FormData): Promise<void> {
  const { host } = await getContext();
  const back = fd.get('stay') ? await sameSiteBack() : '/cart';
  await limited(back);
  await removeLine(host, int(fd.get('index')));
  redirect(back);
}
