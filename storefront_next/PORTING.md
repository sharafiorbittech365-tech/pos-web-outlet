# Porting guide: CodeIgniter storefront → Next.js

`apps/storefront` (CodeIgniter 4, server-rendered PHP) is ported to `apps/storefront_next` (Next.js 16,
App Router, TypeScript). Same design (the CSS files and class names are copied unchanged), same API
Service calls, same behaviour. This file lists the conventions every page follows.

## Concept map

| CodeIgniter                                   | Next.js                                                              |
| --------------------------------------------- | -------------------------------------------------------------------- |
| `Config/Routes.php`, module `Routes.php`      | `src/app/**/page.tsx` + `route.ts` (file-system routing)             |
| `StoreFilter` (resolve shop, load profile)    | `src/proxy.ts` (host → `x-sf-host`) + `lib/context.ts` `getContext()` |
| `StoreApi` + SDK `Client`                     | `lib/api/storefront-api.ts` + `lib/api/client.ts` (server only)      |
| `Store` library                               | `lib/store.ts` (`Store` class)                                       |
| Controllers (`index`, `show`…)                | async server components (`page.tsx`)                                 |
| Controllers (`create`, `place`, `add`…)       | Server Actions in `src/app/actions/*.ts`                             |
| Views / partials                              | TSX components in `src/components/**`                                |
| `storefront_helper.php`                       | `lib/format.ts`, `components/Icon.tsx`                               |
| Session (`cart`, table, placed orders)        | httpOnly cookies: `lib/services/cart.ts`, `table-session.ts`, `done.ts` |
| Flashdata (`ok`, `error`, `added`)            | `lib/flash.ts` cookie + `components/Toast.tsx`                       |
| `old()` input + `errors` flashdata            | Server Action result (`FormState`, `useActionState`)                 |
| `shield` filter (human check, honeypot, rate) | `lib/shield.ts` + `components/HumanCheck.tsx` + `/api/shield`        |
| `storefront-app.js`, `variant-picker.js`      | React client components (`'use client'`)                             |

## Rules

* **Next 16**: `params` and `searchParams` are Promises (`const { id } = await params`). `cookies()` and
  `headers()` are async. Cookies can only be *written* in Server Actions / Route Handlers.
* **Pages are server components.** Get the shop with `const { store, api, host } = await getContext()`.
  Only add `'use client'` for real interactivity, and pass client components plain, serialisable props
  (use `store.toClient()`, not the `Store` instance).
* **Metadata**: `export async function generateMetadata()` → `pageMeta(store, { title, description, path, image, noindex })`.
* **Not found**: `notFound()` from `next/navigation` (the PHP `PageNotFoundException`). API 404 → `notFound()`;
  other `ApiError`s propagate to `app/error.tsx`, exactly like the PHP error handler's generic path.
* **CSS**: all five stylesheets are imported once in `app/layout.tsx`. Reuse the existing class names
  verbatim. No Tailwind, no CSS modules, no new global rules unless a PHP view has inline styles to keep.
* **Links**: internal `<Link href>` from `next/link`; keep the same URLs as the PHP routes.
* **Forms that change data** call a Server Action:
  * add-to-cart style forms: `<form action={addToCart}>` (see `actions/cart.ts`);
  * forms with validation errors: a client form component with `useActionState(action, emptyForm)`.
    The action returns `failed(prev, values, errors, message)` (from `lib/form-errors.ts`) or `redirect()`s on success.
    Order inside the action: throttle → validate fields → `verifyHuman(input)` (consumes the single-use token,
    so it must come *after* cheap validation) → API call. Map API 422/409 like the PHP controller does.
    `redirect()` must be called outside `try/catch` (it throws).
  * the form includes `<ShieldFieldsBlock />` (server component, passed in as a prop/children) and calls
    `refreshHumanCheck()` in an effect whenever the action state changes (`state.n`).
* **Never** send prices from the browser. Orders and bookings send ids/quantities only (the API prices them).
* **Secrets** (`STOREFRONT_GATEWAY_TOKEN`, `CLIENT_SECRET`, `SHIELD_SECRET`) exist only in server code
  (`lib/config.ts` imports `server-only`). Do not read them elsewhere or expose them via props.

## Where things live

```
src/
  proxy.ts                 which shop is this request for  (?site= / cookie / Host)
  app/
    layout.tsx             <html>, CSS, fonts, shop chrome (Shell) or the friendly store-error page
    page.tsx               home (shop | booking | menu | service | clinic)
    actions/               Server Actions (cart, checkout, booking, doctors)
    api/shield/route.ts    fresh human-check question
    not-found.tsx error.tsx [...slug]/page.tsx
  components/              Shell, ProductCard, Pager, Blocks, forms, client islands …
  lib/
    config.ts context.ts host.ts store.ts format.ts seo.ts cookies.ts flash.ts shield.ts request.ts
    api/                   client.ts storefront-api.ts errors.ts
    services/              catalog, calculator, cart, checkout, booking, clinic, track, validate
public/assets/storefront/  icon.svg demo.svg omnipos-geo.js
```
