# Cún Chic × Abit

Plugin WordPress/WooCommerce dùng để kết nối **cunchici.vn** với **Abit Open API**.

README này là **bản đồ kỹ thuật trung tâm** của plugin. Mỗi lần thêm/xóa/đổi trách nhiệm file hoặc thay đổi logic đồng bộ phải cập nhật README cùng commit để lần sau chỉ cần đọc tài liệu này là biết cần sửa ở đâu.

---

## 1. Trạng thái hiện tại

Version khởi tạo: `0.1.0`.

Đã triển khai khung Phase 1:

- Menu quản trị **Cún Chic × Abit**.
- Trang **Cấu hình**.
- Lưu Abit Base URL, Access Token, Partner Name/Mã shop, Product Store ID và limit.
- Nút **Test kết nối Abit**.
- Trang **Đồng bộ sản phẩm**.
- Gọi API danh sách sản phẩm theo pagination.
- Upsert WooCommerce product bằng Abit Product ID, fallback theo SKU.
- Mỗi dòng sản phẩm Abit luôn được lưu thành **WooCommerce simple product**.
- Không tạo variable product và không gom biến thể.
- Đồng bộ SKU, tên, giá, màu, size.
- Có khung gọi API tồn kho theo kho Abit.
- Có log created / updated / failed trên màn hình sync.

### Quy tắc nghiệp vụ đã chốt

Abit đang tách các sản phẩm có biến thể thành từng sản phẩm đơn. Vì vậy plugin cũng giữ nguyên mô hình đó:

```text
1 dòng sản phẩm Abit
        ↓
1 WooCommerce simple product
```

Ví dụ Abit có:

```text
Áo A - Đỏ - S
Áo A - Đỏ - M
Áo A - Xanh - S
```

WooCommerce cũng sẽ có 3 simple products riêng, **không tạo 1 variable product cha**.

Màu và Size được lưu dưới dạng custom product attributes, chỉ dùng để hiển thị/tham chiếu, `variation = false`.

---

## 2. API Abit đang sử dụng

Tài liệu chính thức:

- Tổng quan: https://apidocs.abit.vn/
- Danh sách sản phẩm: https://apidocs.abit.vn/san-pham/danh-sach-san-pham
- Danh sách sản phẩm tồn kho: https://apidocs.abit.vn/san-pham/danh-sach-san-pham-ton-kho

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

Plugin bắt đầu pagination từ `page = 0`.

### Danh sách sản phẩm có tồn kho

```http
POST /products/listProductsWithStockforPartner
Content-Type: application/json
```

Payload bổ sung:

```json
{
  "productstoreid": "..."
}
```

`Product Store ID` được nhập trong màn hình cấu hình plugin.

---

## 3. Menu quản trị

Sau khi plugin được cài và kích hoạt:

```text
WP Admin
└── Cún Chic × Abit
    ├── Cấu hình
    └── Đồng bộ sản phẩm
```

Quyền truy cập hiện tại:

```text
manage_woocommerce
```

### Cấu hình

Các field:

| Field | Ý nghĩa |
|---|---|
| Abit Base URL | Mặc định `https://new.abitstore.vn` |
| Access Token | Token do Abit cấp |
| Partner Name / Mã shop | `partner_name` gửi sang Abit |
| Product Store ID / Kho Abit | Dùng cho API tồn kho |
| Sản phẩm mỗi trang | `limit`, hiện giới hạn 1–500 |

Access Token được lưu trong WordPress options, không nằm trong repository.

**Không commit token thật lên GitHub.**

---

## 4. Luồng đồng bộ hiện tại

```text
Admin bấm "Bắt đầu đồng bộ"
        ↓
AJAX gọi page = 0
        ↓
Abit listProductsforPartner
        ↓
Product Mapper
        ↓
Tìm WooCommerce product
        ↓
_cunchici_abit_product_id có rồi?
    ↓ Có               ↓ Không
 Update          thử tìm bằng SKU
                         ↓
                   Có → Update
                   Không → Create
        ↓
WC_Product_Simple
        ↓
Lưu Abit Product ID + last synced time
        ↓
page++
        ↓
Lặp đến khi số item < limit
```

Frontend admin gọi từng page riêng thay vì một request PHP cực dài để giảm nguy cơ timeout.

Có bảo vệ tối đa `5000` trang.

---

## 5. Mapping sản phẩm

Mapping hiện tại nằm tại:

```text
includes/class-cunchici-abit-product-mapper.php
```

### Mapping chính

| Abit | WooCommerce |
|---|---|
| `productid` | post meta `_cunchici_abit_product_id` |
| `productcode` | SKU |
| `productname` | Product name |
| `unit_price` | Regular price + current price |
| màu | Custom attribute `Màu sắc` |
| size | Custom attribute `Size` |
| `description` | Description |
| `short_description` | Short description |

Giá fallback hiện tại:

