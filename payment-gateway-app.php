<?php
/**
 * @table paysystems
 * @id payment-gateway-app
 * @title Payment Gateway App
 * @visible_link https://payment-gateway.app
 * @recurring none
 * @am_payment_api 6.0
 */
final class PaymentGatewayAppApiErrorContext
{
    const MAX_IDENTIFIER_LENGTH = 128;
    const MAX_PROVIDER_LENGTH = 64;
    const MAX_PROVIDER_COUNT = 20;

    public static function parse($responseBody, $httpStatus = null)
    {
        $data = is_array($responseBody) ? $responseBody : array();
        $context = array(
            'httpStatus' => is_numeric($httpStatus) ? (int)$httpStatus : null,
            'code' => self::identifier(self::scalar($data, array('code', 'error.code'))),
            'requestId' => self::identifier(self::scalar($data, array('requestId', 'requestID', 'error.requestId', 'error.requestID', 'chargeback.requestId', 'chargeback.requestID', 'error.chargeback.requestId', 'error.chargeback.requestID'))),
            'transactionId' => self::identifier(self::scalar($data, array('transactionId', 'error.transactionId', 'chargeback.transactionId', 'error.chargeback.transactionId'))),
            'externalReference' => self::identifier(self::scalar($data, array('externalReference', 'error.externalReference', 'chargeback.externalReference', 'error.chargeback.externalReference'))),
            'amount' => self::numericValue(self::value($data, array('amount', 'error.amount'))),
            'currency' => self::identifier(self::scalar($data, array('currency', 'error.currency'))),
            'disputeDate' => self::identifier(self::scalar($data, array('disputeDate', 'transactionDate', 'error.disputeDate', 'error.transactionDate'))),
            'gatewayStatus' => self::identifier(self::scalar($data, array('status', 'error.status'))),
            'disputeId' => self::identifier(self::scalar($data, array('disputeId', 'chargebackId', 'error.disputeId', 'error.chargebackId', 'chargeback.disputeId', 'chargeback.id', 'error.chargeback.disputeId', 'error.chargeback.id'))),
            'disputeStatus' => self::identifier(self::scalar($data, array('disputeStatus', 'error.disputeStatus', 'chargeback.disputeStatus', 'chargeback.status', 'error.chargeback.disputeStatus', 'error.chargeback.status'))),
            'chargebackStatus' => self::identifier(self::scalar($data, array('chargebackStatus', 'error.chargebackStatus', 'chargeback.chargebackStatus', 'error.chargeback.chargebackStatus'))),
            'creditNoteId' => self::identifier(self::scalar($data, array('creditNoteId', 'error.creditNoteId', 'chargeback.creditNoteId', 'creditNote.id', 'error.chargeback.creditNoteId', 'error.creditNote.id'))),
            'creditNoteNumber' => self::identifier(self::scalar($data, array('creditNoteNumber', 'error.creditNoteNumber', 'chargeback.creditNoteNumber', 'creditNote.number', 'error.chargeback.creditNoteNumber', 'error.creditNote.number'))),
            'customerRiskHoldId' => self::identifier(self::scalar($data, array('customerRiskHoldId', 'customerRiskHold.id', 'error.customerRiskHoldId', 'error.customerRiskHold.id'))),
            'customerRiskAction' => self::action(self::scalar($data, array('customerRiskAction', 'customerRiskHold.action', 'error.customerRiskAction', 'error.customerRiskHold.action'))),
            'customerRiskReason' => self::identifier(self::scalar($data, array('customerRiskReason', 'customerRiskHold.reason', 'error.customerRiskReason', 'error.customerRiskHold.reason'))),
            'allowedProviderTypes' => self::identifierList(self::arrayValue($data, array('allowedProviderTypes', 'customerRiskHold.allowedProviderTypes', 'error.allowedProviderTypes', 'error.customerRiskHold.allowedProviderTypes'))),
            'allowedProviderIds' => self::identifierList(self::arrayValue($data, array('allowedProviderIds', 'customerRiskHold.allowedProviderIds', 'error.allowedProviderIds', 'error.customerRiskHold.allowedProviderIds'))),
        );
        return $context;
    }

    public static function customerMessage(array $context, $fallback)
    {
        $messages = array(
            'CHECKOUT_BLOCKED_BY_DISPUTE' => 'Payment cannot be started because an unresolved dispute is being reviewed. Please contact support.',
            'CHECKOUT_BLOCKED_BY_CUSTOMER_HOLD' => 'Payment cannot be started because this customer account is under merchant review. Please contact support.',
            'CHECKOUT_RESTRICTED_BY_CUSTOMER_HOLD' => 'Only bank transfer payment methods are available for this account. Please choose an available bank transfer option or contact support.',
        );
        $code = isset($context['code']) ? (string)$context['code'] : '';
        $message = isset($messages[$code]) ? $messages[$code] : trim((string)$fallback);
        if ($message === '') {
            $message = 'Payment session creation failed due to an unexpected gateway response.';
        }
        if (!empty($context['requestId'])) {
            $message .= ' Request ID: ' . $context['requestId'];
        }
        return $message;
    }

