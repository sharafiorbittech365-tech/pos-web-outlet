/** The signed-in buyer's orders across shops (AccountController::orders). */
import Link from 'next/link';
import type { Metadata } from 'next';
import { AccountTabs, requireBuyer } from '@/components/Account';
import { Ic, StatusTag } from '@/components/Ic';
import { Pager } from '@/components/Listing';
import { marketApi } from '@/lib/api/market-api';
import { datetimeStr } from '@/lib/format';
import { mkPrice, plainQuery } from '@/lib/market';

export const metadata: Metadata = { title: 'My orders' };

const SHIP: Record<string, string> = { ordered: 'Ordered', pickup: 'Pickup', shipped: 'On the way', delivered: 'Delivered', cancelled: 'Cancelled', '': '—' };
const ship = (s: unknown): string => SHIP[String(s ?? '')] ?? String(s).charAt(0).toUpperCase() + String(s).slice(1);

export default async function Orders({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  const q = plainQuery(await searchParams);
  const buyer = await requireBuyer('/account/orders');
  const list = await (await marketApi()).myOrders(Math.max(1, Math.trunc(Number(q.page ?? 1)) || 1));
  const rows: any[] = list.rows;
  return (
    <div className="wrap page mid">
      <AccountTabs buyer={buyer} on="orders" />
      {rows.length === 0 ? (
        <div className="empty">
          <Ic name="receipt" className="big" />
          <b>No orders yet</b>
          <span>Orders you place while signed in show up here.</span>
          <Link className="btn primary" href="/">
            Browse the market
          </Link>
        </div>
      ) : (
        <>
          <div className="panel tbl-wrap">
            <table className="tbl">
              <thead>
                <tr>
                  <th>Order</th>
                  <th>Shop</th>
                  <th>Status</th>
                  <th className="r">Total</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((o) => (
                  <tr key={o.id}>
                    <td>
                      <Link href={`/account/orders/${o.id}`}>
                        <b>{String(o.web_ref || o.doc_no)}</b>
                      </Link>
                      <br />
                      <small className="muted">{datetimeStr(o.created_at)}</small>
                    </td>
                    <td>
                      {String(o.shop.name)}
                      <br />
                      <small className="muted">{Math.round(Number(o.item_count))} item(s)</small>
                    </td>
                    <td>
                      <StatusTag status={o.status} />
                      <br />
                      <small className="muted">{ship(o.ship_status)}</small>
                    </td>
                    <td className="r">{mkPrice(o.total, o.shop.currency)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pager page={list.page} last={list.last_page} path="/account/orders" q={q} />
        </>
      )}
    </div>
  );
}
