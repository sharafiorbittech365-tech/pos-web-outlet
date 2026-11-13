/** Shared input validation (ports of BookingService::validPhone/normalisePhone and filter_var). */

export function normalisePhone(p: string): string {
  return (p ?? '').trim().replace(/[\s\-().]/g, '');
}

/** Bangladeshi mobile (01[3-9]XXXXXXXX, optional +88/88) or an international +number. */
export function validPhone(p: string): boolean {
  const n = normalisePhone(p);
  return /^(?:\+?88)?01[3-9]\d{8}$/.test(n) || /^\+[1-9]\d{7,14}$/.test(n);
}

export function validEmail(e: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e);
}

/** ISO-8601-ish "YYYY-MM-DD[T ]HH:MM[:SS][Z|±HH:MM]" accepted for booking start times. */
export const STARTS_AT = /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?$/;
