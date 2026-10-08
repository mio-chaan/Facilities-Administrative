<?php
declare(strict_types=1);

define('APP_ENV', 'local');
define('APP_URL', 'http://localhost:8000');

final class ContractInviteStatement extends PDOStatement
{
    private ContractInvitePdo $pdo;
    private string $sql;
    private array $rows = [];

    public function __construct(ContractInvitePdo $pdo, string $sql)
    {
        $this->pdo = $pdo;
        $this->sql = $sql;
    }

    public function execute(?array $params = null): bool
    {
        $params ??= [];
        if (str_contains($this->sql, 'FROM team8_contracts')) {
            $this->rows = [['id' => 7, 'contract_number' => 'CON-2026-00007', 'title' => 'Service Agreement']];
        } elseif (str_contains($this->sql, 'FROM team8_contract_parties')) {
            $this->rows = [
                ['party_id' => 11, 'party_name' => 'Alpha Ltd', 'contact_email' => 'alpha@example.test'],
                ['party_id' => 12, 'party_name' => 'No Email Ltd', 'contact_email' => ''],
                ['party_id' => 13, 'party_name' => 'Beta Ltd', 'contact_email' => 'beta@example.test'],
            ];
        } elseif (str_starts_with(trim($this->sql), 'INSERT INTO team8_contract_portal_invites')) {
            $this->pdo->lastId++;
            $this->pdo->invites[] = ['id' => $this->pdo->lastId, 'status' => 'pending', 'params' => $params];
        } elseif (str_contains($this->sql, 'UPDATE team8_contract_portal_invites')
            && isset($params['id'])) {
            foreach ($this->pdo->invites as &$invite) {
                if ($invite['id'] === (int) $params['id']) {
                    $invite['status'] = 'expired';
                }
            }
            unset($invite);
        } else {
            $this->pdo->updates[] = ['sql' => $this->sql, 'params' => $params];
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->rows[0] ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }
}

final class ContractInvitePdo extends PDO
{
    public array $invites = [];
    public array $updates = [];
    public int $lastId = 0;

    public function __construct() {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new ContractInviteStatement($this, $query);
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return (string) $this->lastId;
    }
}

$GLOBALS['sentEmails'] = [];
$GLOBALS['mailDiagnostics'] = [];

function t8_gmail_dispatch(array $recipients, string $subject, string $htmlBody, string $textBody, array $attachments = [], ?string $diagnosticContext = null): void
{
    if ($recipients[0] === 'beta@example.test') {
        throw new RuntimeException('SMTP delivery failed.');
    }
    $GLOBALS['sentEmails'][] = ['recipients' => $recipients, 'text' => $textBody];
}

function t8_gmail_diagnostic_log(string $context, string $detail): void
{
    $GLOBALS['mailDiagnostics'][] = [$context, $detail];
}

require_once __DIR__ . '/../app/includes/contract_portal.php';

foreach (['http://localhost:8000', 'http://127.0.0.1:8000', 'http://127.23.4.5:8000', 'http://[::1]:8000'] as $localUrl) {
    if (!t8_contract_review_url_is_allowed($localUrl, 'local')) {
        throw new RuntimeException('Local mode must allow localhost and loopback review URLs.');
    }
}
if (!t8_contract_review_url_is_allowed('https://facilities.example.test', 'production')
    || t8_contract_review_url_is_allowed('http://localhost:8000', 'production')
    || t8_contract_review_url_is_allowed('http://192.168.1.2:8000', 'local')
    || t8_contract_review_url_is_allowed('https://127.0.0.1', 'production')) {
    throw new RuntimeException('Review URLs must remain public HTTPS outside local mode.');
}

$pdo = new ContractInvitePdo();
$result = t8_contract_send_involved_party_invites($pdo, 7, 3);

if ($result['sent'] !== 1 || $result['eligible'] !== 2
    || $result['failed'] !== ['No Email Ltd (missing or invalid email address)', 'Beta Ltd']) {
    throw new RuntimeException('Invitation sender did not report successful and failed recipients correctly.');
}
if (count($pdo->invites) !== 2 || count($GLOBALS['sentEmails']) !== 1) {
    throw new RuntimeException('Invitation sender did not persist and send only eligible invitations.');
}

preg_match('/contract_review\.php\?token=([a-f0-9]{64})/', $GLOBALS['sentEmails'][0]['text'], $matches);
$sentToken = $matches[1] ?? '';
if ($sentToken === '' || !hash_equals(hash('sha256', $sentToken), $pdo->invites[0]['params']['token_hash'])) {
    throw new RuntimeException('Invitation token must be random in the email and stored only as a hash.');
}
if ($pdo->invites[1]['params']['token_hash'] === $sentToken
    || !str_contains($GLOBALS['sentEmails'][0]['text'], 'http://localhost:8000/contract_review.php')) {
    throw new RuntimeException('Invitation links must use the configured local URL and unique tokens.');
}
if ($pdo->invites[0]['status'] !== 'pending' || $pdo->invites[1]['status'] !== 'expired') {
    throw new RuntimeException('Failed delivery must not leave a usable invitation.');
}
if ($GLOBALS['mailDiagnostics'] === []) {
    throw new RuntimeException('Failed mail delivery must be logged for diagnosis.');
}

fwrite(STDOUT, "Contract portal invitation checks passed.\n");
