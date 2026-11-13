# Storefront API (CodeIgniter 4)

Dedicated backend of the Storefront website ([`apps/storefront_next`](../storefront_next)). Its own copy of the
storefront part of the API Service (`apps/api`): same `/v1` routes, same authentication (platform gateway token +
`X-Storefront-Host`, self-hosted `CLIENT_ID`/`CLIENT_SECRET`, or a shop's `pk_live_…` site key).

Modules: `Storefront` (`/v1/storefront`, `/v1/pages/{slug}`, `/media/…`), `Catalog`, `Orders`, `Bookings`,
`Clinic`, `Locations`, `Health`. The integration-only endpoints (`/v1/customers`, `/v1/marketplace`,
`/v1/multisite`) stay in `apps/api`.

```bash
cp env.development.example .env      # database + omnipos.storefrontGatewayToken (same value as the website's)
composer install
php spark serve --port 8093          # http://localhost:8093/v1/health
vendor/bin/phpunit                   # feature tests on omnipos_test_sfapi
```

`omnipos.apiUrl` must be this API's own public address: product / logo pictures stored in the database are served
by its `/media/…` route and linked from there.

Fixed here (still present in `apps/api`): `StorefrontController::page()` clashed with the core
`BaseController::page()`, which made PHP fail on every `/v1/storefront` request; the action is now `sitePage()`.
