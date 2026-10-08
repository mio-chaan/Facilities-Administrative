<?php
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/includes/db_connect.php';
require_once __DIR__ . '/app/includes/contract_portal.php';

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
$contract = null;
$pageError = 'This invitation link is invalid, expired, or no longer active.';
$decisionMessage = '';
$responseStatus = 404;

if (preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
    $inviteStmt = $pdo->prepare(
        "SELECT i.id, i.contract_id, i.party_name, i.status, i.expires_at,
                c.contract_number, c.title, c.contract_type, c.description, c.start_date,
                c.end_date, c.amount, c.currency, c.payment_terms, c.payment_frequency, c.financial_notes
         FROM team8_contract_portal_invites i
         JOIN team8_contracts c ON c.id = i.contract_id
         WHERE i.token_hash = :token_hash AND c.deleted_at IS NULL
         LIMIT 1"
    );
    $inviteStmt->execute(['token_hash' => hash('sha256', $token)]);
    $invite = $inviteStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($invite && $invite['status'] === 'pending' && strtotime((string) $invite['expires_at']) > time()) {
        $responseStatus = 200;
        $pageError = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $csrf = (string) ($_POST['csrf_token'] ?? '');
            $decision = (string) ($_POST['decision'] ?? '');
            $feedback = trim((string) ($_POST['feedback'] ?? ''));
            $meetingDetails = trim((string) ($_POST['meeting_details'] ?? ''));
            if (!isset($_SESSION['contract_review_csrf'])
                || !hash_equals((string) $_SESSION['contract_review_csrf'], $csrf)) {
                $responseStatus = 400;
                $pageError = 'The review form expired. Reload this invitation and try again.';
            } elseif (!in_array($decision, ['accepted', 'rejected', 'negotiation_requested'], true)) {
                $responseStatus = 400;
                $pageError = 'Choose whether to accept, reject, or request negotiation.';
            } elseif (!t8_contract_review_consent_is_valid($decision, $_POST['consent'] ?? null)) {
                $responseStatus = 400;
                $pageError = 'Confirm that you have read and agree to the terms of this contract before accepting.';
            } elseif ($decision !== 'accepted' && $feedback === '') {
                $responseStatus = 400;
                $pageError = $decision === 'negotiation_requested'
                    ? 'Explain the changes you are requesting before sending a negotiation proposal.'
                    : 'Explain why you cannot accept this contract.';
            } elseif (strlen($feedback) > 1000 || strlen($meetingDetails) > 2000) {
                $responseStatus = 400;
                $pageError = 'Your explanation or meeting details exceed the allowed length.';
            } else {
                if ($decision === 'negotiation_requested') {
                    try {
                        t8_contract_record_external_negotiation_request(
                            $pdo,
                            (int) $invite['contract_id'],
                            (int) $invite['id'],
                            $feedback,
                            $meetingDetails
                        );
                        $invite['status'] = $decision;
                        $invite['response_feedback'] = $feedback;
                        $invite['meeting_details'] = $meetingDetails;
                        $decisionMessage = 'Your negotiation request was sent to the contract team.';
                    } catch (DomainException $e) {
                        $responseStatus = 409;
                        $pageError = 'This invitation is no longer active. Contact the contract team if you still need to discuss the terms.';
                    } catch (Throwable $e) {
                        error_log('Contract portal negotiation request failed for invitation #' . (int) $invite['id'] . ': ' . $e->getMessage());
                        $responseStatus = 500;
                        $pageError = 'Your negotiation request could not be recorded. Please try again later.';
                    }
                } else {
                    $update = $pdo->prepare(
                        "UPDATE team8_contract_portal_invites
                         SET status = :status, response_feedback = :feedback,
                             meeting_details = :meeting_details, used_at = CURRENT_TIMESTAMP
                         WHERE id = :id AND status = 'pending' AND expires_at > CURRENT_TIMESTAMP"
                    );
                    $update->execute([
                        'status' => $decision,
                        'feedback' => $decision === 'rejected' ? $feedback : null,
                        'meeting_details' => $decision === 'rejected' && $meetingDetails !== '' ? $meetingDetails : null,
                        'id' => (int) $invite['id'],
                    ]);
                    if ($update->rowCount() !== 1) {
                        $responseStatus = 409;
                        $pageError = 'This invitation is no longer active.';
                    } else {
                        $invite['status'] = $decision;
                        $invite['response_feedback'] = $decision === 'rejected' ? $feedback : null;
                        $invite['meeting_details'] = $decision === 'rejected' ? $meetingDetails : null;
                        $decisionMessage = $decision === 'accepted'
                            ? 'Your acceptance has been recorded.'
                            : 'Your rejection and explanation have been recorded.';
                    }
                }
                if ($pageError === '') {
                    unset($_SESSION['contract_review_csrf']);
                }
            }
        } else {
            $_SESSION['contract_review_csrf'] = bin2hex(random_bytes(32));
        }
        $contract = $invite;
    } elseif ($invite && in_array($invite['status'], ['accepted', 'rejected', 'negotiation_requested'], true)) {
        $responseStatus = 200;
        $decisionMessage = match ($invite['status']) {
            'accepted' => 'This contract review was already accepted.',
            'negotiation_requested' => 'A negotiation request has already been sent for this contract review.',
            default => 'This contract review was already rejected.',
        };
        $pageError = '';
    }
}

