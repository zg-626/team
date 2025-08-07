<?php

namespace app\common\repositories\user;

use app\common\model\store\order\StoreOrder;
use app\common\model\store\order\StoreOrderOffline;
use app\common\model\system\merchant\Merchant;
use app\common\model\user\User;
use app\common\repositories\BaseRepository;
use think\facade\Db;
use think\facade\Log;
use app\common\services\dividend\BonusCalculator;
use app\common\services\dividend\BonusDistributor;
use app\common\services\dividend\DividendCacheService;

class BonusOfflineService extends BaseRepository
{
    // 环比增长率
    protected $growthRate = 1.15;
    // 第一期分红的金额阈值
    protected $initialThreshold = 20000;
    // 用户分红比例
    protected $userRatio = 0.5;
    // 商家分红比例
    protected $merchantRatio = 0.5;
    
    /** @var BonusCalculator 分红计算器 */
    protected $calculator;
    
    /** @var BonusDistributor 分红分配器 */
    protected $distributor;
    
    /** @var DividendCacheService 缓存服务 */
    protected $cacheService;

    /**
     * 构造方法
     */
    public function __construct()
    {
        parent::__construct();
        $this->initialThreshold = systemConfig('sys_red_money');
        $this->calculator = new BonusCalculator();
        $this->distributor = new BonusDistributor();
        $this->cacheService = new DividendCacheService();
    }
    
    /**
     * 获取当前的基础金额
     * @param int $poolId 分红池ID
     * @return float
     */
    protected function getCurrentBaseAmount($poolId)
    {
        $threshold = Db::name('dividend_pool')
            ->where('id', $poolId)
            ->value('initial_threshold');

        // 如果initial_threshold为null或0或0.00，返回默认基础金额
        return (!$threshold) ? $this->initialThreshold : $threshold;
    }

    /**
     * 计算并执行分红（重构版本）
     * @param array $pool 分红池信息
     * @return bool|array
     */
    public function calculateBonus($pool)
    {
        try {
            // 验证分红条件
            if (!$this->validateBonusConditions($pool)) {
                return true;
            }

            // 计算分红数据
            $bonusData = $this->calculateBonusData($pool);
            if (!$bonusData || $bonusData['bonus_amount'] <= 0) {
                return true;
            }

            // 获取参与分红的用户和商家
            $participants = $this->getBonusParticipants($pool);
            if (empty($participants['users']) && empty($participants['merchants'])) {
                return true;
            }

            // 执行分红分配
            $distributionResult = $this->executeBonusDistribution($bonusData, $participants);

            // 记录分红日志和更新分红池
            $this->recordBonusExecution($pool, $bonusData, $participants, $distributionResult);

            return [
                'total_amount' => $bonusData['total_amount'],
                'bonus_amount' => $bonusData['actual_amount'],
                'user_bonus' => $distributionResult['user_bonus'],
                'merchant_bonus' => $distributionResult['merchant_bonus'],
                'base_amount' => $this->initialThreshold,
            ];

        } catch (\Exception $e) {
            Log::error('周期分红任务执行失败：' . $e->getMessage() . '，文件：' . $e->getFile() . '，行号：' . $e->getLine());
            return false;
        }
    }

    /**
     * 验证分红条件
     * @param array $pool
     * @return bool
     */
    protected function validateBonusConditions($pool): bool
    {
        return $pool && $pool['available_amount'] > $this->initialThreshold;
    }

