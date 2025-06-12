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


namespace app\controller\api;


use app\common\dao\store\order\StoreOrderDao;
use app\common\dao\store\order\StoreOrderOfflineDao;
use app\common\model\user\UserExtract;
use app\common\repositories\alipay\AlipayUserRepository;
use app\common\repositories\store\order\StoreGroupOrderRepository;
use app\common\repositories\store\order\StoreOrderRepository;
use app\common\repositories\store\order\StoreRefundOrderRepository;
use app\common\repositories\system\merchant\MerchantAdminRepository;
use app\common\repositories\system\merchant\MerchantRepository;
use app\common\repositories\system\notice\SystemNoticeConfigRepository;
use app\common\repositories\user\UserExtractRepository;
use app\common\repositories\user\UserInfoRepository;
use app\common\repositories\user\UserOrderRepository;
use app\common\repositories\user\UserRechargeRepository;
use app\common\repositories\user\UserRepository;
use app\common\repositories\user\UserSignRepository;
use app\common\repositories\wechat\RoutineQrcodeRepository;
use app\common\repositories\wechat\WechatUserRepository;
use app\validate\api\ChangePasswordValidate;
use app\validate\api\FriendsValidate;
use app\validate\api\UserAuthValidate;
use crmeb\basic\BaseController;
use crmeb\services\MiniProgramService;
use crmeb\services\SmsService;
use crmeb\services\WechatService;
use crmeb\services\WechatTemplateMessageService;
use Exception;
use Firebase\JWT\JWT;
use Gregwar\Captcha\CaptchaBuilder;
use Gregwar\Captcha\PhraseBuilder;
use Overtrue\Socialite\AccessToken;
use Symfony\Component\HttpFoundation\Request;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\exception\ValidateException;
use think\facade\Cache;
use think\facade\Log;
use think\facade\Queue;
use crmeb\jobs\SendSmsJob;
use Alipay\EasySDK\Kernel\Factory;
use Alipay\EasySDK\Kernel\Config;

/**
 * Class Auth
 * @package app\controller\api
 * @author xaboy
 * @day 2020-05-06
 */
class Auth extends BaseController
{
    public function test()
    {
//        $data = [
//            'tempId' => '',
//            'id' => '',
//        ];
//        Queue::push(SendSmsJob::class,$data);
//        $status = app()->make(SystemNoticeConfigRepository::class)->getNoticeStatusByConstKey($data['tempId']);
//        if ($status['notice_sms'] == 1) {
//            SmsService::sendMessage($data);
//        }
//        if ($status['notice_wechat'] == 1) {
//            app()->make(WechatTemplateMessageService::class)->sendTemplate($data);
//        }
//        if ($status['notice_routine'] == 1) {
//            app()->make(WechatTemplateMessageService::class)->subscribeSendTemplate($data);
//        }
    }

    /**
     * @param UserRepository $repository
     * @return mixed
     * @throws DbException
     * @author xaboy
     * @day 2020/6/1
     */
    public function login(UserRepository $repository)
    {
        $account = $this->request->param('account');
        $auth_token = $this->request->param('auth_token');
        if (Cache::get('api_login_freeze_' . $account))
            return app('json')->fail('账号或密码错误次数太多，请稍后在尝试');
        if (!$account)
            return app('json')->fail('请输入账号');
        $user = $repository->accountByUser($this->request->param('account'));
//        if($auth_token && $user){
//            return app('json')->fail('用户已存在');
//        }
        if (!$user) $this->loginFailure($account);
        if (!password_verify($pwd = (string)$this->request->param('password'), $user['pwd'])) $this->loginFailure($account);
        $auth = $this->parseAuthToken($auth_token);
        if ($auth && !$user['wechat_user_id']) {
            $repository->syncBaseAuth($auth, $user);
        }
        $user = $repository->mainUser($user);
        $pid = $this->request->param('spread', 0);
        $repository->bindSpread($user, intval($pid));

        $tokenInfo = $repository->createToken($user);
        $repository->loginAfter($user);

        return app('json')->success($repository->returnToken($user, $tokenInfo));
    }

    /**
     * TODO 登录尝试次数限制
     * @param $account
     * @param int $number
     * @param int $n
     * @author Qinii
     * @day 7/6/21
     */
    public function loginFailure($account, $number = 5, $n = 3)
    {
        $key = 'api_login_failuree_' . $account;
        $numb = Cache::get($key) ?? 0;
        $numb++;
        if ($numb >= $number) {
            $fail_key = 'api_login_freeze_' . $account;
            Cache::set($fail_key, 1, 15 * 60);
            throw new ValidateException('账号或密码错误次数太多，请稍后在尝试');

        } else {
            Cache::set($key, $numb, 5 * 60);

            $msg = '账号或密码错误';
            $_n = $number - $numb;
            if ($_n <= $n) {
                $msg .= ',还可尝试' . $_n . '次';
            }
            throw new ValidateException($msg);
        }
    }

