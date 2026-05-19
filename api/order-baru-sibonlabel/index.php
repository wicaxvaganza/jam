<?php
@ini_set('display_errors', '0');
@error_reporting(0);

$apiKey = '123321';

function speak_on_windows_server($text, $lang = 'id', $speed = '1') {
    $text = trim((string)$text);
    if ($text === '') return [false, 'Empty text'];

    $lang = preg_replace('/[^a-zA-Z\-]/', '', (string)$lang);
    if ($lang === '') $lang = 'id';
    $speed = preg_replace('/[^0-9\.]/', '', (string)$speed);
    if ($speed === '') $speed = '1';

    $encoded = rawurlencode($text);
    $url = "https://translate.google.com/translate_tts?ie=UTF-8&q={$encoded}&tl={$lang}&client=tw-ob&ttsspeed={$speed}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Referer: https://translate.google.com/']);
    $audio = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$audio) {
        return [false, "Fetch TTS gagal (HTTP {$httpCode})"];
    }

    $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jam_tts_' . md5($text . microtime(true)) . '.mp3';
    if (@file_put_contents($tmpFile, $audio) === false) {
        return [false, 'Gagal menyimpan file audio sementara'];
    }

    $durationSec = max(4, min(20, (int)ceil(mb_strlen($text) / 11)));
    $escapedPath = str_replace("'", "''", $tmpFile);
    $psScript = "\$p=New-Object -ComObject WMPlayer.OCX; \$m=\$p.newMedia('{$escapedPath}'); \$p.currentPlaylist.appendItem(\$m); \$p.settings.volume=100; \$p.controls.play(); Start-Sleep -Seconds {$durationSec}; \$p.controls.stop(); Remove-Item -LiteralPath '{$escapedPath}' -ErrorAction SilentlyContinue;";
    $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -Command ' . escapeshellarg($psScript);

    @pclose(@popen('start /B "" ' . $cmd, 'r'));
    return [true, null];
}

$requestKey = isset($_GET['api_key']) ? (string)$_GET['api_key'] : '';
if ($requestKey === '' && isset($_SERVER['HTTP_X_API_KEY'])) {
    $requestKey = (string)$_SERVER['HTTP_X_API_KEY'];
}

header('Content-Type: application/json; charset=utf-8');
if ($requestKey !== $apiKey) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized: API key invalid'], JSON_UNESCAPED_UNICODE);
    exit;
}

$speak = isset($_GET['speak']) ? (string)$_GET['speak'] : '0';
$message = 'Ada order baru sibonlabel, cek ya gaes!';
$spoken = false;
$speakError = null;
if ($speak === '1') {
    $lang = isset($_GET['tl']) ? (string)$_GET['tl'] : 'id';
    $speed = isset($_GET['ttsspeed']) ? (string)$_GET['ttsspeed'] : '1';
    list($spoken, $speakError) = speak_on_windows_server($message, $lang, $speed);
}

echo json_encode([
    'ok' => true,
    'message' => $message,
    'spoken' => $spoken,
    'speak_error' => $speakError
], JSON_UNESCAPED_UNICODE);
