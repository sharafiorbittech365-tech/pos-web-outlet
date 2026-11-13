/**
 * Booking: free slots and the booking request for every booking kind (appointment, room, table,
 * pickup, quote, visit, repair). Port of Modules/Booking/Services/BookingService.php.
 */
import { isApiError } from '../api/errors';
import { type Json, type StoreApi } from '../api/storefront-api';
import { strtotime, today, validDate } from '../format';
import { type Store } from '../store';
import { normalisePhone, STARTS_AT, validEmail, validPhone } from './validate';

export const TIMED = ['appointment', 'room', 'table', 'pickup', 'visit', 'repair'];

export interface Slot {
  time: string;
  starts_at: string;
  staff: string[];
}
export interface SlotResult {
  slots: Slot[];
  staff: string[];
  duration: number;
}

const EMPTY: SlotResult = { slots: [], staff: [], duration: 0 };
const str = (in_: Record<string, unknown>, k: string): string => String(in_[k] ?? '').trim();

export class BookingService {
  constructor(private readonly api: StoreApi, private readonly store: Store) {}

  /** Free times on a day (optionally for one service / stylist). Never cached. */
  async slots(date: string, productId: number | null = null, staff: string | null = null): Promise<SlotResult> {
    if (!validDate(date) || date < today()) return { ...EMPTY };
    let r;
    try {
      r = await this.api.slots({ date, product_id: productId, staff: staff !== null && staff !== 'any' ? staff : null });
    } catch (e) {
      if (isApiError(e) && [403, 404, 422].includes(e.status)) return { ...EMPTY };
      throw e;
    }
    const nowS = Math.floor(Date.now() / 1000);
    const slots: Slot[] = [];
    for (const s of r.data) {
      if (!s || typeof s !== 'object' || s.time === undefined || s.starts_at === undefined) continue;
      const t = strtotime(String(s.starts_at));
      if (t !== null && t <= nowS) continue; // the API may list the rest of today including times just passed
      slots.push({ time: String(s.time), starts_at: String(s.starts_at), staff: (Array.isArray(s.staff) ? s.staff : []).map(String) });
    }
    const team = (Array.isArray(r.meta.staff) ? r.meta.staff : []).map(String).filter((n: string) => n !== '' && n !== 'Any');
    return { slots, staff: team, duration: Number(r.meta.duration_min ?? 0) || 0 };
  }

  /** Staff who take bookings: the site's own list, else whoever the API lists for today. */
  async staff(): Promise<string[]> {
    const own = this.store.staff();
    return own.length > 0 ? own : (await this.slots(today())).staff;
  }

  /** Field → message, empty when the input is fine. */
  validate(input: Record<string, unknown>): Record<string, string> {
    const kind = this.store.bookingKind();
    const e: Record<string, string> = {};
    const name = str(input, 'name');
    if (name === '' || String(input.name ?? '').length > 100) e.name = 'Please enter your name.';
    if (!validPhone(String(input.phone ?? ''))) e.phone = 'Please enter a valid mobile number, e.g. 01711000000.';
    if (str(input, 'email') !== '' && !validEmail(str(input, 'email'))) e.email = 'Please enter a valid email address.';
    if (TIMED.includes(kind)) {
      const t = String(input.starts_at ?? '');
      const ts = STARTS_AT.test(t) ? strtotime(t) : null;
      if (ts === null) e.time = 'Please pick a time.';
      else if (ts < Math.floor(Date.now() / 1000)) e.time = 'That time has already passed. Please pick another.';
    }
    if (['room', 'table'].includes(kind)) {
      const party = Math.trunc(Number(input.party ?? 0));
      if (!(party >= 1 && party <= 50)) e.party = 'How many people?';
    }
    const required: Record<string, string> =
      kind === 'pickup' ? { address: 'Where should we pick up?' }
        : kind === 'quote' ? { details: 'Please describe what you need.' }
          : kind === 'repair' ? { device: 'Which device?', details: 'What is wrong with it?' }
            : {};
    for (const [f, msg] of Object.entries(required)) if (str(input, f) === '') e[f] = msg;
    if (kind === 'quote' && str(input, 'due') !== '' && !validDate(str(input, 'due'))) e.due = 'Please pick a valid date.';
    const max: Record<string, number> = { details: 2000, note: 500, address: 300, device: 120, budget: 120, file_link: 500, interest: 200 };
    for (const [f, m] of Object.entries(max)) if (Array.from(String(input[f] ?? '')).length > m) e[f] = 'This is too long.';
    if (str(input, 'file_link') !== '' && !/^https?:\/\//i.test(String(input.file_link))) e.file_link = 'Please paste a link starting with https://';
    return e;
  }

  /** The POST /v1/bookings body for the validated input. */
  payload(input: Record<string, unknown>): Json {
    const kind = this.store.bookingKind();
    const s = (k: string): string => str(input, k);
    const keep = (o: Json): Json => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== '' && v !== 0 && v !== null && v !== undefined));
    let details: Json = {};
    switch (kind) {
      case 'room': details = keep({ hours: Math.max(1, Math.min(12, Math.trunc(Number(input.hours ?? 2)) || 2)) }); break;
      case 'pickup': details = keep({ address: s('address'), items: s('details') }); break;
      case 'quote': details = keep({ description: s('details'), [this.store.isAgency() ? 'budget' : 'quantity']: s('budget'), needed_by: s('due'), file_link: s('file_link') }); break;
      case 'repair': details = keep({ device: s('device'), problem: s('details') }); break;
      case 'visit': details = keep({ interest: s('interest') }); break;
      default: break;
    }
    const customer = Object.fromEntries(Object.entries({ name: s('name'), phone: normalisePhone(s('phone')), email: s('email') }).filter(([, v]) => v !== ''));
    const service = Math.trunc(Number(input.service ?? 0));
    const out: Json = {
      kind,
      customer,
      product_id: service > 0 ? service : null,
      staff: s('staff') !== '' && s('staff') !== 'any' ? s('staff') : null,
      starts_at: TIMED.includes(kind) ? s('starts_at') : null,
      party_size: ['room', 'table'].includes(kind) ? Math.trunc(Number(input.party ?? 0)) : null,
      details: Object.keys(details).length > 0 ? details : null,
      note: s('note') !== '' ? s('note') : null,
    };
    return Object.fromEntries(Object.entries(out).filter(([, v]) => v !== null));
  }

  create(input: Record<string, unknown>, idempotencyKey: string): Promise<Json> {
    return this.api.createBooking(this.payload(input), idempotencyKey);
  }
}
