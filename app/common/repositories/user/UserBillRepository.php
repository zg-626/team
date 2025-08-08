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


namespace app\common\repositories\user;


use app\common\dao\BaseDao;
use app\common\dao\user\UserBillDao;
use app\common\model\user\UserBill;
use app\common\repositories\BaseRepository;
use app\common\repositories\store\product\ProductRepository;
use app\common\repositories\system\merchant\MerchantRepository;
use crmeb\jobs\SendSmsJob;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\Queue;
use think\Model;

/**
 * Class UserBillRepository
 * @package app\common\repositories\user
 * @author xaboy
 * @day 2020-05-07
 * @mixin UserBillDao
 */
class UserBillRepository extends BaseRepository
{
    /**
     * @var UserBillDao
     */
    protected $dao;

    const TYPE_INFO = [
        'brokerage' => [
            'brokerage/now_money' => '佣金转入余额',
            'brokerage/order_one' => '获得一级推广抵用券',
            'brokerage/order_two' => '获得二级推广抵用券',
            'brokerage/refund_one' => '退还一级推广抵用券',
            'brokerage/refund_two' => '退还二级推广抵用券',
        ],
        'integral' => [
            'integral/cancel' => '退回积分',
            'integral/deduction' => '购买商品',
            'integral/points' => '兑换商品',
            'integral/lock' => '下单赠送积分',
            'integral/refund' => '订单退款',
            'integral/refund_lock' => '扣除赠送积分',
            'integral/sign_integral' => '签到赠送积分',
            'integral/spread' => '邀请好友',
            'integral/sys_dec' => '系统减少积分',
            'integral/sys_inc' => '系统增加积分',
            'integral/timeout' => '积分过期',
        ],
        'mer_integral' => [
            'mer_integral/deduction' => '积分抵扣',
            'mer_integral/refund' => '订单退款',
        ],
       'now_money' => [
           'now_money/brokerage' => '佣金转入余额',
           'now_money/pay_product' => '购买商品',
           'now_money/offline_order' => '线下门店支付',
           'integral/lock' => '用户增加积分',
           'coupon_amount/order_one' => '一级推广抵用券',
           'coupon_amount/order_two' => '二级推广抵用券',
           'coupon_amount/exchange' => '获得转移抵用券',
           'brokerage/order_one' => '推广佣金',
           'now_money/presell' => '支付预售尾款',
           'now_money/recharge' => '余额充值',
           //'now_money/sys_dec_money' => '系统减少余额',
           //'now_money/sys_inc_money' => '系统增加余额',
           //'svip_pay/svip_pay' => '付费会员支付'
       ],
        'mer_margin' => [
            'mer_margin/local_margin' => '线下缴纳保证金',
            'mer_margin/pay_margin' => '线上缴纳保证金',
        ],
        'mer_lock_money' => [
            'mer_lock_money/order' => '商户佣金冻结',
        ],
        'sys_members' => [
            'sys_members/member_upgrade' => '会员升级',
            'sys_members/platform_clearing' => '平台清除',
            'sys_members/member_pay_num'   => '下单获得成长值',
            'sys_members/member_sign_num'  => '签到获得成长值',
            'sys_members/member_reply_num' => '评价获得成长值',
            'sys_members/member_share_num' => '邀请获得成长值',
            'sys_members/member_community_num'  => '社区种草内容获得成长值',
        ],
        'equity_value' => [
            'equity_value/promotion_reward' => '推广奖励权益值',
            'equity_value/order_reward' => '订单奖励权益值',
            'equity_value/sys_inc' => '系统增加权益值',
            'equity_value/sys_dec' => '系统减少权益值',
            'equity_value/exchange' => '权益值兑换',
            'equity_value/refund' => '退还权益值',
        ]
    ];

    const CATEGORY_SVIP_PAY = 'svip_pay';
    const CATEGORY_NOW_MONEY = 'now_money';
    const CATEGORY_INTEGRAL = 'integral';
    const CATEGORY_BROKERAGE= 'brokerage';
    const COUPON_AMOUNT= 'coupon_amount';
    const CATEGORY_MER_MARGIN = 'mer_margin';
    const CATEGORY_MER_INTEGRAL = 'mer_integral';
    const CATEGORY_MER_LOCK_MONEY = 'mer_lock_money';
    const CATEGORY_SYS_MEMBERS = 'sys_members';

    // 需要去重复的类型
    const TO_REPEAT_TYPE = ['member_community_num'];


    /**
     * UserBillRepository constructor.
     * @param UserBillDao $dao
     */
    public function __construct(UserBillDao $dao)
    {
        $this->dao = $dao;
    }

    public function userList($where, $uid, $page, $limit)
    {
        $where['uid'] = $uid;
        $query = $this->dao->search($where)->order('create_time DESC');
        $count = $query->count();
        $list = $query->setOption('field', [])->field('bill_id,pm,title,number,balance,mark,create_time,status')->page($page, $limit)->select();
        return compact('count', 'list');
    }

