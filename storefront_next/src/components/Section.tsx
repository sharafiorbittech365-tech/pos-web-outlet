/** Section heading (sh2) and "View all" link helpers. */
import Link from 'next/link';
import type { ReactNode } from 'react';
import { Icon } from './Icon';

/** <div class="sh2"> — icon + title + eyebrow, optional trailing node (e.g. a "View all" link). */
export function Sh2({ eyebrow, title, icon = 'leaf', more }: { eyebrow: string; title: ReactNode; icon?: string; more?: ReactNode }) {
  return (
    <div className="sh2">
      <span className="sh2-ic">
        <Icon name={icon} />
      </span>
      <div className="sh2-t">
        <h2>{title}</h2>
        <small>{eyebrow}</small>
      </div>
      {more}
    </div>
  );
}

/** `Our <span class="acc">Doctors</span>` — accent the last word(s). */
export function Accent({ lead, tail, cls = 'acc' }: { lead: string; tail: string; cls?: string }) {
  return (
    <>
      {lead}
      {lead !== '' ? ' ' : ''}
      <span className={cls}>{tail}</span>
    </>
  );
}

export function ViewAll({ href, label = 'View all' }: { href: string; label?: string }) {
  return (
    <Link className="sh2-more" href={href}>
      {label} <Icon name="arrow" />
    </Link>
  );
}
