'use client';
/** Wishlist (this browser only, in the sf_wish cookie — the /wishlist page reads the same cookie). */
import { useSyncExternalStore } from 'react';
import { Icon } from './Icon';
import { useToast } from './Toast';

const EVENT = 'sf-wish';

function read(): string {
  const m = document.cookie.match(/(?:^|; )sf_wish=([^;]*)/);
  return m ? decodeURIComponent(m[1]) : '';
}

const ids = (raw: string): string[] => raw.split(',').filter((x) => /^\d+$/.test(x));

function subscribe(cb: () => void): () => void {
  window.addEventListener(EVENT, cb);
  return () => window.removeEventListener(EVENT, cb);
}

/** Current wishlist ids; empty while rendering on the server so hydration matches. */
export function useWish(): string[] {
  const raw = useSyncExternalStore(subscribe, read, () => '');
  return ids(raw);
}

function toggle(id: string): boolean {
  let w = ids(read());
  const on = w.includes(id);
  w = on ? w.filter((x) => x !== id) : [...w, id];
  document.cookie = `sf_wish=${encodeURIComponent(w.slice(-60).join(','))}; path=/; max-age=${180 * 86400}; samesite=lax`;
  window.dispatchEvent(new Event(EVENT));
  return !on;
}

export function WishButton({ id, label }: { id: number; label: string }) {
  const wish = useWish();
  const toast = useToast();
  const on = wish.includes(String(id));
  return (
    <button
      className={'wish' + (on ? ' on' : '')}
      type="button"
      aria-label={label}
      aria-pressed={on}
      onClick={(e) => {
        e.preventDefault();
        toast(toggle(String(id)) ? 'Saved to wishlist' : 'Removed from wishlist');
      }}
    >
      <Icon name="heart" />
    </button>
  );
}

/** Header badge with the number of saved products. */
export function WishCount() {
  const n = useWish().length;
  return <i className={'dot' + (n ? '' : ' zero')}>{n}</i>;
}
