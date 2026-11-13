import { redirect } from 'next/navigation';

/** /account → My orders (AccountController::index). */
export default function Account() {
  redirect('/account/orders');
}
