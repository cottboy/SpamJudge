<?php
/**
 * API 客户端类
 * 
 * 负责与 OpenAI / Claude API 进行通信
 *
 * @package SpamJudge
 */

// 如果直接访问此文件，则退出
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * API 客户端类
 */
class SpamJudge_API_Client {
    
    /**
     * API 端点
     *
     * @var string
     */
    private $api_endpoint;
    
    /**
     * API 密钥
     *
     * @var string
     */
    private $api_key;
    
    /**
     * 模型 ID
     *
     * @var string
     */
    private $model_id;
    
    /**
     * 系统提示词
     *
     * @var string
     */
    private $system_prompt;
    
    /**
     * 超时时间（秒）
     *
     * @var int
     */
    private $timeout;

    /**
     * Claude API 版本头
     *
     * @var string
     */
    private $anthropic_version;

    /**
     * Claude 最大输出 Token
     *
     * @var int
     */
    private $claude_max_tokens;
    
    /**
     * 构造函数
     *
     * @param array $settings 设置数组
     */
    public function __construct( $settings ) {
        // 验证和清理输入
        $this->api_endpoint = esc_url_raw( $settings['api_endpoint'] );
        $this->api_key = sanitize_text_field( $settings['api_key'] );
        $this->model_id = sanitize_text_field( $settings['model_id'] );
        $this->system_prompt = sanitize_textarea_field( $settings['system_prompt'] );
        $this->timeout = absint( $settings['timeout'] );

        // Claude API 版本头：未配置时使用官方稳定版本
        $configured_anthropic_version = isset( $settings['anthropic_version'] ) ? sanitize_text_field( $settings['anthropic_version'] ) : '';
        $this->anthropic_version = $configured_anthropic_version !== '' ? $configured_anthropic_version : '2023-06-01';

        // Claude max_tokens：未配置时使用安全默认值，避免请求缺失必填字段
        $configured_claude_max_tokens = isset( $settings['claude_max_tokens'] ) ? absint( $settings['claude_max_tokens'] ) : 0;
        $this->claude_max_tokens = $configured_claude_max_tokens > 0 ? $configured_claude_max_tokens : 64;
        
        // 确保超时时间至少为 5 秒
        $this->timeout = max( 5, $this->timeout );
    }
    
    /**
     * 检查评论并获取 AI 评分
     *
     * @param string $comment_author 评论者名称
     * @param string $comment_content 评论内容
     * @return array 包含 'success', 'score', 'status_code', 'error' 的数组
     */
    public function check_comment( $comment_author, $comment_content ) {
        // 验证输入
        if ( empty( $comment_author ) || empty( $comment_content ) ) {
            return array(
                'success' => false,
                'score' => null,
                'status_code' => null,
                'error' => __( '评论者名称或评论内容为空', 'spamjudge' ),
            );
        }
        
        // 构建用户消息
        $user_message = sprintf(
            "Commenter Name: %s\nComment Content: %s",
            sanitize_text_field( $comment_author ),
            sanitize_textarea_field( $comment_content )
        );
        
        // 根据安全规则动态拼装请求端点（一次性计算，后续复用）
        $prepared_endpoint = $this->prepare_request_endpoint();

        if ( empty( $prepared_endpoint ) ) {
            return array(
                'success' => false,
                'score' => null,
                'status_code' => null,
                'error' => __( 'API 端点无效', 'spamjudge' ),
            );
        }

        // 判定当前请求是否指向 /v1/responses 端点（使用已补全后的端点判断，避免误差）
        $is_responses_api = $this->is_responses_endpoint( $prepared_endpoint );
        // 判定当前请求是否指向 Claude Messages API
        $is_claude_api = $this->is_claude_messages_endpoint( $prepared_endpoint );

        // 构建请求体：区分 Claude / Responses / Chat Completions 三类端点
        if ( $is_claude_api ) {
            /**
             * Claude Messages API 请求体
             *
             * - model、max_tokens、messages 为必填字段
             * - system 为可选字段，存在时传入以复用当前插件的系统提示词
             */
            $request_body = array(
                'model' => $this->model_id,
                'max_tokens' => $this->claude_max_tokens,
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => $user_message,
                    ),
                ),
            );

