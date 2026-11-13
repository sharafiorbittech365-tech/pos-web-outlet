/** SHOP template home (grocery · electronics · beauty · fashion · furniture · pharmacy …) — Shop/Views/home_shop.php. */
import Link from 'next/link';
import type { CSSProperties, ReactNode } from 'react';
import { AboutBlock, CtaBlock, ReviewsBlock } from '@/components/Blocks';
import { Carousel } from '@/components/Carousel';
import { Countdown } from '@/components/Countdown';
import { DemoImg, Icon, Pic } from '@/components/Icon';
import { ProductGrid } from '@/components/ProductCard';
import { ScrollRow } from '@/components/ScrollRow';
import { Sh2, ViewAll } from '@/components/Section';
import { accentSplit, safeImgUrl, sfUrl } from '@/lib/format';
import { type Store } from '@/lib/store';
import { type HomeSections } from '@/lib/services/catalog';

type J = Record<string, any>;

/** Last word(s) in the accent colour (PHP accent()). */
export function AccentText({ text, cls = 'acc' }: { text: string; cls?: string }) {
  const [lead, tail] = accentSplit(text);
  if (tail === '') return null;
  return (
    <>
      {lead}
      {lead !== '' ? ' ' : ''}
      <span className={cls}>{tail}</span>
    </>
  );
}

const TINTS = ['#FFF4E5', '#EAF7EE', '#FDECEF', '#EAF2FF', '#F3EDFF', '#FFF0F6', '#E8F8F7', '#FFF8DC'];
const PAY: Record<string, string> = { cod: 'Cash on delivery', bkash: 'bKash', card: 'Card' };

interface Slide {
  pi: number;
  img: string;
  eb: ReactNode;
  h: ReactNode;
  p: ReactNode;
  cta: ReactNode;
  marks: ReactNode;
}

