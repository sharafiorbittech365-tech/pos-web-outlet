/**
 * Runtime configuration, read from environment variables (see .env.example).
 * Server-side only: nothing here is exposed to the browser (no NEXT_PUBLIC_ variables are used).
 * Port of app/Config/OmniPOS.php.
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
  /** API Service base URL, with a trailing slash. */
  get apiUrl(): string {
    return str('POS_API_URL', 'http://localhost:8093').replace(/\/+$/, '') + '/';
  },
  /** Shared gateway token (platform-hosted) or a shop's pk_live_… site key. */
  get gatewayToken(): string {
    return str('STOREFRONT_GATEWAY_TOKEN');
  },
  get clientId(): string {
    return str('CLIENT_ID');
  },
  get clientSecret(): string {
    return str('CLIENT_SECRET');
  },
  /** Self-hosted on a shop's own domain: CLIENT_ID + CLIENT_SECRET. */
  get isSelfHosted(): boolean {
    return this.clientId !== '';
  },
  /** The token is a shop's pk_… key, so the key itself names the shop. */
  get isSiteKey(): boolean {
    return !this.isSelfHosted && this.gatewayToken.startsWith('pk_');
  },
  get devHosts(): string[] {
    return str('DEV_HOSTS', 'localhost,127.0.0.1,::1')
      .split(',')
      .map((h) => h.trim().toLowerCase())
      .filter(Boolean);
  },
  get brandName(): string {
    return str('BRAND_NAME', 'BizPilot360X');
  },
  get perPage(): number {
    return int('PER_PAGE', 24, 1, 100);
  },
  get apiTimeoutMs(): number {
    return int('API_TIMEOUT_MS', 8000, 500, 60000);
  },
  get shieldSecret(): string {
    return str('SHIELD_SECRET', 'omnipos-storefront-dev-secret');
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
