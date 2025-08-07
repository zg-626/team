<?php
// +----------------------------------------------------------------------
// | 用户补贴记录模型
// +----------------------------------------------------------------------

namespace app\common\model\system;

use think\Model;

/**
 * 用户补贴记录模型
 * Class UserDividendRecord
 * @package app\common\model\dian
 */
class UserDividendRecord extends Model
{
    // 设置字段信息
    protected $schema = [
        'id'                => 'int',
        'statistics_id'     => 'int',
        'dividend_date'     => 'date',
        'mer_id'           => 'int',
        'uid'              => 'int',
        'phone'            => 'string',
        'nickname'         => 'string',
        'dividend_type'    => 'int',
        'user_level'       => 'int',
        'user_integral'    => 'int',
        'weight_percent'   => 'decimal',
        'dividend_amount'  => 'decimal',
        'withdraw_status'  => 'int',
        'withdraw_time'    => 'int',
        'withdraw_amount'  => 'decimal',
        'withdraw_fee'     => 'decimal',
        'actual_amount'    => 'decimal',
        'withdraw_account' => 'string',
        'withdraw_remark'  => 'string',
        'status'           => 'int',
        'remark'           => 'string',
        'create_time'      => 'int',
        'update_time'      => 'int',
    ];
    
    // 自动时间戳
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    // 补贴类型常量
    const DIVIDEND_TYPE_TEAM_LEADER = 1; // 团长补贴
    const DIVIDEND_TYPE_INTEGRAL = 2;    // 积分补贴
    
    // 提现状态常量
    const WITHDRAW_STATUS_PENDING = 0;   // 未提现
    const WITHDRAW_STATUS_PROCESSING = 1; // 提现中
    const WITHDRAW_STATUS_SUCCESS = 2;    // 已提现
    const WITHDRAW_STATUS_FAILED = 3;     // 提现失败
    
    /**
     * 获取用户补贴记录列表
     * @param array $where 查询条件
     * @param int $page 页码
     * @param int $limit 每页数量
     * @return array
     */
    public function getList($where = [], $page = 1, $limit = 20)
    {
        $query = $this->where($where)
            ->order('dividend_date', 'desc')
            ->order('id', 'desc');
            
        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();
        
        // 格式化数据
        foreach ($list as &$item) {
            $item['dividend_type_text'] = $this->getDividendTypeText($item['dividend_type']);
            $item['withdraw_status_text'] = $this->getWithdrawStatusText($item['withdraw_status']);
        }
        
        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit
        ];
    }
    
    /**
     * 获取用户补贴记录
     * @param int $uid 用户ID
     * @param array $where 额外查询条件
     * @param int $page 页码
     * @param int $limit 每页数量
     * @return array
     */
    public function getUserDividendList($uid, $where = [], $page = 1, $limit = 20)
    {
        $where['uid'] = $uid;
        return $this->getList($where, $page, $limit);
    }
    
    /**
     * 批量创建补贴记录
     * @param array $records 补贴记录数组
     * @return bool
     */
    public function createBatch($records)
    {
        return $this->insertAll($records);
    }
    
    /**
     * 获取用户可提现补贴总额
     * @param int $uid 用户ID
     * @return float
     */
    public function getUserWithdrawableAmount($uid)
    {
        return $this->where('uid', $uid)
            ->where('withdraw_status', self::WITHDRAW_STATUS_PENDING)
            ->where('status', 1)
            ->sum('dividend_amount');
    }
    
    /**
     * 更新提现状态
     * @param array $ids 记录ID数组
     * @param int $status 提现状态
     * @param array $extraData 额外数据
     * @return bool
     */
    public function updateWithdrawStatus($ids, $status, $extraData = [])
    {
        $updateData = array_merge(['withdraw_status' => $status], $extraData);
        
        if ($status == self::WITHDRAW_STATUS_PROCESSING) {
            $updateData['withdraw_time'] = time();
        }
        
        return $this->whereIn('id', $ids)->update($updateData);
    }
    
    /**
     * 获取补贴类型文本
     * @param int $type 补贴类型
     * @return string
     */
    public function getDividendTypeText($type)
    {
        $types = [
            self::DIVIDEND_TYPE_TEAM_LEADER => '团长补贴',
            self::DIVIDEND_TYPE_INTEGRAL => '积分补贴'
        ];
        
        return $types[$type] ?? '未知类型';
    }
    
    /**
     * 获取提现状态文本
     * @param int $status 提现状态
     * @return string
     */
    public function getWithdrawStatusText($status)
    {
        $statuses = [
            self::WITHDRAW_STATUS_PENDING => '未提现',
            self::WITHDRAW_STATUS_PROCESSING => '提现中',
            self::WITHDRAW_STATUS_SUCCESS => '已提现',
            self::WITHDRAW_STATUS_FAILED => '提现失败'
        ];
        
        return $statuses[$status] ?? '未知状态';
    }
    
    /**
     * 获取用户补贴统计
     * @param int $uid 用户ID
     * @return array
     */
    public function getUserDividendStats($uid)
    {
        $totalAmount = $this->where('uid', $uid)
            ->where('status', 1)
            ->sum('dividend_amount');
            
        $withdrawnAmount = UserExtract::where('uid', $uid)
            ->where('status', 1)
            ->sum('balance');
            
        $pendingAmount = $this->where('uid', $uid)
            ->where('status', 1)
            ->sum('dividend_amount');
            
        $processingAmount = UserExtract::where('uid', $uid)
            ->where('status', 0)
            ->sum('balance');
        
        return [
            'total_amount' => $totalAmount,        // 总补贴金额
            'withdrawn_amount' => $withdrawnAmount, // 已提现金额
            'pending_amount' => $pendingAmount,     // 待提现金额
            'processing_amount' => $processingAmount // 提现中金额
        ];
    }
}