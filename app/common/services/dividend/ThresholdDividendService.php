<?php
// +----------------------------------------------------------------------
// | 阈值补贴服务类
// +----------------------------------------------------------------------

namespace app\common\services\dividend;

use think\facade\Db;
use think\facade\Log;

/**
 * 阈值补贴服务类
 * 处理手续费累积和阈值检查
 */
class ThresholdDividendService
{
    /**
     * 处理订单支付后的手续费累积
     * @param array $orderData 订单数据
     * @return bool
     */
    public function processOrderHandlingFee($orderData)
    {
        try {
            // 检查订单是否符合条件
            if (!$this->isValidOrder($orderData)) {
                return false;
            }
            
            $handlingFee = (float)$orderData['handling_fee'];
            $merId = $orderData['mer_id'];
            
            if ($handlingFee <= 0) {
                return false;
            }
            
            // 计算40%的手续费
            $dividendAmount = $handlingFee * 0.4;
            
            // 根据商户所在城市更新分红池
            $this->updateDividendPool($merId, $dividendAmount, $orderData);
            
            // 检查是否触发阈值补贴
            $this->checkAndTriggerThresholdDividend($merId);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('处理订单手续费失败: ' . $e->getMessage(), [
                'order_id' => $orderData['order_id'] ?? 0,
                'mer_id' => $orderData['mer_id'] ?? 0,
                'handling_fee' => $orderData['handling_fee'] ?? 0
            ]);
            return false;
        }
    }
    
    /**
     * 检查订单是否有效
     * @param array $orderData
     * @return bool
     */
    private function isValidOrder($orderData)
    {
        // 检查订单是否已支付且通过审核
        return isset($orderData['paid']) && $orderData['paid'] == 1 &&
               isset($orderData['offline_audit_status']) && $orderData['offline_audit_status'] == 1 &&
               isset($orderData['handling_fee']) && $orderData['handling_fee'] > 0;
    }
    
    /**
     * 更新分红池金额
     * @param int $merId 商户ID
     * @param float $dividendAmount 分红金额
     * @param array $orderData 订单数据
     */
    private function updateDividendPool($merId, $dividendAmount, $orderData)
    {
        // 获取商户所在城市
        $cityId = $this->getMerchantCityId($merId);
        
        if (!$cityId) {
            Log::warning('无法获取商户城市信息', ['mer_id' => $merId]);
            return;
        }
        
        // 查找或创建分红池
        $pool = Db::name('dividend_pool')
            ->where('city_id', $cityId)
            ->find();
        
        if ($pool) {
            // 更新现有分红池
            Db::name('dividend_pool')
                ->where('id', $pool['id'])
                ->update([
                    'total_amount' => Db::raw('total_amount + ' . $dividendAmount),
                    'available_amount' => Db::raw('available_amount + ' . $dividendAmount),
                    'update_time' => date('Y-m-d H:i:s')
                ]);
            
            Log::info('更新分红池金额', [
                'pool_id' => $pool['id'],
                'city_id' => $cityId,
                'dividend_amount' => $dividendAmount,
                'order_id' => $orderData['order_id'] ?? 0
            ]);
        } else {
            // 创建新的分红池
            $cityName = $this->getCityName($cityId);
            $poolId = Db::name('dividend_pool')->insertGetId([
                'total_amount' => $dividendAmount,
                'available_amount' => $dividendAmount,
                'distributed_amount' => 0,
                'city_id' => $cityId,
                'city' => $cityName,
                'initial_threshold' => $dividendAmount, // 首次金额作为初始阈值
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s')
            ]);
            
            Log::info('创建新分红池', [
                'pool_id' => $poolId,
                'city_id' => $cityId,
                'city_name' => $cityName,
                'initial_amount' => $dividendAmount
            ]);
        }
        
        // 记录分红池变动日志
        $this->logPoolChange($pool['id'] ?? $poolId, $dividendAmount, $orderData);
    }
    
    /**
     * 获取商户所在城市ID
     * @param int $merId
     * @return int|null
     */
    private function getMerchantCityId($merId)
    {
        $merchant = Db::name('merchant')
            ->where('mer_id', $merId)
            ->field('city_id')
            ->find();
        
        return $merchant ? $merchant['city_id'] : null;
    }
    
    /**
     * 获取城市名称
     * @param int $cityId
     * @return string
     */
    private function getCityName($cityId)
    {
        $city = Db::name('city_area')
            ->where('id', $cityId)
            ->field('name')
            ->find();
        
        return $city ? $city['name'] : '未知城市';
    }
    
