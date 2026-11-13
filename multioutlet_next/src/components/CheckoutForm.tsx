'use client';
/**
 * Checkout for the cart's outlet (port of Modules/Checkout/Views/checkout.php + the checkout part of
 * storefront-app.js): contact, delivery or pickup with the optional location helper (window.OmniGeo),
 * prescription confirmation for Rx items, the outlet's payment methods, live totals, and the double-submit
 * guard (the button is disabled while the Server Action runs).
 */
import Link from 'next/link';
import Script from 'next/script';
import { useActionState, useEffect, useState, type ReactNode } from 'react';
import { placeOrder } from '@/app/actions/checkout';
import { refreshHumanCheck } from '@/components/HumanCheck';
import { Icon } from '@/components/Icon';
import { useToast } from '@/components/Toast';
import { emptyForm } from '@/lib/form-errors';
import { type Currency, sfMoney } from '@/lib/format';

type Json = Record<string, any>;

export interface CheckoutFormProps {
  outlet: { id: number; name: string; area: string; phone: string; address: string; pickup: boolean; lat: number | null; lng: number | null };
  currency: Currency;
  methods: string[];
  lines: { index: number; name: string; variant: string; qty: number; line_total: number }[];
  subtotal: number;
  shipDelivery: number;
  rx: boolean;
  /** <ShieldFieldsBlock /> rendered on the server */
  shield: ReactNode;
}

const PAYS: Record<string, [string, string]> = {
  cod: ['Cash on delivery', 'Pay when you receive it'],
  bkash: ['bKash / Nagad', 'Pay now from your wallet, then enter the transaction ID'],
  card: ['Card', 'Visa · Mastercard · Amex — enter the payment reference'],
};

