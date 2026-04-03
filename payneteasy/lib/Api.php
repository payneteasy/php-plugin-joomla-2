<?php
/**
 *  @author    Payneteasy
 *  @copyright 2007-2026 Payneteasy
 *  @license   Property of Payneteasy
 */

namespace Payneteasy\lib;

defined('_PAYNETEASY_LIB_') or die('Restricted access');

function trace(mixed $arg, string $prefix=''): void {
	if (is_object($arg))
		$arg = get_object_vars($arg);
	
	ksort($arg);
	foreach ($arg as $key => $value)
		error_log("$prefix'$key' => '$value'");
}

include_once('ApiException.php');

class Api {
	private const URL = 'paynet/api/v2/';
	private const USERAGENT = 'Payneteasy-Client/1.0';

	private string $login;
	private string $gate;
	private string $control_key;
	private string $endpoint;
	private bool $is_direct;
	private bool $log_trace;

	public function __construct(string $gate, string $login, string $control_key, string $endpoint, bool $is_direct, bool $log_trace=false)
		{ [ $this->gate, $this->login, $this->control_key, $this->endpoint, $this->is_direct, $this->log_trace ] = [ $gate, $login, $control_key, $endpoint, $is_direct, $log_trace ]; }

	public static function fetch_github_version(string $repo=''): string {
		$Curl = curl_init($url = sprintf('https://api.github.com/repos/%s/releases/latest', $repo));

		curl_setopt_array($Curl, [ CURLOPT_USERAGENT => self::USERAGENT, CURLOPT_RETURNTRANSFER => 1 ]);

		$response = curl_exec($Curl);

		if ($err = curl_error($Curl))
			$errmsg = "Github request error, CURL error: $err";
		elseif (($err = curl_getinfo($Curl, CURLINFO_HTTP_CODE)) != 200)
			$errmsg = "Github request error, HTTP code: '{$err}'";

		if (!empty($errmsg))
			throw new ApiException($errmsg);
		elseif (empty($response))
			throw new ApiException('Github version response is empty');

		curl_close($Curl);

		if (!preg_match('/(?:\d+\.)+\d+$/', ($tag = array_reverse(preg_split('/ +/', (json_decode($response, true)['name'])))[0]), $match))
			throw new ApiException("Github version tag is malformed: '$tag'");

		return $match[0];
	}

	public function check_config_input(string $gate, string $sandbox, string $login, string $control_key, string $endpoint): array {
		return array_reduce([
			[ 'Gateway URL', $gate, '|^https?://(?:\\w+(?:-\\w+)*\\.)+(?:\\w+(?:-\\w+)*)/|' ],
			[ 'Sandbox URL', $sandbox, '|^https?://(?:\\w+(?:-\\w+)*\\.)+\\w+/|' ],
			[ 'Login', $login, '/^[a-z][\\w-]*\\w$/i' ],
			[ 'Control key', $control_key, '/^[\da-f]{8}(?:-[\da-f]{4}){3}-[\da-f]{12}$/i' ],
			[ 'End point Id', $endpoint, '/^\d+$/' ]],
			function($iter, $entry){ if (!preg_match($entry[2], $entry[1])) $iter[] = "{$entry[0]} is invalid"; return $iter; },
			[]);
	}

	public function is_configured(): bool
		{ return $this->login && $this->control_key && $this->endpoint; }

	public function is_direct(): bool
		{ return $this->is_direct; }

	public function is_log_trace(): bool
		{ return $this->log_trace; }

	public function sale(array $data): array
		{ return $this->execute($this->is_direct ? 'sale' : 'sale-form', $this->signed($data)); }

	public function return(array $data): array
		{ return $this->execute('return', $this->signed($data, null, true)); }

	public function status(array $data): array
		{ return $this->execute('status', $this->signed($data, $this->login.$data['client_orderid'].$data['orderid'].$this->control_key)); }

	private function signed(array $data, string $str=null, bool $add_login=false): array {
		if (isset($str) || $add_login)
			$data['login'] = $this->login;

		$data['control'] = sha1($str ?? $this->endpoint.$data['client_orderid'].($data['amount'] * 100).$data['email'].$this->control_key);
		return $data;
	}

	private function execute(string $action, array $data): array {
#throw new ApiException('test sale failed', $data);
		$Curl = curl_init($this->gate . self::URL . "$action/{$this->endpoint}");

		curl_setopt_array($Curl, [
			CURLOPT_HEADER					=> 0,
			CURLOPT_USERAGENT				=> self::USERAGENT,
			CURLOPT_SSL_VERIFYHOST	=> 0,
			CURLOPT_SSL_VERIFYPEER	=> 0,
			CURLOPT_POST						=> 1,
			CURLOPT_RETURNTRANSFER	=> 1,
			CURLOPT_POSTFIELDS			=> http_build_query($data) ]);

		$response = curl_exec($Curl);

		if ($err = curl_error($Curl))
			$errmsg = "Card processing error, CURL error: '$err'";
		elseif (($err = curl_getinfo($Curl, CURLINFO_HTTP_CODE)) != 200)
			$errmsg = "Card processing error, HTTP code: '$err'";

		curl_close($Curl);

		if (!empty($errmsg))
			throw new ApiException($errmsg, $data);
		elseif (empty($response))
			throw new ApiException('Card processing response is empty', $data);

		parse_str($response, $result);
		array_walk($result, fn(&$v) => $v = rtrim($v));

		if ($this->log_trace) {
			trace($data, ' -> ');
			error_log('');
			trace($result, ' <- ');
		}
		
		if ($result['type'] == 'validation-error')
			throw new ApiException("Card processing returned error: '{$result['error-message']}'", $data, $result);

		return $result;
	}
}
