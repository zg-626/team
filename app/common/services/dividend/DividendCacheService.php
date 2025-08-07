<?php

namespace app\common\services\dividend;

use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;

/**
 * 分红缓存服务类
 * 提供分红相关数据的缓存功能
 */
class DividendCacheService
{
    /** @var int 缓存过期时间（秒） */
    protected $cacheExpire = 3600;
    
    /** @var string 缓存键前缀 */
    protected $cachePrefix = 'dividend:';
    
    /**
     * 获取有效用户列表（带缓存）
     * @param array $pool 分红池信息
     * @return array
     */
    public function getValidUsers($pool): array
    {
        $cacheKey = $this->cachePrefix . 'users:' . $pool['city_id'];
        
        return Cache::remember($cacheKey, function() use ($pool) {
            return $this->fetchValidUsers($pool);
        }, $this->cacheExpire);
    }
    
    /**
     * 获取有效商家列表（带缓存）
     * @param array $pool 分红池信息
     * @return array
     */
    public function getValidMerchants($pool): array
    {
        $cacheKey = $this->cachePrefix . 'merchants:' . $pool['city_id'];
        
        return Cache::remember($cacheKey, function() use ($pool) {
            return $this->fetchValidMerchants($pool);
        }, $this->cacheExpire);
    }
    
    /**
     * 获取最后分红记录（带缓存）
     * @param int $poolId 分红池ID
     * @return array|null
     */
    public function getLastBonusRecord($poolId): ?array
    {
        $cacheKey = $this->cachePrefix . 'last_record:' . $poolId;
        
        return Cache::remember($cacheKey, function() use ($poolId) {
            return $this->fetchLastBonusRecord($poolId);
        }, $this->cacheExpire);
    }
    
    /**
     * 清除分红相关缓存
     * @param int $cityId 城市ID
     * @param int $poolId 分红池ID
     */
    public function clearDividendCache($cityId = null, $poolId = null): void
    {
        try {
            if ($cityId) {
                Cache::delete($this->cachePrefix . 'users:' . $cityId);
                Cache::delete($this->cachePrefix . 'merchants:' . $cityId);
            }
            
            if ($poolId) {
                Cache::delete($this->cachePrefix . 'last_record:' . $poolId);
            }
            
            // 如果都没有指定，清除所有分红缓存
            if (!$cityId && !$poolId) {
                $this->clearAllDividendCache();
            }
            
        } catch (\Exception $e) {
            Log::error('清除分红缓存失败：' . $e->getMessage());
        }
    }
    
    /**
     * 清除所有分红缓存
     */
    protected function clearAllDividendCache(): void
    {
        // 获取所有分红池
        $pools = Db::name('dividend_pool')->column('id,city_id');
        
        foreach ($pools as $pool) {
            Cache::delete($this->cachePrefix . 'users:' . $pool['city_id']);
            Cache::delete($this->cachePrefix . 'merchants:' . $pool['city_id']);
            Cache::delete($this->cachePrefix . 'last_record:' . $pool['id']);
        }
    }
    
    /**
     * 获取有效用户数据
     * @param array $pool
     * @return array
     */
    protected function fetchValidUsers($pool): array
    {
        try {
            // 先获取有效用户ID
            $userIds = Db::name('store_order_offline')
                ->alias('soo')
                ->join('user u', 'soo.uid = u.uid')
                ->where('soo.city_id', $pool['city_id'])
                ->where('soo.paid', 1)
                ->where('soo.refund_status', 0)
                ->where('u.status', 1)
                ->group('soo.uid')
                ->having('SUM(soo.pay_price) >= 100')
                ->column('soo.uid');
            
            if (empty($userIds)) {
                return [];
            }
            
            // 再获取用户详情和积分
            return Db::name('user')
                ->alias('u')
                ->field('u.uid, u.integral')
                ->where('u.uid', 'in', $userIds)
                ->where('u.integral', '>', 0)
                ->select()
                ->toArray();
                
        } catch (\Exception $e) {
            Log::error('获取有效用户失败：' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取有效商家数据
     * @param array $pool
     * @return array
     */
    protected function fetchValidMerchants($pool): array
    {
        try {
            // 先获取有效商家ID和总金额
            $merchantData = Db::name('store_order_offline')
                ->alias('soo')
                ->join('system_store ss', 'soo.store_id = ss.id')
                ->where('soo.city_id', $pool['city_id'])
                ->where('soo.paid', 1)
                ->where('soo.refund_status', 0)
                ->where('ss.is_show', 1)
                ->where('ss.del', 0)
                ->field('soo.store_id as mer_id, SUM(soo.pay_price) as total_amount')
                ->group('soo.store_id')
                ->having('SUM(soo.pay_price) >= 100')
                ->select()
                ->toArray();
            
            return $merchantData;
                
        } catch (\Exception $e) {
            Log::error('获取有效商家失败：' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取最后分红记录
     * @param int $poolId
     * @return array|null
     */
    protected function fetchLastBonusRecord($poolId): ?array
    {
        try {
            return Db::name('dividend_period_log')
                ->where('dp_id', $poolId)
                ->where('execute_type', 0) // 只查询周期分红记录
                ->order('id', 'desc')
                ->find();
                
        } catch (\Exception $e) {
            Log::error('获取最后分红记录失败：' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 预热缓存
     * @param array $pools 分红池列表
     */
    public function warmupCache($pools = null): void
    {
        try {
            if (!$pools) {
                $pools = Db::name('dividend_pool')->select()->toArray();
            }
            
            foreach ($pools as $pool) {
                // 预热用户和商家缓存
                $this->getValidUsers($pool);
                $this->getValidMerchants($pool);
                $this->getLastBonusRecord($pool['id']);
            }
            
            Log::info('分红缓存预热完成，共处理 ' . count($pools) . ' 个分红池');
            
        } catch (\Exception $e) {
            Log::error('分红缓存预热失败：' . $e->getMessage());
        }
    }
    
    /**
     * 获取缓存统计信息
     * @return array
     */
    public function getCacheStats(): array
    {
        $stats = [
            'total_keys' => 0,
            'user_cache_keys' => 0,
            'merchant_cache_keys' => 0,
            'record_cache_keys' => 0
        ];
        
        try {
            // 获取所有分红池
            $pools = Db::name('dividend_pool')->column('id,city_id');
            
            foreach ($pools as $pool) {
                $userKey = $this->cachePrefix . 'users:' . $pool['city_id'];
                $merchantKey = $this->cachePrefix . 'merchants:' . $pool['city_id'];
                $recordKey = $this->cachePrefix . 'last_record:' . $pool['id'];
                
                if (Cache::has($userKey)) {
                    $stats['user_cache_keys']++;
                    $stats['total_keys']++;
                }
                
                if (Cache::has($merchantKey)) {
                    $stats['merchant_cache_keys']++;
                    $stats['total_keys']++;
                }
                
                if (Cache::has($recordKey)) {
                    $stats['record_cache_keys']++;
                    $stats['total_keys']++;
                }
            }
            
        } catch (\Exception $e) {
            Log::error('获取缓存统计失败：' . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * 设置缓存过期时间
     * @param int $seconds
     */
    public function setCacheExpire($seconds): void
    {
        $this->cacheExpire = max(60, $seconds); // 最少1分钟
    }
}