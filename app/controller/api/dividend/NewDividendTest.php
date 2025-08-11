<?php
// +----------------------------------------------------------------------
// | 新分红逻辑测试文件
// +----------------------------------------------------------------------

namespace app\controller\api\dividend;

use app\common\model\store\order\StoreOrder;
use app\common\model\user\User as UserModel;
use app\common\model\system\DividendStatistics;
use app\common\model\system\UserDividendRecord;
use think\facade\Db;
use think\facade\Log;
use crmeb\basic\BaseController;

/**
 * 新分红逻辑测试类
 */
class NewDividendTest extends BaseController
{
    /**
     * 测试新分红逻辑
     * @return \think\response\Json
     */
    public function testNewDividend()
    {
        try {
            Log::info('开始测试新分红逻辑');
            
            // 1. 检查数据库字段完整性
            $this->checkDatabaseFields();
            
            // 2. 准备测试数据
            $testData = $this->prepareTestData();
            
            // 测试场景1：完整四级用户分红
            echo "\n=== 测试场景1：完整四级用户分红 ===";
            $this->testFullDividendProcess();
            
            // 测试场景2：部分等级用户分红
            echo "\n\n=== 测试场景2：部分等级用户分红 ===";
            $this->testPartialLevelDividend();
            
            // 测试场景3：模拟分红数据验证
            echo "\n\n=== 测试场景3：分红逻辑验证 ===";
            $result = $this->testNewDividendLogic($testData);
            
            return app('json')->success($result, '新分红逻辑测试完成');
        } catch (\Exception $e) {
            Log::error('新分红逻辑测试失败: ' . $e->getMessage());
            return app('json')->fail('测试失败: ' . $e->getMessage());
        }
    }
    
    /**
      * 测试部分等级用户分红
      */
     private function testPartialLevelDividend()
     {
         Log::info('测试部分等级用户分红场景...');
         
         $userModel = new UserModel();
         
         // 检查当前各等级用户数量
         $levelCounts = [];
         for ($level = 1; $level <= 4; $level++) {
             $count = $userModel
                 ->where('group_id', $level)
                 ->where('status', 1)
                 ->count();
             $levelCounts[$level] = $count;
             echo "\nV{$level}级用户数量: {$count}";
         }
         
         // 模拟分红测试
         $totalHandlingFee = 10000; // 模拟总手续费
         
         // 获取权益用户
         $equityUsers = $userModel
             ->where('equity_value', '>', 0)
             ->where('status', 1)
             ->field('uid,nickname,equity_value,phone')
             ->select()
             ->toArray();
         
         echo "\n权益用户数量: " . count($equityUsers);
         
         // 创建分红实例并测试
         $dividendService = new \app\controller\api\dividend\DistributeDividends();
         
         // 使用反射调用私有方法
         $reflection = new \ReflectionClass($dividendService);
         $method = $reflection->getMethod('executeNewTeamDividend');
         $method->setAccessible(true);
         
         $result = $method->invoke($dividendService, $totalHandlingFee, $userModel, $equityUsers);
         
         echo "\n分红结果:";
         echo "\n- 实际分红轮数: " . $result['total_rounds'];
         echo "\n- 团队分红总额: " . $result['total_team_dividend_amount'];
         echo "\n- 权益分红总额: " . $result['total_equity_dividend_amount'];
         echo "\n- 团队分红记录数: " . count($result['team_dividend_records']);
         echo "\n- 权益分红记录数: " . count($result['equity_dividend_records']);
         
         return $result;
     }
    
    /**
     * 检查数据库字段完整性
     */
    private function checkDatabaseFields()
    {
        Log::info('检查数据库字段完整性...');
        
        // 检查eb_dividend_statistics表字段
        $statisticsFields = Db::query('DESCRIBE eb_dividend_statistics');
        $requiredStatisticsFields = [
            'dividend_base', 'team_dividend_distributed', 
            'remaining_team_leader_pool', 'team_dividend_count'
        ];
        
        foreach ($requiredStatisticsFields as $field) {
            $found = false;
            foreach ($statisticsFields as $dbField) {
                if ($dbField['Field'] === $field) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new \Exception("eb_dividend_statistics表缺少字段: {$field}");
            }
        }
        
        // 检查eb_user_dividend_record表字段
        $recordFields = Db::query('DESCRIBE eb_user_dividend_record');
        $requiredRecordFields = ['distribution_round', 'distribution_type'];
        
        foreach ($requiredRecordFields as $field) {
            $found = false;
            foreach ($recordFields as $dbField) {
                if ($dbField['Field'] === $field) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new \Exception("eb_user_dividend_record表缺少字段: {$field}");
            }
        }
        
        Log::info('数据库字段检查通过');
    }
    
