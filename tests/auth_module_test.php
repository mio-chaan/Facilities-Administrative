<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/includes/auth_otp.php';

if (!function_exists('t8_current_role')) {
    function t8_current_role(): ?string
    {
        return $_SESSION['role'] ?? null;
    }
}
require_once __DIR__ . '/../app/includes/permissions.php';

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $name) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "PASS {$name}" . PHP_EOL;
    } else {
        $failed++;
        fwrite(STDERR, "FAIL {$name}" . PHP_EOL);
    }
};

t8_session_start();
$_SESSION = [];
$assert(session_status() === PHP_SESSION_ACTIVE, 'session starts');
$cookie = session_get_cookie_params();
$assert($cookie['httponly'] === true && $cookie['samesite'] === 'Lax', 'session cookie security flags');
$assert($cookie['secure'] === str_starts_with(strtolower(APP_URL), 'https://'), 'session cookie secure flag follows HTTPS');

$token = t8_csrf_token();
$assert((bool) preg_match('/^[a-f0-9]{64}$/', $token), 'CSRF token is 256-bit hex');
$assert(t8_csrf_token() === $token, 'CSRF token is stable for the session');
$assert(str_contains(t8_csrf_field(), 'name="csrf_token"'), 'CSRF field renders');
$assert(t8_csrf_verify($token), 'CSRF accepts the session token');
$assert(!t8_csrf_verify(null) && !t8_csrf_verify('') && !t8_csrf_verify('wrong'), 'CSRF rejects missing and incorrect tokens');
unset($_SESSION['t8_csrf']);
$assert(!t8_csrf_verify($token), 'CSRF rejects a token without session state');

$_SESSION = [];
$assert(!t8_has_role('employee') && !t8_is_admin() && !t8_is_staff(), 'anonymous session has no permissions');
$_SESSION['role'] = 'employee';
$assert(t8_has_role('employee') && !t8_is_admin() && t8_is_staff(), 'employee receives only operational role status');
$_SESSION['role'] = 'admin';
$assert(t8_is_admin() && !t8_is_staff(), 'admin is not classified as operational staff');
$_SESSION['role'] = 'super_admin';
$assert(t8_has_role('admin'), 'super-admin inherits admin access');

$assert(t8_otp_mask_email('alice@example.com') === 'a****@example.com', 'OTP masks a regular email');
$assert(t8_otp_mask_email('a@example.com') === 'a**@example.com', 'OTP masks a one-character email local part');
$assert(t8_otp_mask_email('invalid') === 'your registered email', 'OTP handles malformed email safely');

$assert(!t8_otp_is_expired(['expires_at' => gmdate('Y-m-d H:i:s', time() + 60)]), 'OTP accepts a future expiry');
$assert(t8_otp_is_expired(['expires_at' => gmdate('Y-m-d H:i:s', time() - 60)]), 'OTP rejects an elapsed expiry');
$assert(t8_otp_is_expired(['expires_at' => 'invalid']), 'OTP treats invalid expiry data as expired');
$assert(t8_otp_is_expired([]), 'OTP treats missing expiry data as expired');
$assert(t8_otp_resend_wait(['created_at' => gmdate('Y-m-d H:i:s', time() - 61)]) === 0, 'OTP resend cooldown expires at its boundary');
$assert(t8_otp_resend_wait(['created_at' => 'invalid']) === 0, 'OTP resend wait handles invalid timestamps');
$assert(T8_OTP_EXPIRY_SECONDS === 300 && T8_OTP_RESEND_COOLDOWN_SECONDS === 60, 'OTP expiry and resend boundaries');
$assert(T8_OTP_MAX_REQUESTS_PER_HOUR === 5 && T8_OTP_MAX_ATTEMPTS === 5, 'OTP request and verification attempt limits');

session_write_close();
echo "{$passed} passed / {$failed} failed" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
