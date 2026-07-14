<?php

declare(strict_types=1);

class Am_Paysystem_Abstract
{
    public const STATUS_PRODUCTION = 1;
    public const REPORTS_NOT_RECURRING = 0;
}

class Am_Paysystem_Transaction_Incoming_Thanks
{
}

class Am_Paysystem_Transaction_Incoming
{
}

require dirname(__DIR__) . '/payment-gateway-app.php';

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

assertTrueValue(class_exists('Am_Paysystem_PaymentGatewayApp'), 'The aMember plugin class must load.');
assertTrueValue(class_exists('PaymentGatewayAppApiErrorContext'), 'The pure API error context helper must load.');

$plugin = new ReflectionClass('Am_Paysystem_PaymentGatewayApp');
foreach (array('formatCustomerApiError', 'logGatewayApiError') as $method) {
    assertSameValue(1, count(array_filter(
        $plugin->getMethods(ReflectionMethod::IS_PRIVATE),
        static fn (ReflectionMethod $candidate): bool => $candidate->getName() === $method
    )), $method . ' must be declared exactly once.');
}

$providers = array();
for ($index = 0; $index < 25; $index++) {
    $providers[] = 'provider-' . $index;
}

$context = PaymentGatewayAppApiErrorContext::parse(array(
    'error' => array(
        'code' => 'CHECKOUT_RESTRICTED_BY_CUSTOMER_HOLD',
        'requestID' => "request-123\r\nInjected",
        'customerRiskHold' => array(
            'id' => 'hold-123',
            'action' => 'allow_provider_types',
            'reason' => "risk-review\nsecret@example.test",
        ),
        'allowedProviderTypes' => array_merge(array('wire', 'wise', 'wire'), $providers),
        'allowedProviderIds' => array('provider:one', str_repeat('x', 65), array('invalid')),
        'authorization' => 'Bearer must-not-log',
        'billingAddress' => array('email' => 'secret@example.test'),
        'message' => 'Raw backend message must not reach the customer or logs.',
    ),
), 409);

assertSameValue('CHECKOUT_RESTRICTED_BY_CUSTOMER_HOLD', $context['code'], 'The nested typed code must be parsed.');
assertSameValue('', $context['requestId'], 'Identifiers containing control characters must be rejected.');
assertSameValue('hold-123', $context['customerRiskHoldId'], 'Nested hold IDs must be parsed.');
assertSameValue('allow_provider_types', $context['customerRiskAction'], 'Known hold actions must be parsed.');
assertSameValue(20, count($context['allowedProviderTypes']), 'Provider lists must be deduplicated and capped at 20 values.');
assertSameValue(array('provider:one'), $context['allowedProviderIds'], 'Oversized and non-scalar provider IDs must be discarded.');

$logContext = PaymentGatewayAppApiErrorContext::logContext($context);
assertTrueValue(!array_key_exists('message', $logContext), 'Raw backend messages must never be logged.');
assertTrueValue(!array_key_exists('authorization', $logContext), 'Credentials must never be logged.');
assertTrueValue(!array_key_exists('billingAddress', $logContext), 'Billing data must never be logged.');

$customerMessage = PaymentGatewayAppApiErrorContext::customerMessage(
    $context,
    'Payment session creation failed due to an unexpected gateway response.'
);
assertTrueValue(str_contains($customerMessage, 'Only bank transfer payment methods are available'), 'Restricted holds need static customer guidance.');
assertTrueValue(!str_contains($customerMessage, 'Raw backend message'), 'Backend messages must not reach customers.');

$blocked = PaymentGatewayAppApiErrorContext::parse(array(
    'code' => 'CHECKOUT_BLOCKED_BY_CUSTOMER_HOLD',
    'requestId' => 'request_456',
), 409);
assertSameValue(
    'Payment cannot be started because this customer account is under merchant review. Please contact support. Request ID: request_456',
    PaymentGatewayAppApiErrorContext::customerMessage($blocked, 'fallback'),
    'Blocked holds need a static message with a sanitized request ID.'
);

echo "aMember plugin load and API error contract: PASS\n";
