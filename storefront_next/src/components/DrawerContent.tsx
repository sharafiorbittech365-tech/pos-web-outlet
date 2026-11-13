/** The inside of the cart drawer (server-rendered; only shown after an item was added). */
import Link from 'next/link';
import { type PricedCart } from '@/lib/services/calculator';
import { type Store } from '@/lib/store';
import { Icon, PThumb } from './Icon';
import { DrawerClose } from './ShellClient';

export function DrawerContent({ store, cart }: { store: Store; cart: PricedCart }) {
  return (
    <>
      <DrawerClose className="dr-bg" label="Close" />
      <aside className="dr" aria-label="Cart">
        <div className="dr-h">
          <h3>
            {store.kind() === 'menu' ? 'Your order' : 'Cart'} ({cart.count})
          </h3>
          <DrawerClose className="x" label="Close">
            <Icon name="x" />
          </DrawerClose>
        </div>
        <div className="dr-b">
          {cart.lines.map((l) => (
            <div className="cl" key={`${l.index}-${l.product_id}`}>
              <PThumb p={l.product} size="sm" />
              <div className="grow">
                <b>{l.name}</b>
                {l.variant !== '' && <small>{l.variant}</small>}
                <small>
                  {l.qty} × {store.money(l.unit)}
                </small>
              </div>
            </div>
          ))}
        </div>
        <div className="dr-f">
          <div className="r tot">
            <span>Subtotal</span>
            <b>{store.money(cart.subtotal)}</b>
          </div>
          <Link className="btn-p full" href="/checkout">
            <Icon name="lock" />
            Checkout
          </Link>
          <Link className="btn-g full" href="/cart">
            View cart
          </Link>
        </div>
      </aside>
    </>
  );
}
