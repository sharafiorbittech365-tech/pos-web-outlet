/** One outlet: location (map link), hours, contact, delivery & payment, and its products (Home::outlet). */
import Link from 'next/link';
import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { Grid } from '@/components/Cards';
import { Icon, Sym } from '@/components/Icon';
import { getContext } from '@/lib/context';
import { sfMoney, sfUrl } from '@/lib/format';
import { pageMeta } from '@/lib/seo';
import { Site } from '@/lib/site';

type Props = { params: Promise<{ id: string }> };

async function load(params: Props['params']) {
  const { id } = await params;
  const ctx = await getContext();
  const o = /^\d+$/.test(id) ? ctx.site.outlet(Number(id)) : null;
  if (o === null) notFound();
  return { ...ctx, o };
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { site, o } = await load(params);
  return pageMeta(site, { title: String(o.name), description: `Address, opening hours and products of ${String(o.name)}.`, path: `/outlets/${Math.trunc(Number(o.id))}` });
}

const PAYS: Record<string, string> = { cod: 'Cash on delivery', bkash: 'bKash / Nagad', card: 'Card' };

export default async function OutletPage({ params }: Props) {
  const { site, api, o } = await load(params);
  const id = Math.trunc(Number(o.id));
  const products = await api.products({ outlet: id, per_page: 12 });
  const cur = site.currency();
  const addr = Site.address(o);
  const map = Site.mapUrl(o);
  const hours = Site.hours(o);
  const ck = o.checkout && typeof o.checkout === 'object' ? o.checkout : {};
  const total = Math.trunc(Number(products.meta.total ?? products.data.length));

  return (
    <section className="wrap sec">
      <nav className="crumbs">
        <Link href="/">Home</Link> / <Link href="/outlets">Outlets</Link> / <span>{String(o.name)}</span>
      </nav>
      <div className="ms-outlet-page">
        <div className="box">
          <h1 className="ms-o-title">
            <Sym name={String(o.logo_icon ?? 'store')} /> {String(o.name)}
          </h1>
          {o.type_name ? <p className="muted">{String(o.type_name)}</p> : null}
          <ul className="ms-o-facts big">
            {addr !== '' && (
              <li>
                <Icon name="pin" />
                <span>{addr}</span>
              </li>
            )}
            {hours !== '' && (
              <li>
                <Icon name="clock" />
                <span>Open daily {hours}</span>
              </li>
            )}
            {o.phone ? (
              <li>
                <Icon name="phone" />
                <a href={`tel:${String(o.phone)}`}>{String(o.phone)}</a>
              </li>
            ) : null}
            {o.email ? (
              <li>
                <Icon name="mail" />
                <a href={`mailto:${String(o.email)}`}>{String(o.email)}</a>
              </li>
            ) : null}
            <li>
              <Icon name="truck" />
              <span>
                {Number(ck.delivery ?? 0) > 0 ? `Home delivery ${sfMoney(ck.delivery, cur)}` : 'Free home delivery'}
                {Number(ck.freeAbove ?? 0) > 0 ? ` · free over ${sfMoney(ck.freeAbove, cur)}` : ''}
                {o.delivery_km ? ` · up to ${String(o.delivery_km)} km` : ''}
              </span>
            </li>
            {ck.pickup ? (
              <li>
                <Icon name="store" />
                <span>Pickup at the outlet</span>
              </li>
            ) : null}
            <li>
              <Icon name="wallet" />
              <span>{Site.paymentMethods(o).map((m) => PAYS[m]).join(' · ')}</span>
            </li>
          </ul>
          <div className="hero-cta">
            {map !== '' && (
              <a className="btn-p" href={map} target="_blank" rel="noopener">
                <Icon name="map" />
                Open in maps
              </a>
            )}
            <Link className="btn-g" href={sfUrl('/products', { outlet: String(id) })}>
              All {total} products here
            </Link>
          </div>
        </div>
      </div>
      <div className="sec-h" style={{ marginTop: 28 }}>
        <h2>Available at {String(o.name)}</h2>
      </div>
      <Grid list={products.data} outlet={id} cur={cur} />
    </section>
  );
}
