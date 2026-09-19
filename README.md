# 婚礼照片互动与抽奖系统

这是一个仅在婚礼当天使用的照片互动项目，预计最多约 200 名用户。系统包含移动端照片上传与点赞、PC 端现场抽奖，以及照片审核后台。

项目采用前后端分离架构：

- 前端：Vue 3、Vite、TypeScript
- 后端：Laravel、PHP 8.2+
- 数据库：MySQL 8
- 文件存储：阿里云 OSS，浏览器直传原图
- 缓存：暂不使用 Redis

## 项目文档

- [产品需求与业务规则](docs/product-requirements.md)
- [技术架构](docs/architecture.md)
- [数据模型与 API](docs/data-and-api.md)
- [开发任务清单](docs/development-tasks.md)

开发某项功能前，应先阅读对应文档，并以 `docs/development-tasks.md` 中的任务编号作为提交和评审单位。