    private function parseAuthToken($authToken)
    {
        $auth = Cache::get('u_try' . $authToken);
        $auth && Cache::delete('u_try' . $authToken);
        return $auth;
    }

    /**
     * @param UserRepository $repository
     * @return mixed
     * @author xaboy
     * @day 2020/6/1
     */
    public function logout(UserRepository $repository)
    {
        $repository->clearToken($this->request->token());
        return app('json')->success('退出登录');
    }

    /**
     * @return mixed
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author xaboy
     * @day 2020-05-11
     */
    public function auth()
    {
        if (systemConfig('is_phone_login') === '1') {
            return app('json')->fail('请绑定手机号');
        }

        $request = $this->request;
        $oauth = WechatService::create()->getApplication()->oauth;
        $oauth->setRequest(new Request($request->get(), $request->post(), [], [], [], $request->server(), $request->getContent()));
        try {
            $wechatInfo = $oauth->user()->getOriginal();
        } catch (Exception $e) {
            return app('json')->fail('授权失败[001]', ['message' => $e->getMessage()]);
        }
        if (!isset($wechatInfo['nickname'])) {
            return app('json')->fail('授权失败[002]');
        }
        /** @var WechatUserRepository $make */
        $make = app()->make(WechatUserRepository::class);
        $user = $make->syncUser($wechatInfo['openid'], $wechatInfo);
        if (!$user)
            return app('json')->fail('授权失败[003]');
        /** @var UserRepository $make */
        $userRepository = app()->make(UserRepository::class);
        $user[1] = $userRepository->mainUser($user[1]);

        $pid = $this->request->param('spread', 0);
        $userRepository->bindSpread($user[1], intval($pid));

        $tokenInfo = $userRepository->createToken($user[1]);
        $userRepository->loginAfter($user[1]);

        return app('json')->success($userRepository->returnToken($user[1], $tokenInfo));
    }

    /**
     * @return mixed
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author xaboy
     * @day 2020-05-11
     */
    public function mpAuth()
    {
        list($code, $post_cache_key) = $this->request->params([
            'code',
            'cache_key',
        ], true);

        if (systemConfig('is_phone_login') === '1') {
            return app('json')->fail('请绑定手机号');
        }

        $userInfoCong = Cache::get('eb_api_code_' . $code);
        if (!$code && !$userInfoCong)
            return app('json')->fail('授权失败,参数有误');
        $miniProgramService = MiniProgramService::create();
        if ($code && !$userInfoCong) {
            try {
                $userInfoCong = $miniProgramService->getUserInfo($code);
                Cache::set('eb_api_code_' . $code, $userInfoCong, 86400);
            } catch (Exception $e) {
                return app('json')->fail('获取session_key失败，请检查您的配置！', ['line' => $e->getLine(), 'message' => $e->getMessage()]);
            }
        }

        $data = $this->request->params([
            ['spread_spid', 0],
            ['spread_code', ''],
            ['iv', ''],
            ['encryptedData', ''],
        ]);

        try {
            //解密获取用户信息
            $userInfo = $miniProgramService->encryptor($userInfoCong['session_key'], $data['iv'], $data['encryptedData']);
        } catch (Exception $e) {
            if ($e->getCode() == '-41003') return app('json')->fail('获取会话密匙失败');
            throw $e;
        }
        if (!$userInfo) return app('json')->fail('openid获取失败');
        if (!isset($userInfo['openId'])) $userInfo['openId'] = $userInfoCong['openid'] ?? '';
        $userInfo['unionId'] = $userInfoCong['unionid'] ?? $userInfo['unionId'] ?? '';
        if (!$userInfo['openId']) return app('json')->fail('openid获取失败');

        /** @var WechatUserRepository $make */
        $make = app()->make(WechatUserRepository::class);
        $user = $make->syncRoutineUser($userInfo['openId'], $userInfo);
        if (!$user)
            return app('json')->fail('授权失败');
        /** @var UserRepository $make */
        $userRepository = app()->make(UserRepository::class);
        $user[1] = $userRepository->mainUser($user[1]);
        $code = intval($data['spread_code']['id'] ?? $data['spread_code']);
        //获取是否有扫码进小程序
        if ($code && ($info = app()->make(RoutineQrcodeRepository::class)->getRoutineQrcodeFindType($code))) {
            $data['spread_spid'] = $info['third_id'];
        }
        $userRepository->bindSpread($user[1], intval($data['spread_spid']));
        $tokenInfo = $userRepository->createToken($user[1]);
        $userRepository->loginAfter($user[1]);

        return app('json')->success($userRepository->returnToken($user[1], $tokenInfo));
    }

    function getOptions()
    {
        $config = systemConfig(['site_url', 'alipay_app_id', 'alipay_public_key', 'alipay_private_key', 'alipay_open']);
        $options = new Config();
        $options->protocol = 'https';
        $options->gatewayHost = 'openapi.alipay.com';
        $options->signType = 'RSA2';

        $options->appId = $config['alipay_app_id'];
        // 为避免私钥随源码泄露，推荐从文件中读取私钥字符串而不是写入源码中
        $options->merchantPrivateKey = $config['alipay_private_key'];

        //注：如果采用非证书模式，则无需赋值上面的三个证书路径，改为赋值如下的支付宝公钥字符串即可
         $options->alipayPublicKey = $config['alipay_public_key'];

        //可设置异步通知接收服务地址（可选）
        $options->notifyUrl = "";

        return $options;
    }

