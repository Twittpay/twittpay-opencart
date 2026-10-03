<?php

declare(strict_types=1);

namespace Opencart\Catalog\Controller\Extension\Twittpay\Payment;

class Twittpay extends \Opencart\System\Engine\Controller
{
    private const ENDPOINT = 'https://checkout.twittpay.com';

    public function index(): string
    {
        $this->load->language('extension/twittpay/payment/twittpay');

        return $this->load->view('extension/twittpay/payment/twittpay', [
            'button_confirm' => $this->language->get('button_confirm'),
            'text_loading'   => $this->language->get('text_loading'),
            'language'       => $this->config->get('config_language'),
        ]);
    }

    /** Start the payment: create it on TwittPay and hand back the checkout link. */
    public function confirm(): void
    {
        $this->load->language('extension/twittpay/payment/twittpay');
        $this->load->model('checkout/order');

        $orderId   = (int) ($this->session->data['order_id'] ?? 0);
        $orderInfo = $orderId ? $this->model_checkout_order->getOrder($orderId) : [];

        if (!$orderInfo) {
            $this->json(['error' => $this->language->get('error_order')]);
            return;
        }

        $amount = $this->expectedAmount($orderInfo);
        if ($amount === null) {
            $this->json(['error' => $this->language->get('error_currency')]);
            return;
        }

        $payload = [
            'cus_name'    => trim(($orderInfo['firstname'] ?? '') . ' ' . ($orderInfo['lastname'] ?? '')) ?: 'Customer',
            'cus_email'   => $orderInfo['email'] ?: 'default@gmail.com',
            'amount'      => number_format($amount, 2, '.', ''),
            'success_url' => $this->url->link('extension/twittpay/payment/twittpay.callback', 'order_id=' . $orderId, true),
            'cancel_url'  => $this->url->link('checkout/checkout', '', true),
            'webhook_url' => $this->url->link('extension/twittpay/payment/twittpay.webhook', '', true),
            'metadata'    => ['order_id' => (string) $orderId],
        ];

        [$code, $result] = $this->call('/api/payment/create', $payload);

        if ($code === 0) {
            $this->json(['error' => $this->language->get('error_connection')]);
            return;
        }

        if (!is_array($result) || empty($result['payment_url'])) {
            $this->json(['error' => $this->language->get('error_payment')]);
            return;
        }

        $this->model_checkout_order->addHistory(
            $orderId,
            (int) $this->config->get('payment_twittpay_pending_status_id'),
            'Waiting for TwittPay payment.'
        );

        $this->json(['success' => true, 'payment_url' => $result['payment_url']]);
    }

    /** The customer comes back here after paying or cancelling. */
    public function callback(): void
    {
        $transactionId = trim((string) ($this->request->get['transactionId'] ?? ''));
        $orderId       = (int) ($this->request->get['order_id'] ?? 0);

        if ($transactionId === '') {
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
            return;
        }

        $state = $this->settle($transactionId, $orderId);

        if ($state === 'completed' || $state === 'pending') {
            $this->response->redirect($this->url->link('checkout/success', '', true));
            return;
        }

        $this->response->redirect($this->url->link('checkout/failure', '', true));
    }

    /** Server to server notice. It is never trusted: the payment is verified again. */
    public function webhook(): void
    {
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body) || !$body) {
            $body = $this->request->post;
        }

        $transactionId = '';
        foreach (['transactionId', 'transaction_id'] as $key) {
            if (!empty($body[$key])) {
                $transactionId = trim((string) $body[$key]);
                break;
            }
        }

        if ($transactionId !== '') {
            $this->settle($transactionId, 0);
        }

        http_response_code(200);
        echo 'OK';
    }

    /**
     * Verify with TwittPay and update the order. Returns completed, pending or failed.
     */
    private function settle(string $transactionId, int $orderFromReturn): string
    {
        [$code, $result] = $this->call('/api/payment/verify', ['transaction_id' => $transactionId]);

        if ($code !== 200 || !is_array($result)) {
            return 'failed';
        }

        $meta = $result['metadata'] ?? [];
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }
        $orderId = (int) (is_array($meta) ? ($meta['order_id'] ?? 0) : 0);

        // The payment must belong to this order.
        if ($orderId <= 0 || ($orderFromReturn > 0 && $orderFromReturn !== $orderId)) {
            return 'failed';
        }

        $this->load->model('checkout/order');
        $orderInfo = $this->model_checkout_order->getOrder($orderId);
        if (!$orderInfo) {
            return 'failed';
        }

        $status    = strtoupper(trim((string) ($result['status'] ?? '')));
        $completed = (int) $this->config->get('payment_twittpay_completed_status_id');
        $pending   = (int) $this->config->get('payment_twittpay_pending_status_id');

        if ($status === 'COMPLETED') {
            $expected = $this->expectedAmount($orderInfo);
            $paid     = (float) ($result['amount'] ?? 0);

            if ($expected === null || $paid + 0.01 < $expected) {
                return 'failed';
            }

            // Mark it paid once only.
            if ((int) $orderInfo['order_status_id'] !== $completed) {
                $this->model_checkout_order->addHistory(
                    $orderId,
                    $completed,
                    'Payment completed via TwittPay. Transaction ID: ' . $transactionId,
                    true
                );
            }

            return 'completed';
        }

        if ($status === 'PENDING') {
            return 'pending';
        }

        return 'failed';
    }

    /** What the order is worth in BDT, or null if the currency is not supported. */
    private function expectedAmount(array $orderInfo): ?float
    {
        $currency = strtoupper((string) $orderInfo['currency_code']);
        $total    = (float) $this->currency->format($orderInfo['total'], $currency, $orderInfo['currency_value'], false);

        if ($currency === 'BDT') {
            return $total;
        }

        if ($currency === 'USD') {
            $rate = (float) $this->config->get('payment_twittpay_usd_rate');
            return $rate > 0 ? round($total * $rate, 2) : null;
        }

        return null;
    }

    /** One JSON POST to TwittPay. Returns [http code, decoded body]. */
    private function call(string $path, array $payload): array
    {
        $ch = curl_init(self::ENDPOINT . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'API-KEY: ' . trim((string) $this->config->get('payment_twittpay_api_key')),
            ],
        ]);

        $response = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$code, is_string($response) ? json_decode($response, true) : null];
    }

    private function json(array $json): void
    {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}
