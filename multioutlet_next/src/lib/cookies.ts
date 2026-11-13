/** Small helpers around next/headers cookies with the website's defaults. */
import 'server-only';
import { cookies } from 'next/headers';
import { createHash } from 'node:crypto';
import { config } from './config';

export const sha = (s: string, len = 12): string => createHash('sha1').update(s).digest('hex').slice(0, len);

export async function getCookie(name: string): Promise<string | undefined> {
  return (await cookies()).get(name)?.value;
}

export async function readJson<T>(name: string): Promise<T | null> {
  const raw = await getCookie(name);
  if (!raw) return null;
  try {
    return JSON.parse(raw) as T;
  } catch {
    return null;
  }
}

export async function setCookie(name: string, value: string, opts: { maxAge: number; httpOnly?: boolean }): Promise<void> {
  (await cookies()).set(name, value, {
    path: '/',
    maxAge: opts.maxAge,
    httpOnly: opts.httpOnly ?? true,
    sameSite: 'lax',
    secure: config.cookieSecure,
  });
}

export async function writeJson(name: string, value: unknown, maxAge: number, httpOnly = true): Promise<void> {
  await setCookie(name, JSON.stringify(value), { maxAge, httpOnly });
}

export async function deleteCookie(name: string): Promise<void> {
  (await cookies()).delete(name);
}
