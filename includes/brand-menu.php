<?php
/**
 * 阿晨品牌统一后台菜单（Hoarfall Tools）
 * 作用：所有“阿晨的插件”自动收纳到同一个顶级菜单「Hoarfall 工具」下，
 *       实现“如果都是我的插件则自动归位”。
 * 复用规则：每个阿晨插件都带上本文件，用 function_exists / defined 包裹，
 *       保证多个插件只注册一次（WordPress 同名菜单去重），不会冲突也不会重复菜单。
 */

if (!defined('ABSPATH')) {
    exit;
}

// 品牌顶级菜单 slug —— 这是“都是我的插件”的统一标识，所有阿晨插件共用同一个值
if (!defined('HOARFALL_MENU_SLUG')) {
    define('HOARFALL_MENU_SLUG', 'hoarfall-tools');
}

// 注册顶级菜单（多插件仅第一个生效，static 守卫确保只注册一次）
if (!function_exists('hoarfall_register_top_menu')) {
    function hoarfall_register_top_menu()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        add_menu_page(
            'Hoarfall 工具',
            'Hoarfall 工具',
            'manage_options',
            HOARFALL_MENU_SLUG,
            'hoarfall_dashboard_page',
            'dashicons-admin-generic',
            81
        );
    }
}
add_action('admin_menu', 'hoarfall_register_top_menu', 5);

// 后台渲染前确保 get_plugins() 可用（用于扫描“阿晨的插件”）
if (!function_exists('hoarfall_ensure_plugin_api')) {
    function hoarfall_ensure_plugin_api()
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    }
}
add_action('admin_menu', 'hoarfall_ensure_plugin_api', 4);

// 顶级菜单默认页：列出所有“阿晨的插件”并给出设置入口
if (!function_exists('hoarfall_dashboard_page')) {
    function hoarfall_dashboard_page()
    {
        $mine = array();
        if (function_exists('get_plugins')) {
            $all    = get_plugins();
            $active = (array) get_option('active_plugins', array());
            foreach ($all as $file => $data) {
                if (!in_array($file, $active, true)) {
                    continue;
                }
                $author = isset($data['Author']) ? (string) $data['Author'] : '';
                $uri    = isset($data['PluginURI']) ? (string) $data['PluginURI'] : '';
                if (false !== strpos($author, '阿晨') || false !== strpos($uri, 'hoarfall.com')) {
                    $mine[] = $data;
                }
            }
        }

        echo '<div class="wrap">';
        echo '<h1>Hoarfall 工具</h1>';
        echo '<p>阿晨出品插件的统一控制台。已自动归位到本菜单的插件如下：</p>';

        if ($mine) {
            echo '<ul style="margin-left:1.4em;list-style:disc;">';
            foreach ($mine as $p) {
                $name = isset($p['Name']) ? esc_html($p['Name']) : '未命名插件';
                $page = isset($p['TextDomain']) ? $p['TextDomain'] : '';
                echo '<li>' . $name;
                if ($page) {
                    echo ' &nbsp;<a href="' . esc_url(admin_url('admin.php?page=' . $page)) . '">设置</a>';
                }
                echo '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p>当前未检测到其它阿晨插件。</p>';
        }

        echo '<hr>';
        echo '<p style="color:#666;">提示：每个阿晨插件的设置页都挂在本菜单下；插件列表中的「设置」链接也可直达。</p>';
        echo '</div>';
    }
}
