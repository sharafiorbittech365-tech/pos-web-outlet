/**
 * The multi-outlet website shown in this request: the GET /v1/multisite profile (website name and
 * brand from the POS Website settings, the outlets on the website, merged categories).
 * Port of app/Libraries/Site.php. Isomorphic: the static helpers are used by client components too.
 */
import { DEFAULT_CURRENCY, type Currency } from './format';
import { normaliseHost } from './host';

type Json = Record<string, any>;

const THEMES = ['fresh', 'bold', 'minimal', 'luxe', 'bistro', 'care', 'studio', 'neon', 'breeze', 'rose'] as const;
const THEME_BRAND: Record<string, string> = {
  luxe: '#C9A45C', care: '#0F766E', studio: '#7C3AED', neon: '#EC4899', breeze: '#0284C7',
  rose: '#BE185D', bistro: '#E8590C', fresh: '#2E7D32', minimal: '#A0522D', bold: '#2563EB',
};

export type Outlet = Json;

export class Site {
  readonly website: Json;
  readonly outlets: Outlet[];
  readonly categories: Json[];

  constructor(profile: Json, public readonly host = '') {
    this.website = profile?.website && typeof profile.website === 'object' ? profile.website : {};
    this.outlets = (Array.isArray(profile?.outlets) ? profile.outlets : []).filter((o: unknown) => o && typeof o === 'object');
    this.categories = (Array.isArray(profile?.categories) ? profile.categories : []).filter((c: unknown) => c && typeof c === 'object');
  }

  name(): string {
    return String(this.website.name ?? 'Our stores');
  }

  /** A business word of the home outlet (website.labels from the API), e.g. term('catalog', 'Products') = "Medicines". */
  term(key: string, fallback: string): string {
    const v = this.website.labels?.[key];
    return typeof v === 'string' && v.trim() !== '' ? v : fallback;
  }

  tagline(): string {
    return String(this.website.tagline ?? '');
  }

  brand(): Json {
    return this.website.brand && typeof this.website.brand === 'object' ? this.website.brand : {};
  }

  theme(): string {
    const t = String(this.website.theme ?? '');
    return (THEMES as readonly string[]).includes(t) ? t : 'fresh';
  }

  brandColor(): string {
    return Site.cssColor(String(this.brand().color ?? '')) ?? THEME_BRAND[this.theme()];
  }

  accentColor(): string | null {
    return Site.cssColor(String(this.brand().accent ?? ''));
  }

  darkColor(): string | null {
    return Site.cssColor(String(this.brand().dark ?? ''));
  }

  static cssColor(v: string): string | null {
    const s = v.trim();
    return /^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/.test(s) ? s : null;
  }

  currency(): Currency {
    const c = this.website.currency ?? {};
    return {
      symbol: String(c.symbol ?? DEFAULT_CURRENCY.symbol),
      decimals: Math.trunc(Number(c.decimals ?? 0)) || 0,
      position: String(c.position ?? 'before'),
      code: String(c.code ?? 'BDT'),
    };
  }

  seoTitle(): string {
    const t = String(this.website.seo?.title ?? '').trim();
    const n = this.outlets.length;
    return t !== '' ? t : `${this.name()} · Order online from ${n} outlet${n === 1 ? '' : 's'}`;
  }

  seoDescription(): string {
    return String(this.website.seo?.description || this.tagline());
  }

  /** Public address (canonical links), e.g. www.yourstores.com. */
  publicHost(): string {
    return normaliseHost(String(this.website.host ?? '')) ?? '';
  }

  outlet(id: number): Outlet | null {
    return this.outlets.find((o) => Math.trunc(Number(o.id ?? 0)) === id) ?? null;
  }

  outletName(id: number): string {
    return String(this.outlet(id)?.name ?? '');
  }

  /** Categories that have at least one product. */
  liveCategories(): Json[] {
    return this.categories.filter((c) => Number(c.products ?? 1) > 0);
  }

  categoryName(key: string): string {
    return String(this.categories.find((c) => String(c.key ?? '') === key)?.name ?? '');
  }

  /** Whether the website is open (it answered with outlets at all). */
  isOpen(): boolean {
    return this.outlets.length > 0;
  }

  /** Plain data for client components. */
  toClient(): { name: string; currency: Currency; outlets: Outlet[] } {
    return { name: this.name(), currency: this.currency(), outlets: this.outlets };
  }

  /** "Road 1, Banani, Dhaka" */
  static address(o: Outlet): string {
    return [o.address, o.area, o.city].map((x) => String(x ?? '').trim()).filter((x) => x !== '').join(', ');
  }

  /** Map link for an outlet: its coordinates when known, else its address (no map service is embedded). */
  static mapUrl(o: Outlet): string {
    const lat = Number(o.lat);
    const lng = Number(o.lng);
    if (o.lat !== null && o.lat !== undefined && o.lng !== null && o.lng !== undefined && Number.isFinite(lat) && Number.isFinite(lng)) {
      return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(`${lat.toFixed(6)},${lng.toFixed(6)}`);
    }
    const a = Site.address(o);
    return a !== '' ? 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(`${String(o.name ?? '')}, ${a}`) : '';
  }

  static hours(o: Outlet): string {
    const h = o.hours;
    return h && typeof h === 'object' && h.open !== undefined && h.close !== undefined ? `${String(h.open)} – ${String(h.close)}` : '';
  }

  /** Payment methods an outlet accepts, in display order. */
  static paymentMethods(o: Outlet): string[] {
    const c = o.checkout && typeof o.checkout === 'object' ? o.checkout : {};
    return ['cod', 'bkash', 'card'].filter((m) => Boolean(c[m]) || (m === 'cod' && !('cod' in c)));
  }
}
