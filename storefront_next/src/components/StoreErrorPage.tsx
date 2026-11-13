/** Friendly full-page messages when the shop cannot be shown (port of Views/errors/store_error.php). */
import 'server-only';
import Link from 'next/link';
import { config } from '@/lib/config';
import type { StoreErrorKind } from '@/lib/context';
import { type Store } from '@/lib/store';
import { Icon } from './Icon';

export function StoreErrorPage({ kind, store = null, requestId = '' }: { kind: StoreErrorKind; store?: Store | null; requestId?: string }) {
  const name = store?.name() ?? '';
  const phone = store?.phone() ?? '';
  const brand = config.brandName;
  const copy: Record<StoreErrorKind, [string, string, string]> = {
    unavailable: ['store', name !== '' ? name : 'This store', 'This online store is not open right now.'],
    not_found: ['search-x', store ? 'Page not found' : 'Store not found', store ? 'We could not find that page. It may have moved or is no longer available.' : 'There is no online store at this address. Please check the link and try again.'],
    temporary: ['refresh', 'We will be right back', 'The store is temporarily unavailable. Please try again in a minute.'],
    domain: ['shield', 'Domain not connected yet', `This website is not yet authorised to show the store. The owner can verify and enable this domain in ${brand} › Website › Domains.`],
    choose: ['store', 'Choose a store', `This is the ${brand} storefront. Open a shop by its address, for example ?site=digitalzone.`],
  };
  const [icon, title, text] = copy[kind] ?? ['store', 'Something went wrong', 'Please try again in a minute.'];

  return (
    <section className="dir">
      <div className="wrap narrow">
        <div className="ok-card">
          <span className="ok-ic off">
            <Icon name={icon} />
          </span>
          <h1>{title}</h1>
          <p>
            {text}
            {kind === 'unavailable' && phone !== '' && (
              <>
                {' '}
                Please visit us or call <a href={`tel:${phone}`}>{phone}</a>.
              </>
            )}
          </p>
          {kind === 'choose' ? (
            <form className="track" method="get" action="/">
              <input name="site" placeholder="Store address, e.g. digitalzone" required pattern="[a-z0-9\-]{1,63}" aria-label="Store address" />
              <button className="btn-p">Open</button>
            </form>
          ) : kind === 'not_found' && store ? (
            <div className="hero-cta" style={{ justifyContent: 'center' }}>
              <Link className="btn-p" href="/">
                Back to {name}
              </Link>
            </div>
          ) : kind === 'temporary' ? (
            <div className="hero-cta" style={{ justifyContent: 'center' }}>
              <a className="btn-p" href="">
                Try again
              </a>
            </div>
          ) : null}
          {requestId !== '' && (
            <p className="muted">
              <small>Reference {requestId}</small>
            </p>
          )}
        </div>
      </div>
    </section>
  );
}
