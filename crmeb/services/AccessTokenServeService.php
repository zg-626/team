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

namespace crmeb\services;

use app\common\repositories\system\config\ConfigValueRepository;
use think\exception\ValidateException;
use think\facade\Cache;
use crmeb\services\RedisCacheService;

/**
 * Class AccessTokenServeService
 * @package crmeb\services
 */
class AccessTokenServeService extends HttpService
{
    /**
     * 配置
     * @var string
     */
    protected $account;

    /**
     * @var string
     */
    protected $secret;

    /**
     * @var Cache|null
     */
    protected $cache;

    /**
     * @var string
     */
    protected $accessToken;

    /**
     * @var string
     */
    protected $cacheTokenPrefix = "_crmeb_plat";

    /**
     * @var string
     */
    protected $apiHost = 'http://sms.crmeb.net/api/v2/';

    /**
     * 沙盒地址
     * @var string
     */
    protected $sandBoxApi = 'https://api_v2.crmeb.net/api/';

    /**
     * 沙盒模式
     * @var bool
     */
    protected $sandBox = false;

    /**
     * 登录接口
     */
    const USER_LOGIN = "user/login";


    /**
     * AccessTokenServeService constructor.
     * @param string $account
     * @param string $secret
     * @param Cache|null $cache
     */
    public function __construct(string $account, string $secret, $cache = null)
    {
        $this->account = $account;
        $this->secret = $secret;
    }

    /**
     * 获取配置
     * @return array
     */
    public function getConfig()
    {
        return [
            'access_key' => $this->account,
            'secret_key' => $this->secret
        ];
    }

    /**
     * 获取缓存token (使用Redis缓存，带错误处理)
     * @return mixed
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function getToken()
    {
        $accessTokenKey = md5($this->account . '_' . $this->secret . $this->cacheTokenPrefix);
        
        try {
            // 使用Redis缓存
            $redisCache = new RedisCacheService();
            $cacheToken = $redisCache->get($accessTokenKey);
            
            if (!$cacheToken) {
                 $getToken = $this->getTokenFromServer();
                 // 设置Redis缓存，永不过期
                 $redisCache->set($accessTokenKey, $getToken['access_token']);
                 $cacheToken = $getToken['access_token'];
             }
            
            $this->accessToken = $cacheToken;
            return $cacheToken;
            
        } catch (\Exception $e) {
            // Redis连接失败时，降级使用默认缓存
            \think\facade\Log::error('Redis缓存连接失败，降级使用默认缓存: ' . $e->getMessage());
            return $this->getTokenWithDefaultCache();
        }
    }
    
    /**
     * 获取缓存token (原版本，使用默认缓存驱动)
     * @return mixed
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function getTokenWithDefaultCache()
    {
        $accessTokenKey = md5($this->account . '_' . $this->secret . $this->cacheTokenPrefix);
        $cacheToken = Cache::get($accessTokenKey);
        if (!$cacheToken) {
            $getToken = $this->getTokenFromServer();
            // 设置默认缓存，永不过期
            Cache::set($accessTokenKey, $getToken['access_token']);
            $cacheToken = $getToken['access_token'];
        }
        $this->accessToken = $cacheToken;

        return $cacheToken;
    }

    /**
     * 清除token缓存 (强制刷新)
     * @return bool
     */
    public function clearTokenCache()
    {
        $accessTokenKey = md5($this->account . '_' . $this->secret . $this->cacheTokenPrefix);
        
        try {
            // 清除Redis缓存
            $redisCache = new RedisCacheService();
            $redisCache->delete($accessTokenKey);
            
            // 清除默认缓存
            Cache::delete($accessTokenKey);
            
            return true;
        } catch (\Exception $e) {
            \think\facade\Log::error('清除token缓存失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 强制刷新token (清除缓存后重新获取)
     * @return mixed
     */
    public function refreshToken()
    {
        $this->clearTokenCache();
        return $this->getToken();
    }

    /**
     * 从服务器获取token
     * @return mixed
     */
    public function getTokenFromServer()
    {
        $params = $this->getConfig();
        if (!$this->account || !$this->secret) {
            throw new ValidateException('请先配置-》第三方接口-》一号通');
        }
        $response = $this->postRequest($this->get(self::USER_LOGIN), $params);
        $response = json_decode($response, true);
        if (!$response) {
            throw new ValidateException('获取token失败');
        }
        if ($response['status'] === 200) {
            return $response['data'];
        } else {
            throw new ValidateException($response['msg']);
        }
    }

    /**
     * 请求
     * @param string $url
     * @param array $data
     * @param string $method
     * @param bool $isHeader
     * @return array|mixed
     */
    public function httpRequest(string $url, array $data = [], string $method = 'POST', bool $isHeader = true,$header = [])
    {
        if ($isHeader) {
            $this->getToken();
            if (!$this->accessToken) {
                throw new ValidateException('配置已更改或token已失效');
            }
            $header =  array_merge(['Authorization:Bearer-' . $this->accessToken] , $header);
        }
        $res = $this->request($this->get($url), $method, $data, $header);
        if (!$res) {
            throw new ValidateException('平台错误：发生异常，请稍后重试');
        }
        $result = json_decode($res, true) ?: false;
        if (!isset($result['status']) || $result['status'] != 200) {
            if ($result['msg'] == '接口请求失败:用户或密码错误') {
               app()->make(CrmebServeServices::class)->logout();
            }
            throw new ValidateException(isset($result['msg']) ? '平台错误：' . $result['msg'] : '平台错误：发生异常，请稍后重试');
        }
        return $result['data'] ?? [];

    }

    /**
     * @param string $apiUrl
     * @return string
     */
    public function get(string $apiUrl = '')
    {
        $host = $this->sandBox ? $this->sandBoxApi : $this->apiHost;
        return $host . $apiUrl;
    }
}
