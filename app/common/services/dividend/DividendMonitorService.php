<?php

namespace app\common\services\dividend;

use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;

/**
 * 分红监控和告警服务类
 * 提供分红系统的监控、告警和数据对账功能
 */
class DividendMonitorService
{
    /** @var array 告警配置 */
    protected $alertConfig = [
        'max_processing_time' => 3600, // 最大处理时间（秒）
        'max_failed_rate' => 0.05, // 最大失败率
        'min_success_rate' => 0.95, // 最小成功率
        'amount_threshold' => 10000, // 金额异常阈值
    ];
    
    /**
     * 监控分红执行状态
     * @param int $poolId 分红池ID
     * @return array
     */
    public function monitorDividendExecution($poolId = null): array
    {
        try {
            $result = [
                'status' => 'normal',
                'alerts' => [],
                'statistics' => [],
                'recommendations' => []
            ];
            
            // 获取执行统计
            $stats = $this->getExecutionStatistics($poolId);
            $result['statistics'] = $stats;
            
            // 检查处理时间
            $timeAlerts = $this->checkProcessingTime($stats);
            $result['alerts'] = array_merge($result['alerts'], $timeAlerts);
            
            // 检查成功率
            $rateAlerts = $this->checkSuccessRate($stats);
            $result['alerts'] = array_merge($result['alerts'], $rateAlerts);
            
            // 检查金额异常
            $amountAlerts = $this->checkAmountAnomalies($stats);
            $result['alerts'] = array_merge($result['alerts'], $amountAlerts);
            
            // 检查队列积压
            $queueAlerts = $this->checkQueueBacklog();
            $result['alerts'] = array_merge($result['alerts'], $queueAlerts);
            
            // 生成建议
            $result['recommendations'] = $this->generateRecommendations($result['alerts'], $stats);
            
            // 确定整体状态
            $result['status'] = $this->determineOverallStatus($result['alerts']);
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error('分红监控失败：' . $e->getMessage());
            return [
                'status' => 'error',
                'alerts' => [['type' => 'system', 'message' => '监控系统异常：' . $e->getMessage()]],
                'statistics' => [],
                'recommendations' => ['请检查监控系统配置']
            ];
        }
    }
    
    /**
     * 获取执行统计信息
     * @param int $poolId
     * @return array
     */
    protected function getExecutionStatistics($poolId = null): array
    {
        $where = [];
        if ($poolId) {
            $where['dp_id'] = $poolId;
        }
        
        // 最近24小时的统计
        $where['create_time'] = ['>=', time() - 86400];
        
        // 执行日志统计
        $executeStats = Db::name('dividend_execute_log')
            ->where($where)
            ->field('status, COUNT(*) as count, SUM(amount) as total_amount, AVG(amount) as avg_amount')
            ->group('status')
            ->select()
            ->toArray();
        
        // 周期日志统计
        $periodStats = Db::name('dividend_period_log')
            ->where($where)
            ->field('execute_type, COUNT(*) as count, SUM(actual_amount) as total_amount, AVG(actual_amount) as avg_amount')
            ->group('execute_type')
            ->select()
            ->toArray();
        
        // 处理时间统计
        $timeStats = $this->getProcessingTimeStats($where);
        
        return [
            'execute_stats' => $executeStats,
            'period_stats' => $periodStats,
            'time_stats' => $timeStats,
            'pool_stats' => $this->getPoolStats($poolId)
        ];
    }
    
