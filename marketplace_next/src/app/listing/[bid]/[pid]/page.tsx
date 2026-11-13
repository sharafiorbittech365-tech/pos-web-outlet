/**
 * Listing detail (ListingController::show): photo, price as paid (VAT rule), stock, options, warranty,
 * prescription note; add to cart, or the booking form for services.
 */
import Link from 'next/link';
import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { randomBytes } from 'node:crypto';
import type { CSSProperties } from 'react';
import { addToCart } from '@/app/actions/cart';
import { BookingForm } from '@/components/BookingForm';
import { Ic, SymIcon } from '@/components/Ic';
import { QtyStepper } from '@/components/MarketClient';
import { ShieldFieldsBlock } from '@/components/ShieldFields';
import { isApiError } from '@/lib/api/errors';
import { marketApi } from '@/lib/api/market-api';
import { qty } from '@/lib/format';
import { cssColor, kmStr, listingImg, mkPrice } from '@/lib/market';
import { getBuyer } from '@/lib/page';

type Props = { params: Promise<{ bid: string; pid: string }>; searchParams: Promise<Record<string, string | string[] | undefined>> };

async function load(params: Props['params'], date: string) {
  const { bid, pid } = await params;
  if (!/^\d+$/.test(bid) || !/^\d+$/.test(pid)) notFound();
  try {
    const d = await (await marketApi()).listing(Number(bid), Number(pid), date);
    return { bid: Number(bid), pid: Number(pid), item: d.item as Record<string, any>, booking: (d.booking ?? null) as Record<string, any> | null };
  } catch (e) {
    if (isApiError(e) && e.status === 404) notFound();
    throw e;
  }
}

const dateOf = async (sp: Props['searchParams']): Promise<string> => {
  const d = (await sp).date;
  return String((Array.isArray(d) ? d[0] : d) ?? '');
};

export async function generateMetadata({ params, searchParams }: Props): Promise<Metadata> {
  const { item } = await load(params, await dateOf(searchParams));
  return { title: String(item.name) };
}

