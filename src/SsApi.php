<?php

namespace Zhengcai\SsApi;

use Illuminate\Support\Facades\App;
use Ixudra\Curl\Facades\Curl;

class SsApi
{
    /**
     * @var string
     */
    protected $apiUrl;
    /**
     * @var string
     */
    protected $appKey;
    /**
     * 用于适配部分应用app_key参数名不同
     * @var string
     */
    protected $appKeyAlias;
    /**
     * @var string
     */
    protected $appSecret;
    /**
     * 验证请求时间误差
     * @var int
     */
    protected static $timeDiff = 300;

    /**
     * 请求重试次数
     * @var int
     */
    protected $_retryTimes = 3;

    public function __construct(array $config)
    {
        $this->apiUrl = $config['api_url'];
        $this->appKey = $config['app_key'];
        $this->appKeyAlias = $config['app_key_alias'] ?? 'app_key';
        $this->appSecret = $config['app_secret'];
    }

    /**
     * 请求接口
     * @param $api
     * @param array $data
     * @param string $method
     * @param array $headers
     * @return array
     * @throws \Exception
     */
    public function request($api, $data = [], $headers = [], $method = 'get')
    {
        $this->sign($data);
        // 用法：https://github.com/ixudra/curl
        $curl = Curl::to($this->apiUrl . '/' . trim($api, '/'));
        App::environment('local') && $curl->enableDebug(storage_path('logs/ssapi-curl.log'));
        $response = $curl->withData($data)
            ->withHeaders($headers)
            ->asJson()
            ->$method();
        if (empty($response))
            throw new \Exception('request fail.');
        return $response;
    }


    /**
     * @param $name
     * @param $arguments
     * @return array
     * @throws \Exception
     */
    public function __call($name, $arguments)
    {
        $name = strtolower($name);
        if (!in_array($name, ['get', 'post', 'put', 'patch', 'delete']))
            throw new \Exception('undefined method.');
        return retry($this->_retryTimes, function () use ($name, $arguments) {
            return $this->request($arguments[0], $arguments[1] ?? [], $name, $arguments[2] ?? []);
        }, 0);
    }

    /**
     * 参数签名
     * @param $data
     * @return string
     */
    public function sign(&$data)
    {
        if (isset($data['_sign']))
            unset($data['_sign']);

        $data[$this->appKeyAlias] = $this->appKey;
        $data['_timestamp'] = date('Y-m-d H:i:s');
        $signStr = $this->appSecret;
        ksort($data);
        foreach ($data as $key => $val) {
            $val = strval($val);
            if ($key != '' && strpos($val, '@') !== 0)
                $signStr .= $key . $val;
        }
        $data['_sign'] = strtoupper(md5($signStr . $this->appSecret));
    }

    /**
     * 服务端验证签名
     * @param array $data
     * @param string $secret
     * @return bool
     */
    public static function verify($data, $secret)
    {
        if (!isset($data['_timestamp']) || !isset($data['_sign']))
            return false; //Arguments missing
        $timestamp = strtotime($data['_timestamp']);
        if ($timestamp < (time() - static::$timeDiff) || $timestamp > (time() + static::$timeDiff))
            return false; //Invalid timestamp
        $originSign = $data['_sign'];
        unset($data['_sign']);
        ksort($data);
        $signStr = $secret;
        foreach ($data as $key => $val) {
            $val = strval($val);
            if ($key != '' && strpos($val, '@') !== 0)
                $signStr .= $key . $val;
        }
        $sign = strtoupper(md5($signStr . $secret));
        if ($sign !== $originSign)
            return false; //Signature verification failed
        return true;
    }

}