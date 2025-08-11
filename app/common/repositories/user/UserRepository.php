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
use app\common\dao\store\order\StoreOrderOfflineDao;
use app\common\dao\user\UserDao;
use app\common\model\alipay\AlipayUser;
use app\common\model\user\User;
use app\common\model\wechat\WechatUser;
use app\common\repositories\BaseRepository;
use app\common\repositories\community\CommunityRepository;
use app\common\repositories\store\order\StoreOrderOfflineRepository;
use app\common\repositories\store\order\StoreOrderRepository;
use app\common\repositories\store\service\StoreServiceRepository;
use app\common\repositories\system\attachment\AttachmentRepository;
use app\common\repositories\wechat\WechatUserRepository;
use crmeb\exceptions\AuthException;
use crmeb\jobs\SendNewPeopleCouponJob;
use crmeb\jobs\UserBrokerageLevelJob;
use crmeb\services\JwtTokenService;
use crmeb\services\QrcodeService;
use crmeb\services\RedisCacheService;
use crmeb\services\WechatService;
use FormBuilder\Exception\FormBuilderException;
use FormBuilder\Factory\Elm;
use FormBuilder\Form;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\exception\ValidateException;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;
use think\facade\Queue;
use think\facade\Route;
use think\Model;

/**
 * Class UserRepository
 * @package app\common\repositories\user
 * @author xaboy
 * @day 2020-04-28
 * @mixin UserDao
 */
class UserRepository extends BaseRepository
{
    /**
     * UserRepository constructor.
     * @param UserDao $dao
     */
    public function __construct(UserDao $dao)
    {
        $this->dao = $dao;
    }

    public function promoter($uid)
    {
        return $this->dao->update($uid, ['is_promoter' => 1, 'promoter_time' => date('Y-m-d H:i:s')]);
    }

    public function extendInfoSave(int $uid, array $data = [])
    {
        $userInfo = $this->dao->get($uid);
        if (empty($userInfo)) {
            throw new ValidateException('用户信息异常');
        }

    }

    public function changePasswordForm(int $id)
    {
        $formData = $this->dao->get($id);
        if (!$formData) throw new ValidateException('用户不存在');
        return Elm::createForm(Route::buildUrl('systemUserChangePassword', ['id' => $id])->build(), [
            Elm::input('account', '账号：', $formData->account)->placeholder('请输入账号')->disabled(true),
            Elm::password('pwd', '新密码：')->required(),
            Elm::password('repwd', '确认新密码：')->required(),
        ])->setTitle('修改密码')->formData([]);
    }

    public function createForm()
    {
        return Elm::createForm(Route::buildUrl('systemUserCreate')->build(), [
            Elm::input('account', '手机号(账号)：')->placeholder('请输入手机号(账号)')->required(),
            Elm::password('pwd', '密码：')->placeholder('请输入密码')->required(),
            Elm::password('repwd', '确认密码：')->placeholder('请输入确认密码')->required(),
            Elm::input('nickname', '用户昵称：')->placeholder('请输入用户昵称')->required(),
            Elm::frameImage('avatar', '头像：', '/' . config('admin.admin_prefix') . '/setting/uploadPicture?field=avatar&type=1')
                ->modal(['modal' => false])
                ->icon('el-icon-camera')
                ->width('1000px')
                ->height('600px'),
            Elm::input('real_name', '真实姓名：')->placeholder('请输入真实姓名'),
            Elm::input('phone', '手机号：')->placeholder('请输入手机号'),

            Elm::input('card_id', '身份证：')->placeholder('请输入身份证'),
            Elm::radio('sex', '性别：', 0)->options([
                ['value' => 0, 'label' => '保密：'],
                ['value' => 1, 'label' => '男：'],
                ['value' => 2, 'label' => '女：'],
            ]),
            Elm::radio('status', '状态：', 1)->options([
                ['value' => 0, 'label' => '禁用'],
                ['value' => 1, 'label' => '正常'],
            ])->requiredNum(),

            Elm::radio('is_promoter', '推广员：', 1)->options([
                ['value' => 0, 'label' => '关闭'],
                ['value' => 1, 'label' => '开启'],
            ])->requiredNum()
        ])->setTitle('添加用户')->formData([]);
    }

    /**
     * @param $id
     * @return Form
     * @throws DataNotFoundException
     * @throws DbException
     * @throws FormBuilderException
     * @throws ModelNotFoundException
     * @author xaboy
     * @day 2020-05-09
     */
    public function userForm($id)
    {
        $user = $this->dao->get($id);
        $user['uid'] = (string)$user['uid'];
        return Elm::createForm(Route::buildUrl('systemUserUpdate', compact('id'))->build(), [
            Elm::input('uid', '用户 ID：', '')->disabled(true)->placeholder('请输入用户 ID')->required(true),
            Elm::input('real_name', '真实姓名：')->placeholder('请输入真实姓名'),
            Elm::input('phone', '手机号：')->placeholder('请输入手机号'),
            Elm::date('birthday', '生日：'),
            Elm::input('card_id', '身份证：')->placeholder('请输入身份证'),
            Elm::input('addres', '用户地址：')->placeholder('请输入用户地址'),
            Elm::textarea('mark', '备注：')->placeholder('请输入备注'),
            Elm::select('group_id', '用户分组：')->options(function () {
                $data = app()->make(UserGroupRepository::class)->allOptions();
                $options = [['value' => 0, 'label' => '请选择']];
                foreach ($data as $value => $label) {
                    $options[] = compact('value', 'label');
                }
                return $options;
            })->placeholder('请选择用户分组'),
            Elm::selectMultiple('label_id', '用户标签：')->options(function () {
                $data = app()->make(UserLabelRepository::class)->allOptions();
                $options = [];
                foreach ($data as $value => $label) {
                    $value = (string)$value;
                    $options[] = compact('value', 'label');
                }
                return $options;
            })->placeholder('请选择用户标签'),
            Elm::radio('status', '状态：', 1)->options([
                ['value' => 0, 'label' => '关闭'],
                ['value' => 1, 'label' => '开启'],
            ])->requiredNum(),
            Elm::radio('is_promoter', '推广员：', 1)->options([
                ['value' => 0, 'label' => '关闭'],
                ['value' => 1, 'label' => '开启'],
            ])->requiredNum()
        ])->setTitle('编辑')->formData($user->toArray());
    }

    /**
     * @param array $where
     * @param $page
     * @param $limit
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author xaboy
     * @day 2020-05-07
     */
    public function getList(array $where, $page, $limit)
    {
        $query = $this->dao->search($where)->with([
            'spread' => function ($query) {
                $query->field('uid,nickname,spread_uid');
            },
            'superior' => function ($query) {
                $query->field('uid,nickname,superior_uid');
            },
            'member' => function ($query) {
                $query->field('user_brokerage_id,brokerage_level,brokerage_name,brokerage_icon');
            },
            'auth' => function ($query) {
                $query->field('uid,real_name,id_card,status,message');
            },
            'group']);
        $make = app()->make(UserLabelRepository::class);
        $count = $query->count('User.' . $this->dao->getPk());
        $list = $query->page($page, $limit)->select()->each(function ($item) use ($make) {
            return $item->label = count($item['label_id']) ? $make->labels($item['label_id']) : [];
        });

        return compact('count', 'list');
    }

    public function getPulbicLst(array $where, $page, $limit)
    {
        $query = $this->dao->search($where);
        $count = $query->count();
        $list = $query->page($page, $limit)->setOption('field', [])->field('uid,nickname,avatar')->select();
        return compact('count', 'list');
    }

    public function promoterCount($where)
    {
        $where['is_promoter'] = 1;
        $total = $this->dao->search($where)
            ->field('sum(spread_count) as spread_count,sum(spread_pay_count) as spread_pay_count,sum(spread_pay_price) as spread_pay_price,count(uid) as total_user,sum(brokerage_price) as brokerage_price')->find();
        $total = $total ? $total->toArray() : ['spread_count' => 0, 'spread_pay_count' => 0, 'spread_pay_price' => 0, 'total_user' => 0, 'brokerage_price' => 0];
        $total['total_extract'] = app()->make(UserExtractRepository::class)->getTotalExtractPrice($where);
        return [
            [
                'className' => 'el-icon-s-goods',
                'count' => $total['total_user'] ?? 0,
                'name' => '分销员人数(人)'
            ],
            [
                'className' => 'el-icon-s-order',
                'count' => $total['spread_count'] ?? 0,
                'name' => '推广人数(人)'
            ],
            [
                'className' => 'el-icon-s-cooperation',
                'count' => (int)($total['spread_pay_count'] ?? 0),
                'name' => '推广订单数'
            ],
            [
                'className' => 'el-icon-s-cooperation',
                'count' => (float)($total['spread_pay_price'] ?? 0),
                'name' => '推广订单金额'
            ],
            [
                'className' => 'el-icon-s-cooperation',
                'count' => (float)($total['total_extract'] ?? 0),
                'name' => '已提现金额(元)'
            ],
            [
                'className' => 'el-icon-s-cooperation',
                'count' => (float)($total['brokerage_price'] ?? 0),
                'name' => '未提现金额(元)'
            ],
        ];
    }

