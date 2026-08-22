# Cún Chic × Abit

Plugin WordPress/WooCommerce dùng để kết nối **cunchici.vn** với **Abit Open API**.

README này là **bản đồ kỹ thuật trung tâm** của plugin. Mỗi lần thêm/xóa/đổi trách nhiệm file hoặc thay đổi logic đồng bộ phải cập nhật README cùng commit.

---

## 1. Trạng thái hiện tại

Version hiện tại: `0.1.1`.

### Trạng thái an toàn

**FULL PRODUCT SYNC ĐANG TẠM KHÓA.**

Lý do:

- API danh sách sản phẩm thật của shop đã được xác minh.
- Response không có field màu/size riêng.
- Chưa xác minh field số lượng tồn kho thật từ endpoint tồn kho.
- Một lần sync thử đã vô tình được kích hoạt trước khi mapping hoàn chỉnh.

Bản `0.1.1` khóa cả nút UI lẫn AJAX endpoint sync. Endpoint trả HTTP `423` nếu có request ghi dữ liệu.

Nút **Test kết nối Abit** chỉ đọc dữ liệu diagnostic và không ghi WooCommerce.

---

## 2. Quy tắc nghiệp vụ đã chốt

Abit đang tách biến thể thành các sản phẩm đơn. Plugin giữ nguyên mô hình này:

```text
1 dòng sản phẩm Abit
        ↓
1 WooCommerce simple product
```

Không tạo variable product.
Không gom các SKU thành sản phẩm cha/con.

Ví dụ:

```text
Áo A - Đỏ - S  -> simple product
Áo A - Đỏ - M  -> simple product
Áo A - Xanh - S -> simple product
```

---

## 3. API Abit

Base URL mặc định:

```text
https://new.abitstore.vn
```

### Danh sách sản phẩm

```http
POST /products/listProductsforPartner
Content-Type: application/json
```

Payload:

```json
{
  "access_token": "...",
  "partner_name": "...",
  "page": 0,
  "limit": 100
}
```

Response thật của shop hiện là **top-level array**.

Các key đã xác minh trên một sản phẩm thật:

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

### Kết luận từ payload thật

Có thể chốt ngay:

| Abit | Ý nghĩa/plugin |
|---|---|
| `productid` | External ID chính |
| `productcode` | SKU |
| `productname` | Tên sản phẩm |
| `unit_price` | Giá hiện dùng để map WooCommerce |
| `gia_daily` | Giá phụ, đang chỉ normalize để tham chiếu |
| `description` | Mô tả |
| `short_description` | Mô tả ngắn |
| `brandname` | Tên thương hiệu |
| `brandid` | ID thương hiệu Abit |
| `barcode` | Barcode |
| `ma_goc` | Mã gốc |
| `modifiedtime` | Thời gian sửa tại Abit |
| `imagename` | Danh sách ảnh dạng JSON/string |
| `default_image` | Ảnh mặc định |

### Chưa được phép suy đoán

Response trên **không có field màu/size riêng**.

Do đó mapper `0.1.1` cố ý trả:

```text
color = ""
size = ""
```

và sync service không được phép xóa attributes WooCommerce khi chưa có mapping thật.

`tonkho_toithieu` và `tonkho_toida` là ngưỡng tồn kho tối thiểu/tối đa, **không được dùng làm tồn kho hiện tại**.

---

## 4. API kho và tồn kho

### Danh sách Kho - Chi nhánh

```http
POST /productstore/getStoreidByPartner
```

Plugin `0.1.1` đã có:

```php
Cunchici_Abit_API::list_stores()
```

Nút Test sẽ gọi API này để hiển thị danh sách kho thật. Không cần đoán `productstoreid`.

### Sản phẩm có tồn kho

```http
POST /products/listProductsWithStockforPartner
```

Payload bổ sung:

```json
{
  "productstoreid": 123
}
```

Plugin chỉ gọi endpoint này sau khi `Product Store ID` đã được cấu hình.

**Field số lượng tồn kho chưa được xác minh nên `stock_quantity()` hiện trả `null`.**

