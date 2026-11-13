'use client';
/**
 * Checkout (port of Checkout/Views/checkout.php): buyer details once, then per shop delivery / pickup and
 * payment (from the shop's site checkout settings). Totals are re-priced by the API when the order is placed.
 */
import Link from 'next/link';
import { useActionState, useEffect, type CSSProperties, type ReactNode } from 'react';
import { placeCheckout } from '@/app/actions/checkout';
import { refreshHumanCheck } from './HumanCheck';
import { Ic, SymIcon } from './Ic';
import { LocLink } from './MarketClient';
import { emptyForm } from '@/lib/form-errors';
import { qty } from '@/lib/format';
import { cssColor, kmStr, mkPrice } from '@/lib/market';

type Json = Record<string, any>;

export function CheckoutForm({ groups, buyer, loc, locLabel, token, shield }: { groups: Json[]; buyer: Json | null; loc: Json | null; locLabel: string; token: string; shield: ReactNode }) {
  const [state, action, pending] = useActionState(placeCheckout, emptyForm);
  useEffect(() => {
    if (state.n > 0) refreshHumanCheck();
  }, [state.n]);
  const v = (k: string, d = ''): string => state.values[k] ?? d;
  const err = (k: string): ReactNode => (state.errors[k] ? <small className="f-err">{state.errors[k]}</small> : null);
  const needsAddress = groups.some((g) => g.error === null && Array.isArray(g.options) && g.options.includes('delivery'));
  let grand = 0;
  let ok = 0;

  return (
    <div className="wrap page mid">
      <h1 className="page-h">Checkout</h1>
      {buyer === null && (
        <p className="muted">
          Have an account? <Link href="/login?next=%2Fcheckout">Sign in</Link> to see this order under My orders.
        </p>
      )}
      {state.error && (
        <div className="flash err" role="alert">
          <Ic name="alert" />
          <span>{state.error}</span>
        </div>
      )}
      <form action={action} key={state.n}>
        {shield}
        <input type="hidden" name="token" value={token} />
        <section className="panel">
          <h2>
            <Ic name="user" />
            Your details
          </h2>
          <div className="two">
            <label className="fld">
              <span>Name</span>
              <input className="in" name="name" required maxLength={120} autoComplete="name" defaultValue={v('name', String(buyer?.name ?? ''))} />
              {err('name')}
            </label>
            <label className="fld">
              <span>Mobile</span>
              <input className="in" name="phone" required maxLength={20} inputMode="tel" autoComplete="tel" placeholder="01XXXXXXXXX" defaultValue={v('phone', String(buyer?.phone ?? ''))} />
              {err('phone')}
            </label>
          </div>
          <label className="fld">
            <span>Delivery address{needsAddress ? '' : ' (not needed for pickup)'}</span>
            <input className="in" name="address" maxLength={255} autoComplete="street-address" placeholder={'House, road' + (loc?.area ? `, ${loc.area}` : '')} defaultValue={v('address')} />
            {err('address')}
          </label>
          {loc !== null && (
            <p className="note">
              <Ic name="map-pin" /> Delivering around {locLabel}.{' '}
              <LocLink href="/location?back=%2Fcheckout">Change</LocLink>
            </p>
          )}
          <label className="fld">
            <span>Note for the shops (optional)</span>
            <input className="in" name="note" maxLength={300} defaultValue={v('note')} />
          </label>
          {err('shield')}
        </section>

        {groups.map((g) => {
          const s = g.shop;
          const bid = g.business_id;
          const fine = g.error === null && s !== null;
          if (fine) {
            grand += Number(g.total);
            ok++;
          }
          const pays: string[] = Array.isArray(g.payments) ? g.payments : ['cod'];
          const chosenPay = v(`shop[${bid}][payment]`, pays[0]);
          const chosenFul = v(`shop[${bid}][fulfilment]`, String(g.fulfilment ?? ''));
          const delShip: number | null = g.delivery_shipping ?? null;
          return (
            <section key={bid} className="panel cart-g">
              <header>
                {s !== null && (
                  <span className="sh-ic" style={{ '--c': cssColor(s.color) } as CSSProperties}>
                    <SymIcon name={s.logo_icon} />
                  </span>
                )}
                <div className="grow">
                  <b>{String(s?.name ?? 'Shop')}</b>
                  {s !== null && (
                    <small>
                      {g.lines.length} item{g.lines.length === 1 ? '' : 's'}
                      {s.distance_km !== null ? ` · ${kmStr(s.distance_km)} away` : ''}
                    </small>
                  )}
                </div>
              </header>
              {!fine ? (
                <p className="note warn">{String(g.error ?? 'Not available.')} This shop will be skipped.</p>
              ) : (
                <>
                  {(g.lines as Json[]).map((l) => (
                    <div key={l.key} className="cart-line">
                      <div>
                        {String(l.name)}
                        {l.variant_name ? <small>{String(l.variant_name)}</small> : null}
                      </div>
                      <span className="muted">× {qty(l.qty)}</span>
                      <span className="amt">{mkPrice(l.amount, s.currency)}</span>
                    </div>
                  ))}
                  <b className="lbl">How do you want it?</b>
                  <div className="opts ful">
                    {(g.options as string[]).map((opt) => (
                      <label key={opt}>
                        <input type="radio" name={`shop[${bid}][fulfilment]`} value={opt} defaultChecked={chosenFul === opt} />
                        <span>
                          <Ic name={opt === 'delivery' ? 'truck' : 'store'} />
                          {opt === 'delivery' ? 'Home delivery' : 'Pickup at the shop'}
                        </span>
                      </label>
                    ))}
                  </div>
                  {!s.delivers_here && s.delivery_on && <p className="note warn">{String(s.name)} delivers within {kmStr(s.delivery_radius_km)} — pickup only at your location.</p>}
                  <b className="lbl">Payment</b>
                  <div className="opts">
                    {pays.map((p) => (
                      <label key={p}>
                        <input type="radio" name={`shop[${bid}][payment]`} value={p} defaultChecked={chosenPay === p} />
                        <span>
                          <Ic name={p === 'cod' ? 'cash' : 'mobile'} />
                          {p === 'cod' ? 'Cash on delivery / at pickup' : 'bKash'}
                        </span>
                      </label>
                    ))}
                  </div>
                  {err(`shop[${bid}][payment]`)}
                  {pays.includes('bkash') && (
                    <label className="fld">
                      <span>bKash transaction id (only when paying with bKash{s.phone ? ` to ${String(s.phone)}` : ''})</span>
                      <input className="in" name={`shop[${bid}][trx]`} maxLength={20} placeholder="e.g. 9JK4LM2QX7" defaultValue={v(`shop[${bid}][trx]`)} />
                      {err(`shop[${bid}][trx]`)}
                    </label>
                  )}
                  <div className="sum">
                    <span>Items</span>
                    <b>{mkPrice(g.goods, s.currency)}</b>
                    {delShip !== null && (
                      <>
                        <span>Delivery charge</span>
                        <b>{delShip > 0 ? mkPrice(delShip, s.currency) : 'Free'}</b>
                        <span className="t">Total with delivery</span>
                        <b className="t">{mkPrice(Number(g.goods) + delShip, s.currency)}</b>
                      </>
                    )}
                    {(g.options as string[]).includes('pickup') && (
                      <>
                        <span className={delShip === null ? 't' : ''}>Total with pickup</span>
                        <b className={delShip === null ? 't' : ''}>{mkPrice(g.goods, s.currency)}</b>
                      </>
                    )}
                  </div>
                </>
              )}
            </section>
          );
        })}

        {ok > 0 ? (
          <>
            <div className="grand">
              <span>
                To pay ({ok} {ok === 1 ? 'order' : 'orders'})
              </span>
              <span>{mkPrice(grand)}</span>
            </div>
            <p className="note">Shown with each shop&apos;s first delivery option; choosing pickup removes that delivery charge.</p>
            <button className="btn primary full" type="submit" disabled={pending}>
              <Ic name="check" />
              {pending ? 'Placing…' : `Place ${ok === 1 ? 'order' : `${ok} orders`}`}
            </button>
            <p className="note">Prices, VAT and stock are checked again by each shop when you place the order.</p>
          </>
        ) : (
          <Link className="btn full" href="/cart">
            Back to cart
          </Link>
        )}
      </form>
    </div>
  );
}
