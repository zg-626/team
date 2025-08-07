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


namespace app\common\repositories\store\order;


use app\common\dao\store\order\StoreOrderOfflineDao;
use app\common\dao\system\merchant\MerchantDao;
use app\common\dao\user\LabelRuleDao;
use app\common\dao\wechat\PayQrcodeDao;
use app\common\model\store\order\StoreOrderOffline;
use app\common\model\user\User;
use app\common\repositories\alipay\AlipayUserRepository;
use app\common\repositories\BaseRepository;
use app\common\repositories\store\coupon\StoreCouponUserRepository;
use app\common\repositories\store\order\StoreOrderRepository;
use app\common\repositories\system\groupData\GroupDataRepository;
use app\common\repositories\system\merchant\FinancialRecordRepository;
use app\common\repositories\system\merchant\MerchantRepository;
use app\common\repositories\user\MemberinterestsRepository;
use app\common\repositories\user\UserBillRepository;
use app\common\repositories\user\UserBrokerageRepository;
use app\common\repositories\user\UserMerchantRepository;
use app\common\repositories\user\UserRepository;
use app\common\repositories\wechat\WechatUserRepository;
use crmeb\jobs\OrderProfitsharingJob;
use crmeb\jobs\SendSmsJob;
use crmeb\jobs\UserBrokerageLevelJob;
use crmeb\services\MiniProgramService;
use crmeb\services\OfflineMiniProgramService;
use crmeb\services\OfflinePayService;
use crmeb\services\PayService;
use crmeb\services\SwooleTaskService;
use FormBuilder\Factory\Elm;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\exception\ValidateException;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\facade\Queue;
use think\Model;
use think\model\Relation;

/**
 * Class LabelRuleRepository
 * @package app\common\repositories\user
 * @author xaboy
 * @day 2020/10/20
 * @mixin StoreOrderOfflineDao
 */
class StoreOrderOfflineRepository extends BaseRepository
{

    // 线下门店支付
    const TYPE_SVIP = 'O-';

    /**
     * LabelRuleRepository constructor.
     * @param StoreOrderOfflineDao $dao
     */
    public function __construct(StoreOrderOfflineDao $dao)
    {
        $this->dao = $dao;
    }

    public function getList(array $where, $page, $limit)
    {
        $query = $this->dao->search($where);
        $count = $query->count();
        $list = $query->with([
            'user' => function($query){
                $query->field('uid,nickname,avatar,phone,is_svip,svip_endtime');
            },
            'merchant' => function ($query) {
                $query->field('mer_id,mer_name,mer_state,mer_avatar,is_trader,type_id')->with(['type_name']);
            }
        ])->order('create_time DESC')->page($page, $limit)->select()->toArray();
        return compact('count', 'list');
    }

    /**
     * @param $data
     * @return mixed
     * @author xaboy
     * @day 2020/10/21
     */
    public function add($money,$mer_id, $user, $params)
    {
        $type = $params['pay_type'];
        if ($params['return_url'] && $type === 'alipay') $body['return_url'] = $params['return_url'];

        if (in_array($type, ['weixin', 'alipay','routine'], true) && $params['is_app']) {
            $type .= 'App';
        }
        // 区分微信和支付宝
        $account_type = 'WECHAT';
        $trans_type = 71;
        if($type=='alipay'){
            $trans_type = 51;
            $account_type = 'ALIPAY';
            /** @var AlipayUserRepository $alipayUserRepository */
            $alipayUserRepository = app()->make(AlipayUserRepository::class);
            $openId = $alipayUserRepository->idByUserId($user['alipay_user_id']);
            if (!$openId)
                throw new ValidateException('请授权支付宝小程序!');
            //$is_share = 1;
        }else{
            $wechatUserRepository = app()->make(WechatUserRepository::class);
            $openId = $wechatUserRepository->idByRoutineId($user['wechat_user_id']);
            if (!$openId)
                throw new ValidateException('请关联微信小程序!');
        }

        $total_price=$money;

        $handling_fee=0;

        /*** @var MerchantDao $merchant */
        $merchant = app()->make(MerchantDao::class);

        $merchant = $merchant->search(['mer_id' => $mer_id])->field('mer_id,commission_rate,salesman_id,mer_name,mer_money,financial_bank,financial_wechat,financial_alipay,financial_type,sub_mchid,merchant_no,term_nos,province,city,province_id,city_id')->find();

        // 判断有没有申请子商户
        /*if ($merchant['sub_mchid'] == 0) {
            throw new ValidateException('该商家未申请子商户，无法下单');
        }*/
        /*if ($merchant['merchant_no'] == 0 || $merchant['term_nos'] == 0) {
            throw new ValidateException('该商家未填写拉卡拉商户号或者终端号，无法下单');
        }*/
        // 判断扫码下单还是直接下单
        if ($params['commission_rate'] == 0) {
            // 直接下单使用商家签订的比例
            $commission_rate=$merchant['commission_rate'];
            if($commission_rate==0){
                throw new ValidateException('该商家未设置积分比例');
            }
        }else{
            // 扫码下单使用扫码的比例
            $commission_rate=$params['commission_rate'];

            // 判断付款码是否禁用
            /** @var PayQrcodeDao $payQrcodeDao */
            $payQrcodeDao = app()->make(PayQrcodeDao::class);
            $qrcodeInfo = $payQrcodeDao->getWhere(['mer_id' => $merchant['mer_id'],'status'=>1, 'commission_rate' => $commission_rate]);

            /*if (!$qrcodeInfo) {
                throw new ValidateException('该比例的付款码已禁用或删除');
            }*/

            // 判断扫码的比例是否大于商家的比例
            // if($commission_rate>$merchant['commission_rate']){
            //     throw new ValidateException('该商家的积分最大比例为：'.$merchant['commission_rate'].'，您扫码的比例为：'.$commission_rate.'，请联系客服');
            // }
            // 判断扫码的比例是否小于2
            // if($commission_rate<2 || $commission_rate==0){
            //     throw new ValidateException('平台允许商家的积分最小比例为：2，您扫码的比例为：'.$commission_rate.'，请联系客服');
            // }
        }

        $rate=0;
        // 根据总金额计算平台手续费，不根据实际支付金额
        if(($money > 0) && $commission_rate >= 3) {
            $rate = $commission_rate /100;
            $handling_fee = bcmul($money,$rate, 2);
        }

        // 精确到小数点后两位
        $pay_price = round($money, 2);

        $total_give_integral = 0;

        // 根据总金额计算积分，不根据实际支付金额
        if ($total_price > 0 && $rate) {
            $total_give_integral = bcmul($total_price, $rate, 2);
        }

        // 抵用券
        if(isset($params['user_deduction']) && $params['user_deduction'] > 0){
            // 计算抵扣后的抵用券
            $user_coupon_amount = $user->coupon_amount;
            // 判断用户的抵用券是否大于抵用券额
            if($user_coupon_amount < $params['user_deduction']){
                throw new ValidateException('您的抵用券不足');
            }
            $deduction_money = bcsub($user_coupon_amount, $params['user_deduction'], 2);
            $user->coupon_amount = $deduction_money;
            $user->save();
            // 如果使用了抵用券，计算手续费
            $total_price=$params['user_deduction']+$money;
            $rate = $commission_rate /100;
            $handling_fee = bcmul($total_price,$rate, 2);
            // 如果使用了抵用券，计算积分
            $total_give_integral = bcmul($total_price, $rate, 2);

        }

        $isSelfBuy = $user->is_promoter && systemConfig('extension_self') ? 1 : 0;
        if ($isSelfBuy) {
            $spreadUser = $user;
            $topUser = $user->valid_spread;
        } else {
            $spreadUser = $user->valid_spread;
            $topUser = $user->valid_top;
        }

        $spreadUid = $spreadUser->uid ?? 0;
        $topUid = $topUser->uid ?? 0;
        $extension_one=0;
        $extension_two=0;

        // 推广比例
        $extension_one_rate = systemConfig('extension_one_rate');
        $extension_two_rate = systemConfig('extension_two_rate');

        if ($spreadUid) {
            $extension_one = $handling_fee > 0 ? bcmul($handling_fee, $extension_one_rate, 4) : 0;
        }
        if ($topUid) {
            $extension_two = $handling_fee > 0 ? bcmul($handling_fee, $extension_two_rate, 4) : 0;
        }

        // 关联商家市区信息
        $city = $merchant['city'];
        $city_id = $merchant['city_id'];
        $province = $merchant['province'];
        $province_id = $merchant['province_id'];
        // 判断是否是直辖市
        if($merchant['city']=='市辖区'){
            $city = $merchant['province'];
            $city_id = $merchant['province_id'];
        }

        $order_sn = $this->getNewOrderId(StoreOrderRepository::TYPE_SN_USER_ORDER);
        $data = [
            'title'     => '线下门店订单',
            'link_id'   => 0,
            'order_sn'  => $order_sn,
            'lkl_mer_cup_no' => $merchant['merchant_no'],
            'pay_price' => $pay_price,
            'order_info' => 0,
            'uid'        => $user->uid,
            'order_type' => self::TYPE_SVIP,
            'pay_type'   =>  $params['pay_type'],
            'commission_rate'=>$commission_rate,
            'handling_fee' => $handling_fee,
            'status'     => 1,
            'mer_id'     => $mer_id,
            'give_integral' => $total_give_integral,
            'other'     => 0,
            'spread_uid' => $spreadUid,
            'top_uid' => $topUid,
            'is_selfbuy' => $isSelfBuy,
            'extension_one' => $extension_one,
            'extension_two' => $extension_two,
            'total_price' => $total_price,
            'deduction' => $params['user_deduction']?: 0,
            'deduction_money' => $params['user_deduction']?: 0,
            'city' => $city,
            'city_id' => $city_id,
            'province' => $province,
            'province_id' => $province_id,
            'to_uid'=>$params['to_uid']?:0
        ];

        // 微信服务商支付
        /*$body = [
            'out_trade_no' => $order_sn,
            'pay_price' => $money,
            'attach' => 'offline_order',
            'sub_mchid' => $merchant['sub_mchid'],
            'description' => '线下商品消费',
            'body' =>'线下门店支付'
        ];*/

        $info = $this->dao->create($data);
        // 拉卡拉支付参数
        $params = [
            'order_no' => $order_sn,
            'total_amount' => $pay_price,
            'remark' => 'offline_order',
            'merchant_no' => $merchant['merchant_no'],
            'term_nos' => $merchant['term_nos'],
            'openid' => $openId,
            'trans_type' => $trans_type,
            'account_type' => $account_type,
            'goods_id' => '1',
            'settle_type' => '1',
        ];

        if ($pay_price>0){

            $api = new \Lakala\LklApi();
            $result = $api::lklPreorder($params);
            if (!$result) {
                return app('json')->fail($api->getErrorInfo());
            }
            $config=[
                'config' => $result
            ];
            return app('json')->status($type, $config + ['order_id' => $info->order_id]);
        } else {
            $this->paySuccess($data);
            return app('json')->status('success', ['order_id' => $info->order_id]);
        }
    }

