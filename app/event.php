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

// 事件定义文件
use crmeb\listens\DividendTaskListen;

return [
    'bind' => [],

    'listen' => [
        'AppInit' => [],
        'HttpRun' => [],
        'HttpEnd' => [],
        'LogLevel' => [],
        'LogWrite' => [],
        'swoole.task' => [\crmeb\listens\SwooleTaskListen::class],
        'swoole.init' => [
            \crmeb\listens\InitSwooleLockListen::class,
            \crmeb\listens\CreateTimerListen::class,
            \crmeb\listens\QueueListen::class,
        ],
        'swoole.workerStart' => [\app\webscoket\SwooleWorkerStart::class],
        'swoole.workerExit' => [\crmeb\listens\SwooleWorkerExitListen::class],
        'swoole.workerError' => [\crmeb\listens\SwooleWorkerExitListen::class],
        'swoole.workerStop' => [\crmeb\listens\SwooleWorkerExitListen::class],
        'create_timer' => env('INSTALLED', false) ? [
//            \crmeb\listens\AutoOrderProfitsharingListen::class,
//            \crmeb\listens\AutoOrderOfflineProfitsharingListen::class,// 微信服务商自动分账
            \crmeb\listens\AutoOrderLakalaListen::class,// 拉卡拉自动分账
            //\crmeb\listens\AutoOrderOfflineShippingListen::class,// 自动虚拟发货
            \crmeb\listens\AuthTakeOrderListen::class,
            \crmeb\listens\AutoCancelGroupOrderListen::class,
            \crmeb\listens\AutoCancelOfflineOrderListen::class,
            \crmeb\listens\AuthCancelPresellOrderListen::class,
            \crmeb\listens\AutoUnLockBrokerageListen::class,
            \crmeb\listens\AutoSendPayOrderSmsListen::class,
            \crmeb\listens\SyncSmsResultCodeListen::class,
//            \crmeb\listens\SyncBroadcastStatusListen::class, //直播间同步监听，未开启可删除次行
            //\crmeb\listens\RefundOrderAgreeListen::class,// 自动退款
            \crmeb\listens\SeckillTImeCheckListen::class,
            \crmeb\listens\AutoOrderReplyListen::class,
            \crmeb\listens\ProductPresellStatusListen::class,
            \crmeb\listens\ProductGroupStatusCheckListen::class,
            \crmeb\listens\SyncSpreadStatusListen::class,
            \crmeb\listens\GuaranteeCountListen::class,
//            \crmeb\listens\AutoUnLockIntegralListen::class,
            \crmeb\listens\AutoUnLockDeductionListen::class,
            \crmeb\listens\AutoClearIntegralListen::class,
            \crmeb\listens\MerchantApplyMentsCheckListen::class,
            \crmeb\listens\AutoUnlockMerchantMoneyListen::class,
            \crmeb\listens\SumCountListen::class,
            \crmeb\listens\SyncHotRankingListen::class,
            \crmeb\listens\AuthCancelActivityListen::class,
            \crmeb\listens\CloseUserSvipListen::class,
            \crmeb\listens\SendSvipCouponListen::class,
            \crmeb\listens\SyncMerchantMarginStatusListen::class,
            \crmeb\listens\SyncQueueStatusListen::class,
            //DividendTaskListen::class,// 自动分红
        ] : [],
        'pay_success_user_recharge' => [\crmeb\listens\pay\UserRechargeSuccessListen::class],
        'pay_success_offline_order' => [\crmeb\listens\pay\OfflineOrderSuccessListen::class],
        //'pay_success_shipping' => [\crmeb\listens\pay\ShippingSuccessListen::class],
        'pay_success_user_order' => [\crmeb\listens\pay\UserOrderSuccessListen::class],
        'pay_success_order' => [\crmeb\listens\pay\OrderPaySuccessListen::class],
        'pay_success_presell' => [\crmeb\listens\pay\PresellPaySuccessListen::class],
        'pay_success_meal' => [\crmeb\listens\pay\MealSuccessListen::class],
        //数据大屏
        'data.screen.send' => [\crmeb\listens\DataScreenListen::class],
        //操作日志
        'create_operate_log' => [\crmeb\listens\CreateOperateLogListen::class],  // 操作日志事件
        'mini_order_shipping' => [\crmeb\listens\MiniOrderShippingListen::class],  // 小程序发货管理事件
    ],

    'subscribe' => [],
];