export default async function ListingPage({ params, searchParams }: Props) {
  const { bid, pid, item: x, booking } = await load(params, await dateOf(searchParams));
  const buyer = await getBuyer();
  const s = x.shop;
  const svc = x.kind === 'service';
  const out = !x.in_stock;
  const cur = x.currency;
  const tel = String(s.phone ?? '').replace(/[^0-9+]/g, '');
  const variants: any[] = Array.isArray(x.variants) ? x.variants : [];
  const firstIn = variants.findIndex((v) => v.in_stock);

  return (
    <div className="wrap page">
      <nav className="crumbs">
        <Link href="/">Market</Link>
        <Ic name="chev" />
        <Link href={`/shops/${s.site_slug}`}>{String(s.name)}</Link>
        <Ic name="chev" />
        <span>{String(x.name)}</span>
      </nav>
      <div className="item-page">
        <div>
          <div className="ph big">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={listingImg(x.image_url)} alt="" />
            <span className={'kind ' + String(x.kind)}>{svc ? 'Service' : 'Product'}</span>
          </div>
          {x.description !== '' && (
            <div className="panel" style={{ marginTop: 14 }}>
              <h2>About</h2>
              <p className="desc">{String(x.description)}</p>
            </div>
          )}
        </div>

        <div className="ib">
          <small>
            {x.category !== '' && x.category ? String(x.category) : String(s.type_name)}
            {x.brand ? ` · ${String(x.brand)}` : ''}
          </small>
          <h1>{String(x.name)}</h1>
          {x.generic_name ? <small>{String(x.generic_name)}</small> : null}
          <div className="price">
            {x.compare_at !== null && <s>{mkPrice(x.compare_at, cur)}</s>}
            {mkPrice(x.price, cur)}
            {x.duration_min ? <small> · {Math.trunc(Number(x.duration_min))} min</small> : null}
            {x.unit && !svc ? <small> / {String(x.unit)}</small> : null}
          </div>
          <div className="facts">
            <span>
              <Ic name="receipt" />
              {String(x.tax_rule?.name ?? '')} {String(x.tax_rule?.label ?? '')}
              {x.tax_rule?.mode === 'exclusive' ? ' (included in the price shown)' : ''}
            </span>
            {!svc && (
              <span className={out ? 'warn' : ''}>
                <Ic name="box" />
                {out ? 'Out of stock' : x.stock !== null ? `${qty(x.stock)} in stock` : 'In stock'}
              </span>
            )}
            {x.warranty_months ? (
              <span>
                <Ic name="shield" />
                {Math.trunc(Number(x.warranty_months))}-month warranty
              </span>
            ) : null}
            {(Array.isArray(x.warranties) ? x.warranties : []).map((w: any, i: number) => (
              <span key={i}>
                <Ic name="shield" />
                {String(w.part)}: {Math.trunc(Number(w.months))} months
              </span>
            ))}
          </div>
          {x.is_rx && (
            <div className="rx">
              <Ic name="rx" />
              <span>
                <b>Prescription medicine.</b> {String(s.name)} will ask to see a valid prescription from a registered doctor before handing it over (at delivery or pickup).
              </span>
            </div>
          )}

          <div className="shopbox" style={{ '--c': cssColor(s.color) } as CSSProperties}>
            <span className="sh-ic">
              <SymIcon name={s.logo_icon} />
            </span>
            <div className="grow">
              <b>{String(s.name)}</b>
              <small>
                {String(s.type_name)} · {String(s.area || s.city || '')}
                {s.distance_km !== null ? ` · ${kmStr(s.distance_km)} away` : ''}
              </small>
            </div>
            <Link className="link" href={`/shops/${s.site_slug}`}>
              Visit shop
            </Link>
          </div>

          {booking !== null ? (
            <BookingForm
              path={`/listing/${bid}/${pid}`}
              businessId={bid}
              productId={pid}
              shopName={String(s.name)}
              booking={booking}
              buyer={buyer}
              token={randomBytes(16).toString('hex')}
              shield={<ShieldFieldsBlock />}
            />
          ) : out ? (
            <p className="note warn">
              Out of stock right now.
              {tel !== '' && (
                <>
                  {' '}
                  <a href={`tel:${tel}`}>Call {String(s.name)}</a> to ask when it is back.
                </>
              )}
            </p>
          ) : svc && !x.orderable ? (
            <a className="btn primary full" href={`tel:${tel}`}>
              <Ic name="phone" />
              Call to book
            </a>
          ) : (
            <form className="frm" action={addToCart}>
              <input type="hidden" name="business_id" value={s.id} />
              <input type="hidden" name="product_id" value={x.id} />
              {variants.length > 0 && (
                <>
                  <b className="lbl">Choose an option</b>
                  <div className="opts">
                    {variants.map((v, i) => (
                      <label key={v.id}>
                        <input type="radio" name="variant_id" value={v.id} disabled={!v.in_stock} defaultChecked={i === firstIn} required />
                        <span>
                          {String(v.name)} · {mkPrice(v.price, cur)}
                        </span>
                      </label>
                    ))}
                  </div>
                </>
              )}
              <QtyStepper max={Math.max(1, Math.trunc(Number(x.max_qty ?? 99)))} />
              {!s.delivers_here && (
                <p className="note warn">
                  {s.delivery_on ? `${String(s.name)} delivers within ${kmStr(s.delivery_radius_km)}${s.distance_km !== null ? ` — you are ${kmStr(s.distance_km)} away` : ''}.` : `${String(s.name)} does not deliver.`}
                  {s.pickup ? ' You can still order and pick it up.' : ' Ordering is not possible at your location.'}
                </p>
              )}
              <div className="row2">
                <button className="btn primary" type="submit">
                  <Ic name="shopping-cart" />
                  Add to cart
                </button>
                <button className="btn" type="submit" name="next" value="checkout">
                  <Ic name="check" />
                  Buy now
                </button>
              </div>
              <small className="muted">
                Delivery{' '}
                {s.delivery_on ? (s.delivery_charge > 0 ? mkPrice(s.delivery_charge, cur) : 'free') + (s.free_above !== null ? `, free above ${mkPrice(s.free_above, cur)}` : '') : 'not offered'}
                {s.cod ? ' · Cash on delivery' : ''}
                {s.bkash ? ' · bKash' : ''}
              </small>
            </form>
          )}
        </div>
      </div>
    </div>
  );
}
