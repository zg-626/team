<?php
// +----------------------------------------------------------------------
// | 分销团队级别统计系统 - 阈值补贴任务
// +----------------------------------------------------------------------

namespace app\controller\api\dividend;

use app\common\model\store\order\StoreOrder;
use app\common\model\user\User as UserModer;
use app\common\model\system\DividendStatistics;
use app\common\model\system\UserDividendRecord;
use app\common\model\user\User;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use think\facade\Log;
use crmeb\basic\BaseController;

/**
 * 阈值补贴任务
 * 基于阈值和增长率的补贴分配系统
 */
class ThresholdDividends extends BaseController
{
    /**
     * 阈值补贴接口
     * @return \think\response\Json
     */
    public function index()
    {
        try {
            $result = $this->processThresholdDividends();
            return app('json')->success($result, '阈值补贴任务执行完成');
        } catch (\Exception $e) {
            Log::error('阈值补贴任务执行失败: ' . $e->getMessage());
            return app('json')->fail('阈值补贴任务执行失败: ' . $e->getMessage());
        }
    }

    /**
     * 处理阈值补贴逻辑
     */
    private function processThresholdDividends()
    {
        Log::info('阈值补贴任务开始执行');
        
        try {
            // 获取所有分红池
            $dividendPools = Db::name('dividend_pool')
                ->where('city_id', '<>', 0)
                ->select()
                ->toArray();
            
            $results = [];
            
            foreach ($dividendPools as $pool) {
                $poolResult = $this->processPoolThreshold($pool);
                $results[] = $poolResult;
            }
            
            Log::info('阈值补贴任务执行完成', ['processed_pools' => count($results)]);
            
            return [
                'processed_pools' => count($results),
                'results' => $results
            ];
            
        } catch (\Exception $e) {
            $errorMsg = '阈值补贴任务执行失败: ' . $e->getMessage();
            Log::error($errorMsg);
            throw $e;
        }
    }
    
    /**
     * 处理单个分红池的阈值检查
     * @param array $pool 分红池数据
     * @return array
     */
    public function processPoolThreshold($pool)
    {
        $poolId = $pool['id'];
        $cityId = $pool['city_id'];
        $initialThreshold = $pool['initial_threshold'];
        
        Log::info("处理分红池 {$poolId} 的阈值补贴", ['city_id' => $cityId, 'initial_threshold' => $initialThreshold]);
        
        try {
            // 获取当前分红池的40%手续费累计金额
            $currentAmount = $this->getCurrentPoolAmount($poolId);
            
            // 获取最后一次补贴记录
            $lastDividend = $this->getLastDividendRecord($poolId);
            
            // 计算需要执行的补贴次数
            $dividendTimes = $this->calculateDividendTimes($currentAmount, $lastDividend, $initialThreshold);
            
            $executedDividends = [];
            
            if ($dividendTimes > 0) {
                for ($i = 0; $i < $dividendTimes; $i++) {
                    $dividendResult = $this->executeSingleDividend($pool, $lastDividend, $i + 1);
                    $executedDividends[] = $dividendResult;
                    
                    // 更新最后补贴记录用于下次计算
                    $lastDividend = $dividendResult;
                }
            }
            
            return [
                'pool_id' => $poolId,
                'city_id' => $cityId,
                'current_amount' => $currentAmount,
                'dividend_times' => $dividendTimes,
                'executed_dividends' => $executedDividends,
                'success' => true
            ];
            
        } catch (\Exception $e) {
            Log::error("分红池 {$poolId} 处理失败: " . $e->getMessage());
            return [
                'pool_id' => $poolId,
                'city_id' => $cityId,
                'error' => $e->getMessage(),
                'success' => false
            ];
        }
    }
    
    /**
     * 获取当前分红池的40%手续费累计金额
     * @param int $poolId
     * @return float
     */
    private function getCurrentPoolAmount($poolId)
    {
        // 从分红池表获取当前可用金额（这里假设available_amount就是40%的手续费累计）
        $pool = Db::name('dividend_pool')->where('id', $poolId)->find();
        return $pool ? (float)$pool['available_amount'] : 0;
    }
    
