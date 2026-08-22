# Cún Chic × Abit

Plugin WordPress/WooCommerce kết nối **cunchici.vn** với **Abit Open API**.

README này là **bản đồ kỹ thuật trung tâm** của plugin. Khi đổi endpoint, mapping, DB schema, file/class responsibility, queue status, sync flow hoặc safety rule thì phải cập nhật README cùng code.

---

## 1. Version hiện tại

```text
0.2.5
```

### 0.2.5 — phục hồi cấu trúc mô tả bị Abit trả thành một dòng

Bản 0.2.4 xử lý tốt mô tả có CR/LF thật bằng `wpautop()`, nhưng một số mô tả marketplace trên Abit đã mất toàn bộ newline và chỉ còn một chuỗi dài.

0.2.5 bổ sung rule **chỉ cho plain text**, không sửa nội dung đã có HTML cấu trúc.

Flow:

```text
source description / short_description
→ normalize CRLF/CR thành LF
→ nếu đã có <p>/<br>/<ul>/<ol>/<li>/<div>/heading/table/blockquote:
     giữ cấu trúc HTML nguồn
→ nếu là plain text:
     nhận diện section heading bảo thủ
     tách repeated " - " thành bullet line
     tách block hashtag khỏi nội dung chính
→ wp_kses_post()
→ wpautop()
→ lưu WooCommerce description / short_description
```

Các heading hiện được nhận diện khi xuất hiện ở đầu đoạn hoặc sau dấu kết thúc câu:

```text
THÔNG TIN SẢN PHẨM
THÔNG TIN CHI TIẾT
MÔ TẢ SẢN PHẨM
HƯỚNG DẪN SỬ DỤNG
HƯỚNG DẪN BẢO QUẢN
CAM KẾT
CHÚ Ý
LƯU Ý
CHÍNH SÁCH ĐỔI TRẢ
CHÍNH SÁCH BẢO HÀNH
```

Ví dụ source một dòng:

```text
... balô của bé. THÔNG TIN SẢN PHẨM - Tên sản phẩm ... - Chất liệu ... CAM KẾT: ... CHÚ Ý ... #tag1 #tag2
```

được cấu trúc lại thành:

```text
... balô của bé.

THÔNG TIN SẢN PHẨM:

- Tên sản phẩm ...
- Chất liệu ...

CAM KẾT:

...

CHÚ Ý:

...

#tag1 #tag2
```

Rule này không cố tách mọi câu và không rewrite câu chữ, để tránh làm hỏng prose bình thường.

### 0.2.4 — import ảnh + newline mô tả

Live CI probe ngày 2026-08-22 đã xác minh:

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

Probe tải trực tiếp 3 URL Shopee CDN thật: cả 3 HTTP 200, `Content-Type: image/jpeg`, magic bytes JPEG hợp lệ.

Kết luận đã áp dụng:

```text
Ảnh:
wp_safe_remote_get() lấy body có giới hạn
→ ghi temp file
→ detect MIME thật
→ filename local có extension đúng
→ media_handle_sideload()
→ featured + gallery

Mô tả có newline thật:
CR/LF
→ normalize
→ sanitize
→ wpautop()
```

Product meta debug ảnh:

```text
_cunchici_abit_last_image_sync
```

