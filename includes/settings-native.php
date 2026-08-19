<?php
/**
 * 原生 WordPress 设置页（兼容非子比 / 未安装 Codestar Framework 的站点）。
 * 数据仍写入同一个 option key `zibll_takebox`，与 CSF 设置页完全互通。
 *
 * 菜单结构：后台左侧只有一个菜单项（位于「Hoarfall 工具」顶级菜单下）：
 *   接管匣 → 进入后在页面内用「标签页」切换各板块：
 *     基础设置 | 存储设置 | 路径 | 同步 | 信息面板 | 关于
 * 其中「存储设置」把原先割裂的「存储选择」与「存储配置」合并为单一页面：
 *   顶部 provider 卡片选择器（S3 兼容 / Cloudflare R2 / 阿里云 OSS），
 *   下方按所选 provider 展开对应凭据卡片（R2 卡片内嵌一键连接 UI），
 *   用户无需在多个 tab 间跳转即可完成“选存储 + 填密钥”。
 * 不依赖 WP 后台三级侧边菜单（原生后台对三级菜单支持不佳、容易显示异常），
 * 因此把“子菜单”做到页面内部，作为标签页切换。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('HOARFALL_MENU_SLUG')) {
    define('HOARFALL_MENU_SLUG', 'hoarfall-tools');
}

// 各标签页管理的字段白名单（用于 sanitize 合并，避免多页共享同一 option 时互相清空）
if (!function_exists('zibll_takebox_native_page_fields')) {
    function zibll_takebox_native_page_fields()
    {
        return array(
            'basic'    => array('master_switch', 'keep_local', 'sign_url_ttl', 'default_acl'),
            // provider 与凭据合并到同一个「存储设置」tab，切换 provider 后无需跨 tab 保存
            'storage'  => array(
                'provider',
                's3_endpoint', 's3_region', 's3_bucket', 's3_access_key', 's3_secret_key',
                'r2_account', 'r2_endpoint', 'r2_access_key', 'r2_secret_key', 'r2_bucket',
                'oss_endpoint', 'oss_bucket', 'oss_access_key', 'oss_secret_key', 'oss_region',
                'public_domain', 'multipart_threshold', 'multipart_part_size',
            ),
            'path'     => array('custom_path', 'year_month', 'rename_uploads'),
            'image'    => array(
                'webp_enabled', 'webp_quality', 'webp_keep_original',
                'watermark_enabled', 'watermark_type', 'watermark_text', 'watermark_font',
                'watermark_image', 'watermark_position', 'watermark_opacity', 'watermark_min_size',
            ),
            'sync'     => array('sync_cron', 'sync_freq', 'sync_email', 'cleanup_orphans'),
            'meta'     => array('meta_enabled'),
        );
    }
}

// 字段定义（标题 / 类型 / 说明 / 选项 / 归属 provider）
if (!function_exists('zibll_takebox_native_field_defs')) {
    function zibll_takebox_native_field_defs()
    {
        return array(
            'master_switch' => array('title' => '启用接管（总开关）', 'type' => 'switcher', 'desc' => '媒体接管类插件默认关闭。开启后任何上传到媒体库的文件都会被接管并同步到对象存储。'),
            'keep_local'    => array('title' => '保留本地副本', 'type' => 'switcher', 'desc' => '开启：同步后保留本地文件（最安全）。关闭：同步后删除本地（省空间，但私有文件需签名 URL）。默认开。'),
            'sign_url_ttl'  => array('title' => '私有签名 URL 有效期（秒）', 'type' => 'number', 'desc' => '私有文件由 WP 动态生成带过期签名直链，默认 3600。', 'min' => 60),
            'default_acl'   => array('title' => '默认访问权限', 'type' => 'select', 'options' => array('public-read' => '公开（直链）', 'private' => '私有（签名 URL）'), 'desc' => '新上传文件的默认权限；单个文件可在媒体库详情页单独修改。'),
            'provider'      => array('title' => '当前生效存储', 'type' => 'radio', 'options' => array('s3' => 'S3 兼容', 'r2' => 'Cloudflare R2（专用令牌 UI）', 'oss' => '阿里云 OSS'), 'desc' => '仅一个生效，避免多桶冲突。'),
            's3_endpoint'   => array('title' => 'S3 端点', 'type' => 'text', 'placeholder' => 'https://s3.amazonaws.com', 'prov' => 's3'),
            's3_region'     => array('title' => '区域', 'type' => 'text', 'placeholder' => 'auto / us-east-1', 'prov' => 's3'),
            's3_bucket'     => array('title' => '存储桶', 'type' => 'text', 'prov' => 's3'),
            's3_access_key' => array('title' => 'Access Key', 'type' => 'text', 'prov' => 's3'),
            's3_secret_key' => array('title' => 'Secret Key', 'type' => 'password', 'prov' => 's3'),
            'r2_account'    => array('title' => '账号 ID（自动推导）', 'type' => 'text', 'attr' => array('readonly' => 'readonly'), 'prov' => 'r2'),
            'r2_endpoint'   => array('title' => 'R2 端点', 'type' => 'text', 'placeholder' => 'https://<accountid>.r2.cloudflarestorage.com', 'prov' => 'r2'),
            'r2_access_key' => array('title' => 'Access Key ID', 'type' => 'text', 'prov' => 'r2'),
            'r2_secret_key' => array('title' => 'Secret Access Key', 'type' => 'password', 'prov' => 'r2'),
            'r2_bucket'     => array('title' => '存储桶（点选）', 'type' => 'text', 'desc' => '在 R2 卡片点「连接并获取存储桶」后自动填入。', 'prov' => 'r2'),
            'oss_endpoint'  => array('title' => 'OSS 端点', 'type' => 'text', 'placeholder' => 'oss-cn-hangzhou.aliyuncs.com', 'prov' => 'oss'),
            'oss_bucket'    => array('title' => '存储桶', 'type' => 'text', 'prov' => 'oss'),
            'oss_access_key'=> array('title' => 'AccessKey ID', 'type' => 'text', 'prov' => 'oss'),
            'oss_secret_key'=> array('title' => 'AccessKey Secret', 'type' => 'password', 'prov' => 'oss'),
            'oss_region'    => array('title' => '区域（可选）', 'type' => 'text', 'placeholder' => 'oss-cn-hangzhou', 'desc' => '如 oss-cn-hangzhou。留空默认 oss-cn-hangzhou，仅用于信息面板展示。', 'prov' => 'oss'),
            'public_domain' => array('title' => '自定义公开域名（可选）', 'type' => 'text', 'placeholder' => 'https://cdn.example.com', 'desc' => '填后，所有对外暴露的图片/文件直链都走你的域名（CDN / 自有域名），而非存储厂商默认端点。需自行在 DNS/CDN 处把该域名回源到存储桶。留空则用默认端点地址。'),
            'custom_path'   => array('title' => '自定义路径', 'type' => 'text', 'placeholder' => 'wp-content/uploads/', 'desc' => '对象存储中的存放前缀，默认 wp-content/uploads（与本地 wp-content/uploads 原始结构一致）。可直接留空按默认；也可改成你的自定义前缀。'),
            'year_month'    => array('title' => '按年/月分目录', 'type' => 'switcher', 'desc' => '开启后路径变为 存储桶/路径/年/月/文件。'),
            'rename_uploads' => array('title' => '上传自动更名', 'type' => 'switcher', 'desc' => '新上传到对象存储的文件名整体替换为标准化标识（如 1162-image.jpg、1162-image-150x150.jpg、1162-video.mp4），彻底去掉原始文件名的中文/表情/长串/空格。仅影响新上传，已有文件与反向导入不受影响。'),
            'sync_cron'     => array('title' => '定时反向同步', 'type' => 'switcher', 'desc' => '开启后按频率扫描对象存储，把缺失文件反向导入媒体库（兜底）。'),
            'sync_freq'     => array('title' => '扫描频率', 'type' => 'select', 'options' => array('hourly' => '每小时', 'twicedaily' => '每 12 小时', 'daily' => '每天')),
            'sync_email'    => array('title' => '完成通知邮箱（可选）', 'type' => 'text', 'placeholder' => 'admin@example.com', 'desc' => '同步完成后发完成通知邮件；留空仅后台提示。'),
            'cleanup_orphans' => array('title' => '允许清理孤儿对象', 'type' => 'switcher', 'desc' => '开启后，方可使用同步页「孤儿对象清理」执行删除（默认关，防误删）。'),
            'meta_enabled'  => array('title' => '增强媒体库信息', 'type' => 'switcher', 'desc' => '展示 上传于/上传者/文件名/类型/大小/分辨率/服务商/区域/权限。默认开。'),
            'multipart_threshold' => array('title' => '大文件分片上传阈值（MB）', 'type' => 'number', 'min' => 0, 'desc' => '文件超过该大小自动改用 Multipart 分片上传（0 = 关闭，默认关）。大视频/大文件建议设 50。'),
            'multipart_part_size'  => array('title' => '分片大小（MB）', 'type' => 'number', 'min' => 5, 'desc' => '每个分片大小，5~100，默认 10。'),
            'webp_enabled'      => array('title' => '自动转 WebP', 'type' => 'switcher', 'desc' => '上传 JPG/PNG/GIF 时自动转为 WebP（体积更小）。独立于对象存储接管，可单独开启。'),
            'webp_quality'      => array('title' => 'WebP 质量', 'type' => 'number', 'min' => 1, 'desc' => '1~100，默认 82。数值越高画质越好、体积越大。'),
            'webp_keep_original'=> array('title' => '保留原图', 'type' => 'switcher', 'desc' => '转 WebP 后是否保留原 JPG/PNG 文件。开启最稳妥（可回退），关闭省空间。'),
            'watermark_enabled' => array('title' => '图片水印', 'type' => 'switcher', 'desc' => '上传时给原图自动加水印（文字或图片）。独立于对象存储接管。'),
            'watermark_type'    => array('title' => '水印类型', 'type' => 'radio', 'options' => array('text' => '文字', 'image' => '图片'), 'desc' => '选择文字或图片水印。'),
            'watermark_text'    => array('title' => '文字水印内容', 'type' => 'text', 'desc' => '留空则用站点名称。'),
            'watermark_font'    => array('title' => '文字水印字体（可选）', 'type' => 'text', 'desc' => '已内置中文字体并自动探测系统字体，留空即可正常显示中文；如需其它 TTF/TTC 可填服务器绝对路径。'),
            'watermark_image'   => array('title' => '图片水印 URL / 路径', 'type' => 'text', 'desc' => '水印图片的本地绝对路径或 http(s) URL（建议 PNG 带透明）。'),
            'watermark_position'=> array('title' => '水印位置', 'type' => 'select', 'options' => array(
                'top-left' => '左上', 'top-center' => '上中', 'top-right' => '右上',
                'center-left' => '左中', 'center' => '居中', 'center-right' => '右中',
                'bottom-left' => '左下', 'bottom-center' => '下中', 'bottom-right' => '右下',
            )),
            'watermark_opacity' => array('title' => '不透明度（%）', 'type' => 'number', 'min' => 0, 'desc' => '0 完全透明 ~ 100 完全不透明，默认 60。'),
            'watermark_min_size'=> array('title' => '最小宽度（px）', 'type' => 'number', 'min' => 0, 'desc' => '图片宽度小于该值不打水印，0 = 全部打。'),
        );
    }
}

// 注册菜单：仅一个二级菜单项（不再做三级侧边菜单）
if (!function_exists('zibll_takebox_register_native_settings')) {
    function zibll_takebox_register_native_settings()
    {
        if (!is_admin()) {
            return;
        }
        $parent = defined('HOARFALL_MENU_SLUG') ? HOARFALL_MENU_SLUG : 'hoarfall-tools';
        add_submenu_page(
            $parent,
            '接管匣',
            '接管匣',
            'manage_options',
            ZIBLL_TAKEBOX_MENU_SLUG,
            'zibll_takebox_native_page_router'
        );
        register_setting(ZIBLL_TAKEBOX_OPTION, ZIBLL_TAKEBOX_OPTION, array(
            'sanitize_callback' => 'zibll_takebox_sanitize_options',
        ));
    }
}
add_action('admin_menu', 'zibll_takebox_register_native_settings', 20);

// 数据清洗：合并模式——只更新本次提交标签页管理的字段，其余字段继承现有 option，避免互相清空
if (!function_exists('zibll_takebox_sanitize_options')) {
    function zibll_takebox_sanitize_options($input)
    {
        $input    = is_array($input) ? $input : array();
        $existing = get_option(ZIBLL_TAKEBOX_OPTION, array());
        $out      = is_array($existing) ? $existing : array();

        $map   = zibll_takebox_native_page_fields();
        $page  = isset($input['__page']) ? $input['__page'] : '';
        $switchers = array('master_switch', 'keep_local', 'year_month', 'sync_cron', 'meta_enabled', 'cleanup_orphans', 'rename_uploads', 'webp_enabled', 'webp_keep_original', 'watermark_enabled');
        $fields = isset($map[$page]) ? $map[$page] : array();

        foreach ($fields as $k) {
            if (in_array($k, $switchers, true)) {
                // 复选/开关未勾选时不传值，默认 0
                $out[$k] = !empty($input[$k]) ? 1 : 0;
                continue;
            }
            if (!isset($input[$k])) {
                continue; // 未提交的字段（如其它 provider 的凭据）保留原值
            }
            switch ($k) {
                case 'sign_url_ttl':
                    $out[$k] = max(60, (int) $input[$k]);
                    break;
                case 'default_acl':
                    $out[$k] = in_array($input[$k], array('public-read', 'private'), true) ? $input[$k] : 'public-read';
                    break;
                case 'provider':
                    $out[$k] = in_array($input[$k], array('s3', 'r2', 'oss'), true) ? $input[$k] : 's3';
                    break;
                case 'sync_freq':
                    $out[$k] = in_array($input[$k], array('hourly', 'twicedaily', 'daily'), true) ? $input[$k] : 'daily';
                    break;
                case 'webp_quality':
                    $out[$k] = max(1, min(100, (int) $input[$k]));
                    break;
                case 'multipart_threshold':
                    $out[$k] = max(0, (int) $input[$k]);
                    break;
                case 'multipart_part_size':
                    $out[$k] = max(5, min(100, (int) $input[$k]));
                    break;
                case 'watermark_type':
                    $out[$k] = in_array($input[$k], array('text', 'image'), true) ? $input[$k] : 'text';
                    break;
                case 'watermark_position':
                    $pos = array('top-left','top-center','top-right','center-left','center','center-right','bottom-left','bottom-center','bottom-right');
                    $out[$k] = in_array($input[$k], $pos, true) ? $input[$k] : 'bottom-right';
                    break;
                case 'watermark_opacity':
                    $out[$k] = max(0, min(100, (int) $input[$k]));
                    break;
                case 'watermark_min_size':
                    $out[$k] = max(0, (int) $input[$k]);
                    break;
                default:
                    $out[$k] = sanitize_text_field($input[$k]);
            }
        }
        return $out;
    }
}

// 准备各页共用数据
if (!function_exists('zibll_takebox_native_data')) {
    function zibll_takebox_native_data()
    {
        $opt = get_option(ZIBLL_TAKEBOX_OPTION, array());
        $ps  = function ($k, $d = '') use ($opt) {
            return isset($opt[$k]) ? $opt[$k] : $d;
        };
        return compact('opt', 'ps');
    }
}

// 共通样式（含页面内标签页样式；前缀 zibll-tb-，避免与 post-sync 冲突）
if (!function_exists('zibll_takebox_native_styles')) {
    function zibll_takebox_native_styles()
    {
        ?>
        <style type="text/css">
            .zibll-tb-wrap { max-width: 880px; margin: 0 0 40px; }
            .zibll-tb-head { margin: 18px 0 22px; }
            .zibll-tb-brand {
                display: inline-block; font-size: 12px; font-weight: 700; letter-spacing: .5px;
                color: #fff; background: #2c6ecb; padding: 3px 10px; border-radius: 20px; margin-bottom: 8px;
            }
            .zibll-tb-head h1 { font-size: 23px; margin: 0 0 6px; }
            .zibll-tb-sub { color: #787c82; margin: 0; font-size: 13px; }

            .zibll-tb-tabs {
                display: flex; flex-wrap: wrap; gap: 2px; margin: 0 0 22px;
                border-bottom: 1px solid #e2e4e7;
            }
            .zibll-tb-tab {
                display: inline-block; padding: 10px 16px; text-decoration: none;
                color: #50575e; font-size: 14px; border-bottom: 2px solid transparent; margin-bottom: -1px;
            }
            .zibll-tb-tab:hover { color: #1d2327; }
            .zibll-tb-tab-active { color: #1d2327; font-weight: 700; border-bottom-color: #2c6ecb; }

            .zibll-tb-card {
                background: #fff; border: 1px solid #e2e4e7; border-left: 4px solid #2c6ecb;
                border-radius: 8px; padding: 6px 22px 18px; margin-bottom: 18px;
                box-shadow: 0 1px 2px rgba(0,0,0,.04);
            }
            .zibll-tb-card-title {
                font-size: 15px; font-weight: 700; color: #1d2327; margin: 18px 0 14px;
                display: flex; align-items: center; gap: 8px;
            }
            .zibll-tb-dot { width: 8px; height: 8px; border-radius: 50%; background: #2c6ecb; display: inline-block; }
            .zibll-tb-tip { color: #787c82; font-size: 13px; margin: 6px 0 0; }

            /* === 存储设置：provider 选择器 + 凭据卡片 === */
            .provider-selector { display: flex; flex-wrap: wrap; gap: 12px; margin: 8px 0 2px; }
            .provider-option {
                position: relative; display: inline-flex; align-items: center; gap: 8px;
                padding: 12px 16px; border: 1px solid #dcdcde; border-radius: 8px;
                background: #fff; cursor: pointer; transition: all .15s; font-size: 14px; color: #1d2327;
            }
            .provider-option:hover { border-color: #2c6ecb; }
            .provider-option.selected { border-color: #2c6ecb; box-shadow: 0 0 0 1px #2c6ecb; background: #f4f8ff; }
            .provider-option input[type=radio] { margin: 0 2px 0 0; }
            .provider-name { font-weight: 600; }
            .provider-badge {
                font-size: 11px; font-weight: 700; color: #fff; background: #d63638;
                padding: 2px 7px; border-radius: 10px; margin-left: 2px;
            }

            .provider-card {
                background: #fff; border: 1px solid #e2e4e7; border-left: 4px solid #2c6ecb;
                border-radius: 8px; padding: 4px 22px 16px; margin-bottom: 18px;
                box-shadow: 0 1px 2px rgba(0,0,0,.04);
            }
            .provider-title {
                font-size: 16px; font-weight: 700; color: #1d2327; margin: 18px 0 6px;
                display: flex; align-items: center; gap: 8px;
            }
            .provider-title::before {
                content: ""; width: 8px; height: 8px; border-radius: 50%; background: #2c6ecb; display: inline-block;
            }
            .provider-desc { color: #787c82; font-size: 13px; margin: 0 0 10px; }
            .provider-card table.form-table { margin-top: 0; }
        </style>
        <?php
    }
}

// ===== 路由：页面内标签页切换（单一后台菜单项）=====
if (!function_exists('zibll_takebox_native_page_router')) {
    function zibll_takebox_native_page_router()
    {
        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }
        $tabs = array(
            'basic'    => '基础设置',
            'storage'  => '存储设置',
            'path'     => '路径',
            'image'    => '图片处理',
            'sync'     => '同步',
            'meta'     => '信息面板',
            'about'    => '关于',
        );
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'basic';
        if (!isset($tabs[$tab])) {
            $tab = 'basic';
        }
        zibll_takebox_native_styles();
        ?>
        <div class="wrap zibll-tb-wrap">
            <div class="zibll-tb-head">
                <div class="zibll-tb-brand">Hoarfall 工具</div>
                <h1>接管匣 TakeBox</h1>
                <p class="zibll-tb-sub">原生兼容模式（未检测到子比主题的 Codestar Framework）。功能与子比主题下完全一致，数据互通。用上方标签页切换各板块。</p>
            </div>

            <nav class="zibll-tb-tabs">
                <?php foreach ($tabs as $k => $label) :
                    $url    = admin_url('admin.php?page=' . ZIBLL_TAKEBOX_MENU_SLUG . '&tab=' . $k);
                    $active = ($k === $tab) ? ' zibll-tb-tab-active' : '';
                ?>
                    <a class="zibll-tb-tab<?php echo $active; ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>

            <form method="post" action="options.php">
                <?php settings_fields(ZIBLL_TAKEBOX_OPTION); ?>
                <input type="hidden" name="<?php echo esc_attr(ZIBLL_TAKEBOX_OPTION); ?>[__page]" value="<?php echo esc_attr($tab); ?>">
                <?php zibll_takebox_render_tab($tab); ?>
                <?php submit_button('保存设置'); ?>
            </form>
        </div>
        <?php
    }
}

