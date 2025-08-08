<?php
// +----------------------------------------------------------------------
// | 阈值补贴定时任务控制器
// +----------------------------------------------------------------------

namespace app\controller\api\dividend;

use app\common\services\dividend\ThresholdDividendService;
use think\facade\Log;
use think\Request;

/**
 * 阈值补贴定时任务控制器
 * 用于定期检查和触发阈值补贴
 */
class ThresholdCron
{
    /**
     * 阈值补贴服务
     * @var ThresholdDividendService
     */
    private $thresholdService;
    
    public function __construct()
    {
        $this->thresholdService = new ThresholdDividendService();
    }
    
    /**
     * 定时检查所有分红池阈值
     * 建议每5-10分钟执行一次
     * @param Request $request
     * @return \think\Response
     */
    public function checkThresholds(Request $request)
    {
        $startTime = microtime(true);
        
        try {
            Log::info('开始执行阈值补贴定时任务');
            
            // 执行阈值检查
            $results = $this->thresholdService->manualCheckAllPools();
            
            // 统计结果
            $totalPools = count($results);
            $triggeredPools = array_filter($results, function($item) {
                return $item['triggered'] === true;
            });
            $triggeredCount = count($triggeredPools);
            
            $endTime = microtime(true);
            $executionTime = round(($endTime - $startTime) * 1000, 2);
            
            $summary = [
                'total_pools' => $totalPools,
                'triggered_count' => $triggeredCount,
                'execution_time_ms' => $executionTime,
                'triggered_pools' => array_column($triggeredPools, 'pool_id'),
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            Log::info('阈值补贴定时任务执行完成', $summary);
            
            return json([
                'code' => 200,
                'msg' => '执行成功',
                'data' => $summary
            ]);
            
        } catch (\Exception $e) {
            $endTime = microtime(true);
            $executionTime = round(($endTime - $startTime) * 1000, 2);
            
            Log::error('阈值补贴定时任务执行失败', [
                'error' => $e->getMessage(),
                'execution_time_ms' => $executionTime,
                'trace' => $e->getTraceAsString()
            ]);
            
            return json([
                'code' => 500,
                'msg' => '执行失败: ' . $e->getMessage(),
                'data' => [
                    'execution_time_ms' => $executionTime
                ]
            ]);
        }
    }
    
    /**
     * 获取所有分红池状态报告
     * @param Request $request
     * @return \think\Response
     */
    public function getStatusReport(Request $request)
    {
        try {
            $poolStatus = $this->thresholdService->getPoolStatus();
            
            // 生成统计报告
            $report = $this->generateStatusReport($poolStatus);
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $report
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取状态报告失败: ' . $e->getMessage());
            
            return json([
                'code' => 500,
                'msg' => '获取失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 生成状态报告
     * @param array $poolStatus
     * @return array
     */
    private function generateStatusReport($poolStatus)
    {
        $totalPools = count($poolStatus);
        $readyPools = array_filter($poolStatus, function($pool) {
            return $pool['can_trigger'] === true;
        });
        $readyCount = count($readyPools);
        
        $totalAmount = array_sum(array_column($poolStatus, 'current_amount'));
        $totalThreshold = array_sum(array_column($poolStatus, 'next_threshold'));
        
        $avgProgress = $totalPools > 0 ? 
            array_sum(array_column($poolStatus, 'progress_percent')) / $totalPools : 0;
        
        return [
            'summary' => [
                'total_pools' => $totalPools,
                'ready_for_dividend' => $readyCount,
                'total_amount' => round($totalAmount, 2),
                'total_threshold' => round($totalThreshold, 2),
                'average_progress' => round($avgProgress, 2) . '%',
                'report_time' => date('Y-m-d H:i:s')
            ],
            'ready_pools' => array_map(function($pool) {
                return [
                    'pool_id' => $pool['pool_id'],
                    'city_name' => $pool['city_name'],
                    'current_amount' => $pool['current_amount'],
                    'next_threshold' => $pool['next_threshold'],
                    'progress_percent' => $pool['progress_percent']
                ];
            }, $readyPools),
            'all_pools' => $poolStatus
        ];
    }
    
    /**
     * 健康检查接口
     * @param Request $request
     * @return \think\Response
     */
    public function healthCheck(Request $request)
    {
        try {
            // 检查服务是否正常
            $poolStatus = $this->thresholdService->getPoolStatus();
            
            $health = [
                'status' => 'healthy',
                'timestamp' => date('Y-m-d H:i:s'),
                'pools_count' => count($poolStatus),
                'service_version' => '1.0.0'
            ];
            
            return json([
                'code' => 200,
                'msg' => '服务正常',
                'data' => $health
            ]);
            
        } catch (\Exception $e) {
            Log::error('健康检查失败: ' . $e->getMessage());
            
            return json([
                'code' => 500,
                'msg' => '服务异常',
                'data' => [
                    'status' => 'unhealthy',
                    'error' => $e->getMessage(),
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ]);
        }
    }
    
    /**
     * 清理过期日志（可选功能）
     * @param Request $request
     * @return \think\Response
     */
    public function cleanupLogs(Request $request)
    {
        try {
            $days = $request->param('days', 30); // 默认保留30天
            
            if ($days < 7) {
                return json([
                    'code' => 400,
                    'msg' => '保留天数不能少于7天'
                ]);
            }
            
            $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            
            // 清理阈值补贴日志
            $thresholdDeleted = \think\facade\Db::name('threshold_dividend_log')
                ->where('create_time', '<', $cutoffDate)
                ->delete();
            
            // 清理分红池变动日志
            $poolDeleted = \think\facade\Db::name('dividend_pool_log')
                ->where('create_time', '<', $cutoffDate)
                ->delete();
            
            Log::info('日志清理完成', [
                'cutoff_date' => $cutoffDate,
                'threshold_logs_deleted' => $thresholdDeleted,
                'pool_logs_deleted' => $poolDeleted
            ]);
            
            return json([
                'code' => 200,
                'msg' => '清理完成',
                'data' => [
                    'cutoff_date' => $cutoffDate,
                    'threshold_logs_deleted' => $thresholdDeleted,
                    'pool_logs_deleted' => $poolDeleted
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('日志清理失败: ' . $e->getMessage());
            
            return json([
                'code' => 500,
                'msg' => '清理失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 获取执行统计信息
     * @param Request $request
     * @return \think\Response
     */
    public function getExecutionStats(Request $request)
    {
        try {
            $days = $request->param('days', 7); // 默认查看7天
            $startDate = date('Y-m-d', strtotime("-{$days} days"));
            
            // 统计阈值补贴执行情况
            $stats = \think\facade\Db::name('threshold_dividend_log')
                ->where('execute_time', '>=', $startDate)
                ->field([
                    'DATE(execute_time) as date',
                    'COUNT(*) as dividend_count',
                    'SUM(dividend_amount) as total_amount',
                    'COUNT(DISTINCT pool_id) as pool_count'
                ])
                ->group('DATE(execute_time)')
                ->order('date', 'desc')
                ->select()
                ->toArray();
            
            // 计算总计
            $totals = [
                'total_dividends' => array_sum(array_column($stats, 'dividend_count')),
                'total_amount' => array_sum(array_column($stats, 'total_amount')),
                'active_pools' => \think\facade\Db::name('threshold_dividend_log')
                    ->where('execute_time', '>=', $startDate)
                    ->distinct(true)
                    ->count('pool_id')
            ];
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'period' => [
                        'start_date' => $startDate,
                        'end_date' => date('Y-m-d'),
                        'days' => $days
                    ],
                    'totals' => $totals,
                    'daily_stats' => $stats
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取执行统计失败: ' . $e->getMessage());
            
            return json([
                'code' => 500,
                'msg' => '获取失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 模拟订单支付（仅测试环境）
     * @return \think\Response
     */
    public function simulatePayment()
    {
        try {
            $params = request()->param();
            
            // 验证必要参数
            if (!isset($params['handling_fee']) || !isset($params['city_id'])) {
                return json([
                    'code' => 400,
                    'msg' => '缺少必要参数: handling_fee, city_id'
                ]);
            }
            
            $handlingFee = (float)$params['handling_fee'];
            $cityId = (int)$params['city_id'];
            $orderId = $params['order_id'] ?? 'TEST_' . time();
            
            if ($handlingFee <= 0) {
                return json([
                    'code' => 400,
                    'msg' => '手续费必须大于0'
                ]);
            }
            
            // 调用阈值补贴服务
            $thresholdService = app()->make(\app\common\services\dividend\ThresholdDividendService::class);
            $orderData = [
                'order_id' => $orderId,
                'handling_fee' => $handlingFee,
                'mer_id' => 0, // 测试订单
                'city_id' => $cityId,
                'city' => '测试城市',
                'uid' => 999999 // 测试用户ID
            ];
            $result = $thresholdService->processOrderHandlingFee($orderData);
            
            return json([
                'code' => 200,
                'msg' => $result ? '模拟支付成功' : '模拟支付处理失败',
                'data' => [
                    'order_id' => $orderId,
                    'handling_fee' => $handlingFee,
                    'city_id' => $cityId,
                    'pool_amount_added' => bcmul((string)$handlingFee, '0.4', 2),
                    'processed' => $result
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('模拟支付失败: ' . $e->getMessage());
            
            return json([
                'code' => 500,
                'msg' => '模拟支付失败: ' . $e->getMessage()
            ]);
        }
    }
}