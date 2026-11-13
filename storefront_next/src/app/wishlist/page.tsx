import type { Metadata } from 'next';
import Link from 'next/link';
import { cookies } from 'next/headers';
import { Icon } from '@/components/Icon';
import { ProductGrid } from '@/components/ProductCard';
import { getContext } from '@/lib/context';
import { pageMeta } from '@/lib/seo';
import { CatalogService } from '@/lib/services/catalog';

export async function generateMetadata(): Promise<Metadata> {
  const { store } = await getContext();
  return pageMeta(store, { title: 'Wishlist', noindex: true });
}

export default async function WishlistPage() {
  const { store, api } = await getContext();
  const raw = (await cookies()).get('sf_wish')?.value ?? '';
  const ids = raw
    .split(',')
    .map((x) => Math.trunc(Number(x)) || 0)
    .filter((x) => x > 0)
    .slice(0, 60);
  const list = ids.length === 0 ? [] : (await new CatalogService(api, store).all()).filter((p) => ids.includes(Number(p.id)));

  return (
    <section className="wrap sec">
      <nav className="crumbs">
        <Link href="/">Home</Link> / <span>Wishlist</span>
      </nav>
      <div className="st-h row">
        <h1>Your wishlist</h1>
      </div>
      {list.length === 0 ? (
        <div className="empty-s big">
          <Icon name="heart" />
          <h2>Nothing saved yet</h2>
          <p className="muted">Tap the heart on any product to keep it here.</p>
          <Link className="btn-p" href="/all">
            Browse products
          </Link>
        </div>
      ) : (
        <ProductGrid store={store} list={list} />
      )}
    </section>
  );
}
