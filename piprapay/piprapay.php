<?php

/**
 * PipraPay FOSSBilling Gateway Module
 *
 * Copyright (c) 2025 piprapay
 * Website: https://piprapay.com
 * Email: support@piprapay.com
 * Developer: piprapay
 * 
 */

class Payment_Adapter_piprapay extends Payment_AdapterAbstract implements \FOSSBilling\InjectionAwareInterface
{
    private $config = [];

    protected ?\Pimple\Container $di;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function __construct($config)
    {
        $this->config = $config;

        if (!isset($this->config['api_key'])) {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'piprapay', ':missing' => 'API KEY']);
        }

        if (!isset($this->config['api_url'])) {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'piprapay', ':missing' => 'API URL']);
        }

        if (!isset($this->config['currency'])) {
            $this->config['currency'] = 'BDT';
        }
    }

    public static function getConfig()
    {
        return [
            'supports_one_time_payments'   => true,
            'supports_subscriptions'       => false,
            'description'                => 'Accept payments via piprapay',
            'logo' => [
                'logo' => 'piprapay/favicon.png',
                'height' => '50px',
                'width' => '50px',
            ],
            'form'  => [
                'api_key' => [
                    'text', [
                        'label' => 'API key:',
                        'required' => true,
                    ],
                ],
                'api_url' => [
                    'text', [
                        'label' => 'API URL:',
                        'required' => true,
                        'value' => 'https://sandbox.piprapay.com',
                    ],
                ],
                'currency' => [
                    'text', [
                        'label' => 'Currency (BDT/USD):',
                        'required' => true,
                        'value' => 'BDT',
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $invoice = $api_admin->invoice_get(['id' => $invoice_id]);
        $data = $this->preparePaymentData($invoice);
        $paymentUrl = $this->createCharge($data);
        
        return $this->generatePaymentForm($paymentUrl);
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $ipn = $this->validateIpn($data);
        
        if (!$ipn) {
            throw new Payment_Exception('Invalid IPN request');
        }

        $payment = $this->verifyPayment($ipn['pp_id']);
        
        if ($payment['status'] !== 'completed') {
            throw new Payment_Exception('Payment not completed');
        }

        $invoice = $this->di['db']->getExistingModelById('Invoice', $payment['metadata']['invoiceid'], 'Invoice not found');
        $transaction = $this->di['db']->getExistingModelById('Transaction', $id, 'Transaction not found');

        $tx_data = [
            'id'            => $id,
            'invoice_id'   => $invoice->id,
            'txn_status'   => $payment['status'],
            'txn_id'        => $payment['transaction_id'],
            'amount'       => $payment['amount'],
            'currency'      => $payment['currency'],
            'type'         => $payment['payment_method'],
            'status'       => 'complete',
        ];

        $transactionService = $this->di['mod_service']('Invoice', 'Transaction');
        $transactionService->update($transaction, $tx_data);

        $bd = [
            'amount'        => $payment['amount'],
            'description'   => $payment['payment_method'] . ' Transaction ID: ' . $payment['transaction_id'],
            'type'          => 'transaction',
            'rel_id'        => $transaction->id,
        ];

        $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id, 'Client not found');
        $clientService = $this->di['mod_service']('client');
        $clientService->addFunds($client, $bd['amount'], $bd['description'], $bd);

        $invoiceService = $this->di['mod_service']('Invoice');
        $invoiceService->payInvoiceWithCredits($invoice);
        $invoiceService->doBatchPayWithCredits(['client_id' => $invoice->client_id]);

        return true;
    }

    private function preparePaymentData($invoice)
    {
        $client = $invoice['client'];
        
        return [
            'full_name'         => $client['first_name'] . ' ' . $client['last_name'],
            'email_mobile'      => $client['email'],
            'amount'           => $invoice['total'],
            'currency'          => $this->config['currency'],
            'metadata'         => [
                'invoiceid'     => $invoice['id'],
            ],
            'redirect_url'      => $this->di['tools']->url('invoice/'.$invoice['id']),
            'return_type'       => 'GET',
            'cancel_url'        => $this->config['cancel_url'],
            'webhook_url'       => $this->config['notify_url'],
        ];
    }

    private function generatePaymentForm($paymentUrl)
    {
        $form = '<form action="' . $paymentUrl . '" method="GET" id="payment_form">';
        $form .= '<input class="bb-button bb-button-submit" type="submit" value="Pay with piprapay" id="payment_button"/>';
        $form .= '</form>';
        
        if (isset($this->config['auto_redirect']) && $this->config['auto_redirect']) {
            $form .= '<script>document.getElementById("payment_form").submit();</script>';
        }
        
        return $form;
    }

    private function createCharge($data)
    {
        $url = rtrim($this->config['api_url'], '/') . '/api/create-charge';
        
        $response = $this->makeApiRequest($url, $data);
        
        if (isset($response['status']) && $response['status'] === true && isset($response['pp_url'])) {
            return $response['pp_url'];
        }
        
        throw new Payment_Exception('Failed to create payment: ' . ($response['message'] ?? 'Unknown error'));
    }

    private function verifyPayment($pp_id)
    {
        $url = rtrim($this->config['api_url'], '/') . '/api/verify-payments';
        $data = ['pp_id' => $pp_id];
        
        $response = $this->makeApiRequest($url, $data);
        
        if (isset($response['status']) && $response['status'] === "completed") {
            return $response;
        }
        
        throw new Payment_Exception('Failed to verify payment: ' . ($response['message'] ?? 'Unknown error'));
    }

    private function validateIpn($data)
    {
        // Check for raw POST data first
        $rawData = file_get_contents("php://input");
        if (!empty($rawData)) {
            $ipn = json_decode($rawData, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $ipn;
            }
        }
        
        // Fall back to GET data if POST is empty
        if (isset($data['get']) && !empty($data['get'])) {
            return $data['get'];
        }
        
        return false;
    }

    private function makeApiRequest($url, $data)
    {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'content-type: application/json',
                'mh-piprapay-api-key: ' . $this->config['api_key'],
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Payment_Exception('cURL error: ' . $error);
        }
        
        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Payment_Exception('Invalid JSON response from API');
        }
        
        return $result;
    }

    private function getAuthorizationHeader()
    {
        $headers = null;
        if (isset($_SERVER['Authorization'])) {
            $headers = trim($_SERVER['Authorization']);
        } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
        } elseif (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
            }
        }
        return $headers;
    }
}