/** Confirmation: each shop's order reference, or why a shop could not take the order (CheckoutController::confirmation). */
import Link from 'next/link';
import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { Ic } from '@/components/Ic';
import { isApiError } from '@/lib/api/errors';
import { marketApi } from '@/lib/api/market-api';
import { mkPrice } from '@/lib/market';
import { getBuyer } from '@/lib/page';

export const metadata: Metadata = { title: 'Order placed', robots: { index: false } };

export default async function CheckoutDone({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  if (!/^[a-f0-9]{32}$/.test(token)) redirect('/cart');
  let results: any[];
  try {
    results = (await (await marketApi()).checkoutResult(token)).results;
  } catch (e) {
    if (isApiError(e) && e.status === 404) redirect('/cart');
    throw e;
  }
  const buyer = await getBuyer();
  const placed = results.filter((r) => r.ok);
  return (
    <div className="wrap page">
      <div className="panel mid">
        <div className="done">
          {placed.length > 0 ? (
            <>
              <Ic name="circle-check-big" className="big" />
              <h2>{placed.length === 1 ? 'Order placed!' : `${placed.length} orders placed!`}</h2>
              <p className="muted">Each shop will call you to confirm. Keep the reference to track your order.</p>
            </>
          ) : (
            <>
              <Ic name="alert" className="big" />
              <h2>Your order could not be placed</h2>
            </>
          )}
          <div className="done-list">
            {results.map((r) =>
              r.ok ? (
                <div key={r.business_id} className="done-row">
                  <Ic name="receipt" />
                  <div className="grow">
                    <b>{String(r.shop)}</b>
                    <small>
                      Reference {String(r.reference)} · Invoice {String(r.doc_no)} · {r.fulfilment === 'pickup' ? 'Pickup' : 'Delivery'}
                      {r.phone ? ` · ${String(r.phone)}` : ''}
                    </small>
                  </div>
                  <b>{mkPrice(r.total, r.currency)}</b>
                </div>
              ) : (
                <div key={r.business_id} className="done-row fail">
                  <Ic name="alert" />
                  <div className="grow">
                    <b>{String(r.shop)}</b>
                    <small>{String(r.error)} It is still in your cart.</small>
                  </div>
                </div>
              ),
            )}
          </div>
          <div className="row2">
            {buyer !== null ? (
              <Link className="btn primary" href="/account/orders">
                <Ic name="orders" />
                My orders
              </Link>
            ) : (
              <Link className="btn primary" href="/signup">
                <Ic name="userplus" />
                Create an account
              </Link>
            )}
            {placed.length < results.length && (
              <Link className="btn" href="/cart">
                Back to cart
              </Link>
            )}
            <Link className="btn" href="/">
              Keep browsing
            </Link>
          </div>
        </div>
      </div>
    </div>
  );
}
