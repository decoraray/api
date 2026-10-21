# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is the Laravel 13 / PHP 8 API for Decoraray, a Persian-language e-commerce platform. It serves the storefront (`ui`), admin dashboard (`admin`), and legacy Vue app (`old`) — see the monorepo root `CLAUDE.md` for the full-stack picture. This file covers only the `api/` service.

- Production API: https://api.decoraray.ir
- Swagger UI: https://api.decoraray.ir/swagger/index.html (served from `resources/documentation/api-docs.yaml` and `public/swagger/`)

## Development Commands

```bash
composer install
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve                        # Dev server at :8000

php artisan test                         # Run full PHPUnit suite (Unit + Feature)
./vendor/bin/phpunit --filter=TestName    # Run a single test
./vendor/bin/phpunit tests/Feature/Foo.php

php artisan scout:import App\\Models\\Product   # Rebuild Typesense index
```

Note: `tests/Feature` has real coverage for Auth, Shop (product/cart/checkout), Admin CRUD, and the middleware tiers described below. `tests/Unit` still only contains the default Laravel example test — services like pricing, search, and payment drivers have no unit coverage yet.

## Architecture

### Routing (`routes/api.php`)

All routes live in a single `v1` prefix group, layered with three concentric middleware tiers (outer to inner):

1. `App\Http\Middleware\Authenticate` (custom, NOT Sanctum) — wraps the entire `v1` group. It *optionally* decodes a JWT if present and logs the user in via `Auth::loginUsingId`, but never rejects the request if the token is missing/invalid — it just calls `$next($request)` regardless. This is what makes routes like `/products` and `/search` effectively public while still resolving `auth()->user()` when a token is sent.
2. `App\Http\Middleware\JwtVerify` — wraps the authenticated-only route group (user profile, checkout, address, admin). Unlike `Authenticate`, this one *does* reject with 401 if the JWT is missing, expired, or invalid.
3. Spatie `role:Admin` / `role:SuperAdmin` — wraps the two admin sub-groups. `SuperAdmin` gets full CRUD on products/variations/categories/orders/colors/materials/users/posts plus dashboard statistics and image management; `Admin` gets read-only (`index`/`show`) access to most of the same resources.

When adding a new authenticated endpoint, decide up front which of these three tiers it belongs in — most bugs around "why is this route public/private" trace back to which middleware group it was placed in.

### Auth (JWT, not Sanctum)

Despite `laravel/sanctum` being a dependency, auth is hand-rolled JWT via `firebase/php-jwt`, in `App\Services\Auth\AuthService`:
- Tokens are signed HS256 using the raw `config('app.key')` as the secret (not Sanctum's model-backed tokens).
- `createToken($user)` embeds `sub` (user id) and `role` in the payload, 7-day expiry.
- `verifyToken($jwt)` is called from both `Authenticate` and `JwtVerify` middleware.
- RBAC roles (`SuperAdmin`, `Admin`, `User`) are managed via `spatie/laravel-permission`, checked with the `role:` middleware — independent of the JWT's embedded `role` claim, which is not itself used for authorization.

### Product search: Typesense is wired up but not live

`App\Services\Search\ProductSearch::getProductList()` has the Typesense/Scout `Product::search()` path fully written but commented out. The active code path queries MySQL directly via Eloquent scopes (`active()`, `byCategory()`, `byTag()`, `hasActiveVariation()`, `Sort()`) with a `search_title LIKE %keyword%` match, cached for 60s per unique query string. If you re-enable Typesense, uncomment that block and be aware admin requests currently skip the `hasActiveVariation()` filter (detected via `$request->is('api/v1/admin/*')`) so admins see products without active variations — replicate that check in the Scout path too.

`Product::search_title` is auto-derived from `title` in `setTitleAttribute()` (lowercased, whitespace/ZWNJ-stripped) — never set `search_title` directly, it's overwritten whenever `title` is set.

### Pricing model

Products don't store a price directly — it's derived through a chain on `App\Models\Product`:
- `oldAveragePrice`/`oldPrice` come from the `variations` relation (`avg('price')` / `min('oldPrice')`).
- `averagePrice`/`price` apply the active `deal`'s discount percentage on top of those, if one exists (`deal()` relation is itself scoped to `active = 1`).
- `updateAveragePrice()` persists the computed average back to `products.average_price` (used for sort-by-price, since that column is what `scopePriceAsc`/`scopePriceDesc` actually order on — it can drift from the live variation prices if not refreshed after variation changes).

### Payment gateways

`App\Services\Payment\PaymentService` resolves a driver class by string convention: `App\Services\Payment\Gateways\{Ucfirst($gateway)}Driver`, implementing `GatewayInterface` (`pay()`/`verify()`). Current drivers: `PaypingDriver` (primary), `SamanDriver`. Adding a new gateway means adding a class following that naming convention — there's no registry/config list to update elsewhere.

### Controllers layout

- `App\Http\Controllers\*` — public/customer-facing (shop, cart, auth, payment, addresses, comments/ratings).
- `App\Http\Controllers\Admin\*` — admin/SuperAdmin CRUD, gated by the `role:` middleware in `routes/api.php` rather than in the controllers themselves.
- Services (`app/Services/`) hold gateway/driver logic (Payment, Sms) and cross-cutting logic (Auth, Search, Jdf for Jalali dates, Campaign). Controllers call into these rather than embedding provider-specific logic.
