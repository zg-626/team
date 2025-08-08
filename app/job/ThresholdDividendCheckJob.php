<?php
// +----------------------------------------------------------------------
// | 阈值补贴检查队列任务
// +----------------------------------------------------------------------

namespace app\job;

use app\common\services\dividend\ThresholdDividendService;
use think\queue\Job;
use think\facade\Log;

/**
 * 阈值补贴检查队列任务
 * 异步处理阈值检查，避免阻塞订单支付流程
 */
class ThresholdDividendCheckJob
{
    /**
     * 执行队列任务
     * @param Job $job 当前的任务对象
     * @param array $data 发布任务时自定义的数据
     */
    public function fire(Job $job, $data)
    {
        try {
            $merId = $data['mer_id'] ?? 0;
            $checkTime = $data['check_time'] ?? time();
            
            if (!$merId) {
                Log::error('阈值补贴检查任务参数错误: mer_id为空');
                $job->delete();
                return;
            }
            
            Log::info('开始执行阈值补贴检查任务', [
                'mer_id' => $merId,
                'check_time' => $checkTime
            ]);
            
            // 获取阈值补贴服务
            /** @var ThresholdDividendService $thresholdService */
            $thresholdService = app()->make(ThresholdDividendService::class);
            
            // 执行阈值检查
            $thresholdService->checkAndTriggerThresholdDividend($merId);
            
            Log::info('阈值补贴检查任务执行完成', ['mer_id' => $merId]);
            
            // 删除任务
            $job->delete();
            
        } catch (\Exception $e) {
            Log::error('阈值补贴检查任务执行失败: ' . $e->getMessage(), [
                'mer_id' => $data['mer_id'] ?? 0,
                'error_trace' => $e->getTraceAsString()
            ]);
            
            // 检查重试次数
            if ($job->attempts() < 3) {
                // 重试任务（延迟30秒）
                $job->release(30);
                Log::info('阈值补贴检查任务将在30秒后重试', [
                    'mer_id' => $data['mer_id'] ?? 0,
                    'attempts' => $job->attempts()
                ]);
            } else {
                // 超过重试次数，删除任务
                $job->delete();
                Log::error('阈值补贴检查任务重试次数超限，任务已删除', [
                    'mer_id' => $data['mer_id'] ?? 0
                ]);
            }
        }
    }
    
    /**
     * 任务失败处理
     * @param array $data 发布任务时自定义的数据
     */
    public function failed($data)
    {
        Log::error('阈值补贴检查任务最终失败', [
            'mer_id' => $data['mer_id'] ?? 0,
            'data' => $data
        ]);
    }
}