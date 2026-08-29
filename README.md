# 接管匣 TakeBox（zibll-takebox）— 插件设计文档 v1.0

> 完整接管 WordPress 媒体库的「对象存储接管」插件：任何会上传到媒体库的文件自动同步到对象存储，并补齐双向同步、删除同步、信息增强与 R2 专用体验。
> 基于子比（zibll）主题开发，但核心逻辑与主题解耦，非子比主题同样可用。

---

## 0. 元信息
- 名称：**接管匣 TakeBox** ｜ slug / TextDomain：`zibll-takebox`
- 开发者：阿晨 ｜ 站 https://navigation.hoarfall.com/ ｜ 邮箱 achen@hoarfall.com ｜ 关于页 https://navigation.hoarfall.com/about.html
- 品牌菜单：顶级菜单「Hoarfall 工具」（`HOARFALL_MENU_SLUG = 'hoarfall-tools'`）
- 运行环境：优先子比(zibll)主题；非子比主题同样可用（自动切原生设置页）

## 1. 目标
完整接管 WP 媒体库：任何会上传到媒体库的文件自动同步到对象存储，并补齐双向同步、删除同步、信息增强与 R2 专用体验。

## 2. 已确认核心决策
| 项 | 决策 |
|---|---|
| 本地副本 | 可配置，**默认保留**本地；逐存储开关"同步后删除本地" |
| 上云接管 | 常驻：任何上传即接管上云（不依赖事件回调） |
| 反向/批量同步 | 手动触发的差异同步引擎，后台(Action Scheduler)运行，关页不中断，完成通知；按文件名/key 比对，存在则跳过 |
| 存储数量 | **仅一个生效**，S3兼容 / R2 / 阿里云OSS 三选一 |
| 访问权限 | 公开→OSS 直链；私有→WP 动态生成带过期签名 URL（付费/私密仍走 Zibpay 权限） |
| 缩略图 | 本地生成多尺寸后**全同步**到 OSS，URL 由 `wp_get_attachment_image_src` 返回 |
| 设置页 | 双模式：子比用 CSF 页；非子比用原生页（文章同步风格） |
| 后台菜单 | 全部挂「Hoarfall 工具」；单页 + 顶部 tab 切换；不做三级侧边菜单 |
| 总开关 | 必带 `zibll_takebox_master_switch`，**默认关闭**（媒体接管风险高） |

## 3. 设置页规范
- 子比主题：`CSF::createOptions`，`menu_type=>'menu'` + 在 `admin_menu` 优先级 999 用 reparent 移入 `hoarfall-tools`。
- 非子比主题：原生 WP 设置页（复用 `settings-native.php` boilerplate），同样挂 `hoarfall-tools`；**单页 + 顶部 tab 切换**；共享 option key 时 sanitize 只更新当前 tab 白名单、继承其余。
- 列表页「设置」跳转：`plugin_action_links_{plugin_basename}`。
- `brand-menu.php` 用 `function_exists` 守卫，多插件共用只注册一次。

## 4. 功能清单
1. 上传接管（原图+全尺寸，不绕过 `zib_php_upload()`，保留 attachment 模型）
2. 删除同步（`delete_attachment` → 删远端）
3. 反向/批量同步引擎（双向差异、后台、进度、通知、离页保护）
4. 本地→OSS 历史迁移（复用引擎正向）
5. 信息面板增强（上传于/上传者/文件名/类型/大小/分辨率/服务商/区域/权限）
6. R2 专用优化（令牌链接→粘贴→自动推导→选桶）
7. 自定义路径 + 年月开关（`bucket/[路径]/[YYYY/MM/]文件`）
8. 访问权限：默认 ACL（设置项）+ 媒体库详情页逐文件权限切换（公开直链 / 私有签名 URL），切换时同步对象存储 ACL（S3/R2/OSS 均已实现 `set_object_acl`）
9. 正文 HTML 图片 URL 扫描替换（让接管前旧文章/论坛帖也彻底上云：干跑预览 + 自动备份原文 + WP-CLI `takebox rewrite-content`）
10. 孤儿对象清理（OSS 中本地无引用者批量删除：默认关 + 二次确认 + WP-CLI `takebox cleanup-orphans`）

