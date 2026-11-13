'use client';
/** Quantity stepper (data-step): the input is posted as `qty`. */
import { useState } from 'react';
import { Icon } from './Icon';

export function QtyStepper({ max = 99 }: { max?: number }) {
  const [v, setV] = useState('1');
  const step = (d: number) => setV(String(Math.min(max, Math.max(1, (Number(v) || 1) + d))));
  return (
    <div className="qty">
      <button type="button" aria-label="Less" onClick={() => step(-1)}>
        <Icon name="minus" />
      </button>
      <input name="qty" type="number" value={v} min={1} max={max} inputMode="numeric" aria-label="Quantity" onChange={(e) => setV(e.target.value)} />
      <button type="button" aria-label="More" onClick={() => step(1)}>
        <Icon name="plus" />
      </button>
    </div>
  );
}