    /**
     * @param $data
     * @return mixed
     * @author xaboy
     * @day 2020/10/21
     */
    public function enter($money,$mer_id, $params)
    {
        $uid = $params['uid'];
        $user = app()->make(UserRepository::class)->get($uid);
        $type = $params['pay_type'];

        $total_price=$money;

        $handling_fee=0;

        /*** @var MerchantDao $merchant */
        $merchant = app()->make(MerchantDao::class);

        $merchant = $merchant->search(['mer_id' => $mer_id])->field('mer_id,commission_rate,salesman_id,mer_name,mer_money,financial_bank,financial_wechat,financial_alipay,financial_type,sub_mchid,merchant_no,term_nos,province,city,province_id,city_id')->find();

        // 判断扫码下单还是直接下单
        if ($params['commission_rate'] == 0) {
            // 直接下单使用商家签订的比例
            $commission_rate=$merchant['commission_rate'];
            if($commission_rate==0){
                throw new ValidateException('该商家未设置积分比例');
            }
        }else{
            // 扫码下单使用扫码的比例
            $commission_rate=$params['commission_rate'];
        }

        $rate=0;
        // 根据总金额计算平台手续费，不根据实际支付金额
        if(($money > 0) && $commission_rate >= 3) {
            $rate = $commission_rate /100;
            $handling_fee = bcmul($money,$rate, 2);
        }

        // 精确到小数点后两位
        $pay_price = round($money, 2);

        $total_give_integral = 0;

        // 根据总金额计算积分，不根据实际支付金额
        if ($total_price > 0 && $rate) {
            $total_give_integral = bcmul($total_price, $rate, 2);
        }

        // 抵用券
        if(isset($params['user_deduction']) && $params['user_deduction'] > 0){
            // 计算抵扣后的抵用券
            $user_coupon_amount = $user->coupon_amount;
            // 判断用户的抵用券是否大于抵用券额
            if($user_coupon_amount < $params['user_deduction']){
                throw new ValidateException('您的抵用券不足');
            }
            $deduction_money = bcsub($user_coupon_amount, $params['user_deduction'], 2);
            $user->coupon_amount = $deduction_money;
            $user->save();
            // 如果使用了抵用券，计算手续费
            $total_price=$params['user_deduction']+$money;
            $rate = $commission_rate /100;
            $handling_fee = bcmul($total_price,$rate, 2);
            // 如果使用了抵用券，计算积分
            $total_give_integral = bcmul($total_price, $rate, 2);

        }

        $isSelfBuy = $user->is_promoter && systemConfig('extension_self') ? 1 : 0;
        if ($isSelfBuy) {
            $spreadUser = $user;
            $topUser = $user->valid_spread;
        } else {
            $spreadUser = $user->valid_spread;
            $topUser = $user->valid_top;
        }

        $spreadUid = $spreadUser->uid ?? 0;
        $topUid = $topUser->uid ?? 0;
        $extension_one=0;
        $extension_two=0;

        // 推广比例
        $extension_one_rate = systemConfig('extension_one_rate');
        $extension_two_rate = systemConfig('extension_two_rate');

        if ($spreadUid) {
            $extension_one = $handling_fee > 0 ? bcmul($handling_fee, $extension_one_rate, 4) : 0;
        }
        if ($topUid) {
            $extension_two = $handling_fee > 0 ? bcmul($handling_fee, $extension_two_rate, 4) : 0;
        }

        // 关联商家市区信息
        $city = $merchant['city'];
        $city_id = $merchant['city_id'];
        $province = $merchant['province'];
        $province_id = $merchant['province_id'];
        // 判断是否是直辖市
        if($merchant['city']=='市辖区'){
            $city = $merchant['province'];
            $city_id = $merchant['province_id'];
        }

        $order_sn = $this->getNewOrderId(StoreOrderRepository::TYPE_SN_USER_ORDER);
        $data = [
            'title'     => '手动录入门店订单',
            'link_id'   => 0,
            'order_sn'  => $order_sn,
            'lkl_mer_cup_no' => $merchant['merchant_no'],
            'pay_price' => $pay_price,
            'order_info' => 0,
            'uid'        => $user->uid,
            'order_type' => self::TYPE_SVIP,
            'pay_type'   =>  $params['pay_type'],
            'commission_rate'=>$commission_rate,
            'handling_fee' => $handling_fee,
            'status'     => 1,
            'mer_id'     => $mer_id,
            'give_integral' => $total_give_integral,
            'other'     => 0,
            'spread_uid' => $spreadUid,
            'top_uid' => $topUid,
            'is_selfbuy' => $isSelfBuy,
            'extension_one' => $extension_one,
            'extension_two' => $extension_two,
            'total_price' => $total_price,
            'deduction' => $params['user_deduction']?: 0,
            'deduction_money' => $params['user_deduction']?: 0,
            'payment_voucher' => $params['payment_voucher']??'',
            'city' => $city,
            'city_id' => $city_id,
            'province' => $province,
            'province_id' => $province_id,
            'to_uid'=>$params['to_uid']?:0
        ];

        $info = $this->dao->create($data);


        if ($pay_price>0){
            /** @var StoreOrderOfflineRepository $storeOrderOfflineRepository */
            $storeOrderOfflineRepository = app()->make(__CLASS__);
            $order = $storeOrderOfflineRepository->getWhere(['order_id' => [$info->order_id]]);
            if($params['pay_type']==='manual'){
                $storeOrderOfflineRepository->computeds($order);
            }

            return app('json')->status($type,['order_id' => $info->order_id]);
        }

        //$this->paySuccess($data);
        //return app('json')->status('success', ['order_id' => $info->order_id]);
    }

