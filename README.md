# Cunchici × Abit Integration

Tài liệu này là **bản đồ kỹ thuật trung tâm** cho repository `cunchici-abit`.

Mục tiêu: bất kỳ ai cần sửa hoặc mở rộng phần kết nối giữa **cunchici.vn** và **Abit** chỉ cần đọc file này để biết:

- Hệ thống đang làm gì.
- Luồng dữ liệu đi theo hướng nào.
- API Abit nào đang được sử dụng.
- File nào chịu trách nhiệm cho phần nào.
- Cần sửa ở đâu khi thay đổi logic.
- Biến môi trường nào cần cấu hình.
- Quy tắc đồng bộ dữ liệu và xử lý lỗi.

> **QUY TẮC BẮT BUỘC:** Mỗi lần thêm, xóa, đổi tên hoặc thay đổi trách nhiệm của một file quan trọng trong project, phải cập nhật mục **“Bản đồ file”** trong README này cùng commit.

---

## 1. Trạng thái hiện tại

Repository hiện đang ở giai đoạn khởi tạo.

Chức năng đầu tiên cần triển khai:

> **Đồng bộ danh sách sản phẩm từ Abit về hệ thống cunchici.vn qua Abit Open API.**

Ở giai đoạn này README mô tả kiến trúc và logic dự kiến. Khi code thật được tạo, tên file và trách nhiệm từng file phải được cập nhật lại để phản ánh đúng code thực tế.

---

## 2. Nguồn tài liệu chính thức

Abit Open API:

- Tổng quan: https://apidocs.abit.vn/
- Khởi tạo Access Token: https://apidocs.abit.vn/khoi-tao-access-token
- Danh sách sản phẩm: https://apidocs.abit.vn/san-pham/danh-sach-san-pham
- Danh sách sản phẩm có tồn kho: https://apidocs.abit.vn/san-pham/danh-sach-san-pham-ton-kho
- Chi tiết sản phẩm: https://apidocs.abit.vn/san-pham/chi-tiet-san-pham

Base URL hiện tại theo tài liệu Abit:

```text
https://new.abitstore.vn
```

---

## 3. Phạm vi Phase 1 – Đồng bộ sản phẩm

Phase 1 chỉ tập trung vào việc lấy danh sách sản phẩm từ Abit và chuẩn hóa dữ liệu để cunchici.vn có thể lưu/cập nhật sản phẩm.

### API sử dụng

```http
POST https://new.abitstore.vn/products/listProductsforPartner
Content-Type: application/json
```

Body:

```json
{
  "access_token": "<ABIT_ACCESS_TOKEN>",
  "partner_name": "<ABIT_PARTNER_NAME>",
  "page": 0,
  "limit": 100
}
```

Theo tài liệu Abit:

| Field | Kiểu | Ý nghĩa |
|---|---|---|
| `access_token` | string | Access Token được chủ tài khoản Abit tạo và cấp quyền |
| `partner_name` | string | Mã shop khi đăng ký Abit |
| `page` | int | Trang cần lấy; mặc định là `0` |
| `limit` | int | Số bản ghi mỗi trang; mặc định là `10` |

### Response sản phẩm có thể chứa

Các field quan trọng hiện thấy trong tài liệu:

| Abit field | Ý nghĩa dự kiến | Cách dùng phía Cunchici |
|---|---|---|
| `productid` | ID sản phẩm trên Abit | **External ID chính để đồng bộ/upsert** |
| `productcode` | Mã/SKU sản phẩm | SKU hiển thị/tìm kiếm |
| `productname` | Tên sản phẩm | Tên sản phẩm |
| `alias` | Bí danh | Lưu nếu website cần |
| `usageunit` | Đơn vị tính | Đơn vị sản phẩm |
| `gia_daily` | Giá daily | Chỉ map khi nghiệp vụ Cunchici cần |
| `unit_price` | Đơn giá | Giá bán nguồn từ Abit |
| `imagename` | Chuỗi JSON chứa danh sách ảnh | Parse thành mảng ảnh trước khi lưu |
| `weight` | Khối lượng | Chuẩn hóa về kiểu số trước khi lưu |
| `taxpercentage` | Thuế suất | Chuẩn hóa kiểu số |
| `inventorytype` | Loại tồn kho | Lưu nếu cần quản lý tồn kho |
| `createdtime` | Thời gian tạo trên Abit | Metadata nguồn |
| `modifiedtime` | Thời gian sửa trên Abit | Dùng hỗ trợ incremental sync sau này |
| `status` | Trạng thái | Map sang trạng thái active/inactive nếu phù hợp nghiệp vụ |
| `tonkho_toithieu` | Tồn kho tối thiểu | Metadata tồn kho |
| `tonkho_toida` | Tồn kho tối đa | Metadata tồn kho |
| `default_image` | Ảnh mặc định | Ưu tiên nếu Abit trả giá trị hợp lệ |
| `short_description` | Mô tả ngắn | Nội dung sản phẩm |
| `description` | Mô tả đầy đủ | Nội dung sản phẩm |
| `productcategory` | Nhóm/danh mục | Cần xác định quy tắc map category ở phase sau |
| `brandid` | ID thương hiệu | External brand ID |
| `brandname` | Tên thương hiệu | Tên thương hiệu |

