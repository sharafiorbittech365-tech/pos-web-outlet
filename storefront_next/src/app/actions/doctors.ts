'use server';
/** Doctors::book — POST /doctors/{id}; the doctor id travels in a hidden `doctor_id` field. */
import { randomBytes } from 'node:crypto';
import { redirect } from 'next/navigation';
import { isApiError } from '@/lib/api/errors';
import { getContext } from '@/lib/context';
import { sha } from '@/lib/cookies';
import { rememberBooking } from '@/lib/done';
import { failed, fromApi, type FormState } from '@/lib/form-errors';
import { setFlash } from '@/lib/flash';
import { formToObject } from '@/lib/request';
import { ClinicSite } from '@/lib/services/clinic';
import { throttle, TOO_MANY, verifyHuman } from '@/lib/shield';

export async function bookDoctor(prev: FormState, fd: FormData): Promise<FormState> {
  const { store, api } = await getContext();
  const input = formToObject(fd);
  const id = Math.trunc(Number(input.doctor_id ?? 0));
  const wait = await throttle('post:doctor', 8, 120);
  if (wait > 0) return failed(prev, input, {}, TOO_MANY(wait));
  if (!store.isClinic()) redirect('/book');
  if (!(id > 0)) redirect('/doctors');

  const svc = new ClinicSite(api);
  const errors = svc.validate(input);
  if (Object.keys(errors).length > 0) return failed(prev, input, errors);
  const human = await verifyHuman(input);
  if (human !== null) return failed(prev, input, {}, human);

  const payload = svc.payload(id, input);
  const key = (input._idem || 'd' + randomBytes(12).toString('hex')) + '-' + sha(JSON.stringify(payload), 12);
  let b: Record<string, any>;
  try {
    b = await api.createBooking(payload, key);
  } catch (e) {
    if (isApiError(e)) {
      if (e.status === 422) {
        const fields: Record<string, string> = {};
        for (const [k, v] of Object.entries(fromApi(e.details))) fields[k.startsWith('patient.') ? k.slice(8) : k] = v;
        return failed(prev, input, fields, e.message);
      }
      if (e.status === 409) return failed(prev, input, { time: 'That time was just taken. Please pick another.' }, e.message);
      if (e.status === 429) return failed(prev, input, {}, 'Too many requests. Please try again later or call us.');
      if (e.status === 403 || e.status === 404) {
        await setFlash({ error: 'Online appointments are not available right now. Please call us.' });
        redirect('/doctors');
      }
    }
    throw e;
  }
  const ref = String(b.reference ?? '');
  await rememberBooking(ref, { ...b, kind: 'appointment', name: (input.name ?? '').trim(), created_at: new Date().toISOString() });
  redirect('/doctors/booked/' + encodeURIComponent(ref));
}
