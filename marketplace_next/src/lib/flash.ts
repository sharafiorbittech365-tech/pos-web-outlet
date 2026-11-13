/**
 * One-shot messages across a redirect (CodeIgniter flashdata "success" / "error"). Stored in a short-lived
 * cookie; the layout shows them and the browser clears the cookie (components/FlashClear).
 */
import 'server-only';
import { getCookie, setCookie } from './cookies';

export interface Flash {
  success?: string;
  error?: string;
  t?: number;
}

export const FLASH_COOKIE = 'mk_flash';

export async function readFlash(): Promise<Flash> {
  const raw = await getCookie(FLASH_COOKIE);
  if (!raw) return {};
  try {
    const f = JSON.parse(raw) as Flash;
    return { success: typeof f.success === 'string' ? f.success : undefined, error: typeof f.error === 'string' ? f.error : undefined, t: typeof f.t === 'number' ? f.t : undefined };
  } catch {
    return {};
  }
}

/** Call from a Server Action before redirecting. */
export async function setFlash(f: Omit<Flash, 't'>): Promise<void> {
  await setCookie(FLASH_COOKIE, JSON.stringify({ ...f, t: Date.now() }), { maxAge: 60, httpOnly: false });
}
