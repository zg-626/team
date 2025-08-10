<?php

namespace app\common\repositories\user;

use app\common\model\store\order\StoreOrder;
use app\common\model\store\order\StoreOrderOffline;
use app\common\model\system\merchant\Merchant;
use app\common\model\user\User;
use app\common\repositories\BaseRepository;
use think\facade\Db;
use think\facade\Log;

class BonusOfflineService extends BaseRepository
{
    // 环比增长率
    protected $growthRate = 1.15;// 增长率
    // 第一期分红的金额阈值
    protected $initialThreshold = 20000;
    // 用户分红比例
    protected $userRatio = 1;
    // 商家分红比例
    protected $merchantRatio = 0.5;

    /**
     * 构造方法
     */
    public function __construct()
    {
        $this->initialThreshold = systemConfig('sys_red_money');
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
     * 计算并执行分红
     * @return bool|array
     */
    public function calculateBonus($pool)
    {
        try {

            if (!$pool || $pool['available_amount'] <= $this->initialThreshold) {
                return true;
            }
            // 保留两位小数
            $totalAmount = round((float)$pool['available_amount'], 2);// 累计未发放的分红金额
            $initialThreshold = round((float)$pool['initial_threshold'], 2);// 累计用于计算的阈值金额

            // 获取上一次分红记录
            $lastBonusRecord = Db::name('dividend_period_log')->where('dp_id',$pool['id'])->where('execute_type',2)->order('id', 'desc')->find();

            $bonusAmount = 0;

            // 计算本次可分红金额
            if (!$lastBonusRecord) {
                $currentThreshold = $this->initialThreshold;
                // 首次分红，判断是否达到初始阈值且满足增长率
                $shouldAmount = round($this->initialThreshold * $this->growthRate, 2);
                if ($totalAmount >= $shouldAmount && $totalAmount >= $this->initialThreshold) {
                    $bonusAmount = round($totalAmount - $this->initialThreshold, 2);
                }
            } else {
                // 获取当前周期的阈值
                $currentThreshold = $lastBonusRecord['initial_threshold'];
                // 非首次分红，判断是否达到当前周期的阈值且满足增长率
                $shouldAmount = round($currentThreshold * $this->growthRate, 2);
                if ($initialThreshold >=$shouldAmount) {
                    $bonusAmount = round($lastBonusRecord['next_should_threshold']-$lastBonusRecord['should_threshold'], 2);
                    //$bonusAmount = round($shouldAmount - $currentThreshold, 2);// 根据额定增长率计算可分红金额，不按实际增长的金额计算
                }
            }

            // 如果没有可分红金额，跳过
            if ($bonusAmount <= 0) {
                return true;
            }

            // 金额的60%为发放金额(实际发放金额)
            $actual_amout = round($bonusAmount * 0.6, 2);
            // 金额的40%为下一期预备金额
            $deduct_amount = round($bonusAmount * 0.4, 2);

            // 新的可用金额
            $newAvailableAmount = round($totalAmount - $actual_amout, 2);

            // 获取当前城市参与分红的用户和商家
            $users = $this->getValidUsers($pool);
            //$merchants = $this->getValidMerchants($pool);

            // 计算分红金额
            $userBonus = round($actual_amout * $this->userRatio, 2);
           //$merchantBonus = round($actual_amout * $this->merchantRatio, 2);

            // 执行分红
            $userBonusAmounts = $this->distributeUserBonus($users, $userBonus);
            //$merchantBonusAmounts = $this->distributeMerchantBonus($merchants, $merchantBonus);

            // 记录分红日志
            $poolId = $this->recordDividendPeriod($actual_amout, $pool,$totalAmount,$initialThreshold,$currentThreshold,$shouldAmount,$bonusAmount,$deduct_amount,2);
            //$this->recordDividendLog($poolId, $users, $merchants, $userBonusAmounts, $merchantBonusAmounts);

            // 获取当前基础金额
            //$currentBaseAmount = $this->getCurrentBaseAmount($pool['id']);

            // 更新分红池，保留基础金额
            Db::name('dividend_pool')->where('id', $pool['id'])->update([
                'available_amount' => $newAvailableAmount,// 当前可分配金额
                'grand_amount' => $newAvailableAmount,// 累计未发放的分红金额,用于每月1号发放
                'distributed_amount' => Db::raw('distributed_amount + ' . $actual_amout),// 累计分红金额
                'update_time' => date('Y-m-d H:i:s')
            ]);

            return [
                'total_amount' => $totalAmount?? '',
                'bonus_amount' => $actual_amout?? '',
                'user_bonus' => $userBonus?? '',
                'merchant_bonus' => $merchantBonus?? '',
                'base_amount' => $this->initialThreshold?? '',
            ];

        } catch (\Exception $e) {
            Log::error('周期分红任务执行失败：' . $e->getMessage(). '，文件：'. $e->getFile(). '，行号：'. $e->getLine());
            return false; // 明确返回 false 表示执行失败
        }
    }

    /**
     * 获取有效用户
     */
    protected function getValidUsers($value)
    {
        /*$user= User::where('status', 1)
            ->where('integral', '>', 0)
            ->field('uid, integral,coupon_amount')
            ->select();*/
        $user =Db::name('store_order_offline')->alias('s')->join('User b', 's.uid = b.uid')->where('s.city_id',$value['city_id'])->where(['s.paid' => 1])->field('s.city,s.city_id,s.uid,b.coupon_amount,b.integral')->group('uid')->select();
        return $user;
    }

    /**
     * 获取有效商家
     */
    protected function getValidMerchants($value)
    {
        /*return Merchant::where('is_del', 0)
            ->where('integral', '>', 0)
            ->field('mer_id, integral,coupon_amount')
            ->select();*/
        $merchant =Db::name('store_order_offline')->alias('s')->join('Merchant b', 's.mer_id = b.mer_id')->where('s.city_id',$value['city_id'])->where(['b.status' => 1,'s.paid' => 1])->field('b.city,b.city_id,s.mer_id,b.coupon_amount,b.integral')->group('mer_id')->select();
        return $merchant;
    }

    /**
     * 分配用户分红
     */
    protected function distributeUserBonus($users, $totalBonus): array
    {
        $totalUserPoints = array_sum(array_column($users->toArray(), 'integral'));
        $bonusAmounts = [];
        $actualTotal = 0;

        // 先计算每个用户的分红金额
        foreach ($users->toArray() as $user) {
            $bonus = round($user['integral'] * $totalBonus / $totalUserPoints, 2);
            // 设置最低分红金额为0.01
            $bonus = max(0.01, $bonus);
            $bonusAmounts[$user['uid']] = $bonus;
            $actualTotal += $bonus;
        }

        // 如果实际分配总额与预期不符，调整最后一个用户的分红金额
        if ($actualTotal != $totalBonus && !empty($bonusAmounts)) {
            $lastUid = array_key_last($bonusAmounts);
            $diff = $totalBonus - $actualTotal;
            $newAmount = $bonusAmounts[$lastUid] + $diff;

            // 确保调整后的金额不小于0.01
            if ($newAmount >= 0.01) {
                $bonusAmounts[$lastUid] = round($newAmount, 2);
            } else {
                // 如果调整后为负数，则按比例减少所有用户的分红金额
                $ratio = $totalBonus / $actualTotal;
                foreach ($bonusAmounts as &$amount) {
                    $amount = round($amount * $ratio, 2);
                }
                unset($amount);
            }
        }

        return $bonusAmounts;
    }

    /**
     * 分配商家分红
     */
    protected function distributeMerchantBonus($merchants, $totalBonus): array
    {
        $totalMerchantPoints = array_sum(array_column($merchants->toArray(), 'integral'));
        $bonusAmounts = [];
        $actualTotal = 0;

        // 先计算每个商家的分红金额
        foreach ($merchants->toArray() as $merchant) {
            $bonus = round($merchant['integral'] * $totalBonus / $totalMerchantPoints, 2);
            // 设置最低分红金额为0.01
            $bonus = max(0.01, $bonus);
            $bonusAmounts[$merchant['mer_id']] = $bonus;
            $actualTotal += $bonus;
        }

        // 如果实际分配总额与预期不符，调整最后一个商家的分红金额
        if ($actualTotal != $totalBonus && !empty($bonusAmounts)) {
            $lastUid = array_key_last($bonusAmounts);
            $diff = $totalBonus - $actualTotal;
            $newAmount = $bonusAmounts[$lastUid] + $diff;

            // 确保调整后的金额不小于0.01
            if ($newAmount >= 0.01) {
                $bonusAmounts[$lastUid] = round($newAmount, 2);
            } else {
                // 如果调整后为负数，则按比例减少所有商家的分红金额
                $ratio = $totalBonus / $actualTotal;
                foreach ($bonusAmounts as &$amount) {
                    $amount = round($amount * $ratio, 2);
                }
                unset($amount);
            }
        }

        return $bonusAmounts;
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

        return Db::name('dividend_period_log')->insertGetId([
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
        ]);
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
        $logs = [];
        foreach ($users as $user) {
            $bonus = $userBonusAmounts[$user['uid']] ?? 0;
            $logs[] = [
                'period_id' => $periodId,
                'type' => 'user',
                'relation_id' => $user['uid'],
                'integral' => $user['integral'],
                'coupon_amount' => $user['coupon_amount'],
                'bonus_amount' => $bonus,
                'create_time' => date('Y-m-d H:i:s')
            ];
            //Db::name('user')->where('uid', $user['uid'])->inc('coupon_amount', $bonus)->update();

            Db::name('user_bill')->insert([
                'uid' => $user['uid'],
                'link_id' => 0,
                'pm' => 1,
                'title' => '补贴抵用券',
                'status' => -2,
                'category' => 'coupon_amount',
                'type' => 'dividend',
                'number' => $bonus,
                'balance' => $user['coupon_amount'] + $bonus,
                'mark' => '金额补贴抵用券' . $bonus,
                'create_time' => date('Y-m-d H:i:s')
            ]);
        }

        foreach ($merchants as $merchant) {
            $bonus = $merchantBonusAmounts[$merchant['mer_id']] ?? 0;
            $logs[] = [
                'period_id' => $periodId,
                'type' => 'merchant',
                'relation_id' => $merchant['mer_id'],
                'integral' => $merchant['integral'],
                'coupon_amount' => $merchant['coupon_amount'],
                'bonus_amount' => $bonus,
                'create_time' => date('Y-m-d H:i:s')
            ];
            //Db::name('merchant')->where('mer_id', $merchant['mer_id'])->inc('coupon_amount', $bonus)->update();

            // 记录商家分红明细
            Db::name('user_bill')->insert([
                'mer_id' => $merchant['mer_id'],
                'link_id' => 0,
                'pm' => 1,
                'title' => '补贴抵用券',
                'status' => -2,
                'category' => 'coupon_amount',
                'type' => 'dividend',
                'number' => $bonus,
                'balance' => $merchant['coupon_amount'] + $bonus,
                'mark' => '金额补贴抵用券' . $bonus,
                'create_time' => date('Y-m-d H:i:s')
            ]);
        }
        Db::name('dividend_distribution_log')->insertAll($logs);
    }

    /**
     * 月底发放基础金额
     */
    public function distributeBaseAmount($pool)
    {
        try {
            if($pool['available_amount']<=1){
                return true;
            }
            $money=$pool['available_amount'];
            if ($pool['available_amount'] >=40000) {
                $money=$pool['available_amount']-20000;
            }
            // 获取参与分红的用户和商家
            $users = $this->getValidUsers($pool);
            $merchants = $this->getValidMerchants($pool);

            // 金额的60%为发放金额
            $grand_amount = round($money * 0.6, 2);
            // 金额的40%为剩余金额
            $deduct_amount = round($money * 0.4, 2);
            // 计算分红金额
            $userBonus = $grand_amount * $this->userRatio;
            //$merchantBonus = $grand_amount * $this->merchantRatio;

            // 执行分红
            $userBonusAmounts = $this->distributeUserBonus($users, $userBonus);
            //$merchantBonusAmounts = $this->distributeMerchantBonus($merchants, $merchantBonus);

            // 获取当前基础金额
            $currentBaseAmount = $this->getCurrentBaseAmount($pool['id']);

            // 记录分红日志
            $poolId = $this->recordDividendPeriod($grand_amount, $pool, $pool['grand_amount'],$grand_amount,$deduct_amount,$money,$money,$deduct_amount,1);
            //$this->recordDividendLog($poolId, $users, $merchants, $userBonusAmounts, $merchantBonusAmounts);

            // 更新分红池时需要保存最后一次的总金额作为新的基准
            /*$lastTotalAmount = Db::name('dividend_period_log')
                ->where('dp_id', $pool['id'])
                ->order('id', 'desc')
                ->value('total_amount') ?? $this->initialThreshold;*/

            // 更新分红池
            Db::name('dividend_pool')
                ->where('id', $pool['id'])
                ->update([
                    'available_amount' => $deduct_amount,
                    'grand_amount' => $deduct_amount,
                    //'initial_threshold' => $pool['total_amount'], //记录当前周期的初始阈值
                    'distributed_amount' => Db::raw('distributed_amount + ' . $grand_amount),
                    'update_time' => date('Y-m-d H:i:s')
                ]);

            return [
                'bonus_amount' => $grand_amount,
            ];

        } catch (\Exception $e) {
            Log::error(' 月初基础金额分红失败：' . $e);
        }
    }
}
