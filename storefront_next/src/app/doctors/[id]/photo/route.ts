/** The doctor's photo, from the API (Doctors::photo). */
import { getContext } from '@/lib/context';

export async function GET(_req: Request, { params }: { params: Promise<{ id: string }> }): Promise<Response> {
  const id = Math.trunc(Number((await params).id));
  if (!(id > 0)) return new Response('Not found', { status: 404 });
  try {
    const { api } = await getContext();
    const p = await api.doctorPhoto(id);
    const mime = String(p.mime ?? '');
    const b64 = String(p.base64 ?? '').replace(/\s+/g, '');
    if (!['image/jpeg', 'image/png'].includes(mime) || b64 === '' || !/^[A-Za-z0-9+/]+={0,2}$/.test(b64)) {
      return new Response('Not found', { status: 404 });
    }
    return new Response(new Uint8Array(Buffer.from(b64, 'base64')), {
      headers: { 'Content-Type': mime, 'Cache-Control': 'public, max-age=86400', 'X-Content-Type-Options': 'nosniff' },
    });
  } catch {
    // any API error (or an unknown shop) is simply "no photo"
    return new Response('Not found', { status: 404 });
  }
}