export function CheckoutForm({ outlet, currency, methods, lines, subtotal, shipDelivery, rx, shield }: CheckoutFormProps) {
  const [state, action, pending] = useActionState(placeOrder, emptyForm);
  const toast = useToast();
  const v = (k: string): string => state.values[k] ?? '';
  const err = (k: string): ReactNode => (state.errors[k] ? <small className="f-err">{state.errors[k]}</small> : null);
  const money = (n: number) => sfMoney(n, currency);

  const [delivery, setDelivery] = useState<'delivery' | 'pickup'>('delivery');
  const [payment, setPayment] = useState<string>(methods[0] ?? 'cod');
  const [rxOk, setRxOk] = useState(false);

  // after a failed submit: keep what was chosen, and ask a fresh human-check question
  useEffect(() => {
    if (state.n === 0) return;
    refreshHumanCheck();
    if (state.values.delivery === 'pickup' && outlet.pickup) setDelivery('pickup');
    if (state.values.payment && methods.includes(state.values.payment)) setPayment(state.values.payment);
    setRxOk(state.values.rx_confirm === '1');
  }, [state.n, state.values, methods, outlet.pickup]);

  const pickup = delivery === 'pickup';
  const ship = pickup ? 0 : shipDelivery;
  let n = 0;

  // ---- location helper (omnipos-geo.js) ----
  const [geoReady, setGeoReady] = useState(false);
  const [geo, setGeo] = useState({ lat: '', lng: '', loc_area: '', loc_city: '', loc_source: '' });
  const [geoMsg, setGeoMsg] = useState<string | null>(null);
  const [geoBusy, setGeoBusy] = useState(false);
  const [areaText, setAreaText] = useState('');
  const [geoPick, setGeoPick] = useState('');
  const G = () => (typeof window !== 'undefined' ? ((window as any).OmniGeo as Json | undefined) : undefined);

  const setLoc = (loc: Json | null) => {
    const g = G();
    setGeo({
      lat: loc ? String(loc.lat ?? '') : '',
      lng: loc ? String(loc.lng ?? '') : '',
      loc_area: loc ? String(loc.area ?? '') : '',
      loc_city: loc ? String(loc.city ?? '') : '',
      loc_source: loc ? String(loc.source || 'area') : '',
    });
    if (!loc || !g) {
      setGeoMsg(null);
      return;
    }
    try {
      g.remember(loc);
    } catch {
      /* private mode */
    }
    if (loc.area) setAreaText((t) => (t ? t : String(loc.area)));
    const shop = outlet.lat !== null && outlet.lng !== null ? { lat: outlet.lat, lng: outlet.lng } : null;
    const km = shop ? g.distanceKm(shop, loc) : null;
    setGeoMsg('Delivering to ' + g.label(loc) + (km != null ? ` · ${km} km from the shop` : ''));
  };

  const geoInit = () => {
    const g = G();
    if (!g) return;
    setGeoReady(true);
    let last: Json | null = null;
    try {
      last = g.last();
    } catch {
      last = null;
    }
    if (last) setLoc(last);
  };

  const detect = () => {
    const g = G();
    if (!g) return;
    setGeoBusy(true);
    g.detect().then(
      (loc: Json) => {
        setGeoBusy(false);
        setLoc(loc);
        toast('Location added');
      },
      (e: Error) => {
        setGeoBusy(false);
        toast((e && e.message) || 'Could not get your location', false);
      },
    );
  };

  const geoCities: string[] = geoReady ? (G()?.cities() ?? []) : [];

  return (
    <section className="wrap sec">
      <Script src="/assets/multistore/omnipos-geo.js" strategy="afterInteractive" onReady={geoInit} />
      <nav className="crumbs">
        <Link href="/cart">Cart</Link> / <span>Checkout</span>
      </nav>
      <h1>Checkout</h1>
      <div className="ms-cartnote">
        <Icon name="store" />
        <span>
          Your order goes to <b>{outlet.name}</b>
          {outlet.area ? ` · ${outlet.area}` : ''}
          {outlet.phone ? ` · ${outlet.phone}` : ''}.
        </span>
      </div>
      {(['items', 'outlet_id'] as const).map((k) =>
        state.errors[k] ? (
          <div key={k} className="sf-alert err">
            <Icon name="alert" />
            <span>
              {state.errors[k]} <Link href="/cart">Review cart</Link>
            </span>
          </div>
        ) : null,
      )}
      {state.error && !state.errors.items && !state.errors.outlet_id && (
        <div className="sf-alert err" role="alert">
          <Icon name="alert" />
          <span>{state.error}</span>
        </div>
      )}
      <form className="co" action={action} noValidate key={state.n}>
        {shield}
        <div className="co-main">
          <div className="box">
            <h3>
              <span>{++n}</span>Contact
            </h3>
            <div className="fg">
              <label>
                Full name
                <input name="name" required maxLength={100} autoComplete="name" defaultValue={v('name')} />
                {err('name')}
              </label>
              <label>
                Mobile number
                <input name="phone" required inputMode="tel" autoComplete="tel" maxLength={20} defaultValue={v('phone')} />
                {err('phone')}
              </label>
              <label className="span2">
                Email (optional, for the receipt)
                <input name="email" type="email" autoComplete="email" maxLength={150} defaultValue={v('email')} />
                {err('email')}
              </label>
            </div>
          </div>

          <div className="box">
            <h3>
              <span>{++n}</span>Delivery
            </h3>
            <div className="radios">
              <label className={'radio' + (!pickup ? ' on' : '')}>
                <input type="radio" name="delivery" value="delivery" checked={!pickup} onChange={() => setDelivery('delivery')} />
                <Icon name="truck" />
                <span>
                  <b>Home delivery</b>
                  <small>
                    {shipDelivery > 0 ? money(shipDelivery) : 'Free'} · from {outlet.name}
                  </small>
                </span>
              </label>
              {outlet.pickup && (
                <label className={'radio' + (pickup ? ' on' : '')}>
                  <input type="radio" name="delivery" value="pickup" checked={pickup} onChange={() => setDelivery('pickup')} />
                  <Icon name="store" />
                  <span>
                    <b>Pick up at the outlet</b>
                    <small>Free · {outlet.address || outlet.name}</small>
                  </span>
                </label>
              )}
            </div>
            {err('delivery')}
            <div className="fg" hidden={pickup}>
              <label className="span2">
                Address
                <input name="address" autoComplete="street-address" placeholder="House, road, area" maxLength={220} required={!pickup} defaultValue={v('address')} />
                {err('address')}
              </label>
              <label>
                Area
                <input name="area" maxLength={80} value={areaText || v('area')} onChange={(e) => setAreaText(e.target.value)} />
              </label>
              <div className="geo-row span2" hidden={!geoReady}>
                <button type="button" className="btn-g sm" onClick={detect} disabled={geoBusy}>
                  <Icon name="locate-fixed" />
                  Use my current location
                </button>
                <select
                  aria-label="Choose your area"
                  value={geoPick}
                  onChange={(e) => {
                    setGeoPick(e.target.value);
                    const a = e.target.value ? G()?.byName(e.target.value) : null;
                    if (a) a.source = 'area';
                    setLoc(a ?? null);
                  }}
                >
                  <option value="">Or choose your area…</option>
                  {geoCities.map((c) => (
                    <optgroup key={c} label={c}>
                      {(G()?.areasIn(c) ?? []).map((a: Json) => (
                        <option key={String(a.area)}>{String(a.area)}</option>
                      ))}
                    </optgroup>
                  ))}
                </select>
                <small className="geo-msg">{geoMsg ? <span className="geo-ok">{geoMsg}</span> : <span className="muted">Share your location so the rider finds you faster — optional.</span>}</small>
              </div>
              {(['lat', 'lng', 'loc_area', 'loc_city', 'loc_source'] as const).map((k) => (
                <input key={k} type="hidden" name={k} value={geo[k]} readOnly />
              ))}
              {err('location')}
            </div>
            <div className="fg">
              <label className="span2">
                Note (optional)
                <input name="note" maxLength={500} defaultValue={v('note')} />
                {err('note')}
              </label>
            </div>
          </div>

          {rx && (
            <div className="box rx-box">
              <h3>
                <span>
                  <Icon name="rx" />
                </span>
                Prescription
              </h3>
              <p className="muted" style={{ margin: 0 }}>
                Your cart has prescription medicine. The outlet&apos;s pharmacist checks the prescription before dispatch — keep it ready to show the rider.
              </p>
              <div className="fg">
                <label className="span2">
                  Doctor / prescription details (optional)
                  <input name="rx_note" maxLength={200} defaultValue={v('rx_note')} />
                </label>
              </div>
              <label className={'radio' + (rxOk ? ' on' : '')}>
                <input type="checkbox" name="rx_confirm" value="1" checked={rxOk} onChange={(e) => setRxOk(e.target.checked)} />
                <span>
                  <b>I have a valid prescription for these medicines</b>
                  <small>Rx orders without a prescription are cancelled.</small>
                </span>
              </label>
              {err('rx_confirm')}
            </div>
          )}

          <div className="box">
            <h3>
              <span>{++n}</span>Payment
            </h3>
            <div className="radios">
              {methods.map((m) => (
                <label key={m} className={'radio' + (payment === m ? ' on' : '')}>
                  <input type="radio" name="payment" value={m} checked={payment === m} onChange={() => setPayment(m)} />
                  <span>
                    <b>{PAYS[m]?.[0] ?? m}</b>
                    <small>{PAYS[m]?.[1] ?? ''}</small>
                  </span>
                </label>
              ))}
            </div>
            {err('payment')}
            {(methods.includes('bkash') || methods.includes('card')) && (
              <div className="fg" hidden={payment === 'cod'}>
                <label>
                  Transaction ID
                  <input name="trx_id" maxLength={32} autoComplete="off" defaultValue={v('trx_id')} />
                  {err('trx_id')}
                </label>
                <small className="muted">Only for bKash / Nagad / card. The outlet verifies the payment before dispatch.</small>
              </div>
            )}
            {err('shield')}
          </div>
        </div>
        <aside className="co-sum">
          <h3>Your order</h3>
          {lines.map((l) => (
            <div key={l.index} className="r it">
              <span>
                {l.name}
                {l.variant !== '' && <small> ({l.variant})</small>} × {l.qty}
              </span>
              <b>{money(l.line_total)}</b>
            </div>
          ))}
          <div className="r">
            <span>Subtotal</span>
            <b>{money(subtotal)}</b>
          </div>
          <div className="r">
            <span>Delivery</span>
            <b>{ship > 0 ? money(ship) : 'Free'}</b>
          </div>
          <div className="r tot">
            <span>Total</span>
            <b>{money(subtotal + ship)}</b>
          </div>
          <button className="btn-p full" type="submit" disabled={pending}>
            <Icon name="lock" />
            {pending ? 'Placing order…' : 'Place order'}
          </button>
          <small className="muted">Prices include VAT. {outlet.name} confirms the final total on the next page.</small>
        </aside>
      </form>
    </section>
  );
}
