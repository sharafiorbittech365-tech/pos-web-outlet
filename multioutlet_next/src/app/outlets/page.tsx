/** Every outlet on the website, with the nearest first when the visitor shares their location (Home::outlets). */
import Link from 'next/link';
import type { Metadata } from 'next';
import { OutletCard } from '@/components/Cards';
import { NearestOutlets } from '@/components/NearestOutlets';
import { getContext } from '@/lib/context';
import { pageMeta } from '@/lib/seo';

export async function generateMetadata(): Promise<Metadata> {
  return pageMeta((await getContext()).site, { title: 'Our outlets', path: '/outlets' });
}

const coord = (v: unknown): number | null => (v === null || v === undefined || v === '' || !Number.isFinite(Number(v)) ? null : Number(v));

export default async function Outlets() {
  const { site } = await getContext();
  const cur = site.currency();
  return (
    <section className="wrap sec">
      <nav className="crumbs">
        <Link href="/">Home</Link> / <span>Our outlets</span>
      </nav>
      <div className="sec-h">
        <h1>Our outlets</h1>
      </div>
      <p className="muted">Every outlet keeps its own stock, prices and delivery area. Pick one to see what it has today.</p>
      <NearestOutlets outlets={site.outlets.map((o) => ({ id: Math.trunc(Number(o.id)), lat: coord(o.lat), lng: coord(o.lng) }))}>
        {site.outlets.map((o) => (
          <OutletCard key={String(o.id)} o={o} cur={cur} />
        ))}
      </NearestOutlets>
    </section>
  );
}