    /**
     * @return string
     * @author xaboy
     * @day 2020/6/9
     */
    public function getNewOrderId($type)
    {
        list($msec, $sec) = explode(' ', microtime());
        $msectime = number_format((floatval($msec) + floatval($sec)) * 1000, 0, '', '');
        $orderId = $type . $msectime . mt_rand(10000, max(intval($msec * 10000) + 10000, 98369));
        return $orderId;
    }

    // 发货确认
    public function shipping($data)
    {
        // 替换更新发货后的流水号
        $out_trade_no = $data['order_sn'];
        /** @var StoreOrderOfflineRepository $storeOrderOfflineRepository */
        $storeOrderOfflineRepository = app()->make(StoreOrderOfflineRepository::class);
        $res = $storeOrderOfflineRepository->getWhere(['order_sn' => $out_trade_no]);
        if (!empty($res)) {
            $res->lkl_log_no = $data['data']['log_no'];
            $res->save();
            $order = $storeOrderOfflineRepository->getWhere(['order_sn' => $out_trade_no]);
            $date = substr($res['lkl_log_date'], 0, 8);
            $params = [
                'lkl_mer_cup_no' => $res['lkl_mer_cup_no'],
                'lkl_log_no' => $data['data']['log_no'], // 用最新的流水号
                'lkl_log_date' => $date,
            ];
            // 可分账金额查询
            //$this->lklQueryAmt($params,$order);
        }
    }

    // 拉卡拉可分账查询
    public function lklQueryAmt($params, $res)
    {
        $api = new \Lakala\LklApi();
        $result = $api::lklQueryAmt($params);
        if (!$result) {
            record_log('时间: ' . date('Y-m-d H:i:s') . ', 拉卡拉可分账金额查询异常: ' . $api->getErrorInfo(), 'queryAmt');
            /** @var StoreOrderProfitsharingRepository $storeOrderProfitsharingRepository */
            $storeOrderProfitsharingRepository = app()->make(StoreOrderProfitsharingRepository::class);
            $profitsharing =$storeOrderProfitsharingRepository ->getWhere(['order_id' => $res['order_id']]);
            $profitsharing->status = -2;
            $profitsharing->error_msg = $api->getErrorInfo();
            $profitsharing->save();
        }
        if (isset($result['total_separate_amt'])) {
            $can_separate_amt = $result['total_separate_amt'];
            if ($can_separate_amt > 0) {
                $this->lklSeparate($params, $can_separate_amt, $res);
            }
        }

    }

    // 拉卡拉分账参数拼接
    public function lklSeparate($param, $can_separate_amt, $res): void
    {
        // 平台抽取的费用
        //$handling_fee = (float)bcmul($res->handling_fee, 100, 0);
        // 实际支付金额收取的手续费
        $handling_fee = (float)bcmul(
            ($res->pay_price * $res->commission_rate) / 100,
            100, // 转换为分
            0    // 小数位数
        );
        // 总金额>0时
        if($can_separate_amt >0){
            $param['can_separate_amt'] = $can_separate_amt;
            $param['recv_datas'] = [
                [
                    'recv_merchant_no' => $param['lkl_mer_cup_no'],
                    'separate_value' => 1-($res['commission_rate']/100)
                ],
                [
                    'recv_no' =>'SR2024000078730' ,// TODO 拉卡拉分账接收方
                    'separate_value' => $res['commission_rate']/100
                ]
            ];
            // 同步更新分账总金额
            $res->total_separate_amt=$can_separate_amt;
            $res->actual_separate_amt=$can_separate_amt - $handling_fee;
            $res->save();

            // 同步更新订单分账表
            /** @var StoreOrderProfitsharingRepository $storeOrderProfitsharingRepository */
            $storeOrderProfitsharingRepository = app()->make(StoreOrderProfitsharingRepository::class);
            $models =$storeOrderProfitsharingRepository ->getWhere(['order_id' => $res['order_id']]);

            $api = new \Lakala\LklApi();
            $param['cal_type']=1;
            $result = $api::lklSeparate($param);
            if (!$result) {
                $models->status = -2;// 分账失败
                $models->error_msg = $api->getErrorInfo();
                $models->save();
                record_log('时间: ' . date('Y-m-d H:i:s') . ', 拉卡拉分账异常: ' . $api->getErrorInfo(), 'separate');
            }
            // 分账成功 同步状态
            if(isset($result['log_no'])){
                $models->status = 1;// 分账成功
                $models->save();
            }

        }

    }

