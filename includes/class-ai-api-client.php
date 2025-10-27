<?php
/**
 * AI API 客户端类
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
 * AI API 客户端类
 */
class AI_API_Client {
    
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
        
        // 发送 API 请求
        $response = wp_remote_post( $this->api_endpoint, array(
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
}

