/** GET /locations/cities?country=BD — relay of the API's city catalogue for the checkout pickers (Places::cities). */
import { NextResponse } from 'next/server';
import { isApiError } from '@/lib/api/errors';
import { getContext } from '@/lib/context';

const reply = (data: unknown[], status: number, maxAge = 0) =>
  NextResponse.json({ data }, { status, headers: { 'Cache-Control': maxAge > 0 ? `public, max-age=${maxAge}` : 'no-store' } });

export async function GET(request: Request) {
  const cc = (new URL(request.url).searchParams.get('country') ?? '').trim().toUpperCase();
  if (!/^[A-Z]{2}$/.test(cc)) return reply([], 422);
  const { api } = await getContext();
  try {
    const list = await api.locationCities(cc);
    return reply(
      list.map((c) => ({ id: Number(c.id), name: String(c.name), kind: String(c.kind ?? 'city'), region: c.region ?? null, areas: Number(c.areas ?? 0) })),
      200,
      600,
    );
  } catch (e) {
    if (isApiError(e)) return reply([], e.status === 404 ? 404 : 502);
    throw e;
  }
}
