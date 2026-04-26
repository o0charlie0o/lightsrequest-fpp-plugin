<?php
$PLUGIN_VERSION = "2026.04.26.01";

include_once "/opt/fpp/www/common.php";
$pluginName = basename(dirname(__FILE__));
$pluginPath = $settings['pluginDirectory']."/".$pluginName."/";
$logFile = $settings['logDirectory']."/".$pluginName."-listener.log";
$pluginConfigFile = $settings['configDirectory'] . "/plugin." .$pluginName;
$pluginSettings = parse_ini_file($pluginConfigFile);

logEntry("Starting Lights Request Plugin v" . $PLUGIN_VERSION);

if ($pluginSettings === false) {
  logEntry("ERROR - Unable to read plugin config file at startup: " . $pluginConfigFile);
  $pluginSettings = array();
}

WriteSettingToFile("pluginVersion",urlencode($PLUGIN_VERSION),$pluginName);

//Set defaults here since this runs before the plugin page is visited
if (strlen(urldecode($pluginSettings['remotePlaylist']))<1){
  WriteSettingToFile("remotePlaylist",urlencode(""),$pluginName);
}
if (strlen(urldecode($pluginSettings['interruptSchedule']))<1){
  WriteSettingToFile("interruptSchedule",urlencode("false"),$pluginName);
}
if (strlen(urldecode($pluginSettings['remoteToken']))<1){
  WriteSettingToFile("remoteToken",urlencode(""),$pluginName);
}
if (strlen(urldecode($pluginSettings['requestFetchTime']))<1){
  WriteSettingToFile("requestFetchTime",urlencode("3"),$pluginName);
}
if (strlen(urldecode($pluginSettings['additionalWaitTime']))<1){
  WriteSettingToFile("additionalWaitTime",urlencode("0"),$pluginName);
}
if (strlen(urldecode($pluginSettings['fppStatusCheckTime']))<1){
  WriteSettingToFile("fppStatusCheckTime",urlencode("1"),$pluginName);
}
if (strlen(urldecode($pluginSettings['pluginsApiPath']))<1){
  WriteSettingToFile("pluginsApiPath",urlencode("https://api.lightsrequest.com"),$pluginName);
}
if (strlen(urldecode($pluginSettings['verboseLogging']))<1){
  WriteSettingToFile("verboseLogging",urlencode("false"),$pluginName);
}
if (strlen(urldecode($pluginSettings['remoteFalconListenerEnabled']))<1){
  WriteSettingToFile("remoteFalconListenerEnabled",urlencode("true"),$pluginName);
}
if (strlen(urldecode($pluginSettings['remoteFalconListenerRestarting']))<1){
  WriteSettingToFile("remoteFalconListenerRestarting",urlencode("false"),$pluginName);
}

$pluginToken = "";
$remotePlaylist = "";
$viewerControlMode = "";
$jukeboxEnabled = true;
$interruptSchedule = "";
$currentlyPlayingInRF = "";
$requestFetchTime = "";
$additionalWaitTime = "";
$pluginsApiPath = "";
$verboseLogging = false;
$lastQueuedSequence = "";
$lastQueuedTime = 0;

$pluginsApiPath = urldecode($pluginSettings['pluginsApiPath']);
logEntry("API Base URL: " . $pluginsApiPath);
$pluginToken = urldecode($pluginSettings['remoteToken']);
$remotePlaylist = urldecode($pluginSettings['remotePlaylist']);
logEntry("Remote Playlist: ".$remotePlaylist);

// Send initial heartbeat (replaces RF's separate /pluginVersion + /fppHeartbeat).
sendHeartbeat($pluginToken);

