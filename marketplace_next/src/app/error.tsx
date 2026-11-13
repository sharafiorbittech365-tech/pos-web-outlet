'use client';
import { useEffect } from 'react';

/** Last-resort boundary for unexpected errors (API down mid-page, bugs). Nothing technical is shown. */
export default function Error({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  useEffect(() => {
    console.error(error);
  }, [error]);
  return (
    <div className="wrap page">
      <div className="empty">
        <b>We will be right back</b>
        <span>Something went wrong while loading this page. Please try again in a moment.</span>
        <div className="row2">
          <button className="btn primary" onClick={() => reset()}>
            Try again
          </button>
          <a className="btn" href="/">
            Home
          </a>
        </div>
        {error.digest && <small className="muted">Reference {error.digest}</small>}
      </div>
    </div>
  );
}
