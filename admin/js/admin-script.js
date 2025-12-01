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
         * 切换 API 密钥可见性（小眼睛按钮）
         */
        $('.sj-toggle-password').on('click', function(e) {
            e.preventDefault();
            var $input = $(this).siblings('input');
            var $icon = $(this).find('.dashicons');
            
            if ($input.attr('type') === 'password') {
                // 密码隐藏 -> 显示文本，眼睛从闭上变睁开
                $input.attr('type', 'text');
                $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
            } else {
                // 文本显示 -> 隐藏密码，眼睛从睁开变闭上
                $input.attr('type', 'password');
                $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
            }
        });
        
        /**
         * 表单验证
         */
        $('form').on('submit', function(e) {
            var isValid = true;
            var errorMessages = [];

            // 验证 API 端点
            var apiEndpoint = $('#api_endpoint').val().trim();
            if (!apiEndpoint) {
                isValid = false;
                errorMessages.push(SpamJudge.strings.apiEndpointEmpty);
            } else if (!isValidUrl(apiEndpoint)) {
                isValid = false;
                errorMessages.push(SpamJudge.strings.apiEndpointInvalid);
            }

            // 验证 API 密钥
            var apiKey = $('#api_key').val().trim();
            if (!apiKey) {
                isValid = false;
                errorMessages.push(SpamJudge.strings.apiKeyEmpty);
            }

            // 验证模型 ID
            var modelId = $('#model_id').val().trim();
            if (!modelId) {
                isValid = false;
                errorMessages.push(SpamJudge.strings.modelIdEmpty);
            }

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
        
        /**
         * 验证 URL 格式
         */
        function isValidUrl(url) {
            try {
                new URL(url);
                return true;
            } catch (e) {
                return false;
            }
        }
    });
    
})(jQuery);

