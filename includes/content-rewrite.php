<?php
/**
 * 正文 HTML 图片 URL 扫描替换（接管匣 TakeBox）
 * 把 post_content 中硬编码的本地 uploads 图片 URL 替换为 OSS 地址，
 * 让「接管前上传」的旧文章/论坛帖等内容也彻底上云。
 * 破坏性操作：执行前自动备份原文（_zibll_takebox_content_backup），默认干跑预览。
 */

if (!defined('ABSPATH')) {
    exit;
}

// 构建 OSS 地址映射：附件文件 basename => OSS 完整 URL（含原图与各尺寸）
if (!function_exists('zibll_takebox_build_oss_map')) {
    function zibll_takebox_build_oss_map()
    {
        static $map = null;
        if (null !== $map) {
            return $map;
        }
        $map = array();
        $adapter = zibll_takebox_get_adapter();
        if (!$adapter) {
            return $map;
        }
        $base = rtrim($adapter->public_url(''), '/') . '/';
        $atts = get_posts(array(
            'post_type'      => 'attachment',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(array('key' => '_zibll_takebox_object_key', 'compare' => 'EXISTS')),
        ));
        foreach ($atts as $id) {
            $keys = get_post_meta($id, '_zibll_takebox_keys', true);
            if (!empty($keys['original'])) {
                $map[basename($keys['original'])] = $base . $keys['original'];
            }
            if (!empty($keys['sizes']) && is_array($keys['sizes'])) {
                foreach ($keys['sizes'] as $k) {
                    $map[basename($k)] = $base . $k;
                }
            }
        }
        return $map;
    }
}

// 判断 URL 是否本站本地 uploads
if (!function_exists('zibll_takebox_is_local_uploads_url')) {
    function zibll_takebox_is_local_uploads_url($url)
    {
        $u = parse_url($url);
        if (empty($u['host'])) {
            return (false !== strpos($url, '/wp-content/uploads/'));
        }
        $home = parse_url(home_url());
        if (empty($home['host'])) {
            return false;
        }
        if ($u['host'] !== $home['host']) {
            return false;
        }
        return (false !== strpos($u['path'], '/wp-content/uploads/'));
    }
}

// 提取内容中的本地 uploads 资源 URL（含相对路径）
if (!function_exists('zibll_takebox_extract_local_urls')) {
    function zibll_takebox_extract_local_urls($content)
    {
        $urls = array();
        if (preg_match_all(
            '/(https?:\/\/[^\s"\'<>]+?\/wp-content\/uploads\/[^\s"\'<>]+\.(?:jpe?g|png|gif|webp|avif|bmp|svg|mp4|mp3|pdf|zip|docx?|xlsx?|pptx?))'
            . '|(\/wp-content\/uploads\/[^\s"\'<>]+\.(?:jpe?g|png|gif|webp|avif|bmp|svg|mp4|mp3|pdf|zip|docx?|xlsx?|pptx?))/i',
            $content, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $urls[] = !empty($mm[1]) ? $mm[1] : $mm[2];
            }
        }
        return array_values(array_unique($urls));
    }
}

// 单个 post 的内容改写；返回 array('new'=>content,'changed'=>n,'urls'=>array)
if (!function_exists('zibll_takebox_rewrite_post_content')) {
    function zibll_takebox_rewrite_post_content($post_id, $map)
    {
        $post = get_post($post_id);
        if (!$post || empty($post->post_content)) {
            return array('new' => '', 'changed' => 0, 'urls' => array());
        }
        $content = $post->post_content;
        $urls = zibll_takebox_extract_local_urls($content);
        $changed = 0;
        $replaced = array();
        foreach ($urls as $url) {
            if (!zibll_takebox_is_local_uploads_url($url)) {
                continue;
            }
            $bn = basename(parse_url($url, PHP_URL_PATH));
            if (isset($map[$bn])) {
                if (strpos($content, $url) !== false) {
                    $content = str_replace($url, $map[$bn], $content);
                    $changed++;
                    $replaced[] = array('from' => $url, 'to' => $map[$bn]);
                }
            }
        }
        return array('new' => $content, 'changed' => $changed, 'urls' => $replaced);
    }
}

// 主入口：扫描并（可选）替换。dry_run=true 只统计不写库。
if (!function_exists('zibll_takebox_content_rewrite')) {
    function zibll_takebox_content_rewrite($dry_run = true)
    {
        if (!zibll_takebox_is_enabled()) {
            return array('ok' => false, 'msg' => '总开关未开启');
        }
        $adapter = zibll_takebox_get_adapter();
        if (!$adapter) {
            return array('ok' => false, 'msg' => '存储适配器不可用');
        }
        $map = zibll_takebox_build_oss_map();
        if (empty($map)) {
            return array('ok' => true, 'posts' => 0, 'changes' => 0, 'msg' => '无已上云附件，无需替换');
        }
        global $wpdb;
        $like = '%wp-content/uploads/%';
        $posts = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_type NOT IN ('attachment','revision') LIMIT 2000",
            $like
        ));
        $posts_scanned = 0;
        $posts_changed = 0;
        $total_changes = 0;
        $samples = array();
        foreach ($posts as $id) {
            $posts_scanned++;
            $res = zibll_takebox_rewrite_post_content($id, $map);
            if ($res['changed'] > 0) {
                $posts_changed++;
                $total_changes += $res['changed'];
                if (count($samples) < 5) {
                    $samples[] = array('id' => (int) $id, 'changes' => $res['changed'], 'sample' => $res['urls'][0]);
                }
                if (!$dry_run) {
                    // 备份原始内容，便于回滚
                    update_post_meta($id, '_zibll_takebox_content_backup', get_post_field('post_content', $id));
                    wp_update_post(array('ID' => (int) $id, 'post_content' => $res['new']));
                }
            }
        }
        return array(
            'ok'            => true,
            'dry_run'       => (bool) $dry_run,
            'posts_scanned' => $posts_scanned,
            'posts_changed' => $posts_changed,
            'changes'       => $total_changes,
            'samples'       => $samples,
            'msg'           => $dry_run ? '干跑预览完成' : '替换完成',
        );
    }
}

// ajax：扫描预览 / 执行替换
if (!function_exists('zibll_takebox_ajax_content_rewrite')) {
    function zibll_takebox_ajax_content_rewrite()
    {
        check_ajax_referer('zibll_takebox_tools', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('权限不足');
        }
        $execute = !empty($_POST['execute']);
        $res = zibll_takebox_content_rewrite(!$execute);
        if (!empty($res['ok'])) {
            wp_send_json_success($res);
        }
        wp_send_json_error($res['msg'] ?? '执行失败');
    }
}
add_action('wp_ajax_zibll_takebox_content_rewrite', 'zibll_takebox_ajax_content_rewrite');

// WP-CLI：wp takebox rewrite-content [--dry-run]
if (defined('WP_CLI') && class_exists('WP_CLI')) {
    WP_CLI::add_command('takebox rewrite-content', function ($args, $assoc) {
        $dry = isset($assoc['dry-run']) ? true : false;
        $res = zibll_takebox_content_rewrite($dry);
        if (empty($res['ok'])) {
            WP_CLI::warning($res['msg'] ?? '未执行');
            return;
        }
        WP_CLI::success(sprintf(
            '扫描 %d 篇，需改写 %d 篇，共 %d 处%s',
            $res['posts_scanned'], $res['posts_changed'], $res['changes'],
            $dry ? '（干跑，未写入）' : '（已替换并备份原文）'
        ));
    });
}
