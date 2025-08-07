<?php

namespace app\common\services\dividend;

use think\facade\Db;
use think\facade\Log;

/**
 * 分红分配器类
 * 专门处理分红的分配和记录逻辑
 */
class BonusDistributor
{
    /**
     * 分配用户分红
     * @param array $userDistributions 用户分红分配
     * @param int $periodId 分红周期ID
     * @return bool
     */
    public function distributeUserBonus($userDistributions, $periodId): bool
    {
        if (empty($userDistributions)) {
            return true;
        }
        
        try {
            foreach ($userDistributions as $distribution) {
                // 记录分红分配日志
                $this->recordDividendDistribution([
                    'period_id' => $periodId,
                    'type' => 1, // 用户分红
                    'relation_id' => $distribution['uid'],
                    'coupon_amount' => 0,
                    'bonus_amount' => $distribution['amount'],
                    'integral' => $distribution['integral'],
                    'create_time' => date('Y-m-d H:i:s')
                ]);
                
                // 更新用户账单
                $this->updateUserBill([
                    'uid' => $distribution['uid'],
                    'link_id' => $periodId,
                    'pm' => 1, // 收入
                    'title' => '分红收益',
                    'category' => 'dividend',
                    'type' => 'dividend',
                    'number' => $distribution['amount'],
                    'balance' => Db::raw('balance + ' . $distribution['amount']),
                    'mark' => '系统分红奖励',
                    'status' => 1,
                    'add_time' => time(),
                    'frozen_time' => 0
                ]);
            }
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('用户分红分配失败：' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 分配商家分红
     * @param array $merchantDistributions 商家分红分配
     * @param int $periodId 分红周期ID
     * @return bool
     */
    public function distributeMerchantBonus($merchantDistributions, $periodId): bool
    {
        if (empty($merchantDistributions)) {
            return true;
        }
        
        try {
            foreach ($merchantDistributions as $distribution) {
                // 记录分红分配日志
                $this->recordDividendDistribution([
                    'period_id' => $periodId,
                    'type' => 2, // 商家分红
                    'relation_id' => $distribution['mer_id'],
                    'coupon_amount' => 0,
                    'bonus_amount' => $distribution['amount'],
                    'integral' => 0,
                    'create_time' => date('Y-m-d H:i:s')
                ]);
                
                // 更新商家账单
                $this->updateMerchantBill([
                    'mer_id' => $distribution['mer_id'],
                    'link_id' => $periodId,
                    'pm' => 1, // 收入
                    'title' => '分红收益',
                    'category' => 'dividend',
                    'type' => 'dividend',
                    'number' => $distribution['amount'],
                    'balance' => Db::raw('balance + ' . $distribution['amount']),
                    'mark' => '系统分红奖励',
                    'status' => 1,
                    'add_time' => time()
                ]);
            }
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('商家分红分配失败：' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 记录分红分配日志
     * @param array $data 分配数据
     * @return int|false
     */
    protected function recordDividendDistribution($data)
    {
        return Db::name('dividend_distribution_log')->insertGetId($data);
    }
    
    /**
     * 更新用户账单
     * @param array $data 账单数据
     * @return bool
     */
    protected function updateUserBill($data): bool
    {
        try {
            // 检查用户是否存在
            $userExists = Db::name('user')->where('uid', $data['uid'])->find();
            if (!$userExists) {
                Log::warning('用户不存在，跳过分红：UID=' . $data['uid']);
                return false;
            }
            
            // 插入用户账单记录
            Db::name('user_bill')->insert($data);
            
            // 更新用户余额
            Db::name('user')->where('uid', $data['uid'])->inc('now_money', $data['number']);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('更新用户账单失败：UID=' . $data['uid'] . '，错误：' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 更新商家账单
     * @param array $data 账单数据
     * @return bool
     */
    protected function updateMerchantBill($data): bool
    {
        try {
            // 检查商家是否存在
            $merchantExists = Db::name('system_store')->where('id', $data['mer_id'])->find();
            if (!$merchantExists) {
                Log::warning('商家不存在，跳过分红：MER_ID=' . $data['mer_id']);
                return false;
            }
            
            // 插入商家账单记录
            Db::name('system_store_bill')->insert($data);
            
            // 更新商家余额
            Db::name('system_store')->where('id', $data['mer_id'])->inc('commission', $data['number']);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('更新商家账单失败：MER_ID=' . $data['mer_id'] . '，错误：' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 记录分红周期日志
     * @param array $periodData 周期数据
     * @return int|false
     */
    public function recordDividendPeriod($periodData)
    {
        try {
            return Db::name('dividend_period_log')->insertGetId($periodData);
        } catch (\Exception $e) {
            Log::error('记录分红周期日志失败：' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 更新分红池状态
     * @param int $poolId 分红池ID
     * @param array $updateData 更新数据
     * @return bool
     */
    public function updateDividendPool($poolId, $updateData): bool
    {
        try {
            $updateData['update_time'] = date('Y-m-d H:i:s');
            return Db::name('dividend_pool')->where('id', $poolId)->update($updateData) !== false;
        } catch (\Exception $e) {
            Log::error('更新分红池失败：Pool ID=' . $poolId . '，错误：' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 批量处理分红分配
     * @param array $userDistributions 用户分红分配
     * @param array $merchantDistributions 商家分红分配
     * @param int $periodId 分红周期ID
     * @return array
     */
    public function batchDistributeBonus($userDistributions, $merchantDistributions, $periodId): array
    {
        $result = [
            'user_success' => false,
            'merchant_success' => false,
            'user_count' => count($userDistributions),
            'merchant_count' => count($merchantDistributions),
            'errors' => []
        ];
        
        try {
            // 分配用户分红
            if (!empty($userDistributions)) {
                $result['user_success'] = $this->distributeUserBonus($userDistributions, $periodId);
                if (!$result['user_success']) {
                    $result['errors'][] = '用户分红分配失败';
                }
            } else {
                $result['user_success'] = true; // 没有用户需要分红时视为成功
            }
            
            // 分配商家分红
            if (!empty($merchantDistributions)) {
                $result['merchant_success'] = $this->distributeMerchantBonus($merchantDistributions, $periodId);
                if (!$result['merchant_success']) {
                    $result['errors'][] = '商家分红分配失败';
                }
            } else {
                $result['merchant_success'] = true; // 没有商家需要分红时视为成功
            }
            
        } catch (\Exception $e) {
            $result['errors'][] = '批量分红分配异常：' . $e->getMessage();
            Log::error('批量分红分配异常：' . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * 验证分红分配结果
     * @param array $distributions 分配结果
     * @param float $expectedTotal 期望总金额
     * @return array
     */
    public function validateDistributionResult($distributions, $expectedTotal): array
    {
        $actualTotal = 0;
        $count = 0;
        
        foreach ($distributions as $distribution) {
            $actualTotal += $distribution['amount'];
            $count++;
        }
        
        $difference = abs($actualTotal - $expectedTotal);
        
        return [
            'is_valid' => $difference <= 0.02, // 允许0.02的精度差异
            'expected_total' => $expectedTotal,
            'actual_total' => $actualTotal,
            'difference' => $difference,
            'count' => $count
        ];
    }
}