### 0.2.2 — active/inactive đã xác minh bằng API thật

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
status=1 → active → syncable
status=0 → inactive → ignored
```

Date-filter probe với range năm 2099 trả 0 rows, nên hiện plugin tiếp tục dùng `date_time_start/date_time_end` cho incremental discovery.

---

## 2. Model sản phẩm

Abit tách biến thể thành sản phẩm đơn:

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

Rule quan trọng:

- `status=1`: active.
- `status=0`: inactive/ignored.
- `imagename`: JSON string; ảnh nằm trong `imgSrc`.
- `default_image`: optional, không dùng làm nguồn ảnh duy nhất.
- `description` / `short_description`: có thể là HTML, plain text có CR/LF, hoặc plain one-line marketplace text.
- Chưa có field màu riêng đã xác minh.
- Chưa có field size riêng đã xác minh.
- `tonkho_toithieu` / `tonkho_toida` không phải current stock.

---

## 4. Abit API

Base:

```text
https://new.abitstore.vn
```

Products:

```http
POST /products/listProductsforPartner
```

Payload hỗ trợ:

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

Stores:

```http
POST /productstore/getStoreidByPartner
```

Products + stock:

```http
POST /products/listProductsWithStockforPartner
```

Cần `productstoreid`. Current stock chưa map cho tới khi xác minh payload thật.

---

## 5. Discovery / Candidate / Incremental

Plugin tách:

```text
DISCOVERY = đọc/upsert candidate từ Abit
SYNC      = ghi candidate vào WooCommerce
```

Candidate table:

```text
{wp_prefix}cunchici_abit_items
```

`abit_product_id` có unique index.

Status:

```text
pending  = active, cần sync hoặc source đổi
synced   = active, đã sync thành công
error    = active, lần sync gần nhất lỗi
ignored  = inactive/status != 1
```

Pending cũ không mất khi incremental ngày sau.

Checkpoint:

```text
cunchici_abit_discovery_state
cunchici_abit_discovery_checkpoint_end
```

Checkpoint chỉ tiến sau khi quét hết pagination. Incremental overlap mặc định `checkpoint - 5 phút`.

Full API có thể đọc 2624 rows nhưng normal syncable catalog là 2579 active.

---

## 6. Sync Run

Run table:

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

Mỗi AJAX xử lý 1 candidate. Reload không auto resume. Không tạo run thứ hai khi còn run queued/running/paused. Một product lỗi không dừng toàn run.

---

## 7. WooCommerce upsert

Tìm product:

```text
1. _cunchici_abit_product_id == productid
2. fallback WooCommerce SKU == productcode
3. chưa có → WC_Product_Simple
```

Không tự convert product type. Duplicate SKU → error.

Sync hiện ghi:

```text
name
regular price / price
SKU
description
short_description
category theo run option
Abit metadata
featured image / gallery
```

Meta chính:

```text
_cunchici_abit_product_id
_cunchici_abit_last_synced_at
_cunchici_abit_modified_time
_cunchici_abit_barcode
_cunchici_abit_ma_goc
```

---

## 8. Mô tả dài / mô tả ngắn

File:

```text
includes/class-cunchici-abit-product-mapper.php
```

Functions:

```text
rich_text()
structure_plain_text()
```

Nguyên tắc:

- HTML có cấu trúc: giữ HTML an toàn, không suy diễn heading/bullet.
- Plain text có newline: giữ newline rồi `wpautop()`.
- Plain one-line: chỉ tách heading đã biết, repeated spaced-dash bullets và first hashtag block.
- Không sửa câu chữ.
- Cả `description` và `short_description` dùng cùng pipeline.

---

## 9. Đồng bộ ảnh

Nguồn:

```text
default_image
imagename[].imgSrc
```

Thứ tự ưu tiên:

```text
1. default_image nếu có
2. imagename isDefault=true
3. các ảnh còn lại
```

Importer:

```text
includes/class-cunchici-abit-media-sync.php
```

Flow:

```text
validate URL
→ wp_safe_remote_get() body, cap 25 MB
→ HTTP + Content-Type
→ temp file
→ wp_get_image_mime()
→ map MIME → extension
→ media_handle_sideload()
→ attachment source meta
→ featured + gallery
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

Safety ảnh:

- Không xóa attachment cũ tự động.
- Không clear featured/gallery nếu remote lỗi hết.
- Một ảnh lỗi, ảnh khác thành công → dùng ảnh thành công.
- Tất cả ảnh lỗi → candidate error; Woo product data và ảnh cũ vẫn giữ.

---

## 10. Danh mục

Run modes:

```text
keep  = giữ category hiện tại
abit  = append category từ productcategory
fixed = append category WooCommerce được chọn
```

Không xóa toàn bộ category cũ.

---

## 11. Màu / Size / Stock

