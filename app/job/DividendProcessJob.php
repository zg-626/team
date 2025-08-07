<?php

namespace app\job;

use think\queue\Job;
use think\facade\Db;
use think\facade\Log;
use app\common\services\dividend\BonusDistributor;
use app\common\services\dividend\DividendCacheService;

/**
 * 分红处理队列任务
 * 处理异步分红分配任务
 */
class DividendProcessJob
{
    /**
     * 执行任务
     * @param Job $job 任务对象
     * @param array $data 任务数据
     */
    public function fire(Job $job, $data)
    {
        try {
            $type = $data['type'] ?? '';
            
            switch ($type) {
                case 'user_dividend':
                    $this->processUserDividend($data);
                    break;
                    
                case 'merchant_dividend':
                    $this->processMerchantDividend($data);
                    break;
                    
                case 'final_processing':
                    $this->processFinalProcessing($data);
                    break;
                    
                case 'retry_dividend':
                    $this->processRetryDividend($data);
                    break;
                    
                default:
                    throw new \Exception('未知的任务类型：' . $type);
            }
            
            // 任务执行成功，删除任务
            $job->delete();
            
            Log::info('分红任务执行成功：' . $type . '，批次：' . ($data['batch_index'] ?? 'N/A'));
            
        } catch (\Exception $e) {
            Log::error('分红任务执行失败：' . $e->getMessage(), $data);
            
            // 重试次数检查
            if ($job->attempts() < 3) {
                // 延迟重试
                $job->release(60); // 60秒后重试
            } else {
                // 超过重试次数，标记为失败
                $job->failed();
                $this->recordFailedJob($data, $e->getMessage());
            }
        }
    }
    