    public function promoterList(array $where, $page, $limit)
    {
        $where['is_promoter'] = 1;
        $query = $this->dao->search($where)->with(['spread' => function ($query) {
            $query->field('uid,nickname,spread_uid');
        }, 'brokerage' => function ($query) {
            $query->field('brokerage_level,brokerage_name,brokerage_icon');
        }]);
        $count = $query->count($this->dao->getPk());
        $list = $query->page($page, $limit)->select()->toArray();
        if (count($list)) {
            $promoterInfo = app()->make(UserExtractRepository::class)->getPromoterInfo(array_column($list, 'uid'));
            if (count($promoterInfo)) {
                $promoterInfo = array_combine(array_column($promoterInfo, 'uid'), $promoterInfo);
            }
            foreach ($list as $k => $item) {
                $list[$k]['total_extract_price'] = $promoterInfo[$item['uid']]['total_price'] ?? 0;
                $list[$k]['total_extract_num'] = $promoterInfo[$item['uid']]['total_num'] ?? 0;
                $list[$k]['total_brokerage_price'] = (float)bcadd($item['brokerage_price'], $list[$k]['total_extract_num'], 2);
            }
        }
        return compact('list', 'count');
    }

    public function merList(string $keyword, $page, $limit)
    {
        $query = $this->dao->searchMerUser($keyword);
        $count = $query->count($this->dao->getPk());
        $list = $query->page($page, $limit)->setOption('field', [])->field('uid,nickname,avatar,user_type,sex')->select();
        return compact('count', 'list');
    }

    /**
     * @param $id
     * @return Form
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws FormBuilderException
     * @author xaboy
     * @day 2020-05-07
     */
    public function changeGroupForm($id)
    {
        $isArray = is_array($id);
        if (!$isArray)
            $user = $this->dao->get($id);

        /** @var UserGroupRepository $make */
        $data = app()->make(UserGroupRepository::class)->allOptions();
        return Elm::createForm(Route::buildUrl($isArray ? 'systemUserBatchChangeGroup' : 'systemUserChangeGroup', $isArray ? [] : compact('id'))->build(), [
            Elm::hidden('ids', $isArray ? $id : [$id]),
            Elm::select('group_id', '用户分组：', $isArray ? '' : $user->group_id)->options(function () use ($data) {
                $options = [['label' => '请选择', 'value' => 0]];
                foreach ($data as $value => $label) {
                    $options[] = compact('value', 'label');
                }
                return $options;
            })->placeholder('请选择用户分组')
        ])->setTitle('修改用户分组');
    }

    /**
     * @param $id
     * @return Form
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws FormBuilderException
     * @author xaboy
     * @day 2020-05-07
     */
    public function changeLabelForm($id)
    {
        $isArray = is_array($id);
        if (!$isArray)
            $user = $this->dao->get($id);

        /** @var UserLabelRepository $make */
        $data = app()->make(UserLabelRepository::class)->allOptions();
        return Elm::createForm(Route::buildUrl($isArray ? 'systemUserBatchChangeLabel' : 'systemUserChangeLabel', $isArray ? [] : compact('id'))->build(), [
            Elm::hidden('ids', $isArray ? $id : [$id]),
            Elm::selectMultiple('label_id', '用户标签：', $isArray ? [] : $user->label_id)->options(function () use ($data) {
                $options = [];
                foreach ($data as $value => $label) {
                    $value = (string)$value;
                    $options[] = compact('value', 'label');
                }
                return $options;
            })->placeholder('请选择用户标签')
        ])->setTitle('修改用户标签');
    }

    /**
     * 设置分销推广员
     * @param $id
     * @return Form
     * @author Qinii
     * @day 2023/10/26
     */
    public function batchSpreadForm($id)
    {
        /** @var UserLabelRepository $make */
        $data = app()->make(UserLabelRepository::class)->allOptions();
        return Elm::createForm(Route::buildUrl('getMemberLevelBatchSpread', compact('id'))->build(), [
            Elm::hidden('uids', $id),
            Elm::radio('status', '推广员：', 1)->options([
                    ['value' => 1, 'label' => '是'],
                    ['value' => 0, 'label' => '否']]
            ),
        ])->setTitle('批量设置推广员');
    }

    /**
     * @param $id
     * @return Form
     * @throws FormBuilderException
     * @author xaboy
     * @day 2020-05-07
     */
    public function changeNowMoneyForm($id)
    {
        return Elm::createForm(Route::buildUrl('systemUserChangeNowMoney', compact('id'))->build(), [
            Elm::radio('type', '修改余额：', 1)->options([
                ['label' => '增加', 'value' => 1],
                ['label' => '减少', 'value' => 0],
            ])->requiredNum(),
            Elm::number('now_money', '金额')->required()->min(0)->max(999999)
        ])->setTitle('修改用户余额');
    }

    public function changeIntegralForm($id)
    {
        return Elm::createForm(Route::buildUrl('systemUserChangeIntegral', compact('id'))->build(), [
            Elm::radio('type', '修改抵用券：', 1)->options([
                ['label' => '增加', 'value' => 1],
                ['label' => '减少', 'value' => 0],
            ])->requiredNum(),
            Elm::number('coupon_amount', '抵用券')->required()->min(0)->max(50)
        ])->setTitle('修改用户抵用券');
    }

    /**
     * @param $id
     * @param $adminId
     * @param $type
     * @param $nowMoney
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author xaboy
     * @day 2020-05-07
     */
    public function changeNowMoney($id, $adminId, $type, $nowMoney)
    {
        $user = $this->dao->get($id);
        Db::transaction(function () use ($id, $adminId, $user, $type, $nowMoney) {
            $balance = $type == 1 ? bcadd($user->now_money, $nowMoney, 2) : bcsub($user->now_money, $nowMoney, 2);
            $user->save(['now_money' => $balance]);
            /** @var UserBillRepository $make */
            $make = app()->make(UserBillRepository::class);
            if ($type == 1) {
                $make->incBill($id, 'now_money', 'sys_inc_money', [
                    'link_id' => $adminId,
                    'status' => 1,
                    'title' => '系统增加余额',
                    'number' => $nowMoney,
                    'mark' => '系统增加了' . floatval($nowMoney) . '余额',
                    'balance' => $balance
                ]);
            } else {
                $make->decBill($id, 'now_money', 'sys_dec_money', [
                    'link_id' => $adminId,
                    'status' => 1,
                    'title' => '系统减少余额',
                    'number' => $nowMoney,
                    'mark' => '系统减少了' . floatval($nowMoney) . '余额',
                    'balance' => $balance
                ]);
            }
        });
    }

    /**
     * 修改用户权益值
     * @param $id 用户ID
     * @param $adminId 管理员ID
     * @param $type 类型 1增加 2减少
     * @param $equityValue 权益值
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author 系统生成
     */
    public function changeEquityValue($id, $adminId, $type, $equityValue)
    {
        $user = $this->dao->get($id);
        Db::transaction(function () use ($id, $adminId, $user, $type, $equityValue) {
            $equityValue = floatval($equityValue);
            $balance = $type == 1 ? bcadd($user->equity_value, $equityValue, 2) : bcsub($user->equity_value, $equityValue, 2);
            $user->save(['equity_value' => $balance]);
            /** @var UserBillRepository $make */
            $make = app()->make(UserBillRepository::class);
            if ($type == 1) {
                $make->incBill($id, 'equity_value', 'sys_inc', [
                    'link_id' => $adminId,
                    'status' => 1,
                    'title' => '系统增加权益值',
                    'number' => $equityValue,
                    'mark' => '系统增加了' . floatval($equityValue) . '权益值',
                    'balance' => $balance
                ]);
            } else {
                $make->decBill($id, 'equity_value', 'sys_dec', [
                    'link_id' => $adminId,
                    'status' => 1,
                    'title' => '系统减少权益值',
                    'number' => $equityValue,
                    'mark' => '系统减少了' . floatval($equityValue) . '权益值',
                    'balance' => $balance
                ]);
            }
        });
    }

    /**
     * 给推广用户增加权益值
     * @param $uid 用户ID
     * @param $equityValue 权益值
     * @param $orderId 订单ID
     * @param $mark 备注
     * @author 系统生成
     */
    public function incEquityValue($uid, $equityValue, $orderId = 0, $mark = '')
    {
        $user = $this->dao->get($uid);
        Db::transaction(function () use ($uid, $user, $equityValue, $orderId, $mark) {
            $equityValue = floatval($equityValue);
            $balance = bcadd($user->equity_value, $equityValue, 2);
            $user->save(['equity_value' => $balance]);
            
            /** @var UserBillRepository $make */
            $make = app()->make(UserBillRepository::class);
            $make->incBill($uid, 'equity_value', 'promotion_reward', [
                'link_id' => $orderId,
                'status' => 1,
                'title' => '推广奖励权益值',
                'number' => $equityValue,
                'mark' => $mark ?: '推广用户获得权益值奖励' . floatval($equityValue),
                'balance' => $balance
            ]);
        });
    }

    /**
     * @param $id
     * @param $adminId
     * @param $type
     * @param $integral
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author xaboy
     * @day 2020-05-07
     */
    public function changeIntegral($id, $adminId, $type, $integral)
    {
        $user = $this->dao->get($id);
        Db::transaction(function () use ($id, $adminId, $user, $type, $integral) {
            $integral = (int)$integral;
            $balance = $type == 1 ? bcadd($user->integral, $integral, 0) : bcsub($user->integral, $integral, 0);
            $user->save(['integral' => $balance]);
            /** @var UserBillRepository $make */
            $make = app()->make(UserBillRepository::class);
            if ($type == 1) {
                $make->incBill($id, 'integral', 'sys_inc', [
                    'link_id' => $adminId,
                    'status' => 1,
                    'title' => '系统增加积分',
                    'number' => $integral,
                    'mark' => '系统增加了' . $integral . '积分',
                    'balance' => $balance
                ]);
            } else {
                $make->decBill($id, 'integral', 'sys_dec', [
                    'link_id' => $adminId,
                    'status' => 1,
                    'title' => '系统减少积分',
                    'number' => $integral,
                    'mark' => '系统减少了' . $integral . '积分',
                    'balance' => $balance
                ]);
            }
        });
    }

