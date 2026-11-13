/**
 * The shop being shown in this request: the GET /v1/storefront profile plus the template rules
 * the design derives from the business type (shop / booking / menu / service).
 * Port of app/Libraries/Store.php.
 */
import { DEFAULT_CURRENCY, type Currency, sfMoney } from './format';
import { normaliseHost } from './host';

type Json = Record<string, any>;

const CTA: Record<string, string> = {
  appointment: 'Book appointment', room: 'Book a room', table: 'Reserve a table',
  pickup: 'Schedule a pickup', quote: 'Get a quote', visit: 'Book a showroom visit', repair: 'Book a repair',
};

/** Template per business type when the API does not say: [kind, bookingKind]. */
const TYPE_KIND: Record<string, [string, string | null]> = {
  salon: ['booking', 'appointment'], clinic: ['booking', 'appointment'], karaoke: ['booking', 'room'],
  restaurant: ['menu', 'table'], cafe: ['menu', 'table'], bakery: ['menu', 'quote'],
  laundry: ['service', 'pickup'], printing: ['service', 'quote'], agency: ['service', 'quote'],
  pharmacy: ['shop', null], furniture: ['shop', 'visit'], electronics: ['shop', 'repair'],
  hardware: ['shop', 'quote'], wholesale: ['shop', 'quote'],
};

const THEMES = ['fresh', 'bold', 'minimal', 'luxe', 'bistro', 'care', 'studio', 'neon', 'breeze', 'rose'];
const THEME_BRAND: Record<string, string> = {
  luxe: '#C9A45C', care: '#0F766E', studio: '#7C3AED', neon: '#EC4899', breeze: '#0284C7',
  rose: '#BE185D', bistro: '#E8590C', fresh: '#2E7D32', minimal: '#A0522D', bold: '#2563EB',
};
const TYPE_TAG: Record<string, string> = {
  salon: 'Barber & salon', restaurant: 'Restaurant', cafe: 'Coffee house', bakery: 'Bakery & sweets', karaoke: 'Karaoke lounge',
  laundry: 'Laundry & dry cleaning', printing: 'Printing press', agency: 'Digital agency', pharmacy: 'Pharmacy', grocery: 'Super shop',
  cosmetics: 'Beauty & care', furniture: 'Furniture', electronics: 'Mobile & electronics', accessories: 'Gadgets', fashion: 'Fashion house',
  footwear: 'Shoe store', hardware: 'Hardware', wholesale: 'Wholesale', clinic: "Doctor's chamber & clinic",
};

export interface ProductPage {
  style: 'standard' | 'detailed';
  showCode: boolean; showCategory: boolean; showStock: boolean;
  orderLabel: string; cartLabel: string;
  callBox: boolean; callText: string; callPhone: string;
  messenger: string | null; whatsapp: string | null;
  deliveryTable: boolean; deliveryTitle: string; delivery: { area: string; charge: number }[];
  showNotes: boolean; notes: { icon: string; title: string; text: string }[];
}

export class Store {
  readonly business: Json;
  readonly site: Json;
  readonly categories: Json[];

  constructor(profile: Json, public readonly host: string = '') {
    this.business = (profile.business ?? {}) as Json;
    this.site = (profile.site ?? {}) as Json;
    this.categories = (Array.isArray(profile.categories) ? profile.categories : []).filter((c: unknown) => c && typeof c === 'object');
  }

  name(): string {
    return String(this.business.name ?? 'Online store');
  }

  /** A business word from the API (business.labels), e.g. term('staff', 'Team') = "Stylist". */
  term(key: string, fallback: string): string {
    const v = this.business.labels?.[key];
    return typeof v === 'string' && v.trim() !== '' ? v : fallback;
  }

  type(): string {
    return String(this.business.type ?? '');
  }

  slug(): string {
    return String(this.site.slug ?? '');
  }

  /** shop | booking | menu | service */
  kind(): 'shop' | 'booking' | 'menu' | 'service' {
    const k = String(this.site.kind ?? '');
    if (k === 'shop' || k === 'booking' || k === 'menu' || k === 'service') return k;
    return (TYPE_KIND[this.type()]?.[0] ?? 'shop') as 'shop' | 'booking' | 'menu' | 'service';
  }

