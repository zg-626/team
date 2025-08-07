<?php

// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2022 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed CRMEB并不是自由软件，未经许可不能去掉CRMEB相关版权
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------

namespace app\controller\api\dividend;

use app\common\repositories\article\ArticleRepository as repository;
use app\common\repositories\user\BonusOfflineService;
use app\common\repositories\user\BonusService;
use app\common\repositories\user\DividendPoolService;
use app\common\repositories\user\UserBillRepository;
use app\common\services\dividend\AsyncDividendService;
use app\common\services\dividend\DividendMonitorService;
use app\common\services\dividend\DividendCacheService;
use crmeb\basic\BaseController;
use think\App;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;

class Dividend extends BaseController
{
    /**
     * @var repository
     */
    protected $repository;
    
    /** @var BonusOfflineService */
    protected $bonusOfflineService;
    
    /** @var DividendPoolService */
    protected $dividendPoolService;
    
    /** @var UserBillRepository */
    protected $userBillRepository;
    
    /** @var AsyncDividendService */
    protected $asyncDividendService;
    
    /** @var DividendMonitorService */
    protected $monitorService;
    
    /** @var DividendCacheService */
    protected $cacheService;

    /**
     * StoreBrand constructor.
     * @param App $app
     * @param repository $repository
     */
    public function __construct(App $app, repository $repository)
    {
        parent::__construct($app);
        $this->repository = $repository;
        $this->bonusOfflineService = app()->make(BonusOfflineService::class);
        $this->dividendPoolService = app()->make(DividendPoolService::class);
        $this->userBillRepository = app()->make(UserBillRepository::class);
        $this->asyncDividendService = new AsyncDividendService();
        $this->monitorService = new DividendMonitorService();
        $this->cacheService = new DividendCacheService();
    }

    // 测试接口(单个城市测试)
    public function test()
    {
        /** @var DividendPoolService $dividendPoolService **/
        /*$dividendPoolService = app()->make(DividendPoolService::class);
        $dividendPoolService->calculateAndDistributeDividend();*/
        /** @var BonusService $bonusService **/
        /*$bonusService = app()->make(BonusService::class);
        $info = $bonusService->calculateBonus();
        echo "<pre>";
        print_r($info);*/
        /** @var BonusOfflineService $bonusOfflineService **/
        $bonusOfflineService = app()->make(BonusOfflineService::class);
        $pool = Db::name('dividend_pool')
                ->where('city_id', '=', 20188)
                ->find();

        try {
            $info = $bonusOfflineService->calculateBonus($pool);
            echo "<pre>";
            print_r($info);
        }catch (\Exception $e) {
            echo "<pre>";
            print_r($e->getMessage());
        }

    }

    //TODO 自动解冻抵用卷2
    public function sync()
    {
        /** @var UserBillRepository $userBillRepository **/
        $userBillRepository = app()->make(UserBillRepository::class);
        $userBillRepository->syncUserBill();
        return json(['code' => 1,'msg' => 'ok']);

    }

    /**
     * 分红任务执行（优化版本）
     * @return \think\response\Json
     */
    public function dividend()
    {
        /** @var BonusOfflineService $bonusOfflineService */
        $bonusOfflineService = app()->make(BonusOfflineService::class);

        // 使用ThinkPHP的Cache门面实现分布式锁
        $lockKey = 'dividend_task_lock';
        $lockValue = uniqid(mt_rand(), true);

        try {
            // 获取锁，5分钟超时
            if (!Cache::store('redis')->set($lockKey, $lockValue, 300, 'NX')) {
                return json(['code' => 0, 'msg' => '分红任务正在执行中']);
            }

            $executionResult = $this->executeDividendTasks($bonusOfflineService);
            
            return json([
                'code' => 1, 
                'msg' => '分红任务执行完成',
                'data' => $executionResult
            ]);

        } catch (\Exception $e) {
            Log::error('分红任务整体执行失败：' . $e->getMessage() . ' File:' . $e->getFile() . ' Line:' . $e->getLine());
            return json(['code' => 0, 'msg' => '分红任务整体执行失败：' . $e->getMessage()]);
        } finally {
            // 释放锁（确保是自己的锁）
            if (Cache::store('redis')->get($lockKey) === $lockValue) {
                Cache::store('redis')->delete($lockKey);
            }
        }
    }