> Không nên lưu nguyên response rồi để UI tự xử lý. Nên có một lớp **mapper/normalizer** để chuyển dữ liệu Abit sang cấu trúc sản phẩm nội bộ của Cunchici.

---

## 4. Hướng dữ liệu

Phase 1:

```text
Abit
  ↓
Abit Product API
  ↓
Abit Client
  ↓
Product Mapper / Normalizer
  ↓
Product Sync Service
  ↓
Product Repository / Database Adapter
  ↓
Cunchici Database
  ↓
cunchici.vn
```

Ở Phase 1, Abit được xem là **nguồn dữ liệu sản phẩm đầu vào**.

Chưa triển khai chiều ngược lại từ Cunchici → Abit cho đến khi có yêu cầu nghiệp vụ rõ ràng.

---

## 5. Nguyên tắc đồng bộ sản phẩm

### 5.1. Khóa định danh

Sử dụng:

```text
abit_product_id = productid
```

làm khóa external ID chính để nhận diện cùng một sản phẩm giữa các lần sync.

Không nên chỉ dựa vào `productcode` vì SKU/mã sản phẩm có thể thuộc logic nghiệp vụ và có khả năng được chỉnh sửa.

### 5.2. Upsert thay vì insert mù

Mỗi sản phẩm lấy từ Abit phải đi theo logic:

```text
Tìm sản phẩm theo abit_product_id
        ↓
Có rồi? ── Có ──> Update các field được phép đồng bộ
   │
   Không
   ↓
Tạo sản phẩm mới
```

Mục tiêu là chạy đồng bộ nhiều lần vẫn cho cùng một kết quả, không sinh sản phẩm trùng.

### 5.3. Không để một sản phẩm lỗi làm dừng toàn bộ batch

Ví dụ batch có 100 sản phẩm:

- Sản phẩm A lỗi parse ảnh → log lỗi A.
- Tiếp tục sync B, C, D...
- Cuối job trả về tổng kết success / failed / skipped.

### 5.4. Phân trang

Bắt đầu:

```text
page = 0
```

Mỗi vòng gọi API:

1. Gửi `page` + `limit`.
2. Normalize response.
3. Upsert từng sản phẩm.
4. Nếu số lượng trả về nhỏ hơn `limit` thì coi như đã đến trang cuối.
5. Nếu vẫn đủ `limit`, tăng `page += 1` và gọi tiếp.

Cần bổ sung điều kiện bảo vệ `maxPages` hoặc `maxItems` trong chế độ test để tránh vòng lặp ngoài ý muốn.

### 5.5. Ảnh sản phẩm

`imagename` trong response mẫu của Abit là một **string chứa JSON array**, ví dụ về mặt cấu trúc:

```json
[
  {
    "isDefault": true,
    "imgSrc": "https://..."
  }
]
```

Mapper phải:

1. Kiểm tra `imagename` có dữ liệu hay không.
2. Parse JSON an toàn.
3. Bỏ phần tử không có URL hợp lệ.
4. Xác định ảnh mặc định từ `isDefault`.
5. Không làm fail toàn sản phẩm chỉ vì JSON ảnh lỗi.

### 5.6. Kiểu dữ liệu

Abit có thể trả một số giá trị số dưới dạng string, ví dụ:

```text
"979000.000"
"11000"
"10.000"
```

Không truyền thẳng các giá trị này sang business layer nếu database yêu cầu number.

Mapper chịu trách nhiệm chuẩn hóa:

