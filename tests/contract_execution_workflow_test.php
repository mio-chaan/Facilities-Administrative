<?php
declare(strict_types=1);

if (!defined('APP_URL')) {
    define('APP_URL', 'http://localhost:8000');
}
if (!defined('APP_ENV')) {
    define('APP_ENV', 'local');
}
if (!defined('T8_SIGNING_OTP_PEPPER')) {
    define('T8_SIGNING_OTP_PEPPER', 'test-only-contract-signing-pepper-32-bytes');
}
if (!defined('T8_SIGNATURE_STORAGE_DIR')) {
    define('T8_SIGNATURE_STORAGE_DIR', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 't8-signature-test-' . getmypid());
}
$GLOBALS['contractSigningEmails'] = [];
function t8_gmail_dispatch(array $recipients, string $subject, string $htmlBody, string $textBody, array $attachments = [], ?string $diagnosticContext = null): void
{
    if (!empty($GLOBALS['failContractSigningMail'])) {
        throw new RuntimeException('Simulated contract signing email failure.');
    }
    $GLOBALS['contractSigningEmails'][] = ['recipients' => $recipients, 'subject' => $subject, 'text' => $textBody];
}

require_once __DIR__ . '/../app/includes/contract_workflow.php';
require_once __DIR__ . '/../app/includes/contract_signing.php';
require_once __DIR__ . '/../app/includes/contract_signing_portal.php';
require_once __DIR__ . '/../app/includes/contract_negotiation.php';
require_once __DIR__ . '/../app/includes/contract_portal.php';
require_once __DIR__ . '/../app/includes/contract_renewal.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$newPdo = static function (): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE team8_contracts (id INTEGER PRIMARY KEY, contract_number TEXT NOT NULL, status TEXT NOT NULL, signature_version_no INTEGER NULL, signature_document_hash TEXT NULL, title TEXT NOT NULL, renewed_from_id INTEGER, deleted_at TEXT, updated_at TEXT);
         CREATE TABLE team8_contract_history (
             id INTEGER PRIMARY KEY AUTOINCREMENT, contract_id INTEGER NOT NULL, version_no INTEGER NOT NULL,
             data_json TEXT NOT NULL, changed_by INTEGER, created_at TEXT
         );
         CREATE TABLE team8_contract_parties (contract_id INTEGER NOT NULL, party_id INTEGER NOT NULL);
         CREATE TABLE team8_parties (id INTEGER PRIMARY KEY, name TEXT NOT NULL, trade_name TEXT, authorized_signatory_name TEXT, contact_email TEXT);
         CREATE TABLE team8_contract_signatures (
             id INTEGER PRIMARY KEY AUTOINCREMENT, contract_id INTEGER NOT NULL, party_id INTEGER NOT NULL,
             signature_version_no INTEGER, document_hash TEXT, signature_hash TEXT, signer_name TEXT NOT NULL,
             signer_email TEXT NOT NULL, signature_method TEXT, drawn_signature_path TEXT,
             drawn_signature_hash TEXT, consented_at TEXT, consent_text TEXT,
             status TEXT NOT NULL, signed_at TEXT, voided_at TEXT, voided_reason TEXT,
             ip_address TEXT, user_agent TEXT
         );
         CREATE TABLE team8_contract_signing_invites (
             id INTEGER PRIMARY KEY AUTOINCREMENT, contract_id INTEGER NOT NULL, party_id INTEGER NOT NULL,
             signature_version_no INTEGER NOT NULL, document_hash TEXT NOT NULL,
             email TEXT NOT NULL, party_name TEXT NOT NULL, signer_name TEXT, token_hash TEXT NOT NULL,
             status TEXT NOT NULL, expires_at TEXT NOT NULL, sent_at TEXT, used_at TEXT, created_by INTEGER
         );
         CREATE TABLE team8_contract_signing_otps (
             id INTEGER PRIMARY KEY AUTOINCREMENT, invite_id INTEGER NOT NULL, otp_hash TEXT NOT NULL,
             attempts INTEGER NOT NULL DEFAULT 0, sent_at TEXT NOT NULL, expires_at TEXT NOT NULL,
             verified_at TEXT, consumed_at TEXT
         );
         CREATE TABLE team8_contract_events (
             id INTEGER PRIMARY KEY AUTOINCREMENT, contract_id INTEGER NOT NULL, actor_id INTEGER,
             event_type TEXT NOT NULL, description TEXT NOT NULL, metadata TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
         );
         CREATE TABLE team8_contract_negotiations (
             id INTEGER PRIMARY KEY AUTOINCREMENT, contract_id INTEGER NOT NULL, version_no INTEGER NOT NULL,
             proposed_by INTEGER, change_summary TEXT NOT NULL, proposed_data TEXT NOT NULL,
             status TEXT NOT NULL, source TEXT NOT NULL, created_at TEXT, resolved_at TEXT
         );
         INSERT INTO team8_contracts (id, contract_number, status, title) VALUES (1, \'CON-TEST-1\', \'pending_approval\', \'Service Agreement\');
         INSERT INTO team8_contract_history (contract_id, version_no, data_json)
         VALUES (1, 1, \'{"contract_number":"CON-TEST-1","title":"Service Agreement","contract_type":"Service Agreement","description":"Test terms","start_date":"2026-10-08","end_date":null,"amount":1000,"currency":"PHP","payment_terms":null,"payment_frequency":null,"deposit_amount":null,"financial_notes":null,"notice_period_days":null}\');'
    );
    return $pdo;
};

