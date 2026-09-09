# Database performance notes

## Slow order query

The original query has two independent scaling problems:

    Order::where('user_id', $userId)
        ->where('status', 'completed')
        ->orderBy('created_at', 'desc')
        ->get();

The get call materializes every matching row, so response time and PHP memory grow
with the complete order history. Without an index matching both equality predicates
and the sort, MySQL must also inspect unrelated rows and can perform a filesort.

The orders_user_status_created_id_index index is defined as:

    (user_id, status, created_at, id)

Equality columns come first, followed by the deterministic sort columns. MySQL can
scan this B-tree backwards for DESC; id resolves ties between equal timestamps. The
index is not described as covering because the API selects order columns that are
not all stored in it.

## Inspecting the execution plan

Run this against a representative staging-sized dataset, substituting an existing
user ID:

    EXPLAIN
    SELECT *
    FROM orders
    WHERE user_id = 1
      AND status = 'completed'
    ORDER BY created_at DESC, id DESC
    LIMIT 20;

Then use EXPLAIN ANALYZE when it is acceptable to execute the read:

    EXPLAIN ANALYZE
    SELECT *
    FROM orders
    WHERE user_id = 1
      AND status = 'completed'
    ORDER BY created_at DESC, id DESC
    LIMIT 20;

The important fields are:

- key: should select orders_user_status_created_id_index.
- type: ref or range is preferable to ALL.
- rows: should be limited to the selected user's completed orders.
- Extra: should not report Using filesort for this exact query shape.
- actual time and rows: compare estimates with actual work and refresh statistics
  if they differ significantly.

The local MySQL 8.4 verification selected:

    type: ref
    key: orders_user_status_created_id_index
    Extra: Backward index scan

The local rows estimate is not treated as production evidence because the fixture
is deliberately small; the selected access path and absence of filesort are the
relevant checks here.

EXPLAIN ANALYZE executes the query. Run it first on staging or a production replica,
not casually against the five-million-row primary.

## Pagination choice

For the large query, use cursor pagination:

    Order::query()
        ->where('user_id', $userId)
        ->where('status', 'completed')
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->cursorPaginate(20);

Cursor pagination seeks from the last (created_at, id) pair and avoids the growing
scan cost of deep offsets. Its trade-off is that clients cannot jump to an arbitrary
page or receive an exact total without another count query.

GET /api/orders keeps length-aware pagination because the requested contract uses
page and requires pagination metadata in the response.

## Stale product update

The products:deactivate-stale command selects only product IDs with chunkById and
performs one conditional bulk update per chunk. The condition is re-applied during
the update, so a completed order committed between candidate selection and update
is observed before changing the product status. It never calls Product::all and
does not run one save query per product.

For this assessment, a sale means an order whose current status is completed. The
sale timestamp is orders.created_at because no order-completion endpoint or
completed_at field is required by the test.