    public function paySuccess($data)
    {
        $res = $this->dao->getWhere(['order_sn' => $data['order_sn']]);
        if($res->paid == 1){
            return true;
        }
        $type = explode('-',$res['order_type'])[0].'-';
        if ($type == self::TYPE_SVIP) {
            //return Db::transaction(function () use($data, $res) {
            $res->paid = 1;
            $res->transaction_id = $data['data']['acc_trade_no']??'';
            $res->lkl_log_no = $data['data']['log_no']??'';
            $res->lkl_trade_no = $data['data']['trade_no']??'';
            $res->lkl_log_date = $data['data']['trade_time']??'';
            $res->pay_time = date('y_m-d H:i:s', time());
            $res->save();
            $order = $res;

            $user = app()->make(UserRepository::class)->get($res['uid']);

            /** @var MerchantRepository $merchantRepository */
            $merchantRepository=app()->make(MerchantRepository::class);
            // 如果用户使用了抵扣券，给商户增加余额，用于平台补贴
            if($order->deduction > 0){
                $totalPrice = $order->total_price;
                $feeRate = $order->commission_rate; // 手续费率

                // 平台手续费(根据总金额)
                $platformFee = $order->handling_fee;
                // 第三方实际手续费
                $thirdPlatformFee = round((float)$order->pay_price * (float)$feeRate / 100,2);

                // 用户实际支付金额（不能为负数）
                $actualPayment = max($totalPrice - $order->deduction, 0);

                // 计算平台补贴
                if ($actualPayment > 0) {
                     // 情况1：正常抵扣（实际支付 > 0）
                    // 补贴 = 抵扣券金额 - (平台手续费 - 第三方实际手续费)
                    // 即：补贴商家抵扣券金额，但扣除平台多承担的手续费部分
                    $subsidyAdjustment = $platformFee - $thirdPlatformFee; // 平台多承担的手续费
                    $subsidy = max($order->deduction - $subsidyAdjustment, 0);
                } else {
                    // 情况2：零元购（实际支付 = 0）
                    // 补贴 = 抵扣券金额 - 手续费（确保手续费被覆盖）
                    $subsidy = max($order->deduction - $platformFee, 0);
                }

                // 发放补贴给商家
                $merchantRepository->addOlllineMoney(
                    $order->mer_id,
                    'order',
                    $order->order_id,
                    $subsidy
                );
                // 增加使用记录
                $userBillRepository = app()->make(UserBillRepository::class);
                $userBillRepository->decBill($order->uid, 'coupon_amount', 'deduction', [
                    'link_id' => $order['order_id'],
                    'status' => 1,
                    'title' => '线下消费使用抵用券',
                    'number' => $order->deduction,
                    'mark' => $user->nickname. '线下消费' . (float)$order->total_price. '元,扣减抵用券' .$order->deduction,
                    'balance' => 0,
                ]);
            }

            // 赠送积分
            $this->giveIntegral($order);

            // 赠送商户积分
            $merchantRepository->addMerIntegral($order);
            // 给商户增加累计收款
            $merchantRepository->giveMerGrandMoney($order->mer_id,$order->pay_price);

            // 所有身份赠送佣金
            /** @var StoreOrderRepository $storeOrderRepository */
            //$storeOrderRepository = app()->make(StoreOrderRepository::class);
            //$storeOrderRepository->addCommission($order->mer_id,$order);

            // 发放推广抵用券
            $this->computed($order,$user);

            $handling_fee = floatval($order->handling_fee);
            $total_amount = bcmul((string)$handling_fee, "0.4", 2);

            // 记录本次分红池,手续费的40%
            try {
                $poolInfo = Db::name('dividend_pool')->where('city_id', $order->city_id)->order('id', 'desc')->find();
                Log::info('查询分红池');
                if (!$poolInfo) {
                    Log::info('创建分红池' . $order->city);
                    // 第一次创建分红池记录
                    Db::name('dividend_pool')->insert([
                        'total_amount' => $total_amount,
                        'available_amount' => $total_amount,
                        'initial_threshold' => $total_amount,
                        'distributed_amount' => 0,
                        'city_id' => $order->city_id,
                        'city' => $order->city,
                        'create_time' => date('Y-m-d H:i:s'),
                        'update_time' => date('Y-m-d H:i:s')
                    ]);
                } else {
                    Log::info('更新分红池' . $poolInfo['id']);
                    // 更新现有分红池
                    Db::name('dividend_pool')->where('id', $poolInfo['id'])->update([
                        'total_amount' => Db::raw('total_amount + ' . $total_amount),
                        'available_amount' => Db::raw('available_amount + ' . $total_amount),
                        'initial_threshold' => Db::raw('initial_threshold + ' . $total_amount),
                        'update_time' => date('Y-m-d H:i:s')
                    ]);
                }

                // 分红池流水表
                Db::name('dividend_pool_log')->insert([
                    'order_id' => $order->order_id,
                    'amount' => $total_amount,
                    'handling_fee' => $handling_fee,
                    'mer_id' => $order->mer_id,
                    'uid' => $order->uid,
                    'city' => $order->city,
                    'city_id' => $order->city_id,
                    'create_time' => date('Y-m-d H:i:s'),
                    'remark' => '订单分红入池'
                ]);
            } catch (\Exception $e) {
                Log::info('查询分红池失败' . $order->order_id . $e->getMessage().$e->getLine());
            }

            $this->payAfter($res);

            // 更新用户支付时间
            /** @var UserMerchantRepository $userMerchantRepository */
            $userMerchantRepository = app()->make(UserMerchantRepository::class);
            $userMerchantRepository->updatePayTime($order->uid, $order->mer_id, $order->pay_price,true,$order->order_id,$order->handling_fee);
            SwooleTaskService::merchant('notice', [
                'type' => 'new_order',
                'data' => [
                    'title' => '新订单',
                    'message' => '您有一个新的订单',
                    'id' => $order->order_id
                ]
            ], $order->mer_id);

            if ($order->spread_uid) {
                Queue::push(UserBrokerageLevelJob::class, ['uid' => $order->spread_uid, 'type' => 'spread_pay_num', 'inc' => 1]);
                Queue::push(UserBrokerageLevelJob::class, ['uid' => $order->spread_uid, 'type' => 'spread_money', 'inc' => $order->pay_price]);
            }
            app()->make(UserRepository::class)->update($order->uid, [
                'pay_count' => Db::raw('pay_count+' . 1),
                'pay_price' => Db::raw('pay_price+' . $order->pay_price),
                //'svip_save_money' => Db::raw('svip_save_money+' . $svipDiscount),
            ]);

            // 获取最新的用户信息，如果用户消费金额pay_price>10000元，且是普通用户，给用户升级为商务
            if($user->pay_price > 10000 && $user->group_id === 1){
                $user->group_id = 2;
                $user->save();
            }

            // 创建分账账单
            /** @var StoreOrderProfitsharingRepository $storeOrderProfitsharingRepository */
            $storeOrderProfitsharingRepository = app()->make(StoreOrderProfitsharingRepository::class);
            // 支付金額不是0
            if ($order->pay_price > 0) {
                $status = 0;
                // 根据支付类型判断status
                if ($order->pay_type == 'alipay') {
                    $status = 2;
                }
                $profitsharing= [
                    'profitsharing_sn' => $storeOrderProfitsharingRepository->getOrderSn(),
                    'order_id' => $order->order_id,
                    'transaction_id' => $order->transaction_id ?? '',
                    'mer_id' => $order->mer_id,
                    'profitsharing_price' => $order->pay_price,
                    'profitsharing_mer_price' => $order->pay_price - $order->handling_fee,
                    'status'=> $status,
                    'type' => $storeOrderProfitsharingRepository::PROFITSHARING_TYPE_ORDER,
                ];

                $profitsharingInfo = $storeOrderProfitsharingRepository->create($profitsharing);
                // 虚拟发货
                //$this->virtualDelivery($res);
            }



            Queue::push(SendSmsJob::class, ['tempId' => 'ORDER_PAY_SUCCESS', 'id' => $order->order_id]);
            Queue::push(SendSmsJob::class, ['tempId' => 'ADMIN_PAY_SUCCESS_CODE', 'id' => $order->order_id]);
            Queue::push(UserBrokerageLevelJob::class, ['uid' => $order->uid, 'type' => 'pay_money', 'inc' => $order->pay_price]);
            Queue::push(UserBrokerageLevelJob::class, ['uid' => $order->uid, 'type' => 'pay_num', 'inc' => 1]);
            app()->make(UserBrokerageRepository::class)->incMemberValue($order->uid, 'member_pay_num', $order->order_id);

            //});
        }
    }

    // 补发奖池
    public function red($order)
    {
        $handling_fee = floatval($order->handling_fee);
        $total_amount = bcmul((string)$handling_fee, "0.4", 2);

        try {
            $poolInfo = Db::name('dividend_pool')->where('city_id', $order->city_id)->order('id', 'desc')->find();
            if (!$poolInfo) {
                // 第一次创建分红池记录
                Db::name('dividend_pool')->insert([
                    'total_amount' => $total_amount,
                    'available_amount' => $total_amount,
                    'distributed_amount' => 0,
                    'city_id' => $order->city_id,
                    'city' => $order->city,
                    'create_time' => date('Y-m-d H:i:s'),
                    'update_time' => date('Y-m-d H:i:s')
                ]);
            } else {
                // 更新现有分红池
                Db::name('dividend_pool')->where('id', $poolInfo['id'])->update([
                    'total_amount' => Db::raw('total_amount + ' . $total_amount),
                    'available_amount' => Db::raw('available_amount + ' . $total_amount),
                    'update_time' => date('Y-m-d H:i:s')
                ]);
            }

            // 分红池流水表
            Db::name('dividend_pool_log')->insert([
                'order_id' => $order->order_id,
                'amount' => $total_amount,
                'handling_fee' => $handling_fee,
                'mer_id' => $order->mer_id,
                'uid' => $order->uid,
                'city' => $order->city,
                'city_id' => $order->city_id,
                'create_time' => date('Y-m-d H:i:s'),
                'remark' => '订单分红入池'
            ]);
        } catch (\Exception $e) {
            Log::info('查询分红池失败' . $order->order_id . $e->getMessage());
        }

    }

