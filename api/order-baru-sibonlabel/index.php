<?php
@ini_set('display_errors', '0');
@error_reporting(0);

$apiKey = '123321';
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

echo json_encode([
    'ok' => true,
    'message' => 'Ada order baru sibonlabel, cek ya gaes!'
], JSON_UNESCAPED_UNICODE);