export function HomeShop({ store, sections, tabs }: { store: Store; sections: HomeSections; tabs: Record<string, string> }) {
  const brand = store.brand();
  const t = store.type();
  const ck = store.checkout();
  const counts = sections.counts;
  const cats = store.liveCategories().filter((c) => (counts[Number(c.id)] ?? 0) > 0 || Number(c.products ?? 0) > 0);
  const maxOff = sections.maxOff;
  const catAt = (i: number): J | null => cats[i] ?? cats[0] ?? null;
  const eb =
    t === 'cosmetics' ? 'Beauty · Self care · Confidence'
      : t === 'grocery' ? 'Fresh · Healthy · Always'
        : t === 'furniture' ? 'Trending collections'
          : t === 'pharmacy' ? '100% genuine medicines'
            : 'New arrivals ' + new Date().getFullYear();
  const marks = (
    <div className="sl-marks">
      {([['leaf', t === 'grocery' ? 'Farm fresh' : 'Genuine'], ['award', 'Premium quality'], ['truck', 'Fast delivery']] as [string, string][]).map(([ic, l]) => (
        <span key={ic}>
          <i>
            <Icon name={ic} />
          </i>
          {l}
        </span>
      ))}
    </div>
  );
  const bookBtn = store.canBook() ? (
    <Link className="btn-g" href="/book">
      {store.cta()}
    </Link>
  ) : null;

  const slides: Slide[] = [];
  const own: J[] = (Array.isArray(brand.slides) ? brand.slides : []).filter((x: unknown) => x && typeof x === 'object' && (x as J).title);
  for (const x of own) {
    const link = String(x.link ?? '');
    const href = link === 'book' ? '/book' : /^c\/\d+$/.test(link) ? '/' + link : link === 'deals' ? '/#sec-deals' : '/all';
    const first = slides.length === 0;
    slides.push({
      pi: slides.length,
      img: safeImgUrl(x.img ?? ''),
      eb: first ? eb : store.name(),
      h: <AccentText text={String(x.title)} />,
      p: String(x.sub ?? ''),
      cta: (
        <div className="hero-cta">
          <Link className="btn-p" href={href}>
            {String(x.btn ?? 'Shop now')} <Icon name="arrow" />
          </Link>
        </div>
      ),
      marks: first ? marks : null,
    });
  }
  if (slides.length === 0) {
    const c0 = catAt(0);
    const c1 = catAt(1);
    slides.push(
      {
        pi: 0,
        img: '',
        eb,
        h: <AccentText text={String(brand.hero || store.name())} />,
        p: String(brand.sub ?? ''),
        marks,
        cta:
          t === 'grocery' ? (
            <form className="hero-search" method="get" action="/s">
              <input name="q" placeholder="Search for products…" aria-label="Search" />
              <button className="btn-p">
                Shop now <Icon name="arrow" />
              </button>
            </form>
          ) : (
            <div className="hero-cta">
              <Link className="btn-p" href="/all">
                Shop now <Icon name="arrow" />
              </Link>
              {bookBtn}
            </div>
          ),
      },
      {
        pi: 2,
        img: '',
        eb: maxOff > 0 ? 'Limited time offer' : 'Honest prices',
        h: maxOff > 0 ? <>Up to <span className="acc">{maxOff}% off</span></> : <>Great <span className="acc">value</span></>,
        p: `Best prices on ${String(c0?.name ?? 'bestsellers')} and more — while stocks last.`,
        marks: null,
        cta: (
          <div className="hero-cta">
            <Link className="btn-p" href="/#sec-deals">
              See all deals <Icon name="arrow" />
            </Link>
          </div>
        ),
      },
      {
        pi: 1,
        img: '',
        eb: 'Just landed',
        h: <>New in <span className="acc">{String(c1?.name ?? 'store')}</span></>,
        p: 'Fresh picks added this week, checked and ready to ship.',
        marks: null,
        cta: (
          <div className="hero-cta">
            <Link className="btn-p" href={c1 !== null ? '/c/' + Number(c1.id) : '/all'}>
              Explore <Icon name="arrow" />
            </Link>
          </div>
        ),
      },
    );
  }

  const slideNodes = slides.map((sl, i) => (
    <>
      {sl.img !== '' ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img className="sl-img" src={sl.img} alt={store.name()} />
      ) : (
        <Pic i={sl.pi} brand={brand} className="sl-img" alt={store.name()} />
      )}
      <div className="sl-t">
        <span className="eyebrow">{sl.eb}</span>
        {i === 0 ? <h1>{sl.h}</h1> : <h2 className="h1">{sl.h}</h2>}
        <p>{sl.p}</p>
        {sl.cta}
        {sl.marks}
      </div>
    </>
  ));

  const side: [number, J | null, string, string][] = [
    [2, catAt(1), 'Hot deal', maxOff > 0 ? `Up to ${maxOff}% off` : 'Best prices in town'],
    [1, catAt(2), 'New arrivals', 'Fresh picks this week'],
  ];
  const promos: [string, string, string, J | null][] = [
    [maxOff > 0 ? 'Fresh deals' : 'Shop now', maxOff > 0 ? `Up to ${maxOff}% off` : 'Everyday low prices', 'g1', catAt(0)],
    ['Daily essentials', 'Better prices every day', 'g2', catAt(1)],
    ['Top quality', '100% genuine & checked', 'g3', catAt(2)],
  ];
  const fcards: [string, string, string][] = [
    ['truck', 'Fast delivery', ck.freeAbove > 0 ? 'Free over ' + store.money(ck.freeAbove) : 'Across the city'],
    ['shield', '100% authentic', 'Guaranteed quality'],
    ['lock', 'Secure payment', store.paymentMethods().map((m) => PAY[m] ?? m).join(' · ')],
    ['return', 'Easy returns', '7-day hassle-free returns'],
  ];
  const seoDesc = String(store.site?.seo?.description ?? '');

  return (
    <>
      <section className="wrap hero2">
        <Carousel slides={slideNodes} />
        <div className="side2">
          {side.map(([pi, c, tag, sub], i) =>
            c !== null ? (
              <Link key={i} className={`promo-s ps${i}`} href={`/c/${Number(c.id)}`}>
                <Pic i={pi} brand={brand} />
                <span>
                  <small>{tag}</small>
                  <b>{String(c.name)}</b>
                  <i>{sub}</i>
                  <em>
                    Shop now <Icon name="arrow" />
                  </em>
                </span>
              </Link>
            ) : null,
          )}
        </div>
      </section>

      {store.section('categories') && cats.length > 0 && (
        <section className="wrap sec" id="sec-categories">
          <Sh2 eyebrow="Browse by your favourite category" title="Featured Categories" icon="grid" more={<ViewAll href="/all" />} />
          <ScrollRow>
            {cats.map((c, i) => {
              const n = Number(counts[Number(c.id)] ?? c.products ?? 0);
              return (
                <Link key={String(c.id)} className="catc" href={`/c/${Number(c.id)}`} style={{ '--tint': TINTS[i % TINTS.length] } as CSSProperties}>
                  <span className="cc-n">
                    {n} item{n === 1 ? '' : 's'}
                  </span>
                  <span className="cc-i">
                    <Icon name="arrow" />
                  </span>
                  <span className="cc-e">
                    <DemoImg alt={String(c.name)} />
                  </span>
                  <span className="cc-b">
                    <b>{String(c.name)}</b>
                    <small>Shop now →</small>
                  </span>
                </Link>
              );
            })}
          </ScrollRow>
        </section>
      )}

      <section className="wrap fcards">
        {fcards.map(([ic, a, d]) => (
          <div className="fcard" key={ic}>
            <span>
              <Icon name={ic} />
            </span>
            <div>
              <b>{a}</b>
              <small>{d}</small>
            </div>
          </div>
        ))}
      </section>

      {store.isPharmacy() && (
        <section className="wrap sec">
          <div className="rx-band">
            <Icon name="rx" />
            <div>
              <b>Have a prescription?</b>
              <span>Add medicines to the cart and tell us about the prescription at checkout. A pharmacist checks every Rx order.</span>
            </div>
          </div>
        </section>
      )}

      {store.section('featured') && (
        <section className="wrap sec" id="sec-shop">
          <Sh2 eyebrow="Handpicked products just for you" title="Recommended For You" icon="heart" more={<ViewAll href="/all" />} />
          <div className="rtabs">
            {Object.entries(tabs).map(([id, l]) => (
              <Link key={id} className={sections.tab === id ? 'on' : ''} href={sfUrl('/', { tab: id === 'featured' ? null : id }) + '#sec-shop'}>
                {l}
              </Link>
            ))}
          </div>
          <ProductGrid store={store} list={sections.tabList} />
        </section>
      )}

      {sections.deals.length > 0 && store.section('deals') && (
        <section className="band" id="sec-deals">
          <div className="wrap sec">
            <Sh2 eyebrow="Maximum savings, minimum price" title="Best Deals" icon="tag" more={<ViewAll href="/all" label="See all deals" />} />
            <div className="deals2">
              <div className="dl-promo">
                <span className="dl-tag">
                  <Icon name="spark" />
                  Limited time
                </span>
                {maxOff > 0 ? (
                  <>
                    <h3>
                      Up to
                      <br />
                      <b>{maxOff}%</b>
                      <br />
                      OFF
                    </h3>
                    <p>On selected items. Featuring our best deals — don&apos;t miss out!</p>
                  </>
                ) : (
                  <>
                    <h3>
                      Best
                      <br />
                      <b>value</b>
                      <br />
                      picks
                    </h3>
                    <p>Popular items at everyday prices.</p>
                  </>
                )}
                <Countdown />
                <Link className="btn-w" href="/all">
                  Shop now <Icon name="arrow" />
                </Link>
              </div>
              <ProductGrid store={store} list={sections.deals} cls="g3" />
            </div>
          </div>
        </section>
      )}

      {sections.trending.length > 0 && (
        <section className="wrap sec" id="sec-trending">
          <Sh2 eyebrow="Picked by the shop this week" title="Trending Now" icon="spark" more={<ViewAll href="/all" />} />
          <ProductGrid store={store} list={sections.trending} cls="g5" ranked />
        </section>
      )}

      <section className="wrap sec">
        <div className="promos">
          {promos.map(([a, b, g, c]) =>
            c !== null ? (
              <Link key={g} className={`promo ${g}`} href={`/c/${Number(c.id)}`}>
                <div>
                  <small>{a}</small>
                  <b>{b}</b>
                  <span className="btn-s">
                    Shop now <Icon name="arrow" />
                  </span>
                </div>
                <span className="pr-emo">
                  <DemoImg alt={String(c.name)} />
                </span>
              </Link>
            ) : null,
          )}
        </div>
      </section>

      {store.bookingKind() === 'visit' && <CtaBlock store={store} title="See it in person" text="Book a time to visit our showroom — we will keep the pieces you like ready." />}
      {store.bookingKind() === 'repair' && <CtaBlock store={store} title="Phone or laptop needs a fix?" text="Book a repair slot. Our technicians check it in front of you and give a free estimate." />}

      {store.section('about') && (
        <AboutBlock
          store={store}
          eyebrow="About us"
          title={<AccentText text={'Why shop with ' + store.name()} />}
          text={seoDesc || `${store.name()} brings you genuine products at honest prices, with fast delivery and friendly support. Every order is packed and checked in our own store.`}
          stats={[['award', '100%', 'Authentic'], ['truck', '24h', 'Dispatch'], ['star', '4.8', 'Rating'], ['users', '10K+', 'Customers']]}
          button={
            <Link className="btn-p" href="/all">
              Start shopping <Icon name="arrow" />
            </Link>
          }
        />
      )}
      {store.section('reviews') && <ReviewsBlock kind="shop" />}
    </>
  );
}
