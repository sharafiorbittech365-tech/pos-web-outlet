/** Create a buyer account (AuthController::signup). */
import Link from 'next/link';
import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { SignupForm } from '@/components/AuthForms';
import { Ic } from '@/components/Ic';
import { ShieldFieldsBlock } from '@/components/ShieldFields';
import { config } from '@/lib/config';
import { getBuyer } from '@/lib/page';
import { afterLogin, relative } from '@/lib/visitor';

export const metadata: Metadata = { title: 'Create your account' };

export default async function Signup({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  const n = (await searchParams).next;
  const next = relative(Array.isArray(n) ? n[0] : n);
  if ((await getBuyer()) !== null) redirect(afterLogin(next));
  return (
    <div className="wrap page">
      <div className="panel narrow">
        <h2>
          <Ic name="userplus" />
          Create your account
        </h2>
        <p className="muted">One account for every shop on {config.brandName} Market.</p>
        <SignupForm next={next} shield={<ShieldFieldsBlock />} />
        <p className="note">
          Already registered? <Link href="/login">Sign in</Link>
        </p>
      </div>
    </div>
  );
}
