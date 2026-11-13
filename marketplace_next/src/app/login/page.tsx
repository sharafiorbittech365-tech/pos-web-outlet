/** Sign in (AuthController::login). Signed-in buyers go straight to their orders. */
import Link from 'next/link';
import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { LoginForm } from '@/components/AuthForms';
import { Ic } from '@/components/Ic';
import { ShieldFieldsBlock } from '@/components/ShieldFields';
import { getBuyer } from '@/lib/page';
import { afterLogin, relative } from '@/lib/visitor';

export const metadata: Metadata = { title: 'Sign in' };

export default async function Login({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  const n = (await searchParams).next;
  const next = relative(Array.isArray(n) ? n[0] : n);
  if ((await getBuyer()) !== null) redirect(afterLogin(next));
  return (
    <div className="wrap page">
      <div className="panel narrow">
        <h2>
          <Ic name="user" />
          Sign in
        </h2>
        {next !== '/' && next.startsWith('/account') ? <p className="note warn">Please sign in to continue.</p> : <p className="muted">See your orders and bookings from every shop in one place.</p>}
        <LoginForm next={next} shield={<ShieldFieldsBlock />} />
        <p className="note">
          New here? <Link href={'/signup' + (next !== '/' ? '?next=' + encodeURIComponent(next) : '')}>Create an account</Link>
        </p>
      </div>
    </div>
  );
}
