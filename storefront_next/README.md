# Storefront (Next.js)

The customer-facing shop websites of BizPilot360X, rebuilt on **Next.js 16 (App Router) + TypeScript**.
It is a port of [`apps/storefront`](../storefront) (CodeIgniter 4): the **same design** (the CSS files and class
names are copied unchanged), the **same routes and behaviour**, and the **same API Service calls**.
Like the PHP app it has **no database** — it reads and writes only through the API Service
([`apps/storefront_api`](../storefront_api), its dedicated CodeIgniter 4 backend), authenticating as a hosted storefront.

## Run it

```bash
cd apps/storefront_next
npm install
cp .env.example .env.local        # then fill in POS_API_URL and the token (see below)
npm run dev                       # http://localhost:8088/?site=digitalzone
```

Production: `npm run build && npm start` (port 8088; put it behind your TLS reverse proxy and set
`COOKIE_SECURE=1`).

### Which shop is shown?

Exactly as in the PHP app:

| Where                         | How the shop is chosen                                                   |
| ----------------------------- | ------------------------------------------------------------------------ |
| `localhost` (development)     | `?site=<slug>` (remembered in a cookie), e.g. `?site=freshmart`           |
| `<slug>.yourdomain.com`       | the sub-domain; a shop's own custom domain works the same way            |
| Self-hosted (`CLIENT_ID` set) | always the request host; the API checks the domain is authorised         |
| Site key (`pk_live_…` token)  | the key itself names the shop                                            |

`src/proxy.ts` makes this decision for every request; `src/lib/context.ts` then loads the shop profile
(`GET /v1/storefront`) and shows a friendly page when the shop is unknown, suspended or the API is down.

### Environment (`.env.local`, never commit it)

See [`.env.example`](.env.example). The important ones: `POS_API_URL`, one of `STOREFRONT_GATEWAY_TOKEN` /
`CLIENT_ID`+`CLIENT_SECRET`, and `SHIELD_SECRET`. Secrets are read only in server code.

## Develop without the API's database

```bash
node tests/mock-api.mjs                         # fake API on :8083 with four sample shops
# .env.local: POS_API_URL=http://localhost:8083/  STOREFRONT_GATEWAY_TOKEN=test-token
npm run dev      # then open /?site=techshop  /?site=foodie  /?site=salonx  /?site=cliniq
```

`npm test` runs the unit tests (host resolution, cart pricing, validation, payloads, tracking, human check).
`npm run typecheck` runs `tsc`.

## How the PHP app maps over

See [PORTING.md](PORTING.md) for the full concept map and the rules each page follows. In short:

* controllers that render → async server components in `src/app/**/page.tsx`;
* controllers that change data → Server Actions in `src/app/actions/` (forms post straight to them);
* the PHP session (cart, table QR, just-placed orders) → small httpOnly cookies; **prices are never stored** —
  the cart holds only product / option / quantity and is re-priced from the API on every view;
* flash messages → a one-shot cookie + toast; form errors and typed values → the action's result state;
* the `shield` filter (honeypot, signed form timestamp, human-check question, optional Cloudflare Turnstile,
  rate limit) → `src/lib/shield.ts` with the same signed-token format;
* `storefront-app.js` / `variant-picker.js` → React client components.

## Known differences from the PHP app

* **Status codes for store-level errors.** Next.js cannot set a status from a layout, so the "store not
  found / not open / temporarily unavailable" pages return 200 (with `noindex`) instead of 404/503.
  Page-level 404s (`notFound()`) are real 404s.
* **Profile freshness.** The shop profile is cached for 10 s (the PHP app re-validated with an ETag on every
  request), so deactivating a shop or domain takes up to ~10 s to show.
* **Rate limiting** is in memory, per server process. Keep real flood protection (nginx `limit_req`, a CDN)
  in front, as with the PHP app.
* **Cookie size.** The "just placed" confirmations keep only the last few orders/bookings; older ones fall back
  to the Track page, which asks the API.