// Fetch settings — viewer_control_mode + jukebox_enabled drive the polling loop.
$serverSettings = getPluginSettings($pluginToken);
if ($serverSettings === null) {
  logEntry("WARNING - Unable to fetch plugin settings. Using default 'jukebox' mode.");
  logEntry("Please verify your Plugin Token is correct and api.lightsrequest.com is reachable.");
  $viewerControlMode = "jukebox";
  $jukeboxEnabled = true;
} else {
  $viewerControlMode = isset($serverSettings->viewer_control_mode) ? $serverSettings->viewer_control_mode : "jukebox";
  $jukeboxEnabled = isset($serverSettings->jukebox_enabled) ? (bool)$serverSettings->jukebox_enabled : true;
  logEntry("Viewer Control Mode: " . $viewerControlMode);
  logEntry("Jukebox Enabled: " . ($jukeboxEnabled ? "true" : "false"));
}

$interruptSchedule = urldecode($pluginSettings['interruptSchedule']);
logEntry("Interrupt Schedule: " . $interruptSchedule);
$interruptSchedule = $interruptSchedule == "true" ? true : false;
$requestFetchTime = intVal(urldecode($pluginSettings['requestFetchTime']));
logEntry("Request Fetch Time: " . $requestFetchTime);
$additionalWaitTime = intVal(urldecode($pluginSettings['additionalWaitTime']));
logEntry("Additional Wait Time: " . $additionalWaitTime);
$fppStatusCheckTime = floatval(urldecode($pluginSettings['fppStatusCheckTime']));
logEntry("FPP Status Check Time: " . $fppStatusCheckTime . " (" . $fppStatusCheckTime * 1000000 . " microseconds)");
$verboseLogging = urldecode($pluginSettings['verboseLogging']);
logEntry("Verbose Logging: " . $verboseLogging);
$GLOBALS['verboseLogging'] = ($verboseLogging === "true");

$lastSettingsRefresh = time();
$lastHeartbeat = time();

