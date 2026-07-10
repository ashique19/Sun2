<?php

namespace App\Services\Couriers;

class CourierApiRegistry
{
    /**
     * @return list<string>
     */
    public function apiSlugs(): array
    {
        return config('couriers.api_slugs', []);
    }

    public function isConfigured(string $slug): bool
    {
        return match ($slug) {
            'steadfast' => (bool) config('steadfast.api_key') && (bool) config('steadfast.secret_key'),
            'pathao' => config('pathao.enabled')
                && (bool) config('pathao.client_id')
                && (bool) config('pathao.client_secret')
                && (bool) config('pathao.username')
                && (bool) config('pathao.password')
                && (int) config('pathao.store_id') > 0,
            'redx' => config('redx.enabled')
                && (bool) config('redx.api_token'),
            'carrybee' => config('carrybee.enabled')
                && (bool) config('carrybee.client_id')
                && (bool) config('carrybee.client_secret')
                && (bool) config('carrybee.client_context')
                && (bool) config('carrybee.store_id'),
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public function configuredSlugs(): array
    {
        return array_values(array_filter(
            $this->apiSlugs(),
            fn (string $slug) => $this->isConfigured($slug),
        ));
    }
}
