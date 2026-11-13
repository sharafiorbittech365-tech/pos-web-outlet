'use client';
/**
 * Small client islands of the shop shell (the interactive parts of storefront-app.js):
 * mobile nav toggle, back-to-top, cart drawer, mobile cart bar and the newsletter form.
 */
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { createContext, useContext, useEffect, useLayoutEffect, useState, type ReactNode } from 'react';
import { Icon } from './Icon';
import { useFlash, useToast } from './Toast';

// ---- header with the mobile nav toggle ---------------------------------------------------------
const NavCtx = createContext<{ open: boolean; toggle: () => void }>({ open: false, toggle: () => {} });

export function HeaderFrame({ children }: { children: ReactNode }) {
  const [open, setOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);
  const path = usePathname();
  useEffect(() => setOpen(false), [path]);
  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 10);
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
    return () => window.removeEventListener('scroll', onScroll);
  }, []);
  return (
    <NavCtx.Provider value={{ open, toggle: () => setOpen((o) => !o) }}>
      <header className={'hd' + (open ? ' nav-open' : '') + (scrolled ? ' scrolled' : '')}>{children}</header>
    </NavCtx.Provider>
  );
}

export function NavToggle({ children }: { children: ReactNode }) {
  const { open, toggle } = useContext(NavCtx);
  return (
    <button className="hd-menu" type="button" aria-label="Menu" aria-expanded={open} onClick={toggle}>
      {children}
    </button>
  );
}

export function BackToTop() {
  const [vis, setVis] = useState(false);
  useEffect(() => {
    const onScroll = () => setVis(window.scrollY > 400);
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);
  return (
    <button
      className={'fab' + (vis ? ' visible' : '')}
      data-top=""
      type="button"
      aria-label="Back to top"
      onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}
    >
      <Icon name="chevl" />
    </button>
  );
}

// ---- cart drawer -------------------------------------------------------------------------------
/** Slide-in cart opened after "add to cart". `children` is the server-rendered cart summary. */
export function Drawer({ children }: { children: ReactNode }) {
  const flash = useFlash();
  const [open, setOpen] = useState(false);
  const path = usePathname();

  // a new "added" flash opens it; leaving the page closes it
  useEffect(() => {
    if (flash.added && flash.t !== undefined) setOpen(true);
  }, [flash.added, flash.t]);
  useEffect(() => setOpen(false), [path]);
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, []);

  return (
    <DrawerCtx.Provider value={() => setOpen(false)}>
      <div id="drawer" className={'drawer' + (open ? ' open' : '')} aria-hidden={!open}>
        {open ? children : null}
      </div>
    </DrawerCtx.Provider>
  );
}

const DrawerCtx = createContext<() => void>(() => {});

/** Anything inside the drawer that should close it (background, ✕ button). */
export function DrawerClose({ className, children, label }: { className?: string; children?: ReactNode; label?: string }) {
  const close = useContext(DrawerCtx);
  return (
    <button type="button" className={className} aria-label={label} onClick={close}>
      {children}
    </button>
  );
}

// ---- floating bottom bar on phones ---------------------------------------------------------------
export function MobileCartBar({ kind, count, subtotal, cta }: { kind: 'cart' | 'book'; count: number; subtotal: string; cta?: string }) {
  const path = usePathname();
  if (kind === 'cart') {
    const hidden = count === 0 || path === '/cart' || path === '/checkout';
    return (
      <Link className={'m-cart' + (hidden ? ' hide' : '')} href="/cart">
        <Icon name="cart" />
        <span>
          {count} item{count === 1 ? '' : 's'} · {subtotal}
        </span>
        <b>View cart</b>
      </Link>
    );
  }
  if (path.startsWith('/book')) return null;
  return (
    <Link className="m-cart" href="/book">
      <Icon name="cal" />
      <span>{cta}</span>
      <b>Book</b>
    </Link>
  );
}

// ---- scroll keeper: preserve position after add-to-cart redirect ----------------------------
/**
 * Forms that add to cart should carry data-scroll-keep.
 * Before submit we snapshot scrollY + pathname; after the server-action redirect
 * lands on the same page we restore it synchronously (useLayoutEffect = before paint).
 */
export function ScrollKeeper() {
  // Phase 1 – save before submit
  useEffect(() => {
    const onSubmit = (e: Event) => {
      const form = e.target as HTMLFormElement | null;
      if (!form?.dataset.scrollKeep) return;
      try {
        sessionStorage.setItem(
          '_sfsv',
          JSON.stringify({ y: Math.round(window.scrollY), p: location.pathname + location.search, t: Date.now() }),
        );
      } catch {}
    };
    document.addEventListener('submit', onSubmit, true);
    return () => document.removeEventListener('submit', onSubmit, true);
  }, []);

  // Phase 2 – restore before paint (no deps: runs each commit, key consumed on first hit)
  useLayoutEffect(() => {
    try {
      const raw = sessionStorage.getItem('_sfsv');
      if (!raw) return;
      const { y, p, t } = JSON.parse(raw) as { y: number; p: string; t: number };
      sessionStorage.removeItem('_sfsv');
      if (p !== location.pathname + location.search) return;
      if (Date.now() - t > 10_000) return; // stale — ignore
      window.scrollTo({ top: y, behavior: 'instant' });
    } catch {}
  });

  return null;
}

// ---- newsletter ----------------------------------------------------------------------------------
export function NewsletterForm() {
  const toast = useToast();
  return (
    <form
      className="nl-f"
      onSubmit={(e) => {
        e.preventDefault();
        toast('Thanks! You are subscribed.');
        e.currentTarget.reset();
      }}
    >
      <input type="email" required placeholder="Your email address" aria-label="Email" />
      <button className="btn-w" type="submit">
        <Icon name="check" />
        Subscribe
      </button>
    </form>
  );
}
