<?php
/**
 * 阿里云 OSS 适配器（接管匣 TakeBox）
 * 使用阿里云自有签名算法 V1（HMAC-SHA1 + Date + CanonicalizedResource），
 * 与 AWS SigV4 完全不同，不能复用 S3 适配器，故单独实现。
 * 采用虚拟主机风格地址：https://{bucket}.{endpoint}/{key}
 * 使用 WordPress 原生 HTTP API（wp_remote_request），不依赖外部 SDK。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Zibll_Takebox_OSS_Adapter')) {
    class Zibll_Takebox_OSS_Adapter extends Zibll_Takebox_Storage_Adapter
    {
        protected $endpoint;
        protected $region;
        protected $bucket;
        protected $access_key;
        protected $secret_key;

        public function __construct($opts = array())
        {
            parent::__construct($opts);
            // endpoint 形如 oss-cn-hangzhou.aliyuncs.com，去掉协议前缀与尾部斜杠
            $ep        = trim((string) ($opts['oss_endpoint'] ?? ''), " \t\n\r\0\x0B/");
            $ep        = preg_replace('#^https?://#i', '', $ep);
            $this->endpoint   = $ep;
            $this->bucket     = $opts['oss_bucket'] ?? '';
            $this->access_key = $opts['oss_access_key'] ?? '';
            $this->secret_key = $opts['oss_secret_key'] ?? '';
            $this->region     = empty($opts['oss_region']) ? 'oss-cn-hangzhou' : $opts['oss_region'];
        }

        public function build_object_key($basename, $attachment_id = 0)
        {
            return $this->apply_path_rules($basename, $attachment_id);
        }

        public function provider_label()
        {
            return 'Aliyun OSS';
        }
        public function region()
        {
            return $this->region;
        }
        public function bucket()
        {
            return $this->bucket;
        }

        // 虚拟主机风格：https://{bucket}.{endpoint}
        public function base_url()
        {
            return 'https://' . $this->bucket . '.' . $this->endpoint;
        }

        public function upload($local_path, $object_key, $acl = 'public-read')
        {
            if ($this->should_multipart($local_path)) {
                return $this->multipart_upload($local_path, $object_key, $acl);
            }
            $body = @file_get_contents($local_path);
            if (false === $body) {
                return false;
            }
            $extra = array(
                'Content-Type'      => $this->guess_mime($local_path),
                'x-oss-object-acl'  => ('private' === $acl) ? 'private' : 'public-read',
            );
            $headers = $this->sign('PUT', $object_key, $body, $extra);
            $url     = $this->object_url($object_key);
            $resp    = wp_remote_request($url, array(
                'method'  => 'PUT',
                'headers' => $headers,
                'body'    => $body,
                'timeout' => 120,
            ));
            if (is_wp_error($resp)) {
                return false;
            }
            $code = (int) wp_remote_retrieve_response_code($resp);
            // 落库/展示用公开地址：填了自定义域名则走自定义域名，否则回退真实端点
            return ($code >= 200 && $code < 300) ? $this->public_url($object_key) : false;
        }

        public function delete($object_key)
        {
            $headers = $this->sign('DELETE', $object_key, '', array());
            $url     = $this->object_url($object_key);
            $resp    = wp_remote_request($url, array(
                'method'  => 'DELETE',
                'headers' => $headers,
                'timeout' => 60,
            ));
            return !is_wp_error($resp);
        }

        // ===== Multipart 分片上传（大文件，OSS V1 签名）=====
        // 流程：POST ?uploads → 逐片 PUT ?partNumber=N&uploadId=X → POST ?uploadId=X (Complete)
        public function multipart_upload($local_path, $object_key, $acl = 'public-read')
        {
            $size      = @filesize($local_path);
            $part_size = $this->multipart_part_size();
            if (false === $size || $size <= 0) {
                return false;
            }
            $acl_header = ('private' === $acl) ? 'private' : 'public-read';

            // 1) 初始化分片上传
            $url     = $this->object_url($object_key) . '?uploads';
            $extra   = array(
                'Content-Type'     => $this->guess_mime($local_path),
                'x-oss-object-acl' => $acl_header,
            );
            $headers = $this->sign('POST', $object_key, '', $extra, 'uploads');
            $resp    = wp_remote_request($url, array(
                'method'  => 'POST',
                'headers' => $headers,
                'body'    => '',
                'timeout' => 60,
            ));
            $upload_id = $this->parse_xml_tag(wp_remote_retrieve_body($resp), 'UploadId');
            if (!$upload_id) {
                return false;
            }

            // 2) 逐片上传
            $parts = array();
            $fh    = @fopen($local_path, 'rb');
            if (!$fh) {
                return false;
            }
            $part_number = 1;
            $failed      = false;
            while (!feof($fh)) {
                $chunk = fread($fh, $part_size);
                if (false === $chunk || '' === $chunk) {
                    break;
                }
                $query   = 'partNumber=' . $part_number . '&uploadId=' . rawurlencode($upload_id);
                $purl    = $this->object_url($object_key) . '?' . $query;
                $headers = $this->sign('PUT', $object_key, $chunk, array(), $query);
                $presp   = wp_remote_request($purl, array(
                    'method'  => 'PUT',
                    'headers' => $headers,
                    'body'    => $chunk,
                    'timeout' => 180,
                ));
                if (is_wp_error($presp) || (int) wp_remote_retrieve_response_code($presp) >= 300) {
                    $failed = true;
                    break;
                }
                $etag = trim((string) wp_remote_retrieve_header($presp, 'etag'), "\"\r\n ");
                $parts[] = array('PartNumber' => $part_number, 'ETag' => $etag);
                $part_number++;
            }
            fclose($fh);

            if ($failed || empty($parts)) {
                $this->abort_multipart($object_key, $upload_id);
                return false;
            }

            // 3) 完成分片上传
            $xml  = '<CompleteMultipartUpload>';
            foreach ($parts as $p) {
                $xml .= '<Part><PartNumber>' . (int) $p['PartNumber'] . '</PartNumber><ETag>' . htmlspecialchars($p['ETag'], ENT_XML1) . '</ETag></Part>';
            }
            $xml .= '</CompleteMultipartUpload>';
            $query   = 'uploadId=' . rawurlencode($upload_id);
            $curl    = $this->object_url($object_key) . '?' . $query;
            $headers = $this->sign('POST', $object_key, $xml, array('Content-Type' => 'application/xml'), $query);
            $cresp   = wp_remote_request($curl, array(
                'method'  => 'POST',
                'headers' => $headers,
                'body'    => $xml,
                'timeout' => 120,
            ));
            $code = (int) wp_remote_retrieve_response_code($cresp);
            return ($code >= 200 && $code < 300) ? $this->public_url($object_key) : false;
        }

        // 中止未完成的分片上传（失败清理）
        public function abort_multipart($object_key, $upload_id)
        {
            $query   = 'uploadId=' . rawurlencode($upload_id);
            $url     = $this->object_url($object_key) . '?' . $query;
            $headers = $this->sign('DELETE', $object_key, '', array(), $query);
            wp_remote_request($url, array('method' => 'DELETE', 'headers' => $headers, 'timeout' => 60));
        }

        // 从 XML 响应里取指定标签文本
        protected function parse_xml_tag($xml, $tag)
        {
            if (function_exists('simplexml_load_string')) {
                $s = @simplexml_load_string($xml);
                if ($s && isset($s->{$tag})) {
                    return (string) $s->{$tag};
                }
            }
            if (preg_match('#<' . preg_quote($tag, '#') . '>([^<]*)</' . preg_quote($tag, '#') . '>#i', $xml, $m)) {
                return $m[1];
            }
            return '';
        }

        // 修改已存在对象的 ACL（OSS：PUT ?acl + x-oss-object-acl 头）
        public function set_object_acl($object_key, $acl)
        {
            $acl = ('private' === $acl) ? 'private' : 'public-read';
            $url  = $this->object_url($object_key) . '?acl';
            $date = gmdate('D, d M Y H:i:s \G\M\T');
            // CanonicalizedResource 必须包含子资源 ?acl
            $resource = '/' . $this->bucket . '/' . ltrim($object_key, '/') . '?acl';
            $string_to_sign = 'PUT' . "\n" . '' . "\n" . '' . "\n" . $date . "\n"
                . 'x-oss-object-acl:' . $acl . "\n" . $resource;
            $signature = base64_encode(hash_hmac('sha1', $string_to_sign, $this->secret_key, true));
            $headers = array(
                'Authorization'    => 'OSS ' . $this->access_key . ':' . $signature,
                'Date'             => $date,
                'x-oss-object-acl' => $acl,
            );
            $resp = wp_remote_request($url, array('method' => 'PUT', 'headers' => $headers, 'timeout' => 30));
            return !is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) < 400;
        }

        // GET Bucket 列举对象，供同步引擎（里程碑 6）使用
        public function list_objects($prefix = '')
        {
            $url    = $this->base_url() . '/?prefix=' . rawurlencode($prefix) . '&max-keys=1000';
            $headers = $this->sign('GET', '', '', array());
            $resp   = wp_remote_request($url, array(
                'method'  => 'GET',
                'headers' => $headers,
                'timeout' => 60,
            ));
            if (is_wp_error($resp)) {
                return array();
            }
            $body = wp_remote_retrieve_body($resp);
            $keys = array();
            if (function_exists('simplexml_load_string')) {
                $xml = @simplexml_load_string($body);
                if ($xml && isset($xml->Contents)) {
                    foreach ($xml->Contents as $c) {
                        if (isset($c->Key)) {
                            $keys[] = (string) $c->Key;
                        }
                    }
                }
            }
            return $keys;
        }

        // 阿里云 OSS 签名 URL（私有读）：GET ?OSSAccessKeyId&Expires&Signature
        public function presigned_url($object_key, $ttl = 3600)
        {
            $expires  = time() + (int) $ttl;
            // 资源需与 object_url() 实际请求路径一致（已编码），故对 key 做 encode_key，否则中文 key 签名不符
            $resource = '/' . $this->bucket . '/' . $this->encode_key(ltrim($object_key, '/'));
            // 签名串：GET\n<Content-MD5>\n<Content-Type>\n<Expires>\n<CanonicalizedOSSHeaders><CanonicalizedResource>
            $string_to_sign = 'GET' . "\n" . '' . "\n" . '' . "\n" . $expires . "\n" . '' . $resource;
            $signature = base64_encode(hash_hmac('sha1', $string_to_sign, $this->secret_key, true));
            $url   = $this->object_url($object_key);
            $query = array(
                'OSSAccessKeyId' => $this->access_key,
                'Expires'        => (string) $expires,
                'Signature'      => $signature,
            );
            return $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        // ===== 阿里云 OSS 签名 V1 内部实现 =====
        protected function object_url($object_key)
        {
            return $this->base_url() . '/' . $this->encode_key($object_key);
        }

        protected function encode_key($key)
        {
            $parts = explode('/', $key);
            $parts = array_map('rawurlencode', $parts);
            return implode('/', $parts);
        }

        /**
         * OSS 签名 V1
         * StringToSign = VERB + "\n" + Content-MD5 + "\n" + Content-Type + "\n" + Date + "\n"
         *                + CanonicalizedOSSHeaders + CanonicalizedResource
         * Signature = base64(HMAC-SHA1(AccessKeySecret, StringToSign))
         * Authorization: OSS AccessKeyId:Signature
         */
        protected function sign($method, $object_key, $body, $extra_headers, $query = '')
        {
            $content_md5 = '';
            if ('' !== $body && null !== $body) {
                $content_md5 = base64_encode(md5($body, true));
            }
            $content_type = isset($extra_headers['Content-Type']) ? $extra_headers['Content-Type'] : '';
            $date         = gmdate('D, d M Y H:i:s \G\M\T');

            // CanonicalizedOSSHeaders：所有 x-oss- 开头头，小写键，按字典序，key:value\n
            $oss_headers = array();
            foreach ($extra_headers as $k => $v) {
                if (0 === strpos(strtolower($k), 'x-oss-')) {
                    $oss_headers[strtolower($k)] = trim((string) $v);
                }
            }
            ksort($oss_headers);
            $canonical_oss = '';
            foreach ($oss_headers as $k => $v) {
                $canonical_oss .= $k . ':' . $v . "\n";
            }

            // 资源需与 object_url() 实际请求路径一致（已编码），故对 key 做 encode_key，否则中文 key 签名不符
            $resource = '/' . $this->bucket . '/' . $this->encode_key(ltrim($object_key, '/'));
            // Multipart 等请求的 sub-resource 必须纳入 CanonicalizedResource（如 ?uploads、?partNumber=N&uploadId=X）
            if ('' !== $query) {
                $resource .= '?' . $query;
            }
            $string_to_sign = $method . "\n" . $content_md5 . "\n" . $content_type . "\n" . $date . "\n" . $canonical_oss . $resource;
            $signature = base64_encode(hash_hmac('sha1', $string_to_sign, $this->secret_key, true));

            $headers = array(
                'Authorization' => 'OSS ' . $this->access_key . ':' . $signature,
                'Date'          => $date,
            );
            if ('' !== $content_md5) {
                $headers['Content-MD5'] = $content_md5;
            }
            if ('' !== $content_type) {
                $headers['Content-Type'] = $content_type;
            }
            // 回写 x-oss- 头到请求
            foreach ($oss_headers as $k => $v) {
                // 还原原始大小写头名（x-oss-object-acl）
                foreach ($extra_headers as $ok => $ov) {
                    if (strtolower($ok) === $k) {
                        $headers[$ok] = $ov;
                        break;
                    }
                }
            }
            return $headers;
        }

        protected function guess_mime($path)
        {
            if (function_exists('mime_content_type')) {
                $m = @mime_content_type($path);
                if ($m) {
                    return $m;
                }
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $map = array(
                'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
                'mp4' => 'video/mp4', 'mp3' => 'audio/mpeg', 'svg' => 'image/svg+xml',
            );
            return isset($map[$ext]) ? $map[$ext] : 'application/octet-stream';
        }
    }
}
