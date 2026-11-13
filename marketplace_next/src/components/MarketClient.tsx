'use client';
/**
 * Client islands of the marketplace (the progressive enhancements of marketplace.js): the location sheet
 * (browser geolocation via window.OmniGeo, or an area of the offline list, filtered as you type),
 * auto-submitting filters, quantity steppers, one-time submit, and clearing the shown flash message.
 */
import Script from 'next/script';
import { createContext, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { clearLocationAction, saveLocationAction } from '@/app/actions/location';
import { Ic } from './Ic';

interface Geo {
  detect(): Promise<{ lat: number; lng: number }>;
}

interface SheetCtx {
  open: () => void;
  detect: (btn?: HTMLButtonElement | null) => void;
}
const Ctx = createContext<SheetCtx>({ open: () => {}, detect: () => {} });

export interface AreaGroup {
  city: string;
  areas: string[];
}

/** Location picker: the sheet in the layout and the /location page. Both post to saveLocationAction. */
export function LocationPicker({ areas, current, back, hasLoc, q = '', autoFocus = false }: { areas: AreaGroup[]; current: string; back: string; hasLoc: boolean; q?: string; autoFocus?: boolean }) {
  const [filter, setFilter] = useState(q.toLowerCase());
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const gps = useRef<HTMLFormElement>(null);
  const input = useRef<HTMLInputElement>(null);
  useEffect(() => {
    if (autoFocus) setTimeout(() => input.current?.focus(), 50);
  }, [autoFocus]);

  const detect = async () => {
    const G = (window as unknown as { OmniGeo?: Geo }).OmniGeo;
    const f = gps.current;
    if (!G || !f) return;
    setBusy(true);
    try {
      const loc = await G.detect();
      (f.elements.namedItem('lat') as HTMLInputElement).value = String(loc.lat);
      (f.elements.namedItem('lng') as HTMLInputElement).value = String(loc.lng);
      f.requestSubmit();
    } catch (e) {
      setBusy(false);
      setMsg(e instanceof Error ? e.message : 'Could not get your location');
    }
  };

  const shown = areas.map((g) => ({ city: g.city, list: g.areas.filter((a) => filter === '' || `${a} ${g.city}`.toLowerCase().includes(filter)) })).filter((g) => g.list.length > 0);

  return (
    <>
      <h2>
        <Ic name="map-pin" />
        Your location
      </h2>
      <form id="loc-gps" ref={gps} action={saveLocationAction}>
        <input type="hidden" name="back" value={back} />
        <input type="hidden" name="lat" defaultValue="" />
        <input type="hidden" name="lng" defaultValue="" />
        <button className="btn primary full js-only" type="button" data-detect="" onClick={detect} disabled={busy}>
          <Ic name="locate-fixed" />
          {busy ? 'Finding you…' : 'Use my current location'}
        </button>
      </form>
      <p className={'note' + (msg ? ' warn' : '')} data-loc-msg="">
        {msg ?? 'We ask your browser for permission. Your location is only used to show nearby shops and is remembered on this device.'}
      </p>
      <div className="or">
        <span>or choose your area</span>
      </div>
      <input ref={input} className="in" data-area-q="" placeholder="Search area, e.g. Dhanmondi" aria-label="Search area" defaultValue={q} onChange={(e) => setFilter(e.target.value.trim().toLowerCase())} />
      <form action={saveLocationAction}>
        <input type="hidden" name="back" value={back} />
        <div className="areas" data-areas="">
          {shown.map((g) => (
            <div key={g.city} className="city" data-city={g.city}>
              <b>{g.city}</b>
              {g.list.map((a) => (
                <button key={a} name="area" value={a} className={current === a ? 'on' : ''}>
                  {a}
                </button>
              ))}
            </div>
          ))}
          <p className="note" data-area-none="" hidden={shown.length > 0}>
            No area found.
          </p>
        </div>
      </form>
      {hasLoc && (
        <form action={clearLocationAction} className="loc-clear">
          <button className="btn sm" type="submit">
            <Ic name="x" />
            Forget my location
          </button>
        </form>
      )}
    </>
  );
}

/** The layout's location sheet + the context the "Deliver to" link / "Use my location" buttons use. */
export function LocationSheet({ children, picker }: { children: ReactNode; picker: { areas: AreaGroup[]; current: string; back: string; hasLoc: boolean } }) {
  const [open, setOpen] = useState(false);
  const [n, setN] = useState(0);
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, []);
  const sheetRef = useRef<HTMLDivElement>(null);
  const ctx: SheetCtx = {
    open: () => {
      setOpen(true);
      setN((x) => x + 1);
    },
    detect: () => {
      setOpen(true);
      setTimeout(() => sheetRef.current?.querySelector<HTMLButtonElement>('[data-detect]')?.click(), 0);
    },
  };
  return (
    <Ctx.Provider value={ctx}>
      <Script src="/assets/marketplace/omnipos-geo.js" strategy="afterInteractive" />
      {children}
      <div id="sheet" ref={sheetRef} className={'sheet' + (open ? ' open' : '')} aria-hidden={!open}>
        <div className="sh-bg" data-close="" onClick={() => setOpen(false)} />
        <div className="sh-box" role="dialog" aria-modal="true" aria-label="Your location">
          <button className="x" type="button" data-close="" aria-label="Close" onClick={() => setOpen(false)}>
            <Ic name="x" />
          </button>
          <LocationPicker key={n} {...picker} autoFocus={open} />
        </div>
      </div>
    </Ctx.Provider>
  );
}

