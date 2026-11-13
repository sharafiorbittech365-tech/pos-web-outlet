/**
 * Catalogue queries for the shop pages: listing pages (sorted + paginated), the home page sections,
 * menus grouped by category. Port of Modules/Shop/Services/CatalogService.php.
 */
import { type Json, type Page, type Product, type StoreApi } from '../api/storefront-api';
import { pOff } from '../format';
import { type Store } from '../store';

export const SORTS: Record<string, string | null> = { featured: null, low: 'price', high: '-price', name: 'name', newest: 'newest' };
export const SORT_LABELS: Record<string, string> = { featured: 'Featured', low: 'Price: low to high', high: 'Price: high to low', name: 'Name', newest: 'Newest' };
export const TABS: Record<string, string> = { featured: 'Featured', new: 'New arrivals', offers: 'On offer', value: 'Best value' };

export function parseSort(s: string | null | undefined): string {
  return s != null && s in SORTS ? s : 'featured';
}

/** Page number from a query string value, clamped to 1…1000. */
export function pageNo(v: string | string[] | undefined): number {
  const n = Math.trunc(Number(Array.isArray(v) ? v[0] : v));
  return Math.max(1, Math.min(1000, Number.isFinite(n) ? n : 1));
}

/** Featured first, then in-stock first, keeping the API order otherwise. */
export function featuredFirst<T extends Json>(list: T[]): T[] {
  return list
    .map((p, i) => ({ p, k: [p.featured ? 0 : 1, p.in_stock ? 0 : 1, i] as const }))
    .sort((a, b) => a.k[0] - b.k[0] || a.k[1] - b.k[1] || a.k[2] - b.k[2])
    .map((x) => x.p);
}

export function uniqueById<T extends Json>(list: T[]): T[] {
  const seen = new Set<number>();
  const out: T[] = [];
  for (const p of list) {
    const id = Number(p.id);
    if (!seen.has(id)) {
      seen.add(id);
      out.push(p);
    }
  }
  return out;
}

export function countByCategory(all: Product[]): Record<number, number> {
  const out: Record<number, number> = {};
  for (const p of all) {
    const c = Number(p.category_id ?? 0);
    out[c] = (out[c] ?? 0) + 1;
  }
  return out;
}

/** Featured in-stock products, topped up with the priciest badged ones when few are featured. */
export function pickFeatured(all: Product[], n = 8): Product[] {
  const inStock = all.filter((p) => p.in_stock);
  let f = inStock.filter((p) => p.featured);
  if (f.length < 4) {
    const rest = inStock.filter((p) => !p.featured).sort((a, b) => (a.badge ? 0 : 1) - (b.badge ? 0 : 1) || Number(b.price) - Number(a.price));
    f = [...f, ...rest];
  }
  return f.slice(0, n);
}

export interface HomeSections {
  tab: string;
  tabList: Product[];
  deals: Product[];
  trending: Product[];
  maxOff: number;
  counts: Record<number, number>;
}

export class CatalogService {
  constructor(private readonly api: StoreApi, private readonly store: Store, private readonly perPage = 24) {}

  async page(filter: { q?: string; category?: number }, sort: string, page: number): Promise<Page> {
    const r = await this.api.products({
      q: filter.q ? filter.q.trim().slice(0, 100) : null,
      category: filter.category ?? null,
      sort: SORTS[sort] ?? null,
      page: Math.max(1, page),
      per_page: this.perPage,
    });
    if (sort === 'featured') r.data = featuredFirst(r.data);
    return r;
  }

  all(): Promise<Product[]> {
    return this.api.allProducts(300);
  }

  /** One product plus up to four others from its category. */
  async product(id: number): Promise<{ product: Product; related: Product[] }> {
    const p = await this.api.product(id);
    let related: Product[] = [];
    if (p.category_id) {
      const r = await this.api.products({ category: Number(p.category_id), per_page: 5 });
      related = r.data.filter((x) => Number(x.id) !== id).slice(0, 4);
    }
    return { product: p, related };
  }

  /** Sections of the shop home: tabbed list, deals, trending, counts per category. */
  homeSections(all: Product[], tab: string): HomeSections {
    const inStock = all.filter((p) => p.in_stock);
    const offers = inStock.filter((p) => pOff(p) > 0).sort((a, b) => pOff(b) - pOff(a));
    const badged = inStock.filter((p) => p.badge);
    const deals = uniqueById([...offers, ...badged, ...featuredFirst(inStock)]);
    const maxOff = Math.max(0, ...all.map((p) => pOff(p)));
    const trending = uniqueById([...badged, ...inStock.filter((p) => p.featured), ...inStock]).slice(0, 5);
    return { tab, tabList: this.tabList(all, tab).slice(0, 10), deals: deals.slice(0, 6), trending, maxOff, counts: countByCategory(all) };
  }

  private tabList(all: Product[], tab: string): Product[] {
    let list = [...all];
    switch (tab) {
      case 'new':
        list.sort((a, b) => Number(b.id) - Number(a.id));
        break;
      case 'offers':
        list = list.filter((p) => pOff(p) > 0).sort((a, b) => pOff(b) - pOff(a));
        break;
      case 'value':
        list = list.filter((p) => p.in_stock).sort((a, b) => Number(a.price) - Number(b.price));
        break;
      default:
        list = featuredFirst(list);
    }
    return list;
  }

  /** Products grouped by (live) category — menus and service price lists. */
  byCategory(all: Product[]): { category: Json; items: Product[] }[] {
    const groups: { category: Json; items: Product[] }[] = [];
    for (const c of this.store.liveCategories()) {
      const items = all.filter((p) => Number(p.category_id ?? 0) === Number(c.id));
      if (items.length > 0) groups.push({ category: c, items });
    }
    return groups;
  }
}
