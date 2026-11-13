import type { Metadata } from 'next';
import { Fragment } from 'react';
import { redirect } from 'next/navigation';
import { getContext } from '@/lib/context';
import { pageMeta } from '@/lib/seo';

export async function generateMetadata(): Promise<Metadata> {
  const { store } = await getContext();
  return pageMeta(store, { title: 'Delivery & returns', path: '/policy' });
}

/** nl2br(esc(text)) */
function Para({ text }: { text: string }) {
  const lines = text.split(/\r\n|\r|\n/);
  return (
    <p>
      {lines.map((l, i) => (
        <Fragment key={i}>
          {i > 0 && <br />}
          {l}
        </Fragment>
      ))}
    </p>
  );
}

export default async function PolicyPage() {
  const { store } = await getContext();
  const own = store.infoPages().find((p) => p.kind === 'delivery');
  if (own) redirect('/pages/' + own.slug);
  const ck = store.checkout();
  const pol = (store.brand().policy ?? {}) as Record<string, unknown>;
  const custom = (k: string): string => String(pol[k] ?? '').trim();

  return (
    <section className="wrap sec narrow prose">
      <h1>Delivery &amp; returns</h1>
      <h3>Delivery</h3>
      {custom('delivery') !== '' ? (
        <Para text={custom('delivery')} />
      ) : (
        <p>
          Inside the city in 1–2 days. {ck.delivery > 0 ? `Delivery charge ${store.money(ck.delivery)}.` : 'Free delivery.'} {ck.freeAbove > 0 ? `Free over ${store.money(ck.freeAbove)}.` : ''}
          {ck.pickup ? ` You can also pick up your order at ${store.branch()} for free.` : ''}
        </p>
      )}
      <h3>Bookings</h3>
      <p>Free cancellation up to 2 hours before your time — just call us.</p>
      <h3>Returns</h3>
      {custom('returns') !== '' ? <Para text={custom('returns')} /> : <p>Unused items in original packaging can be returned within 7 days with the invoice.</p>}
      <h3 id="privacy">Privacy</h3>
      {custom('privacy') !== '' ? (
        <Para text={custom('privacy')} />
      ) : (
        <p>
          {store.name()} uses your name, phone, address and (if you share it) location only to process your order or booking. Payments are verified by the shop; card numbers are never entered on this site.
        </p>
      )}
      <h3 id="terms">Terms</h3>
      {custom('terms') !== '' ? (
        <Para text={custom('terms')} />
      ) : (
        <p>Prices are in {store.currency().code} and include VAT where it applies. The shop confirms every order by phone; the total shown on the confirmation page is final.</p>
      )}
    </section>
  );
}
