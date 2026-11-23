<?php

declare(strict_types=1);

class Am_Paysystem_Abstract
{
    public const STATUS_PRODUCTION = 1;
    public const REPORTS_NOT_RECURRING = 0;

    public array $config = array();
    public array $errorLogs = array();
    public array $otherLogs = array();
    private object $di;

    public function __construct()
    {
        $this->di = (object)array('db' => new CheckoutAttemptDbStub());
    }

    public function getConfig($key)
    {
        return $this->config[$key] ?? null;
    }

    public function getDi(): object { return $this->di; }

    public function logError($message, $context = array()): void
    {
        $this->errorLogs[] = array($message, $context);
    }

    public function logOther($message, $context = array()): void
    {
        $this->otherLogs[] = array($message, $context);
    }

    public function getReturnUrl(): string { return 'https://merchant.test/return'; }
    public function getCancelUrl(): string { return 'https://merchant.test/cancel'; }
    public function getPluginUrl($path): string { return 'https://merchant.test/' . $path; }
}

class CheckoutAttemptDbStub
{
    public function selectCell($query, ...$params): int
    {
        return 1;
    }
}

class Am_Paysystem_Transaction_Incoming_Thanks {}
class Am_Paysystem_Transaction_Incoming {}

class Am_HttpResponseStub
{
    public function __construct(private int $status, private string $body) {}
    public function getStatus(): int { return $this->status; }
    public function getBody(): string { return $this->body; }
}

class Am_HttpRequest
{
    public const METHOD_POST = 'POST';
    public static Am_HttpResponseStub $response;
    public function __construct($url, $method) {}
    public function setHeader($name, $value): void {}
    public static array $bodies = array();
    public function setBody($body): void { self::$bodies[] = json_decode($body, true); }
    public function send(): Am_HttpResponseStub { return self::$response; }
}

class Am_Paysystem_Action_Redirect
{
    public function __construct(public string $url) {}
}

class Am_Exception_FatalError extends Exception {}

class CheckoutAttemptDataStub
{
    public array $values = array();
    public int $updates = 0;
    public function get($key) { return $this->values[$key] ?? null; }
    public function set($key, $value): void { $this->values[$key] = $value; }
    public function update(): void { $this->updates++; }
}

require dirname(__DIR__) . '/payment-gateway-app.php';

function expectSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

function expectTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$nestedRawJson = json_encode(array('error' => array(
    'code' => 'CHECKOUT_RESTRICTED_BY_CUSTOMER_HOLD',
    'requestId' => 'request_123',
    'customerRiskHold' => array(
        'id' => 'hold_123',
        'action' => 'allow_provider_types',
        'reason' => 'lost_dispute',
        'allowedProviderTypes' => array('wire', 'wise', 'card', "wire\nunsafe"),
        'allowedProviderIds' => array('provider:one', str_repeat('x', 65)),
    ),
    'message' => 'private backend message',
    'email' => 'secret@example.test',
)));
$context = PaymentGatewayAppApiErrorContext::parse($nestedRawJson, 409);
expectSame('CHECKOUT_RESTRICTED_BY_CUSTOMER_HOLD', $context['code'], 'Nested raw JSON code must be parsed.');
expectSame('request_123', $context['requestId'], 'Nested raw JSON request ID must be parsed.');
expectSame(array('wire', 'wise', 'card'), $context['allowedProviderTypes'], 'Provider values must be sanitized and bounded.');
expectSame(array('provider:one'), $context['allowedProviderIds'], 'Oversized provider IDs must be rejected.');
expectSame('lost_dispute', $context['customerRiskReason'], 'Known risk reasons must be retained.');

