/** Table::open — a table's QR opens /t/<code>: remember the table for this guest, then open the shop. */
import { isApiError } from '@/lib/api/errors';
import { getContext } from '@/lib/context';
import { setFlash } from '@/lib/flash';
import { redirectTo } from '@/lib/route-redirect';
import { clearTable, setTable } from '@/lib/table-session';

export async function GET(request: Request, { params }: { params: Promise<{ code: string }> }) {
  const { api, host } = await getContext();
  let { code } = await params;
  try {
    code = decodeURIComponent(code);
  } catch {
    /* keep raw */
  }
  let table;
  try {
    table = await api.table(code);
  } catch (e) {
    if (isApiError(e) && (e.status === 404 || e.status === 422)) {
      await clearTable(host);
      await setFlash({ error: 'This table code is not valid any more — please ask the staff for help, or order from the menu.' });
      return redirectTo(request, '/');
    }
    throw e;
  }
  await setTable(host, code, { name: String(table.name ?? ''), area: table.area ?? null });
  await setFlash({ ok: `Welcome! You are ordering at ${String(table.name ?? '')}.` });
  return redirectTo(request, '/');
}
