<?php
// +----------------------------------------------------------------------
// | 分销团队级别统计系统 - 补贴任务(没有15%增长的限制，每天执行)
// +----------------------------------------------------------------------

namespace app\controller\api\dividend;

use app\common\model\store\order\StoreOrder;
use app\common\model\user\User as UserModer;
use app\common\model\system\DividendStatistics;
use app\common\model\system\UserDividendRecord;
use app\common\model\user\User;
use app\common\repositories\user\UserBillRepository;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use think\facade\Log;
use crmeb\basic\BaseController;
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
            $orderModel = new StoreOrder();
            $dividendStatisticsModel = new DividendStatistics();
            $userDividendRecordModel = new UserDividendRecord();
            
            // 获取昨天的日期范围
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $yesterdayStart = $yesterday . ' 00:00:00';
            $yesterdayEnd = $yesterday . ' 23:59:59';
            
            Log::info("补贴日期: {$yesterday}");
            
            // 统计昨天商家980订单的handling_fee总额
            $totalHandlingFee = $orderModel
                ->where('paid', 1)->where('offline_audit_status', 1)
                ->where('mer_id', 980)
                ->where('pay_time', 'between', [$yesterdayStart, $yesterdayEnd])
                ->sum('handling_fee');
            
            if ($totalHandlingFee <= 0) {
                Log::info('商家980昨天没有handling_fee可分配');
                Log::info('补贴任务完成 - 无可分配金额', ['date' => $yesterday, 'total_handling_fee' => 0, 'mer_id' => 980]);
                return 0;
            }
            
            Log::info("商家980昨天总手续费: {$totalHandlingFee}");
            
            // 获取所有团长（V1及以上级别的用户,group_id不为5）
            $teamLeaders = $userDianModel
                ->where('group_id', '<>', 5)
                ->where('status', 1)
                ->field('uid,nickname,group_id,phone')
                ->select()
                ->toArray();

            // 获取所有有权益值的用户（用于70%补贴）
            $equityUsers = $userDianModel
                ->where('equity_value', '>', 0)
                ->where('status', 1)
                ->field('uid,nickname,equity_value,phone')
                ->order('equity_value', 'desc')
                ->select()
                ->toArray();
            
            Log::info("团长数量: " . count($teamLeaders));
            Log::info("有权益值用户数量: " . count($equityUsers));
            
            // 计算分红基数（总手续费的20%）
            $dividendBase = $totalHandlingFee * 0.20;
            Log::info("分红基数(总手续费20%): {$dividendBase}");
            
            // 从配置文件获取分配比例
            $teamLeaderRate = config('threshold_dividend.allocation.team_leader_rate', 0.30);
            $integralRate = config('threshold_dividend.allocation.integral_rate', 0.70);
            $teamLeaderPercent = (int)($teamLeaderRate * 100);
            $integralPercent = (int)($integralRate * 100);
            
            // 计算团长补贴池（基数的30%）
            $teamLeaderPool = $dividendBase * $teamLeaderRate;
            
            // 执行团队分红逻辑
            $teamDividendResult = $this->executeTeamDividend($teamLeaderPool, $userDianModel);
            $remainingTeamLeaderPool = $teamDividendResult['remaining_amount'];
            
            Log::info("\n{$teamLeaderPercent}%团长补贴池: {$teamLeaderPool}");
            Log::info("团队分红已分配: {$teamDividendResult['distributed_amount']}");
            Log::info("团长补贴池剩余: {$remainingTeamLeaderPool}");
            
            // 剩余团长补贴平分给所有团长
            $teamLeaderCount = count($teamLeaders);
            $dividendPerLeader = $teamLeaderCount > 0 ? $remainingTeamLeaderPool / $teamLeaderCount : 0;
            Log::info("每个团长补贴: {$dividendPerLeader}");
            
            // 为团长分配补贴
            $teamLeaderResults = [];
            foreach ($teamLeaders as $leader) {
                $dividendAmount = round($dividendPerLeader, 2);
                
                // 更新用户的coupon_amount字段
                $this->updateUserCouponAmount($leader['phone'], $dividendAmount, $userDianModel);
                
                $teamLeaderResults[] = [
                    'uid' => $leader['uid'],
                    'nickname' => $leader['nickname'],
                    'level' => $leader['group_id'],
                    'phone' => $leader['phone'],
                    'dividend_amount' => $dividendAmount
                ];
                
                Log::info("团长 {$leader['nickname']}({$leader['uid']}) 补贴: {$dividendAmount}");
            }
            
            // 计算权益补贴池（基数的70%）
            $integralPool = $dividendBase * $integralRate;
            $integralUserResults = [];
            
            Log::info("\n{$integralPercent}%权益补贴池: {$integralPool}");

            if (count($equityUsers) > 0) {
                // 计算总权益值
                $totalEquityValue = array_sum(array_column($equityUsers, 'equity_value'));

                if ($totalEquityValue > 0) {
                    // 使用权益值比例分配补贴
                    $integralUserResults = $this->distributeEquityDividend($equityUsers, $integralPool, $totalEquityValue, $userDianModel);
                }
            }
            
            // 输出统计结果
            Log::info("\n补贴任务完成:");
            Log::info("- 补贴日期: {$yesterday}");
            Log::info("- 总手续费: {$totalHandlingFee}");
            Log::info("- 分红基数(20%): {$dividendBase}");
            Log::info("- 团长补贴池({$teamLeaderPercent}%): {$teamLeaderPool}");
            Log::info("- 团队分红已分配: {$teamDividendResult['distributed_amount']}");
            Log::info("- 团长补贴池剩余: {$remainingTeamLeaderPool}");
            Log::info("- 权益补贴池({$integralPercent}%): {$integralPool}");
            Log::info("- 团长补贴人数: " . count($teamLeaderResults));
            Log::info("- 权益补贴人数: " . count($integralUserResults));
            
            // 保存补贴统计数据到数据库
            $this->saveDividendData(
                $dividendStatisticsModel,
                $userDividendRecordModel,
                $yesterday,
                980, // 商户ID
                $totalHandlingFee,
                $dividendBase,
                $teamLeaderPool,
                $remainingTeamLeaderPool,
                $integralPool,
                $teamLeaderResults,
                $integralUserResults,
                $teamDividendResult
            );
            
            // 记录到日志
            Log::info('补贴任务完成', [
                'date' => $yesterday,
                'total_handling_fee' => $totalHandlingFee,
                'dividend_base' => $dividendBase,
                'team_leader_pool' => $teamLeaderPool,
                'team_dividend_distributed' => $teamDividendResult['distributed_amount'],
                'remaining_team_leader_pool' => $remainingTeamLeaderPool,
                'integral_pool' => $integralPool,
                'team_leader_count' => count($teamLeaderResults),
                //'integral_user_count' => count($integralUserResults),
                'team_dividend_count' => count($teamDividendResult['records'])
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
     * 更新用户的coupon_amount字段
     */
    private function updateUserCouponAmount($phone, $dividendAmount, $userModel)
    {
        try {
            $user = $userModel->where('phone', $phone)->find();
            if ($user) {
                $newCouponAmount = $user['coupon_amount'] + $dividendAmount;
                $userModel->where('uid', $user['uid'])->update([
                    'coupon_amount' => $newCouponAmount,
                ]);
                
                // 用户抵用券记录
                $userBillRepository = app()->make(UserBillRepository::class);
                $userBillRepository->incBill($user['uid'], 'coupon_amount', 'exchange', [
                    'link_id' => 0,
                    'status' => 1,
                    'title' => '获得分红抵用券',
                    'number' => $dividendAmount,
                    'mark' => '分红获得抵用券' . (float)$dividendAmount,
                    'balance' => $newCouponAmount
                ]);
            }
        } catch (\Exception $e) {
            Log::error("更新用户抵用券失败 - 手机号: {$phone}, 金额: {$dividendAmount}.错误信息:{$e->getMessage()}");
        }
    }
    
    /**
     * 执行团队分红逻辑
     * 按级别递进分配，每次分配5%，最多分4次
     */
    private function executeTeamDividend($teamLeaderPool, $userModel)
    {
        $distributedAmount = 0;
        $teamDividendRecords = [];
        $distributionRate = 0.05; // 每次分配5%
        $maxDistributions = 4; // 最多分配4次
        
        Log::info("\n开始执行团队分红逻辑:");
        
        for ($level = 1; $level <= $maxDistributions; $level++) {
            // 计算当前分配金额
            $currentDistributionAmount = $teamLeaderPool * $distributionRate;
            
            // 查询当前级别和下一级别的用户
            $currentLevelUsers = $userModel
                ->where('group_id', $level)
                ->where('status', 1)
                ->field('uid,nickname,group_id,phone')
                ->select()
                ->toArray();
                
            $nextLevelUsers = [];
            if ($level < 5) { // 确保不超过最大级别
                $nextLevelUsers = $userModel
                    ->where('group_id', $level + 1)
                    ->where('status', 1)
                    ->field('uid,nickname,group_id,phone')
                    ->select()
                    ->toArray();
            }
            
            // 合并当前级别和下一级别用户
            $eligibleUsers = array_merge($currentLevelUsers, $nextLevelUsers);
            $userCount = count($eligibleUsers);
            
            if ($userCount > 0) {
                $dividendPerUser = $currentDistributionAmount / $userCount;
                
                Log::info("第{$level}轮分红 - {$level}级和" . ($level + 1) . "级用户:");
                Log::info("- 分配金额: {$currentDistributionAmount}");
                Log::info("- 符合条件用户数: {$userCount}");
                Log::info("- 每人分红: {$dividendPerUser}");
                
                // 为符合条件的用户分配分红
                foreach ($eligibleUsers as $user) {
                    $dividendAmount = round($dividendPerUser, 2);
                    
                    // 更新用户的coupon_amount字段
                    $this->updateUserCouponAmount($user['phone'], $dividendAmount, $userModel);
                    
                    $teamDividendRecords[] = [
                        'uid' => $user['uid'],
                        'nickname' => $user['nickname'],
                        'level' => $user['group_id'],
                        'phone' => $user['phone'],
                        'dividend_amount' => $dividendAmount,
                        'distribution_round' => $level,
                        'distribution_type' => "团队分红第{$level}轮"
                    ];
                    
                    Log::info("  用户 {$user['nickname']}({$user['uid']}) {$user['group_id']}级 分红: {$dividendAmount}");
                }
                
                $distributedAmount += $currentDistributionAmount;
            } else {
                Log::info("第{$level}轮分红 - 无符合条件用户，跳过");
            }
        }
        
        $remainingAmount = $teamLeaderPool - $distributedAmount;
        
        Log::info("\n团队分红完成:");
        Log::info("- 总分红池: {$teamLeaderPool}");
        Log::info("- 已分配: {$distributedAmount}");
        Log::info("- 剩余: {$remainingAmount}");
        Log::info("- 分红记录数: " . count($teamDividendRecords));
        
        return [
            'distributed_amount' => $distributedAmount,
            'remaining_amount' => $remainingAmount,
            'records' => $teamDividendRecords
        ];
    }

    /**
     * 权益值补贴算法
     * 根据用户权益值权重分配补贴金额
     */
    private function distributeEquityDividend($users, $totalAmount, $totalEquityValue, $userModel)
    {
        $results = [];
        $distributedAmount = 0;

        Log::info("\n开始权益值补贴分配:");
        Log::info("- 补贴池总额: {$totalAmount}");
        Log::info("- 总权益值: {$totalEquityValue}");
        Log::info("- 用户数量: " . count($users));

        foreach ($users as $user) {
            // 计算用户权益值占比
            $equityRatio = $user['equity_value'] / $totalEquityValue;

            // 计算应得补贴金额
            $dividendAmount = $totalAmount * $equityRatio;
            $dividendAmount = round($dividendAmount, 2);

            // 更新用户的coupon_amount字段
            $this->updateUserCouponAmount($user['phone'], $dividendAmount, $userModel);

            $results[] = [
                'uid' => $user['uid'],
                'nickname' => $user['nickname'],
                'equity_value' => $user['equity_value'],
                'phone' => $user['phone'],
                'dividend_amount' => $dividendAmount,
                'equity_ratio' => round($equityRatio * 100, 4) // 权益占比百分比
            ];

            Log::info("权益用户 {$user['nickname']}({$user['uid']}) 权益值: {$user['equity_value']}, 占比: " . round($equityRatio * 100, 4) . "%, 补贴: {$dividendAmount}");

            $distributedAmount += $dividendAmount;
        }

        Log::info("\n权益补贴分配完成:");
        Log::info("- 实际分配总额: {$distributedAmount}");
        Log::info("- 分配精度差异: " . round($totalAmount - $distributedAmount, 2));

        return $results;
    }
    
    /**
     * 积分补贴算法（类似红包算法）
     * 根据用户积分权重分配补贴金额
     */
    private function distributeIntegralDividend($users, $totalAmount, $totalIntegral, $userModel): array
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
            
            // 更新用户的coupon_amount字段
            $this->updateUserCouponAmount($user['phone'], $dividendAmount, $userModel);
            
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
     * @param float $dividendBase 分红基数
     * @param float $teamLeaderPool 团长补贴池
     * @param float $remainingTeamLeaderPool 剩余团长补贴池
     * @param float $integralPool 权益补贴池
     * @param array $teamLeaderResults 团长补贴结果
     * @param array $integralUserResults 权益补贴结果
     * @param array $teamDividendResult 团队分红结果
     */
    private function saveDividendData(
        $dividendStatisticsModel,
        $userDividendRecordModel,
        $dividendDate,
        $merId,
        $totalHandlingFee,
        $dividendBase,
        $teamLeaderPool,
        $remainingTeamLeaderPool,
        $integralPool,
        $teamLeaderResults,
        $integralUserResults,
        $teamDividendResult
    ): void
    {
        try {
            // 从配置文件获取分配比例用于备注
            $teamLeaderRate = config('threshold_dividend.allocation.team_leader_rate', 0.30);
            $integralRate = config('threshold_dividend.allocation.integral_rate', 0.70);
            $teamLeaderPercent = intval($teamLeaderRate * 100);
            $integralPercent = intval($integralRate * 100);
            
            // 开启事务
            Db::startTrans();
            
            // 计算实际补贴总额
            $teamDividendAmount = array_sum(array_column($teamDividendResult['records'], 'dividend_amount'));
            $teamLeaderAmount = array_sum(array_column($teamLeaderResults, 'dividend_amount'));
            $integralAmount = array_sum(array_column($integralUserResults, 'dividend_amount'));
            $totalDividendAmount = $teamDividendAmount + $teamLeaderAmount + $integralAmount;
            
            // 创建补贴统计记录
            $statisticsData = [
                'dividend_date' => $dividendDate,
                'mer_id' => $merId,
                'total_handling_fee' => $totalHandlingFee,
                'dividend_base' => $dividendBase,
                'team_leader_pool' => $teamLeaderPool,
                'team_dividend_amount' => $teamDividendResult['distributed_amount'],
                'remaining_team_leader_pool' => $remainingTeamLeaderPool,
                'integral_pool' => $integralPool,
                'team_leader_count' => count($teamLeaderResults),
                'integral_user_count' => count($integralUserResults),
                'team_dividend_count' => count($teamDividendResult['records']),
                'total_dividend_amount' => $totalDividendAmount,
                'status' => 1,
                'remark' => '系统自动补贴(含团队分红)'
            ];
            
            $statisticsId = $dividendStatisticsModel->createRecord($statisticsData);
            Log::info("创建补贴统计记录，ID: {$statisticsId}");
            
            // 准备用户补贴记录数据
            $userRecords = [];
            
            // 处理团队分红记录
            foreach ($teamDividendResult['records'] as $teamRecord) {
                $userRecords[] = [
                    'statistics_id' => $statisticsId,
                    'dividend_date' => $dividendDate,
                    'mer_id' => $merId,
                    'uid' => $teamRecord['uid'],
                    'phone' => $teamRecord['phone'],
                    'nickname' => $teamRecord['nickname'],
                    'dividend_type' => 'team_dividend',
                    'user_level' => $teamRecord['level'],
                    'user_integral' => 0,
                    'weight_percent' => 0,
                    'dividend_amount' => $teamRecord['dividend_amount'],
                    'status' => 1,
                    'remark' => $teamRecord['distribution_type'],
                    'create_time' => time(),
                    'update_time' => time()
                ];
            }
            
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
                    'remark' => "团长补贴({$teamLeaderPercent}%剩余)",
                    'create_time' => time(),
                    'update_time' => time()
                ];
            }

            // 处理权益补贴记录
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
                    'user_integral' => $user['equity_value'], // 存储权益值
                    'weight_percent' => $user['equity_ratio'], // 存储权益占比
                    'dividend_amount' => $user['dividend_amount'],
                    'status' => 1,
                    'remark' => "权益补贴({$integralPercent}%)",
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

            [
                'total_handling_fee' => $totalHandlingFee,
                'dividend_base' => $dividendBase,
                'dividend_date' => $dividendDate,
                'team_dividend_amount' => $teamDividendResult['distributed_amount'],
                'remaining_team_leader_pool' => $remainingTeamLeaderPool,
                'user_records_count' => count($userRecords),
                'message' => '补贴数据保存成功'
            ];
            return;
            
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