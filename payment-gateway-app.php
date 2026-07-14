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

class Am_Paysystem_Transaction_PaymentGatewayApp extends Am_Paysystem_Transaction_Incoming
{
    protected $parsedRequest;

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
        return $this->getParsedScalar(array('id', 'transactionId', 'chargeback.transactionId', 'chargeback.gatewayTransactionId'));
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
        if (!isset($this->invoice)) {
            return false;
        }
        $transactionId = (string)$this->getUniqId();
        foreach ($this->invoice->getRefundRecords() as $refund) {
            if ((int)$refund->refund_type === InvoiceRefund::CHARGEBACK && (string)$refund->transaction_id === $transactionId) {
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
                'gatewayTransactionId' => $this->getUniqId(),
                'requestId' => $this->getRequestId(),
            ));
            return;
        }

        try {
            $this->invoice->addChargeback($this, $this->getUniqId());
        } catch (Exception $e) {
            if ($this->hasExistingChargeback()) {
                $this->getPlugin()->logOther('Payment Gateway App duplicate chargeback IPN accepted', array(
                    'invoice' => $this->invoice->public_id,
                    'gatewayTransactionId' => $this->getUniqId(),
                    'requestId' => $this->getRequestId(),
                ));
                return;
            }
            throw $e;
        }
    }

    public function validateSource()
    {
        $raw_body = $this->request->getRawBody();
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
        return $this->getGatewayTransactionId(); // Use the main transaction ID
    }

    public function processValidated()
    {
        $status = isset($this->parsedRequest['status']) && is_numeric($this->parsedRequest['status'])
            ? (int)$this->parsedRequest['status']
            : null;
        $disputeStatus = $this->getDisputeStatus();
        if ($this->isSupportedDisputeStatus($disputeStatus)) {
            $this->logDisputeUpdate($disputeStatus);
            if ($disputeStatus !== 'won') {
                $this->addChargebackIdempotently();
            }
            echo "OK";
            http_response_code(200);
            return;
        }

        switch ($status) {
            case 0: // pending
            case -1: // initiated
                // do nothing for pending/initiated
                break;
            case 1: // successful
                if ($this->invoice->status != Invoice::PAID) {
                    $this->invoice->addPayment($this);
                }
                break;
            case 2: // failed
                if ($this->invoice->status == Invoice::PAID) {
                    $this->invoice->addVoid($this, $this->getUniqId());
                }
                break;
            case 3: // refunded
                if ($this->invoice->status == Invoice::PAID) {
                    $this->invoice->addRefund($this, $this->getUniqId());
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
        // Send HTTP 200 response with "OK" body
        echo "OK";
        http_response_code(200);
    }
}
