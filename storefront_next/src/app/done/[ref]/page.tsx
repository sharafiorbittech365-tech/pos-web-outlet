import type { Metadata } from 'next';
import Link from 'next/link';
import { redirect } from 'next/navigation';
import { Icon } from '@/components/Icon';
import { isApiError } from '@/lib/api/errors';
import { type Json } from '@/lib/api/storefront-api';
import { getContext } from '@/lib/context';
import { recallOrder } from '@/lib/done';
import { pageMeta } from '@/lib/seo';
import { step as trackStep } from '@/lib/services/track';

export async function generateMetadata(): Promise<Metadata> {
  const { store } = await getContext();
  return pageMeta(store, { title: 'Order placed', noindex: true });
}

export default async function DonePage({ params }: { params: Promise<{ ref: string }> }) {
  const { store, api } = await getContext();
  let { ref } = await params;
  try {
    ref = decodeURIComponent(ref);
  } catch {
    /* keep raw */
  }
  const order = await recallOrder(ref);
  if (order === null) redirect('/track/' + encodeURIComponent(ref));

  let live: Json | null = null;
  try {
    live = await api.order(ref);
  } catch (e) {
    if (!isApiError(e) || (e.status !== 404 && e.status < 500 && e.status !== 0)) throw e;
  }

  const step = trackStep(live ?? order);
  const atTable = order.delivery === 'table';
  const labels = ['Order received', store.kind() === 'menu' ? 'In the kitchen' : 'Confirmed in store', atTable ? 'Served' : order.delivery === 'pickup' ? 'Picked up' : 'Delivered'];
  const items: Json[] = Array.isArray(order.items) ? order.items : [];
  const total = order.total ?? 0;

  return (
    <section className="wrap sec narrow">
      <div className="ok-card">
        <span className="ok-ic">
          <Icon name="check" />
        </span>
        <h1>Thank you! Your order is placed.</h1>
        <p>
          Order reference <b>{ref}</b>
          {order.number ? ` · No. ${String(order.number)}` : ''}
          {atTable ? (
            <>
              {' — sent to the kitchen for '}
              <b>{String(order.table ?? 'your table')}</b>.
            </>
          ) : (
            ' — we will call you to confirm.'
          )}
        </p>
        <div className="steps">
          {labels.map((l, i) => (
            <div className={i <= step ? 'done' : ''} key={i}>
              <i>{i <= step ? <Icon name="check" /> : i + 1}</i>
              <span>{l}</span>
            </div>
          ))}
        </div>
        <div className="co-sum flat">
          {items.map((it, i) => (
            <div className="r it" key={i}>
              <span>
                {String(it.name)} × {Math.trunc(Number(it.qty))}
              </span>
              <b>{store.money(it.line_total)}</b>
            </div>
          ))}
          <div className="r">
            <span>Subtotal</span>
            <b>{store.money(order.subtotal ?? 0)}</b>
          </div>
          {Number(order.tax ?? 0) > 0 && (
            <div className="r">
              <span>Includes {String(store.business.tax?.label ?? 'VAT')}</span>
              <b>{store.money(order.tax)}</b>
            </div>
          )}
          {!atTable && (
            <div className="r">
              <span>Delivery</span>
              <b>{Number(order.shipping ?? 0) > 0 ? store.money(order.shipping) : 'Free'}</b>
            </div>
          )}
          <div className="r tot">
            <span>Total</span>
            <b>{store.money(total)}</b>
          </div>
          <p className="muted">
            {order.status === 'paid'
              ? 'Paid — thank you.'
              : order.payment === 'cod'
                ? `Pay ${store.money(total)}${atTable ? ' at the table.' : ' when you receive it.'}`
                : 'The shop will verify your payment.'}
          </p>
        </div>
        <div className="hero-cta" style={{ justifyContent: 'center' }}>
          <Link className="btn-p" href="/">
            {atTable ? 'Order more' : 'Continue'}
          </Link>
          <Link className="btn-g" href={`/track/${encodeURIComponent(ref)}`}>
            Track order
          </Link>
        </div>
      </div>
    </section>
  );
}
