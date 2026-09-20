# 数据模型与 API

本文档定义初始数据模型和接口边界。具体字段可在开发时小幅调整，但业务约束和状态含义应保持一致。

## 1. 数据表

### 1.1 `users`

```text
id
phone                       unique
access_token_hash
invite_code                 unique
invited_by_user_id          nullable, foreign key users.id
created_at
updated_at
```

### 1.2 `photos`

```text
id
user_id                     foreign key users.id
object_key                  unique
original_filename
original_file_hash          unique, SHA-256，压缩前原始文件
upload_file_hash            unique, SHA-256，实际上传文件
mime_type
file_size
width                       nullable
height                      nullable
upload_status               preparing / uploaded / failed
review_status               pending / normal / good / rejected
reviewed_at                 nullable
created_at
updated_at
```

### 1.3 `photo_likes`

```text
id
photo_id                    foreign key photos.id
user_id                     foreign key users.id
created_at

unique(photo_id, user_id)
```

应用层禁止 `photo.user_id == like.user_id`。权重查询也应显式排除这种记录。

### 1.4 `invitations`

```text
id
inviter_user_id             foreign key users.id
invitee_user_id             foreign key users.id, unique
status                      registered / uploaded / qualified / invalid
qualified_photo_id          nullable, foreign key photos.id
qualified_at                nullable
created_at
updated_at
```

### 1.5 `admin_review_logs`

```text
id
photo_id                    foreign key photos.id
previous_status
new_status
created_at
undone_at                   nullable，撤销时间
```

### 1.6 `draw_rounds`

```text
id
round_number
draw_count
status                      prepared / completed / cancelled
candidate_snapshot          json
settings_snapshot           json
created_at
completed_at                nullable
```

### 1.7 `draw_winners`

```text
id
draw_round_id               foreign key draw_rounds.id
user_id                     foreign key users.id
photo_id                    foreign key photos.id
source                      photo / invitation
user_weight                 integer
photo_weight                integer
position
created_at

unique(draw_round_id, user_id)
unique(draw_round_id, position)
```

## 2. API 约定

- API 前缀：`/api`
- 用户身份：`Authorization: Bearer <token>`
- 管理员身份：单独的管理员 Bearer Token
- 时间格式：ISO 8601
- 错误响应至少包含 `message` 和稳定的 `code`
- 分页优先使用 Laravel 标准分页结构
- 完整手机号只在用户本人接口中返回，照片墙、抽奖和后台列表默认只返回尾号

## 3. 身份接口

### `POST /api/auth/phone`

登记或恢复手机号身份。

请求：

```json
{
  "phone": "13800000000",
  "invite_code": "Ab3K9x"
}
```

响应：

```json
{
  "token": "plain-token-returned-on-login",
  "user": {
    "id": 1,
    "phone": "13800000000",
    "invite_code": "Xy8P2q"
  }
}
```

### `GET /api/me`

返回当前用户基本信息。

### `GET /api/me/stats`

响应示例：

```json
{
  "uploaded_count": 6,
  "pending_count": 2,
  "approved_count": 3,
  "rejected_count": 1
}
```

只统计已上传成功的照片；`normal` 和 `good` 均计入通过数量。接口不返回抽奖次数或用户总权重。

## 4. 上传接口

### `POST /api/uploads/prepare`

请求：

```json
{
  "photos": [
    {
      "original_file_hash": "sha256-hex-before-compression",
      "upload_file_hash": "sha256-hex-actually-uploaded",
      "filename": "IMG_0012.JPG",
      "mime_type": "image/jpeg",
      "file_size": 12345678
    }
  ]
}
```

行为：

- 单次最多接收 20 张，逐项校验类型、大小和两个 SHA-256。
- 在所有用户的照片中全局查重，不限定为当前用户。
- 原始哈希和实际上传哈希会与已有记录的两个哈希交叉比较，任意命中时返回 `duplicate`，不创建记录。
- 同一批次中后出现的交叉重复照片同样跳过。
- 非重复项创建 `preparing` 记录并返回 `ready`、照片 ID 和 Object Key；顶层返回 OSS 临时凭证及自定义上传地址，不向客户端返回 OSS Endpoint 或 Bucket 域名。
- 当前客户端为每张照片独立调用本接口，单张照片校验失败不会阻断其他照片继续检查和上传。

### `POST /api/uploads/{photo}/complete`

行为：

- 校验照片属于当前用户。
- 确认 OSS 对象存在。
- 将上传状态改为 `uploaded`，审核状态设为 `pending`。
- 已完成的照片再次提交时直接返回成功。
- OSS 对象不存在时返回冲突状态，不完成登记。
- 客户端应在每一张照片上传成功后立即调用，不等待整批照片上传结束。

### `GET /api/photos/mine`

返回当前用户的照片和上传、审核状态，按最新上传优先排列：

```json
{
  "photos": [
    {
      "id": 10,
      "original_filename": "IMG_0012.JPG",
      "mime_type": "image/jpeg",
      "file_size": 12345678,
      "upload_status": "uploaded",
      "review_status": "pending",
      "thumbnail_url": "https://example.com/wedding/photos/1/photo.jpg?x-oss-process=...",
      "created_at": "2026-09-20T08:00:00.000000Z"
    }
  ]
}
```