    /**
     * 计算分红数据
     * @param array $pool
     * @return array|null
     */
    protected function calculateBonusData($pool): ?array
    {
        $totalAmount = round((float)$pool['available_amount'], 2);
        $initialThreshold = round((float)$pool['initial_threshold'], 2);

        // 获取上一次分红记录
        $lastBonusRecord = $this->getLastBonusRecord($pool['id']);
        
        $bonusAmount = $this->calculateBonusAmount($totalAmount, $initialThreshold, $lastBonusRecord);
        
        if ($bonusAmount <= 0) {
            return null;
        }

        // 计算实际分配金额（60%）和预留金额（40%）
        $actualAmount = round($bonusAmount * 0.6, 2);
        $deductAmount = round($bonusAmount * 0.4, 2);
        $newAvailableAmount = round($totalAmount - $actualAmount, 2);

        return [
            'total_amount' => $totalAmount,
            'initial_threshold' => $initialThreshold,
            'bonus_amount' => $bonusAmount,
            'actual_amount' => $actualAmount,
            'deduct_amount' => $deductAmount,
            'new_available_amount' => $newAvailableAmount,
            'last_record' => $lastBonusRecord,
            'current_threshold' => $lastBonusRecord ? $lastBonusRecord['initial_threshold'] : $this->initialThreshold,
            'should_amount' => $lastBonusRecord ? 
                round($lastBonusRecord['initial_threshold'] * $this->growthRate, 2) : 
                round($this->initialThreshold * $this->growthRate, 2)
        ];
    }

    /**
     * 获取上一次分红记录
     * @param int $poolId
     * @return array|null
     */
    protected function getLastBonusRecord($poolId): ?array
    {
        return $this->cacheService->getLastBonusRecord($poolId);
    }

    /**
     * 计算分红金额
     * @param float $totalAmount
     * @param float $initialThreshold
     * @param array|null $lastRecord
     * @return float
     */
    protected function calculateBonusAmount($totalAmount, $initialThreshold, $lastRecord): float
    {
        return $this->calculator->calculatePeriodicBonus(
            $totalAmount,
            $initialThreshold,
            $lastRecord,
            $this->growthRate,
            $this->initialThreshold
        );
    }

    /**
     * 获取分红参与者
     * @param array $pool
     * @return array
     */
    protected function getBonusParticipants($pool): array
    {
        return [
            'users' => $this->getValidUsers($pool),
            'merchants' => $this->getValidMerchants($pool)
        ];
    }

    /**
     * 执行分红分配
     * @param array $bonusData
     * @param array $participants
     * @return array
     */
    protected function executeBonusDistribution($bonusData, $participants): array
    {
        $userBonus = round($bonusData['actual_amount'] * $this->userRatio, 2);
        $merchantBonus = round($bonusData['actual_amount'] * $this->merchantRatio, 2);

        return [
            'user_bonus' => $userBonus,
            'merchant_bonus' => $merchantBonus,
            'user_amounts' => $this->distributeUserBonus($participants['users'], $userBonus),
            'merchant_amounts' => $this->distributeMerchantBonus($participants['merchants'], $merchantBonus)
        ];
    }

    /**
     * 记录分红执行结果
     * @param array $pool
     * @param array $bonusData
     * @param array $participants
     * @param array $distributionResult
     */
    protected function recordBonusExecution($pool, $bonusData, $participants, $distributionResult): void
    {
        // 记录分红周期日志
        $periodId = $this->recordDividendPeriod(
            $bonusData['actual_amount'], 
            $pool, 
            $bonusData['total_amount'], 
            $bonusData['initial_threshold'], 
            $bonusData['current_threshold'], 
            $bonusData['should_amount'], 
            $bonusData['bonus_amount'], 
            $bonusData['deduct_amount'], 
            2
        );

        // 记录分红分配日志
        $this->recordDividendLog(
            $periodId, 
            $participants['users'], 
            $participants['merchants'], 
            $distributionResult['user_amounts'], 
            $distributionResult['merchant_amounts']
        );

        // 更新分红池
        $this->updateDividendPool($pool['id'], $bonusData);
    }

    /**
     * 更新分红池
     * @param int $poolId
     * @param array $bonusData
     */
    protected function updateDividendPool($poolId, $bonusData): void
    {
        $updateData = [
            'available_amount' => $bonusData['new_available_amount'],
            'distributed_amount' => Db::raw('distributed_amount + ' . $bonusData['actual_amount']),
            'update_time' => date('Y-m-d H:i:s')
        ];
        
        $this->distributor->updateDividendPool($poolId, $updateData);
    }