    /**
     * 获取最后一次补贴记录
     * @param int $poolId
     * @return array|null
     */
    private function getLastDividendRecord($poolId)
    {
        return Db::name('threshold_dividend_log')
            ->where('pool_id', $poolId)
            ->order('id', 'desc')
            ->find();
    }
    
    /**
     * 计算需要执行的补贴次数
     * @param float $currentAmount 当前金额
     * @param array|null $lastDividend 最后一次补贴记录
     * @param float $initialThreshold 初始阈值
     * @return int
     */
    private function calculateDividendTimes($currentAmount, $lastDividend, $initialThreshold)
    {
        if ($lastDividend) {
            // 有历史记录，使用上次的下一个阈值
            $checkThreshold = $lastDividend['next_threshold'];
        } else {
            // 首次补贴，需要达到初始阈值的115%才触发
            $checkThreshold = $initialThreshold * 1.15;
        }
        
        $dividendTimes = 0;
        
        // 循环计算可以执行多少次补贴
        while ($currentAmount >= $checkThreshold) {
            $dividendTimes++;
            // 下次阈值继续增长15%
            $checkThreshold = $checkThreshold * 1.15;
        }
        
        return $dividendTimes;
    }
    
    /**
     * 执行单次补贴
     * @param array $pool 分红池数据
     * @param array|null $lastDividend 上次补贴记录
     * @param int $sequence 补贴序号
     * @return array
     */
    private function executeSingleDividend($pool, $lastDividend, $sequence)
    {
        $poolId = $pool['id'];
        $currentAmount = $this->getCurrentPoolAmount($poolId);
        
        // 计算当前阈值
        if ($lastDividend) {
            $currentThreshold = $lastDividend['next_threshold'];
        } else {
            // 首次补贴，阈值是初始值的115%
            $currentThreshold = $pool['initial_threshold'] * 1.15;
        }
        
        // 计算下次阈值（增长15%）
        $nextThreshold = $currentThreshold * 1.15;
        
        // 开启事务（设置较短的锁等待超时）
        Db::startTrans();
        
        try {
            // 设置当前会话的锁等待超时时间为10秒
            Db::execute('SET innodb_lock_wait_timeout = 10');
            
            // 使用SELECT FOR UPDATE锁定分红池记录，避免并发修改
            $currentPool = Db::name('dividend_pool')
                ->where('id', $poolId)
                ->lock(true)
                ->find();
                
            if (!$currentPool) {
                throw new \Exception('分红池记录不存在或已被删除');
            }
            
            // 重新验证当前金额是否仍然满足阈值条件
            $currentAmount = (float)$currentPool['available_amount'];
            if ($currentAmount < $currentThreshold) {
                Log::info("分红池 {$poolId} 当前金额不足，跳过补贴", [
                    'current_amount' => $currentAmount,
                    'required_threshold' => $currentThreshold
                ]);
                Db::rollback();
                return [
                    'log_id' => 0,
                    'threshold_amount' => $currentThreshold,
                    'amount_at_dividend' => $currentAmount,
                    'base_amount' => $baseAmount ?? 0,
                    'next_threshold' => $nextThreshold,
                    'incremental_amount' => 0,
                    'dividend_amount' => 0,
                    'team_leader_count' => 0,
                    'integral_user_count' => 0,
                    'sequence' => $sequence,
                    'skipped' => true,
                    'reason' => '金额不足'
                ];
            }
            // 获取基础保留金额
            $baseAmount = isset($pool['base_amount']) ? $pool['base_amount'] : $pool['initial_threshold'];
            
            // 计算增量补贴金额
            if ($lastDividend) {
                // 有历史记录，计算从上次阈值到当前阈值的增量
                $lastThreshold = $lastDividend['threshold_amount'];
                $incrementalAmount = $currentThreshold - $lastThreshold;
            } else {
                // 首次补贴，计算从基础保留金额到当前阈值的增量
                $incrementalAmount = $currentThreshold - $baseAmount;
            }
            
            // 执行补贴分配（传入增量金额）
            $dividendResult = $this->executeActualDividend($pool, $incrementalAmount);
            
            // 记录补贴日志
            $logId = Db::name('threshold_dividend_log')->insertGetId([
                'pool_id' => $poolId,
                'city_id' => $pool['city_id'],
                'threshold_amount' => $currentThreshold,
                'amount_at_dividend' => $currentThreshold, // 记录达到的阈值金额
                'next_threshold' => $nextThreshold,
                'dividend_amount' => $dividendResult['total_dividend'],
                'incremental_amount' => $incrementalAmount, // 新增：记录增量金额
                'team_leader_count' => $dividendResult['team_leader_count'],
                'integral_user_count' => $dividendResult['integral_user_count'],
                'sequence' => $sequence,
                'execute_time' => date('Y-m-d H:i:s'),
                'create_time' => time(),
                'status' => 1
            ]);
            
            // 更新分红池状态
            Db::name('dividend_pool')
                ->where('id', $poolId)
                ->update([
                    'distributed_amount' => Db::raw('distributed_amount + ' . $dividendResult['total_dividend']),
                    'available_amount' => Db::raw('available_amount - ' . $dividendResult['total_dividend']),
                    'last_threshold_amount' => $currentThreshold, // 记录最后达到的阈值
                    'update_time' => date('Y-m-d H:i:s')
                ]);
            
            Db::commit();
            
            Log::info("分红池 {$poolId} 第 {$sequence} 次阈值补贴执行成功", [
                'threshold' => $currentThreshold,
                'incremental_amount' => $incrementalAmount,
                'dividend_amount' => $dividendResult['total_dividend'],
                'next_threshold' => $nextThreshold
            ]);
            
            return [
                'log_id' => $logId,
                'threshold_amount' => $currentThreshold,
                'amount_at_dividend' => $currentThreshold, // 记录达到的阈值金额
                'base_amount' => $baseAmount,
                'next_threshold' => $nextThreshold,
                'incremental_amount' => $incrementalAmount, // 增量金额
                'dividend_amount' => $dividendResult['total_dividend'],
                'team_leader_count' => $dividendResult['team_leader_count'],
                'integral_user_count' => $dividendResult['integral_user_count'],
                'sequence' => $sequence
            ];
            
        } catch (\Exception $e) {
            Db::rollback();
            
            // 特殊处理锁等待超时异常
            if (strpos($e->getMessage(), 'Lock wait timeout exceeded') !== false) {
                Log::warning("分红池 {$poolId} 补贴处理遇到锁等待超时，稍后重试", [
                    'sequence' => $sequence,
                    'threshold' => $currentThreshold
                ]);
                
                return [
                    'log_id' => 0,
                    'threshold_amount' => $currentThreshold,
                    'amount_at_dividend' => 0,
                    'base_amount' => $baseAmount ?? 0,
                    'next_threshold' => $nextThreshold,
                    'incremental_amount' => 0,
                    'dividend_amount' => 0,
                    'team_leader_count' => 0,
                    'integral_user_count' => 0,
                    'sequence' => $sequence,
                    'skipped' => true,
                    'reason' => '锁等待超时，稍后重试'
                ];
            }
            
            throw $e;
        }
    }
    