## 5. 接管架构（遵循 zibll 约束，且对主题解耦）
- 加载点：核心接管走 `plugins_loaded`（WP 核心钩子，与主题无关）；CSF 设置页挂 `init` + reparent。独立 option key `zibll_takebox`。
- Hook：`wp_generate_attachment_metadata`（上传+全尺寸到 OSS）、过滤 `wp_get_attachment_url`/`wp_get_attachment_image_src`（改写 OSS 地址，**必须过滤 URL 层**）、`delete_attachment`（删远端）、`upload_dir`/`get_attached_file`（仅"删本地"时配合）。
- 分片兼容：`zib_file_chunk` 先落临时目录，合并后才接管，不拦截分片过程。
- 非子比主题：上述均为 WP 核心钩子，照常生效，仅设置 UI 换成原生页。

## 6. 数据模型
- 附件 meta（前缀 `_zibll_takebox_`）：`provider`、`region`、`bucket`、`object_key`、`acl`、`endpoint`、`filesize`。
- 插件 option：`zibll_takebox`（含总开关、存储类型、各存储凭据、路径、年月、删本地、签名URL时长、同步状态）。

## 7. 设置页 tabs（CSF 一级 section / 原生页顶部 tab）
基础设置 / 存储选择 / 存储配置 / 路径 / 同步 / 信息面板 / 关于

## 8. R2 专用优化
申请令牌按钮→跳转 Cloudflare R2 令牌页→粘贴 Access Key/Secret→「连接并获取存储桶」调 `ListBuckets`→自动解析账号(account id 从 endpoint)、region 设 `auto`、列出 bucket 供点选。

## 9. 信息面板字段映射
上传于=`post_date`；上传者=`post_author` 显示名；文件名=basename；文件类型=`post_mime_type`；文件大小=字节；分辨率=图片宽×高；服务商/区域/权限=`_zibll_takebox_*` meta。

## 10. 同步引擎
手动触发→Action Scheduler 分批→文件名/key 去重（存在跳过）→正向(本地→OSS)/反向(OSS→本地建附件并打 meta)→游标+进度→完成发后台通知(+可选邮件)；同步中离页弹确认，任务后台继续。

## 11. 文件结构
```
zibll-takebox/
├── zibll-takebox.php          # 入口：插件头、常量、boot、总开关 gate、action_links
├── includes/
│   ├── brand-menu.php         # Hoarfall 工具菜单（共用 boilerplate）
│   ├── admin-options.php      # 子比 CSF 设置页（init 注册 + reparent + 原生页兜底）
│   ├── settings-native.php    # 非子比原生页 boilerplate（单页+顶部tab+合并sanitize）
│   ├── upload-takeover.php    # 上传/删除接管 Hook
│   ├── url-rewrite.php        # wp_get_attachment_url 过滤
│   ├── meta-panel.php         # 信息面板增强
│   ├── sync-engine.php        # 双向同步引擎（Action Scheduler）+ 孤儿对象清理
│   ├── content-rewrite.php    # 正文 HTML 图片 URL 扫描替换（旧内容上云）
│   ├── ajax.php               # R2 取桶列表、同步、工具区
│   └── adapters/
│       ├── class-storage-adapter.php  # 抽象基类（含默认 set_object_acl）
│       ├── s3.php             # S3 兼容 / Cloudflare R2（共用 AWS SigV4；R2 用 is_r2 切换端点/region=auto）
│       └── oss.php            # 阿里云 OSS（OSS 签名 V1 / HMAC-SHA1，虚拟主机式地址）
└── assets/
```

## 12. 开发里程碑
1. ✅ 骨架 + Hoarfall 菜单 + 总开关(默认关) + CSF/原生双设置页
2. ✅ S3 兼容 / R2 上传接管 + URL 改写（AWS SigV4 适配器，原图+全尺寸同步）
3. ✅ 删除同步 + 本地副本开关 + 缩略图全同步
4. ✅ 阿里云 OSS 适配器 + 私有签名 URL（OSS 签名 V1 / HMAC-SHA1，虚拟主机式地址，list_objects 已支持）
5. ✅ R2 专用 UI（令牌申请链接 → 粘贴 → ListBuckets 自动推导 account → 点选存储桶）
6. ✅ 双向同步引擎（WP Cron 后台分批 / 差异比对 / 进度轮询 / 完成通知 / 离页保护 / WP-CLI）
7. ✅ 访问权限：默认 ACL 选项 + 媒体库详情页逐文件权限切换，切换时同步对象存储 ACL（S3/R2/OSS `set_object_acl`）
8. 🔜 兼容回归（灯箱/海报/微信分享/付费下载/头像/商品图/论坛图）—— 需真实 WP 环境上机验证，无法离线完成。技术判断见 §14。
9. ✅ 正文 HTML 图片 URL 扫描替换（接管前旧内容彻底上云：干跑预览 + 自动备份原文 + WP-CLI `takebox rewrite-content`）
10. ✅ 孤儿对象清理（OSS 中本地无引用者批量删除：默认关 + 二次确认 + WP-CLI `takebox cleanup-orphans`）

