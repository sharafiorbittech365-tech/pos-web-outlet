'use client';
/** Detailed product page: main picture with thumbnails and previous / next arrows. */
import { useState, type ReactNode } from 'react';
import { Icon } from './Icon';

export function ProductGallery({ pics, alts = [], name, badge, children }: { pics: string[]; alts?: string[]; name: string; badge?: string | null; children?: ReactNode }) {
  const [at, setAt] = useState(0);
  const go = (i: number) => setAt((i + pics.length) % pics.length);
  return (
    <div className="pd2-gal">
      <div className="pd2-main">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={pics[at]} alt={alts[at] || name} />
        {pics.length > 1 && (
          <>
            <button type="button" className="pd2-nav prev" aria-label="Previous picture" onClick={() => go(at - 1)}>
              <Icon name="chevl" />
            </button>
            <button type="button" className="pd2-nav next" aria-label="Next picture" onClick={() => go(at + 1)}>
              <Icon name="chev" />
            </button>
          </>
        )}
        {badge ? <span className="badge">{badge}</span> : null}
        {children}
      </div>
      {pics.length > 1 && (
        <div className="pd2-thumbs">
          {pics.map((u, i) => (
            <button key={u + i} type="button" className={i === at ? 'on' : ''} aria-label={`Picture ${i + 1}`} onClick={() => setAt(i)}>
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src={u} alt={alts[i] || ''} loading="lazy" />
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