```text
unit_price
→ price
→ gia_daily
```

Nếu nghiệp vụ của Cún Chic yêu cầu dùng giá khác, sửa tại mapper.

### Màu và Size

Tài liệu/public response chưa đủ chắc chắn để khóa duy nhất một tên field cho dữ liệu shop thực tế, nên mapper hiện hỗ trợ một số alias thường gặp.

Màu:

```text
color
colorname
mausac
mau_sac
productcolor
```

Size:

```text
size
sizename
kichthuoc
kich_thuoc
productsize
```

**Việc cần làm sau lần Test kết nối đầu tiên:** xem `first_product_keys` do plugin hiển thị, xác định tên field thật của Abit shop và thu hẹp mapper về field chính xác.

Không nên duy trì alias đoán lâu dài nếu payload thật đã xác định được.

---

## 6. Tồn kho

Tồn kho nằm ở endpoint riêng có `productstoreid`.

Plugin hiện:

1. Gọi danh sách sản phẩm.
2. Nếu đã cấu hình Product Store ID, gọi thêm API sản phẩm tồn kho cùng page.
3. Ghép dữ liệu theo `productid`.
4. Nếu đọc được số lượng:
   - `manage_stock = true`
   - cập nhật `stock_quantity`
   - `> 0` → `instock`
   - `0` → `outofstock`

Mapper hiện hỗ trợ một số tên field tồn kho có thể gặp:

```text
stock
quantity
qty
qtyinstock
quantityinstock
tonkho
so_luong_ton
inventory
```

**Cần xác nhận field tồn kho thật từ response Abit trước khi coi Phase 1 là hoàn tất production.**

Nếu chưa nhập Product Store ID, sản phẩm vẫn sync nhưng stock không bị chỉnh và UI sẽ báo cảnh báo.

---

## 7. Bản đồ file

### `cunchici-abit.php`

Plugin bootstrap.

Trách nhiệm:

- Khai báo plugin metadata/version/path.
- Load các class.
- Khởi tạo Settings, API client, Product Sync và Admin UI.

Sửa file này khi:

- Thêm service/class cấp cao mới.
- Đổi version plugin.
- Đổi bootstrap lifecycle.

### `includes/class-cunchici-abit-settings.php`

Quản lý cấu hình plugin.

Trách nhiệm:

- Default values.
- Đọc WordPress option `cunchici_abit_settings`.
- Sanitize settings.
- Validate plugin đã có token/partner chưa.

Sửa file này khi:

- Thêm field cấu hình mới.
- Đổi default Base URL.
- Đổi validation/sanitize settings.

### `includes/class-cunchici-abit-api.php`

HTTP client Abit.

Trách nhiệm:

- Gửi POST JSON.
- Tự thêm `access_token` và `partner_name`.
- Timeout 30 giây.
- Parse JSON/error.
- `list_products()`.
- `list_products_with_stock()`.

Sửa file này khi:

- Abit đổi endpoint/request format.
- Thêm API mới.
- Thêm retry/backoff.
- Thêm chuẩn hóa lỗi API.

### `includes/class-cunchici-abit-product-mapper.php`

Nơi duy nhất ưu tiên xử lý mapping field Abit → dữ liệu nội bộ.

Trách nhiệm:

- Product ID.
- SKU.
- Name.
- Price.
- Color.
- Size.
- Description.
- Images normalization (đã có parser nhưng chưa import media ở Phase 1 hiện tại).
- Stock quantity normalization.

**Nếu sai màu, size, giá hoặc field tồn kho, kiểm tra file này đầu tiên.**

### `includes/class-cunchici-abit-product-sync.php`

Business logic đồng bộ WooCommerce.

Trách nhiệm:

- Gọi API từng page.
- Extract danh sách row từ response wrapper.
- Ghép stock theo Abit Product ID.
- Upsert product.
- Luôn dùng `WC_Product_Simple` cho item mới.
- Không tự convert variable/external product sang simple để tránh mất dữ liệu.
- Set SKU/name/description/price/attributes/stock.
- Lưu meta đồng bộ.
- Trả summary từng page.

Meta hiện dùng:

```text
_cunchici_abit_product_id
_cunchici_abit_last_synced_at
```

Nếu cần thay chiến lược upsert, sửa file này.

### `includes/class-cunchici-abit-admin.php`

Admin UI + AJAX entry points.

Trách nhiệm:

- Tạo menu.
- Trang Settings.
- Trang Sync.
- Test connection.
- Nonce/capability check.
- Client-side pagination loop.
- Hiển thị log sync.

Nếu muốn thay giao diện, thêm nút preview/dry-run hoặc thêm cron trigger, bắt đầu từ file này.

---

## 8. Test kết nối trước khi sync thật

Trang **Cấu hình** có nút:

```text
Test kết nối Abit
```

Nó gọi:

```text
page = 0
limit = 1
```

và **không ghi sản phẩm vào WooCommerce**.

