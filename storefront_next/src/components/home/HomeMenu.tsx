/** MENU template home (restaurant · café · bakery) — Shop/Views/home_menu.php. */
import Link from 'next/link';
import { addToCart, removeFromCart } from '@/app/actions/cart';
import { FeatsBlock, ReviewsBlock } from '@/components/Blocks';
import { Icon, Pic, PThumb, Sym } from '@/components/Icon';
import { MenuList } from '@/components/MenuList';
import { Sh2 } from '@/components/Section';
import { pImage, strim, today, variantNames } from '@/lib/format';
import { type PricedCart } from '@/lib/services/calculator';
import { type Store } from '@/lib/store';
import { AccentText } from './HomeShop';

type J = Record<string, any>;

export function HomeMenu({ store, all, groups, popular, cart }: { store: Store; all: J[]; groups: { category: J; items: J[] }[]; popular: J[]; cart: PricedCart | null }) {
  const brand = store.brand();
  const t = store.type();
  const ck = store.checkout();
  const book = store.bookingKind();
  const tomorrow = today(1);
  const seoDesc = String(store.site?.seo?.description ?? '');

  return (
    <>
      <section className="mn-hero">
        <div className="wrap mh-in">
          <div className="mh-t">
            <span className="eyebrow">{t === 'cafe' ? 'Good coffee | Good mood' : t === 'bakery' ? 'Baked fresh | Every morning' : 'Good food | Good mood'}</span>
            <h1>
              <AccentText text={String(brand.hero || store.name())} cls="acc script" />
            </h1>
            <p>{String(brand.sub ?? '')}</p>
            <div className="hero-cta">
              <a className="btn-p" href="#sec-menu">
                <Icon name="chef" />
                Explore menu
              </a>
              {store.canBook() && (
                <Link className="btn-g" href="/book">
                  <Icon name="cal" />
                  {store.cta()}
                </Link>
              )}
            </div>
            <div className="mini-f">
              {([['leaf', 'Fresh ingredients'], ['chef', 'Expert chefs'], ['heart', 'Made with love']] as [string, string][]).map(([ic, l]) => (
                <span key={ic}>
                  <i>
                    <Icon name={ic} />
                  </i>
                  {l}
                </span>
              ))}
            </div>
          </div>
          <div className="mh-img">
            <div className="plate">
              <Pic i={0} brand={brand} alt="Signature dish" />
            </div>
            <span className="badge-c">
              <b>{all.length}+</b>Signature
              <br />
              dishes
            </span>
            <span className="leafs">
              <Icon name="leaf" />
            </span>
          </div>
        </div>
      </section>

      {store.section('about') && (
        <section className="wrap sec" id="sec-about">
          <div className="about3">
            <div className="ab-img">
              <Pic i={1} brand={brand} alt={store.name()} />
              <span className="yrs">
                <Icon name="users" />
                <b>10+</b>Years of
                <br />
                experience
              </span>
            </div>
            <div className="ab-t">
              <span className="eyebrow">Welcome to {store.name()}</span>
              <h2>
                {t === 'cafe' ? 'Great coffee, ' : t === 'bakery' ? 'Sweet moments, ' : 'Good food, '}
                <span className="acc script">{t === 'cafe' ? 'good life' : t === 'bakery' ? 'baked daily' : 'good life'}</span>
              </h2>
              <p>{seoDesc || `At ${store.name()}, we believe great food brings people together. Every dish is made to order with the freshest ingredients by our own kitchen team.`}</p>
              <span className="sig">{store.name()}</span>
              <a className="btn-dark" href="#sec-menu">
                See the menu <Icon name="arrow" />
              </a>
            </div>
            <div className="ab-f">
              {([['leaf', 'Quality ingredients'], ['chef', 'Skilled chefs'], ['store', 'Cozy ambience']] as [string, string][]).map(([ic, l]) => (
                <div key={ic}>
                  <span>
                    <Icon name={ic} />
                  </span>
                  {l}
                </div>
              ))}
            </div>
          </div>
        </section>
      )}

      {popular.length > 0 && (
        <section className="wrap sec" id="sec-popular">
          <Sh2
            eyebrow="Chef's special"
            title={<>Our <span className="acc">Popular Dishes</span></>}
            icon="chef"
            more={
              <a className="sh2-more" href="#sec-menu">
                View full menu <Icon name="chev" />
              </a>
            }
          />
          <div className="dish-cards">
            {popular.map((p, i) => {
              const vn = variantNames(p);
              const id = Number(p.id);
              return (
                <article className="dish" key={id}>
                  <Link href={`/p/${id}`}>{pImage(p) !== '' ? <PThumb p={p} /> : <Pic i={i + 2} brand={brand} className="dish-img" alt={String(p.name)} />}</Link>
                  <b>{String(p.name)}</b>
                  <small>{strim(vn.length > 0 ? vn.join(' · ') : String(p.description || store.categoryName(Number(p.category_id ?? 0))), 90)}</small>
                  <div className="dish-f">
                    <span className="pr">{store.money(p.price)}</span>
                    {vn.length > 0 ? (
                      <Link className="mi-add dark" href={`/p/${id}`} aria-label="Choose">
                        <Icon name="plus" />
                      </Link>
                    ) : store.sells() && p.in_stock ? (
                      <form className="rb-f" action={addToCart} data-scroll-keep>
                        <input type="hidden" name="product_id" value={id} />
                        <input type="hidden" name="qty" value="1" />
                        <input type="hidden" name="stay" value="1" />
                        <button className="mi-add dark" aria-label={`Add ${String(p.name)}`}>
                          <Icon name="plus" />
                        </button>
                      </form>
                    ) : null}
                  </div>
                </article>
              );
            })}
          </div>
        </section>
      )}

      <nav className="menu-tabs" id="sec-menu">
        <div className="wrap">
          {groups.map((g) => (
            <a key={String(g.category.id)} href={`#cat-${Number(g.category.id)}`}>
              <Sym name={g.category.icon} /> {String(g.category.name)}
            </a>
          ))}
        </div>
      </nav>
      <section className="wrap sec menu-wrap">
        <div className="menu-main">
          {groups.map((g) => (
            <div className="menu-sec" id={`cat-${Number(g.category.id)}`} key={String(g.category.id)}>
              <h2>
                <Sym name={g.category.icon} /> {String(g.category.name)}
              </h2>
              <MenuList store={store} list={g.items} />
            </div>
          ))}
          {groups.length === 0 && <div className="empty-s">The menu is being updated. Please check back soon.</div>}
        </div>
        <aside className="menu-side">
          <div className="side-card mini-cart">
            <b>
              <Icon name="cart" />
              Your order
            </b>
            {cart === null || cart.lines.length === 0 ? (
              <p className="muted">Tap + to add dishes.</p>
            ) : (
              <>
                {cart.lines.map((l) => (
                  <div className="mc-r" key={l.index}>
                    <span>
                      {l.qty} × {l.name}
                      {l.variant !== '' ? ` (${l.variant})` : ''}
                    </span>
                    <span>{store.money(l.line_total)}</span>
                    <form action={removeFromCart} className="rb-f">
                      <input type="hidden" name="index" value={l.index} />
                      <input type="hidden" name="stay" value="1" />
                      <button className="x" aria-label="Remove">
                        <Icon name="x" />
                      </button>
                    </form>
                  </div>
                ))}
                <div className="mc-r tot">
                  <span>Subtotal</span>
                  <b>{store.money(cart.subtotal)}</b>
                </div>
                <Link className="btn-p full" href="/checkout">
                  <Icon name="lock" />
                  Checkout
                </Link>
              </>
            )}
          </div>
        </aside>
      </section>

      <section className="wrap sec" id="sec-reserve">
        <div className="offer-row">
          <div className="offer">
            <Pic i={2} brand={brand} className="of-img" />
            <div className="of-t">
              <span className="eyebrow">Order online</span>
              <h2>Hot &amp; fresh</h2>
              <p>{ck.pickup ? 'Delivery or pickup — your choice' : 'Delivered to your door'}</p>
              <a className="btn-w" href="#sec-menu">
                Order now <Icon name="chev" />
              </a>
            </div>
          </div>
          {store.canBook() &&
            (book === 'table' ? (
              <form className="table-f" method="get" action="/book">
                <h3>Book your table</h3>
                <div className="tf-g">
                  <label>
                    <Icon name="cal" />
                    <input type="date" name="date" defaultValue={tomorrow} min={today()} aria-label="Date" />
                  </label>
                  <label>
                    <Icon name="clock" />
                    <select name="want" aria-label="Time">
                      {['12:00', '13:00', '14:00', '19:00', '20:00', '21:00'].map((x) => (
                        <option key={x}>{x}</option>
                      ))}
                    </select>
                  </label>
                  <label className="span2">
                    <Icon name="users" />
                    <select name="party" aria-label="People" defaultValue="2">
                      {[1, 2, 3, 4, 5, 6, 8, 10].map((x) => (
                        <option key={x} value={x}>
                          {x} {x === 1 ? 'person' : 'people'}
                        </option>
                      ))}
                    </select>
                  </label>
                </div>
                <button className="btn-p full">Find a table</button>
              </form>
            ) : (
              <div className="table-f">
                <h3>{store.cta()}</h3>
                <p className="muted">Tell us the size, flavour and message — we confirm the price by phone.</p>
                <Link className="btn-p full" href="/book">
                  Start your order
                </Link>
              </div>
            ))}
        </div>
      </section>

      <FeatsBlock items={[['truck', 'Free delivery', ck.freeAbove > 0 ? 'On orders over ' + store.money(ck.freeAbove) : 'Nearby areas'], ['card', 'Easy payment', 'bKash · Card · Cash'], ['gift', 'Best offers', 'Deals every week'], ['support', 'Friendly support', 'We are always here']]} />
      {store.section('reviews') && <ReviewsBlock kind="menu" />}
    </>
  );
}
