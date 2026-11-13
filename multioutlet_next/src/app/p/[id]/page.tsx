/**
 * Product page: picture, description, and every outlet that sells it with that outlet's price and stock —
 * the visitor adds it to the cart from the outlet they choose (Catalog::product).
 */
import Link from 'next/link';
import type { Metadata } from 'next';
import { notFound, permanentRedirect } from 'next/navigation';
import { addToCart, clearCartAction } from '@/app/actions/cart';
import { Grid } from '@/components/Cards';
import { Icon, PThumb, Sym } from '@/components/Icon';
import { QtyField } from '@/components/ShellClient';
import { isApiError } from '@/lib/api/errors';
import { getContext } from '@/lib/context';
import { readFlash } from '@/lib/flash';
import { pImage, sfMoney, sfUrl } from '@/lib/format';
import { pageMeta } from '@/lib/seo';
import { cartOutlet } from '@/lib/services/cart';
import { Site } from '@/lib/site';

type Props = { params: Promise<{ id: string }>; searchParams: Promise<Record<string, string | string[] | undefined>> };
type Json = Record<string, any>;
const int = (v: unknown): number => Math.trunc(Number(v ?? 0)) || 0;

async function load(params: Props['params']) {
  const { id } = await params;
  if (!/^\d+$/.test(id)) notFound();
  const ctx = await getContext();
  try {
    return { ...ctx, id: Number(id), item: await ctx.api.product(Number(id)) };
  } catch (e) {
    if (isApiError(e) && e.status === 404) notFound();
    throw e;
  }
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { site, item } = await load(params);
  return pageMeta(site, { title: String(item.name), description: Array.from(String(item.description ?? '').trim()).slice(0, 160).join(''), image: String(item.image ?? ''), path: `/p/${int(item.id)}` });
}

function stockText(o: Json): [string, string] {
  const s = o.stock !== undefined && o.stock !== null ? Math.floor(Number(o.stock)) : null;
  if (!o.in_stock) return ['out', 'Out of stock'];
  if (s !== null && s <= 5) return ['low', `Only ${s} left`];
  return ['ok', 'In stock' + (s !== null ? ` (${s})` : '')];
}

