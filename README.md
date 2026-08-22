# Cún Chic × Abit

Plugin WordPress/WooCommerce dùng để kết nối **cunchici.vn** với **Abit Open API**.

README này là **bản đồ kỹ thuật trung tâm** của plugin. Mỗi lần thêm/xóa/đổi trách nhiệm file hoặc thay đổi logic đồng bộ phải cập nhật README cùng commit để lần sau chỉ cần đọc file này là biết cần sửa ở đâu.

---

## 1. Version hiện tại

```text
0.2.0
```

Version 0.2.0 thay đổi kiến trúc đồng bộ sản phẩm từ kiểu:

```text
Bấm Sync
→ gọi Abit page 0
→ ghi WooCommerce
→ page 1
→ ghi tiếp...
```

sang mô hình an toàn hơn:

```text
Bước 1: Quét/Discovery Abit
→ lưu danh sách sản phẩm cần đồng bộ vào DB riêng
→ KHÔNG ghi WooCommerce

Bước 2: Admin lọc/chọn sản phẩm
→ tạo một Sync Run

Bước 3: Đồng bộ từng sản phẩm
→ hiển thị % / sản phẩm / lỗi
→ có Pause / Resume / Cancel
```

Mục tiêu chính:

- Không vô tình chạy toàn bộ catalog chỉ bằng một click.
- Không mất sản phẩm nếu dừng giữa chừng.
- Hôm sau chỉ quét sản phẩm mới/cập nhật nhưng vẫn giữ các item hôm trước chưa sync.
- Có danh sách sản phẩm cần đồng bộ trước khi ghi WooCommerce.
- Có filter, checkbox, danh mục, trạng thái.
- Có tiến độ chính xác theo từng sản phẩm.
- Reload trang **không tự chạy tiếp**.

---

## 2. Quy tắc nghiệp vụ sản phẩm

Abit đang tách biến thể thành các sản phẩm đơn.

Plugin giữ nguyên mô hình:

```text
1 row Abit
→ 1 WooCommerce simple product
```

Không:

- tạo variable product;
- gom SKU thành parent/variation;
- tự đoán quan hệ cha/con.

Ví dụ:

```text
Áo A - Đỏ - S
Áo A - Đỏ - M
Áo A - Xanh - S
```

WooCommerce cũng giữ thành 3 simple products.

---

## 3. Payload sản phẩm đã xác minh

API danh sách sản phẩm thật của shop Cún Chic đã được test thành công ngày 2026-08-22.

Các field đã thấy:

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

- API danh sách hiện **không có field màu riêng**.
- API danh sách hiện **không có field size riêng**.
- `tonkho_toithieu` và `tonkho_toida` là ngưỡng min/max, **không phải tồn kho hiện tại**.
- Không được tự đoán màu/size từ tên hoặc SKU nếu chưa chốt nghiệp vụ.
- Không được xóa WooCommerce attributes khi Abit không trả màu/size.

---

## 4. API danh sách sản phẩm

Endpoint:

```http
POST /products/listProductsforPartner
```

Base URL mặc định:

```text
https://new.abitstore.vn
```

Các field request đang dùng:

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

`date_time_start` và `date_time_end` là optional trong code.

Lần đầu có thể quét toàn bộ mà không gửi date range.

Các lần incremental sau dùng checkpoint.

---

## 5. Logic incremental sync — phần quan trọng nhất

Plugin tách riêng hai khái niệm:

```text
DISCOVERY = đã nhìn thấy sản phẩm từ Abit
SYNC      = đã ghi sản phẩm đó vào WooCommerce
```

Không được dùng “lần cuối sync WooCommerce” làm mốc gọi Abit.

### Ví dụ

Ngày 22/08:

```text
Abit có 500 sản phẩm
Discovery quét đủ 500
Admin chỉ sync 100
```

DB plugin lúc này:

```text
100 synced
400 pending
```

Checkpoint discovery đã hoàn tất ngày 22/08.

Ngày 23/08 Abit thêm 10 sản phẩm:

```text
Discovery incremental
→ lấy khoảng thời gian từ checkpoint cũ đến hiện tại
→ thêm 10 item mới vào queue
```

DB plugin trở thành:

```text
100 synced
410 pending
```

Do đó 400 sản phẩm cũ chưa sync **không bị mất**.

### Nếu discovery hôm nay chạy dở

Ví dụ:

```text
Quét được page 0
Quét được page 1
Browser reload
```

Checkpoint **không được cập nhật** vì chưa quét hết range.

State discovery giữ:

```text
page tiếp theo
fetched
created
changed
unchanged
range đang quét
status paused/running
```

Admin có thể bấm **Tiếp tục quét**.

### Overlap 5 phút

Sau khi đã có checkpoint, range mặc định bắt đầu:

```text
checkpoint - 5 phút
```

Mục đích:

