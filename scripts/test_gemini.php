<?php
// scripts/test_gemini.php
require __DIR__ . '/../app/config/config.php';
require __DIR__ . '/../app/includes/ai_helper.php';

try {
    $reply = t8_ai_chat([
        ['role' => 'system', 'content' => 'You are a helpful assistant for testing.'],
        ['role' => 'user', 'content' => 'Say hello in one short sentence.']
    ]);
    echo "OK\n";
    echo $reply . "\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
