import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { Listing } from '@/components/Listing';
import { getContext } from '@/lib/context';
import { sfUrl } from '@/lib/format';
import { pageMeta } from '@/lib/seo';
import { CatalogService, pageNo, parseSort } from '@/lib/services/catalog';

type Props = { params: Promise<{ id: string }>; searchParams: Promise<Record<string, string | string[] | undefined>> };

const first = (v: string | string[] | undefined): string | undefined => (Array.isArray(v) ? v[0] : v);

export async function generateMetadata({ params, searchParams }: Props): Promise<Metadata> {
  const { store } = await getContext();
  const id = Math.trunc(Number((await params).id));
  const category = store.category(id);
  if (!category) return pageMeta(store, {});
  const page = pageNo((await searchParams).page);
  return pageMeta(store, { title: String(category.name) + (page > 1 ? ' · Page ' + page : ''), path: sfUrl('/c/' + id, { page: page > 1 ? page : null }) });
}

export default async function CategoryPage({ params, searchParams }: Props) {
  const { store, api } = await getContext();
  const id = Math.trunc(Number((await params).id));
  const category = Number.isFinite(id) ? store.category(id) : null;
  if (!category) notFound();
  const sp = await searchParams;
  const sort = parseSort(first(sp.sort));
  const result = await new CatalogService(api, store).page({ category: id }, sort, pageNo(sp.page));
  return <Listing store={store} heading={String(category.name)} crumb={String(category.name)} category={category} result={result} sort={sort} path={'/c/' + id} />;
}