    /**
     * @param $id
     * @param $adminId
     * @param $type
     * @param $integral
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author xaboy
     * @day 2020-05-07
     */
    public function changeDeduction($id, $adminId, $type, $integral)
    {
        $user = $this->dao->get($id);
        // 获取最多的奖池
        $poolInfo = Db::name('dividend_pool')
            ->where('city_id', '<>', 0)
            ->where('available_amount', '>', $integral)
            ->order('available_amount', 'desc')
            ->find();

        if(!$poolInfo){
            throw new ValidateException('未找到可用的奖池');
        }
        Db::transaction(function () use ($id, $adminId, $user, $type, $integral,$poolInfo) {
            $integral = (int)$integral;
            $balance = $type == 1 ? bcadd($user->coupon_amount, $integral, 0) : bcsub($user->coupon_amount, $integral, 0);
            $user->save(['coupon_amount' => $balance]);
            /** @var UserBillRepository $make */
            $make = app()->make(UserBillRepository::class);
            if ($type == 1) {
                $make->incBill($id, 'coupon_amount', 'sys_inc', [
                    'link_id' => $adminId,
                    'status' => 1,
                    'title' => '系统增加抵用券',
                    'number' => $integral,
                    'mark' => '系统增加了' . $integral . '抵用券',
                    'balance' => $balance
                ]);

                if($integral>=$poolInfo['available_amount']){
                    throw new ValidateException('奖池可用余额不足');
                }

                Db::name('dividend_pool')
                    ->where('id', $poolInfo['id'])
                    ->update([
                        'available_amount' => Db::raw('available_amount - ' . $integral),
                        'update_time' => date('Y-m-d H:i:s')
                    ]);

                // 分红池流水表
                Db::name('dividend_pool_log')->insert([
                    'order_id' => 0,
                    'amount' => $integral,
                    'handling_fee' => 0,
                    'mer_id' => 0,
                    'pm' => 0,
                    'uid' => $id,
                    'city' => $poolInfo['city'],
                    'city_id' => $poolInfo['city_id'],
                    'create_time' => date('Y-m-d H:i:s'),
                    'remark' => '系统扣减奖池，给用户增加抵用券',
                ]);

            } else {
                $make->decBill($id, 'coupon_amount', 'sys_dec', [
                    'link_id' => $adminId,
                    'status' => 1,
                    'title' => '系统减少抵用券',
                    'number' => $integral,
                    'mark' => '系统减少了' . $integral . '抵用券',
                    'balance' => $balance
                ]);
            }
        });
    }

    public function syncBaseAuth(array $auth, User $user)
    {
        $wechatUser = app()->make(WechatUserRepository::class)->get($auth['id']);
        if ($wechatUser) {
            $data = ['wechat_user_id' => $auth['id'], 'user_type' => $auth['type']];
            if ($wechatUser['nickname']) {
                $data['nickname'] = $wechatUser['nickname'];
            }
            if ($wechatUser['headimgurl']) {
                $data['avatar'] = $wechatUser['headimgurl'];
            }
            $data['sex'] = $wechatUser['sex'] ?? 0;
            $user->save($data);
        }
    }

    /**
     * @param WechatUser $wechatUser
     * @return BaseDao|array|Model|null
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author xaboy
     * @day 2020-04-28
     */
    public function syncWechatUser(WechatUser $wechatUser, $userType = 'wechat')
    {
        $user = $this->dao->wechatUserIdBytUser($wechatUser->wechat_user_id);
        $request = request();

        if ($user) {
            $user->save(array_filter([
                'nickname' => $wechatUser['nickname'] ?? '',
                'avatar' => $wechatUser['headimgurl'] ?? '',
                'sex' => $wechatUser['sex'] ?? 0,
                'last_time' => date('Y-m-d H:i:s'),
                'last_ip' => $request->ip(),
            ]));
        } else {
            $user = $this->create($userType, [
                'account' => 'wx' . $wechatUser->wechat_user_id . time(),
                'wechat_user_id' => $wechatUser->wechat_user_id,
                'pwd' => $this->encodePassword($this->dao->defaultPwd()),
                'nickname' => $wechatUser['nickname'] ?? '',
                'avatar' => $wechatUser['headimgurl'] ?? '',
                'sex' => $wechatUser['sex'] ?? 0,
                'spread_uid' => 0,
                'group_id' => 1,
                'is_promoter' => 1,// 默认成为推广员
                'promoter_time' => date('Y-m-d H:i:s'),
                'last_time' => date('Y-m-d H:i:s'),
                'last_ip' => $request->ip()
            ]);
        }
        return $user;
    }

    /**
     * @param AlipayUser $alipayUser
     * @return BaseDao|array|Model|null
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author xaboy
     * @day 2020-04-28
     */
    public function syncAlipayUser(AlipayUser $alipayUser, $userType = 'alipay')
    {
        $user = $this->dao->alipayUserIdBytUser($alipayUser->alipay_user_id);
        $request = request();

        if ($user) {
            $user->save(array_filter([
                'nickname' => $alipayUser['nickname'] ?? '',
                'avatar' => $alipayUser['headimgurl'] ?? '',
                'sex' => $alipayUser['sex'] ?? 0,
                'last_time' => date('Y-m-d H:i:s'),
                'last_ip' => $request->ip(),
            ]));
        } else {
            $user = $this->create($userType, [
                'account' => 'wx' . $alipayUser->alipay_user_id . time(),
                'alipay_user_id' => $alipayUser->alipay_user_id,
                'pwd' => $this->encodePassword($this->dao->defaultPwd()),
                'nickname' => $alipayUser['nickname'] ?? '',
                'avatar' => $alipayUser['headimgurl'] ?? '',
                'sex' => $alipayUser['sex'] ?? 0,
                'spread_uid' => 0,
                'group_id' => 1,
                'is_promoter' => 1,// 默认成为推广员
                'promoter_time' => date('Y-m-d H:i:s'),
                'last_time' => date('Y-m-d H:i:s'),
                'last_ip' => $request->ip()
            ]);
        }
        return $user;
    }

    /**
     * @param string $type
     * @param array $userInfo
     * @return BaseDao|Model
     * @author xaboy
     * @day 2020-04-28
     */
    public function create(string $type, array $userInfo)
    {
        $extend_info = [];
        if (isset($userInfo['extend_info'])) {
            $extend_info = $userInfo['extend_info'];
            unset($userInfo['extend_info']);
        }

        $userInfo['user_type'] = $this->userType($type);
        if (!isset($userInfo['status'])) {
            $userInfo['status'] = 1;
        }
        $user = $this->dao->create($userInfo);
        try {
            Queue::push(SendNewPeopleCouponJob::class, $user->uid);
        } catch (\Exception $e) {
        }
        $user->isNew = true;
        // 保存扩展字段数据
        $this->saveFields((int)$user['uid'], $extend_info);
        return $user;
    }

    public function userType($type)
    {
        if ($type === 'apple') {
            return 'app';
        }
        if (!$type)
            return 'h5';
        return $type;
    }

    /**
     * 保存或者编辑扩展字段
     * @param int $uid
     * @param array $extend_info
     * @return true
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function saveFields(int $uid, array $extend_info = [])
    {
        // 组合数据
        $save_data = [];
        foreach ($extend_info as $item) {
            $save_data[] = [
                'field' => $item['field'],
                'value' => $item['value'],
            ];
        }
        return app()->make(UserFieldsRepository::class)->save($uid, $save_data, false);
    }

    /**
     * @param $password
     * @return false|string|null
     * @author xaboy
     * @day 2020/6/22
     */
    public function encodePassword($password)
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    /**
     * 更新用户组
     * @param User $user
     * @throws DbException
     * @author xaboy
     * @day 2020/6/22
     */
    public function updateUserGroup(User $user)
    {
        $this->dao->update($user['uid'], ['group_id' => 1]);
    }

    /**
     * @param User $user
     * @return array
     * @author xaboy
     * @day 2020-04-29
     */
    public function createToken(User $user)
    {
        $service = new JwtTokenService();
        $exp = intval(Config::get('admin.user_token_valid_exp', 15));
        $token = $service->createToken($user->uid, 'user', strtotime("+ {$exp}day"));
        $this->cacheToken($token['token'], $token['out']);
        return $token;
    }

    /**
     * @param string $token
     * @param int $exp
     * @author xaboy
     * @day 2020-04-29
     */
    public function cacheToken(string $token, int $exp)
    {
        Cache::store('file')->set('user_' . $token, time() + $exp, $exp);
    }

    /**
     * //TODO 登录成功后
     * @param User $user
     * @author xaboy
     * @day 2020/6/22
     */
    public function loginAfter(User $user)
    {
        $user->last_time = date('Y-m-d H:i:s', time());
        $user->last_ip = request()->ip();
        $user->save();
    }

    /**
     * @param string $token
     * @author xaboy
     * @day 2020-04-29
     */
    public function checkToken(string $token)
    {
        $cache = Cache::store('file');
        $has = $cache->has('user_' . $token);
        if (!$has)
            throw new AuthException('无效的token');
        $lastTime = $cache->get('user_' . $token);
        /*if (($lastTime + (intval($cache->get('admin.user_token_valid_exp', 15))) * 24 * 60 * 60) < time())
            throw new AuthException('token 已过期');*/
    }

    /**
     * @param string $token
     * @author xaboy
     * @day 2020-04-29
     */
    public function updateToken(string $token)
    {
        Cache::store('file')->set('user_' . $token, time(), intval(Config::get('admin.user_token_valid_exp', 15)) * 24 * 60 * 60);
    }

    /**
     * @param string $token
     * @author xaboy
     * @day 2020-04-29
     */
    public function clearToken(string $token)
    {
        Cache::store('file')->delete('user_' . $token);
    }

