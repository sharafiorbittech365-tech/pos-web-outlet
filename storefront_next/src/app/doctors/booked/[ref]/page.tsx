import type { Metadata } from 'next';
import Link from 'next/link';
import { redirect } from 'next/navigation';
import { Icon } from '@/components/Icon';
import { getContext } from '@/lib/context';
import { recallBooking } from '@/lib/done';
import { phpDate } from '@/lib/format';
import { pageMeta } from '@/lib/seo';

export async function generateMetadata(): Promise<Metadata> {
  const { store } = await getContext();
  return pageMeta(store, { title: 'Appointment booked', noindex: true });
}

export default async function DoctorBookedPage({ params }: { params: Promise<{ ref: string }> }) {
  const ref = decodeURIComponent((await params).ref);
  const { store } = await getContext();
  const b = await recallBooking(ref);
  if (!b) redirect('/track/' + encodeURIComponent(ref));
  const phone = store.phone();
  return (
    <section className="wrap sec narrow">
      <div className="ok-card">
        <span className="ok-ic">
          <Icon name="cal" />
        </span>
        <h1>Appointment booked</h1>
        {b.serial_no ? (
          <>
            <p>Your serial number</p>
            <span className="dr-serial">{Math.trunc(Number(b.serial_no))}</span>
          </>
        ) : null}
        <p>
          {String(b.doctor?.name ?? b.staff ?? '')}
          {b.starts_at ? ' · ' + phpDate('D j M, g:i A', String(b.starts_at)) : ''}
        </p>
        <p>
          Reference <b>{ref}</b>
        </p>
        <p className="muted">The clinic confirms by call or SMS. Please come a little before your time and bring earlier prescriptions and reports.</p>
        <div className="hero-cta" style={{ justifyContent: 'center' }}>
          <Link className="btn-p" href="/doctors">
            Back to doctors
          </Link>
          <Link className="btn-g" href={'/track/' + encodeURIComponent(ref)}>
            Track
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
