'use client';
/** Deal countdown to local midnight (countdown() in storefront-app.js). */
import { useEffect, useState } from 'react';

function left(): [number, string][] {
  const now = new Date();
  const end = new Date(now);
  end.setHours(24, 0, 0, 0);
  let s = Math.max(0, Math.floor((end.getTime() - now.getTime()) / 1000));
  const h = Math.floor(s / 3600);
  s %= 3600;
  return [[h, 'Hours'], [Math.floor(s / 60), 'Mins'], [s % 60, 'Secs']];
}

export function Countdown() {
  const [parts, setParts] = useState<[number, string][] | null>(null);
  useEffect(() => {
    setParts(left());
    const t = setInterval(() => setParts(left()), 1000);
    return () => clearInterval(t);
  }, []);
  return (
    <div className="cd">
      {parts?.map(([v, l], i) => (
        <span key={l} style={{ display: 'contents' }}>
          {i > 0 && <i>:</i>}
          <span>
            <b>{String(v).padStart(2, '0')}</b>
            <small>{l}</small>
          </span>
        </span>
      ))}
    </div>
  );
}
