/** Request helpers for Server Actions and Route Handlers. */
import 'server-only';
import { headers } from 'next/headers';

/** "Shop.Example.com:8080" → "shop.example.com" (null when empty). */
const normaliseHost = (h: string): string | null => {
  const v = h.trim().toLowerCase().replace(/:\d+$/, '').replace(/\.+$/, '');
  return v === '' ? null : v;
};

/** Path (+ query) of the Referer when it points to this host, else "/". Prevents open redirects. */
export async function sameSiteBack(): Promise<string> {
  const h = await headers();
  const ref = h.get('referer') ?? '';
  if (ref === '') return '/';
  let u: URL;
  try {
    u = new URL(ref);
  } catch {
    return '/';
  }
  const mine = normaliseHost(h.get('x-forwarded-host') ?? h.get('host') ?? '');
  if (mine !== null && normaliseHost(u.host) !== mine) return '/';
  return '/' + u.pathname.replace(/^\/+/, '') + u.search;
}

/** FormData → plain object of strings (files and repeated keys: last value wins). */
export function formToObject(fd: FormData): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [k, v] of fd.entries()) {
    if (typeof v === 'string' && !k.startsWith('$ACTION')) out[k] = v;
  }
  return out;
}
