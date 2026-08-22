# Cún Chic × Abit

Plugin WordPress/WooCommerce kết nối **cunchici.vn** với **Abit Open API**.

README này là bản đồ kỹ thuật trung tâm của plugin. Khi thay đổi endpoint, mapping, DB schema, file/class responsibility hoặc flow đồng bộ thì phải cập nhật README cùng commit.

---

## 1. Version hiện tại

```text
0.2.3
```

### 0.2.3 — đồng bộ hình ảnh

CI đã đọc toàn bộ catalog active thật của Abit ngày 2026-08-22 và xác minh:

```text
Active rows checked       2579
imagename có dữ liệu      2579
default_image có dữ liệu   838
Cả hai rỗng                  0
```

Format thật của `imagename` là **JSON string**, ví dụ:

```json
[
  {
    "id": 1,
    "isDefault": true,
    "imgSrc": "https://cf.shopee.vn/file/..."
  }
]
```

Nhiều URL ảnh là Shopee CDN và **không có đuôi `.jpg/.png`**. Vì vậy plugin không dùng `media_sideload_image()` trực tiếp. `class-cunchici-abit-media-sync.php` tải file tạm, đọc MIME thật rồi mới đưa vào Media Library.

Quy tắc ảnh:

```text
Abit có ảnh hợp lệ
→ tải/reuse attachment trong Media Library
→ ảnh đầu tiên/default → featured image
→ ảnh còn lại → WooCommerce gallery

Abit không có ảnh hoặc CDN lỗi hết
→ giữ nguyên ảnh WooCommerce hiện tại

Ảnh Abit đổi
→ lần re-sync sau cập nhật featured/gallery

Ảnh cũ trong Media Library
→ KHÔNG tự xóa
```

Ảnh được dedupe toàn site bằng hash của source URL. Nhiều SKU Abit dùng cùng một ảnh sẽ reuse cùng attachment thay vì tải lặp.

### 0.2.2 — rule catalog đã xác minh

GitHub Actions live audit thu được:

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

Số 2579 trên Abit Admin khớp chính xác với `status = 1`.

```text
status = 1 → active → được phép sync WooCommerce
status = 0 → inactive → ignored, không được normal sync
```

Snapshot audit:

```text
docs/abit-product-audit-2026-08-22.json
```

CI cũng probe range năm 2099 và nhận 0 rows, nên `date_time_start/date_time_end` hiện có dấu hiệu được endpoint áp dụng thật.

---

## 2. Model sản phẩm

Abit tách biến thể thành các sản phẩm đơn.

```text
1 row Abit
→ 1 WooCommerce simple product
```

Không tạo variable product, không gom SKU thành parent/variation, không tự đoán quan hệ cha/con.

---

## 3. Payload list product đã xác minh

Các field thật đã thấy:

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

Quan trọng:

- `status=1` là active.
- `status=0` là inactive/ignored.
- API list chưa có field màu riêng.
- API list chưa có field size riêng.
- `tonkho_toithieu` / `tonkho_toida` không phải current stock.
- `imagename` là JSON string chứa một hoặc nhiều object ảnh.
- `default_image` là optional; không được phụ thuộc vào field này vì chỉ 838/2579 active rows có giá trị.

---

## 4. Abit API

Base URL:

```text
https://new.abitstore.vn
```

### Danh sách sản phẩm

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

### Danh sách kho

```http
POST /productstore/getStoreidByPartner
```

### Sản phẩm + tồn kho

```http
POST /products/listProductsWithStockforPartner
```

Cần `productstoreid`.

---

## 5. Discovery và incremental

Plugin tách hai khái niệm:

```text
DISCOVERY = đã đọc/ghi candidate từ Abit
SYNC      = đã ghi candidate vào WooCommerce
```

Candidate chưa sync không mất khi sang ngày mới.

Ví dụ:

```text
Ngày 1 discovery 500 active
sync 100
→ 100 synced + 400 pending

Ngày 2 Abit thêm 10 active
incremental discovery
→ 100 synced + 410 pending
```

### Checkpoint

```text
cunchici_abit_discovery_state
cunchici_abit_discovery_checkpoint_end
```

Checkpoint chỉ update khi quét hết pagination của range. Reload/lỗi giữa chừng không nhảy checkpoint.

Range incremental mặc định bắt đầu:

```text
checkpoint - 5 phút
```

Candidate upsert bằng `productid`, nên overlap không tạo duplicate.

