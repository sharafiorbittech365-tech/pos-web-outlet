/** Menu / price list rows of the design (.menu-list .mi). */
import Link from 'next/link';
import { addToCart } from '@/app/actions/cart';
import { strim, variantNames } from '@/lib/format';
import { type Store } from '@/lib/store';
import { Icon, PThumb } from './Icon';

type P = Record<string, any>;

export function MenuList({ store, list }: { store: Store; list: P[] }) {
  return (
    <div className="menu-list">
      {list.map((p) => {
        const id = Number(p.id);
        const vn = variantNames(p);
        const line = vn.length > 0 ? vn.join(' · ') : String(p.description || store.categoryName(Number(p.category_id ?? 0)));
        return (
          <div className="mi" key={id}>
            <Link href={`/p/${id}`} className="mi-img">
              <PThumb p={p} size="sm" />
            </Link>
            <div className="grow">
              <Link href={`/p/${id}`}>
                <b>{String(p.name)}</b>
              </Link>
              <small>{strim(line, 120)}</small>
              <span className="pr">
                {vn.length > 0 ? 'from ' : ''}
                {store.money(p.price ?? 0)}
                {p.unit ? (
                  <>
                    {' '}
                    <small>/ {String(p.unit)}</small>
                  </>
                ) : null}
              </span>
            </div>
            {!p.in_stock ? (
              <span className="st out">Sold out</span>
            ) : vn.length > 0 ? (
              <Link className="mi-add" href={`/p/${id}`} aria-label={`Choose an option for ${String(p.name)}`}>
                <Icon name="plus" />
              </Link>
            ) : store.sells() ? (
              <form className="rb-f" action={addToCart} data-scroll-keep>
                <input type="hidden" name="product_id" value={id} />
                <input type="hidden" name="qty" value="1" />
                <input type="hidden" name="stay" value="1" />
                <button className="mi-add" aria-label={`Add ${String(p.name)}`}>
                  <Icon name="plus" />
                </button>
              </form>
            ) : store.canBook() ? (
              <Link className="svc-b" href={`/book?service=${id}`}>
                Book
              </Link>
            ) : null}
          </div>
        );
      })}
    </div>
  );
}
