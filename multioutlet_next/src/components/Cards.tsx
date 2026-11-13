/**
 * Product card (.card-p of the storefront design) with the outlets that have it, the product grid and the
 * outlet card. Ports of app/Views/partials/{card,grid,outlet_card}.php. Server components.
 */
import Link from 'next/link';
import { addToCart } from '@/app/actions/cart';
import { type Currency, sfMoney, sfUrl } from '@/lib/format';
import { Site, type Outlet } from '@/lib/site';
import { Icon, PThumb, Sym } from './Icon';

type Json = Record<string, any>;
const int = (v: unknown): number => Math.trunc(Number(v ?? 0)) || 0;

export function ProductCard({ p, outlet = 0, cur }: { p: Json; outlet?: number; cur: Currency }) {
  const id = int(p.id);
  const offers: Json[] = (Array.isArray(p.offers) ? p.offers : []).filter((o: unknown) => o && typeof o === 'object');
  const here = outlet > 0 ? (offers.find((o) => int(o.outlet_id) === outlet) ?? null) : null;
  const min = Number(here?.price ?? p.price_min ?? 0);
  const max = Number(here?.price ?? p.price_max ?? 0);
  const inStock = here !== null ? Boolean(here.in_stock) : Boolean(p.in_stock);
  const nIn = int(p.outlets_in_stock);
  const nAll = p.outlets_total !== undefined ? int(p.outlets_total) : offers.length;
  const href = `/p/${id}` + (outlet > 0 ? `?outlet=${outlet}` : '');
  const sub = String(p.generic || p.brand || p.category || '');
  const badge = p.badge ? String(p.badge) : p.featured ? 'Hot' : '';
  const canAdd = here !== null && Boolean(here.in_stock) && !(Array.isArray(here.variants) && here.variants.length > 0);
  const name = String(p.name ?? '');

  return (
    <article className="card-p ms-card">
      <Link href={href} className="cp-img">
        <PThumb p={p} />
      </Link>
      {badge !== '' && <span className="badge hot">{badge}</span>}
      {p.prescription_required ? <span className="badge rx">Rx</span> : null}
      <div className="cp-b">
        <small>{sub}</small>
        <Link href={href} className="cp-n">
          {name}
        </Link>
        <span className={'ms-avail ' + (inStock ? 'ok' : 'out')}>
          <Icon name={inStock ? 'store' : 'x'} />
          {here !== null
            ? (inStock ? 'In stock at ' : 'Out of stock at ') + String(here.outlet)
            : nIn > 0
              ? `In stock at ${nIn} of ${nAll} outlet${nAll === 1 ? '' : 's'}`
              : 'Out of stock everywhere'}
        </span>
        <div className="cp-f">
          <div className="cp-pr">
            <span className="pr">
              {min !== max && (
                <>
                  <small className="per">from</small>{' '}
                </>
              )}
              {sfMoney(min, cur)}
            </span>
            {p.unit ? <small className="per">/ {String(p.unit)}</small> : null}
          </div>
          {canAdd ? (
            <form className="rb-f" action={addToCart}>
              <input type="hidden" name="item_id" value={id} />
              <input type="hidden" name="outlet_id" value={int(here!.outlet_id)} />
              <input type="hidden" name="qty" value="1" />
              <button className="rb" aria-label={`Add ${name} from ${String(here!.outlet)} to cart`}>
                <Icon name="plus" />
              </button>
            </form>
          ) : (
            <Link className="rb" href={href} aria-label={`Choose an outlet for ${name}`}>
              <Icon name="arrow" />
            </Link>
          )}
        </div>
      </div>
    </article>
  );
}

export function Grid({ list, outlet = 0, cur }: { list: Json[]; outlet?: number; cur: Currency }) {
  if (list.length === 0) return <div className="empty-s">No products found. Try another outlet or fewer filters.</div>;
  return (
    <div className="grid">
      {list.map((p) => (
        <ProductCard key={String(p.id)} p={p} outlet={outlet} cur={cur} />
      ))}
    </div>
  );
}

/** One outlet: name, address, hours, phone, map link, products / delivery facts. */
export function OutletCard({ o, cur }: { o: Outlet; cur: Currency }) {
  const addr = Site.address(o);
  const map = Site.mapUrl(o);
  const hours = Site.hours(o);
  const ck = o.checkout && typeof o.checkout === 'object' ? o.checkout : {};
  const id = int(o.id);
  return (
    <article className="ms-outlet">
      <div className="ms-o-h">
        <span className="ms-o-ic">
          <Sym name={String(o.logo_icon ?? 'store')} />
        </span>
        <div>
          <Link href={`/outlets/${id}`}>
            <b>{String(o.name ?? '')}</b>
          </Link>
          <small>{String(o.type_name ?? '')}</small>
        </div>
      </div>
      <ul className="ms-o-facts">
        {addr !== '' && (
          <li>
            <Icon name="pin" />
            <span>{addr}</span>
          </li>
        )}
        {hours !== '' && (
          <li>
            <Icon name="clock" />
            <span>Open {hours}</span>
          </li>
        )}
        {o.phone ? (
          <li>
            <Icon name="phone" />
            <a href={`tel:${String(o.phone)}`}>{String(o.phone)}</a>
          </li>
        ) : null}
        <li>
          <Icon name="truck" />
          <span>
            {Number(ck.delivery ?? 0) > 0 ? `Delivery ${sfMoney(ck.delivery, cur)}` : 'Free delivery'}
            {ck.pickup ? ' · pickup available' : ''}
          </span>
        </li>
      </ul>
      <div className="ms-o-f">
        <Link className="btn-p sm" href={sfUrl('/products', { outlet: String(id) })}>
          <Icon name="shopping-bag" />
          Shop here{o.products !== undefined && o.products !== null ? ` (${int(o.products)})` : ''}
        </Link>
        {map !== '' && (
          <a className="btn-g sm" href={map} target="_blank" rel="noopener">
            <Icon name="map" />
            Map
          </a>
        )}
      </div>
    </article>
  );
}