    /**
     * @param string $key
     * @param string $code
     * @author xaboy
     * @day 2020/6/1
     */
    public function checkCode(string $key, string $code)
    {
        $_code = Cache::get('am_captcha' . $key);
        if (!$_code) {
            throw new ValidateException('验证码过期');
        }

        if (strtolower($_code) != strtolower($code)) {
            throw new ValidateException('验证码错误');
        }

        //删除code
        Cache::delete('am_captcha' . $key);
    }

    /**
     * @param string $code
     * @return string
     * @author xaboy
     * @day 2020/6/1
     */
    public function createLoginKey(string $code)
    {
        $key = uniqid(microtime(true), true);
        Cache::set('am_captcha' . $key, $code, Config::get('admin.captcha_exp', 5) * 60);
        return $key;
    }

    public function registr(string $phone, ?string $pwd, $user_type = 'h5')
    {
        $pwd = $pwd ? $this->encodePassword($pwd) : $this->encodePassword($this->dao->defaultPwd());
        $data = [
            'account' => $phone,
            'pwd' => $pwd,
            'nickname' => substr($phone, 0, 3) . '****' . substr($phone, 7, 4),
            'avatar' => '',
            'phone' => $phone,
            'group_id' => 1,
            'is_promoter' => 1,// 默认成为推广员
            'promoter_time' => date('Y-m-d H:i:s'),
            'last_ip' => app('request')->ip()
        ];
        return $this->create($user_type, $data);
    }

    public function routineSpreadImage(User $user)
    {
        //小程序
        $name = md5('surt' . $user['uid'] . $user['is_promoter'] . date('Ymd')) . '.jpg';
        $attachmentRepository = app()->make(AttachmentRepository::class);
        $imageInfo = $attachmentRepository->getWhere(['attachment_name' => $name]);
        $spreadBanner = systemGroupData('spread_banner');
        if (!count($spreadBanner)) return [];
        $siteName = systemConfig('site_name');
        $siteUrl = systemConfig('site_url');
        $uploadType = (int)systemConfig('upload_type') ?: 1;

        $urlCode = app()->make(QrcodeService::class)->getRoutineQrcodePath($name, 'pages/index/index', 'spid=' . $user['uid']);
        if (!$urlCode)
            throw new ValidateException('二维码生成失败');
        $filelink = [
            'Bold' => 'public/font/Alibaba-PuHuiTi-Regular.otf',
            'Normal' => 'public/font/Alibaba-PuHuiTi-Regular.otf',
        ];
        if (!file_exists($filelink['Bold'])) throw new ValidateException('缺少字体文件Bold');
        if (!file_exists($filelink['Normal'])) throw new ValidateException('缺少字体文件Normal');
        $resRoutine = true;
        foreach ($spreadBanner as $key => &$item) {
            $posterInfo = '海报生成失败:(';
            $config = array(
                'image' => array(
                    array(
                        'url' => $urlCode,     //二维码资源
                        'stream' => 0,
                        'left' => 114,
                        'top' => 790,
                        'right' => 0,
                        'bottom' => 0,
                        'width' => 120,
                        'height' => 120,
                        'opacity' => 100
                    )
                ),
                'text' => array(
                    array(
                        'text' => $user['nickname'],
                        'left' => 250,
                        'top' => 840,
                        'fontPath' => $filelink['Bold'],     //字体文件
                        'fontSize' => 16,             //字号
                        'fontColor' => '40,40,40',       //字体颜色
                        'angle' => 0,
                    ),
                    array(
                        'text' => '邀请您加入' . $siteName,
                        'left' => 250,
                        'top' => 880,
                        'fontPath' => $filelink['Normal'],     //字体文件
                        'fontSize' => 16,             //字号
                        'fontColor' => '40,40,40',       //字体颜色
                        'angle' => 0,
                    )
                ),
                'background' => $item['pic']
            );
            $resRoutine = $resRoutine && $posterInfo = setSharePoster($config, 'routine/spread/poster');
            if (!is_array($posterInfo)) throw new ValidateException($posterInfo);
            $posterInfo['dir'] = tidy_url($posterInfo['dir'], 0, $siteUrl);
            if ($resRoutine) {
                $attachmentRepository->create($uploadType, -1, $user->uid, [
                    'attachment_category_id' => 0,
                    'attachment_name' => $posterInfo['name'],
                    'attachment_src' => $posterInfo['dir']
                ]);
                $item['poster'] = $posterInfo['dir'];
            }
        }
        return $spreadBanner;
    }

    public function wxQrcode(User $user)
    {
        $name = md5('uwx_i' . $user['uid'] . date('Ymd')) . '.jpg';
        $key = 'home_' . $user['uid'];
        return app()->make(QrcodeService::class)->getWechatQrcodePath($name, rtrim(systemConfig('site_url'), '/') . '?spid=' . $user['uid'], false, $key);
    }

    public function mpQrcode(User $user)
    {
        $name = md5('surt_i' . $user['uid'] . $user['is_promoter'] . date('Ymd')) . '.jpg';

        return app()->make(QrcodeService::class)->getRoutineQrcodePath($name, 'pages/index/index', 'spid=' . $user['uid']);
    }

    public function wxSpreadImage(User $user)
    {
        $name = md5('uwx' . $user['uid'] . $user['is_promoter'] . date('Ymd')) . '.jpg';
        $spreadBanner = systemGroupData('spread_banner');
        if (!count($spreadBanner)) return [];
        $siteName = systemConfig('site_name');
        $attachmentRepository = app()->make(AttachmentRepository::class);
        $imageInfo = $attachmentRepository->getWhere(['attachment_name' => $name]);
        $siteUrl = rtrim(systemConfig('site_url'), '/');
        $uploadType = (int)systemConfig('upload_type') ?: 1;
        $resWap = true;
        //检测远程文件是否存在
        if (isset($imageInfo['attachment_src']) && strstr($imageInfo['attachment_src'], 'http') !== false && curl_file_exist($imageInfo['attachment_src']) === false) {
            $imageInfo->delete();
            $imageInfo = null;
        }
        if (!$imageInfo) {
            $codeUrl = $siteUrl . '?spread=' . $user['uid'] . '&spid=' . $user['uid'];//二维码链接
            if (systemConfig('open_wechat_share')) {
                $qrcode = WechatService::create(false)->qrcodeService();
                $codeUrl = $qrcode->forever('_scan_url_home_' . $user['uid'])->url;
            }
            $imageInfo = app()->make(QrcodeService::class)->getQRCodePath($codeUrl, $name);
            if (is_string($imageInfo)) throw new ValidateException('二维码生成失败');

            $imageInfo['dir'] = tidy_url($imageInfo['dir'], null, $siteUrl);

            $attachmentRepository->create(systemConfig('upload_type') ?: 1, -1, $user->uid, [
                'attachment_category_id' => 0,
                'attachment_name' => $imageInfo['name'],
                'attachment_src' => $imageInfo['dir']
            ]);
            $urlCode = $imageInfo['dir'];
        } else $urlCode = $imageInfo['attachment_src'];
        $filelink = [
            'Bold' => 'public/font/Alibaba-PuHuiTi-Regular.otf',
            'Normal' => 'public/font/Alibaba-PuHuiTi-Regular.otf',
        ];
        if (!file_exists($filelink['Bold'])) throw new ValidateException('缺少字体文件Bold');
        if (!file_exists($filelink['Normal'])) throw new ValidateException('缺少字体文件Normal');
        foreach ($spreadBanner as $key => &$item) {
            $posterInfo = '海报生成失败:(';
            $config = array(
                'image' => array(
                    array(
                        'url' => $urlCode,     //二维码资源
                        'stream' => 0,
                        'left' => 114,
                        'top' => 790,
                        'right' => 0,
                        'bottom' => 0,
                        'width' => 120,
                        'height' => 120,
                        'opacity' => 100
                    )
                ),
                'text' => array(
                    array(
                        'text' => $user['nickname'],
                        'left' => 250,
                        'top' => 840,
                        'fontPath' => $filelink['Bold'],     //字体文件
                        'fontSize' => 16,             //字号
                        'fontColor' => '40,40,40',       //字体颜色
                        'angle' => 0,
                    ),
                    array(
                        'text' => '邀请您加入' . $siteName,
                        'left' => 250,
                        'top' => 880,
                        'fontPath' => $filelink['Normal'],     //字体文件
                        'fontSize' => 16,             //字号
                        'fontColor' => '40,40,40',       //字体颜色
                        'angle' => 0,
                    )
                ),
                'background' => $item['pic']
            );
            $resWap = $resWap && $posterInfo = setSharePoster($config, 'wap/spread/poster');
            if (!is_array($posterInfo)) throw new ValidateException('海报生成失败');
            $posterInfo['dir'] = tidy_url($posterInfo['dir'], null, $siteUrl);
            $attachmentRepository->create($uploadType, -1, $user->uid, [
                'attachment_category_id' => 0,
                'attachment_name' => $posterInfo['name'],
                'attachment_src' => $posterInfo['dir']
            ]);
            if ($resWap) {
                $item['wap_poster'] = $posterInfo['dir'];
            }
        }
        return $spreadBanner;
    }

    public function getUsername($uid)
    {
        return User::getDB()->where('uid', $uid)->value('nickname');
    }

    /**
     * @param $uid
     * @param $inc
     * @param string $type
     * @author xaboy
     * @day 2020/6/22
     */
    public function incBrokerage($uid, $inc, $type = '+')
    {
        $moneyKey = 'b_top_' . date('Y-m');
        $weekKey = 'b_top_' . monday();
        //TODO 佣金周榜
        $brokerage = Cache::zscore($weekKey, $uid);
        $brokerage = $type == '+' ? bcadd($brokerage, $inc, 2) : bcsub($brokerage, $inc, 2);
        Cache::zadd($weekKey, $brokerage, $uid);

        //TODO 佣金月榜
        $brokerage = Cache::zscore($moneyKey, $uid);
        $brokerage = $type == '+' ? bcadd($brokerage, $inc, 2) : bcsub($brokerage, $inc, 2);
        Cache::zadd($moneyKey, $brokerage, $uid);
    }