    public function getCaptcha()
    {
        $codeBuilder = new CaptchaBuilder(null, new PhraseBuilder(4));
        $key = uniqid(microtime(true), true);
        Cache::set('api_captche' . $key, $codeBuilder->getPhrase(), 300);
        $captcha = $codeBuilder->build()->inline();
        return app('json')->success(compact('key', 'captcha'));
    }

    public function verify(UserAuthValidate $validate)
    {
        $data = $this->request->params(['phone', ['type', 'login'], ['captchaType', ''], ['captchaVerification', ''], 'token']);
        //二次验证
        try {
            aj_captcha_check_two($data['captchaType'], $data['captchaVerification']);
        } catch (\Throwable $e) {
            return app('json')->fail($e->getMessage());
        }
        $validate->sceneVerify()->check($data);
        $sms_limit_key = 'sms_limit_' . $data['phone'];
        $limit = Cache::get($sms_limit_key) ? Cache::get($sms_limit_key) : 0;
        $sms_limit = systemConfig('sms_limit');
        if ($sms_limit && $limit > $sms_limit) {
            return app('json')->fail('请求太频繁请稍后再试');
        }
//        if(!env('APP_DEBUG', false)){
        try {
            $sms_code = str_pad(random_int(1, 9999), 4, 0, STR_PAD_LEFT);
            $sms_time = systemConfig('sms_time') ? systemConfig('sms_time') : 30;
            SmsService::create()->send($data['phone'], 'VERIFICATION_CODE', ['code' => $sms_code, 'time' => $sms_time]);
        } catch (Exception $e) {
            return app('json')->fail($e->getMessage());
        }
//        }else{
//            $sms_code =  1234;
//            $sms_time = 5;
//        }
        $sms_key = app()->make(SmsService::class)->sendSmsKey($data['phone'], $data['type']);
        Cache::set($sms_key, $sms_code, $sms_time * 60);
        Cache::set($sms_limit_key, $limit + 1, 60);
        //'短信发送成功'
        return app('json')->success('短信发送成功');
    }

    public function smsLogin(UserAuthValidate $validate, UserRepository $repository)
    {
        $data = $this->request->params(['phone', 'sms_code', 'spread', 'auth_token', ['user_type', 'h5']]);
        $validate->sceneSmslogin()->check($data);
        #TODO 为了测试，验证码白名单
        if (($data['sms_code']) != 1234) {
            $sms_code = app()->make(SmsService::class)->checkSmsCode($data['phone'], $data['sms_code'], 'login');
            if (!$sms_code)
                return app('json')->fail('验证码不正确');
        }
        $user = $repository->accountByUser($data['phone']);
        if (!$user) $user = $repository->getWhere(['phone' => $data['phone']]);
        $auth = $this->parseAuthToken($data['auth_token']);
        //有auth说明是绑定手机号
        if ($auth && $user && $user['wechat_user_id'] && $user['wechat_user_id'] !== $auth['id'])
            return app('json')->fail('该手机号已被绑定');
        if (!$user) $user = $repository->registr($data['phone'], null, $data['user_type']);
        if ($auth && !$user['wechat_user_id']) {
            $repository->syncBaseAuth($auth, $user);
        }
        $user = $repository->mainUser($user);
        $repository->bindSpread($user, intval($data['spread']));
        $tokenInfo = $repository->createToken($user);
        $repository->loginAfter($user);

        return app('json')->success($repository->returnToken($user, $tokenInfo));
    }

    public function changePassword(ChangePasswordValidate $validate, UserRepository $repository)
    {
        $data = $this->request->params(['phone', 'sms_code', 'pwd']);
        $validate->check($data);
        $user = $repository->accountByUser($data['phone']);
        if (!$user) return app('json')->fail('用户不存在');
        $sms_code = app()->make(SmsService::class)->checkSmsCode($data['phone'], $data['sms_code'], 'change_pwd');
        if (!$sms_code)
            return app('json')->fail('验证码不正确');
        $user->pwd = $repository->encodePassword($data['pwd']);
        $user->save();
        return app('json')->success('修改成功');
    }

    /**
     * @param FriendsValidate $validate
     * @param UserRepository $repository
     * @return mixed
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */

    public function findFriends(FriendsValidate $validate, UserRepository $repository)
    {
        $data = $this->request->params(['phone']);
        $validate->check($data);
        $user = $repository->accountByUser($data['phone']);
        if (!$user) return app('json')->fail('用户不存在');
        $user=$user->hidden(['label_id', 'group_id', 'pwd', 'addres', 'card_id', 'last_time', 'last_ip', 'create_time', 'mark', 'status', 'spread_uid', 'spread_time', 'real_name', 'birthday', 'brokerage_price']);

        $arr=[
            'avatar' => $user['avatar'],
            'nickname' => $user['nickname'],
            'phone' => $user['phone'],
            'uid' => $user['uid']
        ];
        return app('json')->success($arr);
    }

