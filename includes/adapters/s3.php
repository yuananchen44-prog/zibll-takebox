<?php
/**
 * S3 兼容 / Cloudflare R2 适配器（接管匣 TakeBox）
 * R2 与 S3 共用 AWS Signature V4 签名，仅 region 固定为 auto、端点不同，故同一适配器复用。
 * 使用 WordPress 原生 HTTP API（wp_remote_request），不依赖外部 SDK。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Zibll_Takebox_S3_Adapter')) {
    class Zibll_Takebox_S3_Adapter extends Zibll_Takebox_Storage_Adapter
    {
        protected $endpoint;
        protected $region;
        protected $bucket;
        protected $access_key;
        protected $secret_key;
        protected $is_r2;

        public function __construct($opts = array())
        {
            parent::__construct($opts);
            // 运行时按全局 provider 判定；ajax 测试时用 __force_r2 显式切到 r2 凭据
            $this->is_r2 = ('r2' === zibll_takebox_provider()) || !empty($opts['__force_r2']);
            if ($this->is_r2) {
                $this->endpoint   = rtrim($opts['r2_endpoint'] ?? '', '/');
                $this->bucket     = $opts['r2_bucket'] ?? '';
                $this->access_key = $opts['r2_access_key'] ?? '';
                $this->secret_key = $opts['r2_secret_key'] ?? '';
                $this->region     = 'auto';
            } else {
                $this->endpoint   = rtrim($opts['s3_endpoint'] ?? '', '/');
                $this->bucket     = $opts['s3_bucket'] ?? '';
                $this->access_key = $opts['s3_access_key'] ?? '';
                $this->secret_key = $opts['s3_secret_key'] ?? '';
                $this->region     = empty($opts['s3_region']) ? 'auto' : $opts['s3_region'];
            }
        }

        public function build_object_key($basename, $attachment_id = 0)
        {
            return $this->apply_path_rules($basename, $attachment_id);
        }

        public function provider_label()
        {
            return $this->is_r2 ? 'Cloudflare R2' : 'S3';
        }
        public function region()
        {
            return $this->region;
        }
        public function bucket()
        {
            return $this->bucket;
        }

        public function base_url()
        {
            if ($this->is_r2) {
                return $this->endpoint . '/' . $this->bucket;
            }
            // S3：endpoint 通常已是服务根，拼接 bucket 形成 path-style
            return $this->endpoint . '/' . $this->bucket;
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
            $url     = $this->object_url($object_key);
            $headers = array(
                'Content-Type' => $this->guess_mime($local_path),
                'x-amz-acl'    => $acl,
            );
            $signed = $this->sign('PUT', $url, $headers, $body);
            $resp   = wp_remote_request($url, array(
                'method'  => 'PUT',
                'headers' => $signed,
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
            $url    = $this->object_url($object_key);
            $signed = $this->sign('DELETE', $url, array(), '');
            $resp   = wp_remote_request($url, array(
                'method'  => 'DELETE',
                'headers' => $signed,
                'timeout' => 60,
            ));
            return !is_wp_error($resp);
        }

        // ===== Multipart 分片上传（大文件）=====
        // 流程：POST ?uploads → 逐片 PUT ?partNumber=N&uploadId=X → POST ?uploadId=X (Complete)
        public function multipart_upload($local_path, $object_key, $acl = 'public-read')
        {
            $size      = @filesize($local_path);
            $part_size = $this->multipart_part_size();
            if (false === $size || $size <= 0) {
                return false;
            }

            // 1) 初始化分片上传
            $url     = $this->object_url($object_key) . '?uploads';
            $headers = array(
                'Content-Type' => $this->guess_mime($local_path),
                'x-amz-acl'    => $acl,
            );
            $signed = $this->sign('POST', $url, $headers, '');
            $resp   = wp_remote_request($url, array(
                'method'  => 'POST',
                'headers' => $signed,
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
                $purl   = $this->object_url($object_key) . '?partNumber=' . $part_number . '&uploadId=' . rawurlencode($upload_id);
                $signed = $this->sign('PUT', $purl, array(), $chunk);
                $presp  = wp_remote_request($purl, array(
                    'method'  => 'PUT',
                    'headers' => $signed,
                    'body'    => $chunk,
                    'timeout' => 180,
                ));
                if (is_wp_error($presp) || (int) wp_remote_retrieve_response_code($presp) >= 300) {
                    $failed = true;
                    break;
                }
                $etag = trim((string) wp_remote_retrieve_header($presp, 'etag'), "\"\r\n ");
                if ('' === $etag) {
                    // 部分兼容实现把 ETag 放响应体，兜底读一次
                    $etag = $this->parse_xml_tag(wp_remote_retrieve_body($presp), 'ETag');
                    $etag = trim($etag, "\"\r\n ");
                }
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
            $curl   = $this->object_url($object_key) . '?uploadId=' . rawurlencode($upload_id);
            $signed = $this->sign('POST', $curl, array('Content-Type' => 'application/xml'), $xml);
            $cresp  = wp_remote_request($curl, array(
                'method'  => 'POST',
                'headers' => $signed,
                'body'    => $xml,
                'timeout' => 120,
            ));
            $code = (int) wp_remote_retrieve_response_code($cresp);
            return ($code >= 200 && $code < 300) ? $this->public_url($object_key) : false;
        }

        // 中止未完成的分片上传（失败清理，避免桶里残留碎片）
        public function abort_multipart($object_key, $upload_id)
        {
            $url    = $this->object_url($object_key) . '?uploadId=' . rawurlencode($upload_id);
            $signed = $this->sign('DELETE', $url, array(), '');
            wp_remote_request($url, array('method' => 'DELETE', 'headers' => $signed, 'timeout' => 60));
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

        // 修改已存在对象的 ACL（SigV4：PUT ?acl + x-amz-acl 头）
        public function set_object_acl($object_key, $acl)
        {
            $acl = ('private' === $acl) ? 'private' : 'public-read';
            $url    = $this->object_url($object_key) . '?acl';
            $body   = '';
            $amzdate   = gmdate('Ymd\THis\Z');
            $datestamp = gmdate('Ymd');
            $parsed    = parse_url($url);
            $host      = $parsed['host'];
            // 注意：$url 已由 object_url() 编码，此处直接取 path，不可再 encode_key
            $enc_key   = ltrim($parsed['path'] ?? '', '/');
            $canonical_uri = ('' === $enc_key) ? '/' : ('/' . $enc_key);
            $payload_hash  = hash('sha256', $body);
            $ch = array('host' => $host, 'x-amz-acl' => $acl, 'x-amz-date' => $amzdate);
            ksort($ch);
            $canonical_headers = '';
            $signed = array();
            foreach ($ch as $k => $v) {
                $canonical_headers .= $k . ':' . trim($v) . "\n";
                $signed[] = $k;
            }
            $signed_str = implode(';', $signed);
            $canonical_query = 'acl='; // 子资源必须以 canonical query 形式参与签名
            $canonical_request = "PUT\n{$canonical_uri}\n{$canonical_query}\n{$canonical_headers}\n{$signed_str}\n{$payload_hash}";
            $scope = $datestamp . '/' . $this->region . '/s3/aws4_request';
            $string_to_sign = "AWS4-HMAC-SHA256\n{$amzdate}\n{$scope}\n" . hash('sha256', $canonical_request);
            $kdate    = hash_hmac('sha256', $datestamp, 'AWS4' . $this->secret_key, true);
            $kregion  = hash_hmac('sha256', $this->region, $kdate, true);
            $kservice = hash_hmac('sha256', 's3', $kregion, true);
            $ksigning = hash_hmac('sha256', 'aws4_request', $kservice, true);
            $signature = hash_hmac('sha256', $string_to_sign, $ksigning);
            $credential = $this->access_key . '/' . $scope;
            $headers = array(
                'Authorization' => 'AWS4-HMAC-SHA256 Credential=' . $credential . ', SignedHeaders=' . $signed_str . ', Signature=' . $signature,
                'X-Amz-Date'    => $amzdate,
                'X-Amz-Acl'     => $acl,
                'host'          => $host,
            );
            $resp = wp_remote_request($url, array('method' => 'PUT', 'headers' => $headers, 'timeout' => 30));
            return !is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) < 400;
        }

        public function list_objects($prefix = '')
        {
            $url  = $this->object_url('') . '?list-type=2&prefix=' . rawurlencode($prefix) . '&max-keys=1000';
            $signed = $this->sign('GET', $url, array(), '');
            $resp = wp_remote_request($url, array(
                'method'  => 'GET',
                'headers' => $signed,
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

        // ===== R2 专用：ListBuckets + 自动推导 account（里程碑 5）=====
        public function r2_list_buckets()
        {
            $url    = rtrim($this->endpoint, '/') . '/';
            $signed = $this->sign('GET', $url, array(), '');
            $resp   = wp_remote_request($url, array(
                'method'  => 'GET',
                'headers' => $signed,
                'timeout' => 30,
            ));
            if (is_wp_error($resp)) {
                return new WP_Error('request', $resp->get_error_message());
            }
            $code = (int) wp_remote_retrieve_response_code($resp);
            if (200 !== $code) {
                // 尝试读取 OSS/R2 返回的错误信息
                $msg = 'HTTP ' . $code;
                $body = wp_remote_retrieve_body($resp);
                if (preg_match('#<Message>([^<]+)</Message>#', $body, $m)) {
                    $msg .= '：' . $m[1];
                }
                return new WP_Error('code', $msg);
            }
            $body    = wp_remote_retrieve_body($resp);
            $buckets = array();
            if (function_exists('simplexml_load_string')) {
                $xml = @simplexml_load_string($body);
                if ($xml && isset($xml->Buckets->Bucket)) {
                    foreach ($xml->Buckets->Bucket as $b) {
                        if (isset($b->Name)) {
                            $buckets[] = (string) $b->Name;
                        }
                    }
                }
            }
            return $buckets;
        }

        public function r2_account_id()
        {
            $host = parse_url($this->endpoint, PHP_URL_HOST);
            if (!$host) {
                return '';
            }
            $parts = explode('.', $host);
            return $parts[0] ?? '';
        }

        public function presigned_url($object_key, $ttl = 3600)
        {
            $url       = $this->object_url($object_key);
            $parsed    = parse_url($url);
            $host      = $parsed['host'];
            // 注意：传入的 $url 已由 object_url() 做过 RFC3986 编码，此处不可再 encode_key（二次编码会让中文 key 签名不符）
            $canonical = $parsed['path'] ?? '/';
            if ('' === $canonical) {
                $canonical = '/'; // ListBuckets 等根路径请求：canonical URI 必须是 '/'
            } elseif (strpos($canonical, '/') !== 0) {
                $canonical = '/' . $canonical;
            }
            $amzdate   = gmdate('Ymd\THis\Z');
            $datestamp = gmdate('Ymd');
            $credential = $this->access_key . '/' . $datestamp . '/' . $this->region . '/s3/aws4_request';
            $query = array(
                'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
                'X-Amz-Credential'    => $credential,
                'X-Amz-Date'          => $amzdate,
                'X-Amz-Expires'       => (string) (int) $ttl,
                'X-Amz-SignedHeaders' => 'host',
            );
            ksort($query);
            $canonical_querystring = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $payload_hash = 'UNSIGNED-PAYLOAD';
            $canonical_headers = 'host:' . $host . "\n";
            $canonical_request = "GET\n{$canonical}\n{$canonical_querystring}\n{$canonical_headers}\nhost\n{$payload_hash}";
            $string_to_sign = $this->string_to_sign($amzdate, $datestamp, $canonical_request);
            $signature = $this->signature($datestamp, $string_to_sign);
            return $url . '?' . $canonical_querystring . '&X-Amz-Signature=' . $signature;
        }

        // ===== AWS Signature V4 内部实现 =====
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

        protected function sign($method, $url, $headers, $body)
        {
            $amzdate   = gmdate('Ymd\THis\Z');
            $datestamp = gmdate('Ymd');
            $parsed    = parse_url($url);
            $host      = isset($parsed['host']) ? $parsed['host'] : '';
            // 注意：传入的 $url 已由 object_url() 做过 RFC3986 编码，此处不可再 encode_key（否则中文 key 的 % 会被二次编码导致签名不符）
            $canonical = $parsed['path'] ?? '/';
            if ('' === $canonical) {
                $canonical = '/'; // ListBuckets 等根路径请求：canonical URI 必须是 '/'
            } elseif (strpos($canonical, '/') !== 0) {
                $canonical = '/' . $canonical;
            }
            // AWS SigV4：查询串必须纳入规范请求。否则带 query 的请求（如 ListObjects 的
            // ?list-type=2&prefix=..&max-keys=..）会与服务端算出的签名不符 → 403 SignatureDoesNotMatch。
            // 无 query 的请求（upload/delete）保持空，行为不变。
            $canonical_query = '';
            if (!empty($parsed['query'])) {
                parse_str($parsed['query'], $qtmp);
                $qparams = array();
                foreach ($qtmp as $k => $v) {
                    $qparams[rawurlencode($k)] = rawurlencode($v);
                }
                ksort($qparams);
                $qparts = array();
                foreach ($qparams as $k => $v) {
                    $qparts[] = $k . '=' . $v;
                }
                $canonical_query = implode('&', $qparts);
            }
            $payload_hash = hash('sha256', $body);

            $ch = array();
            $ch['host'] = $host;
            // AWS SigV4 / R2 要求所有请求都带 x-amz-content-sha256（含 GET/HEAD 空 body）
            $ch['x-amz-content-sha256'] = $payload_hash;
            $ch['x-amz-date'] = $amzdate;
            if (isset($headers['x-amz-acl'])) {
                $ch['x-amz-acl'] = $headers['x-amz-acl'];
            }
            ksort($ch);
            $canonical_headers = '';
            $signed = array();
            foreach ($ch as $k => $v) {
                $canonical_headers .= $k . ':' . trim($v) . "\n";
                $signed[] = $k;
            }
            $signed_str = implode(';', $signed);
            $canonical_request = $method . "\n" . $canonical . "\n" . $canonical_query . "\n" . $canonical_headers . "\n" . $signed_str . "\n" . $payload_hash;

            $string_to_sign = $this->string_to_sign($amzdate, $datestamp, $canonical_request);
            $signature = $this->signature($datestamp, $string_to_sign);
            $credential = $this->access_key . '/' . $datestamp . '/' . $this->region . '/s3/aws4_request';
            $authorization = 'AWS4-HMAC-SHA256 Credential=' . $credential . ', SignedHeaders=' . $signed_str . ', Signature=' . $signature;

            $out = array(
                'Authorization' => $authorization,
                'X-Amz-Date'    => $amzdate,
            );
            if (isset($headers['Content-Type'])) {
                $out['Content-Type'] = $headers['Content-Type'];
            }
            if (isset($ch['x-amz-content-sha256'])) {
                $out['X-Amz-Content-Sha256'] = $ch['x-amz-content-sha256'];
            }
            if (isset($headers['x-amz-acl'])) {
                $out['X-Amz-Acl'] = $headers['x-amz-acl'];
            }
            return $out;
        }

        protected function string_to_sign($amzdate, $datestamp, $canonical_request)
        {
            $scope = $datestamp . '/' . $this->region . '/s3/aws4_request';
            return "AWS4-HMAC-SHA256\n{$amzdate}\n{$scope}\n" . hash('sha256', $canonical_request);
        }

        protected function signature($datestamp, $string_to_sign)
        {
            $kdate    = hash_hmac('sha256', $datestamp, 'AWS4' . $this->secret_key, true);
            $kregion  = hash_hmac('sha256', $this->region, $kdate, true);
            $kservice = hash_hmac('sha256', 's3', $kregion, true);
            $ksigning = hash_hmac('sha256', 'aws4_request', $kservice, true);
            return hash_hmac('sha256', $string_to_sign, $ksigning);
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
