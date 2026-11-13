/** Redirect helper for Route Handlers: builds the URL from the public host (behind a proxy request.url may be internal). */
import 'server-only';
import { NextResponse } from 'next/server';

export function redirectTo(request: Request, path = '/'): NextResponse {
  const h = request.headers;
  const own = new URL(request.url);
  const host = (h.get('x-forwarded-host') ?? h.get('host') ?? own.host).split(',')[0].trim();
  const proto = (h.get('x-forwarded-proto') ?? own.protocol.replace(':', '')).split(',')[0].trim();
  return NextResponse.redirect(new URL(path, `${proto}://${host}`), 303);
}
