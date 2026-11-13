/** The signed-in buyer's bookings across shops (AccountController::bookings). */
import Link from 'next/link';
import type { Metadata } from 'next';
import { AccountTabs, requireBuyer } from '@/components/Account';
import { Ic, StatusTag } from '@/components/Ic';
import { marketApi } from '@/lib/api/market-api';
import { datetimeStr } from '@/lib/format';
import { bookingLabel } from '@/lib/market';

export const metadata: Metadata = { title: 'My bookings' };

export default async function Bookings() {
  const buyer = await requireBuyer('/account/bookings');
  const list = await (await marketApi()).myBookings();
  return (
    <div className="wrap page mid">
      <AccountTabs buyer={buyer} on="bookings" />
      {list.length === 0 ? (
        <div className="empty">
          <Ic name="calendar" className="big" />
          <b>No bookings yet</b>
          <span>Book a service from a shop near you — it shows up here.</span>
          <Link className="btn primary" href="/?kind=service">
            Find services
          </Link>
        </div>
      ) : (
        <div className="panel tbl-wrap">
          <table className="tbl">
            <thead>
              <tr>
                <th>Booking</th>
                <th>Shop</th>
                <th>When</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {list.map((b) => (
                <tr key={b.id}>
                  <td>
                    <b>{String(b.reference)}</b>
                    <br />
                    <small className="muted">{String(b.service ?? bookingLabel(String(b.kind)))}</small>
                  </td>
                  <td>
                    {String(b.shop.name)}
                    {b.staff_name ? (
                      <>
                        <br />
                        <small className="muted">{String(b.staff_name)}</small>
                      </>
                    ) : null}
                  </td>
                  <td>{b.starts_at ? datetimeStr(b.starts_at) : <span className="muted">To be agreed</span>}</td>
                  <td>
                    <StatusTag status={b.status} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
