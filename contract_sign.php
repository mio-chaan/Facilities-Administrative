<?php
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; script-src \'unsafe-inline\'; form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/includes/db_connect.php';
require_once __DIR__ . '/app/includes/gmail_dispatch.php';
require_once __DIR__ . '/app/includes/contract_signing_portal.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => str_starts_with(strtolower(APP_URL), 'https://'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$invite = null;
$snapshot = null;
$pageError = 'This signing link is invalid, expired, or no longer active.';
$successMessage = '';
$signed = false;
$responseStatus = 404;

if (preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
    try {
        $invite = t8_contract_signing_invite($pdo, $token);
        if ($invite && ($invite['invite_status'] ?? '') === 'signed') {
            $signed = true;
            $responseStatus = 200;
            $pageError = '';
            $successMessage = 'A signature has already been recorded for this invitation.';
        } elseif ($invite && t8_contract_signing_invite_is_live($invite)) {
            $snapshot = t8_contract_signing_load_snapshot($pdo, $invite);
            $responseStatus = 200;
            $pageError = '';
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $csrf = (string) ($_POST['csrf_token'] ?? '');
                if (!isset($_SESSION['contract_signing_csrf'])
                    || !hash_equals((string) $_SESSION['contract_signing_csrf'], $csrf)) {
                    $responseStatus = 400;
                    $pageError = 'The signing form expired. Reload this page and try again.';
                } elseif (($_POST['action'] ?? '') === 'request_otp') {
                    try {
                        t8_contract_send_signing_otp($pdo, $token);
                        $successMessage = 'A verification code was sent to ' . (string) $invite['email'] . '. It expires in 10 minutes.';
                    } catch (DomainException | InvalidArgumentException $e) {
                        $responseStatus = 400;
                        $pageError = $e->getMessage();
                    } catch (Throwable $e) {
                        error_log('Contract signing OTP request failed for invitation #' . (int) $invite['invite_id'] . ': ' . $e->getMessage());
                        $responseStatus = 500;
                        $pageError = 'The verification code could not be sent. Please try again later.';
                    }
                } elseif (($_POST['action'] ?? '') === 'sign') {
                    if (($_POST['consent'] ?? '') !== '1') {
                        $responseStatus = 400;
                        $pageError = 'Confirm that you have read and agree to be bound by this contract.';
                    } else {
                        try {
                            t8_contract_submit_signature(
                                $pdo,
                                $token,
                                trim((string) ($_POST['otp'] ?? '')),
                                trim((string) ($_POST['signer_name'] ?? '')),
                                trim((string) ($_POST['signature_method'] ?? '')),
                                (string) ($_POST['drawing_json'] ?? ''),
                                true
                            );
                            $signed = true;
                            $successMessage = 'Your signature has been recorded for the frozen contract version.';
                            unset($_SESSION['contract_signing_csrf']);
                        } catch (DomainException | InvalidArgumentException $e) {
                            $responseStatus = 400;
                            $pageError = $e->getMessage();
                        } catch (Throwable $e) {
                            error_log('Contract signature submission failed for invitation #' . (int) $invite['invite_id'] . ': ' . $e->getMessage());
                            $responseStatus = 500;
                            $pageError = 'Your signature could not be recorded. Please retry or contact the contract administrator.';
                        }
                    }
                } else {
                    $responseStatus = 400;
                    $pageError = 'Choose a valid signing action.';
                }
            }
            if (!isset($_SESSION['contract_signing_csrf'])) {
                $_SESSION['contract_signing_csrf'] = bin2hex(random_bytes(32));
            }
        }
    } catch (Throwable $e) {
        error_log('Contract signing page could not verify invitation: ' . $e->getMessage());
        $responseStatus = 500;
        $pageError = 'The frozen contract version could not be verified. Contact the contract administrator.';
    }
}