- tránh mất record đúng ranh giới thời gian;
- tránh sai lệch vài giây/phút giữa server;
- record trùng không sao vì queue upsert bằng `productid`.

---

## 6. Candidate Queue

Tất cả sản phẩm Abit đã discovery được lưu vào table:

```text
{wp_prefix}cunchici_abit_items
```

Các trạng thái chính:

```text
pending = cần đồng bộ hoặc payload đã thay đổi
synced  = đã đồng bộ thành công
error   = lần sync gần nhất lỗi
```

Khi cùng `productid` xuất hiện lại:

```text
payload không đổi + đã synced
→ giữ synced

payload thay đổi
→ chuyển pending
```

Đây là cơ chế giúp sản phẩm được chỉnh sửa bên Abit xuất hiện lại trong danh sách cần sync.

### Field chính của table queue

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

`payload` lưu JSON của row Abit để lúc sync không cần gọi lại API list cho từng item.

---

## 7. Sync Run

Mỗi lần admin bấm:

```text
Đồng bộ sản phẩm đã chọn
```

hoặc:

```text
Đồng bộ tất cả theo bộ lọc
```

plugin tạo một run trong:

```text
{wp_prefix}cunchici_abit_runs
```

Run có:

```text
id
status
options
total
processed
succeeded
failed
current_item_id
current_product_name
created_at
started_at
finished_at
```

Status:

```text
queued
running
paused
completed
cancelled
```

### Progress

Mỗi AJAX request xử lý **1 sản phẩm**.

Do đó phần trăm là chính xác:

```text
percent = processed / total * 100
```

UI hiển thị:

- tổng số sản phẩm;
- đã xử lý;
- thành công;
- lỗi;
- %;
- SKU + tên sản phẩm vừa xử lý;
- log created / updated / error.

### Reload trang

Reload trang:

```text
KHÔNG tự resume sync
```

Run vẫn nằm trong DB.

Admin phải chủ động bấm:

```text
Tiếp tục
```

Đây là thiết kế an toàn có chủ đích.

---

## 8. Filter và chọn sản phẩm