    public function merList($where, $mer_id, $page, $limit)
    {
        $where['mer_id'] = $mer_id;
        $query = $this->dao->search($where)->order('create_time DESC');
        $count = $query->count();
        $list = $query->setOption('field', [])->field('bill_id,pm,title,number,balance,mark,create_time,status')->page($page, $limit)->select();
        return compact('count', 'list');
    }

    public function month(array $where)
    {
        $group = $this->dao->search($where)->field('FROM_UNIXTIME(unix_timestamp(create_time),"%Y-%m") as time')
            ->order('time DESC')->group('time')->select();
        $ret = [];
        foreach ($group as $k => $item){
            $ret[$k]['month'] = $item['time'];
            $query = $this->dao->getSearch($where)->field('title,pm,number,create_time')->whereMonth('create_time',$item['time']);
            $ret[$k]['list'] = $query->order('create_time DESC')->select();
        }
        return $ret;
    }

    public function getList($where, $page, $limit)
    {
        $query = $this->dao->searchJoin($where)->order('a.create_time DESC,bill_id DESC');
        $count = $query->count();
        $list = $query->page($page, $limit)->select();
        return compact('count', 'list');
    }

    public function getLst($where, $page, $limit)
    {
        $query = $this->dao->search($where)->order('create_time DESC');
        $count = $query->count();
        $list = $query->page($page, $limit)->select();
        return compact('count', 'list');
    }

    /**
     * @param int $uid
     * @param string $category
     * @param string $type
     * @param int $pm
     * @param array $data
     * @return BaseDao|Model
     * @author xaboy
     * @day 2020-05-07
     */
    public function bill(int $uid, string $category, string $type, int $pm, array $data)
    {
        $data['category'] = $category;
        $data['type'] = $type;
        $data['uid'] = $uid;
        $data['pm'] = $pm;
        $bill = $this->dao->create($data);
        if($category == 'now_money'){
            Queue::push(SendSmsJob::class,['tempId' => 'USER_BALANCE_CHANGE','id' => $bill->bill_id]);
        }
        return $bill;
    }

    /**
     * @param int $uid
     * @param string $category
     * @param string $type
     * @param array $data
     * @return BaseDao|Model
     * @author xaboy
     * @day 2020-05-07
     */
    public function incBill(int $uid, string $category, string $type, array $data)
    {
        return $this->bill($uid, $category, $type, 1, $data);
    }

    /**
     * @param int $uid
     * @param string $category
     * @param string $type
     * @param array $data
     * @return BaseDao|Model
     * @author xaboy
     * @day 2020-05-07
     */
    public function decBill(int $uid, string $category, string $type, array $data)
    {
        return $this->bill($uid, $category, $type, 0, $data);
    }

    /**
     * @param int $uid
     * @param string $category
     * @param string $type
     * @param array $data
     * @return BaseDao|Model
     * @author xaboy
     * @day 2020-05-07
     */
    public function decMerBill(int $mer_id, string $category, string $type, array $data)
    {
        return $this->bill($mer_id, $category, $type, 0, $data);
    }

    public function type($category)
    {
        $data = [];
        foreach (self::TYPE_INFO[$category] as $type => $title) {
            $data[] = compact('type', 'title');
        }
        return $data;
    }

    /**
     * TODO 积分日志头部统计
     * @return array
     * @author Qinii
     * @day 6/9/21
     */
    public function getStat($merId = 0)
    {
        if($merId){
            $isusd = app()->make(ProductRepository::class)->getSearch(['mer_id' => $merId])->sum('integral_total');
            $refund = $this->dao->search(['category' => 'mer_integral','type' => 'refund','mer_id' => $merId])->sum('number');
            $numb = app()->make(ProductRepository::class)->getSearch(['mer_id' => $merId])->sum('integral_price_total');
            return [
                [
                    'className' => 'el-icon-s-cooperation',
                    'count' => $isusd,
                    'field' => '个',
                    'name' => '已使用积分（分）'
                ],
                [
                    'className' => 'el-icon-edit',
                    'count' => $refund,
                    'field' => '次',
                    'name' => '退款订单返回积分（分）'
                ],
                [
                    'className' => 'el-icon-edit',
                    'count' => $numb,
                    'field' => '次',
                    'name' => '积分抵扣金额（元）'
                ],
            ];
        }
        // 总积分
        $integral = app()->make(UserRepository::class)->search(['status' => 1])->sum('integral');
        // 客户签到次数
        $sign = app()->make(UserSignRepository::class)->getSearch([])->count('*');
        // 签到送出积分
        $sign_integral = $this->dao->search(['type' => 'sign_integral'])->sum('number');
        // 使用积分
        $isusd  = $this->dao->search(['category' => 'integral','type' => 'deduction'])->sum('number');
        $refund = $this->dao->search(['category' => 'mer_integral','type' => 'refund'])->sum('number');
        $order = $isusd - $refund;

        // 下单赠送积分
        $order_integral1 = $this->dao->search(['category' => 'integral','type' => 'lock'])->sum('number');
        $order_integral2 = $this->dao->search(['category' => 'integral','type' => 'refund_lock'])->sum('number');
        $order_integral = $order_integral1 - $order_integral2;
        $order_integral = $order_integral < 0 ? 0 : $order_integral;
        // 冻结积分
        $freeze_integral = $this->dao->lockIntegral();

        return [
            [
                'className' => 'el-icon-s-cooperation',
                'count' => $integral,
                'field' => '个',
                'name' => '总积分'
            ],
            [
                'className' => 'el-icon-edit',
                'count' => $sign,
                'field' => '次',
                'name' => '客户签到次数'
            ],
            [
                'className' => 'el-icon-s-goods',
                'count' => $sign_integral ,
                'field' => '个',
                'name' => '签到送出积分'
            ],
            [
                'className' => 'el-icon-s-order',
                'count' => $order,
                'field' => '个',
                'name' => '使用积分'
            ],
            [
                'className' => 'el-icon-present',
                'count' => $order_integral,
                'field' => '个',
                'name' => '下单赠送积分'
            ],
            [
                'className' => 'el-icon-warning',
                'count' => $freeze_integral,
                'field' => '',
                'name' => '冻结积分'
            ],
        ];
    }


