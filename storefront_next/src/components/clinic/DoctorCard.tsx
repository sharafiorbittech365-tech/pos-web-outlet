/** One doctor card (doctors list, clinic home) — Clinic/Views/_card.php. */
import Link from 'next/link';
import { Icon } from '@/components/Icon';
import { initials } from '@/lib/format';
import { ClinicSite } from '@/lib/services/clinic';
import { type Store } from '@/lib/store';

export function doctorPhoto(d: Record<string, any>): string {
  return d.photo ? `/doctors/${Math.trunc(Number(d.id))}/photo?v=${Math.trunc(Number(d.photo?.version ?? 0))}` : '';
}

export function DoctorCard({ store, d }: { store: Store; d: Record<string, any> }) {
  const photo = doctorPhoto(d);
  const sched = Array.isArray(d.schedule) && d.schedule.length > 0 ? ClinicSite.scheduleText(d).split(' · ') : [];
  return (
    <article className="dr-card">
      <div className="dr-top">
        <span className="dr-av">
          {photo !== '' ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={photo} alt={String(d.name)} loading="lazy" />
          ) : (
            initials(String(d.name))
          )}
        </span>
        <div>
          <h3>{String(d.name)}</h3>
          {d.degrees ? <div className="dr-meta">{String(d.degrees)}</div> : null}
        </div>
      </div>
      {d.specialty ? <span className="dr-spec">{String(d.specialty)}</span> : null}
      {d.designation ? <div className="dr-meta">{String(d.designation)}</div> : null}
      {sched.length > 0 && (
        <div className="dr-sched">
          {sched.map((x, i) => (
            <span key={i}>{x}</span>
          ))}
        </div>
      )}
      {d.fee !== undefined && d.fee !== null && (
        <div className="dr-meta">
          Consultation fee <b>{store.money(d.fee)}</b>
        </div>
      )}
      <Link className="btn-p" href={`/doctors/${Math.trunc(Number(d.id))}`}>
        <Icon name="cal" />
        Book appointment
      </Link>
    </article>
  );
}
