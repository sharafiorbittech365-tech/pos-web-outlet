/** Maps the API's validation `details` keys to the form's field names. Port of app/Libraries/FormErrors.php. */
export function fromApi(details: Record<string, unknown>): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [k, msg] of Object.entries(details ?? {})) {
    let field: string;
    if (k.startsWith('customer.')) field = k.slice(9);
    else if (k.startsWith('customer_')) field = k.slice(9);
    else if (k.startsWith('items')) field = 'items';
    else if (k.startsWith('location')) field = 'location';
    else if (k === 'trx_id') field = 'trx_id';
    else if (k === 'starts_at') field = 'time';
    else if (k === 'staff_name') field = 'staff';
    else if (k === 'party_size') field = 'party';
    else field = k;
    out[field] ??= Array.isArray(msg) ? msg.map(String).join(' ') : String(msg);
  }
  return out;
}

/** Shared result shape of the form Server Actions (used with useActionState). */
export interface FormState {
  /** field → message */
  errors: Record<string, string>;
  /** banner message above the form */
  error: string;
  /** what the visitor typed, to refill the form after an error */
  values: Record<string, string>;
  /** bumps on every result so the client can react (e.g. refresh the human check) */
  n: number;
}

export const emptyForm: FormState = { errors: {}, error: '', values: {}, n: 0 };

export function failed(prev: FormState, values: Record<string, string>, errors: Record<string, string>, error = 'Please check the highlighted fields.'): FormState {
  // never echo the shield tokens or other noise back
  const clean: Record<string, string> = {};
  for (const [k, v] of Object.entries(values)) if (!k.startsWith('shield_') && !k.startsWith('cf-turnstile')) clean[k] = v;
  return { errors, error, values: clean, n: prev.n + 1 };
}
