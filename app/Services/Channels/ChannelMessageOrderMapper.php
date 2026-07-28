<?php

namespace App\Services\Channels;

use App\Models\ChannelMessage;
use App\Models\Product;
use App\Services\Storefront\AddressLocationGuesser;
use App\Support\PhoneNumber;

class ChannelMessageOrderMapper
{
    public const FIELD_PHONE = 'phone';

    public const FIELD_NAME = 'name';

    public const FIELD_ADDRESS = 'address';

    public const FIELD_PRODUCT = 'product';

    /** @var list<string> */
    public const FIELDS = [
        self::FIELD_PHONE,
        self::FIELD_NAME,
        self::FIELD_ADDRESS,
        self::FIELD_PRODUCT,
    ];

    public function __construct(
        private AddressLocationGuesser $locations,
    ) {}

    /**
     * Suggest a value (and optional product match) for mapping a message onto a draft field.
     *
     * @return array{
     *     field: string,
     *     value: ?string,
     *     product_id: ?int,
     *     product_name: ?string,
     *     city: ?string,
     *     area: ?string,
     *     city_id: ?int,
     *     area_id: ?int,
     *     products: list<array{id: int, name: string, price: float}>
     * }
     */
    public function suggest(ChannelMessage $message, string $field): array
    {
        $field = $this->normalizeField($field);
        $text = trim((string) ($message->body ?? ''));

        $base = [
            'field' => $field,
            'value' => null,
            'product_id' => null,
            'product_name' => null,
            'city' => null,
            'area' => null,
            'city_id' => null,
            'area_id' => null,
            'products' => [],
        ];

        return match ($field) {
            self::FIELD_PHONE => $this->suggestPhone($text, $base),
            self::FIELD_NAME => $this->suggestName($text, $base),
            self::FIELD_ADDRESS => $this->suggestAddress($text, $base),
            self::FIELD_PRODUCT => $this->suggestProduct($text, $base),
        };
    }

    /**
     * @param  array{field: string, value: ?string, product_id: ?int, product_name: ?string, city: ?string, area: ?string, city_id: ?int, area_id: ?int, products: list<array{id: int, name: string, price: float}>}  $base
     * @return array{field: string, value: ?string, product_id: ?int, product_name: ?string, city: ?string, area: ?string, city_id: ?int, area_id: ?int, products: list<array{id: int, name: string, price: float}>}
     */
    private function suggestPhone(string $text, array $base): array
    {
        $phone = PhoneNumber::extractFirstBangladeshMobile($text);
        if ($phone) {
            $base['value'] = PhoneNumber::display($phone);
        } elseif ($text !== '' && PhoneNumber::isValidBangladeshMobile($text)) {
            $base['value'] = PhoneNumber::display($text);
        }

        return $base;
    }

    /**
     * @param  array{field: string, value: ?string, product_id: ?int, product_name: ?string, city: ?string, area: ?string, city_id: ?int, area_id: ?int, products: list<array{id: int, name: string, price: float}>}  $base
     * @return array{field: string, value: ?string, product_id: ?int, product_name: ?string, city: ?string, area: ?string, city_id: ?int, area_id: ?int, products: list<array{id: int, name: string, price: float}>}
     */
    private function suggestName(string $text, array $base): array
    {
        $line = $this->firstMeaningfulLine($text);
        if ($line !== null) {
            $base['value'] = mb_substr($line, 0, 255);
        }

        return $base;
    }

    /**
     * @param  array{field: string, value: ?string, product_id: ?int, product_name: ?string, city: ?string, area: ?string, city_id: ?int, area_id: ?int, products: list<array{id: int, name: string, price: float}>}  $base
     * @return array{field: string, value: ?string, product_id: ?int, product_name: ?string, city: ?string, area: ?string, city_id: ?int, area_id: ?int, products: list<array{id: int, name: string, price: float}>}
     */
    private function suggestAddress(string $text, array $base): array
    {
        $value = trim($text);
        if ($value === '') {
            return $base;
        }

        $base['value'] = mb_substr($value, 0, 255);
        $guess = $this->locations->guess($value);
        if ($guess) {
            $base['city_id'] = (int) $guess['city_id'];
            $base['area_id'] = (int) $guess['area_id'];
            $base['area'] = $guess['label'] ?? null;
        }

        return $base;
    }

    /**
     * @param  array{field: string, value: ?string, product_id: ?int, product_name: ?string, city: ?string, area: ?string, city_id: ?int, area_id: ?int, products: list<array{id: int, name: string, price: float}>}  $base
     * @return array{field: string, value: ?string, product_id: ?int, product_name: ?string, city: ?string, area: ?string, city_id: ?int, area_id: ?int, products: list<array{id: int, name: string, price: float}>}
     */
    private function suggestProduct(string $text, array $base): array
    {
        $term = $this->firstMeaningfulLine($text) ?? trim($text);
        if ($term === '') {
            return $base;
        }

        $base['value'] = mb_substr($term, 0, 255);

        $products = Product::query()
            ->searchTerm($term)
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'price']);

        $base['products'] = $products->map(fn (Product $product) => [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'price' => (float) $product->price,
        ])->all();

        if ($products->count() === 1) {
            $only = $products->first();
            $base['product_id'] = (int) $only->id;
            $base['product_name'] = (string) $only->name;
        }

        return $base;
    }

    public function normalizeField(string $field): string
    {
        $field = strtolower(trim($field));

        if (! in_array($field, self::FIELDS, true)) {
            throw new \InvalidArgumentException('Unknown order mapping field: '.$field);
        }

        return $field;
    }

    private function firstMeaningfulLine(string $text): ?string
    {
        foreach (preg_split('/\R+/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (PhoneNumber::extractFirstBangladeshMobile($line) && mb_strlen($line) < 20) {
                continue;
            }

            return $line;
        }

        return null;
    }
}
