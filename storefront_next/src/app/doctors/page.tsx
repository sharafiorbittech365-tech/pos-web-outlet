import type { Metadata } from 'next';
import Link from 'next/link';
import { redirect } from 'next/navigation';
import { DoctorCard } from '@/components/clinic/DoctorCard';
import { Icon } from '@/components/Icon';
import { Accent, Sh2 } from '@/components/Section';
import { getContext } from '@/lib/context';
import { pageMeta } from '@/lib/seo';
import { ClinicSite } from '@/lib/services/clinic';

export async function generateMetadata(): Promise<Metadata> {
  const { store } = await getContext();
  return pageMeta(store, { title: 'Our doctors', path: '/doctors' });
}

export default async function DoctorsPage() {
  const { store, api } = await getContext();
  if (!store.isClinic()) redirect('/book');
  const doctors = await new ClinicSite(api).doctors();
  const phone = store.phone();
  return (
    <section className="wrap sec">
      <nav className="crumbs">
        <Link href="/">Home</Link> / <span>Doctors</span>
      </nav>
      <Sh2 eyebrow="Book an appointment" title={<Accent lead="Our" tail="Doctors" />} icon="stethoscope" />
      {doctors.length === 0 ? (
        <div className="dr-empty">
          <p>Online appointments are not open right now.</p>
          {phone !== '' && (
            <a className="btn-p" href={`tel:${phone}`}>
              <Icon name="phone" />
              Call {phone}
            </a>
          )}
        </div>
      ) : (
        <div className="dr-grid">
          {doctors.map((d) => (
            <DoctorCard key={String(d.id)} store={store} d={d} />
          ))}
        </div>
      )}
    </section>
  );
}
