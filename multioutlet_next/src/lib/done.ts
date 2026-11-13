/**
 * Remembers the orders this visitor just placed so the confirmation page can show the items and the
 * payment choice (the PHP app kept the last one in the session). Last few only, to stay well below the
 * cookie size limit; older ones fall back to the API's tracking data.
 */
import 'server-only';
import { readJson, writeJson } from './cookies';

type Json = Record<string, any>;
const ORDERS = 'ms_orders';
const KEEP = 3;
const TTL = 7 * 86400;

export async function rememberOrder(ref: string, order: Json): Promise<void> {
  const all = (await readJson<Record<string, Json>>(ORDERS)) ?? {};
  delete all[ref];
  all[ref] = order;
  const keys = Object.keys(all).slice(-KEEP);
  await writeJson(ORDERS, Object.fromEntries(keys.map((k) => [k, all[k]])), TTL);
}

export async function recallOrder(ref: string): Promise<Json | null> {
  const v = (await readJson<Record<string, Json>>(ORDERS))?.[ref];
  return v && typeof v === 'object' ? v : null;
}