Trang **Đồng bộ sản phẩm** có filter:

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
- category lấy từ productcategory của Abit
```

Admin có thể:

```text
checkbox từng sản phẩm
select all ở page hiện tại
sync selected
sync toàn bộ kết quả theo filter
```

Nếu chọn `All status`, có thể re-sync cả sản phẩm đã synced nên UI sẽ hỏi xác nhận.

---

## 9. Danh mục WooCommerce

Mỗi Sync Run lưu lựa chọn category riêng.

Hiện có 3 mode:

### `keep`

```text
Không thay đổi danh mục WooCommerce
```

An toàn nhất với sản phẩm đã tồn tại.

### `abit`

```text
Đọc productcategory từ Abit
→ tạo product_cat nếu chưa có
→ gán product vào category đó
```

Hiện category được tạo dạng flat terms.

Nếu sau này cần hierarchy chính xác:

```text
Danh mục cha
└── Danh mục con
```

thì sửa parser/category mapping riêng.

### `fixed`

Admin chọn một WooCommerce category có sẵn.

Mọi product trong run được gán vào category đó.

---

## 10. Upsert WooCommerce

Thứ tự tìm product:

```text
1. _cunchici_abit_product_id == Abit productid
2. Nếu chưa có → WooCommerce SKU == Abit productcode
3. Nếu chưa có → tạo WC_Product_Simple
```

Sau khi save:

```text
_cunchici_abit_product_id
_cunchici_abit_last_synced_at
_cunchici_abit_modified_time
_cunchici_abit_barcode
_cunchici_abit_ma_goc
```

Không tự chuyển variable/external product sang simple.

Nếu Abit ID/SKU đang trỏ tới sản phẩm không phải simple:

```text
→ đánh error
→ không phá dữ liệu hiện tại
```

Nếu SKU đã thuộc product khác:

```text
→ error
→ không cưỡng ép duplicate SKU
```

---

## 11. Mapping hiện tại

File:

```text
includes/class-cunchici-abit-product-mapper.php
```

Mapping:

| Abit | Nội bộ / WooCommerce |
|---|---|
| `productid` | External key `_cunchici_abit_product_id` |
| `productcode` | SKU |
| `productname` | Product name |
| `unit_price` | Regular price/current price |
| `description` | Description |
| `short_description` | Short description |
| `productcategory` | Source category/filter/category mapping |
| `brandname` | Metadata nội bộ mapper |
| `barcode` | `_cunchici_abit_barcode` |
| `ma_goc` | `_cunchici_abit_ma_goc` |
| `modifiedtime` | `_cunchici_abit_modified_time` |

### Màu / Size

Hiện:

```text
color = ''
size = ''
```

Vì payload thật không có field riêng.

Product Sync có rule:

```text
Nếu không map được color/size
→ KHÔNG call set_attributes([])
→ không xóa attributes WooCommerce hiện có
```

---

## 12. Tồn kho

API tồn kho:

```http
POST /products/listProductsWithStockforPartner
```

với:

```text
productstoreid
```

API danh sách kho:

```http
POST /productstore/getStoreidByPartner
```

Trang Settings có Diagnostic để xem:

- danh sách store;
- payload stock sample nếu đã cấu hình `Product Store ID`.

### Trạng thái 0.2.0

Plugin **chưa ghi current stock**.

Lý do:

- chưa có sample thật của `listProductsWithStockforPartner` để xác nhận field số lượng;
- không dùng `tonkho_toithieu` / `tonkho_toida` làm current stock.

Khi có sample stock thật:

1. sửa `Cunchici_Abit_Product_Mapper::stock_quantity()`;
2. bổ sung stock option vào Sync Run;
3. nối stock lookup theo `productid`;
4. cập nhật README.

---

## 13. Bản đồ file

### `cunchici-abit.php`

Bootstrap plugin.

Trách nhiệm:

- version;
- constants;
- require class;
- install/upgrade DB schema;
- dependency wiring.

Sửa khi:

- thêm class/service cấp cao;
- bump version;
- đổi bootstrap.

---

### `includes/class-cunchici-abit-settings.php`

Settings.

Trách nhiệm:

```text
base_url
access_token
partner_name
productstoreid
sync_limit
```

Sửa khi thêm cấu hình mới.

---

### `includes/class-cunchici-abit-db.php`

Database schema plugin.

Trách nhiệm:

- table candidate queue;
- table sync runs;
- schema version;
- dbDelta migration.

Sửa khi thêm field/table/index.

---

### `includes/class-cunchici-abit-api.php`

Abit HTTP client.

Trách nhiệm:

- POST JSON;
- token/partner;
- list products;
- date range;
- list stores;
- list products with stock.

Nếu Abit đổi endpoint/request:

```text
sửa file này
```

---

### `includes/class-cunchici-abit-product-mapper.php`

Chuẩn hóa row Abit.

Trách nhiệm:

- ID;
- SKU;
- name;
- price;
- description;
- brand/barcode;
- source category;
- images normalization;
- sau này color/size/stock.

Sai field mapping:

```text
kiểm tra file này đầu tiên
```

---

### `includes/class-cunchici-abit-sync-repository.php`

Data access layer của Sync Center.

Trách nhiệm:

- upsert candidate;
- payload hash;
- pending/synced/error;
- list/filter candidates;
- category filter values;
- tạo sync run;
- queue product vào run;
- lấy next item;
- cập nhật progress/error.

Nếu sai trạng thái queue/run:

```text
kiểm tra file này
```

---

### `includes/class-cunchici-abit-discovery.php`

Logic quét Abit.

Trách nhiệm:

- initial full discovery;
- incremental discovery;
- `date_time_start` / `date_time_end`;
- pagination;
- resume discovery;
- checkpoint;
- overlap 5 phút;
- ghi candidate vào repository.

Nếu lỗi “hôm sau lấy sản phẩm mới”, “resume quét”, “checkpoint”:

```text
kiểm tra file này
```

---

### `includes/class-cunchici-abit-product-sync.php`

Logic ghi WooCommerce.

Trách nhiệm:

- process 1 queued item;
- simple product upsert;
- SKU;
- name;
- price;
- descriptions;
- categories;
- metadata;
- progress response;
- error isolation.

Không gọi trực tiếp full catalog Abit ở class này nữa.

---

### `includes/class-cunchici-abit-admin.php`

Admin controllers + AJAX.

Trách nhiệm:

- menu;
- Settings UI;
- Sync Center markup;
- diagnostic;
- discovery AJAX;
- candidates AJAX;
- run AJAX;
- enqueue assets.

Nếu cần thêm action UI/server endpoint:

```text
sửa file này
```

---

### `assets/admin.js`

Client-side Sync Center.

Trách nhiệm:

- AJAX helper;
- load/filter table;
- checkbox selection;
- discovery loop;
- pause/resume/cancel;
- create sync run;
- process từng product;
- progress %;
- log product/error;
- không auto resume khi reload.

Nếu giao diện chạy sai flow nhưng PHP backend đúng:

```text
kiểm tra file này
```

---

### `assets/admin.css`

Style Sync Center.

Trách nhiệm:

- dashboard cards;
- grid;
- table;
- badges;
- progress;
- log;
- responsive admin UI.

---

## 14. Database tables

### `{prefix}cunchici_abit_items`

Candidate/product state.

Unique:

```text
abit_product_id
```

### `{prefix}cunchici_abit_runs`

Sync runs.

Không xóa tables khi deactivate plugin.

Mục đích:

- update plugin/deactivate tạm không làm mất queue/history đang dùng.

Nếu sau này viết uninstall cleanup phải làm file riêng và xác nhận trước vì đây là dữ liệu vận hành.

---

## 15. Discovery state options

```text
cunchici_abit_discovery_state
cunchici_abit_discovery_checkpoint_end
```

`discovery_state` lưu progress của lần quét đang/dở.

`checkpoint_end` chỉ cập nhật khi range được quét hết pagination.

---

## 16. Safety rules

Bắt buộc giữ các rule sau:

1. Discovery không ghi WooCommerce.
2. Sync chỉ chạy sau khi đã có candidate list.
3. Reload không auto resume.
4. Checkpoint không update khi discovery dở.
5. Product lỗi không làm dừng toàn run.
6. Không xóa product khi Abit không trả về.
7. Không đổi product type tự động.
8. Không đoán current stock.
9. Không xóa attributes nếu source không có color/size.
10. Không commit Access Token.
11. AJAX phải có nonce.
12. Admin phải có `manage_woocommerce`.

---

## 17. Flow vận hành đề xuất

### Lần đầu

```text
Cấu hình token/partner
→ Test connection
→ Quét toàn bộ lần đầu
→ xem Candidate List
→ filter/chọn sản phẩm
→ chọn category mode
→ Sync selected hoặc filtered
→ theo dõi progress
```

### Ngày tiếp theo

```text
Mở Sync Center
→ range mặc định lấy từ checkpoint
→ Quét mới / cập nhật
→ các sản phẩm mới/thay đổi thành pending
→ pending cũ chưa sync vẫn còn
→ chọn và sync
```

---

## 18. Case cần test trước production full sync

- [ ] Update plugin lên 0.2.0.
- [ ] DB tables tự tạo thành công.
- [ ] Settings vẫn giữ token cũ.
- [ ] Test connection thành công.
- [ ] Quét toàn bộ catalog nhưng không tạo Woo product.
- [ ] Candidate count đúng.
- [ ] Filter pending/category/search hoạt động.
- [ ] Chọn 1 sản phẩm và sync thành công.
- [ ] Sync lại cùng product không duplicate.
- [ ] Chọn fixed category hoạt động.
- [ ] Category mode keep không xóa category hiện có.
- [ ] Pause run sau vài sản phẩm.
- [ ] Reload trang không tự chạy.
- [ ] Resume xử lý phần còn lại.
- [ ] Product lỗi hiển thị status error + last_error.
- [ ] Ngày/range incremental tạo thêm candidate mới.
- [ ] Pending từ ngày cũ vẫn còn.

---

## 19. Việc chưa hoàn tất

### Phase 1 còn lại

- [ ] Xác minh payload tồn kho thật.
- [ ] Map current stock.
- [ ] Xác định nguồn màu.
- [ ] Xác định nguồn size.
- [ ] Import ảnh vào Media Library nếu cần.
- [ ] Category hierarchy nếu productcategory có cấu trúc cha/con.
- [ ] Retry/backoff API 429/5xx.
- [ ] Sync history detail screen nếu cần audit sâu.

### Sau Phase 1

- [ ] Auto schedule/cron.
- [ ] Đơn hàng.
- [ ] Khách hàng.
- [ ] Trạng thái đơn.
- [ ] Inventory workflow hai chiều nếu nghiệp vụ yêu cầu.

---

## 20. Khi cần sửa gì thì vào đâu?

```text
Token / Partner / Kho / Limit
→ class-cunchici-abit-settings.php
→ class-cunchici-abit-admin.php

