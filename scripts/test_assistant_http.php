<?php
// scripts/test_assistant_http.php
$url = 'http://localhost:8000/index.php?page=assistant';
$post = ['message' => 'hello', 'csrf_token' => 'bad-token'];
$options = [
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($post),
        'ignore_errors' => true,
    ],
];
$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);
$headers = isset($http_response_header) ? implode("\n", $http_response_header) : '';

echo "HEADERS:\n" . $headers . "\n\n";
echo "BODY:\n" . ($response === false ? '(no response)' : $response) . "\n";