不返回文件哈希、OSS Object Key 或其他用户的照片。只有已经上传完成的照片包含 `thumbnail_url`；私有 Bucket 使用后端生成的一小时限时签名 URL，图片处理参数包含在签名中，链接域名统一使用配置的自定义域名。

图片 URL 预设：

- `thumbnail`：400 × 400 居中裁切，用于缩略图列表。
- `gallery`：长边不超过 1200 像素，用于移动端预览。
- `review`：长边不超过 1600 像素，用于审核预览。
- `draw`：长边不超过 1920 像素，用于抽奖大图。

## 5. 照片墙与点赞接口

### `GET /api/gallery`

只返回 `normal` 和 `good` 的照片，字段建议包括：

```json
{
  "id": 10,
  "thumbnail_url": "https://...",
  "preview_url": "https://...",
  "like_count": 8,
  "liked_by_me": true,
  "is_mine": false,
  "can_like": true,
  "created_at": "2026-09-19T12:00:00+08:00"
}
```

### `POST /api/photos/{photo}/like`

- 照片必须审核通过。
- 不能给自己的照片点赞。
- 重复调用保持幂等。

### `DELETE /api/photos/{photo}/like`

- 只删除当前用户的点赞。
- 重复调用保持幂等。

## 6. 邀请接口

### `GET /api/invitations/me`

返回：

- 邀请码
- 完整邀请链接
- 各状态邀请数量
- 邀请展示和奖励开关

二维码由前端根据邀请链接生成。

## 7. 审核后台接口

### `POST /api/admin/login`

使用环境变量中的固定密码，返回短期管理员 Token。

请求：

```json
{
  "password": "configured-admin-password"
}
```

响应：

```json
{
  "token": "short-lived-admin-token",
  "expires_at": "2026-09-20T20:00:00+00:00"
}
```

### `GET /api/admin/session`

使用管理员 Bearer Token 检查当前后台登录状态。除登录接口外，后续 `/api/admin/*` 后台接口均使用相同鉴权中间件。

### `GET /api/admin/reviews/next`

返回最早的一张已上传、待审核照片，使用 `created_at` 和 `id` 正序排列。`remaining_count` 包含当前返回的照片。

```json
{
  "photo": {
    "id": 12,
    "original_filename": "IMG_0012.jpg",
    "mime_type": "image/jpeg",
    "file_size": 2457600,
    "width": 4032,
    "height": 3024,
    "review_status": "pending",
    "review_url": "https://ali-cdn.example.com/wedding/photos/12.jpg?...",
    "uploader_phone_suffix": "8000",
    "created_at": "2026-09-20T08:00:00.000000Z"
  },
  "remaining_count": 3
}
```

没有待审核照片时返回：

```json
{
  "photo": null,
  "remaining_count": 0
}
```

### `POST /api/admin/photos/{photo}/review`

请求：

```json
{
  "status": "good"
}
```

允许值：`normal`、`good`、`rejected`。

成功响应：

```json
{
  "id": 12,
  "review_status": "good",
  "reviewed_at": "2026-09-20T08:00:00.000000Z"
}
```

只有上传成功且当前仍为 `pending` 的照片可以审核。重复审核、尚未完成上传或上传失败时返回 `409 photo_not_pending_review`。

每次成功审核都会在同一数据库事务内写入一条 `admin_review_logs` 记录，保存原状态和新状态。

照片首次变为 `normal` 或 `good` 时，检查被邀请人对应邀请记录是否应变为 `qualified`。

### `POST /api/admin/reviews/undo`

撤销最近一次审核并恢复上一个状态。

成功时返回恢复后的照片状态：

```json
{
  "id": 12,
  "review_status": "pending",
  "reviewed_at": null
}
```

审核日志不会删除，而是写入 `undone_at`。恢复为 `pending` 的已上传照片会重新进入待审核队列。没有可撤销记录时返回 `409 nothing_to_undo`；照片状态已被其他操作改变时返回 `409 review_state_changed`。

### `GET /api/admin/reviews/stats`

返回用户、上传、审核、点赞和邀请的基础统计。

## 8. 抽奖接口

### `POST /api/admin/draw-rounds`

创建一轮抽奖并冻结候选快照。抽取人数从服务端配置读取。

快照至少包含：

```json
{
  "users": [
    {
      "user_id": 1,
      "phone_suffix": "1234",
      "photo_weight": 340,
      "invitation_weight": 100,
      "total_weight": 440,
      "photos": [
        {
          "photo_id": 10,
          "draw_url": "https://...",
          "weight": 240,
          "review_status": "good",
          "valid_like_count": 20
        }
      ]
    }
  ]
}
```

权重使用放大 100 倍后的整数。

### `GET /api/draw-rounds/{round}`

返回已冻结的候选快照和轮次状态，供抽奖页刷新恢复。

### `POST /api/draw-rounds/{round}/winners`

提交前端从快照中抽出的结果。后端必须验证：

- 用户属于该轮快照。
- 照片属于该用户且属于快照。
- 同一轮用户不重复。
- 中奖位置不重复。

### `GET /api/draw-winners`

返回历史中奖照片、轮次和手机号尾号。

## 9. 权重服务输出

权重逻辑必须集中在后端单一服务中，并提供计算明细：

```text
valid_like_count
base_weight
review_multiplier
photo_weight
qualified_invitation_count
invitation_weight
user_total_weight
```

禁止控制器、前端页面或 SQL 查询各自复制一套权重公式。
