<?php
@ini_set('display_errors', '0');
@error_reporting(0);

function jam_get_api_key() {
    return '123321';
}

function jam_root_dir() {
    return dirname(__DIR__);
}

function jam_queue_file() {
    return jam_root_dir() . DIRECTORY_SEPARATOR . 'tts_queue.ndjson';
}

function jam_log_file() {
    return jam_root_dir() . DIRECTORY_SEPARATOR . 'tts_activity.log';
}

function jam_dedup_file() {
    return jam_root_dir() . DIRECTORY_SEPARATOR . 'tts_dedup.json';
}

function jam_append_log($line) {
    $ts = date('d-m-Y H:i:s');
    @file_put_contents(jam_log_file(), $ts . ' | QUEUE | ' . $line . PHP_EOL, FILE_APPEND);
}

function jam_get_request_api_key() {
    $fromQuery = isset($_GET['api_key']) ? (string)$_GET['api_key'] : '';
    if ($fromQuery !== '') return $fromQuery;
    if (isset($_SERVER['HTTP_X_API_KEY']) && (string)$_SERVER['HTTP_X_API_KEY'] !== '') {
        return (string)$_SERVER['HTTP_X_API_KEY'];
    }
    return '';
}

function jam_is_api_key_valid() {
    return hash_equals(jam_get_api_key(), jam_get_request_api_key());
}

function jam_enqueue_tts($text, $lang, $speed, $source) {
    $job = [
        'id' => uniqid('tts_', true),
        'created_at' => date('c'),
        'text' => (string)$text,
        'lang' => (string)$lang,
        'speed' => (string)$speed,
        'source' => (string)$source
    ];

    $queue = jam_queue_file();
    $dir = dirname($queue);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return [false, null, 'Gagal membuat direktori queue'];
    }

    $fh = @fopen($queue, 'c+');
    if (!$fh) return [false, null, 'Gagal membuka file queue'];

    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return [false, null, 'Gagal lock file queue'];
    }

    fseek($fh, 0, SEEK_END);
    $line = json_encode($job, JSON_UNESCAPED_UNICODE);
    $ok = ($line !== false) && (@fwrite($fh, $line . PHP_EOL) !== false);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    if (!$ok) return [false, null, 'Gagal menulis queue'];
    return [true, $job['id'], null];
}

function jam_fetch_tts_audio($text, $lang = 'id', $speed = '1') {
    $encoded = rawurlencode($text);
    $url = "https://translate.google.com/translate_tts?ie=UTF-8&q={$encoded}&tl={$lang}&client=tw-ob&ttsspeed={$speed}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Referer: https://translate.google.com/']);
    $audio = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$audio) {
        return [false, null, "Fetch TTS gagal (HTTP {$httpCode})"];
    }
    return [true, $audio, null];
}

function jam_play_audio_bytes_windows($audioBytes, $text) {
    $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jam_tts_' . md5($text . microtime(true)) . '.mp3';
    if (@file_put_contents($tmpFile, $audioBytes) === false) {
        return [false, 'Gagal simpan mp3 sementara'];
    }

    $durationSec = max(4, min(20, (int)ceil(mb_strlen((string)$text) / 11)));
    $escapedPath = str_replace("'", "''", $tmpFile);
    $psScript = "try { \$ErrorActionPreference='Stop'; \$p=New-Object -ComObject WMPlayer.OCX; \$m=\$p.newMedia('{$escapedPath}'); \$p.currentPlaylist.appendItem(\$m) | Out-Null; \$p.settings.volume=100; \$p.controls.play(); Start-Sleep -Seconds {$durationSec}; \$p.controls.stop(); Remove-Item -LiteralPath '{$escapedPath}' -ErrorAction SilentlyContinue; exit 0 } catch { Write-Output \$_.Exception.Message; Remove-Item -LiteralPath '{$escapedPath}' -ErrorAction SilentlyContinue; exit 1 }";

    $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -Command ' . escapeshellarg($psScript) . ' 2>&1';
    $output = [];
    $exitCode = 1;
    @exec($cmd, $output, $exitCode);

    if ($exitCode !== 0) {
        $err = trim(implode(' | ', $output));
        if ($err === '') $err = 'Playback gagal (kemungkinan service non-interaktif)';
        return [false, $err];
    }

    return [true, null];
}

function jam_should_skip_duplicate($dedupKey, $windowSec = 30) {
    $file = jam_dedup_file();
    $fh = @fopen($file, 'c+');
    if (!$fh) return [false, null];
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return [false, null];
    }

    rewind($fh);
    $raw = stream_get_contents($fh);
    $state = [];
    if ($raw !== false && trim($raw) !== '') {
        $decoded = @json_decode($raw, true);
        if (is_array($decoded)) $state = $decoded;
    }

    $now = time();
    foreach ($state as $k => $ts) {
        if (!is_numeric($ts) || ($now - (int)$ts) > max(1, (int)$windowSec)) {
            unset($state[$k]);
        }
    }

    $skip = false;
    $remaining = null;
    if (isset($state[$dedupKey])) {
        $elapsed = $now - (int)$state[$dedupKey];
        if ($elapsed < (int)$windowSec) {
            $skip = true;
            $remaining = (int)$windowSec - $elapsed;
        }
    }

    if (!$skip) {
        $state[$dedupKey] = $now;
    }

    ftruncate($fh, 0);
    rewind($fh);
    @fwrite($fh, json_encode($state, JSON_UNESCAPED_UNICODE));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    return [$skip, $remaining];
}
