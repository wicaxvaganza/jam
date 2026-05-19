<?php 
@ini_set('display_errors', '0');
@error_reporting(0);

// --- config ---
$LOG_PATH = __DIR__ . '/tts_activity.log';
$CLIENT_LOG_PATH = __DIR__ . '/client_error.log'; // log terpisah untuk error client
$MAX_LOG_LINES = 50;
$RATE_LIMIT_PER_MIN = 8;
$MAX_LOG_LINE_LENGTH = 500;

// --- helper: Jakarta timestamp dd-mm-yyyy HH:MM:SS ---
function get_jakarta_ts($ts = null) {
    try {
        if ($ts === null) {
            $dt = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        } else {
            $dt = new DateTime('@' . intval($ts));
            $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
        }
        return $dt->format('d-m-Y H:i:s');
    } catch (Exception $e) {
        return date('d-m-Y H:i:s');
    }
}

// --- sanitize potential malicious/large log content ---
function sanitize_log_line($line, $max_len = 500) {
    $line = preg_replace('/[^\PC\s]/u', '', $line);
    if (stripos($line, '<?php') !== false || stripos($line, '<!doctype') !== false || stripos($line, '<html') !== false) {
        $line = '[skipped large HTML/PHP content] ' . preg_replace('/\s+/', ' ', trim($line));
    }
    $line = str_replace(["\r","\n","\t"], ' ', $line);
    $line = preg_replace('/\s+/', ' ', $line);
    $line = trim($line);
    if (mb_strlen($line) > $max_len) {
        $line = mb_substr($line, 0, $max_len - 3) . '...';
    }
    return $line;
}

// --- append/read log with locking ---
function append_log_line($path, $line, $max_lines = 50, $max_len = 500) {
    $safe = sanitize_log_line($line, $max_len);
    $fh = @fopen($path, 'c+');
    if (!$fh) return false;
    flock($fh, LOCK_EX);
    fseek($fh, 0);
    $content = stream_get_contents($fh);
    $lines = ($content === false || trim($content) === '') ? [] : preg_split("/\r\n|\n|\r/", trim($content));
    if (count($lines) === 1 && $lines[0] === '') $lines = [];
    array_unshift($lines, $safe);
    $lines = array_slice($lines, 0, $max_lines);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, implode(PHP_EOL, $lines) . PHP_EOL);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return true;
}

function read_log_lines($path, $max_lines = 50) {
    if (!file_exists($path)) return [];
    $content = @file_get_contents($path);
    if ($content === false || trim($content) === '') return [];
    $lines = preg_split("/\r\n|\n|\r/", trim($content));
    if (count($lines) === 1 && $lines[0] === '') return [];
    return array_slice($lines, 0, $max_lines);
}

function filter_log_lines_today($lines) {
    try {
        $today = (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('d-m-Y');
    } catch (Exception $e) {
        $today = date('d-m-Y');
    }
    $out = [];
    foreach ($lines as $line) {
        if (preg_match('/^(\d{2}-\d{2}-\d{4})\b/u', $line, $m)) {
            if ($m[1] === $today) $out[] = $line;
        } else {
            // baris tanpa prefix tanggal tetap ditampilkan
            $out[] = $line;
        }
    }
    return $out;
}

// --- viewlog endpoint ---
if (isset($_GET['viewlog'])) {
    header('Content-Type: application/json; charset=utf-8');
    $type = isset($_GET['type']) ? strtolower((string)$_GET['type']) : 'tts';
    $path = ($type === 'client') ? $CLIENT_LOG_PATH : $LOG_PATH;
    $lines = read_log_lines($path, $MAX_LOG_LINES);
    $daily = isset($_GET['daily']) ? (string)$_GET['daily'] : '0';
    if ($daily === '1') {
        $lines = filter_log_lines_today($lines);
    }
    $out = @json_encode(['lines' => $lines], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($out === false) {
        $safe = array_map(function($l){ return mb_convert_encoding($l, 'UTF-8', 'UTF-8'); }, $lines);
        echo json_encode(['lines' => $safe], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo $out;
    exit;
}

// --- client log endpoint ---
if (isset($_GET['clientlog'])) {
    $msg = isset($_GET['msg']) ? (string)$_GET['msg'] : 'clientlog';
    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $line = get_jakarta_ts() . " | $ip | CLIENT | " . sanitize_log_line($msg, 500);
    @file_put_contents($CLIENT_LOG_PATH, $line . PHP_EOL, FILE_APPEND);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true]);
    exit;
}

// --- kaget log endpoint (masuk ke tts_activity.log) ---
if (isset($_GET['kagetlog'])) {
    $msg = isset($_GET['msg']) ? (string)$_GET['msg'] : 'kaget';
    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $line = get_jakarta_ts() . " | $ip | KAGET | " . sanitize_log_line($msg, $MAX_LOG_LINE_LENGTH);
    append_log_line($LOG_PATH, $line, $MAX_LOG_LINES, $MAX_LOG_LINE_LENGTH);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true]);
    exit;
}