```text
unit_price -> number/decimal
weight -> number
 taxpercentage -> number/decimal
```

---

## 6. Bảo mật

**TUYỆT ĐỐI KHÔNG commit Access Token Abit lên GitHub.**

Các secret phải để trong biến môi trường.

Đề xuất:

```env
ABIT_BASE_URL=https://new.abitstore.vn
ABIT_ACCESS_TOKEN=
ABIT_PARTNER_NAME=
ABIT_PRODUCT_SYNC_LIMIT=100
```

Repository chỉ được commit file mẫu:

```text
.env.example
```

không chứa secret thật.

File `.env`, `.env.local`, credential hoặc token thật phải nằm trong `.gitignore`.

Nếu token từng bị commit nhầm, phải:

1. Xóa token khỏi code.
2. Tạo token mới trên Abit.
3. Thu hồi/thay token cũ nếu Abit hỗ trợ.
4. Không chỉ xóa commit rồi tiếp tục dùng token cũ.

---

## 7. Kiến trúc đề xuất

> Đây là cấu trúc **dự kiến**, chưa phải cấu trúc code đã tồn tại. Khi xác định stack thật của website (ví dụ Next.js, Laravel, Node.js...), tên file/extension có thể thay đổi nhưng trách nhiệm từng lớp nên giữ tương tự.

```text
/
├── README.md
├── .env.example
├── src/
│   ├── integrations/
│   │   └── abit/
│   │       ├── config.*
│   │       ├── client.*
│   │       ├── types.*
│   │       ├── products.*
│   │       └── mappers/
│   │           └── product.*
│   ├── services/
│   │   └── product-sync.*
│   ├── repositories/
│   │   └── product-repository.*
│   └── api-or-jobs/
│       └── sync-abit-products.*
└── tests/
    └── abit-product-sync.*
```

---

## 8. Bản đồ file

Đây là phần quan trọng nhất cần được duy trì xuyên suốt project.

### File đang tồn tại

| File | Trách nhiệm | Khi nào cần sửa |
|---|---|---|
| `README.md` | Tài liệu kiến trúc, luồng dữ liệu, API, bản đồ file và quy tắc bảo trì | Mỗi khi thêm/xóa/đổi trách nhiệm file hoặc thay đổi logic tích hợp |

### File dự kiến sẽ tạo ở Phase 1

| File dự kiến | Trách nhiệm | Khi nào cần sửa |
|---|---|---|
| `.env.example` | Khai báo tên các biến môi trường cần cho tích hợp Abit nhưng không chứa secret | Khi Abit cần thêm config/token/base URL |
| `src/integrations/abit/config.*` | Đọc và validate `ABIT_BASE_URL`, `ABIT_ACCESS_TOKEN`, `ABIT_PARTNER_NAME` | Khi đổi cách cấu hình Abit |
| `src/integrations/abit/client.*` | HTTP client dùng chung: POST request, timeout, retry, parse lỗi | Khi đổi cách gọi API, auth, timeout, retry |
| `src/integrations/abit/types.*` | Type/schema request-response của Abit | Khi API Abit thêm/đổi field |
| `src/integrations/abit/products.*` | Hàm gọi API danh sách/chi tiết/tồn kho sản phẩm | Khi thay endpoint hoặc tham số sản phẩm |
| `src/integrations/abit/mappers/product.*` | Chuyển sản phẩm Abit thành model sản phẩm nội bộ | Khi thay mapping giá, ảnh, trạng thái, mô tả, brand... |
| `src/services/product-sync.*` | Điều phối toàn bộ job: pagination → map → upsert → tổng kết | Khi đổi chiến lược sync |
| `src/repositories/product-repository.*` | Đọc/ghi sản phẩm vào database, tìm theo `abit_product_id`, upsert | Khi schema DB hoặc ORM thay đổi |
| `src/api-or-jobs/sync-abit-products.*` | Điểm kích hoạt sync: API admin, cron job hoặc CLI | Khi đổi cách chạy sync |
| `tests/abit-product-sync.*` | Test mapper, pagination, lỗi API và idempotency | Khi thay logic sync |

> Khi code thật bắt đầu được tạo, hãy thay các path `*` ở trên bằng filename thật.

---

## 9. Trách nhiệm từng lớp

### `AbitConfig`

Chỉ chịu trách nhiệm cấu hình.

Không gọi API.
Không xử lý database.
Không map sản phẩm.

