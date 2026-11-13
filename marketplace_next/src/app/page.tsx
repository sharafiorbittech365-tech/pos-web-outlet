import type { Metadata } from 'next';
import { Discovery } from '@/components/Discovery';
import { config } from '@/lib/config';

type SP = Promise<Record<string, string | string[] | undefined>>;

export async function generateMetadata({ searchParams }: { searchParams: SP }): Promise<Metadata> {
  const q = String((await searchParams).q ?? '').trim();
  // the layout's title template does not apply to the root page itself
  return { title: { absolute: `${q !== '' ? `Results for ${q}` : 'Shops & services near you'} · ${config.brandName} Market` } };
}

/** Home & search: every filter is a query-string parameter (DiscoveryController::index). */
export default async function Home({ searchParams }: { searchParams: SP }) {
  return <Discovery path="/" searchParams={await searchParams} />;
}
