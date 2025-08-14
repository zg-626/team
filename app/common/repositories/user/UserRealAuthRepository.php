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


use app\common\dao\user\UserRealAuthDao;
use app\common\repositories\BaseRepository;
use crmeb\services\RealNameAuthService;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 用户实名认证仓库
 * Class UserRealAuthRepository
 * @package app\common\repositories\user
 */
class UserRealAuthRepository extends BaseRepository
{
    /**
     * UserRealAuthRepository constructor.
     * @param UserRealAuthDao $dao
     */
    public function __construct(UserRealAuthDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取用户认证状态
     * @param int $uid
     * @return int
     */
    public function getAuthStatus(int $uid)
    {
        return $this->dao->getAuthStatus($uid);
    }

    /**
     * 获取用户认证信息
     * @param int $uid
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getUserAuth(int $uid)
    {
        $auth = $this->dao->getAuthByUid($uid);
        if (!$auth) {
            return [
                'real_name' => '',
                'id_card' => '',
                'status' => 0,
                'auth_time' => '',
                'message' => ''
            ];
        }
        
        return [
            'real_name' => $auth->real_name,
            'id_card' => $this->hideIdCard($auth->id_card),
            'status' => $auth->status,
            'auth_time' => $auth->auth_time ? date('Y-m-d H:i:s', $auth->auth_time) : '',
            'message' => $auth->message
        ];
    }

    /**
     * 隐藏身份证号中间部分
     * @param string $idCard
     * @return string
     */
    private function hideIdCard(string $idCard)
    {
        if (strlen($idCard) >= 15) {
            return substr($idCard, 0, 6) . '********' . substr($idCard, -4);
        }
        return $idCard;
    }

    /**
     * 提交实名认证
     * @param int $uid 用户ID
     * @param string $realName 真实姓名
     * @param string $idCard 身份证号
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function applyAuth(int $uid, string $realName, string $idCard)
    {
        // 验证身份证号格式
        if (!$this->checkIdCard($idCard)) {
            throw new ValidateException('身份证号格式不正确');
        }
        
        // 检查是否已认证
        $auth = $this->dao->getAuthByUid($uid);
        if ($auth && $auth->status == 1) {
            throw new ValidateException('您已完成实名认证，无需重复认证');
        }
        
        // 调用阿里云实名认证服务
        $result = RealNameAuthService::verify($realName, $idCard);

        // 开启事务
        Db::startTrans();
        try {
            $data = [
                'uid' => $uid,
                'real_name' => $realName,
                'id_card' => $idCard,
                'status' => $result['status'] ? 1 : -1,
                'auth_time' => time(),
                'message' => $result['message']
            ];
            
            if ($auth) {
                // 检查是否已绑定其他用户
                $existingAuth = $this->dao->getAuthByCard($idCard);
                if ($existingAuth && $existingAuth->uid != $uid) {
                    throw new ValidateException('该身份证号已绑定其他用户');
                }
                // 更新认证信息
                $this->dao->update($auth->real_auth_id, $data);
            } else {
                // 新增认证信息
                $this->dao->create($data);
            }
            
            // 提交事务
            Db::commit();
            return ['status' => $result['status'], 'message' => $result['message']];
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            throw new ValidateException($e->getMessage());
        }
    }

    /**
     * 验证身份证号格式
     * @param string $idCard
     * @return bool
     */
    private function checkIdCard(string $idCard)
    {
        $pattern = '/(^\d{15}$)|(^\d{18}$)|(^\d{17}(\d|X|x)$)/';
        return preg_match($pattern, $idCard) ? true : false;
    }
}