$flat = PaymentGatewayAppApiErrorContext::parse('{"code":"CHECKOUT_BLOCKED_BY_CUSTOMER_HOLD","requestId":"request-flat"}', 409);
expectTrue(str_contains(PaymentGatewayAppApiErrorContext::customerMessage($flat, 'fallback'), 'merchant review'), 'Flat raw JSON hold code must use static guidance.');
expectTrue(str_contains(PaymentGatewayAppApiErrorContext::customerMessage($flat, 'fallback'), 'request-flat'), 'Safe request IDs must be shown.');

$lowercase = PaymentGatewayAppApiErrorContext::parse('{"code":"checkout_blocked_by_customer_hold","requestId":"request-lower"}', 409);
expectSame('fallback Request ID: request-lower', PaymentGatewayAppApiErrorContext::customerMessage($lowercase, 'fallback'), 'Customer-hold codes must match exact uppercase values.');

$unsafe = PaymentGatewayAppApiErrorContext::parse(json_encode(array(
    'code' => 'CHECKOUT_BLOCKED_BY_CUSTOMER_HOLD',
    'requestId' => "request\r\nInjected",
    'customerRiskAction' => 'delete_customer',
    'customerRiskReason' => 'free form private note',
    'allowedProviderTypes' => array_fill(0, 30, 'wire'),
)), 409);
expectSame('', $unsafe['requestId'], 'Control characters must invalidate request IDs.');
expectSame('', $unsafe['customerRiskAction'], 'Unknown actions must be discarded.');
expectSame('', $unsafe['customerRiskReason'], 'Free-form reasons must be discarded.');
expectTrue(!array_key_exists('message', PaymentGatewayAppApiErrorContext::logContext($unsafe)), 'Raw backend messages must not be logged.');
$extraContext = PaymentGatewayAppApiErrorContext::logContext($context, array('authorization' => 'Bearer must-not-log'));
expectTrue(!array_key_exists('authorization', $extraContext), 'Caller-supplied fields must still be constrained to the log allowlist.');

$invoice = new class {
    public float $first_total = 10.0;
    public string $currency = 'EUR';
    public string $public_id = 'invoice-1';
    public CheckoutAttemptDataStub $attemptData;
    public function __construct() { $this->attemptData = new CheckoutAttemptDataStub(); }
    public function getEmail(): string { return 'customer@example.test'; }
    public function getItems(): array { return array(); }
    public function data(): CheckoutAttemptDataStub { return $this->attemptData; }
    public function pk(): int { return 1; }
    public function refresh(): void {}
};
$result = new class {
    public $action;
    public function setAction($action): void { $this->action = $action; }
    public function setFailed($message): void { throw new RuntimeException($message); }
};
$plugin = new Am_Paysystem_PaymentGatewayApp();
$plugin->config = array('api_domain' => 'api.example.test', 'site_id' => 'site-1', 'api_key' => 'secret', 'debug_logging' => 0);
Am_HttpRequest::$response = new Am_HttpResponseStub(200, '{"paymentUrl":"https://pay.example.test/session","sessionPublicId":"session-public-attempt-b"}');
$plugin->_process($invoice, null, $result);
expectSame('https://pay.example.test/session', $result->action->url, 'Normal successful checkout must remain unchanged.');
expectSame(
    'session-public-attempt-b',
    $invoice->data()->get(PaymentGatewayAppCheckoutAttempt::STATE_DATA_KEY),
    'The checkout response attempt identity must be persisted on the invoice before redirect.'
);
expectSame(1, $invoice->data()->updates, 'Persisting the attempt identity must durably update invoice data once.');
expectSame(array(), $plugin->errorLogs, 'Debug-off must not write API error logs.');
expectSame(array(), $plugin->otherLogs, 'Debug-off must not write checkout exchange logs.');

