<?php

declare(strict_types=1);

class Am_Paysystem_Abstract
{
    public const STATUS_PRODUCTION = 1;
    public const REPORTS_NOT_RECURRING = 0;

    public array $config = array();
    public array $errorLogs = array();
    public array $otherLogs = array();

    public function getConfig($key)
    {
        return $this->config[$key] ?? null;
    }

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
    public function setBody($body): void {}
    public function send(): Am_HttpResponseStub { return self::$response; }
}

class Am_Paysystem_Action_Redirect
{
    public function __construct(public string $url) {}
}

class Am_Exception_FatalError extends Exception {}

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
    public function getEmail(): string { return 'customer@example.test'; }
    public function getItems(): array { return array(); }
};
$result = new class {
    public $action;
    public function setAction($action): void { $this->action = $action; }
    public function setFailed($message): void { throw new RuntimeException($message); }
};
$plugin = new Am_Paysystem_PaymentGatewayApp();
$plugin->config = array('api_domain' => 'api.example.test', 'site_id' => 'site-1', 'api_key' => 'secret', 'debug_logging' => 0);
Am_HttpRequest::$response = new Am_HttpResponseStub(200, '{"paymentUrl":"https://pay.example.test/session"}');
$plugin->_process($invoice, null, $result);
expectSame('https://pay.example.test/session', $result->action->url, 'Normal successful checkout must remain unchanged.');
expectSame(array(), $plugin->errorLogs, 'Debug-off must not write API error logs.');
expectSame(array(), $plugin->otherLogs, 'Debug-off must not write checkout exchange logs.');

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