Phải fail sớm nếu thiếu credential bắt buộc.

### `AbitClient`

Chỉ chịu trách nhiệm giao tiếp HTTP với Abit:

- Base URL.
- `Content-Type: application/json`.
- Timeout.
- Retry có kiểm soát.
- Parse response.
- Chuẩn hóa lỗi HTTP/API.

Không chứa business logic đồng bộ sản phẩm.

### `AbitProductsApi`

Chịu trách nhiệm các endpoint thuộc nhóm sản phẩm.

Phase 1 cần ít nhất:

```text
listProducts(page, limit)
```

Sau này có thể thêm:

```text
listProductsWithStock(productStoreId, page, limit)
getProductDetail(productId)
```

### `ProductMapper`

Chịu trách nhiệm chuyển:

```text
Abit Product
    ↓
Cunchici Product Input
```

Mọi logic kiểu:

- Parse `imagename`.
- Convert giá string → decimal.
- Convert weight.
- Chọn ảnh mặc định.
- Map status.
- Trim text.
- Normalize null/empty.

phải ưu tiên đặt tại đây thay vì rải trong controller hoặc repository.

### `ProductSyncService`

Đây là business orchestration chính.

Pseudo flow:

```text
syncAllProducts()
  page = 0

  loop:
    products = abitProductsApi.listProducts(page, limit)

    if empty:
      break

    for product in products:
      try:
        normalized = productMapper.map(product)
        productRepository.upsertByAbitProductId(normalized)
        mark success
      catch error:
        log error
        mark failed

    if products.length < limit:
      break

    page++

  return summary
```

### `ProductRepository`

Đây là lớp duy nhất nên biết chi tiết ORM/database đối với logic sync sản phẩm.

Ví dụ trách nhiệm:

```text
findByAbitProductId()
create()
update()
upsertByAbitProductId()
```

Không gọi Abit API trong repository.

### `sync-abit-products`

Là entry point để người/admin/server kích hoạt sync.

Có thể là một trong các hình thức sau, tùy stack thật:

- Admin API route.
- Server Action.
- CLI command.
- Queue job.
- Cron job.

Entry point chỉ validate quyền chạy và gọi `ProductSyncService`; không nhét toàn bộ logic đồng bộ vào route/controller.

---

## 10. Kết quả job đồng bộ nên trả về

Đề xuất format nội bộ:

```json
{
  "startedAt": "...",
  "finishedAt": "...",
  "pagesFetched": 0,
  "fetched": 0,
  "created": 0,
  "updated": 0,
  "skipped": 0,
  "failed": 0,
  "errors": []
}
```

Mục tiêu là admin có thể biết job vừa chạy có thực sự đồng bộ được dữ liệu hay không.

Không nên chỉ trả:

```json
{ "success": true }
```

vì không đủ để debug.

---

## 11. Logging

Mỗi lần sync cần có log đủ để điều tra lỗi nhưng **không được log Access Token**.

Nên log:

```text
sync_id
page
abit_product_id
productcode
action = created | updated | skipped | failed
error_code
error_message
duration
```

Không log:

```text
ABIT_ACCESS_TOKEN
full Authorization/credential payload
```

---

## 12. Retry và timeout

Các request tới Abit là network request nên bắt buộc có timeout.

Đề xuất ban đầu:

```text
connect/read timeout: 10–30 giây tùy hạ tầng
retry: tối đa 2–3 lần cho lỗi mạng hoặc 5xx
```

Không retry mù mọi lỗi.

Ví dụ:

- `400/401/403`: thường là request/config/auth sai → không retry liên tục.
- `429`: có thể retry với backoff nếu Abit rate-limit.
- `500/502/503/504`: có thể retry có backoff.
- Network timeout/reset: có thể retry.

Thông số thật sẽ được điều chỉnh sau khi test với tài khoản Abit thật.

---

## 13. Database – dữ liệu tối thiểu cần có

Chưa biết schema hiện tại của cunchici.vn, nhưng để sync an toàn cần ít nhất một field liên kết với Abit:

```text
abit_product_id
```

Khuyến nghị có unique index:

```text
UNIQUE(abit_product_id)
```

Các field bổ sung hữu ích:

```text
abit_modified_time
abit_last_synced_at
abit_sync_hash
```

Trong đó:

- `abit_modified_time`: thời gian Abit báo sản phẩm thay đổi.
- `abit_last_synced_at`: lần gần nhất Cunchici sync sản phẩm này.
- `abit_sync_hash`: optional, dùng so sánh payload để bỏ qua update không cần thiết.

