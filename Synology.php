<?php

class Synology
{
	private $_host;
	private $_port;
	private $_account;
	private $_password;
	private $_session;
	private $_sid;
	private $_apis;

	public function __construct($host, $port, $account, $password, $session = null)
	{
		$this->_host = $host;
		$this->_port = $port;
		$this->_account = $account;
		$this->_password = $password;
		$this->_session = $session || 'SurvellianceStation';
		$this->_apis = null;
	}

	public function request($api, $method, array $params, $json, $version = null)
	{
		if (is_null($this->_apis))
			$this->_init();

		if (!array_key_exists($api, $this->_apis))
			throw new Exception('Synology does not have an API for ' . $api);

		if (is_null($version))
			$version = $this->_apis[$api]['maxVersion'];

		return $this->_request($this->_apis[$api]['path'], $api, $method, $version, $params, $json);
	}

	private function _init()
	{
		$this->_apis = $this->_request('query.cgi', 'SYNO.API.Info', 'Query', 1, array('query' => 'ALL'
		), true)['data'];

		$this->_sid = $this->request('SYNO.API.Auth', 'Login', array(
			'account' => $this->_account,
			'passwd' => $this->_password,
			'session' => $this->_session,
			'format' => 'sid'
		), true)['data']['sid'];
	}

	private function _request($endpoint, $api, $method, $version, $params, $json)
	{
		$params['version'] = $version;
		$params['method'] = $method;
		$params['api'] = $api;

		if (isset($this->_sid)) {
			$params['_sid'] = $this->_sid;
		}

		$query = implode('&', array_map(function ($key) use ($params) {
			return $key . '=' . urlencode($params[$key]);
		}, array_keys($params)));

		$url = 'http://' . $this->_host . ':' . $this->_port . '/webapi/' . $endpoint . '?' . $query;

		echo 'Requesting [' . $url . ']<br />';

		$result = file_get_contents($url);

		if ($json) {
			$result = json_decode($result, true);

			if (!$result['success'])
				throw new Exception($result['error']);
		}

		return $result;
	}
}