    public function brokerageWeekTop($uid, $page, $limit)
    {
        $key = 'b_top_' . monday();
        return $this->topList($key, $page, $limit) + ['position' => $this->userPosition($key, $uid)];
    }

    public function topList($key, $page, $limit)
    {
        $res = Cache::zrevrange($key, ($page - 1) * $limit, $limit, true);
        $ids = array_keys($res);
        $count = Cache::zcard($key);
        $list = count($ids) ? $this->dao->users($ids, 'uid,avatar,nickname')->toArray() : [];
        foreach ($list as $k => $v) {
            $list[$k]['count'] = $res[$v['uid']] ?? 0;
        }
        $sort = array_column($list, 'count');
        array_multisort($sort, SORT_DESC, $list);
        return compact('count', 'list');
    }

    public function userPosition($key, $uid)
    {
        $index = Cache::zrevrank($key, $uid);
        if ($index === false)
            return 0;
        else
            return $index + 1;
    }

    public function brokerageMonthTop($uid, $page, $limit)
    {
        $key = 'b_top_' . date('Y-m');
        return $this->topList($key, $page, $limit) + ['position' => $this->userPosition($key, $uid)];
    }

    /**
     * //TODO 绑定上下级关系
     * @param User $user
     * @param int $spreadUid
     * @throws DbException
     * @author xaboy
     * @day 2020/6/22
     */
    public function bindSpread(User $user, $spreadUid)
    {
        if ($spreadUid && !$user->spread_uid && $user->uid != $spreadUid && ($spread = $this->dao->get($spreadUid)) && $spread->spread_uid != $user->uid && !$spread->cancel_time) {
            $config = systemConfig(['extension_limit', 'extension_limit_day', 'integral_user_give']);
            event('user.spread.before', compact('user', 'spreadUid'));
            Db::transaction(function () use ($spread, $spreadUid, $user, $config) {
                $user->spread_uid = $spreadUid;
                $user->spread_time = date('Y-m-d H:i:s');
                if ($config['extension_limit'] && $config['extension_limit_day']) {
                    $user->spread_limit = date('Y-m-d H:i:s', strtotime('+ ' . $config['extension_limit_day'] . ' day'));
                }
                $spread->spread_count++;
                if ($config['integral_user_give'] > 0 && $user->isNew) {
                    /*$integral = (int)$config['integral_user_give'];
                    $spread->integral += $integral;
                    app()->make(UserBillRepository::class)->incBill($spreadUid, 'integral', 'spread', [
                        'link_id' => $user->uid,
                        'status' => 1,
                        'title' => '邀请好友',
                        'number' => $integral,
                        'mark' => '邀请好友奖励' . $integral . '积分',
                        'balance' => $spread->integral
                    ]);*/
                    // 邀请赠送抵用券
                }
                $spread->save();
                $user->save();
                $redisCacheService = app()->make(RedisCacheService::class);
                //TODO 推广人月榜
                $redisCacheService->zincrby('s_top_' . date('Y-m'), 1, $spreadUid);
                //TODO 推广人周榜
                $redisCacheService->zincrby('s_top_' . monday(), 1, $spreadUid);
            });
            Queue::push(UserBrokerageLevelJob::class, ['uid' => $spreadUid, 'type' => 'spread_user', 'inc' => 1]);
            // 被邀请人获得成长值
            app()->make(UserBrokerageRepository::class)->incMemberValue($user->uid, 'member_share_num', 0);
            // 邀请人获得成长值
            app()->make(UserBrokerageRepository::class)->incMemberValue($spreadUid, 'member_share_num', 0);
            event('user.spread', compact('user', 'spreadUid'));
        }
    }

    public function spreadWeekTop($page, $limit)
    {
        $key = 's_top_' . monday();
        return $this->topList($key, $page, $limit);
    }

    public function spreadMonthTop($page, $limit)
    {
        $key = 's_top_' . date('Y-m');
        return $this->topList($key, $page, $limit);
    }

    /**
     * @param $uid
     * @param $nickname
     * @param $sort
     * @param $page
     * @param $limit
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author xaboy
     * @day 2020/6/22
     */
    public function getOneLevelList($uid, $where, $page, $limit)
    {
        $where['spread_uid'] = $uid;
        $query = $this->search($where);
        $count = $query->count();
        $list = $query->setOption('field', [])->field('uid,avatar,phone,nickname,pay_count,pay_price,spread_count,spread_time')->page($page, $limit)->select();
        // 手机号脱敏
        foreach ($list as &$item) {
            if (!$item['phone']) {
                continue;
            }
            $item['phone'] = substr_replace($item['phone'], '****', 3, 4);
        }
        return compact('list', 'count');
    }

    /**
     * @param $uid
     * @param $nickname
     * @param $sort
     * @param $page
     * @param $limit
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author xaboy
     * @day 2020/6/22
     */
    public function getTwoLevelList($uid, $where, $page, $limit)
    {
        $where['spread_uids'] = $this->dao->getSubIds($uid);
        if (count($where['spread_uids'])) {
            $query = $this->search($where);
            $count = $query->count();
            $list = $query->setOption('field', [])->field('uid,avatar,phone,nickname,pay_count,pay_price,spread_count,spread_time')->page($page, $limit)->select();
            // 手机号脱敏
            foreach ($list as &$item) {
                if (!$item['phone']) {
                    continue;
                }
                $item['phone'] = substr_replace($item['phone'], '****', 3, 4);
            }
        } else {
            $list = [];
            $count = 0;
        }
        return compact('list', 'count');
    }


    /**
     * @param $uid
     * @param $nickname
     * @param $sort
     * @param $page
     * @param $limit
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author xaboy
     * @day 2020/6/22
     */
    public function getSubordinateList($uid, $where, $page, $limit)
    {
        $where['superior_uid'] = $uid;
        //print_r($where['superior_uids']);exit();
        $query = $this->dao->search($where);
        $query->with(['group' => function ($query) {
            $query->field('group_id,group_name');
        }]);
        $count = $query->count();
        $list = $query->setOption('field', [])->field('uid,group_id,avatar,phone,nickname,pay_count,pay_price,merchant_count,superior_count,superior_time')->page($page, $limit)->select();
        // 手机号脱敏
        foreach ($list as &$item) {
            if (!$item['phone']) {
                continue;
            }
            $item['phone'] = substr_replace($item['phone'], '****', 3, 4);
            //线下订单数量统计
            //$offlineOrderCount = (new \app\common\model\store\order\StoreOrderOffline)->where(['uid' => $item->uid, 'paid' => 1])->count();
            // 线上订单数量统计
            //$orderCount = (new \app\common\model\store\order\StoreOrder)->where(['uid' => $uid, 'paid' => 1])->count();
            //$item['pay_count'] = $orderCount + $offlineOrderCount;
            // 邀请的商户数
            $item['merchant_count'] = (new \app\common\model\system\merchant\Merchant)->where(['salesman_id' => $item->uid])->count();
        }
        // $list根据merchant_count字段进行降序排序
        if ($list) {
            $merchantCounts = array_column($list->toArray(), 'merchant_count');
            $listArray = $list->toArray();
            array_multisort($merchantCounts, SORT_DESC, $listArray);
            $list = collect($listArray);
        }

        return compact('list', 'count');
    }

    // 检测商务流水
    public function getMerchantInfo(): bool
    {
        $userGroupRepository = app()->make(UserGroupRepository::class);
        // 获取商务分组信息及比例
        $salesmanGroup = $userGroupRepository->get(2);
        // 获取所有商务员id
        $salesmanIds = (new \app\common\model\user\User)->whereIn('group_id',[2, 9])->column('uid');
        foreach ($salesmanIds as $uid) {

            //  获取商务员关联的商户
            $merIds = (new \app\common\model\system\merchant\Merchant)->where(['salesman_id' => $uid])->column('mer_id');

            // 线上订单手续费
            //$orderCountHandlingFee = (new \app\common\model\store\order\StoreOrder)->whereMonth('create_time', 'last month')->where(['mer_id' => $merIds, 'paid' => 1])->sum('total_price');

            // 线下订单手续费
            $offlineOrderCountHandlingFee = (new \app\common\model\store\order\StoreOrderOffline)->whereMonth('create_time', 'last month')->where(['mer_id' => $merIds, 'paid' => 1])->sum('handling_fee');

            // 所有手续费
            $handlingFee = bcadd(0, $offlineOrderCountHandlingFee, 2);
            $handlingInfo=Db::name('user_handling_fee')->where('uid', $uid)->whereMonth('create_time', 'last month')->find();

            if($handlingInfo && $handlingInfo['total_amount'] < $handlingFee){
                // 更新
                Db::name('user_handling_fee')->where('id',$handlingInfo['id'])->update([
                    'total_amount'=>Db::raw('total_amount + ' . $handlingFee)
                ]);
            }

            Db::name('user_handling_fee')->insert([
                'uid' => $uid,
                'total_amount' => $handlingFee,
                'execute_date' => date('Y-m',strtotime('-1 month')),
                'create_time' => date('Y-m-d H:i:s')
            ]);

            // 如果$handlingFee>$salesmanGroup['money']，发放积分奖励
            if ($handlingFee > $salesmanGroup['money']) {
                $bill = Db::name('user_bill')->where('uid', $uid)->where('link_id', date('Y-m',strtotime('-1 month')))->where('type','salesman')->find();
                if(!$bill){
                    $integral = $salesmanGroup['integral'];
                    // 积分记录
                    app()->make(UserBillRepository::class)->incBill($uid, 'integral','salesman', [  // 发放积分
                        'link_id' => date('Y-m',strtotime('-1 month')),
                        'status' => 1,
                        'title' => '高级商务员奖励',
                        'number' => $integral,
                        'mark' => '流水金额'. $handlingFee. '元',
                        'balance' => (new \app\common\model\user\User)->where('uid', $uid)->value('integral') + $integral
                    ]);

                    // 给用户增加积分,升级高级商务
                    Db::name('user')->where('uid', $uid)->update([
                        'group_id' => 9,
                        'integral' => Db::raw('integral + '. $integral)
                    ]);
                }
            }
        }

        return true;

    }

