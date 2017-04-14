<?php

require_once 'Synology.php';

$config = json_decode(file_get_contents('config.json'));
$camera = $_GET['camera'];
$shouldTrigger = false;
$now = time();

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
	$synology = new Synology($config->synology->host, $config->synology->port, $config->synology->username, $config->synology->password);
	$recordingStart = max($now - $config->recording_duration, $lastTriggerSent);

	$recordings = $synology->request('SYNO.SurveillanceStation.Recording', 'List', array(
		'fromTime' => strtotime('midnight'),
		'toTime' => $now
	), true, 5)['data']['events'];

	$recording = null;

	foreach ($recordings as $contender) {
		if ($contender['camera_name'] === $camera && $contender['startTime'] < $recordingStart && $contender['stopTime'] > $recordingStart) {

			$temp = tmpfile();
			fwrite($temp, $synology->request('SYNO.SurveillanceStation.Recording', 'Download', array(
				'id' => $recording['id'],
				'offsetTimeMs' => ($recordingStart - $recording['startTime']) * 1000,
				'playTimeMs' => $config->recording_duration * 1000
			), false));

			$recording = new CurlFile(stream_get_meta_data($temp)['uri'], 'image/png', 'recording.mp4');
			break;
		}
	}

	if (is_null($recording)) {
		echo 'No matching recording for ' . $camera . ' where start is before ' . $recordingStart . ' and end is after ' . $recordingStart;
	}

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
			'recording' => $recording
		)
	));

	curl_exec($ch);

	if(!curl_errno($ch)) {
		$info = curl_getinfo($ch);

		if ($info['http_code'] == 200)
			echo 'Data sent sucessfully...';
		else
			echo 'Received ' . $info['http_code'] . ' from server';
	} else {
		echo curl_error($ch);
	}

	curl_close($ch);
} else {
	echo $camera . ' has ' . count($cameraHistory->recent_motion_detections) . ' activations in the past ' . $config->activation_window . ' seconds, and was last triggered ' . ($now - $cameraHistory->last_trigger_sent) . ' seconds ago.';
}