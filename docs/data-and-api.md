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
file_hash                   unique, SHA-256
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
  "rejected_count": 1,
  "successful_invitation_count": 2,
  "invitation_enabled": true,
  "invitation_reward_enabled": true
}
```

不返回抽奖次数或用户总权重。

## 4. 上传接口

### `POST /api/uploads/prepare`

请求：

```json
{
  "file_hash": "sha256-hex",
  "filename": "IMG_0012.JPG",
  "mime_type": "image/jpeg",
  "file_size": 12345678
}
```

行为：

- 校验类型和大小。
- 检查哈希是否存在。
- 创建或恢复 `preparing` 记录。
- 返回照片 ID、Object Key 和 OSS 临时凭证。

### `POST /api/uploads/{photo}/complete`

行为：

- 校验照片属于当前用户。
- 确认 OSS 对象存在。
- 将上传状态改为 `uploaded`，审核状态设为 `pending`。
- 更新关联邀请为 `uploaded`。

### `GET /api/photos/mine`

返回当前用户的照片和上传、审核状态。

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

### `GET /api/admin/reviews/next`

返回最早的一张待审核照片、审核预览 URL 和剩余待审核数量。

### `POST /api/admin/photos/{photo}/review`

请求：

```json
{
  "status": "good"
}
```

允许值：`normal`、`good`、`rejected`。

照片首次变为 `normal` 或 `good` 时，检查被邀请人对应邀请记录是否应变为 `qualified`。

### `POST /api/admin/reviews/undo`

撤销最近一次审核并恢复上一个状态。

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

