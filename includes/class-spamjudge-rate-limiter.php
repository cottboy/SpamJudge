<?php
/**
 * 限流器类（令牌桶，惰性计算）
 *
 * 按字符数统计插件向 AI 发送的输入字符（系统提示词 + 插件拼接模板 + 评论者名称 + 评论内容），
 * 一个英文字母与一个中文文字均计为一个字符。
 *
 * 固定部分（系统提示词 + 拼接模板）通过 Transients 缓存，系统提示词变更时刷新；
 * 动态部分（评论者名称 + 评论内容）每次请求按实际长度计算。
 *
 * @package SpamJudge
 */

// 如果直接访问此文件，则退出
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 限流器类
 */
class SpamJudge_Rate_Limiter {

    /**
     * 令牌桶状态存储的选项名
     */
    const BUCKET_OPTION = 'spamjudge_rate_bucket';

    /**
     * 固定字符数缓存的 Transient 键名
     */
    const FIXED_TRANSIENT = 'spamjudge_fixed_chars';

    /**
     * 用户消息拼接模板（与 API 客户端保持一致）
     */
    const TEMPLATE_FORMAT = "Commenter Name: %s\nComment Content: %s";

    /**
     * 获取限流时间窗口可选项
     *
     * @return array 以秒数为键、显示标签为值的数组
     */
    public static function get_window_choices() {
        return array(
            1800    => __( '30分钟', 'spamjudge' ),
            3600    => __( '1小时', 'spamjudge' ),
            14400   => __( '4小时', 'spamjudge' ),
            43200   => __( '12小时', 'spamjudge' ),
            86400   => __( '1天', 'spamjudge' ),
            259200  => __( '3天', 'spamjudge' ),
            604800  => __( '7天', 'spamjudge' ),
            1209600 => __( '14天', 'spamjudge' ),
            2592000 => __( '1个月', 'spamjudge' ),
        );
    }

    /**
     * 获取默认时间窗口（秒）
     *
     * @return int
     */
    public static function get_default_window() {
        return 86400;
    }

    /**
     * 获取默认字符数限制
     *
     * @return int
     */
    public static function get_default_chars() {
        return -1;
    }

    /**
     * 从设置中获取时间窗口（秒），非法值回退为默认值
     *
     * @param array $settings 设置数组
     * @return int
     */
    public static function get_window_seconds( $settings ) {
        $window = isset( $settings['rate_limit_window'] ) ? intval( $settings['rate_limit_window'] ) : self::get_default_window();
        $choices = array( 1800, 3600, 14400, 43200, 86400, 259200, 604800, 1209600, 2592000 );

        if ( ! in_array( $window, $choices, true ) ) {
            return self::get_default_window();
        }

        return $window;
    }

    /**
     * 从设置中获取字符数限制
     *
     * -1 表示无限制，0 表示暂停所有请求，正数表示配额
     *
     * @param array $settings 设置数组
     * @return int
     */
    public static function get_limit_chars( $settings ) {
        if ( ! isset( $settings['rate_limit_chars'] ) ) {
            return self::get_default_chars();
        }

        $limit = intval( $settings['rate_limit_chars'] );

        // 小于 -1 的非法值统一视为无限制
        if ( $limit < -1 ) {
            return -1;
        }

        return $limit;
    }

    /**
     * 按字符计数（一个英文字母与一个中文文字均计为一个字符）
     *
     * @param string $str 输入字符串
     * @return int
     */
    public static function mb_strlen( $str ) {
        if ( function_exists( 'mb_strlen' ) ) {
            return mb_strlen( $str, 'UTF-8' );
        }

        // 无 mbstring 扩展时的降级方案：按 Unicode 码点计数
        $count = preg_match_all( '/./us', $str, $matches );

        return $count === false ? strlen( $str ) : $count;
    }

    /**
     * 计算固定部分字符数（系统提示词 + 拼接模板静态部分）
     *
     * @param string $system_prompt 系统提示词（已清理）
     * @return int
     */
    public static function calc_fixed_chars( $system_prompt ) {
        $prompt_len = self::mb_strlen( (string) $system_prompt );
        $template_len = self::mb_strlen( sprintf( self::TEMPLATE_FORMAT, '', '' ) );

        return $prompt_len + $template_len;
    }

    /**
     * 获取固定部分字符数（优先使用 Transients 缓存）
     *
     * @param string $system_prompt 系统提示词（已清理）
     * @return int
     */
    public static function get_fixed_chars( $system_prompt ) {
        $cached = get_transient( self::FIXED_TRANSIENT );

        if ( $cached !== false ) {
            return max( 0, intval( $cached ) );
        }

        $fixed = self::calc_fixed_chars( $system_prompt );
        set_transient( self::FIXED_TRANSIENT, $fixed, 0 );

        return $fixed;
    }

