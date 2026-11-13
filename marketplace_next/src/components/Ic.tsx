/** Icons of the shared UI package (icon() → <svg class="ic …">, sym_icon(), status_tag()). */
import { ICONS } from '@/lib/icons-data';
import { statusTag } from '@/lib/format';
import { ICON_IMG } from '@/lib/market';

export function Ic({ name, className = '' }: { name: string; className?: string }) {
  return <svg className={('ic ' + className).trim()} viewBox="0 0 24 24" aria-hidden="true" dangerouslySetInnerHTML={{ __html: ICONS[name] ?? '' }} />;
}

/** Icon for a stored icon key (business type, logo); the favicon when the key is unknown. */
export function SymIcon({ name, className = '' }: { name?: string | null; className?: string }) {
  if (name && ICONS[name]) return <Ic name={name} className={('sym ' + className).trim()} />;
  // eslint-disable-next-line @next/next/no-img-element
  return <img className={('fav-ic ' + className).trim()} src={ICON_IMG} alt="" aria-hidden="true" />;
}

export function Tag({ text, kind = '' }: { text: string; kind?: string }) {
  return <span className={('tag ' + kind).trim()}>{text}</span>;
}

export function StatusTag({ status }: { status: string | null | undefined }) {
  const [label, kind] = statusTag(status);
  return <Tag text={label} kind={kind} />;
}