Màu/Size chưa có source field xác minh nên mapper trả rỗng và plugin không xóa attributes Woo hiện có.

Current stock chưa được ghi. Không dùng `tonkho_toithieu/tonkho_toida` làm quantity.

---

## 12. Bản đồ file

### `cunchici-abit.php`
Bootstrap, version, require class, activation/migration wiring.

### `includes/class-cunchici-abit-settings.php`
Base URL, Access Token, Partner Name, Product Store ID, sync limit.

### `includes/class-cunchici-abit-db.php`
Schema candidate + run, indexes, dbDelta.

### `includes/class-cunchici-abit-api.php`
Abit HTTP client; list products/date range, stores, stock endpoint.

### `includes/class-cunchici-abit-product-mapper.php`
Map product fields, category, image source và formatting description/short_description. Nếu text bị dính một dòng → sửa file này đầu tiên.

### `includes/class-cunchici-abit-media-sync.php`
Remote image download, MIME, sideload, dedupe, featured/gallery, debug meta.

### `includes/class-cunchici-abit-sync-repository.php`
Candidate/run DB; pending/synced/error/ignored; filters; queue; server-side guards; progress.

### `includes/class-cunchici-abit-discovery.php`
Full/incremental discovery, pagination, checkpoint, overlap, inactive handling.

### `includes/class-cunchici-abit-product-sync.php`
Woo simple-product upsert, category/meta, Media Sync call, per-item error isolation.

### `includes/class-cunchici-abit-admin.php`
Settings, Sync Center, AJAX controllers, diagnostic.

### `includes/class-cunchici-abit-audit.php`
Read-only catalog reconciliation.

### `assets/admin.js`
Discovery loop, filter/select, Sync Run loop, progress, Pause/Resume/Cancel.

### `assets/admin.css`
Admin UI styles.

### `.github/workflows/lint.yml`
PHP/JS syntax.

### `.github/workflows/abit-audit.yml`
Live catalog/status/date-filter audit dùng Actions Secret.

### `.github/workflows/abit-image-probe.yml`
Read-only image/CDN/description format probe.

### `docs/abit-product-audit-2026-08-22.json`
Verified catalog count/status snapshot.

### `docs/abit-image-probe-2026-08-22.json`
Verified image payload coverage/shape.

### `docs/abit-image-content-probe-2026-08-22.json`
Verified image CDN response + description/short-description format snapshot.

---

## 13. Safety rules

1. Discovery không ghi WooCommerce.
2. Audit/probe không ghi WooCommerce.
3. `status!=1` không được normal sync.
4. Server-side queue chặn `ignored`.
5. Reload không auto resume.
6. Checkpoint không tiến nếu scan chưa hoàn tất.
7. Product lỗi không dừng toàn run.
8. Không xóa Woo product khi Abit không trả về.
9. Không đổi product type tự động.
10. Không đoán current stock.
11. Không xóa attributes khi source không có color/size.
12. Không xóa ảnh Woo nếu remote image rỗng/lỗi.
13. Không tự xóa attachment cũ.
14. Remote image phải qua URL validation + WP safe HTTP.
15. Description formatter không rewrite câu chữ và không suy diễn cấu trúc khi source đã có HTML block.
16. Không commit Access Token.
17. AJAX phải có nonce + `manage_woocommerce`.

---

## 14. Flow test sau release

```text
Update plugin
→ filter một product đã synced
→ re-sync đúng 1 product
→ kiểm tra title/SKU/price
→ kiểm tra description + short_description
→ kiểm tra Media Library / featured / gallery
→ nếu sample đúng mới tăng phạm vi
```

Plugin không tự re-sync toàn catalog khi update version.

---

## 15. Việc còn lại Phase 1

- [x] Audit API total vs Admin.
- [x] Chốt active/inactive.
- [x] Probe date filter.
- [x] Xác minh payload ảnh.
- [x] Probe CDN ảnh thật.
- [x] Import/dedupe featured + gallery.
- [x] Normalize CR/LF description.
- [x] Recover conservative structure cho one-line description.
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

SKU / giá / category / description / short description / parse image source
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
