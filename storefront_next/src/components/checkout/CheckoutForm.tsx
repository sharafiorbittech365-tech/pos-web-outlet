'use client';
/**
 * Checkout form (Views/checkout.php + the checkout parts of storefront-app.js and checkout-places.js):
 * delivery / payment toggling, live shipping + total, cascading Country > City > Area pickers,
 * the location helper (window.OmniGeo) and the double-submit guard (the button is disabled while pending).
 */
import Link from 'next/link';
import Script from 'next/script';
import { useActionState, useEffect, useRef, useState, type ReactNode } from 'react';
import { placeOrder } from '@/app/actions/checkout';
import { Icon } from '@/components/Icon';
import { refreshHumanCheck } from '@/components/HumanCheck';
import { useToast } from '@/components/Toast';
import { emptyForm } from '@/lib/form-errors';
import { sfMoney } from '@/lib/format';
import type { StoreClient } from '@/lib/store';

type Json = Record<string, any>;
interface Line {
  name: string;
  variant: string;
  qty: number;
  line_total: number;
}
interface Places {
  countries: Json[];
  country: string;
  cities: Json[];
  areas: Json[];
}
export interface CheckoutFormProps {
  store: StoreClient;
  branch: string;
  table: { name: string; area: string | null } | null;
  lines: Line[];
  subtotal: number;
  rx: boolean;
  shipDelivery: number;
  places: Places;
  /** <ShieldFieldsBlock /> rendered on the server */
  shield: ReactNode;
}

