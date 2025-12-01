<?php
/**
 * API 客户端类
 * 
 * 负责与 OpenAI 格式的 API 进行通信
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
     * 温度参数
     *
     * @var float
     */
    private $temperature;
    
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
     * 调试日志文件路径
     *
     * @var string
     */
    private $debug_log_path;
    
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
        $this->temperature = floatval( $settings['temperature'] );
        $this->system_prompt = sanitize_textarea_field( $settings['system_prompt'] );
        $this->timeout = absint( $settings['timeout'] );
        $this->debug_log_path = trailingslashit( SPAMJUDGE_PLUGIN_DIR ) . 'spamjudge-debug.log';
        
        // 确保温度在有效范围内 (0-2)
        $this->temperature = max( 0, min( 2, $this->temperature ) );
        
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

        // 构建请求体：区分 Chat Completions 与 Responses API
        if ( $is_responses_api ) {
            /**
             * Responses API 请求体
             *
             * - 按官方规范使用 role/content 结构的 input（参考 v1/responses 文档）
             * - 明确传递 system 与 user 两条消息，避免部分兼容实现忽略 instructions 导致提示词失效
             * - 保持温度与非流式设置一致，避免行为差异
             * - 仅传递文本输入，满足当前评分需求
             */
            $input_messages = array(
                array(
                    'role'    => 'system',
                    'content' => $this->system_prompt,
                ),
                array(
                    'role'    => 'user',
                    'content' => $user_message,
                ),
            );

            $request_body = array(
                'model' => $this->model_id,
                // 使用 messages 风格的 input，兼容要求 system 角色提示词的供应商
                'input' => $input_messages,
                'temperature' => $this->temperature,
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
                'temperature' => $this->temperature,
            );
        }

        // 记录请求构造阶段的上下文，便于排查后续异常
        $this->write_debug_log(
            'request_prepared',
            array(
                'endpoint' => $prepared_endpoint,
                'is_responses_api' => $is_responses_api,
                'request_body' => $this->redact_request_body_for_log( $request_body ),
            )
        );
        
        // 发送 API 请求
        $response = wp_remote_post( $prepared_endpoint, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
                'User-Agent' => 'SpamJudge WordPress Plugin', // 自定义 User-Agent 标识
            ),
            'body' => wp_json_encode( $request_body ),
            'timeout' => $this->timeout,
            'sslverify' => true, // 安全性：验证 SSL 证书
        ) );
        
        // 检查是否有错误
        if ( is_wp_error( $response ) ) {
            $this->write_debug_log(
                'request_failed',
                array(
                    'reason' => $response->get_error_message(),
                )
            );
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
            $this->write_debug_log(
                'response_error',
                array(
                    'status_code' => $status_code,
                    'body_preview' => $this->truncate_for_log( $error_body ),
                )
            );
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
        if ( $is_responses_api ) {
            $ai_response = $this->extract_responses_api_text( $data );
        } else {
            $ai_response = $this->extract_chat_completions_text( $data );
        }

        $this->write_debug_log(
            'response_parsed',
            array(
                'status_code' => $status_code,
                'raw_body_preview' => $this->truncate_for_log( $body ),
                'parsed_text_preview' => $this->truncate_for_log( $ai_response ),
            )
        );

        // 验证响应数据
        if ( $ai_response === null ) {
            $this->write_debug_log(
                'response_parse_failed',
                array(
                    'status_code' => $status_code,
                    'parsed_data' => $data,
                    'is_responses_api' => $is_responses_api,
                )
            );
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
            $this->write_debug_log(
                'score_parse_failed',
                array(
                    'ai_response' => $ai_response,
                )
            );
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
     * 判定是否为 /v1/responses 端点
     *
     * @param string $endpoint 已补全后的端点
     * @return bool
     */
    private function is_responses_endpoint( $endpoint ) {
        return $this->ends_with( $endpoint, '/v1/responses' );
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

        // 首选 output_text 数组
        if ( isset( $data['output_text'] ) ) {
            // output_text 可能是字符串或数组，做兼容处理
            if ( is_string( $data['output_text'] ) ) {
                return trim( $data['output_text'] );
            }

            if ( is_array( $data['output_text'] ) && isset( $data['output_text'][0] ) && is_string( $data['output_text'][0] ) ) {
                return trim( $data['output_text'][0] );
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
                    if ( isset( $content_item['text'] ) && is_string( $content_item['text'] ) ) {
                        return trim( $content_item['text'] );
                    }
                }
            }
        }

        return null;
    }

    /**
     * 记录调试日志（写入插件目录）
     *
     * @param string $context 上下文字段
     * @param array  $payload 需要写入的详细数据
     * @return void
     */
    private function write_debug_log( $context, $payload ) {
        // 确保日志路径有效且插件目录可写
        if ( empty( $this->debug_log_path ) || ! is_string( $this->debug_log_path ) ) {
            return;
        }

        $context = sanitize_key( $context );
        $payload = $this->sanitize_log_payload( $payload );

        $log_entry = array(
            'timestamp' => current_time( 'mysql' ),
            'context' => $context,
            'payload' => $payload,
        );

        $encoded = wp_json_encode( $log_entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        if ( false === $encoded ) {
            return;
        }

        // 保证日志目录存在（插件目录已存在，此调用只在极端情况下创建）
        wp_mkdir_p( dirname( $this->debug_log_path ) );

        // 使用 FILE_APPEND + LOCK_EX 防止并发写入冲突
        file_put_contents(
            $this->debug_log_path,
            $encoded . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * 清理和截断将写入日志的数据，避免注入恶意内容
     *
     * @param mixed $data 原始数据
     * @return mixed 已清理的数据
     */
    private function sanitize_log_payload( $data ) {
        if ( is_array( $data ) ) {
            $clean = array();

            foreach ( $data as $key => $value ) {
                $safe_key = is_string( $key ) ? sanitize_key( $key ) : $key;
                $clean[ $safe_key ] = $this->sanitize_log_payload( $value );
            }

            return $clean;
        }

        if ( is_object( $data ) ) {
            return $this->sanitize_log_payload( (array) $data );
        }

        if ( is_bool( $data ) || is_int( $data ) || is_float( $data ) ) {
            return $data;
        }

        if ( is_string( $data ) ) {
            $data = wp_strip_all_tags( $data );
            return $this->truncate_for_log( $data );
        }

        return $data;
    }

    /**
     * 截断日志内容，避免单条记录过长
     *
     * @param string|null $value 需要截断的字符串
     * @param int         $limit 最大长度
     * @return string|null
     */
    private function truncate_for_log( $value, $limit = 600 ) {
        if ( $value === null ) {
            return null;
        }

        $value = (string) $value;

        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $value ) <= $limit ) {
                return $value;
            }

            return mb_substr( $value, 0, $limit ) . '...';
        }

        if ( strlen( $value ) <= $limit ) {
            return $value;
        }

        return substr( $value, 0, $limit ) . '...';
    }

    /**
     * 针对请求体进行遮罩，仅保留排查所需字段
     *
     * @param array $request_body 原始请求体
     * @return array
     */
    private function redact_request_body_for_log( $request_body ) {
        if ( ! is_array( $request_body ) ) {
            return array();
        }

        $body = $request_body;

        // 处理 messages / input，避免日志过长
        $message_keys = array( 'messages', 'input' );

        foreach ( $message_keys as $key ) {
            if ( isset( $body[ $key ] ) && is_array( $body[ $key ] ) ) {
                $body[ $key ] = $this->redact_messages_for_log( $body[ $key ] );
            }
        }

        return $this->sanitize_log_payload( $body );
    }

    /**
     * 避免在日志中记录完整评论内容，仅记录前缀
     *
     * @param array $messages 消息数组
     * @return array
     */
    private function redact_messages_for_log( $messages ) {
        $clean_messages = array();

        foreach ( $messages as $message ) {
            if ( ! is_array( $message ) ) {
                continue;
            }

            $clean_messages[] = array(
                'role' => isset( $message['role'] ) ? sanitize_key( $message['role'] ) : '',
                'content_preview' => isset( $message['content'] ) ? $this->truncate_for_log( (string) $message['content'], 200 ) : '',
            );
        }

        return $clean_messages;
    }

    /**
     * 构造最终请求端点
     *
     * - 明确处理以 # 结尾的 URL，防止携带片段
     * - 兼容用户未写版本路径的情况，自动补全 chat completions 端点
     * - 对已指向 /v1/chat/completions 或 /v1/responses 的 URL 保持不变
     *
     * @return string 构造后的端点
     */
    private function prepare_request_endpoint() {
        $endpoint = trim( $this->api_endpoint );

        if ( $endpoint === '' ) {
            return '';
        }

        // 精确匹配官方路径（无尾斜杠），直接使用
        if ( $this->is_preserved_api_path( $endpoint ) ) {
            return $endpoint;
        }

        // 若以官方路径加尾斜杠结尾，去掉尾斜杠后返回，避免 404
        if ( $this->ends_with( $endpoint, '/v1/chat/completions/' ) ) {
            return rtrim( $endpoint, '/' );
        }

        if ( $this->ends_with( $endpoint, '/v1/responses/' ) ) {
            return rtrim( $endpoint, '/' );
        }

        // 去掉末尾的 #，确保不携带片段。
        if ( substr( $endpoint, -1 ) === '#' ) {
            $endpoint = rtrim( $endpoint, '# ' );

            if ( $endpoint === '' ) {
                return '';
            }

            if ( $this->is_preserved_api_path( $endpoint ) ) {
                return $endpoint;
            }
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
        );

        foreach ( $suffixes as $suffix ) {
            if ( $this->ends_with( $endpoint, $suffix ) ) {
                return true;
            }
        }

        return false;
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
}