  /** appointment | room | table | pickup | quote | visit | repair | '' */
  bookingKind(): string {
    const k = String(this.site.booking_kind ?? TYPE_KIND[this.type()]?.[1] ?? '');
    return k in CTA ? k : '';
  }

  canBook(): boolean {
    return this.bookingKind() !== '' && Boolean(this.site.bookings_enabled ?? false);
  }

  /** Clinic site: doctors and online appointments instead of the generic booking form. */
  isClinic(): boolean {
    return this.canBook() && Boolean(this.site.clinic_enabled ?? false);
  }

  cta(): string {
    const k = this.bookingKind();
    if (k === 'quote') {
      switch (this.type()) {
        case 'bakery': return 'Order a custom cake';
        case 'agency': return 'Request a proposal';
        case 'hardware': return 'Get a rate quotation';
        case 'wholesale': return 'Request dealer prices';
        default: return 'Get a quote';
      }
    }
    return CTA[k] ?? 'Book now';
  }

  /** Whether the shop sells for delivery (salons and karaoke only take bookings; agencies only quotes). */
  sells(): boolean {
    return this.kind() !== 'booking' && this.type() !== 'agency';
  }

  isAgency(): boolean {
    return this.type() === 'agency';
  }

  isPharmacy(): boolean {
    return this.type() === 'pharmacy';
  }

  /** Quote forms that take a design file / brief (printing, agency). */
  takesFiles(): boolean {
    return ['printing', 'agency'].includes(this.type());
  }

  theme(): string {
    const t = String(this.site.theme ?? '');
    return THEMES.includes(t) ? t : 'fresh';
  }

  brand(): Json {
    return (this.site.brand ?? {}) as Json;
  }

  /** Brand colour for --brand: the owner's colour, else the theme's. Only safe colour literals pass. */
  brandColor(): string {
    return Store.cssColor(String(this.brand().color ?? '')) ?? THEME_BRAND[this.theme()];
  }

  accentColor(): string | null {
    return Store.cssColor(String(this.brand().accent ?? ''));
  }

  darkColor(): string | null {
    return Store.cssColor(String(this.brand().dark ?? ''));
  }

  static cssColor(v: string): string | null {
    const s = v.trim();
    return /^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/.test(s) ? s : null;
  }

  /** Is a home/page section switched on? (site.sections.<name>) */
  section(name: string, fallback = true): boolean {
    const s = (this.site.sections ?? {}) as Json;
    return name in s ? Boolean(s[name]) : fallback;
  }

  checkout(): { cod: boolean; bkash: boolean; card: boolean; pickup: boolean; delivery: number; freeAbove: number } {
    const c = (this.site.checkout ?? {}) as Json;
    return {
      cod: Boolean(c.cod ?? true), bkash: Boolean(c.bkash ?? false), card: Boolean(c.card ?? false),
      pickup: Boolean(c.pickup ?? false), delivery: Number(c.delivery ?? 0) || 0, freeAbove: Number(c.freeAbove ?? 0) || 0,
    };
  }

  /** Published information pages (POS › Website › Pages); `footer` ones are linked in the footer. Empty on an API without pages. */
  infoPages(): { slug: string; title: string; kind: string; footer: boolean }[] {
    const p = this.site.pages;
    return Array.isArray(p) ? p.map((x: Json) => ({ slug: String(x.slug), title: String(x.title), kind: String(x.kind), footer: Boolean(x.footer) })) : [];
  }

  /** Product page settings (POS › Website › Storefront › Product page), as the API checked them. */
  productPage(): ProductPage {
    const p = (this.site.product_page ?? {}) as Json;
    return {
      style: p.style === 'detailed' ? 'detailed' : 'standard',
      showCode: p.showCode ?? true, showCategory: p.showCategory ?? true, showStock: p.showStock ?? true,
      orderLabel: String(p.orderLabel || 'Order now'), cartLabel: String(p.cartLabel || 'Add to cart'),
      callBox: Boolean(p.callBox), callText: String(p.callText ?? ''), callPhone: String(p.callPhone ?? ''),
      messenger: p.messenger ? String(p.messenger) : null, whatsapp: p.whatsapp ? String(p.whatsapp) : null,
      deliveryTable: Boolean(p.deliveryTable), deliveryTitle: String(p.deliveryTitle || 'Delivery Charge'),
      delivery: Array.isArray(p.delivery) ? p.delivery.map((r: Json) => ({ area: String(r.area ?? ''), charge: Number(r.charge ?? 0) })) : [],
      showNotes: Boolean(p.showNotes),
      notes: Array.isArray(p.notes) ? p.notes.map((n: Json) => ({ icon: String(n.icon ?? 'check'), title: String(n.title ?? ''), text: String(n.text ?? '') })) : [],
    };
  }

