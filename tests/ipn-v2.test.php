<?php

declare(strict_types=1);

class Am_Exception_Paysystem extends Exception
{
}

class Am_Paysystem_Abstract
{
    public const STATUS_PRODUCTION = 1;
    public const REPORTS_NOT_RECURRING = 0;

    private array $config;
    private object $di;
    public array $logs = array();

    public function __construct(array $config = array(), ?object $di = null)
    {
        $this->config = $config;
        $this->di = $di ?? new stdClass();
    }

    public function getConfig(string $name)
    {
        return $this->config[$name] ?? null;
    }

    public function getDi(): object
    {
        return $this->di;
    }

    public function logError(string $message, array $context = array()): void
    {
        $this->logs[] = array('error', $message, $context);
    }

    public function logOther(string $message, array $context = array()): void
    {
        $this->logs[] = array('other', $message, $context);
    }
}

class Am_Paysystem_Transaction_Incoming_Thanks
{
}

class Am_Paysystem_Transaction_Incoming
{
    protected object $plugin;
    protected object $request;
    public object $invoice;

    public function __construct(object $plugin, object $request, $response = null, array $invokeArgs = array())
    {
        $this->plugin = $plugin;
        $this->request = $request;
    }

    protected function getPlugin(): object
    {
        return $this->plugin;
    }
}

class Invoice
{
    public const PAID = 1;
    public const CANCELLED = 2;
}

class InvoiceRefund
{
    public const CHARGEBACK = 2;
}

require dirname(__DIR__) . '/payment-gateway-app.php';

final class FakeIpnRequest
{
    private string $body;
    private array $headers;

    public function __construct(string $body, array $headers)
    {
        $this->body = $body;
        $this->headers = $headers;
    }

    public function getRawBody(): string
    {
        return $this->body;
    }

    public function getHeader(string $name)
    {
        return $this->headers[$name] ?? null;
    }
}

final class FakeAmemberDb
{
    private array $trace;

    public function __construct(array &$trace)
    {
        $this->trace =& $trace;
    }

    public function selectCell(string $sql, ...$params)
    {
        if (str_contains($sql, 'GET_LOCK')) {
            $this->trace[] = 'lock';
            return 1;
        }
        if (str_contains($sql, 'RELEASE_LOCK')) {
            $this->trace[] = 'unlock';
            return 1;
        }
        throw new RuntimeException('Unexpected database query in test.');
    }
}

final class FakeAmemberData
{
    private array $values = array();
    private array $trace;

    public function __construct(array &$trace)
    {
        $this->trace =& $trace;
    }

    public function get(string $name)
    {
        return $this->values[$name] ?? null;
    }

    public function set(string $name, $value): void
    {
        $this->values[$name] = $value;
    }

    public function update(): void
    {
        $this->trace[] = 'state';
    }

    public function values(): array
    {
        return $this->values;
    }
}

final class FakeAmemberInvoice
{
    public string $public_id = 'invoice-42';
    public int $status = 0;
    public array $effects = array();
    public array $trace = array();
    private FakeAmemberData $data;

    public function __construct()
    {
        $this->data = new FakeAmemberData($this->trace);
    }

    public function pk(): int
    {
        return 42;
    }

    public function refresh(): void
    {
        $this->trace[] = 'refresh';
    }

    public function data(): FakeAmemberData
    {
        return $this->data;
    }

    public function addPayment($transaction): void
    {
        $this->trace[] = 'payment';
        $this->effects[] = 'payment';
        $this->status = Invoice::PAID;
    }

    public function addVoid($transaction, string $transactionId): void
    {
        $this->trace[] = 'void';
        $this->effects[] = 'void';
        $this->status = 0;
    }

    public function addRefund($transaction, string $transactionId): void
    {
        $this->trace[] = 'refund';
        $this->effects[] = 'refund';
        $this->status = 0;
    }

    public function addChargeback($transaction, string $transactionId): void
    {
        $this->trace[] = 'chargeback';
        $this->effects[] = 'chargeback';
        $this->status = 0;
    }

    public function setCancelled(bool $cancelled): void
    {
        $this->trace[] = 'cancel';
        $this->effects[] = 'cancel';
        $this->status = $cancelled ? Invoice::CANCELLED : 0;
    }

    public function getRefundRecords(): array
    {
        return array();
    }

    public function persistedState(): array
    {
        return $this->data->values();
    }
}

/** @var list<string> $failures */
$failures = array();

function ipnV2AssertSame($expected, $actual, string $message): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures[] = $message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.';
    }
}

