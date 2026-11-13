'use client';
/**
 * Toast + flash handling (the #sf-toast element and the flash part of storefront-app.js).
 * <ToastProvider flash> wraps the shop; any client component can call useToast()(message, ok?).
 * The server passes the pending flash (set before a redirect); the browser shows it once and clears the cookie.
 */
import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react';

type Show = (message: string, ok?: boolean) => void;
const Ctx = createContext<Show>(() => {});

export const useToast = (): Show => useContext(Ctx);

export interface FlashData {
  ok?: string;
  error?: string;
  added?: boolean;
  t?: number;
}

/** Opens when a flash with `added` arrives (read by the cart drawer). */
export const FlashContext = createContext<FlashData>({});
export const useFlash = (): FlashData => useContext(FlashContext);

export function ToastProvider({ flash, children }: { flash: FlashData; children: ReactNode }) {
  const [msg, setMsg] = useState('');
  const [err, setErr] = useState(false);
  const [shown, setShown] = useState(false);
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  const show = useCallback<Show>((message, ok = true) => {
    if (!message) return;
    setMsg(message);
    setErr(ok === false);
    setShown(true);
    clearTimeout(timer.current);
    timer.current = setTimeout(() => setShown(false), 2600);
  }, []);

  // show each server flash once, then clear its cookie so a refresh does not repeat it
  const lastT = useRef<number | undefined>(undefined);
  useEffect(() => {
    if (flash.t === undefined || flash.t === lastT.current) return;
    lastT.current = flash.t;
    if (flash.error) show(flash.error, false);
    else if (flash.ok) show(flash.ok);
    document.cookie = 'sf_flash=; path=/; max-age=0; samesite=lax';
  }, [flash.t, flash.ok, flash.error, show]);

  useEffect(() => () => clearTimeout(timer.current), []);

  return (
    <Ctx.Provider value={show}>
      <FlashContext.Provider value={flash}>
        {children}
        <div id="sf-toast" className={'sf-toast' + (shown ? ' show' : '') + (err ? ' err' : '')} role="status" aria-live="polite">
          {msg}
        </div>
      </FlashContext.Provider>
    </Ctx.Provider>
  );
}
