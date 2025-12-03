<?php
/**
 * 管理后台设置页面类
 * 
 * 负责渲染和处理插件设置页面
 *
 * @package SpamJudge
 */

// 如果直接访问此文件，则退出
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 管理后台设置页面类
 */
class SpamJudge_Admin_Settings {
    
    /**
     * 单例实例
     *
     * @var SpamJudge_Admin_Settings
     */
    private static $instance = null;
    
    /**
     * 获取单例实例
     *
     * @return SpamJudge_Admin_Settings
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
        // 添加管理菜单
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        
        // 注册设置
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        
        // 加载管理后台资源
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        
        // 处理 AJAX 请求
        add_action( 'wp_ajax_spamjudge_clear_logs', array( $this, 'ajax_clear_logs' ) );
        add_action( 'wp_ajax_spamjudge_get_logs', array( $this, 'ajax_get_logs' ) );
    }
    
    /**
     * 添加管理菜单
     */
    public function add_admin_menu() {
        add_options_page(
            __( 'SpamJudge', 'spamjudge' ), // 页面标题
            __( 'SpamJudge', 'spamjudge' ), // 菜单标题
            'manage_options', // 权限要求
            'spamjudge', // 菜单 slug
            array( $this, 'render_admin_page' ) // 回调函数
        );
    }
    
    /**
     * 注册设置
     */
    public function register_settings() {
        register_setting(
            'spamjudge_settings_group', // 选项组
            'spamjudge_settings', // 选项名称
            array( $this, 'sanitize_settings' ) // 清理回调
        );
    }
    
    /**
     * 清理设置
     *
     * @param array $input 输入数据
     * @return array 清理后的数据
     */
    public function sanitize_settings( $input ) {
        $sanitized = array();
        
        // API 端点
        if ( isset( $input['api_endpoint'] ) ) {
            $sanitized['api_endpoint'] = esc_url_raw( $input['api_endpoint'] );
        }
        
        // API 密钥
        if ( isset( $input['api_key'] ) ) {
            $sanitized['api_key'] = sanitize_text_field( $input['api_key'] );
        }
        
        // 模型 ID
        if ( isset( $input['model_id'] ) ) {
            $sanitized['model_id'] = sanitize_text_field( $input['model_id'] );
        }
        
        // 系统提示词
        if ( isset( $input['system_prompt'] ) ) {
            $sanitized['system_prompt'] = sanitize_textarea_field( $input['system_prompt'] );
        }
        
        // 分数阈值
        if ( isset( $input['score_threshold'] ) ) {
            $threshold = intval( $input['score_threshold'] );
            $sanitized['score_threshold'] = max( 0, min( 100, $threshold ) );
        }

        // 检测到垃圾评论后的操作
        if ( isset( $input['spam_action'] ) ) {
            $spam_action = sanitize_text_field( $input['spam_action'] );
            $sanitized['spam_action'] = in_array( $spam_action, array( 'spam', 'hold' ) ) ? $spam_action : 'spam';
        }

        // 超时时间
        if ( isset( $input['timeout'] ) ) {
            $sanitized['timeout'] = max( 5, absint( $input['timeout'] ) );
        }
        
        // 超时后的操作
        if ( isset( $input['timeout_action'] ) ) {
            $timeout_action = sanitize_text_field( $input['timeout_action'] );
            $sanitized['timeout_action'] = in_array( $timeout_action, array( 'hold', 'approve' ) ) ? $timeout_action : 'hold';
        }
        
        // 日志保留时间
        if ( isset( $input['log_retention'] ) ) {
            $sanitized['log_retention'] = intval( $input['log_retention'] );
        }

        // 检测为垃圾评论后对访客的提醒
        if ( isset( $input['spam_message'] ) ) {
            $sanitized['spam_message'] = sanitize_textarea_field( $input['spam_message'] );
        }

        // 检测失败后对访客的提醒
        if ( isset( $input['error_message'] ) ) {
            $sanitized['error_message'] = sanitize_textarea_field( $input['error_message'] );
        }

        return $sanitized;
    }
    
