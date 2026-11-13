import type { Metadata } from 'next';
import { Discovery } from '@/components/Discovery';

type SP = Promise<Record<string, string | string[] | undefined>>;

export async function generateMetadata({ searchParams }: { searchParams: SP }): Promise<Metadata> {
  const q = String((await searchParams).q ?? '').trim();
  return { title: q !== '' ? `Results for ${q}` : 'Shops & services near you' };
}

/** Home & search: every filter is a query-string parameter (DiscoveryController::index). */
export default async function Search({ searchParams }: { searchParams: SP }) {
  return <Discovery path="/search" searchParams={await searchParams} />;
}
