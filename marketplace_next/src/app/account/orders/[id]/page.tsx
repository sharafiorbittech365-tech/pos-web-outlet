/** One of the buyer's orders with its lines (AccountController::order). */
import Link from 'next/link';
import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { requireBuyer } from '@/components/Account';
import { Ic, StatusTag, Tag } from '@/components/Ic';
import { isApiError } from '@/lib/api/errors';
import { marketApi } from '@/lib/api/market-api';
import { datetimeStr, qty } from '@/lib/format';
import { mkPrice } from '@/lib/market';

export const metadata: Metadata = { title: 'Order' };

const ucfirst = (s: unknown): string => String(s).charAt(0).toUpperCase() + String(s).slice(1);

export default async function OrderPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  if (!/^\d+$/.test(id)) notFound();
  await requireBuyer(`/account/orders/${id}`);
  let o: Record<string, any>;
  try {
    o = await (await marketApi()).myOrder(Number(id));
  } catch (e) {
    if (isApiError(e) && e.status === 404) notFound();
    throw e;
  }
  const cur = o.shop.currency;
  const ref = String(o.web_ref || o.doc_no);
  const tel = String(o.shop.phone ?? '').replace(/[^0-9+]/g, '');
  return (
    <div className="wrap page mid">
      <nav className="crumbs">
        <Link href="/account/orders">My orders</Link>
        <Ic name="chev" />
        <span>{ref}</span>
      </nav>
      <section className="panel">
        <h2>
          <Ic name="receipt" />
          {String(o.shop.name)} · {ref}
        </h2>
        <dl className="kv">
          <dt>Placed</dt>
          <dd>{datetimeStr(o.created_at)}</dd>
          <dt>Invoice</dt>
          <dd>{String(o.doc_no)}</dd>
          <dt>Status</dt>
          <dd>
            <StatusTag status={o.status} /> {o.ship_status ? <Tag text={ucfirst(o.ship_status)} /> : null}
          </dd>
          {o.ship_address ? (
            <>
              <dt>Deliver to</dt>
              <dd>{String(o.ship_address)}</dd>
            </>
          ) : null}
          {o.shop.phone ? (
            <>
              <dt>Shop phone</dt>
              <dd>
                <a href={`tel:${tel}`}>{String(o.shop.phone)}</a>
              </dd>
            </>
          ) : null}
        </dl>
      </section>
      <section className="panel tbl-wrap">
        <table className="tbl">
          <thead>
            <tr>
              <th>Item</th>
              <th className="r">Qty</th>
              <th className="r">Amount</th>
            </tr>
          </thead>
          <tbody>
            {(o.items as any[]).map((i, n) => (
              <tr key={n}>
                <td>
                  {String(i.name)}
                  {i.variant_name ? (
                    <>
                      <br />
                      <small className="muted">{String(i.variant_name)}</small>
                    </>
                  ) : null}
                  {i.serial ? (
                    <>
                      <br />
                      <small className="muted">IMEI/SN {String(i.serial)}</small>
                    </>
                  ) : null}
                </td>
                <td className="r">{qty(i.qty)}</td>
                <td className="r">{mkPrice(i.line_total, cur)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <div className="sum" style={{ marginTop: 12 }}>
          <span>Subtotal</span>
          <b>{mkPrice(o.subtotal, cur)}</b>
          {Number(o.discount_total) > 0 && (
            <>
              <span>Discount</span>
              <b>-{mkPrice(o.discount_total, cur)}</b>
            </>
          )}
          {Number(o.tax_added) > 0 && (
            <>
              <span>VAT added</span>
              <b>{mkPrice(o.tax_added, cur)}</b>
            </>
          )}
          <span>Delivery</span>
          <b>{Number(o.shipping) > 0 ? mkPrice(o.shipping, cur) : 'Free'}</b>
          <span className="t">Total</span>
          <b className="t">{mkPrice(o.total, cur)}</b>
          <span>Paid</span>
          <b>{mkPrice(o.paid_total, cur)}</b>
          {Number(o.due_total) > 0 && (
            <>
              <span>To pay</span>
              <b>{mkPrice(o.due_total, cur)}</b>
            </>
          )}
        </div>
      </section>
    </div>
  );
}
