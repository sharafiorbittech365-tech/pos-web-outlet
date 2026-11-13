/**
 * Runtime configuration, read from environment variables (see .env.example).
 * Server-side only: nothing here is exposed to the browser (no NEXT_PUBLIC_ variables are used).
 */
import 'server-only';

function str(name: string, fallback = ''): string {
  const v = process.env[name];
  return typeof v === 'string' && v.trim() !== '' ? v.trim() : fallback;
}

function int(name: string, fallback: number, min: number, max: number): number {
  const n = Number.parseInt(str(name), 10);
  return Number.isFinite(n) ? Math.min(max, Math.max(min, n)) : fallback;
}

export const config = {
  /** Marketplace API base URL, with a trailing slash. */
  get apiUrl(): string {
    return str('MARKETPLACE_API_URL', 'http://localhost:8095').replace(/\/+$/, '') + '/';
  },
  /** Shared secret with the Marketplace API (market.apiToken there). */
  get apiToken(): string {
    return str('MARKETPLACE_API_TOKEN');
  },
  get brandName(): string {
    return str('BRAND_NAME', 'BizPilot360X');
  },
  get apiTimeoutMs(): number {
    return int('API_TIMEOUT_MS', 8000, 500, 60000);
  },
  get shieldSecret(): string {
    return str('SHIELD_SECRET', 'omnipos-marketplace-dev-secret');
  },
  get turnstileSiteKey(): string {
    return str('TURNSTILE_SITE_KEY');
  },
  get turnstileSecret(): string {
    return str('TURNSTILE_SECRET');
  },
  get cookieSecure(): boolean {
    return str('COOKIE_SECURE') === '1';
  },
};
