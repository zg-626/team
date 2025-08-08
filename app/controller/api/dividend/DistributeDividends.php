<?php
// +----------------------------------------------------------------------
// | 分销团队级别统计系统 - 补贴任务
// +----------------------------------------------------------------------

namespace app\controller\api\dividend;

use app\common\model\store\order\StoreOrderOffline;
use app\common\model\user\User as UserModer;
use app\common\model\system\DividendStatistics;
use app\common\model\system\UserDividendRecord;
use app\common\model\user\User;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use think\facade\Log;

/**
 * 补贴任务
 * 计算并分配昨天的手续费补贴
 */
class DistributeDividends extends BaseController
{
    /**
     * 分配补贴接口
     * @return \think\response\Json
     */
    public function index()
    {
        try {
            $result = $this->distributeDividends();
            return app('json')->success($result, '补贴任务执行完成');
        } catch (\Exception $e) {
            Log::error('补贴任务执行失败: ' . $e->getMessage());
            return app('json')->fail('补贴任务执行失败: ' . $e->getMessage());
        }
    }

    /**
     * 计算并分配昨天的手续费补贴
     */
    private function distributeDividends()
    {
        Log::info('补贴任务开始执行');
        
        try {
            $userDianModel = new UserModer();
            $orderModel = new StoreOrderOffline();
            $dividendStatisticsModel = new DividendStatistics();
            $userDividendRecordModel = new UserDividendRecord();
            
            // 获取昨天的日期范围
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $yesterdayStart = $yesterday . ' 00:00:00';
            $yesterdayEnd = $yesterday . ' 23:59:59';
            
            Log::info("补贴日期: {$yesterday}");
            
            // 统计昨天商家980订单的handling_fee总额
            $totalHandlingFee = $orderModel
                ->where('paid', 1)
                ->where('mer_id', 980)
                ->where('pay_time', 'between', [$yesterdayStart, $yesterdayEnd])
                ->sum('handling_fee');
            
            if ($totalHandlingFee <= 0) {
                Log::info('商家980昨天没有handling_fee可分配');
                Log::info('补贴任务完成 - 无可分配金额', ['date' => $yesterday, 'total_handling_fee' => 0, 'mer_id' => 980]);
                return 0;
            }
            
            Log::info("商家980昨天总手续费: {$totalHandlingFee}");
            
            // 获取所有团长（V1及以上级别的用户）
            $teamLeaders = $userDianModel
                ->where('brokerage_level', '>=', 1)
                ->where('status', 1)
                ->field('uid,nickname,brokerage_level,phone')
                ->select()
                ->toArray();
            
            // 获取所有有积分的用户（用于70%补贴）
            $integralUsers = $userDianModel
                ->where('integral', '>', 0)
                ->where('status', 1)
                ->field('uid,nickname,integral,phone')
                ->order('integral', 'desc')
                ->select()
                ->toArray();
            
            Log::info("团长数量: " . count($teamLeaders));
            Log::info("有积分用户数量: " . count($integralUsers));
            
            // 计算30%团长补贴
            $teamLeaderPool = $totalHandlingFee * 0.3;
            $teamLeaderCount = count($teamLeaders);
            $dividendPerLeader = $teamLeaderCount > 0 ? $teamLeaderPool / $teamLeaderCount : 0;
            
            Log::info("\n30%团长补贴池: {$teamLeaderPool}");
            Log::info("每个团长补贴: {$dividendPerLeader}");
            
            // 为团长分配30%补贴
            $teamLeaderResults = [];
            foreach ($teamLeaders as $leader) {
                $dividendAmount = round($dividendPerLeader, 2);
                
                // 更新用户的brokerage_price字段
                $this->updateUserBrokeragePrice($leader['phone'], $dividendAmount, $userDianModel);
                
                $teamLeaderResults[] = [
                    'uid' => $leader['uid'],
                    'nickname' => $leader['nickname'],
                    'level' => $leader['brokerage_level'],
                    'phone' => $leader['phone'],
                    'dividend_amount' => $dividendAmount
                ];
                
                Log::info("团长 {$leader['nickname']}({$leader['uid']}) 补贴: {$dividendAmount}");
            }
            
            // 计算70%积分补贴
            $integralPool = $totalHandlingFee * 0.7;
            $integralUserResults = [];
            
            Log::info("\n70%积分补贴池: {$integralPool}");
            
            if (count($integralUsers) > 0) {
                // 计算总积分权重
                $totalIntegral = array_sum(array_column($integralUsers, 'integral'));
                
                if ($totalIntegral > 0) {
                    // 使用红包算法分配积分补贴
                    $integralUserResults = $this->distributeIntegralDividend($integralUsers, $integralPool, $totalIntegral, $userDianModel);
                }
            }
            
            // 输出统计结果
            Log::info("\n补贴任务完成:");
            Log::info("- 补贴日期: {$yesterday}");
            Log::info("- 总手续费: {$totalHandlingFee}");
            Log::info("- 团长补贴池(30%): {$teamLeaderPool}");
            Log::info("- 积分补贴池(70%): {$integralPool}");
            Log::info("- 团长补贴人数: " . count($teamLeaderResults));
            Log::info("- 积分补贴人数: " . count($integralUserResults));
            
            // 保存补贴统计数据到数据库
            $this->saveDividendData(
                $dividendStatisticsModel,
                $userDividendRecordModel,
                $yesterday,
                980, // 商户ID
                $totalHandlingFee,
                $teamLeaderPool,
                $integralPool,
                $teamLeaderResults,
                $integralUserResults
            );
            
            // 记录到日志
            Log::info('补贴任务完成', [
                'date' => $yesterday,
                'total_handling_fee' => $totalHandlingFee,
                'team_leader_pool' => $teamLeaderPool,
                'integral_pool' => $integralPool,
                'team_leader_count' => count($teamLeaderResults),
                'integral_user_count' => count($integralUserResults)
            ]);
            
            Log::info('补贴任务执行完成!');
            
        } catch (\Exception $e) {
            $errorMsg = '补贴任务执行失败: ' . $e->getMessage();
            Log::error($errorMsg);
            Log::info($errorMsg);
            return 1;
        }
        
        return 0;
    }
    