    /**
     * 加载管理后台资源
     *
     * @param string $hook 当前页面 hook
     */
    public function enqueue_admin_assets( $hook ) {
        // 仅在插件设置页面加载
        if ( $hook !== 'settings_page_spamjudge' ) {
            return;
        }
        
        // 加载样式
        wp_enqueue_style(
            'spamjudge-admin',
            SPAMJUDGE_PLUGIN_URL . 'admin/css/admin-style.css',
            array(),
            SPAMJUDGE_VERSION
        );
        
        // 加载脚本
        wp_enqueue_script(
            'spamjudge-admin',
            SPAMJUDGE_PLUGIN_URL . 'admin/js/admin-script.js',
            array( 'jquery' ),
            SPAMJUDGE_VERSION,
            true
        );
        
        // 传递数据到 JavaScript
        wp_localize_script(
            'spamjudge-admin',
            'SpamJudge',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'spamjudge_nonce' ),
                'strings' => array(
                    'processing' => __( '处理中...', 'spamjudge' ),
                    'clearAllLogs' => __( '清空所有日志', 'spamjudge' ),
                    'validationFailed' => __( '表单验证失败：', 'spamjudge' ),
                    'apiEndpointEmpty' => __( 'API 端点不能为空', 'spamjudge' ),
                    'apiEndpointInvalid' => __( 'API 端点必须是有效的 URL', 'spamjudge' ),
                    'apiKeyEmpty' => __( 'API 密钥不能为空', 'spamjudge' ),
                    'modelIdEmpty' => __( '模型 ID 不能为空', 'spamjudge' ),
                    'systemPromptEmpty' => __( '系统提示词不能为空', 'spamjudge' ),
                    'thresholdInvalid' => __( '分数阈值必须在 0-100 之间', 'spamjudge' ),
                    'timeoutInvalid' => __( '超时时间必须至少为 5 秒', 'spamjudge' ),
                ),
            )
        );
    }
    
    /**
     * 渲染管理页面
     */
    public function render_admin_page() {
        // 检查用户权限
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( '您没有权限访问此页面', 'spamjudge' ) );
        }
        
        // 获取当前标签页（仅用于显示，无状态变更）
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading view-only tab selector from $_GET
        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'logs';
        // 白名单校验，避免任意参数导致意外行为
        $active_tab = in_array( $active_tab, array( 'logs', 'settings' ), true ) ? $active_tab : 'logs';
        
        ?>
        <div class="wrap spamjudge-wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            
            <!-- 标签页导航 -->
            <h2 class="nav-tab-wrapper">
                <a href="?page=spamjudge&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( '日志', 'spamjudge' ); ?>
                </a>
                <a href="?page=spamjudge&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( '设置', 'spamjudge' ); ?>
                </a>
            </h2>
            
            <!-- 标签页内容 -->
            <div class="tab-content">
                <?php
                if ( $active_tab === 'logs' ) {
                    $this->render_logs_tab();
                } else {
                    $this->render_settings_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * 渲染日志标签页
     */
    private function render_logs_tab() {
        $logger = new SpamJudge_Comment_Logger();

        // 获取当前页码（仅用于显示，无状态变更）
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading pagination from $_GET for display only
        $current_page = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
        $per_page = 20;

        // 获取日志
        $logs = $logger->get_logs( $current_page, $per_page );
        $total_logs = $logger->get_total_logs();
        $total_pages = ceil( $total_logs / $per_page );

        ?>
        <div class="spamjudge-logs">
            
            <!-- 日志表格 -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 16.66%;"><?php esc_html_e( '评论者', 'spamjudge' ); ?></th>
                        <th style="width: 16.66%;"><?php esc_html_e( '评论内容', 'spamjudge' ); ?></th>
                        <th style="width: 16.66%;"><?php esc_html_e( 'API 状态码', 'spamjudge' ); ?></th>
                        <th style="width: 16.66%;"><?php esc_html_e( 'AI 评分', 'spamjudge' ); ?></th>
                        <th style="width: 16.66%;"><?php esc_html_e( '执行操作', 'spamjudge' ); ?></th>
                        <th style="width: 16.66%;"><?php esc_html_e( '时间', 'spamjudge' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $logs ) ) : ?>
                        <tr>
                            <td colspan="6" style="text-align: center;">
                                <?php esc_html_e( '暂无日志记录', 'spamjudge' ); ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $logs as $log ) : ?>
                            <tr>
                                <td>
                                    <div class="comment-author-preview">
                                        <?php echo esc_html( $log['comment_author'] ); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="comment-content-preview">
                                        <?php echo esc_html( $log['comment_content'] ); ?>
                                    </div>
                                </td>
                                <td><?php echo $log['api_status_code'] !== null ? esc_html( $log['api_status_code'] ) : '-'; ?></td>
                                <td><?php echo $log['ai_score'] !== null ? esc_html( $log['ai_score'] ) : '-'; ?></td>
                                <td>
                                    <?php
                                    $action_labels = array(
                                        'spam' => __( '垃圾', 'spamjudge' ),
                                        'approved' => __( '通过', 'spamjudge' ),
                                        'hold' => __( '待审核', 'spamjudge' ),
                                    );
                                    $action = sanitize_text_field( $log['action_taken'] );
                                    echo esc_html( isset( $action_labels[ $action ] ) ? $action_labels[ $action ] : $action );
                                    ?>
                                </td>
                                <td>
                                    <div class="comment-time-preview">
                                        <?php echo esc_html( $log['created_at'] ); ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- 底部操作与分页 -->
            <div class="tablenav">
                <div class="tablenav-left">
                    <button type="button" class="button button-secondary" id="clear-logs-btn">
                        <?php esc_html_e( '清空所有日志', 'spamjudge' ); ?>
                    </button>
                </div>
                <div class="tablenav-pages">
                    <?php if ( $total_pages > 1 ) : ?>
                        <?php
                        echo wp_kses_post( paginate_links( array(
                            'base' => add_query_arg( 'paged', '%#%' ),
                            'format' => '',
                            'prev_text' => __( '&lsaquo;', 'spamjudge' ),
                            'next_text' => __( '&rsaquo;', 'spamjudge' ),
                            'total' => $total_pages,
                            'current' => $current_page,
                        ) ) );
                        ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * 渲染设置标签页
     */
    private function render_settings_tab() {
        // 获取当前设置
        $settings = get_option( 'spamjudge_settings', array() );
        
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'spamjudge_settings_group' ); ?>
            
            <table class="form-table">
                <!-- API 端点 -->
                <tr>
                    <th scope="row">
                        <label for="api_endpoint"><?php esc_html_e( 'API 端点', 'spamjudge' ); ?></label>
                    </th>
                    <td>
                        <input type="url" id="api_endpoint" name="spamjudge_settings[api_endpoint]" 
                               value="<?php echo esc_attr( $settings['api_endpoint'] ?? '' ); ?>" 
                               class="regular-text" required>
                        <p class="description">
                            <?php esc_html_e( 'OpenAI 格式的 API 端点地址，自动补全 /v1/chat/completions，/ 结尾忽略 v1，# 结尾不补全', 'spamjudge' ); ?>
                        </p>
                    </td>
                </tr>
                
                <!-- API 密钥 -->
                <tr>
                    <th scope="row">
                        <label for="api_key"><?php esc_html_e( 'API 密钥', 'spamjudge' ); ?></label>
                    </th>
                    <td>
                        <div class="sj-password-wrapper">
                            <input type="password" id="api_key" name="spamjudge_settings[api_key]" 
                                   value="<?php echo esc_attr( $settings['api_key'] ?? '' ); ?>" 
                                   class="regular-text" required>
                            <button type="button" class="sj-toggle-password" aria-label="<?php esc_attr_e( '切换密钥可见性', 'spamjudge' ); ?>">
                                <span class="dashicons dashicons-hidden"></span>
                            </button>
                        </div>
                        <p class="description">
                            <?php esc_html_e( 'API 访问密钥', 'spamjudge' ); ?>
                        </p>
                    </td>
                </tr>
                
                <!-- 模型 ID -->
                <tr>
                    <th scope="row">
                        <label for="model_id"><?php esc_html_e( '模型 ID', 'spamjudge' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="model_id" name="spamjudge_settings[model_id]" 
                               value="<?php echo esc_attr( $settings['model_id'] ?? '' ); ?>" 
                               class="regular-text" required>
                        <p class="description">
                            <?php esc_html_e( '要使用的 AI 模型 ID', 'spamjudge' ); ?>
                        </p>
                    </td>
                </tr>
                
                <!-- 系统提示词 -->
                <tr>
                    <th scope="row">
                        <label for="system_prompt"><?php esc_html_e( '系统提示词', 'spamjudge' ); ?></label>
                    </th>
                    <td>
                        <textarea id="system_prompt" name="spamjudge_settings[system_prompt]" 
                                  rows="5" class="large-text" required><?php echo esc_textarea( $settings['system_prompt'] ?? '' ); ?></textarea>
                        <p class="description">
                            <?php esc_html_e( '发送给 AI 的系统提示词，用于指导 AI 如何评分', 'spamjudge' ); ?>
                        </p>
                    </td>
                </tr>
                
                <!-- 分数阈值 -->
                <tr>
                    <th scope="row">
                        <label for="score_threshold"><?php esc_html_e( '分数阈值', 'spamjudge' ); ?></label>
                    </th>
                    <td>
                        <input type="number" id="score_threshold" name="spamjudge_settings[score_threshold]"
                               value="<?php echo esc_attr( $settings['score_threshold'] ?? 40 ); ?>"
                               min="0" max="100" required>
                        <p class="description">
                            <?php esc_html_e( '低于此分数的评论将被标记为垃圾评论（0-100）', 'spamjudge' ); ?>
                        </p>
                    </td>
                </tr>

                <!-- 检测到垃圾评论后的操作 -->
                <tr>
                    <th scope="row">
                        <label for="spam_action"><?php esc_html_e( '检测到垃圾评论后的操作', 'spamjudge' ); ?></label>
                    </th>
                    <td>
                        <select id="spam_action" name="spamjudge_settings[spam_action]">
                            <option value="spam" <?php selected( $settings['spam_action'] ?? 'spam', 'spam' ); ?>>
                                <?php esc_html_e( '移到垃圾队列', 'spamjudge' ); ?>
                            </option>
                            <option value="hold" <?php selected( $settings['spam_action'] ?? 'spam', 'hold' ); ?>>
                                <?php esc_html_e( '移到待审核队列', 'spamjudge' ); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e( '当评论分数低于阈值时的处理方式', 'spamjudge' ); ?>
                        </p>
                    </td>
                </tr>

                <!-- 超时时间 -->
                <tr>
                    <th scope="row">
                        <label for="timeout"><?php esc_html_e( '超时时间（秒）', 'spamjudge' ); ?></label>
                    </th>
                    <td>
                        <input type="number" id="timeout" name="spamjudge_settings[timeout]" 
                               value="<?php echo esc_attr( $settings['timeout'] ?? 30 ); ?>" 
                               min="5" required>
                        <p class="description">
                            <?php esc_html_e( 'API 请求超时时间', 'spamjudge' ); ?>
                        </p>
                    </td>
                </tr>
                
                <!-- 超时后的操作 -->
                <tr>
                    <th scope="row">
                        <label for="timeout_action"><?php esc_html_e( '超时后的操作', 'spamjudge' ); ?></label>
                    </th>
                    <td>
                        <select id="timeout_action" name="spamjudge_settings[timeout_action]">
                            <option value="hold" <?php selected( $settings['timeout_action'] ?? 'hold', 'hold' ); ?>>
                                <?php esc_html_e( '移到待审核队列', 'spamjudge' ); ?>
                            </option>
                            <option value="approve" <?php selected( $settings['timeout_action'] ?? 'hold', 'approve' ); ?>>
                                <?php esc_html_e( '直接通过', 'spamjudge' ); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e( '当 API 超时或返回无效数据时的处理方式', 'spamjudge' ); ?>
                        </p>
                    </td>
                </tr>
                
                <!-- 日志保留时间 -->
                <tr>
                    <th scope="row">
                        <label for="log_retention"><?php esc_html_e( '日志保留时间', 'spamjudge' ); ?></label>
                    </th>
                    <td>
                        <select id="log_retention" name="spamjudge_settings[log_retention]">
                            <option value="0" <?php selected( $settings['log_retention'] ?? 30, 0 ); ?>>
                                <?php esc_html_e( '不保存', 'spamjudge' ); ?>
                            </option>
                            <option value="1" <?php selected( $settings['log_retention'] ?? 30, 1 ); ?>>
                                <?php esc_html_e( '1天', 'spamjudge' ); ?>
                            </option>
                            <option value="3" <?php selected( $settings['log_retention'] ?? 30, 3 ); ?>>
                                <?php esc_html_e( '3天', 'spamjudge' ); ?>
                            </option>
                            <option value="7" <?php selected( $settings['log_retention'] ?? 30, 7 ); ?>>
                                <?php esc_html_e( '7天', 'spamjudge' ); ?>
                            </option>
                            <option value="14" <?php selected( $settings['log_retention'] ?? 30, 14 ); ?>>
                                <?php esc_html_e( '14天', 'spamjudge' ); ?>
                            </option>
                            <option value="30" <?php selected( $settings['log_retention'] ?? 30, 30 ); ?>>
                                <?php esc_html_e( '30天', 'spamjudge' ); ?>
                            </option>
                            <option value="90" <?php selected( $settings['log_retention'] ?? 30, 90 ); ?>>
                                <?php esc_html_e( '90天', 'spamjudge' ); ?>
                            </option>
                            <option value="180" <?php selected( $settings['log_retention'] ?? 30, 180 ); ?>>
                                <?php esc_html_e( '180天', 'spamjudge' ); ?>
                            </option>
                            <option value="365" <?php selected( $settings['log_retention'] ?? 30, 365 ); ?>>
                                <?php esc_html_e( '365天', 'spamjudge' ); ?>
                            </option>
                            <option value="-1" <?php selected( $settings['log_retention'] ?? 30, -1 ); ?>>
                                <?php esc_html_e( '永久保存', 'spamjudge' ); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e( '日志保留时长，过期日志将自动删除', 'spamjudge' ); ?>
                        </p>
                    </td>
                </tr>

                <!-- 检测为垃圾评论后对访客的提醒 -->
                <tr>
                    <th scope="row">
                        <label for="spam_message"><?php esc_html_e( '检测为垃圾评论后对访客的提醒', 'spamjudge' ); ?></label>
                    </th>
                    <td>
                        <textarea id="spam_message" name="spamjudge_settings[spam_message]"
                                  rows="3" class="large-text"><?php echo esc_textarea( $settings['spam_message'] ?? '' ); ?></textarea>
                        <p class="description">
                            <?php esc_html_e( '当评论被AI检测为垃圾评论时显示给访客的提醒文字，留空则不提醒', 'spamjudge' ); ?>
                        </p>
                    </td>
                </tr>

                <!-- 检测失败后对访客的提醒 -->
                <tr>
                    <th scope="row">
                        <label for="error_message"><?php esc_html_e( '检测失败后对访客的提醒', 'spamjudge' ); ?></label>
                    </th>
                    <td>
                        <textarea id="error_message" name="spamjudge_settings[error_message]"
                                  rows="3" class="large-text"><?php echo esc_textarea( $settings['error_message'] ?? '' ); ?></textarea>
                        <p class="description">
                            <?php esc_html_e( '当AI检测失败（API错误、超时等）时显示给访客的提醒文字，留空则不提醒', 'spamjudge' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
        <?php
    }
    
    /**
     * AJAX 清空日志
     */
    public function ajax_clear_logs() {
        // 验证 nonce，防止CSRF攻击
        check_ajax_referer( 'spamjudge_nonce', 'nonce' );

        // 检查用户权限，确保只有管理员可以清空日志
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }

        // 清空日志
        $logger = new SpamJudge_Comment_Logger();
        $result = $logger->clear_all_logs();

        // 返回结果，不包含任何消息
        if ( $result ) {
            wp_send_json_success();
        } else {
            wp_send_json_error();
        }
    }
    
    /**
     * AJAX 获取日志
     */
    public function ajax_get_logs() {
        // 验证 nonce
        check_ajax_referer( 'spamjudge_nonce', 'nonce' );
        
        // 检查权限
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'spamjudge' ) ) );
        }
        
        // 获取页码
        // Nonce 已在上方 check_ajax_referer 校验
        $page = isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1;
        
        // 获取日志
        $logger = new SpamJudge_Comment_Logger();
        $logs = $logger->get_logs( $page, 20 );
        
        wp_send_json_success( array( 'logs' => $logs ) );
    }
}

