import type { Metadata } from 'next';
import Link from 'next/link';
import { Icon } from '@/components/Icon';
import { getContext } from '@/lib/context';
import { safeImgUrl } from '@/lib/format';
import { pageMeta } from '@/lib/seo';

export async function generateMetadata(): Promise<Metadata> {
  const { store } = await getContext();
  return pageMeta(store, { title: 'Contact', path: '/contact' });
}

export default async function ContactPage() {
  const { store } = await getContext();
  const b = store.business;
  const loc = store.location();
  const brand = store.brand();
  const email = String(brand.contactEmail ?? '').trim() || String(b.email ?? '');
  const wa = String(brand.whatsapp ?? '').replace(/\D+/g, '');
  const hours = String(brand.hoursText ?? '').trim();
  const map = safeImgUrl(brand.mapUrl ?? '');
  const phone = store.phone();
  const mapHref = map !== '' ? map : loc ? 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(`${loc.lat},${loc.lng}`) : '';
  const place = `${String(b.area ?? '')}, ${String(b.city ?? '')}`.replace(/^[, ]+|[, ]+$/g, '');

  return (
    <section className="wrap sec narrow">
      <nav className="crumbs">
        <Link href="/">Home</Link> / <span>Contact</span>
      </nav>
      <h1>Contact {store.name()}</h1>
      <div className="box">
        <div className="fg">
          {phone !== '' && (
            <p className="ft-ci">
              <span>
                <Icon name="phone" />
              </span>
              <span>
                <b>
                  <a href={`tel:${phone}`}>{phone}</a>
                </b>
                <small>{hours !== '' ? hours : 'Call or message us, 10 AM – 10 PM'}</small>
              </span>
            </p>
          )}
          {wa !== '' && (
            <p className="ft-ci">
              <span>
                <Icon name="wa" />
              </span>
              <span>
                <b>
                  <a href={`https://wa.me/${wa}`} target="_blank" rel="noopener">
                    WhatsApp +{wa}
                  </a>
                </b>
                <small>Chat with us</small>
              </span>
            </p>
          )}
          {email !== '' && (
            <p className="ft-ci">
              <span>
                <Icon name="mail" />
              </span>
              <span>
                <b>
                  <a href={`mailto:${email}`}>{email}</a>
                </b>
                <small>Email us</small>
              </span>
            </p>
          )}
          <p className="ft-ci">
            <span>
              <Icon name="pin" />
            </span>
            <span>
              <b>{store.address()}</b>
              <small>{place}</small>
            </span>
          </p>
        </div>
        {mapHref !== '' && (
          <p>
            <a className="btn-g" href={mapHref} target="_blank" rel="noopener">
              <Icon name="map" />
              Open in maps
            </a>
          </p>
        )}
        <div className="hero-cta">
          <Link className="btn-p" href="/track">
            <Icon name="clock" />
            Track an order
          </Link>
          {store.canBook() && (
            <Link className="btn-g" href="/book">
              <Icon name="cal" />
              {store.cta()}
            </Link>
          )}
        </div>
      </div>
    </section>
  );
}
