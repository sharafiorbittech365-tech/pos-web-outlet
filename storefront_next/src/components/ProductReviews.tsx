'use client';
/** Reviews of a product: summary, the approved reviews with pictures, and the form to write one. */
import { useActionState, useEffect, useState, type ReactNode } from 'react';
import { submitReview } from '@/app/actions/reviews';
import { refreshHumanCheck } from '@/components/HumanCheck';
import { emptyForm } from '@/lib/form-errors';

export interface ReviewItem {
  id: number;
  author: string;
  rating: number;
  title: string | null;
  body: string | null;
  images: string[];
  verified_purchase: boolean;
  created_at: string;
}

const stars = (n: number) => '★'.repeat(n) + '☆'.repeat(5 - n);
const FieldErr = ({ e }: { e?: string }) => (e ? <small className="f-err">{e}</small> : null);

export function ProductReviews({ productId, reviews, average, count, shield }: { productId: number; reviews: ReviewItem[]; average: number; count: number; shield: ReactNode }) {
  const [state, action, pending] = useActionState(submitReview, emptyForm);
  const [rating, setRating] = useState(5);
  const sent = state.values.ok === '1';
  useEffect(() => {
    if (state.n > 0) refreshHumanCheck();
  }, [state.n]);

  return (
    <section className="px-sec" id="reviews">
      <h2>Customer reviews</h2>
      {count > 0 ? (
        <p className="px-sum">
          <b>{average.toFixed(1)}</b> <span className="px-stars">{stars(Math.round(average))}</span> · {count} review{count === 1 ? '' : 's'}
        </p>
      ) : (
        <p className="muted">No reviews yet — be the first to write one.</p>
      )}
      <div className="px-revs">
        {reviews.map((r) => (
          <article key={r.id} className="px-rev">
            <div className="px-rev-h">
              <b>{r.author}</b> <span className="px-stars">{stars(r.rating)}</span>
              {r.verified_purchase && <em className="px-ok">Verified purchase</em>}
              <small className="muted">{r.created_at.slice(0, 10)}</small>
            </div>
            {r.title && <b className="px-rev-t">{r.title}</b>}
            {r.body && <p>{r.body}</p>}
            {r.images.length > 0 && (
              <div className="px-rev-img">
                {r.images.map((u, i) => (
                  <a key={i} href={u} target="_blank" rel="noopener noreferrer">
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img src={u} alt="" loading="lazy" />
                  </a>
                ))}
              </div>
            )}
          </article>
        ))}
      </div>

      {sent ? (
        <p className="px-thanks">Thank you! Your review was received and will appear after the shop approves it.</p>
      ) : (
        <form action={action} className="px-form" encType="multipart/form-data">
          <h3>Write a review</h3>
          <input type="hidden" name="product_id" value={productId} />
          {state.error && <p className="f-err">{state.error}</p>}
          <div className="px-rate" role="radiogroup" aria-label="Rating">
            {[1, 2, 3, 4, 5].map((n) => (
              <label key={n} className={n <= rating ? 'on' : ''}>
                <input type="radio" name="rating" value={n} checked={rating === n} onChange={() => setRating(n)} />★
              </label>
            ))}
          </div>
          <FieldErr e={state.errors.rating} />
          <label className="field">
            Your name
            <input name="author" maxLength={80} defaultValue={state.values.author ?? ''} required />
            <FieldErr e={state.errors.author} />
          </label>
          <label className="field">
            Title
            <input name="title" maxLength={160} defaultValue={state.values.title ?? ''} />
          </label>
          <label className="field">
            Your review
            <textarea name="body" rows={4} maxLength={3000} defaultValue={state.values.body ?? ''} />
            <FieldErr e={state.errors.body} />
          </label>
          <label className="field">
            Pictures (up to 3)
            <input type="file" name="images" accept="image/png,image/jpeg,image/webp,image/gif" multiple />
            <FieldErr e={state.errors.images} />
          </label>
          {shield}
          <button className="btn-p" type="submit" disabled={pending}>
            {pending ? 'Sending…' : 'Submit review'}
          </button>
        </form>
      )}
    </section>
  );
}