    // 二次补发
    public function computeds($order)
    {
        $res=$order;
        $res->paid = 1;
        $res->is_share = 2;
        $res->pay_time = date('y_m-d H:i:s', time());
        $res->save();
        /** @var MerchantRepository $merchantRepository */
        $merchantRepository=app()->make(MerchantRepository::class);
        $user = app()->make(UserRepository::class)->get($res['uid']);
        // 如果用户使用了抵扣券，给商户增加余额，用于平台补贴
        if($order->deduction > 0){
            $totalPrice = $order->total_price;
            $feeRate = $order->commission_rate; // 手续费率

            // 平台手续费(根据总金额)
            $platformFee = $order->handling_fee;
            // 第三方实际手续费
            $thirdPlatformFee = round((float)$order->pay_price * (float)$feeRate / 100,2);

            // 用户实际支付金额（不能为负数）
            $actualPayment = max($totalPrice - $order->deduction, 0);

            // 计算平台补贴
            if ($actualPayment > 0) {
                // 情况1：正常抵扣（实际支付 > 0）
                // 补贴 = 抵扣券金额 - (平台手续费 - 第三方实际手续费)
                // 即：补贴商家抵扣券金额，但扣除平台多承担的手续费部分
                $subsidyAdjustment = $platformFee - $thirdPlatformFee; // 平台多承担的手续费
                $subsidy = max($order->deduction - $subsidyAdjustment, 0);
            } else {
                // 情况2：零元购（实际支付 = 0）
                // 补贴 = 抵扣券金额 - 手续费（确保手续费被覆盖）
                $subsidy = max($order->deduction - $platformFee, 0);
            }

            // 发放补贴给商家
            $merchantRepository->addOlllineMoney(
                $order->mer_id,
                'order',
                $order->order_id,
                $subsidy
            );
            // 增加使用记录
            $userBillRepository = app()->make(UserBillRepository::class);
            $userBillRepository->decBill($order->uid, 'coupon_amount', 'deduction', [
                'link_id' => $order['order_id'],
                'status' => 1,
                'title' => '线下消费使用抵用券',
                'number' => $order->deduction,
                'mark' => $user->nickname. '线下消费' . (float)$order->total_price. '元,扣减抵用券' .$order->deduction,
                'balance' => 0,
            ]);
        }

        // 赠送积分
        $this->giveIntegral($order);

        // 赠送商户积分
        $merchantRepository->addMerIntegral($order);
        // 给商户增加累计收款
        $merchantRepository->giveMerGrandMoney($order->mer_id,$order->pay_price);

        // 所有身份赠送佣金
        /** @var StoreOrderRepository $storeOrderRepository */
        $storeOrderRepository = app()->make(StoreOrderRepository::class);
        $storeOrderRepository->addCommission($order->mer_id,$order);

        // 发放推广抵用券
        $this->computed($order,$user);

        // 更新用户支付时间
        /** @var UserMerchantRepository $userMerchantRepository */
        $userMerchantRepository = app()->make(UserMerchantRepository::class);
        $userMerchantRepository->updatePayTime($order->uid, $order->mer_id, $order->pay_price,true,$order->order_id,$order->handling_fee);
//        SwooleTaskService::merchant('notice', [
//            'type' => 'new_order',
//            'data' => [
//                'title' => '新订单',
//                'message' => '您有一个新的订单',
//                'id' => $order->order_id
//            ]
//        ], $order->mer_id);
        if ($order->spread_uid) {
            Queue::push(UserBrokerageLevelJob::class, ['uid' => $order->spread_uid, 'type' => 'spread_pay_num', 'inc' => 1]);
            Queue::push(UserBrokerageLevelJob::class, ['uid' => $order->spread_uid, 'type' => 'spread_money', 'inc' => $order->pay_price]);
        }
        app()->make(UserRepository::class)->update($order->uid, [
            'pay_count' => Db::raw('pay_count+' . 1),
            'pay_price' => Db::raw('pay_price+' . $order->pay_price),
            //'svip_save_money' => Db::raw('svip_save_money+' . $svipDiscount),
        ]);
        // 获取最新的用户信息，如果用户消费金额pay_price>10000元，且是普通用户，给用户升级为商务
        if($user->pay_price > 10000 && $user->group_id === 1){
            $user->group_id = 2;
            $user->save();
        }
        // 创建分账账单
        /** @var StoreOrderProfitsharingRepository $storeOrderProfitsharingRepository */
        $storeOrderProfitsharingRepository = app()->make(StoreOrderProfitsharingRepository::class);
        // 支付金額不是0
        /*if ($order->pay_price > 0) {
            $profitsharing= [
                'profitsharing_sn' => $storeOrderProfitsharingRepository->getOrderSn(),
                'order_id' => $order->order_id,
                'transaction_id' => $order->transaction_id ?? '',
                'mer_id' => $order->mer_id,
                'profitsharing_price' => $order->pay_price,
                'profitsharing_mer_price' => $order->pay_price - $order->handling_fee,
                'type' => $storeOrderProfitsharingRepository::PROFITSHARING_TYPE_ORDER,
            ];

            $profitsharingInfo = $storeOrderProfitsharingRepository->create($profitsharing);
            // 虚拟发货
            $this->virtualDelivery($res);
        }*/
        $user = app()->make(UserRepository::class)->get($res['uid']);
        // 发放推广抵用券
        //$this->computed($res,$user);
        $handling_fee = floatval($order->handling_fee);
        $total_amount = bcmul((string)$handling_fee, "0.4", 2);

        // 记录本次分红池,手续费的40%
        try {
            $poolInfo = Db::name('dividend_pool')->where('city_id', $order->city_id)->order('id', 'desc')->find();
            Log::info('查询分红池');
            if (!$poolInfo) {
                Log::info('创建分红池' . $order->city);
                // 第一次创建分红池记录
                Db::name('dividend_pool')->insert([
                    'total_amount' => $total_amount,
                    'available_amount' => $total_amount,
                    'initial_threshold' => $total_amount,
                    'distributed_amount' => 0,
                    'city_id' => $order->city_id,
                    'city' => $order->city,
                    'create_time' => date('Y-m-d H:i:s'),
                    'update_time' => date('Y-m-d H:i:s')
                ]);
            } else {
                Log::info('更新分红池' . $poolInfo['id']);
                // 更新现有分红池
                Db::name('dividend_pool')->where('id', $poolInfo['id'])->update([
                    'total_amount' => Db::raw('total_amount + ' . $total_amount),
                    'available_amount' => Db::raw('available_amount + ' . $total_amount),
                    'initial_threshold' => Db::raw('initial_threshold + ' . $total_amount),
                    'update_time' => date('Y-m-d H:i:s')
                ]);
            }

            // 分红池流水表
            Db::name('dividend_pool_log')->insert([
                'order_id' => $order->order_id,
                'amount' => $total_amount,
                'handling_fee' => $handling_fee,
                'mer_id' => $order->mer_id,
                'uid' => $order->uid,
                'city' => $order->city,
                'city_id' => $order->city_id,
                'create_time' => date('Y-m-d H:i:s'),
                'remark' => '订单分红入池'
            ]);
        } catch (\Exception $e) {
            Log::info('查询分红池失败' . $order->order_id . $e->getMessage().$e->getLine());
        }
        return $this->payAfter($res);

    }

