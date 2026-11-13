# Marketplace API (CodeIgniter 4)

Dedicated backend of the Marketplace website ([`apps/marketplace_next`](../marketplace_next)). It holds the
logic the CodeIgniter marketplace (`apps/marketplace`) ran in-process — listed shops, discovery with distance,
listings, cart pricing per shop, one order per shop through the core `OrderService`, bookings through the core
`BookingService`, buyer accounts — and exposes it as JSON under `/v1/market`.

```bash
cp env.development.example .env      # omnipos_marketplace DB account, market.apiToken
composer install
php spark serve --port 8095           # http://localhost:8095/v1/health
vendor/bin/phpunit                    # unit tests (no database needed)
```

## Authentication

* `Authorization: Bearer <market.apiToken>` on every `/v1/market/*` call (`MARKETPLACE_API_TOKEN` in the
  website's `.env.local`). Any browser `Origin` header → 403: the API is server-side only. No token configured →
  every request is refused.
* Buyers: `POST /v1/market/buyers/login` / `POST /v1/market/buyers` return a signed, expiring token
  (`App\Libraries\BuyerToken`); the website sends it back as `X-Buyer-Token`. The account is re-read on every call.
* The visitor's location travels with each request (`lat, lng, area, city, src` on GET; `location` in POST bodies)
  and is cleaned every time (`LocationStore::clean`). The cart travels in the body of `cart/quote` and `checkout`
  and is cleaned the same way (`CartService::normalise`) — only ids and quantities are trusted.

## Endpoints

See [`app/Modules/README.md`](app/Modules/README.md). Responses use the platform envelope
(`{data, meta}` / `{error: {code, message, details, request_id}}`).

## Notes

* The core `ratelimit` filter is not used: every call comes from the website's server, so per-IP limits would
  throttle the whole site. The website throttles per visitor; sign-in is throttled per visitor IP + number by
  the core `LoginThrottle` (IP from `X-Visitor-IP`).
* Checkout / booking results are kept in the cache for `market.resultDays` (default 7) under their token.
  Use Redis in production (`cache.handler`), like the other apps.
