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


namespace app\controller\admin\offline;

use app\common\repositories\store\order\StoreOrderOfflineRepository;
use crmeb\basic\BaseController;
use crmeb\services\ExcelService;
use think\App;

/**
 * 线下订单
 **/
class Order extends BaseController
{
    protected $repository;

    public function __construct(App $app, StoreOrderOfflineRepository $repository)
    {
        parent::__construct($app);
        $this->repository = $repository;
    }

    /**
     * 列表
     * @return mixed
     */
    public function getAllList()
    {
        [$page, $limit] = $this->getPage();
        $where = $this->request->params(['date', 'mer_id', 'pay_type','status', 'keywords', 'order_sn', 'is_trader','group_order_sn']);
        $where['order_sn']=$where['group_order_sn'];
        unset($where['group_order_sn']);
        $data = $this->repository->adminGetList($where, $page, $limit);
        return app('json')->success($data);
    }

    /**
     * 金额统计
     * @return mixed
     */
    public function title()
    {
        $where = $this->request->params(['date', 'mer_id', 'pay_type','status', 'keywords', 'order_sn', 'is_trader']);
        return app('json')->success($this->repository->getStat($where, $where['status']));
    }

    /**
     * 头部统计
     * @return mixed
     */
    public function chart()
    {
        return app('json')->success($this->repository->OrderTitleNumber(null, null));
    }

    /**
     * 详情
     * @return mixed
     **/
    public function detail($id)
    {
        $data = $this->repository->getOne($id, null);
        if (!$data){
            return app('json')->fail('数据不存在');
        }
        return app('json')->success($data);
    }

    /**
     * 导出
     * @return mixed
     **/
    public function export()
    {
        [$page, $limit] = $this->getPage();
        $where = $this->request->params(['date', 'mer_id', 'pay_type','status', 'keywords', 'order_sn', 'is_trader']);
        /** @var ExcelService $service */
        $service = app()->make(ExcelService::class);
        $data = $service->offlineOrder($where, $page, $limit);
        return app('json')->success($data);
    }

    /**
     * 审核表单
     * @param int $id
     * @return mixed
     */
    public function switchStatusForm($id)
    {
        $order = $this->repository->getOne($id, null);
        if (!$order) {
            return app('json')->fail('订单不存在');
        }
        
        if ($order->paid == 1) {
            return app('json')->fail('订单已支付，无需审核');
        }
        
        $form = [
            [
                'type' => 'radio',
                'field' => 'status',
                'title' => '审核状态',
                'value' => 1,
                'options' => [
                    ['label' => '通过审核（触发支付成功）', 'value' => 1],
                    ['label' => '拒绝审核', 'value' => 0]
                ],
                'props' => [
                    'type' => 'button'
                ]
            ],
            /*[
                'type' => 'input',
                'field' => 'remark',
                'title' => '审核备注',
                'value' => '',
                'props' => [
                    'type' => 'textarea',
                    'placeholder' => '请输入审核备注（可选）'
                ]
            ]*/
        ];
        
        return app('json')->success(compact('form'));
    }

    /**
     * 审核处理
     * @param int $id
     * @return mixed
     */
    public function switchStatus($id)
    {
        $data = $this->request->params(['status', 'remark']);
        
        $order = $this->repository->getOne($id, null);
        if (!$order) {
            return app('json')->fail('订单不存在');
        }
        
        if ($order->paid == 1) {
            return app('json')->fail('订单已支付，无需审核');
        }
        
        try {
            if ($data['status'] == 1) {
                // 审核通过，触发支付成功回调
                /*$paySuccessData = [
                    'order_sn' => $order->order_sn,
                    'data' => [
                        'acc_trade_no' => 'ADMIN_APPROVE_' . time(),
                        'log_no' => 'LOG_' . time(),
                        'trade_no' => 'TRADE_' . time(),
                        'trade_time' => date('Y-m-d H:i:s'),
                        'remark' => 'offline_order'
                    ]
                ];*/
                
                // 调用支付成功方法
                //$result = $this->repository->paySuccess($paySuccessData);
                $result = $this->repository->computeds($order);

                if ($result) {
                    // 记录审核日志
                    $this->recordAuditLog($order, 1, $data['remark'] ?? '平台审核通过');
                    return app('json')->success('审核通过，订单支付成功');
                } else {
                    return app('json')->fail('审核处理失败');
                }
            } else {
                // 审核拒绝，可以在这里添加拒绝逻辑
                $this->recordAuditLog($order, 0, $data['remark'] ?? '平台审核拒绝');
                return app('json')->success('审核已拒绝');
            }
        } catch (\Exception $e) {
            return app('json')->fail('审核处理异常：' . $e->getMessage());
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
        \think\facade\Log::info('线下订单审核', [
            'order_id' => $order->order_id,
            'order_sn' => $order->order_sn,
            'status' => $status,
            'remark' => $remark,
            //'admin_id' => request()->adminId() ?? 0,
            'audit_time' => date('Y-m-d H:i:s')
        ]);
    }

}
