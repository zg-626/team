<?php
// +----------------------------------------------------------------------
// | 分销团队级别统计系统 - 批量级别更新任务（优化版）
// +----------------------------------------------------------------------

namespace app\controller\api\dividend;

use app\common\model\store\order\StoreOrder;
use app\common\model\user\User;
use think\console\Input;
use think\console\Output;
use think\facade\Log;
use think\facade\Db;
use think\facade\Cache;
use crmeb\basic\BaseController;
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
            $orderModel = new StoreOrder();
            
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
            echo "发现 {$totalCount} 个用户，开始分批处理...\n";
            
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
     * @param StoreOrder $orderModel
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
        
        $consumerUserIds = $orderModel
            ->where('paid', 1)->where('offline_audit_status', 1)
            ->where('mer_id', 980)
            ->distinct(true)
            ->column('uid');
        
        // 缓存1小时
        Cache::set($cacheKey, $consumerUserIds, 3600);
        
        return $consumerUserIds;
    }

    /**
     * 分批处理用户
     * @param array $consumerUserIds
     * @param User $userModel
     * @param StoreOrder $orderModel
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
                Db::startTrans();
            }
            
            try {
                // 获取当前批次的用户数据
                $batchUserIds = array_slice($consumerUserIds, $offset, $batchSize);
                $users = $this->getBatchUsers($userModel, $batchUserIds);
                
                if (empty($users)) {
                    if (!$isDryRun) {
                        Db::rollback();
                    }
                    break;
                }
                
                foreach ($users as $user) {
                    try {
                        $result = $this->calculateAndUpdateUserLevel(
                            $user['uid'], 
                            $userModel, 
                            $orderModel,
                            $isDryRun
                        );
                        $totalProcessed++;
                        
                        if ($result['level_updated']) {
                            $totalUpdated++;
                            $levelChanges[] = $result;
                            Log::info(
                                "用户 {$user['nickname']}({$user['uid']}) 级别更新: " .
                                "{$result['old_level']} -> {$result['new_level']}"
                            );
                        }
                        
                    } catch (\Exception $e) {
                        $errors[] = "用户ID {$user['uid']}: " . $e->getMessage();
                        Log::error("批量级别更新 - 用户 {$user['uid']} 处理失败", [
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
                $errors[] = "批次处理失败: " . $e->getMessage().$e->getLine();
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
            ->field('uid,phone,nickname,group_id')
            ->whereIn('uid', $userIds)
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
     * @param StoreOrder $orderModel
     * @param bool $isDryRun
     * @return array
     */
    private function calculateAndUpdateUserLevel($userId, $userModel, $orderModel, $isDryRun = false)
    {
        // 获取用户信息（带缓存）
        $user = $this->getUserWithCache($userId, $userModel);
        
        // 修正用户存在性判断逻辑
        if (empty($user) || !is_array($user) && !is_object($user)) {
            throw new \Exception('用户不存在');
        }
        
        // 如果是数组但没有uid字段，也认为用户不存在
        if (is_array($user) && !isset($user['uid'])) {
            throw new \Exception('用户数据无效');
        }
        
        // 如果是对象但没有uid属性，也认为用户不存在
        if (is_object($user) && !isset($user->uid)) {
            throw new \Exception('用户数据无效');
        }
        
        // 直接从用户表获取业绩数据（这些字段已经包含了正确的计算结果）
        $personalTurnover = floatval($user['pay_price']);
        $originalTeamTurnover = floatval($user['spread_pay_price']);
        
        // 获取团队所有成员ID（用于直推级别统计）
        $teamMemberIds = $this->getTeamMemberIdsWithCache($userId, $userModel);
        
        // 获取大区业绩（团队中最大的单个业绩）
        $regionInfo = $this->getRegionTurnover($userId, $userModel, $orderModel);
        $regionTurnover = $regionInfo['turnover'];
        $regionUserId = $regionInfo['user_id'];
        
        // 计算减去大区业绩后的团队业绩
        $teamTurnover = $originalTeamTurnover - $regionTurnover;
        
        // 记录详细的用户业绩信息
        echo "用户ID: {$userId} - 个人业绩: {$personalTurnover}, 原始团队业绩: {$originalTeamTurnover}";
        if ($regionTurnover > 0 && $regionUserId > 0) {
            echo ", 大区业绩(已减去): {$regionTurnover} (用户ID: {$regionUserId}), 最终团队业绩: {$teamTurnover}\n";
        } else {
            echo ", 最终团队业绩: {$teamTurnover}\n";
        }
        
        // 获取团队中各级别数量（用于升级条件判断）
        $teamLevelCounts = $this->getTeamLevelCountsWithCache($teamMemberIds, $userModel);
        
        // 根据新的升级条件确定用户级别
        $newLevel = $this->calculateUserLevelNew($personalTurnover, $teamTurnover, $teamLevelCounts);
        
        // 更新用户级别
        $oldLevel = $user['group_id'] ?? 0;
        $levelUpdated = false;
        
        if ($newLevel != $oldLevel) {
            if (!$isDryRun) {
                // 只更新系统用户表的group_id字段
                $this->performanceStats['query_count']++;
                $userModel->where('uid', $userId)->update([
                    'group_id' => $newLevel
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
            'team_level_counts' => $teamLevelCounts,
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
        
        if ($user === false || $user === null) {
            // Log::info("getUserWithCache - 缓存未命中或为空，开始查询用户ID: {$userId}");
            $this->performanceStats['cache_misses']++;
            $this->performanceStats['query_count']++;
            
            // 添加调试日志
            // Log::info("getUserWithCache - 查询用户ID: {$userId}");
            
            // 尝试多种查询方式
            try {
                // 方式1: 使用User模型查询（强制指定表名）
                 $user = $userModel->table('eb_user')->where('uid', $userId)->find();
                // Log::info("getUserWithCache - User模型查询结果类型: " . gettype($user));
                // Log::info("getUserWithCache - User模型查询结果是否为空: " . (empty($user) ? '是' : '否'));
                
                if (!$user) {
                    // 方式2: 直接使用Db查询
                    // Log::info("getUserWithCache - User模型查询失败，尝试Db查询");
                    $dbResult = Db::table('eb_user')->where('uid', $userId)->find();
                    // Log::info("getUserWithCache - Db查询结果: " . ($dbResult ? json_encode($dbResult) : '空'));
                    
                    if ($dbResult) {
                        // 如果Db查询成功，直接返回数组格式
                        $user = $dbResult;
                        // Log::info("getUserWithCache - 使用Db查询结果");
                    }
                }
                
                if ($user) {
                    // 检查是否为模型对象，需要转换为数组
                    if (is_object($user)) {
                        // Log::info("getUserWithCache - 对象类名: " . get_class($user));
                        $user = $user->toArray();
                        // Log::info("getUserWithCache - toArray()后数据: " . json_encode($user));
                    } else {
                        // Log::info("getUserWithCache - 数组数据: " . json_encode($user));
                    }
                    
                    // 验证用户数据的有效性
                    if (is_array($user) && isset($user['uid']) && $user['uid'] == $userId) {
                        Cache::set($cacheKey, $user, 1800); // 缓存30分钟
                        // Log::info("getUserWithCache - 用户数据已缓存");
                    } else {
                        // Log::warning("getUserWithCache - 用户数据无效，不进行缓存");
                        $user = false;
                    }
                } else {
                    // Log::warning("getUserWithCache - 用户ID {$userId} 所有查询方式都失败");
                    
                    // 设置一个短期的"用户不存在"缓存，避免重复查询
                    Cache::set($cacheKey, false, 300); // 缓存5分钟
                    
                    // 最后尝试: 检查表是否存在以及字段是否正确
                    try {
                        $tableExists = Db::query("SHOW TABLES LIKE 'eb_user'");
                        // Log::info("getUserWithCache - 表eb_user是否存在: " . (empty($tableExists) ? '否' : '是'));
                        
                        if (!empty($tableExists)) {
                            $fieldExists = Db::query("SHOW COLUMNS FROM eb_user LIKE 'uid'");
                            // Log::info("getUserWithCache - 字段uid是否存在: " . (empty($fieldExists) ? '否' : '是'));
                            
                            // 检查是否有任何uid字段的记录
                            $anyRecord = Db::table('eb_user')->limit(1)->find();
                            // Log::info("getUserWithCache - 表中是否有任何记录: " . ($anyRecord ? '是' : '否'));
                        }
                    } catch (\Exception $e) {
                        Log::error("getUserWithCache - 检查表结构时出错: " . $e->getMessage());
                    }
                    
                    return false;
                }
                
            } catch (\Exception $e) {
                Log::error("getUserWithCache - 查询用户时出错: " . $e->getMessage());
                Log::error("getUserWithCache - 错误文件: " . $e->getFile() . " 行号: " . $e->getLine());
                return false;
            }
        } else {
            $this->performanceStats['cache_hits']++;
            // Log::info("getUserWithCache - 从缓存获取用户ID: {$userId}");
            // Log::info("getUserWithCache - 缓存数据类型: " . gettype($user));
            // Log::info("getUserWithCache - 缓存数据内容: " . json_encode($user));
            // Log::info("getUserWithCache - 缓存数据是否为空: " . (empty($user) ? '是' : '否'));
            // Log::info("getUserWithCache - 缓存数据布尔判断: " . ($user ? '真' : '假'));
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
        
        if ($teamMemberIds === false || $teamMemberIds === null) {
            $this->performanceStats['cache_misses']++;
            $teamMemberIds = $this->getTeamMemberIds($userId, $userModel);
            
            // 确保返回值是数组类型
            if (!is_array($teamMemberIds)) {
                Log::warning("getTeamMemberIds返回非数组类型: " . gettype($teamMemberIds) . ", 用户ID: {$userId}");
                $teamMemberIds = [$userId]; // 默认只包含自己
            }
            
            Cache::set($cacheKey, $teamMemberIds, 1800); // 缓存30分钟
        } else {
            $this->performanceStats['cache_hits']++;
            
            // 验证缓存数据类型
            if (!is_array($teamMemberIds)) {
                Log::warning("缓存中的团队成员ID不是数组类型: " . gettype($teamMemberIds) . ", 用户ID: {$userId}");
                $teamMemberIds = [$userId]; // 默认只包含自己
            }
        }
        
        return $teamMemberIds;
    }
    
    /**
     * 获取个人流水（带缓存）
     * @param int $userId
     * @param StoreOrder $orderModel
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
                ->where('paid', 1)->where('offline_audit_status', 1)
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
     * 获取团队流水（带缓存）- 减去大区业绩
     * @param array $teamMemberIds
     * @param StoreOrder $orderModel
     * @param int $userId 当前用户ID
     * @param User $userModel 用户模型
     * @return float
     */
    private function getTeamTurnoverWithCache($teamMemberIds, $orderModel, $userId = 0, $userModel = null)
    {
        $cacheKey = $this->cachePrefix . "team_turnover_" . md5(implode(',', $teamMemberIds) . "_exclude_region_{$userId}");
        $turnover = Cache::get($cacheKey);
        
        if ($turnover === false) {
            $this->performanceStats['cache_misses']++;
            $this->performanceStats['query_count']++;
            
            // 获取团队总业绩
            $teamStats = $orderModel
                ->whereIn('uid', $teamMemberIds)
                ->where('paid', 1)->where('offline_audit_status', 1)
                ->where('mer_id', 980)
                ->field('sum(pay_price) as team_turnover')
                ->find();
            
            $totalTeamTurnover = $teamStats['team_turnover'] ?? 0;
            
            // 减去大区业绩（V2及以上级别用户的团队业绩）
            $regionTurnover = 0;
            if ($userId > 0 && $userModel) {
                $regionTurnover = $this->getRegionTurnover($userId, $userModel, $orderModel);
            }
            
            $turnover = $totalTeamTurnover - $regionTurnover;
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
        $cacheKey = $this->cachePrefix . "group_ids_" . md5(implode(',', $teamMemberIds));
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
    private function getTeamMemberIds($userId, $userModel, $maxLevel = 0)
    {
        try {
            $memberIds = [$userId]; // 包含自己
            $currentLevelIds = [$userId];
            $level = 1;
            
            // 递归获取指定级别的下线
            while (($maxLevel == 0 || $level <= $maxLevel) && !empty($currentLevelIds)) {
                // 获取当前级别的下线用户
                $nextLevelUsers = $userModel
                    ->whereIn('spread_uid', $currentLevelIds)
                    ->column('uid');
                
                if (!empty($nextLevelUsers) && is_array($nextLevelUsers)) {
                    $memberIds = array_merge($memberIds, $nextLevelUsers);
                    $currentLevelIds = $nextLevelUsers; // 为下一级做准备
                    $level++;
                } else {
                    break; // 没有下级了，提前结束
                }
            }
            
            $result = array_unique($memberIds);
            Log::info("getTeamMemberIds成功，用户ID: {$userId}, 团队成员数: " . count($result));
            return $result;
            
        } catch (\Exception $e) {
            Log::error("getTeamMemberIds异常，用户ID: {$userId}, 错误: " . $e->getMessage());
            return [$userId]; // 异常时只返回自己
        }
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
                ->whereIn('uid', $batch)
                ->field('group_id')
                ->select()
                ->toArray();
            
            foreach ($users as $user) {
                $level = $user['group_id'] ?? 0;
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
     * @param float $personalTurnover 个人业绩
     * @param float $teamTurnover 团队业绩（已减去大区）
     * @param array $teamLevelCounts 团队级别统计（无限级）
     */
    private function calculateUserLevelNew($personalTurnover, $teamTurnover, $teamLevelCounts)
    {
        // 新的级别配置（修改后）
        // V1: 个人2万，团队10万（减去大区业绩）
        // V2: 个人5万，团队里面两个V1
        // V3: 个人10万，团队里面两个V2
        // V4: 个人20万，团队里面两个V3
        
        // 检查V4级别条件：个人20万 + 团队里面两个V3
        if ($personalTurnover >= 200000 && $teamLevelCounts['v3'] >= 2) {
            return 4;
        }
        
        // 检查V3级别条件：个人10万 + 团队里面两个V2
        if ($personalTurnover >= 100000 && $teamLevelCounts['v2'] >= 2) {
            return 3;
        }
        
        // 检查V2级别条件：个人5万 + 团队里面两个V1
        if ($personalTurnover >= 50000 && $teamLevelCounts['v1'] >= 2) {
            return 2;
        }
        
        // 检查V1级别条件：个人2万 + 团队10万（减去大区业绩）
        if ($personalTurnover >= 20000 && $teamTurnover >= 100000) {
            return 1;
        }
        
        return 0; // 默认0级
    }
    
    /**
     * 获取大区业绩（团队中最大的单个业绩）
     * @param int $userId 当前用户ID
     * @param User $userModel 用户模型
     * @param StoreOrder $orderModel 订单模型
     * @return array ['turnover' => float, 'user_id' => int]
     */
    private function getRegionTurnover($userId, $userModel, $orderModel)
    {
        try {
            // 获取团队所有成员ID
            $teamMemberIds = $this->getTeamMemberIds($userId, $userModel);
            
            if (empty($teamMemberIds) || count($teamMemberIds) <= 1) {
                return ['turnover' => 0, 'user_id' => 0]; // 没有团队成员或只有自己
            }
            
            $maxTurnover = 0;
            $maxUserId = 0;
            
            // 计算每个团队成员的个人业绩，找出最大值
            foreach ($teamMemberIds as $memberId) {
                if ($memberId == $userId) {
                    continue; // 跳过自己
                }
                
                $memberStats = $orderModel
                    ->where('uid', $memberId)
                    ->where('paid', 1)->where('offline_audit_status', 1)
                    ->where('mer_id', 980)
                    ->field('sum(pay_price) as turnover')
                    ->find();
                
                $memberTurnover = $memberStats['turnover'] ?? 0;
                if ($memberTurnover > $maxTurnover) {
                    $maxTurnover = $memberTurnover;
                    $maxUserId = $memberId;
                }
            }
            
            return ['turnover' => $maxTurnover, 'user_id' => $maxUserId];
            
        } catch (\Exception $e) {
            Log::error("获取大区业绩失败，用户ID: {$userId}, 错误: " . $e->getMessage());
            return ['turnover' => 0, 'user_id' => 0];
        }
    }
    
    /**
     * 获取直推用户中各级别数量（确保是不同用户线）
     * @param int $userId 用户ID
     * @param User $userModel 用户模型
     * @return array
     */
    private function getDirectPushLevelCounts($userId, $userModel)
    {
        try {
            $levelCounts = [
                'v1' => 0,
                'v2' => 0,
                'v3' => 0,
                'v4' => 0
            ];
            
            // 获取直推用户（只统计直接邀请的用户）
            $directUsers = $userModel
                ->where('spread_uid', $userId)
                ->where('status', 1)
                ->field('uid,group_id')
                ->select()
                ->toArray();
            
            if (empty($directUsers)) {
                return $levelCounts;
            }
            
            // 按级别分组统计不同用户线的数量
            $levelGroups = [
                1 => [],
                2 => [],
                3 => [],
                4 => []
            ];
            
            // 将直推用户按级别分组
            foreach ($directUsers as $user) {
                $level = $user['group_id'] ?? 0;
                if ($level >= 1 && $level <= 4) {
                    $levelGroups[$level][] = $user['uid'];
                }
            }
            
            // 统计每个级别的用户数量（每个直推用户代表一条独立的线）
            $levelCounts['v1'] = count($levelGroups[1]);
            $levelCounts['v2'] = count($levelGroups[2]);
            $levelCounts['v3'] = count($levelGroups[3]);
            $levelCounts['v4'] = count($levelGroups[4]);
            
            return $levelCounts;
            
        } catch (\Exception $e) {
            Log::error("获取直推级别统计失败，用户ID: {$userId}, 错误: " . $e->getMessage());
            return [
                'v1' => 0,
                'v2' => 0,
                'v3' => 0,
                'v4' => 0
            ];
        }
    }
}