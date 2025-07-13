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
    const PLUGIN_REVISION = '1.0.1';

    protected $defaultTitle = 'payment-gateway-app';
    protected $defaultDescription = 'Pay securely with your credit card, debit card or bank account.';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('api_domain')
            ->setLabel(___("API Domain\n" .
                'API Domain of your Payment Gateway App without protocol. Example: api.payment-gateway.app (instead of "https://api.payment-gateway.app").'))
            ->addRule('required');
        $form->addText('site_id')
            ->setLabel(___("Site ID\n" .
                'The Site ID shown in your Payment Gateway App dashboard under Sites.'))
            ->addRule('required');
        $form->addText('site_secret_key', array('size' => 100))
            ->setLabel(___("Site Secret Key\n" .
                'Related secret key for this site.'))
            ->addRule('required');
        $form->addAdvCheckbox('pass_billing_address')
            ->setLabel(___("Pass Billing Address\n" .
                'Send the customer’s billing address to the payment gateway app.'));
        $form->addAdvCheckbox('pass_items')
            ->setLabel(___("Pass Items\n" .
                'Send invoice line-items to the payment gateway app.'));
    }

    public function _process($invoice, $request, $result)
    {
        $paymentSessionUrl = 'https://' . rtrim($this->getConfig('api_domain'), '/') . '/api/v1/sessions/create';
        $request = new Am_HttpRequest($paymentSessionUrl, Am_HttpRequest::METHOD_POST);
        $amount = round($invoice->first_total * 100); // Convert to cents
        $hashData = array(
            'amount' => $amount,
            'currency' => $invoice->currency,
            'email' => $invoice->getEmail(),
            'customInvoiceId' => $invoice->public_id,
            //'returnUrl' => $this->getPluginUrl('thanks') . '?customInvoiceId=' . urlencode($invoice->public_id),
            'returnUrl' => $this->getReturnUrl(),
            'cancelUrl' => $this->getCancelUrl(),
            'ipnUrl' => $this->getPluginUrl('ipn'),
        );

        // Conditionally add billing and shipping fields
        if ($this->getConfig('pass_billing_address')) {
            $hashData['billingAddress'] = array(
                'firstName' => $invoice->getFirstName(),
                'lastName' => $invoice->getLastName(),
                'address1' => $invoice->getStreet(),
                'city' => $invoice->getCity(),
                'postcode' => $invoice->getZip(),
                'country' => $invoice->getCountry(),
            );
        }

        // Conditionally pass items as virtual
        if ($this->getConfig('pass_items')) {
            $items = array();
            foreach ($invoice->getItems() as $item) {
                $items[] = array(
                    'name' => $item->item_title,
                    'quantity' => $item->qty,
                    'amount' => round($item->first_total * 100),
                    'type' => 'virtual',
                );
            }
            $hashData['items'] = $items;
        }

        // Set the request headers
        $request->setHeader('Content-Type', 'application/json');
        $request->setHeader('Site-Id', $this->getConfig('site_id'));
        $request->setHeader('Site-Secret-Key', $this->getConfig('site_secret_key'));

        // Set the request body as JSON
        $request->setBody(json_encode($hashData));

        $log = $this->logRequest($request);
        $response = $request->send();
        $log->add($response);

        if ($response->getStatus() == 406) {
            $errorBody = json_decode($response->getBody(), true);
            if (isset($errorBody['error'])) {
                throw new Am_Exception_FatalError($errorBody['error']);
            }
            throw new Am_Exception_FatalError($response->getBody());
        } else if ($response->getStatus() != 200) {
            throw new Am_Exception_InternalError("Can't create payment session. Got:" . $response->getBody());
        }

        $r = json_decode($response->getBody(), true);
        if (!isset($r['paymentUrl'])) {
            $result->setFailed('Payment session creation failed. Reason: ' . $r['error']);
            return;
        }

        $payment_url = $r['paymentUrl'];
        $a = new Am_Paysystem_Action_Redirect($payment_url);
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
    2. Copy the Site ID and Site Secret Key from Payment Gateway App > Sites and paste them here.
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
        return $this->request->getFiltered('customInvoiceId');
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

        // Check if timestamp is recent (e.g., within 5 minutes) to prevent replay attacks.
        if (time() - (int)$received_timestamp > 300) {
            $this->getPlugin()->logError("IPN: Webhook timestamp is too old.", array('received_timestamp' => $received_timestamp));
            throw new Am_Exception_Paysystem("Webhook timestamp too old");
        }

        // Recreate the signature string.
        $string_to_sign = $received_timestamp . '.' . $raw_body;
        $computed_hash = hash_hmac('sha256', $string_to_sign, $this->getPlugin()->getConfig('site_secret_key'));

        // Securely compare the signatures.
        if (!hash_equals($computed_hash, $received_signature)) {
            $this->getPlugin()->logError("IPN: Invalid signature.", array(
                'string_to_hash' => $string_to_sign,
                'computed_hash' => $computed_hash,
                'received_signature' => $received_signature
            ));
            return false;
        }

        return true;
    }

    public function validateTerms()
    {
        return true;
    }

    public function validateStatus()
    {
        $status = $this->parsedRequest['status'];
        // Status is now an integer
        return in_array($status, [-2, -1, 0, 1, 2, 3, 4]);
    }

    public function findInvoiceId()
    {
        return $this->parsedRequest['customInvoiceId'];
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