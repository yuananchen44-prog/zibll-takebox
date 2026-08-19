<?php
/**
 * 后台设置页（基于子比主题 Codestar Framework）。
 * 仅在子比主题加载完成后（init）注册，使用独立 option key，不写入 zibll_options。
 * 非子比主题时本文件不创建菜单，交由 settings-native.php 注册原生页。
 */

if (!defined('ABSPATH')) {
    exit;
}

// 品牌顶级菜单 slug（与 brand-menu.php 保持一致，确保设置页挂到「Hoarfall 工具」下）
if (!defined('HOARFALL_MENU_SLUG')) {
    define('HOARFALL_MENU_SLUG', 'hoarfall-tools');
}

// R2 专用连接 UI（里程碑 5）：申请令牌链接 + 连接取桶按钮 + 桶点选容器。
// 同时用于 CSF 设置页（content 字段）与原生设置页（存储 tab 追加渲染）。
if (!function_exists('zibll_takebox_r2_connect_ui')) {
    function zibll_takebox_r2_connect_ui()
    {
        $token_url = 'https://dash.cloudflare.com/?to=/:account/r2/api-tokens';
        ob_start();
        ?>
        <div class="zma-r2-ui" style="margin:10px 0 4px;padding:12px 14px;border:1px solid #e2e4e7;background:#fff;border-radius:6px;box-shadow:0 1px 2px rgba(0,0,0,.05);">
            <p style="margin:0 0 8px;"><?php echo esc_html__('R2 支持两种方式配置，推荐「一键连接」。', 'zibll-takebox'); ?></p>

            <h4 style="margin:6px 0 6px;">① 方式一：一键连接（推荐）</h4>
            <p style="margin:0 0 8px;">
                <a class="button" href="<?php echo esc_url($token_url); ?>" target="_blank" rel="noopener">申请 Cloudflare API 令牌</a>
            </p>
            <p style="margin:0 0 8px;">
                <input type="text" id="zma-r2-token" class="regular-text" style="max-width:480px;" placeholder="<?php echo esc_attr__('粘贴 API 令牌值（Token Value）', 'zibll-takebox'); ?>" />
            </p>
            <p class="description" style="margin:0 0 8px;">
                <?php echo esc_html__('粘贴 Cloudflare「创建 API 令牌」后显示的', 'zibll-takebox'); ?><b><?php echo esc_html__('令牌值', 'zibll-takebox'); ?></b><?php echo esc_html__('，系统自动推导 Account ID、Access Key、Secret Key 并列出存储桶。', 'zibll-takebox'); ?>
                <strong style="color:#2271b1;"><?php echo esc_html__('令牌值不会被保存，仅本地推导。', 'zibll-takebox'); ?></strong>
            </p>
            <p style="margin:0 0 8px;">
                <button type="button" id="zma-r2-quick" class="button button-primary">② 一键连接并获取存储桶</button>
                <span class="spinner" id="zma-r2-qc-spin" style="visibility:hidden;float:none;"></span>
            </p>

            <hr style="margin:14px 0;border:none;border-top:1px solid #e2e4e7;">

            <h4 style="margin:6px 0 6px;">③ 方式二：手动填写</h4>
            <p style="margin:0 0 8px;">
                <label style="display:block;margin-bottom:4px;font-weight:600;">Account ID（不是 Access Key ID）</label>
                <input type="text" id="zma-r2-account-input" class="regular-text" style="max-width:480px;" placeholder="<?php echo esc_attr__('粘贴 R2 控制台右侧的 Account ID（32 位字符串）', 'zibll-takebox'); ?>" />
            </p>
            <p class="description" style="margin:0 0 8px;">
                <?php echo esc_html__('贴入 R2 控制台右侧的 Account ID（32 位），「存储端点」会自动生成。也可直接贴完整 endpoint 或控制台地址。', 'zibll-takebox'); ?>
                <br><strong style="color:#d63638;"><?php echo esc_html__('注意：不要把 Access Key ID（以 cfat_ 开头）贴到这里。', 'zibll-takebox'); ?></strong>
            </p>
            <p style="margin:0 0 8px;">
                <button type="button" id="zma-r2-connect" class="button button-primary">连接并获取存储桶</button>
                <span class="spinner" id="zma-r2-spin" style="visibility:hidden;float:none;"></span>
            </p>

            <div id="zma-r2-buckets"></div>
            <p class="description" style="margin:6px 0 0;"><?php echo esc_html__('连接成功会列出存储桶，点选后填入「存储桶」框；region 固定为 auto。', 'zibll-takebox'); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }
}

