'use server';
/**
 * Buyer sign-in / sign-up / sign-out (AuthController). The API answers a sign-in with a signed token that is
 * kept in an httpOnly cookie (mk_buyer); signing out is forgetting it.
 */
import { redirect } from 'next/navigation';
import { isApiError } from '@/lib/api/errors';
import { marketApi } from '@/lib/api/market-api';
import { setFlash } from '@/lib/flash';
import { failed, type FormState } from '@/lib/form-errors';
import { formToObject } from '@/lib/request';
import { throttle, TOO_MANY, verifyHuman } from '@/lib/shield';
import { afterLogin, forgetBuyer, setBuyerToken } from '@/lib/visitor';

const fieldErrors = (details: Record<string, unknown>): Record<string, string> => Object.fromEntries(Object.entries(details ?? {}).map(([k, m]) => [k, Array.isArray(m) ? m.join(' ') : String(m)]));

export async function loginAction(prev: FormState, fd: FormData): Promise<FormState> {
  const v = formToObject(fd);
  const wait = await throttle('post:login', 12, 60);
  if (wait > 0) return failed(prev, v, {}, TOO_MANY(wait));
  const human = await verifyHuman(v);
  if (human !== null) return failed(prev, v, { shield: human }, human);
  let r;
  try {
    r = await (await marketApi()).login(String(v.phone ?? ''), String(v.password ?? ''));
  } catch (e) {
    if (isApiError(e) && [401, 422, 429].includes(e.status)) return failed({ ...prev }, { phone: v.phone ?? '', next: v.next ?? '' }, fieldErrors(e.details), e.message);
    throw e;
  }
  await setBuyerToken(r.token, r.expires_at);
  await setFlash({ success: `Welcome back, ${String(r.buyer.name)}.` });
  redirect(afterLogin(v.next));
}

export async function signupAction(prev: FormState, fd: FormData): Promise<FormState> {
  const v = formToObject(fd);
  const wait = await throttle('post:signup', 6, 300);
  if (wait > 0) return failed(prev, v, {}, TOO_MANY(wait));
  const keep = { name: v.name ?? '', phone: v.phone ?? '', email: v.email ?? '', next: v.next ?? '' };
  if ((v.password ?? '') !== (v.password_confirm ?? '')) return failed(prev, keep, { password_confirm: 'The passwords do not match.' });
  const human = await verifyHuman(v);
  if (human !== null) return failed(prev, keep, { shield: human }, human);
  let r;
  try {
    r = await (await marketApi()).register({ name: v.name, phone: v.phone, email: v.email ?? '', password: v.password, password_confirm: v.password_confirm });
  } catch (e) {
    if (isApiError(e) && [409, 422].includes(e.status)) return failed(prev, keep, fieldErrors(e.details), e.message);
    throw e;
  }
  await setBuyerToken(r.token, r.expires_at);
  await setFlash({ success: `Your account is ready, ${String(r.buyer.name)}.` });
  redirect(afterLogin(v.next));
}

export async function logoutAction(): Promise<void> {
  await forgetBuyer();
  await setFlash({ success: 'You are signed out.' });
  redirect('/');
}
