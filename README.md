# Cún Chic × Abit

Plugin WordPress/WooCommerce kết nối **cunchici.vn** với **Abit Open API**.

README này là bản đồ kỹ thuật trung tâm của plugin. Khi thay đổi endpoint, mapping, DB schema, file/class responsibility hoặc flow đồng bộ thì phải cập nhật README cùng commit.

---

## 1. Version hiện tại

```text
0.2.2
```

### 0.2.2 — rule catalog đã xác minh bằng CI thật

Ngày 2026-08-22, GitHub Actions đã gọi trực tiếp Abit API bằng credential thật được lưu trong Actions Secret và thu được:

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

Số **2579** trên Abit Admin khớp **chính xác** với số record `status = 1`.

Kết luận nghiệp vụ:

```text
status = 1 → active → được phép vào Candidate Queue để sync
status = 0 → inactive → giữ lại để audit nhưng KHÔNG được sync WooCommerce
```

Kết quả audit tổng hợp được lưu tại:

```text
docs/abit-product-audit-2026-08-22.json
```

Version 0.2.2 thêm trạng thái nội bộ:

```text
ignored
```

cho sản phẩm Abit inactive. Khi update từ 0.2.1, plugin chạy migration một lần để chuyển các candidate cũ có payload `status != 1` sang `ignored`. Không xóa WooCommerce product nào.

Nếu Abit kích hoạt lại sản phẩm thành `status = 1`, lần discovery sau sẽ đưa candidate đó trở lại `pending`.

### Date filter đã được probe

CI gọi API với range tương lai:

```text
date_time_start = 2099-01-01 00:00:00
date_time_end   = 2099-01-02 23:59:59
```

Kết quả:

```text
0 rows
```

Do đó `date_time_start/date_time_end` **có dấu hiệu được endpoint áp dụng thật** và hiện có thể tiếp tục dùng cho incremental discovery. Nếu Abit thay đổi behavior sau này thì chạy lại audit CI.

---

## 2. Quy tắc sản phẩm

Abit đang tách biến thể thành các sản phẩm đơn.

Plugin giữ nguyên:

```text
1 row Abit
→ 1 WooCommerce simple product
```

Không tạo variable product, không gom SKU thành parent/variation và không tự đoán quan hệ cha/con.

---

## 3. Payload list product đã xác minh

Endpoint thật đã thấy các field:

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

- `status = 1` là nhóm active trùng với tổng sản phẩm Abit Admin.
- `status = 0` là inactive và bị loại khỏi normal sync.
- API list không có field màu riêng.
- API list không có field size riêng.
- `tonkho_toithieu` / `tonkho_toida` không phải current stock.
- Không xóa WooCommerce attributes khi source không có màu/size.

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

Payload plugin hỗ trợ:

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

Hai field ngày chỉ gửi khi có giá trị.

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

## 5. Discovery và incremental sync

Plugin tách:

```text
DISCOVERY = đã lấy/nhìn thấy sản phẩm từ Abit
SYNC      = đã ghi sản phẩm vào WooCommerce
```

Ví dụ ngày đầu:

```text
Abit active có 2579
Discovery đủ 2579 active
Admin sync 100
→ 100 synced
→ 2479 pending
```

Ngày sau Abit thêm 10 active product:

```text
Incremental discovery từ checkpoint đến hiện tại
→ thêm 10 pending
→ 100 synced
→ 2489 pending
```

Các pending cũ không biến mất.

### Checkpoint

Options:

```text
cunchici_abit_discovery_state
cunchici_abit_discovery_checkpoint_end
```

Checkpoint chỉ update sau khi quét hết pagination của range.

Nếu quét dở/reload/lỗi API thì checkpoint không tiến.

### Overlap

Range incremental mặc định bắt đầu:

```text
checkpoint - 5 phút
```

để tránh mất record ở biên thời gian. Candidate upsert bằng `productid`, nên overlap không tạo duplicate.

### Progress discovery từ 0.2.2

UI phân biệt:

```text
API rows
Mới active
Thay đổi
Không đổi
Bỏ qua inactive
```

Do đó full scan có thể đọc 2624 API rows nhưng normal candidate active vẫn chỉ là 2579.

---

## 6. Candidate Queue

Table:

```text
{wp_prefix}cunchici_abit_items
```

`abit_product_id` có unique index.

Status:

