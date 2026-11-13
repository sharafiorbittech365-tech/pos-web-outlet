import type { Metadata, Viewport } from 'next';
import type { CSSProperties, ReactNode } from 'react';
import '../styles/storefront.css';
import '../styles/storefront-app.css';
import '../styles/storefront-modern.css';
import '../styles/variant-picker.css';
import '../styles/clinic.css';
import '../styles/product-detail.css';
import '../styles/site-pages.css';
import '../styles/storefront-interactive.css';
import { Shell } from '@/components/Shell';
import { StoreErrorPage } from '@/components/StoreErrorPage';
import { isApiError } from '@/lib/api/errors';
import { getContext, isStoreError, type StoreError } from '@/lib/context';
import { readFlash } from '@/lib/flash';
import { pageMeta } from '@/lib/seo';
import { cartCount as countCart, priced } from '@/lib/services/cart';
import { type PricedCart } from '@/lib/services/calculator';
import { getTable } from '@/lib/table-session';

export const viewport: Viewport = { width: 'device-width', initialScale: 1, viewportFit: 'cover' };

const FONTS =
  'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Playfair+Display:wght@500;600;700&family=Oswald:wght@500;600;700&family=Dancing+Script:wght@600;700&display=swap';

/** Default <head> for pages that set none (pages override with their own generateMetadata). */
export async function generateMetadata(): Promise<Metadata> {
  try {
    const { store } = await getContext();
    return pageMeta(store, {});
  } catch (e) {
    if (isStoreError(e)) return { title: 'Store', robots: { index: false } };
    throw e;
  }
}

function Frame({ children, className = '', style }: { children: ReactNode; className?: string; style?: CSSProperties }) {
  return (
    <html lang="en" style={style}>
      <head>
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="" />
        <link href={FONTS} rel="stylesheet" />
      </head>
      <body className={className}>{children}</body>
    </html>
  );
}

export default async function RootLayout({ children }: { children: ReactNode }) {
  let ctx;
  try {
    ctx = await getContext();
  } catch (e) {
    if (!isStoreError(e)) throw e;
    const err: StoreError = e;
    return (
      <Frame className={`t-${err.store?.theme() ?? 'fresh'}`} style={{ ['--brand' as string]: err.store?.brandColor() ?? '#2E7D32' }}>
        <StoreErrorPage kind={err.kind} store={err.store} requestId={err.requestId} />
      </Frame>
    );
  }

  const { store, host } = ctx;
  const [flash, table, count] = await Promise.all([readFlash(), getTable(host), countCart(host)]);
  let cart: PricedCart | null = null;
  if (count > 0 && store.sells()) {
    try {
      cart = await priced(ctx, table !== null ? 'pickup' : 'delivery');
    } catch (e) {
      if (!isApiError(e)) throw e; // the header still shows the count; the cart page reports the problem
    }
  }

  const bodyStyle: Record<string, string> = {};
  if (store.accentColor() !== null) {
    bodyStyle['--gold'] = store.accentColor()!;
    bodyStyle['--accent'] = store.accentColor()!;
  }
  if (store.darkColor() !== null) {
    bodyStyle['--dark'] = store.darkColor()!;
    bodyStyle['--dark-2'] = `color-mix(in srgb, ${store.darkColor()} 86%, #fff)`;
  }

  return (
    <html lang="en" style={{ ['--brand' as string]: store.brandColor() } as CSSProperties}>
      <head>
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="" />
        <link href={FONTS} rel="stylesheet" />
      </head>
      <body className={`t-${store.theme()} k-${store.kind()}`} style={bodyStyle as CSSProperties}>
        <Shell ctx={ctx} flash={flash} table={table} cartCount={count} cart={cart}>
          {children}
        </Shell>
      </body>
    </html>
  );
}