    /**
     * 获取有效用户（优化版本）
     */
    protected function getValidUsers($poolInfo)
    {
        return $this->cacheService->getValidUsers($poolInfo);
    }

    /**
     * 获取有效商家（优化版本）
     */
    protected function getValidMerchants($poolInfo)
    {
        return $this->cacheService->getValidMerchants($poolInfo);
    }

    /**
     * 分配用户分红（重构版本）
     * @param \think\Collection $users 用户集合
     * @param float $totalBonus 总分红金额
     * @return array
     */
    protected function distributeUserBonus($users, $totalBonus): array
    {
        return $this->calculator->calculateUserDistribution($users, $totalBonus);
    }

    /**
     * 分配商家分红（重构版本）
     * @param \think\Collection $merchants 商家集合
     * @param float $totalBonus 总分红金额
     * @return array
     */
    protected function distributeMerchantBonus($merchants, $totalBonus): array
    {
        return $this->calculator->calculateMerchantDistribution($merchants, $totalBonus);
    }

    /**
     * 记录分红池日志
     */
    protected function recordDividendPeriod($actual_amout, $poolInfo, $totalAmount=0, $initialThreshold=0,$currentThreshold=0,$shouldAmount=0, $bonusAmount=0,$deduct_amount=0,$type=1)
    {
        $period = 1;
        if ($lastLog = Db::name('dividend_period_log')->where('dp_id',$poolInfo['id'])->order('id', 'desc')->find()) {
            $period = $lastLog['period'] + 1;
        }

        $periodData = [
            'period' => $period,
            'dp_id' => $poolInfo['id'],
            'city_id' => $poolInfo['city_id'],
            'city' => $poolInfo['city'],
            'execute_type' => $type,
            'total_amount' => $totalAmount,// 当期时可分红总金额
            'actual_amout' => $actual_amout,// 实际分红金额
            'initial_threshold' => $initialThreshold,// 当前周期达到的金额（下期开始的阈值）
            'last_threshold' => $currentThreshold,// 上一期的金额（用于计算增长率）
            'should_threshold' => $shouldAmount,// 当前周期应该达到的金额
            'should_amount' => $bonusAmount,// 应分金额
            'deduct_amount' => $deduct_amount,// 截留的金额
            'next_threshold' => round($initialThreshold * $this->growthRate,2), // 所有类型分红都记录下期开始的阈值
            'next_should_threshold' => round($shouldAmount * $this->growthRate,2), // 下期应该开始的阈值
            'growth_rate' => $type === 1 ? 0 : ($lastLog ? $totalAmount / $lastLog['total_amount'] : 0),// 月初分红不记录增长率
            'create_time' => date('Y-m-d H:i:s')
        ];
        
        return $this->distributor->recordDividendPeriod($periodData);
    }

    /**
     * 记录分红日志
     * @param int $periodId 分红期数ID
     * @param array $users 用户列表
     * @param array $merchants 商家列表
     * @param array $userBonusAmounts 用户分红金额
     * @param array $merchantBonusAmounts 商家分红金额
     */
    protected function recordDividendLog($periodId, $users, $merchants, $userBonusAmounts, $merchantBonusAmounts): void
    {
        // 转换用户分红数据格式
        $userDistributions = [];
        foreach ($users as $user) {
            if (isset($userBonusAmounts[$user['uid']])) {
                $userDistributions[] = [
                    'uid' => $user['uid'],
                    'amount' => $userBonusAmounts[$user['uid']],
                    'integral' => $user['integral'],
                    'coupon_amount' => $user['coupon_amount']
                ];
            }
        }
        
        // 转换商家分红数据格式
        $merchantDistributions = [];
        foreach ($merchants as $merchant) {
            if (isset($merchantBonusAmounts[$merchant['mer_id']])) {
                $merchantDistributions[] = [
                    'mer_id' => $merchant['mer_id'],
                    'amount' => $merchantBonusAmounts[$merchant['mer_id']],
                    'integral' => $merchant['integral'],
                    'coupon_amount' => $merchant['coupon_amount']
                ];
            }
        }
        
        // 使用分配器批量处理分红分配
        $this->distributor->batchDistributeBonus($userDistributions, $merchantDistributions, $periodId);
    }

