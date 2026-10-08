<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/contract_workflow.php';
require_once __DIR__ . '/../app/includes/contract_signing.php';
require_once __DIR__ . '/../app/includes/contract_negotiation.php';
require_once __DIR__ . '/../app/includes/contract_portal.php';

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
        'CREATE TABLE team8_contracts (id INTEGER PRIMARY KEY, status TEXT NOT NULL, signature_version_no INTEGER NULL, signature_document_hash TEXT NULL, title TEXT NOT NULL, updated_at TEXT);
         CREATE TABLE team8_contract_history (
             id INTEGER PRIMARY KEY AUTOINCREMENT, contract_id INTEGER NOT NULL, version_no INTEGER NOT NULL,
             data_json TEXT NOT NULL, changed_by INTEGER, created_at TEXT
         );
         CREATE TABLE team8_contract_parties (contract_id INTEGER NOT NULL, party_id INTEGER NOT NULL);
         CREATE TABLE team8_contract_signatures (
             id INTEGER PRIMARY KEY AUTOINCREMENT, contract_id INTEGER NOT NULL, party_id INTEGER NOT NULL,
             signature_version_no INTEGER, document_hash TEXT, signature_hash TEXT, signer_name TEXT NOT NULL,
             signer_email TEXT NOT NULL, status TEXT NOT NULL, signed_at TEXT, voided_at TEXT, voided_reason TEXT,
             ip_address TEXT, user_agent TEXT
         );
         CREATE TABLE team8_contract_negotiations (
             id INTEGER PRIMARY KEY AUTOINCREMENT, contract_id INTEGER NOT NULL, version_no INTEGER NOT NULL,
             proposed_by INTEGER, change_summary TEXT NOT NULL, proposed_data TEXT NOT NULL,
             status TEXT NOT NULL, source TEXT NOT NULL, created_at TEXT, resolved_at TEXT
         );
         INSERT INTO team8_contracts (id, status, title) VALUES (1, \'pending_approval\', \'Service Agreement\');
         INSERT INTO team8_contract_history (contract_id, version_no, data_json) VALUES (1, 1, \'{"title":"Service Agreement","amount":1000}\');'
    );
    return $pdo;
};

$freeze = static function (PDO $pdo): void {
    t8_contract_freeze_signature_version($pdo, 1);
};

$sign = static function (PDO $pdo, int $partyId): void {
    $result = t8_contract_record_signature(
        $pdo,
        1,
        $partyId,
        'Signer ' . $partyId,
        'signer' . $partyId . '@example.test',
        'signed',
        '2026-10-08 10:00:00',
        '192.0.2.10',
        'Contract test'
    );
    if ($result === null) {
        throw new RuntimeException('Test signature was not recorded.');
    }
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
    && $pdo->query('SELECT status FROM team8_contracts WHERE id = 1')->fetchColumn() === 'pending_signature', 'Case 5: one of two signatures must not complete execution.');

$sign($pdo, 12);
$assert(t8_contract_all_parties_signed($pdo, 1)
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
$pdo->exec('INSERT INTO team8_contract_parties (contract_id, party_id) VALUES (1, 11), (1, 12)');
$sign($pdo, 11);
$sign($pdo, 12);
$assert(t8_contract_workflow_transition_target('reopen_for_revision', 'fully_executed', true) === 'negotiation', 'Case 7: administrators must be able to reopen executed contracts for revision.');
t8_contract_void_signatures_for_revision($pdo, 1);
$pdo->exec("UPDATE team8_contracts SET status = 'negotiation' WHERE id = 1");
$proposal = t8_contract_create_negotiation($pdo, 1, 8, 'Update the service agreement title.', ['title' => 'Revised Service Agreement']);
$assert($proposal !== null && $proposal['version_no'] === 2
    && t8_contract_accept_negotiation($pdo, 1, (int) $proposal['id'], 9), 'Case 7: the revised version must be proposed and accepted after reopening.');
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

fwrite(STDOUT, "Contract execution workflow checks passed (11 cases).\n");
