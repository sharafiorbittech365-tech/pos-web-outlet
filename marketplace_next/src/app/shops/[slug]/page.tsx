/** Seller page: profile, distance, delivery info, its listings (paginated), link to its storefront (ShopsController::show). */
import Link from 'next/link';
import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import type { CSSProperties } from 'react';
import { Ic, SymIcon } from '@/components/Ic';
import { Card, Pager } from '@/components/Listing';
import { AutoCheck, AutoSelect } from '@/components/MarketClient';
import { isApiError } from '@/lib/api/errors';
import { marketApi } from '@/lib/api/market-api';
import { cssColor, kmStr, mkPrice, mkUrl, plainQuery } from '@/lib/market';

type Props = { params: Promise<{ slug: string }>; searchParams: Promise<Record<string, string | string[] | undefined>> };
const KINDS: [string, string, string][] = [['', 'Everything', 'layers'], ['product', 'Products', 'shopping-bag'], ['service', 'Services', 'calendar']];

async function load(slug: string, q: Record<string, string>) {
  if (!/^[a-z0-9][a-z0-9-]{0,99}$/.test(slug)) notFound();
  try {
    return await (await marketApi()).shop(slug, q);
  } catch (e) {
    if (isApiError(e) && e.status === 404) notFound();
    throw e;
  }
}

export async function generateMetadata({ params, searchParams }: Props): Promise<Metadata> {
  const d = await load((await params).slug, plainQuery(await searchParams));
  return { title: String(d.shop.name) };
}

export default async function ShopPage({ params, searchParams }: Props) {
  const { slug } = await params;
  const q = plainQuery(await searchParams);
  const { shop, filters: f, location: loc, result } = await load(slug, q);
  const path = `/shops/${slug}`;
  const items: any[] = result.items;
  const from = (result.page - 1) * result.per_page + 1;
  const tel = String(shop.phone ?? '').replace(/[^0-9+]/g, '');

  return (
    <div className="wrap">
      <nav className="crumbs">
        <Link href="/">Market</Link>
        <Ic name="chev" />
        <Link href="/shops">Shops</Link>
        <Ic name="chev" />
        <span>{String(shop.name)}</span>
      </nav>
      <div className="shop-head" style={{ '--c': cssColor(shop.color) } as CSSProperties}>
        <span className="sh-ic lg">
          <SymIcon name={shop.logo_icon} />
        </span>
        <div className="grow">
          <h1>{String(shop.name)}</h1>
          <small>
            {String(shop.type_name)} · {String(shop.address || [shop.area, shop.city].filter(Boolean).join(', '))}
          </small>
          {shop.hero !== '' && (
            <p className="desc" style={{ marginTop: 4 }}>
              {String(shop.hero)}
              {shop.sub !== '' ? ` · ${String(shop.sub)}` : ''}
            </p>
          )}
        </div>
      </div>
      <div className="facts">
        {shop.distance_km !== null && (
          <span>
            <Ic name="navigation" />
            {kmStr(shop.distance_km)} away
          </span>
        )}
        {shop.delivery_on && shop.delivery_radius_km !== null ? (
          <span>
            <Ic name="truck" />
            Delivers within {kmStr(shop.delivery_radius_km)}
          </span>
        ) : shop.delivery_on ? (
          <span>
            <Ic name="truck" />
            Home delivery
          </span>
        ) : null}
        {shop.delivery_on && (
          <span>
            <Ic name="receipt" />
            Delivery {shop.delivery_charge > 0 ? mkPrice(shop.delivery_charge, shop.currency) : 'free'}
            {shop.free_above !== null ? ` · free above ${mkPrice(shop.free_above, shop.currency)}` : ''}
          </span>
        )}
        {shop.pickup && (
          <span>
            <Ic name="store" />
            Pickup
          </span>
        )}
        {shop.cod && (
          <span>
            <Ic name="cash" />
            Cash on delivery
          </span>
        )}
        {shop.bkash && (
          <span>
            <Ic name="mobile" />
            bKash
          </span>
        )}
        {shop.takes_bookings && (
          <span>
            <Ic name="calendar" />
            Online booking
          </span>
        )}
        {loc && !shop.delivers_here && (
          <span className="warn">
            <Ic name="alert" />
            {shop.pickup ? 'Pickup only at your location' : 'Does not deliver to your location'}
          </span>
        )}
      </div>
      <div className="row2">
        {shop.storefront_url && (
          <a className="btn primary" href={String(shop.storefront_url)} target="_blank" rel="noopener">
            <Ic name="external" />
            Open the shop&apos;s website
          </a>
        )}
        {tel !== '' && (
          <a className="btn" href={`tel:${tel}`}>
            <Ic name="phone" />
            Call {String(shop.phone)}
          </a>
        )}
      </div>

      <div className="filters">
        <div className="kinds" style={{ marginTop: 6 }}>
          {KINDS.map(([k, l, i]) => (
            <Link key={l} className={f.kind === k ? 'on' : ''} href={mkUrl(path, q, { kind: k, category: null })}>
              <Ic name={i} />
              {l}
            </Link>
          ))}
        </div>
        <form className="sel-row" method="get" action={path} key={JSON.stringify(f)}>
          {f.kind !== '' && <input type="hidden" name="kind" value={f.kind} />}
          <input className="in" name="q" defaultValue={f.q} placeholder="Search this shop" aria-label="Search this shop" style={{ maxWidth: 260, height: 38 }} />
          <AutoSelect name="category" aria-label="Category" defaultValue={f.category}>
            <option value="">All categories</option>
            {(result.categories as string[]).map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </AutoSelect>
          <AutoSelect name="sort" aria-label="Sort" defaultValue={f.sort}>
            <option value="distance">Featured</option>
            <option value="price">Price: low to high</option>
            <option value="-price">Price: high to low</option>
          </AutoSelect>
          <label className="tg">
            <AutoCheck name="stock" value="1" defaultChecked={Boolean(f.in_stock)} />
            In stock only
          </label>
          <button className="btn sm">Search</button>
        </form>
      </div>

      <div className="sh">
        <h2>
          <Ic name="shopping-bag" />
          Listings
        </h2>
        <span className="muted">{result.last_page > 1 ? `Showing ${from}–${from + items.length - 1} of ${result.total}` : `${result.total} found`}</span>
      </div>
      {items.length > 0 ? (
        <>
          <div className="grid">
            {items.map((x) => (
              <Card key={`${x.business_id}:${x.id}`} x={x} />
            ))}
          </div>
          <Pager page={result.page} last={result.last_page} path={path} q={q} />
        </>
      ) : (
        <div className="empty">
          <Ic name="search-x" className="big" />
          <b>No listings match your filters</b>
        </div>
      )}
    </div>
  );
}
