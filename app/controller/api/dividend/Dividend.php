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

    /**
     * StoreBrand constructor.
     * @param App $app
     * @param repository $repository
     */
    public function __construct(App $app, repository $repository)
    {
        parent::__construct($app);
        $this->repository = $repository;
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

    // 定时分红
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

            $currentDay = date('d');

            // 获取所有城市分红池
            $poolInfo = Db::name('dividend_pool')
                ->where('city_id', '<>', 0)
                ->select()
                ->toArray();

            $successCount = 0;
            $failCount = 0;
            $errorMessages = [];

            foreach ($poolInfo as $pool) {
                // 为每个分红池单独使用事务
                Db::startTrans();
                try {
                    // 1. 执行周期分红（如果条件满足且今天未执行过周期分红）
                    $lastCycleExecuteFullDate = $this->getLastExecuteDay($pool['id']);
                    if ($this->shouldExecuteDividend($lastCycleExecuteFullDate)) {
                        if (!$this->hasExecutedToday($pool['id'], 2)) { // 检查周期分红（类型2）今天是否已执行
                            $infoCycle = $bonusOfflineService->calculateBonus($pool);
                            if ($infoCycle === false) {
                                // 处理执行失败的情况
                                record_log('时间: ' . date('Y-m-d H:i:s') . ', 系统周期分红执行失败: 奖池id' . $pool['id'], 'red_error');
                            } else if ($infoCycle && isset($infoCycle['bonus_amount'])) {
                                // 处理成功分红的情况
                                $this->recordExecuteLog(2, $infoCycle['bonus_amount'], $pool['id']);
                                record_log('时间: ' . date('Y-m-d H:i:s') . ', 系统周期分红: ' . json_encode($infoCycle, JSON_UNESCAPED_UNICODE) . '奖池id' . $pool['id'], 'red');
                            } else {
                                // 处理正常执行但没有分红的情况，仍然记录执行日期
                                $this->recordExecuteLog(2, 0, $pool['id']); // 记录执行日期，金额为0
                                record_log('时间: ' . date('Y-m-d H:i:s') . ', 系统周期分红计算无结果或无金额: 奖池id' . $pool['id'], 'red_error');
                            }
                        }
                    }

                    // 2. 执行月初分红（如果是1号，且今天未执行过月初分红，且本月初未执行过月初分红）
                    if ($currentDay === '01') {
                        if (!$this->hasExecutedToday($pool['id'], 1)) { // 检查月初分红（类型1）今天是否已执行
                            if (!$this->checkFirstDayExecuted($pool['id'])) {
                                $infoMonthly = $bonusOfflineService->distributeBaseAmount($pool);
                                if ($infoMonthly && isset($infoMonthly['bonus_amount'])) { // 确保有有效的分红金额
                                    $this->recordExecuteLog(1, $infoMonthly['bonus_amount'], $pool['id']); // 记录月初分红

                                    record_log('时间: ' . date('Y-m-d H:i:s') . ', 系统月初分红: ' . json_encode($infoMonthly, JSON_UNESCAPED_UNICODE) . '奖池id' . $pool['id'], 'red');

                                } else {
                                    record_log('时间: ' . date('Y-m-d H:i:s') . ', 系统月初分红计算无结果或无金额: 奖池id' . $pool['id'], 'red_error'); // 记录错误或特殊情况
                                }
                            }
                        }
                    }

                    // 提交当前分红池的事务
                    Db::commit();
                    $successCount++;

                } catch (\Exception $e) {
                    // 回滚当前分红池的事务
                    Db::rollback();
                    $failCount++;
                    $errorMessages[] = '奖池ID[' . $pool['id'] . '] 处理失败: ' . $e->getMessage();
                    Log::error('分红任务执行失败：奖池ID[' . $pool['id'] . '] ' . $e->getMessage() . ' File:' . $e->getFile() . ' Line:' . $e->getLine());
                }
            }

            // 返回总体执行结果
            if ($failCount > 0) {
                return json([
                    'code' => $successCount > 0 ? 1 : 0, // 如果有成功的，返回部分成功
                    'msg' => '分红任务执行完成，成功: ' . $successCount . '，失败: ' . $failCount,
                    'errors' => $errorMessages
                ]);
            } else {
                return json(['code' => 1, 'msg' => '分红任务执行成功，共处理 ' . $successCount . ' 个分红池']);
            }

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
}
