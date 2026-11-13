# Multi-outlet website (Next.js)

The multi-outlet websites of BizPilot360X (one website for every outlet of a business), rebuilt on
**Next.js 16 (App Router) + TypeScript**. It is a port of [`apps/multistore`](../multistore) (CodeIgniter 4):
the **same design** (`storefront.css`, `storefront-app.css`, `multistore.css` copied unchanged), the **same
routes and behaviour**. It has **no database** — it reads and writes only through its dedicated backend,
[`apps/multioutlet_api`](../multioutlet_api) (`/v1/multisite`).

## Run it

```bash
# 1. the backend
cd apps/multioutlet_api && cp env.development.example .env   # database + storefront gateway token
composer install && php spark serve --port 8094

# 2. the website
cd apps/multioutlet_next
npm install
cp .env.example .env.local        # POS_API_URL=http://localhost:8094/ and MULTISITE_KEY
npm run dev                       # http://localhost:8089
```

Production: `npm run build && npm start` (port 8089) behind your TLS proxy, with `COOKIE_SECURE=1`.

### Which website is shown?

| `MULTISITE_KEY`                     | Website                                                                      |
| ----------------------------------- | ---------------------------------------------------------------------------- |
| `mk_live_…` (POS › Website › Multi-outlet › Website key) | always that business (no `?site=`)                       |
| `sfg_…` platform gateway token      | by host `<slug>.<multisiteHost>`; on localhost `?site=<slug>` (remembered in `ms_site`) |

The website must be published (POS › Website › Multi-outlet) and the business needs the `website` + `multisite` modules.

## Pages

| URL               | PHP original        | What                                                      |
| ----------------- | ------------------- | --------------------------------------------------------- |
| `/`               | `Home::index`       | hero, outlet chooser, categories, featured & new products |
| `/outlets`        | `Home::outlets`     | every outlet, "nearest first" in the browser              |
| `/outlets/{id}`   | `Home::outlet`      | one outlet and its products                               |
| `/products`       | `Catalog::products` | filters: search, outlet, category, price, in stock, sort  |
| `/p/{id}`         | `Catalog::product`  | the item with every outlet's price and stock              |
| `/cart`           | `Cart::index`       | the cart (one outlet at a time)                           |
| `/checkout`       | `Checkout::index`   | contact, delivery/pickup, Rx, payment                     |
| `/order/{ref}`    | `Checkout::done`    | confirmation + live status                                |

## How it maps

Same conventions as [`apps/storefront_next`](../storefront_next/PORTING.md): pages are async server components,
forms post to Server Actions (`src/app/actions/cart.ts`, `checkout.ts`), the session cart is an httpOnly cookie
holding ids and quantities only (re-priced from the API on every view; the cart id is the order's
`Idempotency-Key`), flash messages are a one-shot cookie, and the `shield` filter is `src/lib/shield.ts`.
`storefront-app.js` / `multistore.js` became small client components (`ShellClient.tsx`, `NearestOutlets.tsx`,
`CheckoutForm.tsx`). The website key / gateway token is read only in server code (`src/lib/config.ts`).

`npm test` runs the unit tests (host resolution, Site helpers, checkout rules); `npm run typecheck` runs `tsc`.

## Known differences from the PHP app

* Store-level error pages (not published, unknown website, API down) answer 200 with `noindex`, because
  Next.js cannot set a status from a layout. Page-level 404s are real 404s.
* Rate limiting of the forms is in memory, per server process — keep real flood protection in front.
