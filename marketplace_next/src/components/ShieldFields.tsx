/** Server component: renders the human-check fields with fresh tokens. Put it inside a <form>. */
import 'server-only';
import { shieldFields } from '@/lib/shield';
import { HumanCheck } from './HumanCheck';

export function ShieldFieldsBlock({ label }: { label?: string }) {
  return <HumanCheck initial={shieldFields()} label={label} />;
}
