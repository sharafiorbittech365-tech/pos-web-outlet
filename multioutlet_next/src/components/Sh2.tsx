import Link from 'next/link';
import { Icon } from './Icon';

/** Section heading of the design: icon tile + title (+ subtitle and an optional "View all"). */
export function Sh2({ eyebrow, title, acc, icon, more }: { eyebrow: string; title: string; acc: string; icon: string; more?: { href: string; label: string } }) {
  return (
    <div className="sh2">
      <span className="sh2-ic">
        <Icon name={icon} />
      </span>
      <div className="sh2-t">
        <h2>
          {title} <span className="acc">{acc}</span>
        </h2>
        <small>{eyebrow}</small>
      </div>
      {more && (
        <Link className="sh2-more" href={more.href}>
          {more.label} <Icon name="arrow" />
        </Link>
      )}
    </div>
  );
}
