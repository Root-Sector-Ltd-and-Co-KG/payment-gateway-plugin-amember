<?php
/**
 * @table paysystems
 * @id multi-payment-gateway
 * @title Multi Payment Gateway
 * @visible_link https://root-sector.com
 * @recurring none
 * @am_payment_api 6.0
 */
class Am_Paysystem_MultiPaymentGateway extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '1.0.0';

    protected $defaultTitle = 'multi-payment-gateway';
    protected $defaultDescription = 'Pay securely with your credit card, debit card or bank account.';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('mpg_main_backend_domain')
            ->setLabel(___("Default Main Backend Domain\n" .
                'Your Multi Payment Gateway main backend domain without the protocol. For example, use "example.com" instead of "https://example.com".'))
            ->addRule('required');
        $form->addText('site_secret_key', array('size' => 100))
            ->setLabel(___("Site Secret Key\n" .
                'Your Multi Payment Gateway site secret key.'))
            ->addRule('required');
    }

    public function _process($invoice, $request, $result)
    {
        $paymentSessionUrl = 'https://' . rtrim($this->getConfig('mpg_main_backend_domain'), '/') . '/api/v1/sessions/create';
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

        // Set the request headers
        $request->setHeader('Content-Type', 'application/json');
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
        $url = $this->getDi()->surl('payment/multi-payment-gateway/ipn');
        return <<<CUT
    <b>aMember Multi Payment Gateway plugin setup</b>

    - Enter the main backend domain for your Multi Payment Gateway configuration in "Default Main Backend Domain".
    - Get your Site Secret from your Multi Payment Gateway and enter it in "Site Secret".
CUT;
    }

    public function createThanksTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_MultiPaymentGateway_Thanks($this, $request, $response, $invokeArgs);
    }

    public function createTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_MultiPaymentGateway($this, $request, $response, $invokeArgs);
    }
}

class Am_Paysystem_Transaction_MultiPaymentGateway_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
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

class Am_Paysystem_Transaction_MultiPaymentGateway extends Am_Paysystem_Transaction_Incoming
{
    protected $parsedRequest;

    public function validateSource()
    {

        $this->parsedRequest = json_decode($this->request->getRawBody(), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($this->parsedRequest)) {
            // Log the JSON error for debugging
            error_log("JSON Error: " . json_last_error_msg());
            error_log("Raw Request Body: " . $this->request->getRawBody());
            throw new Am_Exception_Paysystem("Invalid JSON in request body");
        }

        if (!isset($this->parsedRequest['status'], $this->parsedRequest['transactionId'], $this->parsedRequest['customInvoiceId'])) {
            throw new Am_Exception_Paysystem("Missing required fields in response");
        }

        $hashData = array(
            'status' => $this->parsedRequest['status'],
            'transactionId' => $this->parsedRequest['transactionId'],
            'customInvoiceId' => $this->parsedRequest['customInvoiceId'],
        );

        ksort($hashData);
        $hashString = json_encode($hashData);
        $computedHash = hash_hmac('sha256', $hashString, $this->getPlugin()->getConfig('site_secret_key'));

        // Retrieve the hash from the X-Signature header
        $receivedHash = $this->request->getHeader('X-Signature');
        if (is_null($receivedHash)) {
            // Log or handle the missing hash case
            throw new Am_Exception_Paysystem("X-Signature header is missing");
        }

        return hash_equals($computedHash, $receivedHash);
    }

    public function validateTerms()
    {
        return true;
    }

    public function validateStatus()
    {
        $status = $this->parsedRequest['status'];
        return in_array($status, ['0', '1', '2', '4']);
    }

    public function findInvoiceId()
    {
        return $this->parsedRequest['customInvoiceId'];
    }

    public function getUniqId()
    {
        return $this->parsedRequest['transactionId'];
    }

    public function processValidated()
    {
        switch ($this->parsedRequest['status']) {
            case "0": // pending
                // do nothing
                break;
            case '1': // successful
                if ($this->invoice->status != Invoice::PAID) {
                    $this->invoice->addPayment($this);
                }
                break;
            case '2': // failed
                if ($this->invoice->status == Invoice::PAID) {
                    $this->invoice->addVoid($this, $this->getUniqId());
                }
                break;
            case '4': // chargeback
                $this->invoice->setCancelled(true);
                $this->invoice->addChargeback($this, $this->getUniqId());
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