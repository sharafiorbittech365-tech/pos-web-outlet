/**
 * Formatting + product helpers. Isomorphic (usable in server and client components).
 * Ports of app/Helpers/storefront_helper.php and the money/qty/date helpers of the shared UI package.
 */
export interface Currency {
  symbol: string;
  decimals: number;
  position: 'before' | 'after' | string;
  code: string;
}

export const DEFAULT_CURRENCY: Currency = { symbol: '৳', decimals: 0, position: 'before', code: 'BDT' };

/** 12,34,567 grouping (South Asian), like the PHP money() helper. */
export function money(amount: number | string | null | undefined, cur: Partial<Currency> = {}): string {
  const n = Number(amount ?? 0) || 0;
  const dec = Math.max(0, Math.trunc(cur.decimals ?? 2));
  const sym = cur.symbol ?? '৳';
  const neg = n < 0 ? '-' : '';
  const [int, frac = ''] = Math.abs(n).toFixed(dec).split('.');
  const last3 = int.slice(-3);
  const rest = int.slice(0, -3);
  const grouped = rest !== '' ? rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + last3 : last3;
  const num = grouped + (frac !== '' ? '.' + frac : '');
  return (cur.position ?? 'before') === 'after' ? `${neg}${num} ${sym}` : `${neg}${sym}${num}`;
}

/** Storefront money: whole amounts are shown without ".00". */
export function sfMoney(amount: number | string | null | undefined, cur: Partial<Currency> = DEFAULT_CURRENCY): string {
  const n = Number(amount ?? 0) || 0;
  const c = { ...DEFAULT_CURRENCY, ...cur };
  if (Math.abs(n - Math.round(n)) < 0.005) c.decimals = 0;
  return money(n, c);
}

export function qty(n: number | string | null | undefined, unit?: string | null): string {
  const v = (Number(n ?? 0) || 0).toFixed(3).replace(/0+$/, '').replace(/\.$/, '');
  return unit ? `${v} ${unit}` : v;
}

export function initials(name: string | null | undefined): string {
  const parts = (name ?? '').trim().split(/\s+/).filter(Boolean).slice(0, 2);
  if (parts.length === 0) return '?';
  return parts.map((p) => Array.from(p)[0] ?? '').join('').toUpperCase();
}

export function dur(m: number): string {
  return m >= 60 ? `${Math.floor(m / 60)} h${m % 60 ? ` ${m % 60} min` : ''}` : `${m} min`;
}

/** Only http(s) URLs and site-relative paths may be used as image / link sources. */
export function safeImgUrl(url: unknown): string {
  const u = String(url ?? '').trim();
  return /^(https?:\/\/|\/)[^\s"'<>]+$/i.test(u) ? u : '';
}

export function pOff(p: Record<string, any>): number {
  const price = Number(p.price ?? 0);
  const was = Number(p.compare_at ?? 0);
  return was > price && was > 0 ? Math.round((1 - price / was) * 100) : 0;
}

export function pImage(p: Record<string, any>): string {
  let img: unknown = p.image ?? null;
  if (img == null && Array.isArray(p.images)) img = p.images[0] ?? null;
  return safeImgUrl(img);
}

export function variantNames(p: Record<string, any>): string[] {
  return (Array.isArray(p.variants) ? p.variants : []).map((v: any) => (v && typeof v === 'object' ? String(v.name ?? '') : String(v)));
}

/** "About Our Shop" → ["About", "Our Shop"]: the last word(s) get the accent colour. */
export function accentSplit(text: string): [string, string] {
  const w = text.trim().split(/\s+/).filter(Boolean);
  if (w.length === 0) return ['', ''];
  const n = w.length > 4 ? 2 : 1;
  return [w.slice(0, -n).join(' '), w.slice(-n).join(' ')];
}

/** Cut a string to `width` characters with an ellipsis (PHP mb_strimwidth, simplified). */
export function strim(s: string, width: number, trim = '…'): string {
  const a = Array.from(s);
  return a.length <= width ? s : a.slice(0, Math.max(0, width - Array.from(trim).length)).join('') + trim;
}

/** Stable hue (0–359) from a name, for the placeholder colour of a product photo. */
export function nameHue(name: string): number {
  return (Array.from(name).length * 37) % 360;
}

// ---------------------------------------------------------------------------------------------
// PHP-style date formatting
// ---------------------------------------------------------------------------------------------
const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/** Parse what PHP's strtotime() accepts for the API's values: ISO 8601 and "YYYY-MM-DD HH:MM[:SS]". */
export function toDate(v: string | number | Date | null | undefined): Date | null {
  if (v == null || v === '') return null;
  if (v instanceof Date) return Number.isNaN(v.getTime()) ? null : v;
  if (typeof v === 'number') return new Date(v * 1000);
  const s = String(v).trim();
  const m = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}(?::\d{2})?)(.*)$/.exec(s);
  const d = new Date(m ? `${m[1]}T${m[2]}${m[3]}` : /^\d{4}-\d{2}-\d{2}$/.test(s) ? `${s}T00:00:00` : s);
  return Number.isNaN(d.getTime()) ? null : d;
}

