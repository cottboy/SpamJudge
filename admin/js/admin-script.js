/**
 * SpamJudge 管理后台脚本
 *
 * @package SpamJudge
 */

(function($) {
    'use strict';
    
    /**
     * 文档加载完成后执行
     */
    $(document).ready(function() {
        
        /**
         * 清空日志按钮点击事件
         */
        $('#clear-logs-btn').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);

            // 禁用按钮，防止重复点击
            $button.prop('disabled', true).text(SpamJudge.strings.processing);

            // 发送 AJAX 请求清空日志
            $.ajax({
                url: SpamJudge.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'spamjudge_clear_logs',
                    nonce: SpamJudge.nonce
                },
                success: function(response) {
                    // 无论成功或失败，都直接刷新页面
                    location.reload();
                },
                error: function() {
                    // 发生错误时也刷新页面
                    location.reload();
                }
            });
        });
        
        /**
         * 表单验证
         */
        $('form').on('submit', function(e) {
            var isValid = true;
            var errorMessages = [];

            // 验证系统提示词
            var systemPrompt = $('#system_prompt').val().trim();
            if (!systemPrompt) {
                isValid = false;
                errorMessages.push(SpamJudge.strings.systemPromptEmpty);
            }

            // 验证分数阈值
            var threshold = parseInt($('#score_threshold').val());
            if (isNaN(threshold) || threshold < 0 || threshold > 100) {
                isValid = false;
                errorMessages.push(SpamJudge.strings.thresholdInvalid);
            }

            // 验证超时时间
            var timeout = parseInt($('#timeout').val());
            if (isNaN(timeout) || timeout < 5) {
                isValid = false;
                errorMessages.push(SpamJudge.strings.timeoutInvalid);
            }

            // 如果验证失败，显示错误消息
            if (!isValid) {
                e.preventDefault();
                alert(SpamJudge.strings.validationFailed + '\n\n' + errorMessages.join('\n'));
            }
        });
    });
    
})(jQuery);