    /**
     * 获取处理时间统计
     * @param array $where
     * @return array
     */
    protected function getProcessingTimeStats($where): array
    {
        try {
            $logs = Db::name('dividend_period_log')
                ->where($where)
                ->field('create_time, update_time')
                ->select()
                ->toArray();
            
            $processingTimes = [];
            foreach ($logs as $log) {
                if ($log['update_time'] && $log['create_time']) {
                    $processingTimes[] = $log['update_time'] - $log['create_time'];
                }
            }
            
            if (empty($processingTimes)) {
                return ['avg' => 0, 'max' => 0, 'min' => 0, 'count' => 0];
            }
            
            return [
                'avg' => array_sum($processingTimes) / count($processingTimes),
                'max' => max($processingTimes),
                'min' => min($processingTimes),
                'count' => count($processingTimes)
            ];
            
        } catch (\Exception $e) {
            Log::error('获取处理时间统计失败：' . $e->getMessage());
            return ['avg' => 0, 'max' => 0, 'min' => 0, 'count' => 0];
        }
    }
    
    /**
     * 获取分红池统计
     * @param int $poolId
     * @return array
     */
    protected function getPoolStats($poolId = null): array
    {
        try {
            $where = [];
            if ($poolId) {
                $where['id'] = $poolId;
            }
            
            return Db::name('dividend_pool')
                ->where($where)
                ->field('COUNT(*) as total_pools, SUM(available_amount) as total_amount, AVG(available_amount) as avg_amount')
                ->find();
                
        } catch (\Exception $e) {
            Log::error('获取分红池统计失败：' . $e->getMessage());
            return ['total_pools' => 0, 'total_amount' => 0, 'avg_amount' => 0];
        }
    }
    
    /**
     * 检查处理时间
     * @param array $stats
     * @return array
     */
    protected function checkProcessingTime($stats): array
    {
        $alerts = [];
        $timeStats = $stats['time_stats'] ?? [];
        
        if (isset($timeStats['max']) && $timeStats['max'] > $this->alertConfig['max_processing_time']) {
            $alerts[] = [
                'type' => 'performance',
                'level' => 'warning',
                'message' => '分红处理时间过长：' . $timeStats['max'] . '秒，超过阈值' . $this->alertConfig['max_processing_time'] . '秒',
                'value' => $timeStats['max'],
                'threshold' => $this->alertConfig['max_processing_time']
            ];
        }
        
        if (isset($timeStats['avg']) && $timeStats['avg'] > $this->alertConfig['max_processing_time'] / 2) {
            $alerts[] = [
                'type' => 'performance',
                'level' => 'info',
                'message' => '平均处理时间较长：' . round($timeStats['avg'], 2) . '秒',
                'value' => $timeStats['avg'],
                'threshold' => $this->alertConfig['max_processing_time'] / 2
            ];
        }
        
        return $alerts;
    }
    
    /**
     * 检查成功率
     * @param array $stats
     * @return array
     */
    protected function checkSuccessRate($stats): array
    {
        $alerts = [];
        $executeStats = $stats['execute_stats'] ?? [];
        
        $totalCount = 0;
        $successCount = 0;
        
        foreach ($executeStats as $stat) {
            $totalCount += $stat['count'];
            if ($stat['status'] == 1) {
                $successCount += $stat['count'];
            }
        }
        
        if ($totalCount > 0) {
            $successRate = $successCount / $totalCount;
            
            if ($successRate < $this->alertConfig['min_success_rate']) {
                $alerts[] = [
                    'type' => 'reliability',
                    'level' => 'error',
                    'message' => '分红成功率过低：' . round($successRate * 100, 2) . '%，低于阈值' . ($this->alertConfig['min_success_rate'] * 100) . '%',
                    'value' => $successRate,
                    'threshold' => $this->alertConfig['min_success_rate']
                ];
            }
            
            $failedRate = 1 - $successRate;
            if ($failedRate > $this->alertConfig['max_failed_rate']) {
                $alerts[] = [
                    'type' => 'reliability',
                    'level' => 'warning',
                    'message' => '分红失败率过高：' . round($failedRate * 100, 2) . '%，超过阈值' . ($this->alertConfig['max_failed_rate'] * 100) . '%',
                    'value' => $failedRate,
                    'threshold' => $this->alertConfig['max_failed_rate']
                ];
            }
        }
        
        return $alerts;
    }
    
