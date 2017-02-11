var request = require('request-promise-native');
var moment = require('moment');

function Synology(host, port, account, password, session) {
	this._host = host;
	this._port = port;
	this._account = account;
	this._password = password;
	this._session = session || 'SurveillanceStation';
	this._sid = undefined;
}

Synology.prototype.init = function () {
	return this._request('query.cgi', 'SYNO.API.Info', 'Query', 1, { query: 'ALL' }, true).then((data) => {
		this._apis = data.data;

		return this.request('SYNO.API.Auth', 'Login', {
			account: this._account,
			passwd: this._password,
			session: this._session,
			format: 'sid'
		}, true);
	}).then((auth) => {
		this._sid = auth.data.sid;
	});
};

Synology.prototype.request = function (api, method, params, json, version) {
	if (!this._apis.hasOwnProperty(api)) {
		throw new Error('Synology does not have an API for ' + api);
	}

	if (typeof version === 'undefined') {
		version = this._apis[api].maxVersion;
	}

	return this._request(this._apis[api].path, api, method, version, params, json);
};

Synology.prototype._request = function (endpoint, api, method, version, params, json) {
	params.version = version;
	params.method = method;
	params.api = api;

	if (typeof this._sid !== 'undefined')
		params._sid = this._sid;

	var query = Object.keys(params).map((key) => key + '=' + encodeURIComponent(params[key])).join('&');
	var url = 'http://' + this._host + ':' + this._port + '/webapi/' + endpoint + '?' + query;

	console.log('Requesting [' + url + ']');

	return request(url, {
		encoding: (json ? 'utf8' : null)
	}).then(function (response) {
		if (!json) {
			return response;
		}

		console.log(response);
		var parsed = JSON.parse(response);

		if (!parsed.success) {
			return Promise.reject(parsed.error);
		}

		return parsed;
	});
};

module.exports.init = function (host, port, account, password, session) {
	var synology = new Synology(host, port, account, password, session);

	return synology.init().then(() => {
		return synology;
	});
};