    /**
     * @param $uid
     * @param $nickname
     * @param $sort
     * @param $page
     * @param $limit
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author xaboy
     * @day 2020/6/22
     */
    public function getInviteList($uid, $where, $page, $limit)
    {
        //$where['superior_uids'] = $this->dao->getSupIds($uid);

        $query = $this->dao->search($where);
        $query->with(['group' => function ($query) {
            $query->field('group_id,group_name');
        }]);
        $count = $query->count();
        $list = $query->setOption('field', [])->field('uid,group_id,avatar,phone,nickname,pay_count,pay_price,merchant_count,superior_count,superior_time')->page($page, $limit)->select();
        // 手机号脱敏
        foreach ($list as &$item) {
            if (!$item['phone']) {
                continue;
            }
            $item['phone'] = substr_replace($item['phone'], '****', 3, 4);
        }

        return compact('list', 'count');
    }

    public function getLevelList($uid, array $where, $page, $limit)
    {
        if (!$where['level']) {
            $ids = $this->dao->getSubIds($uid);
            $ids[] = $uid;
            $where['spread_uids'] = $ids;
        } else if ($where['level'] == 2) {
            $ids = $this->dao->getSubIds($uid);
            if (!count($ids)) return ['list' => [], 'count' => 0];
            $where['spread_uids'] = $ids;
        } else {
            $where['spread_uid'] = $uid;
        }
        $query = $this->search($where);
        $count = $query->count();
        $list = $query->setOption('field', [])->field('uid,avatar,nickname,is_promoter,pay_count,pay_price,spread_count,create_time,spread_time,spread_limit,phone')->page($page, $limit)->select();
        // 统计订单数
        foreach ($list as &$item) {
            //线下订单数量统计
            $offlineOrderCount = (new \app\common\model\store\order\StoreOrderOffline)->where(['uid' => $item->uid, 'paid' => 1])->count();
            // 线上订单数量统计
            $orderCount = (new \app\common\model\store\order\StoreOrder)->where(['uid' => $uid, 'paid' => 1])->count();
            $item['pay_count'] = $orderCount + $offlineOrderCount;
        }
        return compact('list', 'count');
    }


    /**
     * @param $uid
     * @param $page
     * @param $limit
     * @param array $where
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author xaboy
     * @day 2020/6/26
     */
    public function subOrder($uid, $page, $limit, array $where = [])
    {
        if (isset($where['level'])) {
            if (!$where['level']) {
                $ids = $this->dao->getSubIds($uid);
                $subIds = $ids ? $this->dao->getSubAllIds($ids) : [];
            } else if ($where['level'] == 2) {
                $ids = $this->dao->getSubIds($uid);
                $subIds = $ids ? $this->dao->getSubAllIds($ids) : [];
                $ids = [];
            } else if ($where['level'] == -1) {
                $ids = [];
                $subIds = [];
            } else {
                $ids = $this->dao->getSubIds($uid);
                $subIds = [];
            }
        } else {
            $ids = $this->dao->getSubIds($uid);
            $subIds = $ids ? $this->dao->getSubAllIds($ids) : [];
        }
        $all = array_unique(array_merge($ids, $subIds));
        $all[] = -1;
        $query = app()->make(StoreOrderRepository::class)->usersOrderQuery($where, $all, (!isset($where['level']) || !$where['level'] || $where['level'] == -1) ? $uid : 0);
        $count = $query->count();
        $list = $query->page($page, $limit)->field('uid,order_sn,pay_time,extension_one,extension_two,is_selfbuy')->with([
            'user' => function ($query) {
                $query->field('avatar,nickname,uid');
            },
            'orderProduct'
        ])->select()->toArray();
        foreach ($list as $k => $item) {
            if ($item['is_selfbuy']) {
                if ($item['uid'] == $uid) {
                    $list[$k]['brokerage'] = $item['extension_one'];
                } else if (in_array($item['uid'], $ids)) {
                    $list[$k]['brokerage'] = $item['extension_two'];
                } else {
                    $list[$k]['brokerage'] = 0;
                }
            } else {
                $list[$k]['brokerage'] = in_array($item['uid'], $ids) ? $item['extension_one'] : $item['extension_two'];
            }
            unset($list[$k]['extension_one'], $list[$k]['extension_two']);
        }
        return compact('count', 'list');
    }

    /**
     * @param $uid
     * @param $page
     * @param $limit
     * @param array $where
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author xaboy
     * @day 2020/6/26
     */
    public function subOfflineOrder($uid, $page, $limit, array $where = []): array
    {
        if (isset($where['level'])) {
            if (!$where['level']) {
                $ids = $this->dao->getSubIds($uid);
                $subIds = $ids ? $this->dao->getSubAllIds($ids) : [];
            } else if ($where['level'] == 2) {
                $ids = $this->dao->getSubIds($uid);
                $subIds = $ids ? $this->dao->getSubAllIds($ids) : [];
                $ids = [];
            } else if ($where['level'] == -1) {
                $ids = [];
                $subIds = [];
            } else {
                $ids = $this->dao->getSubIds($uid);
                $subIds = [];
            }
        } else {
            $ids = $this->dao->getSubIds($uid);
            $subIds = $ids ? $this->dao->getSubAllIds($ids) : [];
        }
        $all = array_unique(array_merge($ids, $subIds));
        $all[] = -1;
        $query = app()->make(StoreOrderOfflineRepository::class)->usersOrderQuery($where, $all, (!isset($where['level']) || !$where['level'] || $where['level'] == -1) ? $uid : 0);
        $count = $query->count();
        $list = $query->page($page, $limit)->field('uid,order_sn,pay_time,extension_one,extension_two,is_selfbuy')->with([
            'user' => function ($query) {
                $query->field('avatar,nickname,uid');
            },
            //'orderProduct'
        ])->select()->toArray();
        foreach ($list as $k => $item) {
            if ($item['is_selfbuy']) {
                if ($item['uid'] == $uid) {
                    $list[$k]['brokerage'] = $item['extension_one'];
                } else if (in_array($item['uid'], $ids)) {
                    $list[$k]['brokerage'] = $item['extension_two'];
                } else {
                    $list[$k]['brokerage'] = 0;
                }
            } else {
                $list[$k]['brokerage'] = in_array($item['uid'], $ids) ? $item['extension_one'] : $item['extension_two'];
            }
            unset($list[$k]['extension_one'], $list[$k]['extension_two']);
        }
        return compact('count', 'list');
    }

    /**
     * @param User $user
     * @return User
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author xaboy
     * @day 2020/7/2
     */
    public function mainUser(User $user): User
    {
        if (!$user->main_uid || $user->uid == $user->main_uid) return $user;
        $switchUser = $this->dao->get($user->main_uid);
        if (!$switchUser) return $user;
        if ($user->wechat_user_id && !$switchUser->wechat_user_id) {
            $switchUser->wechat_user_id = $user->wechat_user_id;
            $switchUser->save();
        }
        return $switchUser;
    }

    public function switchUser(User $user, $uid)
    {
        if ($user->uid == $uid || !$this->dao->existsWhere(['uid' => $uid, 'phone' => $user->phone]))
            throw new ValidateException('操作失败');
        $this->dao->update($user->uid, ['main_uid' => $uid]);
        $this->dao->getSearch([])->where('main_uid', $user->uid)->update(['main_uid' => $uid]);

        $switchUser = $this->dao->get($uid);
        if (!$switchUser->wechat_user_id) {
            $switchUser->wechat_user_id = $user->wechat_user_id;
            $switchUser->save();
        }

        return $switchUser;
    }

    public function returnToken($user, $tokenInfo)
    {
        if (!$user->status) {
            throw new ValidateException('账号已被禁用');
        }
        $user = $user->hidden(['label_id', 'group_id', 'main_uid', 'pwd', 'addres', 'card_id', 'last_time', 'last_ip', 'create_time', 'mark', 'status', 'spread_uid', 'spread_time', 'real_name', 'birthday', 'brokerage_price'])->toArray();
        return [
            'token' => $tokenInfo['token'],
            'exp' => $tokenInfo['out'],
            'expires_time' => strtotime('+ ' . $tokenInfo['out'] . 'seconds'),
            'user' => $user
        ];
    }

    public function switchBrokerage(User $user, $brokerage)
    {
        $user->now_money = bcadd($user->now_money, $brokerage, 2);
        $user->brokerage_price = bcsub($user->brokerage_price, $brokerage, 2);
        Db::transaction(function () use ($brokerage, $user) {
            $user->save();
            app()->make(UserBillRepository::class)->incBill($user->uid, 'now_money', 'brokerage', [
                'link_id' => 0,
                'status' => 1,
                'title' => '佣金转入余额',
                'number' => $brokerage,
                'mark' => '成功转入余额' . floatval($brokerage) . '元',
                'balance' => $user->now_money
            ]);
            app()->make(UserBillRepository::class)->decBill($user->uid, 'brokerage', 'now_money', [
                'link_id' => 0,
                'status' => 1,
                'title' => '佣金转入余额',
                'number' => $brokerage,
                'mark' => '成功转入余额' . floatval($brokerage) . '元',
                'balance' => $user->brokerage_price
            ]);
        });
    }

