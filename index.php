<?php

require_once 'Synology.php';

$config = json_decode(file_get_contents('config.json'));
$synology = new Synology($config->synology->host, $config->synology->port, $config->synology->username, $config->synology->password);
$camera = $_GET['camera'];
$now = time();
$recordingDuration = 10;
$recordingStart = $now - $recordingDuration;

$recordings = $synology->request('SYNO.SurveillanceStation.Recording', 'List', array(
	'fromTime' => strtotime('midnight'),
	'toTime' => $now
), true, 5)['data']['events'];

$recording = null;

foreach ($recordings as $contender) {
	if ($contender['camera_name'] === $camera
		&& $contender['startTime'] < $recordingStart
		&& $contender['stopTime'] > $recordingStart) {

		$recording = $contender;
		break;
	}
}

if (is_null($recording))
	throw new Exception('No matching recording...');

$temp = tmpfile();
fwrite($temp, $synology->request('SYNO.SurveillanceStation.Recording', 'Download', array(
	'id' => $recording['id'],
	'offsetTimeMs' => ($recordingStart - $recording['startTime']) * 1000,
	'playTimeMs' => $recordingDuration * 1000
), false));

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
		'recording' => new CurlFile(stream_get_meta_data($temp)['uri'], 'image/png', 'recording.mp4')
	)
));

curl_exec($ch);

if(!curl_errno($ch))
{
	$info = curl_getinfo($ch);

	if ($info['http_code'] == 200)
		echo 'Data sent sucessfully...';
	else
		echo 'Received ' . $info['http_code'] . ' from server';
}
else
{
	echo curl_error($ch);
}

curl_close($ch);
fclose($temp);