Full scan có thể đọc 2624 API rows nhưng 45 inactive được ignored; normal syncable catalog là 2579 active.

---

## 6. Candidate Queue

Table:

```text
{wp_prefix}cunchici_abit_items
```

`abit_product_id` unique.

Status:

```text
pending  = active, cần sync / source đổi
synced   = active, đã sync thành công
error    = active, lần sync gần nhất lỗi
ignored  = inactive, không được Sync Run
```

Nếu một `ignored` product được Abit bật lại `status=1`, discovery đưa nó về `pending`.

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

Mỗi AJAX xử lý 1 product, nên progress là:

```text
processed / total × 100
```

Reload không auto resume. Admin phải chủ động Resume.

Product lỗi không dừng toàn bộ run.

---

## 8. WooCommerce upsert

Tìm product theo thứ tự:

```text
1. _cunchici_abit_product_id == productid
2. WooCommerce SKU == productcode
3. chưa có → WC_Product_Simple mới
```

Không tự convert product type. Duplicate SKU → error.

Meta chính:

```text
_cunchici_abit_product_id
_cunchici_abit_last_synced_at
_cunchici_abit_modified_time
_cunchici_abit_barcode
_cunchici_abit_ma_goc
```

---

## 9. Đồng bộ ảnh — 0.2.3

### Nguồn ảnh

Mapper đọc:

```text
imagename[].imgSrc
default_image
```

Thứ tự ưu tiên:

1. `default_image` nếu có;
2. object `imagename` có `isDefault=true`;
3. các ảnh còn lại.

URL được unique trước khi import.

### Import Media Library

File:

```text
includes/class-cunchici-abit-media-sync.php
```

Flow:

```text
source URL
→ wp_safe_remote_get() stream vào temp file
→ giới hạn 25 MB
→ đọc MIME thật bằng wp_get_image_mime()
→ tạo filename có extension thật
→ media_handle_sideload()
→ lưu source URL/hash vào attachment meta
→ set featured/gallery cho Woo product
```

Các MIME hỗ trợ tùy WordPress site cho phép:

```text
JPEG
PNG
GIF
WebP
AVIF (nếu WordPress/site cho phép)
```

### Dedupe

Attachment meta:

```text
_cunchici_abit_source_image_hash
_cunchici_abit_source_image_url
_cunchici_abit_image
```

Product meta:

```text
_cunchici_abit_image_set_hash
_cunchici_abit_image_ids
```

Khi re-sync cùng URL, plugin reuse attachment cũ, không tải lại.

### Safety

- Không xóa Media Library attachment cũ.
- Không clear featured/gallery nếu Abit không có URL hợp lệ.
- Nếu một ảnh lỗi nhưng ảnh khác tải được, product vẫn sync và dùng các ảnh thành công.
- Nếu tất cả ảnh lỗi, product data vẫn sync nhưng giữ ảnh WooCommerce cũ.
- URL phải qua `wp_http_validate_url()` và `wp_safe_remote_get()`.

### Sản phẩm đã sync trước 0.2.3

Plugin **không tự chạy lại toàn catalog khi update**.

Để bổ sung ảnh cho product đã synced:

```text
Sync Center
→ filter “Đã đồng bộ”
→ chọn 1 vài sản phẩm để test trước
→ Đồng bộ sản phẩm đã chọn
→ kiểm tra featured/gallery
→ sau đó mới re-sync toàn bộ nhóm Đã đồng bộ nếu đúng
```

Dedupe giúp các SKU dùng chung source image không tạo attachment lặp.

---

## 10. Danh mục

Mode Sync Run:

```text
keep  = không thay đổi category
abit  = append category từ productcategory
fixed = append category WooCommerce được chọn
```

Không xóa category quản trị thủ công.

---

## 11. Màu / Size / Stock

### Màu / Size

Chưa có field nguồn xác minh nên mapper trả rỗng.

```text
không map được
→ không gọi set_attributes([])
→ không xóa attributes hiện có
```

### Stock

Chưa ghi current stock cho tới khi xác minh payload thật của:

```http
POST /products/listProductsWithStockforPartner
```

Không dùng min/max stock làm current quantity.

---

## 12. Bản đồ file

### `cunchici-abit.php`
Bootstrap, version, require class, activation/migration wiring.

### `includes/class-cunchici-abit-settings.php`
Token, Partner Name, Product Store ID, API limit, base URL.

### `includes/class-cunchici-abit-db.php`
Schema queue/run.

