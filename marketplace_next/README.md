# Marketplace (Next.js)

BizPilot360X Market — location-based listings across shops, carts per shop, checkout, bookings and buyer
accounts — rebuilt on **Next.js 16 (App Router) + TypeScript**. It is a port of [`apps/marketplace`](../marketplace)
(CodeIgniter 4): the **same design** (`marketplace.css`, `market-app.css` copied unchanged), the **same routes and
behaviour**.

The CodeIgniter marketplace read the database directly. This app has **no database**: all of that logic now lives
in its dedicated backend, [`apps/marketplace_api`](../marketplace_api) (`/v1/market/*`), which this app calls
server-side with a shared token.

## Run it

```bash
# 1. the backend
cd apps/marketplace_api && cp env.development.example .env    # database account + market.apiToken
composer install && php spark serve --port 8095

# 2. the website
cd apps/marketplace_next
npm install
cp .env.example .env.local    # MARKETPLACE_API_URL=http://localhost:8095/ and MARKETPLACE_API_TOKEN (= market.apiToken)
npm run dev                   # http://localhost:8090
```

Production: `npm run build && npm start` (port 8090) behind your TLS proxy, with `COOKIE_SECURE=1`.

## Pages

| URL                                  | PHP original                            |
| ------------------------------------ | --------------------------------------- |
| `/`, `/search`                       | `DiscoveryController::index`            |
| `/shops`, `/shops/{slug}`            | `ShopsController::index / show`         |
| `/listing/{business}/{product}`      | `ListingController::show` (+ booking form) |
| `/location`                          | `LocationController::index` (the header sheet is the same picker) |
| `/cart`, `/checkout`                 | `CartController::index`, `CheckoutController::form` |
| `/checkout/done/{token}`             | `CheckoutController::confirmation`      |
| `/book/done/{token}`                 | `BookingController::confirmation`       |
| `/login`, `/signup`                  | `AuthController`                        |
| `/account/orders[/{id}]`, `/account/bookings` | `AccountController`            |
| `/sell`                              | `SellersController::index`              |

## Where the PHP session went

| PHP session                    | Next.js                                                                  |
| ------------------------------ | ------------------------------------------------------------------------ |
| `mk_loc` location              | httpOnly cookie `mk_loc`; sent to the API with every call (it re-validates it) |
| `mk_cart`                      | httpOnly cookie `mk_cart` — ids and quantities only; priced by `POST /v1/market/cart/quote` on every view |
| `buyer_id`                     | httpOnly cookie `mk_buyer` holding a signed, expiring token from the API (`X-Buyer-Token`) |
| checkout / booking form tokens | a random token rendered into the form; the API keeps the result under it (a resubmit returns the same result, the per-shop order reference is derived from it) |
| `mk_after_login`               | `?next=` on `/login` (same-site paths only)                              |
| flashdata                      | one-shot cookie `mk_flash`                                              |

The `shield` filter (human check, honeypot, form age, rate limit) is `src/lib/shield.ts`; sign-in throttling
per visitor stays in the API (the visitor's IP is forwarded as `X-Visitor-IP`). The API token is read only in
server code (`src/lib/config.ts`) — the API refuses any request with a browser `Origin`.

`npm test` runs the unit tests; `npm run typecheck` runs `tsc`.

## Known differences from the PHP app

* The booking / checkout confirmation pages are addressed by their token (`/checkout/done/{token}`), not by the
  session; the token is a 128-bit random value and the pages show only the shop's reference and totals.
* Rate limiting of the forms is in memory, per server process — keep real flood protection in front.