export function CheckoutForm({ store, branch, table, lines, subtotal, rx, shipDelivery, places, shield }: CheckoutFormProps) {
  const [state, action, pending] = useActionState(placeOrder, emptyForm);
  const toast = useToast();
  const ck = store.checkout;
  const atTable = table !== null;
  const food = store.kind === 'menu';
  const methods = store.paymentMethods;
  const money = (n: number) => sfMoney(n, store.currency);
  const v = (k: string): string => state.values[k] ?? '';
  const err = (k: string): ReactNode => (state.errors[k] ? <small className="f-err">{state.errors[k]}</small> : null);

  const [delivery, setDelivery] = useState<'delivery' | 'pickup'>('delivery');
  const [payment, setPayment] = useState<string>(methods[0] ?? 'cod');
  const [rxOk, setRxOk] = useState(false);

  // after a failed submit: fresh human-check question
  useEffect(() => {
    if (state.n > 0) refreshHumanCheck();
  }, [state.n]);

  const pickup = delivery === 'pickup';
  const ship = pickup || atTable ? 0 : shipDelivery;
  const pays: Record<string, [string, string]> = {
    cod: atTable ? ['Pay at the table', 'Cash, card or mobile pay when you are done'] : [food ? 'Cash on delivery / at pickup' : 'Cash on delivery', 'Pay when you receive it'],
    bkash: ['bKash / Nagad', 'Pay now from your wallet, then enter the transaction ID'],
    card: ['Card', 'Visa · Mastercard · Amex — enter the payment reference'],
  };
  let n = 0;

  // ---- address pickers ----
  const pickers = !atTable && places.countries.length > 0;
  const [country, setCountry] = useState(places.country);
  const [cities, setCities] = useState<Json[]>(places.cities);
  const [areas, setAreas] = useState<Json[]>(places.areas);
  const [cityId, setCityId] = useState('');
  const [areaId, setAreaId] = useState('');
  const [cityLoading, setCityLoading] = useState(false);
  const [areaEnabled, setAreaEnabled] = useState(places.areas.length > 0);
  const cache = useRef<Record<string, Json[]>>({});
  const pc = places.countries.find((c) => c.code === country);
  const cityLabel = String(pc?.city_label ?? 'City / district');
  const areaLabel = String(pc?.area_label ?? 'Area / thana');

  const load = async (url: string): Promise<Json[]> => {
    if (cache.current[url]) return cache.current[url];
    try {
      const r = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const j = r.ok ? await r.json() : { data: [] };
      cache.current[url] = Array.isArray(j.data) ? j.data : [];
    } catch {
      return [];
    }
    return cache.current[url];
  };

  const onCountry = async (code: string) => {
    setCountry(code);
    setCityId('');
    setAreaId('');
    setAreas([]);
    setAreaEnabled(false);
    if (!code) {
      setCities([]);
      return;
    }
    setCityLoading(true);
    const list = await load('/locations/cities?country=' + encodeURIComponent(code));
    setCities(list);
    setCityLoading(false);
  };

  const onCity = async (id: string) => {
    setCityId(id);
    setAreaId('');
    if (!id) {
      setAreas([]);
      setAreaEnabled(false);
      return;
    }
    setAreaEnabled(false);
    const list = await load('/locations/areas?city=' + encodeURIComponent(id));
    setAreas(list);
    setAreaEnabled(true); // "Not listed" is always possible
  };

  // first view: a preselected country (one dataset) without its cities yet
  useEffect(() => {
    if (pickers && places.country && places.cities.length === 0) void onCountry(places.country);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const hideFree = pickers && areaId !== '' && areaId !== 'other';

  // ---- location helper (omnipos-geo.js) ----
  const [geoReady, setGeoReady] = useState(false);
  const [geo, setGeo] = useState({ lat: '', lng: '', loc_area: '', loc_city: '', loc_source: '' });
  const [geoPick, setGeoPick] = useState('');
  const [geoMsg, setGeoMsg] = useState<string | null>(null);
  const [geoBusy, setGeoBusy] = useState(false);
  const [areaText, setAreaText] = useState('');
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
    const shop = store.location;
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

  const geoCities: string[] = geoReady ? G()?.cities() ?? [] : [];

  return (
    <section className="wrap sec">
      <Script src="/assets/storefront/omnipos-geo.js" strategy="afterInteractive" onReady={geoInit} />
      <nav className="crumbs">
        <Link href="/cart">Cart</Link> / <span>Checkout</span>
      </nav>
      <h1>Checkout</h1>
      {state.errors.items && (
        <div className="sf-alert err">
          <Icon name="alert" />
          <span>
            {state.errors.items} <Link href="/cart">Review cart</Link>
          </span>
        </div>
      )}
      {state.error && state.error !== state.errors.items && (
        <div className="sf-alert err" role="alert">
          <Icon name="alert" />
          <span>{state.error}</span>
        </div>
      )}
      <form className="co" action={action} noValidate>
        <div className="co-main">
          {atTable && (
            <div className="table-box">
              <Icon name="utensils" />
              <span>
                Ordering at <b>{table.name}</b>
                {table.area ? ' · ' + table.area : ''}
                <br />
                <small className="muted">
                  Your order goes straight to the kitchen. <a href="/t-leave">Not at this table?</a>
                </small>
              </span>
            </div>
          )}
          <div className="box">
            <h3>
              <span>{++n}</span>Contact{atTable && <> <small className="muted">(optional)</small></>}
            </h3>
            <div className="fg">
              <label>
                Full name
                <input key={'name' + state.n} name="name" required={!atTable} maxLength={100} autoComplete="name" defaultValue={v('name')} />
                {err('name')}
              </label>
              <label>
                Mobile number
                <input key={'phone' + state.n} name="phone" required={!atTable} inputMode="tel" autoComplete="tel" placeholder="01XXXXXXXXX" maxLength={20} defaultValue={v('phone')} />
                {err('phone')}
              </label>
              <label className="span2">
                Email (optional, for the receipt)
                <input key={'email' + state.n} name="email" type="email" autoComplete="email" maxLength={150} defaultValue={v('email')} />
                {err('email')}
              </label>
            </div>
          </div>

          {atTable ? (
            <div className="box">
              <div className="fg">
                <label className="span2">
                  Note for the kitchen (optional)
                  <input key={'note' + state.n} name="note" maxLength={500} placeholder="e.g. less spicy, no onion" defaultValue={v('note')} />
                  {err('note')}
                </label>
              </div>
            </div>
          ) : (
            <div className="box">
              <h3>
                <span>{++n}</span>
                {food ? 'Delivery or pickup' : 'Delivery'}
              </h3>
              <div className="radios">
                <label className={'radio' + (delivery === 'delivery' ? ' on' : '')}>
                  <input type="radio" name="delivery" value="delivery" checked={delivery === 'delivery'} onChange={() => setDelivery('delivery')} />
                  <Icon name="truck" />
                  <span>
                    <b>{food ? 'Deliver to me' : 'Home delivery'}</b>
                    <small>
                      {shipDelivery > 0 ? money(shipDelivery) : 'Free'} · {food ? '35–45 min' : '1–2 days'}
                    </small>
                  </span>
                </label>
                {ck.pickup && (
                  <label className={'radio' + (delivery === 'pickup' ? ' on' : '')}>
                    <input type="radio" name="delivery" value="pickup" checked={delivery === 'pickup'} onChange={() => setDelivery('pickup')} />
                    <Icon name="store" />
                    <span>
                      <b>{food ? 'I will pick it up' : 'Store pickup'}</b>
                      <small>Free · {food ? 'ready in ~25 min' : branch}</small>
                    </span>
                  </label>
                )}
              </div>
              {err('delivery')}
              <div className="fg" data-when-delivery hidden={pickup}>
                <label className="span2">
                  Address
                  <input key={'address' + state.n} name="address" autoComplete="street-address" placeholder="House, road, area" maxLength={300} required={!pickup} defaultValue={v('address')} />
                  {err('address')}
                </label>
                {pickers && (
                  <>
                    <label>
                      Country
                      <select name="country_code" autoComplete="country" value={country} onChange={(e) => void onCountry(e.target.value)}>
                        {(places.countries.length > 1 || country === '') && <option value="">Choose country…</option>}
                        {places.countries.map((c) => (
                          <option key={String(c.code)} value={String(c.code)}>
                            {String(c.name)}
                          </option>
                        ))}
                        <option value="">Other country (type the address)</option>
                      </select>
                      {err('country_code')}
                    </label>
                    <label>
                      <span>{cityLabel}</span>
                      <select name="city_id" value={cityId} disabled={cities.length === 0 || cityLoading} onChange={(e) => void onCity(e.target.value)}>
                        <option value="">Choose…</option>
                        {cities.map((c) => (
                          <option key={String(c.id)} value={String(c.id)}>
                            {String(c.name)}
                            {c.region ? ` (${String(c.region)})` : ''}
                          </option>
                        ))}
                      </select>
                      {err('city_id')}
                    </label>
                    <label>
                      <span>{areaLabel}</span>
                      <select name="area_id" value={areaId} disabled={!areaEnabled} onChange={(e) => setAreaId(e.target.value)}>
                        <option value="">Choose…</option>
                        {areas.map((a) => (
                          <option key={String(a.id)} value={String(a.id)}>
                            {String(a.name)}
                            {a.postal_code ? ` · ${String(a.postal_code)}` : ''}
                          </option>
                        ))}
                        <option value="other">Not listed — I will type it</option>
                      </select>
                      {err('area_id')}
                    </label>
                  </>
                )}
                <label style={hideFree ? { display: 'none' } : undefined}>
                  {pickers ? 'Area / landmark' : 'Area / thana'}
                  <input name="area" placeholder="e.g. Mirpur 10" maxLength={80} value={areaText} onChange={(e) => setAreaText(e.target.value)} />
                </label>
                <div className="geo-row span2" hidden={!geoReady}>
                  <button type="button" className="btn-g sm" disabled={geoBusy} onClick={detect}>
                    <Icon name="locate-fixed" />
                    Use my current location
                  </button>
                  <select
                    aria-label="Choose your area"
                    value={geoPick}
                    onChange={(e) => {
                      const g = G();
                      const name = e.target.value;
                      setGeoPick(name);
                      const a = name && g ? g.byName(name) : null;
                      if (a) a.source = 'area';
                      setLoc(a);
                    }}
                  >
                    <option value="">Or choose your area…</option>
                    {geoCities.map((c) => (
                      <optgroup label={c} key={c}>
                        {(G()?.areasIn(c) ?? []).map((a: Json) => (
                          <option key={String(a.area)}>{String(a.area)}</option>
                        ))}
                      </optgroup>
                    ))}
                  </select>
                  <small className="geo-msg">
                    {geoMsg ? <span className="geo-ok">{geoMsg}</span> : <span className="muted">Share your location so the rider finds you faster — optional.</span>}
                  </small>
                  <input type="hidden" name="lat" value={geo.lat} />
                  <input type="hidden" name="lng" value={geo.lng} />
                  <input type="hidden" name="loc_area" value={geo.loc_area} />
                  <input type="hidden" name="loc_city" value={geo.loc_city} />
                  <input type="hidden" name="loc_source" value={geo.loc_source} />
                </div>
              </div>
              <div className="fg">
                <label className="span2">
                  {food ? 'Note for the kitchen or rider (optional)' : 'Note (optional)'}
                  <input key={'note' + state.n} name="note" maxLength={500} defaultValue={v('note')} />
                  {err('note')}
                </label>
              </div>
            </div>
          )}

          {rx && (() => {
            n++;
            return (
              <div className="box rx-box">
                <h3>
                  <span>
                    <Icon name="rx" />
                  </span>
                  Prescription
                </h3>
                <p className="muted" style={{ margin: 0 }}>
                  Your cart has prescription medicine. A pharmacist checks the prescription before dispatch — please keep it ready to show the rider, or send a photo when the shop calls to confirm.
                </p>
                <div className="fg">
                  <label className="span2">
                    Doctor / prescription details (optional)
                    <input key={'rx' + state.n} name="rx_note" maxLength={200} placeholder="e.g. Dr. Rahman, 12 Oct" defaultValue={v('rx_note')} />
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
            );
          })()}

          <div className="box">
            <h3>
              <span>{++n}</span>Payment
            </h3>
            <div className="radios">
              {methods.map((m) => (
                <label className={'radio' + (payment === m ? ' on' : '')} key={m}>
                  <input type="radio" name="payment" value={m} checked={payment === m} onChange={() => setPayment(m)} />
                  <span>
                    <b>{pays[m][0]}</b>
                    <small>{pays[m][1]}</small>
                  </span>
                </label>
              ))}
            </div>
            {err('payment')}
            {(methods.includes('bkash') || methods.includes('card')) && (
              <div className="fg" data-when-paid hidden={payment === 'cod'}>
                <label>
                  Transaction ID
                  <input key={'trx' + state.n} name="trx_id" maxLength={32} placeholder="e.g. 9JH2K7L1QW" autoComplete="off" defaultValue={v('trx_id')} />
                  {err('trx_id')}
                </label>
                <small className="muted">Only for bKash / Nagad / card. The shop verifies the payment before dispatch.</small>
              </div>
            )}
          </div>
        </div>
        <aside className="co-sum">
          <h3>Your order</h3>
          {lines.map((l, i) => (
            <div className="r it" key={i}>
              <span>
                {l.name}
                {l.variant !== '' && (
                  <>
                    {' '}
                    <small>({l.variant})</small>
                  </>
                )}{' '}
                × {l.qty}
              </span>
              <b>{money(l.line_total)}</b>
            </div>
          ))}
          <div className="r">
            <span>Subtotal</span>
            <b>{money(subtotal)}</b>
          </div>
          {!atTable && (
            <div className="r">
              <span>Delivery</span>
              <b>{ship > 0 ? money(ship) : 'Free'}</b>
            </div>
          )}
          <div className="r tot">
            <span>Total</span>
            <b>{money(subtotal + ship)}</b>
          </div>
          {shield}
          <button className="btn-p full" type="submit" disabled={pending}>
            <Icon name="lock" />
            {atTable ? 'Send order to the kitchen' : 'Place order'}
          </button>
          <small className="muted">
            Prices include VAT. The shop confirms the final total on the next page. By placing the order you agree to the <Link href="/policy">delivery &amp; return policy</Link>.
          </small>
        </aside>
      </form>
    </section>
  );
}