    /**
     * 执行分红任务
     * @param BonusOfflineService $bonusOfflineService
     * @return array
     */
    protected function executeDividendTasks($bonusOfflineService): array
    {
        $currentDay = date('d');
        
        // 获取所有城市分红池
        $poolInfo = Db::name('dividend_pool')
            ->where('city_id', '<>', 0)
            ->select()
            ->toArray();

        $results = [
            'total_pools' => count($poolInfo),
            'success_count' => 0,
            'failed_count' => 0,
            'details' => []
        ];

        foreach ($poolInfo as $pool) {
            $poolResult = $this->processSinglePool($pool, $bonusOfflineService, $currentDay);
            $results['details'][$pool['id']] = $poolResult;
            
            if ($poolResult['success']) {
                $results['success_count']++;
            } else {
                $results['failed_count']++;
            }
        }

        return $results;
    }

    /**
     * 处理单个分红池
     * @param array $pool
     * @param BonusOfflineService $bonusOfflineService
     * @param string $currentDay
     * @return array
     */
    protected function processSinglePool($pool, $bonusOfflineService, $currentDay): array
    {
        $result = [
            'pool_id' => $pool['id'],
            'success' => false,
            'executed_tasks' => [],
            'errors' => []
        ];

        try {
            // 执行周期分红
            $periodicResult = $this->executePeriodicDividend($pool, $bonusOfflineService);
            if ($periodicResult) {
                $result['executed_tasks'][] = $periodicResult;
            }

            // 执行月初分红
            $monthlyResult = $this->executeMonthlyDividend($pool, $bonusOfflineService, $currentDay);
            if ($monthlyResult) {
                $result['executed_tasks'][] = $monthlyResult;
            }

            $result['success'] = true;
            
        } catch (\Exception $e) {
            $result['errors'][] = $e->getMessage();
            Log::error('分红池 ' . $pool['id'] . ' 执行失败：' . $e->getMessage() . ' File:' . $e->getFile() . ' Line:' . $e->getLine());
        }

        return $result;
    }

    /**
     * 执行周期分红
     * @param array $pool
     * @param BonusOfflineService $bonusOfflineService
     * @return array|null
     */
    protected function executePeriodicDividend($pool, $bonusOfflineService): ?array
    {
        // 检查今天是否已经执行过周期分红
        if ($this->hasExecutedToday($pool['id'], 2)) {
            return null;
        }

        $lastCycleExecuteFullDate = $this->getLastExecuteDay($pool['id']);
        if (!$this->shouldExecuteDividend($lastCycleExecuteFullDate)) {
            return null;
        }

        // 使用独立事务执行周期分红
        return $this->executeWithTransaction(function() use ($pool, $bonusOfflineService) {
            $infoCycle = $bonusOfflineService->calculateBonus($pool);
            
            if ($infoCycle === false) {
                record_log('时间: ' . date('Y-m-d H:i:s') . ', 系统周期分红执行失败: 奖池id' . $pool['id'], 'red_error');
                throw new \Exception('周期分红执行失败');
            } else if ($infoCycle && isset($infoCycle['bonus_amount'])) {
                $this->recordExecuteLog(2, $infoCycle['bonus_amount'], $pool['id']);
                record_log('时间: ' . date('Y-m-d H:i:s') . ', 系统周期分红: ' . json_encode($infoCycle, JSON_UNESCAPED_UNICODE) . '奖池id' . $pool['id'], 'red');
                return [
                    'type' => 'periodic',
                    'bonus_amount' => $infoCycle['bonus_amount'],
                    'executed_at' => date('Y-m-d H:i:s')
                ];
            } else {
                $this->recordExecuteLog(2, 0, $pool['id']);
                record_log('时间: ' . date('Y-m-d H:i:s') . ', 系统周期分红计算无结果或无金额: 奖池id' . $pool['id'], 'red_error');
                return [
                    'type' => 'periodic',
                    'bonus_amount' => 0,
                    'executed_at' => date('Y-m-d H:i:s')
                ];
            }
        });
    }

    /**
     * 执行月初分红
     * @param array $pool
     * @param BonusOfflineService $bonusOfflineService
     * @param string $currentDay
     * @return array|null
     */
    protected function executeMonthlyDividend($pool, $bonusOfflineService, $currentDay): ?array
    {
        // 检查今天是否已经执行过月初分红
        if ($this->hasExecutedToday($pool['id'], 1)) {
            return null;
        }

        // 判断是否为月初（每月1号）
        if ($currentDay !== '01') {
            return null;
        }

        // 检查本月初是否已执行过
        if ($this->checkFirstDayExecuted($pool['id'])) {
            return null;
        }

        // 使用独立事务执行月初分红
        return $this->executeWithTransaction(function() use ($pool, $bonusOfflineService) {
            $infoMonthly = $bonusOfflineService->distributeBaseAmount($pool);
            
            if ($infoMonthly && isset($infoMonthly['bonus_amount'])) {
                $this->recordExecuteLog(1, $infoMonthly['bonus_amount'], $pool['id']);
                record_log('时间: ' . date('Y-m-d H:i:s') . ', 系统月初分红: ' . json_encode($infoMonthly, JSON_UNESCAPED_UNICODE) . '奖池id' . $pool['id'], 'red');
                return [
                    'type' => 'monthly',
                    'bonus_amount' => $infoMonthly['bonus_amount'],
                    'executed_at' => date('Y-m-d H:i:s')
                ];
            } else {
                record_log('时间: ' . date('Y-m-d H:i:s') . ', 系统月初分红计算无结果或无金额: 奖池id' . $pool['id'], 'red_error');
                return null;
            }
        });
    }