    // 虚拟发货
    public function virtualDelivery($order)
    {
        //查找支付者openid
        $payer_openid = $this->getPayerOpenid($order['uid']);
        $order_id = $order['order_sn'];
        $order_key = [
            'out_trade_no' => $order_id,
            'transaction_id' => $order['transaction_id'],
            'lkl_mer_cup_no' => $order['lkl_mer_cup_no']
        ];
        // 不是拆单
        $delivery_mode = 1;
        $is_all_delivered = true;
        $path = '/pages/users/user_bill/index';
        $delivery_id='';
        $delivery_name='mini_order_shipping';
        // 整理商品信息
        $logistics_type = $this->getLogisticsType(3);
        $item_desc = '线下消费' . $order['pay_price'];
        $shipping_list = $this->getShippingList($logistics_type, $item_desc,'', $delivery_id, $delivery_name);
        $queue_param = compact('order_key', 'logistics_type', 'shipping_list', 'payer_openid', 'path', 'delivery_mode', 'is_all_delivered');
        OfflineMiniProgramService::create()->uploadOfflineShippingInfo($order_key, $logistics_type, $shipping_list, $payer_openid, $path, $delivery_mode, $is_all_delivered);
    }

    /**
     * 获取商品发货信息
     * @param string $logistics_type
     * @param string $receiver_contact
     * @param string $delivery_id
     * @param string $delivery_name
     * @return array|array[]
     *
     * @date 2023/10/18
     * @author yyw
     */
    public function getShippingList(string $logistics_type, string $item_desc, string $receiver_contact = '', string $delivery_id = '', string $delivery_name = '')
    {
        if ($logistics_type == 1) {
            return [
                [
                    'tracking_no' => $delivery_id ?? '',
                    'express_company' => $delivery_name ?? '',
                    'contact' => [
                        'receiver_contact' => $receiver_contact
                    ],
                    'item_desc' => $item_desc
                ]
            ];
        } else {
            return [
                ['item_desc' => $item_desc]
            ];
        }
    }

    /**
     * 获取支付者openid
     * @param int $uid
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     *
     * @date 2023/10/18
     * @author yyw
     */
    public function getPayerOpenid(int $uid)
    {
        $user = app()->make(UserRepository::class)->get($uid);
        if (empty($user)) {
            throw new ValidateException('用户异常');
        }
        $wechatUser = app()->make(WechatUserRepository::class)->get($user['wechat_user_id']);
        if (empty($wechatUser)) {
            throw new ValidateException('微信用户异常');
        }
        if (empty($wechatUser['routine_openid'])) {
            throw new ValidateException('订单支付者不是小程序');
        }

        return $wechatUser['routine_openid'];
    }

    /**
     * 转换发货类型
     * @param string $delivery_type
     * @return int
     *
     * @date 2023/10/18
     * @author yyw
     */
    public function getLogisticsType(string $delivery_type)
    {

        switch ($delivery_type) {
            case '1':   // 发货
            case '4':   //电子面单
                $logistics_type = 1;
                break;
            case '5':  // 同城
            case '2':    // 送货
                $logistics_type = 2;
                break;
            case '3':    // 虚拟
            case '6':    // 卡密
                $logistics_type = 3;
                break;
            case '7':    // 自提
                $logistics_type = 4;
                break;
            default:
                throw new ValidateException('发货类型异常');
        }

        return $logistics_type;
    }

    /**
     * @param  $order
     * @param User $user
     * @author xaboy
     * @day 2020/8/3
     */
    public function computed($order, User $user)
    {
        $userBillRepository = app()->make(UserBillRepository::class);
        if ($order->spread_uid) {
            $spreadUid = $order->spread_uid;
            $topUid = $order->top_uid;
        } else if ($order->is_selfbuy) {
            $spreadUid = $user->uid;
            $topUid = $user->spread_uid;
        } else {
            $spreadUid = $user->spread_uid;
            $topUid = $user->top_uid;
        }
        //TODO 添加冻结佣金
        if ($order->extension_one > 0 && $spreadUid) {
            /*$userBillRepository->incBill($spreadUid, 'brokerage', 'order_one', [
                'link_id' => $order['order_id'],
                'status' => 0,
                'title' => '获得推广佣金',
                'number' => $order->extension_one,
                'mark' => $user['nickname'] . '成功消费' . floatval($order['pay_price']) . '元,奖励推广佣金' . floatval($order->extension_one),
                'balance' => 0
            ]);*/
            // 佣金改为抵用券
            $userBillRepository->incBill($spreadUid, 'coupon_amount', 'order_one', [
                'link_id' => $order['order_id'],
                'status' => 0,
                'title' => '获得推广抵用券',
                'number' => $order->extension_one,
                'mark' => $user['nickname'] . '成功消费' . floatval($order['pay_price']) . '元,奖励推广抵用券' . floatval($order->extension_one),
                'balance' => 0
            ]);
            $userRepository = app()->make(UserRepository::class);
            $userRepository->incBrokerage($spreadUid, $order->extension_one);
            //            app()->make(FinancialRecordRepository::class)->dec([
            //                'order_id' => $order->order_id,
            //                'order_sn' => $order->order_sn,
            //                'user_info' => $userRepository->getUsername($spreadUid),
            //                'user_id' => $spreadUid,
            //                'financial_type' => 'brokerage_one',
            //                'number' => $order->extension_one,
            //            ], $order->mer_id);
        }
        if ($order->extension_two > 0 && $topUid) {
            // 获得推广佣金改为获得推广抵用券
            $userBillRepository->incBill($topUid, 'coupon_amount', 'order_two', [
                'link_id' => $order['order_id'],
                'status' => 0,
                'title' => '获得推广抵用券',
                'number' => $order->extension_two,
                'mark' => $user['nickname'] . '成功消费' . floatval($order['pay_price']) . '元,奖励推广抵用券' . floatval($order->extension_two),
                'balance' => 0
            ]);
            /*$userBillRepository->incBill($topUid, 'brokerage', 'order_two', [
                'link_id' => $order['order_id'],
                'status' => 0,
                'title' => '获得推广佣金',
                'number' => $order->extension_two,
                'mark' => $user['nickname'] . '成功消费' . floatval($order['pay_price']) . '元,奖励推广佣金' . floatval($order->extension_two),
                'balance' => 0
            ]);*/
            $userRepository = app()->make(UserRepository::class);
            $userRepository->incBrokerage($topUid, $order->extension_two);
            //            app()->make(FinancialRecordRepository::class)->dec([
            //                'order_id' => $order->order_id,
            //                'order_sn' => $order->order_sn,
            //                'user_info' => $userRepository->getUsername($topUid),
            //                'user_id' => $topUid,
            //                'financial_type' => 'brokerage_two',
            //                'number' => $order->extension_two,
            //            ], $order->mer_id);
        }
    }

    public function giveIntegral($offlineOrder,$type='线下门店')
    {
        if ($offlineOrder->give_integral > 0) {
            $make = app()->make(UserRepository::class);
            $user = $make->get($offlineOrder->uid);
            $user->integral=$user->integral+$offlineOrder->give_integral;
            $user->save();
            app()->make(UserBillRepository::class)->incBill($offlineOrder->uid, 'integral', 'lock', [
                'link_id' => $offlineOrder['order_id'],
                'status' => 1,
                'title' => $type.'消费，用户增加积分',
                'number' => $offlineOrder->give_integral,
                'mark' => '用户成功消费,增加积分' . $offlineOrder->give_integral,
                //'mark' => '线下消费' . floatval($offlineOrder['pay_price']) . '元,赠送积分' . floatval($offlineOrder->give_integral),
                'balance' => $offlineOrder->user->integral
            ]);
        }
    }