// 双向同步 UI（里程碑 6）：方向选择 + 立即同步按钮 + 进度条。
// 同时用于 CSF 设置页（content 字段）与原生设置页（同步 tab 追加渲染）。
if (!function_exists('zibll_takebox_sync_ui')) {
    function zibll_takebox_sync_ui()
    {
        ob_start();
        ?>
        <div class="zma-sync-ui" style="margin:10px 0 4px;padding:12px 14px;border:1px solid #e2e4e7;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.05);border-radius:6px;">
            <p style="margin:0 0 8px;">
                <label style="margin-right:16px;"><input type="radio" name="zma_sync_dir" value="both" checked> 双向（本地↔OSS）</label>
                <label style="margin-right:16px;"><input type="radio" name="zma_sync_dir" value="forward"> 仅本地→OSS（补漏/迁移）</label>
                <label><input type="radio" name="zma_sync_dir" value="reverse"> 仅 OSS→本地（反向导入）</label>
            </p>
            <p style="margin:0 0 8px;">
                <button type="button" id="zma-sync-start" class="button button-primary">立即同步</button>
                <span class="spinner" id="zma-sync-spin" style="visibility:hidden;float:none;"></span>
            </p>
            <div style="margin-top:8px;">
                <div style="height:14px;background:#eee;border-radius:7px;overflow:hidden;">
                    <div id="zma-sync-bar" style="height:100%;width:0;background:#2c6ecb;transition:width .3s;"></div>
                </div>
                <p id="zma-sync-text" class="description" style="margin:6px 0 0;"></p>
            </div>
            <p class="description" style="margin:6px 0 0;">同步在后台分批运行，关闭本页不会中断。</p>
        </div>
        <?php
        return ob_get_clean();
    }
}

