# Cún Chic × Abit

Plugin WordPress/WooCommerce kết nối **cunchici.vn** với **Abit Open API**.

README này là bản đồ kỹ thuật trung tâm của plugin. Khi thêm/xóa/đổi trách nhiệm file, endpoint, mapping, DB schema hoặc flow đồng bộ thì phải cập nhật README cùng commit.

---

## 1. Version hiện tại

```text
0.2.1
```

### 0.2.1

Thêm trang **Đối soát API** hoàn toàn read-only để giải quyết trường hợp số lượng sản phẩm API khác số lượng hiển thị trong admin Abit.

Trang đối soát đọc toàn bộ `listProductsforPartner` theo pagination và thống kê:

- tổng số row API;
- số `productid` unique;
- số nhóm `productid` trùng;
- số lượng theo từng `status`;
- số row `status != 1`;
- số SKU unique;
- số nhóm SKU trùng;
- số sản phẩm SKU rỗng;
- chênh lệch so với tổng sản phẩm admin Abit do người dùng nhập.

Không lưu Access Token vào source/log và trang audit không ghi WooCommerce.

### 0.2.0

Đổi kiến trúc đồng bộ từ kiểu “bấm Sync rồi ghi thẳng toàn catalog” sang:

```text
1. Discovery/Quét Abit
   ↓
2. Candidate Queue trong DB plugin
   ↓
3. Admin lọc/chọn sản phẩm
   ↓
4. Tạo Sync Run
   ↓
5. Đồng bộ từng sản phẩm + progress
```

Reload trang không tự tiếp tục sync. Admin phải chủ động Resume.

---

## 2. Quy tắc nghiệp vụ sản phẩm

Abit đang tách biến thể thành các sản phẩm đơn.

Plugin giữ nguyên:

```text
1 row Abit
→ 1 WooCommerce simple product
```

Không tạo variable product, không gom SKU thành parent/variation và không tự đoán quan hệ cha/con.

---

## 3. Payload list product đã xác minh

API thật đã thấy các field:

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

- API list hiện không có field màu riêng.
- API list hiện không có field size riêng.
- `tonkho_toithieu` / `tonkho_toida` không được coi là current stock.
- Không xóa attributes WooCommerce khi source không có màu/size.

---

## 4. Abit API

Base URL mặc định:

```text
https://new.abitstore.vn
```

### Danh sách sản phẩm

```http
POST /products/listProductsforPartner
```

Request plugin hỗ trợ:

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

`date_time_start` và `date_time_end` chỉ gửi khi có giá trị.

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

Plugin tách hai khái niệm:

```text
DISCOVERY = đã lấy/nhìn thấy sản phẩm từ Abit
SYNC      = đã ghi sản phẩm đó vào WooCommerce
```

Không dùng “lần cuối sync WooCommerce” làm checkpoint Abit.

Ví dụ hôm nay Abit có 500 sản phẩm:

```text
Discovery đủ 500
Admin sync 100
→ 100 synced
→ 400 pending
```

Ngày mai Abit thêm 10:

```text
Incremental discovery từ checkpoint cũ đến hiện tại
→ thêm 10 candidate
→ 100 synced
→ 410 pending
```

400 sản phẩm cũ chưa sync vẫn còn.

### Checkpoint

Options:

```text
cunchici_abit_discovery_state
cunchici_abit_discovery_checkpoint_end
```

Checkpoint chỉ update sau khi quét hết pagination của range.

Nếu quét dở/reload/lỗi API thì checkpoint không nhảy lên.

### Overlap

Range incremental mặc định bắt đầu:

```text
checkpoint - 5 phút
```

để tránh mất record ở ranh giới thời gian. Candidate upsert bằng `productid` nên overlap không gây duplicate.

---

## 6. Candidate Queue

Table:

```text
{wp_prefix}cunchici_abit_items
```

`abit_product_id` có unique index.

