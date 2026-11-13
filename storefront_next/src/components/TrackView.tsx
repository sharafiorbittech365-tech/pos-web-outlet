/** The track page body (Views/track.php), shared by /track and /track/[ref]. */
import 'server-only';
import { type Json } from '@/lib/api/storefront-api';
import { datetimeStr, qty as fmtQty, statusTag } from '@/lib/format';
import { type Store } from '@/lib/store';
import { step as trackStep, statusText, type TrackResult } from '@/lib/services/track';
import { Icon } from './Icon';

export function TrackView({ store, refCode, result }: { store: Store; refCode: string | null; result: TrackResult | null }) {
  const what = store.canBook() && !store.sells() ? 'booking' : 'order';
  const phone = store.phone();
  return (
    <section className="wrap sec narrow">
      <h1>Track your {what}</h1>
      <form className="track" method="get" action="/track">
        <input name="ref" defaultValue={refCode ?? ''} key={refCode ?? ''} placeholder="Reference, e.g. W8K2QX" required maxLength={40} aria-label="Reference" />
        <button className="btn-p">Track</button>
      </form>
      {refCode !== null &&
        (result === null ? (
          <div className="empty-s">
            Nothing found with reference <b>{refCode}</b>. Please check it and try again, or call {phone !== '' ? <a href={`tel:${phone}`}>{phone}</a> : 'the shop'}.
          </div>
        ) : (
          <Result result={result} refCode={refCode} store={store} />
        ))}
    </section>
  );
}

function Result({ result, refCode, store }: { result: TrackResult; refCode: string; store: Store }) {
  const d: Json = result.data;
  const isBooking = result.type === 'booking';
  const step = trackStep(d);
  const [tagLabel, tagKind] = statusTag(String(d.status ?? ''));
  const items: Json[] = Array.isArray(d.items) ? d.items : [];
  return (
    <div className="ok-card">
      <p>
        Reference <b>{refCode}</b>
        {d.created_at ? ` · placed ${datetimeStr(String(d.created_at))}` : ''}
        {isBooking && d.starts_at ? ` · ${datetimeStr(String(d.starts_at))}` : ''}
      </p>
      {step >= 0 && (
        <div className="steps">
          {['Received', 'Confirmed', isBooking ? 'Completed' : 'Delivered'].map((l, i) => (
            <div className={i <= step ? 'done' : ''} key={i}>
              <i>{i <= step ? <Icon name="check" /> : i + 1}</i>
              <span>{l}</span>
            </div>
          ))}
        </div>
      )}
      <p>{statusText(d, result.type)}</p>
      {!isBooking && items.length > 0 && (
        <div className="co-sum flat">
          {items.map((it, i) => (
            <div className="r it" key={i}>
              <span>
                {String(it.name ?? '')} × {fmtQty(it.qty ?? 1)}
              </span>
              <b>{store.money(it.line_total ?? 0)}</b>
            </div>
          ))}
          <div className="r tot">
            <span>Total</span>
            <b>{store.money(d.total ?? 0)}</b>
          </div>
          <p className="muted">
            Payment: <span className={('tag ' + tagKind).trim()}>{tagLabel}</span>
          </p>
        </div>
      )}
    </div>
  );
}
