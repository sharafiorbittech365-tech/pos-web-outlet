/** Pagination from the API meta {total, page, per_page, last_page, from, to}. */
import Link from 'next/link';
import { sfUrl } from '@/lib/format';
import { Icon } from './Icon';

export function Pager({ meta, path, query = {} }: { meta: Record<string, any>; path: string; query?: Record<string, string | number | null | undefined> }) {
  const page = Math.max(1, Math.trunc(Number(meta.page ?? 1)));
  const last = Math.max(1, Math.trunc(Number(meta.last_page ?? 1)));
  if (last <= 1) return null;
  const total = Math.trunc(Number(meta.total ?? 0));
  const per = Math.max(1, Math.trunc(Number(meta.per_page ?? 24)));
  const from = Math.trunc(Number(meta.from ?? (page - 1) * per + 1));
  const to = Math.trunc(Number(meta.to ?? Math.min(total, page * per)));
  const nums = [...new Set([1, last, page, page - 1, page + 1, page - 2, page + 2].filter((x) => x >= 1 && x <= last))].sort((a, b) => a - b);
  const link = (n: number): string => sfUrl(path, { ...query, page: n > 1 ? n : null });

  return (
    <nav className="pager" aria-label="Pages">
      <span className="pg-info">
        Showing {from}–{to} of {total}
      </span>
      <div className="pg-btns">
        {page > 1 ? (
          <Link className="pg-a" href={link(page - 1)} rel="prev" aria-label="Previous page">
            <Icon name="chevl" />
          </Link>
        ) : (
          <button disabled aria-label="Previous page">
            <Icon name="chevl" />
          </button>
        )}
        {nums.map((n, i) => (
          <span key={n} style={{ display: 'contents' }}>
            {i > 0 && n - nums[i - 1] > 1 && <span className="pg-gap">…</span>}
            <Link className={`pg-a ${n === page ? 'on' : ''}`.trim()} href={link(n)} aria-current={n === page ? 'page' : undefined}>
              {n}
            </Link>
          </span>
        ))}
        {page < last ? (
          <Link className="pg-a" href={link(page + 1)} rel="next" aria-label="Next page">
            <Icon name="chev" />
          </Link>
        ) : (
          <button disabled aria-label="Next page">
            <Icon name="chev" />
          </button>
        )}
      </div>
    </nav>
  );
}
