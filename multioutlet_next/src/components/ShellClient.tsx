'use client';
/**
 * Client islands of the website shell (the interactive parts of storefront-app.js / multistore.js):
 * mobile nav toggle, header search (keeps ?q= and ?outlet=), back-to-top, the "your cart is from …"
 * note and the mobile cart bar (hidden on the cart / checkout pages), quantity steppers and
 * confirm-before-submit forms.
 */
import Link from 'next/link';
import { usePathname, useSearchParams } from 'next/navigation';
import { createContext, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { Icon } from './Icon';

// ---- header with the mobile nav toggle ---------------------------------------------------------
const NavCtx = createContext<{ open: boolean; toggle: () => void }>({ open: false, toggle: () => {} });

export function HeaderFrame({ children }: { children: ReactNode }) {
  const [open, setOpen] = useState(false);
  const path = usePathname();
  useEffect(() => setOpen(false), [path]);
  return (
    <NavCtx.Provider value={{ open, toggle: () => setOpen((o) => !o) }}>
      <header className={'hd' + (open ? ' nav-open' : '')}>{children}</header>
    </NavCtx.Provider>
  );
}

export function NavToggle() {
  const { open, toggle } = useContext(NavCtx);
  return (
    <button className="hd-menu" type="button" aria-label="Menu" aria-expanded={open} onClick={toggle}>
      <Icon name="menu" />
    </button>
  );
}

/** The header search: outlet select + text, prefilled from the current query string. */
export function HeaderSearch({ outlets }: { outlets: { id: number; name: string }[] }) {
  const sp = useSearchParams();
  const cur = sp.get('outlet') ?? '';
  const q = sp.get('q') ?? '';
  return (
    <form className="search" method="get" action="/products" role="search" key={cur + '|' + q}>
      <select name="outlet" aria-label="Outlet" defaultValue={cur}>
        <option value="">All outlets</option>
        {outlets.map((o) => (
          <option key={o.id} value={o.id}>
            {o.name}
          </option>
        ))}
      </select>
      <input name="q" defaultValue={q} placeholder="Search products in every outlet…" aria-label="Search" maxLength={100} />
      <button aria-label="Search">
        <Icon name="search" />
        <span>Search</span>
      </button>
    </form>
  );
}

export function BackToTop() {
  return (
    <div className="fabs">
      <button className="fab" type="button" data-top="" aria-label="Back to top" onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}>
        <Icon name="chevl" />
      </button>
    </div>
  );
}

const CART_PAGES = (p: string): boolean => p === '/cart' || p === '/checkout' || p.startsWith('/order/');

/** "Your cart is from <outlet>" — on every page except cart, checkout and the order confirmation. */
export function CartNote({ cartName }: { cartName: string }) {
  const path = usePathname();
  if (cartName === '' || CART_PAGES(path)) return null;
  return (
    <div className="wrap">
      <div className="ms-cartnote" role="status">
        <Icon name="cart" />
        <span>
          Your cart is from <b>{cartName}</b>. One order is prepared by one outlet.
        </span>
        <Link href="/cart">View cart</Link>
      </div>
    </div>
  );
}

export function MobileCart({ count, cartName }: { count: number; cartName: string }) {
  const path = usePathname();
  const hide = count === 0 || path === '/cart' || path === '/checkout';
  return (
    <Link className={'m-cart' + (hide ? ' hide' : '')} href="/cart">
      <Icon name="cart" />
      <span>
        {count} item{count === 1 ? '' : 's'}
        {cartName !== '' ? ` · ${cartName}` : ''}
      </span>
      <b>View cart</b>
    </Link>
  );
}

/** Quantity input with −/+ buttons (the data-step behaviour of storefront-app.js). */
export function QtyField({ max = 99, name = 'qty', label = 'Quantity' }: { max?: number; name?: string; label?: string }) {
  const ref = useRef<HTMLInputElement>(null);
  const step = (d: number) => {
    const inp = ref.current;
    if (!inp) return;
    inp.value = String(Math.min(max, Math.max(1, (Number(inp.value) || 1) + d)));
  };
  return (
    <div className="qty sm">
      <button type="button" data-step="-1" aria-label="Less" onClick={() => step(-1)}>
        <Icon name="minus" />
      </button>
      <input ref={ref} name={name} type="number" defaultValue={1} min={1} max={max} inputMode="numeric" aria-label={label} />
      <button type="button" data-step="1" aria-label="More" onClick={() => step(1)}>
        <Icon name="plus" />
      </button>
    </div>
  );
}

/** A form that asks for confirmation first (data-confirm of multistore.js). */
export function ConfirmForm({ action, message, className, children }: { action: (fd: FormData) => void | Promise<void>; message: string; className?: string; children: ReactNode }) {
  return (
    <form
      action={action}
      className={className}
      onSubmit={(e) => {
        if (!window.confirm(message)) e.preventDefault();
      }}
    >
      {children}
    </form>
  );
}
