<?php
@ini_set('display_errors', '0');
@error_reporting(0);

require_once __DIR__ . '/../_tts_common.php';

header('Content-Type: application/json; charset=utf-8');
if (!jam_is_api_key_valid()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized: API key invalid'], JSON_UNESCAPED_UNICODE);
    exit;
}

$message = 'Pesanan sudah selesai diproses. Terima kasih atas kerja samanya.';
$speak = isset($_GET['speak']) ? (string)$_GET['speak'] : '0';
$mode = isset($_GET['mode']) ? strtolower((string)$_GET['mode']) : 'both';
$lang = isset($_GET['tl']) ? preg_replace('/[^a-zA-Z\-]/', '', (string)$_GET['tl']) : 'id';
$speed = isset($_GET['ttsspeed']) ? preg_replace('/[^0-9\.]/', '', (string)$_GET['ttsspeed']) : '1';
if ($lang === '') $lang = 'id';
if ($speed === '') $speed = '1';

$spoken = false;
$queued = false;
$queueId = null;
$speakError = null;
$duplicateSkipped = false;
$duplicateWindowSec = 30;
$duplicateRemainingSec = null;

if ($speak === '1') {
    $dedupKey = 'order-selesai|' . md5($message . '|' . $lang . '|' . $speed);
    list($duplicateSkipped, $duplicateRemainingSec) = jam_should_skip_duplicate($dedupKey, $duplicateWindowSec);
    if (!$duplicateSkipped) {
        if ($mode === 'queue') {
            list($queued, $queueId, $queueErr) = jam_enqueue_tts($message, $lang, $speed, 'order-selesai');
            if (!$queued) $speakError = $queueErr;
        } elseif ($mode === 'direct') {
            list($okAudio, $audioBytes, $audioErr) = jam_fetch_tts_audio($message, $lang, $speed);
            if (!$okAudio) {
                $speakError = $audioErr;
            } else {
                list($spoken, $playErr) = jam_play_audio_bytes_windows($audioBytes, $message);
                $speakError = $playErr;
            }
        } else {
            list($okAudio, $audioBytes, $audioErr) = jam_fetch_tts_audio($message, $lang, $speed);
            if ($okAudio) {
                list($spoken, $playErr) = jam_play_audio_bytes_windows($audioBytes, $message);
                if (!$spoken) $speakError = $playErr;
            } else {
                $speakError = $audioErr;
            }
            list($queued, $queueId, $queueErr) = jam_enqueue_tts($message, $lang, $speed, 'order-selesai');
            if (!$queued && $speakError === null) $speakError = $queueErr;
        }
    }
}

echo json_encode([
    'ok' => true,
    'message' => $message,
    'mode' => $mode,
    'spoken' => $spoken,
    'queued' => $queued,
    'queue_id' => $queueId,
    'duplicate_skipped' => $duplicateSkipped,
    'duplicate_window_sec' => $duplicateWindowSec,
    'duplicate_remaining_sec' => $duplicateRemainingSec,
    'speak_error' => $speakError
], JSON_UNESCAPED_UNICODE);


