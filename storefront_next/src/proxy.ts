/**
 * Request proxy (Next.js 16's replacement for middleware.ts): decides which shop each request is for
 * and hands the answer to the app in the x-sf-host header (always overwritten, never taken from the
 * client). Port of the ?site= / cookie / Host logic of the PHP "store" filter — see lib/host.ts.
 *
 * Only plain environment variables are read here: this file is not part of the server-only module graph.
 */
import { NextResponse, type NextRequest } from 'next/server';
import { HOST_HEADER, resolveHost, SITE_COOKIE } from './lib/host';

const env = (name: string): string => (process.env[name] ?? '').trim();

export function proxy(req: NextRequest) {
  const selfHosted = env('CLIENT_ID') !== '';
  const resolved = resolveHost(
    req.nextUrl.searchParams.get('site'),
    req.cookies.get(SITE_COOKIE)?.value ?? null,
    req.headers.get('x-forwarded-host') ?? req.headers.get('host') ?? '',
    {
      devHosts: (env('DEV_HOSTS') || 'localhost,127.0.0.1,::1').split(',').map((h) => h.trim().toLowerCase()).filter(Boolean),
      selfHosted,
      siteKey: !selfHosted && env('STOREFRONT_GATEWAY_TOKEN').startsWith('pk_'),
    },
  );

  const headers = new Headers(req.headers);
  headers.set(HOST_HEADER, resolved.host ?? '');
  const res = NextResponse.next({ request: { headers } });
  if (resolved.remember !== null) {
    res.cookies.set(SITE_COOKIE, resolved.remember, {
      path: '/',
      maxAge: 30 * 86400,
      httpOnly: true,
      sameSite: 'lax',
      secure: env('COOKIE_SECURE') === '1',
    });
  }
  return res;
}

export const config = {
  matcher: ['/((?!_next/static|_next/image|assets/|favicon.ico|favicon.svg|robots.txt).*)'],
};
