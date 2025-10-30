<?php
/**
 * SpamJudge 核心类
 * 
 * 负责处理评论提交时的 AI 检查逻辑
 *
 * @package SpamJudge
 */

// 如果直接访问此文件，则退出
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SpamJudge 核心类
 */
class SpamJudge {
    
    /**
     * 单例实例
     *
     * @var SpamJudge
     */
    private static $instance = null;
    
    /**
     * 插件设置
     *
     * @var array
     */
    private $settings;
    
    /**
     * 获取单例实例
     *
     * @return SpamJudge
     */
    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 构造函数
     */
    private function __construct() {
        // 加载设置
        $this->load_settings();

        // 挂钩评论预处理
        add_filter( 'preprocess_comment', array( $this, 'check_comment' ), 10, 1 );

        // 挂钩评论发布后，确保垃圾评论被正确标记
        add_action( 'comment_post', array( $this, 'mark_spam_after_insert' ), 10, 3 );
    }
    
    /**
     * 加载设置
     */
    private function load_settings() {
        $this->settings = get_option( 'spamjudge_settings', array() );
        
        // 确保所有必需的设置都存在
        $defaults = array(
            'api_endpoint' => '',
            'api_key' => '',
            'model_id' => '',
            'temperature' => 0.1,
            'system_prompt' => 'You are a spam comment detection system. Your ONLY task is to output a single number between 0 and 100.

SCORING RULES:
- 0-20: Obvious spam (ads, malicious links, gibberish)
- 21-40: Likely spam (suspicious links, bot-like comments)
- 41-60: Uncertain (short comments, borderline content)
- 61-80: Likely legitimate (relevant, thoughtful)
- 81-100: Clearly legitimate (detailed, helpful, on-topic)

CRITICAL INSTRUCTIONS:
1. Output ONLY a number (0-100)
2. NO explanations
3. NO additional text
4. NO punctuation
5. Just the number

Example valid outputs: 85
Example INVALID outputs: "Score: 85", "85 points", "I think it\'s 85"

If you output anything other than a single number, the system will fail.',
            'score_threshold' => 40,
            'spam_action' => 'spam',
            'timeout' => 30,
            'timeout_action' => 'hold',
            'log_retention' => 90,
            'spam_message' => '',
            'error_message' => '',
        );
        
        $this->settings = wp_parse_args( $this->settings, $defaults );
    }
    
    /**
     * 检查评论
     *
     * @param array $commentdata 评论数据
     * @return array 处理后的评论数据
     */
    public function check_comment( $commentdata ) {

        // 如果 API 配置不完整，跳过检查
        if ( empty( $this->settings['api_key'] ) || empty( $this->settings['api_endpoint'] ) || empty( $this->settings['model_id'] ) ) {
            return $commentdata;
        }

        // 获取评论信息
        $comment_author = isset( $commentdata['comment_author'] ) ? $commentdata['comment_author'] : '';
        $comment_content = isset( $commentdata['comment_content'] ) ? $commentdata['comment_content'] : '';

        // 如果评论内容为空，跳过检查
        if ( empty( $comment_content ) ) {
            return $commentdata;
        }

        // 创建 AI API 客户端
        $api_client = new SpamJudge_API_Client( $this->settings );

        // 调用 AI 检查评论
        $result = $api_client->check_comment( $comment_author, $comment_content );

        // 初始化日志记录器
        $logger = new SpamJudge_Comment_Logger();

        // 确定要执行的操作
        $action = 'approved'; // 默认通过
        $comment_approved = 1; // 1 = 通过, 0 = 待审核, 'spam' = 垃圾评论

        if ( $result['success'] ) {
            // AI 成功返回分数
            $score = intval( $result['score'] );
            $threshold = intval( $this->settings['score_threshold'] );

            if ( $score < $threshold ) {
                // 分数低于阈值，根据设置决定操作
                $spam_action = sanitize_text_field( $this->settings['spam_action'] );

                if ( $spam_action === 'hold' ) {
                    // 移到待审核队列
                    $action = 'hold';
                    $comment_approved = 0;

                    // 添加过滤器，确保评论被正确标记为待审核
                    add_filter( 'pre_comment_approved', array( $this, 'force_hold_status' ), 99, 2 );
                } else {
                    // 移到垃圾队列（默认）
                    $action = 'spam';
                    $comment_approved = 'spam';

                    // 添加垃圾评论元数据，确保 WordPress 正确识别
                    add_filter( 'pre_comment_approved', array( $this, 'force_spam_status' ), 99, 2 );
                }
            } else {
                // 分数高于阈值，通过
                $action = 'approved';
                $comment_approved = 1;
            }

            // 记录日志（使用临时 ID，因为评论还未插入数据库）
            // 我们将在评论插入后更新日志
            $this->log_pending_check( array(
                'comment_author' => $comment_author,
                'comment_content' => $comment_content,
                'api_status_code' => $result['status_code'],
                'ai_score' => $result['score'],
                'action_taken' => $action,
            ) );

        } else {
            // AI 检查失败（超时或其他错误）
            $timeout_action = sanitize_text_field( $this->settings['timeout_action'] );

            if ( $timeout_action === 'approve' ) {
                // 超时后直接通过
                $action = 'approved';
                $comment_approved = 1;
            } else {
                // 超时后移到待审核
                $action = 'hold';
                $comment_approved = 0;

                // 添加过滤器，确保评论被正确标记为待审核
                add_filter( 'pre_comment_approved', array( $this, 'force_hold_status' ), 99, 2 );
            }

            // 记录日志
            $this->log_pending_check( array(
                'comment_author' => $comment_author,
                'comment_content' => $comment_content,
                'api_status_code' => $result['status_code'],
                'ai_score' => null,
                'action_taken' => $action,
            ) );
        }

        // 设置评论状态
        $commentdata['comment_approved'] = $comment_approved;

        return $commentdata;
    }

