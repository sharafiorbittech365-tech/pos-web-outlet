/** Cart, one block per shop — each shop becomes its own order (CartController::index). */
import Link from 'next/link';
import type { Metadata } from 'next';
import type { CSSProperties } from 'react';
import { removeShop, updateCart } from '@/app/actions/cart';
import { Ic, SymIcon } from '@/components/Ic';
import { AutoNumber } from '@/components/MarketClient';
import { marketApi } from '@/lib/api/market-api';
import { qty } from '@/lib/format';
import { cssColor, kmStr, mkPrice } from '@/lib/market';
import { getCart } from '@/lib/visitor';

export const metadata: Metadata = { title: 'Your cart' };

export default async function CartPage() {
  const cart = await getCart();
  const groups: any[] = Object.keys(cart).length > 0 ? (await (await marketApi()).quote(cart)).groups : [];
  let grand = 0;
  let ok = 0;

  return (
    <div className="wrap page mid">
      <h1 className="page-h">Your cart</h1>
      {groups.length === 0 ? (
        <div className="empty">
          <Ic name="shopping-cart" className="big" />
          <b>Your cart is empty</b>
          <span>Find something near you and add it here.</span>
          <Link className="btn primary" href="/">
            Browse the market
          </Link>
        </div>
      ) : (
        <>
          <p className="muted">Each shop prepares and delivers its own order.</p>
          {groups.map((g) => {
            const s = g.shop;
            const fine = g.error === null && s !== null;
            if (fine) {
              grand += Number(g.total);
              ok++;
            }
            return (
              <section key={g.business_id} className="panel cart-g">
                <header>
                  {s !== null && (
                    <span className="sh-ic" style={{ '--c': cssColor(s.color) } as CSSProperties}>
                      <SymIcon name={s.logo_icon} />
                    </span>
                  )}
                  <div className="grow">
                    <b>{String(s?.name ?? 'Shop')}</b>
                    {s !== null && (
                      <small>
                        {String(s.type_name)} · {String(s.area || s.city || '')}
                        {s.distance_km !== null ? ` · ${kmStr(s.distance_km)}` : ''}
                      </small>
                    )}
                  </div>
                  <form action={removeShop}>
                    <input type="hidden" name="business_id" value={g.business_id} />
                    <button className="btn sm danger" type="submit">
                      <Ic name="trash" />
                      Remove
                    </button>
                  </form>
                </header>
                {g.error !== null && <p className="note warn">{String(g.error)}</p>}
                {(g.lines as any[]).map((l) => (
                  <div key={l.key} className="cart-line">
                    <div>
                      <b>{String(l.name)}</b>
                      {l.variant_name ? <small>{String(l.variant_name)}</small> : null}
                    </div>
                    <form action={updateCart} key={`${l.key}:${l.qty}`}>
                      <input type="hidden" name="business_id" value={g.business_id} />
                      <input type="hidden" name="key" value={l.key} />
                      <AutoNumber className="in" name="qty" min={0} max={99} step={1} defaultValue={qty(l.qty)} aria-label="Quantity" />
                      <button className="btn sm js-hide" type="submit">
                        Update
                      </button>
                    </form>
                    <span className="amt">{mkPrice(l.amount, s?.currency)}</span>
                  </div>
                ))}
                {fine && (
                  <>
                    <div className="sum">
                      <span>Items{g.tax_added > 0 ? ` (incl. ${String(s.tax?.tax_label ?? 'VAT')})` : ''}</span>
                      <b>{mkPrice(g.goods, s.currency)}</b>
                      <span>{g.fulfilment === 'pickup' ? 'Pickup at the shop' : 'Delivery'}</span>
                      <b>{g.shipping > 0 ? mkPrice(g.shipping, s.currency) : 'Free'}</b>
                      <span className="t">Shop total</span>
                      <b className="t">{mkPrice(g.total, s.currency)}</b>
                    </div>
                    {s.free_above !== null && g.fulfilment === 'delivery' && g.shipping > 0 && (
                      <p className="note">
                        Free delivery from {mkPrice(s.free_above, s.currency)} at {String(s.name)}.
                      </p>
                    )}
                  </>
                )}
              </section>
            );
          })}
          {ok > 0 && (
            <>
              <div className="grand">
                <span>
                  Total ({ok} {ok === 1 ? 'order' : 'orders'})
                </span>
                <span>{mkPrice(grand)}</span>
              </div>
              <Link className="btn primary full" href="/checkout">
                <Ic name="check" />
                Checkout
              </Link>
            </>
          )}
        </>
      )}
    </div>
  );
}