// ===== 渲染指定 tab =====
if (!function_exists('zibll_takebox_render_tab')) {
function zibll_takebox_render_tab($tab)
{
    $d    = zibll_takebox_native_data();
    $defs = zibll_takebox_native_field_defs();
    $map  = zibll_takebox_native_page_fields();

    if ('about' === $tab) {
        echo '<div class="zibll-tb-card"><h2 class="zibll-tb-card-title"><span class="zibll-tb-dot"></span>关于</h2>';
        echo '<p>插件：接管匣 TakeBox（zibll-takebox）<br>作者：阿晨<br>网站：<a href="https://navigation.hoarfall.com/" target="_blank">navigation.hoarfall.com</a><br>邮箱：achen@hoarfall.com</p></div>';
        return;
    }

    // 存储设置：provider 选择器 + 按 provider 分组的凭据卡片，单独渲染
    if ('storage' === $tab) {
        zibll_takebox_render_storage_tab($d, $defs);
        return;
    }

    $fields = isset($map[$tab]) ? $map[$tab] : array();
    $opt    = $d['opt'];
    echo '<div class="zibll-tb-card"><table class="form-table"><tbody>';
    foreach ($fields as $id) {
        $def = isset($defs[$id]) ? $defs[$id] : array();
        $val = isset($opt[$id]) ? $opt[$id] : '';
        echo '<tr><th scope="row">' . esc_html($def['title'] ?? $id) . '</th><td>';
        zibll_takebox_field($id, $def, $val);
        if (!empty($def['desc'])) {
            echo '<p class="zibll-tb-tip">' . esc_html($def['desc']) . '</p>';
        }
        echo '</td></tr>';
    }
    if ('sync' === $tab && function_exists('zibll_takebox_sync_ui')) {
        echo zibll_takebox_sync_ui();
    }
    if ('sync' === $tab && function_exists('zibll_takebox_tools_ui')) {
        echo zibll_takebox_tools_ui();
    }
    echo '</tbody></table></div>';
}

// ===== 存储设置 tab：provider 选择器 + 分组凭据卡片 =====
if (!function_exists('zibll_takebox_render_storage_tab')) {
    function zibll_takebox_render_storage_tab($d, $defs)
    {
        $opt     = $d['opt'];
        $current = isset($opt['provider']) ? $opt['provider'] : 's3';

        // 单字段行渲染（复用通用字段渲染）
        $row = function ($id) use ($opt, $defs) {
            $def = isset($defs[$id]) ? $defs[$id] : array();
            $val = isset($opt[$id]) ? $opt[$id] : '';
            echo '<tr><th scope="row">' . esc_html($def['title'] ?? $id) . '</th><td>';
            zibll_takebox_field($id, $def, $val);
            if (!empty($def['desc'])) {
                echo '<p class="zibll-tb-tip">' . esc_html($def['desc']) . '</p>';
            }
            echo '</td></tr>';
        };

        // --- provider 选择器 ---
        echo '<div class="zibll-tb-card">';
        echo '<h2 class="zibll-tb-card-title"><span class="zibll-tb-dot"></span>当前生效存储</h2>';
        echo '<p class="zibll-tb-tip">仅一个生效，避免多桶冲突。选择后下方会自动展开对应凭据表单；R2 支持一键连接。</p>';
        echo '<div class="provider-selector">';
        foreach (array('s3' => 'S3 兼容', 'r2' => 'Cloudflare R2', 'oss' => '阿里云 OSS') as $pk => $pl) {
            $sel = ($current === $pk) ? ' selected' : '';
            $badge = ('r2' === $pk) ? ' <span class="provider-badge">一键连接</span>' : '';
            echo '<label class="provider-option' . $sel . '" data-prov="' . $pk . '">';
            echo '<input type="radio" name="' . esc_attr(ZIBLL_TAKEBOX_OPTION) . '[provider]" value="' . $pk . '" ' . checked($pk, $current, false) . '>';
            echo '<span class="provider-name">' . esc_html($pl) . '</span>' . $badge . '</label>';
        }
        echo '</div></div>';

        // --- S3 卡片 ---
        $hide = ('s3' === $current) ? '' : ' style="display:none;"';
        echo '<div class="provider-card" data-provider="s3"' . $hide . '>';
        echo '<h3 class="provider-title">S3 兼容存储</h3>';
        echo '<p class="provider-desc">支持 AWS S3、MinIO、Wasabi、RainS3 等所有 S3 兼容服务。</p>';
        echo '<table class="form-table"><tbody>';
        foreach (array('s3_endpoint', 's3_region', 's3_bucket', 's3_access_key', 's3_secret_key') as $id) {
            $row($id);
        }
        echo '</tbody></table></div>';

        // --- R2 卡片（含一键连接 UI）---
        $hide = ('r2' === $current) ? '' : ' style="display:none;"';
        echo '<div class="provider-card" data-provider="r2"' . $hide . '>';
        echo '<h3 class="provider-title">Cloudflare R2</h3>';
        echo '<p class="provider-desc">支持「一键连接」：粘贴 Cloudflare API 令牌，系统自动推导 Account ID / Access Key / Secret Key 并列出存储桶，无需手动去控制台创建 S3 令牌。</p>';
        if (function_exists('zibll_takebox_r2_connect_ui')) {
            echo zibll_takebox_r2_connect_ui();
        }
        echo '<table class="form-table"><tbody>';
        foreach (array('r2_account', 'r2_endpoint', 'r2_access_key', 'r2_secret_key', 'r2_bucket') as $id) {
            $row($id);
        }
        echo '</tbody></table></div>';

        // --- OSS 卡片 ---
        $hide = ('oss' === $current) ? '' : ' style="display:none;"';
        echo '<div class="provider-card" data-provider="oss"' . $hide . '>';
        echo '<h3 class="provider-title">阿里云 OSS</h3>';
        echo '<p class="provider-desc">填写 OSS Endpoint、Bucket、AccessKey 与区域（里程碑 4）。</p>';
        echo '<table class="form-table"><tbody>';
        foreach (array('oss_endpoint', 'oss_bucket', 'oss_access_key', 'oss_secret_key', 'oss_region') as $id) {
            $row($id);
        }
        echo '</tbody></table></div>';

        // --- 自定义公开域名（全局）---
        echo '<div class="zibll-tb-card">';
        echo '<h3 class="zibll-tb-card-title"><span class="zibll-tb-dot"></span>自定义公开域名（可选）</h3>';
        $row('public_domain');
        echo '</div>';

        // --- 连接测试 ---
        if (function_exists('zibll_takebox_connection_test_ui')) {
            echo '<div class="zibll-tb-card">';
            echo zibll_takebox_connection_test_ui();
            echo '</div>';
        }

        // --- 切换交互 ---
        ?>
        <script type="text/javascript">
        (function ($) {
            $(function () {
                function showProvider(p) {
                    $('.provider-card').hide();
                    $('.provider-card[data-provider="' + p + '"]').show();
                    $('.provider-option').removeClass('selected');
                    $('.provider-option[data-prov="' + p + '"]').addClass('selected');
                }
                $('input[name="<?php echo esc_js(ZIBLL_TAKEBOX_OPTION . '[provider]'); ?>"]').on('change', function () {
                    showProvider($(this).val());
                });
                var cur = $('input[name="<?php echo esc_js(ZIBLL_TAKEBOX_OPTION . '[provider]'); ?>"]:checked').val() || 's3';
                showProvider(cur);
            });
        })(jQuery);
        </script>
        <?php
    }
}
}

