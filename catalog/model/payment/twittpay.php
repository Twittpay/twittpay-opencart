<?php

declare(strict_types=1);

namespace Opencart\Catalog\Model\Extension\Twittpay\Payment;

class Twittpay extends \Opencart\System\Engine\Model
{
    public function getMethods(array $address): array
    {
        $this->load->language('extension/twittpay/payment/twittpay');

        if (!$this->config->get('payment_twittpay_status')) {
            return [];
        }

        $currency = strtoupper((string) ($this->session->data['currency'] ?? $this->config->get('config_currency')));
        if (!in_array($currency, ['BDT', 'USD'], true)) {
            return [];
        }

        $name = (string) $this->config->get('payment_twittpay_display_name');

        return [
            'code'       => 'twittpay',
            'name'       => $name,
            'option'     => [
                'twittpay' => [
                    'code' => 'twittpay.twittpay',
                    'name' => $name,
                ],
            ],
            'sort_order' => $this->config->get('payment_twittpay_sort_order'),
        ];
    }
}
