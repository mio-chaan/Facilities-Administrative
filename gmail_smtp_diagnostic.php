<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/includes/gmail_dispatch.php';

if (!defined('GMAIL_SMTP_USER') || GMAIL_SMTP_USER === ''
    || !defined('GMAIL_SMTP_APP_PASSWORD') || GMAIL_SMTP_APP_PASSWORD === '') {
    fwrite(STDERR, "SMTP sender or app password is not configured.\n");
    exit(2);
}

try {
    t8_gmail_dispatch(
        [GMAIL_SMTP_USER],
        'Gmail SMTP configuration test',
        '<!doctype html><html><body><p>Your Gmail SMTP configuration test succeeded.</p></body></html>',
        'Your Gmail SMTP configuration test succeeded.'
    );
    fwrite(STDOUT, 'SMTP authenticated and accepted a test message for ' . GMAIL_SMTP_USER . ".\n");
} catch (Throwable $e) {
    error_log('Gmail SMTP diagnostic failed (' . get_class($e) . ').');
    fwrite(STDERR, "SMTP diagnostic failed. No password or message contents were logged.\n");
    exit(1);
}
