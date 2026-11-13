/** Category, search results and "All products": sort bar, pager (Shop/Views/listing.php). */
import Link from 'next/link';
import { type Store } from '@/lib/store';
import { type Page } from '@/lib/api/storefront-api';
import { Sym } from './Icon';
import { MenuList } from './MenuList';
import { Pager } from './Pager';
import { ProductGrid } from './ProductCard';
import { SortBar } from './SortBar';

type Q = Record<string, string | number | null | undefined>;

export function Listing({
  store,
  heading,
  crumb,
  category,
  result,
  sort,
  path,
  query = {},
}: {
  store: Store;
  heading: string;
  crumb: string;
  category: Record<string, any> | null;
  result: Page;
  sort: string;
  path: string;
  query?: Q;
}) {
  const total = Math.trunc(Number(result.meta.total ?? result.data.length));
  return (
    <section className="wrap sec">
      <nav className="crumbs">
        <Link href="/">Home</Link> / <span>{crumb}</span>
      </nav>
      <div className="sec-h">
        <h1>
          {category !== null && (
            <>
              <Sym name={category.icon} />{' '}
            </>
          )}
          {heading}
        </h1>
      </div>
      <SortBar total={total} sort={sort} path={path} query={query} />
      {store.kind() === 'menu' && result.data.length > 0 ? <MenuList store={store} list={result.data} /> : <ProductGrid store={store} list={result.data} />}
      <Pager meta={result.meta} path={path} query={{ ...query, sort: sort === 'featured' ? null : sort }} />
    </section>
  );
}
