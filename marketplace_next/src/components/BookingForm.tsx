'use client';
/**
 * Booking form on a service listing (port of Booking/Views/_form.php): days → free times → name & mobile →
 * confirm. Days are links (?date=), so the free times are always fresh from the shop.
 */
import Link from 'next/link';
import { useActionState, useEffect, type ReactNode } from 'react';
import { bookService } from '@/app/actions/booking';
import { refreshHumanCheck } from './HumanCheck';
import { Ic } from './Ic';
import { emptyForm } from '@/lib/form-errors';
import { bookingLabel } from '@/lib/market';
import { phpDate } from '@/lib/format';

type Json = Record<string, any>;

export function BookingForm({ path, businessId, productId, shopName, booking, buyer, token, shield }: { path: string; businessId: number; productId: number; shopName: string; booking: Json; buyer: Json | null; token: string; shield: ReactNode }) {
  const [state, action, pending] = useActionState(bookService, emptyForm);
  useEffect(() => {
    if (state.n > 0) refreshHumanCheck();
  }, [state.n]);
  const v = (k: string, d = ''): string => state.values[k] ?? d;
  const err = (k: string): ReactNode => (state.errors[k] ? <small className="f-err">{state.errors[k]}</small> : null);
  const slots: Json[] | null = Array.isArray(booking.slots) ? booking.slots : null;
  const team: string[] = slots ? [...new Set(slots.flatMap((t) => (Array.isArray(t.staff) ? t.staff.map(String) : [])))] : [];
  const timed = Boolean(booking.timed);

  return (
    <form className="frm" action={action} key={state.n}>
      {state.error && (
        <p className="note warn" role="alert">
          {state.error}
        </p>
      )}
      {shield}
      <input type="hidden" name="token" value={token} />
      <input type="hidden" name="business_id" value={businessId} />
      <input type="hidden" name="product_id" value={productId} />
      <input type="hidden" name="timed" value={timed ? '1' : '0'} />
      <b className="lbl">
        <Ic name="calendar" /> {bookingLabel(String(booking.kind))}
      </b>
      {timed ? (
        <>
          <b className="lbl">Pick a day</b>
          <div className="days">
            {(booking.days as Json[]).map((d) => (
              <Link key={d.value} className={booking.date === d.value ? 'on' : ''} href={`${path}?date=${d.value}#book`} scroll={false}>
                {String(d.label)}
              </Link>
            ))}
          </div>
          <span id="book" />
          {booking.date !== '' ? (
            slots !== null && slots.length === 0 ? (
              <p className="note warn">No free time that day — try another day.</p>
            ) : (
              <>
                <b className="lbl">Pick a time</b>
                <div className="opts">
                  {(slots ?? []).slice(0, 40).map((t) => (
                    <label key={t.starts_at}>
                      <input type="radio" name="starts_at" value={t.starts_at} defaultChecked={v('starts_at') === t.starts_at} required />
                      <span>{phpDate('h:i A', t.starts_at)}</span>
                    </label>
                  ))}
                </div>
                {team.length > 0 && !(team.length === 1 && team[0] === 'Any') && (
                  <label className="fld">
                    <span>Staff (optional)</span>
                    <select className="in" name="staff" defaultValue={v('staff')}>
                      <option value="">Anyone available</option>
                      {team
                        .filter((m) => m !== 'Any')
                        .map((m) => (
                          <option key={m}>{m}</option>
                        ))}
                    </select>
                  </label>
                )}
              </>
            )
          ) : (
            <p className="note">Choose a day to see the free times.</p>
          )}
        </>
      ) : (
        <label className="fld">
          <span>What do you need?</span>
          <textarea className="in" name="note" maxLength={300} placeholder="Size, quantity, deadline …" defaultValue={v('note')} />
        </label>
      )}
      <div className="two">
        <input className="in" name="name" required maxLength={120} placeholder="Your name" autoComplete="name" defaultValue={v('name', String(buyer?.name ?? ''))} />
        <input className="in" name="phone" required maxLength={20} placeholder="Mobile 01XXXXXXXXX" inputMode="tel" autoComplete="tel" defaultValue={v('phone', String(buyer?.phone ?? ''))} />
      </div>
      {err('name')}
      {err('phone')}
      {err('time')}
      {err('staff')}
      {err('shield')}
      {booking.kind === 'pickup' && <input className="in" name="address" maxLength={255} placeholder="Pickup address" autoComplete="street-address" defaultValue={v('address')} />}
      {timed && <input className="in" name="note" maxLength={300} placeholder="Note for the shop (optional)" defaultValue={v('note')} />}
      <button className="btn primary full" type="submit" disabled={pending || (timed && (slots === null || slots.length === 0))}>
        <Ic name="check" />
        {pending ? 'Booking…' : 'Confirm booking'}
      </button>
      <small className="muted">No payment now. Pay at {shopName}.</small>
    </form>
  );
}
