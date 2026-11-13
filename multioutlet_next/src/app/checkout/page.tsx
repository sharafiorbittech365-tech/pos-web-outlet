/** Checkout for the cart's outlet (Checkout::index): empty or unavailable carts go back to the cart page. */
import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { CheckoutForm } from '@/components/CheckoutForm';
import { ShieldFieldsBlock } from '@/components/ShieldFields';
import { getContext } from '@/lib/context';
import { pageMeta } from '@/lib/seo';
import { priced } from '@/lib/services/cart';
import { Site } from '@/lib/site';

export async function generateMetadata(): Promise<Metadata> {
  return pageMeta((await getContext()).site, { title: 'Checkout', noindex: true });
}

const coord = (v: unknown): number | null => (v === null || v === undefined || v === '' || !Number.isFinite(Number(v)) ? null : Number(v));

export default async function CheckoutPage() {
  const ctx = await getContext();
  const cart = await priced(ctx);
  // the PHP app flashed "Some items … are not available any more" here; the cart page lists the exact issues
  if (cart === null || !cart.orderable || cart.outlet === null) redirect('/cart');
  const o = cart.outlet;

  return (
    <CheckoutForm
      outlet={{
        id: Math.trunc(Number(o.id)),
        name: String(o.name ?? ''),
        area: String(o.area ?? ''),
        phone: String(o.phone ?? ''),
        address: Site.address(o),
        pickup: Boolean(o.checkout?.pickup),
        lat: coord(o.lat),
        lng: coord(o.lng),
      }}
      currency={ctx.site.currency()}
      methods={Site.paymentMethods(o)}
      lines={cart.lines.map((l) => ({ index: l.index, name: l.name, variant: l.variant, qty: l.qty, line_total: l.line_total }))}
      subtotal={cart.subtotal}
      shipDelivery={cart.ship_delivery}
      rx={cart.rx}
      shield={<ShieldFieldsBlock />}
    />
  );
}