function ipnV2AssertTrue(bool $actual, string $message): void
{
    ipnV2AssertSame(true, $actual, $message);
}

function v2Payload(string $deliveryId, int $eventVersion, $status = 1): array
{
    return array(
        'schemaVersion' => 2,
        'deliveryId' => $deliveryId,
        'eventVersion' => $eventVersion,
        'occurredAt' => '2026-07-26T18:30:00Z',
        'id' => 'transaction-123',
        'externalReference' => 'invoice-42',
        'status' => $status,
    );
}

function executeIpn(
    Am_Paysystem_PaymentGatewayApp $plugin,
    FakeAmemberInvoice $invoice,
    array $payload,
    int $timestamp,
    ?string $version,
    ?string $deliveryId
): array {
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $headers = array(
        'X-Signature-Timestamp' => (string)$timestamp,
        'X-Signature-HMAC-SHA256' => hash_hmac(
            'sha256',
            $timestamp . '.' . $body,
            (string)$plugin->getConfig('webhook_secret')
        ),
    );
    if ($version !== null) {
        $headers['X-IPN-Version'] = $version;
    }
    if ($deliveryId !== null) {
        $headers['X-IPN-Delivery-ID'] = $deliveryId;
    }

    $transaction = new Am_Paysystem_Transaction_PaymentGatewayApp(
        $plugin,
        new FakeIpnRequest($body, $headers),
        null,
        array()
    );
    $transaction->invoice = $invoice;

    try {
        $sourceValid = $transaction->validateSource();
        $statusValid = $sourceValid === true && $transaction->validateStatus();
        if (!$statusValid) {
            return array('accepted' => false, 'output' => '');
        }
        http_response_code(200);
        ob_start();
        $transaction->processValidated();
        $output = ob_get_clean();
        return array(
            'accepted' => true,
            'output' => $output,
            'httpStatus' => http_response_code(),
        );
    } catch (Throwable $error) {
        return array(
            'accepted' => false,
            'error' => $error->getMessage(),
            'output' => '',
        );
    }
}

$secret = 'whsec_test_receiver_secret';
$now = time();
$trace = array();
$di = (object)array('db' => new FakeAmemberDb($trace));
$plugin = new Am_Paysystem_PaymentGatewayApp(array('webhook_secret' => $secret), $di);

// A correctly signed v2 event accepts an opaque delivery ID and persists its claim before payment.
$validInvoice = new FakeAmemberInvoice();
$opaqueDeliveryId = 'opaque/delivery+id=2026';
$validResult = executeIpn($plugin, $validInvoice, v2Payload($opaqueDeliveryId, 1), $now, '2', $opaqueDeliveryId);
ipnV2AssertSame(true, $validResult['accepted'], 'A valid v2 request must be accepted.');
ipnV2AssertSame(array('payment'), $validInvoice->effects, 'A valid successful v2 event must apply payment once.');
$statePosition = array_search('state', $validInvoice->trace, true);
$effectPosition = array_search('payment', $validInvoice->trace, true);
ipnV2AssertTrue(
    $statePosition !== false && $effectPosition !== false && $statePosition < $effectPosition,
    'The durable v2 claim must be stored before the payment effect.'
);

// Signed header/body identity mismatches never reach payment handling.
$mismatchInvoice = new FakeAmemberInvoice();
$mismatchResult = executeIpn($plugin, $mismatchInvoice, v2Payload('delivery-body', 1), $now, '2', 'delivery-header');
ipnV2AssertSame(false, $mismatchResult['accepted'], 'A v2 delivery header/body mismatch must be rejected.');
ipnV2AssertSame(array(), $mismatchInvoice->effects, 'A mismatched delivery ID must have no payment effect.');

// A resend has a fresh timestamp/signature but the same signed delivery identity and body.
$duplicateResult = executeIpn(
    $plugin,
    $validInvoice,
    v2Payload($opaqueDeliveryId, 1),
    $now + 1,
    '2',
    $opaqueDeliveryId
);
ipnV2AssertSame(true, $duplicateResult['accepted'], 'An acknowledgement-loss resend must return success.');
ipnV2AssertSame('OK', $duplicateResult['output'], 'A duplicate v2 resend must receive the normal success body.');
ipnV2AssertSame(array('payment'), $validInvoice->effects, 'A duplicate v2 resend must not repeat payment effects.');

