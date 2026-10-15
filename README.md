# 婚礼照片互动与抽奖系统

一个面向婚礼现场的照片互动项目：宾客用手机上传照片、浏览照片墙并点赞，工作人员审核照片，主持人在电脑大屏上进行加权抽奖。

项目围绕约 200 人、单日活动的场景设计，也可以作为聚会、年会等小型活动的二次开发起点。前端使用 Vue，后端使用 Laravel，照片存储在阿里云 OSS。页面和 API 同域部署，不要求 Redis。

> 当前采用“输入手机号即登记”的轻量身份机制，未验证号码归属；现场抽奖接口也允许公开调用。适合受控活动场景，面向长期公网服务时，需要先补充身份验证和抽奖操作权限。详见[使用边界](#使用边界与待实现功能)。

## 目录

- [功能与特点](#功能与特点)
- [活动使用流程与抽奖规则](#活动使用流程与抽奖规则)
- [技术栈与运行要求](#技术栈与运行要求)
- [本地运行](#本地运行)
- [阿里云 OSS 配置](#阿里云-oss-配置)
- [生产部署](#生产部署)
- [二次开发](#二次开发)
- [常见问题](#常见问题)
- [使用边界与待实现功能](#使用边界与待实现功能)
- [许可证](#许可证)

## 功能与特点

| 模块 | 已实现能力 |
| --- | --- |
| 手机号登记 | 支持中国大陆手机号，同一号码可在多个浏览器登记并持有独立 Token；浏览器保存身份，后端存储 Token 哈希 |
| 照片上传 | 多图选择、逐张校验、进度展示、失败重试；使用 STS 临时凭证直传 OSS |
| 文件去重 | 同时记录原始文件与上传文件的 SHA-256，跨用户检查重复文件，不依赖文件名 |
| 大图处理 | 超过 20 MiB 的图片先在浏览器压缩，再上传展示版本和原始文件；后端另有大图转码处理，保留原始对象 |
| 我的照片 | 查看自己的上传记录和审核状态；后端提供上传成功、待审核、通过、拒绝的统计接口 |
| 照片墙 | 审核通过的照片、游标分页、大图预览、点赞和取消点赞；前后端均限制本人给本人照片点赞 |
| 审核后台 | 密码登录、逐张审核、下一张预加载、剩余数量、审核日志、撤销最近一次审核 |
| 现场大屏 | 动态照片云、候选图片预加载、逐个揭晓中奖照片与手机号尾号、历史中奖结果恢复、展示照片定时刷新 |
| 加权抽奖 | 审核等级与点赞共同决定权重，先抽用户再抽照片；候选快照、事务内保存结果、重复执行返回已保存结果 |
| 运维基础 | 数据库迁移、开发用 Docker Compose、应用及数据库健康检查、PHPUnit 自动化测试 |

实现上的几个特点：

- **照片直传**：常规上传绕过应用服务器，后端负责业务校验、临时凭证与完成确认。大图完成确认时，服务器仍可能从 OSS 下载原图并转码。
- **结果与动画分离**：后端先确定并持久化中奖结果，前端随后播放动画，动画帧率不参与随机计算。
- **候选快照固定**：准备轮次时冻结候选照片、权重与设置，之后的上传、审核、点赞不改变该轮快照。
- **按用户去重中奖**：同一轮每个手机号最多中奖一次，可配置排除历史中奖者，以及每人进入候选池的照片数量。
- **图片按场景展示**：统一生成 OSS 签名图片地址，提供照片云、缩略图、画廊、审核、抽奖预设；签名按配置时区在次日零点到期。
- **轻量部署**：当前业务不依赖常驻队列 Worker、业务定时任务或 Redis；数据库与 OSS 是主要持久化依赖。

## 活动使用流程与抽奖规则

### 页面入口

路径均相对于部署域名：

| 路径 | 用途 |
| --- | --- |
| `/` | 手机号登记、上传照片、查看自己的照片 |
| `/gallery` | 审核通过的照片墙与点赞 |
| `/admin/review` | 使用 `ADMIN_PASSWORD` 登录审核 |
| `/draw` | PC 抽奖大屏 |
| `/api/health` | 应用及数据库健康检查，数据库不可用时返回 503；不检查 OSS 或 STS |

活动前应在真实手机和大屏浏览器走完：登记 → 上传 → 审核 → 点赞 → 准备轮次 → 开奖 → 刷新后查看中奖记录。

审核快捷键：`1` 普通、`2` 好、`0` 不要、`U` 撤销最近一次审核。只有上传完成且审核为“普通”或“好”的照片进入照片墙；待审核与拒绝照片不参与抽奖。

### 抽奖规则

1. 准备轮次时读取审核通过、上传完成且未标记 `exclude_from_lottery` 的照片。
2. 每个用户默认最多选择 15 张进入候选池，优先按点赞加成、照片总权重、照片 ID 排序选择。**这是候选池上限，当前并未限制每人只能上传 15 张。**
3. 普通照片基础权重为 `1.0`，好照片为 `1.8`。有效点赞贡献按点赞人的审核照片情况计为 `0.25`（无通过照片）、`1.0`（有普通通过照片）、`1.25`（有好照片），本人点赞不计入。
4. 点赞加成为 `0.15 × sqrt(min(有效点赞贡献之和, 16))`，最多增加 `0.6`。
5. 先按用户候选照片总权重抽用户，再按该用户的照片权重抽展示照片；随机源使用后端安全随机数。
6. 同轮内移除已中奖用户及其全部候选照片。默认也排除历史轮次中奖用户。
7. 后端在事务中保存中奖记录后，前端按返回顺序揭晓；重复执行已完成轮次返回相同的已保存结果。

| 配置 | 默认值 | 说明 |
| --- | --- | --- |
| `config/lottery.php` 的 `draw_count` | `1` | 每轮人数，直接修改配置文件；没有 `DRAW_COUNT` 环境变量 |
| `DRAW_MAX_PHOTOS_PER_USER` | `15` | 每人最多进入候选池的照片数 |
| `DRAW_EXCLUDE_PREVIOUS_WINNERS` | `true` | 是否排除历史中奖者 |
| `PHOTO_MAX_BATCH_SIZE` | `20` | 准备上传 API 单次最多接收的照片数；当前页面逐张准备上传 |
| `PHOTO_MAX_ORIGINAL_SIZE_MB` | `100` | API 校验的单张原始文件大小上限，单位实际为 MiB |
| `PHOTO_MAX_DISPLAY_SIZE_MB` | `20` | API 校验的实际上传展示文件大小上限 |
| `PHOTO_LARGE_ORIGINAL_SIZE_MB` | `20` | 后端按大图处理的阈值 |
| `PHOTO_ALLOWED_MIME_TYPES` | HEIC、JPEG、PNG、WebP | 逗号分隔的 MIME 类型列表 |

前端压缩的 20 MiB 阈值写在 `resources/js/services/photoCompression.ts`，不会自动随后端环境变量变化。修改限制时需同时检查前后端。

**准备时机**：大屏会准备下一轮，服务端会复用人数配置一致的未完成轮次。新增照片、点赞或审核不会自动进入已冻结快照；应在目标照片审核完毕后再打开大屏准备首轮。二次开发可按需增加重新准备或作废轮次的流程。

## 技术栈与运行要求

以下以仓库当前锁文件和配置为准。安装时使用锁文件，不必自行升级依赖。

| 组件 | 当前版本 / 要求 |
| --- | --- |
| PHP | `composer.json` 要求 `^8.3`；开发容器使用 PHP 8.5，建议使用 8.5 |
| Laravel | 13，当前锁定 13.32.0 |
| Composer | 2.x |
| Node.js | 建议 24；当前 Vite 要求 `^20.19.0` 或 `>=22.12.0` |
| 前端 | Vue 3.5、Vue Router 5、Pinia 4、TypeScript 6、Vite 8、Tailwind CSS 4 |
| 数据库 | 本地可使用 SQLite；活动部署建议 MySQL 8，开发容器使用 MySQL 8.4 |
| 对象存储 | 阿里云 OSS、RAM Role、STS；当前没有本地磁盘或其他对象存储的上传适配 |
| 图片处理 | Imagick，或带 JPEG/WebP 支持的 GD；HEIC 还取决于浏览器与服务器解码能力 |

PHP 需具备 Laravel 所需扩展、对应数据库的 PDO 驱动和图片处理能力。`composer check-platform-reqs` 可检查 Composer 依赖要求，图片编解码能力需要另行确认。

## 本地运行

### 方式一：直接运行 PHP 和 Node.js

```bash
git clone https://github.com/imdong/wedding-photo-lottery.git
cd wedding-photo-lottery
cp .env.example .env
composer install --no-interaction
npm ci
php artisan key:generate --no-interaction
touch database/database.sqlite
php artisan migrate --no-interaction
```

默认使用 SQLite。编辑 `.env`，设置 `ADMIN_PASSWORD`，并按下一节配置真实可用的 OSS 和 STS 参数。示例 Bucket、域名和空密钥不能直接用于上传。

先构建静态资源，再启动应用：

```bash
npm run build
php artisan serve --host=127.0.0.1 --port=8000 --no-interaction
```

浏览器访问 `http://localhost:8000`。未配置 OSS 时可以检查应用和数据库健康状态，但照片上传、签名图片和抽奖相关功能需要完整 OSS 配置。

**前端热更新**：`vite.config.js` 默认适配 Docker 的 `39538` 代理端口。宿主机直接开发时，先将 `server.origin` 改为 `http://localhost:5173`、`server.hmr.host` 保持 `localhost`、`server.hmr.clientPort` 改为 `5173`，再分别运行 `npm run dev` 和上述 PHP 服务，页面入口仍是 Laravel 的 `8000` 端口。停止 Vite 后如残留 `public/hot`，删除该文件即可回到已构建的资源。

`composer run dev` 也会启动 PHP、Vite、日志及队列监听，同样需要先调整上述 Vite 配置。队列监听是开发工具提供的默认进程，并非当前照片业务的必需服务。

### 方式二：Docker Compose 开发环境

准备 Docker 和 Compose 插件，克隆仓库、复制 `.env.example` 为 `.env`。如果没有宿主机 PHP，可运行下列命令生成密钥，将输出手动填写到 `.env` 的 `APP_KEY`：

```bash
docker run --rm php:8.5-cli-alpine php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

补齐管理员密码和 OSS 配置后启动：

```bash
docker compose up -d --build
docker compose ps
docker compose logs -f app frontend
```

浏览器访问 `http://localhost:39538`。Compose 启动 MySQL、Laravel 开发服务器、Vite 与 Nginx 代理；首次启动会安装依赖并自动迁移，需要等待健康检查通过。

- Compose 环境变量会覆盖 `.env` 中对应项目，例如容器数据库固定指向 `mysql` 服务，应用地址固定为 `http://localhost:39538`。
- PHP 容器挂载项目目录，读取本地 `.env` 中其余配置。修改后如有缓存，执行 `docker compose exec app php artisan config:clear --no-interaction`。
- MySQL、Composer 依赖、Node.js 依赖使用命名卷。`docker compose down` 保留卷，追加 `-v` 会删除数据库等卷数据。
- 手机或其他电脑访问时，需同步修改 `compose.yaml`、`vite.config.js`、`docker/nginx/default.conf` 中的主机名、端口，以及 OSS CORS 来源。
- 该配置启用了 Debug、Vite、源码挂载和示例数据库密码，**仅用于开发**；生产部署使用下文方案。

## 阿里云 OSS 配置

### 1. 准备 Bucket 与权限

创建用于活动照片的 Bucket，建议使用私有读写权限，由应用生成签名地址。为服务端准备专用 RAM 身份及 AccessKey，为浏览器直传准备可扮演的 RAM Role：

- 服务端身份需要对目标角色调用 `sts:AssumeRole` 的权限，并被角色信任策略允许。
- 服务端还直接使用同一组 AccessKey 检查、读取、写回图片，需要目标照片目录的 `oss:GetObject`、`oss:PutObject` 权限；仅配置 STS 权限不足以运行完整流程。
- 被扮演的角色需要允许上传到目标照片目录。应用签发临时凭证时会进一步限制为 `ALIYUN_OSS_UPLOAD_PREFIX/用户ID/*`，且只允许 `oss:PutObject`。
- 长期 AccessKey 只保存在服务端；浏览器仅接收临时凭证。

策略与信任关系参考[阿里云 STS 临时授权](https://www.alibabacloud.com/help/en/oss/sts-temporary-access-authorization)，跨域与直传参考[浏览器直传文档](https://www.alibabacloud.com/help/en/oss/user-guide/uploading-objects-to-oss-directly-from-clients/)。按实际 Bucket 与目录授权，不需要整个账号的 OSS 管理权限。

### 2. 配置环境变量

以下以青岛地域为例，替换 Bucket、账号、角色、密钥：

```dotenv
ADMIN_PASSWORD="replace-with-a-long-random-password"
ADMIN_TOKEN_TTL_MINUTES=480

Ali_Key=your-ram-access-key-id
Ali_Secret=your-ram-access-key-secret
ALIYUN_OSS_BUCKET=your-wedding-bucket
ALIYUN_OSS_REGION=cn-qingdao
ALIYUN_OSS_ENDPOINT=https://oss-cn-qingdao.aliyuncs.com
ALIYUN_OSS_INTERNAL_ENDPOINT=https://oss-cn-qingdao-internal.aliyuncs.com
ALIYUN_OSS_BUCKET_URL=https://your-wedding-bucket.oss-cn-qingdao.aliyuncs.com
ALIYUN_OSS_SERVER_URL=https://your-wedding-bucket.oss-cn-qingdao.aliyuncs.com
ALIYUN_OSS_PUBLIC_URL=https://your-wedding-bucket.oss-cn-qingdao.aliyuncs.com
ALIYUN_OSS_ROLE_ARN=acs:ram::YOUR_ACCOUNT_ID:role/YOUR_UPLOAD_ROLE
ALIYUN_STS_ENDPOINT=https://sts.cn-qingdao.aliyuncs.com
ALIYUN_OSS_TEMPORARY_CREDENTIAL_TTL_SECONDS=900
ALIYUN_OSS_IMAGE_URL_TIMEZONE=Asia/Shanghai
ALIYUN_OSS_UPLOAD_PREFIX=wedding/photos
```

变量名区分大小写，`Ali_Key`、`Ali_Secret` 是代码实际使用的拼写。临时凭证时长校验范围是 900～3600 秒，同时需要满足角色自身的会话限制。

| 配置 | 实际用途 |
| --- | --- |
| `ALIYUN_OSS_PUBLIC_URL` | **同时用于浏览器 PUT 上传与签名图片展示**。建议先用 Bucket 官方 HTTPS 外网地址，普通只读 CDN 域名不能直接替代 |
| `ALIYUN_OSS_SERVER_URL` | 服务端 HEAD、GET、PUT 的实际目标；本地或非阿里云内网环境使用外网 Bucket 地址 |
| `ALIYUN_OSS_ENDPOINT` / `ALIYUN_OSS_INTERNAL_ENDPOINT` / `ALIYUN_OSS_BUCKET_URL` | 当前仍属于必填配置，需与 Bucket 地域一致；配置内网 Endpoint 不会自动切换服务端请求地址 |

只有服务器确实能访问 OSS 内网时，才将 `SERVER_URL` 改为对应的 **Bucket 内网地址**。自定义域名需要绑定 Bucket，并支持 HTTPS、签名查询参数和上传请求。

### 3. 配置 Bucket CORS

| 项目 | 配置内容 |
| --- | --- |
| 来源 Origin | 实际页面来源，包含协议和端口，例如本地 `http://localhost:8000`、Compose 的 `http://localhost:39538` 或正式活动域名 |
| 方法 | `PUT`、`GET`、`HEAD`，并允许浏览器正常完成预检 |
| 请求头 | 至少包含 `Authorization`、`Content-Type`、`x-oss-date`、`x-oss-security-token`；也可在限定来源后允许所有请求头 |
| 暴露响应头 | 可配置 `ETag`，便于调试 |

切换正式域名、HTTPS 或开发端口后需同步修改 CORS。确认 Bucket 可用 OSS 图片处理功能；HEIC 解码和转码能力需用目标手机及实际 Bucket 验证。

## 生产部署

以下使用 **Nginx + PHP-FPM + MySQL + OSS**，前端构建后由同一站点提供。Node.js 只需存在于构建环境，生产不运行 Vite。

### 1. 安装与配置

在服务器或构建流水线拉取仓库，安装 PHP、扩展、Composer、Node.js 和 MySQL。假设项目目录为 `/srv/wedding-photo-lottery`：

```bash
cd /srv/wedding-photo-lottery
cp .env.example .env
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci
npm run build
```

构建会使用 `vite.config.js` 中的 Bunny 字体源，首次构建需能联网获取字体，或事先准备字体缓存 / 改为本地字体。

创建数据库及专用账号。编辑 `.env`，保留完整 OSS 配置，并至少调整：

```dotenv
APP_NAME="婚礼照片互动"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://photo.example.com
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=wedding_photo_lottery
DB_USERNAME=wedding_app
DB_PASSWORD="replace-with-your-database-password"

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=sync
ADMIN_PASSWORD="replace-with-a-long-random-password"
```

首次部署时生成密钥、迁移数据库并缓存配置：

```bash
php artisan key:generate --force --no-interaction
php artisan migrate --force --no-interaction
php artisan optimize --no-interaction
composer check-platform-reqs --no-dev
```

`key:generate` 只在首次初始化空 `APP_KEY` 时执行，后续发布保持原密钥。让 PHP-FPM 运行用户可写 `storage/` 和 `bootstrap/cache/`、可读 `.env`，不要使用全目录 `777` 权限。数据库、`.env` 和 OSS 对象均需持久化并备份。

### 2. 配置 Nginx

站点根目录必须指向 `public/`。以下是 HTTPS 示例，域名、证书路径、PHP-FPM Socket 均需按服务器实际情况修改：

```nginx
server {
    listen 80;
    server_name photo.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name photo.example.com;
    root /srv/wedding-photo-lottery/public;
    index index.php;

    ssl_certificate /etc/nginx/certs/photo.example.com/fullchain.pem;
    ssl_certificate_key /etc/nginx/certs/photo.example.com/privkey.pem;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param HTTPS on;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_read_timeout 120s;
    }

    location ~ \.php$ {
        return 404;
    }

    location ~ /\. {
        deny all;
    }
}
```

若 TLS 由负载均衡或其他代理终止，需按实际拓扑调整协议传递和代理信任配置。大图处理在完成确认请求内同步进行，需为 PHP 内存、FPM 请求时限、代理超时留出空间；前端确认超时目前是 20 秒，见 `resources/js/services/photoUploads.ts`。仅增加 Nginx 超时无法解决所有大图超时问题。

### 3. 上线验证与后续发布

- 删除开发遗留的 `public/hot`，确认存在 `public/build/manifest.json`。
- 检查 `/api/health` 返回 `status: ok`，再用真实照片验证 STS、上传、审核、图片签名、抽奖；健康检查不代表 OSS 配置正确。
- 确认站点与 OSS 地址均为 HTTPS，并将正式页面来源加入 Bucket CORS。
- 对公开抽奖写接口增加现场访问限制，具体接口见下文使用边界。
- 更新前备份数据库与 `.env`。更新依赖、构建资源后，执行 `php artisan migrate --force --no-interaction`、`php artisan optimize --no-interaction`，并按部署方式重载 PHP-FPM。不要每次重新生成应用密钥。
- 生产初始化不要使用 `migrate --seed`；当前 Seeder 是演示图片数据，并非生产基础数据。

当前没有独立照片队列任务或业务定时任务，初次部署不必启动 Worker 或 Cron。将同步转码改成队列时，还需实现任务状态、重试、Worker 管理与前端确认逻辑。

## 二次开发

### 代码导航

```text
app/
  Http/Controllers/Api/       API 入口
  Http/Requests/              参数校验
  Http/Resources/             API 输出结构
  Http/Middleware/            用户与管理员 Token 校验
  Models/                    用户、照片、点赞、审核日志、轮次、中奖记录
  PhotoUpload*Service.php     上传准备、去重与图片处理
  Oss*.php                   OSS 配置、STS、对象请求、图片签名
  Photo*Weight*.php           照片权重与批量计算
  Lottery*.php               候选池、快照与加权选择
  CreateDrawRoundService.php  准备轮次
  ExecuteDrawRoundService.php 执行与保存结果
resources/js/
  views/                     上传、画廊、审核、大屏页面
  components/                通用组件与照片云动画
  services/                  API、OSS 上传、压缩与哈希
  stores/                    用户身份状态
  router/                    前端路由
config/                      管理员、照片、OSS、抽奖配置
database/                    迁移、Factory、演示 Seeder
tests/                       PHPUnit 功能与单元测试
```

| 想调整的内容 | 主要入口 |
| --- | --- |
| 页面文字、主题、移动端界面 | `resources/js/views/`、`resources/js/layouts/`、`resources/css/app.css` |
| 大屏动画与揭晓节奏 | `resources/js/views/DrawView.vue`、`resources/js/components/PhotoDysonCloud.vue` |
| 审核权重与点赞倍率 | `app/PhotoLotteryWeight.php`、`app/Enums/LikeVoterTier.php`、`app/Enums/LotteryPhotoStatus.php` |
| 候选筛选与每轮人数 | `app/LotteryCandidatePoolService.php`、`config/lottery.php` |
| 图片尺寸、压缩、格式 | `app/OssImageUrlGenerator.php`、`app/PhotoUploadProcessingService.php`、`resources/js/services/photoCompression.ts` |
| 替换对象存储 | OSS 后端类、前端 `ossUpload.ts` 与上传 API；只改 Laravel 默认磁盘不能替换当前直传实现 |
| 身份验证与抽奖权限 | `routes/api.php`、身份 Controller / Middleware、前端身份 Store 和抽奖 API 调用 |

### 验证改动

```bash
# 完整后端测试，使用 phpunit.xml 配置的内存 SQLite
php artisan test --compact

# 修改后先运行覆盖该功能的最小测试集
php artisan test --compact tests/Feature/Http/Controllers/Api/PreparedPhotoUploadControllerTest.php

# PHP 格式、前端类型、生产构建
vendor/bin/pint --dirty --format agent
npm run typecheck
npm run build
```

测试需要 `pdo_sqlite`。真实 OSS、STS、浏览器解码和大屏效果仍需手动联调。修改抽奖规则时，应保留“快照固定、后端确定结果、同轮用户去重、结果幂等”的约束并更新测试。

### 演示图片

将自己有权使用的 JPEG、PNG、WebP 图片放入未跟踪的 `storage/images/`，将相同文件名的对象事先上传到配置 Bucket 的 `wedding/images/` 目录，然后在隔离的演示数据库执行：

```bash
php artisan db:seed --no-interaction
```

Seeder **只写数据库，不上传 OSS 文件**。前 68 张图片建立可参与抽奖的演示记录，其余用于不参与抽奖的预填充展示；会创建演示手机号。仓库没有随附真实宾客照片，不要在正式活动数据库运行这些 Seeder。

### 进一步阅读

- [产品需求与业务规则](docs/product-requirements.md)
- [技术架构](docs/architecture.md)
- [数据模型与 API](docs/data-and-api.md)
- [开发任务清单](docs/development-tasks.md)

上述文档包含初期规划，部分内容与当前实现有差异，例如邀请功能、用户令牌有效期、图片存储方式。接手开发以当前代码、配置、迁移和测试为准，不要将规划中的接口当成现有功能。

## 常见问题

| 现象 | 排查方向 |
| --- | --- |
| 找不到 Vite manifest | 执行 `npm ci`、`npm run build`；热更新需运行 Vite 并匹配主机名和端口 |
| 仍请求 `5173` 或旧开发地址 | 检查 `public/hot`，生产不应保留该文件 |
| 获取 STS 凭证失败 | 检查密钥、Role ARN、信任策略、AssumeRole 权限、服务器时间与 STS 网络 |
| PUT 失败或跨域报错 | 检查 CORS、页面实际 Origin、`PUBLIC_URL` 是否允许 PUT、临时凭证权限与地域 |
| PUT 成功但确认失败 | 检查 `SERVER_URL`、服务端对象权限、原始与展示对象是否均上传、图片解码与超时 |
| 图片无法显示或签名失效 | 检查域名是否绑定 Bucket、签名查询参数、图片处理能力、服务器时钟；过期后重新获取页面数据 |
| HEIC 或超大照片失败 | 测试目标浏览器解码，可先导出 JPEG；服务器需对应编解码能力和足够内存 |
| 照片墙为空 | 照片需上传完成且审核为 `normal` 或 `good`，仅上传成功不会自动展示 |
| 修改配置没有生效 | 开发执行 `php artisan config:clear --no-interaction`，生产重新执行 `php artisan optimize --no-interaction` |
| 候选人数不足或新照片没参与 | 检查审核状态、排除标记、历史中奖者排除设置，以及轮次是否已提前冻结 |

## 使用边界与待实现功能

- **手机号不验证归属**：知道号码的人可重新登记为该号码。用户 Token 无内置有效期、主动注销或撤销 API，不适合作为强身份验证。
- **抽奖写接口公开**：`POST /api/draw-rounds`、`POST /api/draw-rounds/{drawRound}/draw` 无需管理员 Token。`/api/admin/*` 虽有管理员保护，公开入口仍存在；公网部署前应在应用或网关限制这些写入口，并同步适配大屏调用。
- **邀请奖励未实现**：已有邀请码字段，但邀请绑定、二维码、资格追踪、奖励仍为规划内容，`INVITATION_*` 不是当前可用开关。
- **没有运营管理平台**：当前是单一活动配置，没有多租户、多活动隔离、复杂角色、照片删除管理或活动归档界面。数据库存储完整手机号，照片公开范围与活动后保留策略需部署者自行安排。
- **去重是文件级别**：没有人脸识别、感知哈希或相似图识别，裁剪、截图、重新编码后的照片可能视为不同文件。
- **部分数据未展示**：统计 API 已实现，但上传页统计卡片由 `isPhotoStatsVisible = false` 隐藏；排除抽奖标记已有数据支持，没有专用管理页面。
- **容量需要实测**：约 200 人是设计场景，不是压测承诺；大图处理、网络、OSS 请求与大屏设备性能都会影响体验。

## 许可证

本项目采用 [MIT 许可证](LICENSE)。

Copyright (c) 2026 ImDong