    public function payAfter($ret)
    {
        $userBillRepository = app()->make(UserBillRepository::class);

        $title = '线下门店支付';

        $mark = '线下门店支付';

        $userBillRepository->incBill($ret['uid'],UserBillRepository::CATEGORY_NOW_MONEY,'offline_order',[
            'link_id' => $ret->order_id,
            'title' => $title,
            'number'=> $ret->pay_price,
            'status'=> 1,
            'mark' => $mark,
        ]);
        return true;
    }

    /**
     * @param $id
     * @param null $uid
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author xaboy
     * @day 2020/6/10
     */
    public function cancel($id, $uid = null)
    {
        $offlineOrder = $this->search(['paid' => 0, 'uid' => $uid ?? ''])->where('order_id', $id)->find();
        if (!$offlineOrder)
            throw new ValidateException('订单不存在');
        if ($offlineOrder['paid'] != 0)
            throw new ValidateException('订单状态错误,无法删除');
        //TODO 关闭订单
        Db::transaction(function () use ($offlineOrder, $id, $uid) {
            $offlineOrder->is_del = 1;
            $orderStatus = [];

            //退回积分
            /*if ($offlineOrder->integral > 0) {
                $make = app()->make(UserRepository::class);
                $make->update($offlineOrder->uid, ['integral' => Db::raw('integral+' . $offlineOrder->integral)]);
                app()->make(UserBillRepository::class)->incBill($offlineOrder->uid, 'integral', 'cancel', [
                    'link_id' => $offlineOrder['order_id'],
                    'status' => 1,
                    'title' => '退回积分',
                    'number' => $offlineOrder['integral'],
                    'mark' => '订单自动关闭,退回' . intval($offlineOrder->integral) . '积分',
                    'balance' => $make->get($offlineOrder->uid)->integral
                ]);
                // 退回商家积分
                app()->make(MerchantRepository::class)->subMerIntegral($offlineOrder->mer_id, 'mer_integral', $offlineOrder->order_id, $offlineOrder->integral);
            }*/

            // 退回抵用券
            if($offlineOrder->deduction > 0){
                $make = app()->make(UserRepository::class);
                $make->update($offlineOrder->uid, ['coupon_amount' => Db::raw('coupon_amount+' . $offlineOrder->deduction)]);
            }

            $offlineOrder->save();
            //$storeOrderStatusRepository->batchCreateLog($orderStatus);
        });
    }

    public function v2CartIdByOrderInfo($user, $money, bool $userDeduction = false)
    {
        $uid = $user->uid;
        $user_coupon_amount = $user->coupon_amount;
        $deductionlFlag = $userDeduction  > 0;
        $deduction = [
            'use' => 0, // 使用的抵用券数量
            'price' => 0 // 抵扣的金额
        ];
        $finalAmount=0;
        //计算抵用券抵扣
        if ($deductionlFlag && $user_coupon_amount > 0 && $money > 0) {

            $eductionRate = 100;

            // 如果抵扣比例大于0
            if ($eductionRate > 0) {
                // 计算抵用券额（抵用券额 = 订单金额 * 抵扣比例）
                $deductionAmount = min($money, $user_coupon_amount); // 最大只能抵扣订单金额或抵用券数量
                $deduction['use'] = intval($deductionAmount); // 使用的抵用券数量
                $deduction['price'] = $deductionAmount; // 抵扣的金额
                // 更新剩余抵用券
                $user_coupon_amount -= $deduction['use'];
                // 计算抵扣后的金额
                $finalAmount = $money - $deduction['price'];
            }

        }

        $data = [
            'use_deduction' => $deduction['use'],
            'deduction_price' => $user_coupon_amount ,
            'finalAmount' => $finalAmount

        ];
        return $data;
    }

    /**
     * @param $id
     * @param null $uid
     * @return array|Model|null
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author xaboy
     * @day 2020/6/10
     */
    public function getDetail($id, $uid = null)
    {
        $where = [];
        $with = [
            'merchant' => function ($query) {
                return $query->field('mer_id,mer_name,service_phone')->append(['services_type']);
            }
        ];
        if ($uid) {
            $where['uid'] = $uid;
        } else if (!$uid) {
            $with['user'] = function ($query) {
                return $query->field('uid,nickname');
            };
        }
        $order = $this->dao->search($where)->where('order_id', $id)->where('is_del', 0)->with($with)->find();
        if (!$order) {
            return null;
        }
        return $order;
    }

    /**
     *  获取订单列表头部统计数据
     * @Author:Qinii
     * @Date: 2020/9/12
     * @param int|null $merId
     * @param int|null $orderType
     * @return array
     */
    public function OrderTitleNumber(?int $merId, ?int $orderType)
    {
        $where = [];
        $sysDel = $merId ? 0 : null;                    //商户删除
        if ($merId) $where['mer_id'] = $merId;          //商户订单

        //1: 未支付 2: 已支付 7: 已删除
        $all = $this->dao->search($where, $sysDel)->where($this->getOrderType(0))->count();
        $statusAll = $all;
        $unpaid = $this->dao->search($where, $sysDel)->where($this->getOrderType(1))->count();
        $unshipped = $this->dao->search($where, $sysDel)->where($this->getOrderType(2))->count();
        $del = $this->dao->search($where, $sysDel)->where($this->getOrderType(7))->count();

        return compact('all', 'statusAll', 'unpaid', 'unshipped', 'del');
    }

    /**
     * @param $status
     * @return mixed
     * @author Qinii
     */
    public function getOrderType($status)
    {
        $param['StoreOrderOffline.is_del'] = 0;
        switch ($status) {
            case 1:
                $param['StoreOrderOffline.paid'] = 0;
                break;    // 未支付
            case 2:
                $param['StoreOrderOffline.paid'] = 1;
                break;  // 已支付
            case 7:
                $param['StoreOrderOffline.is_del'] = 1;
                break;  // 已删除
            default:
                unset($param['StoreOrderOffline.is_del']);
                break;  //全部
        }
        return $param;
    }

    /**
     * @param array $where
     * @param $page
     * @param $limit
     * @return array
     * @author Qinii
     */
    public function merchantGetList(array $where, $page, $limit,$date)
    {
        $status = $where['status'];
        unset($where['status']);
        $query = $this->dao->search($where)->where($this->getOrderType($status))->when($date, function ($query, $date) {
            getModelTime($query, $date, 'pay_time');
        })->with([
            'user' => function($query){
                $query->field('uid,nickname,phone');
            }
        ]);
        $count = $query->count();
        $list = $query->page($page, $limit)->order('order_id desc')->select();
        // 手机号脱敏
        foreach ($list as $k => $v) {
            $list[$k]['user']['phone'] = substr_replace($v['user']['phone'], '****', 3, 4);
        }

        return compact('count', 'list');
    }

    public function getAutoOfflineShipping()
    {
        $now = date('Y-m-d H:i:s');
        $oneMinuteAgo = date('Y-m-d H:i:s', strtotime('-1 minute'));

        return StoreOrderOffline::getDB()
            ->where('is_share', 0) // 未发货,未分账状态
            ->where('pay_type','<>','alipay') // 只处理微信订单
            ->where('paid',1) // 已支付
            ->where('transaction_id', '<>', '') // transaction_id
            //->where('pay_time', '<=', $oneMinuteAgo) // 支付时间超过1分钟
            ->where('pay_time', '>', '1970-01-01') // 过滤无效时间
            ->limit(2)
            //->order('order_id', 'desc')
            ->select();
    }




