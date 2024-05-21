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
        $form->addText('payment_url')
            ->setLabel(___("Payment System URL\n" .
                'This is the payment URL of multi-payment-gateway. (Example: http://example.com/multi-payment-gateway/)'))
            ->addRule('required');
        $form->addText('encryption_key', array('size' => 100))
            ->setLabel("Encryption Key")
            ->addRule('required');
    }

    public function _process($invoice, $request, $result)
    {
        $payment_session_url = rtrim($this->getConfig('payment_url'), '/') . '/' . 'create-payment-session.php';
        $request = new Am_HttpRequest($payment_session_url, Am_HttpRequest::METHOD_POST);
        $hashData = array(
            'amount' => $invoice->first_total,
            'currency' => $invoice->currency,
            'email' => $invoice->getEmail(),
            'custominvoiceid' => $invoice->public_id,
            'returnurl' => $this->getPluginUrl('thanks'),
            'cancelurl' => $this->getCancelUrl(),
            'ipnurl' => $this->getPluginUrl('ipn'),
        );

        ksort($hashData);

        $hashString = implode('', $hashData);
        $computedHash = hash_hmac('sha256', $hashString, $this->getConfig('encryption_key'));
        $hashData['hash'] = $computedHash;

        $request->addPostParameter($hashData);
        $log = $this->logRequest($request);
        $response = $request->send();
        $log->add($response);

        if ($response->getStatus() == 406) {
            $responseBody = json_decode($response->getBody(), true);
            $errorMessage = isset($responseBody['error']) ? $responseBody['error'] : 'Unknown error';
            $result->setFailed('Payment session creation failed. Reason: ' . $errorMessage);
            return;
        }

        if ($response->getStatus() != 201) {
            throw new Am_Exception_InternalError("Can't create payment session. Got:" . $response->getBody());
        }

        $r = json_decode($response->getBody(), true);
        if (!isset($r['sid'])) {
            $result->setFailed('Payment session creation failed. Reason: ' . $r['error']);
            return;
        }

        $payment_url = rtrim($this->getConfig('payment_url'), '/') . '/' . 'index.php?sid=' . $r['sid'];
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

    - Enter URL of Multi Payment Gateway in "Payment System URL".
    - Get your Encryption Key (app_secret) from your Multi Payment Gateway and enter it in "Encryption Key".
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
        return $this->request->get('custominvoiceid');
    }

    public function getUniqId()
    {
        return $this->request->get('transactionid');
    }

    public function validateStatus()
    {
        $status = $this->request->get('status');
        return in_array($status, ['0', '1', '2', '4']);
    }

    public function validateTerms()
    {
        return true;
    }

    public function validateSource()
    {
        $hashData = array(
            'status' => $this->request->get('status'),
            'transactionid' => $this->request->get('transactionid'),
            'custominvoiceid' => $this->request->get('custominvoiceid'),
        );

        ksort($hashData);
        $hashString = implode('', $hashData);
        $computedHash = hash_hmac('sha256', $hashString, $this->getPlugin()->getConfig('encryption_key'));

        return hash_equals($computedHash, $this->request->get('hash'));
    }

    public function getInvoice()
    {
        return $this->invoice;
    }

    public function processValidated()
    {
        switch ($this->request->get('status')) {
            case "0": // pending
                $v = new Am_View;
                $v->title = ___("Payment pending / Zahlung ausstehend / Paiement en attente / Betaling is in behandeling / Pagamento in sospeso / Pago pendiente");
                $v->content = sprintf("<p>%s</p>", ___('English: <br>Although your payment was processed, but the result is still pending. Usually this process complete within 3 days. We\'ll send you an email after the money has been credited to our account. For this order there is no further payment necessary.<br><br>
                                                                Deutsch: <br>Deine Zahlung wurde zwar verarbeitet, das Ergebnis steht jedoch noch aus. Normalerweise ist dieser Vorgang innerhalb von 3 Tagen abgeschlossen. Wir senden Dir eine E-Mail, sobald das Geld auf unserem Konto gutgeschrieben wurde. Für diese Bestellung ist keine weitere Zahlung erforderlich.<br><br>
                                                                Français: <br> Bien que votre paiement ait été traité, le résultat est toujours en attente. Habituellement, ce processus se termine dans les 3 jours. Nous vous enverrons un courrier électronique une fois que l\'argent aura été crédité sur notre compte. Pour cette commande, aucun paiement supplémentaire n\'est nécessaire.<br><br>
                                                                Nederlands: <br> Hoewel uw betaling is verwerkt, maar het resultaat nog steeds in behandeling is. Meestal is dit proces binnen 3 dagen voltooid. We sturen u een e-mail nadat het geld op onze rekening is bijgeschreven. Voor deze bestelling is er geen verdere betaling nodig.<br><br>
                                                                Italiano: <br> Sebbene il pagamento sia stato elaborato, ma il risultato è ancora in sospeso. Di solito questo processo viene completato entro 3 giorni. Ti invieremo un\'email dopo che il denaro è stato accreditato sul nostro conto. Per questo ordine non è necessario alcun ulteriore pagamento.<br><br>
                                                                Español: <br> Aunque su pago fue procesado, pero el resultado aún está pendiente. Por lo general, este proceso se completa dentro de 3 días. Le enviaremos un correo electrónico una vez que el dinero haya sido acreditado en nuestra cuenta. Para este pedido no hay pago adicional necesario.<br><br>'));
                $v->display('layout.phtml');
                throw new Am_Exception_Redirect;
                break;
            case '1': // paid
                if ($this->invoice->status != Invoice::PAID) {
                    $this->invoice->addPayment($this);
                }
                break;
            case '2': // failed
                if ($this->invoice->status == Invoice::PAID) {
                    $this->invoice->addVoid($this, $this->getUniqId());
                }
                break;
            case '3': // refund
                if ($this->invoice->status == Invoice::PAID) {
                    $this->invoice->addRefund($this, $this->getUniqId());
                }
                break;
            case '4': // chargeback
                $this->invoice->setCancelled(true);
                $this->invoice->addChargeback($this, $this->getUniqId());
                break;
            default:
                // Do nothing for withdrawn, retried
                break;
        }
    }
}

class Am_Paysystem_Transaction_MultiPaymentGateway extends Am_Paysystem_Transaction_Incoming
{
    public function validateSource()
    {
        $hashData = array(
            'status' => $this->request->get('status'),
            'transactionid' => $this->request->get('transactionid'),
            'custominvoiceid' => $this->request->get('custominvoiceid'),
        );

        ksort($hashData);
        $hashString = implode('', $hashData);
        $computedHash = hash_hmac('sha256', $hashString, $this->getPlugin()->getConfig('encryption_key'));

        return hash_equals($computedHash, $this->request->get('hash'));
    }

    public function validateStatus()
    {
        $status = $this->request->get('status');
        return in_array($status, ['0', '1', '2', '4']);
    }

    public function findInvoiceId()
    {
        return $this->request->get('custominvoiceid');
    }

    public function getUniqId()
    {
        return $this->request->get('transactionid');
    }
    public function validateTerms()
    {
        return true;
    }

    public function processValidated()
    {
        switch ($this->request->get('status')) {
            case "0": // pending
                // do nothing
                break;
            case '1': // paid
                if ($this->invoice->status != Invoice::PAID) {
                    $this->invoice->addPayment($this);
                }
                break;
            case '2': // failed
                if ($this->invoice->status == Invoice::PAID) {
                    $this->invoice->addVoid($this, $this->getUniqId());
                }
                break;
            case '3': // refund
                if ($this->invoice->status == Invoice::PAID) {
                    $this->invoice->addRefund($this, $this->getUniqId());
                }
                break;
            case '4': // chargeback
                $this->invoice->setCancelled(true);
                $this->invoice->addChargeback($this, $this->getUniqId());
                break;
            default:
                // Do nothing for withdrawn, retried
                break;
        }
        http_response_code(200);
    }

}