/** Order confirmation with the API's reference and totals, and the live status (Checkout::done). */
import Link from 'next/link';
import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { Icon } from '@/components/Icon';
import { isApiError } from '@/lib/api/errors';
import { getContext } from '@/lib/context';
import { recallOrder } from '@/lib/done';
import { sfMoney } from '@/lib/format';
import { pageMeta } from '@/lib/seo';

type Props = { params: Promise<{ ref: string }> };
const clean = (ref: string): string => decodeURIComponent(ref).replace(/[^A-Za-z0-9]/g, '').toUpperCase();

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  return pageMeta((await getContext()).site, { title: 'Order ' + clean((await params).ref), noindex: true });
}

export default async function OrderDone({ params }: Props) {
  const ref = clean((await params).ref);
  const { site, api } = await getContext();
  const cur = site.currency();
  const mine = ref !== '' ? await recallOrder(ref) : null;
  let live: Record<string, any> | null = null;
  try {
    if (ref !== '') live = await api.order(ref);
  } catch (e) {
    if (!isApiError(e) || e.status !== 404 || mine === null) {
      if (isApiError(e) && e.status === 404) notFound();
      throw e;
    }
  }
  if (live === null && mine === null) notFound();
  const order = mine ?? live ?? {};

  const status = String(live?.status || order.status || '');
  const ship = String(live?.ship_status ?? '');
  const step = ['delivered', 'picked_up'].includes(ship) || status === 'completed' ? 2 : ['paid', 'confirmed', 'processing', 'open'].includes(status) || ['packed', 'shipped', 'out_for_delivery'].includes(ship) ? 1 : 0;
  const outlet = (live?.outlet || order.outlet || {}) as Record<string, any>;
  const pickup = order.delivery === 'pickup';
  const labels = ['Order received', 'Confirmed by the outlet', pickup ? 'Picked up' : 'Delivered'];
  const items: Record<string, any>[] = Array.isArray(order.items) ? order.items : [];
  const total = Number(order.total ?? 0);

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
          {outlet.name ? (
            <>
              {' — '}
              <b>{String(outlet.name)}</b> will call you to confirm.
            </>
          ) : null}
        </p>
        <div className="steps">
          {labels.map((l, i) => (
            <div key={l} className={i <= step ? 'done' : ''}>
              <i>{i <= step ? <Icon name="check" /> : i + 1}</i>
              <span>{l}</span>
            </div>
          ))}
        </div>
        <div className="co-sum flat">
          {items.map((it, i) => (
            <div key={i} className="r it">
              <span>
                {String(it.name)} × {Number(it.qty)}
              </span>
              <b>{sfMoney(it.line_total, cur)}</b>
            </div>
          ))}
          {order.subtotal !== undefined && (
            <div className="r">
              <span>Subtotal</span>
              <b>{sfMoney(order.subtotal, cur)}</b>
            </div>
          )}
          {Number(order.tax ?? 0) > 0 && (
            <div className="r">
              <span>Includes VAT</span>
              <b>{sfMoney(order.tax, cur)}</b>
            </div>
          )}
          {order.shipping !== undefined && (
            <div className="r">
              <span>Delivery</span>
              <b>{Number(order.shipping) > 0 ? sfMoney(order.shipping, cur) : 'Free'}</b>
            </div>
          )}
          <div className="r tot">
            <span>Total</span>
            <b>{sfMoney(total, cur)}</b>
          </div>
          {order.payment !== undefined && (
            <p className="muted">{status === 'paid' ? 'Paid — thank you.' : order.payment === 'cod' ? `Pay ${sfMoney(total, cur)} when you receive it.` : 'The outlet will verify your payment.'}</p>
          )}
        </div>
        <div className="hero-cta" style={{ justifyContent: 'center' }}>
          <Link className="btn-p" href="/">
            Continue shopping
          </Link>
          {outlet.phone ? (
            <a className="btn-g" href={`tel:${String(outlet.phone)}`}>
              <Icon name="phone" />
              Call the outlet
            </a>
          ) : null}
        </div>
      </div>
    </section>
  );
}
