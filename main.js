var express = require('express');
var moment = require('moment');
var request = require('request-promise-native');
var _ = require('underscore');
var config = require('./config');
var app = express();

require('./synology').init(config.synology.host, config.synology.port, config.synology.username, config.synology.password).then((synology) => {
	app.get('/motion', function (req, res, next) {
		var camera = req.query.camera;
		var now = moment();

		synology.request('SYNO.SurveillanceStation.Recording', 'List', {
			fromTime: moment(now).startOf('day').format('X'),
			toTime: now.format('X')
		}, true, 5).then((recordings) => {
			const recordingDurationMs  = 10000;
			const recordingStart = moment(now).subtract(recordingDurationMs, 'milliseconds');

			var recording = _.find(recordings.data.events, (recording) => recording.camera_name === camera
				&& moment.unix(recording.startTime).isBefore(recordingStart)
				&& moment.unix(recording.stopTime).isAfter(recordingStart));

			if (recording === undefined) {
				return null;
			}

			return synology.request('SYNO.SurveillanceStation.Recording', 'Download', {
				id: recording.id,
				offsetTimeMs: (recordingStart.format('X') - recording.startTime) * 1000,
				playTimeMs: recordingDurationMs
			}, false);
		}).then((recording) => {
			console.log('Requesting...');
			return request.post({
				url: config.fortknox.url + '/api/event?token=' + encodeURIComponent(config.fortknox.api_key),
				formData: {
					device: camera,
					timestamp: now.format('X'),
					recording: {
						value: recording,
						options: {
							filename: 'recording.mp4',
							contentType: 'video/mp4'
						}
					}
				}
			}).then(() => {
				console.log('Successfully processed motion detection at ' + now.format('HH:mm:ss'));
			});
		}).then(() => {
			res.end();
		}).catch(next);
	});

	app.listen(config.port, function () {
		console.log('Example app listening on port ' + config.port + '!');
	});
}, (err) => {
	console.log(err);
});