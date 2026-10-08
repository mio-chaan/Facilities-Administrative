<?php
declare(strict_types=1);

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/includes/db_connect.php';
require_once __DIR__ . '/app/includes/helpers.php';
require_once __DIR__ . '/app/includes/auth_otp.php';
require_once __DIR__ . '/app/includes/audit.php';

t8_session_start();

if (!empty($_SESSION['user_id'])) {
    redirect(APP_URL . '/dashboard.php');
}

$pendingUserId = (int) ($_SESSION['t8_otp_user_id'] ?? 0);
$challengeId = (int) ($_SESSION['t8_otp_challenge_id'] ?? 0);
if ($pendingUserId <= 0 || $challengeId <= 0) {
    redirect(APP_URL . '/login.php');
}

$challenge = t8_otp_get_challenge($pdo, $pendingUserId, $challengeId);
if ($challenge === null) {
    unset($_SESSION['t8_otp_user_id'], $_SESSION['t8_otp_challenge_id']);
    redirect(APP_URL . '/login.php');
}

$errors = [];
$successMessage = '';
$expired = t8_otp_is_expired($challenge);
$exhausted = (int) $challenge['attempts'] >= T8_OTP_MAX_ATTEMPTS;
$isUsed = (bool) $challenge['used'];
$resendWaitOverride = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? 'verify');
        if ($action === 'back') {
            $pdo->prepare('UPDATE otp_codes SET used = 1 WHERE id = :id AND user_id = :user_id')
                ->execute(['id' => $challengeId, 'user_id' => $pendingUserId]);
            unset($_SESSION['t8_otp_user_id'], $_SESSION['t8_otp_challenge_id']);
            redirect(APP_URL . '/login.php');
        } elseif ($action === 'resend') {
            try {
                $challengeId = t8_otp_issue($pdo, $pendingUserId, (string) $challenge['email']);
                $_SESSION['t8_otp_challenge_id'] = $challengeId;
                $challenge = t8_otp_get_challenge($pdo, $pendingUserId, $challengeId);
                if ($challenge === null) {
                    throw new RuntimeException('The new verification challenge could not be loaded.');
                }
                $expired = false;
                $exhausted = false;
                $isUsed = false;
                $successMessage = 'A new verification code has been sent.';
            } catch (T8OtpRateLimitException $e) {
                $errors[] = 'Please wait ' . $e->getWaitSeconds() . ' seconds before requesting another code.';
                $resendWaitOverride = $e->getWaitSeconds();
            } catch (T8OtpDeliveryException $e) {
                $errors[] = 'We could not send a new verification code. Please try again later.';
                $challengeId = $e->getChallengeId();
                $_SESSION['t8_otp_challenge_id'] = $challengeId;
                $challenge = t8_otp_get_challenge($pdo, $pendingUserId, $challengeId) ?? $challenge;
                $isUsed = true;
                $resendWaitOverride = T8_OTP_RESEND_COOLDOWN_SECONDS;
            }
        } elseif ($action === 'verify') {
            $result = t8_otp_verify($pdo, $pendingUserId, $challengeId, (string) ($_POST['code'] ?? ''));
            if ($result === 'verified') {
                $userStmt = $pdo->prepare('SELECT id, full_name, department_id FROM users WHERE id = :user_id LIMIT 1');
                $userStmt->execute(['user_id' => $pendingUserId]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);
                if (!$user) {
                    unset($_SESSION['t8_otp_user_id'], $_SESSION['t8_otp_challenge_id']);
                    redirect(APP_URL . '/login.php');
                }

                $roleStmt = $pdo->prepare(
                    'SELECT r.role_name
                     FROM user_roles ur
                     JOIN roles r ON r.id = ur.role_id
                     WHERE ur.user_id = :user_id'
                );
                $roleStmt->execute(['user_id' => $pendingUserId]);
                $roles = array_map('strval', $roleStmt->fetchAll(PDO::FETCH_COLUMN));
                $priority = ['admin', 'facilities_staff', 'front_desk', 'records_officer', 'legal_officer', 'employee'];
                $role = null;
                foreach ($priority as $candidate) {
                    if (in_array($candidate, $roles, true)) {
                        $role = $candidate;
                        break;
                    }
                }
                if ($role === null && $roles !== []) {
                    $role = $roles[0];
                }

                session_regenerate_id(true);
                unset($_SESSION['t8_otp_user_id'], $_SESSION['t8_otp_challenge_id']);
                $_SESSION['user_id']       = (int) $user['id'];
                $_SESSION['full_name']     = $user['full_name'];
                $_SESSION['role']          = $role ?: 'employee';
                $_SESSION['department_id'] = $user['department_id'] !== null ? (int) $user['department_id'] : null;

                t8_audit_log($pdo, (int) $user['id'], 'user', (int) $user['id'], 'login');
                t8_flash_set('success', 'Welcome back, ' . $user['full_name'] . '.');
                redirect(APP_URL . '/dashboard.php');
            }

            $challenge = t8_otp_get_challenge($pdo, $pendingUserId, $challengeId) ?? $challenge;
            $expired = $result === 'expired' || t8_otp_is_expired($challenge);
            $exhausted = $result === 'exhausted' || (int) $challenge['attempts'] >= T8_OTP_MAX_ATTEMPTS;
            $isUsed = (bool) $challenge['used'];
            if ($result === 'wrong') {
                $errors[] = $exhausted
                    ? 'Too many incorrect codes. Request a new code to continue.'
                    : 'That code is incorrect. Please try again.';
            } elseif ($result === 'expired') {
                $errors[] = 'Code expired. Request a new code to continue.';
            } elseif ($result === 'exhausted') {
                $errors[] = 'Too many incorrect codes. Request a new code to continue.';
            } else {
                $errors[] = 'This code is no longer valid. Request a new code to continue.';
                $isUsed = true;
            }
        } else {
            $errors[] = 'Invalid request. Please try again.';
        }
    }
}