    public function spread(UserRepository $userRepository)
    {
        $data = $this->request->params([
            ['spread_spid', 0],
            ['spread_code', null],
        ]);
        if (isset($data['spread_code']['id']) && ($info = app()->make(RoutineQrcodeRepository::class)->getRoutineQrcodeFindType($data['spread_code']['id']))) {
            $data['spread_spid'] = $info['third_id'];
        }
        $userRepository->bindSpread($this->request->userInfo(), intval($data['spread_spid']));
        return app('json')->success();
    }

    /**
     * @return mixed
     * @author xaboy
     * @day 2020/6/1
     */
    public function userInfo()
    {
        $user = $this->request->userInfo()->hidden(['label_id', 'group_id', 'pwd', 'addres', 'card_id', 'last_time', 'last_ip', 'create_time', 'mark', 'status', 'spread_uid', 'spread_time', 'real_name', 'birthday', 'brokerage_price']);
        $user->append(['service','group', 'topService', 'total_collect_product', 'total_collect_store', 'total_coupon', 'total_visit_product', 'total_unread', 'total_recharge', 'lock_integral', 'total_integral','merchant']);
        $data = $user->toArray();
        $data['total_consume'] = $user['pay_price'];
        $data['extension_status'] = systemConfig('extension_status');
        if (systemConfig('member_status'))
            $data['member_icon'] = $this->request->userInfo()->member->brokerage_icon ?? '';
        if ($data['is_svip'] == 3)
            $data['svip_endtime'] = date('Y-m-d H:i:s', strtotime("+100 year"));

        $day = date('Y-m-d', time());
        $key = 'sign_' . $user['uid'] . '_' . $day;
        $data['sign_status'] = false;
        if (Cache::get($key)) {
            $data['sign_status'] = true;
        } else {
            $nu = app()->make(UserSignRepository::class)->getSign($user->uid, $day);
            if ($nu) {
                $data['sign_status'] = true;
                Cache::set($key, true, new \DateTime($day . ' 23:59:59'));
            }
        }
        $data['is_shop'] = 0;
        $merchantRepository = app()->make(MerchantRepository::class);
        if ($merchantRepository->fieldExists('mer_phone', $user['phone'])){
            $data['is_shop'] = 1;
        }
        /*// 线下订单金额统计
        $storeOrderOfflineDao = app()->make(StoreOrderOfflineDao::class);
        $offlineOrderPrice = $storeOrderOfflineDao->getWhere(['uid' => $user->uid, 'paid' => 1])->sum('pay_price');
        // 线上订单金额统计
        $storeOrderDao = app()->make(StoreOrderDao::class);
        $orderPrice = $storeOrderDao->getWhere(['uid' => $user->uid, 'paid' => 1, 'status' => [0,1, 2, 3]])->sum('pay_price')+$offlineOrderPrice;
        $user['pay_price']=$orderPrice;*/
        return app('json')->success($data);
    }

    /**
     * @return mixed
     * @author xaboy
     * @day 2020/6/1
     */
    public function getUserInfo()
    {
        $user = $this->request->userInfo()->hidden(['label_id', 'pwd', 'addres', 'card_id', 'last_time', 'last_ip', 'create_time', 'mark', 'status', 'spread_uid', 'spread_time', 'real_name', 'birthday','wechat_user_id','brokerage_level','is_svip','user_type','main_uid']);
        $user->append(['group','total_integral']);
        // 普通用户不能查看
        if($user->group_id === 1) {
            return app('json')->fail('无权查看');
        }
        $data = $user->toArray();
        /** @var MerchantRepository $merchantRepository */
        $merchantRepository = app()->make(MerchantRepository::class);
        //$data['merchant_count'] = $merchantRepository->getMerchantCount($user->uid);
        $data['merchant_count'] = (new \app\common\model\system\merchant\Merchant)->where(['salesman_id' => $user->uid])->count();;
        // 累计提现金额
        $data['total_extract'] = UserExtract::where(['uid' => $user->uid, 'status' => 1])->sum('extract_price');
        return app('json')->success($data);
    }

