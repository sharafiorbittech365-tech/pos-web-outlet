/** Inline icons and image helpers (ports of si(), sym(), fav_img(), demo_img(), pic(), p_thumb()). */
import type { CSSProperties } from 'react';
import { ICONS } from '@/lib/icons-data';
import { nameHue, pImage, safeImgUrl } from '@/lib/format';

export const ASSET = '/assets/storefront';

/** <svg class="i …"> from the Lucide set + social glyphs. Works in server and client components. */
export function Icon({ name, className = '' }: { name: string; className?: string }) {
  return <svg className={('i ' + className).trim()} viewBox="0 0 24 24" aria-hidden="true" dangerouslySetInnerHTML={{ __html: ICONS[name] ?? '' }} />;
}

/** The shop's brand mark placeholder. */
export function FavImg({ className = 'fav-ic' }: { className?: string }) {
  // eslint-disable-next-line @next/next/no-img-element
  return <img className={className} src={`${ASSET}/icon.svg`} alt="" aria-hidden="true" />;
}

/** A category / product symbol: its icon when the set has it, else the brand mark. */
export function Sym({ name, className = '' }: { name?: string | null; className?: string }) {
  return name && ICONS[name] ? <Icon name={name} className={('sym ' + className).trim()} /> : <FavImg className={('fav-ic ' + className).trim()} />;
}

export function DemoImg({ alt = '', className = '' }: { alt?: string; className?: string }) {
  // eslint-disable-next-line @next/next/no-img-element
  return <img className={('demo-img ' + className).trim()} src={`${ASSET}/demo.svg`} alt={alt} loading="lazy" />;
}

/** Hero / about picture: the owner's own image (index 0 hero, 1 about) or the demo art. */
export function Pic({ i, brand, className = '', alt = '' }: { i: number; brand: Record<string, any>; className?: string; alt?: string }) {
  const own = safeImgUrl(i === 0 ? brand.heroImg ?? '' : i === 1 ? brand.aboutImg ?? '' : '');
  return (
    <span className={('pic ' + className).trim()}>
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img src={own !== '' ? own : `${ASSET}/demo.svg`} alt={alt} loading={i === 0 ? 'eager' : 'lazy'} />
    </span>
  );
}

/** Product photo box with a stable placeholder colour. `size` is "" | "sm". */
export function PThumb({ p, size = '' }: { p: Record<string, any>; size?: string }) {
  const name = String(p.name ?? '');
  const img = pImage(p);
  return (
    <div className={('ph ' + size).trim()} style={{ '--h': nameHue(name) } as CSSProperties}>
      {img !== '' ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={img} alt={name} loading="lazy" />
      ) : (
        <DemoImg alt={name} />
      )}
    </div>
  );
}

const STAR = 'M11.5 2.3a.6.6 0 0 1 1 0l2.6 5.3 5.9.9a.6.6 0 0 1 .3 1l-4.2 4.1 1 5.8a.6.6 0 0 1-.8.6L12 17.3 6.7 20a.6.6 0 0 1-.8-.6l1-5.8-4.3-4.1a.6.6 0 0 1 .3-1l6-.9z';

export function Stars({ rating = 5 }: { rating?: number }) {
  return (
    <span className="stars" aria-label={`${rating} out of 5`}>
      {[0, 1, 2, 3, 4].map((i) => (
        <i key={i} className={i < rating ? 'on' : ''}>
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d={STAR} />
          </svg>
        </i>
      ))}
    </span>
  );
}