    /**
     * 强制设置垃圾评论状态
     *
     * 确保评论被正确标记为垃圾，不被其他插件或主题覆盖
     *
     * @param int|string $approved 评论状态
     * @param array $commentdata 评论数据
     * @return string 返回 'spam'
     */
    public function force_spam_status( $approved, $commentdata ) {
        // 移除过滤器，避免无限循环
        remove_filter( 'pre_comment_approved', array( $this, 'force_spam_status' ), 99 );

        // 返回垃圾评论状态
        return 'spam';
    }

    /**
     * 强制设置待审核状态
     *
     * 确保评论被正确标记为待审核，不被其他插件或主题覆盖
     *
     * @param int|string $approved 评论状态
     * @param array $commentdata 评论数据
     * @return int 返回 0（待审核）
     */
    public function force_hold_status( $approved, $commentdata ) {
        // 移除过滤器，避免无限循环
        remove_filter( 'pre_comment_approved', array( $this, 'force_hold_status' ), 99 );

        // 返回待审核状态
        return 0;
    }

    /**
     * 评论插入后标记为垃圾或待审核
     *
     * 如果评论应该被标记为垃圾或待审核但状态不正确，强制更新状态
     *
     * @param int $comment_id 评论 ID
     * @param int|string $comment_approved 评论状态
     * @param array $commentdata 评论数据
     */
    public function mark_spam_after_insert( $comment_id, $comment_approved, $commentdata ) {
        // 获取待处理的检查结果
        $check_data = get_transient( 'spamjudge_pending_log' );

        if ( ! $check_data ) {
            return;
        }

        $action_taken = isset( $check_data['action_taken'] ) ? $check_data['action_taken'] : '';

        // 如果操作是标记为垃圾，但评论状态不是垃圾，强制更新
        if ( $action_taken === 'spam' ) {
            if ( $comment_approved !== 'spam' ) {
                // 使用 WordPress 函数标记为垃圾
                wp_spam_comment( $comment_id );
            }
        }
        // 如果操作是移到待审核，但评论状态不是待审核，强制更新
        elseif ( $action_taken === 'hold' ) {
            if ( $comment_approved != 0 ) {
                // 使用 WordPress 函数标记为待审核
                wp_set_comment_status( $comment_id, 'hold' );
            }
        }
    }

    /**
     * 记录待处理的检查结果
     *
     * 由于评论还未插入数据库，我们暂存检查结果，在评论插入后记录
     *
     * @param array $check_data 检查数据
     */
    private function log_pending_check( $check_data ) {
        // 将检查结果存储在临时选项中
        set_transient( 'spamjudge_pending_log', $check_data, 60 );

        // 挂钩评论插入后的动作
        add_action( 'comment_post', array( $this, 'log_comment_check' ), 10, 1 );
    }
    
    /**
     * 记录评论检查结果
     *
     * @param int $comment_id 评论 ID（不使用，仅为兼容WordPress钩子）
     */
    public function log_comment_check( $comment_id ) {
        // 获取待处理的检查结果
        $check_data = get_transient( 'spamjudge_pending_log' );

        if ( ! $check_data ) {
            return;
        }

        // 删除临时数据
        delete_transient( 'spamjudge_pending_log' );

        // 记录日志（不记录comment_id）
        $logger = new SpamJudge_Comment_Logger();
        $logger->log(
            $check_data['comment_author'],
            $check_data['comment_content'],
            $check_data['api_status_code'],
            $check_data['ai_score'],
            $check_data['action_taken']
        );

        // 日志记录完成后，检查是否需要显示提醒
        $this->show_visitor_message( $check_data );
    }

    /**
     * 向访客显示提醒消息
     *
     * 在所有数据写入完成、日志记录完成后，根据设置向访客显示提醒
     *
     * @param array $check_data 检查数据
     */
    private function show_visitor_message( $check_data ) {
        $action_taken = isset( $check_data['action_taken'] ) ? $check_data['action_taken'] : '';
        $message = '';

        // 检查是否是垃圾评论（spam 或 hold）
        if ( $action_taken === 'spam' || $action_taken === 'hold' ) {
            // 检查是否是因为AI检测失败导致的（API状态码不是200或AI评分为null）
            $is_error = ( $check_data['api_status_code'] !== 200 || $check_data['ai_score'] === null );

            if ( $is_error ) {
                // 检测失败的情况
                $message = isset( $this->settings['error_message'] ) ? trim( $this->settings['error_message'] ) : '';
            } else {
                // AI成功检测为垃圾评论的情况
                $message = isset( $this->settings['spam_message'] ) ? trim( $this->settings['spam_message'] ) : '';
            }
        }

        // 如果有提醒消息，使用 wp_die() 显示
        if ( ! empty( $message ) ) {
            // 清理消息内容，防止XSS攻击
            $message = wp_kses_post( $message );

            // 将换行符转换为 <br> 标签，以便在HTML中正确显示
            $message = nl2br( $message );

            // 使用 wp_die() 显示消息，这会中断评论提交流程并显示消息
            wp_die(
                wp_kses_post( $message ),
                esc_html__( '评论提醒', 'spamjudge' ),
                array(
                    'response' => 200,
                    'back_link' => true,
                )
            );
        }
    }
}