while(true) {
  $pluginSettings = parse_ini_file($pluginConfigFile);

  if ($pluginSettings === false) {
    logEntry("ERROR - Unable to read plugin config file: " . $pluginConfigFile . ". Retrying in 5 seconds.");
    sleep(5);
    continue;
  }

  $remoteFppEnabled = urldecode($pluginSettings['remoteFalconListenerEnabled']);
  $remoteFppEnabled = $remoteFppEnabled == "true" ? true : false;
  $remoteFppRestarting = urldecode($pluginSettings['remoteFalconListenerRestarting']);
  $remoteFppRestarting = $remoteFppRestarting == "true" ? true : false;

  if($remoteFppRestarting == 1) {
    WriteSettingToFile("remoteFalconListenerEnabled",urlencode("true"),$pluginName);
    WriteSettingToFile("remoteFalconListenerRestarting",urlencode("false"),$pluginName);

    logEntry("Restarting Lights Request Plugin v" . $PLUGIN_VERSION);
    $pluginsApiPath = urldecode($pluginSettings['pluginsApiPath']);
    logEntry("API Base URL: " . $pluginsApiPath);
    $pluginToken = urldecode($pluginSettings['remoteToken']);
    $remotePlaylist = urldecode($pluginSettings['remotePlaylist']);
    logEntry("Remote Playlist: ".$remotePlaylist);

    $serverSettings = getPluginSettings($pluginToken);
    if ($serverSettings === null) {
      logEntry("WARNING - Unable to fetch plugin settings. Using default 'jukebox' mode.");
      $viewerControlMode = "jukebox";
      $jukeboxEnabled = true;
    } else {
      $viewerControlMode = isset($serverSettings->viewer_control_mode) ? $serverSettings->viewer_control_mode : "jukebox";
      $jukeboxEnabled = isset($serverSettings->jukebox_enabled) ? (bool)$serverSettings->jukebox_enabled : true;
      logEntry("Viewer Control Mode: " . $viewerControlMode);
      logEntry("Jukebox Enabled: " . ($jukeboxEnabled ? "true" : "false"));
    }

    sendHeartbeat($pluginToken);
    $lastHeartbeat = time();

    $interruptSchedule = urldecode($pluginSettings['interruptSchedule']);
    logEntry("Interrupt Schedule: " . $interruptSchedule);
    $interruptSchedule = $interruptSchedule == "true" ? true : false;
    $requestFetchTime = intVal(urldecode($pluginSettings['requestFetchTime']));
    logEntry("Request Fetch Time: " . $requestFetchTime);
    $additionalWaitTime = intVal(urldecode($pluginSettings['additionalWaitTime']));
    logEntry("Additional Wait Time: " . $additionalWaitTime);
    $fppStatusCheckTime = floatval(urldecode($pluginSettings['fppStatusCheckTime']));
    logEntry("FPP Status Check Time: " . $fppStatusCheckTime . " (" . $fppStatusCheckTime * 1000000 . " microseconds)");
    $verboseLogging = urldecode($pluginSettings['verboseLogging']);
    logEntry("Verbose Logging: " . $verboseLogging);
    $GLOBALS['verboseLogging'] = ($verboseLogging === "true");
  }

  // Refresh server settings every ~10 seconds so admin toggles propagate.
  if((time() - $lastSettingsRefresh) >= 10) {
    $serverSettings = getPluginSettings($pluginToken);
    if ($serverSettings !== null) {
      $newMode = isset($serverSettings->viewer_control_mode) ? $serverSettings->viewer_control_mode : $viewerControlMode;
      $newEnabled = isset($serverSettings->jukebox_enabled) ? (bool)$serverSettings->jukebox_enabled : $jukeboxEnabled;
      if ($newMode !== $viewerControlMode) {
        logEntry("Viewer Control Mode changed: " . $viewerControlMode . " -> " . $newMode);
        $viewerControlMode = $newMode;
      }
      if ($newEnabled !== $jukeboxEnabled) {
        logEntry("Jukebox Enabled changed: " . ($jukeboxEnabled ? "true" : "false") . " -> " . ($newEnabled ? "true" : "false"));
        $jukeboxEnabled = $newEnabled;
      }
    }
    $lastSettingsRefresh = time();
  }

  // Heartbeat every 60 seconds.
  if((time() - $lastHeartbeat) >= 60) {
    sendHeartbeat($pluginToken);
    $lastHeartbeat = time();
  }

  if($remoteFppEnabled == 1) {
    logEntry_verbose("Getting FPP Status");
    $fppStatus = getFppStatus();
    if($fppStatus != null && $fppStatus != false) {
      $statusName = $fppStatus->status_name;
      if($statusName != "idle") {
        $currentlyPlaying = pathinfo($fppStatus->current_sequence, PATHINFO_FILENAME);
        if($currentlyPlaying == "") {
          //Might be media only, so check for current song
          $currentlyPlaying = pathinfo($fppStatus->current_song, PATHINFO_FILENAME);
        }
        updateCurrentlyPlaying($currentlyPlaying, $GLOBALS['currentlyPlayingInRF'], $pluginToken, $fppStatus);

        // If server has us off / jukebox disabled, skip request fetching — let FPP run its scheduled playlist.
        if($jukeboxEnabled && $viewerControlMode !== "off") {
          if($interruptSchedule != 1) {
            doNonInterruptStuff($fppStatus, $requestFetchTime, $additionalWaitTime, $remotePlaylist, $pluginToken);
          }else {
            doInterruptStuff($fppStatus, $requestFetchTime, $additionalWaitTime, $remotePlaylist, $pluginToken);
          }
        }
      }
    }else {
      logEntry("FPPD is not running!");
      sleep(5);
    }
  }

  usleep($fppStatusCheckTime * 1000000);
}

