/** GET /locations/areas?city=12 — relay of the API's area catalogue for the checkout pickers (Places::areas). */
import { NextResponse } from 'next/server';
import { isApiError } from '@/lib/api/errors';
import { getContext } from '@/lib/context';

const reply = (data: unknown[], status: number, maxAge = 0) =>
  NextResponse.json({ data }, { status, headers: { 'Cache-Control': maxAge > 0 ? `public, max-age=${maxAge}` : 'no-store' } });

export async function GET(request: Request) {
  const city = (new URL(request.url).searchParams.get('city') ?? '').trim();
  if (!/^\d+$/.test(city) || city.length > 10) return reply([], 422);
  const { api } = await getContext();
  try {
    const list = await api.locationAreas(Number(city));
    return reply(
      list.map((a) => ({ id: Number(a.id), name: String(a.name), kind: String(a.kind ?? 'area'), postal_code: a.postal_code ?? null })),
      200,
      600,
    );
  } catch (e) {
    if (isApiError(e)) return reply([], e.status === 404 ? 404 : 502);
    throw e;
  }
}
