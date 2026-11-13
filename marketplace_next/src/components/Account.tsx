/** Account header with the tabs (Buyers/Views/_tabs.php) and the guard for buyer-only pages (BuyerAuth filter). */
import 'server-only';
import Link from 'next/link';
import { redirect } from 'next/navigation';
import { logoutAction } from '@/app/actions/auth';
import { getBuyer } from '@/lib/page';
import { Ic } from './Ic';

/** The signed-in buyer, or a redirect to the sign-in page that comes back here afterwards. */
export async function requireBuyer(path: string): Promise<Record<string, any>> {
  const buyer = await getBuyer();
  if (buyer === null) redirect('/login?next=' + encodeURIComponent(path));
  return buyer;
}

export function AccountTabs({ buyer, on }: { buyer: Record<string, any>; on: 'orders' | 'bookings' }) {
  return (
    <>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
        <div>
          <h1 className="page-h">Hello, {String(buyer.name)}</h1>
          <p className="muted" style={{ margin: 0 }}>
            {String(buyer.phone)}
          </p>
        </div>
        <form action={logoutAction}>
          <button className="btn sm" type="submit">
            <Ic name="logout" />
            Sign out
          </button>
        </form>
      </div>
      <nav className="tabs" style={{ marginTop: 14 }}>
        <Link className={on === 'orders' ? 'on' : ''} href="/account/orders">
          <Ic name="receipt" />
          My orders
        </Link>
        <Link className={on === 'bookings' ? 'on' : ''} href="/account/bookings">
          <Ic name="calendar" />
          My bookings
        </Link>
      </nav>
    </>
  );
}
