<?php
/**
 * 图片处理（接管匣 TakeBox）：自动转 WebP + 图片水印
 * 独立于「对象存储接管」总开关，各有自己的启用开关。
 * 执行顺序：水印（priority 3）→ WebP（priority 5）→ 接管上传（priority 10）。
 * 这样 WebP 转换会压缩「已打水印」的原图，水印得以保留在最终 webp 里。
 *
 * 关键点：
 * 1) WebP 转换后需「递归再调 wp_generate_attachment_metadata」以生成 webp 的缩略图，
 *    该嵌套调用正好让接管匣（p10）拿到 webp 文件并 offload —— 与实测过的
 *    zib-webp-converter 行为一致，不会出现「循环覆盖」。
 * 2) 反向导入（OSS→本地）不触发图片处理，避免把桶里的原文件转成 webp 造成两端不一致。
 */

if (!defined('ABSPATH')) {
    exit;
}

// ===== 环境探测（只探测一次）=====
if (!function_exists('zibll_takebox_image_lib')) {
    function zibll_takebox_image_lib()
    {
        static $lib = null;
        if (null !== $lib) {
            return $lib;
        }
        $lib = array(
            'gd'     => function_exists('imagecreatetruecolor') && function_exists('imagejpeg'),
            'webp'   => function_exists('imagewebp'),
            'imagick' => class_exists('Imagick'),
        );
        return $lib;
    }
}

// ===== WebP 转换（GD 优先，Imagick 兜底）=====
if (!function_exists('zibll_takebox_convert_to_webp')) {
    function zibll_takebox_convert_to_webp($src, $quality = 82)
    {
        if (!file_exists($src)) {
            return false;
        }
        $dir  = dirname($src);
        $base = pathinfo($src, PATHINFO_FILENAME);
        $dest = $dir . '/' . $base . '.webp';
        $lib  = zibll_takebox_image_lib();
        $ext  = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        $quality = max(1, min(100, (int) $quality));

        // --- GD 路径 ---
        if ($lib['gd'] && $lib['webp']) {
            $img = false;
            if ('png' === $ext) {
                $img = @imagecreatefrompng($src);
            } elseif ('gif' === $ext) {
                $img = @imagecreatefromgif($src);
            } else {
                $img = @imagecreatefromjpeg($src);
            }
            if ($img) {
                // 保留透明度（png/gif 可能带 alpha）
                imagealphablending($img, true);
                imagesavealpha($img, true);
                $ok = @imagewebp($img, $dest, $quality);
                imagedestroy($img);
                return $ok ? $dest : false;
            }
        }

        // --- Imagick 兜底 ---
        if ($lib['imagick']) {
            try {
                $im = new Imagick($src);
                $im->setImageFormat('webp');
                $im->setImageCompressionQuality($quality);
                $ok = $im->writeImage($dest);
                $im->clear();
                $im->destroy();
                return $ok ? $dest : false;
            } catch (Exception $e) {
                return false;
            }
        }
        return false;
    }
}