$resendWait = max(t8_otp_resend_wait($challenge), $resendWaitOverride);
$canVerify = !$expired && !$exhausted && !$isUsed;
if ($expired && !array_filter($errors, static fn(string $error): bool => str_contains(strtolower($error), 'expired'))) {
    $errors[] = 'Code expired. Request a new code to continue.';
} elseif ($exhausted && !array_filter($errors, static fn(string $error): bool => str_contains(strtolower($error), 'too many'))) {
    $errors[] = 'Too many incorrect codes. Request a new code to continue.';
} elseif ($isUsed && $errors === []) {
    $errors[] = 'This code is no longer valid. Request a new code to continue.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/components.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/login-ambience.css')) ?>?v=11">
    <style>
        .t8-otp-input { text-align: center; font-family: var(--t8-font-data); font-size: 1.6rem; letter-spacing: .45em; }
        .t8-otp-actions { display: grid; gap: var(--t8-space-3); margin-top: var(--t8-space-3); }
        .t8-otp-link { background: none; border: 0; color: var(--t8-primary); cursor: pointer; font: inherit; padding: var(--t8-space-2); }
        .t8-otp-link:disabled { color: var(--t8-text-muted); cursor: not-allowed; }
    </style>
</head>
<body>
<div class="t8-auth-wrapper">
    <div class="t8-auth-ambience" aria-hidden="true">
        <div class="t8-auth-cloud t8-auth-cloud-one"></div>
        <div class="t8-auth-cloud t8-auth-cloud-two"></div>
        <div class="t8-auth-cloud t8-auth-cloud-three"></div>
        <div class="t8-auth-branch t8-auth-branch-left"><i></i><i></i><i></i><i></i><i></i><i></i></div>
        <div class="t8-auth-branch t8-auth-branch-right"><i></i><i></i><i></i><i></i><i></i><i></i></div>
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
                    <div class="t8-auth-steam"><i></i><i></i><i></i><i></i><i></i></div>
                </div>
            </div>
        <?php endif; ?>
        <div class="t8-auth-petals"></div>
    </div>

    <main class="t8-card t8-auth-card">
        <img class="t8-auth-logo" src="<?= e(asset('img/ramyumlogo.jpg')) ?>" alt="RAM-YUM Korean and Japanese Store">
        <h1 class="t8-auth-title"><?= e(APP_NAME) ?></h1>
        <p>Enter the 6-digit code sent to your email</p>
        <p class="t8-help-text">Sent to <?= e(t8_otp_mask_email((string) $challenge['email'])) ?></p>

        <?php foreach ($errors as $error): ?>
            <div class="t8-alert t8-alert-danger" role="alert"><?= e($error) ?></div>
        <?php endforeach; ?>
        <?php if ($successMessage !== ''): ?>
            <div class="t8-alert t8-alert-success" role="status"><?= e($successMessage) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(base_url('verify_otp.php')) ?>" novalidate>
            <?= t8_csrf_field() ?>
            <div class="t8-field">
                <label class="t8-label" for="code">6-digit verification code</label>
                <input class="t8-input t8-otp-input" type="text" id="code" name="code"
                       inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}"
                       maxlength="6" required autofocus <?= $canVerify ? '' : 'disabled' ?>>
            </div>
            <button class="t8-btn t8-btn-accent t8-auth-submit" type="submit" name="action" value="verify"
                    <?= $canVerify ? '' : 'disabled' ?>>Verify</button>
        </form>

        <div class="t8-otp-actions">
            <form method="post" action="<?= e(base_url('verify_otp.php')) ?>">
                <?= t8_csrf_field() ?>
                <button class="t8-otp-link" type="submit" name="action" value="resend"
                        data-resend-button data-cooldown="<?= $resendWait ?>" <?= $resendWait > 0 ? 'disabled' : '' ?>>
                    <span data-resend-label><?= $resendWait > 0 ? 'Resend Code (' . $resendWait . 's)' : 'Resend Code' ?></span>
                </button>
            </form>
            <form method="post" action="<?= e(base_url('verify_otp.php')) ?>">
                <?= t8_csrf_field() ?>
                <button class="t8-otp-link" type="submit" name="action" value="back">Back to Login</button>
            </form>
        </div>
    </main>
</div>
<script src="<?= e(asset('js/login-ambience.js')) ?>?v=6" defer></script>
<script>
    const resendButton = document.querySelector('[data-resend-button]');
    const resendLabel = document.querySelector('[data-resend-label]');
    if (resendButton && resendLabel && Number(resendButton.dataset.cooldown) > 0) {
        let remaining = Number(resendButton.dataset.cooldown);
        const timer = window.setInterval(() => {
            remaining -= 1;
            if (remaining <= 0) {
                window.clearInterval(timer);
                resendButton.disabled = false;
                resendLabel.textContent = 'Resend Code';
            } else {
                resendLabel.textContent = `Resend Code (${remaining}s)`;
            }
        }, 1000);
    }
</script>
</body>
</html>
