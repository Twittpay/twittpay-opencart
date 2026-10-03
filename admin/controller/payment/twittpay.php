<?php

declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\Twittpay\Payment;

class Twittpay extends \Opencart\System\Engine\Controller
{
    private array $error = [];

    private array $keys = [
        'payment_twittpay_display_name',
        'payment_twittpay_api_key',
        'payment_twittpay_usd_rate',
        'payment_twittpay_completed_status_id',
        'payment_twittpay_pending_status_id',
        'payment_twittpay_status',
        'payment_twittpay_sort_order',
    ];

    public function index(): void
    {
        $this->load->language('extension/twittpay/payment/twittpay');

        $this->document->setTitle($this->language->get('heading_title'));

        $token = 'user_token=' . $this->session->data['user_token'];

        $data['breadcrumbs'] = [
            ['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token)],
            ['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=payment')],
            ['text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/twittpay/payment/twittpay', $token)],
        ];

        $data['save'] = $this->url->link('extension/twittpay/payment/twittpay.save', $token);
        $data['back'] = $this->url->link('marketplace/extension', $token . '&type=payment');

        foreach ($this->keys as $key) {
            $data[$key] = $this->config->get($key);
        }

        if ($data['payment_twittpay_usd_rate'] === null || $data['payment_twittpay_usd_rate'] === '') {
            $data['payment_twittpay_usd_rate'] = '120';
        }

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/twittpay/payment/twittpay', $data));
    }

    public function save(): void
    {
        $this->load->language('extension/twittpay/payment/twittpay');

        $json = [];

        if (!$this->user->hasPermission('modify', 'extension/twittpay/payment/twittpay')) {
            $json['error'] = $this->language->get('error_permission');
        }

        if (!$json && !$this->validate()) {
            $json['error'] = reset($this->error);
        }

        if (!$json) {
            $this->load->model('setting/setting');

            $post = [];
            foreach ($this->keys as $key) {
                $post[$key] = isset($this->request->post[$key]) ? trim((string) $this->request->post[$key]) : '';
            }

            $this->model_setting_setting->editSetting('payment_twittpay', $post);

            $json['success'] = $this->language->get('text_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    protected function validate(): bool
    {
        $this->error = [];

        if (trim((string) ($this->request->post['payment_twittpay_display_name'] ?? '')) === '') {
            $this->error['display_name'] = $this->language->get('error_display_name');
        }

        if (trim((string) ($this->request->post['payment_twittpay_api_key'] ?? '')) === '') {
            $this->error['api_key'] = $this->language->get('error_api_key');
        }

        $rate = (float) ($this->request->post['payment_twittpay_usd_rate'] ?? 0);
        if ($rate <= 0) {
            $this->error['usd_rate'] = $this->language->get('error_usd_rate');
        }

        return empty($this->error);
    }

    public function install(): void
    {
        $this->load->model('setting/setting');

        $this->model_setting_setting->editSetting('payment_twittpay', [
            'payment_twittpay_display_name'       => 'bKash, Nagad, Rocket, Upay',
            'payment_twittpay_api_key'            => '',
            'payment_twittpay_usd_rate'           => '120',
            'payment_twittpay_completed_status_id' => 5,
            'payment_twittpay_pending_status_id'  => 1,
            'payment_twittpay_status'             => 0,
            'payment_twittpay_sort_order'         => 1,
        ]);
    }

    public function uninstall(): void
    {
        $this->load->model('setting/setting');

        $this->model_setting_setting->deleteSetting('payment_twittpay');
    }
}
