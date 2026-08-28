<?php
// scripts/test_assistant.php
require __DIR__ . '/../app/config/config.php';
require __DIR__ . '/../app/includes/helpers.php';
require __DIR__ . '/../app/includes/ai_helper.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['t8_csrf'] = 'test-token';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['csrf_token'] = 'test-token';
$_POST['message'] = 'Hello, how are you?';

ob_start();
require __DIR__ . '/../modules/assistant/index.php';
$output = ob_get_clean();

echo "ASSISTANT OUTPUT:\n";
echo $output;
