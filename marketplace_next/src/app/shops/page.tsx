/** All listed shops near the location (ShopsController::index). */
import Link from 'next/link';
import type { Metadata } from 'next';
import { Ic } from '@/components/Ic';
import { ShopTile } from '@/components/Listing';
import { marketApi } from '@/lib/api/market-api';
import { config } from '@/lib/config';
import { mkUrl, plainQuery } from '@/lib/market';
import { getMeta } from '@/lib/page';
import { locLabel } from '@/lib/visitor';

export const metadata: Metadata = { title: 'Shops' };

export default async function Shops({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  const q = plainQuery(await searchParams);
  const [{ filters: f, location: loc, shops }, meta] = await Promise.all([(await marketApi()).shops({ ...q, limit: 500 }), getMeta()]);
  const chips: number[] = Array.isArray(meta.radius_chips) ? meta.radius_chips : [2, 5, 10, 25, 0];
  return (
    <div className="wrap page">
      <h1 className="page-h">Shops on {config.brandName} Market</h1>
      <p className="muted">{loc ? `Nearest first from ${locLabel(loc)}.` : 'Choose your location to see the nearest shops first.'}</p>
      {loc && (
        <div className="chips">
          <span className="lbl">
            <Ic name="radar" />
            Within
          </span>
          {chips.map((v) => (
            <Link key={v} className={'chip' + (Math.trunc(Number(f.radius)) === v ? ' on' : '')} href={mkUrl('/shops', q, { radius: String(v) })}>
              {v === 0 ? 'Any distance' : `${v} km`}
            </Link>
          ))}
        </div>
      )}
      {shops.length > 0 ? (
        <div className="grid" style={{ marginTop: 14 }}>
          {(shops as any[]).map((s) => (
            <ShopTile key={s.id} s={s} />
          ))}
        </div>
      ) : (
        <div className="empty">
          <Ic name="store" className="big" />
          <b>No shops within this distance</b>
          <Link className="btn" href={mkUrl('/shops', q, { radius: '0' })}>
            Show any distance
          </Link>
        </div>
      )}
    </div>
  );
}