    /**
     * 月初发放基础金额（重构版本）
     * @param array $pool 分红池信息
     * @return bool|array
     */
    public function distributeBaseAmount($pool)
    {
        try {
            // 验证月初分红条件
            if (!$this->validateMonthlyBonusConditions($pool)) {
                return true;
            }

            // 计算月初分红数据
            $monthlyData = $this->calculateMonthlyBonusData($pool);
            
            // 获取参与分红的用户和商家
            $participants = $this->getBonusParticipants($pool);
            if (empty($participants['users']) && empty($participants['merchants'])) {
                return true;
            }

            // 执行月初分红分配
            $distributionResult = $this->executeMonthlyDistribution($monthlyData, $participants);

            // 记录月初分红日志和更新分红池
            $this->recordMonthlyBonusExecution($pool, $monthlyData, $participants, $distributionResult);

            return [
                'bonus_amount' => $monthlyData['distribute_amount'],
            ];

        } catch (\Exception $e) {
            Log::error('月初基础金额分红失败：' . $e->getMessage() . '，文件：' . $e->getFile() . '，行号：' . $e->getLine());
            return false;
        }
    }

    /**
     * 验证月初分红条件
     * @param array $pool
     * @return bool
     */
    protected function validateMonthlyBonusConditions($pool): bool
    {
        return $pool && $pool['available_amount'] > 1;
    }

    /**
     * 计算月初分红数据
     * @param array $pool
     * @return array
     */
    protected function calculateMonthlyBonusData($pool): array
    {
        return $this->calculator->calculateMonthlyBonus($pool);
    }

    /**
     * 执行月初分红分配
     * @param array $monthlyData
     * @param array $participants
     * @return array
     */
    protected function executeMonthlyDistribution($monthlyData, $participants): array
    {
        $userBonus = bcmul((string)$monthlyData['distribute_amount'], (string)$this->userRatio, 2);
        $merchantBonus = bcmul((string)$monthlyData['distribute_amount'], (string)$this->merchantRatio, 2);

        return [
            'user_bonus' => (float)$userBonus,
            'merchant_bonus' => (float)$merchantBonus,
            'user_amounts' => $this->distributeUserBonus($participants['users'], (float)$userBonus),
            'merchant_amounts' => $this->distributeMerchantBonus($participants['merchants'], (float)$merchantBonus)
        ];
    }

    /**
     * 记录月初分红执行结果
     * @param array $pool
     * @param array $monthlyData
     * @param array $participants
     * @param array $distributionResult
     */
    protected function recordMonthlyBonusExecution($pool, $monthlyData, $participants, $distributionResult): void
    {
        // 记录月初分红周期日志
        $periodId = $this->recordDividendPeriod(
            $monthlyData['distribute_amount'],
            $pool,
            $monthlyData['available_amount'],
            $monthlyData['distribute_amount'],
            $monthlyData['remain_amount'],
            $monthlyData['distributable_amount'],
            $monthlyData['distributable_amount'],
            $monthlyData['remain_amount'],
            1
        );

        // 记录分红分配日志
        $this->recordDividendLog(
            $periodId,
            $participants['users'],
            $participants['merchants'],
            $distributionResult['user_amounts'],
            $distributionResult['merchant_amounts']
        );

        // 更新分红池
        $this->updateMonthlyDividendPool($pool['id'], $monthlyData);
    }

    /**
     * 更新月初分红池
     * @param int $poolId
     * @param array $monthlyData
     */
    protected function updateMonthlyDividendPool($poolId, $monthlyData): void
    {
        $updateData = [
            'available_amount' => $monthlyData['remain_amount'],
            'distributed_amount' => Db::raw('distributed_amount + ' . $monthlyData['distribute_amount']),
            'update_time' => date('Y-m-d H:i:s')
        ];
        
        $this->distributor->updateDividendPool($poolId, $updateData);
    }
}
