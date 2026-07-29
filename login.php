<?php
/**
 * login.php
 * Verifies email/password against the shared users table and sets
 * the session contract documented in app/includes/auth_check.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/includes/db_connect.php';
require_once __DIR__ . '/app/includes/helpers.php';
require_once __DIR__ . '/app/includes/audit.php';

t8_session_start();

// Already logged in? Skip the form.
if (!empty($_SESSION['user_id'])) {
    redirect(APP_URL . '/index.php?page=dashboard');
}

const T8_LOGIN_MAX_ATTEMPTS  = 5;
const T8_LOGIN_LOCKOUT_SECS  = 300; // 5 minutes

$_SESSION['t8_login_attempts']    ??= 0;
$_SESSION['t8_login_locked_until'] ??= 0;

$isLockedOut = $_SESSION['t8_login_locked_until'] > time();

$errors = [];
$emailValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isLockedOut) {
        $waitSecs = $_SESSION['t8_login_locked_until'] - time();
        $errors[] = "Too many failed attempts. Try again in {$waitSecs}s.";
    } else {
        $emailValue = trim($_POST['email'] ?? '');
        $password   = (string) ($_POST['password'] ?? '');

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

                $_SESSION['t8_login_attempts']++;
                if ($_SESSION['t8_login_attempts'] >= T8_LOGIN_MAX_ATTEMPTS) {
                    $_SESSION['t8_login_locked_until'] = time() + T8_LOGIN_LOCKOUT_SECS;
                    $_SESSION['t8_login_attempts'] = 0;
                    $errors = ['Too many failed attempts. Try again in ' . T8_LOGIN_LOCKOUT_SECS . 's.'];
                }
            } else {
                $roleStmt = $pdo->prepare(
                    'SELECT r.role_name
                     FROM user_roles ur
                     JOIN roles r ON r.id = ur.role_id
                     WHERE ur.user_id = :user_id
                     LIMIT 1'
                );
                $roleStmt->execute(['user_id' => $user['id']]);
                $role = $roleStmt->fetchColumn();

                session_regenerate_id(true);
                $_SESSION['user_id']       = (int) $user['id'];
                $_SESSION['full_name']     = $user['full_name'];
                $_SESSION['role']          = $role ?: 'employee';
                $_SESSION['department_id'] = $user['department_id'] !== null ? (int) $user['department_id'] : null;
                $_SESSION['t8_login_attempts']     = 0;
                $_SESSION['t8_login_locked_until'] = 0;

                t8_audit_log($pdo, (int) $user['id'], 'user', (int) $user['id'], 'login');

                t8_flash_set('success', 'Welcome back, ' . $user['full_name'] . '.');
                redirect(APP_URL . '/index.php?page=dashboard');
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
</head>
<body>
<div class="t8-auth-wrapper">
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
            Local dev seed account: <code>dev.tester@example.local</code> /
            <code>Password123!</code> (see <code>database/seed.sql</code>).
        </p>
    </div>
</div>
</body>
</html>
