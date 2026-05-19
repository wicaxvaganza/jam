<?php
@ini_set('display_errors', '1');
@error_reporting(E_ALL);

require_once __DIR__ . '/api/_tts_common.php';

function pop_queue_job() {
    $queue = jam_queue_file();
    if (!file_exists($queue)) {
        @file_put_contents($queue, '');
    }

    $fh = @fopen($queue, 'c+');
    if (!$fh) return [null, 'Gagal membuka queue'];
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return [null, 'Gagal lock queue'];
    }

    rewind($fh);
    $content = stream_get_contents($fh);
    $lines = preg_split('/\r\n|\n|\r/', (string)$content);
    $lines = array_values(array_filter($lines, function($line){ return trim($line) !== ''; }));

    $job = null;
    if (count($lines) > 0) {
        $raw = array_shift($lines);
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['text'])) {
            $job = $decoded;
        }
    }

    ftruncate($fh, 0);
    rewind($fh);
    if (!empty($lines)) fwrite($fh, implode(PHP_EOL, $lines) . PHP_EOL);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    return [$job, null];
}

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Jalankan via CLI desktop session:\n";
    echo "C:\\xampp\\php\\php.exe C:\\xampp\\htdocs\\jam\\player.php\n";
    exit;
}

echo "[PLAYER] started " . date('Y-m-d H:i:s') . PHP_EOL;
jam_append_log('PLAYER started');

while (true) {
    list($job, $err) = pop_queue_job();
    if ($err !== null) {
        jam_append_log('PLAYER queue error: ' . $err);
        echo "[PLAYER] queue error: {$err}" . PHP_EOL;
        sleep(2);
        continue;
    }

    if ($job === null) {
        sleep(1);
        continue;
    }

    $text = (string)($job['text'] ?? '');
    $lang = (string)($job['lang'] ?? 'id');
    $speed = (string)($job['speed'] ?? '1');
    $id = (string)($job['id'] ?? 'unknown');

    echo "[PLAYER] playing {$id}: {$text}" . PHP_EOL;
    jam_append_log('PLAYER playing ' . $id . ' | ' . $text);

    list($okAudio, $audioBytes, $audioErr) = jam_fetch_tts_audio($text, $lang, $speed);
    if (!$okAudio) {
        jam_append_log('PLAYER fetch fail ' . $id . ' | ' . $audioErr);
        echo "[PLAYER] fetch fail {$id}: {$audioErr}" . PHP_EOL;
        continue;
    }

    list($okPlay, $playErr) = jam_play_audio_bytes_windows($audioBytes, $text);
    if (!$okPlay) {
        jam_append_log('PLAYER play fail ' . $id . ' | ' . $playErr);
        echo "[PLAYER] play fail {$id}: {$playErr}" . PHP_EOL;
        continue;
    }

    jam_append_log('PLAYER done ' . $id);
    echo "[PLAYER] done {$id}" . PHP_EOL;
}
