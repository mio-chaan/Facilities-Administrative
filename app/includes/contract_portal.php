<?php
declare(strict_types=1);

function t8_contract_review_url_is_allowed(string $baseUrl, string $appEnv): bool
{
    $urlParts = parse_url($baseUrl);
    if (!is_array($urlParts) || isset($urlParts['user']) || isset($urlParts['pass'])) {
        return false;
    }

    $scheme = strtolower((string) ($urlParts['scheme'] ?? ''));
    $reviewHost = strtolower(trim((string) ($urlParts['host'] ?? ''), '[]'));
    if ($reviewHost === '') {
        return false;
    }

    $hostIsAddress = filter_var($reviewHost, FILTER_VALIDATE_IP) !== false;
    $hostIsPublicIp = filter_var($reviewHost, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    $urlIsPublicHttps = $scheme === 'https'
        && !in_array($reviewHost, ['localhost', '127.0.0.1', '::1'], true)
        && (!$hostIsAddress || $hostIsPublicIp);

    $isLoopbackAddress = false;
    if (filter_var($reviewHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $isLoopbackAddress = (int) explode('.', $reviewHost, 2)[0] === 127;
    } elseif (filter_var($reviewHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $isLoopbackAddress = inet_pton($reviewHost) === str_repeat("\0", 15) . "\1";
    }
    $urlIsLocal = $appEnv === 'local'
        && in_array($scheme, ['http', 'https'], true)
        && ($reviewHost === 'localhost' || $isLoopbackAddress);

    return $urlIsPublicHttps || $urlIsLocal;
}

function t8_contract_get_party_review_statuses(PDO $pdo, int $contractId): array
{
    $stmt = $pdo->prepare(
        "SELECT p.id AS party_id, COALESCE(NULLIF(p.trade_name, ''), p.name) AS party_name,
                i.status, i.response_feedback, i.meeting_details, i.created_at AS invited_at
         FROM (
             SELECT DISTINCT party_id
             FROM team8_contract_parties
             WHERE contract_id = :contract_id
         ) cp
         JOIN team8_parties p ON p.id = cp.party_id
         LEFT JOIN team8_contract_portal_invites i
           ON i.id = (
               SELECT MAX(i2.id)
               FROM team8_contract_portal_invites i2
               WHERE i2.contract_id = :invite_contract_id AND i2.party_id = cp.party_id
           )
         ORDER BY party_name"
    );
    $stmt->execute(['contract_id' => $contractId, 'invite_contract_id' => $contractId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function t8_contract_parties_have_accepted_latest_review(PDO $pdo, int $contractId): bool
{
    $partyReviews = t8_contract_get_party_review_statuses($pdo, $contractId);
    if ($partyReviews === []) {
        return false;
    }
    foreach ($partyReviews as $partyReview) {
        if (($partyReview['status'] ?? null) !== 'accepted') {
            return false;
        }
    }
    return true;
}

function t8_contract_record_external_negotiation_request(
    PDO $pdo,
    int $contractId,
    int $inviteId,
    string $feedback,
    string $meetingDetails
): int {
    $feedback = trim($feedback);
    $meetingDetails = trim($meetingDetails);
    if ($contractId <= 0 || $inviteId <= 0 || $feedback === '') {
        throw new InvalidArgumentException('A valid invitation and negotiation explanation are required.');
    }

    $pdo->beginTransaction();
    try {
        $inviteStmt = $pdo->prepare(
            "SELECT i.id, i.party_id, c.status AS contract_status
             FROM team8_contract_portal_invites i
             JOIN team8_contracts c ON c.id = i.contract_id
             WHERE i.id = :invite_id AND i.contract_id = :contract_id
               AND i.status = 'pending' AND i.expires_at > CURRENT_TIMESTAMP
               AND c.deleted_at IS NULL
               AND c.status IN ('under_review', 'negotiation')
               AND NOT EXISTS (
                   SELECT 1 FROM team8_contract_portal_invites newer
                   WHERE newer.contract_id = i.contract_id
                     AND newer.party_id = i.party_id AND newer.id > i.id
               )
             FOR UPDATE"
        );
        $inviteStmt->execute(['invite_id' => $inviteId, 'contract_id' => $contractId]);
        $invite = $inviteStmt->fetch(PDO::FETCH_ASSOC);
        if (!$invite) {
            throw new DomainException('This party review invitation is no longer active.');
        }

        $versionStmt = $pdo->prepare(
            'SELECT GREATEST(
                (SELECT COALESCE(MAX(version_no), 0) FROM team8_contract_history WHERE contract_id = :history_contract_id),
                (SELECT COALESCE(MAX(version_no), 0) FROM team8_contract_negotiations WHERE contract_id = :negotiation_contract_id)
             ) + 1'
        );
        $versionStmt->execute([
            'history_contract_id' => $contractId,
            'negotiation_contract_id' => $contractId,
        ]);
        $versionNo = (int) $versionStmt->fetchColumn();

        $proposalStmt = $pdo->prepare(
            "INSERT INTO team8_contract_negotiations
             (contract_id, version_no, proposed_by, proposed_party_id, change_summary, proposed_data,
              external_meeting_details, status, source, created_at)
             VALUES (:contract_id, :version_no, NULL, :party_id, :change_summary, '{}',
                     :meeting_details, 'pending', 'external', CURRENT_TIMESTAMP)"
        );
        $proposalStmt->execute([
            'contract_id' => $contractId,
            'version_no' => $versionNo,
            'party_id' => (int) $invite['party_id'],
            'change_summary' => $feedback,
            'meeting_details' => $meetingDetails !== '' ? $meetingDetails : null,
        ]);
        $proposalId = (int) $pdo->lastInsertId();

        $updateInvite = $pdo->prepare(
            "UPDATE team8_contract_portal_invites
             SET status = 'negotiation_requested', response_feedback = :feedback,
                 meeting_details = :meeting_details, used_at = CURRENT_TIMESTAMP
             WHERE id = :invite_id AND status = 'pending'"
        );
        $updateInvite->execute([
            'feedback' => $feedback,
            'meeting_details' => $meetingDetails !== '' ? $meetingDetails : null,
            'invite_id' => $inviteId,
        ]);
        if ($updateInvite->rowCount() !== 1) {
            throw new DomainException('This party review invitation has already been answered.');
        }

        $pdo->prepare(
            "UPDATE team8_contracts SET status = 'negotiation', updated_at = CURRENT_TIMESTAMP
             WHERE id = :contract_id AND status = 'under_review'"
        )->execute(['contract_id' => $contractId]);

        $pdo->commit();
        return $proposalId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function t8_contract_send_involved_party_invites(PDO $pdo, int $contractId, int $actorId): array
{
    $contractStmt = $pdo->prepare(
        "SELECT id, contract_number, title FROM team8_contracts
         WHERE id = :id AND status = 'under_review' AND deleted_at IS NULL LIMIT 1"
    );
    $contractStmt->execute(['id' => $contractId]);
    $contract = $contractStmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        throw new RuntimeException('The contract is not available for external review.');
    }

    $partiesStmt = $pdo->prepare(
        "SELECT DISTINCT p.id AS party_id, COALESCE(NULLIF(p.trade_name, ''), p.name) AS party_name, p.contact_email
         FROM team8_contract_parties cp
         JOIN team8_parties p ON p.id = cp.party_id
         WHERE cp.contract_id = :contract_id
         ORDER BY party_name"
    );
    $partiesStmt->execute(['contract_id' => $contractId]);
    $parties = $partiesStmt->fetchAll(PDO::FETCH_ASSOC);
    $reviewBaseUrl = rtrim(APP_URL, '/');

    $result = ['sent' => 0, 'failed' => [], 'eligible' => 0];
    foreach ($parties as $party) {
        $partyName = trim((string) ($party['party_name'] ?? ''));
        if ($partyName === '') {
            $partyName = 'Party #' . (int) $party['party_id'];
        }
        $email = trim((string) ($party['contact_email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $result['failed'][] = $partyName . ' (missing or invalid email address)';
            continue;
        }
        $appEnv = defined('APP_ENV') ? APP_ENV : '';
        if (!t8_contract_review_url_is_allowed($reviewBaseUrl, $appEnv)) {
            throw new RuntimeException('APP_URL must be an HTTP(S) localhost/loopback URL in local mode or an externally reachable HTTPS address otherwise.');
        }

        $result['eligible']++;
        $token = bin2hex(random_bytes(32));
        $inviteStmt = $pdo->prepare(
            "INSERT INTO team8_contract_portal_invites
             (contract_id, party_id, email, party_name, token_hash, status, expires_at, created_by)
             VALUES (:contract_id, :party_id, :email, :party_name, :token_hash, 'pending', :expires_at, :created_by)"
        );
        $inviteStmt->execute([
            'contract_id' => $contractId,
            'party_id' => (int) $party['party_id'],
            'email' => $email,
            'party_name' => $partyName,
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+14 days')),
            'created_by' => $actorId,
        ]);
        $inviteId = (int) $pdo->lastInsertId();
        $reviewUrl = $reviewBaseUrl . '/contract_review.php?token=' . rawurlencode($token);
        $contractTitle = (string) $contract['title'];
        $contractNumber = (string) $contract['contract_number'];
        $safePartyName = htmlspecialchars($partyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeContractTitle = htmlspecialchars($contractTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeReviewUrl = htmlspecialchars($reviewUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $subject = 'Review invitation: ' . $contractNumber . ' - ' . $contractTitle;
        $htmlBody = '<p>Hello ' . $safePartyName . ',</p>'
            . '<p>You are invited to review contract <strong>' . htmlspecialchars($contractNumber, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</strong>: ' . $safeContractTitle . '.</p>'
            . '<p><a href="' . $safeReviewUrl . '">Open the secure contract review</a></p>'
            . '<p>This one-time invitation link expires in 14 days. Do not forward it.</p>';
        $textBody = "Hello {$partyName},\n\nYou are invited to review contract {$contractNumber}: {$contractTitle}.\n\n"
            . "Open the secure contract review: {$reviewUrl}\n\nThis invitation link expires in 14 days. Do not forward it.";

        try {
            t8_gmail_dispatch([$email], $subject, $htmlBody, $textBody);
        } catch (Throwable $e) {
            $pdo->prepare(
                "UPDATE team8_contract_portal_invites SET status = 'expired'
                 WHERE id = :id AND status = 'pending'"
            )->execute(['id' => $inviteId]);
            t8_gmail_diagnostic_log(
                'Contract Portal Invite Delivery Failure',
                'Contract #' . $contractId . ', party #' . (int) $party['party_id'] . ': ' . $e->getMessage()
            );
            $result['failed'][] = $partyName;
            continue;
        }

        $pdo->prepare(
            "UPDATE team8_contract_portal_invites SET status = 'expired'
             WHERE contract_id = :contract_id AND party_id = :party_id
               AND status = 'pending' AND id <> :invite_id"
        )->execute([
            'contract_id' => $contractId,
            'party_id' => (int) $party['party_id'],
            'invite_id' => $inviteId,
        ]);
        $result['sent']++;
    }

    return $result;
}