## 13. 风险与兼容（来自 zibll 框架核实）
- 不绕过 `zib_php_upload()` 与 attachment 模型；URL 走过滤器而非替换 HTML。
- 付费/私有资源继续走 Zibpay 权限，不暴露真实 OSS 地址。
- 迁移后逐项验证：海报 CORS、微信分享图、SEO 图、商品图、论坛列表图、用户头像/封面。

## 14. URL 改写验收技术判断（上机对照）
> 结论：清单里每一项都「涉及上传」（本身就是媒体库 attachment）且都「需要改写 URL」。核对的是 URL 改写是否在各取图处生效，不是上传。

### 14.1 为什么这些「都涉及上传」
子比媒体上传贯穿「文章、论坛、评论、私信、用户头像、封面、收款码、商品评价、附件下载」，全部走 `zib_php_upload()` → `media_handle_upload()` → 创建 attachment：
- 头像 `user_upload_avatar`（存 `custom_avatar_id`）、封面 `user_upload_cover`、商品图（`product_config.main_image`/`cover_images`）、论坛/帖子图（论坛上传入口）。
- 因此文件在「上传」那一刻即被接管钩子 `wp_generate_attachment_metadata` 捕获，原图+各尺寸已上云。它们不是「只引用、不上传」的东西。

### 14.2 为什么还要逐项核对
文件已上 OSS，但前台各场景取图 URL 的方式不同。本插件 URL 改写走 **WordPress 过滤器层**（`wp_get_attachment_url` / `wp_get_attachment_image_src`）——只有取图时调用这两个函数的地方才能拿到 OSS 地址：
- 头像/封面/商品图/微信分享图/海报：底层走 `zib_post_thumbnail()` / 附件函数 → 过滤器生效。
- 灯箱：依赖正文 `<img>` 最终 src，函数输出的已改写；但若 HTML 里是**旧内容写死的 `<img src="...wp-content/uploads/...">`**，过滤器改不到（见 14.3）。

### 14.3 两处真实盲区（上机重点查）
1. **正文 HTML 硬编码 `<img>`**：旧文章/论坛帖子里数据库写死的旧地址，过滤器只改函数返回值、改不到正文 HTML。**已用「正文图片改写」工具彻底覆盖**（里程碑 9 / `content-rewrite.php`）：扫描正文本地 uploads 图片 → 按 basename 匹配已上云附件 → 替换为 OSS 地址；执行前自动备份原文（`_zibll_takebox_content_backup`），先干跑预览再执行。验证法：对旧帖运行该工具后，地址应变为 OSS。
2. **海报 CORS 跨域**：海报把 OSS 图塞进 canvas，OSS 未配 CORS 会被跨域污染、海报生成失败。需在 OSS 配 CORS（Origin=站点域名，Methods=GET/HEAD），或开主题 `share_img_compatible_s` 转 base64 兜底。

### 14.4 确实不涉及上传、也不改写的
Gravatar 头像（远程）、主题/插件自带静态资源、第三方外链图、站点图标/Logo（`_pz('iconpng')`/`favicon`）—— 非 attachment，本插件不接管。

### 14.5 付费下载专项
Zibpay 须在服务端校验权限，不能把 OSS 私有真实 URL 暴露前端。私有文件经 `wp_get_attachment_url`/`get_attached_file` 过滤返回**签名 URL**，配合 Zibpay 即可。注意：关闭「保留本地副本」后，付费下载要确认仍走签名 URL 而非依赖本地 `get_attached_file()`（默认保留本地，暂不触发）。