// ===== 上传时自动转 WebP =====
if (!function_exists('zibll_takebox_webp_on_upload')) {
    function zibll_takebox_webp_on_upload($metadata, $attachment_id)
    {
        // 独立开关（不受对象存储总开关约束）
        if (!zibll_takebox_get_option('webp_enabled', 0)) {
            return $metadata;
        }
        // 反向导入：不转 webp（保持与桶内一致）
        if (function_exists('zibll_takebox_is_reverse_importing') && zibll_takebox_is_reverse_importing()) {
            return $metadata;
        }
        // 防重入：递归再调 wp_generate_attachment_metadata 时跳过本函数
        static $converting = array();
        if (!empty($converting[$attachment_id])) {
            return $metadata;
        }

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            return $metadata;
        }
        $mime = get_post_mime_type($attachment_id);
        if (!in_array($mime, array('image/jpeg', 'image/png', 'image/gif'), true)) {
            return $metadata; // 已 webp / avif / svg / 非图片 → 跳过
        }

        $converting[$attachment_id] = true;

        $quality = (int) zibll_takebox_get_option('webp_quality', 82);
        $webp    = zibll_takebox_convert_to_webp($file, $quality);
        if (!$webp) {
            unset($converting[$attachment_id]);
            return $metadata;
        }

        // 记录原文件（无论是否保留，供回溯）
        update_post_meta($attachment_id, '_zibll_takebox_webp_original', _wp_relative_upload_path($file));
        update_post_meta($attachment_id, '_zibll_takebox_webp', 1);

        // 附件主文件切换为 webp + MIME 改为 image/webp
        update_attached_file($attachment_id, _wp_relative_upload_path($webp));
        wp_update_post(array('ID' => $attachment_id, 'post_mime_type' => 'image/webp'));

        // 递归重生成 webp 的缩略图元数据（此嵌套调用会再次触发接管匣 p10 → offload webp）
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $new_metadata = wp_generate_attachment_metadata($attachment_id, $webp);
        if (is_array($new_metadata)) {
            wp_update_attachment_metadata($attachment_id, $new_metadata);
        }

        // 不保留原图：删除原 jpg/png/gif 及其缩略图
        if (!zibll_takebox_get_option('webp_keep_original', 1)) {
            @unlink($file);
            if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
                $dir = dirname($file);
                foreach ($metadata['sizes'] as $s) {
                    if (!empty($s['file']) && file_exists($dir . '/' . $s['file'])) {
                        @unlink($dir . '/' . $s['file']);
                    }
                }
            }
        }

        unset($converting[$attachment_id]);
        return is_array($new_metadata) ? $new_metadata : $metadata;
    }
}
add_filter('wp_generate_attachment_metadata', 'zibll_takebox_webp_on_upload', 5, 2);

// ===== 图片水印（GD）=====
if (!function_exists('zibll_takebox_watermark_on_upload')) {
    function zibll_takebox_watermark_on_upload($metadata, $attachment_id)
    {
        if (!zibll_takebox_get_option('watermark_enabled', 0)) {
            return $metadata;
        }
        if (function_exists('zibll_takebox_is_reverse_importing') && zibll_takebox_is_reverse_importing()) {
            return $metadata;
        }
        // 防重入：WebP 转换会递归再调 wp_generate_attachment_metadata，避免水印在 webp 上重复叠加
        static $done = array();
        if (!empty($done[$attachment_id])) {
            return $metadata;
        }
        $lib = zibll_takebox_image_lib();
        if (!$lib['gd']) {
            return $metadata; // 无 GD 无法水印
        }
        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            return $metadata;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, array('jpg', 'jpeg', 'png', 'webp'), true)) {
            return $metadata;
        }
        // 最小尺寸门槛：小于则不打
        $min = (int) zibll_takebox_get_option('watermark_min_size', 0);
        if ($min > 0) {
            $size = function_exists('getimagesize') ? @getimagesize($file) : false;
            if ($size && (int) $size[0] < $min) {
                return $metadata;
            }
        }

        $done[$attachment_id] = true;
        zibll_takebox_apply_watermark($file);
        return $metadata;
    }
}
add_filter('wp_generate_attachment_metadata', 'zibll_takebox_watermark_on_upload', 3, 2);

if (!function_exists('zibll_takebox_apply_watermark')) {
    function zibll_takebox_apply_watermark($file)
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        // 载入原图
        $img = false;
        if ('png' === $ext) {
            $img = @imagecreatefrompng($file);
        } elseif ('webp' === $ext && function_exists('imagecreatefromwebp')) {
            $img = @imagecreatefromwebp($file);
        } else {
            $img = @imagecreatefromjpeg($file);
        }
        if (!$img) {
            return false;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $pos    = zibll_takebox_get_option('watermark_position', 'bottom-right');
        $opacity = max(0, min(100, (int) zibll_takebox_get_option('watermark_opacity', 60)));
        $type   = zibll_takebox_get_option('watermark_type', 'text');

        if ('image' === $type) {
            $wm_src = trim((string) zibll_takebox_get_option('watermark_image', ''));
            $wm = zibll_takebox_watermark_load($wm_src);
            if ($wm) {
                zibll_takebox_watermark_composite($img, $wm, $pos, $opacity, $w, $h);
            }
        } else {
            $text = trim((string) zibll_takebox_get_option('watermark_text', ''));
            if ('' === $text) {
                $text = get_bloginfo('name');
            }
            zibll_takebox_watermark_text($img, $text, $pos, $opacity, $w, $h);
        }

        // 写回原格式
        $quality = (int) zibll_takebox_get_option('webp_quality', 82);
        if ('png' === $ext) {
            @imagepng($img, $file);
        } elseif ('webp' === $ext && function_exists('imagewebp')) {
            @imagewebp($img, $file, $quality);
        } else {
            @imagejpeg($img, $file, max(60, min(100, $quality)));
        }
        imagedestroy($img);
        return true;
    }
}

