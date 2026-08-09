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

    public function getReceiptId()
    {
        return $this->getUniqId();
    }
}

class Invoice
{
    public const PAID = 1;
    public const CANCELLED = 2;
}

class InvoiceRefund
{
    public const REFUND = 0;
    public const CHARGEBACK = 1;
    public const VOID = 2;
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
    public int $paymentFailuresRemaining = 0;
    public int $postEffectRefreshFailuresRemaining = 0;
    public bool $preservePendingStatusAfterPayment = false;
    public bool $preservePaidStatusAfterRefund = false;
    private array $paymentRecords = array();
    private array $refundRecords = array();
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
        if ($this->effects && $this->postEffectRefreshFailuresRemaining > 0) {
            $this->postEffectRefreshFailuresRemaining--;
            throw new RuntimeException('simulated post-effect refresh failure');
        }
    }

    public function data(): FakeAmemberData
    {
        return $this->data;
    }

    public function addPayment($transaction): void
    {
        $this->trace[] = 'payment';
        if ($this->paymentFailuresRemaining > 0) {
            $this->paymentFailuresRemaining--;
            throw new RuntimeException('simulated payment effect failure');
        }
        $this->effects[] = 'payment';
        $this->paymentRecords[] = (object)array(
            'receipt_id' => $transaction->getReceiptId(),
            'transaction_id' => $transaction->getUniqId(),
        );
        if (!$this->preservePendingStatusAfterPayment) {
            $this->status = Invoice::PAID;
        }
    }

    public function addVoid($transaction, string $transactionId): void
    {
        $this->trace[] = 'void';
        $this->effects[] = 'void';
        $this->refundRecords[] = (object)array(
            'refund_type' => InvoiceRefund::VOID,
            'receipt_id' => $transaction->getReceiptId(),
            'transaction_id' => $transaction->getUniqId(),
            'original_receipt_id' => $transactionId,
        );
        $this->status = 0;
    }

    public function addRefund($transaction, string $transactionId): void
    {
        $this->trace[] = 'refund';
        $this->effects[] = 'refund';
        $this->refundRecords[] = (object)array(
            'refund_type' => InvoiceRefund::REFUND,
            'receipt_id' => $transaction->getReceiptId(),
            'transaction_id' => $transaction->getUniqId(),
            'original_receipt_id' => $transactionId,
        );
        if (!$this->preservePaidStatusAfterRefund) {
            $this->status = 0;
        }
    }

    public function addChargeback($transaction, string $transactionId): void
    {
        $this->trace[] = 'chargeback';
        $this->effects[] = 'chargeback';
        $this->refundRecords[] = (object)array(
            'refund_type' => InvoiceRefund::CHARGEBACK,
            'receipt_id' => $transaction->getReceiptId(),
            'transaction_id' => $transaction->getUniqId(),
            'original_receipt_id' => $transactionId,
        );
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
        return $this->refundRecords;
    }

    public function getPaymentRecords(): array
    {
        return $this->paymentRecords;
    }

    public function seedPaymentReceipt(string $receiptId, string $transactionId): void
    {
        $this->paymentRecords[] = (object)array(
            'receipt_id' => $receiptId,
            'transaction_id' => $transactionId,
        );
    }

    public function seedRefundReceipt(int $refundType, string $receiptId, string $transactionId): void
    {
        $this->refundRecords[] = (object)array(
            'refund_type' => $refundType,
            'receipt_id' => $receiptId,
            'transaction_id' => $transactionId,
            'original_receipt_id' => $receiptId,
        );
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

function persistedV2State(FakeAmemberInvoice $invoice): array
{
    $stored = $invoice->persistedState()[PaymentGatewayAppIpnV2State::STATE_DATA_KEY] ?? '';
    $decoded = is_string($stored) ? json_decode($stored, true) : null;
    return is_array($decoded) ? $decoded : array();
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

// A pre-effect claim remains retryable when the payment operation itself fails.
$recoverableInvoice = new FakeAmemberInvoice();
$recoverableInvoice->paymentFailuresRemaining = 1;
$recoverablePayload = v2Payload('delivery-recoverable', 1, 1);
$failedEffectResult = executeIpn(
    $plugin,
    $recoverableInvoice,
    $recoverablePayload,
    $now,
    '2',
    'delivery-recoverable'
);
$recoveredEffectResult = executeIpn(
    $plugin,
    $recoverableInvoice,
    $recoverablePayload,
    $now + 1,
    '2',
    'delivery-recoverable'
);
$recoveredDuplicateResult = executeIpn(
    $plugin,
    $recoverableInvoice,
    $recoverablePayload,
    $now + 2,
    '2',
    'delivery-recoverable'
);
ipnV2AssertSame(false, $failedEffectResult['accepted'], 'A failed payment effect must not be acknowledged.');
ipnV2AssertSame(true, $recoveredEffectResult['accepted'], 'The same delivery must remain recoverable after its effect fails.');
ipnV2AssertSame(true, $recoveredDuplicateResult['accepted'], 'A recovered delivery must remain duplicate-safe.');
ipnV2AssertSame(
    array('payment'),
    $recoverableInvoice->effects,
    'Recovery must apply the payment exactly once and later retries must not repeat it.'
);

// Out-of-order terminal events stay pending until their payment prerequisite exists.
foreach (array(2 => 'void', 3 => 'refund') as $terminalStatus => $terminalEffect) {
    $outOfOrderInvoice = new FakeAmemberInvoice();
    $terminalDeliveryId = 'delivery-out-of-order-' . $terminalEffect;
    $terminalPayload = v2Payload($terminalDeliveryId, 2, $terminalStatus);
    $terminalBeforePayment = executeIpn(
        $plugin,
        $outOfOrderInvoice,
        $terminalPayload,
        $now,
        '2',
        $terminalDeliveryId
    );
    $pendingState = persistedV2State($outOfOrderInvoice);
    $transactionKey = hash('sha256', 'transaction-123');
    $deliveryKey = hash('sha256', $terminalDeliveryId);

    ipnV2AssertSame(false, $terminalBeforePayment['accepted'], 'An out-of-order ' . $terminalEffect . ' without a payment receipt must remain retryable.');
    ipnV2AssertSame(array(), $outOfOrderInvoice->effects, 'A prerequisite-missing ' . $terminalEffect . ' must have no accounting effect.');
    ipnV2AssertSame('pending', $pendingState['deliveries'][$deliveryKey]['phase'] ?? null, 'A prerequisite-missing ' . $terminalEffect . ' delivery must retain its durable pending claim.');
    ipnV2AssertSame(null, $pendingState['highestEventVersions'][$transactionKey] ?? null, 'A prerequisite-missing ' . $terminalEffect . ' must not advance ordering state.');

    $paymentDeliveryId = 'delivery-before-' . $terminalEffect;
    $paymentPayload = v2Payload($paymentDeliveryId, 1, 1);
    $paymentResult = executeIpn(
        $plugin,
        $outOfOrderInvoice,
        $paymentPayload,
        $now + 1,
        '2',
        $paymentDeliveryId
    );
    $terminalRetry = executeIpn(
        $plugin,
        $outOfOrderInvoice,
        $terminalPayload,
        $now + 2,
        '2',
        $terminalDeliveryId
    );
    $terminalDuplicate = executeIpn(
        $plugin,
        $outOfOrderInvoice,
        $terminalPayload,
        $now + 3,
        '2',
        $terminalDeliveryId
    );
    $appliedState = persistedV2State($outOfOrderInvoice);
    $refundRecords = $outOfOrderInvoice->getRefundRecords();

    ipnV2AssertSame(true, $paymentResult['accepted'], 'The earlier payment must remain processable after an out-of-order ' . $terminalEffect . '.');
    ipnV2AssertSame(true, $terminalRetry['accepted'], 'The ' . $terminalEffect . ' must apply when retried after payment.');
    ipnV2AssertSame(true, $terminalDuplicate['accepted'], 'An applied ' . $terminalEffect . ' retry must be acknowledged as a duplicate.');
    ipnV2AssertSame(array('payment', $terminalEffect), $outOfOrderInvoice->effects, 'Payment followed by retried ' . $terminalEffect . ' must produce accounting exactly once.');
    ipnV2AssertSame(1, count($outOfOrderInvoice->getPaymentRecords()), 'The prerequisite payment must have one durable receipt.');
    ipnV2AssertSame(1, count($refundRecords), 'The retried ' . $terminalEffect . ' must have one durable receipt.');
    ipnV2AssertSame('transaction-123', $refundRecords[0]->original_receipt_id ?? null, 'The retried ' . $terminalEffect . ' must reconcile to the gateway payment receipt.');
    ipnV2AssertSame('applied', $appliedState['deliveries'][$deliveryKey]['phase'] ?? null, 'The retried ' . $terminalEffect . ' delivery must become applied only after its receipt exists.');
    ipnV2AssertSame(2, $appliedState['highestEventVersions'][$transactionKey] ?? null, 'Ordering state must advance after the ' . $terminalEffect . ' effect is durable.');
}

// A committed aMember effect receipt closes the crash window before receiver state can be marked applied.
$paymentReceiptInvoice = new FakeAmemberInvoice();
$paymentReceiptInvoice->preservePendingStatusAfterPayment = true;
$paymentReceiptInvoice->postEffectRefreshFailuresRemaining = 1;
$paymentReceiptPayload = v2Payload('delivery-payment-receipt', 1, 1);
$paymentReceiptFailure = executeIpn(
    $plugin,
    $paymentReceiptInvoice,
    $paymentReceiptPayload,
    $now,
    '2',
    'delivery-payment-receipt'
);
$paymentReceiptRedeliveryPayload = $paymentReceiptPayload;
$paymentReceiptRedeliveryPayload['deliveryId'] = 'delivery-payment-receipt-redelivery';
$paymentReceiptRecovery = executeIpn(
    $plugin,
    $paymentReceiptInvoice,
    $paymentReceiptRedeliveryPayload,
    $now + 1,
    '2',
    'delivery-payment-receipt-redelivery'
);
$paymentReceiptOriginalRetry = executeIpn(
    $plugin,
    $paymentReceiptInvoice,
    $paymentReceiptPayload,
    $now + 2,
    '2',
    'delivery-payment-receipt'
);
ipnV2AssertSame(false, $paymentReceiptFailure['accepted'], 'A post-payment state failure must not be acknowledged.');
ipnV2AssertSame(
    true,
    $paymentReceiptRecovery['accepted'],
    'A committed payment receipt must allow the same event to recover under a replacement delivery ID.'
);
ipnV2AssertSame(true, $paymentReceiptOriginalRetry['accepted'], 'The original pending delivery must remain safely recoverable.');
ipnV2AssertSame(
    array('payment'),
    $paymentReceiptInvoice->effects,
    'Retry after a committed payment receipt must not repeat the payment effect.'
);

$refundReceiptInvoice = new FakeAmemberInvoice();
$refundReceiptInvoice->status = Invoice::PAID;
$refundReceiptInvoice->preservePaidStatusAfterRefund = true;
$refundReceiptInvoice->postEffectRefreshFailuresRemaining = 1;
$refundReceiptPayload = v2Payload('delivery-refund-receipt', 1, 3);
$refundReceiptFailure = executeIpn(
    $plugin,
    $refundReceiptInvoice,
    $refundReceiptPayload,
    $now,
    '2',
    'delivery-refund-receipt'
);
$refundReceiptRecovery = executeIpn(
    $plugin,
    $refundReceiptInvoice,
    $refundReceiptPayload,
    $now + 1,
    '2',
    'delivery-refund-receipt'
);
ipnV2AssertSame(false, $refundReceiptFailure['accepted'], 'A post-refund state failure must not be acknowledged.');
ipnV2AssertSame(true, $refundReceiptRecovery['accepted'], 'A committed refund receipt must allow safe state recovery.');
ipnV2AssertSame(
    array('refund'),
    $refundReceiptInvoice->effects,
    'Retry after a committed refund receipt must not repeat the refund effect.'
);

$chargebackReceiptInvoice = new FakeAmemberInvoice();
$chargebackReceiptInvoice->status = Invoice::PAID;
$chargebackReceiptInvoice->postEffectRefreshFailuresRemaining = 1;
$chargebackReceiptPayload = v2Payload('delivery-chargeback-receipt', 1, 4);
$chargebackReceiptFailure = executeIpn(
    $plugin,
    $chargebackReceiptInvoice,
    $chargebackReceiptPayload,
    $now,
    '2',
    'delivery-chargeback-receipt'
);
$chargebackReceiptRecovery = executeIpn(
    $plugin,
    $chargebackReceiptInvoice,
    $chargebackReceiptPayload,
    $now + 1,
    '2',
    'delivery-chargeback-receipt'
);
ipnV2AssertSame(false, $chargebackReceiptFailure['accepted'], 'A post-chargeback state failure must not be acknowledged.');
ipnV2AssertSame(true, $chargebackReceiptRecovery['accepted'], 'A committed chargeback receipt must allow safe state recovery.');
ipnV2AssertSame(
    array('chargeback'),
    $chargebackReceiptInvoice->effects,
    'Retry after a committed chargeback receipt must not repeat the chargeback effect.'
);
$chargebackReceiptRecords = $chargebackReceiptInvoice->getRefundRecords();
ipnV2AssertSame(
    'transaction-123',
    $chargebackReceiptRecords[0]->original_receipt_id ?? null,
    'A v2 chargeback must reconcile to the canonical gateway payment receipt.'
);

// Replacement deliveries for one transaction/version must preserve the effect semantics claimed first.
$semanticIdentityInvoice = new FakeAmemberInvoice();
$semanticIdentityInvoice->postEffectRefreshFailuresRemaining = 1;
$semanticIdentityPayment = v2Payload('delivery-semantic-payment', 1, 1);
$semanticIdentityPaymentFailure = executeIpn(
    $plugin,
    $semanticIdentityInvoice,
    $semanticIdentityPayment,
    $now,
    '2',
    'delivery-semantic-payment'
);
$semanticIdentityConflict = v2Payload('delivery-semantic-conflict', 1, 2);
$semanticIdentityConflict['occurredAt'] = '2026-07-26T18:31:00Z';
$semanticIdentityConflictResult = executeIpn(
    $plugin,
    $semanticIdentityInvoice,
    $semanticIdentityConflict,
    $now + 1,
    '2',
    'delivery-semantic-conflict'
);
$semanticIdentityEquivalent = v2Payload('delivery-semantic-equivalent', 1, 1);
$semanticIdentityEquivalent['occurredAt'] = '2026-07-26T18:32:00Z';
$semanticIdentityEquivalent['disputeStatus'] = 'lost';
$semanticIdentityEquivalent['chargeback'] = array('status' => 'accepted');
$semanticIdentityEquivalentResult = executeIpn(
    $plugin,
    $semanticIdentityInvoice,
    $semanticIdentityEquivalent,
    $now + 2,
    '2',
    'delivery-semantic-equivalent'
);
ipnV2AssertSame(false, $semanticIdentityPaymentFailure['accepted'], 'A post-payment state failure must leave its semantic event claim pending.');
ipnV2AssertSame(
    false,
    $semanticIdentityConflictResult['accepted'],
    'A replacement delivery must be rejected when its status conflicts with the claimed transaction/event version.'
);
ipnV2AssertSame(
    true,
    $semanticIdentityEquivalentResult['accepted'],
    'An equivalent replacement delivery may recover despite retry-only field and ignored legacy-alias changes.'
);
ipnV2AssertSame(
    array('payment'),
    $semanticIdentityInvoice->effects,
    'A semantic conflict must not apply a void or repeat the committed payment effect.'
);

// Receipt identity is scoped to each v2 event/effect, so valid state recurrence is not cross-suppressed.
$paymentRecurrenceInvoice = new FakeAmemberInvoice();
$paymentRecurrenceSuccessOne = executeIpn(
    $plugin,
    $paymentRecurrenceInvoice,
    v2Payload('delivery-payment-recurrence-1', 1, 1),
    $now,
    '2',
    'delivery-payment-recurrence-1'
);
$paymentRecurrenceVoid = executeIpn(
    $plugin,
    $paymentRecurrenceInvoice,
    v2Payload('delivery-payment-recurrence-2', 2, 2),
    $now + 1,
    '2',
    'delivery-payment-recurrence-2'
);
$paymentRecurrenceSuccessThree = executeIpn(
    $plugin,
    $paymentRecurrenceInvoice,
    v2Payload('delivery-payment-recurrence-3', 3, 1),
    $now + 2,
    '2',
    'delivery-payment-recurrence-3'
);
ipnV2AssertSame(true, $paymentRecurrenceSuccessOne['accepted'], 'The initial successful v2 event must be accepted.');
ipnV2AssertSame(true, $paymentRecurrenceVoid['accepted'], 'The intervening void v2 event must be accepted.');
ipnV2AssertSame(true, $paymentRecurrenceSuccessThree['accepted'], 'A newer successful v2 event after a void must be accepted.');
ipnV2AssertSame(
    array('payment', 'void', 'payment'),
    $paymentRecurrenceInvoice->effects,
    'A v1 success receipt must not suppress the distinct v3 success effect after a v2 void.'
);
$paymentRecurrenceRecords = $paymentRecurrenceInvoice->getPaymentRecords();
ipnV2AssertSame(
    2,
    count($paymentRecurrenceRecords),
    'Each semantically valid recurring payment event must create its own receipt.'
);
ipnV2AssertTrue(
    isset($paymentRecurrenceRecords[0], $paymentRecurrenceRecords[1])
        && $paymentRecurrenceRecords[0]->transaction_id !== $paymentRecurrenceRecords[1]->transaction_id,
    'Different v2 payment event versions must not collide in aMember transaction identity.'
);
foreach ($paymentRecurrenceRecords as $paymentRecord) {
    ipnV2AssertSame(
        'transaction-123',
        $paymentRecord->receipt_id,
        'A v2 payment receipt must retain the canonical gateway transaction identity.'
    );
    ipnV2AssertTrue(
        strlen($paymentRecord->transaction_id) <= 64,
        'A v2 payment transaction identity must fit aMember varchar(64).'
    );
}

$refundRecurrenceInvoice = new FakeAmemberInvoice();
$refundRecurrenceResults = array(
    executeIpn($plugin, $refundRecurrenceInvoice, v2Payload('delivery-refund-recurrence-1', 1, 1), $now, '2', 'delivery-refund-recurrence-1'),
    executeIpn($plugin, $refundRecurrenceInvoice, v2Payload('delivery-refund-recurrence-2', 2, 3), $now + 1, '2', 'delivery-refund-recurrence-2'),
    executeIpn($plugin, $refundRecurrenceInvoice, v2Payload('delivery-refund-recurrence-3', 3, 1), $now + 2, '2', 'delivery-refund-recurrence-3'),
    executeIpn($plugin, $refundRecurrenceInvoice, v2Payload('delivery-refund-recurrence-4', 4, 3), $now + 3, '2', 'delivery-refund-recurrence-4'),
);
foreach ($refundRecurrenceResults as $refundRecurrenceResult) {
    ipnV2AssertSame(true, $refundRecurrenceResult['accepted'], 'Each ordered recurring payment/refund event must be accepted.');
}
ipnV2AssertSame(
    array('payment', 'refund', 'payment', 'refund'),
    $refundRecurrenceInvoice->effects,
    'Distinct recurring v2 payment and refund events must each apply once when the invoice permits recurrence.'
);
$refundRecurrenceRecords = $refundRecurrenceInvoice->getRefundRecords();
ipnV2AssertSame(2, count($refundRecurrenceRecords), 'Each recurring v2 refund event must create its own receipt.');
ipnV2AssertTrue(
    isset($refundRecurrenceRecords[0], $refundRecurrenceRecords[1])
        && $refundRecurrenceRecords[0]->transaction_id !== $refundRecurrenceRecords[1]->transaction_id,
    'Different v2 refund event versions must not collide in aMember transaction identity.'
);
foreach ($refundRecurrenceRecords as $refundRecord) {
    ipnV2AssertSame(
        'transaction-123',
        $refundRecord->receipt_id,
        'A v2 refund receipt must retain the canonical gateway transaction identity.'
    );
    ipnV2AssertSame(
        'transaction-123',
        $refundRecord->original_receipt_id,
        'A v2 refund must reconcile to the canonical gateway payment receipt.'
    );
    ipnV2AssertTrue(
        strlen($refundRecord->transaction_id) <= 64,
        'A v2 refund transaction identity must fit aMember varchar(64).'
    );
}

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

// V2 ordering identity is the canonical typed top-level id, never a legacy alias.
$missingIdInvoice = new FakeAmemberInvoice();
$missingId = v2Payload('delivery-missing-id', 10);
unset($missingId['id']);
$missingId['transactionId'] = 'legacy-alias-one';
$missingIdResult = executeIpn($plugin, $missingIdInvoice, $missingId, $now, '2', 'delivery-missing-id');

$wrongId = v2Payload('delivery-wrong-id', 11);
$wrongId['id'] = 123;
$wrongId['transactionId'] = 'legacy-alias-two';
$wrongIdResult = executeIpn($plugin, $missingIdInvoice, $wrongId, $now, '2', 'delivery-wrong-id');

$oversizedId = v2Payload('delivery-oversized-id', 12);
$oversizedId['id'] = str_repeat('x', 65);
$oversizedIdResult = executeIpn(
    $plugin,
    $missingIdInvoice,
    $oversizedId,
    $now,
    '2',
    'delivery-oversized-id'
);

$canonicalAfterAliases = v2Payload('delivery-canonical-after-aliases', 1);
$canonicalAfterAliases['id'] = 'canonical-transaction';
$canonicalAfterAliasesResult = executeIpn(
    $plugin,
    $missingIdInvoice,
    $canonicalAfterAliases,
    $now,
    '2',
    'delivery-canonical-after-aliases'
);
ipnV2AssertSame(false, $missingIdResult['accepted'], 'A v2 transaction with only a legacy ID alias must be rejected.');
ipnV2AssertSame(false, $wrongIdResult['accepted'], 'A v2 transaction with a non-string top-level id must be rejected.');
ipnV2AssertSame(
    false,
    $oversizedIdResult['accepted'],
    'A v2 transaction ID that cannot fit aMember receipt_id varchar(64) must be rejected.'
);
ipnV2AssertSame(
    true,
    $canonicalAfterAliasesResult['accepted'],
    'Rejected alias-only transactions must not cross-suppress a canonical lower event version.'
);
ipnV2AssertSame(
    array('payment'),
    $missingIdInvoice->effects,
    'Invalid v2 IDs must not cause effects or advance ordering state.'
);

// V2 invoice routing accepts only the bounded canonical top-level externalReference.
$invalidReferenceInvoice = new FakeAmemberInvoice();
$invalidReferences = array(
    'alias-only-nested' => (function (): array {
        $payload = v2Payload('delivery-reference-alias-only', 10);
        unset($payload['externalReference']);
        $payload['chargeback'] = array('externalReference' => 'invoice-42');
        return $payload;
    })(),
    'alias-only-snake-case' => (function (): array {
        $payload = v2Payload('delivery-reference-snake-case', 11);
        unset($payload['externalReference']);
        $payload['external_reference'] = 'invoice-42';
        return $payload;
    })(),
    'hybrid-nested' => (function (): array {
        $payload = v2Payload('delivery-reference-hybrid', 12);
        $payload['chargeback'] = array('externalReference' => 'another-invoice');
        return $payload;
    })(),
    'hybrid-snake-case' => (function (): array {
        $payload = v2Payload('delivery-reference-hybrid-snake-case', 13);
        $payload['external_reference'] = 'another-invoice';
        return $payload;
    })(),
    'typed' => (function (): array {
        $payload = v2Payload('delivery-reference-typed', 14);
        $payload['externalReference'] = 42;
        return $payload;
    })(),
    'blank' => (function (): array {
        $payload = v2Payload('delivery-reference-blank', 15);
        $payload['externalReference'] = '   ';
        return $payload;
    })(),
    'oversized' => (function (): array {
        $payload = v2Payload('delivery-reference-oversized', 16);
        $payload['externalReference'] = str_repeat('r', 65);
        return $payload;
    })(),
);
foreach ($invalidReferences as $case => $payload) {
    $result = executeIpn($plugin, $invalidReferenceInvoice, $payload, $now, '2', $payload['deliveryId']);
    ipnV2AssertSame(false, $result['accepted'], 'The ' . $case . ' v2 external reference must be rejected before invoice routing.');
}
$canonicalReferencePayload = v2Payload('delivery-reference-canonical', 1, 1);
$canonicalReferenceResult = executeIpn(
    $plugin,
    $invalidReferenceInvoice,
    $canonicalReferencePayload,
    $now + 1,
    '2',
    'delivery-reference-canonical'
);
ipnV2AssertSame(true, $canonicalReferenceResult['accepted'], 'Rejected reference aliases and malformed references must not block a valid lower canonical event.');
ipnV2AssertSame(array('payment'), $invalidReferenceInvoice->effects, 'Invalid v2 references must not route or advance receiver state.');

// Legacy dispute aliases in a v2 envelope cannot override the canonical integer status.
$hybridInvoice = new FakeAmemberInvoice();
$hybridPayload = v2Payload('delivery-hybrid', 1, 1);
$hybridPayload['disputeStatus'] = 'lost';
$hybridPayload['chargebackStatus'] = 'accepted';
$hybridPayload['chargeback'] = array(
    'status' => 'open',
    'transactionId' => 'legacy-chargeback-alias',
);
$hybridResult = executeIpn($plugin, $hybridInvoice, $hybridPayload, $now, '2', 'delivery-hybrid');
ipnV2AssertSame(true, $hybridResult['accepted'], 'A valid v2 status remains authoritative in a hybrid payload.');
ipnV2AssertSame(
    array('payment'),
    $hybridInvoice->effects,
    'Legacy dispute aliases must not turn a successful v2 status into a chargeback effect.'
);

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

// V2 receipt reconciliation must not add receipt-based suppression to legacy v1 effects.
$v1PaymentCompatibilityInvoice = new FakeAmemberInvoice();
$v1PaymentCompatibilityInvoice->seedPaymentReceipt('transaction-v1-payment', 'transaction-v1-payment');
$v1PaymentCompatibilityResult = executeIpn(
    $plugin,
    $v1PaymentCompatibilityInvoice,
    array('id' => 'transaction-v1-payment', 'externalReference' => 'invoice-42', 'status' => 1),
    $now,
    null,
    null
);
ipnV2AssertSame(true, $v1PaymentCompatibilityResult['accepted'], 'A legacy v1 payment remains accepted.');
ipnV2AssertSame(
    array('payment'),
    $v1PaymentCompatibilityInvoice->effects,
    'A pre-existing receipt must not change the legacy v1 payment effect gate.'
);

$v1RefundCompatibilityInvoice = new FakeAmemberInvoice();
$v1RefundCompatibilityInvoice->status = Invoice::PAID;
$v1RefundCompatibilityInvoice->seedRefundReceipt(
    InvoiceRefund::REFUND,
    'transaction-v1-refund',
    'transaction-v1-refund'
);
$v1RefundCompatibilityResult = executeIpn(
    $plugin,
    $v1RefundCompatibilityInvoice,
    array('id' => 'transaction-v1-refund', 'externalReference' => 'invoice-42', 'status' => 3),
    $now,
    null,
    null
);
ipnV2AssertSame(true, $v1RefundCompatibilityResult['accepted'], 'A legacy v1 refund remains accepted.');
ipnV2AssertSame(
    array('refund'),
    $v1RefundCompatibilityInvoice->effects,
    'A pre-existing receipt must not change the legacy v1 refund effect gate.'
);

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "aMember IPN v2 receiver contract: PASS\n";
