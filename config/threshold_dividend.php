<?php
// +----------------------------------------------------------------------
// | 阈值补贴配置文件
// +----------------------------------------------------------------------

return [
    // 基础配置
    'basic' => [
        // 阈值增长率（15%）
        'growth_rate' => 0.15,
        
        // 手续费分红比例（20%）
        'dividend_rate' => 0.20,
        
        // 首次触发增长率（115% = 1.15）
        'initial_trigger_rate' => 1.15,
        
        // 默认初始阈值金额（首次补贴的目标值）

        'default_initial_threshold' => 5000.00,
    ],
    
    // 补贴分配配置
    'allocation' => [
        // 团长补贴比例（30%）
        'team_leader_rate' => 0.30,
        
        // 积分补贴比例（70%）
        'integral_rate' => 0.70,
    ],
    
    // 执行控制配置
    'execution' => [
        // 是否启用阈值补贴
        'enabled' => true,
        
        // 是否启用异步处理
        'async_processing' => true,
    ],
    
    // 安全配置
    'security' => [
        // 锁等待超时时间（秒）
        'lock_wait_timeout' => 10,
        
        // 最大重试次数
        'max_retry_times' => 3,
    ],
    
    // 日志配置
    'logging' => [
        // 是否启用详细日志
        'detailed_logging' => true,
    ],
];