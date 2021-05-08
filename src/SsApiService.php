<?php

namespace Zhengcai\SsApi;

class SsApiService
{
    /**
     * @var array
     */
    protected $_services;

    /**
     * @param $serverName
     * @return SsApi
     * @throws \Exception
     */
    public function server($serverName)
    {
        if (!isset($this->_services[$serverName])) {
            $config = config('ssapi.' . $serverName);
            if (empty($config))
                throw new \Exception('Api server config is not exist.');
            $this->_services[$serverName] = new SsApi($config);
        }
        return $this->_services[$serverName];
    }

    /**
     * 服务端验证签名
     * @param array $data
     * @param string $secret
     * @return bool
     */
    public function verify($data, $secret)
    {
        return SsApi::verify($data, $secret);
    }

}