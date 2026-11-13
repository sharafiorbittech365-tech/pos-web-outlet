import type { Metadata } from 'next';
import Link from 'next/link';
import { removeFromCart, updateCart } from '@/app/actions/cart';
import { Icon, PThumb } from '@/components/Icon';
import { getContext } from '@/lib/context';
import { pageMeta } from '@/lib/seo';
import { cartCount, priced } from '@/lib/services/cart';

export async function generateMetadata(): Promise<Metadata> {
  const { store } = await getContext();
  return pageMeta(store, { title: 'Your cart', noindex: true });
}

export default async function CartPage() {
  const ctx = await getContext();
  const { store, host } = ctx;
  const cart = (await cartCount(host)) > 0 ? await priced(ctx) : null;

  if (cart === null || cart.lines.length === 0) {
    return (
      <section className="wrap sec narrow">
        <div className="empty-s big">
          <Icon name="cart" />
          <h2>Your cart is empty</h2>
          <Link className="btn-p" href="/">
            Start {store.kind() === 'menu' ? 'ordering' : 'shopping'}
          </Link>
        </div>
      </section>
    );
  }

  return (
    <section className="wrap sec">
      <h1>Your cart</h1>
      {cart.issues.map((msg) => (
        <div className="sf-alert warn" key={msg}>
          <Icon name="alert" />
          <span>{msg}</span>
        </div>
      ))}
      <div className="co">
        <div className="co-main">
          {cart.lines.map((l) => (
            <div className="cl" key={l.index}>
              <PThumb p={l.product} size="sm" />
              <div className="grow">
                <Link href={`/p/${l.product_id}`}>
                  <b>{l.name}</b>
                </Link>
                {l.variant !== '' && <small>{l.variant}</small>}
                <small>
                  {store.money(l.unit)} each{l.rx ? ' · Rx' : ''}
                  {!l.in_stock && (
                    <>
                      {' · '}
                      <span className="st out">Out of stock</span>
                    </>
                  )}
                </small>
              </div>
              <div className="qty sm">
                <form action={updateCart} className="rb-f">
                  <input type="hidden" name="index" value={l.index} />
                  <input type="hidden" name="d" value="-1" />
                  <button aria-label="One less">
                    <Icon name="minus" />
                  </button>
                </form>
                <span>{l.qty}</span>
                <form action={updateCart} className="rb-f">
                  <input type="hidden" name="index" value={l.index} />
                  <input type="hidden" name="d" value="1" />
                  <button aria-label="One more" disabled={l.qty >= l.max}>
                    <Icon name="plus" />
                  </button>
                </form>
              </div>
              <b className="cl-t">{store.money(l.line_total)}</b>
              <form action={removeFromCart} className="rb-f">
                <input type="hidden" name="index" value={l.index} />
                <button className="x" aria-label={`Remove ${l.name}`}>
                  <Icon name="x" />
                </button>
              </form>
            </div>
          ))}
        </div>
        <aside className="co-sum">
          <h3>Order summary</h3>
          <div className="r">
            <span>Subtotal</span>
            <b>{store.money(cart.subtotal)}</b>
          </div>
          <div className="r">
            <span>Delivery</span>
            <b>{cart.shipping > 0 ? store.money(cart.shipping) : 'Free'}</b>
          </div>
          {cart.free_left > 0 && (
            <>
              <div className="freebar">
                <i style={{ width: `${Math.trunc(cart.free_progress)}%` }} />
              </div>
              <small>Add {store.money(cart.free_left)} more for free delivery</small>
            </>
          )}
          <div className="r tot">
            <span>Total</span>
            <b>{store.money(cart.total)}</b>
          </div>
          {cart.orderable ? (
            <Link className="btn-p full" href="/checkout">
              <Icon name="lock" />
              Checkout
            </Link>
          ) : (
            <>
              <button className="btn-p full" disabled>
                <Icon name="lock" />
                Checkout
              </button>
              <small className="muted">Remove or change the items marked above to continue.</small>
            </>
          )}
          <Link className="link" href="/">
            Continue shopping
          </Link>
          <small className="muted">The final total is confirmed by the shop when you place the order.</small>
        </aside>
      </div>
    </section>
  );
}
