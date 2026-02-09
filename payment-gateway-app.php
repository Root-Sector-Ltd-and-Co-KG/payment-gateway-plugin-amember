<?php
/**
 * @table paysystems
 * @id payment-gateway-app
 * @title Payment Gateway App
 * @visible_link https://payment-gateway.app
 * @recurring none
 * @am_payment_api 6.0
 */
class Am_Paysystem_PaymentGatewayApp extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = 'dev';

    protected $defaultTitle = 'Secure Checkout via payment-gateway.app';
    protected $defaultDescription = 'Pay securely with credit/debit cards, crypto, bank transfer, or local options.';

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
                    'itemType' => 'virtual',
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

        $log = $this->logRequest($httpRequest);
        $response = $httpRequest->send();
        $log->add($response);

        $responseCode = $response->getStatus();
        $responseBody = json_decode($response->getBody(), true);

        if ($responseCode !== 200) {
            $errorMessage = isset($responseBody['error']) ? $responseBody['error'] : $response->getBody();
            if ($responseCode === 401) {
                throw new Am_Exception_FatalError("Authentication failed. Please check your API Key configuration.");
            }
            throw new Am_Exception_FatalError("Payment session creation failed: " . $errorMessage);
        }

        if (!isset($responseBody['paymentUrl'])) {
            $errorMessage = isset($responseBody['error']) ? $responseBody['error'] : 'missing paymentUrl in response';
            $result->setFailed('Payment session creation failed. Reason: ' . $errorMessage);
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

    public function validateSource()
    {
        $raw_body = $this->request->getRawBody();
        $this->parsedRequest = json_decode($raw_body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($this->parsedRequest)) {
            $this->getPlugin()->logError("IPN: Invalid JSON received.", $raw_body);
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

        if (!isset($this->parsedRequest['id'], $this->parsedRequest['externalReference'], $this->parsedRequest['status'])) {
            $this->getPlugin()->logError("IPN: Missing required fields.", $this->parsedRequest);
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
        if (!isset($this->parsedRequest['status']) || !is_numeric($this->parsedRequest['status'])) {
            $this->getPlugin()->logError("IPN: Invalid status field.", $this->parsedRequest);
            return false;
        }
        $status = (int)$this->parsedRequest['status'];
        // Status is now an integer
        return in_array($status, [-2, -1, 0, 1, 2, 3, 4]);
    }

    public function findInvoiceId()
    {
        return $this->parsedRequest['externalReference'];
    }

    public function getUniqId()
    {
        return $this->parsedRequest['id']; // Use the main transaction ID
    }

    public function processValidated()
    {
        switch ($this->parsedRequest['status']) {
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
                $this->invoice->addChargeback($this, $this->getUniqId());
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