Không tự ý migration production trước khi kiểm tra schema thật của website.

---

## 14. Xử lý sản phẩm bị xóa / ngừng hoạt động

Không nên xóa ngay sản phẩm trên Cunchici chỉ vì một lần sync không thấy nó trong một page.

Lý do có thể gồm:

- Pagination lỗi.
- API Abit timeout giữa chừng.
- Filter hoặc quyền API thay đổi.
- Sản phẩm tạm ẩn.

Chiến lược an toàn hơn cho phase sau:

1. Full sync có `sync_run_id`.
2. Đánh dấu các sản phẩm nhìn thấy trong lần sync.
3. Chỉ sau khi full sync hoàn tất thành công mới xử lý sản phẩm không còn xuất hiện.
4. Ưu tiên soft-disable thay vì hard-delete.

---

## 15. Đồng bộ tồn kho – Phase tiếp theo

Abit có endpoint riêng cho danh sách sản phẩm kèm tồn kho:

```http
POST /products/listProductsWithStockforPartner
```

API này cần thêm:

```text
productstoreid
```

ID kho/chi nhánh có thể lấy từ API danh sách kho của Abit.

Không trộn logic tồn kho vào Phase 1 trước khi xác định rõ:

- Cunchici có bao nhiêu kho.
- Kho nào tương ứng với kho nào bên Abit.
- Website hiển thị tồn kho tổng hay theo kho.
- Khi hết hàng thì website xử lý trạng thái thế nào.

---

## 16. Chi tiết sản phẩm – Phase tiếp theo

Abit có endpoint:

```http
POST /products/detailtProductforPartner
```

Input có `productid` lấy từ API danh sách sản phẩm.

Endpoint chi tiết có thêm nhiều field so với list, ví dụ barcode, thông tin kích thước, vendor, xuất xứ...

Chỉ gọi API detail cho từng sản phẩm nếu thực sự cần field mà API list không có, vì cách gọi N sản phẩm → N request sẽ tốn thời gian và dễ bị rate-limit hơn.

---

## 17. Cách triển khai Phase 1 an toàn

Thứ tự đề xuất:

### Bước 1 – Xác định stack website

Cần biết code thật của cunchici.vn đang dùng gì:

- Framework/backend.
- ORM.
- Database.
- Model sản phẩm hiện tại.
- Cơ chế deploy.

### Bước 2 – Thêm config Abit

Tạo `.env.example` và config loader.

### Bước 3 – Viết Abit HTTP client

Test gọi được API list products với credential thật ở server/local.

### Bước 4 – Viết schema/type và mapper

Không ghi DB ngay.

Trước tiên log/inspect một vài sản phẩm đã normalize.

### Bước 5 – Kết nối database

Thêm `abit_product_id` nếu schema hiện tại chưa có.

### Bước 6 – Viết upsert

Test chạy 2 lần liên tiếp không sinh duplicate.

### Bước 7 – Viết pagination full sync

Test với limit nhỏ trước, sau đó tăng limit.

### Bước 8 – Thêm entry point admin/cron

Không public endpoint sync ra Internet mà không có auth.

### Bước 9 – Logging và summary

Có kết quả created / updated / failed rõ ràng.

### Bước 10 – Deploy thử nghiệm

Chạy giới hạn số page/item trước khi full sync production.

---

## 18. Checklist trước khi bật production sync

- [ ] Có `ABIT_ACCESS_TOKEN` trong secret/env của server, không nằm trong Git.
- [ ] Có `ABIT_PARTNER_NAME` đúng shop.
- [ ] Gọi API Abit thành công.
- [ ] Mapper parse được `imagename` kể cả khi null/lỗi JSON.
- [ ] Giá và số được normalize đúng.
- [ ] Database có external ID `abit_product_id`.
- [ ] `abit_product_id` có unique constraint/index nếu phù hợp schema.
- [ ] Chạy sync 2 lần không tạo duplicate.
- [ ] Một sản phẩm lỗi không làm fail cả batch.
- [ ] Có timeout HTTP.
- [ ] Có retry có kiểm soát.
- [ ] Log không chứa Access Token.
- [ ] Endpoint/job kích hoạt sync được bảo vệ quyền truy cập.
- [ ] Có giới hạn test trước khi chạy full catalog.
- [ ] README đã cập nhật đúng filename/code thật.

