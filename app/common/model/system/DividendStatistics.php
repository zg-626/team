<?php
// +----------------------------------------------------------------------
// | 分红统计模型
// +----------------------------------------------------------------------

namespace app\common\model\system;
use app\common\model\BaseModel;
use think\Model;

/**
 * 分红统计模型
 * Class DividendStatistics
 * @package app\common\model\dian
 */
class DividendStatistics extends Model
{
    // 设置字段信息
    protected $schema = [
        'id'                    => 'int',
        'dividend_date'         => 'date',
        'mer_id'               => 'int',
        'total_handling_fee'   => 'decimal',
        'team_leader_pool'     => 'decimal',
        'integral_pool'        => 'decimal',
        'team_leader_count'    => 'int',
        'integral_user_count'  => 'int',
        'total_dividend_amount'=> 'decimal',
        'status'               => 'int',
        'remark'               => 'string',
        'create_time'          => 'int',
        'update_time'          => 'int',
    ];

    // 自动时间戳
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    /**
     * 获取分红统计列表
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

        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit
        ];
    }

    /**
     * 根据日期和商户ID获取分红统计
     * @param string $dividendDate 分红日期
     * @param int $merId 商户ID
     * @return array|null
     */
    public function getByDateAndMer($dividendDate, $merId)
    {
        return $this->where('dividend_date', $dividendDate)
            ->where('mer_id', $merId)
            ->find();
    }

    /**
     * 创建分红统计记录
     * @param array $data 分红数据
     * @return int 统计记录ID
     */
    public function createRecord($data)
    {
        $record = $this->create($data);
        return $record->id;
    }

    /**
     * 更新分红统计记录
     * @param int $id 记录ID
     * @param array $data 更新数据
     * @return bool
     */
    public function updateRecord($id, $data)
    {
        return $this->where('id', $id)->update($data);
    }
}