/* ========= Adzan proxy (Banyuwangi Kota) + JSON cache harian ========= */
if (isset($_GET['adzan'])) {
    header('Content-Type: application/json; charset=utf-8');

    $city = 'Banyuwangi';
    $kotaIdBanyuwangi = '1602';

    try {
        $dtJakarta = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    } catch (Exception $e) {
        $dtJakarta = new DateTime('now');
    }
    $dateKey = $dtJakarta->format('Y-m-d');
    $year = $dtJakarta->format('Y');
    $month = $dtJakarta->format('m');
    $day = $dtJakarta->format('d');

    $cacheDir = __DIR__ . '/cache_adzan';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }

    $safeCity = preg_replace('/[^A-Za-z0-9_\-]/', '_', $city);
    $cacheFile = $cacheDir . "/adzan_{$safeCity}_{$dateKey}_myquran.json";

    if (file_exists($cacheFile)) {
        $cached = @file_get_contents($cacheFile);
        if ($cached !== false && trim($cached) !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded) && isset($decoded['data']['timings'])) {
                echo $cached;
                exit;
            }
        }
    }

    $url = "https://api.myquran.com/v2/sholat/jadwal/{$kotaIdBanyuwangi}/{$year}/{$month}/{$day}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $result = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$result) {
        http_response_code(502);
        echo json_encode([
            "ok"    => false,
            "error" => "Failed to fetch adzan timings",
            "code"  => $code
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $decoded = json_decode($result, true);
    $jadwal = is_array($decoded) && isset($decoded['data']['jadwal']) && is_array($decoded['data']['jadwal'])
        ? $decoded['data']['jadwal']
        : null;

    if (!$jadwal) {
        http_response_code(502);
        echo json_encode([
            "ok"    => false,
            "error" => "Invalid adzan payload from source"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Normalize MyQuran payload to existing UI contract: data.timings.*
    $normalized = [
        "ok" => true,
        "source" => "myquran",
        "data" => [
            "city" => $decoded['data']['lokasi'] ?? $city,
            "timings" => [
                "Imsak" => $jadwal['imsak'] ?? null,
                "Fajr" => $jadwal['subuh'] ?? null,
                "Sunrise" => $jadwal['terbit'] ?? null,
                "Dhuhr" => $jadwal['dzuhur'] ?? ($jadwal['dhuhr'] ?? null),
                "Asr" => $jadwal['ashar'] ?? null,
                "Maghrib" => $jadwal['maghrib'] ?? null,
                "Isha" => $jadwal['isya'] ?? null,
                "Midnight" => null,
                "Firstthird" => null,
                "Lastthird" => null
            ],
            "date" => [
                "gregorian" => [
                    "date" => $jadwal['date'] ?? $dateKey
                ]
            ]
        ]
    ];

    $normalizedJson = json_encode($normalized, JSON_UNESCAPED_UNICODE);
    if ($normalizedJson !== false) {
        @file_put_contents($cacheFile, $normalizedJson, LOCK_EX);
        echo $normalizedJson;
    } else {
        http_response_code(500);
        echo json_encode([
            "ok"    => false,
            "error" => "Failed to encode adzan payload"
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// --- TTS fetcher ---
if (isset($_GET['text'])) {
    $text = trim((string) $_GET['text']);
    if ($text === '') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Missing text";
        exit;
    }
    $tl = isset($_GET['tl']) ? preg_replace('/[^a-zA-Z\-]/','',$_GET['tl']) : 'id';
    $ttsspeed = isset($_GET['ttsspeed']) ? preg_replace('/[^0-9\.]/','',$_GET['ttsspeed']) : '1';

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ratefile = sys_get_temp_dir() . "/tts_rate_" . md5($ip) . ".json";
    $now = time();
    $timestamps = [];
    if (file_exists($ratefile)) {
        $raw = @file_get_contents($ratefile);
        $timestamps = $raw ? json_decode($raw, true) : [];
        if (!is_array($timestamps)) $timestamps = [];
        $timestamps = array_filter($timestamps, function($t) use ($now){ return ($now - $t) < 60; });
    }
    if (count($timestamps) >= $RATE_LIMIT_PER_MIN) {
        http_response_code(429);
        header('Content-Type: text/plain; charset=utf-8');
        append_log_line($LOG_PATH, get_jakarta_ts() . " | $ip | RATE_LIMIT | " . sanitize_log_line(mb_substr($text,0,200)));
        echo "Rate limit. Try later.";
        exit;
    }
    $timestamps[] = $now;
    @file_put_contents($ratefile, json_encode(array_values($timestamps)));

    $encoded = rawurlencode($text);
    $url = "https://translate.google.com/translate_tts?ie=UTF-8&q={$encoded}&tl={$tl}&client=tw-ob&ttsspeed={$ttsspeed}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64)");
    curl_setopt($ch, CURLOPT_ENCODING, "");
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Referer: https://translate.google.com/"]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = $result ? strlen($result) : 0;
    curl_close($ch);

    $safeText = sanitize_log_line($text, 200);
    $statusText = ($httpCode == 200) ? 'OK' : 'FAIL';
    $sizeKB = $size ? round($size / 1024, 2) . ' KB' : '0 KB';
    $logLine = get_jakarta_ts() . " | " . $ip . " | " . $statusText . " | " . $sizeKB . " | " . $safeText;
    append_log_line($LOG_PATH, $logLine, $MAX_LOG_LINES, $MAX_LOG_LINE_LENGTH);

    if ($httpCode !== 200 || !$result) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Failed to fetch TTS (code: $httpCode).";
        exit;
    }

    header("Content-Type: audio/mpeg");
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
    echo $result;
    exit;
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>bot ngomong</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
  @keyframes eventBlink {
    0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.0); }
    50% { opacity: 0.65; box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.25); }
  }
  .event-blink {
    animation: eventBlink 1.2s ease-in-out infinite;
  }
</style>
</head>
<body class="bg-slate-50 text-slate-900">
  <div class="max-w-4xl mx-auto p-5">
    <div class="flex items-start gap-3">
      <div>
        <h1 class="text-xl font-semibold">bot ngomong</h1>
        <p class="text-slate-500 text-sm">
          Klik <strong>Start</strong> sekali agar browser memberi izin suara. Biarkan tab terbuka.
          Server menyimpan log (maks <?php echo $MAX_LOG_LINES;?> baris).
        </p>
      </div>
      <div class="ml-auto flex items-center gap-2">
        <span class="text-slate-500 text-sm">Status:</span>
        <span id="statusBadge" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-emerald-700 text-sm">Stopped</span>
      </div>
    </div>

    <!-- Bar Atas -->
    <div class="mt-4 flex flex-wrap items-center gap-2">
      <button id="btnStart" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-[15px] shadow-sm hover:bg-slate-50">Start</button>
      <button id="btnStop" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-[15px] shadow-sm hover:bg-slate-50 hidden">Stop</button>

      <button id="btnTest" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm hover:bg-slate-100">
        Tes Sekarang
      </button>
      <button id="btnTestHalfHour" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm hover:bg-slate-100">
        Test 30m
      </button>
      <button id="btnTestPulang5" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm hover:bg-slate-100">
        Test Pulang+5
      </button>
      <button id="btnTestPantunPulang" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm hover:bg-slate-100">
        Test Pantun
      </button>
      <span class="text-sm text-slate-600 ml-1">
        • <span id="liveClock" class="font-medium text-slate-800">--:--:--</span>
      </span>

      <!-- Toggle ringkas -->
      <div class="ml-auto flex flex-wrap items-center gap-1.5">
        <input type="checkbox" id="optTest1Min" class="peer/one hidden">
        <label for="optTest1Min" class="px-2.5 py-1.5 text-xs rounded-md border bg-white hover:bg-slate-50 cursor-pointer border-slate-200 text-slate-700 peer-checked/one:bg-emerald-600 peer-checked/one:text-white peer-checked/one:border-emerald-600">1m</label>

        <input type="checkbox" id="optHourly" checked class="peer/hour hidden">
        <label for="optHourly" class="px-2.5 py-1.5 text-xs rounded-md border bg-white hover:bg-slate-50 cursor-pointer border-slate-200 text-slate-700 peer-checked/hour:bg-emerald-600 peer-checked/hour:text-white peer-checked/hour:border-emerald-600">Hourly</label>

        <input type="checkbox" id="optHalfHourly" checked class="peer/half hidden">
        <label for="optHalfHourly" class="px-2.5 py-1.5 text-xs rounded-md border bg-white hover:bg-slate-50 cursor-pointer border-slate-200 text-slate-700 peer-checked/half:bg-orange-600 peer-checked/half:text-white peer-checked/half:border-orange-600">Half30</label>

        <input type="checkbox" id="optPresensi" checked class="peer/presensi hidden">
        <label for="optPresensi" class="px-2.5 py-1.5 text-xs rounded-md border bg-white hover:bg-slate-50 cursor-pointer border-slate-200 text-slate-700 peer-checked/presensi:bg-emerald-600 peer-checked/presensi:text-white peer-checked/presensi:border-emerald-600">Presensi</label>

        <input type="checkbox" id="optSholat" checked class="peer/sholat hidden">
        <label for="optSholat" class="px-2.5 py-1.5 text-xs rounded-md border bg-white hover:bg-slate-50 cursor-pointer border-slate-200 text-slate-700 peer-checked/sholat:bg-emerald-600 peer-checked/sholat:text-white peer-checked/sholat:border-emerald-600">Sholat</label>

        <!-- Auto Reload -->
        <input type="checkbox" id="optAutoReload" checked class="peer/reload hidden">
        <label for="optAutoReload" class="px-2.5 py-1.5 text-xs rounded-md border bg-white hover:bg-slate-50 cursor-pointer border-slate-200 text-slate-700 peer-checked/reload:bg-amber-500 peer-checked/reload:text-white peer-checked/reload:border-amber-500">
          Auto Reload
        </label>
      </div>
    </div>

    <!-- Countdown -->
    <div class="mt-3 flex items-center gap-3">
      <span class="text-slate-500 text-sm">Countdown event:</span>
      <span id="countdown" class="font-bold text-lg text-emerald-700">--:--:--</span>
      <span class="text-slate-400">•</span>
      <span id="nextEventLabel" class="text-slate-500 text-sm">-</span>
    </div>
    <div class="mt-1 text-slate-500 text-sm">
      Mode: <span id="modeInfo" class="font-medium text-slate-700">-</span>
    </div>
    <div class="mt-1 text-slate-500 text-sm">
      Reload halaman dalam: <span id="reloadInfo" class="font-medium text-slate-700">-</span>
    </div>

    <!-- Tabs -->
    <div class="mt-5">
      <div role="tablist" class="inline-flex rounded-lg border border-slate-200 bg-white p-1">
        <button id="tabPresensi" role="tab" aria-selected="true" class="px-3 py-1.5 text-sm rounded-md data-[active=true]:bg-slate-900 data-[active=true]:text-white" data-active="true">Presensi Alerts</button>
        <button id="tabSholat" role="tab" aria-selected="false" class="px-3 py-1.5 text-sm rounded-md text-slate-600 hover:text-slate-800">Sholat Alerts</button>
        <button id="tabMonitoring" role="tab" aria-selected="false" class="px-3 py-1.5 text-sm rounded-md text-slate-600 hover:text-slate-800">Monitoring</button>
      </div>

      <!-- Panel Presensi -->
      <div id="panelPresensi" role="tabpanel" class="mt-3">
        <div class="flex flex-col gap-2" id="presensiList"></div>
      </div>

      <!-- Panel Sholat -->
      <div id="panelSholat" role="tabpanel" class="mt-3 hidden">
        <div class="flex items-center justify-between gap-3">
          <h3 class="font-semibold">Banyuwangi Kota (Hari Ini)</h3>
          <div class="text-xs text-slate-500">
            Terakhir diperbarui: <span id="sholatLastUpdate">-</span>
          </div>
        </div>
        <div class="mt-2 flex flex-col gap-2" id="sholatList"></div>
      </div>

      <!-- Panel Monitoring -->
      <div id="panelMonitoring" role="tabpanel" class="mt-3 hidden">
        <div class="max-w-md rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <div class="flex items-center justify-between gap-3">
            <h3 class="text-lg font-semibold text-slate-800">Event Berikutnya</h3>
            <div class="text-right">
              <div id="monitorNextCountdown" class="text-2xl font-bold text-emerald-700">--:--:--</div>
              <div id="monitorNextDate" class="text-xs text-slate-500">-</div>
            </div>
          </div>
          <div id="monitorEventList" class="mt-3 flex max-h-80 flex-col gap-2 overflow-y-auto pr-1"></div>
        </div>
        <div class="mt-3 max-w-md rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <h3 class="text-base font-semibold text-slate-800">Riwayat Notifikasi Sibonlabel</h3>
          <div id="monitorOrderReads" class="mt-2 flex max-h-56 flex-col gap-2 overflow-y-auto pr-1"></div>
        </div>
      </div>
    </div>

    <!-- Log -->
    <div class="mt-5">
      <div class="flex flex-wrap items-center gap-2">
        <div id="logTitle" class="text-slate-500 text-sm">Log server harian (terbaru paling atas, maksimal <?php echo $MAX_LOG_LINES;?> baris)</div>
        <div class="ml-auto inline-flex rounded-lg border border-slate-200 bg-white p-1">
          <button id="tabLogTts" class="px-3 py-1.5 text-xs rounded-md bg-slate-900 text-white">TTS Activity</button>
          <button id="tabLogClient" class="px-3 py-1.5 text-xs rounded-md text-slate-600 hover:text-slate-800">Client Error</button>
        </div>
      </div>
      <div id="log" class="mt-2 max-h-56 overflow-auto whitespace-pre-wrap rounded-lg border border-slate-200 bg-slate-50 p-3 font-mono text-sm text-slate-700">Memuat log...</div>
      <div class="mt-2 text-slate-500 text-sm">Log harian di-refresh otomatis tiap 10 detik.</div>
    </div>
  </div>

<script>
(function(){
  // ====== Presensi Alerts (static) ======
  const presensiAlerts = [
    { id: 'datang_mon_thu', label: 'Presensi Datang (Sen–Kam)', days: [1,2,3,4], time: '06:45' },
    { id: 'pulang_mon_thu', label: 'Presensi Pulang (Sen–Kam)', days: [1,2,3,4], time: '14:00' },
    { id: 'datang_fri',     label: 'Presensi Datang (Jumat)',   days: [5],       time: '07:00' },
    { id: 'pulang_fri',     label: 'Presensi Pulang (Jumat)',   days: [5],       time: '11:00' },
    { id: 'datang_sat',     label: 'Presensi Datang (Sabtu)',   days: [6],       time: '06:45' },
    { id: 'pulang_sat',     label: 'Presensi Pulang (Sabtu)',   days: [6],       time: '12:30' }
  ];
  const presensiPulangAlerts = presensiAlerts.filter(a => (a.label||'').toLowerCase().includes('pulang'));
  const pulangSyahduMessages = [
    'Selamat pulang ya, hati-hati di jalan dan jangan lupa berdoa.',
    'Selamat pulang kerja, hati-hati di perjalanan, jangan lupa berdoa supaya lancar sampai rumah.',
    'Hati-hati di jalan ya, selamat pulang dan jangan lupa berdoa dulu sebelum berangkat.',
    'Selamat pulang, semoga perjalananmu aman. Jangan lupa berdoa ya.',
    'Selamat pulang yaa, hati-hati di jalan, jangan lupa berdoa biar selalu dilindungi.',
    'Selamat pulang, jaga diri baik-baik di jalan dan jangan lupa berdoa.',
    'Hati-hati di perjalanan pulangnya, selamat sampai tujuan dan jangan lupa berdoa.',
    'Selamat pulang, semoga Allah jaga setiap langkahmu. Hati-hati di jalan dan jangan lupa berdoa.',
    'Selamat pulang ya, semoga lancar tanpa hambatan. Jangan lupa berdoa sebelum jalan.',
    'Hati-hati ya di jalan, selamat pulang dan semoga selalu dalam perlindungan-Nya. Jangan lupa berdoa.',
    'Selamat pulang, pelan-pelan di jalan dan jangan lupa berdoa dulu ya.',
    'Selamat pulang kerja, hati-hati di jalan, jangan lupa berdoa agar selamat sampai rumah.'
  ];
  const pantunPulangMessages = [
    'Pagi-pagi belanja sukun, singgah sebentar membeli jamu. Sebelum beranjak menutup hari, jangan lupa absen pulang dulu.',
    'Terbang rendah burung merpati, hinggap sebentar di pagar kampung. Tas sudah siap mari berhenti, klik absen pulang di Smart Kampung.',
    'Jalan-jalan ke kota Wangi, pulangnya mampir membeli karung. Kerja seharian jangan rugi, absen pulang dulu di Smart Kampung.',
    'Senja tiba langit kemerahan, angin berembus sejuk tak murung. Supaya jalan terasa nyaman, jangan lupa absen di Smart Kampung.',
    'Membeli paku di toko ujung, disimpan rapi di dalam karung. Sebelum melangkah keluar gedung, sempatkan absen di Smart Kampung.'
  ];

  // Build Presensi list UI
  const presensiListEl = document.getElementById('presensiList');
  presensiAlerts.forEach(a => {
    const row = document.createElement('div'); 
    row.className = 'presensi-row flex items-center gap-2';
    const desc = document.createElement('div'); 
    desc.className='rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm';
    const daysNames = a.days.map(d => ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d]).join(',');
    desc.textContent = `${a.time} — ${a.label} (${daysNames})`;
    const btn = document.createElement('button'); 
    btn.className='ml-auto rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm hover:bg-slate-100';
    btn.textContent='Test';
    btn.addEventListener('click', async ()=> {
      if(btn.dataset.running === '1'){
        stopCurrentPlayback();
        return;
      }
      try { 
        const kal = buildPresensiAnnouncementForHHMM(a);
        const status = await playText(kal, {
          trackAsTest: true,
          onStart: ()=>{ btn.dataset.running='1'; setInlineTestButtonState(btn, true); },
          onFinish: ()=>{ btn.dataset.running='0'; setInlineTestButtonState(btn, false); }
        });
        if(status === 'ok') fetchLogsAfterDelay();
      } catch(e){ alert('Gagal memutar test: '+e); }
    });
    row.appendChild(desc); row.appendChild(btn); presensiListEl.appendChild(row);
  });

  // ====== Elements ======
  const btnStart=document.getElementById('btnStart'),
        btnStop=document.getElementById('btnStop'),
        btnTest=document.getElementById('btnTest'),
        btnTestHalfHour=document.getElementById('btnTestHalfHour'),
        btnTestPulang5=document.getElementById('btnTestPulang5'),
        btnTestPantunPulang=document.getElementById('btnTestPantunPulang');
  const optTest1Min=document.getElementById('optTest1Min'),
        optHourly=document.getElementById('optHourly'),
        optHalfHourly=document.getElementById('optHalfHourly'),
        optPresensi=document.getElementById('optPresensi'),
        optSholat=document.getElementById('optSholat'),
        optAutoReload=document.getElementById('optAutoReload');
  const countdownEl=document.getElementById('countdown'),
        statusBadge=document.getElementById('statusBadge'),
        modeInfo=document.getElementById('modeInfo'),
        logEl=document.getElementById('log'),
        logTitleEl=document.getElementById('logTitle'),
        tabLogTts=document.getElementById('tabLogTts'),
        tabLogClient=document.getElementById('tabLogClient'),
        liveClockEl=document.getElementById('liveClock'),
        reloadInfoEl=document.getElementById('reloadInfo');
  const sholatListEl=document.getElementById('sholatList');

  // Tabs
  const tabPresensi=document.getElementById('tabPresensi');
  const tabSholat=document.getElementById('tabSholat');
  const tabMonitoring=document.getElementById('tabMonitoring');
  const panelPresensi=document.getElementById('panelPresensi');
  const panelSholat=document.getElementById('panelSholat');
  const panelMonitoring=document.getElementById('panelMonitoring');
  const monitorNextCountdownEl=document.getElementById('monitorNextCountdown');
  const monitorNextDateEl=document.getElementById('monitorNextDate');
  const monitorEventListEl=document.getElementById('monitorEventList');
  const monitorOrderReadsEl=document.getElementById('monitorOrderReads');

  function setActiveTab(which){
    const tabs = [
      { key:'presensi', btn:tabPresensi, panel:panelPresensi },
      { key:'sholat', btn:tabSholat, panel:panelSholat },
      { key:'monitoring', btn:tabMonitoring, panel:panelMonitoring }
    ];
    tabs.forEach(t=>{
      if(!t.btn || !t.panel) return;
      const active = t.key === which;
      t.btn.dataset.active = active ? 'true' : 'false';
      t.btn.setAttribute('aria-selected', active ? 'true' : 'false');
      t.btn.classList.toggle('text-white', active);
      t.btn.classList.toggle('bg-slate-900', active);
      t.btn.classList.toggle('text-slate-600', !active);
      t.panel.classList.toggle('hidden', !active);
    });
  }
  tabPresensi.addEventListener('click', ()=> setActiveTab('presensi'));
  tabSholat.addEventListener('click', ()=> setActiveTab('sholat'));
  tabMonitoring.addEventListener('click', ()=> setActiveTab('monitoring'));

  // ====== State ======
  let started=false,intervalId=null,nextRunTimestamp=null,countdownInterval=null,lastPlayedHour=null,lastPlayedHalfHourKey=null,lastKagetDeferKey=null,hourlyIntervalId=null,logRefreshIntervalId=null,presensiCheckIntervalId=null,sholatCheckIntervalId=null,orderCheckIntervalId=null,midnightResetTimeoutId=null,sholatRefreshTimeoutId=null,watchdogIntervalId=null,monitoringIntervalId=null;
  let activeTestPlayback=null,isTestRunning=false;
  const activePlaybacks = new Set();
  let playedAlerts={},nextRunLabel=null;
  let playedPulangPlus5={},lastPulangSyahduIndex=-1;
  let pendingPulangPlus5 = new Set(), lastPantunPulangIndex = -1;
  let countdownStuckReported = false; // untuk log countdown stuck
  let schedulerHeartbeatTs = Date.now();
  let schedulerHeartbeatSource = 'init';
  let fireRunBusy = false;
  let lastFireRunMinuteKey = null;
  let orderNotifierPrimed = false;
  let orderAnnouncementBusy = false;
  const orderAnnouncementQueue = [];
  const pendingOrderAnnouncements = { new_order: {}, completed_order: {} };
  const orderReadHistory = [];
  const ORDER_READ_HISTORY_LIMIT = 20;
  const PLAYBACK_TIMEOUT_MS = 60000;
  const ORDER_STATUS_URL = 'https://sibonlabel.rsudblambangan.id/status';
  const ORDER_STATUS_INTERVAL_MS = 5000;
  const ORDER_SPOKEN_KEY = 'botngomong_spoken_orders_v1';
  const ORDER_MAX_SPOKEN_IDS = 500;
  const HOLIDAY_CACHE_KEY_PREFIX = 'libur_nasional_v1_';
  const HOLIDAY_CACHE_META_KEY_PREFIX = 'libur_nasional_meta_v1_';
  const HOLIDAY_CACHE_TTL_MS = 7 * 24 * 60 * 60 * 1000;
  const nationalHolidayByYear = {};

  // Sholat state
  let sholatTimings=null; // semua waktu sholat & ekstra
  let playedSholat={};   // { 'YYYY-MM-DD': {Key:1, ...} }
  let sholatFetchErrorAnnouncedDate = null;

  // Reload state
  let reloadTargetTimestamp=null;
  let reloadCountdownInterval=null;

  // ====== Simpan & load posisi checklist ke localStorage ======
  const CHECKBOX_STATE_KEY = 'botngomong_checkbox_state_v1';

  function saveCheckboxState(){
    try{
      const state = {
        test1Min: !!(optTest1Min && optTest1Min.checked),
        hourly: !!(optHourly && optHourly.checked),
        halfHourly: !!(optHalfHourly && optHalfHourly.checked),
        presensi: !!(optPresensi && optPresensi.checked),
        sholat: !!(optSholat && optSholat.checked),
        autoReload: !!(optAutoReload && optAutoReload.checked)
      };
      localStorage.setItem(CHECKBOX_STATE_KEY, JSON.stringify(state));
    }catch(_){}
  }

  function loadCheckboxState(){
    try{
      const raw = localStorage.getItem(CHECKBOX_STATE_KEY);
      if(!raw) return;
      const s = JSON.parse(raw);
      if(optTest1Min && typeof s.test1Min === 'boolean') optTest1Min.checked = s.test1Min;
      if(optHourly && typeof s.hourly === 'boolean') optHourly.checked = s.hourly;
      if(optHalfHourly && typeof s.halfHourly === 'boolean') optHalfHourly.checked = s.halfHourly;
      if(optPresensi && typeof s.presensi === 'boolean') optPresensi.checked = s.presensi;
      if(optSholat && typeof s.sholat === 'boolean') optSholat.checked = s.sholat;
      if(optAutoReload && typeof s.autoReload === 'boolean') optAutoReload.checked = s.autoReload;
    }catch(_){}
  }

  function loadSpokenOrders(){
    try{
      const raw = localStorage.getItem(ORDER_SPOKEN_KEY);
      if(!raw) return { new_order: {}, completed_order: {} };
      const parsed = JSON.parse(raw);
      return {
        new_order: (parsed && parsed.new_order && typeof parsed.new_order === 'object') ? parsed.new_order : {},
        completed_order: (parsed && parsed.completed_order && typeof parsed.completed_order === 'object') ? parsed.completed_order : {}
      };
    }catch(_){
      return { new_order: {}, completed_order: {} };
    }
  }

  function saveSpokenOrders(state){
    try{
      localStorage.setItem(ORDER_SPOKEN_KEY, JSON.stringify(state));
    }catch(_){}
  }

  function trimSpokenOrderMap(map){
    const keys = Object.keys(map || {});
    if(keys.length <= ORDER_MAX_SPOKEN_IDS) return map;
    const sorted = keys.sort((a,b)=> (map[b] || 0) - (map[a] || 0));
    const next = {};
    sorted.slice(0, ORDER_MAX_SPOKEN_IDS).forEach(k=>{ next[k] = map[k]; });
    return next;
  }

  let spokenOrders = loadSpokenOrders();

  function hasSpokenOrder(type, id){
    const t = type === 'completed_order' ? 'completed_order' : 'new_order';
    const key = String(id || '').trim();
    if(!key) return false;
    return !!(spokenOrders[t] && spokenOrders[t][key]);
  }

  function markOrderSpoken(type, id){
    const t = type === 'completed_order' ? 'completed_order' : 'new_order';
    const key = String(id || '').trim();
    if(!key) return;
    if(!spokenOrders[t]) spokenOrders[t] = {};
    spokenOrders[t][key] = Date.now();
    spokenOrders[t] = trimSpokenOrderMap(spokenOrders[t]);
    saveSpokenOrders(spokenOrders);
  }

  function isOrderAnnouncementPending(type, id){
    const t = type === 'completed_order' ? 'completed_order' : 'new_order';
    const key = String(id || '').trim();
    if(!key) return false;
    return !!(pendingOrderAnnouncements[t] && pendingOrderAnnouncements[t][key]);
  }

  function setOrderAnnouncementPending(type, id, pending){
    const t = type === 'completed_order' ? 'completed_order' : 'new_order';
    const key = String(id || '').trim();
    if(!key) return;
    if(!pendingOrderAnnouncements[t]) pendingOrderAnnouncements[t] = {};
    if(pending) pendingOrderAnnouncements[t][key] = 1;
    else delete pendingOrderAnnouncements[t][key];
  }

  function waitForPlaybackIdle(maxWaitMs = 120000){
    const startedAt = Date.now();
    return new Promise(resolve=>{
      const tick = ()=>{
        const idle = !fireRunBusy && activePlaybacks.size === 0;
        if(idle) return resolve(true);
        if((Date.now() - startedAt) >= maxWaitMs) return resolve(false);
        setTimeout(tick, 300);
      };
      tick();
    });
  }

  function pushOrderReadHistory(text){
    const safe = (text || '').toString().trim();
    if(!safe) return;
    orderReadHistory.unshift({ ts: Date.now(), text: safe });
    if(orderReadHistory.length > ORDER_READ_HISTORY_LIMIT){
      orderReadHistory.length = ORDER_READ_HISTORY_LIMIT;
    }
  }

  function renderOrderReadHistory(){
    if(!monitorOrderReadsEl) return;
    monitorOrderReadsEl.innerHTML = '';
    if(!orderReadHistory.length){
      const empty = document.createElement('div');
      empty.className = 'rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500';
      empty.textContent = 'Belum ada notifikasi sibonlabel yang dibacakan.';
      monitorOrderReadsEl.appendChild(empty);
      return;
    }
    orderReadHistory.forEach((it)=>{
      const row = document.createElement('div');
      row.className = 'rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700';
      row.textContent = `${fmtHHMM(it.ts)} - ${it.text}`;
      monitorOrderReadsEl.appendChild(row);
    });
  }

  async function processOrderAnnouncementQueue(){
    if(orderAnnouncementBusy) return;
    orderAnnouncementBusy = true;
    try{
      while(orderAnnouncementQueue.length){
        const item = orderAnnouncementQueue[0];
        const idle = await waitForPlaybackIdle();
        if(!idle) break;
        try{
          const status = await playText(item.text, { ttsSpeed: '0.92' });
          if(status === 'ok'){
            markOrderSpoken(item.type, item.id);
            pushOrderReadHistory(item.text);
            renderOrderReadHistory();
            fetchLogsAfterDelay();
          }
        }catch(e){
          logClient('order announce error: ' + errMessage(e));
        } finally {
          setOrderAnnouncementPending(item.type, item.id, false);
          orderAnnouncementQueue.shift();
        }
      }
    } finally {
      orderAnnouncementBusy = false;
    }
  }

  function enqueueOrderAnnouncement(type, id, text){
    if(!id || !text) return;
    orderAnnouncementQueue.push({ type, id, text });
    processOrderAnnouncementQueue();
  }

  function composeOrderSpeech(type, name, room, fallbackMessage){
    const cleanName = normalizeOrderSpeechText(name, { titleCaseWords: true });
    const cleanRoom = normalizeRoomSpeechText(room);
    const isCompleted = type === 'completed_order';
    if(cleanName && cleanRoom){
      return isCompleted
        ? `Order dari ${cleanName}, ruang ${cleanRoom}, telah diselesaikan.`
        : `Ada order baru, dari ${cleanName}, ruang ${cleanRoom}.`;
    }
    if(cleanName){
      return isCompleted
        ? `Order dari ${cleanName}, telah diselesaikan.`
        : `Ada order baru, dari ${cleanName}.`;
    }
    if(cleanRoom){
      return isCompleted
        ? `Order ruang ${cleanRoom}, telah diselesaikan.`
        : `Ada order baru, untuk ruang ${cleanRoom}.`;
    }
    const cleanFallback = normalizeOrderSpeechText(fallbackMessage, { titleCaseWords: false });
    return cleanFallback || (isCompleted ? 'Ada order yang telah diselesaikan.' : 'Ada order baru masuk.');
  }

  function normalizeOrderSpeechText(value, opts={}){
    const { titleCaseWords=false } = opts;
    let t = (value || '').toString();
    t = t.replace(/[_]+/g, ' ');
    t = t.replace(/[|]+/g, ', ');
    t = t.replace(/[&]/g, ' dan ');
    t = t.replace(/[/]+/g, ' ');
    t = t.replace(/[(){}\[\]<>]/g, ' ');
    t = t.replace(/\s+/g, ' ').trim();
    if(!t) return '';
    if(titleCaseWords || /^[A-Z0-9\s.,-]+$/.test(t)){
      const minorWords = new Set(['dan','di','ke','dari','untuk','atas','bawah','rawat','jalan']);
      const words = t.toLowerCase().split(' ').map((w, idx)=>{
        if(!w) return w;
        if(minorWords.has(w) && idx > 0) return w;
        return w.charAt(0).toUpperCase() + w.slice(1);
      });
      t = words.join(' ');
    }
    return t;
  }

  function normalizeRoomSpeechText(room){
    let t = normalizeOrderSpeechText(room, { titleCaseWords: true });
    if(!t) return '';
    const map = {
      'IGD': 'instalasi gawat darurat',
      'ICU': 'I C U',
      'NICU': 'N I C U',
      'PICU': 'P I C U',
      'HCU': 'H C U',
      'ICCU': 'I C C U',
      'OK': 'kamar operasi',
      'VK': 'ruang V K',
      'RJ': 'rawat jalan',
      'RI': 'rawat inap',
      'RWJ': 'rawat jalan',
      'IRNA': 'rawat inap'
    };
    t = t.split(' ').map(token=>{
      const key = token.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
      if(map[key]) return map[key];
      return token;
    }).join(' ');
    t = t.replace(/\s+/g, ' ').trim();
    return t;
  }

  function extractOrderFields(section){
    if(!section || typeof section !== 'object') return { id: '', name: '', room: '', message: '' };
    const id = section.latest_id ?? section.id ?? section.order_id ?? '';
    const name = section.order_name ?? section.nama_order ?? section.nama ?? section.patient_name ?? section.pasien ?? '';
    const room = section.nama_ruang ?? section.ruang ?? section.room ?? section.room_name ?? section.lokasi ?? '';
    const message = section.message ?? '';
    return { id: String(id || '').trim(), name, room, message };
  }

  async function checkOrderStatusLoop(){
    if(!started) return;
    try{
      const resp = await fetch(ORDER_STATUS_URL, { cache: 'no-store' });
      if(!resp.ok) throw new Error('HTTP '+resp.status);
      const data = await resp.json();
      const newOrder = extractOrderFields(data && data.new_order);
      const completedOrder = extractOrderFields(data && data.completed_order);

      if(!orderNotifierPrimed){
        if(newOrder.id) markOrderSpoken('new_order', newOrder.id);
        if(completedOrder.id) markOrderSpoken('completed_order', completedOrder.id);
        orderNotifierPrimed = true;
        return;
      }

      if(newOrder.id && data?.new_order?.exists && !hasSpokenOrder('new_order', newOrder.id) && !isOrderAnnouncementPending('new_order', newOrder.id)){
        setOrderAnnouncementPending('new_order', newOrder.id, true);
        enqueueOrderAnnouncement(
          'new_order',
          newOrder.id,
          composeOrderSpeech('new_order', newOrder.name, newOrder.room, newOrder.message)
        );
      }

      if(completedOrder.id && data?.completed_order?.exists && !hasSpokenOrder('completed_order', completedOrder.id) && !isOrderAnnouncementPending('completed_order', completedOrder.id)){
        setOrderAnnouncementPending('completed_order', completedOrder.id, true);
        enqueueOrderAnnouncement(
          'completed_order',
          completedOrder.id,
          composeOrderSpeech('completed_order', completedOrder.name, completedOrder.room, completedOrder.message)
        );
      }
    }catch(e){
      logClient('order status loop error: ' + errMessage(e));
    }
  }

  // ====== Helpers ======
  function ttsUrlFor(text, options = {}){
    const speed = (options && options.speed) ? String(options.speed) : '1';
    return window.location.origin+window.location.pathname+'?text='+encodeURIComponent(text)+'&tl=id&ttsspeed='+encodeURIComponent(speed);
  }
  function kagetUrl(){
    const basePath = window.location.pathname.replace(/\/[^\/]*$/, '/');
    return window.location.origin + basePath + 'kaget.mp3';
  }
  function bellUrl(kind='start'){
    const basePath = window.location.pathname.replace(/\/[^\/]*$/, '/');
    const file = kind === 'end' ? 'belakang.mp3' : 'depan.mp3';
    return window.location.origin + basePath + file;
  }
  let activeLogTab = 'tts';

  function setActiveLogTab(tab){
    activeLogTab = (tab === 'client') ? 'client' : 'tts';
    const isTts = activeLogTab === 'tts';
    if(tabLogTts){
      tabLogTts.classList.toggle('bg-slate-900', isTts);
      tabLogTts.classList.toggle('text-white', isTts);
      tabLogTts.classList.toggle('text-slate-600', !isTts);
    }
    if(tabLogClient){
      tabLogClient.classList.toggle('bg-slate-900', !isTts);
      tabLogClient.classList.toggle('text-white', !isTts);
      tabLogClient.classList.toggle('text-slate-600', isTts);
    }
    if(logTitleEl){
      logTitleEl.textContent = isTts
        ? 'Log server harian (terbaru paling atas, maksimal <?php echo $MAX_LOG_LINES;?> baris)'
        : 'Log client error harian (terbaru paling atas, maksimal <?php echo $MAX_LOG_LINES;?> baris)';
    }
  }

  function fetchLogs(){
    const type = activeLogTab === 'client' ? 'client' : 'tts';
    fetch(window.location.pathname+'?viewlog=1&type='+encodeURIComponent(type)+'&daily=1')
      .then(async (r) => {
        try {
          const j = await r.json();
          logEl.textContent = (j.lines || []).join('\n');
        } catch (_) {
          const txt = await r.text();
          logEl.textContent = 'Gagal parse JSON log ('+type+'):\n\n' + txt;
        }
      })
      .catch(e => {
        logEl.textContent = 'Gagal memuat log ('+type+'): ' + e;
      });
  }
  function fetchLogsAfterDelay(){setTimeout(fetchLogs,800);}
  function logKaget(msg){
    try{
      fetch(window.location.pathname+'?kagetlog=1&msg=' + encodeURIComponent(msg));
    }catch(_){}
  }
  function logClient(msg){
    try{
      fetch(window.location.pathname+'?clientlog=1&msg=' + encodeURIComponent(msg));
    }catch(_){}
  }
  function errMessage(err){
    if(!err) return 'unknown error';
    if(typeof err === 'string') return err;
    if(err && typeof err.message === 'string' && err.message) return err.message;
    try{
      return JSON.stringify(err);
    }catch(_){
      return String(err);
    }
  }

  // === BEL: pakai file depan.mp3 (start) & belakang.mp3 (end) ===
  async function playBell(kind='start'){
    await new Promise((resolve, reject)=>{
      const audio = new Audio(bellUrl(kind));
      audio.preload = 'auto';
      audio.onended = ()=> resolve();
      audio.onerror = (e)=> reject(e || new Error('bell playback error'));
      const p = audio.play();
      if(p && p.catch){
        p.catch(err=> reject(err));
      }
    });
  }

  // === TTS player (bel start & end terpisah) ===
  function stopCurrentPlayback(){
    const items = Array.from(activePlaybacks);
    if(!items.length){
      refreshTestButtonFromPlayback();
      return;
    }
    items.forEach(current=>{
      current.stopped = true;
      if(activeTestPlayback === current) activeTestPlayback = null;
      try{
        if(current.audio){
          current.audio.onended = null;
          current.audio.onerror = null;
          current.audio.pause();
          current.audio.currentTime = 0;
        }
      }catch(_){ }
      try { current.finish('stopped'); } catch(_) { }
    });
    refreshTestButtonFromPlayback();
  }

  function refreshTestButtonFromPlayback(){
    const running = activePlaybacks.size > 0;
    isTestRunning = running;
    setTestButtonState(running);
  }

  function withPlaybackTimeout(promiseFactory, timeoutMs = PLAYBACK_TIMEOUT_MS, timeoutLabel = 'Playback timeout'){
    return new Promise((resolve, reject)=>{
      let settled = false;
      const tid = setTimeout(()=>{
        if(settled) return;
        settled = true;
        logClient(timeoutLabel);
        reject(new Error(timeoutLabel));
      }, timeoutMs);
      Promise.resolve()
        .then(()=>promiseFactory())
        .then((v)=>{
          if(settled) return;
          settled = true;
          clearTimeout(tid);
          resolve(v);
        })
        .catch((e)=>{
          if(settled) return;
          settled = true;
          clearTimeout(tid);
          reject(e);
        });
    });
  }

  function playText(text, opts={}){
    return withPlaybackTimeout(()=>new Promise(async (resolve,reject)=>{
      const { trackAsTest=false, onStart=null, onFinish=null, withBell=true, ttsSpeed='1' } = opts;
      const ctx = { audio:null, stopped:false, done:false, finish:null };

      ctx.finish = (status, err=null)=>{
        if(ctx.done) return;
        ctx.done = true;
        activePlaybacks.delete(ctx);
        refreshTestButtonFromPlayback();
        if(trackAsTest && activeTestPlayback === ctx){
          activeTestPlayback = null;
        }
        try { if(typeof onFinish === 'function') onFinish(status); } catch(_) {}
        if(status === 'error'){
          const em = errMessage(err);
          logClient('playText error: '+em);
          reject(err || new Error('playback error'));
        }
        else resolve(status);
      };

      if(trackAsTest){
        if(activeTestPlayback && activeTestPlayback !== ctx){
          try{
            activeTestPlayback.stopped = true;
            if(activeTestPlayback.audio){
              activeTestPlayback.audio.onended = null;
              activeTestPlayback.audio.onerror = null;
              activeTestPlayback.audio.pause();
              activeTestPlayback.audio.currentTime = 0;
            }
            activeTestPlayback.finish('stopped');
          }catch(_){ }
        }
        activeTestPlayback = ctx;
      }

      activePlaybacks.add(ctx);
      refreshTestButtonFromPlayback();

      try { if(typeof onStart === 'function') onStart(); } catch(_) {}
      if(withBell){
        try{ await playBell('start'); }catch(_){ }
      }
      if(ctx.stopped){
        ctx.finish('stopped');
        return;
      }

      const audio=new Audio(ttsUrlFor(text, { speed: ttsSpeed }));
      ctx.audio = audio;
      audio.preload='auto';

      audio.onended=()=>{
        (async ()=>{
          if(ctx.stopped){
            ctx.finish('stopped');
            return;
          }
          if(withBell){
            try { await playBell('end'); } catch(_) {}
          }
          ctx.finish('ok');
        })();
      };
      audio.onerror=e=>{ ctx.finish('error', e); };

      const p=audio.play();
      if(p&&p.catch){
        p.catch(err=>{ ctx.finish('error', err); });
      }
    }), PLAYBACK_TIMEOUT_MS, 'TTS playback timeout');
  }

  function playKaget(opts={}){
    return withPlaybackTimeout(()=>new Promise((resolve,reject)=>{
      const { trackAsTest=false, onStart=null, onFinish=null } = opts;
      const ctx = { audio:null, stopped:false, done:false, finish:null };

      ctx.finish = (status, err=null)=>{
        if(ctx.done) return;
        ctx.done = true;
        activePlaybacks.delete(ctx);
        refreshTestButtonFromPlayback();
        if(trackAsTest && activeTestPlayback === ctx){
          activeTestPlayback = null;
        }
        try { if(typeof onFinish === 'function') onFinish(status); } catch(_) {}
        if(status === 'error'){
          const em = errMessage(err);
          logClient('playKaget error: '+em);
          reject(err || new Error('playback error'));
        }
        else resolve(status);
      };

      if(trackAsTest){
        if(activeTestPlayback && activeTestPlayback !== ctx){
          try{
            activeTestPlayback.stopped = true;
            if(activeTestPlayback.audio){
              activeTestPlayback.audio.onended = null;
              activeTestPlayback.audio.onerror = null;
              activeTestPlayback.audio.pause();
              activeTestPlayback.audio.currentTime = 0;
            }
            activeTestPlayback.finish('stopped');
          }catch(_){ }
        }
        activeTestPlayback = ctx;
      }

      const audio = new Audio(kagetUrl());
      ctx.audio = audio;
      audio.preload = 'auto';

      activePlaybacks.add(ctx);
      refreshTestButtonFromPlayback();
      try { if(typeof onStart === 'function') onStart(); } catch(_) {}

      audio.onended = ()=>{ ctx.finish('ok'); };
      audio.onerror = (e)=>{ ctx.finish('error', e); };
      const p = audio.play();
      if(p&&p.catch){
        p.catch(err=>{ ctx.finish('error', err); });
      }
    }), PLAYBACK_TIMEOUT_MS, 'Kaget playback timeout');
  }

  async function fetchWithTimeout(url, opts={}, timeoutMs=10000){
    const ctrl = new AbortController();
    const id = setTimeout(()=>ctrl.abort(), timeoutMs);
    try{
      const res = await fetch(url, {...opts, signal: ctrl.signal});
      return res;
    } finally {
      clearTimeout(id);
    }
  }
  async function fetchJsonRetry(url, attempts=[1500, 3000, 6000]){
    try{
      const res = await fetchWithTimeout(url, {}, 10000);
      if(!res.ok) throw new Error('HTTP '+res.status);
      return await res.json();
    }catch(e){
      if(attempts.length===0) throw e;
      const wait = attempts.shift();
      await new Promise(r=>setTimeout(r, wait));
      return fetchJsonRetry(url, attempts);
    }
  }

  function nextTopOfHourTS(){const n=new Date();return new Date(n.getFullYear(),n.getMonth(),n.getDate(),n.getHours()+1,0,0,100).getTime();}
  function nextHalfHourTS(){
    const n=new Date();
    if(n.getMinutes()<30) return new Date(n.getFullYear(),n.getMonth(),n.getDate(),n.getHours(),30,0,100).getTime();
    return new Date(n.getFullYear(),n.getMonth(),n.getDate(),n.getHours()+1,30,0,100).getTime();
  }
  function nextHalfHourKagetCandidate(){
    const now = new Date();
    const nowTs = now.getTime();

    // Jika saat ini menit 30 dan bentrok presensi, prioritaskan fallback menit 31 pada jam yang sama.
    const currentHalfTs = new Date(
      now.getFullYear(),
      now.getMonth(),
      now.getDate(),
      now.getHours(),
      30,
      0,
      100
    ).getTime();
    if (now.getMinutes() === 30) {
      const currentHalfDate = new Date(currentHalfTs);
      if (isPresensiScheduledAt(currentHalfDate)) {
        const deferredTs = currentHalfTs + 60 * 1000;
        if (deferredTs > nowTs + 15000) {
          return { ts: deferredTs, label: 'Alarm kaget menit 30 (geser +1 menit)' };
        }
      }
    }

    const halfTs = nextHalfHourTS();
    const halfDate = new Date(halfTs);
    if (isPresensiScheduledAt(halfDate)) {
      return { ts: halfTs + 60 * 1000, label: 'Alarm kaget menit 30 (geser +1 menit)' };
    }
    return { ts: halfTs, label: 'Alarm kaget tiap menit 30' };
  }
  function nextTopOfMinuteTS(){const n=new Date();return new Date(n.getFullYear(),n.getMonth(),n.getDate(),n.getHours(),n.getMinutes()+1,0,100).getTime();}
  function tsTodayFromHHMM(hhmm){const [H,M]=hhmm.split(':').map(Number);const n=new Date();return new Date(n.getFullYear(),n.getMonth(),n.getDate(),H,M,0,100).getTime();}
  function todayKey(){return new Date().toISOString().slice(0,10);}
  function hhmmPlusMinutes(hhmm, addMin){
    const [H,M]=hhmm.split(':').map(Number);
    const base = new Date(2000,0,1,H,M,0,0);
    base.setMinutes(base.getMinutes()+addMin);
    return `${String(base.getHours()).padStart(2,'0')}:${String(base.getMinutes()).padStart(2,'0')}`;
  }
  function dateKeyLocal(dateObj){
    const d = new Date(dateObj);
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth()+1).padStart(2,'0');
    const dd = String(d.getDate()).padStart(2,'0');
    return `${yyyy}-${mm}-${dd}`;
  }
  function loadHolidayCacheYear(year){
    try{
      const raw = localStorage.getItem(HOLIDAY_CACHE_KEY_PREFIX + String(year));
      if(!raw) return false;
      const list = JSON.parse(raw);
      if(!Array.isArray(list)) return false;
      nationalHolidayByYear[year] = new Set(list.filter(x=>typeof x==='string'));
      return true;
    }catch(_){
      return false;
    }
  }
  function isHolidayCacheFreshYear(year){
    try{
      const raw = localStorage.getItem(HOLIDAY_CACHE_META_KEY_PREFIX + String(year));
      if(!raw) return false;
      const meta = JSON.parse(raw);
      if(!meta || typeof meta.fetchedAt !== 'number') return false;
      return (Date.now() - meta.fetchedAt) < HOLIDAY_CACHE_TTL_MS;
    }catch(_){
      return false;
    }
  }
  function getHolidaySetYear(year){
    if(!(nationalHolidayByYear[year] instanceof Set)){
      loadHolidayCacheYear(year);
    }
    return nationalHolidayByYear[year] instanceof Set ? nationalHolidayByYear[year] : new Set();
  }
  function isNationalHolidayDate(dateObj){
    const d = new Date(dateObj);
    const y = d.getFullYear();
    return getHolidaySetYear(y).has(dateKeyLocal(d));
  }
  async function loadNationalHolidayYear(year){
    const y = Number(year);
    if(!Number.isFinite(y)) return;
    const hasCache = getHolidaySetYear(y).size > 0;
    if(hasCache && isHolidayCacheFreshYear(y)) return;
    try{
      const res = await fetchWithTimeout('https://libur.deno.dev/api?year='+encodeURIComponent(String(y)), {}, 10000);
      if(!res.ok) throw new Error('HTTP '+res.status);
      const rows = await res.json();
      const list = Array.isArray(rows)
        ? rows
            .filter(r => r && typeof r.date === 'string')
            .map(r => r.date)
        : [];
      const uniq = Array.from(new Set(list));
      nationalHolidayByYear[y] = new Set(uniq);
      try{
        localStorage.setItem(HOLIDAY_CACHE_KEY_PREFIX + String(y), JSON.stringify(uniq));
        localStorage.setItem(HOLIDAY_CACHE_META_KEY_PREFIX + String(y), JSON.stringify({ fetchedAt: Date.now() }));
      }catch(_){}
    }catch(_){
      loadHolidayCacheYear(y);
    }
  }
  function pickRandomPulangSyahduMessage(){
    if(!pulangSyahduMessages.length) return 'Selamat pulang, hati-hati di jalan dan jangan lupa berdoa.';
    let idx = Math.floor(Math.random() * pulangSyahduMessages.length);
    if(pulangSyahduMessages.length > 1 && idx === lastPulangSyahduIndex){
      idx = (idx + 1) % pulangSyahduMessages.length;
    }
    lastPulangSyahduIndex = idx;
    return pulangSyahduMessages[idx];
  }

  function getNextWorkdayName(baseDate = new Date()){
    const dayNames = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    const d = new Date(baseDate);
    for(let i=1;i<=14;i++){
      const cand = new Date(d.getFullYear(), d.getMonth(), d.getDate()+i);
      if(cand.getDay() === 0) continue; // Minggu
      if(isNationalHolidayDate(cand)) continue; // libur nasional
      return dayNames[cand.getDay()];
    }
    let next = (d.getDay() + 1) % 7;
    if (next === 0) next = 1;
    return dayNames[next];
  }

  function buildPulangPlus5Message(baseDate = new Date()){
    const core = pickRandomPulangSyahduMessage();
    const nextDay = getNextWorkdayName(baseDate);
    return `${core} Sampai jumpa kembali di hari ${nextDay}.`;
  }
  function pickRandomPantunPulangMessage(){
    if(!pantunPulangMessages.length) return 'Jangan lupa absen pulang di Smart Kampung.';
    let idx = Math.floor(Math.random() * pantunPulangMessages.length);
    if(pantunPulangMessages.length > 1 && idx === lastPantunPulangIndex){
      idx = (idx + 1) % pantunPulangMessages.length;
    }
    lastPantunPulangIndex = idx;
    return pantunPulangMessages[idx];
  }

  // helper untuk hitung ts HH:MM pada tanggal dari baseTs
  function tsFromHHMMOnDate(baseTs, hhmm){
    const [H,M]=hhmm.split(':').map(Number);
    const d = new Date(baseTs);
    return new Date(d.getFullYear(), d.getMonth(), d.getDate(), H, M, 0, 0).getTime();
  }

  function nextPresensiTS(){
    const now=new Date();
    for(let addDay=0;addDay<7;addDay++){
      const d=new Date(now.getFullYear(),now.getMonth(),now.getDate()+addDay);
      const day=d.getDay();
      const dayAlerts=presensiAlerts.filter(a=>a.days.includes(day));
      const candidates=dayAlerts.map(a=>{
        const[H,M]=a.time.split(':').map(Number);
        const ts=new Date(d.getFullYear(),d.getMonth(),d.getDate(),H,M,0,100).getTime();
        return{ts,alert:a};
      });
      candidates.sort((x,y)=>x.ts-y.ts);
      for(const c of candidates){ if(addDay>0 || c.ts>now.getTime()+15000) return c; }
    }
    return null;
  }

  function nextPulangPlus5TS(){
    if(!(optPresensi && optPresensi.checked)) return null;
    const now = new Date();
    const tkey = todayKey();
    const day = now.getDay();
    const candidates = presensiPulangAlerts
      .filter(a => a.days.includes(day))
      .map(a => {
        const due = hhmmPlusMinutes(a.time, 20);
        const ts = tsTodayFromHHMM(due);
        return { ts, alert: a, id: a.id + '_plus5' };
      })
      .filter(x => x.ts > now.getTime() + 15000 && playedPulangPlus5[x.id] !== tkey)
      .sort((a,b)=>a.ts-b.ts);
    return candidates.length ? candidates[0] : null;
  }

  // === Sholat sets ===
  const SHOLAT_ORDER = ['Fajr','Dhuhr','Asr','Maghrib','Isha'];
  const SHOLAT_LABEL = {Fajr:'Subuh',Dhuhr:'Dzuhur',Asr:'Ashar',Maghrib:'Maghrib',Isha:'Isya'};
  const SHOLAT_EXTRA_ORDER = ['Imsak','Sunrise','Midnight','Firstthird','Lastthird'];
  const SHOLAT_LABEL_EXTRA = {
    Imsak:'Imsak',
    Sunrise:'Terbit',
    Midnight:'Tengah malam syar’i',
    Firstthird:'Sepertiga malam pertama',
    Lastthird:'Sepertiga malam terakhir'
  };
  const ALL_SHOLAT_KEYS = ['Imsak','Fajr','Sunrise','Dhuhr','Asr','Maghrib','Isha','Midnight','Firstthird','Lastthird'];

  function fmtDDMMYYYY(ts){
    const d = new Date(ts);
    const dd = String(d.getDate()).padStart(2,'0');
    const mm = String(d.getMonth()+1).padStart(2,'0');
    const yyyy = d.getFullYear();
    return `${dd}-${mm}-${yyyy}`;
  }

  function buildMonitoringEvents(limit=null){
    const now = Date.now();
    const horizon = now + 24 * 3600 * 1000;
    const events = [];
    const seen = new Set();

    const addEvent = (ts, label)=>{
      if(!ts || ts <= now + 1000 || ts > horizon) return;
      const key = `${ts}|${label}`;
      if(seen.has(key)) return;
      seen.add(key);
      events.push({ ts, label });
    };

    if(optHourly && optHourly.checked){
      let d = new Date(now + 1000);
      d = new Date(d.getFullYear(), d.getMonth(), d.getDate(), d.getHours()+1, 0, 0, 100);
      while(d.getTime() <= horizon){
        addEvent(d.getTime(), 'Pengumuman setiap jam');
        d = new Date(d.getFullYear(), d.getMonth(), d.getDate(), d.getHours()+1, 0, 0, 100);
      }
    }

    if(optHalfHourly && optHalfHourly.checked){
      let d = new Date(now + 1000);
      if(d.getMinutes() < 30){
        d = new Date(d.getFullYear(), d.getMonth(), d.getDate(), d.getHours(), 30, 0, 100);
      }else{
        d = new Date(d.getFullYear(), d.getMonth(), d.getDate(), d.getHours()+1, 30, 0, 100);
      }
      while(d.getTime() <= horizon){
        if(isPresensiScheduledAt(d)){
          addEvent(d.getTime() + 60*1000, 'Alarm kaget menit 30 (geser +1 menit)');
        }else{
          addEvent(d.getTime(), 'Alarm kaget menit 30');
        }
        d = new Date(d.getFullYear(), d.getMonth(), d.getDate(), d.getHours()+1, 30, 0, 100);
      }
    }

    if(optPresensi && optPresensi.checked){
      for(let addDay=0; addDay<=1; addDay++){
        const base = new Date(now);
        const dayDate = new Date(base.getFullYear(), base.getMonth(), base.getDate()+addDay);
        const day = dayDate.getDay();
        presensiAlerts.forEach(a=>{
          if(!a.days.includes(day)) return;
          addEvent(tsFromHHMMOnDate(dayDate.getTime(), a.time), a.label);
        });
        presensiPulangAlerts.forEach(a=>{
          if(!a.days.includes(day)) return;
          addEvent(tsFromHHMMOnDate(dayDate.getTime(), hhmmPlusMinutes(a.time, 20)), 'Pantun pulang +20 menit');
        });
      }
    }

    if(optSholat && optSholat.checked && sholatTimings){
      for(let addDay=0; addDay<=1; addDay++){
        const base = new Date(now);
        const dayDate = new Date(base.getFullYear(), base.getMonth(), base.getDate()+addDay);
        ALL_SHOLAT_KEYS.forEach(k=>{
          const t = sholatTimings[k];
          if(!t) return;
          const label = SHOLAT_LABEL[k] ? `Adzan ${SHOLAT_LABEL[k]}` : (SHOLAT_LABEL_EXTRA[k] || k);
          addEvent(tsFromHHMMOnDate(dayDate.getTime(), t), label);
        });
      }
    }

    events.sort((a,b)=>a.ts-b.ts);
    if(typeof limit === 'number' && limit > 0){
      return events.slice(0, limit);
    }
    return events;
  }

  function renderMonitoringEvents(){
    if(!monitorEventListEl || !monitorNextCountdownEl || !monitorNextDateEl) return;
    renderOrderReadHistory();
    const items = buildMonitoringEvents();
    monitorEventListEl.innerHTML = '';

    if(!items.length){
      monitorNextCountdownEl.textContent = '--:--:--';
      monitorNextDateEl.textContent = '-';
      const empty = document.createElement('div');
      empty.className = 'rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500';
      empty.textContent = 'Tidak ada event aktif.';
      monitorEventListEl.appendChild(empty);
      return;
    }

    const next = items[0];
    const diff = Math.max(0, next.ts - Date.now());
    const totalSec = Math.floor(diff / 1000);
    const hh = String(Math.floor(totalSec/3600)).padStart(2,'0');
    const mm = String(Math.floor((totalSec%3600)/60)).padStart(2,'0');
    const ss = String(totalSec%60).padStart(2,'0');
    monitorNextCountdownEl.textContent = `${hh}:${mm}:${ss}`;
    monitorNextDateEl.textContent = fmtDDMMYYYY(next.ts);

    items.forEach((it, idx)=>{
      const row = document.createElement('div');
      row.className = idx === 0
        ? 'event-blink rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700'
        : 'rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700';
      row.textContent = `${fmtHHMM(it.ts)} - ${it.label}`;
      monitorEventListEl.appendChild(row);
    });
  }

  function startMonitoringTicker(){
    if(monitoringIntervalId){ clearInterval(monitoringIntervalId); monitoringIntervalId = null; }
    renderMonitoringEvents();
    monitoringIntervalId = setInterval(renderMonitoringEvents, 1000);
  }

  // === Sholat: cache helpers (localStorage) ===
  const SHOLAT_CACHE_KEY = 'adzan_bwi_timings';
  const SHOLAT_CACHE_DATE = 'adzan_bwi_date';
  const SHOLAT_CACHE_UPDATED_AT = 'adzan_bwi_updated_at';

  function saveSholatCache(obj){
    try{
      localStorage.setItem(SHOLAT_CACHE_KEY, JSON.stringify(obj||{}));
      localStorage.setItem(SHOLAT_CACHE_DATE, todayKey());
      localStorage.setItem(SHOLAT_CACHE_UPDATED_AT, new Date().toISOString());
    }catch(_){}
  }
  function loadSholatCache(){
    try{
      const d = localStorage.getItem(SHOLAT_CACHE_KEY);
      const day= localStorage.getItem(SHOLAT_CACHE_DATE);
      if(!d || day!==todayKey()) return null;
      return JSON.parse(d);
    }catch(_){return null;}
  }
  function getCachedUpdatedAt(){
    try{
      const iso = localStorage.getItem(SHOLAT_CACHE_UPDATED_AT);
      return iso ? new Date(iso) : null;
    }catch(_){return null;}
  }

  // === Sholat: renderers ===
  function renderSholatListPlaceholder(){
    renderSholatList({Fajr:'—:—',Dhuhr:'—:—',Asr:'—:—',Maghrib:'—:—',Isha:'—:—'});
  }

  function kalimatSholatExtra(key, time){
    const map = {
      Imsak: `Sekarang waktu imsak untuk wilayah Banyuwangi Kota, pukul ${time} WIB.`,
      Sunrise: `Matahari terbit untuk wilayah Banyuwangi Kota, pukul ${time} WIB.`,
      Midnight: `Telah masuk tengah malam syar’i, pukul ${time} WIB.`,
      Firstthird: `Memasuki sepertiga malam pertama, pukul ${time} WIB.`,
      Lastthird: `Memasuki sepertiga malam terakhir, pukul ${time} WIB.`
    };
    return map[key] || `Waktu ${key} pada pukul ${time} WIB.`;
  }

  function renderSholatList(t){
    if(!sholatListEl) return;
    sholatListEl.innerHTML = '';

    // Baris ekstra (jika ada)
    SHOLAT_EXTRA_ORDER.forEach(k=>{
      const time = t && t[k] ? t[k] : null;
      if(!time) return;
      const label = SHOLAT_LABEL_EXTRA[k];
      const row=document.createElement('div');
      row.className='flex items-center gap-2';
      const desc=document.createElement('div');
      desc.className='rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm';
      desc.textContent = `${time} — ${label} (Banyuwangi)`;
      const btn=document.createElement('button');
      btn.className='ml-auto rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm hover:bg-slate-100';
      btn.textContent='Test';
      btn.onclick=async()=>{
        if(btn.dataset.running === '1'){
          stopCurrentPlayback();
          return;
        }
        try{
          const status = await playText(kalimatSholatExtra(k, time), {
            trackAsTest: true,
            onStart: ()=>{ btn.dataset.running='1'; setInlineTestButtonState(btn, true); },
            onFinish: ()=>{ btn.dataset.running='0'; setInlineTestButtonState(btn, false); }
          });
          if(status === 'ok') fetchLogsAfterDelay();
        }
        catch(e){ alert('Gagal test: '+e); }
      };
      row.appendChild(desc); row.appendChild(btn); sholatListEl.appendChild(row);
    });

    // 5 sholat utama
    SHOLAT_ORDER.forEach(k=>{
      const time = (t && t[k]) ? t[k] : '—:—';
      const label = SHOLAT_LABEL[k];
      const row=document.createElement('div'); 
      row.className='flex items-center gap-2';
      const desc=document.createElement('div'); 
      desc.className='rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm';
      desc.textContent = `${time} — Sholat ${label} (Banyuwangi)`;
      const btn=document.createElement('button'); 
      btn.className='ml-auto rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm hover:bg-slate-100';
      btn.textContent='Test';
      btn.onclick=async()=>{
        if(btn.dataset.running === '1'){
          stopCurrentPlayback();
          return;
        }
        const jamInfo = time && time !== '—:—' ? `, pukul ${time} WIB.` : '.';
        const kal=`Sekarang waktu adzan ${label} untuk wilayah Banyuwangi Kota${jamInfo}`;
        try{
          const status = await playText(kal, {
            trackAsTest: true,
            onStart: ()=>{ btn.dataset.running='1'; setInlineTestButtonState(btn, true); },
            onFinish: ()=>{ btn.dataset.running='0'; setInlineTestButtonState(btn, false); }
          });
          if(status === 'ok') fetchLogsAfterDelay();
        }catch(e){ alert('Gagal test sholat: '+e); }
      };
      row.appendChild(desc); row.appendChild(btn); sholatListEl.appendChild(row);
    });
  }

  // === Sholat: fetch & helpers ===
  async function loadSholatBanyuwangi(opts={announceOnError:false}){
    try{
      const j = await fetchJsonRetry(window.location.pathname+'?adzan=1');
      const t = j && j.data && j.data.timings ? j.data.timings : null;
      if(!t) throw new Error('Invalid payload');
      sholatTimings = {
        Fajr:t.Fajr, Dhuhr:t.Dhuhr, Asr:t.Asr, Maghrib:t.Maghrib, Isha:t.Isha,
        Imsak:t.Imsak||null, Sunrise:t.Sunrise||null,
        Midnight:t.Midnight||null, Firstthird:t.Firstthird||null, Lastthird:t.Lastthird||null
      };
      saveSholatCache(sholatTimings);
      renderSholatList(sholatTimings);
      setSholatLastUpdateLabel(new Date(), {cached:false});
      setNextRunFromCalculator();
      sholatFetchErrorAnnouncedDate = null;
    }catch(e){
      const cached = loadSholatCache();
      if(cached){
        sholatTimings = cached;
        renderSholatList(sholatTimings);
        const cachedAt = getCachedUpdatedAt() || new Date();
        setSholatLastUpdateLabel(cachedAt, {cached:true});
        setNextRunFromCalculator();
        if(opts.announceOnError && sholatFetchErrorAnnouncedDate!==todayKey()){
          try{ await playText('Gagal memuat jadwal salat. Menggunakan data terakhir.'); }catch(_){}
          sholatFetchErrorAnnouncedDate = todayKey();
        }
      }else{
        renderSholatListPlaceholder();
        setSholatLastUpdateLabel(null);
        if(opts.announceOnError && sholatFetchErrorAnnouncedDate!==todayKey()){
          try{ await playText('Gagal memuat jadwal salat dan tidak ada data tersimpan.'); }catch(_){}
          sholatFetchErrorAnnouncedDate = todayKey();
        }
      }
    }
  }

  function setSholatLastUpdateLabel(dateObj, opts={cached:false}){
    const el=document.getElementById('sholatLastUpdate');
    if(!el){return;}
    if(!dateObj){ el.textContent='-'; return; }
    const d = new Date(dateObj);
    const dd = String(d.getDate()).padStart(2,'0');
    const mm = String(d.getMonth()+1).padStart(2,'0');
    const yyyy = d.getFullYear();
    const hh = String(d.getHours()).padStart(2,'0');
    const mi = String(d.getMinutes()).padStart(2,'0');
    const ss = String(d.getSeconds()).padStart(2,'0');
    el.textContent = `${dd}-${mm}-${yyyy} ${hh}:${mi}:${ss} WIB${opts.cached?' (cached)':''}`;
  }

  function nextSholatTS(){
    if(!(optSholat && optSholat.checked) || !sholatTimings) return null;
    const now = Date.now();
    const cand = ALL_SHOLAT_KEYS
      .filter(k => sholatTimings[k])
      .map(k => ({
        k,
        ts: tsTodayFromHHMM(sholatTimings[k]),
        label: (SHOLAT_LABEL[k] ? 'Sholat '+SHOLAT_LABEL[k] : SHOLAT_LABEL_EXTRA[k])
      }))
      .filter(x => x.ts > now + 15000)
      .sort((a,b)=>a.ts-b.ts);
    return cand.length ? {ts:cand[0].ts, label:cand[0].label} : null;
  }

  // ====== Countdown calculator (merge all modes) ======
  function calculateNextRunWithLabel(){
    const candidates=[];
    if(optTest1Min && optTest1Min.checked) candidates.push({ts:nextTopOfMinuteTS(),label:'Pengumuman setiap menit'});
    if(optHourly && optHourly.checked)   candidates.push({ts:nextTopOfHourTS(),label:'Pengumuman setiap jam'});
    if(optHalfHourly && optHalfHourly.checked){
      const half = nextHalfHourKagetCandidate();
      candidates.push(half);
    }
    if(optPresensi && optPresensi.checked){
      const p=nextPresensiTS();
      if(p) candidates.push({ts:p.ts,label:p.alert.label});
      const p5=nextPulangPlus5TS();
      if(p5) candidates.push({ts:p5.ts,label:'Pantun pulang +20 menit'});
    }
    const s=nextSholatTS();
    if(s) candidates.push(s);
    if(!candidates.length) return {ts:null,label:null};
    candidates.sort((a,b)=>a.ts-b.ts);
    return candidates[0];
  }

  function setNextRunFromCalculator(){
    const { ts, label } = calculateNextRunWithLabel();
    nextRunTimestamp = ts;
    nextRunLabel = label;
    startCountdown();
    renderMonitoringEvents();
  }

  function touchSchedulerHeartbeat(source='unknown'){
    schedulerHeartbeatTs = Date.now();
    schedulerHeartbeatSource = source;
  }

  function fmtHHMM(ts){
    if(!ts) return '--:--';
    const d = new Date(ts);
    return `${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
  }

  // ========= Gaya 12-jam natural (pagi/siang/sore/malam) =========
  function numberToIndoWords(n){
    const u=['nol','satu','dua','tiga','empat','lima','enam','tujuh','delapan','sembilan'];
    if(n<10) return u[n];
    if(n===10) return 'sepuluh';
    if(n===11) return 'sebelas';
    if(n<20) return u[n-10]+' belas';
    const tens=['','', 'dua puluh','tiga puluh','empat puluh','lima puluh'];
    const t=Math.floor(n/10), r=n%10;
    return tens[t] + (r?(' '+u[r]):'');
  }
  function periodOf(h){
    if(h>=12 && h<15) return 'siang';
    if(h>=15 && h<18) return 'sore';
    if(h>=18 || h<4)  return 'malam';
    return 'pagi';
  }
  function waktuNatural(h, m){
    const period=periodOf(h);
    const h12=(h%12)||12;
    const jamWord=numberToIndoWords(h12);
    if(m===0) return `tepat pukul ${jamWord} ${period}`;
    return `pukul ${jamWord} ${period} lewat ${numberToIndoWords(m)} menit`;
  }
  function waktuNaturalFromHHMM(hhmm){
    const [H,M]=hhmm.split(':').map(Number);
    return waktuNatural(H,M);
  }

  // ====== Presensi: kalimat ======
  function buildPresensiAnnouncementForNow(actionLabel){
    const now=new Date();
    const kalWaktu = `Waktu sekarang menunjukkan ${waktuNatural(now.getHours(), now.getMinutes())}.`;
    const isDatang = actionLabel && actionLabel.toLowerCase().includes('datang');
    const tail = isDatang
      ? 'Silahkan melakukan presensi datang pada aplikasi SmartKampung.'
      : 'Silahkan melakukan presensi pulang pada aplikasi SmartKampung.';
    return `${kalWaktu} ${tail}`;
  }
  function buildPresensiAnnouncementForHHMM(a){
    const kalWaktu = `Waktu sekarang menunjukkan ${waktuNaturalFromHHMM(a.time)}.`;
    const isDatang = a.label && a.label.toLowerCase().includes('datang');
    const tail = isDatang
      ? 'Silahkan melakukan presensi datang pada aplikasi SmartKampung.'
      : 'Silahkan melakukan presensi pulang pada aplikasi SmartKampung.';
    return `${kalWaktu} ${tail}`;
  }

  // Live clock (HH:MM:SS)
  (function startLiveClock(){
    if(!liveClockEl) return;
    const tick = () => { 
      const d=new Date();
      const hh=String(d.getHours()).padStart(2,'0');
      const mm=String(d.getMinutes()).padStart(2,'0');
      const ss=String(d.getSeconds()).padStart(2,'0');
      liveClockEl.textContent = `${hh}:${mm}:${ss}`;
    };
    tick();
    setInterval(tick, 1000);
  })();

  // ==== Countdown dengan auto-recalc & logging saat mentok ====
  function startCountdown(){
    if(countdownInterval) clearInterval(countdownInterval);
    countdownStuckReported = false;
    const nextLabelEl=document.getElementById('nextEventLabel');
    countdownInterval=setInterval(()=>{
      if(!nextRunTimestamp){
        countdownEl.textContent='--:--:--';
        nextLabelEl.textContent='-';
        return;
      }
      const ms=nextRunTimestamp-Date.now();
      if(ms<=0){
        countdownEl.textContent='00:00:00';
        if(!countdownStuckReported){
          countdownStuckReported = true;
          try {
            fetch(window.location.pathname+'?clientlog=1&msg=' + encodeURIComponent('Countdown reached zero. nextRunTimestamp='+nextRunTimestamp+'; started='+started));
          } catch(_) {}
        }
        setNextRunFromCalculator();
        return;
      }
      const s=Math.floor(ms/1000);
      const hh=String(Math.floor(s/3600)).padStart(2,'0');
      const mm=String(Math.floor((s%3600)/60)).padStart(2,'0');
      const ss=String(s%60).padStart(2,'0');
      countdownEl.textContent=`${hh}:${mm}:${ss}`;
      const timeStr = fmtHHMM(nextRunTimestamp);
      nextLabelEl.textContent = nextRunLabel ? `${nextRunLabel} (${timeStr})` : '-';
    },250);
  }

  // ====== Skip-hourly bila ada alert menit itu ======
  function isAnyAlertNow(now){
    const hh = String(now.getHours()).padStart(2,'0');
    const mm = String(now.getMinutes()).padStart(2,'0');
    const cur = hh + ':' + mm;
    const tkey = todayKey();

    if (optPresensi && optPresensi.checked) {
      const day = now.getDay();
      const duePresensi = presensiAlerts.some(a => a.days.includes(day) && a.time === cur && playedAlerts[a.id] !== tkey);
      if (duePresensi) return true;
    }

    if (optSholat && optSholat.checked && sholatTimings) {
      for (const k of ALL_SHOLAT_KEYS) {
        const v = sholatTimings[k];
        if (!v || v !== cur) continue;
        if (!playedSholat[tkey]) playedSholat[tkey] = {};
        if (!playedSholat[tkey][k]) return true;
      }
    }
    return false;
  }

  function isPresensiDueNow(now){
    if (!(optPresensi && optPresensi.checked)) return false;
    const hh = String(now.getHours()).padStart(2,'0');
    const mm = String(now.getMinutes()).padStart(2,'0');
    const cur = hh + ':' + mm;
    const day = now.getDay();
    const tkey = todayKey();
    return presensiAlerts.some(a =>
      a.days.includes(day) &&
      a.time === cur &&
      playedAlerts[a.id] !== tkey
    );
  }

  function isPresensiScheduledAt(now){
    if (!(optPresensi && optPresensi.checked)) return false;
    const hh = String(now.getHours()).padStart(2,'0');
    const mm = String(now.getMinutes()).padStart(2,'0');
    const cur = hh + ':' + mm;
    const day = now.getDay();
    return presensiAlerts.some(a =>
      a.days.includes(day) &&
      a.time === cur
    );
  }

  // ====== Eksekutor event terjadwal ======
  async function fireRun(){
    if (fireRunBusy) return;
    const startNow = new Date();
    const minuteKey = `${todayKey()}-${String(startNow.getHours()).padStart(2,'0')}:${String(startNow.getMinutes()).padStart(2,'0')}`;
    if (lastFireRunMinuteKey === minuteKey) return;
    fireRunBusy = true;
    lastFireRunMinuteKey = minuteKey;

    try {
    const now = new Date();
    const hh = String(now.getHours()).padStart(2,'0');
    const mm = String(now.getMinutes()).padStart(2,'0');
    const hhmm = hh + ':' + mm;
    const tkey = todayKey();
    const playQueue = [];
    let hasOtherAlertThisMinute = false;

    // 1) Sholat (5 waktu + ekstra)
    if (optSholat && optSholat.checked && sholatTimings) {
      for (const k of ALL_SHOLAT_KEYS) {
        const v = sholatTimings[k];
        if (v !== hhmm) continue;
        if (!playedSholat[tkey]) playedSholat[tkey] = {};
        if (playedSholat[tkey][k]) continue;

        let kal;
        if (SHOLAT_LABEL[k]) {
          const labelMap = {Fajr:'Subuh',Dhuhr:'Dzuhur',Asr:'Ashar',Maghrib:'Maghrib',Isha:'Isya'};
          kal = `Sekarang waktu adzan ${labelMap[k]} untuk wilayah Banyuwangi Kota, pukul ${v} WIB.`;
        } else {
          kal = kalimatSholatExtra(k, v);
        }

        playedSholat[tkey][k] = 1;
        hasOtherAlertThisMinute = true;
        playQueue.push(async ()=>{
          try{
            await playText(kal);
            fetchLogsAfterDelay();
          }catch(e){
            console.error('Sholat fireRun error', e);
          }
        });
      }
    }

    // 2) Presensi reguler (tepat di menit jadwal)
    if (optPresensi && optPresensi.checked) {
      const day = now.getDay();
      for (const a of presensiAlerts) {
        if(!a.days.includes(day)) continue;
        if(a.time !== hhmm) continue;
        if(playedAlerts[a.id] === tkey) continue;
        // Tandai dulu untuk mencegah dobel oleh loop fallback 20 detik.
        playedAlerts[a.id] = tkey;
        hasOtherAlertThisMinute = true;
        playQueue.push(async ()=>{
          try{
            await playText(buildPresensiAnnouncementForNow(a.label));
            fetchLogsAfterDelay();
          }catch(e){
            delete playedAlerts[a.id];
            console.error('Presensi fireRun error', e);
          }
        });
      }
    }

    // 3) Pulang +20 menit (tetap jalan walau berbarengan event lain)
    if (optPresensi && optPresensi.checked) {
      const day = now.getDay();
      for (const a of presensiPulangAlerts) {
        if(!a.days.includes(day)) continue;
        if(hhmm !== hhmmPlusMinutes(a.time, 20)) continue;
        const key = a.id + '_plus5';
        if(playedPulangPlus5[key] === tkey) continue;
        if(pendingPulangPlus5.has(key)) continue;
        pendingPulangPlus5.add(key);
        hasOtherAlertThisMinute = true;
        playQueue.push(async ()=>{
          try{
            await playText(pickRandomPantunPulangMessage(), { withBell: false });
            playedPulangPlus5[key] = tkey;
            fetchLogsAfterDelay();
          }catch(e){
            console.error('Pantun pulang +20 fireRun error', e);
          }finally{
            pendingPulangPlus5.delete(key);
          }
        });
      }
    }

    // 4) Hourly - menit 00 (skip jika menit itu sudah ada alert lain)
    if (optHourly && optHourly.checked) {
      if (now.getMinutes() === 0 && window.lastPlayedHour !== now.getHours() && !hasOtherAlertThisMinute) {
        window.lastPlayedHour = now.getHours();
        const text = `Waktu sekarang menunjukkan ${waktuNatural(now.getHours(), now.getMinutes())}.`;
        playQueue.push(async ()=>{
          try{
            await playText(text);
            fetchLogsAfterDelay();
          }catch(e){
            console.error(e);
          }
        });
      }
    }

    // 5) Alarm kaget - menit 30
    if (optHalfHourly && optHalfHourly.checked) {
      const nowMinute = now.getMinutes();
      const halfDate = new Date(now.getFullYear(), now.getMonth(), now.getDate(), now.getHours(), 30, 0, 0);
      const hasPresensiAtHalfHour = isPresensiScheduledAt(halfDate);
      // Jika bentrok dengan presensi di menit 30, kaget digeser ke menit 31.
      const kagetDueNow =
        (nowMinute === 30 && !hasPresensiAtHalfHour) ||
        (nowMinute === 31 && hasPresensiAtHalfHour);
      if (nowMinute === 30 && hasPresensiAtHalfHour) {
        const deferKey = `${tkey}-${hh}-defer`;
        if (lastKagetDeferKey !== deferKey) {
          lastKagetDeferKey = deferKey;
          logKaget(`DEFER ${hh}:30 -> ${hh}:31 (bentrok presensi)`);
          fetchLogsAfterDelay();
        }
      }
      if (kagetDueNow) {
        const mark = `${tkey}-${hh}`;
        if (lastPlayedHalfHourKey !== mark) {
          lastPlayedHalfHourKey = mark;
          hasOtherAlertThisMinute = true;
          playQueue.push(async ()=>{
            try{
              await playKaget();
              const curMinute = String(nowMinute).padStart(2,'0');
              const mode = (nowMinute === 31 && hasPresensiAtHalfHour) ? 'deferred+1m' : 'normal';
              logKaget(`PLAY ${hh}:${curMinute} (${mode})`);
              fetchLogsAfterDelay();
            }catch(e){
              const curMinute = String(nowMinute).padStart(2,'0');
              const em = (e && e.message) ? e.message : String(e);
              logKaget(`ERROR ${hh}:${curMinute} ${em}`);
              console.error('Half-hour kaget error', e);
            }
          });
        }
      }
    }

    if (playQueue.length) {
      for (const run of playQueue) {
        await run();
      }
    }
    setNextRunFromCalculator();
    } finally {
      fireRunBusy = false;
    }
  }

  function scheduleRunner(){
    if(intervalId){ clearTimeout(intervalId); intervalId=null; }
    if(hourlyIntervalId){ clearInterval(hourlyIntervalId); hourlyIntervalId=null; }
    touchSchedulerHeartbeat('scheduleRunner:arm');
    setNextRunFromCalculator();
    if(!nextRunTimestamp){ statusBadge.textContent='Idle'; return; }
    const delay=Math.max(0,nextRunTimestamp-Date.now());
    statusBadge.textContent='Scheduled';
    intervalId = setTimeout(async ()=>{
      touchSchedulerHeartbeat('scheduleRunner:fire');
      await fireRun();
      touchSchedulerHeartbeat('scheduleRunner:done');
      intervalId = null;
      if(started){
        scheduleRunner();
      }
    }, delay);
  }

  function startWatchdog(){
    if(watchdogIntervalId){ clearInterval(watchdogIntervalId); watchdogIntervalId=null; }
    watchdogIntervalId = setInterval(()=>{
      if(!started) return;

      const now = Date.now();
      const staleMs = now - schedulerHeartbeatTs;
      const overdueMs = nextRunTimestamp ? (now - nextRunTimestamp) : 0;
      const schedulerMissing = !intervalId && !!nextRunTimestamp;
      const countdownMissing = !countdownInterval;
      const stale = staleMs > (3 * 60 * 1000);
      const overdue = !!nextRunTimestamp && overdueMs > (90 * 1000);

      if(!(schedulerMissing || countdownMissing || stale || overdue)) return;

      try {
        const reason = [
          schedulerMissing ? 'schedulerMissing' : '',
          countdownMissing ? 'countdownMissing' : '',
          stale ? `stale(${Math.floor(staleMs/1000)}s)` : '',
          overdue ? `overdue(${Math.floor(overdueMs/1000)}s)` : ''
        ].filter(Boolean).join(',');
        fetch(window.location.pathname+'?clientlog=1&msg=' + encodeURIComponent(
          `Watchdog recover: ${reason}; hb=${schedulerHeartbeatSource}; started=${started}`
        ));
      } catch(_) {}

      if(intervalId){ clearTimeout(intervalId); intervalId=null; }
      fireRunBusy = false;
      setNextRunFromCalculator();
      startCountdown();
      scheduleRunner();
      touchSchedulerHeartbeat('watchdog:recover');
    }, 30000);
  }

  // ====== Loop presensi & sholat ======
  async function checkPresensiAlertsLoop(){
    if(!(optPresensi && optPresensi.checked)) return;
    const now=new Date();
    const day=now.getDay();
    const hh=String(now.getHours()).padStart(2,'0');
    const mm=String(now.getMinutes()).padStart(2,'0');
    const cur=hh+':'+mm;
    const tkey=todayKey();

    for(const a of presensiAlerts){
      if(!a.days.includes(day)) continue;
      if(a.time!==cur) continue;
      if(playedAlerts[a.id]===tkey) continue;
      playedAlerts[a.id]=tkey;
      try{
        const kal = buildPresensiAnnouncementForNow(a.label);
        await playText(kal);
        fetchLogsAfterDelay();
        setNextRunFromCalculator();
      }catch(e){
        delete playedAlerts[a.id];
        console.error('Presensi play error',e);
      }
    }

    for(const a of presensiPulangAlerts){
      if(!a.days.includes(day)) continue;
      const due = hhmmPlusMinutes(a.time, 20);
      const key = a.id + '_plus5';
      if(due !== cur) continue;
      if(playedPulangPlus5[key]===tkey) continue;
      if(pendingPulangPlus5.has(key)) continue;
      pendingPulangPlus5.add(key);
      playedPulangPlus5[key]=tkey;
      try{
        await playText(pickRandomPantunPulangMessage(), { withBell: false });
        fetchLogsAfterDelay();
        setNextRunFromCalculator();
      }catch(e){
        delete playedPulangPlus5[key];
        console.error('Pantun pulang +20 play error',e);
      } finally { pendingPulangPlus5.delete(key); }
    }
  }

  async function checkSholatAlertsLoop(){
    if(!(optSholat && optSholat.checked) || !sholatTimings) return;
    const now=new Date();
    const hh=String(now.getHours()).padStart(2,'0');
    const mm=String(now.getMinutes()).padStart(2,'0');
    const cur=hh+':'+mm;
    const tkey=todayKey();
    for(const k of ALL_SHOLAT_KEYS){
      const v = sholatTimings[k];
      if(v===cur){
        if(!playedSholat[tkey]) playedSholat[tkey]={};
        if(playedSholat[tkey][k]) continue;
        playedSholat[tkey][k]=1;

        let kal;
        if (SHOLAT_LABEL[k]) {
          const labelMap = {Fajr:'Subuh',Dhuhr:'Dzuhur',Asr:'Ashar',Maghrib:'Maghrib',Isha:'Isya'};
          kal = `Sekarang waktu adzan ${labelMap[k]} untuk wilayah Banyuwangi Kota, pukul ${v} WIB.`;
        } else {
          kal = kalimatSholatExtra(k, v);
        }

        try{
          await playText(kal);
          fetchLogsAfterDelay();
          setNextRunFromCalculator();
        }catch(e){
          delete playedSholat[tkey][k];
          console.error('Sholat play error', e);
        }
      }
    }
  }

  function scheduleMidnightReset(){
    if(midnightResetTimeoutId){ clearTimeout(midnightResetTimeoutId); midnightResetTimeoutId=null; }
    const now=new Date();
    const tomorrow=new Date(now.getFullYear(),now.getMonth(),now.getDate()+1,0,0,10);
    const delay=tomorrow-now;
    midnightResetTimeoutId = setTimeout(async ()=>{
      midnightResetTimeoutId = null;
      if(!started) return;
      playedAlerts={};
      playedPulangPlus5={};
      pendingPulangPlus5 = new Set();
      playedSholat={};
      await loadSholatBanyuwangi({announceOnError:true});
      scheduleRunner();
      scheduleSafeReload(1);
      scheduleMidnightReset();
    }, delay);
  }

  // refresh 03:00 WIB
  function scheduleSholatRefreshAt3AM(){
    if(sholatRefreshTimeoutId){ clearTimeout(sholatRefreshTimeoutId); sholatRefreshTimeoutId=null; }
    const now = new Date();
    const three = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 3, 0, 5, 0);
    if (three <= now) three.setDate(three.getDate()+1);
    const delay = three - now;
    sholatRefreshTimeoutId = setTimeout(async ()=>{
      sholatRefreshTimeoutId = null;
      if(!started) return;
      await loadSholatBanyuwangi({announceOnError:true});
      setNextRunFromCalculator();
      scheduleSholatRefreshAt3AM();
    }, delay);
  }

  // ====== Cari event yang mengganggu reload di sekitar baseTs ======
  function findBlockingEventAround(baseTs, windowMs){
    const now = Date.now();
    let candidate = null;

    const pick = (ts, label)=>{
      if(ts <= now) return;
      const diff = Math.abs(ts - baseTs);
      if(diff > windowMs) return;
      if(!candidate || ts < candidate.ts){
        candidate = {ts, label};
      }
    };

    const baseDate = new Date(baseTs);
    const baseDay = baseDate.getDay();

    // Hourly
    if(optHourly && optHourly.checked){
      const hTs = new Date(baseDate.getFullYear(), baseDate.getMonth(), baseDate.getDate(), baseDate.getHours(), 0, 0, 0).getTime();
      pick(hTs, 'Pengumuman setiap jam');
    }

    // Half-hour kaget
    if(optHalfHourly && optHalfHourly.checked){
      const halfTs = new Date(baseDate.getFullYear(), baseDate.getMonth(), baseDate.getDate(), baseDate.getHours(), 30, 0, 0).getTime();
      if(isPresensiScheduledAt(new Date(halfTs))){
        pick(halfTs + 60*1000, 'Alarm kaget menit 30 (geser +1 menit)');
      }else{
        pick(halfTs, 'Alarm kaget menit 30');
      }
    }

    // Presensi
    if(optPresensi && optPresensi.checked){
      presensiAlerts.forEach(a=>{
        if(!a.days.includes(baseDay)) return;
        const ts = tsFromHHMMOnDate(baseTs, a.time);
        pick(ts, a.label);
      });
      presensiPulangAlerts.forEach(a=>{
        if(!a.days.includes(baseDay)) return;
        const ts = tsFromHHMMOnDate(baseTs, hhmmPlusMinutes(a.time, 20));
        pick(ts, 'Pantun pulang +20 menit');
      });
    }

    // Sholat
    if(optSholat && optSholat.checked && sholatTimings){
      ALL_SHOLAT_KEYS.forEach(k=>{
        const t = sholatTimings[k];
        if(!t) return;
        const ts = tsFromHHMMOnDate(baseTs, t);
        const label = SHOLAT_LABEL[k]
          ? 'Sholat '+SHOLAT_LABEL[k]
          : (SHOLAT_LABEL_EXTRA[k] || k);
        pick(ts, label);
      });
    }

    return candidate;
  }

  // ====== Reload countdown ======
  function startReloadCountdown(){
    if(!reloadInfoEl) return;
    if(reloadCountdownInterval) clearInterval(reloadCountdownInterval);

    reloadCountdownInterval = setInterval(()=>{
      if(!reloadTargetTimestamp){
        reloadInfoEl.textContent = '-';
        return;
      }
      const ms = reloadTargetTimestamp - Date.now();
      if(ms <= 0){
        reloadInfoEl.textContent = 'Reloading...';
        clearInterval(reloadCountdownInterval);
        reloadCountdownInterval = null;
        location.reload();
        return;
      }
      const totalSec = Math.floor(ms/1000);
      const hh = String(Math.floor(totalSec/3600)).padStart(2,'0');
      const mm = String(Math.floor((totalSec%3600)/60)).padStart(2,'0');
      const ss = String(totalSec%60).padStart(2,'0');
      reloadInfoEl.textContent = `${hh}:${mm}:${ss}`;
    }, 500);
  }

  function scheduleSafeReload(hours=1){
    // kalau auto reload dimatikan, bersihkan jadwal & countdown
    if(!(optAutoReload && optAutoReload.checked)){
      reloadTargetTimestamp = null;
      if(reloadCountdownInterval){
        clearInterval(reloadCountdownInterval);
        reloadCountdownInterval = null;
      }
      if(reloadInfoEl) reloadInfoEl.textContent = '-';
      return;
    }

    const base = Date.now() + hours*3600*1000;
    const windowMs = 3*60*1000; // ±3 menit
    const block = findBlockingEventAround(base, windowMs);

    let target = base;
    if(block){
      target = block.ts + 60*1000;
    }
    reloadTargetTimestamp = target;
    startReloadCountdown();
  }

  // ========= START ENGINE (bisa tanpa suara) =========
  async function startEngine({withVoice=true, auto=false}={}){
    if(started) return;
    try{
      btnStart.disabled = true;

      setActiveTab('presensi');

      renderSholatListPlaceholder();
      await loadSholatBanyuwangi({announceOnError: !auto});
      const now = new Date();
      loadNationalHolidayYear(now.getFullYear());
      loadNationalHolidayYear(now.getFullYear()+1);

      scheduleRunner();
      scheduleSafeReload(1);
      fetchLogs();
      if(logRefreshIntervalId) clearInterval(logRefreshIntervalId);
      logRefreshIntervalId=setInterval(fetchLogs,10000);

      if(presensiCheckIntervalId) clearInterval(presensiCheckIntervalId);
      presensiCheckIntervalId=setInterval(()=>checkPresensiAlertsLoop(), 20000);

      if(sholatCheckIntervalId) clearInterval(sholatCheckIntervalId);
      sholatCheckIntervalId=setInterval(()=>checkSholatAlertsLoop(), 5000);

      if(orderCheckIntervalId) clearInterval(orderCheckIntervalId);
      checkOrderStatusLoop();
      orderCheckIntervalId=setInterval(()=>checkOrderStatusLoop(), ORDER_STATUS_INTERVAL_MS);

      if(window._sholatRefreshId) clearInterval(window._sholatRefreshId);
      window._sholatRefreshId = setInterval(async ()=>{
        const before = JSON.stringify(sholatTimings||{});
        await loadSholatBanyuwangi({announceOnError:false});
        const after = JSON.stringify(sholatTimings||{});
        if (before !== after) setNextRunFromCalculator();
      }, 6 * 3600 * 1000);

      scheduleSholatRefreshAt3AM();
      scheduleMidnightReset();
      startWatchdog();
      touchSchedulerHeartbeat('startEngine:ready');

      started=true;
      btnStart.classList.add('hidden');
      btnStop.classList.remove('hidden');
      statusBadge.textContent='Running';
      updateModeInfo();
    }catch(err){
      started=false;
      try {
        fetch(window.location.pathname+'?clientlog=1&msg=' + encodeURIComponent('startEngine error: '+(err && err.message ? err.message : String(err))));
      } catch(_) {}
      if(withVoice){
        alert('Browser menolak autoplay — klik halaman lalu tekan Start lagi.');
      } else {
        console.error('Auto start error:', err);
      }
    }finally{
      btnStart.disabled = false;
    }
  }

  // ====== Handlers ======
  btnStart.addEventListener('click', ()=>{
    startEngine({withVoice:true, auto:false});
  });

  btnStop.addEventListener('click', async ()=>{
    if(!started) return;
    if(activePlaybacks.size > 0){
      stopCurrentPlayback();
    }
    fetchLogsAfterDelay();
    started=false;

    if(intervalId){ clearTimeout(intervalId); intervalId=null; }
    if(hourlyIntervalId){ clearInterval(hourlyIntervalId); hourlyIntervalId=null; }
    if(countdownInterval){ clearInterval(countdownInterval); countdownInterval=null; }
    if(logRefreshIntervalId){ clearInterval(logRefreshIntervalId); logRefreshIntervalId=null; }
    if(presensiCheckIntervalId){ clearInterval(presensiCheckIntervalId); presensiCheckIntervalId=null; }
    if(sholatCheckIntervalId){ clearInterval(sholatCheckIntervalId); sholatCheckIntervalId=null; }
    if(orderCheckIntervalId){ clearInterval(orderCheckIntervalId); orderCheckIntervalId=null; }
    if(window._sholatRefreshId){ clearInterval(window._sholatRefreshId); window._sholatRefreshId=null; }
    if(midnightResetTimeoutId){ clearTimeout(midnightResetTimeoutId); midnightResetTimeoutId=null; }
    if(sholatRefreshTimeoutId){ clearTimeout(sholatRefreshTimeoutId); sholatRefreshTimeoutId=null; }
    if(watchdogIntervalId){ clearInterval(watchdogIntervalId); watchdogIntervalId=null; }
    if(reloadCountdownInterval){ clearInterval(reloadCountdownInterval); reloadCountdownInterval=null; }
    reloadTargetTimestamp = null;
    if(reloadInfoEl) reloadInfoEl.textContent='-';

    nextRunTimestamp=null;
    lastPlayedHour=null;
    lastPlayedHalfHourKey=null;
    lastKagetDeferKey=null;
    btnStart.classList.remove('hidden');
    btnStop.classList.add('hidden');
    statusBadge.textContent='Stopped';
    countdownEl.textContent='--:--:--';
    document.getElementById('nextEventLabel').textContent='-';
  });

  function setTestButtonState(running){
    if(!btnTest) return;
    btnTest.textContent = running ? 'Stop' : 'Tes Sekarang';
    btnTest.classList.toggle('border-rose-300', running);
    btnTest.classList.toggle('bg-rose-50', running);
    btnTest.classList.toggle('text-rose-700', running);
    btnTest.classList.toggle('hover:bg-rose-100', running);
    btnTest.classList.toggle('border-slate-200', !running);
    btnTest.classList.toggle('bg-slate-50', !running);
    btnTest.classList.toggle('text-slate-900', !running);
    btnTest.classList.toggle('hover:bg-slate-100', !running);
  }

  function setInlineTestButtonState(btn, running){
    if(!btn) return;
    btn.textContent = running ? 'Stop' : 'Test';
    btn.classList.toggle('border-rose-300', running);
    btn.classList.toggle('bg-rose-50', running);
    btn.classList.toggle('text-rose-700', running);
    btn.classList.toggle('hover:bg-rose-100', running);
    btn.classList.toggle('border-slate-200', !running);
    btn.classList.toggle('bg-slate-50', !running);
    btn.classList.toggle('text-slate-900', !running);
    btn.classList.toggle('hover:bg-slate-100', !running);
  }

  // Tes Sekarang
  btnTest.addEventListener('click', async ()=>{
    if(activePlaybacks.size > 0){
      stopCurrentPlayback();
      return;
    }
    try{
      const now = new Date();
      const kal = `Waktu sekarang menunjukkan ${waktuNatural(now.getHours(), now.getMinutes())}.`;
      const status = await playText(kal, { trackAsTest: true });
      if(status === 'ok') fetchLogsAfterDelay();
    }catch(e){
      alert('Gagal uji: '+e);
    }
  });

  if(btnTestHalfHour){
    btnTestHalfHour.addEventListener('click', async ()=>{
      if(btnTestHalfHour.dataset.running === '1'){
        stopCurrentPlayback();
        return;
      }
      try{
        const status = await playKaget({
          trackAsTest: true,
          onStart: ()=>{ btnTestHalfHour.dataset.running='1'; setInlineTestButtonState(btnTestHalfHour, true); },
          onFinish: ()=>{ btnTestHalfHour.dataset.running='0'; setInlineTestButtonState(btnTestHalfHour, false); }
        });
        if(status === 'ok'){
          logKaget('TEST PLAY manual');
          fetchLogsAfterDelay();
        }
      }catch(e){
        const em = (e && e.message) ? e.message : String(e);
        logKaget(`TEST ERROR ${em}`);
        alert('Gagal test 30m (kaget.mp3): '+e);
      }
    });
  }

  if(btnTestPulang5){
    btnTestPulang5.addEventListener('click', async ()=>{
      if(btnTestPulang5.dataset.running === '1'){
        stopCurrentPlayback();
        return;
      }
      try{
        const status = await playText(buildPulangPlus5Message(new Date()), {
          trackAsTest: true,
          withBell: false,
          onStart: ()=>{ btnTestPulang5.dataset.running='1'; setInlineTestButtonState(btnTestPulang5, true); },
          onFinish: ()=>{ btnTestPulang5.dataset.running='0'; setInlineTestButtonState(btnTestPulang5, false); }
        });
        if(status === 'ok') fetchLogsAfterDelay();
      }catch(e){
        alert('Gagal test Pulang+5: '+e);
      }
    });
  }
  if(btnTestPantunPulang){
    btnTestPantunPulang.addEventListener('click', async ()=>{
      if(btnTestPantunPulang.dataset.running === '1'){
        stopCurrentPlayback();
        return;
      }
      try{
        const status = await playText(pickRandomPantunPulangMessage(), {
          trackAsTest: true,
          withBell: false,
          onStart: ()=>{ btnTestPantunPulang.dataset.running='1'; setInlineTestButtonState(btnTestPantunPulang, true); },
          onFinish: ()=>{ btnTestPantunPulang.dataset.running='0'; setInlineTestButtonState(btnTestPantunPulang, false); }
        });
        if(status === 'ok') fetchLogsAfterDelay();
      }catch(e){
        alert('Gagal test pantun pulang: '+e);
      }
    });
  }

  function updateModeInfo(){
    const modes=[];
    if(optTest1Min && optTest1Min.checked) modes.push('1-minute testing');
    if(optHourly && optHourly.checked) modes.push('hourly');
    if(optHalfHourly && optHalfHourly.checked) modes.push('half-hour-kaget');
    if(optPresensi && optPresensi.checked) modes.push('presensi-alerts');
    if(optPresensi && optPresensi.checked) modes.push('pulang+20-pantun');
    if(optSholat && optSholat.checked) modes.push('sholat-alerts');
    if(optAutoReload && optAutoReload.checked) modes.push('auto-reload');
    modeInfo.textContent = modes.length ? modes.join(' + ') : 'None';
  }

  [optTest1Min,optHourly,optHalfHourly,optPresensi,optSholat,optAutoReload].forEach(el=>{
    if(!el) return;
    el.addEventListener('change',()=>{
      saveCheckboxState();
      updateModeInfo();
      if(started){
        scheduleRunner();
        scheduleSafeReload(1);
      }else{
        scheduleSafeReload(1);
      }
    });
  });

  if(tabLogTts){
    tabLogTts.addEventListener('click', ()=>{
      setActiveLogTab('tts');
      fetchLogs();
    });
  }
  if(tabLogClient){
    tabLogClient.addEventListener('click', ()=>{
      setActiveLogTab('client');
      fetchLogs();
    });
  }

  // ====== INIT ======
  loadCheckboxState();
  updateModeInfo();
  setActiveTab('presensi');
  setActiveLogTab('tts');
  renderSholatListPlaceholder();
  startMonitoringTicker();
  fetchLogs();
  if(logRefreshIntervalId) clearInterval(logRefreshIntervalId);
  logRefreshIntervalId=setInterval(fetchLogs,10000);
  setNextRunFromCalculator();
  if(reloadInfoEl) reloadInfoEl.textContent='-';
  scheduleSafeReload(1);

  // Auto-start engine tanpa ucapan "aplikasi aktif"
  startEngine({withVoice:false, auto:true});

})();
</script>
</body>
</html>