    /**
     * 在事务中执行操作
     * @param callable $callback
     * @return mixed
     * @throws \Exception
     */
    protected function executeWithTransaction(callable $callback)
    {
        Db::startTrans();
        try {
            $result = $callback();
            Db::commit();
            return $result;
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 检查指定类型的分红今天是否已执行
     * @param int $poolId 奖池ID
     * @param int $type 执行类型：1=月初分红，2=周期分红
     * @return bool
     */
    private function hasExecutedToday(int $poolId, int $type): bool
    {
        return Db::name('dividend_execute_log')
            ->where('execute_date', date('Y-m-d'))
            ->where('execute_type', $type)
            ->where('status', 1)
            ->where('dp_id', $poolId)
            ->count() > 0;
    }

    private function recordExecuteLog(int $type, float $amount,$poolId): void
    {
        Db::name('dividend_execute_log')->insert([
            'execute_date' => date('Y-m-d'),
            'execute_type' => $type,
            'status' => 1,
            'dp_id' => $poolId,
            'bonus_amount' => $amount,
            'create_time' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * 获取上次执行日期
     */
    private function getLastExecuteDay($poolId): string
    {
        // 查询最近一次周期分红（execute_type = 2）记录
        $lastRecordDate = Db::name('dividend_execute_log')
            ->where('execute_type', 2) // 明确指定周期分红类型
            ->where('status', 1)
            ->where('dp_id', $poolId)
            ->order('execute_date desc')
            ->value('execute_date');
        
        // return $lastRecordDate ? date('d', strtotime($lastRecordDate)) : '0'; // 返回的是月份中的天
        return $lastRecordDate ?: ''; // 返回完整的日期字符串 YYYY-MM-DD，如果不存在则返回空字符串
    }

    /**
     * 查询01号是否执行过
     */
    public function checkFirstDayExecuted($poolId): bool
    {
        return Db::name('dividend_execute_log')
            ->where('execute_date', date('Y-m-01'))
            ->where('execute_type', 1)
            ->where('status', 1)
            ->where('dp_id', $poolId)
            ->count() > 0;
    }

    /**
     * 判断是否需要执行分红
     */
    /**
     * 判断是否需要执行周期分红
     * @param string $lastExecuteDate 上次周期分红的完整日期 (YYYY-MM-DD)，如果从未执行过则为空字符串或null
     * @return bool
     */
    private function shouldExecuteDividend(string $lastExecuteDate): bool
    {
        $cycleDays = (int)systemConfig('sys_red_day') ?: 5; // 获取周期分红天数，默认为5天

        // 如果从未执行过周期分红，则应该执行一次
        if (empty($lastExecuteDate)) {
            return true;
        }

        try {
            $lastDate = new \DateTime($lastExecuteDate);
            $currentDate = new \DateTime(date('Y-m-d')); // 获取当前日期，不含时间部分

            // 计算日期差异
            $interval = $currentDate->diff($lastDate);
            $daysPassed = $interval->days;

            // 如果当前日期早于上次执行日期（这种情况不应该发生）
            if ($interval->invert == 0) {
                return false; // 当前日期早于上次执行日期
            }

            return $daysPassed > $cycleDays;
        } catch (\Exception $e) {
            // 日期格式错误等异常处理
            Log::error('shouldExecuteDividend日期处理异常: ' . $e->getMessage());
            return false; // 发生异常则不执行
        }
    }

    /**
     * 异步执行分红
     * @return \think\response\Json
     */
    public function asyncDividend()
    {
        try {
            $pools = $this->dividendPoolService->getActivePools();
            
            if (empty($pools)) {
                return json(['code' => 1, 'msg' => '没有需要处理的分红池']);
            }
            
            $processedCount = 0;
            foreach ($pools as $pool) {
                // 计算分红数据
                $dividendData = $this->bonusOfflineService->calculateBonus($pool);
                
                if (!empty($dividendData)) {
                    // 异步处理分红分配
                    $result = $this->asyncDividendService->asyncDistributeBonus($dividendData, $pool);
                    if ($result) {
                        $processedCount++;
                    }
                }
            }
            
            return json([
                'code' => 1,
                'msg' => '异步分红任务已启动',
                'data' => [
                    'total_pools' => count($pools),
                    'processed_pools' => $processedCount
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('异步分红启动失败：' . $e->getMessage());
            return json(['code' => 0, 'msg' => '异步分红启动失败：' . $e->getMessage()]);
        }
    }
    
    /**
     * 获取分红监控状态
     * @return \think\response\Json
     */
    public function monitorStatus()
    {
        try {
            $poolId = $this->request->param('pool_id', null);
            $result = $this->monitorService->monitorDividendExecution($poolId);
            
            return json(['code' => 1, 'msg' => '获取监控状态成功', 'data' => $result]);
            
        } catch (\Exception $e) {
            Log::error('获取监控状态失败：' . $e->getMessage());
            return json(['code' => 0, 'msg' => '获取监控状态失败：' . $e->getMessage()]);
        }
    }
    
    /**
     * 数据对账
     * @return \think\response\Json
     */
    public function reconcile()
    {
        try {
            $poolId = $this->request->param('pool_id/d');
            $date = $this->request->param('date', date('Y-m-d'));
            
            if (!$poolId) {
                return json(['code' => 0, 'msg' => '请指定分红池ID']);
            }
            
            $result = $this->monitorService->reconcileData($poolId, $date);
            
            return json(['code' => 1, 'msg' => '数据对账完成', 'data' => $result]);
            
        } catch (\Exception $e) {
            Log::error('数据对账失败：' . $e->getMessage());
            return json(['code' => 0, 'msg' => '数据对账失败：' . $e->getMessage()]);
        }
    }
    
    /**
     * 检查分红任务状态
     * @return \think\response\Json
     */
    public function checkTaskStatus()
    {
        try {
            $poolId = $this->request->param('pool_id/d');
            
            if (!$poolId) {
                return json(['code' => 0, 'msg' => '请指定分红池ID']);
            }
            
            $result = $this->asyncDividendService->checkDividendStatus($poolId);
            
            return json(['code' => 1, 'msg' => '获取任务状态成功', 'data' => $result]);
            
        } catch (\Exception $e) {
            Log::error('获取任务状态失败：' . $e->getMessage());
            return json(['code' => 0, 'msg' => '获取任务状态失败：' . $e->getMessage()]);
        }
    }
    
    /**
     * 重试失败的分红任务
     * @return \think\response\Json
     */
    public function retryFailedTasks()
    {
        try {
            $poolId = $this->request->param('pool_id/d');
            
            if (!$poolId) {
                return json(['code' => 0, 'msg' => '请指定分红池ID']);
            }
            
            $result = $this->asyncDividendService->retryFailedDividend($poolId);
            
            if ($result) {
                return json(['code' => 1, 'msg' => '重试任务已启动']);
            } else {
                return json(['code' => 0, 'msg' => '重试任务启动失败']);
            }
            
        } catch (\Exception $e) {
            Log::error('重试失败任务失败：' . $e->getMessage());
            return json(['code' => 0, 'msg' => '重试失败任务失败：' . $e->getMessage()]);
        }
    }
    
    /**
     * 缓存管理
     * @return \think\response\Json
     */
    public function cacheManage()
    {
        try {
            $action = $this->request->param('action', 'stats');
            $cityId = $this->request->param('city_id', null);
            $poolId = $this->request->param('pool_id', null);
            
            switch ($action) {
                case 'stats':
                    $result = $this->cacheService->getCacheStats();
                    return json(['code' => 1, 'msg' => '获取缓存统计成功', 'data' => $result]);
                    
                case 'clear':
                    $this->cacheService->clearDividendCache($cityId, $poolId);
                    return json(['code' => 1, 'msg' => '缓存清除成功']);
                    
                case 'warmup':
                    $pools = $poolId ? [['id' => $poolId]] : null;
                    $this->cacheService->warmupCache($pools);
                    return json(['code' => 1, 'msg' => '缓存预热完成']);
                    
                default:
                    return json(['code' => 0, 'msg' => '不支持的操作：' . $action]);
            }
            
        } catch (\Exception $e) {
            Log::error('缓存管理失败：' . $e->getMessage());
            return json(['code' => 0, 'msg' => '缓存管理失败：' . $e->getMessage()]);
        }
    }
}
