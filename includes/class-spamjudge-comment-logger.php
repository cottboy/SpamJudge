<?php
/**
 * 评论日志记录类
 * 
 * 负责记录和管理评论审核日志
 *
 * @package SpamJudge
 */

// 如果直接访问此文件，则退出
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 评论日志记录类
 */
class SpamJudge_Comment_Logger {
    
    /**
     * 日志表名
     *
     * @var string
     */
    private $table_name;
    
    /**
     * 构造函数
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'spamjudge_logs';
    }
    
    /**
     * 记录日志
     *
     * @param string $comment_author 评论者名称
     * @param string $comment_content 评论内容
     * @param int    $api_status_code API 响应状态码
     * @param int    $ai_score AI 评分
     * @param string $action_taken 执行的操作
     * @return bool 是否成功
     */
    public function log( $comment_author, $comment_content, $api_status_code, $ai_score, $action_taken ) {
        global $wpdb;

        // 验证输入
        $comment_author = sanitize_text_field( $comment_author );
        $comment_content = sanitize_textarea_field( $comment_content );
        $api_status_code = $api_status_code !== null ? absint( $api_status_code ) : null;
        $ai_score = $ai_score !== null ? absint( $ai_score ) : null;
        $action_taken = sanitize_text_field( $action_taken );

        // 插入日志到自定义表（WordPress 核心 API 不支持自定义表）
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- 自定义表必须使用直接查询
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'comment_author' => $comment_author,
                'comment_content' => $comment_content,
                'api_status_code' => $api_status_code,
                'ai_score' => $ai_score,
                'action_taken' => $action_taken,
                'created_at' => current_time( 'mysql' ),
            ),
            array(
                '%s', // comment_author
                '%s', // comment_content
                '%d', // api_status_code
                '%d', // ai_score
                '%s', // action_taken
                '%s', // created_at
            )
        );

        return $result !== false;
    }
    
    /**
     * 获取日志列表
     *
     * @param int $page 页码
     * @param int $per_page 每页数量
     * @return array 日志列表
     */
    public function get_logs( $page = 1, $per_page = 20 ) {
        global $wpdb;
        
        // 验证输入
        $page = max( 1, absint( $page ) );
        $per_page = max( 1, min( 100, absint( $per_page ) ) ); // 限制最大每页 100 条
        
        $offset = ( $page - 1 ) * $per_page;
        
        // 使用预处理语句防止 SQL 注入；表名为固定前缀+固定后缀，已知安全
        // 自定义表无 WP API，实时数据不适合缓存
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自定义表必须直接查询，日志数据实时变化不宜缓存
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名固定安全（prefix + 固定后缀）
                "SELECT * FROM {$this->table_name} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        return $logs ? $logs : array();
    }
    
    /**
     * 获取日志总数
     *
     * @return int 日志总数
     */
    public function get_total_logs() {
        global $wpdb;
        
        // 自定义表无 WP API，COUNT 查询不适合缓存
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 自定义表必须直接查询，COUNT 实时计算，表名固定安全
        $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" );

        return absint( $count );
    }
    
    /**
     * 清空所有日志
     *
     * @return bool 是否成功
     */
    public function clear_all_logs() {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is fixed/validated
        $result = $wpdb->query( "TRUNCATE TABLE {$this->table_name}" );

        return $result !== false;
    }
    
    /**
     * 清理过期日志
     *
     * @return bool 是否成功
     */
    public function cleanup_old_logs() {
        global $wpdb;
        
        // 获取日志保留设置
        $settings = get_option( 'spamjudge_settings', array() );
        $retention_days = isset( $settings['log_retention'] ) ? intval( $settings['log_retention'] ) : 30;
        
        // 0 表示不保存，-1 表示永久保存
        if ( $retention_days === 0 ) {
            // 不保存日志，清空所有
            return $this->clear_all_logs();
        } elseif ( $retention_days === -1 ) {
            // 永久保存，不清理
            return true;
        }
        
        // 计算过期时间（使用 GMT 避免受运行时时区影响）
        $expiry_timestamp = time() - ( $retention_days * DAY_IN_SECONDS );
        $expiry_date = gmdate( 'Y-m-d H:i:s', $expiry_timestamp );
        
        // 删除过期日志（自定义表无 WP API，DELETE 操作无需缓存）
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自定义表必须直接查询，DELETE 操作不涉及缓存
        $result = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名固定安全（prefix + 固定后缀）
                "DELETE FROM {$this->table_name} WHERE created_at < %s",
                $expiry_date
            )
        );
        
        return $result !== false;
    }
    

}

