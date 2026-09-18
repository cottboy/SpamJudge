<?php
/**
 * AI 检测客户端类
 *
 * 通过 WordPress 7.0 内置的 AI Client（wp_ai_client_prompt()）与 AI 模型通信。
 * 供应商、API 端点与密钥均由 WordPress 统一管理（后台"设置 → 连接"），
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
     * AI 提供商 ID（空字符串表示由 WordPress 自动选择）
     *
     * @var string
     */
    private $provider_id;

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
        $this->provider_id = sanitize_text_field( $settings['provider_id'] ?? '' );
        $this->system_prompt = sanitize_textarea_field( $settings['system_prompt'] ?? '' );
        $this->timeout = absint( $settings['timeout'] ?? 30 );

        // 确保超时时间至少为 5 秒
        $this->timeout = max( 5, $this->timeout );
    }

    /**
     * 获取当前站点可用的 AI 提供商列表
     *
     * 仅返回已配置凭据的提供商，供设置页下拉框与能力检测使用。
     * 凭据判断基于 AI Client 注册表中已注入的请求认证，均为零成本本地检查，
     * 不会发起任何 API 请求（注意：不要用 isProviderConfigured()，它会发起真实的测试请求）。
     * 覆盖的凭据来源：
     * - WordPress 后台"设置 → 连接"页面存储的 API 密钥（核心在 init 优先级 20 时注入注册表）
     * - 环境变量或 PHP 常量（SDK 注册提供商时会自动读取同名的 {PROVIDER_ID}_API_KEY）
     * - 第三方插件自行调用 setProviderRequestAuthentication() 注入的请求认证
     *
     * @return array 以提供商 ID 为键、显示名称为值的数组
     */
    public static function get_available_providers() {
        // 同一请求内缓存结果，避免重复遍历注册表
        static $cache = null;

        if ( is_array( $cache ) ) {
            return $cache;
        }

        $cache = array();

        // WordPress 内置 AI Client 不可用时直接返回空列表
        if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
            return $cache;
        }

        try {
            $registry = \WordPress\AiClient\AiClient::defaultRegistry();

            foreach ( $registry->getRegisteredProviderIds() as $provider_id ) {
                // 注册表中已存在请求认证实例即视为已配置凭据
                if ( $registry->getProviderRequestAuthentication( $provider_id ) === null ) {
                    continue;
                }

                // 获取提供商显示名称，缺失时以首字母大写的 ID 兜底
                $class_name = $registry->getProviderClassName( $provider_id );
                $name = $class_name::metadata()->getName();

                $cache[ $provider_id ] = $name !== '' ? $name : ucwords( $provider_id );
            }
        } catch ( \Exception $e ) {
            // 注册表操作出现异常时，返回已收集到的结果
        }

        return $cache;
    }

    /**
     * 构建评分用的提示词构建器
     *
     * 统一 is_supported() 与 check_comment() 的构建逻辑，
     * 确保能力检测与实际请求使用相同的提供商配置
     *
     * @param string $text 用户消息文本
     * @return WP_AI_Client_Prompt_Builder WordPress 内置 AI Client 的提示词构建器
     */
    private function build_prompt( $text ) {
        $builder = wp_ai_client_prompt()
            ->using_system_instruction( $this->system_prompt );

        // 设置了指定提供商时使用之，否则由 WordPress 自动选择
        if ( $this->provider_id !== '' ) {
            $builder = $builder->using_provider( $this->provider_id );
        }

        return $builder->with_text( $text );
    }

    /**
     * 检测当前站点是否支持 AI 文本生成
     *
     * 基于已配置凭据的提供商列表进行判断，纯本地检查，不产生任何 API 请求。
     * 若设置了指定提供商，则只检测该提供商；否则检测任意已配置的提供商。
     *
     * @return bool
     */
    public function is_supported() {
        $providers = self::get_available_providers();

        // 设置了指定提供商时，只检测该提供商是否可用
        if ( $this->provider_id !== '' ) {
            return array_key_exists( $this->provider_id, $providers );
        }

        return ! empty( $providers );
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
        $ai_response = $this->build_prompt( $user_message )
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
