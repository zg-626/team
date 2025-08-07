<?php

namespace app\common\services\dividend;

use think\facade\Db;
use think\facade\Log;
use think\facade\Queue;
use think\queue\Job;

/**
 * 异步分红处理服务类
 * 处理大量分红分配任务，避免超时
 */
class AsyncDividendService
{
    /** @var int 批处理大小 */
    protected $batchSize = 100;
    
    /** @var string 队列名称 */
    protected $queueName = 'dividend';
    
    /**
     * 异步处理分红分配
     * @param array $dividendData 分红数据
     * @param array $poolInfo 分红池信息
     * @return bool
     */
    public function asyncDistributeBonus($dividendData, $poolInfo): bool
    {
        try {
            // 分批处理用户分红
            if (!empty($dividendData['users'])) {
                $this->batchProcessUsers($dividendData['users'], $poolInfo);
            }
            
            // 分批处理商家分红
            if (!empty($dividendData['merchants'])) {
                $this->batchProcessMerchants($dividendData['merchants'], $poolInfo);
            }
            
            // 最后处理分红记录和状态更新
            $this->queueFinalProcessing($dividendData, $poolInfo);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('异步分红处理失败：' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 分批处理用户分红
     * @param array $users 用户分红数据
     * @param array $poolInfo 分红池信息
     */
    protected function batchProcessUsers($users, $poolInfo): void
    {
        $batches = array_chunk($users, $this->batchSize);
        
        foreach ($batches as $index => $batch) {
            $jobData = [
                'type' => 'user_dividend',
                'batch_index' => $index,
                'users' => $batch,
                'pool_info' => $poolInfo,
                'created_at' => time()
            ];
            
            Queue::push('app\\job\\DividendProcessJob', $jobData, $this->queueName);
        }
        
        Log::info('用户分红任务已加入队列，共 ' . count($batches) . ' 个批次');
    }
    
    /**
     * 分批处理商家分红
     * @param array $merchants 商家分红数据
     * @param array $poolInfo 分红池信息
     */
    protected function batchProcessMerchants($merchants, $poolInfo): void
    {
        $batches = array_chunk($merchants, $this->batchSize);
        
        foreach ($batches as $index => $batch) {
            $jobData = [
                'type' => 'merchant_dividend',
                'batch_index' => $index,
                'merchants' => $batch,
                'pool_info' => $poolInfo,
                'created_at' => time()
            ];
            
            Queue::push('app\\job\\DividendProcessJob', $jobData, $this->queueName);
        }
        
        Log::info('商家分红任务已加入队列，共 ' . count($batches) . ' 个批次');
    }
    
    /**
     * 队列最终处理任务
     * @param array $dividendData 分红数据
     * @param array $poolInfo 分红池信息
     */
    protected function queueFinalProcessing($dividendData, $poolInfo): void
    {
        $jobData = [
            'type' => 'final_processing',
            'dividend_data' => $dividendData,
            'pool_info' => $poolInfo,
            'created_at' => time()
        ];
        
        // 延迟执行，确保所有分红任务完成后再执行
        $delay = $this->calculateDelay($dividendData);
        Queue::later($delay, 'app\\job\\DividendProcessJob', $jobData, $this->queueName);
        
        Log::info('最终处理任务已加入队列，延迟 ' . $delay . ' 秒执行');
    }
    
    /**
     * 计算延迟时间
     * @param array $dividendData
     * @return int 延迟秒数
     */
    protected function calculateDelay($dividendData): int
    {
        $userCount = count($dividendData['users'] ?? []);
        $merchantCount = count($dividendData['merchants'] ?? []);
        $totalCount = $userCount + $merchantCount;
        
        // 根据数据量计算延迟时间，每100条数据延迟30秒
        $delay = ceil($totalCount / $this->batchSize) * 30;
        
        // 最少延迟60秒，最多延迟600秒
        return max(60, min(600, $delay));
    }
    
    /**
     * 检查分红任务状态
     * @param int $poolId 分红池ID
     * @return array
     */
    public function checkDividendStatus($poolId): array
    {
        try {
            // 检查队列中的任务数量
            $queueStats = $this->getQueueStats();
            
            // 检查分红执行日志
            $executeLog = Db::name('dividend_execute_log')
                ->where('dp_id', $poolId)
                ->order('id', 'desc')
                ->find();
            
            return [
                'queue_stats' => $queueStats,
                'execute_log' => $executeLog,
                'status' => $this->determineDividendStatus($queueStats, $executeLog)
            ];
            
        } catch (\Exception $e) {
            Log::error('检查分红状态失败：' . $e->getMessage());
            return [
                'queue_stats' => [],
                'execute_log' => null,
                'status' => 'error'
            ];
        }
    }
    
    /**
     * 获取队列统计信息
     * @return array
     */
    protected function getQueueStats(): array
    {
        // 这里需要根据实际使用的队列驱动来实现
        // 示例使用Redis队列
        try {
            return [
                'pending' => 0, // 待处理任务数
                'processing' => 0, // 正在处理任务数
                'failed' => 0, // 失败任务数
                'completed' => 0 // 已完成任务数
            ];
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * 确定分红状态
     * @param array $queueStats
     * @param array|null $executeLog
     * @return string
     */
    protected function determineDividendStatus($queueStats, $executeLog): string
    {
        if (empty($queueStats)) {
            return 'unknown';
        }
        
        if ($queueStats['pending'] > 0 || $queueStats['processing'] > 0) {
            return 'processing';
        }
        
        if ($queueStats['failed'] > 0) {
            return 'partial_failed';
        }
        
        if ($executeLog && $executeLog['status'] == 1) {
            return 'completed';
        }
        
        return 'pending';
    }
    
    /**
     * 重试失败的分红任务
     * @param int $poolId 分红池ID
     * @return bool
     */
    public function retryFailedDividend($poolId): bool
    {
        try {
            // 获取失败的分红记录
            $failedLogs = Db::name('dividend_execute_log')
                ->where('dp_id', $poolId)
                ->where('status', 0)
                ->select()
                ->toArray();
            
            if (empty($failedLogs)) {
                Log::info('没有找到失败的分红记录，池ID：' . $poolId);
                return true;
            }
            
            foreach ($failedLogs as $log) {
                // 重新加入队列
                $jobData = [
                    'type' => 'retry_dividend',
                    'log_id' => $log['id'],
                    'pool_id' => $poolId,
                    'created_at' => time()
                ];
                
                Queue::push('app\\job\\DividendProcessJob', $jobData, $this->queueName);
            }
            
            Log::info('重试分红任务已加入队列，共 ' . count($failedLogs) . ' 个任务');
            return true;
            
        } catch (\Exception $e) {
            Log::error('重试分红任务失败：' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 清理过期的分红任务
     * @param int $expireHours 过期小时数
     * @return int 清理的任务数
     */
    public function cleanExpiredTasks($expireHours = 24): int
    {
        try {
            $expireTime = time() - ($expireHours * 3600);
            
            // 清理过期的执行日志
            $count = Db::name('dividend_execute_log')
                ->where('create_time', '<', $expireTime)
                ->where('status', 0) // 只清理失败的记录
                ->delete();
            
            Log::info('清理过期分红任务完成，共清理 ' . $count . ' 条记录');
            return $count;
            
        } catch (\Exception $e) {
            Log::error('清理过期分红任务失败：' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * 设置批处理大小
     * @param int $size
     */
    public function setBatchSize($size): void
    {
        $this->batchSize = max(10, min(500, $size)); // 限制在10-500之间
    }
    
    /**
     * 设置队列名称
     * @param string $name
     */
    public function setQueueName($name): void
    {
        $this->queueName = $name;
    }
}