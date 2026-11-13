'use client';
/** Horizontal scroll row with prev/next buttons ([data-row] / [data-scroll]). */
import { useRef, type ReactNode } from 'react';
import { Icon } from './Icon';

export function ScrollRow({ children, wrapClass = 'catrow-w', rowClass = 'catrow' }: { children: ReactNode; wrapClass?: string; rowClass?: string }) {
  const row = useRef<HTMLDivElement>(null);
  const scroll = (d: number) => {
    const r = row.current;
    if (r) r.scrollBy({ left: d * r.clientWidth * 0.8, behavior: 'smooth' });
  };
  return (
    <div className={wrapClass}>
      <div className={rowClass} ref={row}>
        {children}
      </div>
      <button className="row-b prev" type="button" aria-label="Scroll left" onClick={() => scroll(-1)}>
        <Icon name="chevl" />
      </button>
      <button className="row-b next" type="button" aria-label="Scroll right" onClick={() => scroll(1)}>
        <Icon name="chev" />
      </button>
    </div>
  );
}
