/** Products across the outlets with filters: outlet, category, in stock, price range, search, sort (Catalog::products). */
import Link from 'next/link';
import type { Metadata } from 'next';
import { Grid } from '@/components/Cards';
import { Icon } from '@/components/Icon';
import { Pager } from '@/components/Pager';
import { config } from '@/lib/config';
import { type Ctx, getContext } from '@/lib/context';
import { sfMoney, sfUrl } from '@/lib/format';
import { pageMeta } from '@/lib/seo';

type SP = Promise<Record<string, string | string[] | undefined>>;

const SORTS: Record<string, string> = { featured: '', name: 'name', low: 'price', high: '-price', newest: 'newest' };
const SORT_LABELS: [string, string][] = [['featured', 'Featured'], ['low', 'Price: low to high'], ['high', 'Price: high to low'], ['name', 'Name'], ['newest', 'Newest']];

interface Filters {
  q: string;
  category: string;
  outlet: string;
  in_stock: string;
  min_price: string;
  max_price: string;
  sort: string;
}

/** The filters from the query string, sanitised. */
function filters(sp: Record<string, string | string[] | undefined>, ctx: Ctx): Filters {
  const g = (k: string): string => String((Array.isArray(sp[k]) ? sp[k]![0] : sp[k]) ?? '').trim();
  const num = (v: string): string => (v !== '' && Number.isFinite(Number(v)) && Number(v) >= 0 ? String(Number(v)) : '');
  const cut = (v: string): string => Array.from(v).slice(0, 100).join('');
  const outlet = /^\d+$/.test(g('outlet')) && ctx.site.outlet(Number(g('outlet'))) !== null ? g('outlet') : '';
  const cat = cut(g('category'));
  return {
    q: cut(g('q')),
    category: cat !== '' && ctx.site.categoryName(cat) !== '' ? cat : '',
    outlet,
    in_stock: g('in_stock') === '1' ? '1' : '',
    min_price: num(g('min_price')),
    max_price: num(g('max_price')),
    sort: g('sort') in SORTS ? g('sort') : 'featured',
  };
}

function heading(f: Filters, ctx: Ctx): string {
  const { site } = ctx;
  if (f.q !== '') return `Results for “${f.q}”`;
  if (f.category !== '') return site.categoryName(f.category);
  if (f.outlet !== '') return `${site.term('catalog', 'Products')} at ${site.outletName(Number(f.outlet))}`;
  return `All ${site.term('catalog', 'Products').toLowerCase()}`;
}

export async function generateMetadata({ searchParams }: { searchParams: SP }): Promise<Metadata> {
  const ctx = await getContext();
  const f = filters(await searchParams, ctx);
  return pageMeta(ctx.site, { title: heading(f, ctx), path: '/products', noindex: f.q !== '' || f.in_stock !== '' || f.min_price !== '' || f.max_price !== '' });
}

export default async function Products({ searchParams }: { searchParams: SP }) {
  const ctx = await getContext();
  const { site, api } = ctx;
  const sp = await searchParams;
  const f = filters(sp, ctx);
  const page = Math.max(1, Math.trunc(Number(sp.page ?? 1)) || 1);
  const result = await api.products({
    q: f.q, category: f.category, outlet: f.outlet, in_stock: f.in_stock, min_price: f.min_price, max_price: f.max_price,
    sort: SORTS[f.sort], page, per_page: config.perPage,
  });
  const cur = site.currency();
  const title = heading(f, ctx);
  const total = Math.trunc(Number(result.meta.total ?? result.data.length));
  const keep: Record<string, string> = Object.fromEntries(Object.entries(f).filter(([k, v]) => v !== '' && !(k === 'sort' && v === 'featured')));
  const active = Object.entries(keep).filter(([k]) => k !== 'sort');
  const chip = (k: string, v: string): string =>
    k === 'outlet' ? site.outletName(Number(v)) : k === 'category' ? site.categoryName(v) : k === 'in_stock' ? 'In stock' : k === 'min_price' ? `From ${sfMoney(v, cur)}` : k === 'max_price' ? `Up to ${sfMoney(v, cur)}` : `“${v}”`;

  return (
    <section className="wrap sec">
      <nav className="crumbs">
        <Link href="/">Home</Link> / <span>{title}</span>
      </nav>
      <div className="sec-h">
        <h1>{title}</h1>
      </div>
      <div className="ms-shop">
        <form className="ms-filters box" method="get" action="/products" aria-label="Filter products" key={JSON.stringify(f)}>
          <h3>
            <Icon name="sliders" />
            Filters
          </h3>
          <label>
            Search
            <input name="q" maxLength={100} defaultValue={f.q} placeholder="Name, brand, SKU" />
          </label>
          <label>
            Outlet
            <select name="outlet" defaultValue={f.outlet}>
              <option value="">All outlets</option>
              {site.outlets.map((o) => (
                <option key={String(o.id)} value={String(Math.trunc(Number(o.id)))}>
                  {String(o.name)}
                  {o.area ? ` · ${String(o.area)}` : ''}
                </option>
              ))}
            </select>
          </label>
          <label>
            Category
            <select name="category" defaultValue={f.category}>
              <option value="">All categories</option>
              {site.liveCategories().map((c) => (
                <option key={String(c.key)} value={String(c.key)}>
                  {String(c.name)} ({Math.trunc(Number(c.products ?? 0))})
                </option>
              ))}
            </select>
          </label>
          <fieldset className="ms-price">
            <legend>Price</legend>
            <input name="min_price" type="number" min={0} step="any" inputMode="decimal" placeholder="Min" defaultValue={f.min_price} aria-label="Minimum price" />
            <span>–</span>
            <input name="max_price" type="number" min={0} step="any" inputMode="decimal" placeholder="Max" defaultValue={f.max_price} aria-label="Maximum price" />
          </fieldset>
          <label className="ms-check">
            <input type="checkbox" name="in_stock" value="1" defaultChecked={f.in_stock === '1'} />
            In stock only
          </label>
          <label>
            Sort
            <select name="sort" defaultValue={f.sort}>
              {SORT_LABELS.map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
                </option>
              ))}
            </select>
          </label>
          <button className="btn-p full" type="submit">
            <Icon name="search" />
            Show products
          </button>
          {active.length > 0 && (
            <Link className="link" href="/products">
              Clear all filters
            </Link>
          )}
        </form>
        <div className="ms-results">
          <div className="sortbar">
            <span>
              {total} item{total === 1 ? '' : 's'}
            </span>
            {active.length > 0 && (
              <span className="ms-chips">
                {active.map(([k, v]) => (
                  <Link key={k} className="ms-chip" href={sfUrl('/products', Object.fromEntries(Object.entries(keep).filter(([x]) => x !== k)))} aria-label="Remove filter">
                    {chip(k, v)}
                    <Icon name="x" />
                  </Link>
                ))}
              </span>
            )}
          </div>
          <Grid list={result.data} outlet={Number(f.outlet) || 0} cur={cur} />
          <Pager meta={result.meta} path="/products" query={keep} />
        </div>
      </div>
    </section>
  );
}