/** A link to /location that opens the sheet instead when JavaScript runs. */
export function LocLink({ href, className, children }: { href: string; className?: string; children: ReactNode }) {
  const { open } = useContext(Ctx);
  return (
    <a
      className={className}
      data-loc=""
      href={href}
      onClick={(e) => {
        e.preventDefault();
        open();
      }}
    >
      {children}
    </a>
  );
}

/** "Use my location" outside the sheet (home page card). */
export function DetectButton({ children }: { children: ReactNode }) {
  const { detect } = useContext(Ctx);
  return (
    <button className="btn primary js-only" type="button" data-detect="" onClick={() => detect()}>
      {children}
    </button>
  );
}

/** Submit the surrounding form when this select / checkbox changes (data-autosubmit). */
export function AutoSelect(props: React.SelectHTMLAttributes<HTMLSelectElement>) {
  return <select {...props} onChange={(e) => e.currentTarget.form?.requestSubmit()} />;
}

export function AutoCheck(props: React.InputHTMLAttributes<HTMLInputElement>) {
  return <input type="checkbox" {...props} onChange={(e) => e.currentTarget.form?.requestSubmit()} />;
}

export function AutoNumber(props: React.InputHTMLAttributes<HTMLInputElement>) {
  return <input type="number" {...props} onChange={(e) => e.currentTarget.form?.requestSubmit()} />;
}

/** Quantity with −/+ (data-q of marketplace.js). */
export function QtyStepper({ max }: { max: number }) {
  const ref = useRef<HTMLInputElement>(null);
  const step = (d: number) => {
    const i = ref.current;
    if (i) i.value = String(Math.max(1, Math.min(max, (Number(i.value) || 1) + d)));
  };
  return (
    <div className="qty">
      <span>Quantity</span>
      <button type="button" data-q="-1" aria-label="Less" onClick={() => step(-1)}>
        <Ic name="minus" />
      </button>
      <input ref={ref} className="in" type="number" name="qty" defaultValue={1} min={1} max={max} step={1} style={{ width: 72, textAlign: 'center' }} aria-label="Quantity" />
      <button type="button" data-q="1" aria-label="More" onClick={() => step(1)}>
        <Ic name="plus" />
      </button>
    </div>
  );
}

/** The shown flash message is one-shot: forget it once the page is up. */
export function FlashClear({ t }: { t?: number }) {
  useEffect(() => {
    if (t !== undefined) document.cookie = 'mk_flash=; path=/; max-age=0; samesite=lax';
  }, [t]);
  return null;
}