Điều này cố ý ngăn việc ghi sai stock lên WooCommerce.

---

## 5. Menu quản trị

```text
WP Admin
└── Cún Chic × Abit
    ├── Cấu hình
    └── Đồng bộ sản phẩm
```

Quyền:

```text
manage_woocommerce
```

### Cấu hình

Các field:

| Field | Ý nghĩa |
|---|---|
| Abit Base URL | `https://new.abitstore.vn` |
| Access Token | Token Abit |
| Partner Name / Mã shop | `partner_name` |
| Product Store ID | Kho dùng để đọc tồn kho |
| Sản phẩm mỗi trang | `limit`, 1–500 |

Token nằm trong WordPress options, không commit GitHub.

---

## 6. Test kết nối / Diagnostic

Bản `0.1.1` nâng nút **Test kết nối Abit** thành diagnostic.

Nút test dự kiến đọc:

1. `listProductsforPartner` với `page=0`, `limit=1`.
2. Danh sách kho qua `getStoreidByPartner`.
3. Nếu đã có `productstoreid`, lấy 1 record từ `listProductsWithStockforPartner`.

Không có bước ghi dữ liệu WooCommerce.

Mục tiêu diagnostic tiếp theo:

- Xem sample values của sản phẩm để xác định màu/size đang được encode ở đâu (có thể trong tên/SKU/mã gốc hoặc nguồn khác).
- Xem danh sách kho thật.
- Xem key và value của record tồn kho thật.

---

## 7. Bản đồ file

### `cunchici-abit.php`

Bootstrap plugin.

Trách nhiệm:

- Metadata/version plugin.
- Constants path/version.
- Load class.
- Khởi tạo Settings, API, Product Sync, Admin.

Sửa khi thêm service mới hoặc bump version.

### `includes/class-cunchici-abit-settings.php`

Quản lý settings.

Trách nhiệm:

- Base URL.
- Access Token.
- Partner Name.
- Product Store ID.
- Sync limit.
- Sanitize/validate.

Sửa khi thêm cấu hình mới.

### `includes/class-cunchici-abit-api.php`

HTTP client Abit.

Hiện có:

```text
list_products()
list_stores()
list_products_with_stock()
```

Trách nhiệm:

- POST JSON.
- Tự thêm token/partner.
- Timeout 30 giây.
- Parse HTTP/JSON errors.

Sửa file này nếu Abit đổi endpoint/request hoặc thêm API mới.

### `includes/class-cunchici-abit-product-mapper.php`

Mapper Abit -> dữ liệu nội bộ.

Đã chốt theo payload thật:

- `productid`.
- `productcode`.
- `productname`.
- `unit_price`.
- `gia_daily`.
- description.
- brand.
- barcode.
- mã gốc.
- modified time.
- image parser.

Quan trọng:

```text
color = chưa xác minh
size = chưa xác minh
stock quantity = chưa xác minh
```

Không thêm alias đoán khi chưa có evidence từ payload thật.

### `includes/class-cunchici-abit-product-sync.php`

Logic ghi WooCommerce.

Trách nhiệm:

- Pagination từng page.
- Upsert bằng `_cunchici_abit_product_id`.
- Fallback SKU.
- Chỉ tạo `WC_Product_Simple`.
- Set name/description/price/SKU.
- Stock chỉ ghi nếu mapper trả quantity thật.
- Không xóa attributes khi color/size chưa có mapping.

Meta hiện lưu:

```text
_cunchici_abit_product_id
_cunchici_abit_last_synced_at
_cunchici_abit_modified_time
_cunchici_abit_barcode
_cunchici_abit_ma_goc
```

### `includes/class-cunchici-abit-admin.php`

Admin UI + AJAX.

Bản `0.1.1`:

- Settings UI.
- Diagnostic API test.
- Liệt kê sample kho/payload.
- Full sync UI bị khóa.
- AJAX sync endpoint bị khóa server-side với HTTP 423.

Nếu muốn mở lại sync, phải sửa file này **sau khi mapping đã xác minh**.

---

