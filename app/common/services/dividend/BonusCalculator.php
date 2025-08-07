<?php

namespace app\common\services\dividend;

use think\facade\Db;
use think\facade\Log;

/**
 * 分红计算器类
 * 使用策略模式处理不同类型的分红计算
 */
class BonusCalculator
{
    /**
     * 计算周期分红金额
     * @param array $pool 分红池信息
     * @param array $lastRecord 上次分红记录
     * @param float $growthRate 增长率
     * @param float $initialThreshold 初始阈值
     * @return array
     */
    public function calculatePeriodicBonus($pool, $lastRecord, $growthRate, $initialThreshold): array
    {
        $totalAmount = (float)$pool['total_amount'];
        $lastThreshold = $lastRecord ? (float)$lastRecord['next_threshold'] : $initialThreshold;
        
        // 使用bcmath确保精度计算
        $shouldThreshold = bcmul((string)$lastThreshold, (string)(1 + $growthRate), 2);
        $shouldAmount = bcsub((string)$totalAmount, (string)$lastThreshold, 2);
        
        // 如果总金额未达到应分红阈值，不进行分红
        if (bccomp($totalAmount, $shouldThreshold, 2) < 0) {
            return [
                'should_distribute' => false,
                'reason' => '总金额未达到分红阈值',
                'total_amount' => $totalAmount,
                'should_threshold' => (float)$shouldThreshold,
                'last_threshold' => $lastThreshold
            ];
        }
        
        // 计算实际分红金额（应分红金额的60%）
        $actualAmount = bcmul($shouldAmount, '0.6', 2);
        
        // 计算扣除金额（应分红金额的40%）
        $deductAmount = bcsub($shouldAmount, $actualAmount, 2);
        
        // 计算下次分红阈值
        $nextThreshold = bcadd((string)$totalAmount, $deductAmount, 2);
        $nextShouldThreshold = bcmul($nextThreshold, (string)(1 + $growthRate), 2);
        
        return [
            'should_distribute' => true,
            'total_amount' => $totalAmount,
            'last_threshold' => $lastThreshold,
            'should_threshold' => (float)$shouldThreshold,
            'should_amount' => (float)$shouldAmount,
            'actual_amount' => (float)$actualAmount,
            'deduct_amount' => (float)$deductAmount,
            'next_threshold' => (float)$nextThreshold,
            'next_should_threshold' => (float)$nextShouldThreshold,
            'growth_rate' => $growthRate
        ];
    }
    
    /**
     * 计算月初分红金额
     * @param array $pool 分红池信息
     * @return array
     */
    public function calculateMonthlyBonus($pool): array
    {
        $availableAmount = (float)$pool['available_amount'];
        
        // 验证分红条件
        if ($availableAmount <= 1) {
            return [
                'should_distribute' => false,
                'reason' => '可用金额不足',
                'available_amount' => $availableAmount
            ];
        }
        
        // 计算可分配金额（如果超过40000，保留20000作为基础金额）
        $distributableAmount = $availableAmount >= 40000 ? 
            $availableAmount - 20000 : 
            $availableAmount;
        
        // 使用bcmath确保精度
        $distributeAmount = bcdiv(bcmul((string)$distributableAmount, '0.6', 4), '1', 2);
        $remainAmount = bcdiv(bcmul((string)$distributableAmount, '0.4', 4), '1', 2);
        
        return [
            'should_distribute' => true,
            'available_amount' => $availableAmount,
            'distributable_amount' => $distributableAmount,
            'distribute_amount' => (float)$distributeAmount,
            'remain_amount' => (float)$remainAmount
        ];
    }
    
    /**
     * 计算用户分红分配
     * @param array $users 用户列表
     * @param float $totalUserBonus 用户总分红金额
     * @return array
     */
    public function calculateUserDistribution($users, $totalUserBonus): array
    {
        if (empty($users) || $totalUserBonus <= 0) {
            return [];
        }
        
        // 计算总积分
        $totalIntegral = array_sum(array_column($users, 'integral'));
        
        if ($totalIntegral <= 0) {
            return [];
        }
        
        $distributions = [];
        $allocatedAmount = 0;
        
        foreach ($users as $index => $user) {
            $userIntegral = (float)$user['integral'];
            
            if ($index === count($users) - 1) {
                // 最后一个用户分配剩余金额，避免精度问题
                $amount = bcsub((string)$totalUserBonus, (string)$allocatedAmount, 2);
            } else {
                // 按积分比例分配
                $ratio = bcdiv((string)$userIntegral, (string)$totalIntegral, 6);
                $amount = bcmul((string)$totalUserBonus, $ratio, 2);
                $allocatedAmount = bcadd((string)$allocatedAmount, $amount, 2);
            }
            
            // 确保分红金额不低于0.01
            if (bccomp($amount, '0.01', 2) >= 0) {
                $distributions[$user['uid']] = [
                    'uid' => $user['uid'],
                    'amount' => (float)$amount,
                    'integral' => $userIntegral
                ];
            }
        }
        
        return $distributions;
    }
    
    /**
     * 计算商家分红分配
     * @param array $merchants 商家列表
     * @param float $totalMerchantBonus 商家总分红金额
     * @return array
     */
    public function calculateMerchantDistribution($merchants, $totalMerchantBonus): array
    {
        if (empty($merchants) || $totalMerchantBonus <= 0) {
            return [];
        }
        
        // 计算总金额
        $totalAmount = array_sum(array_column($merchants, 'total_amount'));
        
        if ($totalAmount <= 0) {
            return [];
        }
        
        $distributions = [];
        $allocatedAmount = 0;
        
        foreach ($merchants as $index => $merchant) {
            $merchantAmount = (float)$merchant['total_amount'];
            
            if ($index === count($merchants) - 1) {
                // 最后一个商家分配剩余金额，避免精度问题
                $amount = bcsub((string)$totalMerchantBonus, (string)$allocatedAmount, 2);
            } else {
                // 按金额比例分配
                $ratio = bcdiv((string)$merchantAmount, (string)$totalAmount, 6);
                $amount = bcmul((string)$totalMerchantBonus, $ratio, 2);
                $allocatedAmount = bcadd((string)$allocatedAmount, $amount, 2);
            }
            
            // 确保分红金额不低于0.01
            if (bccomp($amount, '0.01', 2) >= 0) {
                $distributions[$merchant['mer_id']] = [
                    'mer_id' => $merchant['mer_id'],
                    'amount' => (float)$amount,
                    'total_amount' => $merchantAmount
                ];
            }
        }
        
        return $distributions;
    }
    
    /**
     * 验证分红计算结果
     * @param array $calculationResult 计算结果
     * @param float $expectedTotal 期望总金额
     * @return bool
     */
    public function validateCalculationResult($calculationResult, $expectedTotal): bool
    {
        if (empty($calculationResult)) {
            return $expectedTotal == 0;
        }
        
        $actualTotal = array_sum(array_column($calculationResult, 'amount'));
        $difference = abs($actualTotal - $expectedTotal);
        
        // 允许0.02的精度差异
        return $difference <= 0.02;
    }
}