$freeze = static function (PDO $pdo): void {
    t8_contract_freeze_signature_version($pdo, 1);
};

$sign = static function (PDO $pdo, int $partyId): string {
    $token = bin2hex(random_bytes(32));
    $pdo->prepare(
        "INSERT INTO team8_contract_signing_invites
         (contract_id, party_id, signature_version_no, document_hash, email, party_name, token_hash, status, expires_at, sent_at)
         VALUES (1, :party_id, 1, :document_hash, :email, :party_name, :token_hash, 'pending', :expires_at, CURRENT_TIMESTAMP)"
    )->execute([
        'party_id' => $partyId,
        'document_hash' => t8_contract_frozen_document($pdo, 1)['document_hash'],
        'email' => 'signer' . $partyId . '@example.test',
        'party_name' => 'Party ' . $partyId,
        'token_hash' => hash('sha256', $token),
        'expires_at' => date('Y-m-d H:i:s', strtotime('+14 days')),
    ]);
    t8_contract_send_signing_otp($pdo, $token);
    $email = array_pop($GLOBALS['contractSigningEmails']);
    if (!preg_match('/code is ([0-9]{6})/', (string) $email['text'], $matches)) {
        throw new RuntimeException('Test OTP email did not contain a code.');
    }
    t8_contract_submit_signature(
        $pdo,
        $token,
        $matches[1],
        'Signer ' . $partyId,
        'typed',
        '',
        true,
        '192.0.2.10',
        'Contract test'
    );
    return $token;
};

$pdo = $newPdo();
$activation = t8_contract_activation_audit('fully_executed', '');
$assert(t8_contract_workflow_transition_target('activate', 'fully_executed', true) === 'active'
    && $activation !== null && $activation['event_type'] === 'ACTIVATED'
    && t8_contract_workflow_transition_target('cancel', 'fully_executed', true) === 'cancelled', 'Case 1: fully executed activation and pre-activation cancellation must be available to admins.');

$override = t8_contract_activation_audit('pending_signature', 'Signed outside the portal.');
$assert(t8_contract_workflow_transition_target('activate', 'pending_signature', true) === 'active'
    && $override !== null
    && $override['event_type'] === 'ACTIVATED_WITHOUT_EXECUTION'
    && $override['metadata']['reason'] === 'Signed outside the portal.', 'Case 2: the escape hatch must retain its reason and distinct audit type.');

$pdo->exec("UPDATE team8_contracts SET status = 'pending_signature', signature_version_no = 1 WHERE id = 1");
$assert(t8_contract_activation_audit('pending_signature', '') === null
    && $pdo->query('SELECT status FROM team8_contracts WHERE id = 1')->fetchColumn() === 'pending_signature', 'Case 3: an override without a reason must be rejected without changing status.');

$assert(t8_contract_workflow_transition_target('activate', 'pending_signature', false) === null, 'Case 4: non-admin activation must be rejected.');

$pdo = $newPdo();
$freeze($pdo);
$pdo->exec('INSERT INTO team8_contract_parties (contract_id, party_id) VALUES (1, 11), (1, 12)');
$sign($pdo, 11);
$assert(!t8_contract_all_parties_signed($pdo, 1)
    && !t8_contract_mark_fully_executed($pdo, 1)
    && $pdo->query('SELECT status FROM team8_contracts WHERE id = 1')->fetchColumn() === 'partially_signed'
    && t8_contract_refresh_signature_status($pdo, 1) === 'partially_signed', 'Case 5: one of two signatures must not complete execution.');
