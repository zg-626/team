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


namespace app\controller\admin\order;

use app\common\repositories\store\order\StoreGroupOrderRepository;
use crmeb\basic\BaseController;
use app\common\repositories\store\ExcelRepository;
use app\common\repositories\system\merchant\MerchantRepository;
use app\common\repositories\store\order\StoreOrderRepository as repository;
use crmeb\services\ExcelService;
use FormBuilder\Factory\Elm;
use think\App;
use think\exception\ValidateException;
use think\facade\Route;

class Order extends BaseController
{
    protected $repository;
    protected $isSpread;

    public function __construct(App $app, repository $repository)
    {
        parent::__construct($app);
        $this->repository = $repository;
    }


    public function lst($id)
    {
        [$page, $limit] = $this->getPage();
        $where = $this->request->params(['date', 'order_sn', 'order_type', 'keywords', 'username', 'activity_type', 'group_order_sn', 'store_name', 'filter_delivery', 'filter_product', 'delivery_id']);
        $where['mer_id'] = $id;
        $where['is_spread'] = $this->request->param('is_spread', '');
        return app('json')->success($this->repository->adminMerGetList($where, $page, $limit));
    }

    public function markForm($id)
    {
        if (!$this->repository->getWhereCount([$this->repository->getPk() => $id]))
            return app('json')->fail('数据不存在');
        return app('json')->success(formToData($this->repository->adminMarkForm($id)));
    }

    public function mark($id)
    {
        if (!$this->repository->getWhereCount([$this->repository->getPk() => $id]))
            return app('json')->fail('数据不存在');
        $data = $this->request->params(['admin_mark']);
        $this->repository->update($id, $data);
        return app('json')->success('备注成功');
    }

    public function title()
    {
        $where = $this->request->params(['type', 'date', 'mer_id', 'keywords', 'status', 'username', 'order_sn', 'is_trader', 'activity_type', 'filter_delivery', 'filter_product']);
        $where['is_spread'] = $this->request->param('is_spread', 0);
        return app('json')->success($this->repository->getStat($where, $where['status']));
    }

    /**
     * TODO
     * @return mixed
     * @author Qinii
     * @day 2020-06-25
     */
    public function getAllList()
    {
        [$page, $limit] = $this->getPage();
        $where = $this->request->params(['type', 'date', 'mer_id', 'keywords', 'status', 'username', 'order_sn', 'is_trader', 'activity_type', 'group_order_sn', 'store_name', 'spread_name', 'top_spread_name', 'filter_delivery', 'filter_product']);
        $pay_type = $this->request->param('pay_type', '');
        if ($pay_type != '') $where['pay_type'] = $this->repository::PAY_TYPE_FILTEER[$pay_type];
        $where['is_spread'] = $this->request->param('is_spread', 0);
        $data = $this->repository->adminGetList($where, $page, $limit);
        return app('json')->success($data);
    }

    public function takeTitle()
    {
        $where = $this->request->params(['date', 'order_sn', 'keywords', 'username', 'is_trader']);
        $where['take_order'] = 1;
        $where['status'] = '';
        $where['verify_date'] = $where['date'];
        unset($where['date']);
        return app('json')->success($this->repository->getStat($where, ''));
    }

    /**
     * TODO 自提订单列表
     * @return mixed
     * @author Qinii
     * @day 2020-08-17
     */
    public function getTakeList()
    {
        [$page, $limit] = $this->getPage();
        $where = $this->request->params(['date', 'order_sn', 'keywords', 'username', 'is_trader']);
        $where['take_order'] = 1;
        $where['status'] = '';
        $where['verify_date'] = $where['date'];
        unset($where['date']);
        return app('json')->success($this->repository->adminGetList($where, $page, $limit));
    }

    /**
     * TODO
     * @return mixed
     * @author Qinii
     * @day 2020-08-17
     */
    public function chart()
    {
        return app('json')->success($this->repository->OrderTitleNumber(null, null));
    }