    /**
     * 检查金额异常
     * @param array $stats
     * @return array
     */
    protected function checkAmountAnomalies($stats): array
    {
        $alerts = [];
        $executeStats = $stats['execute_stats'] ?? [];
        
        foreach ($executeStats as $stat) {
            if (isset($stat['avg_amount']) && $stat['avg_amount'] > $this->alertConfig['amount_threshold']) {
                $alerts[] = [
                    'type' => 'amount',
                    'level' => 'warning',
                    'message' => '平均分红金额异常：' . $stat['avg_amount'] . '元，超过阈值' . $this->alertConfig['amount_threshold'] . '元',
                    'value' => $stat['avg_amount'],
                    'threshold' => $this->alertConfig['amount_threshold']
                ];
            }
        }
        
        return $alerts;
    }
    
    /**
     * 检查队列积压
     * @return array
     */
    protected function checkQueueBacklog(): array
    {
        $alerts = [];
        
        try {
            // 检查失败任务数量
            $failedCount = Db::name('dividend_failed_jobs')
                ->where('created_at', '>=', time() - 3600) // 最近1小时
                ->count();
            
            if ($failedCount > 10) {
                $alerts[] = [
                    'type' => 'queue',
                    'level' => 'error',
                    'message' => '队列失败任务过多：' . $failedCount . '个，需要及时处理',
                    'value' => $failedCount,
                    'threshold' => 10
                ];
            }
            
        } catch (\Exception $e) {
            Log::error('检查队列积压失败：' . $e->getMessage());
        }
        
        return $alerts;
    }
    
    /**
     * 生成建议
     * @param array $alerts
     * @param array $stats
     * @return array
     */
    protected function generateRecommendations($alerts, $stats): array
    {
        $recommendations = [];
        
        foreach ($alerts as $alert) {
            switch ($alert['type']) {
                case 'performance':
                    $recommendations[] = '建议优化分红处理性能，考虑增加批处理大小或使用异步处理';
                    break;
                    
                case 'reliability':
                    $recommendations[] = '建议检查分红逻辑和数据完整性，排查失败原因';
                    break;
                    
                case 'amount':
                    $recommendations[] = '建议检查分红金额计算逻辑，确认是否存在异常';
                    break;
                    
                case 'queue':
                    $recommendations[] = '建议及时处理失败的队列任务，检查队列配置';
                    break;
            }
        }
        
        // 基于统计数据的建议
        $timeStats = $stats['time_stats'] ?? [];
        if (isset($timeStats['count']) && $timeStats['count'] > 100) {
            $recommendations[] = '分红任务量较大，建议考虑分时段执行或增加处理资源';
        }
        
        return array_unique($recommendations);
    }
    
    /**
     * 确定整体状态
     * @param array $alerts
     * @return string
     */
    protected function determineOverallStatus($alerts): string
    {
        if (empty($alerts)) {
            return 'normal';
        }
        
        $hasError = false;
        $hasWarning = false;
        
        foreach ($alerts as $alert) {
            if ($alert['level'] === 'error') {
                $hasError = true;
            } elseif ($alert['level'] === 'warning') {
                $hasWarning = true;
            }
        }
        
        if ($hasError) {
            return 'error';
        } elseif ($hasWarning) {
            return 'warning';
        } else {
            return 'info';
        }
    }
    
