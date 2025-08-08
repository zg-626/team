<?php
// +----------------------------------------------------------------------
// | 分销团队级别统计系统 - 批量级别更新任务（优化版）
// +----------------------------------------------------------------------

namespace app\controller\api\dividend;

use app\common\model\store\order\StoreOrderOffline;
use app\common\model\user\User;
use think\console\Input;
use think\console\Output;
use think\facade\Log;
use think\facade\Db;
use think\facade\Cache;

/**
 * 批量级别更新任务（优化版）
 * 根据新的升级条件批量更新所有用户级别
 * 优化特性：缓存机制、事务处理、性能监控、数据一致性保障
 */
class BatchUpdateLevels extends BaseController
{
    // 缓存键前缀
    private $cachePrefix = 'batch_update_levels_';
    
    // 性能统计
    private $performanceStats = [
        'start_time' => 0,
        'query_count' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0
    ];

    /**
     * 批量更新用户级别接口
     * @return \think\response\Json
     */
    public function index()
    {
        $isDryRun = $this->request->param('dry_run', false);
        $forceUpdate = $this->request->param('force_update', false);
        
        try {
            $result = $this->batchUpdateLevels($isDryRun, $forceUpdate);
            return app('json')->success($result, '批量级别更新任务执行完成');
        } catch (\Exception $e) {
            Log::error('批量级别更新任务执行失败: ' . $e->getMessage());
            return app('json')->fail('批量级别更新任务执行失败: ' . $e->getMessage());
        }
    }

    private function batchUpdateLevels($isDryRun, $forceUpdate)
    {
        $this->performanceStats['start_time'] = microtime(true);
        //$isDryRun = $input->hasOption('dry-run');
        //$forceUpdate = $input->hasOption('force');

        Log::info('开始执行批量级别更新任务（优化版）...');
        if ($isDryRun) {
            Log::info('<info>试运行模式：不会实际更新数据</info>');
        }

        Log::info('批量级别更新任务开始执行', [
            'dry_run' => $isDryRun,
            'force_update' => $forceUpdate
        ]);
        
        try {
            $userModel = new User();
            $orderModel = new StoreOrderOffline();
            
            Log::info("开始批量更新用户级别...");
            
            // 获取在商家980消费过的用户ID（使用缓存优化）
            $consumerUserIds = $this->getConsumerUserIds($orderModel, $forceUpdate);
            
            if (empty($consumerUserIds)) {
                Log::info('商家980没有消费用户，任务结束。');
                return 0;
            }
            
            $totalCount = count($consumerUserIds);
            
            if ($totalCount == 0) {
                Log::info('没有找到用户数据');
                return 0;
            }
            
            Log::info("发现 {$totalCount} 个用户，开始分批处理...");
            
            // 处理结果统计
            $processResult = $this->processBatchUsers(
                $consumerUserIds, 
                $userModel, 
                $orderModel,
                $isDryRun
            );
            
            // 输出最终统计和性能报告
            $this->outputFinalReport($processResult);
            
            return [
                'total_users' => $totalCount,
                'process_result' => $processResult,
                'performance_stats' => $this->performanceStats,
                'dry_run' => $isDryRun,
                'message' => '批量级别更新任务执行完成'
            ];
            
        } catch (\Exception $e) {
            $errorMsg = '批量级别更新任务执行失败: ' . $e->getMessage();
            Log::error($errorMsg, ['trace' => $e->getTraceAsString()]);
            Log::info($errorMsg);
            throw $e;
        }
    }
    
    /**
     * 获取消费用户ID（带缓存优化）
     * @param StoreOrderOffline $orderModel
     * @param bool $forceUpdate
     * @return array
     */
    private function getConsumerUserIds($orderModel, $forceUpdate = false)
    {
        $cacheKey = $this->cachePrefix . 'consumer_user_ids';
        
        if (!$forceUpdate) {
            $cachedIds = Cache::get($cacheKey);
            if ($cachedIds !== false) {
                $this->performanceStats['cache_hits']++;
                return $cachedIds;
            }
        }
        
        $this->performanceStats['cache_misses']++;
        $this->performanceStats['query_count']++;
        
        $consumerUserIds = Db::name('store_order_offline')
            ->where('paid', 1)
            ->where('mer_id', 980)
            ->group('uid')
            ->select();
        
        // 缓存1小时
        Cache::set($cacheKey, $consumerUserIds, 3600);
        
        return $consumerUserIds;
    }

