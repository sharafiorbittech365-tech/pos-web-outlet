/** Full-page location chooser (works without the sheet; same picker). LocationController::index */
import type { Metadata } from 'next';
import { LocationPicker } from '@/components/MarketClient';
import { getMeta } from '@/lib/page';
import { getLocation, relative } from '@/lib/visitor';

export const metadata: Metadata = { title: 'Your location' };

export default async function LocationPage({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  const sp = await searchParams;
  const one = (k: string): string => String((Array.isArray(sp[k]) ? sp[k]![0] : sp[k]) ?? '');
  const [loc, meta] = await Promise.all([getLocation(), getMeta()]);
  return (
    <div className="wrap page">
      <div className="panel narrow">
        <LocationPicker areas={Array.isArray(meta.areas) ? meta.areas : []} current={loc?.area ?? ''} back={relative(one('back'))} hasLoc={loc !== null} q={one('q').trim()} />
      </div>
    </div>
  );
}
