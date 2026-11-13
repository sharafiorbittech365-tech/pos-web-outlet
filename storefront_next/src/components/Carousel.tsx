'use client';
/** Hero carousel (data-car in storefront-app.js): auto-advance every 6 s unless hovered / hidden, swipe, dots, counter. */
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Icon } from './Icon';

export function Carousel({ slides }: { slides: ReactNode[] }) {
  const [cur, setCur] = useState(0);
  const n = slides.length;
  const root = useRef<HTMLDivElement>(null);
  const tx = useRef<number | null>(null);

  const go = (i: number) => setCur(((i % n) + n) % n);

  useEffect(() => {
    if (n < 2) return;
    const t = setInterval(() => {
      if (root.current?.matches(':hover') || document.hidden) return;
      setCur((c) => (c + 1) % n);
    }, 6000);
    return () => clearInterval(t);
  }, [n]);

  return (
    <div
      className="car"
      ref={root}
      onTouchStart={(e) => {
        tx.current = e.touches[0].clientX;
      }}
      onTouchEnd={(e) => {
        if (tx.current === null) return;
        const dx = e.changedTouches[0].clientX - tx.current;
        tx.current = null;
        if (Math.abs(dx) > 40) go(cur + (dx < 0 ? 1 : -1));
      }}
    >
      {slides.map((s, i) => (
        <div className={'slide' + (i === cur ? ' on' : '')} key={i}>
          {s}
        </div>
      ))}
      {n > 1 && (
        <>
          <button className="car-b prev" type="button" aria-label="Previous" onClick={() => go(cur - 1)}>
            <Icon name="chevl" />
          </button>
          <button className="car-b next" type="button" aria-label="Next" onClick={() => go(cur + 1)}>
            <Icon name="chev" />
          </button>
          <span className="car-n">
            <b>{cur + 1}</b> / {n}
          </span>
          <div className="car-dots">
            {slides.map((_, i) => (
              <i key={i} className={i === cur ? 'on' : ''} onClick={() => go(i)} />
            ))}
          </div>
        </>
      )}
    </div>
  );
}
