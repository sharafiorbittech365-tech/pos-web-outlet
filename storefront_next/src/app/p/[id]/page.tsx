import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Fragment } from 'react';
import { addToCart } from '@/app/actions/cart';
import { Icon, PThumb } from '@/components/Icon';
import { ProductExtras } from '@/components/ProductExtras';
import { ProductGallery } from '@/components/ProductGallery';
import type { ReviewItem } from '@/components/ProductReviews';
import { ShieldFieldsBlock } from '@/components/ShieldFields';
import { MenuList } from '@/components/MenuList';
import { ProductGrid } from '@/components/ProductCard';
import { QtyStepper } from '@/components/QtyStepper';
import { VariantPicker, type PickerVariant } from '@/components/VariantPicker';
import { WishButton } from '@/components/WishButton';
import { isApiError } from '@/lib/api/errors';
import { type Product } from '@/lib/api/storefront-api';
import { getContext } from '@/lib/context';
import { dur, pImage, pOff, safeImgUrl, strim } from '@/lib/format';
import { pageMeta } from '@/lib/seo';
import { CatalogService } from '@/lib/services/catalog';

type Props = { params: Promise<{ id: string }> };

async function load(id: number): Promise<{ product: Product; related: Product[] }> {
  const { store, api } = await getContext();
  try {
    return await new CatalogService(api, store).product(id);
  } catch (e) {
    if (isApiError(e) && e.status === 404) notFound();
    throw e;
  }
}

const idOf = async (params: Props['params']): Promise<number> => {
  const id = Math.trunc(Number((await params).id));
  if (!Number.isFinite(id) || id < 1) notFound();
  return id;
};

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { store } = await getContext();
  const id = await idOf(params);
  const { product: p } = await load(id);
  const desc = String(p.description ?? '').trim();
  return pageMeta(store, {
    title: String(p.name),
    description: desc !== '' ? strim(desc.replace(/\s+/g, ' '), 160) : `${String(p.name)} — ${store.money(p.price)} at ${store.name()}.`,
    path: '/p/' + id,
    image: pImage(p),
    type: 'product',
  });
}

