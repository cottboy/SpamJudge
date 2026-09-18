<?php
/**
 * AI 检测客户端类
 *
 * 通过 WordPress 7.0 内置的 AI Client（wp_ai_client_prompt()）与 AI 模型通信。
 * 供应商、API 端点与密钥均由 WordPress 统一管理（后台"设置 → AI 凭据"），
 * 插件本身不再单独配置任何供应商信息。
 *
 * @package SpamJudge
 */

// 如果直接访问此文件，则退出
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use WordPress\AiClient\Providers\Http\DTO\RequestOptions;

/**
 * AI 检测客户端类
 */
class SpamJudge_API_Client {

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
        $this->system_prompt = sanitize_textarea_field( $settings['system_prompt'] ?? '' );
        $this->timeout = absint( $settings['timeout'] ?? 30 );

        // 确保超时时间至少为 5 秒
        $this->timeout = max( 5, $this->timeout );
    }

    /**
     * 检测当前站点是否支持 AI 文本生成
     *
     * 基于 WordPress 内置 AI Client 的能力检测，纯本地判断，不产生任何 API 请求。
     * 只要站点在"设置 → AI 凭据"中配置了任一支持文本生成的供应商即返回 true。
     *
     * @return bool
     */
    public function is_supported() {
        return wp_ai_client_prompt()
            ->with_text( 'test' )
            ->is_supported_for_text_generation();
    }

    /**
     * 检查评论并获取 AI 评分
     *
     * @param string $comment_author 评论者名称
     * @param string $comment_content 评论内容
     * @return array 包含 'success', 'score' 的数组
     */
    public function check_comment( $comment_author, $comment_content ) {
        // 验证输入
        if ( empty( $comment_author ) || empty( $comment_content ) ) {
            return array(
                'success' => false,
                'score' => null,
            );
        }

        // 构建用户消息（对评论者名称与评论内容做清理，防止提示词注入之外的意外内容）
        $user_message = sprintf(
            "Commenter Name: %s\nComment Content: %s",
            sanitize_text_field( $comment_author ),
            sanitize_textarea_field( $comment_content )
        );

        // 通过 WordPress 内置 AI Client 发起文本生成请求
        $ai_response = wp_ai_client_prompt()
            ->using_system_instruction( $this->system_prompt )
            ->with_text( $user_message )
            // 使用插件设置的超时时间覆盖默认请求选项
            ->using_request_options(
                RequestOptions::fromArray(
                    array(
                        RequestOptions::KEY_TIMEOUT => $this->timeout,
                    )
                )
            )
            ->generate_text();

        // 请求失败（超时、网络错误、供应商返回错误等）时返回 WP_Error
        if ( is_wp_error( $ai_response ) ) {
            return array(
                'success' => false,
                'score' => null,
            );
        }

        // 验证响应文本非空
        $ai_response = trim( (string) $ai_response );

        if ( $ai_response === '' ) {
            return array(
                'success' => false,
                'score' => null,
            );
        }

        // 尝试从响应中提取分数（0-100）
        $score = $this->extract_score( $ai_response );

        return array(
            'success' => $score !== null,
            'score' => $score,
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
