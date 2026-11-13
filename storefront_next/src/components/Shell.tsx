/**
 * Shop shell of the design build: top bar, header (search or section nav), category bar, page,
 * newsletter, footer, floating buttons, mobile cart bar, cart drawer, toast. Port of Views/layout.php.
 */
import 'server-only';
import Link from 'next/link';
import { Suspense, type ReactNode } from 'react';
import { config } from '@/lib/config';
import { type Ctx } from '@/lib/context';
import { safeImgUrl } from '@/lib/format';
import { type Flash } from '@/lib/flash';
import { type PricedCart } from '@/lib/services/calculator';
import { type TableInfo } from '@/lib/table-session';
import { DrawerContent } from './DrawerContent';
import { FavImg, Icon, Sym } from './Icon';
import { SearchInput } from './SearchInput';
import { BackToTop, Drawer, HeaderFrame, MobileCartBar, NavToggle, NewsletterForm, ScrollKeeper } from './ShellClient';
import { ToastProvider } from './Toast';
import { WishCount } from './WishButton';

export interface ShellProps {
  ctx: Ctx;
  flash: Flash;
  table: TableInfo | null;
  cartCount: number;
  cart: PricedCart | null;
  children: ReactNode;
}

const GLYPH: Record<string, string> = { facebook: 'fb', instagram: 'ig', tiktok: 'tt', youtube: 'yt' };

