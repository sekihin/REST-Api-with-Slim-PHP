<?php
$url = 'http://localhost:8080/api/chat';

$data = [
    'message' => '我昨天买的那个商品怎么还没发货？',
    ];

$options = [
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/json',
        'content' => json_encode($data),
        ]
];

$context = stream_context_create($options);
$result = file_get_contents($url, false, $context);

if ($result === false) {
    $error = error_get_last();
    echo "HTTP request failed!\n";
    if ($error && isset($error['message'])) {
        echo "Error message: " . $error['message'] . "\n";
    }
    // Try to fetch HTTP response code and body if possible
    if (isset($http_response_header)) {
        echo "HTTP response headers:\n";
        foreach ($http_response_header as $header) {
            echo $header . "\n";
        }
    }
} else {
    print_r($result);
}