$legacyInvoice = clone $invoice;
$legacyInvoice->attemptData = new CheckoutAttemptDataStub();
$legacyResult = new class {
    public $action;
    public function setAction($action): void { $this->action = $action; }
    public function setFailed($message): void { throw new RuntimeException($message); }
};
Am_HttpRequest::$response = new Am_HttpResponseStub(200, '{"paymentUrl":"https://pay.example.test/legacy"}');
$plugin->_process($legacyInvoice, null, $legacyResult);
expectSame('https://pay.example.test/legacy', $legacyResult->action->url, 'A gateway omitting the additive attempt identity must remain compatible.');
expectSame(array(), $legacyInvoice->data()->values, 'An omitted attempt identity must not persist an ambiguous value.');

$invalidAttemptInvoice = clone $invoice;
$invalidAttemptInvoice->attemptData = new CheckoutAttemptDataStub();
$invalidAttemptResult = new class {
    public function setAction($action): void { throw new RuntimeException('Invalid attempt identity must not redirect.'); }
    public function setFailed($message): void {
        if ($message !== 'Payment session creation failed due to an invalid gateway response.') {
            throw new RuntimeException('Malformed attempt identity must use fixed safe guidance.');
        }
    }
};
Am_HttpRequest::$response = new Am_HttpResponseStub(200, '{"paymentUrl":"https://pay.example.test/invalid","sessionPublicId":"invalid attempt identity"}');
$plugin->_process($invalidAttemptInvoice, null, $invalidAttemptResult);
expectSame(array(), $invalidAttemptInvoice->data()->values, 'A malformed checkout-attempt identity must never be persisted.');

$plugin->config['debug_logging'] = 1;
Am_HttpRequest::$response = new Am_HttpResponseStub(409, $nestedRawJson);
try {
    $plugin->_process($invoice, null, $result);
    throw new RuntimeException('Expected customer hold response to fail checkout.');
} catch (Am_Exception_FatalError $error) {
    expectTrue(str_contains($error->getMessage(), 'Only bank transfer payment methods are available'), 'Hold failures must use static customer guidance.');
    expectTrue(!str_contains($error->getMessage(), 'private backend message'), 'Raw backend messages must not reach customers.');
}
expectSame(1, count($plugin->errorLogs), 'Debug-on must log one structured API error.');
$encodedLogs = json_encode(array($plugin->errorLogs, $plugin->otherLogs));
expectTrue(!str_contains($encodedLogs, 'private backend message'), 'Debug logs must exclude backend messages.');
expectTrue(!str_contains($encodedLogs, 'secret@example.test'), 'Debug logs must exclude PII.');
expectTrue(!str_contains($encodedLogs, 'Bearer'), 'Debug logs must exclude credentials.');
expectTrue(strlen($encodedLogs) < 8192, 'Debug log context must remain bounded.');

$oversizedRawJson = json_encode(array(
    'code' => 'CHECKOUT_BLOCKED_BY_CUSTOMER_HOLD',
    'requestId' => 'request-must-not-parse',
    'padding' => str_repeat('x', PaymentGatewayAppApiErrorContext::MAX_JSON_LENGTH),
));
Am_HttpRequest::$response = new Am_HttpResponseStub(409, $oversizedRawJson);
try {
    $plugin->_process($invoice, null, $result);
    throw new RuntimeException('Expected oversized gateway error response to fail checkout.');
} catch (Am_Exception_FatalError $error) {
    expectTrue(str_contains($error->getMessage(), 'unexpected gateway response'), 'Oversized gateway errors must use the generic fallback.');
    expectTrue(!str_contains($error->getMessage(), 'merchant review'), 'Oversized gateway errors must not bypass the raw-response bound.');
    expectTrue(!str_contains($error->getMessage(), 'request-must-not-parse'), 'Oversized gateway errors must not expose unparsed request IDs.');
}

echo "aMember customer risk hold contract: PASS\n";

