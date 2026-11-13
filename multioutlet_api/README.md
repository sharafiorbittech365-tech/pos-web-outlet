# Multi-outlet API (CodeIgniter 4)

Dedicated backend of the multi-outlet website ([`apps/multioutlet_next`](../multioutlet_next)). Its own copy of the
`/v1/multisite` part of the API Service (`apps/api`), with the same authentication: a business's `mk_live_…`
website key, or the platform gateway token + `X-Multisite-Host`. Server-side only (any browser `Origin` → 403).

Routes: `GET /v1/multisite`, `/outlets`, `/outlets/{id}`, `/categories`, `/products`, `/products/{id}`,
`POST /v1/multisite/orders` (`Idempotency-Key`), `GET /v1/multisite/orders/{reference}`, `GET /media/…`,
`GET /v1/health`. The catalogue, order and storefront *services* it builds on are included; their single-outlet
routes are not.

```bash
cp env.development.example .env      # database + omnipos.storefrontGatewayToken
composer install
php spark serve --port 8094          # http://localhost:8094/v1/health
vendor/bin/phpunit                   # feature tests on omnipos_test_moapi
```