Điều này có nghĩa cùng một `productid` không được lưu thành hai candidate khác nhau.

Status:

```text
pending
synced
error
```

Nếu payload cùng `productid` thay đổi thì sản phẩm quay lại `pending`.

Field chính:

```text
id
abit_product_id
sku
product_name
category_label
price
created_time
modified_time
payload_hash
payload
sync_status
woo_product_id
last_error
discovered_at
synced_at
queue_run_id
queue_status
```

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

Progress:

```text
percent = processed / total * 100
```

UI hiển thị:

- sản phẩm/SKU đang đồng bộ;
- %;
- processed/total;
- success;
- failed;
- log created/updated/error;
- Pause / Resume / Cancel.

Không cho tạo run thứ hai khi đang có run `queued/running/paused`.

Reload trang không auto resume.

---

## 8. Filter / chọn sản phẩm

Sync Center hỗ trợ:

```text
Search:
- SKU
- Product name
- Abit Product ID

Status:
- Pending
- Error
- Synced
- All

Category:
- category_label từ productcategory Abit
```

Có thể:

- chọn từng sản phẩm;
- select all page hiện tại;
- sync selected;
- sync tất cả theo filter.

---

## 9. Danh mục WooCommerce

Sync Run có 3 mode:

### keep

Không thay đổi category.

### abit

Đọc tên category từ `productcategory`, tạo `product_cat` nếu cần và **append** vào category hiện có.

### fixed

Admin chọn một category WooCommerce có sẵn và plugin **append** category đó.

Plugin không xóa toàn bộ category cũ chỉ vì một lần sync.

---

## 10. WooCommerce upsert

Thứ tự tìm product:

```text
1. _cunchici_abit_product_id == productid
2. fallback WooCommerce SKU == productcode
3. nếu chưa có → WC_Product_Simple mới
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

Hiện chưa xác định source field chính xác nên mapper trả rỗng.

Rule an toàn:

```text
không map được color/size
→ không gọi set_attributes([])
→ không xóa attributes đang có
```

### Stock

Chưa ghi current stock cho tới khi xác minh payload thật từ `listProductsWithStockforPartner`.

Không dùng min/max stock làm current quantity.

---

## 12. Đối soát API — 0.2.1

Menu:

```text
Cún Chic × Abit
└── Đối soát API
```

File:

```text
includes/class-cunchici-abit-audit.php
```

### Mục đích

Khi admin Abit báo một tổng sản phẩm nhưng API/discovery báo một tổng khác, không được đoán hoặc xóa candidate ngay.

Chạy audit để phân biệt:

1. API thực sự trả nhiều row hơn.
2. Pagination trả trùng `productid`.
3. API trả thêm `status != 1`.
4. Có nhiều productid nhưng trùng SKU.
5. Có SKU rỗng.

### Cách chạy

1. Vào **Cún Chic × Abit → Đối soát API**.
2. Nhập số lượng đang thấy trong admin Abit.
3. Bấm **Chạy đối soát toàn bộ API**.
4. Plugin đọc page 0, 1, 2... tới cuối.
5. Không ghi WooCommerce và không thay candidate queue.

Report trả:

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
conclusion
```

Nếu:

```text
status=1 count == admin count
```

nhưng unique API lớn hơn admin thì khả năng rất cao admin đang chỉ tính nhóm sản phẩm active/đang dùng còn API trả thêm trạng thái khác.

Audit cũng hiện danh sách sample `status != 1` và nhóm SKU trùng.

---

## 13. Bản đồ file

### `cunchici-abit.php`

Bootstrap, version, require class, dependency wiring, activation hook.

### `includes/class-cunchici-abit-settings.php`

Cấu hình:

```text
base_url
access_token
partner_name
productstoreid
sync_limit
```

### `includes/class-cunchici-abit-db.php`

Schema `items` + `runs`; unique/index; migration bằng `dbDelta`.

### `includes/class-cunchici-abit-api.php`

