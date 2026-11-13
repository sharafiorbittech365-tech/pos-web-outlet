import type { Metadata } from 'next';
import Link from 'next/link';
import { AboutBlock, ReviewsBlock } from '@/components/Blocks';
import { Icon } from '@/components/Icon';
import { Accent } from '@/components/Section';
import { getContext } from '@/lib/context';
import { accentSplit } from '@/lib/format';
import { pageMeta } from '@/lib/seo';

export async function generateMetadata(): Promise<Metadata> {
  const { store } = await getContext();
  return pageMeta(store, { title: 'About us', path: '/about' });
}

export default async function AboutPage() {
  const { store } = await getContext();
  const b = store.business;
  const text =
    String(store.brand().about ?? '').trim() ||
    String(store.site.seo?.description ?? '') ||
    `${store.name()} serves customers in ${String(b.area ?? b.city ?? 'the city')} with care, fair prices and friendly support.`;
  const [lead, tail] = accentSplit('About ' + store.name());
  const button = store.sells() ? (
    <Link className="btn-p" href="/all">
      Start shopping <Icon name="arrow" />
    </Link>
  ) : store.canBook() ? (
    <Link className="btn-p" href="/book">
      {store.cta()} <Icon name="arrow" />
    </Link>
  ) : null;

  return (
    <>
      <section className="wrap sec">
        <nav className="crumbs">
          <Link href="/">Home</Link> / <span>About us</span>
        </nav>
      </section>
      <AboutBlock
        store={store}
        eyebrow="About us"
        title={<Accent lead={lead} tail={tail} />}
        text={text}
        stats={[
          ['store', store.typeTag(), 'What we do'],
          ['pin', String(b.area || b.city || ''), 'Where we are'],
          ['phone', store.phone(), 'Call us'],
        ]}
        button={button}
      />
      {store.section('reviews') && <ReviewsBlock kind={store.kind()} />}
    </>
  );
}
