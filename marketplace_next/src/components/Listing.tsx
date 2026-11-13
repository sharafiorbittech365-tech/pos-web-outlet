/** Listing card, shop tile and pager (ports of Discovery/Views/_card, _shop_tile, _pager). Server components. */
import Link from 'next/link';
import type { CSSProperties } from 'react';
import { qty } from '@/lib/format';
import { cssColor, kmStr, listingImg, mkPrice, mkUrl, type Q } from '@/lib/market';
import { Ic, SymIcon } from './Ic';

type Json = Record<string, any>;

export function Card({ x }: { x: Json }) {
  const out = !x.in_stock;
  const low = x.stock !== null && x.stock !== undefined && Number(x.stock) > 0 && Number(x.stock) <= 5;
  const stockText = out ? 'Out of stock' : low ? `Only ${qty(x.stock)} left` : x.kind === 'service' ? 'Available' : 'In stock';
  return (
    <Link className={'card' + (out ? ' out' : '')} href={`/listing/${x.business_id}/${x.id}`}>
      <div className="ph">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={listingImg(x.image_url)} alt="" loading="lazy" />
        <span className={'kind ' + String(x.kind)}>{x.kind === 'service' ? 'Service' : 'Product'}</span>
        {x.distance_km !== null && x.distance_km !== undefined && (
          <span className="dist">
            <Ic name="navigation" />
            {kmStr(x.distance_km)}
          </span>
        )}
      </div>
      <div className="cb">
        <small>
          {x.category !== '' && x.category ? String(x.category) : String(x.shop?.type_name ?? '')}
          {x.is_rx ? ' · Prescription' : ''}
        </small>
        <b>{String(x.name)}</b>
        <span className="shopname">
          <Ic name="store" />
          {String(x.shop?.name ?? '')}
        </span>
        <div className="pr">
          <span>
            {x.compare_at !== null && x.compare_at !== undefined && <s>{mkPrice(x.compare_at, x.currency)}</s>}
            {mkPrice(x.price, x.currency)}
            {x.duration_min ? <small> · {Math.trunc(Number(x.duration_min))} min</small> : null}
          </span>
          <span className={'st ' + (out ? 'no' : low ? 'low' : 'ok')}>{stockText}</span>
        </div>
      </div>
    </Link>
  );
}

export function ShopTile({ s }: { s: Json }) {
  return (
    <Link className="shop" href={`/shops/${s.site_slug}`} style={{ '--c': cssColor(s.color) } as CSSProperties}>
      <span className="sh-ic">
        <SymIcon name={s.logo_icon} />
      </span>
      <b>{String(s.name)}</b>
      <small>
        {String(s.type_name)} · {String(s.area || s.city || '')}
      </small>
      <span className="meta">
        {s.distance_km !== null && s.distance_km !== undefined && (
          <em>
            <Ic name="navigation" />
            {kmStr(s.distance_km)}
          </em>
        )}
        {s.takes_bookings && (
          <em>
            <Ic name="calendar" />
            Bookings
          </em>
        )}
        {!s.delivers_here && <em className="warn">{s.pickup ? 'Pickup only here' : 'No delivery here'}</em>}
      </span>
    </Link>
  );
}

/** Server-side pager (first, last, current ±1, gaps). */
export function Pager({ page, last, path, q }: { page: number; last: number; path: string; q: Q }) {
  if (last <= 1) return null;
  const nums = [...new Set([1, last, page, page - 1, page + 1].filter((x) => x >= 1 && x <= last))].sort((a, b) => a - b);
  const link = (n: number) => mkUrl(path, q, { page: String(n) });
  return (
    <nav className="pager" aria-label="Pages">
      {page > 1 ? (
        <Link href={link(page - 1)} aria-label="Previous page" rel="prev">
          <Ic name="chevl" />
        </Link>
      ) : (
        <span className="dis" aria-hidden="true">
          <Ic name="chevl" />
        </span>
      )}
      {nums.map((n, i) => (
        <span key={n} style={{ display: 'contents' }}>
          {i > 0 && n - nums[i - 1] > 1 && <span className="gap">…</span>}
          {n === page ? (
            <span className="cur" aria-current="page">
              {n}
            </span>
          ) : (
            <Link href={link(n)}>{n}</Link>
          )}
        </span>
      ))}
      {page < last ? (
        <Link href={link(page + 1)} aria-label="Next page" rel="next">
          <Ic name="chev" />
        </Link>
      ) : (
        <span className="dis" aria-hidden="true">
          <Ic name="chev" />
        </span>
      )}
    </nav>
  );
}