            if ( $this->system_prompt !== '' ) {
                $request_body['system'] = $this->system_prompt;
            }
        } elseif ( $is_responses_api ) {
            /**
             * Responses API 请求体
             *
             * - content 必须是带有 type 的数组（官方规范要求）
             * - 明确传递 system 与 user 两条消息，避免部分兼容实现忽略 instructions
             * - 仅传递文本输入，满足当前评分需求
             */
            $input_messages = array(
                array(
                    'role'    => 'system',
                    'content' => array(
                        array(
                            'type' => 'input_text',
                            'text' => $this->system_prompt,
                        ),
                    ),
                ),
                array(
                    'role'    => 'user',
                    'content' => array(
                        array(
                            'type' => 'input_text',
                            'text' => $user_message,
                        ),
                    ),
                ),
            );

            $request_body = array(
                'model' => $this->model_id,
                // 使用 messages 风格的 input，兼容要求严格遵守 schema 的供应商
                'input' => $input_messages,
                'stream' => false,
            );
        } else {
            // Chat Completions 请求体保持不变
            $request_body = array(
                'model' => $this->model_id,
                'messages' => array(
                    array(
                        'role' => 'system',
                        'content' => $this->system_prompt,
                    ),
                    array(
                        'role' => 'user',
                        'content' => $user_message,
                    ),
                ),
            );
        }

        // 发送 API 请求
        $response = wp_remote_post( $prepared_endpoint, array(
            'headers' => $this->build_request_headers( $is_claude_api ),
            'body' => wp_json_encode( $request_body ),
            'timeout' => $this->timeout,
            'sslverify' => true, // 安全性：验证 SSL 证书
        ) );
        
        // 检查是否有错误
        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'score' => null,
                'status_code' => null,
                'error' => $response->get_error_message(),
            );
        }
        
        // 获取响应状态码
        $status_code = wp_remote_retrieve_response_code( $response );
        
        // 检查状态码
        if ( $status_code !== 200 ) {
            $error_body = wp_remote_retrieve_body( $response );
            return array(
                'success' => false,
                'score' => null,
                'status_code' => $status_code,
                'error' => sprintf(
                    /* translators: 1: HTTP status code, 2: API error body */
                    __( 'API 返回错误状态码 %1$d: %2$s', 'spamjudge' ),
                    $status_code,
                    $error_body
                ),
            );
        }
        
        // 解析响应体
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        // 针对不同端点执行响应提取
        if ( $is_claude_api ) {
            $ai_response = $this->extract_claude_messages_text( $data );
        } elseif ( $is_responses_api ) {
            $ai_response = $this->extract_responses_api_text( $data );
        } else {
            $ai_response = $this->extract_chat_completions_text( $data );
        }

        // 验证响应数据
        if ( $ai_response === null ) {
            return array(
                'success' => false,
                'score' => null,
                'status_code' => $status_code,
                'error' => __( 'API 响应格式无效', 'spamjudge' ),
            );
        }
        
        // 尝试从响应中提取分数（0-100）
        $score = $this->extract_score( $ai_response );
        
        if ( $score === null ) {
            return array(
                'success' => false,
                'score' => null,
                'status_code' => $status_code,
                'error' => sprintf(
                    /* translators: %s: AI response content */
                    __( 'AI 返回的内容不是有效分数: %s', 'spamjudge' ),
                    $ai_response
                ),
            );
        }
        
        return array(
            'success' => true,
            'score' => $score,
            'status_code' => $status_code,
            'error' => null,
        );
    }
    
    /**
     * 从 AI 响应中提取分数
     *
     * @param string $response AI 响应内容
     * @return int|null 分数（0-100）或 null
     */
    private function extract_score( $response ) {
        // 移除所有非数字字符
        $cleaned = preg_replace( '/[^0-9]/', '', $response );

        // 注意：不能使用 empty()，因为 empty("0") 返回 true
        // 必须检查字符串长度，以支持 AI 返回 "0" 的情况
        if ( $cleaned === '' ) {
            return null;
        }

        $score = intval( $cleaned );

        // 验证分数在 0-100 范围内
        if ( $score < 0 || $score > 100 ) {
            return null;
        }

        return $score;
    }

    /**
     * 按端点类型构建请求头
     *
     * @param bool $is_claude_api 是否为 Claude Messages API
     * @return array
     */
    private function build_request_headers( $is_claude_api ) {
        $headers = array(
            'Content-Type' => 'application/json',
            'User-Agent' => 'SpamJudge WordPress Plugin',
        );

        // Claude 使用 x-api-key 与 anthropic-version；其他端点继续使用 Bearer 认证
        if ( $is_claude_api ) {
            $headers['x-api-key'] = $this->api_key;
            $headers['anthropic-version'] = $this->anthropic_version;
        } else {
            $headers['Authorization'] = 'Bearer ' . $this->api_key;
        }

        return $headers;
    }

    /**
     * 判定是否为 /v1/responses 端点
     *
     * @param string $endpoint 已补全后的端点
     * @return bool
     */
    private function is_responses_endpoint( $endpoint ) {
        return $this->endpoint_path_ends_with( $endpoint, '/v1/responses' );
    }

    /**
     * 判定是否为 Claude /v1/messages 端点
     *
     * @param string $endpoint 已补全后的端点
     * @return bool
     */
    private function is_claude_messages_endpoint( $endpoint ) {
        return $this->endpoint_path_ends_with( $endpoint, '/v1/messages' );
    }

    /**
     * 提取 Chat Completions 响应中的文本
     *
     * @param array $data 解码后的响应数据
     * @return string|null
     */
    private function extract_chat_completions_text( $data ) {
        if ( ! is_array( $data ) || ! isset( $data['choices'][0]['message']['content'] ) ) {
            return null;
        }

        return trim( $data['choices'][0]['message']['content'] );
    }

    /**
     * 提取 Claude Messages API 响应中的文本
     *
     * @param array $data 解码后的响应数据
     * @return string|null
     */
    private function extract_claude_messages_text( $data ) {
        if ( ! is_array( $data ) || ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
            return null;
        }

        foreach ( $data['content'] as $content_item ) {
            if ( ! is_array( $content_item ) ) {
                continue;
            }

            if ( ! isset( $content_item['type'] ) || $content_item['type'] !== 'text' ) {
                continue;
            }

            if ( ! isset( $content_item['text'] ) || ! is_string( $content_item['text'] ) ) {
                continue;
            }

            $normalized_text = trim( $content_item['text'] );

            if ( $normalized_text !== '' ) {
                return $normalized_text;
            }
        }

        return null;
    }

    /**
     * 提取 Responses API 响应中的文本
     *
     * 优先使用 output_text（官方推荐的便捷字段），若不存在则回退读取
     * output 数组中的 content 文本字段；若仍不存在则返回 null。
     *
     * @param array $data 解码后的响应数据
     * @return string|null
     */
    private function extract_responses_api_text( $data ) {
        if ( ! is_array( $data ) ) {
            return null;
        }

        // 首选 output_text 字段（官方 SDK 提供的快捷字段），但若内容为空需要继续回退
        if ( isset( $data['output_text'] ) ) {
            $normalized_output_text = $this->normalize_responses_text_field( $data['output_text'] );

            if ( $normalized_output_text !== '' ) {
                return $normalized_output_text;
            }
        }

        // 兼容 output 数组：遍历所有输出项与内容项，找到第一个文本
        if ( isset( $data['output'] ) && is_array( $data['output'] ) ) {
            foreach ( $data['output'] as $output_item ) {
                if ( ! isset( $output_item['content'] ) || ! is_array( $output_item['content'] ) ) {
                    continue;
                }

                foreach ( $output_item['content'] as $content_item ) {
                    // Responses API 文本内容通常包含 type=output_text 与 text 字段
                    if ( isset( $content_item['text'] ) ) {
                        $normalized_text = $this->normalize_responses_text_field( $content_item['text'] );

                        if ( $normalized_text !== '' ) {
                            return $normalized_text;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * 将 Responses API 的 text 字段统一转换为字符串
     *
     * 兼容以下情况：
     * - 直接返回字符串
     * - 返回数组（多个字符串或嵌套数组）
     * - 返回对象（例如 { value: "...", annotations: [] } 的结构）
     *
     * @param mixed $text_field 原始 text 字段
     * @return string 正常化后的文本，若为空则返回空字符串
     */
    private function normalize_responses_text_field( $text_field ) {
        // 字符串直接去除首尾空白
        if ( is_string( $text_field ) ) {
            $sanitized = trim( $text_field );
            return $sanitized;
        }

        // 对象转换成数组后统一处理
        if ( is_object( $text_field ) ) {
            $text_field = (array) $text_field;
        }

        // 数组场景需递归提取各项内容
        if ( is_array( $text_field ) ) {
            $collected = array();

            foreach ( $text_field as $value ) {
                if ( isset( $value ) && $value !== '' ) {
                    $normalized_child = $this->normalize_responses_text_field( $value );

                    if ( $normalized_child !== '' ) {
                        $collected[] = $normalized_child;
                    }
                }
            }

            if ( ! empty( $collected ) ) {
                return trim( implode( "\n", $collected ) );
            }

            // 若数组中存在 value 字段（例如 { value: 'text', annotations: [] }），优先读取
            if ( isset( $text_field['value'] ) && is_string( $text_field['value'] ) ) {
                return trim( $text_field['value'] );
            }
        }

        return '';
    }

    /**
     * 构造最终请求端点
     *
     * - 以 # 结尾视为“禁止自动补全”开关，请求前移除 #
     * - 兼容用户未写版本路径的情况，自动补全 chat completions 端点
     * - 对已指向 /v1/chat/completions、/v1/responses、/v1/messages 的 URL 保持不变
     *
     * @return string 构造后的端点
     */
    private function prepare_request_endpoint() {
        $endpoint = trim( $this->api_endpoint );

        if ( $endpoint === '' ) {
            return '';
        }

        // 用户以 # 结尾表示“不要自动补全”，仅移除 # 后直接返回。
        // 例如：https://example.com/custom/path# -> https://example.com/custom/path
        if ( substr( $endpoint, -1 ) === '#' ) {
            $endpoint_without_flag = rtrim( $endpoint, '# ' );

            if ( $endpoint_without_flag === '' ) {
                return '';
            }

            return $endpoint_without_flag;
        }

        // 精确匹配官方路径（无尾斜杠），直接使用
        if ( $this->is_preserved_api_path( $endpoint ) ) {
            return $this->normalize_preserved_endpoint( $endpoint );
        }

        // 以 /v1 结尾 → 拼接 /chat/completions，避免重复附加版本前缀
        if ( $this->ends_with( $endpoint, '/v1' ) ) {
            return $endpoint . '/chat/completions';
        }

        // 以 / 结尾 → 直接拼接 chat/completions
        if ( substr( $endpoint, -1 ) === '/' ) {
            return $endpoint . 'chat/completions';
        }

        // 其他情况默认补全 /v1/chat/completions
        return $endpoint . '/v1/chat/completions';
    }

    /**
     * 判断端点是否属于无需变更的目标路径
     *
     * @param string $endpoint 当前端点
     * @return bool
     */
    private function is_preserved_api_path( $endpoint ) {
        $suffixes = array(
            '/v1/chat/completions',
            '/v1/responses',
            '/v1/messages',
        );

        foreach ( $suffixes as $suffix ) {
            if ( $this->endpoint_path_ends_with( $endpoint, $suffix ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * 规范化官方端点格式（移除多余斜杠但保留查询/片段）
     *
     * @param string $endpoint 原始端点
     * @return string
     */
    private function normalize_preserved_endpoint( $endpoint ) {
        $endpoint = trim( $endpoint );

        if ( $endpoint === '' ) {
            return '';
        }

        $endpoint_length = strlen( $endpoint );
        $delimiter_position = strcspn( $endpoint, '?#' );

        if ( $delimiter_position >= $endpoint_length ) {
            $base_path = $endpoint;
            $suffix = '';
        } else {
            $base_path = substr( $endpoint, 0, $delimiter_position );
            $suffix = substr( $endpoint, $delimiter_position );
        }

        $base_path = rtrim( $base_path, '/' );

        return $base_path . $suffix;
    }

    /**
     * 安全判断字符串是否以指定后缀结尾
     *
     * @param string $haystack 原始字符串
     * @param string $needle 后缀
     * @return bool
     */
    private function ends_with( $haystack, $needle ) {
        $haystack_length = strlen( $haystack );
        $needle_length = strlen( $needle );

        if ( $needle_length === 0 ) {
            return true;
        }

        if ( $needle_length > $haystack_length ) {
            return false;
        }

        return substr( $haystack, - $needle_length ) === $needle;
    }

    /**
     * 判断 URL 的路径部分是否以指定后缀结尾（忽略查询参数）
     *
     * @param string $endpoint 待检测的完整端点
     * @param string $suffix   需要匹配的路径后缀
     * @return bool
     */
    private function endpoint_path_ends_with( $endpoint, $suffix ) {
        $path = wp_parse_url( $endpoint, PHP_URL_PATH );

        if ( ! is_string( $path ) ) {
            $path = $endpoint;
        }

        $normalized_path = rtrim( $path, '/' );
        $normalized_suffix = rtrim( $suffix, '/' );

        if ( $normalized_suffix === '' ) {
            return true;
        }

        $path_length = strlen( $normalized_path );
        $suffix_length = strlen( $normalized_suffix );

        if ( $suffix_length > $path_length ) {
            return false;
        }

        return substr( $normalized_path, - $suffix_length ) === $normalized_suffix;
    }
}

