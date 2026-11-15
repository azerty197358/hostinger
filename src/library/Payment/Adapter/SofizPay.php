<?php

declare(strict_types=1);
/**
 * SofizPay payment adapter for Adyos (based on FOSSBilling).
 */
use Box\Mod\Invoice\Entity\Invoice;
use Box\Mod\Invoice\Entity\Transaction;
use Pimple\Container;

class Payment_Adapter_SofizPay implements FOSSBilling\Core\Container\InjectionAwareInterface
{
    protected ?Container $di = null;

    public function setDi(Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Container
    {
        return $this->di;
    }

    public function __construct(private $config)
    {
        if (!isset($this->config['account']) || trim((string) $this->config['account']) === '') {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'SofizPay', ':missing' => 'SofizPay account'], 4001);
        }
    }

    public static function requiresManualApproval(): bool
    {
        return false;
    }

    public static function getConfig(): array
    {
        return [
            'can_load_in_iframe' => false,
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Accept Algerian CIB and Edahabia card payments through SofizPay.',
            'logo' => [
                'logo' => 'sofizpay.png',
                'height' => '40px',
                'width' => '100px',
            ],
            'form' => [
                'account' => [
                    'text', [
                        'label' => 'SofizPay receiving account (public key):',
                        'required_when' => ['enabled' => true],
                    ],
                ],
                'language' => [
                    'select', [
                        'label' => 'Checkout language:',
                        'multi_options' => [
                            'ar' => 'Arabic',
                            'fr' => 'French',
                            'en' => 'English',
                        ],
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $invoiceModel = $this->di['em']->getRepository(Invoice::class)->find($invoice_id);
        if (!$invoiceModel instanceof Invoice) {
            throw new Payment_Exception('Invoice not found');
        }

        $invoiceService = $this->di['mod_service']('Invoice');
        $invoice = $invoiceService->toApiArray($invoiceModel, true);

        $client = is_array($invoice['client'] ?? null) ? $invoice['client'] : [];
        $fullName = trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? ''));
        if ($fullName === '') {
            $fullName = (string) ($client['name'] ?? ('Client #' . $invoiceModel->getClientId()));
        }
        $email = (string) ($client['email'] ?? '');
        $phone = (string) ($client['phone'] ?? ($client['phone_cc'] ?? ''));

        $amount = (float) ($invoice['total'] ?? $invoiceService->getTotalWithTax($invoiceModel));
        $currency = (string) ($invoice['currency'] ?? $invoiceModel->getCurrency());

        $isSandbox = $this->testMode;
        $base = $isSandbox
            ? 'https://sofizpay.com/sandbox/make-cib-transaction/'
            : 'https://sofizpay.com/make-cib-transaction/';

        $params = [
            'account' => (string) $this->config['account'],
            'amount' => $amount,
            'full_name' => $fullName,
            'phone' => $phone !== '' ? $phone : '0000000000',
            'email' => $email !== '' ? $email : 'client@example.com',
            'return_url' => (string) $this->getParam('redirect_url'),
            'webhook_url' => (string) $this->getParam('notify_url'),
            'invoice_id' => (string) $invoice_id,
            'language' => (string) ($this->config['language'] ?? 'fr'),
            'memo' => 'Invoice #' . $invoice_id,
            'redirect' => 'yes',
            'keep_return_url' => 'True',
        ];

        $url = $base . '?' . http_build_query($params);

        $paymentUrl = null;
        try {
            $response = $this->getHttpClient()->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
            $data = json_decode($response->getContent(false), true);
            if (is_array($data)) {
                $paymentUrl = $data['payment_url'] ?? ($data['cib_response']['formUrl'] ?? null);
            }
        } catch (\Throwable $e) {
            $this->getLog()->error('SofizPay getHtml request failed: ' . $e->getMessage());
        }

        if (!is_string($paymentUrl) || $paymentUrl === '') {
            $paymentUrl = $url;
        }

        $safeUrl = htmlspecialchars($paymentUrl, ENT_QUOTES, 'UTF-8');

        return '<script>window.location.href = ' . json_encode($paymentUrl) . ';</script>'
            . '<noscript><meta http-equiv="refresh" content="0;url=' . $safeUrl . '"><a href="' . $safeUrl . '">Continue to payment</a></noscript>';
    }

    public function processTransaction($api_admin, int $id, array $data, int $gateway_id): void
    {
        $get = (isset($data['get']) && is_array($data['get'])) ? $data['get'] : [];
        $post = (isset($data['post']) && is_array($data['post'])) ? $data['post'] : [];
        $merged = array_merge($get, $post);

        $tx = $this->di['em']->getRepository(Transaction::class)->find($id);
        $invoiceModel = $tx?->getInvoice();
        if (!$invoiceModel instanceof Invoice) {
            $invoiceId = (int) ($merged['invoice_id'] ?? 0);
            if ($invoiceId > 0) {
                $invoiceModel = $this->di['em']->getRepository(Invoice::class)->find($invoiceId);
            }
        }
        if (!$invoiceModel instanceof Invoice) {
            throw new Payment_Exception('Invoice not found');
        }

        $orderNumber = $merged['order_number'] ?? ($merged['cib_transaction_id'] ?? ($merged['transaction_id'] ?? ($merged['order_id'] ?? null)));

        $paid = false;
        if (is_string($orderNumber) && $orderNumber !== '') {
            $isSandbox = $this->testMode;
            $base = $isSandbox
                ? 'https://sofizpay.com/sandbox/cib-transaction-check/'
                : 'https://sofizpay.com/cib-transaction-check/';
            try {
                $response = $this->getHttpClient()->request('GET', $base . '?order_number=' . urlencode($orderNumber), [
                    'headers' => ['Accept' => 'application/json'],
                ]);
                $respData = json_decode($response->getContent(false), true);
                if (is_array($respData)) {
                    $paid = ($respData['errorCode'] ?? null) === 0
                        || ($respData['orderStatus'] ?? null) === 2
                        || ($respData['status'] ?? '') === 'success'
                        || ($respData['respCode'] ?? '') === '00';
                }
            } catch (\Throwable $e) {
                $this->getLog()->error('SofizPay processTransaction check failed: ' . $e->getMessage());
            }
        } else {
            $status = strtolower((string) ($merged['status'] ?? ''));
            $paid = in_array($status, ['success', 'paid', 'completed', 'approved'], true);
        }

        if (!$paid) {
            throw new Payment_Exception('SofizPay payment is not completed');
        }

        $invoiceService = $this->di['mod_service']('Invoice');
        $invoiceService->markAsPaid($invoiceModel, true, true);

        if ($tx instanceof Transaction) {
            $tx->setStatus(Transaction::STATUS_PROCESSED);
            $tx->setUpdatedAt(new DateTime());
            $this->di['em']->flush();
        }
    }

    public function getInvoiceId($data)
    {
        $get = (isset($data['get']) && is_array($data['get'])) ? $data['get'] : [];
        $post = (isset($data['post']) && is_array($data['post'])) ? $data['post'] : [];
        $invoiceId = $get['invoice_id'] ?? ($post['invoice_id'] ?? null);
        return $invoiceId !== null ? (int) $invoiceId : null;
    }
}
