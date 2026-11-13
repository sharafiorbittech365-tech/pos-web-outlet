'use server';
/** Book a service (BookingController::book): validate → human check → POST /v1/market/bookings (idempotent by the form token). */
import { redirect } from 'next/navigation';
import { isApiError } from '@/lib/api/errors';
import { marketApi } from '@/lib/api/market-api';
import { failed, fromApi, type FormState } from '@/lib/form-errors';
import { formToObject } from '@/lib/request';
import { throttle, TOO_MANY, verifyHuman } from '@/lib/shield';

export async function bookService(prev: FormState, fd: FormData): Promise<FormState> {
  const v = formToObject(fd);
  const wait = await throttle('post:book', 8, 120);
  if (wait > 0) return failed(prev, v, {}, TOO_MANY(wait));
  const errors: Record<string, string> = {};
  if (Array.from((v.name ?? '').trim()).length < 2) errors.name = 'Please enter your name.';
  if ((v.phone ?? '').trim() === '') errors.phone = 'Use a mobile number like 01712345678.';
  if (v.timed === '1' && (v.starts_at ?? '') === '') errors.time = 'Pick a time.';
  if (Object.keys(errors).length > 0) return failed(prev, v, errors);
  const human = await verifyHuman(v);
  if (human !== null) return failed(prev, v, { shield: human }, human);

  let token: string;
  try {
    const r = await (await marketApi()).book({
      token: v.token, business_id: v.business_id, product_id: v.product_id, name: v.name, phone: v.phone,
      starts_at: v.starts_at ?? '', staff: v.staff ?? '', address: v.address ?? '', note: v.note ?? '',
    });
    token = String(r.token ?? v.token);
  } catch (e) {
    if (isApiError(e) && [403, 404, 409, 422].includes(e.status)) return failed(prev, v, fromApi(e.details), e.message);
    throw e;
  }
  redirect('/book/done/' + encodeURIComponent(token));
}