function doNonInterruptStuff($fppStatus, $requestFetchTime, $additionalWaitTime, $remotePlaylist, $pluginToken) {
  $secondsRemaining = intVal($fppStatus->seconds_remaining);
  $currentlyPlaying = pathinfo($fppStatus->current_sequence, PATHINFO_FILENAME);
  if($currentlyPlaying == "") {
    $currentlyPlaying = pathinfo($fppStatus->current_song, PATHINFO_FILENAME);
  }

  // Prevent duplicate queueing for the same currently-playing sequence.
  if($currentlyPlaying == $GLOBALS['lastQueuedSequence']) {
    $timeSinceQueue = time() - $GLOBALS['lastQueuedTime'];
    if($timeSinceQueue < ($requestFetchTime + $additionalWaitTime + 2)) {
      logEntry_verbose("Already queued for current sequence, skipping. Time since queue: " . $timeSinceQueue . "s");
      return;
    }
  }

  if($secondsRemaining < $requestFetchTime) {
    $start_time = microtime(true);
    logEntry_verbose("Starting Non Interrupt Function");

    logEntry($requestFetchTime . " seconds remaining. Asking server for next sequence.");
    $next = nextPlaylist($pluginToken);
    $nextSequence = isset($next->sequence) ? $next->sequence : null;
    $nextSequenceIndex = isset($next->fpp_index) ? $next->fpp_index : null;
    $source = isset($next->source) ? $next->source : 'none';

    if($nextSequence !== null && $nextSequenceIndex !== null) {
      logEntry("Queuing " . $nextSequence . " (source=" . $source . ") at index " . $nextSequenceIndex);
      insertPlaylistAfterCurrent(rawurlencode($remotePlaylist), $nextSequenceIndex);

      $GLOBALS['lastQueuedSequence'] = $currentlyPlaying;
      $GLOBALS['lastQueuedTime'] = time();

      $fppWaitTime = $requestFetchTime + $additionalWaitTime;
      logEntry("Sleeping for " . $fppWaitTime . " seconds.");
      sleep($fppWaitTime);
    } else {
      logEntry("No requests/votes (source=" . $source . ")");

      $GLOBALS['lastQueuedSequence'] = $currentlyPlaying;
      $GLOBALS['lastQueuedTime'] = time();

      $fppWaitTime = $requestFetchTime + $additionalWaitTime;
      logEntry("Sleeping for " . $fppWaitTime . " seconds.");
      sleep($fppWaitTime);
    }

    $end_time = microtime(true);
    $execution_time = ($end_time - $start_time);
    logEntry_verbose("Completed Non Interrupt Function. Execution time: " . $execution_time * 1000 . " ms");
  }
}

function doInterruptStuff($fppStatus, $requestFetchTime, $additionalWaitTime, $remotePlaylist, $pluginToken) {
  if($fppStatus->current_playlist != null) {
    $currentPlaylist = $fppStatus->current_playlist->playlist;
    if($currentPlaylist != $remotePlaylist) {
      // Throttle interrupts to avoid rapid-fire firing.
      $timeSinceLastInterrupt = time() - $GLOBALS['lastQueuedTime'];
      if($timeSinceLastInterrupt < ($requestFetchTime + $additionalWaitTime + 2)) {
        logEntry_verbose("Recently interrupted, skipping. Time since last: " . $timeSinceLastInterrupt . "s");
        return;
      }

      $start_time = microtime(true);
      logEntry_verbose("Starting Interrupt Function");

      $next = nextPlaylist($pluginToken);
      $nextSequence = isset($next->sequence) ? $next->sequence : null;
      $nextSequenceIndex = isset($next->fpp_index) ? $next->fpp_index : null;
      $source = isset($next->source) ? $next->source : 'none';

      if($nextSequence !== null && $nextSequenceIndex !== null) {
        insertPlaylistImmediate(rawurlencode($remotePlaylist), $nextSequenceIndex);
        logEntry("Playing " . $nextSequence . " (source=" . $source . ") at index " . $nextSequenceIndex);

        $GLOBALS['lastQueuedSequence'] = $nextSequence;
        $GLOBALS['lastQueuedTime'] = time();

        $fppWaitTime = $requestFetchTime + $additionalWaitTime;
        logEntry("Sleeping for " . $fppWaitTime . " seconds.");
        sleep($fppWaitTime);
      }

      $end_time = microtime(true);
      $execution_time = ($end_time - $start_time);
      logEntry_verbose("Completed Interrupt Function. Execution time: " . $execution_time * 1000 . " ms");
    }else {
      doNonInterruptStuff($fppStatus, $requestFetchTime, $additionalWaitTime, $remotePlaylist, $pluginToken);
    }
  }
}