  /** Payment methods the site accepts, in display order. */
  paymentMethods(): string[] {
    const c = this.checkout();
    return (['cod', 'bkash', 'card'] as const).filter((m) => c[m]);
  }

  currency(): Currency {
    const c = (this.business.currency ?? {}) as Json;
    return {
      symbol: String(c.symbol ?? DEFAULT_CURRENCY.symbol),
      decimals: Number(c.decimals ?? 0) || 0,
      position: String(c.position ?? 'before'),
      code: String(c.code ?? 'BDT'),
    };
  }

  /** Format an amount in the shop's currency (whole amounts without decimals). */
  money(n: number | string | null | undefined): string {
    return sfMoney(n, this.currency());
  }

  typeTag(): string {
    return TYPE_TAG[this.type()] ?? String(this.business.type_name ?? 'Online store');
  }

  seoTitle(): string {
    const seo = (this.site.seo ?? {}) as Json;
    const suffix = this.kind() === 'booking' ? 'Book online' : this.kind() === 'menu' ? 'Order online' : 'Online store';
    return String(seo.title ?? '').trim() !== '' ? String(seo.title) : `${this.name()} · ${suffix}`;
  }

  seoDescription(): string {
    const seo = (this.site.seo ?? {}) as Json;
    return String(seo.description || this.brand().sub || '');
  }

  /** Public address of the shop (for canonical links), e.g. digitalzone.<siteHost>. */
  publicHost(): string {
    return normaliseHost(String(this.site.host ?? '')) ?? '';
  }

  address(): string {
    const b = this.business;
    return String(b.address || [b.area, b.city].filter(Boolean).join(', '));
  }

  phone(): string {
    return String(this.business.phone ?? '');
  }

  branch(): string {
    return String(this.business.area || 'our store');
  }

  location(): { lat: number; lng: number } | null {
    const b = this.business;
    const lat = Number(b.lat);
    const lng = Number(b.lng);
    return b.lat != null && b.lng != null && Number.isFinite(lat) && Number.isFinite(lng) && b.lat !== '' && b.lng !== '' ? { lat, lng } : null;
  }

  /** Categories that have at least one product. */
  liveCategories(): Json[] {
    return this.categories.filter((c) => Number(c.products ?? 1) > 0);
  }

  category(id: number): Json | null {
    return this.categories.find((c) => Number(c.id ?? 0) === id) ?? null;
  }

  categoryName(id: number): string {
    return String(this.category(id)?.name ?? '');
  }

  /** Staff who take bookings (site.staff), without the "Any" placeholder. */
  staff(): string[] {
    const out: string[] = [];
    for (const s of Array.isArray(this.site.staff) ? this.site.staff : []) {
      const n = String(s && typeof s === 'object' ? s.name ?? '' : s ?? '').trim();
      if (n !== '' && n.toLowerCase() !== 'any' && !out.includes(n)) out.push(n);
    }
    return out;
  }

  /** Whether the site is open for customers (mode storefront). */
  isOpen(): boolean {
    return (this.site.mode ?? 'storefront') === 'storefront';
  }

  /** Small serialisable summary for client components (class instances cannot cross the boundary). */
  toClient() {
    return {
      name: this.name(),
      kind: this.kind(),
      sells: this.sells(),
      canBook: this.canBook(),
      currency: this.currency(),
      checkout: this.checkout(),
      paymentMethods: this.paymentMethods(),
      location: this.location(),
      theme: this.theme(),
    };
  }
}

export type StoreClient = ReturnType<Store['toClient']>;
