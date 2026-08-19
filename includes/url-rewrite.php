<?php
/**
 * URL 改写（接管匣 TakeBox）
 * 必须走过滤器层（而非只替换 HTML），否则主题海报/下载/用户资料/后台字段会读旧地址。
 */

if (!defined('ABSPATH')) {
    exit;
}

// 改写附件原图 URL
if (!function_exists('zibll_takebox_filter_attachment_url')) {
    function zibll_takebox_filter_attachment_url($url, $attachment_id)
    {
        if (!zibll_takebox_is_enabled()) {
            return $url;
        }
        $keys = get_post_meta($attachment_id, '_zibll_takebox_keys', true);
        if (empty($keys['original'])) {
            return $url;
        }
        $adapter = zibll_takebox_get_adapter();
        if (!$adapter) {
            return $url;
        }
        $acl = get_post_meta($attachment_id, '_zibll_takebox_acl', true);
        if ('private' === $acl) {
            return $adapter->presigned_url($keys['original'], (int) zibll_takebox_get_option('sign_url_ttl', 3600));
        }
        return $adapter->public_url($keys['original']);
    }
}
add_filter('wp_get_attachment_url', 'zibll_takebox_filter_attachment_url', 10, 2);

// 改写各尺寸 URL（wp_get_attachment_image_src 返回值数组 [url, w, h, is_intermediate]）
if (!function_exists('zibll_takebox_filter_image_src')) {
    function zibll_takebox_filter_image_src($image, $attachment_id, $size)
    {
        if (!zibll_takebox_is_enabled()) {
            return $image;
        }
        if (empty($image) || !is_array($image)) {
            return $image;
        }
        $keys = get_post_meta($attachment_id, '_zibll_takebox_keys', true);
        if (empty($keys) || !is_array($keys)) {
            return $image;
        }
        $adapter = zibll_takebox_get_adapter();
        if (!$adapter) {
            return $image;
        }
        if ('full' === $size || 'original' === $size) {
            $key = isset($keys['original']) ? $keys['original'] : '';
        } elseif (!empty($keys['sizes'][$size])) {
            $key = $keys['sizes'][$size];
        } else {
            $key = isset($keys['original']) ? $keys['original'] : '';
        }
        if (!$key) {
            return $image;
        }
        $acl = get_post_meta($attachment_id, '_zibll_takebox_acl', true);
        if ('private' === $acl) {
            $image[0] = $adapter->presigned_url($key, (int) zibll_takebox_get_option('sign_url_ttl', 3600));
        } else {
            $image[0] = $adapter->public_url($key);
        }
        return $image;
    }
}
add_filter('wp_get_attachment_image_src', 'zibll_takebox_filter_image_src', 10, 3);

// 改写「媒体库网格 / 媒体弹窗 / REST /wp/v2/media」吐出的附件数据。
// 关键背景：核心 wp_prepare_attachment_for_js 对每个尺寸先调用 apply_filters('image_downsize', false, ...)，
// 若没人挂钩子就 fallback 到「$base_url . 元数据里的尺寸文件名」。而 $base_url 来自已被改写的 OSS 原图地址，
// 一旦开了 rename_uploads，OSS 上的缩略图叫 1165-image-150x150.jpg，但元数据尺寸文件名还是原文件名，
// 拼出来的 OSS 地址根本不存在 → 网格封面 404 / 不显示。所以我们必须在这一层显式按 keys 重写 url 与 sizes[].url。
// （这一层与 wp_get_attachment_image_src 是两条独立路径，前者供网格/弹窗/REST，后者供前端 the_post_thumbnail 等。）
if (!function_exists('zibll_takebox_filter_prepare_for_js')) {
    function zibll_takebox_filter_prepare_for_js($response)
    {
        if (!zibll_takebox_is_enabled()) {
            return $response;
        }
        if (empty($response['id']) || !is_numeric($response['id'])) {
            return $response;
        }
        $attachment_id = (int) $response['id'];
        $keys = get_post_meta($attachment_id, '_zibll_takebox_keys', true);
        if (empty($keys) || !is_array($keys)) {
            return $response;
        }
        $adapter = zibll_takebox_get_adapter();
        if (!$adapter) {
            return $response;
        }
        $acl  = get_post_meta($attachment_id, '_zibll_takebox_acl', true);
        $sign = ('private' === $acl);
        $ttl  = (int) zibll_takebox_get_option('sign_url_ttl', 3600);
        $url_for = function ($key) use ($adapter, $sign, $ttl) {
            return $sign ? $adapter->presigned_url($key, $ttl) : $adapter->public_url($key);
        };

        // 原图地址
        if (!empty($keys['original'])) {
            $response['url'] = $url_for($keys['original']);
        }
        // 各尺寸地址（网格封面 thumbnail 走这里）
        if (!empty($response['sizes']) && is_array($response['sizes'])) {
            foreach ($response['sizes'] as $size => $data) {
                if (!is_array($data)) {
                    continue;
                }
                if (isset($keys['sizes'][$size])) {
                    $response['sizes'][$size]['url'] = $url_for($keys['sizes'][$size]);
                } elseif (!empty($keys['original'])) {
                    // 该尺寸未在接管 keys 中登记（如某些自定义尺寸）：回退原图，避免 404
                    $response['sizes'][$size]['url'] = $url_for($keys['original']);
                }
            }
        }
        // 原图编辑页用的大图地址
        if (!empty($response['originalImageURL']) && !empty($keys['original'])) {
            $response['originalImageURL'] = $url_for($keys['original']);
        }
        return $response;
    }
}
add_filter('wp_prepare_attachment_for_js', 'zibll_takebox_filter_prepare_for_js', 10, 1);
