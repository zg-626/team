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

    // 用户表单
    Route::group('user/info', function () {
        Route::get('lst', '/lst')->name('systemUserInfolst')->option([
            '_alias' => '列表',
        ]);
        Route::get('create_from', '/createFrom')->name('systemUserInfoCreateFrom')->option([
            '_alias' => '添加表单',
            '_auth' => false,
            '_form' => 'systemUserInfoCreate'
        ]);
        Route::post('create', '/create')->name('systemUserInfoCreate')->option([
            '_alias' => '添加',
        ]);
        Route::post('save_all', '/saveAll')->name('systemUserInfoSaveAll')->option([
            '_alias' => '保存信息',
        ]);
        Route::get('type', '/getType')->name('systemUserInfoType')->option([
            '_alias' => '类型',
            '_auth' => false,
        ]);
        Route::delete('delete/:id', '/delete')->name('systemUserInfoDelete')->option([
            '_alias' => '删除',
        ]);
        Route::get('select_list', '/getSelectList')->name('systemUserGetSelectList')->option([
            '_alias' => '下拉列表',
            '_auth' => false,
        ]);

    })->prefix('admin.user.UserInfo')->option([
        '_path' => '/user/setup_user',
        '_auth' => true,
    ]);

    // 用户表单
    Route::group('user/fields', function () {
        Route::get('save_form/:uid', '/saveForm')->name('systemUserFieldSaveForm')->option([
            '_alias' => '扩展信息表单',
        ]);
        Route::post('save/:uid', '/save')->name('systemUserInfoFieldSave')->option([
            '_alias' => '添加或编辑',
        ]);

    })->prefix('admin.user.UserFields')->option([
        '_path' => '/user/info',
        '_auth' => true,
    ]);

    //用户标签
    Route::group('user/label', function () {
        Route::get('lst', '/lst')->name('systemUserLabelLst')->option([
            '_alias' => '用户标签列表',
        ]);
        Route::post('user/label', '/create')->name('systemUserLabelCreate')->option([
            '_alias' => '用户标签添加',
        ]);
        Route::get('form', '/createForm')->name('systemUserLabelCreateForm')->option([
            '_alias' => '用户标签添加表单',
            '_auth' => false,
            '_form' => 'systemUserLabelCreate',
        ]);
        Route::delete(':id', '/delete')->name('systemUserLabelDelete')->option([
            '_alias' => '用户标签删除',
        ]);
        Route::post(':id', '/update')->name('systemUserLabelUpdate')->option([
            '_alias' => '用户标签编辑',
        ]);
        Route::get('form/:id', '/updateForm')->name('systemUserLabelUpdateForm')->option([
            '_alias' => '用户标签编辑表单',
            '_auth' => false,
            '_form' => 'systemUserLabelUpdate',
        ]);
    })->prefix('admin.user.UserLabel')->option([
        '_path' => '/user/label',
        '_auth' => true,
    ]);

    //用户
    Route::group('user', function () {
        //用户列表
        Route::get('lst', '/lst')->name('systemUserLst')->option([
            '_alias' => '用户列表',
        ]);
        Route::get('update/form/:id', '/updateForm')->name('systemUserUpdateForm')->option([
            '_alias' => '用户编辑表单',
            '_auth' => false,
            '_form' => 'systemUserUpdate',
        ]);
        Route::post('update/:id', '/update')->name('systemUserUpdate')->option([
            '_alias' => '用户编辑',
        ]);
        //修改用户余额
        Route::get('change_now_money/form/:id', '/changeNowMoneyForm')->name('systemUserChangeNowMoneyForm')->option([
            '_alias' => '用户修改余额表单',
            '_auth' => false,
            '_form' => 'systemUserChangeNowMoney',
        ]);
        Route::post('change_now_money/:id', '/changeNowMoney')->name('systemUserChangeNowMoney')->option([
            '_alias' => '用户修改余额',
        ]);
        //修改用户积分
        Route::get('change_integral/form/:id', '/changeIntegralForm')->name('systemUserChangeIntegralForm')->option([
            '_alias' => '用户修改积分表单',
            '_auth' => false,
            '_form' => 'systemUserChangeIntegral',
        ]);
        Route::post('change_integral/:id', '/changeIntegral')->name('systemUserChangeIntegral')->option([
            '_alias' => '用户修改积分',
        ]);
        //微信图文群发
        Route::post('news/push', '/sendNews')->name('systemWechatUserSendNews')->option([
            '_alias' => '用户发送图文',
        ]);
        Route::get('detail/:id', '/detail')->name('systemUserDetail')->option([
            '_alias' => '用户详情',
        ]);
        Route::get('order/:id', '/order')->name('systemUserOrder')->option([
            '_alias' => '用户消费记录',
        ]);
        Route::get('coupon/:id', '/coupon')->name('systemUserCoupon')->option([
            '_alias' => '用户持有优惠券',
        ]);
        Route::get('bill/:id', '/bill')->name('systemUserBill')->option([
            '_alias' => '用户余额变动列表',
        ]);

        Route::get('spread_log/:id', '/spreadLog')->name('systemUserSpreadLog')->option([
            '_alias' => '推荐人修改记录',
        ]);
        Route::get('change_spread_form/:id', '/spreadForm')->name('systemUserSpreadChangeForm')->option([
            '_alias' => '修改推荐人表单',
            '_auth' => false,
            '_form' => 'systemUserSpreadChange',
        ]);
        Route::get('change_superior_form/:id', '/superiorForm')->name('systemUserSuperiorChangeForm')->option([
            '_alias' => '修改上级表单',
            '_auth' => false,
            '_form' => 'systemUserSuperiorChange',
        ]);
        Route::post('change_spread/:id', '/spread')->name('systemUserSpreadChange')->option([
            '_alias' => '修改推荐人',
        ]);

        Route::post('change_superior/:id', '/superior')->name('systemUserSuperiorChange')->option([
            '_alias' => '修改上级',
        ]);

        Route::post('binding_proxy/:id', '/proxy')->name('systemUserProxy')->option([
            '_alias' => '讲师绑定代理',
        ]);

        Route::get('binding_proxy_form/:id', '/proxyForm')->name('systemUserProxyForm')->option([
            '_alias' => '讲师绑定表单',
            '_auth' => false,
            '_form' => 'systemUserProxy',
        ]);

        Route::get('/member/:id/form', '/memberForm')->name('systemUserMemberForm')->option([
            '_alias' => '用户修改会员等级表单',
            '_auth' => false,
            '_form' => 'systemUserMemberSave',
        ]);
        Route::post('/member/:id/save', '/memberSave')->name('systemUserMemberSave')->option([
            '_alias' => '用户修改会员等级',
        ]);

        Route::get('/create', '/createForm')->name('systemUserCreateForm')->option([
            '_alias' => '用户添加表单',
            '_auth' => false,
            '_form' => 'systemUserCreate',
        ]);
        Route::get('/get_fields', '/getFields')->name('systemUserGetFields')->option([
            '_alias' => '用户扩展信息表单',
            '_auth' => false,
            '_form' => 'systemUserCreate',
        ]);
        Route::post('/create', '/create')->name('systemUserCreate')->option([
            '_alias' => '用户添加',
        ]);

        Route::get('change_password/form/:id', '/changePasswordForm')->name('systemUserChangePasswordForm')->option([
            '_alias' => '用户修改密码表单',
            '_auth' => false,
            '_form' => 'systemUserChangePassword',
        ]);
        Route::post('change_password/:id', '/changePassword')->name('systemUserChangePassword')->option([
            '_alias' => '用户修改密码',
        ]);

        //修改用户分组
        Route::get('change_group/form/:id', '/changeGroupForm')->name('systemUserChangeGroupForm')->option([
            '_alias' => '用户分组编辑表单',
            '_auth' => false,
            '_form' => 'systemUserChangeGroup',
        ]);
        Route::get('batch_change_group/form', '/batchChangeGroupForm')->name('systemUserBatchChangeGroupForm')->option([
            '_alias' => '用户分组批量编辑表单',
            '_auth' => false,
            '_form' => 'systemUserBatchChangeGroup',
        ]);
        Route::post('change_group/:id', '/changeGroup')->name('systemUserChangeGroup')->option([
            '_alias' => '用户分组编辑',
        ]);
        Route::post('batch_change_group', '/batchChangeGroup')->name('systemUserBatchChangeGroup')->option([
            '_alias' => '用户分组批量编辑',
        ]);

        //修改用户标签
        Route::get('change_label/form/:id', '/changeLabelForm')->name('systemUserChangeLabelForm')->option([
            '_alias' => '用户标签编辑表单',
            '_auth' => false,
            '_form' => 'systemUserChangeLabel',
        ]);
        Route::get('batch_change_label/form', '/batchChangeLabelForm')->name('systemUserBatchChangeLabelForm')->option([
            '_alias' => '用户标签批量编辑表单',
            '_auth' => false,
            '_form' => 'systemUserBatchChangeLabel',
        ]);
        Route::post('change_label/:id', '/changeLabel')->name('systemUserChangeLabel')->option([
            '_alias' => '用户标签编辑',
        ]);
        Route::post('batch_change_label', '/batchChangeLabel')->name('systemUserBatchChangeLabel')->option([
            '_alias' => '用户标签批量编辑',
        ]);
        Route::get('svip/:id/form', '/svipForm')->name('systemUserSvipForm')->option([
            '_auth' => false,
            '_form' => 'systemUserLabelUpdate',
        ]);
        Route::post('svip/:id', '/svipUpdate')->name('systemUserSvipUpdate')->option([
            '_alias' => '用户标签编辑',
        ]);
        Route::get('integral/:id', '/integralList')->name('systemUserIntegralList')->option([
            '_alias' => '积分记录',
        ]);
        Route::get('sign_log/:id', '/sign_log')->name('systemUserSginLog')->option([
            '_alias' => '签到记录',
        ]);
        Route::get('history/:id', '/history')->name('systemUserHistory')->option([
            '_alias' => '浏览记录',
        ]);
        Route::get('excel', '/excel')->name('systemUserExcel')->option([
            '_alias' => '用户信息导出',
        ]);
        Route::get('member_select_list', '/getMemberLevelSelectList')->name('getMemberLevelSelectList')->option([
            '_alias' => '获取用户的等级下拉列表',
            '_auth' => false,
        ]);
        Route::get('batch_spread_form', '/batchSpreadForm')->name('getMemberLevelBatchSpreadForm')->option([
            '_auth' => false,
            '_form' => 'getMemberLevelBatchSpread',
        ]);
        Route::post('batch_spread', '/batchSpread')->name('getMemberLevelBatchSpread')->option([
            '_alias' => '批量设置分销员',
        ]);
    })->prefix('admin.user.User')->option([
        '_path' => '/user/list',
        '_auth' => true,
        '_append' => [
            [
                '_name' => 'uploadImage',
                '_path' => '/user/list',
                '_alias' => '上传图片',
                '_auth' => true,
            ],
            [
                '_name' => 'systemAttachmentLst',
                '_path' => '/user/list',
                '_alias' => '图片列表',
                '_auth' => true,
            ],
        ]
    ]);

    Route::group('user', function () {
        //搜索记录
        Route::get('search_log', 'User/searchLog')->name('systemUserSearchLog')->option([
            '_alias' => '用户搜索记录',
        ]);
        Route::get('search_log/export', 'User/exportSearchLog')->name('systemUserExportSearchLog')->option([
            '_alias' => '用户搜索记录导出',
        ]);
        //成长值记录
        Route::get('member_log', 'UserBill/getMembers')->name('systemUserSearchLog')->option([
            '_alias' => '用户搜索记录',
        ]);
    })->prefix('admin.user.')->option([
        '_path' => '/user/searchRecord',
        '_auth' => true,
    ]);

    //用户分组
    Route::group('user/group', function () {
        Route::get('lst', '/lst')->name('systemUserGroupLst')->option([
            '_alias' => '用户分组列表',
        ]);
        Route::post('user/group', '/create')->name('systemUserGroupCreate')->option([
            '_alias' => '用户分组添加',
        ]);
        Route::get('form', '/createForm')->name('systemUserGroupCreateForm')->option([
            '_alias' => '用户分组添加表单',
            '_auth' => false,
            '_form' => 'systemUserGroupCreate',
        ]);
        Route::delete(':id', '/delete')->name('systemUserGroupDelete')->option([
            '_alias' => '用户分组删除',
        ]);
        Route::post(':id', '/update')->name('systemUserGroupUpdate')->option([
            '_alias' => '用户分组编辑',
        ]);
        Route::get('form/:id', '/updateForm')->name('systemUserGroupUpdateForm')->option([
            '_alias' => '用户分组编辑表单',
            '_auth' => false,
            '_form' => 'systemUserGroupUpdate',
        ]);
    })->prefix('admin.user.UserGroup')->option([
        '_path' => '/user/group',
        '_auth' => true,
    ]);

    // 用户代理申请
    Route::group('user/acting', function () {
        Route::get('lst', '/lst')->name('userActingLst')->option([
            '_alias' => '用户代理申请列表',
        ]);
        Route::delete('delete/:id', '/delete')->name('userActingDelete')->option([
            '_alias' => '用户代理删除',
        ]);
        Route::post(':id', '/update')->name('userActingUpdate')->option([
            '_alias' => '用户代理编辑',
        ]);
        Route::get('form/:id', '/updateForm')->name('userActingUpdateForm')->option([
            '_alias' => '用户代理编辑表单',
            '_auth' => false,
            '_form' => 'userActingUpdate',
        ]);
        Route::post('status/:id', '/switchStatus')->name('userActingStatus')->option([
            '_alias' => '审核',
        ]);
        Route::get('status/:id/form', '/statusForm')->name('userActingStatusForm')->option([
            '_alias' => '申请商户',
            '_auth' => false,
            '_form' => 'userActingStatus',
        ]);
        Route::get('mark/:id/form', '/form')->name('userActingMarkForm')->option([
            '_alias' => '备注',
            '_auth' => false,
            '_form' => 'userActingMark',
        ]);
        Route::post('mark/:id', '/mark')->name('userActingMark')->option([
            '_alias' => '备注',
        ]);
    })->prefix('admin.user.UserActing')->option([
        '_path' => '/user/acting',
        '_auth' => true,
    ]);

    //用户反馈
    Route::group('user/feedback', function () {
        Route::get('category/lst', '/lst')->name('systemUserFeedBackCategoryLst')->option([
            '_alias' => '列表',
        ]);
        Route::get('category/create/form', '/createForm')->name('systemUserFeedBackCategoryCreateForm')->option([
            '_alias' => '添加表单',
            '_auth' => false,
            '_form' => 'systemUserFeedBackCategoryCreate',
        ]);
        Route::post('category/create', '/create')->name('systemUserFeedBackCategoryCreate')->option([
            '_alias' => '添加',
        ]);
        Route::get('category/update/:id/form', '/updateForm')->name('systemUserFeedBackCategoryUpdateForm')->option([
            '_alias' => '编辑表单',
            '_auth' => false,
            '_form' => 'systemUserFeedBackCategoryUpdate',
        ]);
        Route::post('category/update/:id', '/update')->name('systemUserFeedBackCategoryUpdate')->option([
            '_alias' => '编辑',
        ]);
        Route::post('category/status/:id', '/switchStatus')->name('systemUserFeedBackCategorySwitchStatus')->option([
            '_alias' => '修改状态',
        ]);
        Route::delete('category/delete/:id', '/delete')->name('systemUserFeedBackCategoryDelete')->option([
            '_alias' => '删除',
        ]);
    })->prefix('admin.user.FeedBackCategory')->option([
        '_path' => '/feedback/classify',
        '_auth' => true,
    ]);

    //用户反馈
    Route::group('user/feedback', function () {
        Route::get('lst', 'FeedBack/lst')->name('systemUserFeedBackLst')->option([
            '_alias' => '列表',
        ]);
        Route::get('detail/:id', 'FeedBack/detail')->name('systemUserFeedBackDetail')->option([
            '_alias' => '详情',
        ]);
        Route::get('reply/:id/form', 'FeedBack/replyForm')->name('systemUserFeedBackReplyForm')->option([
            '_alias' => '回复表单',
            '_auth' => false,
            '_form' => 'systemUserFeedBackReply',
        ]);
        Route::post('reply/:id', 'FeedBack/reply')->name('systemUserFeedBackReply')->option([
            '_alias' => '回复',
        ]);
        Route::delete('delete/:id', 'FeedBack/delete')->name('systemUserFeedBackDelete')->option([
            '_alias' => '删除',
        ]);
    })->prefix('admin.user.')->option([
        '_path' => '/feedback/list',
        '_auth' => true,
    ]);

    // 用户权益值管理
    Route::group('equity_value', function () {
        Route::get('list', 'UserEquityValue/getList')->name('systemUserEquityValueList')->option([
            '_alias' => '权益值日志列表',
        ]);
        Route::post('change', 'UserEquityValue/changeEquityValue')->name('systemUserEquityValueChange')->option([
            '_alias' => '修改用户权益值',
        ]);
        Route::get('statistics', 'UserEquityValue/getStatistics')->name('systemUserEquityValueStatistics')->option([
            '_alias' => '权益值统计',
        ]);
        Route::post('batch_add', 'UserEquityValue/batchAddEquityValue')->name('systemUserEquityValueBatchAdd')->option([
            '_alias' => '批量增加权益值',
        ]);
        Route::get('detail/:uid', 'UserEquityValue/getUserEquityDetail')->name('systemUserEquityValueDetail')->option([
            '_alias' => '用户权益值详情',
        ]);
    })->prefix('admin.user.')->option([
        '_path' => '/equity_value/list',
        '_auth' => true,
    ]);


})->middleware(AllowOriginMiddleware::class)
    ->middleware(AdminTokenMiddleware::class, true)
    ->middleware(AdminAuthMiddleware::class)
    ->middleware(LogMiddleware::class);
