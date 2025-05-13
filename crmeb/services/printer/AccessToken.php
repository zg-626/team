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


namespace crmeb\services\printer;

use crmeb\services\HttpService;
use think\facade\Config;
use think\facade\Log;
use think\helper\Str;
use think\facade\Cache;

/**
 *
 * Class AccessToken
 * @package crmeb\services\printer
 */
class AccessToken extends HttpService
{

    /**
     * token
     * @var array
     */
    protected $accessToken;

    /**
     * 请求接口
     * @var string
     */
    protected $apiUrl;

    /**
     * @var string
     */
    protected $clientId;

    /**
     * 终端号码
     * @var string
     */
    protected $machineCode;

    /**
     * 开发者id
     * @var string
     */
    protected $partner;

    /**
     * 驱动类型
     * @var string
     */
    protected $name;

    /**
     * 配置文件名
     * @var string
     */
    protected $configFile;

    /**
     * api key
     * @var string
     */
    protected $apiKey ;
    protected $type;
    const HOST = [
        'https://open-api.10ss.net/',
        'http://api.feieyun.cn/Api/Open/',
    ];

    public function __construct(array $config = [], string $name, string $configFile)
    {
        //打印机编号
        $this->machineCode = $config['terminal'] ?? null;
        //appkey
        $this->clientId = $config['clientId'] ?? null;
        //用户
        $this->partner = $config['partner'] ?? null;
        $this->apiKey = $config['apiKey'] ?? null;
        //打印机类型
        $this->type = $config['type'] ?? 0;
        $this->name = $name;
        $this->configFile = $configFile;
        $this->apiUrl = self::HOST[$this->type];
    }

    /**
     * 获取token
     * @return mixed|null|string
     * @throws \Exception
     */
    public function getAccessToken()
    {
        if (isset($this->accessToken[$this->name])) {
            return $this->accessToken[$this->name];
        }

        $action = 'get' . Str::studly($this->name) . 'AccessToken';
        if (method_exists($this, $action)) {
            return $this->{$action}();
        } else {
            throw new \RuntimeException(__CLASS__ . '->' . $action . '(),Method not worn in');
        }
    }

    /**
     * 获取易联云token
     * @return mixed|null|string
     * @throws \Exception
     */
    protected function getYiLianYunAccessToken()
    {
         if(!$this->accessToken[$this->name] = Cache::get('YLY_access_token_'.$this->clientId)){
             $token = $this->getToken();
             Cache::set('YLY_access_token_'.$this->clientId,$token, 18 * 86400);
             $this->accessToken[$this->name] = $token;
         }
        if (!$this->accessToken[$this->name])
            Log::info('易联云打印机获取access_token获取失败:'.$this->clientId);
        return $this->accessToken[$this->name];
    }


    protected function getToken () {
        $data = [
            'client_id' => $this->clientId,
            'grant_type' => 'client_credentials',
            'sign' => strtolower(md5($this->clientId . time() . $this->apiKey)),
            'scope' => 'all',
            'timestamp' => time(),
            'id' => $this->createUuid(),
        ];
        $request = self::postRequest($this->apiUrl . 'oauth/oauth',$data );
        $request = json_decode($request, true);
        $request['error'] = $request['error'] ?? 0;
        $request['error_description'] = $request['error_description'] ?? '';
        if ($request['error'] == 0 && $request['error_description'] == 'success') {
            return $request['body']['access_token'] ?? '';
        }

        return '';
    }

    /**
     * 获取请求链接
     * @return string
     */
    public function getApiUrl(string $url = '')
    {
        return $url ? $this->apiUrl . $url : $this->apiUrl;
    }

    /**
     * 生成UUID4
     * @return string
     */
    public function createUuid()
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }

    /**
     * 获取属性
     * @param $name
     * @return mixed
     */
    public function __get($name)
    {
        if (in_array($name, ['clientId', 'apiKey', 'accessToken', 'partner', 'terminal', 'machineCode'])) {
            return $this->{$name};
        }
    }
}
