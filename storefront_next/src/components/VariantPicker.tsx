'use client';
/** Size x colour picker of the product page (port of variant-picker.js). Posts the chosen id as `variant_id`. */
import { useState } from 'react';

export interface PickerVariant {
  id: number;
  a: Record<string, string>;
  ok: boolean;
}

const matches = (v: PickerVariant, s: Record<string, string>): boolean => Object.keys(s).every((k) => String(v.a[k]) === s[k]);

export function VariantPicker({ variants, dims, initial }: { variants: PickerVariant[]; dims: Record<string, string[]>; initial: number }) {
  const names = Object.keys(dims);
  const [sel, setSel] = useState<Record<string, string>>(() => {
    const v = variants.find((x) => x.id === initial);
    return v ? Object.fromEntries(Object.entries(v.a).map(([k, x]) => [k, String(x)])) : {};
  });

  const complete = names.every((d) => sel[d] !== undefined);
  const found = complete ? variants.find((v) => matches(v, sel)) : undefined;
  const chosen = found && found.ok ? found.id : null;
  const msg = chosen !== null ? '' : complete ? 'This combination is out of stock.' : `Choose ${names.filter((d) => sel[d] === undefined).map((d) => d.toLowerCase()).join(' and ')}.`;

  const pick = (d: string, v: string) =>
    setSel((s) => {
      const n = { ...s };
      if (n[d] === v) delete n[d];
      else n[d] = v;
      return n;
    });

  return (
    <div className="vp">
      {names.map((d) => {
        const others = Object.fromEntries(Object.entries(sel).filter(([k]) => k !== d));
        return (
          <div className="opt vp-dim" key={d}>
            <b>
              {d} <span>{sel[d] ? '· ' + sel[d] : ''}</span>
            </b>
            <div>
              {dims[d].map((v) => {
                const any = variants.some((x) => matches(x, { ...others, [d]: v }) && x.ok);
                const on = sel[d] === v;
                return (
                  <button key={v} type="button" className={'ob' + (on ? ' on' : '') + (any ? '' : ' na')} aria-pressed={on} onClick={() => pick(d, v)}>
                    {v}
                  </button>
                );
              })}
            </div>
          </div>
        );
      })}
      <small className="muted vp-msg" aria-live="polite">
        {msg}
      </small>
      {/* the value the form posts; empty (and required) until a purchasable combination is chosen */}
      <input name="variant_id" value={chosen ?? ''} required readOnly tabIndex={-1} aria-hidden="true" style={{ position: 'absolute', opacity: 0, width: 1, height: 1, pointerEvents: 'none' }} />
    </div>
  );
}
