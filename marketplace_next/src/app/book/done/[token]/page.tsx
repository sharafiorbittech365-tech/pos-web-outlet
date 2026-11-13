/** Booking confirmation (BookingController::confirmation). */
import Link from 'next/link';
import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { Ic } from '@/components/Ic';
import { isApiError } from '@/lib/api/errors';
import { marketApi } from '@/lib/api/market-api';
import { datetimeStr } from '@/lib/format';
import { getBuyer } from '@/lib/page';

export const metadata: Metadata = { title: 'Booking received', robots: { index: false } };

export default async function BookingDone({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  if (!/^[a-f0-9]{32}$/.test(token)) redirect('/');
  let b: Record<string, any>;
  try {
    b = await (await marketApi()).booking(token);
  } catch (e) {
    if (isApiError(e) && e.status === 404) redirect('/');
    throw e;
  }
  const buyer = await getBuyer();
  return (
    <div className="wrap page">
      <div className="panel narrow">
        <div className="done">
          <Ic name="circle-check-big" className="big" />
          <h2>You are booked in!</h2>
          <p>
            Reference <b>{String(b.reference)}</b> · {String(b.service)}
            {b.starts_at ? ` · ${datetimeStr(b.starts_at)}` : ''}
            {b.staff ? ` with ${String(b.staff)}` : ''}
          </p>
          <p className="muted">
            {String(b.shop)} will confirm your {b.kind === 'quote' ? 'request' : 'time'}
            {b.shop_phone ? ` — or call ${String(b.shop_phone)}` : ''}.
          </p>
          <div className="row2">
            {buyer !== null && (
              <Link className="btn primary" href="/account/bookings">
                <Ic name="calendar" />
                My bookings
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