// 载入图片水印（本地路径或 URL）
if (!function_exists('zibll_takebox_watermark_load')) {
    function zibll_takebox_watermark_load($src)
    {
        if ('' === $src) {
            return false;
        }
        $path = $src;
        if (preg_match('#^https?://#i', $src)) {
            $tmp = download_url($src);
            if (is_wp_error($tmp)) {
                return false;
            }
            $path = $tmp;
        }
        if (!file_exists($path)) {
            return false;
        }
        $info = @getimagesize($path);
        $img  = false;
        if ($info) {
            switch ($info[2]) {
                case IMAGETYPE_PNG:  $img = @imagecreatefrompng($path); break;
                case IMAGETYPE_JPEG: $img = @imagecreatefromjpeg($path); break;
                case IMAGETYPE_GIF:  $img = @imagecreatefromgif($path); break;
            }
        }
        if (preg_match('#^https?://#i', $src) && file_exists($tmp)) {
            @unlink($tmp);
        }
        return $img;
    }
}

// 计算水印左上角坐标（9 宫格 + 10px 边距）
if (!function_exists('zibll_takebox_watermark_pos')) {
    function zibll_takebox_watermark_pos($pos, $w, $h, $ww, $wh, $margin = 12)
    {
        switch ($pos) {
            case 'top-left':     $x = $margin; $y = $margin; break;
            case 'top-center':   $x = (int) (($w - $ww) / 2); $y = $margin; break;
            case 'top-right':    $x = $w - $ww - $margin; $y = $margin; break;
            case 'center-left':  $x = $margin; $y = (int) (($h - $wh) / 2); break;
            case 'center':       $x = (int) (($w - $ww) / 2); $y = (int) (($h - $wh) / 2); break;
            case 'center-right': $x = $w - $ww - $margin; $y = (int) (($h - $wh) / 2); break;
            case 'bottom-left':  $x = $margin; $y = $h - $wh - $margin; break;
            case 'bottom-center':$x = (int) (($w - $ww) / 2); $y = $h - $wh - $margin; break;
            case 'bottom-right':
            default:             $x = $w - $ww - $margin; $y = $h - $wh - $margin; break;
        }
        return array(max(0, $x), max(0, $y));
    }
}

// 图片水印：合并（按不透明度）
if (!function_exists('zibll_takebox_watermark_composite')) {
    function zibll_takebox_watermark_composite($img, $wm, $pos, $opacity, $w, $h)
    {
        $ww = imagesx($wm);
        $wh = imagesy($wm);
        if ($ww > $w || $wh > $h) {
            // 水印比原图大则按比例缩小到 30%
            $ratio = min(0.3, min($w / $ww, $h / $wh));
            $nw = max(1, (int) ($ww * $ratio));
            $nh = max(1, (int) ($wh * $ratio));
            $scaled = imagecreatetruecolor($nw, $nh);
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            imagecopyresampled($scaled, $wm, 0, 0, 0, 0, $nw, $nh, $ww, $wh);
            imagedestroy($wm);
            $wm = $scaled;
            $ww = $nw; $wh = $nh;
        }
        list($x, $y) = zibll_takebox_watermark_pos($pos, $w, $h, $ww, $wh);
        $pct = (int) round((100 - $opacity) * 1.27); // 0 不透明 ~ 127 全透明
        imagecopymerge($img, $wm, $x, $y, 0, 0, $ww, $wh, $pct);
        imagedestroy($wm);
    }
}