$directSignatureBlocked = false;
try {
    t8_contract_register_signature($pdo, 1, 12, 'Signer 12', 'signer12@example.test', 'signed');
} catch (LogicException $e) {
    $directSignatureBlocked = true;
}
$assert($directSignatureBlocked, 'Case 5: completed signatures must not bypass the OTP-verified signing flow.');

$sign($pdo, 12);
$assert(t8_contract_all_parties_signed($pdo, 1)
    && t8_contract_refresh_signature_status($pdo, 1) === 'partially_signed'
    && t8_contract_mark_fully_executed($pdo, 1)
    && $pdo->query('SELECT status FROM team8_contracts WHERE id = 1')->fetchColumn() === 'fully_executed'
    && t8_contract_can_activate($pdo, 1), 'Case 6: valid signatures from every party must allow explicit execution and activation.');
$pdo->exec(
    "INSERT INTO team8_contract_negotiations (contract_id, version_no, change_summary, proposed_data, status, source)
     VALUES (1, 2, 'Pending revision', '{}', 'pending', 'internal')"
);
$assert(!t8_contract_can_activate($pdo, 1), 'Case 6: activation must stop while a negotiation is pending.');

$pdo = $newPdo();
$freeze($pdo);
$pdo->exec('INSERT INTO team8_contract_parties (contract_id, party_id) VALUES (1, 11)');
$pdo->exec("INSERT INTO team8_parties (id, name, contact_email) VALUES (11, 'Counterparty', 'signer11@example.test')");
$GLOBALS['contractSigningEmails'] = [];
$delivery = t8_contract_send_signing_invitations($pdo, 1, 7);
$assert($delivery['sent'] === 1 && $delivery['failed'] === [], 'Case 10: approval dispatches one signing link for each eligible party.');
$inviteEmail = array_shift($GLOBALS['contractSigningEmails']);
preg_match('/contract_sign\.php\?token=([a-f0-9]{64})/', (string) $inviteEmail['text'], $tokenMatch);
$signingToken = $tokenMatch[1] ?? '';
$storedTokenHash = (string) $pdo->query('SELECT token_hash FROM team8_contract_signing_invites LIMIT 1')->fetchColumn();
$assert($signingToken !== '' && hash_equals(hash('sha256', $signingToken), $storedTokenHash), 'Case 10: signing links store only token hashes.');
t8_contract_send_signing_otp($pdo, $signingToken);
$otpEmail = array_shift($GLOBALS['contractSigningEmails']);
preg_match('/code is ([0-9]{6})/', (string) $otpEmail['text'], $otpMatch);
$otp = $otpMatch[1] ?? '';
$assert($otp !== '', 'Case 10: each signing attempt sends a six-digit email OTP.');
$cooldownApplied = false;
try {
    t8_contract_send_signing_otp($pdo, $signingToken);
} catch (DomainException $e) {
    $cooldownApplied = true;
}
$assert($cooldownApplied, 'Case 10: OTP resend is limited by the 60-second cooldown.');
$consentRequired = false;
try {
    t8_contract_submit_signature($pdo, $signingToken, $otp, 'Signer 11', 'typed');
} catch (DomainException $e) {
    $consentRequired = true;
}
$assert($consentRequired, 'Case 10: a signature cannot be submitted without the explicit consent confirmation.');
$wrongOtpRejected = false;
try {
    $wrongOtp = $otp === '000000' ? '000001' : '000000';
    t8_contract_submit_signature($pdo, $signingToken, $wrongOtp, 'Signer 11', 'typed', '', true);
} catch (DomainException $e) {
    $wrongOtpRejected = true;
}
$assert($wrongOtpRejected, 'Case 10: an incorrect OTP is rejected and recorded as an attempt.');
$signature = t8_contract_submit_signature($pdo, $signingToken, $otp, 'Signer 11', 'typed', '', true);
$storedSignature = $pdo->query('SELECT * FROM team8_contract_signatures')->fetch(PDO::FETCH_ASSOC);
$assert($signature['signature_method'] === 'typed'
    && $storedSignature['consented_at'] !== null
    && $storedSignature['consent_text'] === T8_CONTRACT_SIGNING_CONSENT_TEXT
    && t8_contract_all_parties_signed($pdo, 1)
    && $pdo->query('SELECT status FROM team8_contracts WHERE id = 1')->fetchColumn() === 'partially_signed',
    'Case 10: OTP-verified consent is recorded and all-party signing remains admin-gated.');
