/** Sort dropdown above a product listing; changing it submits the GET form (no JS needed beyond that). */
import { SORT_LABELS } from '@/lib/services/catalog';
import { AutoSubmitSelect } from './AutoSubmit';

export function SortBar({ total, sort, path, query = {} }: { total: number; sort: string; path: string; query?: Record<string, string | number | null | undefined> }) {
  return (
    <form className="sortbar" method="get" action={path}>
      <span>
        {total} item{total === 1 ? '' : 's'}
      </span>
      {Object.entries(query).map(([k, v]) => (v !== null && v !== undefined && v !== '' ? <input key={k} type="hidden" name={k} value={String(v)} /> : null))}
      <label>
        Sort
        <AutoSubmitSelect name="sort" defaultValue={sort}>
          {Object.entries(SORT_LABELS).map(([v, l]) => (
            <option key={v} value={v}>
              {l}
            </option>
          ))}
        </AutoSubmitSelect>
      </label>
      <noscript>
        <button className="btn-g sm">Apply</button>
      </noscript>
    </form>
  );
}