### `includes/class-cunchici-abit-api.php`
Abit HTTP client, list products, date range, stores, stock API.

### `includes/class-cunchici-abit-product-mapper.php`
Map field sản phẩm, category, image payload normalization.

### `includes/class-cunchici-abit-media-sync.php`
Download/dedupe/import Media Library, featured image, gallery. Nếu lỗi ảnh → sửa file này đầu tiên.

### `includes/class-cunchici-abit-sync-repository.php`
Candidate/run DB, pending/synced/error/ignored, filters, queue, progress.

### `includes/class-cunchici-abit-discovery.php`
Full/incremental discovery, pagination, checkpoint, overlap, inactive handling.

### `includes/class-cunchici-abit-product-sync.php`
Woo product upsert, category, metadata, gọi Media Sync.

### `includes/class-cunchici-abit-admin.php`
Settings, Sync Center, AJAX controllers, diagnostic.

### `includes/class-cunchici-abit-audit.php`
Read-only API reconciliation.

### `assets/admin.js`
Sync Center AJAX flow, filters, selection, progress, Pause/Resume/Cancel.

### `assets/admin.css`
Admin UI styles.

### `.github/workflows/lint.yml`
PHP/JS syntax checks.

### `.github/workflows/abit-audit.yml`
Live catalog audit bằng `ABIT_ACCESS_TOKEN` repository secret.

### `.github/workflows/abit-image-probe.yml`
Read-only live probe cho `imagename/default_image` format và coverage.

### `docs/abit-product-audit-2026-08-22.json`
Snapshot audit catalog/status.

---

## 13. Safety rules

1. Discovery không ghi WooCommerce.
2. Audit/probe không ghi WooCommerce.
3. `status!=1` không được normal sync.
4. Server-side queue phải chặn `ignored`.
5. Reload không auto resume.
6. Checkpoint không update khi scan chưa hoàn tất.
7. Product lỗi không dừng toàn run.
8. Không xóa Woo product khi Abit không trả về.
9. Không đổi product type tự động.
10. Không đoán current stock.
11. Không xóa attributes khi source không có color/size.
12. Không xóa ảnh Woo nếu source image rỗng/lỗi.
13. Không tự xóa attachment cũ trong Media Library.
14. Không commit Access Token.
15. AJAX phải có nonce + `manage_woocommerce`.

---

## 14. Flow vận hành đề xuất

### Lần đầu / product mới

```text
Discovery
→ xem Candidate List
→ chọn vài product test
→ Sync
→ kiểm tra name/SKU/price/category/image
→ tăng phạm vi sync
```

### Hàng ngày

```text
Incremental discovery từ checkpoint
→ active mới/thay đổi thành pending
→ pending cũ vẫn còn
→ sync selected/filtered
```

### Sau khi update 0.2.3 để bổ sung ảnh cũ

```text
Filter “Đã đồng bộ”
→ re-sync 1-5 product trước
→ kiểm tra featured/gallery + Media Library
→ nếu đúng mới re-sync toàn bộ nhóm synced
```

---

## 15. Việc còn lại Phase 1

- [x] Audit API total vs Admin.
- [x] Chốt active/inactive.
- [x] Probe date filter.
- [x] Xác minh payload ảnh.
- [x] Import/dedupe featured image + gallery.
- [ ] Xác minh payload tồn kho.
- [ ] Map current stock.
- [ ] Xác định nguồn màu.
- [ ] Xác định nguồn size.
- [ ] Category hierarchy nếu cần.
- [ ] Retry/backoff API 429/5xx.

---

## 16. Khi cần sửa gì thì vào đâu?

```text
Token / Partner / Kho / Limit
→ class-cunchici-abit-settings.php
→ class-cunchici-abit-admin.php

Endpoint / request / date range
→ class-cunchici-abit-api.php

SKU / giá / category / image source parsing
→ class-cunchici-abit-product-mapper.php

Download ảnh / MIME / Media Library / featured-gallery / dedupe ảnh
→ class-cunchici-abit-media-sync.php

Checkpoint / incremental / scan
→ class-cunchici-abit-discovery.php

Candidate / ignored / queue / run DB
→ class-cunchici-abit-sync-repository.php

Create/update Woo product
→ class-cunchici-abit-product-sync.php

Admin UI / AJAX
→ class-cunchici-abit-admin.php
→ assets/admin.js
```

> README phải mô tả code đang chạy, không mô tả ý tưởng chưa triển khai.