```text
pending  = active, cần sync hoặc source đã thay đổi
synced   = active, đã sync thành công
error    = active, lần sync gần nhất lỗi
ignored  = Abit inactive/status != 1, không được queue để sync
```

Normal list/filter "Tất cả" của Sync Center **không bao gồm `ignored`**.

`create_sync_run()` và `next_queued_item()` cũng có server-side guard để `ignored` không thể bị sync ngay cả khi request bị gọi thủ công.

Nếu payload active thay đổi thì candidate quay lại `pending`.

---

## 7. Sync Run

Table:

```text
{wp_prefix}cunchici_abit_runs
```

Status run:

```text
queued
running
paused
completed
cancelled
```

Mỗi AJAX xử lý đúng 1 candidate.

Progress:

```text
percent = processed / total * 100
```

UI hiển thị SKU/tên đang xử lý, %, success, failed, log, Pause/Resume/Cancel.

Reload trang không auto resume.

Không cho tạo run thứ hai khi còn run `queued/running/paused`.

---

## 8. Filter và chọn sản phẩm

Sync Center hỗ trợ:

```text
Search: SKU / Product name / Abit Product ID
Status: Pending / Error / Synced / All syncable
Category: category_label từ productcategory Abit
```

Có thể:

- chọn từng product;
- select all page;
- sync selected;
- sync tất cả theo filter.

Inactive/ignored không xuất hiện trong normal "All" và không thể vào Sync Run.

---

## 9. Danh mục WooCommerce

Sync Run có 3 mode:

### keep

Không thay đổi category.

### abit

Đọc `productcategory`, tạo `product_cat` nếu cần và **append** vào category hiện có.

### fixed

Admin chọn category WooCommerce có sẵn và plugin **append** category đó.

Plugin không xóa toàn bộ category cũ khi sync.

---

## 10. WooCommerce upsert

Thứ tự tìm product:

```text
1. _cunchici_abit_product_id == productid
2. fallback WooCommerce SKU == productcode
3. chưa có → tạo WC_Product_Simple
```

Meta:

```text
_cunchici_abit_product_id
_cunchici_abit_last_synced_at
_cunchici_abit_modified_time
_cunchici_abit_barcode
_cunchici_abit_ma_goc
```

Không tự convert product type.

SKU đã thuộc product khác → error, không cưỡng ép duplicate SKU.

---

## 11. Màu / Size / Stock

### Màu / Size

Chưa xác định source field chính xác nên mapper hiện trả rỗng.

Rule:

```text
không map được color/size
→ không gọi set_attributes([])
→ không xóa attributes WooCommerce hiện có
```

### Stock

Chưa ghi current stock cho tới khi xác minh response thật từ:

```http
POST /products/listProductsWithStockforPartner
```

Không dùng min/max stock làm current quantity.

---

## 12. Đối soát API

Menu:

```text
Cún Chic × Abit
└── Đối soát API
```

File:

```text
includes/class-cunchici-abit-audit.php
```

Audit hoàn toàn read-only, thống kê:

```text
api_total_rows
api_unique_product_ids
admin_expected_count
difference_unique_vs_admin
status_counts
status_not_1
duplicate_productid_groups
unique_non_empty_skus
duplicate_sku_groups
empty_sku_rows
```

Không ghi WooCommerce, không sửa candidate queue và không log token.

---

## 13. GitHub Actions audit

File:

```text
.github/workflows/abit-audit.yml
```

Credential đọc từ repository secret:

```text
ABIT_ACCESS_TOKEN
```

Không hard-code token vào workflow/history.

Workflow:

- gọi API thật theo pagination;
- đếm total rows/unique productid;
- thống kê status;
- kiểm tra duplicate ID/SKU;
- kiểm tra SKU/ID rỗng;
- probe date filter bằng range tương lai;
- upload artifact JSON.

Kết quả verified hiện tại:

```text
docs/abit-product-audit-2026-08-22.json
```

---

## 14. Bản đồ file

### `cunchici-abit.php`

Bootstrap, version, require class, activation hook, dependency wiring và migration candidate status 0.2.2.

### `includes/class-cunchici-abit-settings.php`

`base_url`, `access_token`, `partner_name`, `productstoreid`, `sync_limit`.

### `includes/class-cunchici-abit-db.php`

Schema `items` + `runs`, unique/index, dbDelta.

### `includes/class-cunchici-abit-api.php`

HTTP client, list products, date range, store list, stock endpoint.

### `includes/class-cunchici-abit-product-mapper.php`

