'use client';
import type { ComponentProps } from 'react';

/** <select data-autosubmit>: submits its form when the value changes. */
export function AutoSubmitSelect(props: ComponentProps<'select'>) {
  return <select {...props} onChange={(e) => e.currentTarget.form?.requestSubmit()} />;
}