function updateCurrentlyPlaying($currentlyPlaying, $currentlyPlayingInRF, $pluginToken, $fppStatus) {
  if($currentlyPlaying != $currentlyPlayingInRF) {
    $duration = null;
    if (isset($fppStatus->seconds_total)) {
      $duration = intVal($fppStatus->seconds_total);
    }
    updateNowPlaying($currentlyPlaying, $duration, $pluginToken);
    logEntry("Updated current playing sequence to " . $currentlyPlaying);
    $GLOBALS['currentlyPlayingInRF'] = $currentlyPlaying;
  }
}

function getPluginSettings($pluginToken) {
  $url = $GLOBALS['pluginsApiPath'] . "/plugin/settings";
  $options = array(
    'http' => array(
      'method'  => 'GET',
      'timeout' => 10,
      'header'=>  "Authorization: Bearer $pluginToken\r\n" .
                  "Accept: application/json\r\n"
      )
  );
  $context = stream_context_create($options);
  $result = @file_get_contents($url, false, $context);

  if ($result === FALSE) {
    logEntry("ERROR - Failed to fetch plugin settings from: " . $url);
    return null;
  }

  $decoded = json_decode($result);
  if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
    logEntry("ERROR - Invalid JSON response from /plugin/settings: " . json_last_error_msg());
    return null;
  }

  return $decoded;
}

function getFppStatus() {
  $options = array(
    'http' => array(
      'timeout' => 5
    )
  );
  $context = stream_context_create($options);
  $result = @file_get_contents("http://127.0.0.1/api/system/status", false, $context);

  if ($result === FALSE) {
    logEntry_verbose("ERROR - Failed to get FPP status");
    return null;
  }

  $decoded = json_decode($result);
  if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
    logEntry("ERROR - Invalid JSON response from FPP status: " . json_last_error_msg());
    return null;
  }

  return $decoded;
}

function updateNowPlaying($currentlyPlaying, $durationSeconds, $pluginToken) {
  $start_time = microtime(true);
  logEntry_verbose("Calling /plugin/now-playing");
  $url = $GLOBALS['pluginsApiPath'] . "/plugin/now-playing";
  $data = array(
    'sequence' => trim($currentlyPlaying)
  );
  if ($durationSeconds !== null && $durationSeconds > 0) {
    $data['duration_seconds'] = $durationSeconds;
  }
  $options = array(
    'http' => array(
      'method'  => 'POST',
      'timeout' => 10,
      'content' => json_encode($data),
      'header'=>  "Content-Type: application/json; charset=UTF-8\r\n" .
                  "Accept: application/json\r\n" .
                  "Authorization: Bearer $pluginToken\r\n"
      )
  );
  $context = stream_context_create($options);
  $result = @file_get_contents($url, false, $context);

  if ($result === FALSE) {
    logEntry("ERROR - Failed to POST /plugin/now-playing to: " . $url);
    return false;
  }

  $end_time = microtime(true);
  $execution_time = ($end_time - $start_time);
  logEntry_verbose("SUCCESS - /plugin/now-playing. Execution time: " . $execution_time * 1000 . " ms");
  return true;
}

function sendHeartbeat($pluginToken) {
  $url = $GLOBALS['pluginsApiPath'] . "/plugin/heartbeat";
  // Include FPP version when reachable.
  $fppVersion = null;
  $fppVerJson = @file_get_contents("http://127.0.0.1/api/fppd/version");
  if ($fppVerJson !== FALSE) {
    $decoded = @json_decode($fppVerJson);
    if ($decoded !== null && isset($decoded->version)) {
      $fppVersion = $decoded->version;
    }
  }
  $data = array(
    'plugin_version' => $GLOBALS['PLUGIN_VERSION']
  );
  if ($fppVersion !== null) {
    $data['fpp_version'] = $fppVersion;
  }
  $options = array(
    'http' => array(
      'method'  => 'POST',
      'timeout' => 10,
      'content' => json_encode($data),
      'header'=>  "Content-Type: application/json; charset=UTF-8\r\n" .
                  "Accept: application/json\r\n" .
                  "Authorization: Bearer $pluginToken\r\n"
      )
  );
  $context = stream_context_create($options);
  $result = @file_get_contents($url, false, $context);

  if ($result === FALSE) {
    logEntry_verbose("ERROR - Failed to POST /plugin/heartbeat to: " . $url);
    return false;
  }

  logEntry_verbose("SUCCESS - /plugin/heartbeat");
  return true;
}

