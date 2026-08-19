<?php
/**
 * 后台 Ajax（接管匣 TakeBox）
 * 里程碑 5：R2「连接并获取存储桶」——粘贴令牌 → ListBuckets（SigV4 region=auto）
 * → 自动从 endpoint 推导 account id → 返回 bucket 列表供点选。
 */

if (!defined('ABSPATH')) {
    exit;
}

// R2 取桶：用 S3 SigV4（region=auto）调 ListBuckets
if (!function_exists('zibll_takebox_ajax_r2_buckets')) {
    function zibll_takebox_ajax_r2_buckets()
    {
        check_ajax_referer('zibll_takebox_r2', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('权限不足');
        }

        $endpoint = rtrim(sanitize_text_field($_POST['endpoint'] ?? ''), '/');
        $ak       = sanitize_text_field($_POST['access_key'] ?? '');
        $sk       = sanitize_text_field($_POST['secret_key'] ?? '');

        if ('' === $endpoint || '' === $ak || '' === $sk) {
            wp_send_json_error('请先填写完整的 R2 端点、Access Key 与 Secret Key');
        }

        if (!class_exists('Zibll_Takebox_S3_Adapter')) {
            require_once ZIBLL_TAKEBOX_PATH . 'includes/adapters/s3.php';
        }

        // 自动匹配管辖区：除用户填写的端点外，再探测默认 / 欧盟 / fedramp 端点，
        // 合并列出所有桶并标注各自管辖区，前端选桶即自动把 endpoint 设为对应管辖区。
        $account = '';
        if (preg_match('/([a-f0-9]{32})/i', $endpoint, $m)) {
            $account = $m[1];
        }
        $cands = array('user' => $endpoint);
        if ($account) {
            $cands['default'] = 'https://' . $account . '.r2.cloudflarestorage.com';
            $cands['eu']      = 'https://' . $account . '.eu.r2.cloudflarestorage.com';
            $cands['fedramp'] = 'https://' . $account . '.fedramp.r2.cloudflarestorage.com';
        }
        $cands = array_unique(array_values($cands));

        $buckets     = array();
        $seen        = array();
        $account_out = '';
        foreach ($cands as $ep) {
            $opts    = array('__force_r2' => true, 'r2_endpoint' => $ep, 'r2_access_key' => $ak, 'r2_secret_key' => $sk);
            $adapter = new Zibll_Takebox_S3_Adapter($opts);
            $bs      = $adapter->r2_list_buckets();
            if (is_wp_error($bs)) {
                continue;
            }
            if ('' === $account_out) {
                $account_out = $adapter->r2_account_id();
            }
            $jur = (false !== strpos($ep, '.eu.r2')) ? 'eu' : ((false !== strpos($ep, '.fedramp.r2')) ? 'fedramp' : '');
            foreach ($bs as $b) {
                if (!isset($seen[$b])) {
                    $seen[$b] = true;
                    $buckets[] = array('name' => $b, 'jurisdiction' => $jur);
                }
            }
        }

        if (empty($buckets)) {
            wp_send_json_error('未能列出存储桶，请确认端点、Access Key 与 Secret Key 正确（已自动探测默认/欧盟/fedramp 管辖区）。');
        }

        wp_send_json_success(array(
            'account' => $account_out,
            'buckets' => $buckets,
        ));
    }
}
add_action('wp_ajax_zibll_takebox_r2_buckets', 'zibll_takebox_ajax_r2_buckets');

// Cloudflare REST API 助手（Bearer 鉴权），用于一键推导 R2 凭证
if (!function_exists('zibll_takebox_cf_api')) {
    function zibll_takebox_cf_api($path, $token, $method = 'GET', $body = null, $extra_headers = array())
    {
        $resp = wp_remote_request('https://api.cloudflare.com/client/v4' . $path, array(
            'method'  => $method,
            'timeout' => 30,
            'headers' => array_merge(array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ), $extra_headers),
            'body'    => null === $body ? null : json_encode($body),
        ));
        if (is_wp_error($resp)) {
            return $resp;
        }
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($data)) {
            return new WP_Error('cf_api', 'Cloudflare API 响应解析失败');
        }
        if (empty($data['success'])) {
            $msg = 'Cloudflare API 错误';
            if (!empty($data['errors'][0]['message'])) {
                $msg .= '：' . $data['errors'][0]['message'];
                if (!empty($data['errors'][0]['code'])) {
                    $msg .= '（code ' . $data['errors'][0]['code'] . '）';
                }
            }
            return new WP_Error('cf_api', $msg);
        }
        return isset($data['result']) ? $data['result'] : array();
    }
}

