<?php
declare(strict_types=1);

function t8_gmail_diagnostic_log(string $context, string $detail): void
{
    $logDirectory = dirname(__DIR__, 2) . '/storage/logs';
    if (!is_dir($logDirectory) && !mkdir($logDirectory, 0775, true) && !is_dir($logDirectory)) {
        error_log($context . ': ' . $detail);
        return;
    }
    $logEntry = sprintf("[%s] %s: %s%s", date('Y-m-d H:i:s'), $context, $detail, PHP_EOL);
    if (file_put_contents($logDirectory . '/compliance-mail-debug.log', $logEntry, FILE_APPEND | LOCK_EX) === false) {
        error_log($logEntry);
    }
}

function t8_gmail_dispatch(array $recipients, string $subject, string $htmlBody, string $textBody, array $attachments = [], ?string $diagnosticContext = null): void
{
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Email dependencies are missing. Run Composer install first.');
    }
    require_once $autoload;
    if (!defined('GMAIL_SMTP_USER') || GMAIL_SMTP_USER === ''
        || !defined('GMAIL_SMTP_APP_PASSWORD') || GMAIL_SMTP_APP_PASSWORD === '') {
        throw new RuntimeException('Gmail SMTP is not configured. Add GMAIL_SMTP_USER and GMAIL_SMTP_APP_PASSWORD to app/config/config.local.php.');
    }
    if ($recipients === []) {
        throw new RuntimeException('At least one email recipient is required.');
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = GMAIL_SMTP_USER;
    $mail->Password = GMAIL_SMTP_APP_PASSWORD;
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom(GMAIL_SMTP_USER, 'Facilities & Administration');
    foreach (array_unique($recipients) as $recipient) {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A renewal email recipient address is invalid.');
        }
        $mail->addAddress($recipient);
    }
    foreach ($attachments as $attachment) {
        $path = (string) ($attachment['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('An email attachment is missing.');
        }
        $name = (string) ($attachment['name'] ?? basename($path));
        $mail->addAttachment($path, $name);
    }
    $mail->Subject = $subject;
    $mail->isHTML(true);
    $mail->Body = $htmlBody;
    $mail->AltBody = $textBody;
    try {
        $mail->send();
    } catch (Throwable $e) {
        $errorInfo = trim((string) $mail->ErrorInfo);
        $detail = $errorInfo !== '' ? $errorInfo : $e->getMessage();
        if ($diagnosticContext === 'compliance_report') {
            t8_gmail_diagnostic_log('Compliance Report PHPMailer ErrorInfo', $detail . ' | Exception: ' . $e->getMessage());
        }
        throw new RuntimeException($detail, (int) $e->getCode(), $e);
    }
}