class AmountTestInvoice
{
    public $first_total = '10.00';
    public string $currency = 'EUR';
    public string $public_id = 'amount-invoice';
    public array $items = array();
    public function getEmail() { return 'buyer@example.test'; }
    public function getItems() { return $this->items; }
}
$plugin->config['pass_items'] = true;
Am_HttpRequest::$response = new Am_HttpResponseStub(200, '{"paymentUrl":"https://pay.example.test/session"}');
foreach (array(array('JPY', '1000', 1000), array('EUR', '10.00', 1000), array('KWD', '1.234', 1234)) as [$currency, $major, $minor]) {
    $invoice = new AmountTestInvoice(); $invoice->currency = $currency; $invoice->first_total = $major;
    $invoice->items = array((object)array('first_total' => $major, 'qty' => 3, 'item_title' => 'Product &amp; Service'));
    $plugin->_process($invoice, null, $result);
    $payload = end(Am_HttpRequest::$bodies);
    expectSame($minor, $payload['amount'], 'Invoice must use currency minor units.');
    expectSame($currency, $payload['currency'], 'Invoice currency must be retained.');
    expectSame(3, array_sum(array_column($payload['items'], 'quantity')), 'Price split must preserve total quantity.');
    $sum = 0; foreach ($payload['items'] as $item) { $sum += $item['quantity'] * $item['unitPrice']; expectSame('digital_service', $item['itemType'], 'aMember item type must be retained.'); expectSame('Product & Service', $item['description'], 'aMember description decoding must be retained.'); }
    expectSame($minor, $sum, 'Item sum must equal declared amount.');
    if ($currency === 'EUR') { expectSame(array(333, 334), array_column($payload['items'], 'unitPrice'), 'EUR 10 / 3 requires two distinct integer unit prices.'); }
}
foreach (array(array('0.01', 3, '0.01'), array('9.00', 1, '10.00')) as [$line, $quantity, $total]) {
    $invoice = new AmountTestInvoice(); $invoice->first_total = $total;
    $invoice->items = array((object)array('first_total' => $line, 'qty' => $quantity, 'item_title' => 'Product'));
    $before = count(Am_HttpRequest::$bodies);
    $failed = new class { public bool $failed = false; public function setFailed($message) { $this->failed = true; } public function setAction($action) { throw new RuntimeException('Must not redirect invalid amounts'); } };
    $plugin->_process($invoice, null, $failed);
    expectTrue($failed->failed, 'Inconsistent or unrepresentable amounts must fail visibly.');
    expectSame($before, count(Am_HttpRequest::$bodies), 'Invalid amounts must never reach the gateway.');
}
echo "aMember checkout amount contract: PASS\n";

foreach (array(
    array('JPY', '1000', 1000), array('EUR', '0.29', 29), array('KWD', '1.234', 1234),
    array('CLF', '1.2345', 12345), array('eur', '1.005', 101), array('EUR', '-1.005', -101),
    array('EUR', '90071992547409.91', 9007199254740991), array('EUR', '92233720368547758.07', PHP_INT_MAX),
) as [$currency, $value, $expected]) {
    expectSame($expected, PaymentGatewayAppCheckoutAmounts::minorUnits($value, $currency), 'Exact decimal conversion: ' . $value);
}
expectSame(1, PaymentGatewayAppCheckoutAmounts::sumMinorUnits(array('0.004', '0.004'), 'EUR'), 'Gross is rounded only after exact summation.');
expectSame(-125, PaymentGatewayAppCheckoutAmounts::sumMinorUnits(array('0.25', '-1.50'), 'EUR'), 'Mixed-sign summation must preserve discounts.');
foreach (array('1e3', 'NaN', '1,00', '', '92233720368547758.08') as $invalid) {
    $rejected = false;
    try { PaymentGatewayAppCheckoutAmounts::minorUnits($invalid, 'EUR'); } catch (InvalidArgumentException $error) { $rejected = true; }
    expectTrue($rejected, 'Invalid or overflowing amount must be rejected.');
}