Kết quả hiện hiển thị:

- Kết nối thành công/thất bại.
- `top_level_keys` của API response.
- `first_product_keys` của sản phẩm mẫu.

Mục đích của `first_product_keys` là xác minh chính xác schema Abit của shop trước khi chốt mapper màu/size.

Không hiển thị Access Token trong kết quả test.

---

## 9. Upsert và chống trùng

Thứ tự tìm sản phẩm:

```text
1. _cunchici_abit_product_id == Abit productid
2. Nếu chưa có → tìm theo WooCommerce SKU == Abit productcode
3. Không có nữa → tạo product mới
```

Sau khi save luôn gắn:

```text
_cunchici_abit_product_id
```

Do đó những lần sync sau ưu tiên ID Abit thay vì phụ thuộc SKU.

Nếu SKU mới từ Abit đang thuộc một WooCommerce product khác, plugin không cưỡng ép set SKU để tránh WooCommerce duplicate-SKU exception.

---

## 10. Quy tắc an toàn hiện tại

- Chỉ user có `manage_woocommerce` mới truy cập/sync.
- AJAX có nonce.
- Token không đưa vào JS.
- Token không commit GitHub.
- Một sản phẩm lỗi không làm dừng các sản phẩm còn lại trong page.
- Không convert một WooCommerce product không phải `simple` sang simple tự động.
- Không xóa sản phẩm khi Abit không trả về.
- Không hard-delete dữ liệu website.
- Chưa có cron tự động; chỉ chạy thủ công từ admin.

---

## 11. Việc còn phải xác minh trước production

### Bắt buộc

- [ ] Cài plugin vào staging/website.
- [ ] Nhập Access Token + Partner Name.
- [ ] Nhập Product Store ID đúng kho.
- [ ] Bấm **Test kết nối Abit**.
- [ ] Ghi nhận `first_product_keys` thực tế.
- [ ] Xác định chính xác field màu.
- [ ] Xác định chính xác field size.
- [ ] Xác định chính xác field số lượng tồn kho.
- [ ] Test sync với catalog nhỏ/staging trước.
- [ ] Kiểm tra chạy lần 2 không tạo duplicate.
- [ ] Kiểm tra SKU, giá, màu, size, tồn kho trên WooCommerce.

### Nên làm sau khi payload thật đã xác minh

- [ ] Thu hẹp alias mapper về field Abit chính xác.
- [ ] Tighten `extract_rows()` theo response envelope thực tế.
- [ ] Thêm retry/backoff cho lỗi mạng/5xx/429.
- [ ] Thêm dry-run/preview trước khi ghi DB.
- [ ] Thêm lịch tự động/cron nếu cần.
- [ ] Thêm import ảnh nếu nghiệp vụ yêu cầu.
- [ ] Thêm log lịch sử mỗi sync run vào DB.

---

## 12. Khi cần sửa gì thì vào đâu?

```text
Token / Partner / Kho / Limit
→ includes/class-cunchici-abit-settings.php
→ includes/class-cunchici-abit-admin.php

Sai endpoint / payload Abit
→ includes/class-cunchici-abit-api.php

Sai SKU / giá / màu / size / tồn kho
→ includes/class-cunchici-abit-product-mapper.php

Sai logic create/update/duplicate/stock
→ includes/class-cunchici-abit-product-sync.php

Sai menu / nút test / nút sync / AJAX / log
→ includes/class-cunchici-abit-admin.php

Thêm class/service mới hoặc đổi version
→ cunchici-abit.php
```

---

## 13. Roadmap

### Phase 1 — Product sync

- [x] Plugin bootstrap.
- [x] Admin menu.
- [x] Settings/token UI.
- [x] Test API connection.
- [x] Product list API client.
- [x] Simple product upsert.
- [x] SKU.
- [x] Price.
- [x] Color/Size attribute framework.
- [x] Stock API framework.
- [ ] Verify exact Abit color/size/stock fields using real shop payload.
- [ ] Production/staging validation.

### Phase 2

- [ ] Product images.
- [ ] Categories/brands mapping.
- [ ] Automatic scheduled sync.
- [ ] Sync history/log table.
- [ ] Incremental sync if Abit supports a reliable modified-time strategy.

### Phase 3

- [ ] Orders.
- [ ] Customers.
- [ ] Order status/inventory workflows as required.

---

## 14. Nguyên tắc duy trì README

Mỗi commit thay đổi integration phải cập nhật README nếu có một trong các việc sau:

1. Thêm/xóa/đổi tên file.
2. Di chuyển trách nhiệm giữa các class.
3. Thêm endpoint Abit.
4. Đổi field mapping.
5. Đổi meta/schema DB.
6. Đổi settings.
7. Đổi cách sync/pagination/retry.
8. Đổi mô hình sản phẩm.

Mục tiêu: **README phải phản ánh code thật, không phải tài liệu dự kiến.**
