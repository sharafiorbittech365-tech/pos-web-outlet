'use client';
/** Sign-in and sign-up forms (ports of Buyers/Views/login.php and signup.php). */
import { useActionState, useEffect, type ReactNode } from 'react';
import { loginAction, signupAction } from '@/app/actions/auth';
import { refreshHumanCheck } from './HumanCheck';
import { Ic } from './Ic';
import { emptyForm, type FormState } from '@/lib/form-errors';

function useForm(action: (s: FormState, fd: FormData) => Promise<FormState>) {
  const [state, run, pending] = useActionState(action, emptyForm);
  useEffect(() => {
    if (state.n > 0) refreshHumanCheck();
  }, [state.n]);
  const v = (k: string): string => state.values[k] ?? '';
  const err = (k: string): ReactNode => (state.errors[k] ? <small className="f-err">{state.errors[k]}</small> : null);
  const banner = state.error ? (
    <div className="flash err" role="alert">
      <Ic name="alert" />
      <span>{state.error}</span>
    </div>
  ) : null;
  return { state, run, pending, v, err, banner };
}

export function LoginForm({ next, shield }: { next: string; shield: ReactNode }) {
  const { state, run, pending, v, err, banner } = useForm(loginAction);
  return (
    <form action={run} key={state.n}>
      {banner}
      <input type="hidden" name="next" value={next} />
      <label className="fld">
        <span>Mobile number</span>
        <input className="in" name="phone" required maxLength={20} inputMode="tel" autoComplete="username" placeholder="01XXXXXXXXX" defaultValue={v('phone')} />
        {err('phone')}
      </label>
      <label className="fld">
        <span>Password</span>
        <input className="in" type="password" name="password" required maxLength={128} autoComplete="current-password" />
        {err('password')}
      </label>
      <div className="span2">{shield}</div>
      {err('shield')}
      <button className="btn primary full" type="submit" disabled={pending}>
        <Ic name="lock" />
        Sign in
      </button>
    </form>
  );
}

export function SignupForm({ next, shield }: { next: string; shield: ReactNode }) {
  const { state, run, pending, v, err, banner } = useForm(signupAction);
  return (
    <form action={run} key={state.n}>
      {banner}
      <input type="hidden" name="next" value={next} />
      <label className="fld">
        <span>Your name</span>
        <input className="in" name="name" required minLength={2} maxLength={120} autoComplete="name" defaultValue={v('name')} />
        {err('name')}
      </label>
      <label className="fld">
        <span>Mobile number</span>
        <input className="in" name="phone" required maxLength={20} inputMode="tel" autoComplete="tel" placeholder="01XXXXXXXXX" defaultValue={v('phone')} />
        {err('phone')}
      </label>
      <label className="fld">
        <span>Email (optional)</span>
        <input className="in" type="email" name="email" maxLength={160} autoComplete="email" defaultValue={v('email')} />
        {err('email')}
      </label>
      <label className="fld">
        <span>Password (at least 8 characters)</span>
        <input className="in" type="password" name="password" required minLength={8} maxLength={128} autoComplete="new-password" />
        {err('password')}
      </label>
      <label className="fld">
        <span>Repeat password</span>
        <input className="in" type="password" name="password_confirm" required minLength={8} maxLength={128} autoComplete="new-password" />
        {err('password_confirm')}
      </label>
      <div className="span2">{shield}</div>
      {err('shield')}
      <button className="btn primary full" type="submit" disabled={pending}>
        <Ic name="check" />
        Create account
      </button>
    </form>
  );
}
