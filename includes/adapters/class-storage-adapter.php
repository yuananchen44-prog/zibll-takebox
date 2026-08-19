<?php
/**
 * 存储适配器抽象基类（接管匣 TakeBox）
 * 所有对象存储适配器（S3 / R2 / OSS）都继承此类，统一 upload / delete / list / url 接口。
 * v0.1.16：public_url() 跟随站点协议（https 站内强制 https），修复「混合内容」导致后台缩略图不显示。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Zibll_Takebox_Storage_Adapter')) {
    abstract class Zibll_Takebox_Storage_Adapter
    {
        protected $opts = array();

        public function __construct($opts = array())
        {
            $this->opts = is_array($opts) ? $opts : array();
        }

        /**
         * 根据「自定义路径 + 年月开关 + 上传自动更名」计算对象 key。
         * 规则：存储桶 / [自定义路径] / [年/月] / [附件ID-]文件名
         *
         * @param string $basename      文件名（不含目录）
         * @param int    $attachment_id 附件 ID；>0 且开启「上传自动更名」时，文件名前置「ID-」。
         *                              反向导入等无附件 ID 的场景传 0，不做更名（沿用桶内已有 key）。
         */
        protected function apply_path_rules($basename, $attachment_id = 0)
        {
            $parts = array();
            $cp = trim((string) zibll_takebox_get_option('custom_path', ''), '/');
            if ('' === $cp) {
                // 默认落在 wp-content/uploads 下，与本地原始 wp-content/uploads 结构保持一致；
                // 对象存储接管场景下几乎总是希望保留此前缀，故空值不再表示“桶根”。
                $cp = 'wp-content/uploads';
            }
            $parts[] = $cp;
            if (zibll_takebox_get_option('year_month', 0)) {
                $parts[] = gmdate('Y');
                $parts[] = gmdate('m');
            }
            $basename = ltrim($basename, '/');
            // 上传自动更名：开启后对象 key 的文件名整体替换为“标准化标识”，
            // 即「附件ID-类型标识[-尺寸后缀].扩展名」，例如 1162-image.jpg、1162-image-150x150.jpg、
            // 1162-video.mp4、1162-file.pdf。彻底去掉原始文件名里的中文/表情/长串/空格，命名标准化。
            // 仅 forward 上传（有附件 ID）生效；反向导入 / 连接测试传 0，不受影响。
            if ($attachment_id > 0 && zibll_takebox_get_option('rename_uploads', 0)) {
                $basename = zibll_takebox_clean_rename($basename, (int) $attachment_id);
            }
            $parts[] = $basename;
            return implode('/', $parts);
        }

        // ===== 子类必须实现的接口 =====
        abstract public function build_object_key($basename, $attachment_id = 0);
        abstract public function upload($local_path, $object_key, $acl = 'public-read');
        abstract public function delete($object_key);
        abstract public function list_objects($prefix = '');
        abstract public function presigned_url($object_key, $ttl = 3600);
        abstract public function base_url();
        abstract public function provider_label();
        abstract public function region();
        abstract public function bucket();

        /**
         * 修改已存在对象的访问权限（公开 public-read / 私有 private）。
         * 默认实现返回 false；S3/R2 与 OSS 适配器各自覆盖。
         */
        public function set_object_acl($object_key, $acl)
        {
            return false;
        }

        /**
         * 判断某本地文件是否应走 Multipart 分片上传。
         * 依据设置 multipart_threshold（MB，0 = 关闭分片）。
         */
        public function should_multipart($local_path)
        {
            $threshold = (int) zibll_takebox_get_option('multipart_threshold', 0);
            if ($threshold <= 0) {
                return false;
            }
            $size = @filesize($local_path);
            return (false !== $size) && $size >= ($threshold * 1024 * 1024);
        }

        /**
         * Multipart 单个分片大小（字节）。S3/OSS 除最后一片外最小 5MB。
         */
        public function multipart_part_size()
        {
            $mb = (int) zibll_takebox_get_option('multipart_part_size', 10);
            $mb = max(5, min(100, $mb));
            return $mb * 1024 * 1024;
        }

        /**
         * 对外暴露的「公开访问地址」（用户最终看到的 URL）。
         * 若用户在设置里填了「自定义公开域名」，则一律走自定义域名（CDN / 自有域名）；
         * 否则回退到真实存储端点（base_url）。
         * 注意：此地址只用于「展示 / 落库附件 URL」，不可用于真实 API 请求（签名会失效）。
         * 真实的 PUT/DELETE/列举/签名 URL 必须由 object_url()（基于 base_url）提供。
         *
         * @param string $object_key 对象 key（可为空，返回域名前缀）
         */
        public function public_url($object_key)
        {
            $custom = trim((string) zibll_takebox_get_option('public_domain', ''), " \t\n\r\0\x0B/");
            $key    = $this->encode_key($object_key);
            if ('' !== $custom) {
                // 去除用户可能填写的协议头，统一「跟随站点本身协议」输出。
                // 站点为 https 时必须输出 https，否则站内 <img src="http://..."> 会触发
                // 浏览器「混合内容（mixed content）」拦截——现象就是后台媒体库缩略图等
                // 所有远程图都不显示，但单独新标签打开该 http 链接却正常。
                $custom = preg_replace('#^https?://#i', '', $custom);
                $scheme = 'http://';
                $home   = function_exists('home_url') ? home_url() : '';
                if (('' !== $home && 'https' === parse_url($home, PHP_URL_SCHEME))
                    || (function_exists('is_ssl') && is_ssl())) {
                    $scheme = 'https://';
                }
                return $scheme . rtrim($custom, '/') . '/' . $key;
            }
            return $this->base_url() . '/' . $key;
        }
    }
}

// ===== 上传自动更名：把原始文件名整体替换为标准化标识 =====
// 「附件ID-类型[-尺寸].ext」，类型按扩展名映射（image/video/audio/file），
// 保留 WordPress 缩略图尺寸后缀（-WxH）与扩展名。原始文件名（中文/表情/长串）全部丢弃。
// 例：photo.jpg → 1162-image.jpg；photo-150x150.jpg → 1162-image-150x150.jpg；a.mp4 → 1162-video.mp4
if (!function_exists('zibll_takebox_clean_rename')) {
    function zibll_takebox_clean_rename($basename, $attachment_id)
    {
        $basename = ltrim($basename, '/');
        $map = array(
            'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image',
            'webp' => 'image', 'avif' => 'image', 'bmp' => 'image', 'svg' => 'image',
            'ico' => 'image', 'tiff' => 'image',
            'mp4' => 'video', 'mov' => 'video', 'wmv' => 'video', 'avi' => 'video',
            'mkv' => 'video', 'webm' => 'video', 'ogv' => 'video', 'm4v' => 'video',
            'mpg' => 'video', 'mpeg' => 'video', '3gp' => 'video',
            'mp3' => 'audio', 'wav' => 'audio', 'ogg' => 'audio', 'aac' => 'audio',
            'm4a' => 'audio', 'wma' => 'audio', 'flac' => 'audio',
        );
        if (preg_match('/^(.*?)(-\d+x\d+)?(\.[^.]+)$/i', $basename, $m)) {
            $ext   = strtolower(ltrim($m[3], '.'));
            $size  = isset($m[2]) ? $m[2] : '';
            $token = isset($map[$ext]) ? $map[$ext] : 'file';
            return (int) $attachment_id . '-' . $token . $size . '.' . $ext;
        }
        // 无扩展名兜底：统一标识 + 原 basename
        return (int) $attachment_id . '-image-' . $basename;
    }
}
