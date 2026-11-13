/** Friendly full-page messages when the website cannot be shown (port of Views/errors/site_error.php). */
import 'server-only';
import Link from 'next/link';
import { config } from '@/lib/config';
import type { SiteErrorKind } from '@/lib/context';
import { type Site } from '@/lib/site';
import { Icon } from './Icon';

export function SiteErrorPage({ kind, site = null, requestId = '' }: { kind: SiteErrorKind; site?: Site | null; requestId?: string }) {
  const name = site?.name() ?? '';
  const copy: Record<SiteErrorKind, [string, string, string]> = {
    unavailable: ['store', name !== '' ? name : 'This website', 'This website is not open right now. Please visit one of our outlets.'],
    not_found: ['search-x', site ? 'Page not found' : 'Website not found', site ? 'We could not find that page. It may have moved or is no longer available.' : 'There is no website at this address. Please check the link and try again.'],
    temporary: ['refresh', 'We will be right back', 'The website is temporarily unavailable. Please try again in a minute.'],
    choose: ['store', 'Choose a website', `This is the ${config.brandName} multi-outlet website. Open one by its address, for example ?site=digital-zone-group.`],
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
          <p>{text}</p>
          {kind === 'choose' ? (
            <form className="track" method="get" action="/">
              <input name="site" placeholder="Website address, e.g. digital-zone-group" required pattern="[a-z0-9\-]{1,63}" aria-label="Website address" />
              <button className="btn-p">Open</button>
            </form>
          ) : kind === 'not_found' && site ? (
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
