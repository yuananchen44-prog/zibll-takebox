<?php
/**
 * 媒体库信息面板增强 + 逐文件权限切换（接管匣 TakeBox）
 * 在附件详情页展示：上传于 / 上传者 / 文件名 / 文件类型 / 文件大小 / 分辨率 / 服务商 / 区域 / 访问权限。
 * 仅对已被接管的附件显示（有 _zibll_takebox_provider meta）。
 * 同时提供「访问权限」下拉：公开 / 私有，随附件保存时同步对象存储 ACL。
 */

if (!defined('ABSPATH')) {
    exit;
}

// ACL 存储值 → 中文展示
if (!function_exists('zibll_takebox_acl_label')) {
    function zibll_takebox_acl_label($acl)
    {
        return ('private' === $acl) ? '私有' : '公开';
    }
}

// 统一设置附件访问权限：写 meta，并在总开关开启时同步对象存储的 ACL
if (!function_exists('zibll_takebox_set_attachment_acl')) {
    function zibll_takebox_set_attachment_acl($attachment_id, $acl)
    {
        $acl = in_array($acl, array('public-read', 'private'), true) ? $acl : 'public-read';
        update_post_meta($attachment_id, '_zibll_takebox_acl', $acl);
        if (!zibll_takebox_is_enabled()) {
            return true;
        }
        $adapter = zibll_takebox_get_adapter();
        if (!$adapter || !method_exists($adapter, 'set_object_acl')) {
            return true;
        }
        $keys = get_post_meta($attachment_id, '_zibll_takebox_keys', true);
        if (empty($keys) || !is_array($keys)) {
            return true;
        }
        $all = array();
        if (!empty($keys['original'])) {
            $all[] = $keys['original'];
        }
        if (!empty($keys['sizes']) && is_array($keys['sizes'])) {
            $all = array_merge($all, array_values($keys['sizes']));
        }
        foreach ($all as $k) {
            $adapter->set_object_acl($k, $acl);
        }
        return true;
    }
}

// 附件保存时（含媒体库详情页更新）持久化逐文件权限
if (!function_exists('zibll_takebox_save_attachment_acl')) {
    function zibll_takebox_save_attachment_acl($attachment_id)
    {
        // 弹窗 / 编辑页标准字段结构：attachments[<id>][zibll_takebox_acl]
        $raw = '';
        if (isset($_POST['attachments'][$attachment_id]['zibll_takebox_acl'])) {
            $raw = $_POST['attachments'][$attachment_id]['zibll_takebox_acl'];
        } elseif (isset($_POST['zibll_takebox_acl'])) {
            // 兼容旧版直接提交
            $raw = $_POST['zibll_takebox_acl'];
        }
        if ('' === $raw) {
            return;
        }
        if (!get_post_meta($attachment_id, '_zibll_takebox_provider', true)) {
            return; // 未被接管的不处理
        }
        $acl = in_array($raw, array('public-read', 'private'), true) ? $raw : 'public-read';
        zibll_takebox_set_attachment_acl($attachment_id, $acl);
    }
}
add_action('edit_attachment', 'zibll_takebox_save_attachment_acl');

// 在附件详情表单（媒体库弹窗 + 经典编辑页）注入接管匣信息面板
if (!function_exists('zibll_takebox_attachment_fields_to_edit')) {
    function zibll_takebox_attachment_fields_to_edit($form_fields, $post)
    {
        if (!zibll_takebox_get_option('meta_enabled', 1)) {
            return $form_fields;
        }
        $id       = $post->ID;
        $provider = get_post_meta($id, '_zibll_takebox_provider', true);
        if (!$provider) {
            return $form_fields; // 未被接管的不显示
        }
        $region = get_post_meta($id, '_zibll_takebox_region', true);
        $bucket = get_post_meta($id, '_zibll_takebox_bucket', true);
        $acl    = get_post_meta($id, '_zibll_takebox_acl', true) ?: 'public-read';
        $author = get_the_author_meta('display_name', $post->post_author);
        $file   = get_attached_file($id);
        $size   = $file ? @filesize($file) : 0;
        $size_str = $size ? size_format($size) : '';
        $dim = '';
        if (wp_attachment_is_image($id)) {
            $meta = wp_get_attachment_metadata($id);
            if (!empty($meta['width']) && !empty($meta['height'])) {
                $dim = $meta['width'] . '×' . $meta['height'] . ' 像素';
            }
        }
        ob_start();
        ?>
        <ul style="margin:4px 0 0;line-height:1.7;">
            <li>上传于：<?php echo esc_html(get_the_date('Y年n月j日', $id)); ?></li>
            <li>上传者：<?php echo esc_html($author); ?></li>
            <li>文件名：<?php echo esc_html(basename($file)); ?></li>
            <li>文件类型：<?php echo esc_html($post->post_mime_type); ?></li>
            <?php if ($size_str) : ?><li>文件大小：<?php echo esc_html($size_str); ?></li><?php endif; ?>
            <?php if ($dim) : ?><li>分辨率：<?php echo esc_html($dim); ?></li><?php endif; ?>
            <li>服务商：<?php echo esc_html($provider); ?></li>
            <?php if ($region) : ?><li>区域：<?php echo esc_html($region); ?></li><?php endif; ?>
            <?php if ($bucket) : ?><li>存储桶：<?php echo esc_html($bucket); ?></li><?php endif; ?>
            <li>访问权限：<?php echo esc_html(zibll_takebox_acl_label($acl)); ?></li>
        </ul>
        <p style="margin:8px 0 0;">
            <label for="zibll-takebox-acl" style="font-weight:600;">修改访问权限：</label>
            <select name="attachments[<?php echo esc_attr($id); ?>][zibll_takebox_acl]" id="zibll-takebox-acl">
                <option value="public-read" <?php selected('public-read', $acl); ?>>公开（直链）</option>
                <option value="private" <?php selected('private', $acl); ?>>私有（签名 URL）</option>
            </select>
        </p>
        <p class="description" style="margin:4px 0 0;">设为私有后，前端通过 WordPress 动态生成的带过期签名直链访问，对象存储不暴露真实可公开地址。</p>
        <?php
        $form_fields['zibll_takebox_meta'] = array(
            'label' => '接管匣 TakeBox',
            'input' => 'html',
            'html'  => ob_get_clean(),
        );
        return $form_fields;
    }
}
add_filter('attachment_fields_to_edit', 'zibll_takebox_attachment_fields_to_edit', 10, 2);
