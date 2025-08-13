<?php
// +----------------------------------------------------------------------
// | 分销团队级别统计系统 - 阈值补贴任务
// +----------------------------------------------------------------------

namespace app\controller\api\dividend;

use app\common\model\store\order\StoreOrder;
use app\common\model\user\User;
use app\common\model\user\UserBill;
use app\common\repositories\user\UserBillRepository;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\Log;
use crmeb\basic\BaseController;
use think\response\Json;

/**
 * 阈值补贴任务(新版补贴池)
 * 基于阈值和增长率的补贴分配系统
 */
class RedDividends extends BaseController
{
    /**
     * 阈值补贴接口
     * @return Json
     */
    public function index()
    {
        try {
            $result = $this->processOrderGrowthDividends();
            return app('json')->success('分红执行成功', $result);
        } catch (\Exception $e) {
            Log::error('分红阈值补贴任务执行失败: ' . $e->getMessage());
            return app('json')->fail('分红阈值补贴任务执行失败: ' . $e->getMessage());
        }
    }

    /**
     * 处理订单增长分红
     * 总营业额满足基础值后，每增长15%触发一次分红
     * 第一次分红金额为订单支付金额的 commission_rate * 0.25
     * 之后每次分红金额为上一次的1.15倍
     * 最多分36次，新订单从第1次开始，老订单不影响新订单
     * @return array|bool
     */
    public function processOrderGrowthDividends()
    {
        try {
            // 1. 获取红包池信息，如果不存在则不处理
            $redPool = Db::name('red_pool')
                ->order('id', 'desc')
                ->find();

            if (!$redPool) {
                throw new ValidateException('红包池不存在，无需处理');
            }

            // 2. 计算当前总营业额
            //$totalPayPrice = $this->calculateTotalPayPrice();
            $totalPayPrice = $redPool['total_pay_price'];

            // 3. 检查是否达到分红阈值（使用配置文件的阈值）
            $config = config('threshold_dividend.basic');
            $initialThreshold = (float)$config['default_initial_threshold'];
            $lastThresholdAmount = (float)($redPool['last_threshold_amount']);
            if($redPool['last_threshold_amount']==0){
                $lastThresholdAmount = $initialThreshold;
            }

            if ($totalPayPrice < $initialThreshold) {
                throw new ValidateException('总营业额未达到初始阈值');
            }

            // 4. 检查是否需要触发分红（总营业额增长15%）
            $growthThreshold = round($lastThresholdAmount * 1.15,2);
            if ($totalPayPrice < $growthThreshold) {
                throw new ValidateException('总营业额增长未达到15%，无需分红');
            }

            // 5. 执行分红操作 - 所有订单次数+1
            $result = $this->executeOrderGrowthDividend($redPool, $growthThreshold);
            if (!$result) {
                throw new ValidateException('分红执行失败');
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('分红处理异常：' . $e->getMessage().$e->getLine());
            throw new ValidateException('分红处理异常：' . $e->getMessage().$e->getLine());
        }
    }

    /**
     * 计算当前总营业额
     * @return float
     */
    protected function calculateTotalPayPrice()
    {
        $totalPayPrice = Db::name('store_order')
            ->where('paid', 1)
            ->where('status', 3)
            ->where('is_del', 0)
            ->where('is_system_del', 0)
            ->sum('pay_price');

        return (float)$totalPayPrice;
    }


    /**
     * 执行订单增长分红
     * @param array $redPool 红包池信息
     * @param float $growthThreshold 新的阈值金额
     * @return array|bool
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    protected function executeOrderGrowthDividend($redPool, $growthThreshold)
    {
        // 开启事务
        Db::startTrans();
        try {
            // 更新红包池阈值
            Db::name('red_pool')->where('id', $redPool['id'])->update([
                'last_threshold_amount' => $growthThreshold,
                'update_time' => date('Y-m-d H:i:s')
            ]);

            // 获取所有订单
            $orders = Db::name('store_order')
                ->where('paid', 1)
                ->where('status', 3)
                ->where('offline_audit_status', 1)
                ->where('is_del', 0)
                ->where('is_system_del', 0)
                ->select()
                ->toArray();

            $distributedDetails = [];
            $totalDividendAmount = 0;
            $userBillRepository = app()->make(UserBillRepository::class);

            foreach ($orders as $order) {
                // 获取当前订单的分红次数，如果为空则为0
                $currentTimes = (int)($order['dividend_times'] ?: 0);

                // 如果已经分红36次，跳过
                if ($currentTimes >= 36) {
                    continue;
                }

                // 计算新的分红次数
                $newTimes = $currentTimes + 1;

                // 计算分红金额
                $dividendAmount = $this->calculateOrderDividendAmount($order, $newTimes);

                if ($dividendAmount > 0) {
                    // TODO 不直接更新用户抵用券余额,更新用户抵用券账单,根据账单记录自动更新
                    User::where('uid', $order['uid'])->update([
                        'coupon_amount' => Db::raw("coupon_amount + {$dividendAmount}")
                    ]);

                    // 记录用户抵用券账单
                    $userBillRepository->incBill($order['uid'], 'coupon_amount', 'order_growth_dividend', [
                        'link_id' => $order['order_id'],
                        'status' => 1,
                        'title' => "订单号：{$order['order_sn']}，第{$newTimes}次分红",
                        'number' => $dividendAmount,
                        'mark' => "订单增长分红，订单ID：{$order['order_id']}，第{$newTimes}次分红，获得推广抵用券{$dividendAmount}元",
                        'balance' => 0
                    ]);

                    $distributedDetails[] = [
                        'uid' => $order['uid'],
                        'order_id' => $order['order_id'],
                        'times' => $newTimes,
                        'amount' => $dividendAmount
                    ];

                    $totalDividendAmount += $dividendAmount;
                }

                // 更新订单分红次数和金额
                Db::name('store_order')->where('order_id', $order['order_id'])->update([
                    'dividend_times' => $newTimes,
                    'last_dividend_amount' => $dividendAmount
                ]);
            }

            // 更新红包池分配金额
            Db::name('red_pool')->where('id', $redPool['id'])->update([
                'distributed_amount' => Db::raw("distributed_amount + {$totalDividendAmount}")
            ]);

            Db::commit();
            return [
                'total_dividend_amount' => $totalDividendAmount,
                'growth_threshold' => $growthThreshold,
                'order_count' => count($distributedDetails),
                'distributed_details' => $distributedDetails
            ];
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 计算订单分红金额
     * @param array $order 订单信息
     * @param int $times 当前分红次数
     * @return float
     */
    protected function calculateOrderDividendAmount($order, $times)
    {
        if ($times == 1) {
            // 第一次分红：订单支付金额 * commission_rate * 0.25
            $commissionRate = (float)$order['commission_rate'] / 100; // 转换为小数
            return (float)$order['pay_price'] * $commissionRate * 0.25;
        }

        // 之后每次：上次分红金额 * 1.15
        $lastAmount = (float)$order['last_dividend_amount'];
        return $lastAmount * 1.15;
    }

}