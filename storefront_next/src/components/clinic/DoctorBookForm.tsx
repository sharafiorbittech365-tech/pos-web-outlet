'use client';
/** The appointment form of a doctor's page (Clinic/Views/doctor.php). Day chips are links; the time is a radio. */
import Link from 'next/link';
import { useActionState, useEffect, useState, type ReactNode } from 'react';
import { bookDoctor } from '@/app/actions/doctors';
import { Icon } from '@/components/Icon';
import { refreshHumanCheck } from '@/components/HumanCheck';
import { useToast } from '@/components/Toast';
import { emptyForm } from '@/lib/form-errors';

export interface DoctorFormData {
  id: number;
  name: string;
  date: string;
  dateLabel: string;
  dates: { v: string; day: string; num: string; mon: string; open: boolean }[];
  nextOpenLabel: string;
  slots: { starts_at: string; label: string }[];
  at: string;
  fee: string | null;
  idem: string;
}

const FieldErr = ({ e }: { e?: string }) => (e ? <small className="f-err">{e}</small> : null);

export function DoctorBookForm({ data: d, shield }: { data: DoctorFormData; shield: ReactNode }) {
  const [state, action, pending] = useActionState(bookDoctor, emptyForm);
  const toast = useToast();
  const v = state.values;
  const err = state.errors;
  const o = (k: string): string => v[k] ?? '';
  const [at, setAt] = useState(d.at);
  useEffect(() => {
    if (state.n === 0) return;
    if (v.starts_at !== undefined) setAt(v.starts_at);
    if (state.error) toast(state.error, false);
    refreshHumanCheck();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [state.n]);
  const picked = d.slots.find((s) => s.starts_at === at) ?? null;

  return (
    <form className="co" action={action} data-book>
      <input type="hidden" name="date" value={d.date} />
      <input type="hidden" name="doctor_id" value={d.id} />
      <input type="hidden" name="_idem" value={d.idem} />
      <div className="co-main" key={state.n}>
        <div className="box" id="when">
          <h3>
            <span>1</span>Choose a day &amp; time
          </h3>
          <div className="dchips">
            {d.dates.map((x) => (
              <Link key={x.v} className={'dchip ' + (x.v === d.date ? 'on' : '') + ' ' + (x.open ? '' : 'closed')} href={`/doctors/${d.id}?date=${x.v}#when`} title={x.open ? undefined : 'No free time'}>
                <small>{x.day}</small>
                <b>{x.num}</b>
                <small>{x.mon}</small>
              </Link>
            ))}
          </div>
          {d.slots.length === 0 ? (
            <p className="muted">No free time on this day{d.nextOpenLabel !== '' ? ' — the next free day is ' + d.nextOpenLabel : ''}.</p>
          ) : (
            <div className="slots">
              {d.slots.map((s) => (
                <label key={s.starts_at} className={'slot ' + (s.starts_at === at ? 'on' : '')}>
                  <input type="radio" name="starts_at" value={s.starts_at} checked={s.starts_at === at} onChange={() => setAt(s.starts_at)} required />
                  {s.label}
                </label>
              ))}
            </div>
          )}
          <FieldErr e={err.time} />
        </div>

        <div className="box">
          <h3>
            <span>2</span>Patient details
          </h3>
          <div className="fg">
            <label>
              Patient&apos;s full name *
              <input name="name" required maxLength={100} autoComplete="name" defaultValue={o('name')} />
              <FieldErr e={err.name} />
            </label>
            <label>
              Mobile number *
              <input name="phone" required inputMode="tel" autoComplete="tel" placeholder="01XXXXXXXXX" maxLength={20} defaultValue={o('phone')} />
              <FieldErr e={err.phone} />
            </label>
            <label>
              Age (years)
              <input name="age" inputMode="numeric" maxLength={3} defaultValue={o('age')} />
              <FieldErr e={err.age} />
            </label>
            <label>
              Sex
              <select name="sex" defaultValue={o('sex')}>
                <option value="">—</option>
                <option value="male">Male</option>
                <option value="female">Female</option>
                <option value="other">Other</option>
              </select>
              <FieldErr e={err.sex} />
            </label>
            <label>
              Email (optional)
              <input name="email" type="email" maxLength={150} autoComplete="email" defaultValue={o('email')} />
              <FieldErr e={err.email} />
            </label>
            <label>
              Note (optional)
              <input name="note" maxLength={500} defaultValue={o('note')} placeholder="e.g. follow-up visit" />
              <FieldErr e={err.note} />
            </label>
          </div>
        </div>
      </div>
      <aside className="co-sum">
        <h3>Summary</h3>
        <div className="r it">
          <span>
            <Icon name="stethoscope" />
            {d.name}
          </span>
        </div>
        <div className="r it">
          <span>
            <Icon name="cal" />
            {d.dateLabel}
            {picked ? ' · ' + picked.label : ' · pick a time'}
          </span>
        </div>
        {d.fee !== null && (
          <div className="r tot">
            <span>Consultation fee</span>
            <b>{d.fee}</b>
          </div>
        )}
        {shield}
        <button className="btn-p full" type="submit" data-once disabled={pending || d.slots.length === 0}>
          <Icon name="check" />
          Confirm appointment
        </button>
        <small className="muted">You get a serial number. Pay at the chamber.</small>
      </aside>
    </form>
  );
}
