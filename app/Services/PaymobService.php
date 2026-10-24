<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PaymobService
{
    public function isConfigured(): bool
    {
        return filled(config('paymob.api_key'))
            && filled(config('paymob.integration_id'))
            && filled(config('paymob.iframe_id'));
    }

    /**
     * @return array{checkout_url: string, order_id: string, payment_token: string}
     */
    public function createCheckout(float $amount, array $billingData, string $merchantOrderId): array
    {
        $authToken = $this->authToken();
        $amountCents = (int) round($amount * 100);

        $orderResponse = Http::post('https://accept.paymob.com/api/ecommerce/orders', [
            'auth_token' => $authToken,
            'delivery_needed' => false,
            'amount_cents' => $amountCents,
            'currency' => config('paymob.currency', 'EGP'),
            'merchant_order_id' => $merchantOrderId,
            'items' => [],
        ])->throw()->json();

        $orderId = (string) ($orderResponse['id'] ?? '');

        $paymentKeyResponse = Http::post('https://accept.paymob.com/api/acceptance/payment_keys', [
            'auth_token' => $authToken,
            'amount_cents' => $amountCents,
            'expiration' => 3600,
            'order_id' => $orderId,
            'billing_data' => array_merge([
                'apartment' => 'NA',
                'email' => 'student@eltaisir.com',
                'floor' => 'NA',
                'first_name' => 'Student',
                'street' => 'NA',
                'building' => 'NA',
                'phone_number' => '01000000000',
                'shipping_method' => 'NA',
                'postal_code' => 'NA',
                'city' => 'Cairo',
                'country' => 'EG',
                'last_name' => 'Eltaisir',
                'state' => 'Cairo',
            ], $billingData),
            'currency' => config('paymob.currency', 'EGP'),
            'integration_id' => (int) config('paymob.integration_id'),
        ])->throw()->json();

        $paymentToken = (string) ($paymentKeyResponse['token'] ?? '');
        $iframeId = config('paymob.iframe_id');

        return [
            'checkout_url' => "https://accept.paymob.com/api/acceptance/iframes/{$iframeId}?payment_token={$paymentToken}",
            'order_id' => $orderId,
            'payment_token' => $paymentToken,
        ];
    }

    public function verifyCallbackHmac(array $payload): bool
    {
        $secret = config('paymob.hmac_secret');
        if (empty($secret)) {
            return app()->environment('local');
        }

        $obj = $payload['obj'] ?? [];
        $fields = [
            $obj['amount_cents'] ?? '',
            $obj['created_at'] ?? '',
            $obj['currency'] ?? '',
            $obj['error_occured'] ?? '',
            $obj['has_parent_transaction'] ?? '',
            $obj['id'] ?? '',
            $obj['integration_id'] ?? '',
            $obj['is_3d_secure'] ?? '',
            $obj['is_auth'] ?? '',
            $obj['is_capture'] ?? '',
            $obj['is_refunded'] ?? '',
            $obj['is_standalone_payment'] ?? '',
            $obj['is_voided'] ?? '',
            $obj['order']['id'] ?? '',
            $obj['owner'] ?? '',
            $obj['pending'] ?? '',
            $obj['source_data']['pan'] ?? '',
            $obj['source_data']['sub_type'] ?? '',
            $obj['source_data']['type'] ?? '',
            $obj['success'] ?? '',
        ];

        $calculated = hash_hmac('sha512', implode('', $fields), $secret);

        return hash_equals($calculated, (string) ($payload['hmac'] ?? ''));
    }

    private function authToken(): string
    {
        $response = Http::post('https://accept.paymob.com/api/auth/tokens', [
            'api_key' => config('paymob.api_key'),
        ])->throw()->json();

        return (string) ($response['token'] ?? Str::uuid());
    }
}