    /**
     * 执行实际的补贴分配（复用原有逻辑）
     * @param array $pool 分红池数据
     * @param float $dividendAmount 补贴金额
     * @return array
     */
    private function executeActualDividend($pool, $dividendAmount)
    {
        $userDianModel = new UserModer();
        $dividendStatisticsModel = new DividendStatistics();
        $userDividendRecordModel = new UserDividendRecord();
        
        // 获取所有团长（V1及以上级别的用户）
        $teamLeaders = $userDianModel
            ->where('team_level', '>=', 1)
            ->where('status', 1)
            ->field('uid,nickname,team_level,phone')
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
        
        // 计算30%团长补贴
        $teamLeaderPool = $dividendAmount * 0.3;
        $teamLeaderCount = count($teamLeaders);
        $dividendPerLeader = $teamLeaderCount > 0 ? $teamLeaderPool / $teamLeaderCount : 0;
        
        // 为团长分配30%补贴
        $teamLeaderResults = [];
        foreach ($teamLeaders as $leader) {
            $leaderDividend = round($dividendPerLeader, 2);
            
            // 更新用户的brokerage_price字段
            $this->updateUserBrokeragePrice($leader['phone'], $leaderDividend, $userDianModel);
            
            $teamLeaderResults[] = [
                'uid' => $leader['uid'],
                'nickname' => $leader['nickname'],
                'level' => $leader['team_level'],
                'phone' => $leader['phone'],
                'dividend_amount' => $leaderDividend
            ];
        }
        
        // 计算70%积分补贴
        $integralPool = $dividendAmount * 0.7;
        $integralUserResults = [];
        
        if (count($integralUsers) > 0) {
            // 计算总积分权重
            $totalIntegral = array_sum(array_column($integralUsers, 'integral'));
            
            if ($totalIntegral > 0) {
                // 使用红包算法分配积分补贴
                $integralUserResults = $this->distributeIntegralDividend($integralUsers, $integralPool, $totalIntegral, $userDianModel);
            }
        }
        
        // 计算实际补贴总额
        $totalDividendAmount = array_sum(array_column($teamLeaderResults, 'dividend_amount')) + 
                             array_sum(array_column($integralUserResults, 'dividend_amount'));
        
        // 保存补贴统计数据
        $this->saveThresholdDividendData(
            $dividendStatisticsModel,
            $userDividendRecordModel,
            date('Y-m-d'),
            980, // 固定商户ID
            $dividendAmount,
            $teamLeaderPool,
            $integralPool,
            $teamLeaderResults,
            $integralUserResults,
            $pool['id']
        );
        
        return [
            'total_dividend' => $totalDividendAmount,
            'team_leader_count' => count($teamLeaderResults),
            'integral_user_count' => count($integralUserResults),
            'team_leader_pool' => $teamLeaderPool,
            'integral_pool' => $integralPool
        ];
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
                $userModel->where('id', $user['uid'])->update([
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
     */
    private function distributeIntegralDividend($users, $totalAmount, $totalIntegral, $userModel)
    {
        $results = [];
        $remainingAmount = $totalAmount;
        $remainingUsers = count($users);
        
        foreach ($users as $index => $user) {
            if ($remainingUsers == 1) {
                $dividendAmount = $remainingAmount;
            } else {
                $weight = $user['integral'] / $totalIntegral;
                $baseAmount = $totalAmount * $weight;
                $randomFactor = mt_rand(80, 120) / 100;
                $dividendAmount = $baseAmount * $randomFactor;
                
                $maxAmount = $remainingAmount * 0.8;
                if ($dividendAmount > $maxAmount) {
                    $dividendAmount = $maxAmount;
                }
                
                if ($dividendAmount < 0.01) {
                    $dividendAmount = 0.01;
                }
            }
            
            $dividendAmount = round($dividendAmount, 2);
            
            $this->updateUserBrokeragePrice($user['phone'], $dividendAmount, $userModel);
            
            $results[] = [
                'uid' => $user['uid'],
                'nickname' => $user['nickname'],
                'integral' => $user['integral'],
                'phone' => $user['phone'],
                'dividend_amount' => $dividendAmount,
                'weight_percent' => round(($user['integral'] / $totalIntegral) * 100, 2)
            ];
            
            $remainingAmount -= $dividendAmount;
            $remainingUsers--;
            
            if ($remainingAmount < 0) {
                $remainingAmount = 0;
            }
        }
        
        return $results;
    }
    
    /**
     * 保存阈值补贴数据到数据库
     */
    private function saveThresholdDividendData(
        $dividendStatisticsModel,
        $userDividendRecordModel,
        $dividendDate,
        $merId,
        $totalHandlingFee,
        $teamLeaderPool,
        $integralPool,
        $teamLeaderResults,
        $integralUserResults,
        $poolId
    ) {
        try {
            // 计算实际补贴总额
            $totalDividendAmount = array_sum(array_column($teamLeaderResults, 'dividend_amount')) + 
                                 array_sum(array_column($integralUserResults, 'dividend_amount'));
            
            // 检查是否已存在相同记录
            $existingRecord = $dividendStatisticsModel->getByDateAndMer($dividendDate, $merId);
            if ($existingRecord) {
                Log::warning("阈值补贴统计记录已存在", [
                    'date' => $dividendDate,
                    'mer_id' => $merId,
                    'existing_id' => $existingRecord['id']
                ]);
                return $existingRecord;
            }
            
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
                'remark' => "阈值补贴-分红池{$poolId}"
            ];
            
            $statisticsId = $dividendStatisticsModel->createRecord($statisticsData);
            
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
                    'remark' => "阈值团长补贴(30%)-池{$poolId}",
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
                    'remark' => "阈值积分补贴(70%)-池{$poolId}",
                    'create_time' => time(),
                    'update_time' => time()
                ];
            }
            
            // 批量插入用户补贴记录
            if (!empty($userRecords)) {
                $userDividendRecordModel->createBatch($userRecords);
            }
            
            Log::info("阈值补贴数据保存成功", [
                'statistics_id' => $statisticsId,
                'pool_id' => $poolId,
                'user_records_count' => count($userRecords)
            ]);
            
            return [
                'statistics_id' => $statisticsId,
                'total_dividend_amount' => $totalDividendAmount,
                'user_records_count' => count($userRecords)
            ];
            
        } catch (\Exception $e) {
            $errorMsg = '保存阈值补贴数据失败: ' . $e->getMessage();
            Log::error($errorMsg);
            throw $e;
        }
    }
    
