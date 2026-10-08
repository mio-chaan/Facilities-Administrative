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
        body { font: 16px/1.55 system-ui, sans-serif; margin: 0; padding: 2rem 1rem; color: #17212b; background: #f4f6f8; }
        main { max-width: 760px; margin: auto; padding: 2rem; background: #fff; border-radius: 10px; box-shadow: 0 2px 12px #17212b1a; }
        h1 { margin-top: 0; } dt { font-weight: 700; margin-top: 1rem; } dd { margin: .25rem 0 0; white-space: pre-wrap; }
        .actions { display: flex; gap: .75rem; margin-top: 2rem; } button { padding: .7rem 1rem; border: 0; border-radius: 5px; cursor: pointer; font: inherit; }
        button[name="decision"][value="accepted"] { background: #176b43; color: white; } button[name="decision"][value="rejected"] { background: #9b3030; color: white; }
        .notice { padding: 1rem; border-radius: 5px; background: #edf4fa; }
    </style>
</head>
<body>
<main>
    <h1>Secure contract review</h1>
    <?php if ($pageError !== ''): ?>
        <p class="notice"><?= $escape($pageError) ?></p>
    <?php endif; ?>
    <?php if ($decisionMessage !== '' && $contract === null): ?>
        <p class="notice"><?= $escape($decisionMessage) ?></p>
    <?php elseif ($contract !== null): ?>
        <?php if ($decisionMessage !== ''): ?><p class="notice"><?= $escape($decisionMessage) ?></p><?php endif; ?>
        <p>Hello <?= $escape((string) ($contract['party_name'] ?? '')) ?>, review the contract details below.</p>
        <dl>
            <dt>Contract</dt><dd><?= $escape((string) $contract['contract_number']) ?> — <?= $escape((string) $contract['title']) ?></dd>
            <dt>Type</dt><dd><?= $escape((string) ($contract['contract_type'] ?? 'Not specified')) ?></dd>
            <dt>Description</dt><dd><?= $escape((string) ($contract['description'] ?? 'Not provided')) ?></dd>
            <dt>Period</dt><dd><?= $escape((string) $contract['start_date']) ?><?= !empty($contract['end_date']) ? ' to ' . $escape((string) $contract['end_date']) : '' ?></dd>
            <dt>Value</dt><dd><?= $contract['amount'] !== null ? $escape((string) $contract['currency'] . ' ' . (string) $contract['amount']) : 'Not specified' ?></dd>
            <dt>Payment terms</dt><dd><?= $escape((string) ($contract['payment_terms'] ?? 'Not specified')) ?></dd>
            <?php if (!empty($contract['payment_frequency'])): ?><dt>Payment frequency</dt><dd><?= $escape((string) $contract['payment_frequency']) ?></dd><?php endif; ?>
            <?php if (!empty($contract['financial_notes'])): ?><dt>Additional terms</dt><dd><?= $escape((string) $contract['financial_notes']) ?></dd><?php endif; ?>
        </dl>
        <?php if ($decisionMessage === '' && $invite['status'] === 'pending'): ?>
            <form method="post">
                <input type="hidden" name="token" value="<?= $escape($token) ?>">
                <input type="hidden" name="csrf_token" value="<?= $escape((string) ($_SESSION['contract_review_csrf'] ?? '')) ?>">
                <div class="actions">
                    <button type="submit" name="decision" value="accepted">Accept contract</button>
                </div>
            </form>
            <h2>Request a negotiation</h2>
            <p>If you have concerns, send an explanation to the contract team. They will review it and prepare any revised terms.</p>
            <form method="post">
                <input type="hidden" name="token" value="<?= $escape($token) ?>">
                <input type="hidden" name="csrf_token" value="<?= $escape((string) ($_SESSION['contract_review_csrf'] ?? '')) ?>">
                <label for="negotiation-feedback">What would you like to discuss or change? (required)</label>
                <textarea id="negotiation-feedback" name="feedback" maxlength="1000" required rows="4" style="display:block;width:100%;margin:.5rem 0 1rem;"></textarea>
                <label for="meeting-details">Meeting request or availability (optional)</label>
                <textarea id="meeting-details" name="meeting_details" maxlength="2000" rows="3" style="display:block;width:100%;margin:.5rem 0 1rem;"></textarea>
                <button type="submit" name="decision" value="negotiation_requested">Send negotiation request</button>
            </form>
            <h2>Reject this contract</h2>
            <form method="post">
                <input type="hidden" name="token" value="<?= $escape($token) ?>">
                <input type="hidden" name="csrf_token" value="<?= $escape((string) ($_SESSION['contract_review_csrf'] ?? '')) ?>">
                <label for="rejection-feedback">Why can’t you accept these terms? (required)</label>
                <textarea id="rejection-feedback" name="feedback" maxlength="1000" required rows="3" style="display:block;width:100%;margin:.5rem 0 1rem;"></textarea>
                <button type="submit" name="decision" value="rejected">Reject contract</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
