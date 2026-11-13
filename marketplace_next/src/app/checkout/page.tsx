/** Checkout form (CheckoutController::form): an empty cart goes back to the cart page. */
import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { randomBytes } from 'node:crypto';
import { CheckoutForm } from '@/components/CheckoutForm';
import { ShieldFieldsBlock } from '@/components/ShieldFields';
import { marketApi } from '@/lib/api/market-api';
import { getBuyer } from '@/lib/page';
import { getCart, getLocation, locLabel } from '@/lib/visitor';

export const metadata: Metadata = { title: 'Checkout' };

export default async function CheckoutPage() {
  const cart = await getCart();
  if (Object.keys(cart).length === 0) redirect('/cart');
  const [{ groups }, buyer, loc] = await Promise.all([(await marketApi()).quote(cart), getBuyer(), getLocation()]);
  if (groups.length === 0) redirect('/cart');
  return <CheckoutForm groups={groups} buyer={buyer} loc={loc} locLabel={locLabel(loc)} token={randomBytes(16).toString('hex')} shield={<ShieldFieldsBlock />} />;
}
