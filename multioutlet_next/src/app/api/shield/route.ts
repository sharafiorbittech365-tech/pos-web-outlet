import { NextResponse } from 'next/server';
import { shieldFields, throttle } from '@/lib/shield';

export const dynamic = 'force-dynamic';

/** A fresh human-check question + form timestamp (used after a failed submit; tokens are single-use). */
export async function GET() {
  if ((await throttle('get:api/shield', 60, 60)) > 0) return NextResponse.json({ error: 'Too many requests.' }, { status: 429, headers: { 'Cache-Control': 'no-store' } });
  return NextResponse.json(shieldFields(), { headers: { 'Cache-Control': 'no-store' } });
}
