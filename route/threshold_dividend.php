<?php
// +----------------------------------------------------------------------
// | 阈值补贴相关路由配置
// +----------------------------------------------------------------------

use app\common\middleware\AllowOriginMiddleware;
use app\common\middleware\CheckSiteOpenMiddleware;
use app\common\middleware\InstallMiddleware;
use app\common\middleware\UserTokenMiddleware;
use think\facade\Route;

// 阈值补贴管理路由组
Route::group('api/dividend/threshold', function () {
    
    // 手动执行阈值补贴
    Route::any('execute', 'api.dividend.ThresholdDividends/index');
    
    // 处理单个分红池阈值
    Route::post('pool/process', 'api.dividend.ThresholdDividends/processPoolThreshold');
    
    // 获取阈值补贴历史记录
    Route::get('history', 'api.dividend.ThresholdDividends/getHistory');
    
    // 获取补贴统计数据
    Route::get('statistics', 'api.dividend.ThresholdDividends/getStatistics');
    
})->middleware(UserTokenMiddleware::class, true)
    ->middleware(AllowOriginMiddleware::class)
    ->middleware(InstallMiddleware::class)
    ->middleware(CheckSiteOpenMiddleware::class); // 需要管理员权限

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
    
})->middleware(AllowOriginMiddleware::class)
    ->middleware(InstallMiddleware::class)
    ->middleware(CheckSiteOpenMiddleware::class); // 定时任务路由

// 手动触发和测试路由组
Route::group('api/dividend/manual', function () {
    
    // 手动触发阈值检查
    Route::post('trigger-threshold', 'api.dividend.ThresholdDividends/index')
        ->middleware(UserTokenMiddleware::class, true);
    
    // 模拟订单支付（仅测试环境）
    Route::post('simulate-payment', 'api.dividend.ThresholdCron/simulatePayment')
        ->middleware(UserTokenMiddleware::class, true);
    
})->middleware(UserTokenMiddleware::class, true)
    ->middleware(AllowOriginMiddleware::class)
    ->middleware(InstallMiddleware::class)
    ->middleware(CheckSiteOpenMiddleware::class);

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
        ->middleware(AllowOriginMiddleware::class);
    
    // 获取系统状态
    Route::get('status', 'api.dividend.ThresholdCron/healthCheck')
        ->middleware(['webhook_auth']);
    
});