    /**
     * @return mixed
     * @author xaboy
     * @day 2020/6/1
     */
    public function actingInfo()
    {
        $user = $this->request->userInfo()->hidden(['label_id', 'group_id', 'pwd', 'addres', 'card_id', 'last_time', 'last_ip', 'create_time', 'mark', 'status', 'spread_uid', 'spread_time', 'real_name', 'birthday', 'brokerage_price']);
        $user->append(['service', 'topService', 'total_collect_product', 'total_collect_store', 'total_coupon', 'total_visit_product', 'total_unread', 'total_recharge', 'lock_integral', 'total_integral']);
        $data = $user->toArray();
        $data['total_consume'] = $user['pay_price'];
        $data['extension_status'] = systemConfig('extension_status');
        if (systemConfig('member_status'))
            $data['member_icon'] = $this->request->userInfo()->member->brokerage_icon ?? '';
        if ($data['is_svip'] == 3)
            $data['svip_endtime'] = date('Y-m-d H:i:s', strtotime("+100 year"));

        $day = date('Y-m-d', time());
        $key = 'sign_' . $user['uid'] . '_' . $day;
        $data['sign_status'] = false;
        if (Cache::get($key)) {
            $data['sign_status'] = true;
        } else {
            $nu = app()->make(UserSignRepository::class)->getSign($user->uid, $day);
            if ($nu) {
                $data['sign_status'] = true;
                Cache::set($key, true, new \DateTime($day . ' 23:59:59'));
            }
        }
        return app('json')->success($data);
    }

    /**
     * TODO 注册账号
     * @param UserAuthValidate $validate
     * @param UserRepository $repository
     * @return \think\response\Json
     * @author Qinii
     * @day 5/27/21
     */
    public function register(UserAuthValidate $validate, UserRepository $repository)
    {
        $data = $this->request->params(['phone', 'sms_code', 'spread', 'pwd', 'auth_token', ['user_type', 'h5']]);
        $validate->check($data);
        #TODO 为了测试，验证码白名单
        if (($data['sms_code']) != 1234) {
            $sms_code = app()->make(SmsService::class)->checkSmsCode($data['phone'], $data['sms_code'], 'login');
            if (!$sms_code)
                return app('json')->fail('验证码不正确');
        }

        $user = $repository->accountByUser($data['phone']);
        if ($user) return app('json')->fail('用户已存在');
        $auth = $this->parseAuthToken($data['auth_token']);
        $user = $repository->registr($data['phone'], $data['pwd'], $data['user_type']);
        if ($auth) {
            $repository->syncBaseAuth($auth, $user);
        }
        $user = $repository->mainUser($user);
        $repository->bindSpread($user, intval($data['spread']));

        $tokenInfo = $repository->createToken($user);
        $repository->loginAfter($user);

        // 更新用户分组
        //$repository->updateUserGroup($user);

        return app('json')->success($repository->returnToken($user, $tokenInfo));
    }

    /**
     * @return \think\response\Json
     * @author Qinii
     * @day 2023/11/9
     */
    public function authLogin()
    {
        $auth = $this->request->param('auth');
        $users = $this->authInfo($auth, systemConfig('is_phone_login') !== '1');
        if (!$users)
            return app('json')->fail('授权失败');
        $authInfo = $users[0];
        $userRepository = app()->make(UserRepository::class);
        $user = $users[1] ?? $userRepository->wechatUserIdBytUser($authInfo['wechat_user_id']);
        $code = (int)($auth['auth']['spread_code']['id'] ?? $auth['auth']['spread_code'] ?? '');
        //获取是否有扫码进小程序
        if ($code && ($info = app()->make(RoutineQrcodeRepository::class)->getRoutineQrcodeFindType($code))) {
            $auth['auth']['spread'] = $info['third_id'];
        }
        if (!$user) {
            $uni = uniqid(true, false) . random_int(1, 100000000);
            $key = 'U' . md5(time() . $uni);
            Cache::set('u_try' . $key, ['id' => $authInfo['wechat_user_id'], 'type' => $authInfo['user_type'], 'spread' => $auth['auth']['spread'] ?? 0], 3600);
            $wechat_phone_switch = systemConfig('wechat_phone_switch');
            return app('json')->status(201, compact('key', 'wechat_phone_switch'));
        }

        if ($auth['auth']['spread'] ?? 0) {
            $userRepository->bindSpread($user, (int)($auth['auth']['spread']));
        }
        $tokenInfo = $userRepository->createToken($user);
        $userRepository->loginAfter($user);
        return app('json')->status(200, $userRepository->returnToken($user, $tokenInfo));
    }

    // 支付宝授权登录
    public function alipayAuth()
    {
        $auth = $this->request->param('auth');
        $users = $this->authInfo($auth, systemConfig('is_phone_login') !== '1');
        if (!$users)
            return app('json')->fail('授权失败');


    }

