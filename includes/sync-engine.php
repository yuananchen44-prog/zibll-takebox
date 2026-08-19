<?php
/**
 * 双向同步引擎（接管匣 TakeBox）里程碑 6
 * 手动触发 → WP Cron 后台分批（关页不中断）→ 进度 → 完成后台通知（+可选邮件）
 * 差异比对：本地有/桶无 → 上传(forward)；桶有/本地无 → 反向导入(reverse)；已存在 → 跳过
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZIBLL_TAKEBOX_SYNC_BATCH')) {
    define('ZIBLL_TAKEBOX_SYNC_BATCH', 20);
}

// 触发同步（手动按钮 / WP-CLI）。返回 false 表示无法启动（总开关关、无适配器、已有任务在跑）。
if (!function_exists('zibll_takebox_sync_start')) {
    function zibll_takebox_sync_start($direction = 'both')
    {
        if (!zibll_takebox_is_enabled()) {
            return false;
        }
        $adapter = zibll_takebox_get_adapter();
        if (!$adapter) {
            return false;
        }
        $job = get_option('_zibll_takebox_sync_job', array());
        if (!empty($job['status']) && in_array($job['status'], array('queued', 'running'), true)) {
            return false; // 已有任务在运行
        }
        $queue = zibll_takebox_build_queue($direction, $adapter);
        update_option('_zibll_takebox_sync_job', array(
            'direction' => $direction,
            'total'     => count($queue),
            'processed' => 0,
            'queue'     => $queue,
            'status'    => 'queued',
            'started'   => time(),
            'finished'  => 0,
            'errors'    => array(),
        ));
        // 后台分批：先立即跑一批，后续用 WP Cron 续跑（关页不中断）
        wp_schedule_single_event(time() + 3, 'zibll_takebox_sync_tick');
        return true;
    }
}

// 构建任务队列（forward：缺 meta 且有本地文件的附件；reverse：桶内对象）
if (!function_exists('zibll_takebox_build_queue')) {
    function zibll_takebox_build_queue($direction, $adapter)
    {
        $queue = array();
        if (in_array($direction, array('both', 'forward'), true)) {
            $ids = get_posts(array(
                'post_type'      => 'attachment',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => array(array('key' => '_zibll_takebox_keys', 'compare' => 'NOT EXISTS')),
            ));
            foreach ($ids as $id) {
                $file = get_attached_file($id);
                if ($file && file_exists($file)) {
                    $queue[] = array('type' => 'forward', 'id' => (int) $id);
                }
            }
        }
        if (in_array($direction, array('both', 'reverse'), true)) {
            $prefix = trim((string) zibll_takebox_get_option('custom_path', ''), '/');
            if ('' === $prefix) {
                $prefix = 'wp-content/uploads';
            }
            $keys   = $adapter->list_objects($prefix);
            foreach ($keys as $k) {
                $queue[] = array('type' => 'reverse', 'key' => $k);
            }
        }
        return $queue;
    }
}

// 后台分批处理（WP Cron 触发）
if (!function_exists('zibll_takebox_sync_tick')) {
    function zibll_takebox_sync_tick()
    {
        $job = get_option('_zibll_takebox_sync_job', array());
        if (empty($job['status']) || !in_array($job['status'], array('queued', 'running'), true)) {
            return;
        }
        $adapter = zibll_takebox_get_adapter();
        if (!$adapter) {
            $job['status'] = 'error';
            $job['errors'][] = '存储适配器不可用';
            update_option('_zibll_takebox_sync_job', $job);
            return;
        }

        $job['status'] = 'running';
        $queue = !empty($job['queue']) && is_array($job['queue']) ? $job['queue'] : array();
        $batch = array_splice($queue, 0, ZIBLL_TAKEBOX_SYNC_BATCH);
        $errors = !empty($job['errors']) ? $job['errors'] : array();

        foreach ($batch as $task) {
            if ('forward' === $task['type']) {
                $ok = zibll_takebox_upload_attachment($task['id']);
                if (!$ok) {
                    $errors[] = 'forward #' . $task['id'] . ' 失败';
                }
            } elseif ('reverse' === $task['type']) {
                $ok = zibll_takebox_import_from_oss($adapter, $task['key']);
                if (!$ok) {
                    $errors[] = 'reverse ' . $task['key'] . ' 失败';
                }
            }
            $job['processed']++;
        }

        $job['queue']  = $queue;
        $job['errors'] = $errors;

        if (!empty($queue)) {
            update_option('_zibll_takebox_sync_job', $job);
            wp_schedule_single_event(time() + 3, 'zibll_takebox_sync_tick');
        } else {
            $job['status']   = 'done';
            $job['finished'] = time();
            update_option('_zibll_takebox_sync_job', $job);
            zibll_takebox_sync_notify($job);
        }
    }
}
add_action('zibll_takebox_sync_tick', 'zibll_takebox_sync_tick');

// 反向导入：OSS 对象 → 媒体库（已存在的跳过）
if (!function_exists('zibll_takebox_import_from_oss')) {
    function zibll_takebox_import_from_oss($adapter, $key)
    {
        // 目录占位对象（以 / 结尾的零字节"文件夹标记"）不是真实文件，跳过
        if (substr($key, -1) === '/') {
            return true;
        }
        // 跳过 WordPress 生成的尺寸缩略图（如 -150x150、-800x800），避免作为独立附件重复导入；
        // 原图导入后 WP 会本地重新生成缩略图
        $base = basename($key);
        if (preg_match('/-\d+x\d+\.\w+$/i', $base)) {
            return true;
        }
        // 仅允许媒体/文档类型导入，避免把桶里的 txt/json/sql/备份等非媒体对象塞进媒体库
        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        $allowed = array(
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tiff', 'ico', 'svg',
            'mp4', 'mov', 'wmv', 'avi', 'mkv', 'webm', 'ogv', 'm4v', 'mpg', 'mpeg', '3gp',
            'mp3', 'wav', 'ogg', 'aac', 'm4a', 'wma', 'flac',
            'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx',
        );
        if (!in_array($ext, $allowed, true)) {
            return true; // 非媒体类型，跳过（不报错）
        }
        // 已导入过则跳过
        $exists = get_posts(array(
            'post_type'      => 'attachment',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'meta_query'     => array(array('key' => '_zibll_takebox_object_key', 'value' => $key, 'compare' => '=')),
        ));
        if (!empty($exists)) {
            return true;
        }
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $url = $adapter->base_url() . '/' . $key;
        $tmp = download_url($url);
        if (is_wp_error($tmp)) {
            return false;
        }
        $file_array = array('name' => basename($key), 'tmp_name' => $tmp);
        // 屏蔽「上传接管」钩子：反向导入只是把桶里已有的对象登记到本地媒体库，
        // 不应再把刚拉下来的文件上传回桶（否则会创建重复/改名对象，违背“反向导入不动桶内容”）。
        zibll_takebox_set_reverse_importing(true);
        $post_id = media_handle_sideload($file_array, 0);
        zibll_takebox_set_reverse_importing(false);
        if (is_wp_error($post_id)) {
            @unlink($tmp);
            return false;
        }
        update_post_meta($post_id, '_zibll_takebox_object_key', $key);
        // 合并保留：media_handle_sideload 触发上传钩子时已把缩略图 sizes 记进 _zibll_takebox_keys，
        // 此处只把 original 修正为云端真实 key，切勿整体覆盖，否则删附件时缩略图会删不干净
        $existing_keys = get_post_meta($post_id, '_zibll_takebox_keys', true);
        if (!is_array($existing_keys)) {
            $existing_keys = array();
        }
        $existing_keys['original'] = $key;
        update_post_meta($post_id, '_zibll_takebox_keys', $existing_keys);
        update_post_meta($post_id, '_zibll_takebox_provider', $adapter->provider_label());
        update_post_meta($post_id, '_zibll_takebox_region', $adapter->region());
        update_post_meta($post_id, '_zibll_takebox_bucket', $adapter->bucket());
        update_post_meta($post_id, '_zibll_takebox_acl', 'public-read');
        return true;
    }
}

// 完成通知（后台提示 + 可选邮件）
if (!function_exists('zibll_takebox_sync_notify')) {
    function zibll_takebox_sync_notify($job)
    {
        update_option('_zibll_takebox_sync_notice', array(
            'time'      => time(),
            'processed' => $job['processed'],
            'errors'    => isset($job['errors']) ? $job['errors'] : array(),
        ));
        $email = zibll_takebox_get_option('sync_email', '');
        if ($email && is_email($email)) {
            wp_mail(
                $email,
                '接管匣 TakeBox 同步完成',
                '本次同步处理 ' . (int) $job['processed'] . ' 项，错误 ' . count($job['errors']) . ' 项。'
            );
        }
    }
}

// 后台通知展示（24h 内有效，展示一次后清除）
if (!function_exists('zibll_takebox_sync_notice_show')) {
    function zibll_takebox_sync_notice_show()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $n = get_option('_zibll_takebox_sync_notice', array());
        if (empty($n['time'])) {
            return;
        }
        if (time() - (int) $n['time'] > 86400) {
            delete_option('_zibll_takebox_sync_notice');
            return;
        }
        $err = !empty($n['errors']) ? '，错误 ' . count($n['errors']) . ' 项' : '，无错误';
        echo '<div class="notice notice-success is-dismissible"><p>接管匣 TakeBox：上次同步于 '
            . date('Y-m-d H:i', $n['time']) . ' 完成，共处理 ' . (int) $n['processed'] . ' 项' . $err . '。</p></div>';
        delete_option('_zibll_takebox_sync_notice');
    }
}
add_action('admin_notices', 'zibll_takebox_sync_notice_show');

// 进度轮询 ajax（只读，不执行任务）
if (!function_exists('zibll_takebox_ajax_sync_status')) {
    function zibll_takebox_ajax_sync_status()
    {
        check_ajax_referer('zibll_takebox_sync', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('权限不足');
        }
        wp_send_json_success(get_option('_zibll_takebox_sync_job', array()));
    }
}
add_action('wp_ajax_zibll_takebox_sync_status', 'zibll_takebox_ajax_sync_status');

// 触发同步 ajax
if (!function_exists('zibll_takebox_ajax_sync_start')) {
    function zibll_takebox_ajax_sync_start()
    {
        check_ajax_referer('zibll_takebox_sync', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('权限不足');
        }
        $direction = isset($_POST['direction']) ? sanitize_key($_POST['direction']) : 'both';
        if (!in_array($direction, array('both', 'forward', 'reverse'), true)) {
            $direction = 'both';
        }
        $ok = zibll_takebox_sync_start($direction);
        if ($ok) {
            wp_send_json_success(array('msg' => '同步任务已启动'));
        }
        wp_send_json_error('无法启动（请检查总开关、存储配置，或已有任务在运行）');
    }
}
add_action('wp_ajax_zibll_takebox_sync_start', 'zibll_takebox_ajax_sync_start');

// ===== 孤儿对象清理（默认关，需设置页开启 + 显式执行）=====
// 列出 OSS 中本地已无对应附件引用的对象（孤儿）；execute=true 才删除。
if (!function_exists('zibll_takebox_orphan_cleanup')) {
    function zibll_takebox_orphan_cleanup($execute = false)
    {
        if (!zibll_takebox_is_enabled()) {
            return array('ok' => false, 'msg' => '总开关未开启');
        }
        $adapter = zibll_takebox_get_adapter();
        if (!$adapter) {
            return array('ok' => false, 'msg' => '存储适配器不可用');
        }
        if ($execute && !zibll_takebox_get_option('cleanup_orphans', 0)) {
            return array('ok' => false, 'msg' => '请先在设置页开启「允许清理孤儿对象」');
        }

        // 已上云对象的 key 集合（原图 + 各尺寸）
        $existing = array();
        $atts = get_posts(array(
            'post_type'      => 'attachment',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(array('key' => '_zibll_takebox_object_key', 'compare' => 'EXISTS')),
        ));
        foreach ($atts as $id) {
            $keys = get_post_meta($id, '_zibll_takebox_keys', true);
            if (!empty($keys['original'])) {
                $existing[$keys['original']] = 1;
            }
            if (!empty($keys['sizes']) && is_array($keys['sizes'])) {
                foreach ($keys['sizes'] as $k) {
                    $existing[$k] = 1;
                }
            }
        }

        $prefix  = trim((string) zibll_takebox_get_option('custom_path', ''), '/');
        if ('' === $prefix) {
            $prefix = 'wp-content/uploads';
        }
        $objects = $adapter->list_objects($prefix);
        $orphans = array();
        foreach ($objects as $obj) {
            if (!isset($existing[$obj])) {
                $orphans[] = $obj;
            }
        }

        if ($execute) {
            $deleted = 0;
            foreach ($orphans as $obj) {
                if ($adapter->delete($obj)) {
                    $deleted++;
                }
            }
            return array('ok' => true, 'execute' => true, 'total' => count($orphans), 'deleted' => $deleted);
        }
        return array('ok' => true, 'execute' => false, 'total' => count($orphans), 'orphans' => $orphans);
    }
}

// ajax 孤儿清理（扫描/执行）
if (!function_exists('zibll_takebox_ajax_orphan_cleanup')) {
    function zibll_takebox_ajax_orphan_cleanup()
    {
        check_ajax_referer('zibll_takebox_tools', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('权限不足');
        }
        $execute = !empty($_POST['execute']);
        $res = zibll_takebox_orphan_cleanup($execute);
        if (!empty($res['ok'])) {
            wp_send_json_success($res);
        }
        wp_send_json_error($res['msg'] ?? '失败');
    }
}
add_action('wp_ajax_zibll_takebox_orphan_cleanup', 'zibll_takebox_ajax_orphan_cleanup');

// WP-CLI：wp takebox cleanup-orphans [--execute]
if (defined('WP_CLI') && class_exists('WP_CLI')) {
    WP_CLI::add_command('takebox cleanup-orphans', function ($args, $assoc) {
        $execute = isset($assoc['execute']) ? true : false;
        $res = zibll_takebox_orphan_cleanup($execute);
        if (empty($res['ok'])) {
            WP_CLI::warning($res['msg'] ?? '未执行');
            return;
        }
        if ($execute) {
            WP_CLI::success(sprintf('共 %d 个孤儿对象，已删除 %d 个', $res['total'], $res['deleted']));
        } else {
            WP_CLI::success(sprintf('发现 %d 个孤儿对象（干跑，加 --execute 执行删除）', $res['total']));
        }
    });
}

// WP-CLI：wp takebox sync --direction=both
if (defined('WP_CLI') && class_exists('WP_CLI')) {
    WP_CLI::add_command('takebox sync', function ($args, $assoc) {
        $direction = isset($assoc['direction']) ? $assoc['direction'] : 'both';
        $ok = zibll_takebox_sync_start($direction);
        WP_CLI::success($ok ? '同步任务已触发（后台运行）' : '同步未执行（检查总开关/存储配置/是否已有任务）');
    });
}
