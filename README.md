# Cún Chic × Abit

Plugin WordPress/WooCommerce kết nối **cunchici.vn** với **Abit Open API**.

README này là **bản đồ kỹ thuật trung tâm** của plugin. Khi đổi endpoint, mapping, DB schema, file/class responsibility, queue status, sync flow hoặc safety rule thì phải cập nhật README cùng code.

---

## 1. Version hiện tại

```text
0.2.4
```

### 0.2.4 — sửa import ảnh + giữ xuống dòng mô tả

Live CI probe ngày 2026-08-22 đã xác minh trực tiếp catalog thật:

```text
Active rows checked                2579
imagename có dữ liệu               2579
default_image có dữ liệu            838
Cả hai rỗng                           0

description có dữ liệu             2567
description có CR/LF               1899
description có <br>                   0
description có <p>                  372

short_description có dữ liệu       2567
short_description có CR/LF         1899
short_description có <br>             0
short_description có <p>            372
```

Probe tải trực tiếp 3 URL ảnh Shopee CDN thật và cả 3 đều trả:

```text
HTTP 200
Content-Type: image/jpeg
JPEG magic bytes: FF D8 FF E0 ...
```

Kết luận:

- Abit/Shopee CDN có ảnh hợp lệ; lỗi 0.2.3 nằm ở cách importer WordPress stream trực tiếp vào temp file trên một số hosting.
- 1.899 sản phẩm dùng newline thuần trong mô tả, nên chỉ `wp_kses_post()` không tạo xuống dòng HTML nhìn thấy được.

0.2.4 đổi thành:

```text
Ảnh:
wp_safe_remote_get() lấy body có giới hạn
→ ghi body vào temp file
→ wp_get_image_mime() đọc MIME thật
→ đặt filename local có extension đúng
→ media_handle_sideload()
→ featured image + gallery

Mô tả:
CR/LF Abit
→ normalize newline
→ wp_kses_post()
→ wpautop()
→ paragraph / line break hợp lệ trong WooCommerce
```

Product meta debug ảnh mới:

```text
_cunchici_abit_last_image_sync
```

Meta này lưu JSON `total/imported/reused/applied/warnings/synced_at` để biết chính xác lần import ảnh gần nhất thành công hay lỗi ở bước nào.

### 0.2.3 — nguồn ảnh đã xác minh

`imagename` là JSON string, ví dụ:

```json
[
  {
    "id": 1,
    "isDefault": true,
    "imgSrc": "https://cf.shopee.vn/file/..."
  }
]
```

Nhiều URL không có `.jpg/.png`; vì vậy không dùng `media_sideload_image()` trực tiếp theo extension URL.

### 0.2.2 — rule active/inactive đã xác minh

Live audit catalog:

```text
API total rows             2624
Unique productid           2624
status = 1                 2579
status = 0                   45
Duplicate productid groups    0
Duplicate SKU groups          0
Empty productid               0
Empty SKU                     0
```

Số 2579 trên Abit Admin khớp chính xác `status=1`.

```text
status=1 → active → được phép sync WooCommerce
status=0 → inactive → ignored, không được normal sync
```

Date-filter probe với range năm 2099 trả 0 rows, nên hiện có thể dùng `date_time_start/date_time_end` cho incremental discovery.

---

## 2. Model sản phẩm

Abit tách biến thể thành sản phẩm đơn:

```text
1 row Abit
→ 1 WooCommerce simple product
```

Plugin không tạo variable product, không gom SKU thành parent/variation và không tự đoán quan hệ cha/con.

---

## 3. Payload list product đã xác minh

Các field đã thấy trên API thật:

```text
productcode
productname
alias
usageunit
gia_daily
unit_price
imagename
productid
weight
taxpercentage
vitri
inventorytype
createdtime
modifiedtime
status
tonkho_toithieu
tonkho_toida
default_image
short_description
description
productcategory
brandid
brandname
ma_ke_toan
ma_goc
barcode
created_at
updated_at
```

Rule quan trọng:

- `status=1`: active.
- `status=0`: inactive/ignored.
- `imagename`: JSON string chứa ảnh, `imgSrc` là URL nguồn.
- `default_image`: optional, không thể dùng làm nguồn ảnh duy nhất.
- `description` / `short_description`: có thể là HTML hoặc plain text chứa CR/LF.
- Chưa có field màu riêng đã xác minh.
- Chưa có field size riêng đã xác minh.
- `tonkho_toithieu` / `tonkho_toida` không phải current stock.

---

## 4. Abit API

Base URL:

```text
https://new.abitstore.vn
```

### Products

```http
POST /products/listProductsforPartner
```

Request hỗ trợ:

```json
{
  "access_token": "...",
  "partner_name": "...",
  "page": 0,
  "limit": 100,
  "date_time_start": "2026-08-18 00:00:00",
  "date_time_end": "2026-08-22 23:59:59"
}
```

### Stores

```http
POST /productstore/getStoreidByPartner
```

### Products + stock

```http
POST /products/listProductsWithStockforPartner
```