    /**
     * TODO 分销订单头部统计
     * @return \think\response\Json
     * @author Qinii
     * @day 2023/7/7
     */
    public function spreadChart()
    {
        return app('json')->success($this->repository->OrderTitleNumber(null, 2));
    }

    /**
     * TODO 自提订单头部统计
     * @return mixed
     * @author Qinii
     * @day 2020-08-17
     */
    public function takeChart()
    {
        return app('json')->success($this->repository->OrderTitleNumber(null, 1));
    }

    /**
     * TODO 订单类型
     * @return mixed
     * @author Qinii
     * @day 2020-08-15
     */
    public function orderType()
    {
        return app('json')->success($this->repository->orderType([]));
    }

    public function detail($id)
    {
        $data = $this->repository->getOne($id, null);
        if (!$data)
            return app('json')->fail('数据不存在');
        return app('json')->success($data);
    }

    public function status($id)
    {
        [$page, $limit] = $this->getPage();
        $where = $this->request->params(['date', 'user_type']);
        $where['id'] = $id;
        return app('json')->success($this->repository->getOrderStatus($where, $page, $limit));
    }

    /**
     * TODO 快递查询
     * @param $id
     * @return mixed
     * @author Qinii
     * @day 2020-06-25
     */
    public function express($id)
    {
        if (!$this->repository->getWhereCount(['order_id' => $id]))
            return app('json')->fail('订单信息或状态错误');
        return app('json')->success($this->repository->express($id, null));
    }

    public function reList($id)
    {
        [$page, $limit] = $this->getPage();
        $where = ['reconciliation_id' => $id, 'type' => 0];
        return app('json')->success($this->repository->reconList($where, $page, $limit));
    }

    /**
     * TODO 导出文件
     * @author Qinii
     * @day 2020-07-30
     */
    public function excel()
    {
        $where = $this->request->params(['type', 'date', 'mer_id', 'keywords', 'status', 'username', 'order_sn', 'take_order', 'is_trader', 'activity_type', 'group_order_sn', 'store_name', 'filter_delivery', 'filter_product', 'pay_type']);
        if ($where['pay_type'] != '') $where['pay_type'] = $this->repository::PAY_TYPE_FILTEER[$where['pay_type']];
        if ($where['take_order']) {
            $where['verify_date'] = $where['date'];
            unset($where['date']);
        }
        [$page, $limit] = $this->getPage();
        $data = app()->make(ExcelService::class)->order($where, $page, $limit);
        return app('json')->success($data);
    }

    /**
     * TODO
     * @param $id
     * @return \think\response\Json
     * @author Qinii
     * @day 2023/2/22
     */
    public function childrenList($id)
    {
        $data = $this->repository->childrenList($id, 0);
        return app('json')->success($data);
    }

    /**
     * 线上订单审核表单
     * @param int $id
     * @return mixed
     */
    public function switchStatusForm($id)
    {
        $order = $this->repository->getOne($id, null);
        if (!$order) {
            throw new ValidateException('数据不存在');
        }

        // 检查是否为线下支付订单
        if ($order->pay_type !== 7) {
            throw new ValidateException('非线下支付订单，无需审核');
        }

        if ($order->paid == 1) {
            throw new ValidateException('订单已支付，无需审核');
        }

        // 检查是否已经审核过
        if ($order->offline_audit_status == 1) {
            throw new ValidateException('订单已审核通过');
        }
        if ($order->offline_audit_status == -1) {
            throw new ValidateException('订单已审核拒绝');
        }

        $arr=Elm::createForm(Route::buildUrl('systemOrderSwitchStatus', compact('id'))->build(), [
            // 订单价格
            Elm::input('pay_price', '订单价格：')->value($order->pay_price)->disabled(true),
            // 支付凭证
            Elm::frameImage('payment_voucher', '支付凭证：', '/' . config('admin.admin_prefix') . '/setting/uploadPicture?field=payment_voucher&type=1')
                ->value($order->payment_voucher ?? '')
                ->modal(['modal' => false])
                ->icon('el-icon-camera')
                ->width('1000px')
                ->height('600px'),
            Elm::radio('status', '审核状态：', 1)->options(
                [['value' => -1, 'label' => '拒绝'], ['value' => 1, 'label' => '通过']])
                ->control([
                    ['value' => -1, 'rule' => [
                        Elm::textarea('fail_msg', '拒绝原因：', '信息有误,请完善')->placeholder('请输入拒绝理由')->required()
                    ]]
                ]),
        ])->setTitle('线下支付订单审核');

        return app('json')->success(formToData($arr));
    }