    public static function logContext(array $context, array $extra = array())
    {
        $allowed = array('httpStatus', 'code', 'requestId', 'transactionId', 'externalReference', 'amount', 'currency', 'disputeDate', 'gatewayStatus', 'disputeId', 'disputeStatus', 'chargebackStatus', 'creditNoteId', 'creditNoteNumber', 'customerRiskHoldId', 'customerRiskAction', 'customerRiskReason', 'allowedProviderTypes', 'allowedProviderIds');
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $context) || $context[$key] === '' || $context[$key] === null || $context[$key] === array()) {
                continue;
            }
            $extra[$key] = $context[$key];
        }
        return $extra;
    }

    private static function value(array $data, array $paths)
    {
        foreach ($paths as $path) {
            $value = $data;
            foreach (explode('.', $path) as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    continue 2;
                }
                $value = $value[$part];
            }
            return $value;
        }
        return null;
    }

    private static function scalar(array $data, array $paths)
    {
        $value = self::value($data, $paths);
        return is_scalar($value) ? trim((string)$value) : '';
    }

    private static function arrayValue(array $data, array $paths)
    {
        $value = self::value($data, $paths);
        return is_array($value) ? $value : array();
    }

    private static function identifier($value, $maxLength = self::MAX_IDENTIFIER_LENGTH)
    {
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > $maxLength || !preg_match('/\A[A-Za-z0-9._:-]+\z/', $value)) {
            return '';
        }
        return $value;
    }

    private static function action($value)
    {
        $value = self::identifier($value);
        return in_array($value, array('block_all', 'manual_review', 'allow_provider_types'), true) ? $value : '';
    }

    private static function identifierList(array $values)
    {
        $result = array();
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $identifier = self::identifier($value, self::MAX_PROVIDER_LENGTH);
            if ($identifier === '' || in_array($identifier, $result, true)) {
                continue;
            }
            $result[] = $identifier;
            if (count($result) >= self::MAX_PROVIDER_COUNT) {
                break;
            }
        }
        return $result;
    }

    private static function numericValue($value)
    {
        return is_numeric($value) ? $value + 0 : null;
    }
}