    /**
     * 准备测试数据
     */
    private function prepareTestData()
    {
        Log::info('准备测试数据...');
        
        $userModel = new UserModel();
        
        // 检查是否有四个等级的用户
        $levelCounts = [];
        for ($level = 1; $level <= 4; $level++) {
            $count = $userModel
                ->where('group_id', $level)
                ->where('status', 1)
                ->count();
            $levelCounts[$level] = $count;
            Log::info("V{$level}级用户数量: {$count}");
        }
        
        // 获取权益值用户
        $equityUsers = $userModel
            ->where('equity_value', '>', 0)
            ->where('status', 1)
            ->field('uid,nickname,equity_value,phone')
            ->select()
            ->toArray();
        
        Log::info('权益值用户数量: ' . count($equityUsers));
        
        // 模拟手续费数据
        $totalHandlingFee = 2000; // 测试用的手续费
        
        return [
            'total_handling_fee' => $totalHandlingFee,
            'level_counts' => $levelCounts,
            'equity_users' => $equityUsers,
            'user_model' => $userModel
        ];
    }
    
    /**
     * 测试新分红逻辑
     */
    private function testNewDividendLogic($testData)
    {
        Log::info('开始测试新分红逻辑...');
        
        $totalHandlingFee = $testData['total_handling_fee'];
        $userModel = $testData['user_model'];
        $equityUsers = $testData['equity_users'];
        
        // 创建DistributeDividends实例来调用新方法
        $dividendController = new DistributeDividends();
        
        // 使用反射调用私有方法
        $reflection = new \ReflectionClass($dividendController);
        $method = $reflection->getMethod('executeNewTeamDividend');
        $method->setAccessible(true);
        
        // 执行新分红逻辑
        $result = $method->invoke($dividendController, $totalHandlingFee, $userModel, $equityUsers);
        
        // 分析结果
        $analysis = $this->analyzeResult($result, $totalHandlingFee);
        
        Log::info('新分红逻辑测试完成');
        
        return [
            'test_data' => $testData,
            'dividend_result' => $result,
            'analysis' => $analysis
        ];
    }
    
    /**
     * 分析分红结果
     */
    private function analyzeResult($result, $totalHandlingFee)
    {
        $analysis = [
            'expected_total_per_round' => $totalHandlingFee * 0.05, // 每轮5%
            'expected_team_per_round' => $totalHandlingFee * 0.05 * 0.30, // 每轮团队30%
            'expected_equity_per_round' => $totalHandlingFee * 0.05 * 0.70, // 每轮权益70%
            'expected_total_4_rounds' => $totalHandlingFee * 0.05 * 4, // 4轮总计
            'actual_team_total' => $result['total_team_dividend_amount'],
            'actual_equity_total' => $result['total_equity_dividend_amount'],
            'actual_total' => $result['total_team_dividend_amount'] + $result['total_equity_dividend_amount'],
            'team_records_count' => count($result['team_dividend_records']),
            'equity_records_count' => count($result['equity_dividend_records']),
            'total_rounds' => $result['total_rounds']
        ];
        
        // 计算误差
        $analysis['team_amount_error'] = abs($analysis['expected_team_per_round'] * 4 - $analysis['actual_team_total']);
        $analysis['equity_amount_error'] = abs($analysis['expected_equity_per_round'] * 4 - $analysis['actual_equity_total']);
        $analysis['total_amount_error'] = abs($analysis['expected_total_4_rounds'] - $analysis['actual_total']);
        
        // 验证结果
        $analysis['is_team_amount_correct'] = $analysis['team_amount_error'] < 0.01;
        $analysis['is_equity_amount_correct'] = $analysis['equity_amount_error'] < 0.01;
        $analysis['is_total_amount_correct'] = $analysis['total_amount_error'] < 0.01;
        
        Log::info('分红结果分析:', $analysis);
        
        return $analysis;
    }
    
    /**
     * 模拟完整分红流程测试
     */
    public function testFullDividendProcess()
    {
        try {
            Log::info('开始测试完整分红流程');
            
            // 创建DistributeDividends实例
            $dividendController = new DistributeDividends();
            
            // 调用分红接口
            $result = $dividendController->index();
            
            Log::info('完整分红流程测试完成');
            
            return $result;
        } catch (\Exception $e) {
            Log::error('完整分红流程测试失败: ' . $e->getMessage());
            return app('json')->fail('完整分红流程测试失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 查看分红统计数据
     */
    public function viewDividendStatistics()
    {
        try {
            $statisticsModel = new DividendStatistics();
            $recordModel = new UserDividendRecord();
            
            // 获取最近的分红统计
            $recentStatistics = $statisticsModel
                ->order('create_time', 'desc')
                ->limit(5)
                ->select()
                ->toArray();
            
            // 获取最近的分红记录
            $recentRecords = $recordModel
                ->order('create_time', 'desc')
                ->limit(20)
                ->select()
                ->toArray();
            
            return app('json')->success([
                'recent_statistics' => $recentStatistics,
                'recent_records' => $recentRecords
            ], '分红数据查询成功');
        } catch (\Exception $e) {
            return app('json')->fail('查询失败: ' . $e->getMessage());
        }
    }
}