<?php
declare(strict_types=1);

class ExternalReviewStatement extends PDOStatement
{
    private ExternalReviewPdo $pdo;
    private string $sql;
    private array $rows = [];

    public function __construct(ExternalReviewPdo $pdo, string $sql)
    {
        $this->pdo = $pdo;
        $this->sql = $sql;
    }

    public function execute(?array $params = null): bool
    {
        $params ??= [];
        $this->rows = [];
        $this->pdo->rowCount = 0;
        if (str_contains($this->sql, 'SELECT p.id AS party_id')) {
            $this->rows = $this->pdo->partyReviews;
        } elseif (str_contains($this->sql, 'SELECT i.id, i.party_id, c.status AS contract_status')) {
            $this->rows = [$this->pdo->activeInvite];
        } elseif (str_contains($this->sql, 'SELECT GREATEST(')) {
            $this->rows = [[4]];
        } elseif (str_starts_with(trim($this->sql), 'INSERT INTO team8_contract_negotiations')) {
            $this->pdo->lastId = 51;
            $this->pdo->proposalParams = $params;
            $this->pdo->proposalSql = $this->sql;
        } elseif (str_contains($this->sql, 'UPDATE team8_contract_portal_invites')) {
            $this->pdo->inviteUpdateParams = $params;
            $this->pdo->rowCount = 1;
        } elseif (str_contains($this->sql, "UPDATE team8_contracts SET status = 'negotiation'")) {
            $this->pdo->contractMovedToNegotiation = true;
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

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->rows[0][$column] ?? false;
    }

    public function rowCount(): int
    {
        return $this->pdo->rowCount;
    }
}

class ExternalReviewPdo extends PDO
{
    public array $partyReviews = [];
    public array $activeInvite = ['id' => 24, 'party_id' => 9, 'contract_status' => 'under_review'];
    public array $proposalParams = [];
    public string $proposalSql = '';
    public array $inviteUpdateParams = [];
    public int $lastId = 0;
    public int $rowCount = 0;
    public bool $transaction = false;
    public bool $contractMovedToNegotiation = false;

    public function __construct() {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new ExternalReviewStatement($this, $query);
    }

    public function beginTransaction(): bool
    {
        $this->transaction = true;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }

    public function commit(): bool
    {
        $this->transaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->transaction = false;
        return true;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return (string) $this->lastId;
    }
}

$GLOBALS['sentEmails'] = [];
$GLOBALS['mailDiagnostics'] = [];
function t8_gmail_dispatch(array $recipients, string $subject, string $htmlBody, string $textBody, array $attachments = [], ?string $diagnosticContext = null): void {}
function t8_gmail_diagnostic_log(string $context, string $detail): void {}
require_once __DIR__ . '/../app/includes/contract_portal.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$pdo = new ExternalReviewPdo();
$pdo->partyReviews = [
    ['party_id' => 1, 'party_name' => 'Alpha Ltd', 'status' => 'accepted'],
    ['party_id' => 2, 'party_name' => 'Beta Ltd', 'status' => 'accepted'],
];
$assert(t8_contract_parties_have_accepted_latest_review($pdo, 12), 'Every linked party accepting the latest review should allow approval.');

foreach (['negotiation_requested', 'rejected', 'pending', 'expired', null] as $nonAcceptingStatus) {
    $pdo->partyReviews[1]['status'] = $nonAcceptingStatus;
    $assert(
        !t8_contract_parties_have_accepted_latest_review($pdo, 12),
        'A negotiation request, rejection, pending or expired review, or missing invitation must block approval.'
    );
}
$pdo->partyReviews = [];
$assert(!t8_contract_parties_have_accepted_latest_review($pdo, 12), 'A contract with no linked parties must not pass the unanimous review gate.');

$pdo = new ExternalReviewPdo();
$proposalId = t8_contract_record_external_negotiation_request(
    $pdo,
    12,
    24,
    'Please clarify the delivery schedule.',
    'Available Tuesday morning.'
);
$assert($proposalId === 51, 'External negotiation requests should return their proposal history identifier.');
$assert($pdo->proposalParams['party_id'] === 9 && $pdo->proposalParams['change_summary'] === 'Please clarify the delivery schedule.', 'The external proposal must record its party and explanation.');
$assert($pdo->proposalParams['meeting_details'] === 'Available Tuesday morning.', 'The external proposal must preserve optional meeting details.');
$assert(str_contains($pdo->proposalSql, "'{}'"), 'An external request must not imply direct edits to contract fields.');
$assert($pdo->inviteUpdateParams['feedback'] === 'Please clarify the delivery schedule.', 'The invitation must preserve the external response.');
$assert($pdo->contractMovedToNegotiation && !$pdo->transaction, 'A negotiation request must move the contract into negotiation and commit.');

fwrite(STDOUT, "External contract review checks passed.\n");
