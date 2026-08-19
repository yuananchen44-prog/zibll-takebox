/* 接管匣 TakeBox — R2 专用连接 UI（里程碑 5，增强：Account ID 一键推导端点）
 * 仅在本插件设置页加载。通过 name 选择器同时兼容 CSF 与原生设置页。 */
(function ($) {
    'use strict';

    function nameSel(key) {
        return '[name="zibll_takebox[' + key + ']"]';
    }

    // 检测用户是否把 Access Key ID 误填到 Account ID 框里
    function isLikelyR2AccessKey(raw) {
        if (!raw) { return false; }
        raw = String(raw).trim();
        return /^cfat[_-]/i.test(raw) || /^cft[_-]/i.test(raw);
    }

    // 从各种输入中智能提取 Cloudflare account id
    function extractAccountId(raw) {
        if (!raw) { return ''; }
        raw = String(raw).trim();
        // 完整 endpoint：https://<id>.r2.cloudflarestorage.com
        var m = raw.match(/https?:\/\/([a-z0-9]+)\.r2\.cloudflarestorage\.com/i);
        if (m) { return m[1]; }
        // R2 控制台 URL：dash.cloudflare.com/<32位hex>/r2...
        var m2 = raw.match(/cloudflare\.com\/([a-f0-9]{32})/i);
        if (m2) { return m2[1]; }
        // 纯 account id（32 位十六进制）
        if (/^[a-f0-9]{32}$/i.test(raw)) { return raw; }
        // 兜底：去掉非字母数字字符后若像 id 则用
        return raw.replace(/[^a-z0-9]/gi, '');
    }

    // 由 Account ID 输入框自动生成 endpoint 并填入 r2_endpoint 字段
    function applyEndpointFromAccount() {
        var raw = $('#zma-r2-account-input').val();
        if (isLikelyR2AccessKey(raw)) { return; } // 防止 Access Key 被拼成 endpoint
        var id = extractAccountId(raw);
        if (id) {
            $(nameSel('r2_endpoint')).val('https://' + id + '.r2.cloudflarestorage.com');
        }
    }

    // 按管辖区返回正确的 R2 端点（自动匹配 EU / fedramp）
    function endpointForJur(accountId, jur) {
        accountId = accountId || '';
        if (jur === 'eu') {
            return 'https://' + accountId + '.eu.r2.cloudflarestorage.com';
        }
        if (jur === 'fedramp') {
            return 'https://' + accountId + '.fedramp.r2.cloudflarestorage.com';
        }
        return 'https://' + accountId + '.r2.cloudflarestorage.com';
    }

    $(function () {
        // 子比 CSF 不支持 password 字段类型，secret_key / access_key 用 text 渲染；运行时改回密码框以遮罩明文
        $('input[name*="secret_key"]').prop('type', 'password');
        $('input[name*="access_key"]').prop('type', 'password');

        var $btn = $('#zma-r2-connect');
        if (!$btn.length) {
            return;
        }

        // Account ID 输入框实时自动补全端点
        $('#zma-r2-account-input').on('input', applyEndpointFromAccount);

        // 方式一：一键连接（粘贴 Cloudflare API Token，本地推导 R2 凭证）
        $('#zma-r2-quick').on('click', function (e) {
            e.preventDefault();
            var token = $('#zma-r2-token').val();
            if (!token || token.length < 20) {
                alert('请粘贴完整的 Cloudflare API 令牌值（Token Value）');
                return;
            }
            var $btn = $(this);
            $btn.prop('disabled', true).text('连接中…');
            $('#zma-r2-qc-spin').css('visibility', 'visible');
            $('#zma-r2-buckets').empty();

            $.ajax({
                url: (typeof ajaxurl !== 'undefined') ? ajaxurl : zmaR2.ajaxurl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'zibll_takebox_r2_quick_connect',
                    nonce: zmaR2.nonce,
                    token: token
                }
            }).done(function (res) {
                if (res && res.success) {
                    var d = res.data;
                    // 自动填入所有 R2 字段
                    $(nameSel('r2_account')).val(d.account_id);
                    $(nameSel('r2_endpoint')).val('https://' + d.account_id + '.r2.cloudflarestorage.com');
                    $(nameSel('r2_access_key')).val(d.access_key);
                    $(nameSel('r2_secret_key')).val(d.secret_key);

                    var $box = $('#zma-r2-buckets').empty();
                    if (d.buckets && d.buckets.length) {
                        var $label = $('<p>').text('已自动填入凭证，选择存储桶（点选后自动匹配管辖区端点并保存）：');
                        var $sel = $('<select class="regular-text">');
                        $.each(d.buckets, function (i, b) {
                            var label = b.name + (b.jurisdiction ? ' (' + b.jurisdiction + ')' : '');
                            $sel.append($('<option>').val(b.name).text(label).attr('data-jur', b.jurisdiction || ''));
                        });
                        $sel.on('change', function () {
                            var $o = $(this).find('option:selected');
                            $(nameSel('r2_bucket')).val($(this).val());
                            $(nameSel('r2_endpoint')).val(endpointForJur(d.account_id, $o.attr('data-jur')));
                        });
                        $box.append($label).append($sel);
                        $(nameSel('r2_bucket')).val(d.buckets[0].name);
                        $(nameSel('r2_endpoint')).val(endpointForJur(d.account_id, d.buckets[0].jurisdiction || ''));
                        $sel.val(d.buckets[0].name);
                    } else {
                        $box.append($('<p class="description">').text('未找到存储桶，请先在 Cloudflare 控制台创建。'));
                    }
                } else {
                    var msg = (res && res.data) ? res.data : '未知错误';
                    $('#zma-r2-buckets').html('<p class="description" style="color:#d63638;">连接失败：' + msg + '</p>');
                }
            }).fail(function () {
                $('#zma-r2-buckets').html('<p class="description" style="color:#d63638;">请求失败，请检查网络。</p>');
            }).always(function () {
                $btn.prop('disabled', false).text('一键连接并获取存储桶');
                $('#zma-r2-qc-spin').css('visibility', 'hidden');
            });
        });

        $btn.on('click', function (e) {
            e.preventDefault();

            // 连接前：若 endpoint 框里其实是 account id 或 R2 控制台 URL，先智能补全
            var epVal = $(nameSel('r2_endpoint')).val();
            var epId = extractAccountId(epVal);
            if (epId && !/^https?:\/\//i.test(epVal)) {
                epVal = 'https://' + epId + '.r2.cloudflarestorage.com';
                $(nameSel('r2_endpoint')).val(epVal);
            }
            // 再同步一次 Account ID 输入框 → endpoint（保证自动推导生效）
            applyEndpointFromAccount();

            // 检测是否把 Access Key ID 误填到 Account ID 框
            var accountRaw = $('#zma-r2-account-input').val();
            if (accountRaw && isLikelyR2AccessKey(accountRaw)) {
                alert('「Account ID」框里填的是 Access Key ID（以 cfat_ 开头），不是 Account ID。\n\n请从 Cloudflare R2 控制台右侧的「Account ID」小卡片复制 32 位字符串，贴到 Account ID 框里；\n然后把 Access Key ID 贴到下方的「Access Key ID」框里。');
                $('#zma-r2-account-input').focus();
                return;
            }

            var endpoint = $(nameSel('r2_endpoint')).val();
            var ak = $(nameSel('r2_access_key')).val();
            var sk = $(nameSel('r2_secret_key')).val();

            var missing = [];
            if (!endpoint) { missing.push('R2 端点'); }
            if (!ak) { missing.push('Access Key ID'); }
            if (!sk) { missing.push('Secret Access Key'); }
            if (missing.length) {
                alert('请先填写：' + missing.join('、') + '，再连接。');
                return;
            }

            $btn.prop('disabled', true).text('连接中…');
            $('#zma-r2-spin').css('visibility', 'visible');
            $('#zma-r2-buckets').empty();

            $.ajax({
                url: (typeof ajaxurl !== 'undefined') ? ajaxurl : zmaR2.ajaxurl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'zibll_takebox_r2_buckets',
                    nonce: zmaR2.nonce,
                    endpoint: endpoint,
                    access_key: ak,
                    secret_key: sk
                }
            }).done(function (res) {
                if (res && res.success) {
                    // 自动填入账号 ID（只读框）
                    $(nameSel('r2_account')).val(res.data.account);

                    var $box = $('#zma-r2-buckets').empty();
                    if (res.data.buckets && res.data.buckets.length) {
                        var $label = $('<p>').text('已匹配到存储桶（已自动探测默认 / 欧盟 / fedramp 管辖区），选择并自动套用对应端点（点选后保存）：');
                        var $sel = $('<select class="regular-text">');
                        $.each(res.data.buckets, function (i, b) {
                            var label = b.name + (b.jurisdiction ? ' (' + b.jurisdiction + ')' : '');
                            $sel.append($('<option>').val(b.name).text(label).attr('data-jur', b.jurisdiction || ''));
                        });
                        $sel.on('change', function () {
                            var $o = $(this).find('option:selected');
                            $(nameSel('r2_bucket')).val($(this).val());
                            $(nameSel('r2_endpoint')).val(endpointForJur(res.data.account, $o.attr('data-jur')));
                        });
                        $box.append($label).append($sel);
                        // 默认选中第一个并套用其管辖区端点
                        $(nameSel('r2_bucket')).val(res.data.buckets[0].name);
                        $(nameSel('r2_endpoint')).val(endpointForJur(res.data.account, res.data.buckets[0].jurisdiction || ''));
                        $sel.val(res.data.buckets[0].name);
                    } else {
                        $box.append($('<p class="description">').text('未找到存储桶，请先在 Cloudflare 控制台创建。'));
                    }
                } else {
                    var msg = (res && res.data) ? res.data : '未知错误';
                    $('#zma-r2-buckets').html('<p class="description" style="color:#d63638;">连接失败：' + msg + '</p>');
                }
            }).fail(function () {
                $('#zma-r2-buckets').html('<p class="description" style="color:#d63638;">请求失败，请检查网络或端点格式。</p>');
            }).always(function () {
                $btn.prop('disabled', false).text('连接并获取存储桶');
                $('#zma-r2-spin').css('visibility', 'hidden');
            });
        });
    });
})(jQuery);