// 工具区 UI（里程碑收尾）：正文图片改写 + 孤儿对象清理。
// 同时用于 CSF 设置页（content 字段）与原生设置页（同步 tab 追加渲染）。
if (!function_exists('zibll_takebox_tools_ui')) {
    function zibll_takebox_tools_ui()
    {
        ob_start();
        ?>
        <div class="zma-tools-ui" style="margin:14px 0 4px;">
            <div style="padding:12px 14px;border:1px solid #e2e4e7;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.05);border-radius:6px;margin-bottom:12px;">
                <h3 style="margin:0 0 6px;">正文图片改写（旧内容彻底上云）</h3>
                <p class="description" style="margin:0 0 8px;">扫描正文中本地图片地址并替换为对象存储地址，执行前自动备份，可先干跑预览。</p>
                <p style="margin:0 0 8px;">
                    <button type="button" id="zma-crw-scan" class="button">① 干跑扫描预览</button>
                    <button type="button" id="zma-crw-run" class="button button-primary">② 执行替换（先备份原文）</button>
                    <span class="spinner" id="zma-crw-spin" style="visibility:hidden;float:none;"></span>
                </p>
                <div id="zma-crw-result" class="description" style="margin:6px 0 0;"></div>
            </div>
            <div style="padding:12px 14px;border:1px solid #e2e4e7;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.05);border-radius:6px;">
                <h3 style="margin:0 0 6px;">孤儿对象清理（释放 OSS 空间）</h3>
                <p class="description" style="margin:0 0 8px;">列出存储桶中本地已无引用的孤儿对象，批量清理（需先开「允许清理孤儿对象」并二次确认）。</p>
                <p style="margin:0 0 8px;">
                    <button type="button" id="zma-orphan-scan" class="button">① 扫描孤儿对象</button>
                    <button type="button" id="zma-orphan-run" class="button button-primary">② 执行删除（二次确认）</button>
                    <span class="spinner" id="zma-orphan-spin" style="visibility:hidden;float:none;"></span>
                </p>
                <div id="zma-orphan-result" class="description" style="margin:6px 0 0;"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

// 连接测试 UI：配置保存后，一键验证「自定义域名 / 默认端点」能否真正访问上传对象。
if (!function_exists('zibll_takebox_connection_test_ui')) {
    function zibll_takebox_connection_test_ui()
    {
        ob_start();
        ?>
        <div class="zma-conn-test-ui" style="margin:14px 0 4px;padding:12px 14px;border:1px solid #e2e4e7;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.05);border-radius:6px;">
            <h3 style="margin:0 0 6px;">连接测试（验证自定义域名 / 端点是否真正可用）</h3>
            <p class="description" style="margin:0 0 8px;">保存配置后点击：临时上传小文件，分别用对外地址与真实端点访问，区分是域名/CDN 问题还是上传/凭据问题。测试对象自动删除。</p>
            <p style="margin:0 0 8px;">
                <button type="button" id="zma-conn-test" class="button button-primary">运行连接测试</button>
                <span class="spinner" id="zma-conn-test-spin" style="visibility:hidden;float:none;"></span>
            </p>
            <div id="zma-conn-test-result" class="description" style="margin:6px 0 0;"></div>
        </div>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('zibll_takebox_create_options')) {
    function zibll_takebox_create_options()
    {
        if (!class_exists('CSF') || !is_admin()) {
            return;
        }

        $prefix = ZIBLL_TAKEBOX_OPTION; // 'zibll_takebox'

        CSF::createOptions($prefix, array(
            'menu_title'      => '接管匣',
            'menu_slug'       => ZIBLL_TAKEBOX_MENU_SLUG,
            'menu_type'       => 'menu',
            'framework_title' => '接管匣 TakeBox <small>v' . ZIBLL_TAKEBOX_VERSION . '</small>',
            'theme'           => 'light',
            'ajax_save'       => true,
        ));

        // ===== 基础设置 =====
        CSF::createSection($prefix, array(
            'id'     => 'basic',
            'title'  => '基础设置',
            'icon'   => 'fa fa-cog',
            'fields' => array(
                array(
                    'id'      => 'master_switch',
                    'type'    => 'switcher',
                    'title'   => '启用接管（总开关）',
                    'desc'    => '总开关：开启后接管并同步媒体库上传到对象存储。',
                    'default' => false,
                ),
                array(
                    'id'      => 'keep_local',
                    'type'    => 'switcher',
                    'title'   => '保留本地副本',
                    'desc'    => '保留服务器本地文件（最安全）。关闭则同步后删除本地。',
                    'default' => true,
                ),
                array(
                    'id'      => 'sign_url_ttl',
                    'type'    => 'number',
                    'title'   => '私有文件签名 URL 有效期（秒）',
                    'desc'    => '私有文件签名直链有效期，默认 3600 秒。',
                    'default' => 3600,
                    'min'     => 60,
                ),
                array(
                    'id'      => 'default_acl',
                    'type'    => 'select',
                    'title'   => '默认访问权限',
                    'desc'    => '新上传文件默认权限。公开=直链；私有=签名 URL。',
                    'options' => array('public-read' => '公开（直链）', 'private' => '私有（签名 URL）'),
                    'default' => 'public-read',
                ),
            ),
        ));

        // ===== 存储设置（按 provider 用 dependency 显示对应字段）=====
        // 注意：provider 选择器和具体凭据字段必须放在同一个 section 内，
        // 子比 CSF 的 dependency 跨 section 不可靠，会导致整个 tab 空白。
        CSF::createSection($prefix, array(
            'id'     => 'storage',
            'title'  => '存储设置',
            'icon'   => 'fa fa-cloud',
            'fields' => array(
                array(
                    'id'      => 'provider',
                    'type'    => 'radio',
                    'title'   => '当前生效存储',
                    'desc'    => '选择当前生效的存储服务，切换后显示对应凭据。',
                    'inline'  => true,
                    'options' => array(
                        's3'  => 'S3 兼容',
                        'r2'  => 'Cloudflare R2',
                        'oss' => '阿里云 OSS',
                    ),
                    'default' => 's3',
                ),
                // --- S3 兼容 ---
                array('id' => 's3_endpoint', 'type' => 'text', 'title' => 'S3 端点', 'dependency' => array('provider', '==', 's3'), 'placeholder' => 'https://s3.amazonaws.com'),
                array('id' => 's3_region', 'type' => 'text', 'title' => '区域', 'dependency' => array('provider', '==', 's3'), 'placeholder' => 'auto / us-east-1'),
                array('id' => 's3_bucket', 'type' => 'text', 'title' => '存储桶', 'dependency' => array('provider', '==', 's3')),
                array('id' => 's3_access_key', 'type' => 'text', 'title' => 'Access Key', 'dependency' => array('provider', '==', 's3')),
                array('id' => 's3_secret_key', 'type' => 'text', 'title' => 'Secret Key', 'dependency' => array('provider', '==', 's3')),
                // --- Cloudflare R2 ---
                array('id' => 'r2_account', 'type' => 'text', 'title' => '账号 ID（自动推导）', 'dependency' => array('provider', '==', 'r2'), 'attributes' => array('readonly' => 'readonly')),
                array('id' => 'r2_endpoint', 'type' => 'text', 'title' => 'R2 端点', 'dependency' => array('provider', '==', 'r2'), 'placeholder' => 'https://<accountid>.r2.cloudflarestorage.com'),
                array('id' => 'r2_access_key', 'type' => 'text', 'title' => 'Access Key ID', 'dependency' => array('provider', '==', 'r2')),
                array('id' => 'r2_secret_key', 'type' => 'text', 'title' => 'Secret Access Key', 'dependency' => array('provider', '==', 'r2')),
                array('id' => 'r2_bucket', 'type' => 'text', 'title' => '存储桶（点选）', 'dependency' => array('provider', '==', 'r2'), 'desc' => '点「连接并获取存储桶」后自动填入。'),
                // --- 阿里云 OSS ---
                array('id' => 'oss_endpoint', 'type' => 'text', 'title' => 'OSS 端点', 'dependency' => array('provider', '==', 'oss'), 'placeholder' => 'oss-cn-hangzhou.aliyuncs.com'),
                array('id' => 'oss_bucket', 'type' => 'text', 'title' => '存储桶', 'dependency' => array('provider', '==', 'oss')),
                array('id' => 'oss_access_key', 'type' => 'text', 'title' => 'AccessKey ID', 'dependency' => array('provider', '==', 'oss')),
                array('id' => 'oss_secret_key', 'type' => 'text', 'title' => 'AccessKey Secret', 'dependency' => array('provider', '==', 'oss')),
                array('id' => 'oss_region', 'type' => 'text', 'title' => '区域（可选）', 'dependency' => array('provider', '==', 'oss'), 'desc' => '留空默认 oss-cn-hangzhou。', 'placeholder' => 'oss-cn-hangzhou'),
                // --- 自定义公开域名（全局，对所有 provider 生效；对外地址走自定义域名，真实 API 仍走存储端点）---
                array(
                    'id'      => 'public_domain',
                    'type'    => 'text',
                    'title'   => '自定义公开域名（可选）',
                    'desc'    => '填后对外直链走你的域名（需 DNS/CDN 处 CNAME 回源到存储桶）。留空则用默认端点。',
                    'placeholder' => 'https://cdn.example.com',
                ),
                array(
                    'id'      => 'multipart_threshold',
                    'type'    => 'number',
                    'title'   => '大文件分片上传阈值（MB）',
                    'desc'    => '文件超过该大小自动改用 Multipart 分片上传（0 = 关闭，默认关）。大视频/大文件建议设 50。',
                    'default' => 0,
                    'min'     => 0,
                ),
                array(
                    'id'      => 'multipart_part_size',
                    'type'    => 'number',
                    'title'   => '分片大小（MB）',
                    'desc'    => '每个分片大小，5~100，默认 10。',
                    'default' => 10,
                    'min'     => 5,
                    'max'     => 100,
                ),
                // --- 连接测试（保存配置后一键验证自定义域名 / 端点可用性）---
                array('id' => 'connection_test_ui', 'type' => 'content', 'content' => zibll_takebox_connection_test_ui()),
                // --- R2 专用连接 UI（里程碑 5）---
                array('id' => 'r2_connect_ui', 'type' => 'content', 'content' => zibll_takebox_r2_connect_ui(), 'dependency' => array('provider', '==', 'r2')),
            ),
        ));

        // ===== 路径规则 =====
        CSF::createSection($prefix, array(
            'id'     => 'path',
            'title'  => '路径规则',
            'icon'   => 'fa fa-folder-open',
            'fields' => array(
                array(
                    'id'      => 'custom_path',
                    'type'    => 'text',
                    'title'   => '自定义路径',
                    'desc'    => '对象存储存放前缀，默认 wp-content/uploads（与本地结构一致）。',
                    'placeholder' => 'wp-content/uploads/',
                ),
                array(
                    'id'      => 'year_month',
                    'type'    => 'switcher',
                    'title'   => '按年/月分目录',
                    'desc'    => '开启后路径按 年/月 分子目录。',
                    'default' => false,
                ),
                array(
                    'id'      => 'rename_uploads',
                    'type'    => 'switcher',
                    'title'   => '上传自动更名',
                    'desc'    => '新上传文件名标准化（去中文/空格），如 1162-image.jpg。仅影响新上传。',
                    'default' => false,
                ),
            ),
        ));

        // ===== 图片处理（自动转 WebP + 水印，独立于对象存储总开关）=====
        CSF::createSection($prefix, array(
            'id'     => 'image',
            'title'  => '图片处理',
            'icon'   => 'fa fa-image',
            'fields' => array(
                array(
                    'id'      => 'webp_enabled',
                    'type'    => 'switcher',
                    'title'   => '自动转 WebP',
                    'desc'    => '上传 JPG/PNG/GIF 时自动转为 WebP（体积更小）。独立于对象存储接管，可单独开启。',
                    'default' => false,
                ),
                array(
                    'id'      => 'webp_quality',
                    'type'    => 'number',
                    'title'   => 'WebP 质量',
                    'desc'    => '1~100，默认 82。数值越高画质越好、体积越大。',
                    'default' => 82,
                    'min'     => 1,
                    'max'     => 100,
                ),
                array(
                    'id'      => 'webp_keep_original',
                    'type'    => 'switcher',
                    'title'   => '保留原图',
                    'desc'    => '转 WebP 后是否保留原 JPG/PNG 文件。开启最稳妥（可回退），关闭省空间。',
                    'default' => true,
                ),
                array(
                    'id'      => 'watermark_enabled',
                    'type'    => 'switcher',
                    'title'   => '图片水印',
                    'desc'    => '上传时给原图自动加水印（文字或图片）。独立于对象存储接管。',
                    'default' => false,
                ),
                array(
                    'id'      => 'watermark_type',
                    'type'    => 'radio',
                    'title'   => '水印类型',
                    'inline'  => true,
                    'options' => array('text' => '文字', 'image' => '图片'),
                    'default' => 'text',
                ),
                array(
                    'id'      => 'watermark_text',
                    'type'    => 'text',
                    'title'   => '文字水印内容',
                    'desc'    => '留空则用站点名称。',
                    'dependency' => array('watermark_type', '==', 'text'),
                ),
                array(
                    'id'      => 'watermark_font',
                    'type'    => 'text',
                    'title'   => '文字水印字体（可选）',
                    'desc'    => '已内置中文字体并自动探测系统字体，留空即可正常显示中文；如需其它 TTF/TTC 可填服务器绝对路径。',
                    'dependency' => array('watermark_type', '==', 'text'),
                ),
                array(
                    'id'      => 'watermark_image',
                    'type'    => 'text',
                    'title'   => '图片水印 URL / 路径',
                    'desc'    => '水印图片的本地绝对路径或 http(s) URL（建议 PNG 带透明）。',
                    'dependency' => array('watermark_type', '==', 'image'),
                ),
                array(
                    'id'      => 'watermark_position',
                    'type'    => 'select',
                    'title'   => '水印位置',
                    'options' => array(
                        'top-left' => '左上', 'top-center' => '上中', 'top-right' => '右上',
                        'center-left' => '左中', 'center' => '居中', 'center-right' => '右中',
                        'bottom-left' => '左下', 'bottom-center' => '下中', 'bottom-right' => '右下',
                    ),
                    'default' => 'bottom-right',
                ),
                array(
                    'id'      => 'watermark_opacity',
                    'type'    => 'number',
                    'title'   => '不透明度（%）',
                    'desc'    => '0 完全透明 ~ 100 完全不透明，默认 60。',
                    'default' => 60,
                    'min'     => 0,
                    'max'     => 100,
                ),
                array(
                    'id'      => 'watermark_min_size',
                    'type'    => 'number',
                    'title'   => '最小宽度（px）',
                    'desc'    => '图片宽度小于该值不打水印，0 = 全部打。',
                    'default' => 0,
                    'min'     => 0,
                ),
            ),
        ));

        // ===== 同步 =====
        CSF::createSection($prefix, array(
            'id'     => 'sync',
            'title'  => '同步',
            'icon'   => 'fa fa-refresh',
            'fields' => array(
                array(
                    'id'      => 'sync_cron',
                    'type'    => 'switcher',
                    'title'   => '定时反向同步',
                    'desc'    => '定时扫描对象存储，缺失文件反向导入媒体库（兜底）。',
                    'default' => false,
                ),
                array(
                    'id'      => 'sync_freq',
                    'type'    => 'select',
                    'title'   => '扫描频率',
                    'options' => array('hourly' => '每小时', 'twicedaily' => '每 12 小时', 'daily' => '每天'),
                    'default' => 'daily',
                    'dependency' => array('sync_cron', '==', '1'),
                ),
                array(
                    'id'      => 'sync_email',
                    'type'    => 'text',
                    'title'   => '完成通知邮箱（可选）',
                    'desc'    => '同步完成通知邮箱，留空仅后台提示。',
                    'placeholder' => 'admin@example.com',
                ),
                array(
                    'id'      => 'cleanup_orphans',
                    'type'    => 'switcher',
                    'title'   => '允许清理孤儿对象',
                    'desc'    => '开启后可使用下方「孤儿对象清理」删除。',
                    'default' => false,
                ),
                // 双向同步引擎 UI（里程碑 6）
                array('id' => 'sync_ui', 'type' => 'content', 'content' => zibll_takebox_sync_ui()),
                // 工具区：正文改写 + 孤儿清理
                array('id' => 'tools_ui', 'type' => 'content', 'content' => zibll_takebox_tools_ui()),
            ),
        ));

        // ===== 信息面板 =====
        CSF::createSection($prefix, array(
            'id'     => 'meta',
            'title'  => '信息面板',
            'icon'   => 'fa fa-info-circle',
            'fields' => array(
                array(
                    'id'      => 'meta_enabled',
                    'type'    => 'switcher',
                    'title'   => '增强媒体库信息',
                    'desc'    => '附件详情展示服务商/区域/权限等增强信息。',
                    'default' => true,
                ),
            ),
        ));

        // ===== 更新 =====
        CSF::createSection($prefix, array(
            'id'     => 'update',
            'title'  => '更新',
            'icon'   => 'fa fa-refresh',
            'fields' => array(
                array(
                    'id'      => 'update_repo',
                    'type'    => 'text',
                    'title'   => 'GitHub 仓库（owner/repo）',
                    'desc'    => '留空自动使用默认仓库 yuananchen44-prog/zibll-takebox；填 0/off 可禁用在线更新；也可填你自己的仓库覆盖。Release 需附带结构正确的 ZIP（解压后顶层为 zibll-takebox/）。',
                    'placeholder' => 'yuananchen44-prog/zibll-takebox',
                ),
                array(
                    'id'     => 'update_info',
                    'type'   => 'content',
                    'title'  => '当前版本',
                    'content' => '当前版本：<b>v' . ZIBLL_TAKEBOX_VERSION . '</b>。填好仓库后，到「仪表盘 → 更新」点击「立即检查更新」即可检测新版本。',
                ),
            ),
        ));

        // ===== 关于 =====
        CSF::createSection($prefix, array(
            'id'     => 'about',
            'title'  => '关于',
            'icon'   => 'fa fa-heart',
            'fields' => array(
                array(
                    'id'     => 'about_content',
                    'type'   => 'content',
                    'title'  => '开发者',
                    'content' => '作者：阿晨<br>网站：<a href="https://navigation.hoarfall.com/" target="_blank">navigation.hoarfall.com</a><br>邮箱：achen@hoarfall.com',
                ),
            ),
        ));
    }
}
// 在 init 阶段注册设置页：此时子比 CSF 已加载，且早于 admin_menu，确保 CSF 内部 hook 正常挂上菜单。
add_action('init', 'zibll_takebox_create_options');

// 把 CSF 创建的独立顶级菜单“重定父”到「Hoarfall 工具」下
// （用 WP 原生菜单数组操作，绕过 CSF 的 submenu 父级参数名差异）
if (!function_exists('zibll_takebox_reparent_menu')) {
    function zibll_takebox_reparent_menu()
    {
        if (!is_admin()) {
            return;
        }
        global $menu, $submenu;
        $top   = defined('HOARFALL_MENU_SLUG') ? HOARFALL_MENU_SLUG : 'hoarfall-tools';
        $child = ZIBLL_TAKEBOX_MENU_SLUG;
        if (empty($menu) || !is_array($menu)) {
            return;
        }
        foreach ($menu as $k => $item) {
            if (isset($item[2]) && $item[2] === $child) {
                if (!isset($submenu[$top]) || !is_array($submenu[$top])) {
                    $submenu[$top] = array();
                }
                $submenu[$top][] = $item;
                unset($menu[$k]);
                break;
            }
        }
    }
}
add_action('admin_menu', 'zibll_takebox_reparent_menu', 999);

// 兼容非子比主题（未检测到 Codestar Framework）：加载原生设置页，保证设置界面始终可访问。
// 判定必须推迟到 admin_menu 阶段——子比主题的 CSF 在 plugins_loaded 之后才加载，
// 若在加载本文件时（plugins_loaded）判定 class_exists('CSF') 会得到 false，导致“子比下也显示原生页”。
if (!function_exists('zibll_takebox_maybe_load_native_settings')) {
    function zibll_takebox_maybe_load_native_settings()
    {
        if (class_exists('CSF')) {
            return; // 已检测到子比 CSF，使用其设置页，不加载原生页
        }
        if (!function_exists('zibll_takebox_register_native_settings')) {
            require_once ZIBLL_TAKEBOX_PATH . 'includes/settings-native.php';
        }
    }
}
add_action('admin_menu', 'zibll_takebox_maybe_load_native_settings', 1);