// ===== 通用字段渲染 =====
if (!function_exists('zibll_takebox_field')) {
    function zibll_takebox_field($id, $def, $val)
    {
        $name = ZIBLL_TAKEBOX_OPTION . '[' . $id . ']';
        $type = $def['type'] ?? 'text';
        switch ($type) {
            case 'switcher':
                echo '<label><input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked(1, $val, false) . '> 启用</label>';
                break;
            case 'radio':
                foreach (($def['options'] ?? array()) as $k => $v) {
                    echo '<label style="margin-right:18px;"><input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($k) . '" ' . checked($k, $val, false) . '> ' . esc_html($v) . '</label>';
                }
                break;
            case 'select':
                echo '<select name="' . esc_attr($name) . '">';
                foreach (($def['options'] ?? array()) as $k => $v) {
                    echo '<option value="' . esc_attr($k) . '" ' . selected($k, $val, false) . '>' . esc_html($v) . '</option>';
                }
                echo '</select>';
                break;
            case 'password':
                echo '<input type="password" name="' . esc_attr($name) . '" value="' . esc_attr($val) . '" class="regular-text" autocomplete="new-password">';
                break;
            case 'number':
                $min = isset($def['min']) ? ' min="' . (int) $def['min'] . '"' : '';
                echo '<input type="number" name="' . esc_attr($name) . '" value="' . esc_attr($val) . '" class="small-text"' . $min . '>';
                break;
            default:
                $ph   = isset($def['placeholder']) ? ' placeholder="' . esc_attr($def['placeholder']) . '"' : '';
                $attr = '';
                foreach (($def['attr'] ?? array()) as $ak => $av) {
                    $attr .= ' ' . $ak . '="' . esc_attr($av) . '"';
                }
                echo '<input type="text" name="' . esc_attr($name) . '" value="' . esc_attr($val) . '" class="regular-text"' . $ph . $attr . '>';
        }
    }
}