---

## 19. Roadmap tích hợp Abit

### Phase 1

- [ ] Kết nối Abit API.
- [ ] Lấy danh sách sản phẩm.
- [ ] Normalize dữ liệu.
- [ ] Upsert sản phẩm vào Cunchici.
- [ ] Full pagination.
- [ ] Logging + sync summary.

### Phase 2

- [ ] Đồng bộ tồn kho theo kho/chi nhánh.
- [ ] Lấy chi tiết sản phẩm khi cần.
- [ ] Map category.
- [ ] Map brand.
- [ ] Quy tắc sản phẩm inactive/deleted.
- [ ] Incremental sync theo `modifiedtime` nếu API/logic cho phép.

### Phase 3

- [ ] Đồng bộ đơn hàng.
- [ ] Đồng bộ khách hàng.
- [ ] Đồng bộ trạng thái đơn hàng.
- [ ] Các luồng hai chiều nếu nghiệp vụ yêu cầu.

---

## 20. Quy tắc cập nhật README cho các lần làm việc sau

Mỗi PR/commit làm thay đổi integration phải cập nhật README nếu có một trong các trường hợp:

1. Tạo file mới có trách nhiệm business/integration.
2. Xóa file.
3. Đổi tên file.
4. Chuyển logic từ file A sang file B.
5. Thêm endpoint Abit mới.
6. Đổi mapping field.
7. Đổi schema database phục vụ sync.
8. Đổi biến môi trường.
9. Đổi cách trigger sync.
10. Đổi chiến lược retry, pagination hoặc error handling.

Format cập nhật **Bản đồ file**:

```text
File | Trách nhiệm | Khi nào cần sửa
```

Mục tiêu là không để README trở thành tài liệu cũ khác với code.

---

## 21. Quy tắc dành cho AI/developer khi tiếp tục project

Trước khi sửa code:

1. Đọc README này.
2. Kiểm tra file thật trong repo.
3. Xác định đúng lớp chịu trách nhiệm.
4. Không nhét logic API + mapping + database vào cùng một route/controller nếu có thể tách.
5. Sau khi sửa code, cập nhật **Bản đồ file**.
6. Không commit secret/token.

Khi có bug, tra theo hướng:

```text
Lỗi gọi Abit API
→ integration/abit/client

Sai endpoint/request sản phẩm
→ integration/abit/products

Sai field/giá/ảnh/status
→ integration/abit/mappers/product

Sai pagination/upsert flow
→ services/product-sync

Sai query/database
→ repositories/product-repository

Không chạy được từ admin/cron
→ api-or-jobs/sync-abit-products
```

---

## 22. Ghi chú kỹ thuật cần xác minh khi bắt đầu code thật

Các điểm chưa nên đoán khi repo chưa có code website:

- Framework/backend của cunchici.vn.
- Database và ORM đang dùng.
- Bảng/model sản phẩm hiện tại.
- Field nào trên website được phép Abit ghi đè.
- Giá nào là giá nguồn chính: `unit_price`, `gia_daily` hay logic riêng.
- Cách map danh mục Abit ↔ Cunchici.
- Cách map thương hiệu.
- Có cần tải ảnh về storage riêng hay dùng URL remote.
- Cơ chế sản phẩm biến thể/SKU nếu website đang có.
- Kho Abit nào tương ứng với tồn kho website.
- Tần suất chạy sync mong muốn.

Các quyết định trên phải được cập nhật vào README khi đã xác định.

---

## 23. Kết luận kiến trúc Phase 1

Nguyên tắc quan trọng nhất:

```text
Abit API
  ≠ Database code
  ≠ Mapping code
  ≠ Sync orchestration
  ≠ API/Cron trigger
```

Mỗi phần có trách nhiệm riêng để khi Abit đổi API hoặc website đổi schema, chỉ cần sửa đúng lớp liên quan thay vì phải sửa toàn bộ hệ thống.

External key ưu tiên cho sản phẩm:

```text
Abit productid -> Cunchici abit_product_id
```

Luồng Phase 1:

```text
Fetch pages from Abit
→ Validate
→ Normalize
→ Upsert by abit_product_id
→ Log result
→ Return sync summary
```

Đây là nền tảng để sau này mở rộng sang tồn kho, đơn hàng, khách hàng và các API khác của Abit mà không làm code bị rối.