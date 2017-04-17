<?php

require_once 'Synology.php';

function debug($msg) {
	echo '[' . date('H:i:s.v') . ']: ' . $msg . '<br />';
}

$config = json_decode(file_get_contents('config.json'));
$camera = $_GET['camera'];
$eventType = $_GET['type'];
$now = time();

function getRecording($camera, $now, $recordingStart) {
	global $config;

	try {
		$synology = new Synology($config->synology->host, $config->synology->port, $config->synology->username, $config->synology->password);
		$recordings = $synology->request('SYNO.SurveillanceStation.Recording', 'List', array(
			'fromTime' => strtotime('midnight'),
			'toTime' => $now
		), true, 5)['data']['events'];

		foreach ($recordings as $contender) {
			if ($contender['camera_name'] === $camera && $contender['startTime'] < $recordingStart && $contender['stopTime'] > $recordingStart) {
				$temp = tmpfile();
				fwrite($temp, $synology->request('SYNO.SurveillanceStation.Recording', 'Download', array(
					'id' => $contender['id'],
					'offsetTimeMs' => ($recordingStart - $contender['startTime']) * 1000,
					'playTimeMs' => $config->recording_duration * 1000
				), false));

				return $temp;
			}
		}
	} catch (Exception $e) {
		debug($e->getMessage());
	}

	debug('No matching recording for ' . $camera . ' where start is before ' . $recordingStart . ' and end is after ' . $recordingStart);
	return null;
}

function createEvent($camera, $now, $type, $recording) {
	global $config;

	$ch = curl_init();
	curl_setopt_array($ch, array(
		CURLOPT_URL => $config->fortknox->url . '/api/event?token=' . urlencode($config->fortknox->api_key),
		CURLOPT_HEADER => true,
		CURLOPT_POST => 1,
		CURLOPT_HTTPHEADER => array("Content-Type: multipart/form-data"),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_POSTFIELDS => array(
			'device' => $camera,
			'timestamp' => $now,
			'recording' => is_null($recording) ? null : new CurlFile(stream_get_meta_data($recording)['uri'], 'image/png', 'recording.mp4'),
			'type' => $type
		)
	));

	curl_exec($ch);

	if(!curl_errno($ch)) {
		$info = curl_getinfo($ch);

		if ($info['http_code'] == 200)
			debug('Data sent sucessfully...');
		else
			debug('Received ' . $info['http_code'] . ' from server');
	} else {
		debug(curl_error($ch));
	}

	curl_close($ch);
}

switch ($eventType) {
	case 'motion':
		$history = json_decode(@file_get_contents($config->writeable_directory . 'history.json'));
		$history = is_null($history) ? new stdClass : $history;

		if (!isset($history->$camera)) {
			$history->$camera = new stdClass();
			$history->$camera->last_trigger_sent = 0;
			$history->$camera->recent_motion_detections = array();
		}

		$cameraHistory =& $history->$camera;
		$cameraHistory->recent_motion_detections[] = $now;
		$cameraHistory->recent_motion_detections = array_values(array_filter($cameraHistory->recent_motion_detections, function ($timestamp) use ($now, $config) {
			return $now - $timestamp <= $config->activation_window;
		}));

		$lastTriggerSent = $cameraHistory->last_trigger_sent;

		if ($cameraHistory->last_trigger_sent + $config->activation_backoff <= $now && count($cameraHistory->recent_motion_detections) >= $config->activation_threshold) {
			$cameraHistory->last_trigger_sent = $now;
			$shouldTrigger = true;
		}

		file_put_contents($config->writeable_directory . 'history.json', json_encode($history, JSON_PRETTY_PRINT));

		if ($shouldTrigger) {
			createEvent($camera, $now, $eventType, getRecording($camera, $now, max($now - $config->recording_duration, $lastTriggerSent)));
		} else {
			debug($camera . ' has ' . count($cameraHistory->recent_motion_detections) . ' activations in the past ' . $config->activation_window . ' seconds, and was last triggered ' . ($now - $cameraHistory->last_trigger_sent) . ' seconds ago.');
		}
	break;
	case 'disconnection':
		createEvent($camera, $now, $eventType, getRecording($camera, $now, $config->recording_duration));
	break;
	case 'connection':
		createEvent($camera, $now, $eventType, null);
	break;
	default:
		debug("\"$eventType\" is not a recognised event type");
}