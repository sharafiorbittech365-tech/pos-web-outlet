/** Home & search (port of Discovery/Views/index.php). Server component shared by / and /search. */
import Link from 'next/link';
import { Card, Pager, ShopTile } from '@/components/Listing';
import { Ic } from '@/components/Ic';
import { AutoCheck, AutoSelect, DetectButton, LocLink } from '@/components/MarketClient';
import { marketApi } from '@/lib/api/market-api';
import { config } from '@/lib/config';
import { backPath, mkUrl, plainQuery } from '@/lib/market';
import { getMeta } from '@/lib/page';

type SP = Record<string, string | string[] | undefined>;
const KINDS: [string, string, string][] = [['', 'Everything', 'layers'], ['product', 'Products', 'shopping-bag'], ['service', 'Services', 'calendar']];

export async function Discovery({ path, searchParams }: { path: string; searchParams: SP }) {
  const q = plainQuery(searchParams);
  const api = await marketApi();
  const listing = await api.listings(q);
  const { filters: f, location: loc, result } = listing;
  const [shops, meta] = await Promise.all([loc ? api.shops({ radius: String(f.radius), limit: 12 }).then((r) => r.shops as any[]) : Promise.resolve([] as any[]), getMeta()]);
  const items: any[] = result.items;
  const from = (result.page - 1) * result.per_page + 1;
  const heading = f.q !== '' ? `Results for "${f.q}"` : loc ? 'Near you' : `Popular on ${config.brandName} Market`;
  const radius = String(Math.trunc(Number(f.radius)));
  const chips: number[] = Array.isArray(meta.radius_chips) ? meta.radius_chips : [2, 5, 10, 25, 0];
  const hidden = (pairs: Record<string, string>) =>
    Object.entries(pairs)
      .filter(([, v]) => v !== '')
      .map(([k, v]) => <input key={k} type="hidden" name={k} value={v} />);

  return (
    <>
      <section className="hero">
        <div className="wrap">
          <h1>
            Shops &amp; services <span>near you</span>
          </h1>
          <p>Order products or book services from local businesses — delivered or ready when you arrive.</p>
          <form className="search" method="get" action="/" role="search">
            <Ic name="search" />
            <input name="q" defaultValue={f.q} placeholder="Search products, services or shops" aria-label="Search" key={f.q} />
            {hidden({ kind: f.kind, radius: loc ? radius : '', sort: f.sort !== 'distance' ? f.sort : '', stock: f.in_stock ? '1' : '' })}
            <button>Search</button>
          </form>
          <div className="kinds">
            {KINDS.map(([k, l, i]) => (
              <Link key={l} className={f.kind === k ? 'on' : ''} href={mkUrl(path, q, { kind: k, category: null })}>
                <Ic name={i} />
                {l}
              </Link>
            ))}
          </div>
        </div>
      </section>

      {!loc && (
        <div className="wrap">
          <div className="locate-card">
            <Ic name="locate-fixed" className="big" />
            <div className="grow">
              <b>Where should we look?</b>
              <p>Share your location or choose your area to see shops and prices near you.</p>
            </div>
            <DetectButton>
              <Ic name="locate-fixed" />
              Use my location
            </DetectButton>
            <LocLink className="btn" href={'/location?back=' + encodeURIComponent(backPath(path, q))}>
              Choose area
            </LocLink>
          </div>
        </div>
      )}

      <div className="wrap filters">
        {loc && (
          <div className="chips">
            <span className="lbl">
              <Ic name="radar" />
              Within
            </span>
            {chips.map((v) => (
              <Link key={v} className={'chip' + (Math.trunc(Number(f.radius)) === v ? ' on' : '')} href={mkUrl(path, q, { radius: String(v) })}>
                {v === 0 ? 'Any distance' : `${v} km`}
              </Link>
            ))}
          </div>
        )}
        <form className="sel-row" method="get" action={path} key={JSON.stringify(f)}>
          {hidden({ q: f.q, kind: f.kind, radius: loc ? radius : '' })}
          <AutoSelect name="category" aria-label="Category" defaultValue={f.category}>
            <option value="">All categories</option>
            {(result.categories as string[]).map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </AutoSelect>
          <AutoSelect name="sort" aria-label="Sort" defaultValue={f.sort}>
            <option value="distance">{loc ? 'Nearest first' : 'Popular'}</option>
            <option value="price">Price: low to high</option>
            <option value="-price">Price: high to low</option>
          </AutoSelect>
          <label className="tg">
            <AutoCheck name="stock" value="1" defaultChecked={Boolean(f.in_stock)} />
            In stock only
          </label>
          <button className="btn sm js-hide">Apply</button>
        </form>
      </div>

      {shops.length > 0 && (
        <section className="wrap">
          <div className="sh">
            <h2>
              <Ic name="store" />
              Shops near you
            </h2>
            <Link className="muted" href={'/shops' + (loc ? `?radius=${radius}` : '')}>
              {shops.length} open for orders
            </Link>
          </div>
          <div className="shops">
            {shops.map((s) => (
              <ShopTile key={s.id} s={s} />
            ))}
          </div>
        </section>
      )}

      <section className="wrap">
        <div className="sh">
          <h2>
            <Ic name="shopping-bag" />
            {heading}
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
            <b>Nothing found here yet</b>
            <span>{loc && f.radius > 0 ? 'Try a larger distance or another search.' : 'Try another search.'}</span>
            {loc && f.radius > 0 && (
              <Link className="btn" href={mkUrl(path, q, { radius: '0' })}>
                Show any distance
              </Link>
            )}
          </div>
        )}
      </section>
    </>
  );
}