http_response_code($responseStatus);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contract review</title>
    <style>
        :root {
            color-scheme: light;
            font: 15px/1.6 'Poppins', 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: #2c2020;
            background: #f5f5f5;
            --review-primary: #b22222;
            --review-success: #287a50;
            --review-danger: #a83232;
            --review-muted: #796d6d;
            --review-border: #ece4e2;
        }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; padding: clamp(1rem, 4vw, 2.5rem) 1rem; }
        main {
            width: 100%;
            max-width: 820px;
            margin: 0 auto;
            padding: 0;
            background: #fff;
            border: 1px solid var(--review-border);
            border-radius: 14px;
            box-shadow: 0 8px 24px rgba(36, 17, 17, .08);
            overflow: hidden;
        }
        .review-header { padding: 1.75rem 2.25rem 1.25rem; }
        h1 { margin: 0 0 .4rem; color: #241111; font-size: clamp(1.4rem, 4vw, 1.7rem); line-height: 1.25; letter-spacing: -.025em; }
        h2 { margin: 0 0 1rem; color: #241111; font-size: 1.05rem; line-height: 1.35; }
        p { margin: 0 0 1rem; }
        .intro { margin: 0; color: var(--review-muted); font-size: .9rem; }
        .contract-id { display: inline-flex; align-items: center; gap: .5rem; margin-top: .8rem; color: var(--review-muted); font-size: .82rem; }
        .contract-id strong { padding: .2rem .65rem; border-radius: 999px; background: #f8e9e8; color: var(--review-primary); font-size: .7rem; letter-spacing: .04em; text-transform: uppercase; }
        .contract-details {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem 2rem;
            margin: 0;
            padding: 1.5rem 2.25rem;
            border-top: 1px solid var(--review-border);
            border-bottom: 1px solid var(--review-border);
            background: #faf9f8;
        }
        .detail-item { min-width: 0; }
        dt { margin: 0 0 .15rem; color: var(--review-muted); font-size: .7rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; }
        dd { margin: 0; color: #241111; font-weight: 500; white-space: pre-wrap; overflow-wrap: anywhere; }
        .detail-item:first-child, .detail-item:nth-child(3) { grid-column: 1 / -1; }
        .detail-item:nth-child(5) dd { color: var(--review-success); font-size: 1.08rem; font-weight: 700; }
        .decision-section {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            min-width: 0;
            margin: 0;
            padding: 1.5rem 2.25rem 2rem;
            border: 0;
        }
        .decision-section legend { grid-column: 1 / -1; margin-bottom: 1rem; padding: 0; color: #241111; font-size: 1.05rem; font-weight: 700; }
        .decision-switch {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            clip-path: inset(50%);
        }
        .decision-tab {
            display: flex;
            min-height: 46px;
            grid-row: 2;
            align-items: center;
            justify-content: center;
            padding: .55rem .35rem;
            border-bottom: 2px solid transparent;
            border-bottom-color: var(--review-border);
            color: var(--review-muted);
            cursor: pointer;
            font-size: .86rem;
            font-weight: 600;
            text-align: center;
        }
        .decision-switch:checked + .decision-tab { border-bottom-color: var(--review-success); color: #241111; }
        .decision-switch:focus-visible + .decision-tab { outline: 3px solid rgba(178, 34, 34, .3); outline-offset: -3px; }
        .decision-panels { grid-column: 1 / -1; grid-row: 3; padding-top: 1.25rem; }
        .decision-panels > section[role="region"] { display: none; }
        #decision-accept:checked ~ .decision-panels .panel-accept,
        #decision-negotiate:checked ~ .decision-panels .panel-negotiate,
        #decision-reject:checked ~ .decision-panels .panel-reject { display: block; }
        .consent-confirmation {
            display: flex;
            align-items: flex-start;
            gap: .7rem;
            margin: 0 0 1.25rem;
            padding: .8rem 1rem;
            border: 1px solid #c5e5d1;
            border-radius: 8px;
            background: #eff9f2;
            color: #205f3b;
            cursor: pointer;
            font-size: .88rem;
        }
        .consent-confirmation input { width: 1.1rem; height: 1.1rem; flex: 0 0 auto; margin: .15rem 0 0; accent-color: var(--review-success); }
        form { margin: 0; }
        .panel-copy { margin: 0 0 1rem; color: var(--review-muted); font-size: .9rem; }
        label.field-label { display: block; margin: .75rem 0 .4rem; font-weight: 500; }
        textarea {
            display: block;
            width: 100%;
            min-height: 3.75rem;
            padding: .65rem .8rem;
            border: 1px solid #d8cecb;
            border-radius: 7px;
            background: #fff;
            color: #2c2020;
            font: inherit;
            resize: vertical;
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        textarea:focus { border-color: var(--review-primary); outline: 0; box-shadow: 0 0 0 3px rgba(178, 34, 34, .14); }
        textarea + button { margin-top: .5rem; }
        button {
            min-height: 44px;
            padding: .65rem 1rem;
            border: 0;
            border-radius: 7px;
            cursor: pointer;
            font: inherit;
            font-weight: 600;
            transition: background-color .15s ease, transform .15s ease;
        }
        button:hover { transform: translateY(-1px); }
        button:focus-visible { outline: 3px solid rgba(178, 34, 34, .3); outline-offset: 2px; }
        button[name="decision"][value="accepted"] { background: var(--review-success); color: #fff; }
        button[name="decision"][value="accepted"]:hover { background: #206441; }
        button[name="decision"][value="negotiation_requested"] { background: var(--review-primary); color: #fff; }
        button[name="decision"][value="negotiation_requested"]:hover { background: #7a1717; }
        button[name="decision"][value="rejected"] { background: var(--review-danger); color: #fff; }
        button[name="decision"][value="rejected"]:hover { background: #8d2929; }
        .notice { margin: 1.25rem 2.25rem 0; padding: .9rem 1rem; border: 1px solid #e5deda; border-radius: 8px; background: #f8f6f3; }
        .notice.error { border-color: #f0c9c9; background: #fff3f3; color: #852626; }
        .notice.success { border-color: #c5e5d1; background: #eff9f2; color: #205f3b; }
        .review-header .notice { margin: 1rem 0 0; }
        @media (max-width: 560px) {
            body { padding: .75rem; }
            .review-header { padding: 1.35rem 1.25rem 1rem; }
            .contract-details { gap: .9rem 1rem; padding: 1.25rem; }
            .decision-section { padding: 1.25rem; }
            .decision-tab { font-size: .76rem; }
            .notice { margin-right: 1.25rem; margin-left: 1.25rem; }
        }
    </style>
</head>
<body>
<main>
    <header class="review-header">
        <h1>Secure contract review</h1>
        <?php if ($contract !== null): ?>
            <p class="intro">Hello <strong><?= $escape((string) ($contract['party_name'] ?? '')) ?></strong>, please review the terms carefully before making a decision.</p>
            <div class="contract-id"><strong><?= $invite['status'] === 'pending' ? 'Action required' : 'Review complete' ?></strong> Contract ID: <?= $escape((string) $contract['contract_number']) ?></div>
        <?php endif; ?>
        <?php if ($pageError !== ''): ?>
            <p class="notice error" role="alert"><?= $escape($pageError) ?></p>
        <?php endif; ?>
        <?php if ($decisionMessage !== '' && $contract === null): ?>
            <p class="notice success" role="status"><?= $escape($decisionMessage) ?></p>
        <?php elseif ($decisionMessage !== ''): ?>
            <p class="notice success" role="status"><?= $escape($decisionMessage) ?></p>
        <?php endif; ?>
    </header>
    <?php if ($contract !== null): ?>
        <dl class="contract-details">
            <div class="detail-item"><dt>Contract title</dt><dd><?= $escape((string) $contract['title']) ?></dd></div>
            <div class="detail-item"><dt>Type</dt><dd><?= $escape((string) ($contract['contract_type'] ?? 'Not specified')) ?></dd></div>
            <div class="detail-item"><dt>Period</dt><dd><?= $escape((string) $contract['start_date']) ?><?= !empty($contract['end_date']) ? ' to ' . $escape((string) $contract['end_date']) : '' ?></dd></div>
            <div class="detail-item"><dt>Description</dt><dd><?= $escape((string) ($contract['description'] ?? 'Not provided')) ?></dd></div>
            <div class="detail-item"><dt>Value</dt><dd><?= $contract['amount'] !== null ? $escape((string) $contract['currency'] . ' ' . (string) $contract['amount']) : 'Not specified' ?></dd></div>
            <div class="detail-item"><dt>Payment terms</dt><dd><?= $escape((string) ($contract['payment_terms'] ?? 'Not specified')) ?></dd></div>
            <?php if (!empty($contract['payment_frequency'])): ?><div class="detail-item"><dt>Payment frequency</dt><dd><?= $escape((string) $contract['payment_frequency']) ?></dd></div><?php endif; ?>
            <?php if (!empty($contract['financial_notes'])): ?><div class="detail-item"><dt>Additional terms</dt><dd><?= $escape((string) $contract['financial_notes']) ?></dd></div><?php endif; ?>
        </dl>
        <?php if ($decisionMessage === '' && $invite['status'] === 'pending'): ?>
            <fieldset class="decision-section">
                <legend>Your decision</legend>
                <input class="decision-switch" type="radio" name="decision-tab" id="decision-accept" checked>
                <label class="decision-tab" for="decision-accept">Accept contract</label>
                <input class="decision-switch" type="radio" name="decision-tab" id="decision-negotiate">
                <label class="decision-tab" for="decision-negotiate">Request negotiation</label>
                <input class="decision-switch" type="radio" name="decision-tab" id="decision-reject">
                <label class="decision-tab" for="decision-reject">Reject contract</label>
                <div class="decision-panels">
                    <section class="panel-accept" role="region" aria-labelledby="decision-accept">
                        <p class="panel-copy">Confirm your acceptance of the contract terms.</p>
                        <form method="post">
                            <input type="hidden" name="token" value="<?= $escape($token) ?>">
                            <input type="hidden" name="csrf_token" value="<?= $escape((string) ($_SESSION['contract_review_csrf'] ?? '')) ?>">
                            <label class="consent-confirmation" for="acceptance-consent">
                                <input type="checkbox" id="acceptance-consent" name="consent" value="1" required>
                                <span>I confirm that I have read and agree to be bound by the terms of this contract.</span>
                            </label>
                            <button type="submit" name="decision" value="accepted">Accept contract</button>
                        </form>
                    </section>
                    <section class="panel-negotiate" role="region" aria-labelledby="decision-negotiate">
                        <p class="panel-copy">Tell the contract team what you’d like to discuss or change.</p>
                        <form method="post">
                            <input type="hidden" name="token" value="<?= $escape($token) ?>">
                            <input type="hidden" name="csrf_token" value="<?= $escape((string) ($_SESSION['contract_review_csrf'] ?? '')) ?>">
                            <label class="field-label" for="negotiation-feedback">What would you like to discuss or change? (required)</label>
                            <textarea id="negotiation-feedback" name="feedback" maxlength="1000" required rows="3"></textarea>
                            <label class="field-label" for="meeting-details">Meeting request or availability (optional)</label>
                            <textarea id="meeting-details" name="meeting_details" maxlength="2000" rows="2"></textarea>
                            <button type="submit" name="decision" value="negotiation_requested">Send negotiation request</button>
                        </form>
                    </section>
                    <section class="panel-reject" role="region" aria-labelledby="decision-reject">
                        <p class="panel-copy">Let the contract team know why you can’t accept these terms.</p>
                        <form method="post">
                            <input type="hidden" name="token" value="<?= $escape($token) ?>">
                            <input type="hidden" name="csrf_token" value="<?= $escape((string) ($_SESSION['contract_review_csrf'] ?? '')) ?>">
                            <label class="field-label" for="rejection-feedback">Why can’t you accept these terms? (required)</label>
                            <textarea id="rejection-feedback" name="feedback" maxlength="1000" required rows="3"></textarea>
                            <button type="submit" name="decision" value="rejected">Reject contract</button>
                        </form>
                    </section>
                </div>
            </fieldset>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
