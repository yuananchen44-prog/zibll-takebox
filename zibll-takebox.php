<?php
/**
 * Plugin Name: 接管匣 TakeBox
 * Plugin URI: https://navigation.hoarfall.com/
 * Description: 完整接管 WordPress 媒体库，把任何上传到媒体库的文件自动同步到对象存储（S3 兼容 / Cloudflare R2 / 阿里云 OSS），并补齐删除同步、双向同步、信息增强与 R2 专用体验。
 * Version: 0.2.1
 * Author: 阿晨
 * Author URI: https://navigation.hoarfall.com/about.html
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: zibll-takebox
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// ===== 常量定义（带 !defined 守卫，避免重复定义告警）=====
if (!defined('ZIBLL_TAKEBOX_PATH')) {
    define('ZIBLL_TAKEBOX_PATH', plugin_dir_path(__FILE__));
}
if (!defined('ZIBLL_TAKEBOX_URL')) {
    define('ZIBLL_TAKEBOX_URL', plugin_dir_url(__FILE__));
}
if (!defined('ZIBLL_TAKEBOX_VERSION')) {
    define('ZIBLL_TAKEBOX_VERSION', '0.2.1');
}
// 独立 option key（不写入 zibll_options）
if (!defined('ZIBLL_TAKEBOX_OPTION')) {
    define('ZIBLL_TAKEBOX_OPTION', 'zibll_takebox');
}
// 设置页 slug（与 TextDomain 一致，便于品牌页拼链接）
if (!defined('ZIBLL_TAKEBOX_MENU_SLUG')) {
    define('ZIBLL_TAKEBOX_MENU_SLUG', 'zibll-takebox');
}

// ===== 统一读取入口 =====
if (!function_exists('zibll_takebox_get_option')) {
    function zibll_takebox_get_option($key = '', $default = null)
    {
        static $options = null;
        if (null === $options) {
            $options = get_option(ZIBLL_TAKEBOX_OPTION, array());
        }
        if ('' === $key) {
            return is_array($options) ? $options : array();
        }
        return isset($options[$key]) ? $options[$key] : $default;
    }
}

// ===== 总开关（媒体接管类插件，默认关闭）=====
if (!function_exists('zibll_takebox_is_enabled')) {
    function zibll_takebox_is_enabled()
    {
        // 默认关闭：只有用户手动开启（master_switch=1）才接管媒体库，避免装上即接管的高风险。
        return (bool) zibll_takebox_get_option('master_switch', 0);
    }
}

// ===== 当前生效存储类型 =====
if (!function_exists('zibll_takebox_provider')) {
    function zibll_takebox_provider()
    {
        $p = zibll_takebox_get_option('provider', '');
        return in_array($p, array('s3', 'r2', 'oss'), true) ? $p : '';
    }
}

// ===== 加载业务文件 =====
// 接管核心基于 WordPress 原生能力，在 plugins_loaded 之后加载；
// 依赖子比主题（CSF 设置页）的部分在 admin-options.php 内挂在 init + admin_menu。
if (!function_exists('zibll_takebox_boot')) {
    function zibll_takebox_boot()
    {
        $core = array(
            'includes/brand-menu.php',
            'includes/admin-options.php',
            'includes/upload-takeover.php',
            'includes/url-rewrite.php',
            'includes/meta-panel.php',
            'includes/image-process.php',
            'includes/sync-engine.php',
            'includes/content-rewrite.php',
            'includes/ajax.php',
            'includes/updater.php',
            'includes/adapters/class-storage-adapter.php',
            'includes/adapters/s3.php',
            'includes/adapters/oss.php',
        );
        foreach ($core as $file) {
            $path = ZIBLL_TAKEBOX_PATH . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }
}
add_action('plugins_loaded', 'zibll_takebox_boot');

// 未启用子比主题时，给后台管理员提示（不致命，前台不报错，原生页已兼容）
if (!function_exists('zibll_takebox_theme_notice')) {
    function zibll_takebox_theme_notice()
    {
        if (defined('ZIB_TEMPLATE_DIRECTORY_URI')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-info"><p>'
            . esc_html__('「接管匣 TakeBox」推荐在子比（zibll）主题下使用以获得最佳后台体验；当前未检测到子比主题，已自动使用原生设置页，接管核心功能不受影响。', 'zibll-takebox')
            . '</p></div>';
    }
}
add_action('admin_notices', 'zibll_takebox_theme_notice');

// ===== 插件列表页「设置」跳转链接 =====
if (!function_exists('zibll_takebox_plugin_action_links')) {
    function zibll_takebox_plugin_action_links($links)
    {
        $url    = admin_url('admin.php?page=' . ZIBLL_TAKEBOX_MENU_SLUG);
        $links[] = '<a href="' . esc_url($url) . '">设置</a>';
        return $links;
    }
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'zibll_takebox_plugin_action_links');
