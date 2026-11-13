import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { CheckoutForm } from '@/components/checkout/CheckoutForm';
import { ShieldFieldsBlock } from '@/components/ShieldFields';
import { getContext } from '@/lib/context';
import { pageMeta } from '@/lib/seo';
import { shipping } from '@/lib/services/calculator';
import { cartCount, priced } from '@/lib/services/cart';
import { CheckoutService, NO_PLACES } from '@/lib/services/checkout';
import { getTable } from '@/lib/table-session';

export async function generateMetadata(): Promise<Metadata> {
  const { store } = await getContext();
  return pageMeta(store, { title: 'Checkout', noindex: true });
}

export default async function CheckoutPage() {
  const ctx = await getContext();
  const { store, api, host } = ctx;
  if (!store.sells() || (await cartCount(host)) === 0) redirect('/cart');

  const table = await getTable(host);
  // at a table there is no delivery charge (priced as pickup)
  const cart = await priced(ctx, table !== null ? 'pickup' : 'delivery');
  const shipDelivery = shipping(cart.subtotal, store.checkout(), 'delivery');
  const places = table !== null ? NO_PLACES : await new CheckoutService(api, store).places();

  return (
    <CheckoutForm
      store={store.toClient()}
      branch={store.branch()}
      table={table !== null ? { name: table.name, area: table.area } : null}
      lines={cart.lines.map((l) => ({ name: l.name, variant: l.variant, qty: l.qty, line_total: l.line_total }))}
      subtotal={cart.subtotal}
      rx={cart.rx}
      shipDelivery={shipDelivery}
      places={places}
      shield={<ShieldFieldsBlock />}
    />
  );
}