    private function authInfo($auth, $createUser = false)
    {
        if (!in_array($auth['type'] ?? '', ['wechat', 'alipay','routine', 'apple', 'app_wechat']) || !isset($auth['auth']))
            throw new ValidateException('授权信息类型有误');
        $data = $auth['auth'];
        if ($auth['type'] === 'routine') {
            $code = $data['code'] ?? '';
            $userInfoCong = Cache::get('eb_api_code_' . $code);
            if (!$code && !$userInfoCong)
                throw new ValidateException('授权失败,参数有误');
            $miniProgramService = MiniProgramService::create();
            if ($code && !$userInfoCong) {
                try {
                    $userInfoCong = $miniProgramService->getUserInfo($code);
                    Cache::set('eb_api_code_' . $code, $userInfoCong, 86400);
                } catch (Exception $e) {
                    throw new ValidateException('获取session_key失败，请检查您的配置！' . $e->getMessage());
                }
            }
//            try {
//                //解密获取用户信息
//                $userInfo = $miniProgramService->encryptor($userInfoCong['session_key'], $data['iv'], $data['encryptedData']);
//            } catch (Exception $e) {
//                if ($e->getCode() == '-41003') throw new ValidateException('获取会话密匙失败'.$e->getMessage());
//                throw $e;
//            }
            $userInfo = [];
//            if (!$userInfo) throw new ValidateException('openid获取失败');
            if (!isset($userInfo['openId'])) $userInfo['openId'] = $userInfoCong['openid'] ?? '';
            $userInfo['unionId'] = $userInfoCong['unionid'] ?? $userInfo['unionId'] ?? '';
            if (!$userInfo['openId']) throw new ValidateException('openid获取失败');

            /** @var WechatUserRepository $make */
            $make = app()->make(WechatUserRepository::class);
            $user = $make->syncRoutineUser($userInfo['openId'], $userInfo, $createUser);
            if (!$user)
                throw new ValidateException('授权失败');
            return $user;
        } else if ($auth['type'] === 'wechat') {
            $request = $this->request;
            $oauth = WechatService::create()->getApplication()->oauth;
            $oauth->setRequest(new Request($data, $data, [], [], [], $request->server(), $request->getContent()));
            try {
                $wechatInfo = $oauth->user()->getOriginal();
            } catch (Exception $e) {
                throw new ValidateException('授权失败[001]');
            }
            if (!isset($wechatInfo['nickname'])) {
                throw new ValidateException('授权失败[002]');
            }
            /** @var WechatUserRepository $make */
            $make = app()->make(WechatUserRepository::class);

            $user = $make->syncUser($wechatInfo['openid'], $wechatInfo, false, $createUser);
            if (!$user)
                throw new ValidateException('授权失败[003]');
            return $user;
        } else if ($auth['type'] === 'app_wechat') {
            $oauth = WechatService::create()->getApplication()->oauth;
            try {
                $wechatInfo = $oauth->user(new AccessToken(['access_token' => $data['code'], 'openid' => $data['openid']]))->getOriginal();
            } catch (Exception $e) {
                throw new ValidateException('授权失败[001]' . $e->getMessage());
            }
            $user = app()->make(WechatUserRepository::class)->syncAppUser($wechatInfo['unionid'], $wechatInfo, 'App', $createUser);
            if (!$user)
                throw new ValidateException('授权失败');
            return $user;
        } else if ($auth['type'] === 'apple') {
            $identityToken = $data['userInfo']['identityToken'];
            $tks = explode('.', $identityToken);
            if (count($tks) != 3) {
                throw new ValidateException('Wrong number of segments');
            }
            list($headb64, $bodyb64, $cryptob64) = $tks;
            if (null === ($payload = JWT::jsonDecode(JWT::urlsafeB64Decode($bodyb64)))) {
                throw new ValidateException('Invalid header encoding');
            }
            if ($payload->sub != $data['openId']) {
                throw new ValidateException('授权失败');
            }
            $user = app()->make(WechatUserRepository::class)->syncAppUser($data['openId'], [
                'nickName' => (string)$data['nickname'] ?: '用户' . strtoupper(substr(md5(time()), 0, 12))
            ], 'App', $createUser);
            if (!$user)
                throw new ValidateException('授权失败');
            return $user;
        }else if ($auth['type'] === 'alipay'){
            $code = $data['code'] ?? '';
            $user_id=$auth['user_id'];
            // 使用授权码获取访问令牌
            Factory::setOptions($this->getOptions());
            $result = Factory::base()->oauth()->getToken($code);

            if (!isset($result->accessToken)) {
                throw new ValidateException('获取access_token失败');
            }

            //var_dump($result);exit();
            try {
                $accessToken= $result->accessToken;

                $textParams = [
                    'auth_token' => $accessToken // 注意：此处的 auth_token 是系统参数，不是业务参数
                ];
                // 业务参数
                $bizParams = [];

                // 获取用户信息
                $result = Factory::util()->generic()->execute(
                    'alipay.user.info.share',
                    $textParams,  // 系统参数
                    $bizParams    // 业务参数（必须传，即使为空）
                );
                // 获取用户信息
                if ($result->code === '10000') {
                    /** @var AlipayUserRepository $make */
                    $make = app()->make(AlipayUserRepository::class);

                    $alipayInfo = [
                        'openid' => $result->open_id,
                        'user_id'=> $result->user_id,
                        'nickname' => $result->nick_name,
                        'avatar' => $result->avatar,
                    ];

                    $alipayUserInfo = $make->syncUser($alipayInfo['openid'], $alipayInfo, false, $createUser);

                    if ($alipayUserInfo)
                    {
                        // 同步用户信息到数据库
                        /** @var UserRepository $userRepository */
                        $userRepository = app()->make(UserRepository::class);
                        $userInfo = $userRepository->getWhere(['uid' => $user_id]);
                        if ($userInfo && !$userInfo['alipay_user_id']) {
                            $userInfo->alipay_user_id = $alipayUserInfo['alipay_user_id'];
                            $userInfo->save();
                        }
                    }

                    return $alipayUserInfo;

                }

                //return app('json')->fail($result->subMsg);

            } catch (\Exception $e) {
                return app('json')->fail('授权失败：' . $e->getMessage());
            }
        }
    }

