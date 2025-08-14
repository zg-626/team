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


namespace app\common\dao\user;


use app\common\dao\BaseDao;
use app\common\model\user\UserRealAuth;

/**
 * 用户实名认证DAO
 * Class UserRealAuthDao
 * @package app\common\dao\user
 */
class UserRealAuthDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function getModel(): string
    {
        return UserRealAuth::class;
    }

    /**
     * 根据用户ID获取实名认证信息
     * @param int $uid
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getAuthByUid(int $uid)
    {
        return $this->getModel()::getDB()->where('uid', $uid)->find();
    }

    /**
     * 获取用户认证状态
     * @param int $uid
     * @return int
     */
    public function getAuthStatus(int $uid)
    {
        return $this->getModel()::getDB()->where('uid', $uid)->value('status') ?: 0;
    }

    // 根据身份证号获取认证信息
    public function getAuthByCard(string $idCard)
    {
        return $this->getModel()::getDB()->where('id_card', $idCard)->find();
    }
}