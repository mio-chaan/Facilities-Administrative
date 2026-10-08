<?php
declare(strict_types=1);

require_once __DIR__ . '/contract_signing.php';
require_once __DIR__ . '/contract_portal.php';

if (!defined('T8_CONTRACT_SIGNING_CONSENT_TEXT')) {
    define('T8_CONTRACT_SIGNING_CONSENT_TEXT', 'I have read and agree to be bound by this contract.');
}

function t8_contract_signing_otp_pepper(): string
{
    $pepper = defined('T8_SIGNING_OTP_PEPPER') ? (string) T8_SIGNING_OTP_PEPPER : '';
    if (strlen($pepper) < 32) {
        throw new RuntimeException('Contract signing OTPs require T8_SIGNING_OTP_PEPPER to be configured with at least 32 characters.');
    }
    return $pepper;
}

function t8_contract_signing_otp_hash(string $otp): string
{
    return hash_hmac('sha256', $otp, t8_contract_signing_otp_pepper());
}

function t8_contract_signing_invite(PDO $pdo, string $token, bool $lock = false): ?array
{
    if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        return null;
    }
    $sql = "SELECT i.id AS invite_id, i.contract_id, i.party_id, i.email, i.party_name,
                   i.signature_version_no AS invitation_version_no,
                   i.document_hash AS invitation_document_hash,
                   i.signer_name AS invited_signer_name, i.status AS invite_status,
                   i.expires_at AS invite_expires_at, i.sent_at, i.used_at,
                   c.contract_number, c.title, c.status AS contract_status,
                   c.signature_version_no, c.signature_document_hash
            FROM team8_contract_signing_invites i
            JOIN team8_contracts c ON c.id = i.contract_id
            WHERE i.token_hash = :token_hash AND c.deleted_at IS NULL
              AND EXISTS (
                  SELECT 1 FROM team8_contract_parties cp
                  WHERE cp.contract_id = i.contract_id AND cp.party_id = i.party_id
              )
            LIMIT 1";
    if ($lock && (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['token_hash' => hash('sha256', $token)]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function t8_contract_signing_invite_is_live(array $invite): bool
{
    return ($invite['invite_status'] ?? '') === 'pending'
        && !empty($invite['sent_at'])
        && strtotime((string) ($invite['invite_expires_at'] ?? '')) > time()
        && (int) ($invite['invitation_version_no'] ?? 0) === (int) ($invite['signature_version_no'] ?? -1)
        && hash_equals(
            (string) ($invite['signature_document_hash'] ?? ''),
            (string) ($invite['invitation_document_hash'] ?? '')
        )
        && in_array((string) ($invite['contract_status'] ?? ''), ['pending_signature', 'partially_signed'], true);
}

function t8_contract_party_has_valid_signature(PDO $pdo, int $contractId, int $partyId, array $document): bool
{
    $stmt = $pdo->prepare(
        "SELECT signature_version_no, document_hash, signature_hash, signature_method, drawn_signature_path,
                drawn_signature_hash, consented_at, consent_text,
                signer_name, signer_email, signed_at, status
         FROM team8_contract_signatures
         WHERE contract_id = :contract_id AND party_id = :party_id AND voided_at IS NULL
           AND signature_version_no = :version_no
           AND LOWER(status) IN ('signed', 'complete', 'approved')
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([
        'contract_id' => $contractId,
        'party_id' => $partyId,
        'version_no' => $document['version_no'],
    ]);
    $signature = $stmt->fetch(PDO::FETCH_ASSOC);
    return $signature !== false && t8_contract_signature_is_valid($signature, $contractId, $partyId, $document);
}

function t8_contract_send_signing_invitations(PDO $pdo, int $contractId, int $actorId): array
{
    if (!defined('APP_URL')) {
        throw new RuntimeException('APP_URL must be configured before signing invitations can be sent.');
    }
    $contractStmt = $pdo->prepare(
        "SELECT id, contract_number, title, status FROM team8_contracts
         WHERE id = :id AND deleted_at IS NULL LIMIT 1"
    );
    $contractStmt->execute(['id' => $contractId]);
    $contract = $contractStmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract || !in_array($contract['status'], ['pending_signature', 'partially_signed'], true)) {
        throw new RuntimeException('The contract is not currently accepting signatures.');
    }
    $document = t8_contract_frozen_document($pdo, $contractId);
    t8_contract_signing_load_snapshot($pdo, [
        'contract_id' => $contractId,
        'invitation_version_no' => $document['version_no'],
        'invitation_document_hash' => $document['document_hash'],
    ]);
    if (!t8_contract_review_url_is_allowed(APP_URL, defined('APP_ENV') ? (string) APP_ENV : '')) {
        throw new RuntimeException('APP_URL must be an HTTP(S) loopback URL in local mode or a public HTTPS address otherwise.');
    }
    $partyStmt = $pdo->prepare(
        "SELECT DISTINCT p.id AS party_id, COALESCE(NULLIF(p.trade_name, ''), p.name) AS party_name,
                p.name AS legal_party_name, p.authorized_signatory_name, p.contact_email
         FROM team8_contract_parties cp
         JOIN team8_parties p ON p.id = cp.party_id
         WHERE cp.contract_id = :contract_id ORDER BY party_name"
    );
    $partyStmt->execute(['contract_id' => $contractId]);
    $parties = $partyStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($parties === []) {
        throw new RuntimeException('At least one involved party is required before signing invitations can be sent.');
    }

    $result = ['sent' => 0, 'failed' => [], 'signed' => 0];
    foreach ($parties as $party) {
        $partyId = (int) $party['party_id'];
        if (t8_contract_party_has_valid_signature($pdo, $contractId, $partyId, $document)) {
            $result['signed']++;
            continue;
        }
        $email = trim((string) ($party['contact_email'] ?? ''));
        $partyName = trim((string) ($party['party_name'] ?? ''));
        if ($partyName === '') {
            $partyName = 'Party #' . $partyId;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $result['failed'][] = $partyName . ' (missing or invalid email address)';
            continue;
        }
        if (!function_exists('t8_gmail_dispatch')) {
            throw new RuntimeException('The email service is unavailable. Load the Gmail dispatch helper before sending invitations.');
        }

        $token = bin2hex(random_bytes(32));
        $pdo->beginTransaction();
        try {
            $lockSql = 'SELECT status, signature_version_no, signature_document_hash FROM team8_contracts WHERE id = :id';
            if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                $lockSql .= ' FOR UPDATE';
            }
            $lockStmt = $pdo->prepare($lockSql);
            $lockStmt->execute(['id' => $contractId]);
            $lockedContract = $lockStmt->fetch(PDO::FETCH_ASSOC);
            if (!$lockedContract
                || !in_array($lockedContract['status'], ['pending_signature', 'partially_signed'], true)
                || (int) $lockedContract['signature_version_no'] !== (int) $document['version_no']
                || !hash_equals((string) $document['document_hash'], (string) $lockedContract['signature_document_hash'])) {
                throw new DomainException('The contract changed before signing invitations could be issued.');
            }
            $partyLock = $pdo->prepare(
                'SELECT 1 FROM team8_contract_parties WHERE contract_id = :contract_id AND party_id = :party_id'
            );
            $partyLock->execute(['contract_id' => $contractId, 'party_id' => $partyId]);
            if ($partyLock->fetchColumn() === false) {
                throw new DomainException('The invited party is no longer involved in this contract.');
            }
            $pdo->prepare(
                "UPDATE team8_contract_signing_invites SET status = 'expired'
                 WHERE contract_id = :contract_id AND party_id = :party_id AND status = 'pending'"
            )->execute(['contract_id' => $contractId, 'party_id' => $partyId]);
            $insert = $pdo->prepare(
                "INSERT INTO team8_contract_signing_invites
                 (contract_id, party_id, signature_version_no, document_hash, email, party_name, signer_name,
                  token_hash, status, expires_at, sent_at, created_by)
                 VALUES (:contract_id, :party_id, :version_no, :document_hash, :email, :party_name, :signer_name,
                  :token_hash, 'pending', :expires_at, CURRENT_TIMESTAMP, :created_by)"
            );
            $insert->execute([
                'contract_id' => $contractId,
                'party_id' => $partyId,
                'version_no' => (int) $document['version_no'],
                'document_hash' => (string) $document['document_hash'],
                'email' => $email,
                'party_name' => $partyName,
                'signer_name' => trim((string) ($party['authorized_signatory_name'] ?? '')) ?: null,
                'token_hash' => hash('sha256', $token),
                'expires_at' => date('Y-m-d H:i:s', strtotime('+14 days')),
                'created_by' => $actorId > 0 ? $actorId : null,
            ]);
            $inviteId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                "INSERT INTO team8_contract_events
                 (contract_id, actor_id, event_type, description, metadata)
                 VALUES (:contract_id, :actor_id, 'SIGNING_INVITATION_CREATED',
                         'A one-time signing invitation was created for an involved party.',
                         :metadata)"
            )->execute([
                'contract_id' => $contractId,
                'actor_id' => $actorId > 0 ? $actorId : null,
                'metadata' => json_encode([
                    'party_id' => $partyId,
                    'invitation_id' => $inviteId,
                    'signature_version_no' => (int) $document['version_no'],
                ], JSON_THROW_ON_ERROR),
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $signingUrl = rtrim(APP_URL, '/') . '/contract_sign.php?token=' . rawurlencode($token);
        $safeParty = htmlspecialchars($partyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeNumber = htmlspecialchars((string) $contract['contract_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeTitle = htmlspecialchars((string) $contract['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($signingUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        try {
            t8_gmail_dispatch(
                [$email],
                'Signature requested: ' . $contract['contract_number'] . ' - ' . $contract['title'],
                '<p>Hello ' . $safeParty . ',</p><p>Please review and sign contract <strong>' . $safeNumber
                    . '</strong>: ' . $safeTitle . '.</p><p><a href="' . $safeUrl . '">Open the secure signing page</a></p>'
                    . '<p>This one-time link expires in 14 days. An email verification code is required to sign.</p>',
                "Hello {$partyName},\n\nPlease review and sign contract {$contract['contract_number']}: {$contract['title']}.\n\n"
                    . "Open the secure signing page: {$signingUrl}\n\nThis one-time link expires in 14 days. An email verification code is required to sign."
            );
            $result['sent']++;
        } catch (Throwable $e) {
            $pdo->prepare(
                "UPDATE team8_contract_signing_invites SET status = 'delivery_failed'
                 WHERE id = :id AND status = 'pending'"
            )->execute(['id' => $inviteId]);
            if (function_exists('t8_gmail_diagnostic_log')) {
                t8_gmail_diagnostic_log(
                    'Contract Signing Invite Delivery Failure',
                    'Contract #' . $contractId . ', party #' . $partyId . ': ' . $e->getMessage()
                );
            }
            $result['failed'][] = $partyName;
        }
    }
    return $result;
}

function t8_contract_signing_load_snapshot(PDO $pdo, array $invite): array
{
    $document = t8_contract_frozen_document($pdo, (int) $invite['contract_id']);
    if ((int) ($invite['invitation_version_no'] ?? 0) !== (int) $document['version_no']
        || !hash_equals((string) $document['document_hash'], (string) ($invite['invitation_document_hash'] ?? ''))) {
        throw new DomainException('This signing link does not match the current frozen contract version.');
    }
    $terms = json_decode($document['data_json'], true, 512, JSON_THROW_ON_ERROR);
    $requiredTermKeys = [
        'contract_number', 'title', 'contract_type', 'description', 'start_date', 'end_date',
        'amount', 'currency', 'payment_terms', 'payment_frequency', 'deposit_amount',
        'financial_notes', 'notice_period_days',
    ];
    if (!is_array($terms) || array_diff($requiredTermKeys, array_keys($terms)) !== []
        || trim((string) ($terms['title'] ?? '')) === '') {
        throw new RuntimeException('The frozen contract snapshot does not contain displayable contract terms.');
    }
    $document['terms'] = $terms;
    return $document;
}

function t8_contract_send_signing_otp(PDO $pdo, string $token): void
{
    $pepper = t8_contract_signing_otp_pepper();
    if (!function_exists('t8_gmail_dispatch')) {
        throw new RuntimeException('The email service is unavailable. Load the Gmail dispatch helper before requesting a code.');
    }
    $otp = sprintf('%06d', random_int(0, 999999));
    $sentAt = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $invite = t8_contract_signing_invite($pdo, $token, true);
        if (!$invite || !t8_contract_signing_invite_is_live($invite)) {
            throw new DomainException('This signing link is invalid, expired, or no longer active.');
        }
        t8_contract_signing_load_snapshot($pdo, $invite);
        $latestSql = 'SELECT sent_at FROM team8_contract_signing_otps WHERE invite_id = :invite_id ORDER BY id DESC LIMIT 1';
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $latestSql .= ' FOR UPDATE';
        }
        $latestStmt = $pdo->prepare($latestSql);
        $latestStmt->execute(['invite_id' => (int) $invite['invite_id']]);
        $latestSentAt = $latestStmt->fetchColumn();
        if (is_string($latestSentAt) && time() - strtotime($latestSentAt) < 60) {
            throw new DomainException('Please wait before requesting another verification code.');
        }
        $pdo->prepare(
            'UPDATE team8_contract_signing_otps SET consumed_at = CURRENT_TIMESTAMP
             WHERE invite_id = :invite_id AND consumed_at IS NULL'
        )->execute(['invite_id' => (int) $invite['invite_id']]);
        $pdo->prepare(
            'INSERT INTO team8_contract_signing_otps (invite_id, otp_hash, attempts, sent_at, expires_at)
             VALUES (:invite_id, :otp_hash, 0, :sent_at, :expires_at)'
        )->execute([
            'invite_id' => (int) $invite['invite_id'],
            'otp_hash' => hash_hmac('sha256', $otp, $pepper),
            'sent_at' => $sentAt,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+10 minutes')),
        ]);
        $challengeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO team8_contract_events
             (contract_id, actor_id, event_type, description, metadata)
             VALUES (:contract_id, NULL, 'SIGNING_OTP_REQUESTED',
                     'An email verification code was requested for contract signing.',
                     :metadata)"
        )->execute([
            'contract_id' => (int) $invite['contract_id'],
            'metadata' => json_encode([
                'party_id' => (int) $invite['party_id'],
                'invitation_id' => (int) $invite['invite_id'],
                'challenge_id' => $challengeId,
            ], JSON_THROW_ON_ERROR),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    try {
        $safeName = htmlspecialchars((string) $invite['party_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        t8_gmail_dispatch(
            [(string) $invite['email']],
            'Your contract signing verification code',
            '<p>Hello ' . $safeName . ',</p><p>Your verification code is <strong>' . $otp
                . '</strong>. It expires in 10 minutes. Do not share it.</p>',
            "Hello {$invite['party_name']},\n\nYour contract signing verification code is {$otp}. It expires in 10 minutes. Do not share it."
        );
    } catch (Throwable $e) {
        $pdo->prepare(
            'UPDATE team8_contract_signing_otps SET consumed_at = CURRENT_TIMESTAMP
             WHERE invite_id = :invite_id AND otp_hash = :otp_hash AND consumed_at IS NULL'
        )->execute([
            'invite_id' => (int) $invite['invite_id'],
            'otp_hash' => hash_hmac('sha256', $otp, $pepper),
        ]);
        if (function_exists('t8_gmail_diagnostic_log')) {
            t8_gmail_diagnostic_log(
                'Contract Signing OTP Delivery Failure',
                'Contract #' . (int) $invite['contract_id'] . ', party #' . (int) $invite['party_id'] . ': ' . $e->getMessage()
            );
        }
        throw new RuntimeException('The verification code could not be delivered. Please try again later.', 0, $e);
    }
}

function t8_contract_signing_drawn_file(string $drawingJson): string
{
    $storageRoot = defined('T8_SIGNATURE_STORAGE_DIR') ? trim((string) T8_SIGNATURE_STORAGE_DIR) : '';
    if ($storageRoot === '') {
        throw new RuntimeException('Drawn signatures require T8_SIGNATURE_STORAGE_DIR to point to private storage outside the web root.');
    }
    if (strlen($drawingJson) > 20000) {
        throw new InvalidArgumentException('The drawn signature contains too much data.');
    }
    $strokes = json_decode($drawingJson, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($strokes) || $strokes === [] || count($strokes) > 20) {
        throw new InvalidArgumentException('Draw a signature before submitting.');
    }
    $paths = [];
    foreach ($strokes as $stroke) {
        if (!is_array($stroke) || count($stroke) < 2 || count($stroke) > 500) {
            throw new InvalidArgumentException('The drawn signature contains an invalid stroke.');
        }
        $points = [];
        foreach ($stroke as $point) {
            if (!is_array($point) || !isset($point['x'], $point['y'])
                || !is_numeric($point['x']) || !is_numeric($point['y'])) {
                throw new InvalidArgumentException('The drawn signature contains invalid coordinates.');
            }
            $x = (float) $point['x'];
            $y = (float) $point['y'];
            if (!is_finite($x) || !is_finite($y) || $x < 0 || $x > 1000 || $y < 0 || $y > 300) {
                throw new InvalidArgumentException('The drawn signature coordinates are out of range.');
            }
            $points[] = [round($x, 2), round($y, 2)];
        }
        $path = 'M ' . $points[0][0] . ' ' . $points[0][1];
        for ($index = 1; $index < count($points) - 1; $index++) {
            $middleX = round(($points[$index][0] + $points[$index + 1][0]) / 2, 2);
            $middleY = round(($points[$index][1] + $points[$index + 1][1]) / 2, 2);
            $path .= ' Q ' . $points[$index][0] . ' ' . $points[$index][1] . ' ' . $middleX . ' ' . $middleY;
        }
        if (count($points) > 1) {
            $last = $points[count($points) - 1];
            $path .= ' L ' . $last[0] . ' ' . $last[1];
        }
        $paths[] = '<path d="' . $path . '"/>';
    }

    if (!is_dir($storageRoot) && !mkdir($storageRoot, 0700, true) && !is_dir($storageRoot)) {
        throw new RuntimeException('Private drawn-signature storage could not be created.');
    }
    $resolvedStorage = realpath($storageRoot);
    if ($resolvedStorage === false) {
        throw new RuntimeException('Private drawn-signature storage could not be resolved.');
    }
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
    if ($documentRoot !== false) {
        $normalizedStorage = rtrim(str_replace('\\', '/', $resolvedStorage), '/');
        $normalizedDocumentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
        if (strncasecmp($normalizedStorage, $normalizedDocumentRoot . '/', strlen($normalizedDocumentRoot) + 1) === 0
            || strcasecmp($normalizedStorage, $normalizedDocumentRoot) === 0) {
            throw new RuntimeException('T8_SIGNATURE_STORAGE_DIR must be outside the web root.');
        }
    }
    if (!is_writable($resolvedStorage)) {
        throw new RuntimeException('Private drawn-signature storage is not writable.');
    }
    $fileName = bin2hex(random_bytes(32)) . '.svg';
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 300" role="img">'
        . '<g fill="none" stroke="#111" stroke-width="4" stroke-linecap="round" stroke-linejoin="round">'
        . implode('', $paths) . '</g></svg>';
    $filePath = $resolvedStorage . DIRECTORY_SEPARATOR . $fileName;
    if (file_put_contents($filePath, $svg, LOCK_EX) === false) {
        throw new RuntimeException('The drawn signature could not be stored.');
    }
    @chmod($filePath, 0600);
    return $fileName;
}

function t8_contract_submit_signature(
    PDO $pdo,
    string $token,
    string $otp,
    string $signerName,
    string $method,
    string $drawingJson = '',
    bool $consentConfirmed = false,
    ?string $ipAddress = null,
    ?string $userAgent = null
): array {
    if (!$consentConfirmed) {
        throw new DomainException('Confirm that you have read and agree to be bound by this contract.');
    }
    if (trim($signerName) === '' || strlen(trim($signerName)) > 200) {
        throw new InvalidArgumentException('Enter your full legal name (up to 200 characters).');
    }
    if (!in_array($method, ['typed', 'drawn_touch', 'drawn_pen', 'drawn_mouse'], true)) {
        throw new InvalidArgumentException('Choose a supported signature method.');
    }
    if (preg_match('/^\d{6}$/', $otp) !== 1) {
        throw new InvalidArgumentException('Enter the 6-digit email verification code.');
    }
    if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        throw new DomainException('This signing link is invalid, expired, or no longer active.');
    }

    $drawnPath = null;
    if ($method === 'typed' && $drawingJson !== '') {
        throw new InvalidArgumentException('Clear the drawn signature or choose the typed-name option.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $invite = t8_contract_signing_invite($pdo, $token, true);
        if (!$invite || !t8_contract_signing_invite_is_live($invite)) {
            throw new DomainException('This signing link is invalid, expired, or no longer active.');
        }
        $document = t8_contract_signing_load_snapshot($pdo, $invite);
        $otpStmt = $pdo->prepare(
            'SELECT id, otp_hash, attempts, expires_at, verified_at
             FROM team8_contract_signing_otps
             WHERE invite_id = :invite_id AND consumed_at IS NULL ORDER BY id DESC LIMIT 1'
        );
        $otpStmt->execute(['invite_id' => (int) $invite['invite_id']]);
        $challenge = $otpStmt->fetch(PDO::FETCH_ASSOC);
        if (!$challenge || !empty($challenge['verified_at'])
            || (int) $challenge['attempts'] >= 5
            || strtotime((string) $challenge['expires_at']) <= time()) {
            throw new DomainException('Request a new email verification code before signing.');
        }
        $expectedOtpHash = hash_hmac('sha256', $otp, t8_contract_signing_otp_pepper());
        if (!hash_equals((string) $challenge['otp_hash'], $expectedOtpHash)) {
            $attempts = (int) $challenge['attempts'] + 1;
            $attemptSql = $attempts >= 5
                ? 'UPDATE team8_contract_signing_otps SET attempts = :attempts, consumed_at = CURRENT_TIMESTAMP WHERE id = :id AND consumed_at IS NULL'
                : 'UPDATE team8_contract_signing_otps SET attempts = :attempts WHERE id = :id AND consumed_at IS NULL';
            $pdo->prepare($attemptSql)->execute(['attempts' => $attempts, 'id' => (int) $challenge['id']]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            throw new DomainException($attempts >= 5
                ? 'Too many incorrect codes. Request a new verification code after the resend cooldown.'
                : 'The verification code is incorrect.');
        }

        if ($method !== 'typed') {
            $drawnPath = t8_contract_signing_drawn_file($drawingJson);
        }
        $drawnHash = null;
        if ($drawnPath !== null) {
            $drawnFile = rtrim((string) T8_SIGNATURE_STORAGE_DIR, '/\\') . DIRECTORY_SEPARATOR . $drawnPath;
            $drawnHash = hash_file('sha256', $drawnFile);
            if (!is_string($drawnHash)) {
                throw new RuntimeException('The drawn signature could not be verified after storage.');
            }
        }
        $signedAt = date('Y-m-d H:i:s');
        $consentedAt = $signedAt;
        $ipAddress = $ipAddress ?? (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $userAgent = substr($userAgent ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        $signatureHash = t8_contract_signature_digest(
            (int) $invite['contract_id'],
            (int) $invite['party_id'],
            (string) $document['document_hash'],
            $signedAt,
            trim($signerName),
            (string) $invite['email'],
            $method,
            $consentedAt,
            T8_CONTRACT_SIGNING_CONSENT_TEXT,
            $drawnHash
        );
        $insert = $pdo->prepare(
            "INSERT INTO team8_contract_signatures
             (contract_id, party_id, signature_version_no, document_hash, signature_hash,
              signer_name, signer_email, signature_method, drawn_signature_path, drawn_signature_hash,
              consented_at, consent_text,
              status, signed_at, ip_address, user_agent)
             VALUES (:contract_id, :party_id, :version_no, :document_hash, :signature_hash,
              :signer_name, :signer_email, :signature_method, :drawn_signature_path, :drawn_signature_hash,
              :consented_at, :consent_text,
              'signed', :signed_at, :ip_address, :user_agent)"
        );
        $insert->execute([
            'contract_id' => (int) $invite['contract_id'],
            'party_id' => (int) $invite['party_id'],
            'version_no' => (int) $document['version_no'],
            'document_hash' => (string) $document['document_hash'],
            'signature_hash' => $signatureHash,
            'signer_name' => trim($signerName),
            'signer_email' => (string) $invite['email'],
            'signature_method' => $method,
            'drawn_signature_path' => $drawnPath,
            'drawn_signature_hash' => $drawnHash,
            'consented_at' => $consentedAt,
            'consent_text' => T8_CONTRACT_SIGNING_CONSENT_TEXT,
            'signed_at' => $signedAt,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
        $signatureId = (int) $pdo->lastInsertId();
        $inviteUpdate = $pdo->prepare(
            "UPDATE team8_contract_signing_invites SET status = 'signed', used_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'pending'"
        );
        $inviteUpdate->execute(['id' => (int) $invite['invite_id']]);
        if ($inviteUpdate->rowCount() !== 1) {
            throw new DomainException('This signing link has already been used.');
        }
        $pdo->prepare(
            'UPDATE team8_contract_signing_otps SET verified_at = CURRENT_TIMESTAMP, consumed_at = CURRENT_TIMESTAMP
             WHERE id = :id AND consumed_at IS NULL'
        )->execute(['id' => (int) $challenge['id']]);

        $pdo->prepare(
            "UPDATE team8_contracts SET status = 'partially_signed'
             WHERE id = :id AND status IN ('pending_signature', 'partially_signed')"
        )->execute(['id' => (int) $invite['contract_id']]);
        $metadata = json_encode([
            'party_id' => (int) $invite['party_id'],
            'signature_id' => $signatureId,
            'signature_version_no' => (int) $document['version_no'],
            'document_hash' => (string) $document['document_hash'],
            'signature_method' => $method,
        ], JSON_THROW_ON_ERROR);
        $pdo->prepare(
            "INSERT INTO team8_contract_events
             (contract_id, actor_id, event_type, description, metadata)
             VALUES (:contract_id, NULL, 'PARTY_SIGNATURE_RECORDED', 'An involved party signed the frozen contract version.', :metadata)"
        )->execute(['contract_id' => (int) $invite['contract_id'], 'metadata' => $metadata]);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return [
            'signature_id' => $signatureId,
            'contract_id' => (int) $invite['contract_id'],
            'party_id' => (int) $invite['party_id'],
            'signature_version_no' => (int) $document['version_no'],
            'document_hash' => (string) $document['document_hash'],
            'signature_method' => $method,
            'signed_at' => $signedAt,
        ];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($drawnPath !== null && defined('T8_SIGNATURE_STORAGE_DIR') && T8_SIGNATURE_STORAGE_DIR !== '') {
            $storedFile = rtrim((string) T8_SIGNATURE_STORAGE_DIR, '/\\') . DIRECTORY_SEPARATOR . $drawnPath;
            if (is_file($storedFile)) {
                unlink($storedFile);
            }
        }
        throw $e;
    }
}

function t8_contract_revoke_signing_invites_for_revision(PDO $pdo, int $contractId): void
{
    $pdo->prepare(
        "UPDATE team8_contract_signing_invites SET status = 'revoked'
         WHERE contract_id = :contract_id AND status IN ('pending', 'delivery_failed', 'signed')"
    )->execute(['contract_id' => $contractId]);
    $pdo->prepare(
        'UPDATE team8_contract_signing_otps SET consumed_at = CURRENT_TIMESTAMP
         WHERE invite_id IN (SELECT id FROM team8_contract_signing_invites WHERE contract_id = :contract_id)
           AND consumed_at IS NULL'
    )->execute(['contract_id' => $contractId]);
}

function t8_contract_signing_party_statuses(PDO $pdo, int $contractId): array
{
    $partyStmt = $pdo->prepare(
        "SELECT DISTINCT p.id AS party_id, COALESCE(NULLIF(p.trade_name, ''), p.name) AS party_name
         FROM team8_contract_parties cp JOIN team8_parties p ON p.id = cp.party_id
         WHERE cp.contract_id = :contract_id ORDER BY party_name"
    );
    $partyStmt->execute(['contract_id' => $contractId]);
    $document = t8_contract_frozen_document($pdo, $contractId);
    $result = [];
    $inviteStmt = $pdo->prepare(
        'SELECT status, sent_at, expires_at FROM team8_contract_signing_invites
         WHERE contract_id = :contract_id AND party_id = :party_id ORDER BY id DESC LIMIT 1'
    );
    foreach ($partyStmt->fetchAll(PDO::FETCH_ASSOC) as $party) {
        $partyId = (int) $party['party_id'];
        $inviteStmt->execute(['contract_id' => $contractId, 'party_id' => $partyId]);
        $invite = $inviteStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $signed = t8_contract_party_has_valid_signature($pdo, $contractId, $partyId, $document);
        $status = $signed ? 'signed' : (string) ($invite['status'] ?? 'not_sent');
        if (!$signed && $status === 'pending' && strtotime((string) ($invite['expires_at'] ?? '')) <= time()) {
            $status = 'expired';
        }
        $result[] = [
            'party_id' => $partyId,
            'party_name' => (string) $party['party_name'],
            'signing_status' => $status,
            'sent_at' => $invite['sent_at'] ?? null,
        ];
    }
    return $result;
}
