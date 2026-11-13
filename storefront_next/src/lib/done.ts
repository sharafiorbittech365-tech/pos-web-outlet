/**
 * Remembers the orders / bookings this visitor just placed so the confirmation page can show them
 * (the PHP app kept these in the session). Last few only, to stay well below the cookie size limit.
 */
import 'server-only';
import { readJson, writeJson } from './cookies';

type Json = Record<string, any>;
const ORDERS = 'sf_orders';
const BOOKINGS = 'sf_bookings';
const KEEP = 4;
const TTL = 7 * 86400;

async function remember(name: string, ref: string, value: Json): Promise<void> {
  const all = (await readJson<Record<string, Json>>(name)) ?? {};
  delete all[ref];
  all[ref] = value;
  const keys = Object.keys(all).slice(-KEEP);
  await writeJson(name, Object.fromEntries(keys.map((k) => [k, all[k]])), TTL);
}

async function recall(name: string, ref: string): Promise<Json | null> {
  const all = await readJson<Record<string, Json>>(name);
  const v = all?.[ref];
  return v && typeof v === 'object' ? v : null;
}

export const rememberOrder = (ref: string, order: Json) => remember(ORDERS, ref, order);
export const recallOrder = (ref: string) => recall(ORDERS, ref);
export const rememberBooking = (ref: string, booking: Json) => remember(BOOKINGS, ref, booking);
export const recallBooking = (ref: string) => recall(BOOKINGS, ref);
export const allBookings = async (): Promise<Record<string, Json>> => (await readJson<Record<string, Json>>(BOOKINGS)) ?? {};
