import type { Metadata } from 'next';
import { TrackView } from '@/components/TrackView';
import { getContext } from '@/lib/context';
import { allBookings } from '@/lib/done';
import { pageMeta } from '@/lib/seo';
import { findTracked, normaliseRef } from '@/lib/services/track';

export async function generateMetadata(): Promise<Metadata> {
  const { store } = await getContext();
  return pageMeta(store, { title: 'Track your order', noindex: true });
}

export default async function TrackRefPage({ params }: { params: Promise<{ ref: string }> }) {
  const { store, api } = await getContext();
  let { ref: raw } = await params;
  try {
    raw = decodeURIComponent(raw);
  } catch {
    /* keep raw */
  }
  const ref = normaliseRef(raw);
  const result = ref !== null ? await findTracked(api, ref, await allBookings()) : null;
  return <TrackView store={store} refCode={ref} result={result} />;
}
