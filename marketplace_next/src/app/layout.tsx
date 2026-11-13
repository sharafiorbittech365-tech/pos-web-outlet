/**
 * Market shell (port of app/Views/layout.php): top bar with "Deliver to", nav, flash messages, footer and the
 * location sheet. Pages render into <main>.
 */
import type { Metadata, Viewport } from 'next';
import Link from 'next/link';
import Script from 'next/script';
import type { ReactNode } from 'react';
import '../styles/marketplace.css';
import '../styles/market-app.css';
import { Ic } from '@/components/Ic';
import { FlashClear, LocationSheet, LocLink } from '@/components/MarketClient';
import { config } from '@/lib/config';
import { readFlash } from '@/lib/flash';
import { ICON_IMG } from '@/lib/market';
import { currentPath, getBuyer, getMeta } from '@/lib/page';
import { cartCount, getCart, getLocation, locLabel } from '@/lib/visitor';

export const viewport: Viewport = { width: 'device-width', initialScale: 1, viewportFit: 'cover' };

export async function generateMetadata(): Promise<Metadata> {
  const brand = config.brandName;
  return {
    title: { template: `%s · ${brand} Market`, default: `${brand} Market` },
    description: 'Find products and services from shops near you, order or book in a few taps.',
    icons: { icon: [{ url: ICON_IMG, type: 'image/svg+xml' }] },
  };
}

const FONTS = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap';

export default async function RootLayout({ children }: { children: ReactNode }) {
  const [loc, cart, buyer, flash, meta, back] = await Promise.all([getLocation(), getCart(), getBuyer(), readFlash(), getMeta(), currentPath()]);
  const brand = config.brandName;
  const count = cartCount(cart);
  const label = locLabel(loc);
  const locHref = '/location?back=' + encodeURIComponent(back);

  return (
    <html lang="en" suppressHydrationWarning>
      <head>
        {/* the stylesheet shows .js-only parts and hides .js-hide ones once scripts run (marketplace.js) */}
        <Script id="js-flag" strategy="beforeInteractive">{"document.documentElement.classList.add('js')"}</Script>
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="" />
        <link href={FONTS} rel="stylesheet" />
      </head>
      <body>
        <LocationSheet picker={{ areas: Array.isArray(meta.areas) ? meta.areas : [], current: loc?.area ?? '', back, hasLoc: loc !== null }}>
          <header className="top">
            <div className="wrap top-in">
              <Link className="logo" href="/">
                <span className="lg">
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img src={ICON_IMG} alt="" />
                </span>
                <b>
                  {brand} <em>Market</em>
                </b>
              </Link>
              <LocLink className="loc" href={locHref}>
                <Ic name="map-pin" />
                <span>
                  <small>Deliver to</small>
                  <b>{label}</b>
                </span>
                <Ic name="chev" />
              </LocLink>
              <nav className="top-nav" aria-label="Main">
                <Link href="/shops">
                  <Ic name="store" />
                  <span className="lbl-t">Shops</span>
                </Link>
                <Link href="/cart" aria-label={`Cart, ${count} items`}>
                  <Ic name="shopping-cart" />
                  <span className="lbl-t">Cart</span>
                  {count > 0 && <span className="badge">{count}</span>}
                </Link>
                {buyer !== null ? (
                  <Link href="/account/orders">
                    <Ic name="user" />
                    <span className="lbl-t">{String(buyer.name ?? '').split(' ')[0]}</span>
                  </Link>
                ) : (
                  <Link href="/login">
                    <Ic name="user" />
                    <span className="lbl-t">Sign in</span>
                  </Link>
                )}
                <Link className="biz" href="/sell">
                  For businesses
                </Link>
              </nav>
            </div>
          </header>

          {(flash.success || flash.error) && (
            <div className="wrap">
              {flash.success && (
                <div className="flash" role="status">
                  <Ic name="check" />
                  <span>{flash.success}</span>
                </div>
              )}
              {flash.error && (
                <div className="flash err" role="alert">
                  <Ic name="alert" />
                  <span>{flash.error}</span>
                </div>
              )}
            </div>
          )}
          <FlashClear t={flash.t} />

          <main>{children}</main>

          <footer className="ft">
            <div className="wrap">
              {brand} Market · Orders and bookings go straight to each shop · <Link href="/sell">List your shop</Link>
            </div>
          </footer>
        </LocationSheet>
      </body>
    </html>
  );
}
