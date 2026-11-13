/** Product card of the design build (.card-p) and the grid around it. */
import Link from 'next/link';
import { addToCart } from '@/app/actions/cart';
import { pOff } from '@/lib/format';
import { type Store } from '@/lib/store';
import { Icon, PThumb } from './Icon';
import { WishButton } from './WishButton';

type P = Record<string, any>;

export function ProductCard({ store, p, rank = 0 }: { store: Store; p: P; rank?: number }) {
  const id = Number(p.id);
  const off = pOff(p);
  const badge: [string, string] | null = off > 0 ? ['Offer', 'offer'] : p.badge ? [String(p.badge), 'hot'] : p.featured ? ['Hot', 'hot'] : null;
  const inStock = Boolean(p.in_stock ?? true);
  const stock = p.stock !== undefined && p.stock !== null ? Math.floor(Number(p.stock)) : null;
  const hasOptions = Array.isArray(p.variants) && p.variants.length > 0 && store.kind() !== 'menu';
  const warranty: string[] = Array.isArray(p.warranty) ? p.warranty : [];
  const sub = String(p.generic || p.brand || store.categoryName(Number(p.category_id ?? 0)));
  const href = `/p/${id}`;

  return (
    <article className="card-p">
      <Link href={href} className="cp-img">
        <PThumb p={p} />
      </Link>
      {rank > 0 && (
        <span className="rank">
          <Icon name="spark" />#{rank}
        </span>
      )}
      {badge && <span className={`badge ${badge[1]} ${rank ? 'low' : ''}`.trim()}>{badge[0]}</span>}
      {p.prescription_required && <span className="badge rx">Rx</span>}
      <WishButton id={id} label={`Save ${String(p.name)} to wishlist`} />
      <div className="cp-b">
        <small>{sub}</small>
        <Link href={href} className="cp-n">
          {String(p.name)}
        </Link>
        {warranty.length > 0 && (
          <span className="wty">
            <Icon name="shield" />
            {String(warranty[0])}
          </span>
        )}
        <div className="cp-f">
          <div className="cp-pr">
            <span className="pr">{store.money(p.price ?? 0)}</span>
            {p.unit ? <small className="per">/ {String(p.unit)}</small> : null}
            {off > 0 && (
              <span className="was">
                <s>{store.money(p.compare_at)}</s>
                <em>-{off}%</em>
              </span>
            )}
            {inStock && stock !== null && stock > 0 && stock <= 5 && <span className="st low">Only {stock} left</span>}
          </div>
          {!inStock ? (
            <span className="oos">Out of stock</span>
          ) : hasOptions ? (
            <Link className="rb" href={href} aria-label="Choose options">
              <Icon name="arrow" />
            </Link>
          ) : store.sells() ? (
            <form className="rb-f" action={addToCart} data-scroll-keep>
              <input type="hidden" name="product_id" value={id} />
              <input type="hidden" name="qty" value="1" />
              <button className="rb" aria-label={`Add ${String(p.name)} to cart`}>
                <Icon name="plus" />
              </button>
            </form>
          ) : null}
        </div>
      </div>
    </article>
  );
}

/** Product grid; `cls` adds a column class (g3, g5); `ranked` numbers the cards for "Trending". */
export function ProductGrid({ store, list, cls = '', ranked = false }: { store: Store; list: P[]; cls?: string; ranked?: boolean }) {
  if (list.length === 0) return <div className="empty-s">No products found.</div>;
  return (
    <div className={('grid ' + cls).trim()}>
      {list.map((p, i) => (
        <ProductCard key={String(p.id)} store={store} p={p} rank={ranked ? i + 1 : 0} />
      ))}
    </div>
  );
}