    /**
     * 记录分红池变动日志
     * @param int $poolId
     * @param float $amount
     * @param array $orderData
     */
    private function logPoolChange($poolId, $amount, $orderData)
    {
        try {
            Db::name('dividend_pool_log')->insert([
                'pool_id' => $poolId,
                'order_id' => $orderData['order_id'] ?? 0,
                'mer_id' => $orderData['mer_id'] ?? 0,
                'uid' => $orderData['uid'] ?? 0,
                'change_amount' => $amount,
                'change_type' => 1, // 1=增加，2=减少
                'handling_fee' => $orderData['handling_fee'] ?? 0,
                'city_id' => $orderData['city_id'] ?? 0,
                'city' => $orderData['city'] ?? '',
                'remark' => '订单手续费40%累积',
                'create_time' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            Log::error('记录分红池变动日志失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 检查并触发阈值补贴
     * @param int $merId
     */
    private function checkAndTriggerThresholdDividend($merId)
    {
        try {
            // 获取商户所在城市的分红池
            $cityId = $this->getMerchantCityId($merId);
            if (!$cityId) {
                return;
            }
            
            $pool = Db::name('dividend_pool')
                ->where('city_id', $cityId)
                ->find();
            
            if (!$pool) {
                return;
            }
            
            // 检查是否达到阈值
            if ($this->shouldTriggerDividend($pool)) {
                // 异步触发阈值补贴
                $this->triggerAsyncThresholdDividend($pool);
            }
            
        } catch (\Exception $e) {
            Log::error('检查阈值补贴失败: ' . $e->getMessage(), [
                'mer_id' => $merId
            ]);
        }
    }
    
    /**
     * 判断是否应该触发补贴
     * @param array $pool
     * @return bool
     */
    private function shouldTriggerDividend($pool)
    {
        $currentAmount = (float)$pool['available_amount'];
        $initialThreshold = (float)$pool['initial_threshold'];
        
        // 获取最后一次补贴记录
        $lastDividend = Db::name('threshold_dividend_log')
            ->where('pool_id', $pool['id'])
            ->order('id', 'desc')
            ->find();
        
        if ($lastDividend) {
            // 有历史记录，检查是否达到下次阈值
            $nextThreshold = (float)$lastDividend['next_threshold'];
            return $currentAmount >= $nextThreshold;
        } else {
            // 首次检查，使用初始阈值
            return $currentAmount >= $initialThreshold;
        }
    }
    
    /**
     * 异步触发阈值补贴
     * @param array $pool
     */
    private function triggerAsyncThresholdDividend($pool)
    {
        try {
            // 这里可以使用队列或者其他异步方式
            // 为了简化，直接调用补贴控制器
            $thresholdDividends = new \app\controller\api\dividend\ThresholdDividends();
            
            // 直接调用公共方法
            $result = $thresholdDividends->processPoolThreshold($pool);
            
            Log::info('异步触发阈值补贴完成', [
                'pool_id' => $pool['id'],
                'result' => $result
            ]);
            
        } catch (\Exception $e) {
            Log::error('异步触发阈值补贴失败: ' . $e->getMessage(), [
                'pool_id' => $pool['id']
            ]);
        }
    }
    
    /**
     * 手动触发阈值检查（用于定时任务）
     * @return array
     */
    public function manualCheckAllPools()
    {
        $results = [];
        
        try {
            $pools = Db::name('dividend_pool')
                ->where('city_id', '<>', 0)
                ->select()
                ->toArray();
            
            foreach ($pools as $pool) {
                if ($this->shouldTriggerDividend($pool)) {
                    $this->triggerAsyncThresholdDividend($pool);
                    $results[] = [
                        'pool_id' => $pool['id'],
                        'city_id' => $pool['city_id'],
                        'triggered' => true
                    ];
                } else {
                    $results[] = [
                        'pool_id' => $pool['id'],
                        'city_id' => $pool['city_id'],
                        'triggered' => false
                    ];
                }
            }
            
        } catch (\Exception $e) {
            Log::error('手动检查所有分红池失败: ' . $e->getMessage());
        }
        
        return $results;
    }
    
    /**
     * 获取分红池状态信息
     * @param int|null $poolId
     * @return array
     */
    public function getPoolStatus($poolId = null)
    {
        try {
            $query = Db::name('dividend_pool');
            
            if ($poolId) {
                $query->where('id', $poolId);
            } else {
                $query->where('city_id', '<>', 0);
            }
            
            $pools = $query->select()->toArray();
            $results = [];
            
            foreach ($pools as $pool) {
                $lastDividend = Db::name('threshold_dividend_log')
                    ->where('pool_id', $pool['id'])
                    ->order('id', 'desc')
                    ->find();
                
                $nextThreshold = $lastDividend ? 
                    (float)$lastDividend['next_threshold'] : 
                    (float)$pool['initial_threshold'];
                
                $currentAmount = (float)$pool['available_amount'];
                $progress = $nextThreshold > 0 ? ($currentAmount / $nextThreshold) * 100 : 0;
                
                $results[] = [
                    'pool_id' => $pool['id'],
                    'city_id' => $pool['city_id'],
                    'city_name' => $pool['city'],
                    'current_amount' => $currentAmount,
                    'next_threshold' => $nextThreshold,
                    'progress_percent' => round($progress, 2),
                    'can_trigger' => $currentAmount >= $nextThreshold,
                    'last_dividend_time' => $lastDividend ? $lastDividend['execute_time'] : null,
                    'total_dividends' => Db::name('threshold_dividend_log')
                        ->where('pool_id', $pool['id'])
                        ->count()
                ];
            }
            
            return $results;
            
        } catch (\Exception $e) {
            Log::error('获取分红池状态失败: ' . $e->getMessage());
            return [];
        }
    }
}