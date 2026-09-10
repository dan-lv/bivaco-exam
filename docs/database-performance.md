# Phân tích yêu cầu 6: query trên 5.000.000 orders

## 1. Query ban đầu và vấn đề

```php
Order::where('user_id', $userId)
    ->where('status', 'completed')
    ->orderBy('created_at', 'desc')
    ->get();
```

Query này có hai nhóm vấn đề độc lập.

### Database phải đọc và sắp xếp quá nhiều dữ liệu

Nếu không có composite index phù hợp, MySQL có thể phải:

1. Quét một lượng lớn records để tìm đúng `user_id` và `status`.
2. Tạo temporary result rồi `filesort` theo `created_at`.
3. Trả toàn bộ records phù hợp về application vì query không có `LIMIT`.

Các index đơn lẻ trên `user_id`, `status` và `created_at` không tương đương một
composite index. MySQL có thể index-merge trong một số trường hợp, nhưng cách đó
không đồng thời giải quyết tốt cả hai equality predicates và thứ tự sort.

`status` thường có cardinality thấp nên index chỉ chứa `status` không đủ chọn lọc.
Đặt nó sau `user_id` trong composite index giúp MySQL thu hẹp đúng tập orders của
một user và một trạng thái trước khi đọc theo thời gian.

### `get()` không có giới hạn

`get()` hydrate tất cả rows thành Eloquent models. Khi lịch sử của một user tăng,
database time, network payload và PHP memory đều tăng theo toàn bộ kết quả. Index
có thể giúp tìm dữ liệu nhanh hơn nhưng không sửa được việc application yêu cầu và
giữ tất cả dữ liệu trong memory.

Vì vậy cần xử lý cả index lẫn pagination; chỉ làm một trong hai là chưa đủ.

## 2. Index được triển khai

Migration `2026_09_09_000004_create_orders_table.php` tạo index:

```php
$table->index(
    ['user_id', 'status', 'created_at', 'id'],
    'orders_user_status_created_id_index'
);
```

Tương đương:

```sql
CREATE INDEX orders_user_status_created_id_index
ON orders (user_id, status, created_at, id);
```

Thứ tự cột được chọn vì:

1. `user_id` và `status` là hai điều kiện equality nên đứng đầu index.
2. `created_at` đứng tiếp theo để MySQL đọc kết quả theo thời gian mà không cần
   `filesort`.
3. `id` là tie-breaker duy nhất khi nhiều orders có cùng `created_at`. Nó làm thứ
   tự ổn định và là phần bắt buộc để cursor pagination không bỏ sót hoặc lặp rows.

Index là B-tree tăng dần nhưng MySQL 8 có thể đọc ngược index cho:

```sql
ORDER BY created_at DESC, id DESC
```

Không cần tạo thêm một index chỉ khác ở chiều `DESC` cho query này.

Migration còn có `(user_id, created_at, id)` và `(status, created_at, id)` cho hai
query shape của API danh sách: customer không truyền status và admin lọc status.
Trade-off là mỗi index chiếm disk/RAM và tăng chi phí `INSERT`/`UPDATE`. Không nên
thêm index cho mọi tổ hợp filter; ba index hiện tại bám theo đúng các query đã được
yêu cầu.

Đây không phải covering index vì query đọc toàn bộ columns của `orders`. Cố nhét
mọi response column vào index sẽ làm index lớn và tăng write amplification. Với
pagination nhỏ, việc InnoDB lookup khoảng 20 rows từ clustered primary key là
trade-off hợp lý.

## 3. Query và EXPLAIN đã sử dụng

Query kiểm tra có cùng predicate và sort với query cần tối ưu, đồng thời có giới
hạn giống một page thực tế:

```sql
EXPLAIN
SELECT *
FROM orders
WHERE user_id = 1
  AND status = 'completed'
ORDER BY created_at DESC, id DESC
LIMIT 20;
```

Kết quả trên MySQL 8.4 local:

| Field | Value |
| --- | --- |
| `id` | `1` |
| `select_type` | `SIMPLE` |
| `table` | `orders` |
| `type` | `ref` |
| `possible_keys` | ba composite indexes của `orders` |
| `key` | `orders_user_status_created_id_index` |
| `key_len` | `90` |
| `ref` | `const,const` |
| `rows` | `1` |
| `filtered` | `100.00` |
| `Extra` | `Backward index scan` |

### Giải thích execution plan