    /**
     * 获取所有分红池状态
     * @param Request $request
     * @return \think\Response
     */
    public function getPoolsStatus(Request $request)
    {
        try {
            $service = new \app\common\services\dividend\ThresholdDividendService();
            $poolStatus = $service->getPoolStatus();
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $poolStatus
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取分红池状态失败: ' . $e->getMessage());
            
            return json([
                'code' => 500,
                'msg' => '获取失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 获取单个分红池详情
     * @param Request $request
     * @return \think\Response
     */
    public function getPoolDetail(Request $request)
    {
        try {
            $poolId = $request->param('id');
            
            if (!$poolId) {
                return json(['code' => 400, 'msg' => '分红池ID不能为空']);
            }
            
            $service = new \app\common\services\dividend\ThresholdDividendService();
            $poolStatus = $service->getPoolStatus($poolId);
            
            if (empty($poolStatus)) {
                return json(['code' => 404, 'msg' => '分红池不存在']);
            }
            
            // 获取最近的补贴记录
            $recentDividends = Db::name('threshold_dividend_log')
                ->where('pool_id', $poolId)
                ->order('id', 'desc')
                ->limit(10)
                ->select()
                ->toArray();
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'pool_info' => $poolStatus[0],
                    'recent_dividends' => $recentDividends
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取分红池详情失败: ' . $e->getMessage());
            
            return json([
                'code' => 500,
                'msg' => '获取失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 获取阈值补贴历史记录
     * @param Request $request
     * @return \think\Response
     */
    public function getHistory(Request $request)
    {
        try {
            $page = $request->param('page', 1);
            $limit = $request->param('limit', 20);
            $poolId = $request->param('pool_id');
            $startDate = $request->param('start_date');
            $endDate = $request->param('end_date');
            
            $query = Db::name('threshold_dividend_log');
            
            // 添加筛选条件
            if ($poolId) {
                $query->where('pool_id', $poolId);
            }
            
            if ($startDate) {
                $query->where('execute_time', '>=', $startDate);
            }
            
            if ($endDate) {
                $query->where('execute_time', '<=', $endDate . ' 23:59:59');
            }
            
            // 获取总数
            $total = $query->count();
            
            // 获取分页数据
            $list = $query->order('id', 'desc')
                ->page($page, $limit)
                ->select()
                ->toArray();
            
            // 关联分红池信息
            foreach ($list as &$item) {
                $pool = Db::name('dividend_pool')
                    ->where('id', $item['pool_id'])
                    ->field('city_id,city')
                    ->find();
                
                $item['city_info'] = $pool;
            }
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'list' => $list,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取补贴历史失败: ' . $e->getMessage());
            
            return json([
                'code' => 500,
                'msg' => '获取失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 获取补贴统计数据
     * @param Request $request
     * @return \think\Response
     */
    public function getStatistics(Request $request)
    {
        try {
            $days = $request->param('days', 30);
            $startDate = date('Y-m-d', strtotime("-{$days} days"));
            
            // 总体统计
            $totalStats = Db::name('threshold_dividend_log')
                ->where('execute_time', '>=', $startDate)
                ->field([
                    'COUNT(*) as total_count',
                    'SUM(dividend_amount) as total_amount',
                    'SUM(team_leader_count) as total_team_leaders',
                    'SUM(integral_user_count) as total_integral_users',
                    'COUNT(DISTINCT pool_id) as active_pools'
                ])
                ->find();
            
            // 按日统计
            $dailyStats = Db::name('threshold_dividend_log')
                ->where('execute_time', '>=', $startDate)
                ->field([
                    'DATE(execute_time) as date',
                    'COUNT(*) as count',
                    'SUM(dividend_amount) as amount'
                ])
                ->group('DATE(execute_time)')
                ->order('date', 'desc')
                ->select()
                ->toArray();
            
            // 按城市统计
            $cityStats = Db::name('threshold_dividend_log')
                ->alias('tdl')
                ->join('dividend_pool dp', 'tdl.pool_id = dp.id')
                ->where('tdl.execute_time', '>=', $startDate)
                ->field([
                    'dp.city',
                    'dp.city_id',
                    'COUNT(*) as count',
                    'SUM(tdl.dividend_amount) as amount'
                ])
                ->group('dp.city_id')
                ->order('amount', 'desc')
                ->select()
                ->toArray();
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'period' => [
                        'start_date' => $startDate,
                        'end_date' => date('Y-m-d'),
                        'days' => $days
                    ],
                    'total' => $totalStats,
                    'daily' => $dailyStats,
                    'by_city' => $cityStats
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取补贴统计失败: ' . $e->getMessage());
            
            return json([
                'code' => 500,
                'msg' => '获取失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 获取公开分红池信息（无敏感数据）
     * @param Request $request
     * @return \think\Response
     */
    public function getPublicPoolInfo(Request $request)
    {
        try {
            $service = new \app\common\services\dividend\ThresholdDividendService();
            $poolStatus = $service->getPoolStatus();
            
            // 过滤敏感信息，只返回公开数据
            $publicData = array_map(function($pool) {
                return [
                    'city_name' => $pool['city_name'],
                    'progress_percent' => $pool['progress_percent'],
                    'total_dividends' => $pool['total_dividends'],
                    'last_dividend_time' => $pool['last_dividend_time']
                ];
            }, $poolStatus);
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $publicData
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取公开分红池信息失败: ' . $e->getMessage());
            
            return json([
                'code' => 500,
                'msg' => '获取失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 获取补贴公告
     * @param Request $request
     * @return \think\Response
     */
    public function getAnnouncements(Request $request)
    {
        try {
            $limit = $request->param('limit', 10);
            
            // 获取最近的补贴记录作为公告
            $announcements = Db::name('threshold_dividend_log')
                ->alias('tdl')
                ->join('dividend_pool dp', 'tdl.pool_id = dp.id')
                ->field([
                    'tdl.execute_time',
                    'tdl.dividend_amount',
                    'tdl.team_leader_count',
                    'tdl.integral_user_count',
                    'dp.city'
                ])
                ->order('tdl.id', 'desc')
                ->limit($limit)
                ->select()
                ->toArray();
            
            // 格式化公告内容
            $formattedAnnouncements = array_map(function($item) {
                return [
                    'time' => $item['execute_time'],
                    'title' => $item['city'] . '地区补贴发放',
                    'content' => sprintf(
                        '本次共发放补贴 %.2f 元，惠及团长 %d 人，积分用户 %d 人',
                        $item['dividend_amount'],
                        $item['team_leader_count'],
                        $item['integral_user_count']
                    )
                ];
            }, $announcements);
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $formattedAnnouncements
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取补贴公告失败: ' . $e->getMessage());
            
            return json([
                'code' => 500,
                'msg' => '获取失败: ' . $e->getMessage()
            ]);
        }
    }
}