$replayRejected = false;
try {
    t8_contract_submit_signature($pdo, $signingToken, $otp, 'Signer 11', 'typed', '', true);
} catch (DomainException $e) {
    $replayRejected = true;
}
$assert($replayRejected, 'Case 10: a signed invitation cannot be replayed.');

$pdo = $newPdo();
$freeze($pdo);
$pdo->exec('INSERT INTO team8_contract_parties (contract_id, party_id) VALUES (1, 11)');
$drawnToken = bin2hex(random_bytes(32));
$pdo->prepare(
    "INSERT INTO team8_contract_signing_invites
     (contract_id, party_id, signature_version_no, document_hash, email, party_name, token_hash, status, expires_at, sent_at)
     VALUES (1, 11, 1, :document_hash, 'signer11@example.test', 'Party 11', :token_hash, 'pending', :expires_at, CURRENT_TIMESTAMP)"
)->execute([
    'token_hash' => hash('sha256', $drawnToken),
    'document_hash' => t8_contract_frozen_document($pdo, 1)['document_hash'],
    'expires_at' => date('Y-m-d H:i:s', strtotime('+14 days')),
]);
t8_contract_send_signing_otp($pdo, $drawnToken);
$drawnOtpEmail = array_pop($GLOBALS['contractSigningEmails']);
preg_match('/code is ([0-9]{6})/', (string) $drawnOtpEmail['text'], $drawnOtpMatch);
$drawnSignature = t8_contract_submit_signature(
    $pdo,
    $drawnToken,
    $drawnOtpMatch[1] ?? '',
    'Signer 11',
    'drawn_pen',
    json_encode([[
        ['x' => 100, 'y' => 120],
        ['x' => 400, 'y' => 100],
        ['x' => 800, 'y' => 180],
    ]], JSON_THROW_ON_ERROR),
    true
);
$drawnFilePath = T8_SIGNATURE_STORAGE_DIR . DIRECTORY_SEPARATOR . (string) $pdo->query('SELECT drawn_signature_path FROM team8_contract_signatures')->fetchColumn();
$assert($drawnSignature['signature_method'] === 'drawn_pen'
    && is_file($drawnFilePath)
    && t8_contract_all_parties_signed($pdo, 1)
    && str_contains((string) file_get_contents($drawnFilePath), '<path d="M 100 120'),
    'Case 10: drawn signatures are stored as sanitized vector data outside the web root.');
unlink($drawnFilePath);
rmdir(T8_SIGNATURE_STORAGE_DIR);

$pdo = $newPdo();
$freeze($pdo);
$pdo->exec('INSERT INTO team8_contract_parties (contract_id, party_id) VALUES (1, 11)');
$limitedToken = bin2hex(random_bytes(32));
$pdo->prepare(
    "INSERT INTO team8_contract_signing_invites
     (contract_id, party_id, signature_version_no, document_hash, email, party_name, token_hash, status, expires_at, sent_at)
     VALUES (1, 11, 1, :document_hash, 'signer11@example.test', 'Party 11', :token_hash, 'pending', :expires_at, CURRENT_TIMESTAMP)"
)->execute([
    'token_hash' => hash('sha256', $limitedToken),
    'document_hash' => t8_contract_frozen_document($pdo, 1)['document_hash'],
    'expires_at' => date('Y-m-d H:i:s', strtotime('+14 days')),
]);
t8_contract_send_signing_otp($pdo, $limitedToken);
$limitedOtpEmail = array_pop($GLOBALS['contractSigningEmails']);
preg_match('/code is ([0-9]{6})/', (string) $limitedOtpEmail['text'], $limitedOtpMatch);
$limitedOtp = $limitedOtpMatch[1] ?? '';
$wrongAttemptCode = $limitedOtp === '999999' ? '000000' : '999999';
for ($attempt = 1; $attempt <= 5; $attempt++) {
    try {
        t8_contract_submit_signature($pdo, $limitedToken, $wrongAttemptCode, 'Signer 11', 'typed', '', true);
    } catch (DomainException $e) {
    }
}
$otpAttempts = (int) $pdo->query('SELECT attempts FROM team8_contract_signing_otps')->fetchColumn();
$otpConsumed = $pdo->query('SELECT consumed_at FROM team8_contract_signing_otps')->fetchColumn();
$assert($otpAttempts === 5 && $otpConsumed !== null && $limitedOtp !== '',
    'Case 10: five incorrect OTP attempts exhaust and consume a challenge.');

