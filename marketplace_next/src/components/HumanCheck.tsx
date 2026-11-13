'use client';
/**
 * The bot-protection fields of a form: honeypot, signed form timestamp and the "human check" question
 * (or Cloudflare Turnstile). Rendered on the server with fresh tokens (see ShieldFields); the form calls
 * refreshHumanCheck() after every submit result, because a question token can be used only once.
 */
import { useEffect, useState } from 'react';
import type { ShieldFields } from '@/lib/shield';

const REFRESH = 'sf-shield-refresh';

/** Ask every HumanCheck on the page for a new question (call after a failed submit). */
export const refreshHumanCheck = (): void => {
  window.dispatchEvent(new Event(REFRESH));
};

export function HumanCheck({ initial, label = 'Human check' }: { initial: ShieldFields; label?: string }) {
  const [f, setF] = useState<ShieldFields>(initial);
  const [n, setN] = useState(0);

  useEffect(() => {
    const on = async () => {
      try {
        const r = await fetch('/api/shield', { cache: 'no-store' });
        if (r.ok) {
          setF((await r.json()) as ShieldFields);
          setN((x) => x + 1);
        }
      } catch {
        /* keep the old question; the server will say if it expired */
      }
    };
    window.addEventListener(REFRESH, on);
    return () => window.removeEventListener(REFRESH, on);
  }, []);

  return (
    <div className="shield" style={{ margin: '12px 0', position: 'relative' }}>
      <input type="text" name="shield_hp" defaultValue="" tabIndex={-1} autoComplete="off" aria-hidden="true" style={{ position: 'absolute', left: -9999, width: 1, height: 1, opacity: 0 }} />
      <input type="hidden" name="shield_ts" value={f.ts} readOnly />
      {f.turnstileSiteKey ? (
        <>
          <div className="cf-turnstile" data-sitekey={f.turnstileSiteKey} key={n} />
          {/* eslint-disable-next-line @next/next/no-sync-scripts */}
          <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer />
        </>
      ) : (
        <label className="shield-q" key={n} style={{ display: 'grid', gap: 6, fontSize: 14, fontWeight: 600 }}>
          <span>
            {label}: what is <b>{f.question}</b> ?
          </span>
          <input
            className="input"
            type="text"
            name="shield_ans"
            inputMode="numeric"
            pattern="[0-9]*"
            autoComplete="off"
            required
            maxLength={3}
            style={{ maxWidth: 140 }}
            aria-label={`${label} — ${f.question}`}
          />
          <input type="hidden" name="shield_ch" value={f.token ?? ''} readOnly />
        </label>
      )}
    </div>
  );
}
