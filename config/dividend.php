<?php

/**
 * 分红系统配置文件
 */
return [
    // 基础配置
    'basic' => [
        // 用户分红比例
        'user_ratio' => 0.5,
        // 商家分红比例
        'merchant_ratio' => 0.5,
        // 最小分红金额（元）
        'min_dividend_amount' => 0.01,
        // 最小参与金额（元）
        'min_participate_amount' => 100,
        // 最小积分要求
        'min_integral_required' => 1,
    ],
    
    // 缓存配置
    'cache' => [
        // 缓存过期时间（秒）
        'expire_time' => 3600,
        // 缓存键前缀
        'prefix' => 'dividend:',
        // 是否启用缓存
        'enabled' => true,
        // 预热缓存
        'warmup_enabled' => true,
    ],
    
    // 异步处理配置
    'async' => [
        // 是否启用异步处理
        'enabled' => true,
        // 批处理大小
        'batch_size' => 100,
        // 队列名称
        'queue_name' => 'dividend',
        // 最大重试次数
        'max_retries' => 3,
        // 重试延迟（秒）
        'retry_delay' => 60,
        // 任务超时时间（秒）
        'timeout' => 300,
    ],
    
    // 监控告警配置
    'monitor' => [
        // 是否启用监控
        'enabled' => true,
        // 最大处理时间（秒）
        'max_processing_time' => 3600,
        // 最大失败率
        'max_failed_rate' => 0.05,
        // 最小成功率
        'min_success_rate' => 0.95,
        // 金额异常阈值（元）
        'amount_threshold' => 10000,
        // 队列积压阈值
        'queue_backlog_threshold' => 10,
        // 告警通知方式
        'alert_methods' => ['log', 'email'],
        // 邮件通知配置
        'email_config' => [
            'to' => ['admin@example.com'],
            'subject_prefix' => '[分红系统告警]',
        ],
    ],
    
    // 数据对账配置
    'reconcile' => [
        // 是否启用自动对账
        'auto_enabled' => true,
        // 对账时间（每天几点执行）
        'schedule_hour' => 2,
        // 对账保留天数
        'retention_days' => 30,
        // 差异阈值（元）
        'discrepancy_threshold' => 0.01,
    ],
    
    // 性能优化配置
    'performance' => [
        // 数据库连接池大小
        'db_pool_size' => 10,
        // 查询超时时间（秒）
        'query_timeout' => 30,
        // 是否启用查询缓存
        'query_cache_enabled' => true,
        // 大数据量处理阈值
        'large_data_threshold' => 1000,
        // 分页大小
        'page_size' => 100,
    ],
    
    // 安全配置
    'security' => [
        // 是否启用操作日志
        'audit_log_enabled' => true,
        // 敏感操作需要二次确认
        'require_confirmation' => true,
        // IP白名单（空数组表示不限制）
        'ip_whitelist' => [],
        // 操作频率限制（次/分钟）
        'rate_limit' => 60,
        // 金额精度（小数位数）
        'amount_precision' => 2,
    ],
    
    // 日志配置
    'logging' => [
        // 日志级别
        'level' => 'info',
        // 日志文件路径
        'file_path' => 'dividend.log',
        // 日志保留天数
        'retention_days' => 30,
        // 是否记录详细信息
        'detailed' => true,
        // 敏感信息脱敏
        'mask_sensitive' => true,
    ],
    
    // 通知配置
    'notification' => [
        // 分红完成通知
        'dividend_complete' => [
            'enabled' => true,
            'methods' => ['sms', 'push'],
            'template' => '您的分红已到账，金额：{amount}元',
        ],
        // 分红失败通知
        'dividend_failed' => [
            'enabled' => true,
            'methods' => ['email'],
            'template' => '分红处理失败，请及时处理',
        ],
    ],
    
    // 定时任务配置
    'schedule' => [
        // 分红执行时间（cron表达式）
        'dividend_cron' => '0 2 * * *', // 每天凌晨2点
        // 月初分红时间
        'monthly_dividend_cron' => '0 3 1 * *', // 每月1号凌晨3点
        // 缓存清理时间
        'cache_cleanup_cron' => '0 4 * * *', // 每天凌晨4点
        // 数据对账时间
        'reconcile_cron' => '0 5 * * *', // 每天凌晨5点
    ],
    
    // 数据库配置
    'database' => [
        // 表前缀
        'prefix' => 'eb_',
        // 主要表名
        'tables' => [
            'dividend_pool' => 'dividend_pool',
            'dividend_period_log' => 'dividend_period_log',
            'dividend_execute_log' => 'dividend_execute_log',
            'dividend_failed_jobs' => 'dividend_failed_jobs',
            'user_bill' => 'user_bill',
            'merchant_bill' => 'merchant_bill',
            'store_order_offline' => 'store_order_offline',
        ],
        // 索引配置
        'indexes' => [
            'dividend_execute_log' => ['dp_id', 'uid', 'mer_id', 'create_time'],
            'store_order_offline' => ['city_id', 'paid', 'refund_status'],
        ],
    ],
    
    // 环境配置
    'environment' => [
        // 开发环境配置
        'development' => [
            'debug' => true,
            'log_level' => 'debug',
            'cache_enabled' => false,
            'async_enabled' => false,
        ],
        // 测试环境配置
        'testing' => [
            'debug' => true,
            'log_level' => 'info',
            'cache_enabled' => true,
            'async_enabled' => true,
        ],
        // 生产环境配置
        'production' => [
            'debug' => false,
            'log_level' => 'warning',
            'cache_enabled' => true,
            'async_enabled' => true,
        ],
    ],
];