- `select_type = SIMPLE`: query không có subquery hay union.
- `type = ref`: MySQL dùng các giá trị equality không-unique để tìm một range nhỏ
  trong index. Đây tốt hơn `ALL`, vốn biểu thị full table scan.
- `key = orders_user_status_created_id_index`: optimizer đã chọn đúng index dành
  cho query shape này.
- `key_len = 90` và `ref = const,const`: MySQL dùng hai phần đầu của index với hai
  constants `user_id` và `status`; phần còn lại cung cấp thứ tự đọc.
- `Backward index scan`: MySQL đọc B-tree theo chiều ngược để đáp ứng cả
  `created_at DESC, id DESC`.
- Không có `Using filesort`: database không cần một bước sort riêng cho query này.
- `rows` là số rows optimizer ước lượng phải xem, không phải số rows thực tế trả
  về. Fixture local nhỏ nên giá trị `1` chỉ chứng minh access path, không phải bằng
  chứng về hiệu năng trên 5 triệu records.
- `filtered` là phần trăm ước lượng còn lại sau các điều kiện. Cần đọc nó cùng
  `rows`, không xem riêng lẻ.

Trên staging có phân bố dữ liệu gần production, nên chạy thêm:

```sql
EXPLAIN ANALYZE
SELECT *
FROM orders
WHERE user_id = 1
  AND status = 'completed'
ORDER BY created_at DESC, id DESC
LIMIT 20;
```

`EXPLAIN ANALYZE` thực thi query thật và bổ sung `actual time`, `actual rows` và
`loops`. Cần so sánh estimated rows với actual rows. Chênh lệch lớn có thể cho thấy
statistics cũ hoặc data skew; khi đó kiểm tra statistics và cân nhắc
`ANALYZE TABLE orders` trong quy trình vận hành phù hợp.

Không nên tùy tiện chạy `EXPLAIN ANALYZE` trên primary production vì nó thực thi
query. Staging có dataset đại diện hoặc production replica là nơi an toàn hơn.

## 4. Pagination phù hợp

Với query 5 triệu records, lựa chọn ưu tiên là cursor pagination:

```php
Order::query()
    ->where('user_id', $userId)
    ->where('status', 'completed')
    ->orderByDesc('created_at')
    ->orderByDesc('id')
    ->cursorPaginate(20);
```

Page đầu đọc tối đa một page từ đầu range trong index. Các page tiếp theo seek từ
cặp `(created_at, id)` cuối của cursor trước đó, về logic tương đương:

```sql
AND (
    created_at < :cursor_created_at
    OR (created_at = :cursor_created_at AND id < :cursor_id)
)
```

Nó không phải bỏ qua hàng trăm nghìn rows như deep offset.

| Giải pháp | Ưu điểm | Trade-off |
| --- | --- | --- |
| `paginate(20)` | Có số page và total; đúng contract `page`/`meta` | Có count query và deep `OFFSET` ngày càng đắt |
| `simplePaginate(20)` | Bỏ count query | Vẫn dùng offset, không giải quyết deep page |
| `cursorPaginate(20)` | Seek ổn định, chi phí không tăng tuyến tính theo page | Không nhảy tới page bất kỳ và không có exact total |

`GET /api/orders` hiện dùng `paginate()` vì yêu cầu 5 đưa rõ query parameter
`page=...` và response phải có pagination `meta`. Đây là quyết định giữ đúng API
contract. Nếu endpoint lịch sử lớn được phép đổi contract, nên chuyển endpoint đó
sang cursor pagination; không nên âm thầm trả cursor response cho client đang chờ
page number và exact total.

## 5. Tiêu chí verify trên dữ liệu đại diện

Local `EXPLAIN` chỉ xác nhận schema và hướng truy cập. Trước khi kết luận trên dữ
liệu 5 triệu rows cần kiểm tra thêm:

1. `key` vẫn là `orders_user_status_created_id_index`.
2. `type` không rơi về `ALL`.
3. `Extra` không xuất hiện `Using filesort` cho đúng query shape trên.
4. Estimated rows gần actual rows trong `EXPLAIN ANALYZE`.
5. Đo p95 latency, rows examined và database CPU với user có lịch sử lớn, không
   chỉ user trung bình.
6. Chạy ở page đầu và cursor sâu để chứng minh latency không tăng theo offset.

Một user có lượng orders rất lớn vẫn có thể tạo một index range lớn. `LIMIT` và
cursor là phần giới hạn công việc thực tế; composite index một mình không bảo đảm
latency nếu vẫn gọi `get()`.
