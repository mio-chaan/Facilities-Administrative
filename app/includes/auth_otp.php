<?php
declare(strict_types=1);

require_once __DIR__ . '/gmail_dispatch.php';

const T8_OTP_EXPIRY_SECONDS = 300;
const T8_OTP_RESEND_COOLDOWN_SECONDS = 60;
const T8_OTP_MAX_REQUESTS_PER_HOUR = 5;
const T8_OTP_MAX_ATTEMPTS = 5;

final class T8OtpRateLimitException extends RuntimeException
{
    public function __construct(private int $waitSeconds)
    {
        parent::__construct('OTP request rate limit reached.');
    }

    public function getWaitSeconds(): int
    {
        return $this->waitSeconds;
    }
}

final class T8OtpDeliveryException extends RuntimeException
{
    public function __construct(private int $challengeId, Throwable $previous)
    {
        parent::__construct('The verification email could not be sent.', 0, $previous);
    }

    public function getChallengeId(): int
    {
        return $this->challengeId;
    }
}

function t8_otp_issue(PDO $pdo, int $userId, string $email): int
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The account email address is invalid.');
    }

    $pdo->beginTransaction();
    try {
        $userStmt = $pdo->prepare('SELECT id FROM users WHERE id = :user_id AND email = :email FOR UPDATE');
        $userStmt->execute(['user_id' => $userId, 'email' => $email]);
        if (!$userStmt->fetchColumn()) {
            throw new RuntimeException('The account could not be verified.');
        }

        $requestsStmt = $pdo->prepare(
            'SELECT created_at FROM otp_codes
             WHERE user_id = :user_id AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)
             ORDER BY id DESC'
        );
        $requestsStmt->execute(['user_id' => $userId]);
        $recentRequests = $requestsStmt->fetchAll(PDO::FETCH_COLUMN);
        $now = time();

        if ($recentRequests !== []) {
            $lastRequested = strtotime((string) $recentRequests[0] . ' UTC');
            $cooldownRemaining = T8_OTP_RESEND_COOLDOWN_SECONDS - ($now - $lastRequested);
            if ($cooldownRemaining > 0) {
                throw new T8OtpRateLimitException($cooldownRemaining);
            }
        }
        if (count($recentRequests) >= T8_OTP_MAX_REQUESTS_PER_HOUR) {
            $oldestRequest = strtotime((string) $recentRequests[count($recentRequests) - 1] . ' UTC');
            throw new T8OtpRateLimitException(max(1, $oldestRequest + 3600 - $now));
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        if ($codeHash === false) {
            throw new RuntimeException('The verification code could not be secured.');
        }

        $pdo->prepare('UPDATE otp_codes SET used = 1 WHERE user_id = :user_id AND used = 0')
            ->execute(['user_id' => $userId]);
        $createdAt = gmdate('Y-m-d H:i:s');
        $expiresAt = gmdate('Y-m-d H:i:s', $now + T8_OTP_EXPIRY_SECONDS);
        $insert = $pdo->prepare(
            'INSERT INTO otp_codes (user_id, code_hash, created_at, expires_at)
             VALUES (:user_id, :code_hash, :created_at, :expires_at)'
        );
        $insert->execute([
            'user_id' => $userId,
            'code_hash' => $codeHash,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
        ]);
        $challengeId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $subject = 'Your sign-in verification code';
    $htmlBody = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#292321;line-height:1.6">'
        . '<h1 style="color:#781d32">Sign-in verification</h1>'
        . '<p>Your one-time sign-in code is <strong>' . $code . '</strong>.</p>'
        . '<p>This code expires in 5 minutes. If you did not try to sign in, you can ignore this email.</p>'
        . '</body></html>';
    $textBody = "Your one-time sign-in code is {$code}.\nThis code expires in 5 minutes. If you did not try to sign in, you can ignore this email.";

    try {
        t8_gmail_dispatch([$email], $subject, $htmlBody, $textBody, [], 'otp');
    } catch (Throwable $e) {
        $pdo->prepare('UPDATE otp_codes SET used = 1 WHERE id = :id AND user_id = :user_id')
            ->execute(['id' => $challengeId, 'user_id' => $userId]);
        throw new T8OtpDeliveryException($challengeId, $e);
    }

    return $challengeId;
}

function t8_otp_get_challenge(PDO $pdo, int $userId, int $challengeId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT otp.id, otp.code_hash, otp.created_at, otp.expires_at, otp.used, otp.attempts, u.email
         FROM otp_codes otp
         JOIN users u ON u.id = otp.user_id
         WHERE otp.id = :id AND otp.user_id = :user_id
         LIMIT 1'
    );
    $stmt->execute(['id' => $challengeId, 'user_id' => $userId]);
    $challenge = $stmt->fetch(PDO::FETCH_ASSOC);
    return $challenge ?: null;
}

function t8_otp_is_expired(array $challenge): bool
{
    $expiry = trim((string) ($challenge['expires_at'] ?? ''));
    if ($expiry === '') {
        return true;
    }

    $expiresAt = strtotime($expiry . ' UTC');
    return $expiresAt === false || $expiresAt <= time();
}

function t8_otp_resend_wait(array $challenge): int
{
    $createdAt = strtotime((string) ($challenge['created_at'] ?? '') . ' UTC');
    if ($createdAt === false) {
        return 0;
    }
    return max(0, T8_OTP_RESEND_COOLDOWN_SECONDS - (time() - $createdAt));
}

function t8_otp_verify(PDO $pdo, int $userId, int $challengeId, string $code): string
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT code_hash, expires_at, used, attempts
             FROM otp_codes WHERE id = :id AND user_id = :user_id FOR UPDATE'
        );
        $stmt->execute(['id' => $challengeId, 'user_id' => $userId]);
        $challenge = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$challenge || (bool) $challenge['used']) {
            $pdo->commit();
            return 'invalid';
        }
        if (t8_otp_is_expired($challenge)) {
            $pdo->commit();
            return 'expired';
        }
        if ((int) $challenge['attempts'] >= T8_OTP_MAX_ATTEMPTS) {
            $pdo->commit();
            return 'exhausted';
        }

        $matches = preg_match('/^\d{6}$/', $code) === 1
            && password_verify($code, (string) $challenge['code_hash']);
        if (!$matches) {
            $pdo->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = :id')
                ->execute(['id' => $challengeId]);
            $pdo->commit();
            return 'wrong';
        }

        $pdo->prepare('UPDATE otp_codes SET used = 1 WHERE id = :id')->execute(['id' => $challengeId]);
        $pdo->commit();
        return 'verified';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function t8_otp_mask_email(string $email): string
{
    [$localPart, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if ($localPart === '' || $domain === '') {
        return 'your registered email';
    }
    return substr($localPart, 0, 1) . str_repeat('*', max(2, strlen($localPart) - 1)) . '@' . $domain;
}
