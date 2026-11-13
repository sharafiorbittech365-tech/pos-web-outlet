/**
 * Website shell (same markup as the storefront so storefront.css styles it): top bar, header with search
 * + outlet picker, category bar, page, footer with every outlet, mobile cart bar, toast.
 * Port of app/Views/layout.php of the CodeIgniter multistore app.
 */
import 'server-only';
import Link from 'next/link';
import { Suspense, type ReactNode } from 'react';
import { config } from '@/lib/config';
import type { Ctx } from '@/lib/context';
import type { Flash } from '@/lib/flash';
import { safeImgUrl, sfUrl } from '@/lib/format';
import { FavImg, Icon, Sym } from './Icon';
import { BackToTop, CartNote, HeaderFrame, HeaderSearch, MobileCart, NavToggle } from './ShellClient';
import { ToastProvider } from './Toast';

function BrandLink({ ctx }: { ctx: Ctx }) {
  const { site } = ctx;
  const logo = safeImgUrl(site.brand().logo_url ?? '');
  const n = site.outlets.length;
  return (
    <Link className="brand" href="/">
      {logo !== '' ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={logo} alt="" />
      ) : (
        <FavImg className="fav-logo" />
      )}
      <span className="bn">
        <b>{site.name()}</b>
        <small>
          {n} outlet{n === 1 ? '' : 's'} · order online
        </small>
      </span>
    </Link>
  );
}

export function Shell({ ctx, flash, cartCount, cartOutlet, children }: { ctx: Ctx; flash: Flash; cartCount: number; cartOutlet: number | null; children: ReactNode }) {
  const { site } = ctx;
  const brand = site.brand();
  const cats = site.liveCategories();
  const n = site.outlets.length;
  const host = site.publicHost();
  const cartName = cartOutlet !== null ? site.outletName(cartOutlet) : '';
  const catalog = site.term('catalog', 'Products');
  const outlets = site.outlets.map((o) => ({ id: Math.trunc(Number(o.id)), name: String(o.name ?? '') }));

  return (
    <ToastProvider flash={flash}>
      <div id="sf">
        <div className="topbar">
          <div className="wrap tb-in">
            <span>
              <Icon name="store" />
              {n} outlet{n === 1 ? '' : 's'} · one website
            </span>
            <span className="tb-mid">
              {brand.announce ? (
                <>
                  <Icon name="megaphone" />
                  {String(brand.announce)}
                </>
              ) : (
                <>
                  <Icon name="truck" />
                  Order from the outlet nearest to you · delivery or pickup
                </>
              )}
            </span>
            <span className="tb-r">
              <Link href="/outlets">
                <Icon name="pin" />
                Find an outlet
              </Link>
            </span>
          </div>
        </div>

        <HeaderFrame>
          <div className="wrap hd-in">
            <NavToggle />
            <BrandLink ctx={ctx} />
            <Suspense fallback={null}>
              <HeaderSearch outlets={outlets} />
            </Suspense>
            <div className="hd-icons">
              <Link className="hd-ico" href="/outlets" title="Outlets">
                <Icon name="store" />
                <span>Outlets</span>
              </Link>
              <Link className="hd-ico" href="/products" title={`All ${catalog.toLowerCase()}`}>
                <Icon name="grid" />
                <span>{catalog}</span>
              </Link>
              <Link className="hd-ico cart-btn" href="/cart" aria-label={'Cart' + (cartName !== '' ? ` from ${cartName}` : '')}>
                <Icon name="cart" />
                <span>Cart</span>
                <i className={'dot' + (cartCount ? '' : ' zero')}>{cartCount}</i>
              </Link>
            </div>
          </div>
          <nav className="catbar">
            <div className="wrap cb-in">
              <Link href="/" className="cb-home">
                <Icon name="store" />
                Home
              </Link>
              <div className="cb-links">
                {cats.slice(0, 8).map((c) => (
                  <Link key={String(c.key)} href={sfUrl('/products', { category: String(c.key) })}>
                    <span>
                      <Sym name={c.icon ?? null} />
                    </span>
                    {String(c.name)}
                  </Link>
                ))}
                <Link href="/outlets">Our outlets</Link>
              </div>
            </div>
          </nav>
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
          <CartNote cartName={cartName} />
          {children}
        </main>

        <footer className="ft" id="sec-contact">
          <div className="wrap ft-in">
            <div className="ft-brand">
              <BrandLink ctx={ctx} />
              <p>{site.tagline()}</p>
            </div>
            <div>
              <h4>Shop</h4>
              <Link href="/products">All {catalog.toLowerCase()}</Link>
              <Link href={sfUrl('/products', { in_stock: '1' })}>In stock now</Link>
              <Link href="/outlets">Our outlets</Link>
              <Link href="/cart">My cart</Link>
            </div>
            <div>
              <h4>Categories</h4>
              {cats.slice(0, 7).map((c) => (
                <Link key={String(c.key)} href={sfUrl('/products', { category: String(c.key) })}>
                  {String(c.name)}
                </Link>
              ))}
            </div>
            <div>
              <h4>Outlets</h4>
              {site.outlets.slice(0, 8).map((o) => (
                <Link key={String(o.id)} href={`/outlets/${Math.trunc(Number(o.id))}`}>
                  {String(o.name)}
                  {o.area ? ` · ${String(o.area)}` : ''}
                </Link>
              ))}
            </div>
          </div>
          <div className="wrap ft-bot">
            <span>
              &copy; {new Date().getFullYear()} {site.name()}
              {host !== '' ? ` · ${host}` : ''}
            </span>
            <span className="pw">
              <Icon name="lock" />
              Powered by <b>{config.brandName}</b>
            </span>
          </div>
        </footer>

        <BackToTop />
        <MobileCart count={cartCount} cartName={cartName} />
      </div>
    </ToastProvider>
  );
}