const p2 = (n: number): string => String(n).padStart(2, '0');

/** PHP date() for the subset of format characters the storefront uses (local time of the server). */
export function phpDate(format: string, input: string | number | Date | null | undefined = new Date()): string {
  const d = toDate(input) ?? new Date(NaN);
  if (Number.isNaN(d.getTime())) return '';
  let out = '';
  for (let i = 0; i < format.length; i++) {
    const c = format[i];
    switch (c) {
      case '\\':
        out += format[++i] ?? '';
        break;
      case 'd': out += p2(d.getDate()); break;
      case 'j': out += d.getDate(); break;
      case 'D': out += DAYS[d.getDay()].slice(0, 3); break;
      case 'l': out += DAYS[d.getDay()]; break;
      case 'N': out += d.getDay() === 0 ? 7 : d.getDay(); break;
      case 'w': out += d.getDay(); break;
      case 'F': out += MONTHS[d.getMonth()]; break;
      case 'M': out += MONTHS[d.getMonth()].slice(0, 3); break;
      case 'm': out += p2(d.getMonth() + 1); break;
      case 'n': out += d.getMonth() + 1; break;
      case 'Y': out += d.getFullYear(); break;
      case 'y': out += String(d.getFullYear()).slice(-2); break;
      case 'a': out += d.getHours() < 12 ? 'am' : 'pm'; break;
      case 'A': out += d.getHours() < 12 ? 'AM' : 'PM'; break;
      case 'g': out += d.getHours() % 12 || 12; break;
      case 'G': out += d.getHours(); break;
      case 'h': out += p2(d.getHours() % 12 || 12); break;
      case 'H': out += p2(d.getHours()); break;
      case 'i': out += p2(d.getMinutes()); break;
      case 's': out += p2(d.getSeconds()); break;
      case 'U': out += Math.floor(d.getTime() / 1000); break;
      default: out += c;
    }
  }
  return out;
}

export const dateStr = (v: string | null | undefined): string => (v ? phpDate('d M Y', v) || '—' : '—');
export const datetimeStr = (v: string | null | undefined): string => (v ? phpDate('d M Y, h:i A', v) || '—' : '—');
export const timeStr = (v: string | null | undefined): string => (v ? phpDate('h:i A', v) || '—' : '—');

/** Epoch seconds like strtotime() (NaN-safe: returns null when the value cannot be parsed). */
export function strtotime(v: string | null | undefined): number | null {
  const d = toDate(v);
  return d ? Math.floor(d.getTime() / 1000) : null;
}

/** Today as YYYY-MM-DD in the server's local time. */
export const today = (offsetDays = 0): string => {
  const d = new Date();
  d.setDate(d.getDate() + offsetDays);
  return phpDate('Y-m-d', d);
};

/** Strict YYYY-MM-DD check (real calendar date). */
export function validDate(s: string): boolean {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
  if (!m) return false;
  const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
  return d.getFullYear() === Number(m[1]) && d.getMonth() === Number(m[2]) - 1 && d.getDate() === Number(m[3]);
}

/** Build "/path?a=1&b=2", skipping null / empty values (PHP sf_url). */
export function sfUrl(path = '/', query: Record<string, string | number | boolean | null | undefined> = {}): string {
  const qs = new URLSearchParams();
  for (const [k, v] of Object.entries(query)) {
    if (v !== null && v !== undefined && v !== '') qs.set(k, String(v));
  }
  const q = qs.toString();
  return '/' + path.replace(/^\/+/, '') + (q ? '?' + q : '');
}

/** Status → [label, kind] for the small status tag. */
export function statusTag(status: string | null | undefined): [string, string] {
  const map: Record<string, [string, string]> = {
    trial: ['Free trial', 'primary'], active: ['Active', 'ok'], past_due: ['Past due', 'warn'],
    suspended: ['Suspended', 'danger'], expired: ['Trial ended', 'danger'], cancelled: ['Cancelled', 'danger'],
    paid: ['Paid', 'ok'], partial: ['Partially paid', 'warn'], due: ['Due', 'danger'], open: ['Open', 'primary'],
    held: ['On hold', 'warn'], returned: ['Returned', ''], void: ['Void', ''],
    pending: ['New', 'warn'], confirmed: ['Confirmed', 'primary'], done: ['Done', 'ok'],
    draining: ['Draining', 'warn'], maintenance: ['Maintenance', 'warn'], offline: ['Offline', 'danger'],
  };
  const s = String(status ?? '');
  if (map[s]) return map[s];
  const label = s.replace(/_/g, ' ');
  return [label.charAt(0).toUpperCase() + label.slice(1), ''];
}