    /**
     * 更新用户的brokerage_price字段
     */
    private function updateUserBrokeragePrice($phone, $dividendAmount, $userModel)
    {
        try {
            $user = $userModel->where('phone', $phone)->find();
            if ($user) {
                $newBrokeragePrice = $user['brokerage_price'] + $dividendAmount;
                $userModel->where('id', $user['id'])->update([
                    'brokerage_price' => $newBrokeragePrice,
                    'update_time' => time()
                ]);
            }
        } catch (\Exception $e) {
            Log::error("更新用户补贴失败 - 手机号: {$phone}, 金额: {$dividendAmount}", ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * 积分补贴算法（类似红包算法）
     * 根据用户积分权重分配补贴金额
     */
    private function distributeIntegralDividend($users, $totalAmount, $totalIntegral, $userModel)
    {
        $results = [];
        $remainingAmount = $totalAmount;
        $remainingUsers = count($users);
        
        foreach ($users as $index => $user) {
            if ($remainingUsers == 1) {
                // 最后一个用户获得剩余所有金额
                $dividendAmount = $remainingAmount;
            } else {
                // 计算当前用户的权重比例
                $weight = $user['integral'] / $totalIntegral;
                
                // 基础分配金额
                $baseAmount = $totalAmount * $weight;
                
                // 添加随机因子（±20%波动），让分配更像红包算法
                $randomFactor = mt_rand(80, 120) / 100;
                $dividendAmount = $baseAmount * $randomFactor;
                
                // 确保不超过剩余金额的80%（为后续用户预留）
                $maxAmount = $remainingAmount * 0.8;
                if ($dividendAmount > $maxAmount) {
                    $dividendAmount = $maxAmount;
                }
                
                // 确保最小补贴金额（0.01元）
                if ($dividendAmount < 0.01) {
                    $dividendAmount = 0.01;
                }
            }
            
            $dividendAmount = round($dividendAmount, 2);
            
            // 更新用户的brokerage_price字段
            $this->updateUserBrokeragePrice($user['phone'], $dividendAmount, $userModel);
            
            $results[] = [
                'uid' => $user['uid'],
                'nickname' => $user['nickname'],
                'integral' => $user['integral'],
                'phone' => $user['phone'],
                'dividend_amount' => $dividendAmount,
                'weight_percent' => round(($user['integral'] / $totalIntegral) * 100, 2)
            ];
            
            Log::info("积分用户 {$user['nickname']}({$user['uid']}) 积分: {$user['integral']}, 补贴: {$dividendAmount}");
            
            $remainingAmount -= $dividendAmount;
            $remainingUsers--;
            
            // 防止剩余金额为负数
            if ($remainingAmount < 0) {
                $remainingAmount = 0;
            }
        }
        
        return $results;
    }

    /**
     * 保存补贴数据到数据库
     * @param DividendStatistics $dividendStatisticsModel 补贴统计模型
     * @param UserDividendRecord $userDividendRecordModel 用户补贴记录模型
     * @param string $dividendDate 补贴日期
     * @param int $merId 商户ID
     * @param float $totalHandlingFee 总手续费
     * @param float $teamLeaderPool 团长补贴池
     * @param float $integralPool 积分补贴池
     * @param array $teamLeaderResults 团长补贴结果
     * @param array $integralUserResults 积分补贴结果
     */
    private function saveDividendData(
        $dividendStatisticsModel,
        $userDividendRecordModel,
        $dividendDate,
        $merId,
        $totalHandlingFee,
        $teamLeaderPool,
        $integralPool,
        $teamLeaderResults,
        $integralUserResults
    ) {
        try {
            // 开启事务
            Db::startTrans();
            
            // 计算实际补贴总额
            $totalDividendAmount = array_sum(array_column($teamLeaderResults, 'dividend_amount')) + 
                                 array_sum(array_column($integralUserResults, 'dividend_amount'));
            
            // 创建补贴统计记录
            $statisticsData = [
                'dividend_date' => $dividendDate,
                'mer_id' => $merId,
                'total_handling_fee' => $totalHandlingFee,
                'team_leader_pool' => $teamLeaderPool,
                'integral_pool' => $integralPool,
                'team_leader_count' => count($teamLeaderResults),
                'integral_user_count' => count($integralUserResults),
                'total_dividend_amount' => $totalDividendAmount,
                'status' => 1,
                'remark' => '系统自动补贴'
            ];
            
            $statisticsId = $dividendStatisticsModel->createRecord($statisticsData);
            Log::info("创建补贴统计记录，ID: {$statisticsId}");
            
            // 准备用户补贴记录数据
            $userRecords = [];
            
            // 处理团长补贴记录
            foreach ($teamLeaderResults as $leader) {
                $userRecords[] = [
                    'statistics_id' => $statisticsId,
                    'dividend_date' => $dividendDate,
                    'mer_id' => $merId,
                    'uid' => $leader['uid'],
                    'phone' => $leader['phone'],
                    'nickname' => $leader['nickname'],
                    'dividend_type' => UserDividendRecord::DIVIDEND_TYPE_TEAM_LEADER,
                    'user_level' => $leader['level'],
                    'user_integral' => 0,
                    'weight_percent' => 0,
                    'dividend_amount' => $leader['dividend_amount'],
                    'status' => 1,
                    'remark' => '团长补贴(30%)',
                    'create_time' => time(),
                    'update_time' => time()
                ];
            }
            
            // 处理积分补贴记录
            foreach ($integralUserResults as $user) {
                $userRecords[] = [
                    'statistics_id' => $statisticsId,
                    'dividend_date' => $dividendDate,
                    'mer_id' => $merId,
                    'uid' => $user['uid'],
                    'phone' => $user['phone'],
                    'nickname' => $user['nickname'],
                    'dividend_type' => UserDividendRecord::DIVIDEND_TYPE_INTEGRAL,
                    'user_level' => 0,
                    'user_integral' => $user['integral'],
                    'weight_percent' => $user['weight_percent'],
                    'dividend_amount' => $user['dividend_amount'],
                    'status' => 1,
                    'remark' => '积分补贴(70%)',
                    'create_time' => time(),
                    'update_time' => time()
                ];
            }
            
            // 批量插入用户补贴记录
            if (!empty($userRecords)) {
                $userDividendRecordModel->createBatch($userRecords);
                Log::info("创建用户补贴记录: " . count($userRecords) . " 条");
            }
            
            // 提交事务
            Db::commit();
            Log::info("补贴数据保存成功!");
            
            return [
                'total_handling_fee' => $totalHandlingFee,
                'dividend_date' => $yesterday,
                'user_records_count' => count($userRecords),
                'message' => '补贴数据保存成功'
            ];
            
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            $errorMsg = '保存补贴数据失败: ' . $e->getMessage();
            Log::error($errorMsg);
            Log::info($errorMsg);
            throw $e;
        }
    }
}