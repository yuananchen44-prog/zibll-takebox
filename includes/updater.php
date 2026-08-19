<?php
/**
 * GitHub Releases 在线更新（接管匣 TakeBox）
 * 从 GitHub 仓库的 Releases 检测新版本，注入 WordPress 插件更新流程，
 * 支持后台「立即更新 / 自动更新」。
 *
 * 约定（与 WPStow 一致）：每个可安装版本都应在 Release 里附带一个结构正确的 ZIP
 * （解压后顶层为 zibll-takebox/）。更新器优先取 Release 资产里的 .zip，找不到则回退
 * 到 GitHub 源码 zipball（结构为 owner-repo-ref/，WordPress 安装时可能目录名不符，故仅兜底）。
 */

if (!defined('ABSPATH')) {
    exit;
}

// 默认在线更新仓库（写死默认值，开箱即用；设置页 update_repo 可覆盖，填 0/off 可禁用）
if (!defined('ZIBLL_TAKEBOX_UPDATE_REPO')) {
    define('ZIBLL_TAKEBOX_UPDATE_REPO', 'yuananchen44-prog/zibll-takebox');
}

if (!class_exists('Zibll_Takebox_Updater')) {
    class Zibll_Takebox_Updater
    {
        private $slug;    // 插件目录名 zibll-takebox
        private $basename; // zibll-takebox/zibll-takebox.php
        private $repo;    // owner/repo
        private $cache_key;

        public function __construct()
        {
            $this->slug     = 'zibll-takebox';
            $this->basename = $this->slug . '/' . $this->slug . '.php';

            // 仓库三态：0/off/disabled/none 显式禁用；非空用自定义；留空用默认仓库
            $cfg = trim((string) zibll_takebox_get_option('update_repo', ''), '/');
            if (in_array(strtolower($cfg), array('0', 'off', 'false', 'disabled', 'none'), true)) {
                $this->repo = '';
            } elseif ('' !== $cfg) {
                $this->repo = $cfg;
            } else {
                $this->repo = defined('ZIBLL_TAKEBOX_UPDATE_REPO') ? ZIBLL_TAKEBOX_UPDATE_REPO : '';
            }
            $this->cache_key = 'zibll_takebox_release_' . md5($this->repo);

            if ('' === $this->repo) {
                return;
            }
            add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
            add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        }

        // 获取最新 release（带 12 小时缓存）
        public function latest_release()
        {
            $cached = get_transient($this->cache_key);
            if (false !== $cached && is_array($cached)) {
                return $cached;
            }
            $url  = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
            $resp = wp_remote_get($url, array(
                'headers' => array('User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()),
                'timeout' => 20,
            ));
            if (is_wp_error($resp) || 200 !== (int) wp_remote_retrieve_response_code($resp)) {
                return array();
            }
            $data = json_decode(wp_remote_retrieve_body($resp), true);
            if (!is_array($data) || empty($data['tag_name'])) {
                return array();
            }
            $release = array(
                'version'   => ltrim($data['tag_name'], 'v'),
                'url'       => isset($data['html_url']) ? $data['html_url'] : '',
                'package'   => $this->resolve_package($data),
                'changelog' => isset($data['body']) ? $data['body'] : '',
                'published' => isset($data['published_at']) ? $data['published_at'] : '',
            );
            set_transient($this->cache_key, $release, 12 * HOUR_IN_SECONDS);
            return $release;
        }

        // 优先取 Release 资产中的 .zip，找不到回退 zipball
        private function resolve_package($data)
        {
            if (!empty($data['assets']) && is_array($data['assets'])) {
                foreach ($data['assets'] as $asset) {
                    if (!empty($asset['browser_download_url']) && preg_match('/\.zip$/i', $asset['name'] ?? '')) {
                        return $asset['browser_download_url'];
                    }
                }
            }
            return isset($data['zipball_url']) ? $data['zipball_url'] : '';
        }

        // 检查更新并注入 transient
        public function check_update($transient)
        {
            if (empty($transient->checked)) {
                return $transient;
            }
            $release = $this->latest_release();
            if (empty($release['version']) || empty($release['package'])) {
                return $transient;
            }
            if (version_compare($release['version'], ZIBLL_TAKEBOX_VERSION, '>')) {
                $transient->response[$this->basename] = (object) array(
                    'slug'        => $this->slug,
                    'plugin'      => $this->basename,
                    'new_version' => $release['version'],
                    'url'         => $release['url'],
                    'package'     => $release['package'],
                    'tested'      => '',
                    'requires'    => '5.8',
                    'requires_php'=> '7.4',
                );
            }
            return $transient;
        }

        // 详情弹窗数据
        public function plugin_info($result, $action, $args)
        {
            if ('plugin_information' !== $action) {
                return $result;
            }
            if (!isset($args->slug) || $args->slug !== $this->slug) {
                return $result;
            }
            $release = $this->latest_release();
            if (empty($release)) {
                return $result;
            }
            return (object) array(
                'name'          => '接管匣 TakeBox',
                'slug'          => $this->slug,
                'version'       => $release['version'],
                'author'        => '阿晨',
                'author_profile'=> 'https://navigation.hoarfall.com/about.html',
                'homepage'      => 'https://navigation.hoarfall.com/',
                'requires'      => '5.8',
                'tested'        => '',
                'requires_php'  => '7.4',
                'last_updated'  => $release['published'],
                'download_link' => $release['package'],
                'sections'      => array(
                    'description' => '完整接管 WordPress 媒体库，把上传文件自动同步到对象存储（S3 兼容 / Cloudflare R2 / 阿里云 OSS），并支持自动转 WebP、图片水印、大文件分片上传。',
                    'changelog'   => $release['changelog'],
                ),
            );
        }
    }
}

// 实例化（需在 option 可读之后；plugins_loaded 阶段 zibll_takebox_get_option 已可用）
if (!function_exists('zibll_takebox_init_updater')) {
    function zibll_takebox_init_updater()
    {
        new Zibll_Takebox_Updater();
    }
}
add_action('plugins_loaded', 'zibll_takebox_init_updater', 20);
