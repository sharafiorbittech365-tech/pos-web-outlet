import { randomBytes } from 'node:crypto';
import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound, redirect } from 'next/navigation';
import { DoctorBookForm } from '@/components/clinic/DoctorBookForm';
import { doctorPhoto } from '@/components/clinic/DoctorCard';
import { Icon } from '@/components/Icon';
import { ShieldFieldsBlock } from '@/components/ShieldFields';
import { getContext } from '@/lib/context';
import { initials, phpDate, today, validDate } from '@/lib/format';
import { pageMeta } from '@/lib/seo';
import { ClinicSite } from '@/lib/services/clinic';

type Params = Promise<{ id: string }>;
type SP = Promise<Record<string, string | string[] | undefined>>;

export async function generateMetadata({ params }: { params: Params }): Promise<Metadata> {
  const { store, api } = await getContext();
  const id = Math.trunc(Number((await params).id));
  if (!store.isClinic() || !(id > 0)) return pageMeta(store, { title: 'Our doctors' });
  const doctor = await new ClinicSite(api).doctor(id);
  if (!doctor) return pageMeta(store, { title: 'Our doctors' });
  const description = [String(doctor.specialty ?? ''), String(doctor.degrees ?? '')].join(' · ').replace(/^[ ·]+|[ ·]+$/g, '');
  return pageMeta(store, { title: String(doctor.name), path: '/doctors/' + id, description });
}

export default async function DoctorPage({ params, searchParams }: { params: Params; searchParams: SP }) {
  const id = Math.trunc(Number((await params).id));
  const sp = await searchParams;
  const { store, api } = await getContext();
  if (!store.isClinic()) redirect('/book');
  if (!(id > 0)) notFound();
  const svc = new ClinicSite(api);
  const doctor = await svc.doctor(id);
  if (!doctor) notFound();

  const open: string[] = (Array.isArray(doctor.open_dates) ? doctor.open_dates : []).map(String);
  const raw = sp.date;
  const want = (Array.isArray(raw) ? raw[0] : raw ?? '').trim();
  const date = validDate(want) && want >= today() ? want : open[0] ?? today();
  const slots = await svc.slots(id, date);
  const photo = doctorPhoto(doctor);
  const name = String(doctor.name);
  const sched = Array.isArray(doctor.schedule) && doctor.schedule.length > 0 ? ClinicSite.scheduleText(doctor).split(' · ') : [];

  return (
    <section className="wrap sec">
      <nav className="crumbs">
        <Link href="/">Home</Link> / <Link href="/doctors">Doctors</Link> / <span>{name}</span>
      </nav>
      <div className="dr-head">
        <span className="dr-av lg">
          {photo !== '' ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={photo} alt={name} />
          ) : (
            initials(name)
          )}
        </span>
        <div className="grow">
          <h1>{name}</h1>
          {doctor.degrees ? <div className="dr-meta">{String(doctor.degrees)}</div> : null}
          <div>{doctor.specialty ? <span className="dr-spec">{String(doctor.specialty)}</span> : null}</div>
          {doctor.designation ? <div className="dr-meta">{String(doctor.designation)}</div> : null}
          {doctor.chamber ? (
            <div className="dr-meta">
              <Icon name="pin" /> {String(doctor.chamber)}
            </div>
          ) : null}
          {doctor.registration_no ? <div className="dr-meta">Reg. No: {String(doctor.registration_no)}</div> : null}
          {sched.length > 0 && (
            <div className="dr-sched">
              {sched.map((x, i) => (
                <span key={i}>{x}</span>
              ))}
            </div>
          )}
          {doctor.about ? <p className="dr-about">{String(doctor.about)}</p> : null}
        </div>
      </div>

      <DoctorBookForm
        shield={<ShieldFieldsBlock />}
        data={{
          id,
          name,
          date,
          dateLabel: phpDate('l, j F', date),
          dates: Array.from({ length: 14 }, (_, i) => {
            const v = today(i);
            return { v, day: i === 0 ? 'Today' : i === 1 ? 'Tomorrow' : phpDate('D', v), num: phpDate('j', v), mon: phpDate('M', v), open: open.includes(v) };
          }),
          nextOpenLabel: open.length > 0 ? phpDate('l, j F', open[0]) : '',
          slots: slots.map((s) => ({ starts_at: s.starts_at, label: phpDate('g:i A', s.starts_at) })),
          at: '',
          fee: doctor.fee !== undefined && doctor.fee !== null ? store.money(doctor.fee) : null,
          idem: 'd' + randomBytes(12).toString('hex'),
        }}
      />
    </section>
  );
}
