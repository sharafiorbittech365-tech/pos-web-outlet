/** Page metadata (title, description, canonical, Open Graph) — the <head> part of the PHP layout. */
import type { Metadata } from 'next';
import { safeImgUrl } from './format';
import { type Site } from './site';

export interface PageMeta {
  title?: string;
  description?: string;
  /** canonical path, e.g. "/outlets/3" */
  path?: string;
  image?: string;
  noindex?: boolean;
}

export function pageMeta(site: Site, meta: PageMeta = {}): Metadata {
  const title = meta.title ? `${meta.title} · ${site.name()}` : site.seoTitle();
  const description = meta.description || site.seoDescription();
  const host = site.publicHost();
  const canonical = host !== '' && meta.path ? `https://${host}${meta.path}` : '';
  const og = safeImgUrl(meta.image ?? '') || safeImgUrl(site.brand().hero_image ?? '');
  const favicon = safeImgUrl(site.brand().favicon_url ?? '');
  return {
    title: { absolute: title },
    description,
    robots: meta.noindex ? { index: false, follow: true } : undefined,
    alternates: canonical !== '' ? { canonical } : undefined,
    icons: favicon !== '' ? { icon: favicon } : { icon: [{ url: '/assets/multistore/icon.svg', type: 'image/svg+xml' }] },
    openGraph: {
      siteName: site.name(),
      title,
      description,
      type: 'website',
      url: canonical !== '' ? canonical : undefined,
      // root-relative pictures are not valid Open Graph images (the PHP layout skipped them too)
      images: og !== '' && !og.startsWith('/') ? [og] : undefined,
    },
  };
}
