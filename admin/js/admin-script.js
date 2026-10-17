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
         * 模型下拉框：设置页打开后按需联网读取模型清单并填充选项
         */
        var $providerSelect = $('#provider_id');
        var $modelSelect = $('#model_id');

        if ($modelSelect.length) {
            var $modelStatus = $('#model_status');
            var modelsByProvider = null;
            var savedModel = $modelSelect.attr('data-saved-model') || '';

            /**
             * 判断下拉框中是否已有指定值的选项
             *
             * @param {string} value 待查找的选项值
             * @return {boolean} 是否存在
             */
            var hasOption = function (value) {
                var found = false;

                $modelSelect.find('option').each(function () {
                    if (this.value === value) {
                        found = true;
                        return false;
                    }
                });

                return found;
            };

            /**
             * 按当前所选提供商重建模型选项
             *
             * @param {string} selectedValue 重建后需要选中的模型 ID
             */
            var fillModels = function (selectedValue) {
                var providerId = $providerSelect.val() || '';
                var $opts = $('<option>').val('').text(SpamJudge.strings.defaultModel);

                /**
                 * 生成某个提供商的模型选项
                 *
                 * @param {object} provider 提供商数据
                 * @return {jQuery} 选项或选项分组
                 */
                var buildProviderOptions = function (provider) {
                    var $group = $('<optgroup>').attr('label', provider.name);

                    $.each(provider.models, function (id, name) {
                        $group.append($('<option>').val(id).text(name || id));
                    });

                    // 提供商选“默认”时按服务商分组，选定具体服务商时只列它的模型
                    return providerId === '' ? $group : $group.children();
                };

                if (modelsByProvider) {
                    if (providerId) {
                        if (modelsByProvider[providerId]) {
                            $opts = $opts.add(buildProviderOptions(modelsByProvider[providerId]));
                        }
                    } else {
                        $.each(modelsByProvider, function (id, provider) {
                            $opts = $opts.add(buildProviderOptions(provider));
                        });
                    }
                }

                $modelSelect.empty().append($opts);

                // 清单中没有的已保存模型（服务商未返回或本次读取失败）保留为选项，避免保存本页时被清掉
                if (selectedValue && !hasOption(selectedValue)) {
                    $modelSelect.append($('<option>').val(selectedValue).text(selectedValue));
                }

                $modelSelect.val(hasOption(selectedValue) ? selectedValue : '');
            };

            // 切换服务商后原模型未必被新服务商支持，回到默认
            $providerSelect.on('change', function () {
                fillModels('');
            });

            $modelStatus.text(SpamJudge.strings.loadingModels);

            $.ajax({
                url: SpamJudge.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'spamjudge_get_models',
                    nonce: SpamJudge.nonce
                },
                success: function (response) {
                    if (!response || !response.success) {
                        $modelStatus.text(SpamJudge.strings.modelsLoadFailed);
                        return;
                    }

                    modelsByProvider = response.data.providers;
                    $modelStatus.text('');
                    fillModels(savedModel);
                },
                error: function () {
                    $modelStatus.text(SpamJudge.strings.modelsLoadFailed);
                }
            });
        }

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

            // 验证限流字符数（-1 无限制，0 暂停，不设上限）
            var rateLimitInput = $('#rate_limit_chars');
            if (rateLimitInput.length) {
                var rateLimit = parseInt(rateLimitInput.val());
                if (isNaN(rateLimit) || rateLimit < -1) {
                    isValid = false;
                    errorMessages.push(SpamJudge.strings.rateLimitInvalid);
                }
            }

            // 如果验证失败，显示错误消息
            if (!isValid) {
                e.preventDefault();
                alert(SpamJudge.strings.validationFailed + '\n\n' + errorMessages.join('\n'));
            }
        });
    });
    
})(jQuery);
