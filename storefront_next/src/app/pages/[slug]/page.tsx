/** An information page written in POS › Website › Pages (Delivery & returns, Privacy policy, Terms & conditions, About us, …). */
import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { isApiError } from '@/lib/api/errors';
import { getContext } from '@/lib/context';
import { pageMeta } from '@/lib/seo';

type Props = { params: Promise<{ slug: string }> };

async function load(slug: string) {
  if (!/^[a-z0-9-]{1,80}$/.test(slug)) notFound();
  const { store, api } = await getContext();
  try {
    return { store, page: await api.page(slug) };
  } catch (e) {
    if (isApiError(e) && e.status === 404) notFound();
    throw e;
  }
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const { store, page } = await load(slug);
  return pageMeta(store, { title: String(page.title), description: page.seo_description ? String(page.seo_description) : undefined, path: '/pages/' + slug });
}

export default async function InfoPage({ params }: Props) {
  const { slug } = await params;
  const { page } = await load(slug);
  return (
    <section className="wrap sec narrow prose">
      <nav className="crumbs">
        <Link href="/">Home</Link> / <span>{String(page.title)}</span>
      </nav>
      <h1>{String(page.title)}</h1>
      {/* body_html is produced by the API from the shop's text with everything escaped (SitePages::render) */}
      <div className="pg-body" dangerouslySetInnerHTML={{ __html: String(page.body_html ?? '') }} />
    </section>
  );
}
