<?php

namespace Lakala\OpenAPISDK\V2\Api;

use Lakala\OpenAPISDK\V2\V2ApiException;
use Lakala\OpenAPISDK\V2\V2Configuration;
use Lakala\OpenAPISDK\V2\V2ObjectSerializer;

use Lakala\OpenAPISDK\V2\Model\V2ModelTradeNotify;

/**
 * V2LakalaNotifyApi Class
 *
 * @category Class
 * @package  Lakala\OpenAPISDK\V2\Api
 * @author   lucongyu
 * @link     https://o.lakala.com
 */
class V2LakalaNotifyApi
{
    protected $config;

    public function __construct(V2Configuration $config)
    {
        $this->config = $config;
    }

    public function notiApi() {
        $headers = getallheaders();
        $body = file_get_contents('php://input');

        $verify = $this->requestVerifySign($headers['Authorization'], $body);

        if (!$verify) {
            throw new V2ApiException('验证拉卡拉验签错误');
        }

        $notify = new \Lakala\OpenAPISDK\V2\Model\V2ModelTradeNotify();
        $notify->setHeaders($headers);
        $notify->setOriginalText($body);

        return $notify;
    }

    protected function requestVerifySign($authorization, $body) {
        $pattern = '/timestamp="(\d+)",nonce_str="(\w+)",signature="([^"]+)"/';
        preg_match($pattern, $authorization, $matches);
        if (count($matches) == 0) return false;

        $timestamp = $matches[1];
        $nonce_str = $matches[2];
        $signature = $matches[3];

        $sign = base64_decode($signature);
        $data = $timestamp. "\n"
                . $nonce_str . "\n"
                . $body . "\n";

        $certContent = file_get_contents($this->config->getLklCertificatePath());
        $key = openssl_pkey_get_public($certContent);
        $result = openssl_verify($data, $sign, $key, OPENSSL_ALGO_SHA256) === 1;
        return $result;
    }

    public function success() {
        echo '{"code":"SUCCESS","message":"执行成功"}';
    }

    public function fail($msg) {
        $ret = [
            'code' => 'FAIL',
            'message' => $msg
        ];
        echo json_encode($ret, JSON_UNESCAPED_UNICODE);
    }
}