    /**
     * 线下订单列表
     * @param array $where
     * @param $page
     * @param $limit
     * @return array
     */
    public function adminGetList(array $where, $page, $limit)
    {
        $status = $where['status'];
        unset($where['status']);
        $query = $this->dao->search($where, null)
            ->where($this->getOrderType($status))
            ->with([
                'merchant' => function ($query) {
                    $query->field('mer_id,mer_name,is_trader,mer_state,mer_avatar');
                },
                'user' => function ($query) {
                    $query->field('uid,nickname,phone,avatar');
                },
            ])
            ->order('order_id desc');
        $count = $query->count();
        $list = $query->page($page, $limit)->select();
        foreach ($list as $k => $v) {
            if($list[$k]['pay_type'] == 'routine'){
                $list[$k]['pay_type']=1;
            }else if($list[$k]['pay_type'] == 'manual'){
                $list[$k]['pay_type']=0;
            }else{
                $list[$k]['pay_type']=4;
            }
        }
        return compact('count', 'list');
    }

    /**
     * 线下订单金额统计
     * @param array $where
     * @param string $status
     **/
    public function getStat(array $where, $status)
    {
        unset($where['status']);
        //实际支付订单数量
        $all = $this->dao->search($where)->where($this->getOrderType($status))->where('paid', 1)->count();
        //实际支付订单金额
        $countQuery = $this->dao->search($where)->where($this->getOrderType($status))->where('paid', 1);
        $countPay1 = $countQuery->sum('StoreOrderOffline.pay_price');
        $countPay = $countPay1;
        $presellOrderRepository = app()->make(PresellOrderRepository::class);
        //微信金额
        $wechatQuery = $this->dao->search($where)->where($this->getOrderType($status))->where('paid', 1)->where('pay_type', 'weixin');
//        $wechatOrderId = $wechatQuery->column('order_id');
        $wechatPay1 = $wechatQuery->sum('StoreOrderOffline.pay_price');
//        $wechatPay2 = $presellOrderRepository->search(['pay_type' => [1, 2, 3, 6], 'paid' => 1, 'order_ids' => $wechatOrderId])->sum('pay_price');
//        $wechatPay = bcadd($wechatPay1, $wechatPay2, 2);
        $wechatPay = $wechatPay1;
        //支付宝金额
        $aliQuery = $this->dao->search($where)->where($this->getOrderType($status))->where('paid', 1)->where('pay_type', 'alipay');
//        $aliOrderId = $aliQuery->column('order_id');
        $aliPay1 = $aliQuery->sum('StoreOrderOffline.pay_price');
//        $aliPay2 = $presellOrderRepository->search(['pay_type' => [4, 5], 'paid' => 1, 'order_ids' => $aliOrderId])->sum('pay_price');
//        $aliPay = bcadd($aliPay1, $aliPay2, 2);
        $aliPay = $aliPay1;
        // 抵扣金
        $deductiontQuery = $this->dao->search($where)->where($this->getOrderType($status))->where('paid', 1);
        $deduction = $deductiontQuery->sum('StoreOrderOffline.deduction');

        $stat = [
            [
                'className' => 'el-icon-s-goods',
                'count' => $all,
                'field' => '件',
                'name' => '已支付订单数量'
            ],
            [
                'className' => 'el-icon-s-order',
                'count' => (float)$countPay,
                'field' => '元',
                'name' => '实际支付金额'
            ],
            [
                'className' => 'el-icon-s-cooperation',
                'count' => (float)$deduction,
                'field' => '元',
                'name' => '抵扣金'
            ],
            [
                'className' => 'el-icon-s-cooperation',
                'count' => (float)$wechatPay,
                'field' => '元',
                'name' => '微信支付金额'
            ],
            [
                'className' => 'el-icon-s-cooperation',
                'count' => (float)$aliPay,
                'field' => '元',
                'name' => '支付宝支付金额'
            ],
        ];
        return $stat;
    }

    /**
     * 线下订单详情
     * @param integer $id
     * @param integer $merId
     **/
    public function getOne($id, ?int $merId)
    {
        $where = [$this->getPk() => $id];
        if ($merId) {
            $where['mer_id'] = $merId;
        }
        $res = $this->dao->getWhere($where, '*', [
//                'merchant' => function (Relation $query) {
//                    $query->field('mer_id,mer_name,mer_state,mer_avatar,delivery_way,commission_rate,category_id,type_id')
//                        ->with(['merchantCategory', 'type_name']);
//                },
                'merchant' => function (Relation $query) {
                    return $query->field('mer_id,mer_name,is_trader,mer_state,mer_avatar');
                },
                'user' => function ($query) {
                    $query->field('uid,real_name,nickname,is_svip,svip_endtime,phone');
                },
                'spread' => function ($query) {
                    $query->field('uid,nickname,avatar');
                },
                'TopSpread' => function ($query) {
                    $query->field('uid,nickname,avatar');
                },
            ]
        );
        if (!$res) throw new ValidateException('数据不存在');
        return $res;
    }

    /**
     * 线下平台账单列表
     **/
    public function getFinancialRecordList($where, $page, $limit)
    {
        $field = ',sum(total_price) as total_amount,sum(extension_one) as extension_one_amount,sum(extension_two) as extension_two_amount,sum(deduction) as deduction_amount,sum(handling_fee) as handling_amount';
        //日
        if (!$where['type'] || $where['type'] == 1) {
            $field = Db::raw('from_unixtime(unix_timestamp(create_time),\'%Y-%m-%d\') as time' . $field);
        } else {
            //月
            if (!empty($where['date'])) {
                list($startTime, $endTime) = explode('-', $where['date']);
                $firstday = date('Y/m/01', strtotime($startTime));
                $lastday_ = date('Y/m/01', strtotime($endTime));
                $lastday = date('Y/m/d', strtotime("$lastday_ +1 month -1 day"));
                $where['date'] = $firstday . '-' . $lastday;
            }
            $field = Db::raw('from_unixtime(unix_timestamp(create_time),\'%Y-%m\') as time' . $field);
        }
        $query = $this->dao->search_new($where)->where('paid', 1)->field($field)->group("time")->order('create_time desc');
        $count = $query->count();
        $list = $query->page($page, $limit)->select()->each(function ($item) use ($where) {
            $extension_amount = bcadd($item['extension_one_amount'], $item['extension_two_amount'], 2);
            $sub_amount = bcadd($extension_amount, $item['deduction_amount'], 2);
            $item['income'] = $item['total_amount'];
            $item['expend'] = $sub_amount;
            $item['charge'] = $item['handling_amount'];
        });
        return compact('count', 'list');
    }

    /**
     * 线下平台账单统计
     **/
    public function getFinancialRecordTitle($where){
        //订单收入总金额
        $count = $this->dao->search_new($where)->where('paid', 1)->sum('total_price');
        //佣金支出金额
        $brokerage_ = $this->dao->search_new($where)->where('paid', 1)->sum('extension_one');
        $_brokerage = $this->dao->search_new($where)->where('paid', 1)->sum('extension_two');
        $brokerage = bcsub($brokerage_,$_brokerage,2);
        // 抵扣金
        $coupon = $this->dao->search_new($where)->where('paid', 1)->sum('deduction');
        //平台手续费
        $charge = $this->dao->search_new($where)->where('paid', 1)->sum('handling_fee');
        //产生交易的商户数
        $mer_number = $this->dao->search($where)->group('mer_id')->count('order_id');
        $stat = [
            [
                'className' => 'el-icon-s-goods',
                'count' => $count,
                'field' => '元',
                'name' => '订单收入总金额'
            ],
            [
                'className' => 'el-icon-s-cooperation',
                'count' => $brokerage,
                'field' => '元',
                'name' => '佣金支出金额'
            ],
            [
                'className' => 'el-icon-s-cooperation',
                'count' => $charge,
                'field' => '元',
                'name' => '平台手续费'
            ],
            [
                'className' => 'el-icon-s-goods',
                'count' => $mer_number,
                'field' => '个',
                'name' => '产生交易的商户数'
            ],
            [
                'className' => 'el-icon-s-goods',
                'count' => $coupon,
                'field' => '元',
                'name' => '抵扣金金额'
            ]
        ];
        return compact('stat');
    }

}