## 8. Sự cố sync thử ngày 2026-08-22

Một lần bấm **Bắt đầu đồng bộ** đã xảy ra trước khi mapping hoàn tất.

Kiến trúc bản cũ chạy như sau:

```text
Browser JavaScript
  -> AJAX page 0
  -> chờ xong
  -> AJAX page 1
  -> ...
```

Không có:

```text
WP-Cron
Action Scheduler
queue worker
background process
```

Do đó reload/đóng tab sẽ ngừng việc gửi **page tiếp theo**. Một request đang chạy dở tại thời điểm reload vẫn có thể hoàn thành.

### Dữ liệu bản cũ có thể đã chạm

Trên những page đã hoàn tất, bản cũ có thể:

- Tạo simple product mới.
- Update sản phẩm hiện có nếu match Abit ID hoặc SKU.
- Update tên.
- Update description/short description.
- Update regular/current price.
- Update SKU nếu không conflict.
- Gắn `_cunchici_abit_product_id`.
- Gắn `_cunchici_abit_last_synced_at`.
- Có nguy cơ clear WooCommerce attributes do color/size mapper rỗng.
- Stock chỉ bị đụng nếu Product Store ID đã cấu hình và mapper tìm được quantity.

Không nên tự động rollback/xóa hàng loạt khi chưa audit vì có thể có sản phẩm WooCommerce cũ bị update chứ không chỉ sản phẩm mới.

---

## 9. Quy tắc an toàn từ bây giờ

Trước mọi thao tác ghi dữ liệu:

1. Test API read-only.
2. Capture sample payload thật.
3. Chốt mapping.
4. Có **Dry Run/Preview**.
5. Cho phép giới hạn `N` sản phẩm test.
6. Chỉ sau đó mới mở Full Sync.

Full sync không được mở lại chỉ vì API trả HTTP 200.

---

## 10. Việc cần làm tiếp theo

- [x] Kết nối token/partner thành công.
- [x] Xác minh payload list sản phẩm thật.
- [x] Loại bỏ alias đoán màu/size.
- [x] Ngăn mapper tồn kho đoán quantity.
- [x] Giữ nguyên WooCommerce attributes nếu chưa map color/size.
- [x] Thêm API danh sách kho.
- [x] Khóa full sync ở UI và endpoint.
- [ ] Update plugin trên website lên `0.1.1`.
- [ ] Chạy diagnostic mới.
- [ ] Chọn đúng Product Store ID.
- [ ] Capture sample `listProductsWithStockforPartner`.
- [ ] Xác định nguồn màu/size thật.
- [ ] Thêm trang Dry Run/Preview.
- [ ] Thêm giới hạn test 1/5/10 sản phẩm.
- [ ] Audit các sản phẩm đã bị lần sync thử chạm tới.
- [ ] Sau khi audit và mapping đúng mới mở full sync.

---

## 11. Khi cần sửa gì thì vào đâu?

```text
Token / Partner / Kho / Limit
-> includes/class-cunchici-abit-settings.php
-> includes/class-cunchici-abit-admin.php

Endpoint / payload / API Abit
-> includes/class-cunchici-abit-api.php

SKU / giá / màu / size / tồn kho / ảnh
-> includes/class-cunchici-abit-product-mapper.php

Create / update / duplicate / WooCommerce write
-> includes/class-cunchici-abit-product-sync.php

Menu / diagnostic / safety lock / sync button / AJAX
-> includes/class-cunchici-abit-admin.php

Version / bootstrap
-> cunchici-abit.php
```

---

## 12. Nguyên tắc duy trì README

Mỗi commit thay đổi integration phải cập nhật README nếu có:

1. Thêm/xóa/đổi tên file.
2. Chuyển trách nhiệm class.
3. Thêm endpoint Abit.
4. Đổi mapping.
5. Đổi meta/schema DB.
6. Đổi settings.
7. Đổi cách sync/pagination/retry.
8. Đổi quy tắc an toàn.

Mục tiêu: **README luôn phản ánh code thật và trạng thái production hiện tại.**
