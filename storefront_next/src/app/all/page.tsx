import type { Metadata } from 'next';
import { Listing } from '@/components/Listing';
import { getContext } from '@/lib/context';
import { sfUrl } from '@/lib/format';
import { pageMeta } from '@/lib/seo';
import { CatalogService, pageNo, parseSort } from '@/lib/services/catalog';

type Props = { searchParams: Promise<Record<string, string | string[] | undefined>> };

export async function generateMetadata({ searchParams }: Props): Promise<Metadata> {
  const { store } = await getContext();
  const page = pageNo((await searchParams).page);
  return pageMeta(store, { title: 'All products' + (page > 1 ? ' · Page ' + page : ''), path: sfUrl('/all', { page: page > 1 ? page : null }) });
}

export default async function AllPage({ searchParams }: Props) {
  const { store, api } = await getContext();
  const sp = await searchParams;
  const s = Array.isArray(sp.sort) ? sp.sort[0] : sp.sort;
  const sort = parseSort(s);
  const result = await new CatalogService(api, store).page({}, sort, pageNo(sp.page));
  return <Listing store={store} heading="All products" crumb="All products" category={null} result={result} sort={sort} path="/all" />;
}
