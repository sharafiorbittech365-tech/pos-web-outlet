/** Shared home-page blocks of the design: reviews, about split, CTA band, feature strip (partials/blocks.php). */
import Link from 'next/link';
import type { ReactNode } from 'react';
import { initials } from '@/lib/format';
import { type Store } from '@/lib/store';
import { Icon, Pic, Stars } from './Icon';
import { Sh2 } from './Section';
import type { CSSProperties } from 'react';

const QUOTES: Record<string, string[]> = {
  booking: ['Best place in town. Always consistent and professional — love the vibe and the result.', 'Clean fades, great service and friendly people. Booking online took 20 seconds.', 'I get compliments every time. These guys know exactly what they are doing.'],
  menu: ['Incredible flavours and a warm team that truly cares. A must-visit!', 'Ordered for the office — arrived hot and on time. Everyone loved it.', 'Our go-to place for family dinners. The table was ready when we arrived.'],
  service: ['Super easy — they picked up, delivered on time and the quality is excellent.', 'Clear price, fast turnaround and great communication from start to finish.', 'Professional team. We keep coming back for every project.'],
  shop: ['Genuine products and fast delivery. Cash on delivery made it easy.', 'Great prices, and the stock shown online was exactly what arrived.', 'Ordered at night, got it next day. Will buy again!'],
};
const PEOPLE: [string, number][] = [['Rahim U.', 210], ['Nusrat J.', 330], ['Tanvir A.', 20]];

export function ReviewsBlock({ kind = 'shop' }: { kind?: string }) {
  const quotes = QUOTES[kind] ?? QUOTES.shop;
  return (
    <section className="wrap sec" id="sec-reviews">
      <Sh2 eyebrow="What our clients say" title={<>Happy <span className="acc">Clients</span></>} icon="star" />
      <div className="quotes">
        {quotes.map((q, i) => (
          <figure className="qt" key={i}>
            <span className="av-q" style={{ '--h': PEOPLE[i][1] } as CSSProperties}>
              {initials(PEOPLE[i][0])}
            </span>
            <div>
              <Stars rating={5} />
              <blockquote>{q}</blockquote>
              <figcaption>— {PEOPLE[i][0]}</figcaption>
            </div>
          </figure>
        ))}
      </div>
    </section>
  );
}

export function AboutBlock({ store, eyebrow, title, text, stats, button }: { store: Store; eyebrow: string; title: ReactNode; text: string; stats: [string, string, string][]; button?: ReactNode }) {
  return (
    <section className="wrap sec" id="sec-about">
      <div className="about2">
        <div className="ab-img">
          <Pic i={1} brand={store.brand()} alt={store.name()} />
          <span className="playb" aria-hidden="true">
            <Icon name="play" />
          </span>
        </div>
        <div className="ab-t">
          <span className="eyebrow">{eyebrow}</span>
          <h2>{title}</h2>
          <p>{text}</p>
          <div className="ab-stats">
            {stats.map(([ic, v, l], i) => (
              <div key={i}>
                <span>
                  <Icon name={ic} />
                </span>
                <b>{v}</b>
                <small>{l}</small>
              </div>
            ))}
          </div>
          {button}
        </div>
      </div>
    </section>
  );
}

export function CtaBlock({ store, title, text }: { store: Store; title: string; text: string }) {
  if (!store.canBook()) return null;
  return (
    <section className="wrap sec">
      <div className="cta-band">
        <div>
          <span className="eyebrow">Ready for it?</span>
          <h2>{title}</h2>
          <p>{text}</p>
        </div>
        <Link className="btn-p" href="/book">
          <Icon name="cal" />
          {store.cta()} <Icon name="arrow" />
        </Link>
      </div>
    </section>
  );
}

export function FeatsBlock({ items, cls = '' }: { items: [string, string, string][]; cls?: string }) {
  return (
    <section className={('feats ' + cls).trim()}>
      <div className="wrap f-in">
        {items.map(([ic, t, d], i) => (
          <div key={i}>
            <span>
              <Icon name={ic} />
            </span>
            <b>{t}</b>
            <small>{d}</small>
          </div>
        ))}
      </div>
    </section>
  );
}
