/**
 * One-shot messages across a redirect (CodeIgniter flashdata). Stored in a short-lived cookie that the
 * browser clears after showing it (see components/FlashBridge). Only plain messages live here —
 * field errors and the values the visitor typed travel in the Server Action result instead.
 */
import 'server-only';
import { getCookie, setCookie } from './cookies';

export interface Flash {
  ok?: string;
  error?: string;
  /** open the cart drawer */
  added?: boolean;
  /** nonce: changes with every flash so the browser reacts to each one exactly once */
  t?: number;
}

export const FLASH_COOKIE = 'sf_flash';

export async function readFlash(): Promise<Flash> {
  const raw = await getCookie(FLASH_COOKIE);
  if (!raw) return {};
  try {
    const f = JSON.parse(raw) as Flash;
    return {
      ok: typeof f.ok === 'string' ? f.ok : undefined,
      error: typeof f.error === 'string' ? f.error : undefined,
      added: f.added === true,
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