    /**
     * 处理用户分红
     * @param array $data
     */
    protected function processUserDividend($data): void
    {
        $users = $data['users'] ?? [];
        $poolInfo = $data['pool_info'] ?? [];
        
        if (empty($users) || empty($poolInfo)) {
            throw new \Exception('用户分红数据不完整');
        }
        
        $distributor = new BonusDistributor();
        
        Db::startTrans();
        try {
            foreach ($users as $user) {
                // 更新用户账单
                $distributor->updateUserBill([
                    'uid' => $user['uid'],
                    'amount' => $user['bonus_amount'],
                    'pool_id' => $poolInfo['id'],
                    'type' => 'dividend'
                ]);
                
                // 记录分红分配日志
                $distributor->recordDividendDistribution([
                    'dp_id' => $poolInfo['id'],
                    'uid' => $user['uid'],
                    'amount' => $user['bonus_amount'],
                    'type' => 'user',
                    'status' => 1
                ]);
            }
            
            Db::commit();
            
            Log::info('用户分红批次处理完成，批次：' . $data['batch_index'] . '，用户数：' . count($users));
            
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }
    
    /**
     * 处理商家分红
     * @param array $data
     */
    protected function processMerchantDividend($data): void
    {
        $merchants = $data['merchants'] ?? [];
        $poolInfo = $data['pool_info'] ?? [];
        
        if (empty($merchants) || empty($poolInfo)) {
            throw new \Exception('商家分红数据不完整');
        }
        
        $distributor = new BonusDistributor();
        
        Db::startTrans();
        try {
            foreach ($merchants as $merchant) {
                // 更新商家账单
                $distributor->updateMerchantBill([
                    'mer_id' => $merchant['mer_id'],
                    'amount' => $merchant['bonus_amount'],
                    'pool_id' => $poolInfo['id'],
                    'type' => 'dividend'
                ]);
                
                // 记录分红分配日志
                $distributor->recordDividendDistribution([
                    'dp_id' => $poolInfo['id'],
                    'mer_id' => $merchant['mer_id'],
                    'amount' => $merchant['bonus_amount'],
                    'type' => 'merchant',
                    'status' => 1
                ]);
            }
            
            Db::commit();
            
            Log::info('商家分红批次处理完成，批次：' . $data['batch_index'] . '，商家数：' . count($merchants));
            
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }
    
    /**
     * 处理最终处理任务
     * @param array $data
     */
    protected function processFinalProcessing($data): void
    {
        $dividendData = $data['dividend_data'] ?? [];
        $poolInfo = $data['pool_info'] ?? [];
        
        if (empty($dividendData) || empty($poolInfo)) {
            throw new \Exception('最终处理数据不完整');
        }
        
        $distributor = new BonusDistributor();
        $cacheService = new DividendCacheService();
        
        Db::startTrans();
        try {
            // 记录分红周期日志
            $distributor->recordDividendPeriod([
                'dp_id' => $poolInfo['id'],
                'total_amount' => $dividendData['total_amount'] ?? 0,
                'user_amount' => $dividendData['user_total_amount'] ?? 0,
                'merchant_amount' => $dividendData['merchant_total_amount'] ?? 0,
                'user_count' => count($dividendData['users'] ?? []),
                'merchant_count' => count($dividendData['merchants'] ?? []),
                'execute_type' => $dividendData['execute_type'] ?? 0,
                'status' => 1
            ]);
            
            // 更新分红池状态
            $distributor->updateDividendPool([
                'id' => $poolInfo['id'],
                'available_amount' => bcadd($poolInfo['available_amount'], $dividendData['total_amount'] ?? 0, 2),
                'last_execute_time' => time(),
                'status' => 1
            ]);
            
            Db::commit();
            
            // 清除相关缓存
            $cacheService->clearDividendCache($poolInfo['city_id'], $poolInfo['id']);
            
            Log::info('分红最终处理完成，池ID：' . $poolInfo['id'] . '，总金额：' . ($dividendData['total_amount'] ?? 0));
            
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }
    
    /**
     * 处理重试分红任务
     * @param array $data
     */
    protected function processRetryDividend($data): void
    {
        $logId = $data['log_id'] ?? 0;
        $poolId = $data['pool_id'] ?? 0;
        
        if (!$logId || !$poolId) {
            throw new \Exception('重试分红数据不完整');
        }
        
        // 获取失败的分红记录
        $failedLog = Db::name('dividend_execute_log')
            ->where('id', $logId)
            ->find();
        
        if (!$failedLog) {
            throw new \Exception('未找到失败的分红记录：' . $logId);
        }
        
        $distributor = new BonusDistributor();
        
        Db::startTrans();
        try {
            // 根据记录类型重新处理
            if ($failedLog['type'] == 'user') {
                $distributor->updateUserBill([
                    'uid' => $failedLog['uid'],
                    'amount' => $failedLog['amount'],
                    'pool_id' => $poolId,
                    'type' => 'dividend_retry'
                ]);
            } else {
                $distributor->updateMerchantBill([
                    'mer_id' => $failedLog['mer_id'],
                    'amount' => $failedLog['amount'],
                    'pool_id' => $poolId,
                    'type' => 'dividend_retry'
                ]);
            }
            
            // 更新执行日志状态
            Db::name('dividend_execute_log')
                ->where('id', $logId)
                ->update([
                    'status' => 1,
                    'retry_time' => time(),
                    'error_msg' => ''
                ]);
            
            Db::commit();
            
            Log::info('重试分红任务完成，日志ID：' . $logId);
            
        } catch (\Exception $e) {
            Db::rollback();
            
            // 更新错误信息
            Db::name('dividend_execute_log')
                ->where('id', $logId)
                ->update([
                    'error_msg' => $e->getMessage(),
                    'retry_time' => time()
                ]);
            
            throw $e;
        }
    }
    
    /**
     * 记录失败的任务
     * @param array $data
     * @param string $errorMsg
     */
    protected function recordFailedJob($data, $errorMsg): void
    {
        try {
            Db::name('dividend_failed_jobs')->insert([
                'type' => $data['type'] ?? '',
                'data' => json_encode($data),
                'error_msg' => $errorMsg,
                'created_at' => time()
            ]);
        } catch (\Exception $e) {
            Log::error('记录失败任务失败：' . $e->getMessage());
        }
    }
    
    /**
     * 任务失败处理
     * @param Job $job
     * @param array $data
     */
    public function failed(Job $job, $data)
    {
        Log::error('分红任务最终失败', $data);
        $this->recordFailedJob($data, '任务重试次数超限');
    }
}