Endpoint / request Abit / date range field
→ class-cunchici-abit-api.php

Checkpoint / incremental / quét dở
→ class-cunchici-abit-discovery.php

Candidate / pending / synced / error / run DB
→ class-cunchici-abit-sync-repository.php

SKU / giá / category / màu / size / stock mapping
→ class-cunchici-abit-product-mapper.php

Create/update WooCommerce / category assignment
→ class-cunchici-abit-product-sync.php

Menu / AJAX / markup
→ class-cunchici-abit-admin.php

Table UI / progress / filter / checkbox / pause-resume
→ assets/admin.js

Giao diện
→ assets/admin.css

DB schema
→ class-cunchici-abit-db.php

Bootstrap/version/dependency
→ cunchici-abit.php
```

---

## 21. Quy tắc cập nhật README

Mỗi commit phải cập nhật README nếu có một trong các việc:

1. thêm/xóa/đổi tên file;
2. đổi trách nhiệm class;
3. thêm endpoint Abit;
4. đổi mapping;
5. đổi database schema;
6. đổi checkpoint/incremental logic;
7. đổi status queue/run;
8. đổi admin flow;
9. đổi safety rule;
10. đổi product model.

Mục tiêu:

> README phải mô tả **code đang chạy**, không phải ý tưởng dự kiến.