$oldPrecision = ini_get('precision'); $oldSerializePrecision = ini_get('serialize_precision');
try {
    foreach (array(array('14', '14'), array('6', '3'), array('17', '-1')) as [$precision, $serializePrecision]) {
        ini_set('precision', $precision); ini_set('serialize_precision', $serializePrecision);
        foreach (array(array(123456789012.345, 'KWD', 123456789012345), array(1.005, 'EUR', 101), array(-1.005, 'EUR', -101), array(1.0e-7, 'EUR', 0), array(1.0e12, 'JPY', 1000000000000)) as [$value, $currency, $expected]) {
            expectSame($expected, PaymentGatewayAppCheckoutAmounts::minorUnits($value, $currency), 'Legacy floats must not depend on ini precision.');
        }
        $invoice = new AmountTestInvoice(); $invoice->currency = 'KWD'; $invoice->first_total = 123456789012.345;
        $plugin->config['pass_items'] = false;
        $plugin->_process($invoice, null, $result);
        $payload = end(Am_HttpRequest::$bodies);
        expectSame(123456789012345, $payload['amount'], 'Production checkout must preserve legacy float without item forwarding.');
        expectTrue(!isset($payload['items']), 'Disabled item forwarding must remain disabled.');
    }
} finally {
    ini_set('precision', $oldPrecision); ini_set('serialize_precision', $oldSerializePrecision);
}
foreach (array(INF, -INF, NAN, 1.0e20, 9007199254740992.0, '1e3') as $invalid) {
    $rejected = false;
    try { PaymentGatewayAppCheckoutAmounts::minorUnits($invalid, 'JPY'); } catch (InvalidArgumentException $error) { $rejected = true; }
    expectTrue($rejected, 'Non-finite, ambiguous or exponent-string values must fail safely.');
}

$plugin->config['pass_items'] = true;
foreach (array(array('1.004', '2.004', '3.01', 301), array('1.006', '2.006', '3.01', 301)) as [$first, $second, $total, $minor]) {
    $invoice = new AmountTestInvoice(); $invoice->first_total = $total;
    $invoice->items = array((object)array('first_total' => $first, 'qty' => 1, 'item_title' => 'First'), (object)array('first_total' => $second, 'qty' => 2, 'item_title' => 'Second'));
    $plugin->_process($invoice, null, $result); $payload = end(Am_HttpRequest::$bodies);
    $sum = 0; foreach ($payload['items'] as $item) { $sum += $item['quantity'] * $item['unitPrice']; }
    expectSame($minor, $sum, 'Only aggregate rounding drift must be reconciled.');
    expectSame(3, array_sum(array_column($payload['items'], 'quantity')), 'Reconciliation must preserve quantity.');
}

$roundedItems = PaymentGatewayAppCheckoutAmounts::buildItems(array(
    array('description' => 'Small', 'quantity' => 1, 'values' => array('0.005'), 'itemType' => 'goods'),
    array('description' => 'Adjustable', 'quantity' => 1, 'values' => array('1.005'), 'itemType' => 'goods'),
    array('description' => 'Exact tax', 'quantity' => 1, 'values' => array('0.20'), 'itemType' => 'tax'),
), 121, 'EUR');
expectSame(array(1, 100, 20), array_column($roundedItems, 'unitPrice'), 'Reconciliation must skip zero-price alternatives and preserve exact tax.');

// Near 9e12 KWD, binary64 spacing exceeds 0.001 KWD; reject the
// legacy float rather than pretending it identifies an adjacent minor unit.
$ambiguousKwdRejected = false;
try { PaymentGatewayAppCheckoutAmounts::minorUnits(9000000000000.001, 'KWD'); }
catch (InvalidArgumentException $error) { $ambiguousKwdRejected = true; }
expectTrue($ambiguousKwdRejected, 'Adjacent KWD minor units must remain distinguishable for legacy floats.');
expectSame(9000000000000001, PaymentGatewayAppCheckoutAmounts::minorUnits('9000000000000.001', 'KWD'), 'Canonical decimal strings must retain full minor-unit precision.');