class Am_Paysystem_PaymentGatewayApp extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = 'dev';

    protected $defaultTitle = 'Secure Checkout via payment-gateway.app';
    protected $defaultDescription = 'Pay securely with credit/debit cards, crypto, wire transfer, or local options.';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('api_domain')
            ->setLabel(___("API Domain\n" .
                'API Domain of your Payment Gateway App without protocol. Example: api.payment-gateway.app (instead of "https://api.payment-gateway.app").'))
            ->addRule('required');
        $form->addText('api_key', array('size' => 100))
            ->setLabel(___("API Key\n" .
                'Create an API Key with checkout:create scope from Payment Gateway App Dashboard > API Keys. Format: sk_...'))
            ->addRule('required');
        $form->addText('site_id')
            ->setLabel(___("Site ID\n" .
                'Copy the Site ID from Payment Gateway App Dashboard > Sites.'))
            ->addRule('required');
        $form->addText('webhook_secret', array('size' => 100))
            ->setLabel(___("Webhook Signing Secret\n" .
                'Copy the Webhook Signing Secret from Payment Gateway App Dashboard > Sites > Edit Site. ' .
                'This secret verifies IPN/webhook notifications (HMAC-SHA256). Starts with whsec_.'))
            ->addRule('required');
        $form->addAdvCheckbox('pass_billing_address')
            ->setLabel(___("Enable passing billing address\n" .
                'Send the customer’s billing address to the payment gateway app.'));
        $form->addAdvCheckbox('pass_items')
            ->setLabel(___("Pass Items\n" .
                'Send invoice line-items to the payment gateway app.'));
    }

    /**
     * Parse structured API errors from Payment Gateway App responses.
     *
     * @param array<string, mixed>|null $responseBody
     * @return array<string, mixed>
     */
    private function getApiErrorDetails($responseBody, $httpStatus = null)
    {
        return PaymentGatewayAppApiErrorContext::parse($responseBody, $httpStatus);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function formatCustomerApiError($details, $fallback)
    {
        return PaymentGatewayAppApiErrorContext::customerMessage($details, $fallback);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function logGatewayApiError($details)
    {
        $context = PaymentGatewayAppApiErrorContext::logContext($details);
        if ($context) {
            $this->logError('Payment Gateway App API error', $context);
        }
    }


    private function logCheckoutApiExchange($stage, array $context)
    {
        $allowed = array('invoice', 'endpoint', 'httpStatus', 'amount', 'currency', 'itemCount', 'hasBillingAddress', 'requestId', 'code', 'message');
        $safeContext = array();
        foreach ($allowed as $key) {
            if (array_key_exists($key, $context) && $context[$key] !== '' && $context[$key] !== null) {
                $safeContext[$key] = $context[$key];
            }
        }
        $this->logOther('Payment Gateway App checkout ' . $stage, $safeContext);
    }

    public function _process($invoice, $request, $result)
    {
        $paymentSessionUrl = 'https://' . rtrim($this->getConfig('api_domain'), '/') . '/v1/checkouts/' . $this->getConfig('site_id') . '/create';
        $httpRequest = new Am_HttpRequest($paymentSessionUrl, Am_HttpRequest::METHOD_POST);
        $amount = round($invoice->first_total * 100); // Convert to cents
        $hashData = array(
            'amount' => $amount,
            'currency' => $invoice->currency,
            'email' => $invoice->getEmail(),
            'externalReference' => $invoice->public_id,
            'returnUrl' => $this->getReturnUrl(),
            'cancelUrl' => $this->getCancelUrl(),
            'ipnUrl' => $this->getPluginUrl('ipn'),
        );

        // Conditionally add billing and shipping fields
        if ($this->getConfig('pass_billing_address')) {
            $hashData['billingAddress'] = array(
                'firstName' => html_entity_decode($invoice->getFirstName(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'lastName' => html_entity_decode($invoice->getLastName(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'address1' => html_entity_decode($invoice->getStreet(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'city' => html_entity_decode($invoice->getCity(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'postcode' => $invoice->getZip(),
                'country' => $invoice->getCountry(),
            );
        }

        // Conditionally pass items
        if ($this->getConfig('pass_items')) {
            $items = array();
            foreach ($invoice->getItems() as $item) {
                $quantity = max(1, (int)$item->qty);
                $items[] = array(
                    'description' => html_entity_decode($item->item_title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'quantity' => $quantity,
                    'unitPrice' => round(($item->first_total / $quantity) * 100),
                    'itemType' => 'digital_service',
                );
            }

            // Correct for rounding errors by ensuring the sum of items exactly equals the total amount.
            $items_total_cents = 0;
            foreach ($items as $it) {
                $items_total_cents += $it['unitPrice'] * $it['quantity'];
            }
            $diff_cents = $amount - $items_total_cents;
            if ($diff_cents != 0 && count($items) > 0) {
                $items[count($items) - 1]['unitPrice'] += $diff_cents;
            }

            $hashData['items'] = $items;
        }

        // Set the request headers (API Key authentication)
        $httpRequest->setHeader('Content-Type', 'application/json');
        $httpRequest->setHeader('Authorization', 'Bearer ' . $this->getConfig('api_key'));

        // Set the request body as JSON
        $httpRequest->setBody(json_encode($hashData));

        $this->logCheckoutApiExchange('request', array(
            'invoice' => $invoice->public_id,
            'endpoint' => $paymentSessionUrl,
            'amount' => $amount,
            'currency' => $invoice->currency,
            'itemCount' => isset($hashData['items']) ? count($hashData['items']) : 0,
            'hasBillingAddress' => isset($hashData['billingAddress']),
        ));
        $response = $httpRequest->send();

        $responseCode = $response->getStatus();
        $responseBody = json_decode($response->getBody(), true);
        $responseErrorDetails = $this->getApiErrorDetails($responseBody, $responseCode);
        $this->logCheckoutApiExchange('response', array(
            'invoice' => $invoice->public_id,
            'httpStatus' => $responseCode,
            'requestId' => $responseErrorDetails['requestId'],
            'code' => $responseErrorDetails['code'],
        ));

        if ($responseCode !== 200) {
            $errorDetails = $responseErrorDetails;
            $this->logGatewayApiError($errorDetails);
            $errorMessage = $this->formatCustomerApiError($errorDetails, 'Payment session creation failed due to an unexpected gateway response.');
            if ($responseCode === 401) {
                throw new Am_Exception_FatalError("Authentication failed. Please check your API Key configuration.");
            }
            throw new Am_Exception_FatalError("Payment session creation failed: " . $errorMessage);
        }

        if (!isset($responseBody['paymentUrl'])) {
            $errorDetails = $this->getApiErrorDetails($responseBody, $responseCode);
            $this->logGatewayApiError($errorDetails);
            $result->setFailed('Payment session creation failed. Reason: ' . $this->formatCustomerApiError($errorDetails, 'missing paymentUrl in response'));
            return;
        }

        $a = new Am_Paysystem_Action_Redirect($responseBody['paymentUrl']);
        $result->setAction($a);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getReadme()
    {
        $url = $this->getDi()->surl('payment/payment-gateway-app/ipn');
        return <<<CUT
    <b>aMember Payment Gateway app plugin setup</b>

    1. Enter your backend domain in "API Domain" (e.g. api.payment-gateway.app).
    2. Create an API Key in Payment Gateway App Dashboard > API Keys and paste it in "API Key".
    3. Copy the Site ID from Payment Gateway App Dashboard > Sites and paste it in "Site ID".
CUT;
    }

    public function createThanksTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_PaymentGatewayApp_Thanks($this, $request, $response, $invokeArgs);
    }

    public function createTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_PaymentGatewayApp($this, $request, $response, $invokeArgs);
    }
}

class Am_Paysystem_Transaction_PaymentGatewayApp_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function findInvoiceId()
    {
        return $this->request->getFiltered('externalReference');
    }

    public function getUniqId()
    {
        return true;
    }

    public function validateStatus()
    {
        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function validateSource()
    {
        return true;
    }

    public function processValidated()
    {
        return true;
    }
}

final class PaymentGatewayAppIpnV2State
{
    const STATE_FORMAT_VERSION = 1;
    const STATE_DATA_KEY = 'payment_gateway_app_ipn_v2';
    const MAX_RETAINED_DELIVERIES = 100;
    const LOCK_WAIT_SECONDS = 5;

    public static function process($invoice, $db, $transactionId, array $payload, $rawBody, callable $applyEffect)
    {
        $lockName = 'pgw-ipn-v2:' . substr(hash('sha256', (string)$invoice->pk()), 0, 48);
        $lockAcquired = $db->selectCell('SELECT GET_LOCK(?, ?d)', $lockName, self::LOCK_WAIT_SECONDS);
        if ((int)$lockAcquired !== 1) {
            throw new Am_Exception_Paysystem('Unable to lock IPN v2 state');
        }

        try {
            $invoice->refresh();
            $state = self::loadState($invoice->data()->get(self::STATE_DATA_KEY));
            $deliveryKey = hash('sha256', (string)$payload['deliveryId']);
            $transactionKey = hash('sha256', (string)$transactionId);
            $eventVersion = (int)$payload['eventVersion'];
            $bodyHash = hash('sha256', (string)$rawBody);
            $semanticHash = self::semanticEventHash($payload);
            $delivery = null;
            $phase = null;

            if (isset($state['deliveries'][$deliveryKey])) {
                $delivery = $state['deliveries'][$deliveryKey];
                if (
                    !isset($delivery['transactionKey'], $delivery['eventVersion'], $delivery['bodyHash'])
                    || !hash_equals((string)$delivery['transactionKey'], $transactionKey)
                    || (int)$delivery['eventVersion'] !== $eventVersion
                    || !hash_equals((string)$delivery['bodyHash'], $bodyHash)
                ) {
                    throw new Am_Exception_Paysystem('Conflicting IPN v2 delivery identity');
                }
                $phase = isset($delivery['phase']) ? (string)$delivery['phase'] : 'pending';
            }

            $highestEventVersion = isset($state['highestEventVersions'][$transactionKey])
                ? (int)$state['highestEventVersions'][$transactionKey]
                : 0;
            if (
                isset($state['eventSemanticHashes'][$transactionKey])
                && !is_array($state['eventSemanticHashes'][$transactionKey])
            ) {
                throw new Am_Exception_Paysystem('Invalid persisted IPN v2 event semantics');
            }
            $eventVersionKey = (string)$eventVersion;
            $storedSemanticHash = isset($state['eventSemanticHashes'][$transactionKey][$eventVersionKey])
                ? $state['eventSemanticHashes'][$transactionKey][$eventVersionKey]
                : null;
            if ($storedSemanticHash !== null) {
                if (!is_string($storedSemanticHash) || strlen($storedSemanticHash) !== 64) {
                    throw new Am_Exception_Paysystem('Invalid persisted IPN v2 event semantics');
                }
                if (!hash_equals($storedSemanticHash, $semanticHash)) {
                    throw new Am_Exception_Paysystem('Conflicting IPN v2 event semantics');
                }
            } elseif ($delivery === null) {
                foreach ($state['deliveries'] as $knownDelivery) {
                    if (
                        isset($knownDelivery['transactionKey'], $knownDelivery['eventVersion'])
                        && hash_equals((string)$knownDelivery['transactionKey'], $transactionKey)
                        && (int)$knownDelivery['eventVersion'] === $eventVersion
                    ) {
                        throw new Am_Exception_Paysystem('Missing persisted IPN v2 event semantics');
                    }
                }
            }
            if ($delivery === null && $eventVersion <= $highestEventVersion) {
                return 'outdated';
            }
            if ($storedSemanticHash === null) {
                if (!isset($state['eventSemanticHashes'][$transactionKey])) {
                    $state['eventSemanticHashes'][$transactionKey] = array();
                }
                $state['eventSemanticHashes'][$transactionKey][$eventVersionKey] = $semanticHash;
            }

            if ($delivery !== null) {
                if ($phase === 'applied') {
                    if ($storedSemanticHash === null) {
                        self::saveState($invoice, $state);
                    }
                    return 'duplicate';
                }
                if ($phase === 'superseded') {
                    if ($storedSemanticHash === null) {
                        self::saveState($invoice, $state);
                    }
                    return 'outdated';
                }
                if ($phase !== 'pending') {
                    throw new Am_Exception_Paysystem('Invalid IPN v2 delivery phase');
                }
            }

            if ($delivery !== null && $eventVersion < $highestEventVersion) {
                $state['deliveries'][$deliveryKey]['phase'] = 'superseded';
                self::saveState($invoice, $state);
                return 'outdated';
            }
            if ($delivery === null) {
                $state['deliveries'][$deliveryKey] = array(
                    'transactionKey' => $transactionKey,
                    'eventVersion' => $eventVersion,
                    'bodyHash' => $bodyHash,
                    'phase' => 'pending',
                );
                $state = self::trimDeliveries($state);
                self::saveState($invoice, $state);
            } elseif ($storedSemanticHash === null) {
                self::saveState($invoice, $state);
            }

            $applyEffect();

            $invoice->refresh();
            $state = self::loadState($invoice->data()->get(self::STATE_DATA_KEY));
            if (!isset($state['deliveries'][$deliveryKey])) {
                throw new Am_Exception_Paysystem('IPN v2 delivery state disappeared');
            }
            $state['deliveries'][$deliveryKey]['phase'] = 'applied';
            $state['highestEventVersions'][$transactionKey] = max(
                $highestEventVersion,
                $eventVersion
            );
            self::saveState($invoice, $state);
            return 'applied';
        } finally {
            $db->selectCell('SELECT RELEASE_LOCK(?)', $lockName);
        }
    }

    private static function loadState($storedState)
    {
        if ($storedState === null || $storedState === '') {
            return array(
                'formatVersion' => self::STATE_FORMAT_VERSION,
                'highestEventVersions' => array(),
                'eventSemanticHashes' => array(),
                'deliveries' => array(),
            );
        }

        $state = is_string($storedState) ? json_decode($storedState, true) : null;
        if (
            !is_array($state)
            || !isset($state['formatVersion'], $state['highestEventVersions'], $state['deliveries'])
            || (int)$state['formatVersion'] !== self::STATE_FORMAT_VERSION
            || !is_array($state['highestEventVersions'])
            || !is_array($state['deliveries'])
        ) {
            throw new Am_Exception_Paysystem('Invalid persisted IPN v2 state');
        }
        if (!isset($state['eventSemanticHashes'])) {
            $state['eventSemanticHashes'] = array();
        }
        if (!is_array($state['eventSemanticHashes'])) {
            throw new Am_Exception_Paysystem('Invalid persisted IPN v2 event semantics');
        }
        return $state;
    }

    private static function semanticEventHash(array $payload)
    {
        // V2 effect selection is entirely status-driven; retry metadata and legacy dispute aliases are inert.
        return hash(
            'sha256',
            "payment-gateway-app-ipn-v2-semantic-v1\0status\0" . (string)$payload['status']
        );
    }

    private static function saveState($invoice, array $state)
    {
        $encoded = json_encode($state);
        if (!is_string($encoded)) {
            throw new Am_Exception_Paysystem('Unable to encode IPN v2 state');
        }
        $invoice->data()->set(self::STATE_DATA_KEY, $encoded);
        $invoice->data()->update();
    }

    private static function trimDeliveries(array $state)
    {
        while (count($state['deliveries']) > self::MAX_RETAINED_DELIVERIES) {
            $deliveryKey = array_key_first($state['deliveries']);
            if ($deliveryKey === null) {
                break;
            }
            unset($state['deliveries'][$deliveryKey]);
        }
        return $state;
    }
}

class Am_Paysystem_Transaction_PaymentGatewayApp extends Am_Paysystem_Transaction_Incoming
{
    protected $parsedRequest;
    private $rawRequestBody = '';
    private $ipnVersion = 1;

    private function getParsedScalar(array $keys)
    {
        foreach ($keys as $key) {
            $value = $this->parsedRequest;
            foreach (explode('.', $key) as $part) {
                if (is_array($value) && isset($value[$part])) {
                    $value = $value[$part];
                } else {
                    continue 2;
                }
            }
            if (is_scalar($value)) {
                return trim((string)$value);
            }
        }
        return '';
    }

    private function getDisputeStatus()
    {
        foreach (array('disputeStatus', 'chargebackStatus', 'status', 'chargeback.status', 'chargeback.disputeStatus', 'chargeback.chargebackStatus') as $key) {
            $status = strtolower($this->getParsedScalar(array($key)));
            if ($this->isSupportedDisputeStatus($status)) {
                return $status;
            }
        }
        return '';
    }

    private function isSupportedDisputeStatus($status)
    {
        return in_array($status, array('open', 'under_review', 'won', 'lost', 'accepted'), true);
    }

    private function getRequestId()
    {
        return $this->getParsedScalar(array('requestId', 'requestID', 'chargeback.requestId', 'chargeback.requestID'));
    }

    private function getGatewayTransactionId()
    {
        if ($this->ipnVersion === 2) {
            return isset($this->parsedRequest['id']) && is_string($this->parsedRequest['id'])
                ? $this->parsedRequest['id']
                : '';
        }
        return $this->getParsedScalar(array('id', 'transactionId', 'chargeback.transactionId', 'chargeback.gatewayTransactionId'));
    }

    private function getV2ReceiptEffect()
    {
        $status = isset($this->parsedRequest['status']) && is_int($this->parsedRequest['status'])
            ? $this->parsedRequest['status']
            : null;
        $effects = array(
            -2 => 'cancel',
            -1 => 'initiated',
            0 => 'pending',
            1 => 'payment',
            2 => 'void',
            3 => 'refund',
            4 => 'chargeback',
        );
        return isset($effects[$status]) ? $effects[$status] : 'invalid';
    }

    private function getV2ReceiptTransactionId()
    {
        $transactionId = $this->getGatewayTransactionId();
        $eventVersion = isset($this->parsedRequest['eventVersion'])
            ? (int)$this->parsedRequest['eventVersion']
            : 0;
        return hash(
            'sha256',
            "payment-gateway-app-ipn-v2\0"
                . strlen($transactionId) . ':' . $transactionId . "\0"
                . $eventVersion . "\0"
                . $this->getV2ReceiptEffect()
        );
    }

    private function getExternalReference()
    {
        return $this->getParsedScalar(array('externalReference', 'chargeback.externalReference'));
    }

    private function getSafeWebhookLogContext(array $extra = array())
    {
        $context = $extra;
        $fields = array(
            'gatewayTransactionId' => $this->getGatewayTransactionId(),
            'externalReference' => $this->getExternalReference(),
            'disputeId' => $this->getParsedScalar(array('disputeId', 'chargebackId', 'chargeback.disputeId', 'chargeback.chargebackId', 'chargeback.id')),
            'disputeStatus' => $this->getDisputeStatus(),
            'requestId' => $this->getRequestId(),
            'creditNoteId' => $this->getParsedScalar(array('creditNoteId', 'chargeback.creditNoteId', 'creditNote.id')),
            'creditNoteNumber' => $this->getParsedScalar(array('creditNoteNumber', 'chargeback.creditNoteNumber', 'creditNote.number')),
        );
        if (isset($this->parsedRequest['status']) && is_scalar($this->parsedRequest['status'])) {
            $fields['status'] = (string)$this->parsedRequest['status'];
        }
        foreach ($fields as $key => $value) {
            if ($value !== '') {
                $context[$key] = $value;
            }
        }
        return $context;
    }

    private function logDisputeUpdate($disputeStatus)
    {
        $this->getPlugin()->logOther('Payment Gateway App dispute update', array_merge(array(
            'invoice' => isset($this->invoice) ? $this->invoice->public_id : $this->getExternalReference(),
        ), $this->getSafeWebhookLogContext(array(
            'disputeStatus' => $disputeStatus,
        ))));
    }

    private function hasExistingChargeback()
    {
        return $this->hasExistingRefundReceipt(InvoiceRefund::CHARGEBACK);
    }

    private function hasExistingPaymentReceipt()
    {
        $transactionId = (string)$this->getUniqId();
        foreach ($this->invoice->getPaymentRecords() as $payment) {
            if ((string)$payment->transaction_id === $transactionId) {
                return true;
            }
        }
        return false;
    }

    private function hasExistingRefundReceipt($refundType)
    {
        if (!isset($this->invoice)) {
            return false;
        }
        $transactionId = (string)$this->getUniqId();
        foreach ($this->invoice->getRefundRecords() as $refund) {
            if (
                (int)$refund->refund_type === (int)$refundType
                && (string)$refund->transaction_id === $transactionId
            ) {
                return true;
            }
        }
        return false;
    }

    private function addChargebackIdempotently()
    {
        if ($this->hasExistingChargeback()) {
            $this->getPlugin()->logOther('Payment Gateway App chargeback IPN already recorded', array(
                'invoice' => $this->invoice->public_id,
                'gatewayTransactionId' => $this->getGatewayTransactionId(),
                'requestId' => $this->getRequestId(),
            ));
            return;
        }

        try {
            $this->invoice->addChargeback($this, $this->getReceiptId());
        } catch (Exception $e) {
            if ($this->hasExistingChargeback()) {
                $this->getPlugin()->logOther('Payment Gateway App duplicate chargeback IPN accepted', array(
                    'invoice' => $this->invoice->public_id,
                    'gatewayTransactionId' => $this->getGatewayTransactionId(),
                    'requestId' => $this->getRequestId(),
                ));
                return;
            }
            throw $e;
        }
    }

    private function rejectV2Envelope($reason)
    {
        $this->getPlugin()->logError(
            'IPN: Invalid v2 envelope.',
            $this->getSafeWebhookLogContext(array('reason' => (string)$reason))
        );
        throw new Am_Exception_Paysystem('Invalid IPN v2 envelope');
    }

    private function isValidOpaqueDeliveryId($value)
    {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= 128
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private function isValidOccurredAt($value)
    {
        if (!is_string($value) || strlen($value) > 64) {
            return false;
        }
        if (!preg_match(
            '/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:Z|[+-](\d{2}):(\d{2}))\z/',
            $value,
            $parts
        )) {
            return false;
        }

        $year = (int)$parts[1];
        $month = (int)$parts[2];
        $day = (int)$parts[3];
        $hour = (int)$parts[4];
        $minute = (int)$parts[5];
        $second = (int)$parts[6];
        $offsetHour = isset($parts[7]) && $parts[7] !== '' ? (int)$parts[7] : 0;
        $offsetMinute = isset($parts[8]) && $parts[8] !== '' ? (int)$parts[8] : 0;

        return checkdate($month, $day, $year)
            && $hour <= 23
            && $minute <= 59
            && $second <= 59
            && $offsetHour <= 23
            && $offsetMinute <= 59;
    }

    private function validateIpnVersion()
    {
        $versionHeader = $this->request->getHeader('X-IPN-Version');
        $versionHeader = is_scalar($versionHeader) ? trim((string)$versionHeader) : '';

        if ($versionHeader === '') {
            if (array_key_exists('schemaVersion', $this->parsedRequest)) {
                $this->rejectV2Envelope('version_mismatch');
            }
            $this->ipnVersion = 1;
            return;
        }

        if ($versionHeader === '1') {
            if (
                array_key_exists('schemaVersion', $this->parsedRequest)
                && (!is_int($this->parsedRequest['schemaVersion']) || $this->parsedRequest['schemaVersion'] !== 1)
            ) {
                $this->rejectV2Envelope('version_mismatch');
            }
            $this->ipnVersion = 1;
            return;
        }

        if ($versionHeader !== '2') {
            $this->rejectV2Envelope('unsupported_version');
        }
        $this->ipnVersion = 2;
        if (
            !isset($this->parsedRequest['schemaVersion'])
            || !is_int($this->parsedRequest['schemaVersion'])
            || $this->parsedRequest['schemaVersion'] !== 2
        ) {
            $this->rejectV2Envelope('schema_version');
        }
        if (
            !isset($this->parsedRequest['id'])
            || !is_string($this->parsedRequest['id'])
            || trim($this->parsedRequest['id']) === ''
            || strlen($this->parsedRequest['id']) > 64
            || preg_match('/[\x00-\x1F\x7F]/', $this->parsedRequest['id']) === 1
        ) {
            $this->rejectV2Envelope('transaction_identity');
        }

        $deliveryId = isset($this->parsedRequest['deliveryId']) ? $this->parsedRequest['deliveryId'] : null;
        $headerDeliveryId = $this->request->getHeader('X-IPN-Delivery-ID');
        if (
            !$this->isValidOpaqueDeliveryId($deliveryId)
            || !$this->isValidOpaqueDeliveryId($headerDeliveryId)
            || !hash_equals($deliveryId, $headerDeliveryId)
        ) {
            $this->rejectV2Envelope('delivery_identity');
        }
        if (
            !isset($this->parsedRequest['eventVersion'])
            || !is_int($this->parsedRequest['eventVersion'])
            || $this->parsedRequest['eventVersion'] <= 0
        ) {
            $this->rejectV2Envelope('event_version');
        }
        if (
            !isset($this->parsedRequest['occurredAt'])
            || !$this->isValidOccurredAt($this->parsedRequest['occurredAt'])
        ) {
            $this->rejectV2Envelope('occurred_at');
        }
    }

    public function validateSource()
    {
        $raw_body = $this->request->getRawBody();
        $this->rawRequestBody = $raw_body;
        $this->parsedRequest = json_decode($raw_body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($this->parsedRequest)) {
            $this->getPlugin()->logError("IPN: Invalid JSON received.", array(
                'jsonError' => json_last_error_msg(),
                'rawBodyLength' => strlen($raw_body),
            ));
            throw new Am_Exception_Paysystem("Invalid JSON in request body");
        }

        // --- New Webhook Verification Logic ---
        $received_timestamp = $this->request->getHeader('X-Signature-Timestamp');
        $received_signature = $this->request->getHeader('X-Signature-HMAC-SHA256');

        if (!$received_timestamp || !$received_signature) {
            $this->getPlugin()->logError("IPN: Signature headers missing.");
            throw new Am_Exception_Paysystem("Signature headers missing");
        }

        if (!is_numeric($received_timestamp)) {
            $this->getPlugin()->logError("IPN: Invalid signature timestamp.", array('received_timestamp' => $received_timestamp));
            throw new Am_Exception_Paysystem("Invalid signature timestamp");
        }

        // Check if timestamp is recent (e.g., within 5 minutes) to prevent replay attacks.
        if (abs(time() - (int)$received_timestamp) > 300) {
            $this->getPlugin()->logError("IPN: Webhook timestamp is too old.", array('received_timestamp' => $received_timestamp));
            throw new Am_Exception_Paysystem("Webhook timestamp too old");
        }

        // Recreate the signature string and verify using the webhook signing secret.
        $string_to_sign = $received_timestamp . '.' . $raw_body;
        $computed_hash = hash_hmac('sha256', $string_to_sign, $this->getPlugin()->getConfig('webhook_secret'));

        // Securely compare the signatures (timing-safe).
        if (!hash_equals($computed_hash, $received_signature)) {
            $this->getPlugin()->logError("IPN: Invalid signature.");
            http_response_code(400);
            echo "Invalid signature";
            return false;
        }

        $this->validateIpnVersion();

        $hasDisputeStatus = $this->isSupportedDisputeStatus($this->getDisputeStatus());
        if ($this->getGatewayTransactionId() === '' || $this->getExternalReference() === '' || (!isset($this->parsedRequest['status']) && !$hasDisputeStatus)) {
            $this->getPlugin()->logError("IPN: Missing required fields.", $this->getSafeWebhookLogContext(array('reason' => 'missing_required_fields')));
            throw new Am_Exception_Paysystem("Missing required fields");
        }

        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function validateStatus()
    {
        if ($this->ipnVersion === 2) {
            if (
                !isset($this->parsedRequest['status'])
                || !is_int($this->parsedRequest['status'])
                || !in_array($this->parsedRequest['status'], array(-2, -1, 0, 1, 2, 3, 4), true)
            ) {
                $this->getPlugin()->logError(
                    'IPN: Invalid v2 status field.',
                    $this->getSafeWebhookLogContext(array('reason' => 'invalid_status_field'))
                );
                return false;
            }
            return true;
        }
        if ($this->isSupportedDisputeStatus($this->getDisputeStatus())) {
            return true;
        }
        if (!isset($this->parsedRequest['status']) || !is_numeric($this->parsedRequest['status'])) {
            $this->getPlugin()->logError("IPN: Invalid status field.", $this->getSafeWebhookLogContext(array('reason' => 'invalid_status_field')));
            return false;
        }
        $status = (int)$this->parsedRequest['status'];
        // Status is now an integer
        return in_array($status, [-2, -1, 0, 1, 2, 3, 4]);
    }

    public function findInvoiceId()
    {
        return $this->getExternalReference();
    }

    public function getUniqId()
    {
        return $this->ipnVersion === 2
            ? $this->getV2ReceiptTransactionId()
            : $this->getGatewayTransactionId();
    }

    public function getReceiptId()
    {
        return $this->getGatewayTransactionId();
    }

    public function processValidated()
    {
        if ($this->ipnVersion === 2) {
            PaymentGatewayAppIpnV2State::process(
                $this->invoice,
                $this->getPlugin()->getDi()->db,
                $this->getGatewayTransactionId(),
                $this->parsedRequest,
                $this->rawRequestBody,
                function () {
                    $this->applyPaymentEffect();
                }
            );
            echo "OK";
            http_response_code(200);
            return;
        }

        $this->applyPaymentEffect();
        // Send HTTP 200 response with "OK" body
        echo "OK";
        http_response_code(200);
    }

    private function applyPaymentEffect()
    {
        $status = isset($this->parsedRequest['status']) && is_numeric($this->parsedRequest['status'])
            ? (int)$this->parsedRequest['status']
            : null;
        $disputeStatus = $this->ipnVersion === 2 ? '' : $this->getDisputeStatus();
        if ($this->isSupportedDisputeStatus($disputeStatus)) {
            $this->logDisputeUpdate($disputeStatus);
            if ($disputeStatus !== 'won') {
                $this->addChargebackIdempotently();
            }
            return;
        }

        switch ($status) {
            case 0: // pending
            case -1: // initiated
                // do nothing for pending/initiated
                break;
            case 1: // successful
                if (
                    $this->invoice->status != Invoice::PAID
                    && ($this->ipnVersion !== 2 || !$this->hasExistingPaymentReceipt())
                ) {
                    $this->invoice->addPayment($this);
                }
                break;
            case 2: // failed
                if (
                    $this->invoice->status == Invoice::PAID
                    && ($this->ipnVersion !== 2 || !$this->hasExistingRefundReceipt(InvoiceRefund::VOID))
                ) {
                    $this->invoice->addVoid($this, $this->getReceiptId());
                }
                break;
            case 3: // refunded
                if (
                    $this->invoice->status == Invoice::PAID
                    && ($this->ipnVersion !== 2 || !$this->hasExistingRefundReceipt(InvoiceRefund::REFUND))
                ) {
                    $this->invoice->addRefund($this, $this->getReceiptId());
                }
                break;
            case 4: // chargeback
                if ($disputeStatus === 'won') {
                    break;
                }
                $this->addChargebackIdempotently();
                break;
            case -2: // cancelled
                if ($this->invoice->status != Invoice::CANCELLED && $this->invoice->status != Invoice::PAID) {
                    $this->invoice->setCancelled(true);
                }
                break;
            default:
                // Do nothing for other statuses
                break;
        }
    }
}
