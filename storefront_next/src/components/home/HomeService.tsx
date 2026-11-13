/** SERVICE template home (laundry · printing · agency) — Shop/Views/home_service.php. */
import Link from 'next/link';
import { AboutBlock, CtaBlock, ReviewsBlock } from '@/components/Blocks';
import { Icon, Pic, Stars, Sym } from '@/components/Icon';
import { MenuList } from '@/components/MenuList';
import { Sh2 } from '@/components/Section';
import { type Store } from '@/lib/store';
import { AccentText } from './HomeShop';

type J = Record<string, any>;

export function HomeService({ store, groups }: { store: Store; groups: { category: J; items: J[] }[] }) {
  const brand = store.brand();
  const book = store.bookingKind();
  const agency = store.isAgency();
  const seoDesc = String(store.site?.seo?.description ?? '');
  const steps: [string, string, string][] =
    book === 'pickup'
      ? [['cal', 'Schedule a pickup', 'Pick a day and a time window.'], ['truck', 'We collect', 'Our rider picks up and tags every item.'], ['check', 'Fresh & delivered', 'Cleaned, ironed and delivered in 48 h (24 h express).']]
      : agency
        ? [['file', 'Send your brief', 'Tell us what you need and share your files.'], ['phone', 'We call you', 'A strategist calls within one working day.'], ['check', 'Proposal & start', 'Clear scope, price and timeline.']]
        : [['file', 'Send your file', 'Share your design or describe the job.'], ['phone', 'Get a price', 'We confirm the price and proof within hours.'], ['truck', 'Print & deliver', 'Printed on our machines, delivered to you.']];

  return (
    <>
      <section className="sv-hero">
        <div className="wrap sv-in">
          <div className="sv-t">
            <span className="eyebrow">
              {store.typeTag()} · {store.branch()}
            </span>
            <h1>
              <AccentText text={String(brand.hero || store.name())} />
            </h1>
            <p>{String(brand.sub ?? '')}</p>
            <div className="hero-cta">
              {store.canBook() && (
                <Link className="btn-p" href="/book">
                  <Icon name={book === 'pickup' ? 'truck' : 'file'} />
                  {store.cta()}
                </Link>
              )}
              <a className="btn-g" href="#sec-services">
                {store.sells() ? 'See prices' : 'Our services'}
              </a>
            </div>
            <div className="proof">
              <Stars rating={5} />
              <small>Trusted by customers across {String(store.business.city ?? 'the city')}</small>
            </div>
          </div>
          <div className="sv-img">
            <Pic i={0} brand={brand} alt={store.name()} />
            <span className="badge-c">
              <b>{book === 'pickup' ? '24h' : agency ? '1 day' : '48h'}</b>
              {book === 'pickup' ? (
                <>
                  Express
                  <br />
                  service
                </>
              ) : agency ? (
                <>
                  Reply
                  <br />
                  time
                </>
              ) : (
                <>
                  Delivery
                  <br />
                  time
                </>
              )}
            </span>
          </div>
        </div>
      </section>

      <section className="wrap sec" id="sec-how">
        <Sh2 eyebrow="How it works" title={<>3 simple <span className="acc">Steps</span></>} icon="check" />
        <div className="steps3">
          {steps.map(([ic, tt, d], i) => (
            <div key={i}>
              <span>
                <Icon name={ic} />
              </span>
              <em>0{i + 1}</em>
              <b>{tt}</b>
              <small>{d}</small>
            </div>
          ))}
        </div>
      </section>

      <section className="wrap sec" id="sec-services">
        {book === 'pickup' ? <Sh2 eyebrow="Price list" title={<>Honest <span className="acc">Prices</span></>} icon="tag" /> : <Sh2 eyebrow="What we do" title={<>Services &amp; <span className="acc">Products</span></>} icon="scissors" />}
        {groups.map((g) => (
          <div className="menu-sec" id={`cat-${Number(g.category.id)}`} key={String(g.category.id)}>
            <h3>
              <Sym name={g.category.icon} /> {String(g.category.name)}
            </h3>
            {store.sells() ? (
              <MenuList store={store} list={g.items} />
            ) : (
              <div className="pkg">
                {g.items.map((p) => (
                  <article className="pkg-c" key={String(p.id)}>
                    <span className="pkg-e">
                      <Sym name={p.icon} />
                    </span>
                    <b>{String(p.name)}</b>
                    <span className="pr">
                      from {store.money(p.price)}
                      {p.unit ? (
                        <>
                          {' '}
                          <small>/ {String(p.unit)}</small>
                        </>
                      ) : null}
                    </span>
                    {store.canBook() && (
                      <Link className="btn-g" href={`/book?service=${Number(p.id)}`}>
                        Request this
                      </Link>
                    )}
                  </article>
                ))}
              </div>
            )}
          </div>
        ))}
        {groups.length === 0 && <div className="empty-s">Our price list is being updated. Please call us for a quote.</div>}
      </section>

      {store.section('about') && (
        <AboutBlock
          store={store}
          eyebrow="About us"
          title={<AccentText text={'Why choose ' + store.name()} />}
          text={seoDesc || `${store.name()} has served thousands of customers with care, speed and fair prices. Everything is tracked in our system, so you always know the status of your order.`}
          stats={[['cal', '10+', 'Years experience'], ['users', '5K+', 'Happy clients'], ['award', '99%', 'On-time'], ['star', '4.9', 'Rating']]}
          button={
            store.canBook() ? (
              <Link className="btn-p" href="/book">
                {store.cta()} <Icon name="arrow" />
              </Link>
            ) : null
          }
        />
      )}
      {store.section('reviews') && <ReviewsBlock kind="service" />}
      <CtaBlock
        store={store}
        title={book === 'pickup' ? 'Too busy to drop off?' : 'Have something custom in mind?'}
        text={book === 'pickup' ? 'Schedule a free pickup — we collect, clean and deliver.' : 'Send us the details. We reply with a price, usually the same day.'}
      />
    </>
  );
}