    /**
     * 查询小程序是否需要绑定手机好 以及绑定手机号的方式
     * @return \think\response\Json
     * @author Qinii
     * @day 2023/11/10'
     */
    public function mpLoginType()
    {
        $code = $this->request->param('code');
        if (!$code) return app('json')->fail('请获取code参数');
        $spread = $this->request->param('spread', 0);
        $data = app()->make(WechatUserRepository::class)->mpLoginType($code, $spread);
        return app('json')->success($data);
    }

    /**
     * 查询支付宝小程序是否需要绑定手机号 以及绑定手机号的方式
     * @return \think\response\Json
     * @author Qinii
     * @day 2023/11/10'
     */
    public function alipayLoginType()
    {
        $code = $this->request->param('code');
        if (!$code) return app('json')->fail('请获取code参数');
        $spread = $this->request->param('spread', 0);
        $data = app()->make(AlipayUserRepository::class)->mpLoginType($code, $spread);
        return app('json')->success($data);
    }

    /**
     * App微信登陆
     * @param Request $request
     * @return mixed
     */
    public function appAuth()
    {
        $data = $this->request->params(['userInfo']);

        if (systemConfig('is_phone_login') === '1') {
            return app('json')->fail('请绑定手机号');
        }

        $user = app()->make(WechatUserRepository::class)->syncAppUser($data['userInfo']['unionId'], $data['userInfo']);
        if (!$user)
            return app('json')->fail('授权失败');
        /** @var UserRepository $make */
        $userRepository = app()->make(UserRepository::class);
        $user[1] = $userRepository->mainUser($user[1]);
        $tokenInfo = $userRepository->createToken($user[1]);
        $userRepository->loginAfter($user[1]);

        return app('json')->success($userRepository->returnToken($user[1], $tokenInfo));
    }

    public function getMerCertificate($merId)
    {
        $merId = (int)$merId;
        $data = $this->request->params(['key', 'code']);
        if (!$this->checkCaptcha($data['key'], $data['code']))
            return app('json')->fail('验证码输入有误');
        $certificate = merchantConfig($merId, 'mer_certificate') ?: [];
        if (!count($certificate))
            return app('json')->fail('该商户未上传证书');
        return app('json')->success($certificate);
    }

    protected function checkCaptcha($uni, string $code): bool
    {
        $cacheName = 'api_captche' . $uni;
        if (!Cache::has($cacheName)) return false;
        $key = Cache::get($cacheName);
        $res = strtolower($key) == strtolower($code);
        if ($res) Cache::delete($cacheName);
        return $res;
    }

    public function appleAuth()
    {
        $data = $this->request->params(['openId', 'nickname']);

        if (systemConfig('is_phone_login') === '1') {
            return app('json')->fail('请绑定手机号');
        }

        $user = app()->make(WechatUserRepository::class)->syncAppUser($data['openId'], [
            'nickName' => (string)$data['nickname'] ?: '用户' . strtoupper(substr(md5(time()), 0, 12))
        ], 'apple');
        if (!$user)
            return app('json')->fail('授权失败');
        /** @var UserRepository $make */
        $userRepository = app()->make(UserRepository::class);
        $user[1] = $userRepository->mainUser($user[1]);
        $tokenInfo = $userRepository->createToken($user[1]);
        $userRepository->loginAfter($user[1]);
        return app('json')->success($userRepository->returnToken($user[1], $tokenInfo));
    }

    /**
     * 注销账号
     */
    public function cancel()
    {
        $userRepository = app()->make(UserRepository::class);
        $user = $this->request->userInfo();
        $order = app()->make(StoreOrderRepository::class)->search(['uid' => $user['uid'], 'paid' => 1])->where('StoreOrder.status', 0)->count();
        $refund = app()->make(StoreRefundOrderRepository::class)->search(['uid' => $user['uid'], 'type' => 1])->count();
        $key = $this->request->param('key');
        $flag = false;
        if ($user->now_money > 0 || $user->integral > 0 || $order > 0 || $refund > 0) {
            $flag = true;
            if (!$key) {
                $uni = uniqid(true, false) . random_int(1, 100000000);
                $key = 'L' . md5(time() . $uni);
                Cache::set('u_out' . $user['uid'], $key, 600);
                return app('json')->status(201, '该账号下有未完成业务，注销后不可恢复，您确定继续注销？', compact('key'));
            }
        }
        if ($flag && (!$key || (Cache::get('u_out' . $user['uid']) != $key))) {
            return app('json')->fail('操作超时');
        }
        $userRepository->cancel($user);
        $userRepository->clearToken($user);
        return app('json')->status(200, '注销成功');
    }

