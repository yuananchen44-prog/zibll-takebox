/* 接管匣 TakeBox — 双向同步 UI（里程碑 6）
 * 仅在本插件设置页加载。触发后由 WP Cron 后台运行，本脚本仅轮询进度。 */
(function ($) {
    'use strict';

    $(function () {
        var $btn = $('#zma-sync-start');
        if (!$btn.length) {
            return;
        }
        var isRunning = false;

        function poll() {
            $.ajax({
                url: (typeof ajaxurl !== 'undefined') ? ajaxurl : zmaSync.ajaxurl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'zibll_takebox_sync_status',
                    nonce: zmaSync.nonce
                }
            }).done(function (res) {
                if (!res || !res.success || !res.data) {
                    return;
                }
                var j = res.data;
                var total = parseInt(j.total, 10) || 0;
                var processed = parseInt(j.processed, 10) || 0;
                var pct = total ? Math.round(processed / total * 100) : 0;

                if (j.status === 'running' || j.status === 'queued') {
                    isRunning = true;
                    $('#zma-sync-bar').css('width', pct + '%');
                    $('#zma-sync-text').text('进行中：' + processed + ' / ' + total + '（' + j.direction + '）');
                    setTimeout(poll, 2000);
                } else if (j.status === 'done') {
                    isRunning = false;
                    $('#zma-sync-bar').css('width', '100%');
                    var err = (j.errors && j.errors.length) ? '，错误 ' + j.errors.length + ' 项' : '';
                    $('#zma-sync-text').text('已完成：' + processed + ' 项' + err);
                    $btn.prop('disabled', false).text('立即同步');
                } else {
                    isRunning = false;
                    $btn.prop('disabled', false).text('立即同步');
                }
            }).fail(function () {
                isRunning = false;
                $btn.prop('disabled', false).text('立即同步');
            });
        }

        $btn.on('click', function (e) {
            e.preventDefault();
            var dir = $('input[name="zma_sync_dir"]:checked').val() || 'both';
            $btn.prop('disabled', true).text('启动中…');
            $('#zma-sync-text').text('正在启动…');
            $.ajax({
                url: (typeof ajaxurl !== 'undefined') ? ajaxurl : zmaSync.ajaxurl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'zibll_takebox_sync_start',
                    nonce: zmaSync.nonce,
                    direction: dir
                }
            }).done(function (res) {
                if (res && res.success) {
                    isRunning = true;
                    poll();
                } else {
                    var msg = (res && res.data) ? res.data : '未知错误';
                    $('#zma-sync-text').text('启动失败：' + msg);
                    $btn.prop('disabled', false).text('立即同步');
                }
            }).fail(function () {
                $('#zma-sync-text').text('请求失败，请重试。');
                $btn.prop('disabled', false).text('立即同步');
            });
        });

        // 离页保护：同步进行中离开页面先提示
        $(window).on('beforeunload', function () {
            if (isRunning) {
                return '同步仍在后台运行，关闭页面不会中断任务，但将停止查看进度。确定离开？';
            }
        });

        // 进入页面时若已有任务在跑，自动开始轮询
        poll();
    });
})(jQuery);
