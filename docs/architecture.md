# 技术架构

## 1. 技术选型

### 前端

- Vue 3
- Vite
- TypeScript
- Vue Router
- Pinia
- Axios
- 阿里云 OSS Browser SDK
- QRCode.js
- CSS Transform 或 Canvas 实现照片云动画

### 后端

- PHP 8.2+
- Laravel 12 或初始化时选定的稳定版本
- MySQL 8
- 轻量 Bearer Token 用户身份
- 环境变量固定管理员密码和管理员短期 Token

### 基础设施

- 阿里云 OSS 保存原图
- Nginx
- HTTPS
- 同域部署
- 不使用 Redis

## 2. 前端路由

建议使用一个 Vue 项目：

```text
/                移动端上传页
/gallery         移动端照片墙
/draw            PC 抽奖页
/admin/review    审核后台
```

移动端上传页和照片墙通过底部导航切换。抽奖页面和审核后台使用独立布局。

## 3. 部署结构

```text
https://photo.example.com/          Vue 静态资源
https://photo.example.com/api/      Laravel API
```

同域部署用于减少 CORS 和 Cookie 配置。用户接口使用 Bearer Token，不依赖服务端 Session。

## 4. 核心流程

### 4.1 登录

```text
输入手机号
  → POST /api/auth/phone
  → 创建或查找用户
  → 返回访问令牌
  → localStorage 保存令牌
  → GET /api/me 恢复身份
```

服务端只保存访问令牌的哈希值。

### 4.2 OSS 原图直传

```text
选择原图
  → 浏览器计算 SHA-256
  → POST /api/uploads/prepare
  → 后端检查文件哈希并创建 preparing 记录
  → 返回 OSS 临时凭证和 objectKey
  → 浏览器直传 OSS
  → POST /api/uploads/{photo}/complete
  → 后端确认 OSS 对象存在
  → 标记 uploaded + pending
```

建议 Object Key：

```text
wedding/photos/{user_id}/{year}/{month}/{uuid}.{ext}
```

Object Key 和日志中不包含完整手机号。

### 4.3 审核

```text
后台获取最早的待审核照片
  → 选择 normal / good / rejected
  → 更新照片状态
  → 写入审核日志
  → qualified 邀请状态按需更新
  → 自动加载下一张
```

### 4.4 点赞

```text
用户点击点赞
  → 校验照片已审核通过
  → 校验不是自己的照片
  → 依靠唯一索引防止重复
  → 返回更新后的点赞数
```

### 4.5 抽奖

```text
准备抽奖
  → 后端汇总有效照片和有效点赞
  → 计算照片权重与用户总权重
  → 加入可选邀请奖励
  → 排除已中奖用户
  → 保存不可变候选快照
  → 前端预加载图片
  → 前端从快照加权随机
  → 播放照片云动画
  → 展示中奖照片和手机号尾号
  → 提交并保存结果
```

## 5. 配置项

建议集中配置：

```env
USER_TOKEN_TTL_DAYS=30
ADMIN_PASSWORD=change-me
ADMIN_TOKEN_TTL_MINUTES=480

PHOTO_MAX_SIZE_MB=30
PHOTO_ALLOWED_MIME_TYPES=image/jpeg,image/png,image/webp,image/heic

LIKE_WEIGHT_INCREMENT=0.01
LIKE_WEIGHT_CAP=100
GOOD_REVIEW_MULTIPLIER=2

INVITATION_ENABLED=true
INVITATION_REWARD_ENABLED=true
INVITATION_REWARD_WEIGHT=1

DRAW_WINNER_COUNT=3
DRAW_EXCLUDE_PREVIOUS_WINNERS=true
```

OSS 的 Bucket、Region、临时凭证角色等信息使用独立环境变量保存，不提交真实密钥。

## 6. 正确性约束

- 手机号必须唯一。
- 照片 SHA-256 必须唯一。
- OSS Object Key 必须唯一。
- 每个用户对每张照片最多一条点赞记录。
- 每个被邀请人最多一条邀请关系。
- 同一抽奖轮次内同一用户最多中奖一次。
- 权重计算统一由后端服务实现，前端只使用快照结果。
- 金额型精度虽然不涉及，但权重计算应使用 Decimal 或统一放大为整数，避免浮点误差。

建议将权重放大 100 倍转为整数：

```text
普通照片整数权重 = 100 + 有效点赞数
好照片整数权重 = (100 + 有效点赞数) × 2
```

这样与 `1 + 点赞数 × 0.01` 完全等价，并且更适合加权随机。

## 7. 可靠性

- 上传准备和上传完成接口应保持幂等。
- 点赞和取消点赞接口应保持幂等。
- 前端上传失败可重试，不新增照片记录。
- 抽奖快照创建后不可修改。
- 中奖结果提交失败时先保存在浏览器本地，恢复网络后重试。
- 抽奖前预加载图片并显示进度。
- 可以导出候选快照 JSON 作为现场备份。

## 8. 测试重点

- 相同文件重复上传
- 上传 OSS 成功但完成接口失败后的重试
- 重复点赞和本人点赞
- 审核状态改变后的照片墙可见性
- 普通、好、点赞及邀请组合权重
- 邀请奖励开关不修改历史数据
- 抽奖快照生成后数据保持不变
- 同一轮和跨轮中奖排除规则
- 刷新抽奖页后的结果恢复

