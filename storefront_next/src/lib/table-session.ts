/**
 * Table QR ordering: after a guest scans a table's QR (<shop>/t/<code>) the table is remembered for
 * this shop only. While it is set the menu shows "You are ordering at …" and checkout becomes a dine-in
 * order on that table. The API checks the code again on every order — the cookie is never trusted.
 * Port of app/Libraries/TableSession.php.
 */
import 'server-only';
import { deleteCookie, readJson, sha, writeJson } from './cookies';

const TTL = 6 * 3600;

export interface TableInfo {
  code: string;
  name: string;
  area: string | null;
}

const key = (host: string): string => 'sf_table_' + sha(host);

export async function getTable(host: string): Promise<TableInfo | null> {
  const t = await readJson<{ code?: string; name?: string; area?: string | null; at?: number }>(key(host));
  if (!t || typeof t.code !== 'string' || (t.at ?? 0) < Math.floor(Date.now() / 1000) - TTL) return null;
  return { code: t.code, name: String(t.name ?? ''), area: t.area ?? null };
}

export async function setTable(host: string, code: string, table: { name: string; area?: string | null }): Promise<void> {
  await writeJson(key(host), { code, name: String(table.name), area: table.area ?? null, at: Math.floor(Date.now() / 1000) }, TTL);
}

export async function clearTable(host: string): Promise<void> {
  await deleteCookie(key(host));
}
