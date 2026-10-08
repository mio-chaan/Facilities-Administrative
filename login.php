<?php
/**
 * login.php
 * Verifies email/password against the shared users table and sets
 * the session contract documented in app/includes/auth_check.php.
 *
 * BUG FIX: throttling previously lived in $_SESSION, which resets the
 * instant a client drops its session cookie. It now persists in the
 * team8_login_throttle table (see database/schema.sql), keyed by the
 * lowercased email being attempted, so the lockout survives across
 * sessions/browsers for that account. A GET (just showing the form)
 * no longer needs to check lockout state, since we don't know which
 * account is being targeted until a POST supplies an email.
 */

declare(strict_types=1);

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/includes/db_connect.php';
require_once __DIR__ . '/app/includes/helpers.php';
require_once __DIR__ . '/app/includes/audit.php';
require_once __DIR__ . '/app/includes/auth_otp.php';

t8_session_start();

// Already logged in? Skip the form.
if (!empty($_SESSION['user_id'])) {
    redirect(APP_URL . '/dashboard.php');
}

const T8_LOGIN_MAX_ATTEMPTS  = 5;
const T8_LOGIN_LOCKOUT_SECS  = 300; // 5 minutes

/** Fetch the throttle row for this identifier (lowercased email), or null. */
function t8_login_throttle_fetch(PDO $pdo, string $identifier): ?array
{
    $stmt = $pdo->prepare('SELECT attempts, locked_until FROM team8_login_throttle WHERE identifier = :id LIMIT 1');
    $stmt->execute(['id' => $identifier]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** True if the given throttle row is currently within its lockout window. */
function t8_login_is_locked(?array $throttle): bool
{
    return $throttle !== null
        && $throttle['locked_until'] !== null
        && strtotime((string) $throttle['locked_until']) > time();
}

/**
 * Records a failed attempt for this identifier. Returns true if this
 * failure just triggered a new lockout.
 */
function t8_login_record_failure(PDO $pdo, string $identifier): bool
{
    $throttle = t8_login_throttle_fetch($pdo, $identifier);
    $attempts = ($throttle['attempts'] ?? 0) + 1;

    if ($attempts >= T8_LOGIN_MAX_ATTEMPTS) {
        $lockedUntil = date('Y-m-d H:i:s', time() + T8_LOGIN_LOCKOUT_SECS);
        $pdo->prepare(
            'INSERT INTO team8_login_throttle (identifier, attempts, locked_until)
             VALUES (:id, 0, :locked_until)
             ON DUPLICATE KEY UPDATE attempts = 0, locked_until = :locked_until2'
        )->execute(['id' => $identifier, 'locked_until' => $lockedUntil, 'locked_until2' => $lockedUntil]);
        return true;
    }

    $pdo->prepare(
        'INSERT INTO team8_login_throttle (identifier, attempts, locked_until)
         VALUES (:id, :attempts, NULL)
         ON DUPLICATE KEY UPDATE attempts = :attempts2, locked_until = NULL'
    )->execute(['id' => $identifier, 'attempts' => $attempts, 'attempts2' => $attempts]);
    return false;
}

/** Clears the throttle row for this identifier on a successful login. */
function t8_login_clear_throttle(PDO $pdo, string $identifier): void
{
    $pdo->prepare('DELETE FROM team8_login_throttle WHERE identifier = :id')->execute(['id' => $identifier]);
}

$errors = [];
$emailValue = '';
$isLockedOut = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailValue = trim($_POST['email'] ?? '');
    $identifier = strtolower($emailValue);
    $throttle = $identifier !== '' ? t8_login_throttle_fetch($pdo, $identifier) : null;
    $isLockedOut = t8_login_is_locked($throttle);

    if ($isLockedOut) {
        $waitSecs = strtotime((string) $throttle['locked_until']) - time();
        $errors[] = "Too many failed attempts. Try again in {$waitSecs}s.";
    } else {
        $password = (string) ($_POST['password'] ?? '');

        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            $errors[] = 'Your session expired. Please try again.';
        } elseif ($emailValue === '' || $password === '') {
            $errors[] = 'Email and password are both required.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, full_name, password_hash, department_id
                 FROM users
                 WHERE email = :email
                 LIMIT 1'
            );
            $stmt->execute(['email' => $emailValue]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                // Deliberately vague — never reveal whether the email exists.
                $errors[] = 'Invalid email or password.';

                $justLockedOut = t8_login_record_failure($pdo, $identifier);
                if ($justLockedOut) {
                    $isLockedOut = true;
                    $errors = ['Too many failed attempts. Try again in ' . T8_LOGIN_LOCKOUT_SECS . 's.'];
                }
            } else {
                t8_login_clear_throttle($pdo, $identifier);

                try {
                    $challengeId = t8_otp_issue($pdo, (int) $user['id'], $emailValue);
                    $_SESSION['t8_otp_user_id'] = (int) $user['id'];
                    $_SESSION['t8_otp_challenge_id'] = $challengeId;
                    redirect(APP_URL . '/verify_otp.php');
                } catch (T8OtpRateLimitException $e) {
                    $errors[] = 'Please wait ' . $e->getWaitSeconds() . ' seconds before requesting another verification code.';
                } catch (T8OtpDeliveryException $e) {
                    $errors[] = 'We could not send a verification code. Please try again later.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/components.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/login-ambience.css')) ?>?v=11">
</head>
<body>
<div class="t8-auth-wrapper">
    <div class="t8-auth-ambience" aria-hidden="true">
        <div class="t8-auth-cloud t8-auth-cloud-one"></div>
        <div class="t8-auth-cloud t8-auth-cloud-two"></div>
        <div class="t8-auth-cloud t8-auth-cloud-three"></div>

        <div class="t8-auth-branch t8-auth-branch-left">
            <i></i><i></i><i></i><i></i><i></i><i></i>
        </div>
        <div class="t8-auth-branch t8-auth-branch-right">
            <i></i><i></i><i></i><i></i><i></i><i></i>
        </div>

        <?php
        $bodyPath = __DIR__ . '/public/assets/ram-yum/ramen/body.png';
        if (is_file($bodyPath)):
        ?>
            <div class="t8-auth-mascot">
                <div class="t8-auth-mascot-art">
                    <img class="t8-auth-mascot-body" src="<?= e(asset('assets/ram-yum/ramen/body.png')) ?>" alt="">
                    <img class="t8-auth-mascot-eye t8-auth-mascot-eye-left" src="<?= e(asset('assets/ram-yum/ramen/eye-left.png')) ?>" alt="">
                    <img class="t8-auth-mascot-eye t8-auth-mascot-eye-right" src="<?= e(asset('assets/ram-yum/ramen/eye-right.png')) ?>" alt="">
                    <img class="t8-auth-mascot-mouth" src="<?= e(asset('assets/ram-yum/ramen/mouth.png')) ?>" alt="">
                    <div class="t8-auth-steam">
                        <i></i><i></i><i></i><i></i><i></i>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <div class="t8-auth-petals"></div>
    </div>

    <div class="t8-card t8-auth-card">
        <img class="t8-auth-logo" src="<?= e(asset('img/ramyumlogo.jpg')) ?>" alt="RAM-YUM Korean and Japanese Store">
        <h1 class="t8-auth-title"><?= e(APP_NAME) ?></h1>
      

        <?php foreach ($errors as $error): ?>
            <div class="t8-alert t8-alert-danger"><?= e($error) ?></div>
        <?php endforeach; ?>

        <form method="post" action="<?= e(base_url('login.php')) ?>" novalidate>
            <?= t8_csrf_field() ?>

            <div class="t8-field">
                <label class="t8-label" for="email">Email</label>
                <input class="t8-input" type="email" id="email" name="email"
                       value="<?= e($emailValue) ?>" required autofocus
                       <?= $isLockedOut ? 'disabled' : '' ?>>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="password">Password</label>
                <input class="t8-input" type="password" id="password" name="password" required
                       <?= $isLockedOut ? 'disabled' : '' ?>>
            </div>

            <button class="t8-btn t8-btn-accent t8-auth-submit" type="submit" <?= $isLockedOut ? 'disabled' : '' ?>>
                Sign In
            </button>
        </form>

        <p class="t8-help-text t8-auth-hint">
            Local dev seed account: <code>ramyum060@gmail.com</code> /
            <code> Password123!@ </code> (see <code>database/seed_account.sql</code>); sign-in also requires
            access to the account's registered email for the OTP.
        </p>
    </div>
</div>
<script src="<?= e(asset('js/login-ambience.js')) ?>?v=6" defer></script>
</body>
</html>
