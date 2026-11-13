/** BOOKING template home (barber & salon · karaoke) — Shop/Views/home_booking.php. */
import Link from 'next/link';
import type { CSSProperties } from 'react';
import { AboutBlock, FeatsBlock, ReviewsBlock } from '@/components/Blocks';
import { Icon, Pic, PThumb, Stars, Sym } from '@/components/Icon';
import { MenuList } from '@/components/MenuList';
import { Sh2 } from '@/components/Section';
import { dur, initials, pImage, sfUrl, today, variantNames } from '@/lib/format';
import { type Slot } from '@/lib/services/booking';
import { type Store } from '@/lib/store';
import { AccentText } from './HomeShop';

type J = Record<string, any>;

const ROLES = ['Master barber', 'Fade specialist', 'Colour expert', 'Skin specialist', 'Stylist'];

export function HomeBooking({ store, all, staff, quick, quickLoaded }: { store: Store; all: J[]; staff: string[]; quick: Slot[]; quickLoaded: boolean }) {
  const brand = store.brand();
  const room = store.bookingKind() === 'room';
  const timed = all.filter((p) => Number(p.duration_min ?? 0) > 0);
  const svcs = (timed.length > 0 ? timed : all).slice(0, 6);
  const other = all.filter((p) => Number(p.duration_min ?? 0) === 0);
  const tomorrow = today(1);
  const seoDesc = String(store.site?.seo?.description ?? '');
  const avatars = (staff.length > 0 ? staff : ['A', 'B', 'C', 'D']).slice(0, 4);

  return (
    <>
      <section className="bk-hero">
        <Pic i={0} brand={brand} className="bh-img" alt={store.name()} />
        <div className="wrap bh-in">
          <div className="bh-t">
            <span className="eyebrow">{room ? 'Sing. Party. Repeat.' : 'Look good. Feel confident.'}</span>
            <h1 className="cond">
              <AccentText text={String(brand.hero || store.name())} />
            </h1>
            <p>{String(brand.sub ?? '')}</p>
            <div className="hero-cta">
              {store.canBook() && (
                <Link className="btn-p" href="/book">
                  {store.cta()} <Icon name="cal" />
                </Link>
              )}
              <Link className="btn-g" href="/services">
                {room ? 'View rooms' : 'View services'}
              </Link>
            </div>
            <div className="proof">
              <span className="avs">
                {avatars.map((n, i) => (
                  <i key={i} style={{ '--h': i * 67 + 20 } as CSSProperties}>
                    {initials(n)}
                  </i>
                ))}
              </span>
              <Stars rating={5} />
              <small>Book online in 30 seconds</small>
            </div>
          </div>
          <div className="bh-card">
            <b>{room ? 'Rooms tonight' : 'Next free slots today'}</b>
            {!store.canBook() ? (
              <p className="muted">Call us to book.</p>
            ) : quick.length === 0 ? (
              <p className="muted">
                {quickLoaded ? (
                  <>
                    Fully booked today — <Link href={sfUrl('/book', { date: tomorrow })}>see tomorrow</Link>.
                  </>
                ) : (
                  'See all times on the booking page.'
                )}
              </p>
            ) : (
              <div className="qs">
                {quick.map((s) => (
                  <Link key={s.starts_at} href={sfUrl('/book', { date: s.starts_at.slice(0, 10), at: s.starts_at })}>
                    {s.time}
                  </Link>
                ))}
              </div>
            )}
            <Link className="btn-p full" href="/book">
              See all times <Icon name="arrow" />
            </Link>
          </div>
        </div>
      </section>

      <FeatsBlock
        cls="dark"
        items={
          room
            ? [['mic', 'Pro sound', 'Studio speakers & mics'], ['users', 'Rooms for 6–20', 'Private & sound-proof'], ['chef', 'Food & drinks', 'Order from your room'], ['award', 'Best in town', 'Loved by our guests']]
            : [['scissors', 'Expert barbers', 'Skilled & experienced professionals'], ['bottle', 'Premium products', 'Top quality products for the best results'], ['chair', 'Clean & hygienic', 'We maintain the highest standards'], ['award', 'Satisfaction guaranteed', 'Your satisfaction is our priority']]
        }
      />

      {room ? (
        <>
          <section className="wrap sec" id="sec-services">
            <Sh2 eyebrow="Our rooms" title={<>Pick your <span className="acc">Stage</span></>} icon="mic" />
            <div className="rooms">
              {svcs.map((r, i) => (
                <article className="room" key={String(r.id)}>
                  <Pic i={i % 2} brand={brand} className="room-img" />
                  <div className="room-b">
                    <b>{String(r.name)}</b>
                    <small>{String(r.description || dur(Number(r.duration_min ?? 60)))}</small>
                    <span className="pr">
                      {store.money(r.price)}
                      {r.unit ? <small>/{String(r.unit)}</small> : null}
                    </span>
                    {store.canBook() && (
                      <Link className="btn-g" href={`/book?service=${Number(r.id)}`}>
                        Book {String(r.name)}
                      </Link>
                    )}
                  </div>
                </article>
              ))}
            </div>
          </section>
          {other.length > 0 && (
            <section className="wrap sec" id="sec-team">
              <Sh2 eyebrow="Food, drinks & packages" title={<>Make it a <span className="acc">Night</span></>} icon="chef" />
              <MenuList store={store} list={other} />
            </section>
          )}
        </>
      ) : (
        <>
          <section className="wrap sec" id="sec-services">
            <Sh2 eyebrow="Our services" title={<>Services We <span className="acc">Offer</span></>} icon="scissors" />
            <div className="svc-cards">
              {svcs.map((p, i) => {
                const vn = variantNames(p);
                const has = pImage(p) !== '';
                return (
                  <article className="svc-c" key={String(p.id)}>
                    <Link href={`/book?service=${Number(p.id)}`}>
                      {has ? <PThumb p={p} size="svc-img" /> : <Pic i={i + 2} brand={brand} className="svc-img" alt={String(p.name)} />}
                      <span className="svc-ic">{has ? null : <Sym name={p.icon} />}</span>
                    </Link>
                    <b>{String(p.name)}</b>
                    <small>
                      {p.duration_min ? dur(Number(p.duration_min)) : ''}
                      {vn.length > 0 ? ' · ' + vn.join(' / ') : ''}
                    </small>
                    <span className="pr">
                      {vn.length > 0 ? 'from ' : ''}
                      {store.money(p.price)}
                    </span>
                  </article>
                );
              })}
            </div>
            <div className="center">
              <Link className="btn-dark" href="/services">
                View all services <Icon name="arrow" />
              </Link>
            </div>
          </section>
          {store.section('about') && (
            <AboutBlock
              store={store}
              eyebrow="About us"
              title={<>Precision. Style. <span className="acc">Confidence.</span></>}
              text={seoDesc || `At ${store.name()}, a haircut is more than a service — it is an experience. Our team combines skill, passion and attention to detail to deliver styles that make you look and feel your best.`}
              stats={[['cal', '10+', 'Years experience'], ['users', '50K+', 'Happy clients'], ['scissors', Math.max(staff.length, 3) + '+', 'Expert barbers'], ['star', '4.9', 'Client rating']]}
              button={
                store.canBook() ? (
                  <Link className="btn-p" href="/book">
                    {store.cta()} <Icon name="arrow" />
                  </Link>
                ) : null
              }
            />
          )}
          {staff.length > 0 && (
            <section className="wrap sec" id="sec-team">
              <Sh2 eyebrow="Meet the team" title={<>Our <span className="acc">Barbers</span></>} icon="users" />
              <div className="team-s">
                {staff.map((n, i) => (
                  <div className="tm-s" key={i}>
                    <span className="av-s" style={{ '--h': i * 67 } as CSSProperties}>
                      {initials(n)}
                    </span>
                    <b>{n}</b>
                    <small>{ROLES[i % 5]}</small>
                    <Stars rating={5} />
                    {store.canBook() && (
                      <Link href={sfUrl('/book', { staff: n })}>
                        Book with {n.split(' ')[0]} <Icon name="arrow" />
                      </Link>
                    )}
                  </div>
                ))}
              </div>
            </section>
          )}
        </>
      )}
      {store.section('reviews') && <ReviewsBlock kind="booking" />}
      <section className="wrap sec">
        <div className="split-cta">
          <div className="sc-dark">
            <span className="eyebrow">Walk in or book online</span>
            <h2>
              Your style,
              <br />
              our passion.
            </h2>
            <p>{String(brand.sub ?? '')}</p>
            <span className="sig">{store.name()}</span>
          </div>
          <Pic i={1} brand={brand} className="sc-img" />
          <div className="sc-light">
            <span className="ic-c">
              <Icon name="cal" />
            </span>
            <span className="eyebrow">Ready for a fresh look?</span>
            <h3>Book your appointment today</h3>
            {store.canBook() ? (
              <Link className="btn-dark" href="/book">
                Book now <Icon name="arrow" />
              </Link>
            ) : store.phone() !== '' ? (
              <a className="btn-dark" href={`tel:${store.phone()}`}>
                Call us
              </a>
            ) : null}
          </div>
        </div>
      </section>
    </>
  );
}