Cần `productstoreid`. Current stock chưa được map cho tới khi xác minh payload thật.

---

## 5. Discovery và incremental

Plugin tách:

```text
DISCOVERY = đã đọc/upsert candidate từ Abit
SYNC      = đã ghi candidate vào WooCommerce
```

Ví dụ:

```text
Ngày 1 discovery 500 active
sync 100
→ 100 synced + 400 pending

Ngày 2 Abit thêm 10 active
incremental discovery
→ 100 synced + 410 pending
```

Pending cũ không mất khi sang ngày mới.

Checkpoint options:

```text
cunchici_abit_discovery_state
cunchici_abit_discovery_checkpoint_end
```

Checkpoint chỉ tiến sau khi quét hết pagination của range. Reload/lỗi giữa chừng không nhảy checkpoint.

Incremental mặc định overlap:

```text
checkpoint - 5 phút
```

Candidate unique theo `productid`, nên overlap không tạo duplicate.

Full scan có thể đọc 2624 API rows nhưng 45 `status=0` bị ignored; normal syncable catalog là 2579 active.

---

## 6. Candidate Queue

Table:

```text
{wp_prefix}cunchici_abit_items
```

`abit_product_id` có unique index.

Status:

```text
pending  = active, cần sync / source đổi
synced   = active, đã sync thành công
error    = active, lần sync gần nhất lỗi
ignored  = inactive, không được Sync Run
```

Nếu Abit đổi `status=0 → 1`, discovery đưa candidate `ignored → pending`.

---

## 7. Sync Run

Table:

```text
{wp_prefix}cunchici_abit_runs
```

Status:

```text
queued
running
paused
completed
cancelled
```

Mỗi AJAX xử lý 1 candidate.

```text
percent = processed / total × 100
```

Reload không auto resume. Không cho tạo run thứ hai khi còn run queued/running/paused. Một product lỗi không dừng toàn run.

---

## 8. WooCommerce upsert

Tìm product theo thứ tự:

```text
1. _cunchici_abit_product_id == productid
2. WooCommerce SKU == productcode
3. chưa có → WC_Product_Simple mới
```

Không tự convert product type. SKU đã thuộc product khác → error.

Meta chính:

```text
_cunchici_abit_product_id
_cunchici_abit_last_synced_at
_cunchici_abit_modified_time
_cunchici_abit_barcode
_cunchici_abit_ma_goc
```

---

## 9. Mô tả dài / mô tả ngắn — 0.2.4

File chịu trách nhiệm:

```text
includes/class-cunchici-abit-product-mapper.php
```

`rich_text()` xử lý cả hai trường:

```text
description
short_description
```

Flow:

```text
source string
→ CRLF/CR thành LF
→ giảm chuỗi blank line quá dài
→ wp_kses_post()
→ wpautop()
```

Mục tiêu:

- plain text có newline từ Abit hiển thị thành paragraph/line break;
- HTML an toàn có sẵn vẫn được giữ;
- không tự suy diễn thêm nội dung nếu source không có newline/markup.

---

## 10. Đồng bộ ảnh — 0.2.4

### Nguồn ảnh

Mapper đọc:

```text
default_image
imagename[].imgSrc
```

Thứ tự:

1. `default_image` nếu có;
2. `imagename` có `isDefault=true`;
3. các ảnh còn lại.

URL unique trước khi import.

### Import Media Library

File:

```text
includes/class-cunchici-abit-media-sync.php
```

Flow 0.2.4:

```text
source URL
→ validate http/https public URL
→ wp_safe_remote_get() lấy body, cap 25 MB
→ kiểm tra HTTP status + Content-Type
→ ghi body vào temp file
→ wp_get_image_mime() detect file thật
→ map MIME → extension hợp lệ
→ media_handle_sideload()
→ attachment meta source URL/hash
→ featured image + gallery
```

Dedupe attachment toàn site bằng source URL hash:

```text
_cunchici_abit_source_image_hash
_cunchici_abit_source_image_url
_cunchici_abit_image
```

Product image meta:

```text
_cunchici_abit_image_set_hash
_cunchici_abit_image_ids
_cunchici_abit_last_image_sync
```

Safety:

- Không tự xóa Media Library attachment cũ.
- Không clear featured/gallery nếu remote image lỗi hết.
- Một ảnh lỗi nhưng ảnh khác thành công → dùng ảnh thành công.
- Tất cả ảnh lỗi → candidate thành `error`, Woo product data đã lưu vẫn giữ và ảnh Woo cũ không bị xóa.
- Error message phân biệt WordPress HTTP / HTTP status / body rỗng / MIME / Media Library sideload.

---

## 11. Danh mục

Sync Run modes:

```text
keep  = không thay đổi category
abit  = append category từ productcategory
fixed = append category WooCommerce được chọn
```

Không xóa category quản trị thủ công.

---

## 12. Màu / Size / Stock

### Màu / Size

Chưa có source field xác minh:

```text
không map được
→ không gọi set_attributes([])
→ không xóa attributes hiện có
```

### Stock

Chưa ghi current stock. Không dùng `tonkho_toithieu/tonkho_toida` làm số lượng hiện tại.

