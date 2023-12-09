<?php

namespace Zhengcai\SsApi;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\App;
use Ixudra\Curl\Facades\Curl;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Config;

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
	public static $errorMsg;

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
		$url = $this->apiUrl . '/' . trim($api, '/');
		$curl = Curl::to($url);
		App::environment('local') && $curl->enableDebug(storage_path('logs/ssapi-curl.log'));

		// 使用Curl请求
		$response = $curl->withData($data)
			->withHeaders($headers)
			->withOption('SSL_VERIFYPEER', false)
			->asJson()
			->$method();

		if (empty($response)) {
			// 如果Curl处理失败则使用GuzzleHttp进行请求,看具体报什么错误
			$curl->enableDebug(storage_path('logs/ssapi-curl.log'));
			if ($method == 'get') {
				$url = $url . '?' . http_build_query($data);
			}

			$client = new Client([
				'timeout' => 3,
			]);
			$request = $client->$method($url, [
				'body' => json_encode($data),
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'verify' => false,
			]);

			$response = $request->getBody()->getContents();
			$response = json_decode($response, true) ?? [];

			if (empty($response)) {
				$logFile = self::getLogFile('.wf.log');
				$logModel = new \Monolog\Logger("[WARNING]");
				$logModel->pushHandler(new StreamHandler(storage_path($logFile), 200));
				$logModel->info(self::$errorMsg);
				throw new \Exception('curl请求失败，详细内容请查看日志文件:' . $logFile);
			}
		}
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

	public static function requestDataCard($url, $method, $fields = '', $httpHeaders = '')
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 50);
		curl_setopt($ch, CURLOPT_TIMEOUT, 300);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/75.0.3770.142 Safari/537.36');
		//curl_setopt($ch, CURLINFO_HEADER_OUT, true);

		if (is_array($httpHeaders)) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
		}

		if (strpos($url, "https://") !== false) {
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		}

		switch ($method) {
			case 'POST':
				curl_setopt($ch, CURLOPT_POST, TRUE);
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
				if (!empty($fields)) {
					curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
				}
				break;
			case 'DELETE':
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
				if (!empty($fields)) {
					$url = "{$url}?{$fields}";
				}
		}

		$response = curl_exec($ch);

		if ($response == false) {
			$ch->enableDebug(storage_path('logs/ssapi-curl.log'));
			$error_msg = curl_error($ch);
			curl_close($ch);
			self::$errorMsg = $error_msg;
			return '';
		}
		curl_close($ch);
		return $response;
	}


	protected static function getLogFile($ext = 'log')
	{
		$appName = env('APP_NAME') ?? 'log';
		return 'logs/' . $appName . '.' . date('Y-m-d-H') . '.' . trim($ext, '.');
	}
}