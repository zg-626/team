<?php
// +----------------------------------------------------------------------
// | 阈值补贴相关路由配置
// +----------------------------------------------------------------------

use think\facade\Route;

// 阈值补贴管理路由组
Route::group('api/dividend/threshold', function () {
    
    // 手动执行阈值补贴
    Route::post('execute', 'api.dividend.ThresholdDividends/execute');
    
    // 获取分红池状态
    Route::get('pools/status', 'api.dividend.ThresholdDividends/getPoolsStatus');
    
    // 获取单个分红池详情
    Route::get('pool/:id/detail', 'api.dividend.ThresholdDividends/getPoolDetail');
    
    // 获取阈值补贴历史记录
    Route::get('history', 'api.dividend.ThresholdDividends/getHistory');
    
    // 获取补贴统计数据
    Route::get('statistics', 'api.dividend.ThresholdDividends/getStatistics');
    
})->middleware(['auth', 'admin']); // 需要管理员权限

// 定时任务路由组
Route::group('api/dividend/cron', function () {
    
    // 定时检查阈值
    Route::post('check-thresholds', 'api.dividend.ThresholdCron/checkThresholds');
    
    // 获取状态报告
    Route::get('status-report', 'api.dividend.ThresholdCron/getStatusReport');
    
    // 健康检查
    Route::get('health', 'api.dividend.ThresholdCron/healthCheck');
    
    // 清理过期日志
    Route::post('cleanup-logs', 'api.dividend.ThresholdCron/cleanupLogs');
    
    // 获取执行统计
    Route::get('execution-stats', 'api.dividend.ThresholdCron/getExecutionStats');
    
})->middleware(['cron_auth']); // 定时任务专用认证

// 手动触发和测试路由组
Route::group('api/dividend/manual', function () {
    
    // 手动触发阈值检查
    Route::post('trigger-threshold', 'api.dividend.ThresholdDividends/execute')
        ->middleware(['auth', 'admin']);
    
    // 模拟订单支付（仅测试环境）
    Route::post('simulate-payment', 'api.dividend.ThresholdCron/simulatePayment')
        ->middleware(['auth', 'admin', 'test_env']);
    
})->middleware(['auth', 'admin']);

// 公开接口（无需认证）
Route::group('api/public/dividend', function () {
    
    // 获取分红池公开信息（不包含敏感数据）
    Route::get('pools/public', 'api.dividend.ThresholdDividends/getPublicPoolInfo');
    
    // 获取补贴公告
    Route::get('announcements', 'api.dividend.ThresholdDividends/getAnnouncements');
    
});

// WebHook路由（用于第三方系统集成）
Route::group('webhook/dividend', function () {
    
    // 外部系统触发阈值检查
    Route::post('trigger', 'api.dividend.ThresholdCron/checkThresholds')
        ->middleware(['webhook_auth']);
    
    // 获取系统状态
    Route::get('status', 'api.dividend.ThresholdCron/healthCheck')
        ->middleware(['webhook_auth']);
    
});