    public function rmLabel($id)
    {
        return $this->search(['label_id' => $id])->update([
            'label_id' => Db::raw('trim(BOTH \',\' FROM replace(CONCAT(\',\',label_id,\',\'),\',' . $id . ',\',\',\'))')
        ]);
    }

    public function changeSpreadForm($id)
    {
        $user = $this->dao->get($id);
        $form = Elm::createForm(Route::buildUrl('systemUserSpreadChange', compact('id'))->build());
        $form->setRule(
            [
                [
                    'type' => 'span',
                    'title' => '用户昵称：',
                    'native' => false,
                    'children' => [$user->nickname]
                ],
                [
                    'type' => 'span',
                    'title' => '上级推荐人 Id：',
                    'native' => false,
                    'children' => [$user->spread ? (string)$user->spread->uid : '无']
                ],
                [
                    'type' => 'span',
                    'title' => '上级推荐人昵称：',
                    'native' => false,
                    'children' => [$user->spread ? (string)$user->spread->nickname : '无']
                ],
                Elm::frameImage('spid', '上级推荐人：', '/' . config('admin.admin_prefix') . '/setting/referrerList?field=spid')->prop('srcKey', 'src')->value($user->spread ? [
                    'src' => $user->spread->avatar,
                    'id' => $user->spread->uid,
                ] : [])->icon('el-icon-camera')->modal(['modal' => false])->width('1000px')->height('600px'),
            ]);
        return $form->setTitle('修改推荐人');
    }

    public function changeSuperiorForm($id)
    {
        $user = $this->dao->get($id);
        $form = Elm::createForm(Route::buildUrl('systemUserSuperiorChange', compact('id'))->build());
        $form->setRule(
            [
                [
                    'type' => 'span',
                    'title' => '用户昵称：',
                    'native' => false,
                    'children' => [$user->nickname]
                ],
                [
                    'type' => 'span',
                    'title' => '上级 Id：',
                    'native' => false,
                    'children' => [$user->superior ? (string)$user->superior->uid : '无']
                ],
                [
                    'type' => 'span',
                    'title' => '上级昵称：',
                    'native' => false,
                    'children' => [$user->superior ? (string)$user->superior->nickname : '无']
                ],
                Elm::frameImage('spid', '上级：', '/' . config('admin.admin_prefix') . '/setting/referrerList?field=spid')->prop('srcKey', 'src')->value($user->superior ? [
                    'src' => $user->superior->avatar,
                    'id' => $user->superior->uid,
                ] : [])->icon('el-icon-camera')->modal(['modal' => false])->width('1000px')->height('600px'),
            ]);
        return $form->setTitle('修改上级');
    }

    public function changeSpread($uid, $spread_id, $admin = 0)
    {
        $spreadLogRepository = app()->make(UserSpreadLogRepository::class);
        $user = $this->dao->get($uid);
        if ($user->spread_uid == $spread_id)
            return;
        $config = systemConfig(['extension_limit', 'extension_limit_day']);
        Db::transaction(function () use ($config, $user, $spreadLogRepository, $spread_id, $admin) {
            $old = $user->spread_uid ?: 0;
            $spreadLogRepository->add($user->uid, $spread_id, $old, $admin);
            $user->spread_time = $spread_id ? date('Y-m-d H:i:s') : null;
            if ($spread_id && $config['extension_limit'] && $config['extension_limit_day']) {
                $user->spread_limit = date('Y-m-d H:i:s', strtotime('+ ' . $config['extension_limit_day'] . ' day'));
            } else {
                $user->spread_limit = null;
            }
            $user->spread_uid = $spread_id;
            if ($spread_id) {
                $this->dao->incSpreadCount($spread_id);
            }
            if ($old) {
                $this->dao->decSpreadCount($old);
            }
            $user->save();
        });
    }

    public function changeSuperior($uid, $superior_id, $admin = 0)
    {
        $superiorLogRepository = app()->make(UserSuperiorLogRepository::class);
        $user = $this->dao->get($uid);
        if ($user->superior_uid == $superior_id)
            return;
        $config = systemConfig(['extension_limit', 'extension_limit_day']);
        Db::transaction(function () use ($config, $user, $superiorLogRepository, $superior_id, $admin) {
            $old = $user->superior_uid ?: 0;
            $superiorLogRepository->add($user->uid, $superior_id, $old, $admin);
            $user->superior_time = $superior_id ? date('Y-m-d H:i:s') : null;

            $user->superior_uid = $superior_id;
            if ($superior_id) {
                $this->dao->incSuperiorCount($superior_id);
            }
            if ($old) {
                $this->dao->decSuperiorCount($old);
            }
            $user->save();
        });
    }

    public function changeProxy($uid, $proxy, $admin = 0)
    {
        $superiorLogRepository = app()->make(UserSuperiorLogRepository::class);
        $user = $this->dao->get($uid);
        
        // 解析proxy JSON字符串
        $proxyData = json_decode($proxy, true);
        if (!empty($proxyData)) {
            // 提取所有代理ID
            $proxyIds = array_column($proxyData, 'id');
            
            // 检查所有代理的lecturer绑定状态
            foreach ($proxyIds as $proxyId) {
                // 不能选择自己
                if ($proxyId == $uid) {
                    throw new ValidateException('不能选择自己');
                }
                /*$otherUser = $this->dao->get($proxyId);
                if ($otherUser && $otherUser->lecturer) {
                    throw new ValidateException('代理：' . $otherUser['nickname'] . '已绑定讲师');
                }*/
            }
            
            // 将所有ID转换为逗号分隔的字符串
            $proxyString = implode(',', $proxyIds);
        }
        
        Db::transaction(function () use ($user, $superiorLogRepository, $proxyString, $proxyIds) {
            $old = $user->proxy ?: 0;

            $user->proxy_time = !empty($proxyString) ? date('Y-m-d H:i:s') : null;
            $user->proxy = $proxyString ?? '0';
            
            $user->save();

            // 给所有代理同步讲师id
            if (!empty($proxyIds)) {
                foreach ($proxyIds as $proxyId) {
                    $otherUser = $this->dao->get($proxyId);
                    if ($otherUser) {
                        $otherUser->lecturer = $user->uid;
                        $otherUser->save();
                    }
                }
            }
        });
    }

    public function changeProxyForm($id)
    {
        $user = $this->dao->get($id);
        $proxyDataUid = [];
        $proxyDataNickname = [];
        
        if(!empty($user->proxy)){
            // 获取所有当前用户的代理信息
            $proxyIds = explode(',', $user->proxy);
            $proxyInfo=$this->dao->getSearch([])->whereIn('uid', $proxyIds)->select()->toArray();
            // 构建代理信息数组
            foreach ($proxyInfo as $proxy) {
                $proxyDataUid[] = [
                    'type' => 'span',
                    'title' => '代理商 Id：',
                    'native' => false,
                    'children' => [$proxy['uid']? (string)$proxy['uid'] : '无']
                ];
                $proxyDataNickname[] = [
                    'type' =>'span',
                    'title' => '代理商昵称：',
                    'native' => false,
                    'children' => [$proxy['nickname']? (string)$proxy['nickname'] : '无']
                ];
            }
        }

        $form = Elm::createForm(Route::buildUrl('systemUserProxy', compact('id'))->build());
        $form->setRule(
            [
                [
                    'type' => 'span',
                    'title' => '用户昵称：',
                    'native' => false,
                    'children' => [$user->nickname]
                ],
                $proxyDataUid,
                $proxyDataNickname,
                Elm::frameImages('spid', '代理：', '/' . config('admin.admin_prefix') . '/setting/referrerLists?field=spid')->prop('srcKey', 'src')->value($user->superior ? [
                    'src' => $user->superior->avatar,
                    'id' => $user->superior->uid,
                ] : [])->icon('el-icon-camera')->modal(['modal' => false])->width('1000px')->height('600px')
            ]);
        return $form->setTitle('修改上级');
    }

    public function syncSpreadStatus()
    {
        if (systemConfig('extension_limit')) {
            $this->dao->syncSpreadStatus();
        }
    }

    /**
     * TODO 积分增加
     * @param int $uid
     * @param int $number
     * @param $title
     * @param $type
     * @param $data
     * @author Qinii
     * @day 6/9/21
     */
    public function incIntegral(int $uid, int $number, $title, $type, $data)
    {
        Db::transaction(function () use ($uid, $number, $title, $type, $data) {

            $user = $this->dao->get($uid);
            $user->integral = $user->integral + $number;
            $user->save();

            app()->make(UserBillRepository::class)
                ->incBill($uid, 'integral', $type,
                    [
                        'link_id' => 0,
                        'status' => 1,
                        'title' => $title,
                        'number' => $data['number'],
                        'mark' => $data['mark'],
                        'balance' => $data['balance'],
                    ]);
        });
    }


    public function memberForm(int $id, int $type)
    {
        if ($type) {
            $form = Elm::createForm(Route::buildUrl('systemUserMemberSave', ['id' => $id])->build());
            $field = 'member_level';
        } else {
            $form = Elm::createForm(Route::buildUrl('systemUserSpreadSave', ['id' => $id])->build());
            $field = 'brokerage_level';
        }

        $data = $this->dao->get($id);
        if (!$data) throw new ValidateException('数据不存在');
        if (!$type && !$data['is_promoter']) throw new ValidateException('用户不是分销员');
        $rules = [
            Elm::select($field, '级别：', $data->$field)->options(function () use ($type) {
                $options = app()->make(UserBrokerageRepository::class)->options(['type' => $type])->toArray();
                return $options;
            })->placeholder('请选择级别'),
        ];
        $form->setRule($rules);
        return $form->setTitle($type ? '编辑会员等级' : '编辑分销等级');
    }

