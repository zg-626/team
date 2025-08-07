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


namespace app\controller\api\store\order;

use app\common\repositories\store\order\StoreOrderOfflineRepository;
use app\common\repositories\user\UserBillRepository;
use crmeb\basic\BaseController;
use think\App;
use think\response\Json;

class StoreOrderOffline extends BaseController
{
    protected $repository;

    public function __construct(App $app, StoreOrderOfflineRepository $repository)
    {
        parent::__construct($app);
        $this->repository = $repository;
        //if (!systemConfig('svip_switch_status'))  throw new ValidateException('付费会员未开启');
    }

    /**
     * TODO 生成线下支付订单
     * @param $money
     * @param int $mer_id
     * @param StoreOrderOfflineRepository $storeOrderOfflineRepository
     * @author esc
     * @day 2025/03/07
     */
    public function enter($money, StoreOrderOfflineRepository $storeOrderOfflineRepository, int $mer_id=556)
    {
        $params = $this->request->params(['uid','user_deduction','payment_voucher',['commission_rate','pay_type',0]]);
        if ($money<0)
            return app('json')->fail('金额不能小于0');
        if(!$mer_id)
            return app('json')->fail('缺少商户id');
        if(!$params['payment_voucher'])
            return app('json')->fail('支付凭证不能为空');
        $params['is_app'] = $this->request->isApp();
        $params['pay_type'] = 'weixin';
        $params['to_uid'] = 0;
        if($params['uid']==0){
            $params['uid'] = $this->request->userInfo()->uid;
        }
        return $storeOrderOfflineRepository->enter($money,$mer_id,$params);
    }

    /**
     * TODO 生成线下支付订单
     * @param $money
     * @param $mer_id
     * @param StoreOrderOfflineRepository $storeOrderOfflineRepository
     * @return Json|void
     * @author esc
     * @day 2025/03/07
     */
    public function createOrder($money,$mer_id, StoreOrderOfflineRepository $storeOrderOfflineRepository)
    {
        $params = $this->request->params(['pay_type','return_url','to_uid','user_deduction',['commission_rate',0]]);
        if (!in_array($params['pay_type'], ['weixin', 'routine', 'h5', 'alipay', 'alipayQr', 'weixinQr', 'native'], true))
            return app('json')->fail('请选择正确的支付方式');
        if ($money<0)
            return app('json')->fail('金额不能小于0');
        if(!$mer_id)
            return app('json')->fail('缺少商户id');
        $params['is_app'] = $this->request->isApp();
        return $storeOrderOfflineRepository->add($money,$mer_id,$this->request->userInfo(),$params);
    }

    public function getList()
    {
        $where = $this->request->params(['date', 'paid', 'keyword']);
        [$page, $limit] = $this->getPage();
        $user = $this->request->userInfo();
        $where['uid'] = $user->uid;
        $where['is_del'] = 0;
        $where['paid'] = 1;
        return app('json')->success($this->repository->getList($where, $page, $limit));
    }

    public function v2CheckOrder(StoreOrderOfflineRepository $storeOrderOfflineRepository)
    {
        $userDeduction = (bool)$this->request->param('user_deduction', false);
        $money = (float)$this->request->param('money', 0);
        $user = $this->request->userInfo();
        if ($money <= 0)
            return app('json')->fail('数据无效');
        $orderInfo = $storeOrderOfflineRepository->v2CartIdByOrderInfo($user,$money,$userDeduction);

        return app('json')->success($orderInfo);
    }

    /**
     * @param $id
     * @return mixed
     * @author xaboy
     * @day 2020/6/10
     */
    public function detail($id)
    {
        $order = $this->repository->getDetail((int)$id);
        if (!$order)
            return app('json')->fail('订单不存在');
        /*if ($order->order_type == 1) {
            $order->append(['take', 'refund_status']);
        }*/
        return app('json')->success($order->toArray());
    }

}
