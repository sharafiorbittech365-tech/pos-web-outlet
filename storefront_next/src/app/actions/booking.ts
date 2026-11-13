'use server';
/** Booking::create — POST /book. Returns the form state on errors, redirects on success. */
import { randomBytes } from 'node:crypto';
import { redirect } from 'next/navigation';
import { isApiError } from '@/lib/api/errors';
import { getContext } from '@/lib/context';
import { sha } from '@/lib/cookies';
import { rememberBooking } from '@/lib/done';
import { failed, fromApi, type FormState } from '@/lib/form-errors';
import { setFlash } from '@/lib/flash';
import { formToObject } from '@/lib/request';
import { BookingService } from '@/lib/services/booking';
import { throttle, TOO_MANY, verifyHuman } from '@/lib/shield';

export async function createBooking(prev: FormState, fd: FormData): Promise<FormState> {
  const { store, api } = await getContext();
  const input = formToObject(fd);
  const wait = await throttle('post:book', 8, 120);
  if (wait > 0) return failed(prev, input, {}, TOO_MANY(wait));
  if (!store.canBook()) redirect('/book');

  const svc = new BookingService(api, store);
  const errors = svc.validate(input);
  if (store.bookingKind() === 'appointment' && Math.trunc(Number(input.service ?? 0)) < 1 && input.has_services === '1') {
    errors.service = 'Please choose a service.';
  }
  if (Object.keys(errors).length > 0) return failed(prev, input, errors);

  const human = await verifyHuman(input);
  if (human !== null) return failed(prev, input, {}, human);

  const idem = (input._idem || 'b' + randomBytes(12).toString('hex')) + '-' + sha(JSON.stringify(svc.payload(input)), 12);
  let booking: Record<string, any>;
  try {
    booking = await svc.create(input, idem);
  } catch (e) {
    if (isApiError(e)) {
      if (e.status === 422) return failed(prev, input, fromApi(e.details), e.message);
      if (e.status === 409) return failed(prev, input, { time: 'That time was just taken. Please pick another.' }, e.message);
      if (e.status === 403) {
        await setFlash({ error: 'Online booking is not available right now. Please call us.' });
        redirect('/book');
      }
    }
    throw e;
  }
  const ref = String(booking.reference ?? '');
  await rememberBooking(ref, { ...booking, kind: store.bookingKind(), name: (input.name ?? '').trim(), created_at: new Date().toISOString() });
  redirect('/booked/' + encodeURIComponent(ref));
}