    /**
     * 线上订单审核处理
     * @param int $id
     * @return mixed
     */
    public function switchStatus($id)
    {
        $data = $this->request->params(['status', 'fail_msg']);

        $order = $this->repository->getOne($id, null);
        if (!$order) {
            return app('json')->fail('订单不存在');
        }

        // 检查是否为线下支付订单
        if ($order->pay_type !== 7) {
            return app('json')->fail('非线下支付订单，无需审核');
        }

        if ($order->paid == 1) {
            return app('json')->fail('订单已支付，无需审核');
        }

        // 检查是否已经审核过
        if ($order->offline_audit_status != 0) {
            return app('json')->fail('订单已审核，无法重复操作');
        }

        // 检查是否上传了支付凭证
        if (empty($order->payment_voucher)) {
            return app('json')->fail('用户未上传支付凭证，无法审核');
        }

        try {
            if ($data['status'] == 1) {
                // 审核通过，更新审核状态
                $this->repository->update($order->order_id, [
                    'offline_audit_status' => 1,
                    'status'=>3,
                    'is_del'=>0,
                    'fail_msg' => ''
                ]);

                // 触发支付成功回调
                /** @var StoreGroupOrderRepository $groupOrderRepository */
                $groupOrderRepository = app()->make(StoreGroupOrderRepository::class);
                $groupOrder = $groupOrderRepository->get($order->group_order_id);
                
                if (!$groupOrder) {
                    // 回滚审核状态
                    $this->repository->update($order->order_id, [
                        'offline_audit_status' => 0
                    ]);
                    return app('json')->fail('未找到对应的主订单，审核失败');
                }

                // 调用支付成功方法
                $this->repository->paySuccess($groupOrder);
                // 记录审核日志
                $this->recordAuditLog($order, 1, '平台审核通过');
                return app('json')->success('审核通过，订单支付成功');

            }

            // 审核拒绝，更新订单状态和审核状态
            $updateData = [
                'offline_audit_status' => -1,
                'status' => -1,
                'fail_msg' => $data['fail_msg'] ?? '审核拒绝'
            ];

            $this->repository->update($order->order_id, $updateData);

            // 记录审核日志
            $this->recordAuditLog($order, -1, $data['fail_msg'] ?? '平台审核拒绝');
            return app('json')->success('审核已拒绝');
        } catch (\Exception $e) {
            return app('json')->fail('审核处理异常：' . $e->getMessage());
        }
    }

    /**
     * 获取审核状态文本
     * @param int $status
     * @return string
     */
    private function getAuditStatusText($status)
    {
        switch ($status) {
            case 0:
                return '待审核';
            case 1:
                return '审核通过';
            case -1:
                return '审核拒绝';
            default:
                return '未知状态';
        }
    }

    /**
     * 记录审核日志
     * @param object $order
     * @param int $status
     * @param string $remark
     */
    private function recordAuditLog($order, $status, $remark)
    {
        // 这里可以记录审核日志到数据库
        // 暂时使用日志记录
        \think\facade\Log::info('线下支付订单审核', [
            'order_id' => $order->order_id,
            'order_sn' => $order->order_sn,
            'offline_audit_status' => $status,
            'remark' => $remark,
            'admin_id' => request()->adminId() ?? 0,
            'audit_time' => date('Y-m-d H:i:s')
        ]);
    }
}