    /**
     * 分批处理用户
     * @param array $consumerUserIds
     * @param User $userModel
     * @param StoreOrderOffline $orderModel
     * @param bool $isDryRun
     * @return array
     */
    private function processBatchUsers($consumerUserIds, $userModel, $orderModel, $isDryRun)
    {
        $totalCount = count($consumerUserIds);
        $batchSize = 100;
        $offset = 0;
        $totalProcessed = 0;
        $totalUpdated = 0;
        $errors = [];
        $levelChanges = [];
        
        // 分批处理数据 - 使用事务优化性能
        while ($offset < $totalCount) {
            $currentBatch = min($offset + $batchSize, $totalCount);
            $progress = round(($offset / $totalCount) * 100, 1);
            Log::info("[{$progress}%] 处理第 " . ($offset + 1) . " - {$currentBatch} 个用户...");
            
            // 开启事务
            if (!$isDryRun) {
                (new Db)->startTrans();
            }
            
            try {
                // 获取当前批次的用户数据
                $batchUserIds = array_slice($consumerUserIds, $offset, $batchSize);
                $users = $this->getBatchUsers($userModel, $batchUserIds);
                
                if (empty($users)) {
                    if (!$isDryRun) {
                        (new Db)->rollback();
                    }
                    break;
                }
                
                foreach ($users as $user) {
                    try {
                        $result = $this->calculateAndUpdateUserLevel(
                            $user['id'], 
                            $userModel, 
                            $orderModel,
                            $isDryRun
                        );
                        $totalProcessed++;
                        
                        if ($result['level_updated']) {
                            $totalUpdated++;
                            $levelChanges[] = $result;
                            Log::info(
                                "用户 {$user['nickname']}({$user['id']}) 级别更新: " .
                                "{$result['old_level']} -> {$result['new_level']}"
                            );
                        }
                        
                    } catch (\Exception $e) {
                        $errors[] = "用户ID {$user['id']}: " . $e->getMessage();
                        Log::error("批量级别更新 - 用户 {$user['id']} 处理失败", [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    }
                }
                
                // 提交事务
                if (!$isDryRun) {
                    Db::commit();
                }
                
            } catch (\Exception $e) {
                if (!$isDryRun) {
                    Db::rollback();
                }
                $errors[] = "批次处理失败: " . $e->getMessage();
                Log::error("批量级别更新 - 批次处理失败", [
                    'offset' => $offset,
                    'batch_size' => $batchSize,
                    'error' => $e->getMessage()
                ]);
            }
            
            // 更新偏移量
            $offset += $batchSize;
            
            // 释放内存
            unset($users);
            
            // 短暂休息，避免数据库压力过大
            if ($offset < $totalCount) {
                usleep(50000); // 休息0.05秒
            }
        }
        
        return [
            'total_processed' => $totalProcessed,
            'total_updated' => $totalUpdated,
            'errors' => $errors,
            'level_changes' => $levelChanges
        ];
    }
    
    /**
     * 获取批次用户数据（带缓存）
     * @param User $userModel
     * @param array $userIds
     * @return array
     */
    private function getBatchUsers($userModel, $userIds)
    {
        $this->performanceStats['query_count']++;
        
        return $userModel
            ->field('id,phone,nickname,team_level')
            ->whereIn('id', $userIds)
            ->where('is_disable', 0)
            ->select()
            ->toArray();
    }

    /**
     * 输出最终报告
     * @param array $processResult
     */
    private function outputFinalReport($processResult)
    {
        $executionTime = round(microtime(true) - $this->performanceStats['start_time'], 2);
        
        Log::info("\n=== 批量级别更新完成 ===");
        Log::info("- 处理用户数: {$processResult['total_processed']}");
        Log::info("- 更新用户数: {$processResult['total_updated']}");
        Log::info("- 错误数量: " . count($processResult['errors']));
        Log::info("- 执行时间: {$executionTime}秒");
        
        // 性能统计
        Log::info("\n=== 性能统计 ===");
        Log::info("- 数据库查询次数: {$this->performanceStats['query_count']}");
        Log::info("- 缓存命中次数: {$this->performanceStats['cache_hits']}");
        Log::info("- 缓存未命中次数: {$this->performanceStats['cache_misses']}");
        
        // 级别变更统计
        if (!empty($processResult['level_changes'])) {
            $levelChangeStats = $this->analyzeLevelChanges($processResult['level_changes']);
            Log::info("\n=== 级别变更统计 ===");
            foreach ($levelChangeStats as $change => $count) {
                Log::info("- {$change}: {$count}人");
            }
        }
        
        // 错误详情
        if (!empty($processResult['errors'])) {
            Log::info("\n=== 错误详情 ===");
            foreach (array_slice($processResult['errors'], 0, 10) as $error) {
                Log::info("- {$error}");
            }
            if (count($processResult['errors']) > 10) {
                Log::info("- ... 还有 " . (count($processResult['errors']) - 10) . " 个错误");
            }
        }
        
        // 保存统计数据到数据库
        $this->saveStatsToDatabase($processResult, $executionTime);
        
        // 记录到日志
        Log::info('批量级别更新任务完成', array_merge($processResult, [
            'execution_time' => $executionTime,
            'performance_stats' => $this->performanceStats
        ]));
        
        Log::info('\n批量级别更新任务执行完成!');
    }
    
    /**
     * 分析级别变更统计
     * @param array $levelChanges
     * @return array
     */
    private function analyzeLevelChanges($levelChanges)
    {
        $stats = [];
        
        foreach ($levelChanges as $change) {
            $key = "v{$change['old_level']} -> v{$change['new_level']}";
            $stats[$key] = ($stats[$key] ?? 0) + 1;
        }
        
        return $stats;
    }
    
    /**
     * 保存统计数据到数据库
     * @param array $processResult
     * @param float $executionTime
     */
    private function saveStatsToDatabase($processResult, $executionTime)
    {
        try {
            $statsDate = date('Y-m-d');
            
            // 准备统计数据
            $statsData = [
                'total_users' => $processResult['total_processed'],
                'success_count' => $processResult['total_updated'],
                'failure_count' => count($processResult['errors']),
                'skip_count' => $processResult['total_processed'] - $processResult['total_updated'] - count($processResult['errors']),
                'level_changes' => $processResult['level_changes'],
                'performance_stats' => [
                    'avg_time_per_user' => $processResult['total_processed'] > 0 ? 
                        round($executionTime / $processResult['total_processed'], 4) : 0,
                    'cache_hits' => $this->performanceStats['cache_hits'] ?? 0,
                    'cache_misses' => $this->performanceStats['cache_misses'] ?? 0,
                    'query_count' => $this->performanceStats['query_count'] ?? 0
                ]
            ];
            
            // 使用TaskStats模型保存统计数据
            $result = \app\common\model\TaskStats::saveBatchUpdateStats(
                $statsData,
                $executionTime,
                $statsDate
            );
            
            if ($result) {
                Log::info('BatchUpdateLevels统计数据已保存到数据库', [
                    'stats_date' => $statsDate,
                    'execution_time' => $executionTime
                ]);
            } else {
                Log::warning('BatchUpdateLevels统计数据保存到数据库失败');
            }
            
        } catch (\Exception $e) {
            Log::error('保存BatchUpdateLevels统计数据到数据库失败: ' . $e->getMessage(), [
                'execution_time' => $executionTime,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * 计算并更新用户级别（优化版）
     * @param int $userId
     * @param User $userModel
     * @param StoreOrderOffline $orderModel
     * @param bool $isDryRun
     * @return array
     */
    private function calculateAndUpdateUserLevel($userId, $userModel, $orderModel, $isDryRun = false)
    {
        // 获取用户信息（带缓存）
        $user = $this->getUserWithCache($userId, $userModel);
        if (!$user) {
            throw new \Exception('用户不存在');
        }
        
        // 获取团队所有成员ID（带缓存优化）
        $teamMemberIds = $this->getTeamMemberIdsWithCache($userId, $userModel);
        
        // 统计个人流水（带缓存）
        $personalTurnover = $this->getPersonalTurnoverWithCache($userId, $orderModel);
        
        // 统计团队流水（带缓存）
        $teamTurnover = $this->getTeamTurnoverWithCache($teamMemberIds, $orderModel);
        
        // 获取团队中各级别用户数量（带缓存）
        $levelCounts = $this->getTeamLevelCountsWithCache($teamMemberIds, $userModel);
        
        // 根据新的升级条件确定用户级别
        $newLevel = $this->calculateUserLevelNew($personalTurnover, $teamTurnover, $levelCounts);
        
        // 更新用户级别
        $oldLevel = $user['team_level'] ?? 0;
        $levelUpdated = false;
        
        if ($newLevel != $oldLevel) {
            if (!$isDryRun) {
                // 只更新系统用户表的team_level字段
                $this->performanceStats['query_count']++;
                $userModel->where('id', $userId)->update([
                    'team_level' => $newLevel,
                    'update_time' => time()
                ]);
                
                // 清除相关缓存
                $this->clearUserRelatedCache($userId);
            }
            
            $levelUpdated = true;
        }
        
        return [
            'uid' => $userId,
            'personal_turnover' => $personalTurnover,
            'team_turnover' => $teamTurnover,
            'level_counts' => $levelCounts,
            'old_level' => $oldLevel,
            'new_level' => $newLevel,
            'level_updated' => $levelUpdated
        ];
    }
    
    /**
     * 获取用户信息（带缓存）
     * @param int $userId
     * @param User $userModel
     * @return array|null
     */
    private function getUserWithCache($userId, $userModel)
    {
        $cacheKey = $this->cachePrefix . "user_{$userId}";
        $user = Cache::get($cacheKey);
        
        if ($user === false) {
            $this->performanceStats['cache_misses']++;
            $this->performanceStats['query_count']++;
            
            $user = $userModel->where('id', $userId)->find();
            if ($user) {
                $user = $user->toArray();
                Cache::set($cacheKey, $user, 1800); // 缓存30分钟
            }
        } else {
            $this->performanceStats['cache_hits']++;
        }
        
        return $user;
    }
    
    /**
     * 获取团队成员ID（带缓存）
     * @param int $userId
     * @param User $userModel
     * @return array
     */
    private function getTeamMemberIdsWithCache($userId, $userModel)
    {
        $cacheKey = $this->cachePrefix . "team_members_{$userId}";
        $teamMemberIds = Cache::get($cacheKey);
        
        if ($teamMemberIds === false) {
            $this->performanceStats['cache_misses']++;
            $teamMemberIds = $this->getTeamMemberIds($userId, $userModel);
            Cache::set($cacheKey, $teamMemberIds, 1800); // 缓存30分钟
        } else {
            $this->performanceStats['cache_hits']++;
        }
        
        return $teamMemberIds;
    }
    
    /**
     * 获取个人流水（带缓存）
     * @param int $userId
     * @param StoreOrderOffline $orderModel
     * @return float
     */
    private function getPersonalTurnoverWithCache($userId, $orderModel)
    {
        $cacheKey = $this->cachePrefix . "personal_turnover_{$userId}";
        $turnover = Cache::get($cacheKey);
        
        if ($turnover === false) {
            $this->performanceStats['cache_misses']++;
            $this->performanceStats['query_count']++;
            
            $personalStats = $orderModel
                ->where('uid', $userId)
                ->where('paid', 1)
                ->where('mer_id', 980)
                ->field('sum(pay_price) as personal_turnover')
                ->find();
            
            $turnover = $personalStats['personal_turnover'] ?? 0;
            Cache::set($cacheKey, $turnover, 1800); // 缓存30分钟
        } else {
            $this->performanceStats['cache_hits']++;
        }
        
        return $turnover;
    }
    
    /**
     * 获取团队流水（带缓存）
     * @param array $teamMemberIds
     * @param StoreOrderOffline $orderModel
     * @return float
     */
    private function getTeamTurnoverWithCache($teamMemberIds, $orderModel)
    {
        $cacheKey = $this->cachePrefix . "team_turnover_" . md5(implode(',', $teamMemberIds));
        $turnover = Cache::get($cacheKey);
        
        if ($turnover === false) {
            $this->performanceStats['cache_misses']++;
            $this->performanceStats['query_count']++;
            
            $teamStats = $orderModel
                ->whereIn('uid', $teamMemberIds)
                ->where('paid', 1)
                ->where('mer_id', 980)
                ->field('sum(pay_price) as team_turnover')
                ->find();
            
            $turnover = $teamStats['team_turnover'] ?? 0;
            Cache::set($cacheKey, $turnover, 1800); // 缓存30分钟
        } else {
            $this->performanceStats['cache_hits']++;
        }
        
        return $turnover;
    }
    
    /**
     * 获取团队级别统计（带缓存）
     * @param array $teamMemberIds
     * @param User $userModel
     * @return array
     */
    private function getTeamLevelCountsWithCache($teamMemberIds, $userModel)
    {
        $cacheKey = $this->cachePrefix . "team_levels_" . md5(implode(',', $teamMemberIds));
        $levelCounts = Cache::get($cacheKey);
        
        if ($levelCounts === false) {
            $this->performanceStats['cache_misses']++;
            $levelCounts = $this->getTeamLevelCounts($teamMemberIds, $userModel);
            Cache::set($cacheKey, $levelCounts, 1800); // 缓存30分钟
        } else {
            $this->performanceStats['cache_hits']++;
        }
        
        return $levelCounts;
    }
    
    /**
     * 清除用户相关缓存
     * @param int $userId
     */
    private function clearUserRelatedCache($userId)
    {
        $keys = [
            $this->cachePrefix . "user_{$userId}",
            $this->cachePrefix . "team_members_{$userId}",
            $this->cachePrefix . "personal_turnover_{$userId}"
        ];
        
        foreach ($keys as $key) {
            Cache::delete($key);
        }
    }
    
    /**
     * 获取团队所有成员ID（支持配置分销级别）
     * @param int $userId 用户ID
     * @param object $userModel 用户模型
     * @param int $maxLevel 最大分销级别，默认2级，0表示无限级
     * @return array 团队成员ID数组
     */
    private function getTeamMemberIds($userId, $userModel, $maxLevel = 2)
    {
        $memberIds = [$userId]; // 包含自己
        $currentLevelIds = [$userId];
        $level = 1;
        
        // 递归获取指定级别的下线
        while (($maxLevel == 0 || $level <= $maxLevel) && !empty($currentLevelIds)) {
            // 获取当前级别的下线用户
            $nextLevelUsers = $userModel
                ->whereIn('spread_uid', $currentLevelIds)
                ->column('id');
            
            if (!empty($nextLevelUsers)) {
                $memberIds = array_merge($memberIds, $nextLevelUsers);
                $currentLevelIds = $nextLevelUsers; // 为下一级做准备
                $level++;
            } else {
                break; // 没有下级了，提前结束
            }
        }
        
        return array_unique($memberIds);
    }
    
    /**
     * 获取团队中各级别用户数量（优化版）
     * @param array $teamMemberIds
     * @param User $userModel
     * @return array
     */
    private function getTeamLevelCounts($teamMemberIds, $userModel)
    {
        $this->performanceStats['query_count']++;
        
        $levelCounts = [
            'v1' => 0,
            'v2' => 0,
            'v3' => 0,
            'v4' => 0
        ];
        
        if (empty($teamMemberIds)) {
            return $levelCounts;
        }
        
        // 分批查询，避免IN语句过长
        $batchSize = 500;
        $batches = array_chunk($teamMemberIds, $batchSize);
        
        foreach ($batches as $batch) {
            $users = $userModel
                ->whereIn('id', $batch)
                ->field('team_level')
                ->select()
                ->toArray();
            
            foreach ($users as $user) {
                $level = $user['team_level'] ?? 0;
                switch ($level) {
                    case 1:
                        $levelCounts['v1']++;
                        break;
                    case 2:
                        $levelCounts['v2']++;
                        break;
                    case 3:
                        $levelCounts['v3']++;
                        break;
                    case 4:
                        $levelCounts['v4']++;
                        break;
                }
            }
        }
        
        return $levelCounts;
    }
    
    /**
     * 根据新的升级条件计算用户级别
     */
    private function calculateUserLevelNew($personalTurnover, $teamTurnover, $levelCounts)
    {
        // 新的级别配置
        // V1: 个人2万，团队30万
        // V2: 个人5万，团队里面两个V1
        // V3: 个人10万，团队里面两个V2
        // V4: 个人20万，团队里面两个V3
        
        // 检查V4级别条件
        if ($personalTurnover >= 200000 && $levelCounts['v3'] >= 2) {
            return 4;
        }
        
        // 检查V3级别条件
        if ($personalTurnover >= 100000 && $levelCounts['v2'] >= 2) {
            return 3;
        }
        
        // 检查V2级别条件
        if ($personalTurnover >= 50000 && $levelCounts['v1'] >= 2) {
            return 2;
        }
        
        // 检查V1级别条件
        if ($personalTurnover >= 20000 && $teamTurnover >= 300000) {
            return 1;
        }
        
        return 0; // 默认0级
    }
}