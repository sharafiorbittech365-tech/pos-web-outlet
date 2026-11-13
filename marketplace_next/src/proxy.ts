/**
 * Request proxy: hands the current path + query to the app in x-mk-path (always overwritten, never taken
 * from the client), so the layout can offer "return here" links (the PHP back_path() helper).
 */
import { NextResponse, type NextRequest } from 'next/server';

export const PATH_HEADER = 'x-mk-path';

export function proxy(req: NextRequest) {
  const headers = new Headers(req.headers);
  headers.set(PATH_HEADER, (req.nextUrl.pathname + req.nextUrl.search).slice(0, 500));
  return NextResponse.next({ request: { headers } });
}

export const config = {
  matcher: ['/((?!_next/static|_next/image|assets/|favicon.ico|robots.txt|api/).*)'],
};
