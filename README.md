# Laravel Order Management Assessment

Laravel 12 / PHP 8.3 implementation of the requested ecommerce product and order
APIs. The local stack uses MySQL 8.4 and Redis 7 through Docker Compose.

## Run locally

Build the application image and start its dependencies:

```bash
docker compose build
docker compose up -d mysql redis
```

Create the schema and demo records, then start the API on
`http://localhost:8000`:

```bash
docker compose run --rm app php artisan migrate:fresh --seed
docker compose up -d app
```

`migrate:fresh` deletes existing tables. Use `php artisan migrate --seed` when
the database must be preserved.

The seeder creates these users with password `password`:

- `admin@example.com` (`admin`)
- `customer@example.com` (`customer`)

Authentication uses Sanctum bearer tokens. A login endpoint is intentionally
outside the assessment scope. Generate a demo token with:

```bash
docker compose run --rm app php artisan tinker --execute="echo App\\Models\\User::where('email', 'admin@example.com')->firstOrFail()->createToken('demo')->plainTextToken.PHP_EOL;"
```

Pass the result as `Authorization: Bearer <token>`.

## API surface

All routes are authenticated and limited through Redis to 100 requests per
minute per user.

| Method | Endpoint | Access |
| --- | --- | --- |
| `POST` | `/api/products` | Admin |
| `GET` | `/api/products` | Authenticated user |
| `GET` | `/api/products/{id}` | Authenticated user |
| `PUT` | `/api/products/{id}` | Admin |
| `DELETE` | `/api/products/{id}` | Admin |
| `POST` | `/api/orders` | Authenticated user |
| `GET` | `/api/orders` | Customer: own orders; admin: all orders |

Example order payload:

```json
{
  "warehouse_id": 1,
  "items": [
    {"product_id": 1, "quantity": 2},
    {"product_id": 2, "quantity": 1}
  ]
}
```

The order list accepts `page`, `per_page`, `status`, `keyword`, `from_date`, and
`to_date`. Dates use `YYYY-MM-DD`; the response contains Laravel Resource
`data`, `links`, and `meta` fields.

Expected error contract:

- `401` unauthenticated
- `403` unauthorized product mutation
- `422` invalid request shape or referenced resource at validation time
- `409` inventory/resource state changed or is insufficient during transaction
- `429` Redis rate limit exceeded

## Large-data tasks

Deactivate products without a completed order in the last two years:

```bash
docker compose run --rm app php artisan products:deactivate-stale
```

Use `--before=YYYY-MM-DD` and `--chunk=1000` to control the cutoff and batch
size. It selects only IDs in chunks and performs conditional bulk updates, so it
does not materialize one million products or issue one update per model.

The slow-query analysis, composite index rationale, real local `EXPLAIN` result,
and offset-versus-cursor pagination trade-off are in
[`docs/database-performance.md`](docs/database-performance.md).

## Tests

The feature suite is forced onto an isolated MySQL database and isolated Redis
DBs. It includes two-process concurrency coverage for the stock-equals-one case,
after-commit/rollback event coverage, authorization, validation, filtering,
pagination, batch processing, and Redis throttling.

```bash
docker compose run --rm app-test
```