// 一键连接：粘贴 Cloudflare API Token → 本地推导 R2 S3 凭证 + 列桶
// 原理（Cloudflare 官方）：Access Key ID = 令牌 Token ID；Secret Access Key = sha256(令牌值)。
// 移植自 HFR2 的 quick_connect 机制。不保存用户原始 API 令牌值，仅保存推导出的 R2 凭据。
if (!function_exists('zibll_takebox_ajax_r2_quick_connect')) {
    function zibll_takebox_ajax_r2_quick_connect()
    {
        check_ajax_referer('zibll_takebox_r2', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('权限不足');
        }

        $token = trim((string) ($_POST['token'] ?? ''));
        if (strlen($token) < 20) {
            wp_send_json_error('请粘贴完整的 API 令牌值（Token Value）');
        }

        // 1. 校验令牌并取得 Token ID（= Access Key ID）
        $token_id = '';
        $verify   = zibll_takebox_cf_api('/user/tokens/verify', $token);
        if (!is_wp_error($verify) && !empty($verify['id'])) {
            $token_id = $verify['id'];
        }

        // 2. 列出令牌可访问的账户
        $accounts     = zibll_takebox_cf_api('/accounts', $token);
        $account_list = (!is_wp_error($accounts) && is_array($accounts)) ? $accounts : array();

        // 账户级令牌兜底：逐一验证取 Token ID
        if ('' === $token_id) {
            foreach ($account_list as $acct) {
                if (empty($acct['id'])) {
                    continue;
                }
                $v = zibll_takebox_cf_api('/accounts/' . rawurlencode($acct['id']) . '/tokens/verify', $token);
                if (!is_wp_error($v) && !empty($v['id'])) {
                    $token_id = $v['id'];
                    break;
                }
            }
        }
        if ('' === $token_id) {
            $hint = is_wp_error($verify) ? $verify->get_error_message() : '';
            wp_send_json_error('令牌校验失败，请确认粘贴的是创建令牌后显示的「令牌值 / Token Value」，且令牌未被删除。' . ($hint ? ' 详情：' . $hint : ''));
        }

        // 3. 找到能访问 R2 的账户并列桶（探测默认 + 欧盟管辖区）
        $account_id = '';
        $buckets    = array();
        foreach ($account_list as $acct) {
            if (empty($acct['id'])) {
                continue;
            }
            $found = false;
            foreach (array('' => array(), 'eu' => array('cf-r2-jurisdiction' => 'eu')) as $jur => $headers) {
                $b = zibll_takebox_cf_api('/accounts/' . rawurlencode($acct['id']) . '/r2/buckets', $token, 'GET', null, $headers);
                if (is_wp_error($b)) {
                    continue;
                }
                $found = true;
                if (!empty($b['buckets']) && is_array($b['buckets'])) {
                    foreach ($b['buckets'] as $bk) {
                        if (!empty($bk['name'])) {
                            $buckets[] = array('name' => $bk['name'], 'jurisdiction' => $jur);
                        }
                    }
                }
            }
            if ($found) {
                $account_id = $acct['id'];
                break;
            }
        }
        if ('' === $account_id && !empty($account_list[0]['id'])) {
            $account_id = $account_list[0]['id'];
        }
        if ('' === $account_id) {
            wp_send_json_error('令牌有效，但无法自动获取 Account ID（令牌可能缺少账户读取权限）。请改用下方的手动方式填写 Account ID 与 R2 凭据。');
        }

        // 4. 本地推导 R2 S3 凭证（Cloudflare 官方机制：Secret = sha256(令牌值)）
        //    注意：不保存用户原始 API 令牌值本身，仅保存推导出的 R2 凭据。
        wp_send_json_success(array(
            'account_id' => $account_id,
            'access_key' => $token_id,
            'secret_key' => hash('sha256', $token),
            'buckets'    => $buckets,
        ));
    }
}
add_action('wp_ajax_zibll_takebox_r2_quick_connect', 'zibll_takebox_ajax_r2_quick_connect');