    /**
     * 刷新固定部分字符数缓存（系统提示词变更时调用）
     *
     * @param string $system_prompt 系统提示词（已清理）
     * @return int 新的固定字符数
     */
    public static function refresh_fixed_cache( $system_prompt ) {
        $fixed = self::calc_fixed_chars( $system_prompt );
        set_transient( self::FIXED_TRANSIENT, $fixed, 0 );

        return $fixed;
    }

    /**
     * 估算本次请求需要的字符数
     *
     * @param string $system_prompt 系统提示词（原始值，内部会做与 API 客户端相同的清理）
     * @param string $comment_author 评论者名称（原始值）
     * @param string $comment_content 评论内容（原始值）
     * @return int
     */
    public static function estimate_need( $system_prompt, $comment_author, $comment_content ) {
        $system_prompt = sanitize_textarea_field( $system_prompt );
        $author = sanitize_text_field( $comment_author );
        $content = sanitize_textarea_field( $comment_content );

        $fixed = self::get_fixed_chars( $system_prompt );

        return $fixed + self::mb_strlen( $author ) + self::mb_strlen( $content );
    }

    /**
     * 尝试消费指定数量的字符（令牌桶，惰性计算）
     *
     * - 限制为 -1 时直接放行，不触碰桶状态
     * - 限制为 0 时直接拒绝，不触碰桶状态
     * - 桶状态缺失或配额/窗口变化时重置为满桶
     * - 每次调用按流逝时间惰性补充令牌：补充量 = 流逝秒数 * (配额 / 窗口)，补充后四舍五入取整
     *
     * @param int   $need_chars 本次请求需要的字符数
     * @param array $settings 设置数组，为空时从选项中加载
     * @param int|null $now 当前时间戳（秒），为空时使用 time()，仅供测试注入
     * @return bool true 表示允许请求并已扣减配额，false 表示配额不足应拒绝请求
     */
    public static function try_consume( $need_chars, $settings = null, $now = null ) {
        if ( $settings === null ) {
            $settings = get_option( 'spamjudge_settings', array() );
        }

        $limit = self::get_limit_chars( $settings );

        // 无限制：直接放行
        if ( $limit === -1 ) {
            return true;
        }

        // 暂停：拒绝所有请求
        if ( $limit === 0 ) {
            return false;
        }

        $need_chars = max( 0, intval( $need_chars ) );
        $window = self::get_window_seconds( $settings );
        $capacity = $limit;
        $now = $now === null ? time() : intval( $now );

        // 单次请求超过整个窗口配额时永远无法放行
        if ( $need_chars > $capacity ) {
            return false;
        }

        $bucket = get_option( self::BUCKET_OPTION, null );

        // 桶缺失、结构异常或配额/窗口变化时重置为满桶
        if ( ! is_array( $bucket )
            || ! isset( $bucket['tokens'], $bucket['updated_at'], $bucket['capacity'], $bucket['window'] )
            || intval( $bucket['capacity'] ) !== $capacity
            || intval( $bucket['window'] ) !== $window
        ) {
            $bucket = array(
                'tokens' => $capacity,
                'updated_at' => $now,
                'capacity' => $capacity,
                'window' => $window,
            );
        }

        // 历史桶中可能存有小数，读出时四舍五入取整
        $tokens = (int) round( (float) $bucket['tokens'] );
        $updated_at = intval( $bucket['updated_at'] );

        // 惰性补充令牌
        $elapsed = $now - $updated_at;
        if ( $elapsed < 0 ) {
            $elapsed = 0;
        }

        if ( $elapsed > 0 && $window > 0 ) {
            $rate = $capacity / $window;
            // 补充后四舍五入取整，令牌数始终为整数
            $tokens = (int) min( $capacity, round( $tokens + $elapsed * $rate ) );
        }

        // 配额不足：保存补充后的状态并拒绝
        if ( $tokens < $need_chars ) {
            update_option( self::BUCKET_OPTION, array(
                'tokens' => $tokens,
                'updated_at' => $now,
                'capacity' => $capacity,
                'window' => $window,
            ), false );

            return false;
        }

        // 配额充足：扣减并保存
        $tokens -= $need_chars;
        update_option( self::BUCKET_OPTION, array(
            'tokens' => $tokens,
            'updated_at' => $now,
            'capacity' => $capacity,
            'window' => $window,
        ), false );

        return true;
    }
}
