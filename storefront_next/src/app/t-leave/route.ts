/** Table::leave — forget the table and open the shop. */
import { getContext } from '@/lib/context';
import { redirectTo } from '@/lib/route-redirect';
import { clearTable } from '@/lib/table-session';

export async function GET(request: Request) {
  const { host } = await getContext();
  await clearTable(host);
  return redirectTo(request, '/');
}
