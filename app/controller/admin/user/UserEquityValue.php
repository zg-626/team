<?php

namespace app\controller\admin\user;

use app\common\repositories\user\UserBillRepository;
use app\common\repositories\user\UserRepository;
use crmeb\basic\BaseController;
use think\App;

/**
 * 用户权益值管理控制器
 * Class UserEquityValue
 * @package app\controller\admin\user
 */
class UserEquityValue extends BaseController
{
    /**
     * @var UserRepository
     */
    protected $repository;

    /**
     * @var UserBillRepository
     */
    protected $userBillRepository;

    /**
     * UserEquityValue constructor.
     * @param App $app
     * @param UserRepository $repository
     * @param UserBillRepository $userBillRepository
     */
    public function __construct(App $app, UserRepository $repository, UserBillRepository $userBillRepository)
    {
        parent::__construct($app);
        $this->repository = $repository;
        $this->userBillRepository = $userBillRepository;
    }

    /**
     * 获取权益值日志列表
     * @return mixed
     */
    public function getList()
    {
        [$page, $limit] = $this->getPage();
        $where = $this->request->params([
            ['uid', ''],
            ['keyword', ''],
            ['date', ''],
            ['type', ''],
        ]);

        return app('json')->success($this->userBillRepository->getList('equity_value', $where, $page, $limit));
    }

    /**
     * 修改用户权益值
     * @param int $uid
     * @return mixed
     */
    public function changeEquityValue($uid)
    {
        $data = $this->request->params([
            ['equity_value', 0],
            ['type', 1], // 1增加 0减少
            ['mark', ''],
        ]);

        if (!$data['equity_value'] || $data['equity_value'] <= 0) {
            return app('json')->fail('权益值必须大于0');
        }

        if (!$data['mark']) {
            return app('json')->fail('请填写备注信息');
        }

        $user = $this->repository->get($uid);
        if (!$user) {
            return app('json')->fail('用户不存在');
        }

        $equity_value = $data['type'] ? $data['equity_value'] : -$data['equity_value'];
        $this->repository->changeEquityValue($uid, $equity_value, 'system', $data['mark']);

        return app('json')->success('操作成功');
    }

    /**
     * 获取用户权益值统计
     * @return mixed
     */
    public function getStatistics()
    {
        $where = $this->request->params([
            ['date', ''],
        ]);

        $data = [
            'total_equity_value' => $this->repository->sum('equity_value'),
            'today_add' => $this->userBillRepository->todayEquityValue('inc'),
            'today_sub' => $this->userBillRepository->todayEquityValue('dec'),
            'month_add' => $this->userBillRepository->monthEquityValue('inc'),
            'month_sub' => $this->userBillRepository->monthEquityValue('dec'),
        ];

        return app('json')->success($data);
    }

    /**
     * 批量增加用户权益值
     * @return mixed
     */
    public function batchAddEquityValue()
    {
        $data = $this->request->params([
            ['uids', []], // 用户ID数组
            ['equity_value', 0],
            ['mark', ''],
        ]);

        if (!$data['uids'] || !is_array($data['uids'])) {
            return app('json')->fail('请选择用户');
        }

        if (!$data['equity_value'] || $data['equity_value'] <= 0) {
            return app('json')->fail('权益值必须大于0');
        }

        if (!$data['mark']) {
            return app('json')->fail('请填写备注信息');
        }

        $successCount = 0;
        foreach ($data['uids'] as $uid) {
            try {
                $user = $this->repository->get($uid);
                if ($user) {
                    $this->repository->changeEquityValue($uid, $data['equity_value'], 'system', $data['mark']);
                    $successCount++;
                }
            } catch (\Exception $e) {
                // 记录错误但继续处理其他用户
                continue;
            }
        }

        return app('json')->success("成功为 {$successCount} 个用户增加权益值");
    }

    /**
     * 获取用户权益值详情
     * @param int $uid
     * @return mixed
     */
    public function getUserEquityDetail($uid)
    {
        $user = $this->repository->get($uid);
        if (!$user) {
            return app('json')->fail('用户不存在');
        }

        [$page, $limit] = $this->getPage();
        $where = ['uid' => $uid];
        $bills = $this->userBillRepository->getList('equity_value', $where, $page, $limit);

        $data = [
            'user_info' => [
                'uid' => $user->uid,
                'nickname' => $user->nickname,
                'phone' => $user->phone,
                'equity_value' => $user->equity_value,
            ],
            'bills' => $bills
        ];

        return app('json')->success($data);
    }
}