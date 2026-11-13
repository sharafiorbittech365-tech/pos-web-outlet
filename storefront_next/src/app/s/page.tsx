import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { Listing } from '@/components/Listing';
import { getContext } from '@/lib/context';
import { pageMeta } from '@/lib/seo';
import { CatalogService, pageNo, parseSort } from '@/lib/services/catalog';

type Props = { searchParams: Promise<Record<string, string | string[] | undefined>> };

const one = (v: string | string[] | undefined): string => (Array.isArray(v) ? v[0] ?? '' : v ?? '');
const term = (v: string | string[] | undefined): string => Array.from(one(v)).slice(0, 100).join('').trim();

export async function generateMetadata({ searchParams }: Props): Promise<Metadata> {
  const { store } = await getContext();
  return pageMeta(store, { title: 'Search: ' + term((await searchParams).q), noindex: true });
}

export default async function SearchPage({ searchParams }: Props) {
  const { store, api } = await getContext();
  const sp = await searchParams;
  const q = term(sp.q);
  const cat = Math.trunc(Number(one(sp.cat))) || 0;
  if (q === '') redirect(cat > 0 ? '/c/' + cat : '/all');
  const sort = parseSort(one(sp.sort));
  const category = cat > 0 && store.category(cat) !== null ? cat : null;
  const result = await new CatalogService(api, store).page({ q, ...(category !== null ? { category } : {}) }, sort, pageNo(sp.page));
  return <Listing store={store} heading={`Results for “${q}”`} crumb="Search" category={null} result={result} sort={sort} path="/s" query={{ q, cat: category }} />;
}