HTTP client Abit; list product; date range; list stores; stock API.

### `includes/class-cunchici-abit-product-mapper.php`

Field mapping Abit → internal/WooCommerce.

### `includes/class-cunchici-abit-sync-repository.php`

Candidate/run DB access; status; filters; queue; progress.

### `includes/class-cunchici-abit-discovery.php`

Full/incremental discovery; pagination; checkpoint; overlap; Pause/Resume.

### `includes/class-cunchici-abit-product-sync.php`

Process từng queued product; WooCommerce upsert; category; metadata; error isolation.

### `includes/class-cunchici-abit-admin.php`

Settings + Sync Center + AJAX controllers.

### `includes/class-cunchici-abit-audit.php`

Read-only full API reconciliation.

Sửa file này khi cần thêm thống kê để giải thích chênh lệch giữa API và admin Abit.

### `assets/admin.js`

Sync Center UI logic, discovery loop, filters, run progress, Pause/Resume/Cancel.

### `assets/admin.css`

Style Sync Center.

### `.github/workflows/lint.yml`

Lint PHP syntax và JavaScript khi push/PR.

---

## 14. Safety rules

1. Discovery không ghi WooCommerce.
2. Audit không ghi WooCommerce và không sửa queue.
3. Sync chỉ chạy từ candidate đã chọn/filter.
4. Reload không auto resume.
5. Checkpoint không update khi discovery chưa hoàn tất.
6. Product lỗi không dừng toàn run.
7. Không xóa product khi Abit không trả về.
8. Không tự đổi product type.
9. Không đoán current stock.
10. Không xóa attributes khi source không có color/size.
11. Không commit Access Token.
12. AJAX phải có nonce.
13. Admin phải có `manage_woocommerce`.
14. Khi số lượng API khác admin, chạy audit trước khi thêm filter loại bỏ sản phẩm.

---

## 15. Flow vận hành

### Lần đầu

```text
Cấu hình token/partner
→ Test API
→ nếu count có nghi vấn: Đối soát API
→ Quét toàn bộ lần đầu
→ xem Candidate List
→ filter/chọn
→ sync từng nhóm
```

### Hàng ngày

```text
Mở Sync Center
→ date range từ checkpoint
→ Quét mới/cập nhật
→ sản phẩm mới/thay đổi thành pending
→ pending cũ vẫn còn
→ chọn và sync
```

---

## 16. Checklist trước production full sync

- [ ] Plugin version 0.2.1 hoặc mới hơn.
- [ ] Test connection thành công.
- [ ] Đối soát API và hiểu rõ total API so với admin Abit.
- [ ] `duplicate_productid_groups = 0`.
- [ ] Xác định có cần loại `status != 1` hay không trước khi sync.
- [ ] Candidate count hợp lý.
- [ ] Test sync 1 sản phẩm.
- [ ] Sync lại không duplicate.
- [ ] Pause/Reload/Resume đúng.
- [ ] Category mode không phá category cũ.
- [ ] Xác minh stock payload trước khi bật stock.

---

## 17. Việc còn lại Phase 1

- [ ] Chạy audit catalog thật và chốt rule `status`.
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

Count API khác admin / status / duplicate SKU-ID
→ class-cunchici-abit-audit.php

Checkpoint / incremental / quét dở
→ class-cunchici-abit-discovery.php

Candidate / status / queue / run DB
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

Bootstrap/version
→ cunchici-abit.php
```

---

## 19. Quy tắc duy trì README

README phải cập nhật cùng commit khi:

- thêm/xóa/đổi file;
- đổi class responsibility;
- thêm endpoint;
- đổi mapping;
- đổi DB schema;
- đổi checkpoint;
- đổi queue/run status;
- đổi admin flow;
- đổi safety rule;
- đổi model sản phẩm.

> README phải mô tả code đang chạy, không mô tả ý tưởng chưa triển khai.
