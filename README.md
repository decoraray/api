# Decoraray API

Laravel 13 / PHP 8.3 API powering [Decoraray](https://decoraray.ir) (دکور آرای), a Persian-language e-commerce platform for wooden home/office decoration products. This repo is the backend API only — it serves a Nuxt 3 storefront and a Nuxt 3 admin dashboard, both separate projects.

- Swagger UI: `/swagger` (served from `resources/documentation/api-docs.yaml` and `public/swagger/`)

## Requirements

- PHP 8.3+
- Composer
- MySQL 8
- (optional) Typesense, for search — wired up but not enabled by default

## Getting started

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve   # http://localhost:8000
```

Or with Docker:

```bash
docker compose up --build
```

## Testing

```bash
php artisan test                        # full suite (Unit + Feature)
./vendor/bin/phpunit --filter=TestName   # a single test
```

Feature tests run against an in-memory sqlite database (configured in `phpunit.xml`), independent of whatever `DB_CONNECTION` is set in `.env`.

## Architecture

- **Auth**: hand-rolled JWT (`firebase/php-jwt`), not Sanctum — see `App\Services\Auth\AuthService`. Roles (`SuperAdmin`, `Admin`, `User`) are managed separately via `spatie/laravel-permission`.
- **Routing**: all routes live under the `v1` prefix in `routes/api.php`, layered with three middleware tiers — an outer tier that optionally decodes a JWT without rejecting the request, a `JwtVerify` tier that requires a valid token, and `role:` gates for the admin-only routes.
- **Payments**: `App\Services\Payment\PaymentService` resolves a gateway driver by naming convention (`App\Services\Payment\Gateways\{Gateway}Driver`, implementing `pay()`/`verify()`). Payping is the primary gateway.
- **Search**: Typesense/Scout integration exists in `App\Services\Search\ProductSearch` but is currently commented out in favor of a direct MySQL query path.

## License

MIT — see [LICENSE](LICENSE).