http_response_code($responseStatus);
$terms = is_array($snapshot['terms'] ?? null) ? $snapshot['terms'] : [];
$termRows = [
    'Contract number' => $terms['contract_number'] ?? $invite['contract_number'] ?? null,
    'Title' => $terms['title'] ?? $invite['title'] ?? null,
    'Type' => $terms['contract_type'] ?? null,
    'Description' => $terms['description'] ?? null,
    'Start date' => $terms['start_date'] ?? null,
    'End date' => $terms['end_date'] ?? null,
    'Value' => isset($terms['amount']) && $terms['amount'] !== ''
        ? trim((string) ($terms['currency'] ?? 'PHP') . ' ' . (string) $terms['amount'])
        : null,
    'Payment terms' => $terms['payment_terms'] ?? null,
    'Payment frequency' => $terms['payment_frequency'] ?? null,
    'Deposit amount' => isset($terms['deposit_amount']) && $terms['deposit_amount'] !== ''
        ? trim((string) ($terms['currency'] ?? 'PHP') . ' ' . (string) $terms['deposit_amount'])
        : null,
    'Additional financial notes' => $terms['financial_notes'] ?? null,
    'Notice period (days)' => $terms['notice_period_days'] ?? null,
];
$maskedEmail = '';
if ($invite) {
    [$emailName, $emailDomain] = array_pad(explode('@', (string) $invite['email'], 2), 2, '');
    $maskedEmail = ($emailName !== '' ? substr($emailName, 0, 1) . str_repeat('*', max(1, strlen($emailName) - 1)) : '')
        . ($emailDomain !== '' ? '@' . $emailDomain : '');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Secure contract signing</title>
    <style>
        :root { color-scheme: light; font: 16px/1.55 system-ui, sans-serif; color: #17212b; background: #f4f6f8; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 1rem; }
        main { max-width: 820px; margin: 1rem auto; padding: clamp(1rem, 4vw, 2rem); background: #fff; border-radius: 12px; box-shadow: 0 2px 16px #17212b1a; }
        h1 { margin-top: 0; } h2 { margin-bottom: .5rem; }
        dt { margin-top: 1rem; font-weight: 700; } dd { margin: .2rem 0 0; white-space: pre-wrap; overflow-wrap: anywhere; }
        .notice { padding: .9rem 1rem; border-radius: 6px; background: #edf4fa; }
        .error { background: #fff0f0; color: #7f1d1d; } .success { background: #edf9f1; color: #14532d; }
        .hash { font-family: ui-monospace, monospace; word-break: break-all; padding: .65rem; border: 1px solid #cbd5e1; border-radius: 6px; background: #f8fafc; }
        form { margin-top: 1.25rem; } label { display: block; margin: .75rem 0 .35rem; font-weight: 600; }
        input[type="text"], input[type="email"], input[type="number"] { width: 100%; padding: .7rem; border: 1px solid #9aa9b8; border-radius: 5px; font: inherit; }
        button { padding: .7rem 1rem; border: 0; border-radius: 5px; cursor: pointer; font: inherit; background: #176b43; color: #fff; }
        button.secondary { color: #17212b; background: #e5edf4; } button:focus-visible, input:focus-visible { outline: 3px solid #2563eb; outline-offset: 2px; }
        .choices { display: flex; flex-wrap: wrap; gap: 1rem; } .choices label { display: inline-flex; align-items: center; gap: .45rem; }
        #signature-canvas { display: block; width: 100%; height: 180px; border: 1px solid #8796a5; border-radius: 6px; background: #fff; touch-action: none; }
        .help { color: #475569; font-size: .92rem; }
        @media (max-width: 520px) { body { padding: .4rem; } main { margin: .4rem auto; } }
    </style>
</head>
<body>
<main>
    <h1>Secure contract signing</h1>
    <?php if ($pageError !== ''): ?><p class="notice error"><?= $escape($pageError) ?></p><?php endif; ?>
    <?php if ($successMessage !== ''): ?><p class="notice success"><?= $escape($successMessage) ?></p><?php endif; ?>

    <?php if ($invite && $snapshot && $pageError === '' && !$signed): ?>
        <p>Hello <?= $escape((string) $invite['party_name']) ?>. Review the frozen contract terms below before signing.</p>
        <?php if (!empty($invite['invited_signer_name'])): ?>
            <p><strong>Expected authorized signatory:</strong> <?= $escape((string) $invite['invited_signer_name']) ?></p>
        <?php endif; ?>
        <dl>
            <?php foreach ($termRows as $label => $value): ?>
                <?php if ($value !== null && $value !== ''): ?>
                    <dt><?= $escape($label) ?></dt><dd><?= $escape((string) $value) ?></dd>
                <?php endif; ?>
            <?php endforeach; ?>
        </dl>
        <h2>Frozen version integrity hash</h2>
        <p class="help">This SHA-256 hash identifies the frozen contract snapshot. Terms above are loaded from that saved version.</p>
        <p class="hash" aria-label="Frozen contract document hash"><?= $escape((string) $snapshot['document_hash']) ?></p>

        <form method="post">
            <input type="hidden" name="token" value="<?= $escape($token) ?>">
            <input type="hidden" name="csrf_token" value="<?= $escape((string) ($_SESSION['contract_signing_csrf'] ?? '')) ?>">
            <input type="hidden" name="action" value="request_otp">
            <p class="help">A six-digit code will be sent to <?= $escape($maskedEmail) ?>. Codes expire after 10 minutes.</p>
            <button class="secondary" type="submit">Email me a verification code</button>
        </form>

        <form method="post" id="signing-form">
            <input type="hidden" name="token" value="<?= $escape($token) ?>">
            <input type="hidden" name="csrf_token" value="<?= $escape((string) ($_SESSION['contract_signing_csrf'] ?? '')) ?>">
            <input type="hidden" name="action" value="sign">
            <input type="hidden" name="signature_method" id="signature-method" value="typed">
            <input type="hidden" name="drawing_json" id="drawing-json" value="">
            <h2>Choose how to sign</h2>
            <div class="choices">
                <label><input type="radio" name="signature_choice" value="typed" checked> Type full legal name</label>
                <label><input type="radio" name="signature_choice" value="drawn"> Draw signature</label>
            </div>
            <label for="signer-name">Full legal name</label>
            <input id="signer-name" name="signer_name" type="text" maxlength="200" autocomplete="name" value="<?= $escape((string) ($invite['invited_signer_name'] ?? '')) ?>" required>
            <div id="drawing-panel" hidden>
                <label for="signature-canvas">Draw using your finger, stylus or mouse</label>
                <canvas id="signature-canvas" aria-label="Draw your signature here"></canvas>
                <button class="secondary" type="button" id="clear-signature">Clear drawing</button>
                <p class="help">Keyboard users can use the typed-name option instead.</p>
            </div>
            <label for="otp">Six-digit email verification code</label>
            <input id="otp" name="otp" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
            <label style="display:flex;align-items:flex-start;gap:.55rem;font-weight:400;">
                <input type="checkbox" name="consent" value="1" required style="margin-top:.35rem;">
                <span>I have read and agree to be bound by this contract.</span>
            </label>
            <button type="submit">Sign contract</button>
        </form>
    <?php endif; ?>
</main>
<script>
(() => {
    const form = document.getElementById('signing-form');
    if (!form) return;
    const choices = [...document.querySelectorAll('input[name="signature_choice"]')];
    const drawingPanel = document.getElementById('drawing-panel');
    const canvas = document.getElementById('signature-canvas');
    const context = canvas.getContext('2d');
    const strokes = [];
    let activeStroke = null;
    let pointerMethod = 'mouse';
    const resizeCanvas = () => {
        const rect = canvas.getBoundingClientRect();
        const ratio = window.devicePixelRatio || 1;
        canvas.width = Math.max(1, Math.round(rect.width * ratio));
        canvas.height = Math.max(1, Math.round(rect.height * ratio));
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        context.lineWidth = 2.5;
        context.lineCap = 'round';
        context.lineJoin = 'round';
        context.strokeStyle = '#111';
        redraw();
    };
    const redraw = () => {
        const rect = canvas.getBoundingClientRect();
        context.clearRect(0, 0, rect.width, rect.height);
        for (const stroke of strokes) {
            if (stroke.length < 2) continue;
            context.beginPath();
            context.moveTo(stroke[0].x * rect.width, stroke[0].y * rect.height);
            for (let i = 1; i < stroke.length - 1; i++) {
                const midX = (stroke[i].x + stroke[i + 1].x) / 2;
                const midY = (stroke[i].y + stroke[i + 1].y) / 2;
                context.quadraticCurveTo(stroke[i].x * rect.width, stroke[i].y * rect.height, midX * rect.width, midY * rect.height);
            }
            const last = stroke[stroke.length - 1];
            context.lineTo(last.x * rect.width, last.y * rect.height);
            context.stroke();
        }
    };
    const activeChoice = () => choices.find(choice => choice.checked)?.value || 'typed';
    choices.forEach(choice => choice.addEventListener('change', () => {
        drawingPanel.hidden = activeChoice() !== 'drawn';
        if (!drawingPanel.hidden) resizeCanvas();
    }));
    canvas.addEventListener('pointerdown', event => {
        event.preventDefault();
        canvas.setPointerCapture(event.pointerId);
        if (strokes.length === 0) {
            pointerMethod = event.pointerType === 'touch' ? 'touch' : event.pointerType === 'pen' ? 'pen' : 'mouse';
        }
        activeStroke = [];
        strokes.push(activeStroke);
        const rect = canvas.getBoundingClientRect();
        activeStroke.push({x: (event.clientX - rect.left) / rect.width, y: (event.clientY - rect.top) / rect.height});
        redraw();
    });
    canvas.addEventListener('pointermove', event => {
        if (!activeStroke || !canvas.hasPointerCapture(event.pointerId)) return;
        event.preventDefault();
        const rect = canvas.getBoundingClientRect();
        activeStroke.push({x: (event.clientX - rect.left) / rect.width, y: (event.clientY - rect.top) / rect.height});
        if (activeStroke.length > 500) activeStroke.shift();
        redraw();
    });
    const endStroke = () => { activeStroke = null; };
    canvas.addEventListener('pointerup', endStroke);
    canvas.addEventListener('pointercancel', endStroke);
    document.getElementById('clear-signature').addEventListener('click', () => {
        strokes.length = 0;
        pointerMethod = 'mouse';
        redraw();
    });
    form.addEventListener('submit', event => {
        if (activeChoice() !== 'drawn') {
            document.getElementById('signature-method').value = 'typed';
            document.getElementById('drawing-json').value = '';
            return;
        }
        const completedStrokes = strokes.filter(stroke => stroke.length > 1);
        if (completedStrokes.length === 0) {
            event.preventDefault();
            alert('Draw your signature in the box before submitting.');
            return;
        }
        document.getElementById('signature-method').value = 'drawn_' + pointerMethod;
        document.getElementById('drawing-json').value = JSON.stringify(completedStrokes.map(stroke => stroke.map(point => ({
            x: Math.round(point.x * 100000) / 100,
            y: Math.round(point.y * 30000) / 100
        }))));
    });
    window.addEventListener('resize', () => { if (!drawingPanel.hidden) resizeCanvas(); });
})();
</script>
</body>
</html>
