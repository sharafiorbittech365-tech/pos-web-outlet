import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { TrackView } from '@/components/TrackView';
import { getContext } from '@/lib/context';
import { pageMeta } from '@/lib/seo';
import { normaliseRef } from '@/lib/services/track';

export async function generateMetadata(): Promise<Metadata> {
  const { store } = await getContext();
  return pageMeta(store, { title: 'Track your order', noindex: true });
}

export default async function TrackPage({ searchParams }: { searchParams: Promise<{ ref?: string | string[] }> }) {
  const { store } = await getContext();
  const { ref } = await searchParams;
  if (ref !== undefined) {
    const n = normaliseRef(String(Array.isArray(ref) ? ref[0] : ref));
    redirect(n !== null ? '/track/' + encodeURIComponent(n) : '/track');
  }
  return <TrackView store={store} refCode={null} result={null} />;
}
