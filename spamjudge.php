<?php
/**
 * Plugin Name: SpamJudge
 * Description: Using AI large language models to automatically detect and filter spam comments
 * Version: 1.0.0
 * Author: cottboy
 * Author URI: https://www.joyfamily.top/
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: spamjudge
 * Domain Path: /languages
 */

// 如果直接访问此文件，则退出
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 定义插件常量
define( 'SPAMJUDGE_VERSION', '1.0.0' );
define( 'SPAMJUDGE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPAMJUDGE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SPAMJUDGE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * 插件激活时执行
 */
function spamjudge_activate() {
    global $wpdb;
    
    // 创建日志表
    $table_name = $wpdb->prefix . 'spamjudge_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        comment_author varchar(255) NOT NULL,
        comment_content text NOT NULL,
        api_status_code int(11) DEFAULT NULL,
        ai_score int(11) DEFAULT NULL,
        action_taken varchar(50) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        KEY created_at (created_at)
    ) $charset_collate;";
    
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
    
    // 设置默认选项
    $default_options = array(
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
        'score_threshold' => 40, // 评分阈值，AI评分低于此值则认为是垃圾评论
        'spam_action' => 'spam', // spam 或 hold - 检测到垃圾评论后的操作
        'timeout' => 30, // API请求超时时间（秒）
        'timeout_action' => 'hold', // hold 或 approve
        'log_retention' => 90, // 天数，0 表示不保存，-1 表示永久保存
        'spam_message' => '', // 检测为垃圾评论后对访客的提醒，为空则不提醒
        'error_message' => '', // 检测失败后对访客的提醒，为空则不提醒
    );
    
    add_option( 'spamjudge_settings', $default_options );
}
register_activation_hook( __FILE__, 'spamjudge_activate' );

// 自 WordPress 4.6 起，WordPress.org 托管插件会自动加载翻译，避免显式调用以通过插件检查工具。
// 若需在非 WordPress.org 环境手动加载翻译，可在自定义钩子中选择性调用 load_plugin_textdomain()。

/**
 * 加载插件核心文件
 */
require_once SPAMJUDGE_PLUGIN_DIR . 'includes/class-ai-api-client.php';
require_once SPAMJUDGE_PLUGIN_DIR . 'includes/class-comment-logger.php';
require_once SPAMJUDGE_PLUGIN_DIR . 'includes/class-spamjudge.php';

// 仅在管理后台加载管理类
if ( is_admin() ) {
    require_once SPAMJUDGE_PLUGIN_DIR . 'admin/class-admin-settings.php';
}

/**
 * 初始化插件
 */
function spamjudge_init() {
    // 初始化核心类
    SpamJudge::get_instance();
    
    // 初始化管理后台
    if ( is_admin() ) {
        SpamJudge_Admin_Settings::get_instance();
    }
}
add_action( 'init', 'spamjudge_init' );

/**
 * 定期清理过期日志
 */
function spamjudge_cleanup_logs() {
    $logger = new AI_Comment_Logger();
    $logger->cleanup_old_logs();
}
// 每天执行一次日志清理
if ( ! wp_next_scheduled( 'spamjudge_cleanup_logs' ) ) {
    wp_schedule_event( time(), 'daily', 'spamjudge_cleanup_logs' );
}
add_action( 'spamjudge_cleanup_logs', 'spamjudge_cleanup_logs' );

/**
 * 在插件列表页面添加设置链接
 *
 * @param array $links 现有的插件操作链接
 * @return array 修改后的链接数组
 */
function spamjudge_plugin_action_links( $links ) {
    // 创建设置链接，指向日志页面（默认标签页）
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        esc_url( admin_url( 'admin.php?page=spamjudge' ) ),
        esc_html__( '设置', 'spamjudge' )
    );

    // 将设置链接添加到数组开头
    array_unshift( $links, $settings_link );

    return $links;
}
add_filter( 'plugin_action_links_' . SPAMJUDGE_PLUGIN_BASENAME, 'spamjudge_plugin_action_links' );

