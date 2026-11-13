'use client';
/**
 * The outlet list with "Nearest outlet first" (multistore.js): asks the browser for the visitor's position
 * (window.OmniGeo from /assets/multistore/omnipos-geo.js — the location never leaves the browser) and sorts
 * the server-rendered outlet cards by distance.
 */
import Script from 'next/script';
import { Children, isValidElement, useState, type ReactElement, type ReactNode } from 'react';
import { Icon } from './Icon';

interface Geo {
  detect(): Promise<{ lat: number; lng: number }>;
  distanceKm(a: { lat: number; lng: number }, b: { lat: number; lng: number }): number;
}

export interface OutletPos {
  id: number;
  lat: number | null;
  lng: number | null;
}

export function NearestOutlets({ outlets, children }: { outlets: OutletPos[]; children: ReactNode }) {
  const [ready, setReady] = useState(false);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState('');
  const [km, setKm] = useState<Record<number, number>>({});
  const cards = Children.toArray(children).filter(isValidElement) as ReactElement[];

  const order = outlets.map((o, i) => ({ o, i }));
  if (Object.keys(km).length > 0) order.sort((a, b) => (km[a.o.id] ?? Infinity) - (km[b.o.id] ?? Infinity));

  const nearest = async () => {
    const G = (window as unknown as { OmniGeo?: Geo }).OmniGeo;
    if (!G) return;
    setBusy(true);
    try {
      const me = await G.detect();
      const out: Record<number, number> = {};
      for (const o of outlets) out[o.id] = o.lat === null || o.lng === null ? Infinity : G.distanceKm(me, { lat: o.lat, lng: o.lng });
      setKm(out);
      setMsg('Nearest outlet first.');
    } catch (e) {
      setMsg(e instanceof Error ? e.message : 'Could not get your location');
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <Script src="/assets/multistore/omnipos-geo.js" strategy="afterInteractive" onReady={() => setReady(true)} />
      <p>
        <button className="btn-g sm" type="button" hidden={!ready} disabled={busy} onClick={nearest}>
          <Icon name="locate-fixed" />
          Nearest outlet first
        </button>{' '}
        <small className="muted" aria-live="polite">
          {msg}
        </small>
      </p>
      <div className="ms-outlets">
        {order.map(({ o, i }) => {
          const d = km[o.id];
          return (
            <div key={o.id} data-km={d === undefined ? undefined : String(d)}>
              {cards[i]}
              {d !== undefined && d !== Infinity && (
                <span className="ms-o-dist" style={{ display: 'block', marginTop: 6 }}>
                  {d < 1 ? '< 1' : d.toFixed(1)} km away
                </span>
              )}
            </div>
          );
        })}
      </div>
    </>
  );
}
