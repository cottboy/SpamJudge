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
        
        // 构建请求体
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
        
        // 根据安全规则动态拼装请求端点
        $prepared_endpoint = $this->prepare_request_endpoint();

        if ( empty( $prepared_endpoint ) ) {
            return array(
                'success' => false,
                'score' => null,
                'status_code' => null,
                'error' => __( 'API 端点无效', 'spamjudge' ),
            );
        }

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
        
        // 验证响应数据
        if ( ! is_array( $data ) || ! isset( $data['choices'][0]['message']['content'] ) ) {
            return array(
                'success' => false,
                'score' => null,
                'status_code' => $status_code,
                'error' => __( 'API 响应格式无效', 'spamjudge' ),
            );
        }
        
        // 提取 AI 返回的内容
        $ai_response = trim( $data['choices'][0]['message']['content'] );
        
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

        // 全量匹配无需处理的端点
        if ( $this->is_preserved_api_path( $endpoint ) ) {
            return $endpoint;
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

