/** Order / booking tracking. Port of Modules/Track/Services/TrackService.php. */
import { isApiError } from '../api/errors';
import { type Json, type StoreApi } from '../api/storefront-api';

export interface TrackResult {
  type: 'order' | 'booking';
  data: Json;
}

/** A tidy reference ("w8k-2qx" → "W8K-2QX") or null when it cannot be one. */
export function normaliseRef(ref: string): string | null {
  const r = String(ref ?? '').replace(/[^A-Za-z0-9-]/g, '').toUpperCase();
  return r.length >= 3 && r.length <= 32 ? r : null;
}

/** The API knows orders and bookings by reference; a booking just made here is a fallback. */
export async function findTracked(api: StoreApi, ref: string, bookings: Record<string, Json> = {}): Promise<TrackResult | null> {
  try {
    const d = await api.order(ref);
    return { type: d.type === 'booking' ? 'booking' : 'order', data: d };
  } catch (e) {
    if (!isApiError(e) || e.status !== 404) throw e;
  }
  const b = bookings[ref];
  return b && typeof b === 'object' ? { type: 'booking', data: b } : null;
}

/** -1 cancelled · 0 received · 1 confirmed · 2 completed */
export function step(o: Json): number {
  const status = String(o.status ?? '').toLowerCase();
  const ship = String(o.ship_status ?? '').toLowerCase();
  if (['void', 'cancelled', 'canceled', 'returned'].includes(status) || ['cancelled', 'canceled', 'returned'].includes(ship)) return -1;
  if (['delivered', 'picked_up', 'collected', 'completed', 'done'].includes(ship) || status === 'done') return 2;
  if (['confirmed', 'processing', 'packed', 'ready', 'shipped', 'dispatched', 'out_for_delivery', 'in_transit'].includes(ship) || status === 'confirmed') return 1;
  return 0;
}

export function statusText(o: Json, type: 'order' | 'booking' = 'order'): string {
  const s = step(o);
  const ship = String(o.ship_status ?? '');
  if (s === -1) return type === 'booking' ? 'This booking was cancelled.' : 'This order was cancelled.';
  if (s === 2) return type === 'booking' ? 'Completed — thank you!' : 'Delivered — thank you for shopping with us!';
  if (s === 1) return type === 'booking' ? 'Confirmed — see you soon.' : ship !== '' ? `Your order is ${ship.replace(/_/g, ' ')}.` : 'Confirmed by the shop.';
  return type === 'booking' ? 'Request received — the shop will confirm it shortly.' : 'Order received — the shop will confirm it shortly.';
}
