/** The cart: one outlet, lines priced live from the API, delivery threshold, checkout (Cart::index). */
import Link from 'next/link';
import type { Metadata } from 'next';
import { clearCartAction, removeFromCart, updateCart } from '@/app/actions/cart';
import { Icon, PThumb } from '@/components/Icon';
import { ConfirmForm } from '@/components/ShellClient';
import { getContext } from '@/lib/context';
import { sfMoney, sfUrl } from '@/lib/format';
import { pageMeta } from '@/lib/seo';
import { priced } from '@/lib/services/cart';

export async function generateMetadata(): Promise<Metadata> {
  return pageMeta((await getContext()).site, { title: 'Your cart', noindex: true });
}

export default async function CartPage() {
  const ctx = await getContext();
  const cart = await priced(ctx);
  const cur = ctx.site.currency();
  if (cart === null || cart.lines.length === 0) {
    return (
      <section className="wrap sec narrow">
        <div className="empty-s big">
          <Icon name="cart" />
          <h2>Your cart is empty</h2>
          <Link className="btn-p" href="/products">
            Start shopping
          </Link>
        </div>
      </section>
    );
  }
  const outlet = cart.outlet;

  return (
    <section className="wrap sec">
      <h1>Your cart</h1>
      {outlet !== null && (
        <div className="ms-cartnote">
          <Icon name="store" />
          <span>
            Ordering from <b>{String(outlet.name)}</b>
            {outlet.area ? ` · ${String(outlet.area)}` : ''}. One order is prepared and delivered by one outlet.
          </span>
          <ConfirmForm action={clearCartAction} message="Empty your cart?" className="rb-f">
            <button className="btn-g sm" type="submit">
              Empty cart to switch outlet
            </button>
          </ConfirmForm>
        </div>
      )}
      {cart.issues.map((msg) => (
        <div key={msg} className="sf-alert warn">
          <Icon name="alert" />
          <span>{msg}</span>
        </div>
      ))}
      <div className="co">
        <div className="co-main">
          {cart.lines.map((l) => (
            <div key={l.index} className="cl">
              <PThumb p={l.product} size="sm" />
              <div className="grow">
                <Link href={`/p/${l.item_id}`}>
                  <b>{l.name}</b>
                </Link>
                {l.variant !== '' && <small>{l.variant}</small>}
                <small>
                  {sfMoney(l.unit, cur)} each{l.rx ? ' · Rx' : ''}
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
              <b className="cl-t">{sfMoney(l.line_total, cur)}</b>
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
            <b>{sfMoney(cart.subtotal, cur)}</b>
          </div>
          <div className="r">
            <span>Delivery</span>
            <b>{cart.shipping > 0 ? sfMoney(cart.shipping, cur) : 'Free'}</b>
          </div>
          {cart.free_left > 0 && (
            <>
              <div className="freebar">
                <i style={{ width: `${cart.free_progress}%` }} />
              </div>
              <small>Add {sfMoney(cart.free_left, cur)} more for free delivery</small>
            </>
          )}
          <div className="r tot">
            <span>Total</span>
            <b>{sfMoney(cart.total, cur)}</b>
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
          <Link className="link" href={outlet !== null ? sfUrl('/products', { outlet: String(outlet.id) }) : '/products'}>
            Continue shopping{outlet !== null ? ` at ${String(outlet.name)}` : ''}
          </Link>
          <small className="muted">The final total is confirmed by the outlet when you place the order.</small>
        </aside>
      </div>
    </section>
  );
}
