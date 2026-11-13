/** Clinic sites: doctors and their appointments. Port of Modules/Clinic/Services/ClinicSite.php. */
import { isApiError } from '../api/errors';
import { type Json, type StoreApi } from '../api/storefront-api';
import { phpDate, strtotime, validDate, today } from '../format';
import { normalisePhone, STARTS_AT, validEmail, validPhone } from './validate';

export interface DoctorSlot {
  time: string;
  starts_at: string;
}

export class ClinicSite {
  constructor(private readonly api: StoreApi) {}

  async doctors(): Promise<Json[]> {
    try {
      return (await this.api.doctors()).filter((d) => d && typeof d === 'object');
    } catch (e) {
      if (isApiError(e) && [403, 404].includes(e.status)) return [];
      throw e;
    }
  }

  /** One doctor (with open_dates), or null when the API does not know or may not show them. */
  async doctor(id: number): Promise<Json | null> {
    try {
      return await this.api.doctor(id);
    } catch (e) {
      if (isApiError(e) && [403, 404].includes(e.status)) return null;
      throw e;
    }
  }

  /** Free slots of a doctor on a date (past times and taken slots removed). */
  async slots(id: number, date: string): Promise<DoctorSlot[]> {
    if (!validDate(date) || date < today()) return [];
    let r;
    try {
      r = await this.api.doctorSlots(id, date);
    } catch (e) {
      if (isApiError(e) && [403, 404, 422].includes(e.status)) return [];
      throw e;
    }
    const now = Math.floor(Date.now() / 1000);
    const out: DoctorSlot[] = [];
    for (const s of r.data) {
      if (s && typeof s === 'object' && s.time !== undefined && s.starts_at !== undefined && (s.available ?? true) && (strtotime(String(s.starts_at)) ?? 0) > now) {
        out.push({ time: String(s.time), starts_at: String(s.starts_at) });
      }
    }
    return out;
  }

  validate(input: Record<string, unknown>): Record<string, string> {
    const e: Record<string, string> = {};
    const s = (k: string): string => String(input[k] ?? '').trim();
    if (s('name') === '' || Array.from(String(input.name ?? '')).length > 100) e.name = "Please enter the patient's name.";
    if (!validPhone(String(input.phone ?? ''))) e.phone = 'Please enter a valid mobile number, e.g. 01711000000.';
    if (s('email') !== '' && !validEmail(s('email'))) e.email = 'Please enter a valid email address.';
    if (s('age') !== '' && (!/^\d+$/.test(s('age')) || Number(s('age')) > 130)) e.age = 'Age in years.';
    if (s('sex') !== '' && !['male', 'female', 'other'].includes(s('sex'))) e.sex = 'Please choose.';
    const t = String(input.starts_at ?? '');
    const ts = STARTS_AT.test(t) ? strtotime(t) : null;
    if (ts === null) e.time = 'Please pick a time.';
    else if (ts < Math.floor(Date.now() / 1000)) e.time = 'That time has already passed. Please pick another.';
    if (Array.from(String(input.note ?? '')).length > 500) e.note = 'This is too long.';
    return e;
  }

  payload(doctorId: number, input: Record<string, unknown>): Json {
    const s = (k: string): string => String(input[k] ?? '').trim();
    const patient = Object.fromEntries(
      Object.entries({ name: s('name'), phone: normalisePhone(s('phone')), email: s('email'), sex: s('sex'), age: s('age') !== '' ? Number(s('age')) : null }).filter(([, v]) => v !== '' && v !== null),
    );
    const out: Json = { kind: 'appointment', doctor_id: doctorId, starts_at: s('starts_at'), channel: 'storefront', patient, note: s('note') !== '' ? s('note') : null };
    return Object.fromEntries(Object.entries(out).filter(([, v]) => v !== null));
  }

  /** "Mon 9:00 AM–1:00 PM · Wed …" */
  static scheduleText(doctor: Json): string {
    return (Array.isArray(doctor.schedule) ? doctor.schedule : [])
      .map((s: Json) => `${String(s.day).slice(0, 3)} ${phpDate('g:i A', timeToDate(String(s.from)))}–${phpDate('g:i A', timeToDate(String(s.to)))}`)
      .join(' · ');
  }
}

/** "09:30" / "09:30:00" → a Date today at that time (for formatting only). */
function timeToDate(t: string): Date {
  const m = /^(\d{1,2}):(\d{2})/.exec(t);
  const d = new Date();
  d.setHours(m ? Number(m[1]) : 0, m ? Number(m[2]) : 0, 0, 0);
  return d;
}
