<?php
// +----------------------------------------------------------------------
// | 分销团队级别统计系统 - 级别统计任务
// +----------------------------------------------------------------------

namespace app\controller\api\dividend;

use app\common\model\store\order\StoreOrder;
use app\common\model\user\User as UserModer;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Log;
use think\facade\Db;
use crmeb\basic\BaseController;

/**
 * 级别统计任务
 * 统计昨天的订单数据，计算个人和团队流水
 */
class CountUp extends BaseController
{
    /**
     * 级别统计接口
     * @return \think\response\Json
     */
    public function index()
    {
        try {
            $result = $this->countUp();
            return app('json')->success($result, '级别统计任务执行完成');
        } catch (\Exception $e) {
            Log::error('级别统计任务执行失败: ' . $e->getMessage());
            return app('json')->fail('级别统计任务执行失败: ' . $e->getMessage());
        }
    }

    /**
     * 统计昨天的订单数据，计算个人和团队流水
     */
    private function countUp()
    {
        Log::info('级别统计任务开始执行');
        
        try {
            // 获取昨天的日期范围
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $yesterdayStart = $yesterday . ' 00:00:00';
            $yesterdayEnd = $yesterday . ' 23:59:59';
            
            Log::info("统计日期: {$yesterday}");
            Log::info("级别统计任务 - 统计日期: {$yesterday}");
            
            $userModel = new UserModer();
            $orderModel = new StoreOrder();
            
            // 统计昨天商家980的订单数据
            $totalStats = $orderModel
                ->where('paid', 1)
                ->where('mer_id', 980)
                ->where('pay_time', 'between', [$yesterdayStart, $yesterdayEnd])
                ->field('count(*) as order_count, sum(pay_price) as total_turnover, sum(handling_fee) as total_handling_fee')
                ->find();
            
            $orderCount = $totalStats['order_count'] ?? 0;
            $totalTurnover = $totalStats['total_turnover'] ?? 0;
            $totalHandlingFee = $totalStats['total_handling_fee'] ?? 0;
            
            Log::info("商家980昨天订单统计:");
            Log::info("- 订单数量: {$orderCount}");
            Log::info("- 总流水: {$totalTurnover}");
            Log::info("- 总手续费: {$totalHandlingFee}");
            
            // 获取在商家980消费过的用户ID
            $consumerUserIds = $orderModel
                ->where('paid', 1)
                ->where('mer_id', 980)
                ->distinct(true)
                ->column('uid');
            
            // 统计在该商家消费过的用户的级别分布
            $levelStats = [];
            if (!empty($consumerUserIds)) {
                $levelStats = $userModel
                    ->field('brokerage_level, count(*) as user_count')
                    ->where('status', 1)
                    ->whereIn('uid', $consumerUserIds)
                    ->group('brokerage_level')
                    ->select()
                    ->toArray();
            }
            
            $levelCounts = [
                'v0' => 0,
                'v1' => 0,
                'v2' => 0,
                'v3' => 0,
                'v4' => 0
            ];
            
            foreach ($levelStats as $stat) {
                $level = $stat['brokerage_level'] ?? 0;
                $count = $stat['user_count'] ?? 0;
                $levelCounts['v' . $level] = $count;
            }
            
            Log::info("在商家980消费过的用户级别统计:");
            $totalConsumers = count($consumerUserIds);
            Log::info("- 总消费用户数: {$totalConsumers}人");
            foreach ($levelCounts as $level => $count) {
                Log::info("- {$level}级用户: {$count}人");
            }
            
            // 记录统计结果到日志
            $logData = [
                'date' => $yesterday,
                'order_count' => $orderCount,
                'total_turnover' => $totalTurnover,
                'total_handling_fee' => $totalHandlingFee,
                'level_counts' => $levelCounts
            ];
            
            Log::info('级别统计任务完成', $logData);
            Log::info('级别统计任务执行完成!');
            
            return $logData;
            
        } catch (\Exception $e) {
            $errorMsg = '级别统计任务执行失败: ' . $e->getMessage();
            Log::error($errorMsg);
            Log::info($errorMsg);
        }
    }
}