Field mapping Abit → dữ liệu nội bộ/WooCommerce.

### `includes/class-cunchici-abit-sync-repository.php`

Candidate/run repository, `pending/synced/error/ignored`, filters, queue, guard inactive, progress DB.

### `includes/class-cunchici-abit-discovery.php`

Full/incremental discovery, pagination, checkpoint, overlap, Pause/Resume, inactive counter.

### `includes/class-cunchici-abit-product-sync.php`

Process từng queued product, simple product upsert, category, metadata, error isolation.

### `includes/class-cunchici-abit-admin.php`

Settings + Sync Center + AJAX controllers.

### `includes/class-cunchici-abit-audit.php`

Read-only API reconciliation.

### `assets/admin.js`

Sync Center UI, discovery loop, filter, checkbox, progress, Pause/Resume/Cancel; hiển thị API rows và inactive riêng.

### `assets/admin.css`

Style Sync Center.

### `.github/workflows/lint.yml`

Lint PHP/JavaScript.

### `.github/workflows/abit-audit.yml`

Live Abit audit qua GitHub Actions Secret.

### `docs/abit-product-audit-2026-08-22.json`

Snapshot kết quả audit catalog đã xác minh.

---

## 15. Safety rules

1. Discovery không ghi WooCommerce.
2. Audit không ghi WooCommerce và không sửa queue.
3. `status != 1` không được normal sync.
4. Server-side Sync Run phải loại `ignored`, không chỉ dựa vào UI.
5. Reload không auto resume.
6. Checkpoint không update khi discovery chưa hoàn tất.
7. Product lỗi không dừng toàn run.
8. Không xóa product khi Abit không trả về.
9. Không tự đổi product type.
10. Không đoán current stock.
11. Không xóa attributes khi source không có color/size.
12. Không commit Access Token.
13. AJAX phải có nonce.
14. Admin phải có `manage_woocommerce`.
15. Khi API/admin lệch count, chạy audit trước khi thay rule catalog.

---

## 16. Flow vận hành

### Lần đầu

```text
Cấu hình token/partner
→ Test API
→ Đối soát API nếu cần
→ Quét toàn bộ
→ API có thể đọc 2624 rows
→ 45 inactive → ignored
→ 2579 active → candidate syncable
→ filter/chọn
→ sync từng nhóm
```

### Hàng ngày

```text
Mở Sync Center
→ date range từ checkpoint
→ Quét mới/cập nhật
→ active mới/thay đổi → pending
→ inactive → ignored
→ pending cũ vẫn còn
→ chọn và sync
```

---

## 17. Việc còn lại Phase 1

- [x] Xác minh API total vs Abit Admin.
- [x] Chốt rule `status=1` active / `status=0` inactive.
- [x] Probe date filter bằng CI.
- [ ] Xác minh payload tồn kho.
- [ ] Map current stock.
- [ ] Xác định nguồn màu.
- [ ] Xác định nguồn size.
- [ ] Import ảnh nếu cần.
- [ ] Category hierarchy nếu cần.
- [ ] Retry/backoff 429/5xx.

---

## 18. Khi cần sửa gì thì vào đâu?

```text
Token / Partner / Kho / Limit
→ class-cunchici-abit-settings.php
→ class-cunchici-abit-admin.php

Endpoint / request / date range
→ class-cunchici-abit-api.php

Count API khác admin / status / duplicate
→ class-cunchici-abit-audit.php
→ .github/workflows/abit-audit.yml

Checkpoint / incremental / quét dở
→ class-cunchici-abit-discovery.php

Candidate / ignored / queue / run DB
→ class-cunchici-abit-sync-repository.php

SKU / giá / category / màu / size / stock mapping
→ class-cunchici-abit-product-mapper.php

Create/update WooCommerce
→ class-cunchici-abit-product-sync.php

Menu / AJAX / Sync Center markup
→ class-cunchici-abit-admin.php

UI progress/filter/Pause-Resume
→ assets/admin.js

CSS
→ assets/admin.css

DB schema
→ class-cunchici-abit-db.php

Bootstrap/version/migration
→ cunchici-abit.php
```

---

## 19. Quy tắc duy trì README

README phải cập nhật cùng commit khi thay đổi file responsibility, endpoint, mapping, DB schema, checkpoint, queue/run status, admin flow, safety rule hoặc product model.

> README phải mô tả code đang chạy, không mô tả ý tưởng chưa triển khai.
