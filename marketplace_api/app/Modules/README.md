# Marketplace API modules

One folder per feature: `Controllers/`, `Models/`, `Services/`, `Config/Routes.php`.
Controllers stay thin (JSON in, JSON out — see `App\Controllers\MarketController`); business rules go
in `Services/` (or in omnipos/core when another app needs them too).

| Module    | Endpoints (`/v1/market/…`, token required)                         |
| --------- | ------------------------------------------------------------------ |
| Discovery | `GET listings`                                                     |
| Shops     | `GET shops`, `GET shops/{slug}`                                    |
| Listing   | `GET listings/{business}/{product}`                                |
| Location  | `GET meta`, `POST location`                                        |
| Checkout  | `POST cart/check`, `POST cart/quote`, `POST checkout`, `GET checkout/{token}` |
| Booking   | `POST bookings`, `GET bookings/{token}`                            |
| Buyers    | `POST buyers/login`, `POST buyers`, `GET me`, `GET me/orders`, `GET me/orders/{id}`, `GET me/bookings` |
| Health    | `GET /v1/health` (public)                                          |
