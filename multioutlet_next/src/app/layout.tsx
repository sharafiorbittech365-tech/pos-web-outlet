import type { Metadata, Viewport } from 'next';
import type { CSSProperties, ReactNode } from 'react';
import '../styles/storefront.css';
import '../styles/storefront-app.css';
import '../styles/multistore.css';
import { Shell } from '@/components/Shell';
import { SiteErrorPage } from '@/components/SiteErrorPage';
import { getContext, isSiteError } from '@/lib/context';
import { readFlash } from '@/lib/flash';
import { pageMeta } from '@/lib/seo';
import { cartCount, cartOutlet } from '@/lib/services/cart';

export const viewport: Viewport = { width: 'device-width', initialScale: 1, viewportFit: 'cover' };

const FONTS = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Playfair+Display:wght@500;600;700&display=swap';

/** Default <head> for pages that set none (pages override with their own generateMetadata). */
export async function generateMetadata(): Promise<Metadata> {
  try {
    return pageMeta((await getContext()).site, {});
  } catch (e) {
    if (isSiteError(e)) return { title: 'Website', robots: { index: false } };
    throw e;
  }
}

function Head() {
  return (
    <head>
      <link rel="preconnect" href="https://fonts.googleapis.com" />
      <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="" />
      <link href={FONTS} rel="stylesheet" />
    </head>
  );
}

export default async function RootLayout({ children }: { children: ReactNode }) {
  let ctx;
  try {
    ctx = await getContext();
  } catch (e) {
    if (!isSiteError(e)) throw e;
    return (
      <html lang="en" style={{ ['--brand' as string]: e.site?.brandColor() ?? '#2E7D32' } as CSSProperties}>
        <Head />
        <body className={`t-${e.site?.theme() ?? 'fresh'}`}>
          <SiteErrorPage kind={e.kind} site={e.site} requestId={e.requestId} />
        </body>
      </html>
    );
  }

  const { site, host } = ctx;
  const [flash, count, outlet] = await Promise.all([readFlash(), cartCount(host), cartOutlet(host)]);
  const bodyStyle: Record<string, string> = {};
  if (site.accentColor() !== null) {
    bodyStyle['--gold'] = site.accentColor()!;
    bodyStyle['--accent'] = site.accentColor()!;
  }
  if (site.darkColor() !== null) {
    bodyStyle['--dark'] = site.darkColor()!;
    bodyStyle['--dark-2'] = `color-mix(in srgb, ${site.darkColor()} 86%, #fff)`;
  }

  return (
    <html lang="en" style={{ ['--brand' as string]: site.brandColor() } as CSSProperties}>
      <Head />
      <body className={`t-${site.theme()} k-shop ms`} style={bodyStyle as CSSProperties}>
        <Shell ctx={ctx} flash={flash} cartCount={count} cartOutlet={outlet}>
          {children}
        </Shell>
      </body>
    </html>
  );
}
