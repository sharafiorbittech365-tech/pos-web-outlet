'use client';
import { useEffect } from 'react';

/** Last-resort boundary for unexpected errors (API down mid-page, bugs). Nothing technical is shown. */
export default function Error({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  useEffect(() => {
    console.error(error);
  }, [error]);
  return (
    <section className="dir">
      <div className="wrap narrow">
        <div className="ok-card">
          <h1>We will be right back</h1>
          <p>Something went wrong while loading this page. Please try again in a moment.</p>
          <div className="hero-cta" style={{ justifyContent: 'center' }}>
            <button className="btn-p" onClick={() => reset()}>
              Try again
            </button>
            <a className="btn-g" href="/">
              Home
            </a>
          </div>
          {error.digest && (
            <p className="muted">
              <small>Reference {error.digest}</small>
            </p>
          )}
        </div>
      </div>
    </section>
  );
}