$pdo = $newPdo();
$freeze($pdo);
$pdo->exec('INSERT INTO team8_contract_parties (contract_id, party_id) VALUES (1, 11)');
$pdo->exec("INSERT INTO team8_parties (id, name, contact_email) VALUES (11, 'Counterparty', 'signer11@example.test')");
$GLOBALS['failContractSigningMail'] = true;
$failedDelivery = t8_contract_send_signing_invitations($pdo, 1, 7);
$GLOBALS['failContractSigningMail'] = false;
$failedInvite = $pdo->query('SELECT status FROM team8_contract_signing_invites')->fetchColumn();
$assert($failedDelivery['sent'] === 0
    && $failedDelivery['failed'] === ['Counterparty']
    && $failedInvite === 'delivery_failed',
    'Case 10: failed mail delivery is visible and leaves no active signing invitation.');

$pdo = $newPdo();
$freeze($pdo);
$pdo->exec('INSERT INTO team8_contract_parties (contract_id, party_id) VALUES (1, 11)');
$revokedToken = bin2hex(random_bytes(32));
$pdo->prepare(
    "INSERT INTO team8_contract_signing_invites
     (contract_id, party_id, signature_version_no, document_hash, email, party_name, token_hash, status, expires_at, sent_at)
     VALUES (1, 11, 1, :document_hash, 'signer11@example.test', 'Party 11', :token_hash, 'pending', :expires_at, CURRENT_TIMESTAMP)"
)->execute([
    'token_hash' => hash('sha256', $revokedToken),
    'document_hash' => t8_contract_frozen_document($pdo, 1)['document_hash'],
    'expires_at' => date('Y-m-d H:i:s', strtotime('+14 days')),
]);
$pdo->exec("INSERT INTO team8_contract_signing_otps (invite_id, otp_hash, attempts, sent_at, expires_at) VALUES (1, 'unused', 0, CURRENT_TIMESTAMP, datetime('now', '+10 minutes'))");
t8_contract_revoke_signing_invites_for_revision($pdo, 1);
$revokedInvite = t8_contract_signing_invite($pdo, $revokedToken);
$assert($revokedInvite !== null && $revokedInvite['invite_status'] === 'revoked'
    && $pdo->query('SELECT consumed_at FROM team8_contract_signing_otps')->fetchColumn() !== null,
    'Case 11: reopening for revision revokes live signing links and consumes OTPs.');

$pdo = $newPdo();
$freeze($pdo);
$pdo->exec('INSERT INTO team8_contract_parties (contract_id, party_id) VALUES (1, 11), (1, 12)');
$revisionTokenOne = $sign($pdo, 11);
$revisionTokenTwo = $sign($pdo, 12);
$assert(t8_contract_workflow_transition_target('reopen_for_revision', 'fully_executed', true) === 'negotiation', 'Case 7: administrators must be able to reopen executed contracts for revision.');
t8_contract_void_signatures_for_revision($pdo, 1);
t8_contract_revoke_signing_invites_for_revision($pdo, 1);
$pdo->exec("UPDATE team8_contracts SET status = 'negotiation' WHERE id = 1");
$revokedSignatures = (int) $pdo->query("SELECT COUNT(*) FROM team8_contract_signing_invites WHERE status = 'revoked'")->fetchColumn();
$assert($revokedSignatures === 2
    && t8_contract_signing_invite($pdo, $revisionTokenOne)['invite_status'] === 'revoked'
    && t8_contract_signing_invite($pdo, $revisionTokenTwo)['invite_status'] === 'revoked',
    'Case 7: revision revokes previously used signing links along with signatures.');
$proposal = t8_contract_create_negotiation($pdo, 1, 8, 'Update the service agreement title.', ['title' => 'Revised Service Agreement']);
$assert($proposal !== null && $proposal['version_no'] === 2 && $proposal['status'] === 'accepted'
    && $pdo->query('SELECT title FROM team8_contracts WHERE id = 1')->fetchColumn() === 'Revised Service Agreement'
    && $pdo->query('SELECT status FROM team8_contracts WHERE id = 1')->fetchColumn() === 'under_review',
    'Case 7: saving the revised version after reopening must publish it and return the contract to external review.');