// 自动寻找可用字体（TTF/TTC，可渲染中文）：
// 优先级 1) 用户配置路径 → 2) 插件内置中文字体 → 3) 系统常见中文字体
if (!function_exists('zibll_takebox_find_cjk_font')) {
    function zibll_takebox_find_cjk_font()
    {
        static $cached = null;
        if (null !== $cached) {
            return $cached;
        }
        $cached = '';

        // 1) 用户配置的字体路径
        $cfg = trim((string) zibll_takebox_get_option('watermark_font', ''));
        if ('' !== $cfg && @file_exists($cfg) && @is_readable($cfg)) {
            $cached = $cfg;
            return $cached;
        }

        // 2) 插件内置中文字体（文泉驿微米黑，开源可免费商用/再分发）
        $builtin = ZIBLL_TAKEBOX_PATH . 'assets/fonts/wqy-microhei.ttc';
        if (@file_exists($builtin) && @is_readable($builtin)) {
            $cached = $builtin;
            return $cached;
        }

        // 3) 系统常见中文字体（Linux / Windows / macOS）
        $candidates = array(
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/noto-cjk/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/truetype/wqy/wqy-microhei.ttc',
            '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
            '/usr/share/fonts/truetype/arphic/uming.ttc',
            '/usr/share/fonts/truetype/arphic/ukai.ttc',
            '/usr/share/fonts/truetype/droid/DroidSansFallbackFull.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            'C:\\Windows\\Fonts\\msyh.ttc',
            'C:\\Windows\\Fonts\\msyh.ttf',
            'C:\\Windows\\Fonts\\simhei.ttf',
            'C:\\Windows\\Fonts\\simsun.ttc',
            '/System/Library/Fonts/PingFang.ttc',
            '/System/Library/Fonts/STHeiti Light.ttc',
            '/System/Library/Fonts/Hiragino Sans GB.ttc',
        );
        foreach ($candidates as $f) {
            if (@file_exists($f) && @is_readable($f)) {
                $cached = $f;
                return $cached;
            }
        }
        return $cached; // '' 表示未找到
    }
}

// 文字水印（优先 TTF/TTC 字体渲染中文；纯 ASCII 且无字体时退回 GD 内置小字体）
if (!function_exists('zibll_takebox_watermark_text')) {
    function zibll_takebox_watermark_text($img, $text, $pos, $opacity, $w, $h)
    {
        $color_alpha  = (int) round((100 - $opacity) * 1.27);
        $has_non_ascii = (bool) preg_match('/[^\x00-\x7F]/', $text);

        $font = zibll_takebox_find_cjk_font();
        if ('' !== $font && function_exists('imagettftext')) {
            $size = max(10, (int) round($w / 30));
            $box  = @imagettfbbox($size, 0, $font, $text);
            if ($box) {
                $ww = abs($box[2] - $box[0]);
                $wh = abs($box[5] - $box[1]);
                list($x, $y) = zibll_takebox_watermark_pos($pos, $w, $h, $ww, $wh);
                $shadow = imagecolorallocatealpha($img, 0, 0, 0, $color_alpha);
                $white  = imagecolorallocatealpha($img, 255, 255, 255, $color_alpha);
                @imagettftext($img, $size, 0, $x + 1, $y + $wh + 1, $shadow, $font, $text);
                @imagettftext($img, $size, 0, $x, $y + $wh, $white, $font, $text);
                return;
            }
        }

        // 无 TTF 且文本含非 ASCII（中文等）：GD 内置字体无法渲染，跳过避免乱码
        if ($has_non_ascii) {
            return;
        }

        // 内置字体（fallback，仅纯 ASCII，字号小但零依赖）
        $size = 5;
        $ww   = strlen($text) * imagefontwidth($size);
        $wh   = imagefontheight($size);
        list($x, $y) = zibll_takebox_watermark_pos($pos, $w, $h, $ww, $wh);
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, $color_alpha);
        $white  = imagecolorallocatealpha($img, 255, 255, 255, $color_alpha);
        imagestring($img, $size, $x + 1, $y + 1, $text, $shadow);
        imagestring($img, $size, $x, $y, $text, $white);
    }
}