export function Shell({ ctx, flash, table, cartCount, cart, children }: ShellProps) {
  const { store } = ctx;
  const b = store.business;
  const brand = store.brand();
  const ck = store.checkout();
  const kind = store.kind();
  const book = store.bookingKind();
  const shop = kind === 'shop';
  const cats = store.liveCategories();
  const sub = Number(cart?.subtotal ?? 0);
  const host = store.publicHost();
  const phone = store.phone();
  const wa = String(brand.whatsapp ?? '').replace(/\D+/g, '');
  const hoursText = String(brand.hoursText ?? '').trim();

  const mid: ReactNode = (() => {
    if (kind === 'booking') return <><Icon name="star" />Walk-ins welcome · Book online in 30 seconds</>;
    if (store.isAgency()) return <><Icon name="phone" />Free consultation · We reply within one working day</>;
    if (book === 'pickup') return <><Icon name="truck" />Free pickup &amp; delivery · Express in 24 hours</>;
    const delivery = ck.freeAbove > 0 ? `Free delivery on orders over ${store.money(ck.freeAbove)}` : ck.delivery > 0 ? `Fast delivery · ${store.money(ck.delivery)}` : 'Free delivery';
    return <><Icon name="truck" />{delivery} · {ck.cod ? 'Cash on delivery' : 'Secure payment'}</>;
  })();

  const lower = (s: string): string => s.toLowerCase();
  const navFor = (): [string, string][] => {
    switch (store.isClinic() ? 'clinic' : kind) {
      case 'clinic': return [['/doctors', 'Doctors'], ['/#sec-about', 'About us'], ['/contact', 'Contact']];
      case 'booking': return [['/#sec-services', book === 'room' ? 'Rooms' : store.term('catalog', 'Services')], ['/#sec-about', 'About us'], ['/#sec-team', book === 'room' ? 'Packages' : 'Our ' + lower(store.term('staff', 'Team')) + 's'], ['/#sec-reviews', 'Reviews'], ['/contact', 'Contact']];
      case 'menu': return [['/#sec-menu', 'Menu'], ['/#sec-about', 'About us'], ['/#sec-popular', 'Popular'], ['/#sec-reserve', book === 'table' ? 'Reservation' : 'Custom order'], ['/contact', 'Contact']];
      case 'service': return [['/#sec-services', 'Services'], ['/#sec-how', 'How it works'], ['/#sec-about', 'About us'], ['/#sec-reviews', 'Reviews'], ['/contact', 'Contact']];
      default: return [['/all', 'Shop'], ['/#sec-deals', 'Deals'], ['/#sec-categories', 'Categories'], ['/about', 'About'], ['/contact', 'Contact']];
    }
  };
  const nav: [string, string][] = [['/', 'Home'], ...navFor()];

  const logo = safeImgUrl(b.logo_url ?? b.logo ?? '');
  const brandLink = (
    <Link className="brand" href="/">
      {logo !== '' ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={logo} alt="" />
      ) : (
        <FavImg className="fav-logo" />
      )}
      <span className="bn">
        <b>{store.name()}</b>
        <small>{store.typeTag()}</small>
      </span>
    </Link>
  );

  const socials = Object.entries((brand.social ?? {}) as Record<string, unknown>).filter(([, u]) => safeImgUrl(u) !== '');
  const social =
    socials.length > 0 ? (
      <span className="soc">
        {socials.map(([k, u]) => (
          <a key={k} href={safeImgUrl(u)} target="_blank" rel="noopener nofollow" aria-label={k.charAt(0).toUpperCase() + k.slice(1)}>
            <Icon name={GLYPH[k] ?? 'globe'} />
          </a>
        ))}
      </span>
    ) : null;

  const searchable = shop || (kind === 'service' && store.sells());

  return (
    <ToastProvider flash={flash}>
      <ScrollKeeper />
      <div id="sf">
        <div className="topbar">
          <div className="wrap tb-in">
            <span>
              <Icon name="pin" />
              {store.address()}
            </span>
            <span className="tb-mid">
              {brand.announce ? (
                <>
                  <Icon name="megaphone" />
                  {String(brand.announce)}
                </>
              ) : (
                mid
              )}
            </span>
            <span className="tb-r">
              {phone !== '' && (
                <a href={`tel:${phone}`}>
                  <Icon name="phone" />
                  {phone}
                </a>
              )}
              {social}
            </span>
          </div>
        </div>

        {table && (
          <div className="table-bar" role="status">
            <div className="wrap table-bar-in">
              <span>
                <Icon name="utensils" />
                You are ordering at <b>{table.name}</b>
                {table.area ? ' · ' + table.area : ''} — orders go straight to the kitchen; pay at the table.
              </span>
              <a href="/t-leave">Not at this table?</a>
            </div>
          </div>
        )}

        <HeaderFrame>
          <div className="wrap hd-in">
            <NavToggle>
              <Icon name="menu" />
            </NavToggle>
            {brandLink}
            {searchable ? (
              <form className="search" method="get" action="/s" role="search">
                {shop && cats.length > 0 && (
                  <select name="cat" aria-label="Category" defaultValue="">
                    <option value="">All</option>
                    {cats.map((c) => (
                      <option key={String(c.id)} value={String(c.id)}>
                        {String(c.name)}
                      </option>
                    ))}
                  </select>
                )}
                <Suspense fallback={<input name="q" placeholder="Search products, categories…" aria-label="Search" maxLength={100} />}>
                  <SearchInput placeholder={store.isPharmacy() ? 'Search medicine or generic name…' : 'Search products, categories…'} />
                </Suspense>
                <button aria-label="Search">
                  <Icon name="search" />
                  <span>Search</span>
                </button>
              </form>
            ) : (
              <nav className="mainnav">
                {nav.map(([href, label]) => (
                  <Link key={href + label} href={href}>
                    {label}
                  </Link>
                ))}
              </nav>
            )}
            <div className="hd-icons">
              {shop && (
                <>
                  <Link className="hd-ico" href="/wishlist" title="Wishlist">
                    <Icon name="heart" />
                    <span>Wishlist</span>
                    <WishCount />
                  </Link>
                  <Link className="hd-ico" href="/#sec-deals" title="Offers">
                    <Icon name="tag" />
                    <span>Offers</span>
                  </Link>
                </>
              )}
              <Link className="hd-ico" href="/track" title="Track">
                <Icon name="clock" />
                <span>Track</span>
              </Link>
              {store.sells() && (
                <Link className="hd-ico cart-btn" href="/cart" aria-label="Cart">
                  <Icon name="cart" />
                  <span>Cart</span>
                  <i className={'dot' + (cartCount ? '' : ' zero')}>{cartCount}</i>
                </Link>
              )}
              {store.canBook() && (
                <Link className="hd-cta" href="/book">
                  <Icon name="cal" />
                  <span>{store.cta()}</span>
                </Link>
              )}
            </div>
          </div>
          {shop && (
            <nav className="catbar">
              <div className="wrap cb-in">
                <Link href="/" className="cb-home">
                  <Icon name="store" />
                  Home
                </Link>
                <div className="cb-links">
                  {cats.slice(0, 8).map((c) => (
                    <Link key={String(c.id)} href={`/c/${Number(c.id)}`}>
                      <span>
                        <Sym name={c.icon} />
                      </span>
                      {String(c.name)}
                    </Link>
                  ))}
                  <Link href="/about">About us</Link>
                </div>
                {phone !== '' && (
                  <a className="cb-phone" href={`tel:${phone}`}>
                    <span>
                      <Icon name="phone" />
                    </span>
                    {phone}
                  </a>
                )}
              </div>
              <div className="catdrop">
                <div className="wrap">
                  {cats.map((c) => (
                    <Link key={String(c.id)} href={`/c/${Number(c.id)}`}>
                      <span>
                        <Sym name={c.icon} />
                      </span>
                      {String(c.name)}
                      <em>{Number(c.products ?? 0)}</em>
                    </Link>
                  ))}
                </div>
              </div>
            </nav>
          )}
        </HeaderFrame>

        <main>
          {flash.error && (
            <div className="wrap">
              <div className="sf-alert err" role="alert">
                <Icon name="alert" />
                <span>{flash.error}</span>
              </div>
            </div>
          )}
          {children}
        </main>

        {store.section('newsletter') && (
          <section className="newsletter">
            <div className="wrap nl-in">
              <span className="nl-ic">
                <Icon name="mail" />
              </span>
              <div>
                <b>Subscribe to our newsletter</b>
                <span>{shop ? 'Get all the latest information on events, sales and offers.' : `Offers, new services and updates from ${store.name()}.`}</span>
              </div>
              <NewsletterForm />
            </div>
          </section>
        )}

        <footer className="ft" id="sec-contact">
          <div className="wrap ft-in">
            <div className="ft-brand">
              {brandLink}
              <p>{String(brand.sub ?? '')}</p>
              {phone !== '' && (
                <p className="ft-ci">
                  <span>
                    <Icon name="phone" />
                  </span>
                  <span>
                    <b>{phone}</b>
                    <small>{hoursText !== '' ? hoursText : '10 AM – ' + (book === 'room' ? '2 AM' : '10 PM')}</small>
                  </span>
                </p>
              )}
              <p className="ft-ci">
                <span>
                  <Icon name="pin" />
                </span>
                {store.address()}
              </p>
              {social}
            </div>
            <div>
              <h4>{shop ? 'Shop' : 'Explore'}</h4>
              {nav.map(([href, label]) => (
                <Link key={href + label} href={href}>
                  {label}
                </Link>
              ))}
              {shop && <Link href="/all">Browse all {lower(store.term('catalog', 'Products'))}</Link>}
            </div>
            <div>
              <h4>{kind === 'menu' ? 'Our ' + lower(store.term('catalog', 'Menu')) : shop ? 'Categories' : store.term('catalog', 'Services')}</h4>
              {cats.slice(0, 7).map((c) => (
                <Link key={String(c.id)} href={shop ? `/c/${Number(c.id)}` : kind === 'menu' ? `/#cat-${Number(c.id)}` : '/services'}>
                  {String(c.name)}
                </Link>
              ))}
            </div>
            <div>
              <h4>My account</h4>
              <Link href="/track">Track {store.canBook() && !store.sells() ? 'booking' : 'order'}</Link>
              {shop && <Link href="/wishlist">My wishlist</Link>}
              {store.sells() && (
                <>
                  <Link href="/cart">My cart</Link>
                  <Link href="/checkout">Checkout</Link>
                </>
              )}
              {store.canBook() && <Link href="/book">{store.cta()}</Link>}
            </div>
            <div>
              <h4>Help &amp; info</h4>
              {store.infoPages().length > 0 ? (
                store.infoPages().filter((p) => p.footer).map((p) => (
                  <Link key={p.slug} href={'/pages/' + p.slug}>
                    {p.title}
                  </Link>
                ))
              ) : (
                <>
                  <Link href="/policy">Delivery &amp; returns</Link>
                  <Link href="/policy#privacy">Privacy policy</Link>
                  <Link href="/policy#terms">Terms &amp; conditions</Link>
                  <Link href="/about">About us</Link>
                </>
              )}
            </div>
          </div>
          <div className="wrap ft-bot">
            <span>{String(brand.footer ?? '').trim() !== '' ? String(brand.footer) : `© ${new Date().getFullYear()} ${store.name()}${host !== '' ? ' · ' + host : ''}`}</span>
            <span className="pays">
              <i>bKash</i>
              <i>Nagad</i>
              <i>VISA</i>
              <i>Mastercard</i>
              <i>COD</i>
            </span>
            <span className="pw">
              <Icon name="lock" />
              Powered by <b>{config.brandName}</b>
            </span>
          </div>
        </footer>

        <div className="fabs">
          <BackToTop />
          {wa !== '' && (
            <a className="fab chat wa" href={`https://wa.me/${wa}`} target="_blank" rel="noopener" aria-label="WhatsApp">
              <Icon name="wa" />
            </a>
          )}
          {phone !== '' && (
            <a className="fab chat" href={`tel:${phone}`} aria-label="Call us">
              <Icon name="phone" />
            </a>
          )}
        </div>
        {store.sells() ? (
          <MobileCartBar kind="cart" count={cartCount} subtotal={store.money(sub)} />
        ) : store.canBook() ? (
          <MobileCartBar kind="book" count={0} subtotal="" cta={store.cta()} />
        ) : null}
      </div>

      <Drawer>{cart !== null && <DrawerContent store={store} cart={cart} />}</Drawer>
    </ToastProvider>
  );
}