// 连接测试：上传一个小临时对象，分别用「对外地址（自定义域名或默认端点）」与「真实端点（签名 URL）」
// 访问它，从而区分「域名/CDN/公开读没配好」与「上传/凭据本身有问题」。测试对象会自动删除，不污染桶。
if (!function_exists('zibll_takebox_ajax_connection_test')) {
    function zibll_takebox_ajax_connection_test()
    {
        check_ajax_referer('zibll_takebox_test', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('权限不足');
        }

        // 确保工厂与适配器可用（ajax 上下文按需加载）
        if (!class_exists('Zibll_Takebox_Storage_Adapter')) {
            require_once ZIBLL_TAKEBOX_PATH . 'includes/adapters/class-storage-adapter.php';
        }
        if (!class_exists('Zibll_Takebox_S3_Adapter')) {
            require_once ZIBLL_TAKEBOX_PATH . 'includes/adapters/s3.php';
        }
        if (!class_exists('Zibll_Takebox_OSS_Adapter')) {
            require_once ZIBLL_TAKEBOX_PATH . 'includes/adapters/oss.php';
        }
        if (!function_exists('zibll_takebox_get_adapter')) {
            require_once ZIBLL_TAKEBOX_PATH . 'includes/upload-takeover.php';
        }

        $adapter = zibll_takebox_get_adapter();
        if (!$adapter) {
            wp_send_json_error('未检测到可用的存储配置。请先在「存储配置」中保存 provider / 凭据 / 存储桶（总开关可关，但需有有效凭据），再点测试。');
        }

        // 1) 上传一个小测试对象（内容带唯一标记，便于 GET 后校验）
        $content = 'TakeBox connection test ' . gmdate('Y-m-d H:i:s') . ' ' . uniqid();
        $tmp     = tempnam(sys_get_temp_dir(), 'takebox');
        file_put_contents($tmp, $content);
        $key = $adapter->build_object_key('takebox-connection-test-' . gmdate('YmdHis') . '-' . uniqid() . '.txt');
        $acl = zibll_takebox_get_option('default_acl', 'public-read');
        $acl = in_array($acl, array('public-read', 'private'), true) ? $acl : 'public-read';

        $uploaded = $adapter->upload($tmp, $key, $acl);
        @unlink($tmp);

        $base = array(
            'provider'      => $adapter->provider_label(),
            'bucket'        => $adapter->bucket(),
            'object_key'    => $key,
            'acl'           => $acl,
            'custom_domain' => trim((string) zibll_takebox_get_option('public_domain', ''), " \t\n\r\0\x0B/"),
        );

        if (false === $uploaded) {
            // 部分上传的兜底清理
            $adapter->delete($key);
            wp_send_json_error(array_merge($base, array(
                'step'    => 'upload',
                'message' => '上传测试对象失败。请检查：凭据是否正确、存储桶名是否存在、端点/区域（R2 注意管辖区 EU / fedramp）是否正确、以及账号是否有写入权限。',
            )));
        }

        // 2) 对外 URL（自定义域名→走自定义域名；否则默认端点）可达性
        $public_url = $adapter->public_url($key);
        $pub        = wp_remote_get($public_url, array('timeout' => 30, 'sslverify' => false));
        $pub_code   = is_wp_error($pub) ? 0 : (int) wp_remote_retrieve_response_code($pub);
        $pub_body   = is_wp_error($pub) ? '' : wp_remote_retrieve_body($pub);
        $pub_ok     = ($pub_code >= 200 && $pub_code < 300) && (false !== strpos($pub_body, $content));

        // 3) 真实端点可达性（用签名 URL，私有对象也能验证“对象已存在且端点可达”）
        $endpoint_code = null;
        $endpoint_ok   = null;
        if (method_exists($adapter, 'presigned_url')) {
            $sig = $adapter->presigned_url($key, 300);
            if ($sig) {
                $ep           = wp_remote_get($sig, array('timeout' => 30, 'sslverify' => false));
                $endpoint_code = is_wp_error($ep) ? 0 : (int) wp_remote_retrieve_response_code($ep);
                $endpoint_ok   = ($endpoint_code >= 200 && $endpoint_code < 300);
            }
        }

        // 4) 清理测试对象，避免污染桶
        $adapter->delete($key);

        $result = array_merge($base, array(
            'public_url'         => $public_url,
            'public_reachable'   => $pub_ok,
            'public_code'        => $pub_code,
            'endpoint_reachable' => $endpoint_ok,
            'endpoint_code'      => $endpoint_code,
        ));

        if ($pub_ok) {
            $msg = $base['custom_domain']
                ? '✅ 连接成功：自定义域名可正常访问上传对象（公开读）。接管配置正确。'
                : '✅ 连接成功：默认端点可正常访问上传对象。当前未设置自定义域名，对外直链走厂商默认端点。';
            wp_send_json_success(array_merge($result, array('message' => $msg)));
        }

        if ($endpoint_ok) {
            $msg = $base['custom_domain']
                ? '⚠️ 对象已成功上传到存储桶（真实端点可访问），但「自定义域名」无法访问（HTTP ' . $pub_code . '）。请排查：① DNS / CNAME 是否已指向存储桶；② CDN 是否配置回源到该桶；③ 存储桶 / 对象是否设为公开读（当前 ACL=' . $acl . '）。'
                : '⚠️ 对象已上传且真实端点可访问，但对外 URL 不可达（HTTP ' . $pub_code . '）。请检查 base_url 拼接。';
            wp_send_json_success(array_merge($result, array('message' => $msg, 'warning' => true)));
        }

        $msg = '⚠️ 上传成功，但真实端点也无法访问（签名 URL 返回 HTTP ' . $endpoint_code . '）。请检查端点、区域 / 管辖区与凭据。';
        wp_send_json_success(array_merge($result, array('message' => $msg, 'warning' => true)));
    }
}
add_action('wp_ajax_zibll_takebox_connection_test', 'zibll_takebox_ajax_connection_test');

