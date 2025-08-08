<?php
// +----------------------------------------------------------------------
// | 任务统计数据模型
// +----------------------------------------------------------------------

namespace app\common\model;

use think\Model;

/**
 * 任务统计数据模型
 * 用于存储CountUp任务的统计结果
 */
class TaskStats extends Model
{
    protected $name = 'task_stats';
    
    // 设置字段信息
    protected $schema = [
        'id'          => 'int',
        'task_name'   => 'string',
        'stats_date'  => 'date',
        'stats_data'  => 'json',
        'execution_time' => 'float',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime'
    ];
    
    // 自动时间戳
    protected $autoWriteTimestamp = true;
    
    // JSON字段
    protected $json = ['stats_data'];
    
    // 字段类型转换
    protected $type = [
        'stats_date' => 'date:Y-m-d',
        'execution_time' => 'float',
        'stats_data' => 'json'
    ];
    
    /**
     * 保存CountUp统计数据
     * @param array $statsData 统计数据
     * @param float $executionTime 执行时间
     * @param string $statsDate 统计日期
     * @return bool|TaskStats
     */
    public static function saveCountUpStats($statsData, $executionTime, $statsDate = null)
    {
        if ($statsDate === null) {
            $statsDate = date('Y-m-d', strtotime('-1 day'));
        }
        
        try {
            // 检查是否已存在当天的统计记录
            $existingRecord = self::where('task_name', 'count_up')
                ->where('stats_date', $statsDate)
                ->find();
            
            if ($existingRecord) {
                // 更新现有记录
                $existingRecord->stats_data = $statsData;
                $existingRecord->execution_time = $executionTime;
                $existingRecord->updated_at = date('Y-m-d H:i:s');
                return $existingRecord->save();
            } else {
                // 创建新记录
                return self::create([
                    'task_name' => 'count_up',
                    'stats_date' => $statsDate,
                    'stats_data' => $statsData,
                    'execution_time' => $executionTime
                ]);
            }
            
        } catch (\Exception $e) {
            \think\facade\Log::error('保存CountUp统计数据失败: ' . $e->getMessage(), [
                'stats_date' => $statsDate,
                'execution_time' => $executionTime,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
    
    /**
     * 保存BatchUpdateLevels统计数据
     * @param array $statsData 统计数据
     * @param float $executionTime 执行时间
     * @param string $statsDate 统计日期
     * @return bool|TaskStats
     */
    public static function saveBatchUpdateStats($statsData, $executionTime, $statsDate = null)
    {
        if ($statsDate === null) {
            $statsDate = date('Y-m-d');
        }
        
        try {
            // 检查是否已存在当天的统计记录
            $existingRecord = self::where('task_name', 'batch_update_levels')
                ->where('stats_date', $statsDate)
                ->find();
            
            if ($existingRecord) {
                // 更新现有记录
                $existingRecord->stats_data = $statsData;
                $existingRecord->execution_time = $executionTime;
                $existingRecord->updated_at = date('Y-m-d H:i:s');
                return $existingRecord->save();
            } else {
                // 创建新记录
                return self::create([
                    'task_name' => 'batch_update_levels',
                    'stats_date' => $statsDate,
                    'stats_data' => $statsData,
                    'execution_time' => $executionTime
                ]);
            }
            
        } catch (\Exception $e) {
            \think\facade\Log::error('保存BatchUpdateLevels统计数据失败: ' . $e->getMessage(), [
                'stats_date' => $statsDate,
                'execution_time' => $executionTime,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
    
    /**
     * 获取指定日期的CountUp统计数据
     * @param string $statsDate 统计日期
     * @return TaskStats|null
     */
    public static function getCountUpStats($statsDate)
    {
        return self::where('task_name', 'count_up')
            ->where('stats_date', $statsDate)
            ->find();
    }
    
    /**
     * 获取指定日期的BatchUpdateLevels统计数据
     * @param string $statsDate 统计日期
     * @return TaskStats|null
     */
    public static function getBatchUpdateStats($statsDate)
    {
        return self::where('task_name', 'batch_update_levels')
            ->where('stats_date', $statsDate)
            ->find();
    }
    
    /**
     * 获取最近N天的统计数据
     * @param string $taskName 任务名称
     * @param int $days 天数
     * @return array
     */
    public static function getRecentStats($taskName, $days = 7)
    {
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        
        return self::where('task_name', $taskName)
            ->where('stats_date', '>=', $startDate)
            ->order('stats_date desc')
            ->select()
            ->toArray();
    }
    
    /**
     * 获取任务执行时间趋势
     * @param string $taskName 任务名称
     * @param int $days 天数
     * @return array
     */
    public static function getExecutionTimeTrend($taskName, $days = 30)
    {
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        
        return self::where('task_name', $taskName)
            ->where('stats_date', '>=', $startDate)
            ->field('stats_date, execution_time')
            ->order('stats_date asc')
            ->select()
            ->toArray();
    }
    
    /**
     * 清理过期的统计数据
     * @param int $keepDays 保留天数
     * @return int 删除的记录数
     */
    public static function cleanupOldStats($keepDays = 90)
    {
        $cutoffDate = date('Y-m-d', strtotime("-{$keepDays} days"));
        
        try {
            $deletedCount = self::where('stats_date', '<', $cutoffDate)->delete();
            
            \think\facade\Log::info('清理过期统计数据完成', [
                'cutoff_date' => $cutoffDate,
                'deleted_count' => $deletedCount
            ]);
            
            return $deletedCount;
            
        } catch (\Exception $e) {
            \think\facade\Log::error('清理过期统计数据失败: ' . $e->getMessage(), [
                'cutoff_date' => $cutoffDate,
                'trace' => $e->getTraceAsString()
            ]);
            return 0;
        }
    }
    
    /**
     * 获取任务统计摘要
     * @param string $taskName 任务名称
     * @param int $days 统计天数
     * @return array
     */
    public static function getTaskSummary($taskName, $days = 30)
    {
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        
        $stats = self::where('task_name', $taskName)
            ->where('stats_date', '>=', $startDate)
            ->field('count(*) as total_runs, avg(execution_time) as avg_execution_time, min(execution_time) as min_execution_time, max(execution_time) as max_execution_time')
            ->find();
        
        if ($stats) {
            return [
                'task_name' => $taskName,
                'period_days' => $days,
                'total_runs' => $stats['total_runs'] ?? 0,
                'avg_execution_time' => round($stats['avg_execution_time'] ?? 0, 2),
                'min_execution_time' => round($stats['min_execution_time'] ?? 0, 2),
                'max_execution_time' => round($stats['max_execution_time'] ?? 0, 2)
            ];
        }
        
        return [
            'task_name' => $taskName,
            'period_days' => $days,
            'total_runs' => 0,
            'avg_execution_time' => 0,
            'min_execution_time' => 0,
            'max_execution_time' => 0
        ];
    }
    
    /**
     * 比较两个日期的统计数据
     * @param string $taskName 任务名称
     * @param string $date1 日期1
     * @param string $date2 日期2
     * @return array
     */
    public static function compareStats($taskName, $date1, $date2)
    {
        $stats1 = self::where('task_name', $taskName)
            ->where('stats_date', $date1)
            ->find();
            
        $stats2 = self::where('task_name', $taskName)
            ->where('stats_date', $date2)
            ->find();
        
        return [
            'date1' => $date1,
            'date2' => $date2,
            'stats1' => $stats1 ? $stats1->toArray() : null,
            'stats2' => $stats2 ? $stats2->toArray() : null,
            'execution_time_diff' => $stats1 && $stats2 ? 
                round($stats2['execution_time'] - $stats1['execution_time'], 2) : null
        ];
    }
}