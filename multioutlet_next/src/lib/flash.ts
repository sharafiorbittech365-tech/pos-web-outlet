/**
 * One-shot messages across a redirect (CodeIgniter flashdata). Stored in a short-lived cookie that the
 * browser clears after showing it (see components/Toast). Only plain messages live here — field errors
 * and the values the visitor typed travel in the Server Action result instead.
 */
import 'server-only';
import { getCookie, setCookie } from './cookies';

export interface Flash {
  ok?: string;
  error?: string;
  /** outlet the visitor tried to add from while the cart belongs to another outlet */
  switchTo?: number;
  /** nonce: changes with every flash so the browser reacts to each one exactly once */
  t?: number;
}

export const FLASH_COOKIE = 'ms_flash';

export async function readFlash(): Promise<Flash> {
  const raw = await getCookie(FLASH_COOKIE);
  if (!raw) return {};
  try {
    const f = JSON.parse(raw) as Flash;
    return {
      ok: typeof f.ok === 'string' ? f.ok : undefined,
      error: typeof f.error === 'string' ? f.error : undefined,
      switchTo: typeof f.switchTo === 'number' && f.switchTo > 0 ? Math.trunc(f.switchTo) : undefined,
      t: typeof f.t === 'number' ? f.t : undefined,
    };
  } catch {
    return {};
  }
}

/** Merge into the pending flash (call from a Server Action / Route Handler before redirecting). */
export async function setFlash(f: Omit<Flash, 't'>): Promise<void> {
  const cur = await readFlash();
  await setCookie(FLASH_COOKIE, JSON.stringify({ ...cur, ...f, t: Date.now() }), { maxAge: 60, httpOnly: false });
}
