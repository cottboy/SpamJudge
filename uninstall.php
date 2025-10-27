<?php
/**
 * 插件卸载清理脚本
 *
 * 当插件被卸载时，清理所有数据，不留任何垃圾
 *
 * @package SpamJudge
 */

// 如果不是通过 WordPress 卸载，则退出
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// 删除插件选项
delete_option( 'spamjudge_settings' );

// 删除日志表（包含所有日志数据）
$table_name = $wpdb->prefix . 'spamjudge_logs';
// 卸载时删除自定义表（WordPress 官方文档推荐做法）
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 卸载时清理自定义表，表名固定安全
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

// 清除定时任务
wp_clear_scheduled_hook( 'spamjudge_cleanup_logs' );

// 删除所有临时数据（transients）
// 卸载时清理插件相关的 transients（一次性操作无需缓存）
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 卸载时清理数据，options 表引用安全
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_spamjudge_%' OR option_name LIKE '_transient_timeout_spamjudge_%'"
);

// 删除所有可能的插件相关选项（确保清理干净）
// 卸载时清理插件相关的 options（一次性操作无需缓存）
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 卸载时清理数据，options 表引用安全
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'spamjudge_%'"
);

// 清理 WordPress 对象缓存
wp_cache_flush();