    public function updateLevel(int $id, array $data, int $type)
    {
        $make = app()->make(UserBrokerageRepository::class);
        $user = $this->dao->get($id);
        $field = $type ? 'member_level' : 'brokerage_level';
        if ($data[$field] == $user->$field) return true;
        $has = $make->fieldExists('brokerage_level', $data[$field], null, $type);
        if (!$has) throw new ValidateException('等级不存在');
        Db::transaction(function () use ($id, $data, $field, $user, $type) {
            $user->$field = $data[$field];
            if ($type) {
                app()->make(UserBillRepository::class)->decBill($user->uid, 'sys_members', 'platform_clearing', [
                    'number' => $user->member_value,
                    'title' => '平台修改等级',
                    'balance' => 0,
                    'status' => 0,
                    'mark' => '平台修改等级清除成长值' . ':' . $user->member_value,
                ]);
                $user->member_value = 0;
            }
            $user->save();

            if ($type == 0) app()->make(UserBillRepository::class)->search(['category' => 'sys_brokerage'])->where('uid', $id)->delete();

        });

    }

    public function cancel(User $user)
    {
        Db::transaction(function () use ($user) {
            $uid = $user->uid;
            $name = '已注销用户' . substr(uniqid(true, true), -6);
            if ($user->wechat) {
                $user->wechat->save([
                    'unionid' => '',
                    'openid' => '',
                    'routine_openid' => '',
                    'nickname' => $name,
                    'headimgurl' => '',
                    'city' => '',
                    'province' => '',
                    'country' => '',
                ]);
            }
            $user->save([
                'account' => '',
                'real_name' => '',
                'nickname' => $name,
                'avatar' => '',
                'phone' => '',
                'address' => '',
                'card_id' => '',
                'main_uid' => 0,
                'label_id' => '',
                'group_id' => 0,
                'spread_uid' => 0,
                'status' => 0,
                'is_promoter' => 0,
                'wechat_user_id' => 0,
                'cancel_time' => date('Y-m-d H:i:s')
            ]);
            $this->getSearch([])->where('main_uid', $uid)->update(['main_uid' => 0]);
            app()->make(UserAddressRepository::class)->getSearch([])->where('uid', $uid)->delete();
            app()->make(UserMerchantRepository::class)->getSearch([])->where('uid', $uid)->delete();
            app()->make(UserReceiptRepository::class)->getSearch([])->where('uid', $uid)->delete();
            app()->make(StoreServiceRepository::class)->getSearch([])->where('uid', $uid)->update(['uid' => 0, 'status' => 0, 'is_open' => 0]);
            $this->getSearch([])->where('spread_uid', $uid)->update(['spread_uid' => 0]);
            $this->delBrokerageTop($uid);
            //TODO 推广人月榜
            Cache::zrem('s_top_' . date('Y-m'), $uid);
            //TODO 推广人周榜
            Cache::zrem('s_top_' . monday(), $uid);
            app()->make(CommunityRepository::class)->destoryByUid($uid);
        });
    }

    /**
     * TODO 删除排行榜中的个人排行
     * @param $uid
     * @author Qinii
     * @day 2022/10/18
     */
    public function delBrokerageTop($uid)
    {
        $moneyKey = 'b_top_' . date('Y-m');
        $weekKey = 'b_top_' . monday();
        Cache::zrem($weekKey, $uid);
        Cache::zrem($moneyKey, $uid);
    }

    public function svipForm(int $id)
    {
        $formData = $this->dao->get($id);
        if (!$formData) throw new ValidateException('数据不存在');
        $form = Elm::createForm(Route::buildUrl('systemUserSvipUpdate', ['id' => $id])->build());
        $rule = [
            Elm::switches('is_svip', '付费会员：', $formData->is_svip > 0 ? 1 : 0)->activeValue(1)->inactiveValue(0)->inactiveText('关')->activeText('开'),
        ];
        if ($formData->is_svip == 3) {
            $rule[] = Elm::input('is_svip_type', '会员类型：', '永久会员')->disabled(true)->appendRule('suffix', [
                'type' => 'div',
                'style' => ['color' => '#999999'],
                'domProps' => [
                    'innerHTML' => '永久会员，若关闭后再次开启将不再是永久会员，请谨慎操作',
                ]
            ])->placeholder('请输入会员类型');
        } else {
            $rule[] = Elm::radio('type', '修改类型：', 1)->options([
                ['label' => '增加', 'value' => 1],
                ['label' => '减少', 'value' => 0],
            ])->requiredNum();
            $rule[] = Elm::number('add_time', '会员期限（天）：')->required()->min(1);
            $rule[] = Elm::input('end_time', '当前有效期期限：', $formData->is_svip > 0 ? $formData->svip_endtime : 0)->placeholder('请输入当前有效期期限')->disabled(true);
        }
        $form->setRule($rule);
        return $form->setTitle('编辑付费会员期限');
    }

    /**
     * TODO 设置付费会员
     * @param $id
     * @param $data
     * @author Qinii
     * @day 2022/11/22
     */
    public function svipUpdate($id, $data, $adminId)
    {
        $user = app()->make(UserRepository::class)->get($id);
        if (!$user) throw new ValidateException('用户不存在');
        if ($user['is_svip'] < 1 && ($data['is_svip'] == 0 || !$data['type']))
            throw new ValidateException('该用户还不是付费会员');
        if ($user['is_svip'] == 3 && $data['is_svip'] == 1)
            throw new ValidateException('该用户已是永久付费会员');
        if ($data['is_svip']) {
            $day = ($data['type'] == 1 ? '+ ' : '- ') . (int)$data['add_time'];
            $endtime = ($user['svip_endtime'] && $user['is_svip'] != 0) ? $user['svip_endtime'] : date('Y-m-d H:i:s', time());
            $is_svip = 1;
            $svip_endtime = date('Y-m-d H:i:s', strtotime("$endtime  $day day"));
            //结束时间小于当前 就关闭付费会员
            if (strtotime($svip_endtime) <= time()) {
                $is_svip = 0;
            }
        } else {
            $is_svip = 0;
            $svip_endtime = date('Y-m-d H:i:s', time());
        }
        $make = app()->make(UserOrderRepository::class);
        $res = [
            'title' => $data['is_svip'] == 0 ? '平台取消会员资格' : ($data['type'] ? '平台赠送' : '平台扣除'),
            'link_id' => 0,
            'order_sn' => app()->make(StoreOrderRepository::class)->getNewOrderId(StoreOrderRepository::TYPE_SN_USER_ORDER),
            'pay_price' => 0,
            'order_info' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'uid' => $id,
            'order_type' => UserOrderRepository::TYPE_SVIP . $is_svip,
            'pay_type' => 'sys',
            'status' => 1,
            'pay_time' => date('Y-m-d H:i:s', time()),
            'admin_id' => $adminId,
            'end_time' => $svip_endtime,
            'other' => $user->is_svip == -1 ? 'first' : '',
        ];

        Db::transaction(function () use ($user, $res, $is_svip, $svip_endtime, $make) {
            $make->create($res);
            $user->is_svip = $is_svip;
            $user->svip_endtime = $svip_endtime;
            $user->save();
        });
    }

    public function updateBaseInfo($data, $user)
    {
        Db::transaction(function () use ($data, $user) {
            $user->save(array_filter([
                'nickname' => $data['nickname'] ?? '',
                'avatar' => $data['avatar'] ?? '',
            ]));
            if (isset($user->wechat)) {
                $user->wechat->save(array_filter([
                    'nickname' => $data['nickname'] ?? '',
                    'headimgurl' => $data['avatar'] ?? '',
                ]));
            }
        });
    }

    /**
     * 获取默认删除用户信息
     * @param array $user_info
     * @param array $append_info
     * @return array
     *
     * @date 2023/09/07
     * @author yyw
     */
    public function getDelUserInfo(array $user_info = [], array $append_info = [])
    {
        return [
                'nickname' => $user_info['nickname'] ?? '用户已被删除',
                'phone' => $user_info['phone'] ?? '00000000000',
                'uid' => $user_info['uid'] ?? '-1'
            ] + $append_info;
    }

    /**
     * 获取用户等级下拉列表
     * @return array
     */
    public function getMemberLevelSelectList()
    {
        return app()->make(UserBrokerageRepository::class)->options(['type' => 1])->toArray();
    }

    /**
     * 搜索社区用户列表
     * @param array $where
     * @param int $page
     * @param int $limit
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     *
     * @date 2023/10/21
     * @author yyw
     */
    public function getCommunityUserList(array $where = [], int $page = 1, int $limit = 10)
    {
        $query = $this->dao->search($where)->field('uid,nickname,real_name,phone,count_start,count_fans,count_content');

        $count = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        return compact('count', 'list');
    }

    /**
     * 更新用户内容数
     * @return bool
     * @throws DbException
     *
     * @date 2023/10/21
     * @author yyw
     */
    public function syncUserCountContent()
    {
        $communityRepository = app()->make(CommunityRepository::class);
        $user_count = $communityRepository->getCountByGroupUid();

        foreach ($user_count as $count) {
            $this->dao->update($count['uid'], ['count_content' => $count['count']]);
        }
        return true;
    }

    public function userOrderDetail($uid)
    {
        $info = $this->dao->userOrderDetail($uid);
        // 添加用户扩展信息
        $info['extend_info'] = app()->make(UserFieldsRepository::class)->info((int)$uid, false)['extend_info'];
        return $info;
    }

    /**
     *
     * @param int $uid
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function getFields()
    {
        return app()->make(UserInfoRepository::class)->getSearch(['is_used' => 1])->order(['sort', 'create_time' => 'ASC'])->select()->toArray();
    }
}