    /**
     * 数据对账
     * @param int $poolId 分红池ID
     * @param string $date 对账日期（Y-m-d）
     * @return array
     */
    public function reconcileData($poolId, $date = null): array
    {
        try {
            if (!$date) {
                $date = date('Y-m-d');
            }
            
            $startTime = strtotime($date);
            $endTime = $startTime + 86400;
            
            // 获取分红池信息
            $pool = Db::name('dividend_pool')->where('id', $poolId)->find();
            if (!$pool) {
                throw new \Exception('分红池不存在：' . $poolId);
            }
            
            // 统计分红执行记录
            $executeData = $this->getExecuteReconcileData($poolId, $startTime, $endTime);
            
            // 统计周期记录
            $periodData = $this->getPeriodReconcileData($poolId, $startTime, $endTime);
            
            // 统计用户账单
            $userBillData = $this->getUserBillReconcileData($poolId, $startTime, $endTime);
            
            // 统计商家账单
            $merchantBillData = $this->getMerchantBillReconcileData($poolId, $startTime, $endTime);
            
            // 对账结果
            $result = [
                'pool_info' => $pool,
                'date' => $date,
                'execute_data' => $executeData,
                'period_data' => $periodData,
                'user_bill_data' => $userBillData,
                'merchant_bill_data' => $merchantBillData,
                'discrepancies' => [],
                'status' => 'success'
            ];
            
            // 检查差异
            $result['discrepancies'] = $this->checkDiscrepancies($result);
            
            if (!empty($result['discrepancies'])) {
                $result['status'] = 'discrepancy';
            }
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error('数据对账失败：' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'discrepancies' => [],
                'date' => $date ?? date('Y-m-d')
            ];
        }
    }
    
    /**
     * 获取执行记录对账数据
     */
    protected function getExecuteReconcileData($poolId, $startTime, $endTime): array
    {
        return Db::name('dividend_execute_log')
            ->where('dp_id', $poolId)
            ->where('create_time', 'between', [$startTime, $endTime])
            ->field('status, COUNT(*) as count, SUM(amount) as total_amount')
            ->group('status')
            ->select()
            ->toArray();
    }
    
    /**
     * 获取周期记录对账数据
     */
    protected function getPeriodReconcileData($poolId, $startTime, $endTime): array
    {
        return Db::name('dividend_period_log')
            ->where('dp_id', $poolId)
            ->where('create_time', 'between', [$startTime, $endTime])
            ->field('execute_type, COUNT(*) as count, SUM(actual_amount) as total_amount')
            ->group('execute_type')
            ->select()
            ->toArray();
    }
    
    /**
     * 获取用户账单对账数据
     */
    protected function getUserBillReconcileData($poolId, $startTime, $endTime): array
    {
        return Db::name('user_bill')
            ->where('mark', 'like', '%dividend%')
            ->where('add_time', 'between', [$startTime, $endTime])
            ->field('COUNT(*) as count, SUM(number) as total_amount')
            ->find();
    }
    
    /**
     * 获取商家账单对账数据
     */
    protected function getMerchantBillReconcileData($poolId, $startTime, $endTime): array
    {
        return Db::name('merchant_bill')
            ->where('mark', 'like', '%dividend%')
            ->where('add_time', 'between', [$startTime, $endTime])
            ->field('COUNT(*) as count, SUM(number) as total_amount')
            ->find();
    }
    
    /**
     * 检查数据差异
     */
    protected function checkDiscrepancies($data): array
    {
        $discrepancies = [];
        
        // 检查执行记录与周期记录的一致性
        $executeTotal = 0;
        foreach ($data['execute_data'] as $execute) {
            if ($execute['status'] == 1) {
                $executeTotal = bcadd($executeTotal, $execute['total_amount'], 2);
            }
        }
        
        $periodTotal = 0;
        foreach ($data['period_data'] as $period) {
            $periodTotal = bcadd($periodTotal, $period['total_amount'], 2);
        }
        
        if (bccomp($executeTotal, $periodTotal, 2) !== 0) {
            $discrepancies[] = [
                'type' => 'amount_mismatch',
                'message' => '执行记录总金额与周期记录不一致',
                'execute_total' => $executeTotal,
                'period_total' => $periodTotal,
                'difference' => bcsub($executeTotal, $periodTotal, 2)
            ];
        }
        
        return $discrepancies;
    }
    
    /**
     * 设置告警配置
     * @param array $config
     */
    public function setAlertConfig($config): void
    {
        $this->alertConfig = array_merge($this->alertConfig, $config);
    }
    
    /**
     * 获取告警配置
     * @return array
     */
    public function getAlertConfig(): array
    {
        return $this->alertConfig;
    }
}