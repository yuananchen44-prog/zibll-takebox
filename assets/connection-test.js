/* 接管匣 TakeBox — 连接测试（验证自定义域名 / 端点是否真正可用）
 * 仅在本插件设置页加载。通过 name 选择器兼容 CSF 与原生设置页。 */
(function ($) {
    'use strict';

    function nameSel(key) {
        return '[name="zibll_takebox[' + key + ']"]';
    }

    $(function () {
        var $btn = $('#zma-conn-test');
        if (!$btn.length) {
            return;
        }

        $btn.on('click', function (e) {
            e.preventDefault();
            var $spin = $('#zma-conn-test-spin');
            var $box  = $('#zma-conn-test-result');

            $btn.prop('disabled', true).text('测试中…');
            $spin.css('visibility', 'visible');
            $box.html('');

            $.ajax({
                url: (typeof ajaxurl !== 'undefined') ? ajaxurl : zmaTest.ajaxurl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'zibll_takebox_connection_test',
                    nonce: zmaTest.nonce
                }
            }).done(function (res) {
                if (res && res.success) {
                    var d = res.data;
                    var color = d.warning ? '#b32d2e' : '#1a7f37';
                    var html = '<p style="color:' + color + ';font-weight:600;margin:0 0 6px;">' + d.message + '</p>';
                    html += '<ul style="margin:0;padding-left:18px;font-size:12px;color:#50575e;line-height:1.7;">';
                    html += '<li>服务商：' + (d.provider || '-') + '，存储桶：' + (d.bucket || '-') + '，ACL：' + (d.acl || '-') + '</li>';
                    html += '<li>对外地址：' + (d.public_url || '-') + ' → ' + (d.public_reachable ? '可访问（HTTP ' + d.public_code + '）' : '不可访问（HTTP ' + d.public_code + '）') + '</li>';
                    if (d.custom_domain) {
                        html += '<li>自定义域名：' + d.custom_domain + '</li>';
                    }
                    html += '<li>真实端点（签名 URL）：' + (d.endpoint_reachable ? '可访问（HTTP ' + d.endpoint_code + '）' : '不可访问（HTTP ' + d.endpoint_code + '）') + '</li>';
                    html += '</ul>';
                    $box.html(html);
                } else {
                    var msg = (res && res.data && res.data.message) ? res.data.message : ((res && res.data) ? res.data : '未知错误');
                    $box.html('<p style="color:#b32d2e;font-weight:600;margin:0;">连接测试失败：' + msg + '</p>');
                }
            }).fail(function () {
                $box.html('<p style="color:#b32d2e;font-weight:600;margin:0;">请求失败，请检查网络或刷新后重试。</p>');
            }).always(function () {
                $btn.prop('disabled', false).text('运行连接测试');
                $spin.css('visibility', 'hidden');
            });
        });
    });
})(jQuery);