    /**
     *  通过小程序组件，获取小程序绑定手机号
     * @return \think\response\Json
     * @author Qinii
     * @day 2023/11/9
     */
    public function mpPhone()
    {
        $code = $this->request->param('code');
        $auth_token = $this->request->param('auth_token');
        $iv = $this->request->param('iv');
        $encryptedData = $this->request->param('encryptedData');
        $miniProgramService = MiniProgramService::create();
        $userInfoCong = Cache::get('eb_api_code_' . $code);
        if (!$code && !$userInfoCong)
            throw new ValidateException('授权失败,参数有误');
        if ($code && !$userInfoCong) {
            try {
                $userInfoCong = $miniProgramService->getUserInfo($code);
                Cache::set('eb_api_code_' . $code, $userInfoCong, 86400);
            } catch (Exception $e) {
                throw new ValidateException('获取session_key失败，请检查您的配置！');
            }
        }
        $session_key = $userInfoCong['session_key'];

        $data = $miniProgramService->encryptor($session_key, $iv, $encryptedData);
        $userRepository = app()->make(UserRepository::class);

        $phone = $data['purePhoneNumber'];
        $user = $userRepository->accountByUser($phone);
//        if($user && $auth_token){
//            return app('json')->fail('用户已存在');
//        }
        $auth = $this->parseAuthToken($auth_token);
        if ($user && $auth) {
            $userRepository->syncBaseAuth($auth, $user);
        } else if (!$user) {
            if (!$auth) {
                return app('json')->fail('操作超时');
            }
            $wechatUser = app()->make(WechatUserRepository::class)->get($auth['id']);
            $user = $userRepository->syncWechatUser($wechatUser, 'routine');
            $user->phone = $phone;
            $user->account = $phone;
            $user->save();
            if ($auth['spread']) {
                $userRepository->bindSpread($user, (int)$auth['spread']);
            }
        }
        $tokenInfo = $userRepository->createToken($user);
        $userRepository->loginAfter($user);
        return app('json')->success($userRepository->returnToken($user, $tokenInfo));
    }


    /**
     * 支付宝获取手机号
     */
    public function alipayPhone()
    {
        $response = $this->request->param('response');
        
        if (!$response) {
            return app('json')->fail('参数错误');
        }

        try {
            $encrys = json_decode($response, true);
            $encryptedData = $encrys['response'];
            // 从配置中获取 AES 密钥
            $aesKey = 'T9g1Y6hNZPYRA6I0vcLPlw==';
            //$aesKey = systemConfig('ali_aes_key')?? 'T9g1Y6hNZPYRA6I0vcLPlw==';
            if (!$aesKey) {
                return app('json')->fail('请先配置支付宝 AES 密钥');
            }

            // 解密手机号
            $result = openssl_decrypt(
                base64_decode($encryptedData),
                'AES-128-CBC',
                base64_decode($aesKey),
                OPENSSL_RAW_DATA,
                str_repeat(chr(0), 16)  // 添加初始化向量（IV），使用 16 个空字节
            );

            if (!$result) {
                return app('json')->fail('解密失败');
            }
            
            $data = json_decode($result, true);
            if (!isset($data['mobile'])) {
                return app('json')->fail('获取手机号失败');
            }

            $phone=$data['mobile'];
            // 根据手机号查找用户
            /** @var UserRepository $userRepository */
            $userRepository = app()->make(UserRepository::class);
            $user = $userRepository->accountByUser($phone);

            if (!$user) {
                $user = $userRepository->registr($phone, 123456, 'alipay');

                //$user = $userRepository->mainUser($user);

            }
            $tokenInfo = $userRepository->createToken($user);
            $userRepository->loginAfter($user);
            return app('json')->success($userRepository->returnToken($user, $tokenInfo));
            
        } catch (\Exception $e) {
            return app('json')->fail($e->getMessage());
        }
    }

    // 检测商务流水
    public function checkMerchant()
    {
        /** @var UserRepository $userRepository */
        $userRepository = app()->make(UserRepository::class);
        try {
            $info = $userRepository->getMerchantInfo();
            return app('json')->success();
        }catch (Exception $e) {
            Log::error('月初检测商务流水失败：'. $e->getMessage());
        }
    }



    /**
     * @return mixed
     */
    public function ajcaptcha()
    {
        $captchaType = $this->request->get('captchaType');
        if (!$captchaType) return app('json')->fail('请输入类型');
        return app('json')->success(aj_captcha_create($captchaType));
    }

    /**
     * 一次验证
     * @return mixed
     */
    public function ajcheck()
    {
        $token = $this->request->param('token', '');
        $pointJson = $this->request->param('pointJson', '');
        $captchaType = $this->request->param('captchaType', '');

        try {
            //aj_captcha_check_one($captchaType, $token, $pointJson);
            return app('json')->success();
        } catch (\Throwable $e) {
            return app('json')->fail(400336);
        }
    }

}
