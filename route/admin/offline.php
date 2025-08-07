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

use think\facade\Route;
use app\common\middleware\AdminAuthMiddleware;
use app\common\middleware\AdminTokenMiddleware;
use app\common\middleware\AllowOriginMiddleware;
use app\common\middleware\LogMiddleware;

Route::group(function () {
    Route::group('offline_order', function () {
        Route::get('lst', '/getAllList')->name('systemOrderLst')->option([
            '_alias' => '列表',
        ]);
        Route::get('title', '/title')->name('systemOrderStat')->option([
            '_alias' => '金额统计',
        ]);
        Route::get('chart', '/chart')->name('systemOrderTitle')->option([
            '_alias' => '头部统计',
        ]);
        Route::get('detail/:id', '/detail')->name('systemOrderDetail')->option([
            '_alias' => '详情',
        ]);
        Route::get('status/:id/form', '/switchStatusForm')->name('systemOrderSwitchStatusForm')->option([
            '_alias' => '审核表单',
            '_auth' => false,
            '_form' => 'systemOrderSwitchStatus',
        ]);
        Route::post('status/:id', '/switchStatus')->name('systemOrderSwitchStatus')->option([
            '_alias' => '审核',
        ]);
        Route::get('export', '/export')->name('systemOrderExport')->option([
            '_alias' => '导出',
        ]);
    })->prefix('admin.offline.Order')->option([
        '_path' => '/offline_order/list',
        '_auth' => true,
        '_append'=> [
            [
                '_name'  =>'systemStoreExcelLst',
                '_path'  =>'/offline_order/list',
                '_alias' => '导出列表',
                '_auth'  => true,
            ],
            [
                '_name'  =>'systemStoreExcelDownload',
                '_path'  =>'/offline_order/list',
                '_alias' => '导出列表',
                '_auth'  => true,
            ],
        ]
    ]);;

})->middleware(AllowOriginMiddleware::class)
    ->middleware(AdminTokenMiddleware::class, true)
    ->middleware(AdminAuthMiddleware::class)
    ->middleware(LogMiddleware::class);
