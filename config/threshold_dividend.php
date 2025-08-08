<?php
// +----------------------------------------------------------------------
// | 阈值补贴配置文件
// +----------------------------------------------------------------------

return [
    // 基础配置
    'basic' => [
        // 增长率阈值（15%）
        'growth_rate' => 0.15,
        
        // 手续费分红比例（40%）
        'dividend_rate' => 0.40,
        
        // 默认初始阈值金额
        'default_initial_threshold' => 1000.00,
        
        // 最小触发金额
        'min_trigger_amount' => 100.00,
        
        // 最大单次补贴金额
        'max_single_dividend' => 50000.00,
    ],
    
    // 补贴分配配置
    'allocation' => [
        // 团长补贴比例
        'team_leader_rate' => 0.60,
        
        // 积分补贴比例
        'integral_rate' => 0.40,
        
        // 团长补贴单价（元/人）
        'team_leader_unit_price' => 10.00,
        
        // 积分补贴单价（元/人）
        'integral_unit_price' => 5.00,
    ],
    
    // 执行控制配置
    'execution' => [
        // 是否启用阈值补贴
        'enabled' => true,
        
        // 单日最大补贴次数
        'max_daily_dividends' => 10,
        
        // 补贴间隔时间（分钟）
        'dividend_interval_minutes' => 30,
        
        // 是否启用异步处理
        'async_processing' => true,
        
        // 批处理大小
        'batch_size' => 1000,
    ],
    
    // 安全配置
    'security' => [
        // 是否启用重复检查
        'duplicate_check' => true,
        
        // 事务超时时间（秒）
        'transaction_timeout' => 300,
        
        // 最大重试次数
        'max_retry_times' => 3,
        
        // 错误阈值（连续失败次数）
        'error_threshold' => 5,
    ],
    
    // 日志配置
    'logging' => [
        // 是否启用详细日志
        'detailed_logging' => true,
        
        // 日志保留天数
        'log_retention_days' => 90,
        
        // 是否记录性能指标
        'performance_logging' => true,
        
        // 慢查询阈值（毫秒）
        'slow_query_threshold' => 1000,
    ],
    
    // 缓存配置
    'cache' => [
        // 是否启用缓存
        'enabled' => true,
        
        // 分红池状态缓存时间（秒）
        'pool_status_ttl' => 300,
        
        // 团队成员缓存时间（秒）
        'team_members_ttl' => 1800,
        
        // 用户积分缓存时间（秒）
        'user_integral_ttl' => 600,
    ],
    
    // 通知配置
    'notification' => [
        // 是否启用通知
        'enabled' => true,
        
        // 通知方式：email, sms, webhook
        'methods' => ['email', 'webhook'],
        
        // 通知阈值（补贴金额）
        'amount_threshold' => 10000.00,
        
        // Webhook URL
        'webhook_url' => '',
        
        // 邮件接收者
        'email_recipients' => [
            'admin@example.com',
            'finance@example.com'
        ],
    ],
    
    // 监控配置
    'monitoring' => [
        // 是否启用监控
        'enabled' => true,
        
        // 监控指标
        'metrics' => [
            'execution_time',
            'success_rate',
            'error_count',
            'dividend_amount',
            'pool_growth_rate'
        ],
        
        // 告警阈值
        'alert_thresholds' => [
            'execution_time_ms' => 30000,
            'error_rate_percent' => 5.0,
            'pool_growth_rate_percent' => 50.0
        ],
    ],
    
    // 数据库配置
    'database' => [
        // 表前缀
        'table_prefix' => 'eb_',
        
        // 是否启用读写分离
        'read_write_separation' => false,
        
        // 查询超时时间（秒）
        'query_timeout' => 30,
        
        // 连接池大小
        'connection_pool_size' => 10,
    ],
    
    // API配置
    'api' => [
        // 请求频率限制（次/分钟）
        'rate_limit' => 60,
        
        // API版本
        'version' => 'v1',
        
        // 响应格式
        'response_format' => 'json',
        
        // 是否启用API文档
        'documentation_enabled' => true,
    ],
    
    // 测试配置
    'testing' => [
        // 是否为测试环境
        'is_test_env' => false,
        
        // 测试数据前缀
        'test_data_prefix' => 'test_',
        
        // 模拟延迟（毫秒）
        'simulate_delay_ms' => 0,
        
        // 是否启用调试模式
        'debug_mode' => false,
    ],
    
    // 城市配置
    'cities' => [
        // 默认城市ID
        'default_city_id' => 1,
        
        // 支持的城市列表（可选，为空则支持所有城市）
        'supported_cities' => [],
        
        // 城市特殊配置
        'city_specific' => [
            // 示例：北京的特殊配置
            // 1 => [
            //     'growth_rate' => 0.20,
            //     'team_leader_rate' => 0.70,
            // ]
        ],
    ],
    
    // 时间配置
    'timing' => [
        // 时区
        'timezone' => 'Asia/Shanghai',
        
        // 工作时间（24小时制）
        'working_hours' => [
            'start' => '09:00',
            'end' => '18:00'
        ],
        
        // 是否只在工作时间执行
        'working_hours_only' => false,
        
        // 节假日是否执行
        'execute_on_holidays' => true,
    ],
];