export default async function ProductPage({ params }: Props) {
  const { store, api } = await getContext();
  const id = await idOf(params);
  const { product: p, related } = await load(id);
  const reviews = (await api.productReviews(id).catch(() => [])) as ReviewItem[];
  const catId = Number(p.category_id ?? 0);
  const variants: Record<string, any>[] = (Array.isArray(p.variants) ? p.variants : []).filter((v: unknown) => v && typeof v === 'object');
  const inStock = Boolean(p.in_stock ?? true);
  const stock = p.stock !== undefined && p.stock !== null ? Math.floor(Number(p.stock)) : null;
  const bookOnly = !store.sells();
  const off = pOff(p);
  const ck = store.checkout();
  const options = p.options;
  const firstAvail = variants.find((v) => v.in_stock)?.id;
  const chosen = Number(firstAvail ?? 0);

  // size x colour picker: one chip row per dimension when every option has its attributes
  let dims: Record<string, string[]> = {};
  for (const v of variants) {
    const attrs = v.attributes && typeof v.attributes === 'object' && !Array.isArray(v.attributes) ? (v.attributes as Record<string, unknown>) : {};
    if (Object.keys(attrs).length === 0) {
      dims = {};
      break;
    }
    for (const [dk, dv] of Object.entries(attrs)) {
      const list = (dims[dk] ??= []);
      if (!list.includes(String(dv))) list.push(String(dv));
    }
  }
  const picker = variants.length > 0 && Object.keys(dims).length > 0 && Object.keys(dims).length <= 3;
  const pickerVariants: PickerVariant[] = variants.map((v) => ({
    id: Number(v.id),
    a: Object.fromEntries(Object.entries((v.attributes ?? {}) as Record<string, unknown>).map(([k, x]) => [k, String(x)])),
    ok: Boolean(v.in_stock),
  }));
  const warranty: string[] = Array.isArray(p.warranty) ? p.warranty : [];
  const max = stock !== null && stock > 0 ? Math.min(99, stock) : 99;
  const catName = store.categoryName(catId);
  const pp = store.productPage();
  const detailed = pp.style === 'detailed' && !bookOnly;
  const items: { url: string; alt: string }[] = (Array.isArray(p.image_items) ? p.image_items : Array.isArray(p.images) ? p.images.map((u: unknown) => ({ url: u, alt: '' })) : [])
    .map((i: Record<string, unknown>) => ({ url: safeImgUrl(i.url), alt: String(i.alt ?? '') }))
    .filter((i: { url: string }) => i.url !== '');
  if (items.length === 0 && pImage(p) !== '') items.push({ url: pImage(p), alt: '' });
  const pics = items.map((i) => i.url);
  const alts = items.map((i) => i.alt);
  const range = (p.price_range ?? null) as { min: number | null; max: number | null; currency: string | null } | null;
  const money = (n: number) => (range?.currency ? `${range.currency} ${n.toLocaleString('en-US')}` : store.money(n));  const phone = pp.callPhone.trim();
  const tel = phone.replace(/[^0-9+]/g, '');
  const wa = pp.whatsapp ? `https://wa.me/${pp.whatsapp}?text=${encodeURIComponent(String(p.name))}` : null;
  const cols = pp.delivery.length > 0 && pp.delivery.length <= 4;
  const brandLine = String(p.generic || p.brand || catName);
  const ld = {
    '@context': 'https://schema.org',
    '@type': 'Product',
    name: String(p.name),
    ...(p.brand ? { brand: { '@type': 'Brand', name: String(p.brand) } } : {}),
    ...(pImage(p) ? { image: pImage(p) } : {}),
    offers: {
      '@type': 'Offer',
      price: String(p.price),
      priceCurrency: store.currency().code,
      availability: inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
    },
  };

  return (
    <section className="wrap sec">
      <nav className="crumbs">
        <Link href="/">Home</Link> / <Link href={store.kind() === 'shop' && catId ? '/c/' + catId : '/'}>{catName || 'Products'}</Link> / <span>{String(p.name)}</span>
      </nav>
      <div className={detailed ? 'pd pd2 pd2-nonotes' : 'pd'}>
        <div className="pd-img">
          {detailed && pics.length > 0 ? (
            <ProductGallery pics={pics} alts={alts} name={String(p.name)} badge={p.badge ? String(p.badge) : null}>
              <WishButton id={id} label="Save to wishlist" />
            </ProductGallery>
          ) : (
            <>
          <PThumb p={p} size="big" />
          {p.badge ? <span className="badge">{String(p.badge)}</span> : null}
          {p.prescription_required ? <span className="badge rx">Rx</span> : null}
          <WishButton id={id} label="Save to wishlist" />
            </>
          )}
        </div>
        <div className={detailed ? 'pd-info pd2-info' : 'pd-info'}>
          {detailed ? (
            <>
              <h1>{String(p.name)}</h1>
              <hr className="pd2-sep" />
              <div className="pd2-meta">
                {pp.showCode && p.code ? (
                  <span>
                    Product Code: <b>{String(p.code)}</b>
                  </span>
                ) : null}
                {pp.showStock && (
                  <span>
                    Availability: {!inStock ? <span className="st out">Out of stock</span> : stock !== null && stock <= 5 ? <span className="st low">Only {stock} left</span> : <span className="st ok">In Stock</span>}
                  </span>
                )}
              </div>
              <div className="pd2-price">
                <span>Price:</span>
                <span className="now">{store.money(p.price)}</span>
                {off > 0 && (
                  <>
                    <s>{store.money(p.compare_at)}</s>
                    <em>-{off}%</em>
                  </>
                )}
                {p.unit ? <small className="muted">per {String(p.unit)}</small> : null}
              </div>
              <hr className="pd2-sep" />
            </>
          ) : (
            <>          <small className="pd-brand">{brandLine}</small>
          <h1>{String(p.name)}</h1>
          <div className="pd-pr">
            <span className="pr">{store.money(p.price)}</span>
            {off > 0 && (
              <>
                <s>{store.money(p.compare_at)}</s>
                <em>-{off}%</em>
              </>
            )}
            {p.unit ? <small>per {String(p.unit)}</small> : null}
            {p.duration_min ? <small>· {dur(Number(p.duration_min))}</small> : null}
          </div>
          {p.tax?.label && p.tax?.rate ? (
            <small className="muted">
              Price includes {String(p.tax.rate)}% {String(p.tax.label)}
            </small>
          ) : null}
          {!bookOnly &&
            (!inStock ? (
              <span className="st out">Out of stock</span>
            ) : stock !== null && stock <= 5 ? (
              <span className="st low">Only {stock} left</span>
            ) : (
              <span className="st ok">Available</span>
            ))}
          {p.prescription_required ? (
            <div className="rx-band sm">
              <Icon name="rx" />
              <span>Prescription medicine — a pharmacist will confirm your prescription before dispatch.</span>
            </div>
          ) : null}
          {p.description ? (
            <p className="pd-desc">
              {String(p.description)
                .split(/\r\n|\r|\n/)
                .map((line, i) => (
                  <Fragment key={i}>
                    {i > 0 && <br />}
                    {line}
                  </Fragment>
                ))}
            </p>
          ) : null}

            </>
          )}
          {(p.short_description || range || p.sample_price != null || p.moq != null) && (
            <div className="px-meta">
              {p.short_description ? <p>{String(p.short_description)}</p> : null}
              {range && (range.min != null || range.max != null) ? (
                <div>
                  <span>Price range</span>
                  <b>
                    {range.min != null ? money(range.min) : ''}
                    {range.min != null && range.max != null ? ' – ' : ''}
                    {range.max != null ? money(range.max) : ''}
                  </b>
                </div>
              ) : null}
              {p.sample_price != null ? (
                <div>
                  <span>Sample price</span>
                  <b>{store.money(Number(p.sample_price))}</b>
                </div>
              ) : null}
              {p.moq != null ? (
                <div>
                  <span>Minimum order</span>
                  <b>{String(p.moq)} pcs</b>
                </div>
              ) : null}
            </div>
          )}
          {bookOnly ? (
            store.canBook() && (
              <div className="buy">
                <Link className="btn-p" href={`/book?service=${id}`}>
                  <Icon name="cal" />
                  {store.cta()}
                </Link>
              </div>
            )
          ) : (
            <form action={addToCart} className="pd-buy" data-scroll-keep>
              <input type="hidden" name="product_id" value={id} />
              {picker && <VariantPicker variants={pickerVariants} dims={dims} initial={chosen} />}
              {!picker && variants.length > 0 && (
                <div className="opt">
                  <b>{Array.isArray(options) && options[0]?.name ? String(options[0].name) : 'Options'}</b>
                  <div>
                    {variants.map((v) => {
                      const ok = Boolean(v.in_stock);
                      const vid = Number(v.id);
                      return (
                        <label key={vid} className={`ob ${chosen === vid ? 'on' : ''} ${ok ? '' : 'na'}`.replace(/\s+/g, ' ').trim()}>
                          <input type="radio" name="variant_id" value={vid} defaultChecked={chosen === vid} disabled={!ok} required />
                          {String(v.name)}
                          {Number(v.price ?? 0) > 0 && Number(v.price) !== Number(p.price) ? <> · {store.money(v.price)}</> : null}
                        </label>
                      );
                    })}
                  </div>
                </div>
              )}
              {detailed ? (
                <>
                  <div className="pd2-qty">
                    <span>Quantity</span>
                    <QtyStepper max={max} />
                  </div>
                  <div className="pd2-btns">
                    <button className="order" name="go" value="checkout" disabled={!inStock}>
                      <Icon name="lock" />
                      {pp.orderLabel}
                    </button>
                    <button className="cart" name="go" value="cart" disabled={!inStock}>
                      <Icon name="lock" />
                      {pp.cartLabel}
                    </button>
                  </div>
                </>
              ) : (
                <div className="buy">
                  <QtyStepper max={max} />
                  <button className="btn-p" name="go" value="cart" disabled={!inStock}>
                    <Icon name="cart" />
                    Add to cart
                  </button>
                  <button className="btn-g" name="go" value="checkout" disabled={!inStock}>
                    Buy now
                  </button>
                </div>
              )}            </form>
          )}
          {detailed && (
            <>
              {pp.callBox && tel !== '' && (
                <div className="pd2-call">
                  <span>{pp.callText}</span>
                  <a href={`tel:${tel}`}>
                    <Icon name="phone" />
                    {phone}
                  </a>
                </div>
              )}
              {(pp.messenger || wa) && (
                <div className="pd2-chat">
                  {pp.messenger && (
                    <a className="msgr" href={pp.messenger} target="_blank" rel="noopener noreferrer">
                      <Icon name="send" />
                      Messenger
                    </a>
                  )}
                  {wa && (
                    <a className="wapp" href={wa} target="_blank" rel="noopener noreferrer">
                      <Icon name="wa" />
                      WhatsApp
                    </a>
                  )}
                </div>
              )}
              {pp.showCategory && catName !== '' && (
                <div className="pd2-box">
                  <span>Categories:</span>
                  <Link className="pd2-cat" href={'/c/' + catId}>
                    {catName}
                  </Link>
                </div>
              )}
              {pp.deliveryTable && pp.delivery.length > 0 && (
                <div className={cols ? 'pd2-del cols' : 'pd2-del'}>
                  <h3>{pp.deliveryTitle}</h3>
                  <table>
                    {cols ? (
                      <>
                        <thead>
                          <tr>
                            {pp.delivery.map((r, i) => (
                              <th key={i}>{r.area}</th>
                            ))}
                          </tr>
                        </thead>
                        <tbody>
                          <tr>
                            {pp.delivery.map((r, i) => (
                              <td key={i}>{store.money(r.charge)}</td>
                            ))}
                          </tr>
                        </tbody>
                      </>
                    ) : (
                      <tbody>
                        {pp.delivery.map((r, i) => (
                          <tr key={i}>
                            <th>{r.area}</th>
                            <td>{store.money(r.charge)}</td>
                          </tr>
                        ))}
                      </tbody>
                    )}
                  </table>
                </div>
              )}
            </>
          )}
          {warranty.length > 0 && (
            <div className="pd-w">
              <b>
                <Icon name="shield" />
                Warranty
              </b>
              <ul>
                {warranty.map((w, i) => (
                  <li key={i}>{String(w)}</li>
                ))}
              </ul>
            </div>
          )}
          {!bookOnly && (
            <ul className="pd-perks">
              <li>
                <Icon name="truck" />
                {ck.freeAbove > 0 ? `Free delivery over ${store.money(ck.freeAbove)}` : 'Home delivery'}
              </li>
              {ck.cod && (
                <li>
                  <Icon name="check" />
                  Cash on delivery
                </li>
              )}
              {ck.pickup && (
                <li>
                  <Icon name="store" />
                  Pick up at {store.branch()}
                </li>
              )}
            </ul>
          )}
          <p className="muted sold">
            {bookOnly ? 'Provided' : 'Sold and delivered'} by <b>{store.name()}</b>
          </p>
        </div>
      </div>
      {detailed && p.description ? (
        <div className="pd2-desc">
          <h2>Description</h2>
          <p className="pd-desc">
            {String(p.description)
              .split(/\r\n|\r|\n/)
              .map((line, i) => (
                <Fragment key={i}>
                  {i > 0 && <br />}
                  {line}
                </Fragment>
              ))}
          </p>
        </div>
      ) : null}
      <ProductExtras p={p} store={store} reviews={reviews} shield={<ShieldFieldsBlock />} />
      {related.length > 0 && (
        <>
          <div className="sec-h" style={{ marginTop: 40 }}>
            <h2>You may also like</h2>
          </div>
          {store.kind() === 'menu' ? <MenuList store={store} list={related} /> : <ProductGrid store={store} list={related} />}
        </>
      )}
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(ld).replace(/</g, '\\u003c').replace(/>/g, '\\u003e').replace(/&/g, '\\u0026') }} />
    </section>
  );
}
