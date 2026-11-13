/** Page metadata (title, description, canonical, Open Graph) — the <head> part of the PHP layout. */
import type { Metadata } from 'next';
import { safeImgUrl } from './format';
import { type Store } from './store';

export interface PageMeta {
  title?: string;
  description?: string;
  /** canonical path, e.g. "/c/3" */
  path?: string;
  image?: string;
  type?: string;
  noindex?: boolean;
}

export function pageMeta(store: Store, meta: PageMeta = {}): Metadata {
  const title = meta.title ? `${meta.title} · ${store.name()}` : store.seoTitle();
  const description = meta.description || store.seoDescription();
  const host = store.publicHost();
  const canonical = host !== '' && meta.path ? `https://${host}${meta.path}` : '';
  let og = safeImgUrl(meta.image ?? '') || safeImgUrl(store.brand().heroImg ?? '');
  if (og !== '' && og.startsWith('/') && host !== '') og = `https://${host}${og}`;
  const favicon = safeImgUrl(store.brand().favicon ?? '');
  return {
    title: { absolute: title },
    description,
    robots: meta.noindex ? { index: false, follow: true } : undefined,
    alternates: canonical !== '' ? { canonical } : undefined,
    icons: favicon !== '' ? { icon: favicon } : { icon: [{ url: '/assets/storefront/icon.svg', type: 'image/svg+xml' }] },
    openGraph: {
      siteName: store.name(),
      title,
      description,
      type: (meta.type === 'product' ? 'website' : 'website') as 'website',
      url: canonical !== '' ? canonical : undefined,
      images: og !== '' ? [og] : undefined,
    },
    twitter: { card: 'summary_large_image' },
  };
}
