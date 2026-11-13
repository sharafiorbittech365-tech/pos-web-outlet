/** "List your shop" — shops opt in from the POS (Website › Marketplace); this page explains how (SellersController). */
import Link from 'next/link';
import type { Metadata } from 'next';
import { Ic } from '@/components/Ic';
import { config } from '@/lib/config';
import { getMeta } from '@/lib/page';

export const metadata: Metadata = { title: 'List your shop' };

export default async function Sell() {
  const meta = await getMeta();
  const brand = config.brandName;
  return (
    <>
      <section className="hero">
        <div className="wrap">
          <h1>
            Sell to customers <span>near your shop</span>
          </h1>
          <p>
            {brand} Market shows your products and services to people around you. Orders and bookings land in your POS, exactly like your own website orders — same stock, same
            invoice numbers, same reports.
          </p>
          <div className="row2">
            {meta.pos_url ? (
              <a className="btn primary" href={String(meta.pos_url)}>
                <Ic name="store" />
                Open the POS
              </a>
            ) : null}
            <Link className="btn" href="/shops">
              <Ic name="compass" />
              See the {Math.trunc(Number(meta.shop_count ?? 0))} shops listed
            </Link>
          </div>
        </div>
      </section>
      <div className="wrap">
        <div className="steps">
          <div className="panel">
            <b>
              <Ic name="globe" />
              1. Turn on your website
            </b>
            <p>In the POS go to Website and publish your storefront. The Website module is part of the Business plan, or an add-on on Starter.</p>
          </div>
          <div className="panel">
            <b>
              <Ic name="compass" />
              2. List on the Marketplace
            </b>
            <p>In Website › Marketplace, switch on &quot;List my shop&quot;. Set your delivery radius, delivery charge and free-delivery amount.</p>
          </div>
          <div className="panel">
            <b>
              <Ic name="map-pin" />
              3. Set your location
            </b>
            <p>Your outlet&apos;s map location decides who sees you first. Customers see the distance and whether you deliver to them.</p>
          </div>
          <div className="panel">
            <b>
              <Ic name="receipt" />
              4. Get orders and bookings
            </b>
            <p>Marketplace orders appear in the POS with the &quot;Marketplace&quot; channel. Bookings need the Bookings module.</p>
          </div>
        </div>
        <p className="note" style={{ marginTop: 16 }}>
          Listing on the Marketplace has no extra charge. Shops with a suspended or expired subscription are hidden until the account is active again.
        </p>
      </div>
    </>
  );
}