function insertPlaylistImmediate($remotePlaylistEncoded, $index) {
  $url = "http://127.0.0.1/api/command/Insert%20Playlist%20Immediate/" . $remotePlaylistEncoded . "/" . $index . "/" . $index;
  $options = array(
    'http' => array(
      'method'  => 'GET',
      'timeout' => 5
      )
  );
  $context = stream_context_create($options);
  $result = @file_get_contents($url, false, $context);

  if ($result === FALSE) {
    logEntry("ERROR - Failed to insert playlist immediate: " . rawurldecode($remotePlaylistEncoded) . " at index " . $index);
    return false;
  }

  logEntry_verbose("SUCCESS - Inserted playlist immediate");
  return true;
}

function insertPlaylistAfterCurrent($remotePlaylistEncoded, $index) {
  $url = "http://127.0.0.1/api/command/Insert%20Playlist%20After%20Current/" . $remotePlaylistEncoded . "/" . $index . "/" . $index;
  $options = array(
    'http' => array(
      'method'  => 'GET',
      'timeout' => 5
      )
  );
  $context = stream_context_create($options);
  $result = @file_get_contents($url, false, $context);

  if ($result === FALSE) {
    logEntry("ERROR - Failed to insert playlist after current: " . rawurldecode($remotePlaylistEncoded) . " at index " . $index);
    return false;
  }

  logEntry_verbose("SUCCESS - Inserted playlist after current");
  return true;
}

function stopGracefully() {
  $url = "http://127.0.0.1/api/playlists/stopgracefully";
  $options = array(
    'http' => array(
      'method'  => 'GET'
      )
  );
  $context = stream_context_create($options);
  $result = file_get_contents($url, false, $context);
}

function nextPlaylist($pluginToken) {
  $start_time = microtime(true);
  logEntry_verbose("Calling /plugin/next");
  $url = $GLOBALS['pluginsApiPath'] . "/plugin/next";
  $options = array(
    'http' => array(
      'method'  => 'GET',
      'timeout' => 10,
      'header'=>  "Authorization: Bearer $pluginToken\r\n" .
                  "Accept: application/json\r\n"
      )
  );
  $context = stream_context_create($options);
  $result = @file_get_contents($url, false, $context);

  if ($result === FALSE) {
    logEntry("ERROR - Failed to fetch /plugin/next from: " . $url);
    return (object)['sequence' => null, 'fpp_index' => null, 'source' => 'none'];
  }

  $decoded = json_decode($result);
  if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
    logEntry("ERROR - Invalid JSON response from /plugin/next: " . json_last_error_msg());
    return (object)['sequence' => null, 'fpp_index' => null, 'source' => 'none'];
  }

  $end_time = microtime(true);
  $execution_time = ($end_time - $start_time);
  logEntry_verbose("SUCCESS - /plugin/next. Execution time: " . $execution_time * 1000 . " ms");
  return $decoded;
}

function logEntry($data) {

	global $logFile;

  $logWrite = @fopen($logFile, "a");
  if ($logWrite === false) {
    error_log("Lights Request listener cannot open log file: " . $logFile . " | Message: " . $data);
    return;
  }

	fwrite($logWrite, date('Y-m-d h:i:s A',time()).": ".$data."\n");
	fclose($logWrite);
}

function logEntry_verbose($data) {
  if(!isset($GLOBALS['verboseLogging']) || $GLOBALS['verboseLogging'] !== true) {
    return;
  }

  global $logFile;

  $logWrite = @fopen($logFile, "a");
  if ($logWrite === false) {
    error_log("Lights Request listener cannot open log file: " . $logFile . " | Message: " . $data);
    return;
  }

  fwrite($logWrite, date('Y-m-d h:i:s A',time()).": ".$data."\n");
  fclose($logWrite);
}

?>
