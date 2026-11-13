/**
 * Form bot protection, ported from OmniPOS\Ui\Shield\Shield so the tokens work the same way:
 *   - honeypot field (shield_hp must stay empty)
 *   - signed form timestamp (shield_ts): not faster than 2 s, not older than 2 h
 *   - the "human check": a small arithmetic question with a signed, single-use, 30-minute token —
 *     or Cloudflare Turnstile when TURNSTILE_SITE_KEY + TURNSTILE_SECRET are set
 *   - an in-memory rate limit per visitor (use the web server / CDN for real flood protection)
 */
import 'server-only';
import { createHmac, randomBytes, randomInt, timingSafeEqual } from 'node:crypto';
import { headers } from 'next/headers';
import { config } from './config';

const MIN_FILL_SECONDS = 2;
const MAX_FORM_AGE = 7200;
const CHALLENGE_TTL = 1800;

const sign = (data: string): string => createHmac('sha256', config.shieldSecret).update(data).digest('hex').slice(0, 32);

function safeEqual(a: string, b: string): boolean {
  const x = Buffer.from(a);
  const y = Buffer.from(b);
  return x.length === y.length && timingSafeEqual(x, y);
}

const now = (): number => Math.floor(Date.now() / 1000);

export interface ShieldFields {
  ts: string;
  /** Arithmetic mode: the question "3 + 4" and its signed token. */
  question?: string;
  token?: string;
  /** Turnstile mode: the public site key. */
  turnstileSiteKey?: string;
}

/** Fields to render in a form (call per request / per refresh: the token is single-use). */
export function shieldFields(): ShieldFields {
  const t = String(now());
  const ts = `${t}.${sign('ts|' + t)}`;
  if (config.turnstileSiteKey !== '' && config.turnstileSecret !== '') return { ts, turnstileSiteKey: config.turnstileSiteKey };
  const a = randomInt(2, 10);
  const b = randomInt(2, 10);
  const exp = now() + CHALLENGE_TTL;
  const nonce = randomBytes(6).toString('hex');
  return { ts, question: `${a} + ${b}`, token: `${nonce}.${exp}.${sign(`ch|${nonce}|${exp}|${a + b}`)}` };
}

const used = new Map<string, number>();
function markUsed(nonce: string): boolean {
  const t = now();
  for (const [k, exp] of used) if (exp < t) used.delete(k);
  if (used.has(nonce)) return false;
  used.set(nonce, t + CHALLENGE_TTL + 60);
  return true;
}

/** The honeypot + timestamp checks only (no human question) — null when fine, else a message. */
export function verifyForm(input: Record<string, unknown>): string | null {
  if (String(input.shield_hp ?? '').trim() !== '') return 'This request looks automated.';
  const m = /^(\d{9,11})\.([a-f0-9]{32})$/.exec(String(input.shield_ts ?? ''));
  if (!m || !safeEqual(sign('ts|' + m[1]), m[2])) return 'This form expired. Please reload the page and try again.';
  const age = now() - Number(m[1]);
  if (age < MIN_FILL_SECONDS) return 'That was too fast — please take a moment and try again.';
  if (age > MAX_FORM_AGE) return 'This form expired. Please reload the page and try again.';
  return null;
}

/** Full check for a form that needs the human question. Returns null when fine, else a message. */
export async function verifyHuman(input: Record<string, unknown>): Promise<string | null> {
  const basic = verifyForm(input);
  if (basic !== null) return basic;

  if (config.turnstileSecret !== '') return verifyTurnstile(String(input['cf-turnstile-response'] ?? ''), config.turnstileSecret);

  const ans = String(input.shield_ans ?? '').trim();
  const c = /^([a-f0-9]{12})\.(\d{10})\.([a-f0-9]{32})$/.exec(String(input.shield_ch ?? ''));
  if (!c || ans === '' || !/^\d+$/.test(ans)) return 'Please answer the quick check - it only takes a second.';
  if (Number(c[2]) < now()) return 'The quick check timed out - please answer the new one.';
  if (!safeEqual(sign(`ch|${c[1]}|${c[2]}|${Number(ans)}`), c[3])) return 'That answer is not right — please try again.';
  if (!markUsed(c[1])) return 'That check was already used - please answer the new one.';
  return null;
}

async function verifyTurnstile(response: string, secret: string): Promise<string | null> {
  if (response === '' || response.length > 2048) return 'Please complete the human check.';
  try {
    const r = await fetch('https://challenges.cloudflare.com/turnstile/v0/siteverify', {
      method: 'POST',
      body: new URLSearchParams({ secret, response, remoteip: await clientIp() }),
      signal: AbortSignal.timeout(5000),
      cache: 'no-store',
    });
    const j = (await r.json()) as { success?: boolean };
    return j.success ? null : 'The human check failed — please try again.';
  } catch (e) {
    console.error('[multioutlet] Turnstile check failed:', e instanceof Error ? e.message : e);
    return 'The human check failed — please try again.';
  }
}

export async function clientIp(): Promise<string> {
  const h = await headers();
  return (h.get('x-forwarded-for') ?? '').split(',')[0].trim() || h.get('x-real-ip') || 'unknown';
}

// ---- rate limit (fixed window, per visitor + bucket, in memory) ----------------------------------
const hits = new Map<string, { n: number; reset: number }>();

/** Seconds to wait when the visitor is over the limit, 0 when the request may go on. */
export async function throttle(bucket: string, max: number, seconds: number): Promise<number> {
  const key = `${bucket}|${await clientIp()}`;
  const t = Date.now();
  if (hits.size > 5000) for (const [k, v] of hits) if (v.reset < t) hits.delete(k);
  const cur = hits.get(key);
  if (!cur || cur.reset < t) {
    hits.set(key, { n: 1, reset: t + seconds * 1000 });
    return 0;
  }
  cur.n++;
  return cur.n > max ? Math.max(1, Math.ceil((cur.reset - t) / 1000)) : 0;
}

export const TOO_MANY = (wait: number): string => `Too many requests. Please wait ${wait} seconds and try again.`;
