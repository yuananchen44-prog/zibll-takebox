/* 接管匣 TakeBox — 工具区（正文改写 + 孤儿清理）交互 */
jQuery(function ($) {
    if (typeof zmaTools === 'undefined') {
        return;
    }
    function spin(id, on) {
        jQuery('#' + id).css('visibility', on ? 'visible' : 'hidden');
    }

    // 正文图片改写：干跑扫描
    function scanContent() {
        spin('zma-crw-spin', true);
        jQuery('#zma-crw-result').text('扫描中…');
        jQuery.post(zmaTools.ajaxurl, {
            action: 'zibll_takebox_content_rewrite',
            nonce: zmaTools.nonce,
            execute: 0
        }, function (r) {
            spin('zma-crw-spin', false);
            if (r.success) {
                var d = r.data;
                jQuery('#zma-crw-result').html('干跑：扫描 ' + d.posts_scanned + ' 篇，需改写 ' + d.posts_changed + ' 篇，共 ' + d.changes + ' 处。');
            } else {
                jQuery('#zma-crw-result').text('失败：' + (r.data || '未知错误'));
            }
        });
    }

    // 正文图片改写：执行替换
    function runContent() {
        if (!confirm('确认执行？执行前会自动备份原文到文章元数据，可回滚。')) {
            return;
        }
        spin('zma-crw-spin', true);
        jQuery('#zma-crw-result').text('替换中…');
        jQuery.post(zmaTools.ajaxurl, {
            action: 'zibll_takebox_content_rewrite',
            nonce: zmaTools.nonce,
            execute: 1
        }, function (r) {
            spin('zma-crw-spin', false);
            if (r.success) {
                var d = r.data;
                jQuery('#zma-crw-result').html('完成：改写 ' + d.posts_changed + ' 篇，共 ' + d.changes + ' 处，原文已备份。');
            } else {
                jQuery('#zma-crw-result').text('失败：' + (r.data || '未知错误'));
            }
        });
    }

    // 孤儿对象：扫描
    function scanOrphan() {
        spin('zma-orphan-spin', true);
        jQuery('#zma-orphan-result').text('扫描中…');
        jQuery.post(zmaTools.ajaxurl, {
            action: 'zibll_takebox_orphan_cleanup',
            nonce: zmaTools.nonce,
            execute: 0
        }, function (r) {
            spin('zma-orphan-spin', false);
            if (r.success) {
                var d = r.data;
                var msg = '发现 ' + d.total + ' 个孤儿对象。';
                if (d.orphans && d.orphans.length) {
                    msg += '<br>示例：' + d.orphans.slice(0, 5).join('<br>');
                }
                jQuery('#zma-orphan-result').html(msg);
            } else {
                jQuery('#zma-orphan-result').text('失败：' + (r.data || '未知错误'));
            }
        });
    }

    // 孤儿对象：执行删除
    function runOrphan() {
        if (!confirm('确认删除这些孤儿对象？此操作不可恢复（对象存储中已无本地引用）。建议先扫描确认。')) {
            return;
        }
        spin('zma-orphan-spin', true);
        jQuery('#zma-orphan-result').text('删除中…');
        jQuery.post(zmaTools.ajaxurl, {
            action: 'zibll_takebox_orphan_cleanup',
            nonce: zmaTools.nonce,
            execute: 1
        }, function (r) {
            spin('zma-orphan-spin', false);
            if (r.success) {
                var d = r.data;
                jQuery('#zma-orphan-result').html('完成：共 ' + d.total + ' 个，已删除 ' + d.deleted + ' 个。');
            } else {
                jQuery('#zma-orphan-result').text('失败：' + (r.data || '未知错误'));
            }
        });
    }

    jQuery('#zma-crw-scan').on('click', scanContent);
    jQuery('#zma-crw-run').on('click', runContent);
    jQuery('#zma-orphan-scan').on('click', scanOrphan);
    jQuery('#zma-orphan-run').on('click', runOrphan);
});
