/** Rich product information under the product: specifications, supplier, packaging & delivery, customization, video, reviews. */
import type { ReactNode } from 'react';
import type { Store } from '@/lib/store';
import { ProductReviews, type ReviewItem } from './ProductReviews';

type Json = Record<string, any>;
const CUSTOM: Record<string, string> = { logo: 'Customized logo', packaging: 'Customized packaging', graphic: 'Graphic customization' };
const SUPPLIER_TYPE: Record<string, string> = { manufacturer: 'Manufacturer', trading: 'Trading company', wholesaler: 'Wholesaler', distributor: 'Distributor', retailer: 'Retailer', other: 'Other' };
const num = (n: unknown) => String(Number(n));

function embed(url: string): string | null {
  const yt = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([A-Za-z0-9_-]{6,15})/);
  if (yt) return `https://www.youtube-nocookie.com/embed/${yt[1]}`;
  const vm = url.match(/vimeo\.com\/(\d+)/);
  return vm ? `https://player.vimeo.com/video/${vm[1]}` : null;
}

export function ProductExtras({ p, store, reviews, shield }: { p: Json; store: Store; reviews: ReviewItem[]; shield: ReactNode }) {
  const attrs: Json[] = Array.isArray(p.attributes) ? p.attributes : [];
  const sup: Json | null = p.supplier && typeof p.supplier === 'object' ? p.supplier : null;
  const pkg: Json | null = p.packaging && typeof p.packaging === 'object' ? p.packaging : null;
  const cust: Json[] = Array.isArray(p.customizations) ? p.customizations : [];
  const video = typeof p.video_url === 'string' ? p.video_url : '';
  const frame = video ? embed(video) : null;
  const rating = (p.rating ?? {}) as Json;
  const size = pkg && (pkg.length || pkg.width || pkg.height) ? [pkg.length, pkg.width, pkg.height].filter((x) => x != null).map(num).join(' × ') + ' ' + String(pkg.dim_unit ?? 'cm') : '';

  return (
    <div className="px">
      {attrs.length > 0 && (
        <section className="px-sec" id="specs">
          <h2>Specifications</h2>
          <table className="px-table">
            <tbody>
              {attrs.map((a, i) => (
                <tr key={i}>
                  <th>{String(a.name)}</th>
                  <td>
                    {String(a.value)}
                    {a.unit && a.type !== 'text' && a.type !== 'select' && a.type !== 'multiselect' && a.type !== 'boolean' ? ' ' + String(a.unit) : ''}
                  </td>
                </tr>
              ))}
              {p.country_origin ? (
                <tr>
                  <th>Country of origin</th>
                  <td>{String(p.country_origin)}</td>
                </tr>
              ) : null}
            </tbody>
          </table>
        </section>
      )}

      {(frame || video) && (
        <section className="px-sec" id="video">
          <h2>Video</h2>
          {frame ? (
            <div className="px-video">
              <iframe src={frame} title="Product video" loading="lazy" allowFullScreen referrerPolicy="strict-origin-when-cross-origin" />
            </div>
          ) : (
            <a className="link" href={video} target="_blank" rel="noopener noreferrer">
              Watch the product video
            </a>
          )}
        </section>
      )}

      {sup && (
        <section className="px-sec" id="supplier">
          <h2>Supplier</h2>
          <div className="px-card">
            <b>{String(sup.company || sup.name || '')}</b>
            {sup.verified ? <em className="px-ok">Verified</em> : null}
            <dl className="px-dl">
              {sup.name && sup.company ? (
                <>
                  <dt>Contact</dt>
                  <dd>{String(sup.name)}</dd>
                </>
              ) : null}
              {sup.type ? (
                <>
                  <dt>Supplier type</dt>
                  <dd>{SUPPLIER_TYPE[String(sup.type)] ?? String(sup.type)}</dd>
                </>
              ) : null}
              {sup.country || sup.location ? (
                <>
                  <dt>Location</dt>
                  <dd>{[sup.location, sup.country].filter(Boolean).join(', ')}</dd>
                </>
              ) : null}
              {sup.years != null ? (
                <>
                  <dt>Experience</dt>
                  <dd>{num(sup.years)} years</dd>
                </>
              ) : null}
              {sup.response ? (
                <>
                  <dt>Response time</dt>
                  <dd>{String(sup.response)}</dd>
                </>
              ) : null}
              {sup.dispatch != null ? (
                <>
                  <dt>On-time dispatch</dt>
                  <dd>{num(sup.dispatch)}%</dd>
                </>
              ) : null}
              {sup.rating != null ? (
                <>
                  <dt>Store rating</dt>
                  <dd>
                    {num(sup.rating)} / 5{sup.reviews != null ? ` (${num(sup.reviews)} reviews)` : ''}
                  </dd>
                </>
              ) : null}
            </dl>
          </div>
        </section>
      )}

      {pkg && (
        <section className="px-sec" id="packaging">
          <h2>Packaging &amp; delivery</h2>
          <table className="px-table">
            <tbody>
              {pkg.unit ? (
                <tr>
                  <th>Selling unit</th>
                  <td>{String(pkg.unit)}</td>
                </tr>
              ) : null}
              {pkg.type ? (
                <tr>
                  <th>Package type</th>
                  <td>{String(pkg.type)}</td>
                </tr>
              ) : null}
              {size ? (
                <tr>
                  <th>Package size</th>
                  <td>{size}</td>
                </tr>
              ) : null}
              {pkg.gross != null ? (
                <tr>
                  <th>Gross weight</th>
                  <td>
                    {num(pkg.gross)} {String(pkg.weight_unit ?? 'kg')}
                  </td>
                </tr>
              ) : null}
              {pkg.net != null ? (
                <tr>
                  <th>Net weight</th>
                  <td>
                    {num(pkg.net)} {String(pkg.weight_unit ?? 'kg')}
                  </td>
                </tr>
              ) : null}
              {pkg.shipping ? (
                <tr>
                  <th>Shipping</th>
                  <td>{String(pkg.shipping)}</td>
                </tr>
              ) : null}
            </tbody>
          </table>
        </section>
      )}

      {cust.length > 0 && (
        <section className="px-sec" id="custom">
          <h2>Customization</h2>
          <div className="px-cust">
            {cust.map((c, i) => (
              <div className="px-card" key={i}>
                <b>{CUSTOM[String(c.type)] ?? String(c.type)}</b>
                <dl className="px-dl">
                  {c.moq != null ? (
                    <>
                      <dt>Minimum order</dt>
                      <dd>{num(c.moq)} pcs</dd>
                    </>
                  ) : null}
                  {c.cost != null ? (
                    <>
                      <dt>Additional cost</dt>
                      <dd>{store.money(Number(c.cost))} / pc</dd>
                    </>
                  ) : null}
                </dl>
                {c.note ? <small className="muted">{String(c.note)}</small> : null}
              </div>
            ))}
          </div>
        </section>
      )}

      <ProductReviews productId={Number(p.id)} reviews={reviews} average={Number(rating.average ?? 0)} count={Number(rating.count ?? 0)} shield={shield} />
    </div>
  );
}