    /**
     * 判断是否需要去除重复添加
     * @param int $uid
     * @param string $type
     * @param int $link_id
     * @return bool
     */
    public function ToRepeat(int $uid, string $type, int $link_id)
    {
        if (in_array($type, self::TO_REPEAT_TYPE)) {
            //判断是否重复
            $make = app()->make(UserBillRepository::class);
            $count = $make->getWhereCount(['uid' => $uid, 'type' => $type, 'link_id' => $link_id]);
            if ($count) {
                return true;
            }
        }
        return false;
    }

    public function dayFieldCount($day, $merId = null, $field = 'mer_integral')
    {
        return getModelTime(UserBill::getDB()->where(['status' => 1, 'category' => $field,'pm' => 1])->when($merId, function ($query, $merId) {
            $query->where('mer_id', $merId);
        }), $day, 'create_time')->sum('number');
    }

    // 所有积分
    public function allIntegralCount($merId = null,$field = 'mer_integral')
    {
        /*return getModelTime(UserBill::getDB()->where(['status' => 1, 'category' => 'integral','pm' => 1])->when($merId, function ($query, $merId) {
            $query->where('mer_id', $merId);
        }), 'all', 'create_time')->sum('number');*/
        // 总积分
        return app()->make(MerchantRepository::class)->search(['mer_id' => $merId])->sum('integral');
    }

    // 所有抵用券
    public function allCouponCount($merId = null,$field = 'coupon_amount')
    {
        return app()->make(MerchantRepository::class)->search(['mer_id' => $merId])->sum('coupon_amount');
    }

    public function syncUserBill()
    {

        $bills =UserBill::getDB()->where(['status' => -2, 'category' => 'coupon_amount','pm' => 1])->select();
        Db::transaction(function () use ($bills) {
            foreach ($bills as $bill) {
                // 用户
                if ($bill->number > 0 && $bill->uid!==0) {
                    $user=app()->make(UserRepository::class)->get($bill->uid);
                    $user->coupon_amount = bcadd($user->coupon_amount, $bill->number, 2);
                    $user->save();

                    $bill->status = 1;
                    $bill->create_time = date('Y-m-d H:i:s');
                    $bill->balance = $user->coupon_amount ?? 0;
                    $bill->save();
                }
                // 商户
                if ($bill->number > 0 && $bill->mer_id !== 0) {
                    $merchant = app()->make(MerchantRepository::class)->get($bill->mer_id);
                    $merchant->coupon_amount = bcadd($merchant->coupon_amount, $bill->number, 2);
                    $merchant->save();

                    $bill->status = 1;
                    $bill->create_time = date('Y-m-d H:i:s');
                    $bill->balance = $merchant->coupon_amount ?? 0;
                    $bill->save();
                }
            }
        });
    }

    /**
     * 今日权益值统计
     * @param string $type inc增加 dec减少
     * @return float
     */
    public function todayEquityValue($type = 'inc')
    {
        $where = [
            'category' => 'equity_value',
            'status' => 1,
        ];
        
        if ($type === 'inc') {
            $where[] = ['number', '>', 0];
        } else {
            $where[] = ['number', '<', 0];
        }
        
        return $this->dao->search($where)
            ->whereTime('create_time', 'today')
            ->sum('number') ?: 0;
    }

    /**
     * 本月权益值统计
     * @param string $type inc增加 dec减少
     * @return float
     */
    public function monthEquityValue($type = 'inc')
    {
        $where = [
            'category' => 'equity_value',
            'status' => 1,
        ];
        
        if ($type === 'inc') {
            $where[] = ['number', '>', 0];
        } else {
            $where[] = ['number', '<', 0];
        }
        
        return $this->dao->search($where)
            ->whereTime('create_time', 'month')
            ->sum('number') ?: 0;
    }


}
