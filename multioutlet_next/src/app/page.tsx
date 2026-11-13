/** Home: brand hero, outlet chooser, categories, featured and newest products (port of Home::index). */
import Link from 'next/link';
import type { Metadata } from 'next';
import { Grid, OutletCard } from '@/components/Cards';
import { DemoImg, Icon, Sym } from '@/components/Icon';
import { Sh2 } from '@/components/Sh2';
import { getContext } from '@/lib/context';
import { accentSplit, safeImgUrl, sfUrl } from '@/lib/format';
import { pageMeta } from '@/lib/seo';
import type { ReactNode } from 'react';

export async function generateMetadata(): Promise<Metadata> {
  return pageMeta((await getContext()).site, { path: '/' });
}

function Accent({ text }: { text: string }): ReactNode {
  const [a, b] = accentSplit(text);
  return (
    <>
      {a} <span className="acc">{b}</span>
    </>
  );
}

export default async function Home() {
  const { site, api } = await getContext();
  const [featured, latest] = await Promise.all([api.products({ featured: 1, per_page: 8 }), api.products({ sort: 'newest', per_page: 8 })]);
  const brand = site.brand();
  const hero = String(brand.hero ?? '').trim() !== '' ? String(brand.hero) : 'Everything from all our outlets, in one place';
  const sub = site.tagline() !== '' ? site.tagline() : 'See which outlet has what you need, check the stock and order from the outlet you choose.';
  const img = safeImgUrl(brand.hero_image ?? '');
  const cats = site.liveCategories();
  const cur = site.currency();
  const n = site.outlets.length;

  return (
    <>
      <section className="ms-hero">
        <div className="wrap ms-hero-in">
          <div className="ms-hero-t">
            <span className="eb">
              <Icon name="store" />
              {n} outlet{n === 1 ? '' : 's'} · one website
            </span>
            <h1>
              <Accent text={hero} />
            </h1>
            <p>{sub}</p>
            <div className="hero-cta">
              <Link className="btn-p" href="/products">
                Browse all products <Icon name="arrow" />
              </Link>
              <Link className="btn-g" href="/outlets">
                <Icon name="pin" />
                Find an outlet
              </Link>
            </div>
          </div>
          <div className="ms-hero-pic">
            {img !== '' ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={img} alt="" loading="eager" />
            ) : (
              <DemoImg alt={site.name()} />
            )}
          </div>
        </div>
      </section>

      <section className="wrap sec" id="sec-outlets">
        <Sh2 eyebrow="Pick the outlet nearest to you" title="Choose your" acc="outlet" icon="store" more={{ href: '/outlets', label: 'All outlets' }} />
        <div className="ms-outlets">
          {site.outlets.map((o) => (
            <OutletCard key={String(o.id)} o={o} cur={cur} />
          ))}
        </div>
      </section>

      {cats.length > 0 && (
        <section className="wrap sec" id="sec-categories">
          <Sh2 eyebrow="Across every outlet" title="Shop by" acc="category" icon="grid" />
          <div className="ms-cats">
            {cats.map((c) => {
              const k = Math.trunc(Number(c.products ?? 0));
              return (
                <Link key={String(c.key)} className="ms-cat" href={sfUrl('/products', { category: String(c.key) })}>
                  <span>
                    <Sym name={c.icon ?? null} />
                  </span>
                  <b>{String(c.name)}</b>
                  <small>
                    {k} item{k === 1 ? '' : 's'}
                  </small>
                </Link>
              );
            })}
          </div>
        </section>
      )}

      {featured.data.length > 0 && (
        <section className="wrap sec">
          <Sh2 eyebrow="Hand-picked by our outlets" title="Featured" acc="products" icon="star" more={{ href: '/products', label: 'View all' }} />
          <Grid list={featured.data} cur={cur} />
        </section>
      )}

      <section className="wrap sec">
        <Sh2 eyebrow="Just added" title="New" acc="arrivals" icon="spark" more={{ href: sfUrl('/products', { sort: 'newest' }), label: 'View all' }} />
        <Grid list={latest.data} cur={cur} />
      </section>
    </>
  );
}
