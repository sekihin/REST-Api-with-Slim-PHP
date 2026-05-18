<?php

declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
if (ob_get_level()) {
    ob_end_clean();
}

$input = file_get_contents('php://input');
$data  = json_decode($input, true) ?: ['message' => ''];

$targetUrl = getenv('RAG_BACKEND_URL') ?: 'http://127.0.0.1:8080/agent/rag';
$buffer    = '';

$ch = curl_init($targetUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: text/event-stream',
    ],
    CURLOPT_POSTFIELDS     => http_build_query(array_merge($data, ['stream' => '1'])),
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$buffer) {
        $buffer .= $chunk;
        while (($pos = strpos($buffer, "\n\n")) !== false) {
            $event  = substr($buffer, 0, $pos + 2);
            $buffer = substr($buffer, $pos + 2);
            echo $event;
            flush();
        }
        return strlen($chunk);
    },
]);

$ok    = curl_exec($ch);
if ($ok === false) {
    $error = curl_error($ch);
}
$code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($buffer !== '') {
    echo $buffer;
    flush();
}

if (!$ok || ($code !== 200 && $code !== 0)) {
    echo 'data: ' . json_encode(['error' => $error ?: "HTTP {$code}"], JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

echo "data: [DONE]\n\n";
flush();