// A newer successful event wins; an older failed event is acknowledged without voiding it.
$orderedInvoice = new FakeAmemberInvoice();
$newerResult = executeIpn($plugin, $orderedInvoice, v2Payload('delivery-newer', 2, 1), $now, '2', 'delivery-newer');
$staleResult = executeIpn($plugin, $orderedInvoice, v2Payload('delivery-stale', 1, 2), $now, '2', 'delivery-stale');
ipnV2AssertSame(true, $newerResult['accepted'], 'The newer v2 event must be accepted.');
ipnV2AssertSame(true, $staleResult['accepted'], 'A stale v2 event must be acknowledged successfully.');
ipnV2AssertSame(array('payment'), $orderedInvoice->effects, 'A stale failed event must not regress a paid invoice.');

// Invalid statuses are rejected before deduplication or highest-version state can advance.
$invalidStatusInvoice = new FakeAmemberInvoice();
$invalidStatuses = array(
    'malformed' => null,
    'fractional' => 1.5,
    'numeric-string' => '1',
    'unsupported' => 5,
);
foreach ($invalidStatuses as $case => $status) {
    $payload = v2Payload('delivery-invalid-' . $case, 10);
    if ($case === 'malformed') {
        unset($payload['status']);
    } else {
        $payload['status'] = $status;
    }
    $result = executeIpn($plugin, $invalidStatusInvoice, $payload, $now, '2', $payload['deliveryId']);
    ipnV2AssertSame(false, $result['accepted'], 'The ' . $case . ' v2 status must be rejected.');
}
$validAfterInvalid = executeIpn(
    $plugin,
    $invalidStatusInvoice,
    v2Payload('delivery-valid-after-invalid', 1, 1),
    $now,
    '2',
    'delivery-valid-after-invalid'
);
ipnV2AssertSame(true, $validAfterInvalid['accepted'], 'Rejected statuses must not block a later valid lower event version.');
ipnV2AssertSame(array('payment'), $invalidStatusInvoice->effects, 'Rejected statuses must cause no effects or state advance.');

// Version metadata and event envelope fields are validated strictly.
$missingVersionInvoice = new FakeAmemberInvoice();
$missingVersionResult = executeIpn(
    $plugin,
    $missingVersionInvoice,
    v2Payload('delivery-version', 1),
    $now,
    null,
    'delivery-version'
);
ipnV2AssertSame(false, $missingVersionResult['accepted'], 'A schema-v2 body without X-IPN-Version must not downgrade to v1.');

$invalidEventInvoice = new FakeAmemberInvoice();
$invalidEvent = v2Payload('delivery-event', 1);
$invalidEvent['eventVersion'] = 0;
$invalidEventResult = executeIpn($plugin, $invalidEventInvoice, $invalidEvent, $now, '2', 'delivery-event');
ipnV2AssertSame(false, $invalidEventResult['accepted'], 'A non-positive v2 eventVersion must be rejected.');

$invalidDateInvoice = new FakeAmemberInvoice();
$invalidDate = v2Payload('delivery-date', 1);
$invalidDate['occurredAt'] = '2026-02-30T18:30:00Z';
$invalidDateResult = executeIpn($plugin, $invalidDateInvoice, $invalidDate, $now, '2', 'delivery-date');
ipnV2AssertSame(false, $invalidDateResult['accepted'], 'An impossible occurredAt date must be rejected.');

$schemaMismatchInvoice = new FakeAmemberInvoice();
$schemaMismatch = v2Payload('delivery-schema', 1);
$schemaMismatch['schemaVersion'] = 1;
$schemaMismatchResult = executeIpn($plugin, $schemaMismatchInvoice, $schemaMismatch, $now, '2', 'delivery-schema');
ipnV2AssertSame(false, $schemaMismatchResult['accepted'], 'X-IPN-Version and schemaVersion must both equal v2.');

// The bounded migration path still accepts the existing signed v1 shape.
$v1Invoice = new FakeAmemberInvoice();
$v1Result = executeIpn(
    $plugin,
    $v1Invoice,
    array('id' => 'transaction-v1', 'externalReference' => 'invoice-42', 'status' => 1),
    $now,
    null,
    null
);
ipnV2AssertSame(true, $v1Result['accepted'], 'A correctly signed legacy v1 request must remain accepted.');
ipnV2AssertSame(array('payment'), $v1Invoice->effects, 'The retained v1 path must preserve payment behavior.');
ipnV2AssertSame(array(), $v1Invoice->persistedState(), 'The v1 compatibility path must remain isolated from v2 state.');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "aMember IPN v2 receiver contract: PASS\n";