// 仅在接管匣设置页入队 R2 连接脚本并注入 nonce
if (!function_exists('zibll_takebox_enqueue_admin')) {
    function zibll_takebox_enqueue_admin()
    {
        if (empty($_GET['page']) || ZIBLL_TAKEBOX_MENU_SLUG !== $_GET['page']) {
            return;
        }
        $js = ZIBLL_TAKEBOX_PATH . 'assets/r2-connect.js';
        if (file_exists($js)) {
            wp_enqueue_script(
                'zibll-takebox-r2',
                ZIBLL_TAKEBOX_URL . 'assets/r2-connect.js',
                array('jquery'),
                ZIBLL_TAKEBOX_VERSION,
                true
            );
            wp_localize_script('zibll-takebox-r2', 'zmaR2', array(
                'nonce'   => wp_create_nonce('zibll_takebox_r2'),
                'ajaxurl' => admin_url('admin-ajax.php'),
            ));
        }
        $sync_js = ZIBLL_TAKEBOX_PATH . 'assets/sync.js';
        if (file_exists($sync_js)) {
            wp_enqueue_script(
                'zibll-takebox-sync',
                ZIBLL_TAKEBOX_URL . 'assets/sync.js',
                array('jquery'),
                ZIBLL_TAKEBOX_VERSION,
                true
            );
            wp_localize_script('zibll-takebox-sync', 'zmaSync', array(
                'nonce'   => wp_create_nonce('zibll_takebox_sync'),
                'ajaxurl' => admin_url('admin-ajax.php'),
            ));
        }
        $tools_js = ZIBLL_TAKEBOX_PATH . 'assets/tools.js';
        if (file_exists($tools_js)) {
            wp_enqueue_script(
                'zibll-takebox-tools',
                ZIBLL_TAKEBOX_URL . 'assets/tools.js',
                array('jquery'),
                ZIBLL_TAKEBOX_VERSION,
                true
            );
            wp_localize_script('zibll-takebox-tools', 'zmaTools', array(
                'nonce'   => wp_create_nonce('zibll_takebox_tools'),
                'ajaxurl' => admin_url('admin-ajax.php'),
            ));
        }

        $conn_js = ZIBLL_TAKEBOX_PATH . 'assets/connection-test.js';
        if (file_exists($conn_js)) {
            wp_enqueue_script(
                'zibll-takebox-conn-test',
                ZIBLL_TAKEBOX_URL . 'assets/connection-test.js',
                array('jquery'),
                ZIBLL_TAKEBOX_VERSION,
                true
            );
            wp_localize_script('zibll-takebox-conn-test', 'zmaTest', array(
                'nonce'   => wp_create_nonce('zibll_takebox_test'),
                'ajaxurl' => admin_url('admin-ajax.php'),
            ));
        }
    }
}
add_action('admin_enqueue_scripts', 'zibll_takebox_enqueue_admin');
