import type { Metadata } from 'next';
import Link from 'next/link';
import { redirect } from 'next/navigation';
import { Icon } from '@/components/Icon';
import { getContext } from '@/lib/context';
import { recallBooking } from '@/lib/done';
import { phpDate } from '@/lib/format';
import { pageMeta } from '@/lib/seo';
import { statusText, step } from '@/lib/services/track';

export async function generateMetadata(): Promise<Metadata> {
  const { store } = await getContext();
  return pageMeta(store, { title: 'Booking received', noindex: true });
}

export default async function BookedPage({ params }: { params: Promise<{ ref: string }> }) {
  const ref = decodeURIComponent((await params).ref);
  const { store } = await getContext();
  const b = await recallBooking(ref);
  if (!b) redirect('/track/' + encodeURIComponent(ref));
  const q = b.kind === 'quote';
  const s = step(b);
  const labels = [q ? 'Received' : 'Requested', q ? 'Price confirmed' : 'Confirmed', q ? 'Done' : 'Completed'];
  const phone = store.phone();
  return (
    <section className="wrap sec narrow">
      <div className="ok-card">
        <span className="ok-ic">
          <Icon name={q ? 'file' : 'cal'} />
        </span>
        <h1>{q ? 'Request sent!' : 'You are booked in!'}</h1>
        <p>
          Reference <b>{ref}</b>
          {!q && b.starts_at ? ' · ' + phpDate('D j M, h:i A', String(b.starts_at)) : ''}
        </p>
        <div className="steps">
          {labels.map((l, i) => (
            <div key={i} className={i <= s ? 'done' : ''}>
              <i>{i <= s ? <Icon name="check" /> : i + 1}</i>
              <span>{l}</span>
            </div>
          ))}
        </div>
        <p className="muted">{statusText(b, 'booking')}</p>
        <div className="hero-cta" style={{ justifyContent: 'center' }}>
          <Link className="btn-p" href="/">
            Back to {store.name()}
          </Link>
          {phone !== '' && (
            <a className="btn-g" href={`tel:${phone}`}>
              <Icon name="phone" />
              Call
            </a>
          )}
        </div>
      </div>
    </section>
  );
}