$pdo->exec("UPDATE team8_contracts SET status = 'pending_approval' WHERE id = 1");
$freeze($pdo);
$voidedCount = (int) $pdo->query('SELECT COUNT(*) FROM team8_contract_signatures WHERE voided_at IS NOT NULL AND voided_reason = \'superseded by new version\'')->fetchColumn();
$assert($voidedCount === 2 && !t8_contract_all_parties_signed($pdo, 1), 'Case 7: old signatures must be voided and not satisfy the new version.');

$reviewPdo = new PDO('sqlite::memory:');
$reviewPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$reviewPdo->exec(
    'CREATE TABLE team8_contract_parties (contract_id INTEGER NOT NULL, party_id INTEGER NOT NULL);
     CREATE TABLE team8_parties (id INTEGER PRIMARY KEY, name TEXT NOT NULL, trade_name TEXT);
     CREATE TABLE team8_contract_portal_invites (id INTEGER PRIMARY KEY, contract_id INTEGER, party_id INTEGER, status TEXT, response_feedback TEXT, meeting_details TEXT, created_at TEXT);
     INSERT INTO team8_contract_parties VALUES (1, 11), (1, 12);
     INSERT INTO team8_parties VALUES (11, \'Alpha\', NULL), (12, \'Beta\', NULL);
     INSERT INTO team8_contract_portal_invites VALUES (1, 1, 11, \'accepted\', NULL, NULL, CURRENT_TIMESTAMP), (2, 1, 12, \'pending\', NULL, NULL, CURRENT_TIMESTAMP);'
);
$assert(!t8_contract_parties_have_accepted_latest_review($reviewPdo, 1), 'Case 8: an unaccepted review must continue to block contracting approval.');

$pdo = $newPdo();
$freeze($pdo);
$pdo->exec('INSERT INTO team8_contract_parties (contract_id, party_id) VALUES (1, 11)');
$sign($pdo, 11);
$pdo->exec("UPDATE team8_contract_history SET data_json = '{\"title\":\"Tampered\"}' WHERE contract_id = 1 AND version_no = 1");
$hashMismatchFailsClosed = false;
try {
    t8_contract_all_parties_signed($pdo, 1);
} catch (RuntimeException $e) {
    $hashMismatchFailsClosed = true;
}
$assert($hashMismatchFailsClosed, 'Case 9: a changed frozen document must invalidate its signature.');

$pdo = $newPdo();
$freeze($pdo);
$assert(!t8_contract_all_parties_signed($pdo, 1), 'Case 10: a contract with no involved parties must not pass the execution gate.');

$pdo = $newPdo();
$freeze($pdo);
$pdo->exec('DROP TABLE team8_contract_signatures');
$schemaFailureClosed = false;
try {
    t8_contract_all_parties_signed($pdo, 1);
} catch (Throwable $e) {
    $schemaFailureClosed = true;
}
$assert($schemaFailureClosed, 'Case 11: a missing signature schema must fail closed.');

$renewalPdo = $newPdo();
$renewalPdo->exec('UPDATE team8_contracts SET renewed_from_id = 7 WHERE id = 1');
$renewalPdo->exec('DELETE FROM team8_contract_history WHERE contract_id = 1');
$renewalPdo->beginTransaction();
t8_contract_ensure_renewal_history($renewalPdo, 1, 8);
$renewalPdo->commit();
$renewalHistory = $renewalPdo->query('SELECT version_no, data_json, changed_by FROM team8_contract_history WHERE contract_id = 1')->fetch(PDO::FETCH_ASSOC);
$renewalSnapshot = json_decode((string) $renewalHistory['data_json'], true, 512, JSON_THROW_ON_ERROR);
$assert((int) $renewalHistory['version_no'] === 1 && (int) $renewalHistory['changed_by'] === 8
    && $renewalSnapshot['title'] === 'Service Agreement'
    && !isset($renewalSnapshot['id'], $renewalSnapshot['status']),
    'Legacy renewals must get an initial history snapshot of their current terms before signature.');

fwrite(STDOUT, "Contract execution and digital signing workflow checks passed.\n");