export default async function ProductPage({ params, searchParams }: Props) {
  const { site, api, host, id, item } = await load(params);
  // another outlet's product id of the same item: one canonical address
  if (int(item.id) !== id) permanentRedirect(`/p/${int(item.id)}`);

  const sp = await searchParams;
  const [cart, flash] = await Promise.all([cartOutlet(host), readFlash()]);
  const switchTo = flash.switchTo ?? 0;
  const pref = int(Array.isArray(sp.outlet) ? sp.outlet[0] : sp.outlet);
  const cur = site.currency();
  const offers: Json[] = (Array.isArray(item.offers) ? item.offers : []).filter((o: unknown) => o && typeof o === 'object');
  const min = Number(item.price_min ?? 0);
  const max = Number(item.price_max ?? 0);
  const warranty: string[] = Array.isArray(item.warranty) ? item.warranty.map(String) : [];
  const category = String(item.category ?? '');
  let related: Json[] = [];
  if (category !== '') {
    related = (await api.products({ category: category.trim().toLowerCase(), per_page: 9 })).data.filter((p) => int(p.id) !== id).slice(0, 8);
  }
  const ld = {
    '@context': 'https://schema.org', '@type': 'Product', name: String(item.name), image: pImage(item) || null,
    offers: { '@type': 'AggregateOffer', lowPrice: String(min), highPrice: String(max), priceCurrency: cur.code, offerCount: offers.length,
      availability: item.in_stock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock' },
  };

  const catHref = category !== '' ? sfUrl('/products', { category: category.trim().toLowerCase() }) : '/products';
  const kicker = String(item.generic || item.brand || item.category || '');
  const inStockAt = int(item.outlets_in_stock);
  const description = String(item.description ?? '').trim();

  return (
    <section className="wrap sec pdx">
      <nav className="crumbs">
        <Link href="/">Home</Link> / <Link href={catHref}>{category || 'Products'}</Link> / <span>{String(item.name)}</span>
      </nav>

      <div className="pdx-main">
        <div className="pdx-media">
          <div className="pdx-img">
            <PThumb p={item} size="big" />
            {item.badge ? <span className="badge">{String(item.badge)}</span> : null}
            {item.prescription_required ? <span className="badge rx">Rx</span> : null}
          </div>
        </div>

        <div className="pdx-info">
          <div className="pdx-head">
            {kicker !== '' && <span className="pdx-cat">{kicker}</span>}
            <h1>{String(item.name)}</h1>
            <div className="pdx-price">
              <span className="pr">{min !== max ? `${sfMoney(min, cur)} – ${sfMoney(max, cur)}` : sfMoney(min, cur)}</span>
              {item.unit ? <small>/ {String(item.unit)}</small> : null}
            </div>
            <div className="pdx-meta">
              <span className={'pdx-chip ' + (item.in_stock ? 'ok' : 'out')}>
                <Icon name="store" />
                {item.in_stock ? `In stock at ${inStockAt} of ${offers.length} outlet${offers.length === 1 ? '' : 's'}` : 'Out of stock everywhere'}
              </span>
              <span className="pdx-chip">
                <Icon name="tag" />
                VAT included
              </span>
            </div>
          </div>

          {item.prescription_required ? (
            <div className="rx-band sm">
              <Icon name="rx" />
              <span>Prescription medicine — the outlet&apos;s pharmacist confirms your prescription before dispatch.</span>
            </div>
          ) : null}

          <div className="pdx-buy" id="outlets">
            <div className="pdx-buy-h">
              <h2>Choose an outlet</h2>
              <small>Price and stock can differ by outlet</small>
            </div>
            {cart !== null && (switchTo > 0 || offers.length > 1) && (
              <div className="ms-cartnote">
                <Icon name="info" />
                <span>
                  Your cart is from <b>{site.outletName(cart)}</b>. Each order is prepared by one outlet — to order from another outlet, finish that order or empty your cart first.
                </span>
                {switchTo > 0 && switchTo !== cart && (
                  <form action={clearCartAction} className="rb-f">
                    <input type="hidden" name="outlet_id" value={switchTo} />
                    <button className="btn-g sm" type="submit">
                      Empty cart and order from {site.outletName(switchTo)}
                    </button>
                  </form>
                )}
              </div>
            )}
            <div className="pdx-offers">
              {offers.map((o) => {
                const oid = int(o.outlet_id);
                const [cls, label] = stockText(o);
                const variants: Json[] = (Array.isArray(o.variants) ? o.variants : []).filter((v: unknown) => v && typeof v === 'object');
                const outlet = site.outlet(oid) ?? {};
                const smax = o.stock !== undefined && o.stock !== null ? Math.max(1, Math.min(99, Math.floor(Number(o.stock)))) : 99;
                const hours = Site.hours(outlet);
                const address = Site.address(outlet) || String(o.area ?? '');
                return (
                  <div key={oid} className={'pdx-offer' + (pref === oid || cart === oid ? ' on' : '') + (o.in_stock ? '' : ' off')} id={`o${oid}`}>
                    <div className="pdx-of-top">
                      <span className="pdx-of-ic" aria-hidden="true">
                        <Sym name={String(outlet.logo_icon ?? 'store')} />
                      </span>
                      <div className="pdx-of-o">
                        <Link href={`/outlets/${oid}`}>{String(o.outlet)}</Link>
                        {address !== '' && (
                          <small>
                            <Icon name="map-pin" />
                            {address}
                          </small>
                        )}
                        {hours !== '' && (
                          <small>
                            <Icon name="clock" />
                            Open {hours}
                          </small>
                        )}
                      </div>
                      <div className="pdx-of-p">
                        <span className="pr">{sfMoney(o.price, cur)}</span>
                        {o.compare_at && Number(o.compare_at) > Number(o.price) ? <s>{sfMoney(o.compare_at, cur)}</s> : null}
                        <span className={'pdx-st ' + cls}>{label}</span>
                      </div>
                    </div>
                    <form className="pdx-of-buy" action={addToCart}>
                      <input type="hidden" name="item_id" value={int(item.id)} />
                      <input type="hidden" name="outlet_id" value={oid} />
                      {variants.length > 0 && (
                        <select name="variant_id" required aria-label={`Option at ${String(o.outlet)}`} defaultValue="">
                          <option value="">Choose an option…</option>
                          {variants.map((v) => (
                            <option key={int(v.id)} value={int(v.id)} disabled={!v.in_stock}>
                              {String(v.name)} · {sfMoney(v.price ?? o.price, cur)}
                              {!v.in_stock ? ' (out of stock)' : ''}
                            </option>
                          ))}
                        </select>
                      )}
                      <QtyField max={smax} />
                      <button className="btn-p sm" type="submit" disabled={!o.in_stock} data-other-outlet={cart !== null && cart !== oid ? '' : undefined}>
                        <Icon name="cart" />
                        {o.in_stock ? 'Add to cart' : 'Unavailable'}
                      </button>
                    </form>
                  </div>
                );
              })}
            </div>
          </div>

          <ul className="pdx-perks">
            <li>
              <span><Icon name="truck" /></span>
              <div><b>Delivery or pickup</b><small>As the outlet offers</small></div>
            </li>
            <li>
              <span><Icon name="store" /></span>
              <div><b>One outlet</b><small>Prepares your order</small></div>
            </li>
            <li>
              <span><Icon name="tag" /></span>
              <div><b>VAT included</b><small>In every price shown</small></div>
            </li>
          </ul>
        </div>
      </div>

      {(description !== '' || warranty.length > 0) && (
        <div className={'pdx-details' + (description !== '' && warranty.length > 0 ? ' two' : '')}>
          {description !== '' && (
            <div className="pdx-card">
              <h2>About this product</h2>
              <p>{description}</p>
            </div>
          )}
          {warranty.length > 0 && (
            <div className="pdx-card pdx-war">
              <h2>
                <Icon name="shield" />
                Warranty
              </h2>
              <ul>
                {warranty.map((w, i) => (
                  <li key={i}>
                    <Icon name="check" />
                    {w}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}

      {related.length > 0 && (
        <div className="pdx-rel">
          <div className="sec-h">
            <h2>You may also like</h2>
            <Link className="link" href={catHref}>
              See all
            </Link>
          </div>
          <Grid list={related} cur={cur} />
        </div>
      )}
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(ld).replace(/</g, '\\u003c') }} />
    </section>
  );
}