---

## 13. Bản đồ file

### `cunchici-abit.php`
Bootstrap, version, require class, activation/migration wiring.

### `includes/class-cunchici-abit-settings.php`
`base_url`, Access Token, Partner Name, Product Store ID, sync limit.

### `includes/class-cunchici-abit-db.php`
Schema candidate/run và dbDelta migration.

### `includes/class-cunchici-abit-api.php`
HTTP client Abit, products/date range, stores, stock endpoint.

### `includes/class-cunchici-abit-product-mapper.php`
Map product fields; normalize category; parse `imagename`; normalize long/short description bằng `rich_text()`.

### `includes/class-cunchici-abit-media-sync.php`
Download ảnh, MIME detect, Media Library sideload, dedupe, featured/gallery, image debug meta. Nếu ảnh lỗi → kiểm tra file này đầu tiên.

### `includes/class-cunchici-abit-sync-repository.php`
Candidate/run DB, pending/synced/error/ignored, filters, queue, server-side guards, progress.

### `includes/class-cunchici-abit-discovery.php`
Full/incremental discovery, pagination, checkpoint, overlap, inactive handling.

### `includes/class-cunchici-abit-product-sync.php`
Simple product upsert, description/price/SKU/category/meta, gọi Media Sync, image failure → candidate error.

### `includes/class-cunchici-abit-admin.php`
Settings, Sync Center markup, AJAX controllers, diagnostic.

### `includes/class-cunchici-abit-audit.php`
Read-only catalog reconciliation.

### `assets/admin.js`
Discovery loop, candidate filter/select, run loop, progress, Pause/Resume/Cancel.

### `assets/admin.css`
Admin styles.

### `.github/workflows/lint.yml`
PHP/JS syntax checks.

### `.github/workflows/abit-audit.yml`
Live catalog/status/date-filter audit bằng repository secret.

### `.github/workflows/abit-image-probe.yml`
Live read-only probe cho image payload, CDN response và description line-break format.

### `docs/abit-product-audit-2026-08-22.json`
Snapshot verified catalog count/status.

### `docs/abit-image-probe-2026-08-22.json`
Snapshot verified image payload format/coverage.

---

## 14. Safety rules

1. Discovery không ghi WooCommerce.
2. Audit/probe không ghi WooCommerce.
3. `status!=1` không được normal sync.
4. Server-side queue chặn `ignored`.
5. Reload không auto resume.
6. Checkpoint không update nếu scan chưa hoàn tất.
7. Product lỗi không dừng toàn run.
8. Không xóa Woo product khi Abit không trả về.
9. Không đổi product type tự động.
10. Không đoán current stock.
11. Không xóa attributes khi source không có color/size.
12. Không xóa ảnh Woo nếu source image rỗng/lỗi.
13. Không tự xóa attachment cũ.
14. Remote image phải qua URL validation + WP safe HTTP.
15. Không commit Access Token.
16. AJAX phải có nonce + `manage_woocommerce`.

---

## 15. Flow test sau release

Sau mỗi thay đổi mapping/importer:

```text
Update plugin
→ filter một product đã synced
→ re-sync đúng 1 product
→ kiểm tra title/SKU/price
→ kiểm tra mô tả dài + ngắn xuống dòng
→ kiểm tra Media Library
→ kiểm tra featured image + gallery
→ nếu lỗi, đọc candidate last_error và _cunchici_abit_last_image_sync
→ chỉ tăng phạm vi sau khi sample đúng
```

Plugin không tự re-sync toàn catalog khi update version.

---

## 16. Việc còn lại Phase 1

- [x] Audit API total vs Admin.
- [x] Chốt active/inactive.
- [x] Probe date filter.
- [x] Xác minh payload ảnh.
- [x] Probe CDN ảnh thật.
- [x] Import/dedupe featured + gallery.
- [x] Normalize description line breaks.
- [ ] Xác minh payload tồn kho.
- [ ] Map current stock.
- [ ] Xác định nguồn màu.
- [ ] Xác định nguồn size.
- [ ] Category hierarchy nếu cần.
- [ ] Retry/backoff API 429/5xx.

---

## 17. Khi cần sửa gì thì vào đâu?

```text
Token / Partner / Kho / Limit
→ class-cunchici-abit-settings.php
→ class-cunchici-abit-admin.php

Endpoint / request / date range
→ class-cunchici-abit-api.php

SKU / giá / category / description / parse image source
→ class-cunchici-abit-product-mapper.php

Download ảnh / HTTP / MIME / Media Library / featured-gallery / dedupe
→ class-cunchici-abit-media-sync.php

Checkpoint / incremental / scan
→ class-cunchici-abit-discovery.php

Candidate / ignored / queue / run DB
→ class-cunchici-abit-sync-repository.php

Create/update Woo product / gọi image sync
→ class-cunchici-abit-product-sync.php

Admin UI / AJAX
→ class-cunchici-abit-admin.php
→ assets/admin.js
```

> README phải mô tả code đang chạy, không mô tả ý tưởng chưa triển khai.
