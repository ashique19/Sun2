<?php

namespace App\Services\LegacyImport;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class LegacyImporter
{
    private const CHUNK = 500;

    /** @var list<string> */
    private array $usedPhones = [];

    /** @var array<string, true> */
    private array $usedPhoneKeys = [];

    /** @var array<string, int> */
    private array $roleIds = [];

    /** @var array<string, int> */
    private array $usedEmails = [];

    public function run(bool $fresh, ?array $only, Command $output): void
    {
        $this->assertLegacyConnection();

        $steps = [
            'countries' => fn () => $this->importCountries($output),
            'categories' => fn () => $this->importCategories($output),
            'tags' => fn () => $this->importTags($output),
            'couriers' => fn () => $this->importCouriers($output),
            'users' => fn () => $this->importUsers($output),
            'products' => fn () => $this->importProducts($output),
            'orders' => function () use ($output) {
                $this->importOrders($output);
                $this->importOrderProducts($output);
            },
            'order_products' => fn () => $this->importOrderProducts($output),
            'settings' => fn () => $this->importSettings($output),
        ];

        if ($fresh) {
            $this->truncateTargetTables();
            $output->warn('Target tables truncated.');
        }

        DB::connection()->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            $this->seedRoles();

            foreach ($steps as $name => $step) {
                if ($only !== null && ! in_array($name, $only, true)) {
                    continue;
                }

                $step();
            }
        } finally {
            DB::connection()->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function assertLegacyConnection(): void
    {
        try {
            DB::connection('legacy')->getPdo();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Cannot connect to the legacy database. Import storage/sun.sql into a `sun_legacy` database first.',
                previous: $e,
            );
        }
    }

    private function truncateTargetTables(): void
    {
        $tables = [
            'order_products', 'order_status_history', 'payment_transactions', 'orders',
            'product_tag', 'product_images', 'products', 'addresses', 'users',
            'categories', 'tags', 'couriers', 'countries', 'settings',
            'model_has_roles', 'model_has_permissions', 'role_has_permissions',
        ];

        DB::connection()->statement('SET FOREIGN_KEY_CHECKS=0');

        foreach ($tables as $table) {
            DB::connection()->table($table)->truncate();
        }

        DB::connection()->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function seedRoles(): void
    {
        foreach (['dev', 'admin', 'reseller', 'customers', 'moderator'] as $role) {
            $this->roleIds[$role] = Role::findOrCreate($role)->id;
        }
    }

    private function importCountries(Command $output): void
    {
        $rows = DB::connection('legacy')->table('countries')->orderBy('id')->get();
        $payload = [];

        foreach ($rows as $row) {
            $payload[] = [
                'id' => $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];
        }

        if ($payload !== []) {
            DB::connection()->table('countries')->insert($payload);
        }

        $output->line("  countries: {$rows->count()}");
    }

    private function importCategories(Command $output): void
    {
        $rows = DB::connection('legacy')->table('categories')->orderBy('id')->get();
        $payload = [];

        foreach ($rows as $row) {
            $payload[] = [
                'id' => $row->id,
                'parent_id' => null,
                'name' => $row->name,
                'slug' => $this->importSlug($row->name_slug ?: $row->name, (int) $row->id),
                'headline' => $row->headline,
                'summary' => $row->summary,
                'details' => $row->details,
                'thumb_image' => $this->normalizeAssetPath($row->thumb_image),
                'display_order' => $row->display_order,
                'is_homepage' => (bool) $row->is_homepage,
                'is_active' => $row->name_slug !== 'stockout',
                'legacy_id' => $row->id,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];
        }

        if ($payload !== []) {
            DB::connection()->table('categories')->insert($payload);
        }

        $output->line("  categories: {$rows->count()}");
    }

    private function importTags(Command $output): void
    {
        $rows = DB::connection('legacy')->table('tags')->orderBy('id')->get();
        $payload = [];

        foreach ($rows as $row) {
            $payload[] = [
                'id' => $row->id,
                'name' => $row->name,
                'slug' => $this->importSlug($row->name, (int) $row->id),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($payload !== []) {
            DB::connection()->table('tags')->insert($payload);
        }

        $output->line("  tags: {$rows->count()}");
    }

    private function importCouriers(Command $output): void
    {
        $rows = DB::connection('legacy')->table('couriers')->orderBy('id')->get();
        $payload = [];

        foreach ($rows as $row) {
            $payload[] = [
                'id' => $row->id,
                'name' => $row->name,
                'slug' => Str::slug($row->name),
                'charge' => $row->charge,
                'osd_charge' => $row->osd_charge,
                'customer_charge' => $row->customer_charge,
                'customer_osd_charge' => $row->customer_osd_charge,
                'cod_percentage' => $row->cod_percentage,
                'balance' => $row->balance,
                'is_default' => (bool) $row->is_default,
                'is_active' => true,
                'legacy_id' => $row->id,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];
        }

        if ($payload !== []) {
            DB::connection()->table('couriers')->insert($payload);
        }

        $output->line("  couriers: {$rows->count()}");
    }

    private function importUsers(Command $output): void
    {
        $count = 0;
        $roleMap = [1 => 'dev', 2 => 'admin', 3 => 'reseller', 4 => 'customers', 5 => 'moderator'];

        DB::connection('legacy')->table('users')->orderBy('id')->chunk(self::CHUNK, function ($rows) use (&$count, $roleMap) {
            $users = [];
            $addresses = [];
            $roles = [];

            foreach ($rows as $row) {
                $phone = $this->uniquePhone($this->normalizePhone((string) $row->contact), (int) $row->id);
                $email = $this->uniqueEmail((string) $row->email, (int) $row->id);

                $users[] = [
                    'id' => $row->id,
                    'name' => trim($row->name) ?: trim($row->firstname.' '.$row->lastname) ?: 'User '.$row->id,
                    'phone' => $phone,
                    'email' => $email,
                    'email_verified_at' => null,
                    'phone_verified_at' => null,
                    'password' => $row->password ?: null,
                    'avatar' => $this->normalizeAssetPath($row->user_photo),
                    'is_active' => (bool) $row->active,
                    'country_id' => $row->country_id ?: null,
                    'referrer_id' => null,
                    'referral_balance' => $row->referral_balance,
                    'referral_benefit_expiry_date' => $this->validTimestamp($row->referral_benefit_expiry_date),
                    'social_provider' => ((int) $row->social_id) > 1 ? 'legacy' : null,
                    'social_id' => ((int) $row->social_id) > 1 ? (string) $row->social_id : null,
                    'legacy_id' => $row->id,
                    'remember_token' => $row->remember_token,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];

                if (trim((string) $row->address) !== '') {
                    $addresses[] = [
                        'user_id' => $row->id,
                        'label' => 'Default',
                        'name' => trim($row->name) ?: 'Customer',
                        'phone' => $phone,
                        'address' => $row->address,
                        'area' => $row->area ?: null,
                        'city' => $row->city ?: null,
                        'state' => $row->state ?: null,
                        'postcode' => $row->postcode ?: null,
                        'country_id' => $row->country_id ?: null,
                        'is_default' => true,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ];
                }

                $count++;
            }

            DB::connection()->table('users')->insert($users);

            if ($addresses !== []) {
                DB::connection()->table('addresses')->insert($addresses);
            }

            foreach ($rows as $row) {
                $role = $roleMap[(int) $row->role] ?? 'customers';
                $roles[] = [
                    'role_id' => $this->roleIds[$role],
                    'model_type' => 'App\\Models\\User',
                    'model_id' => $row->id,
                ];
            }

            if ($roles !== []) {
                DB::connection()->table('model_has_roles')->insertOrIgnore($roles);
            }
        });

        DB::connection('legacy')->table('users')->whereNotNull('referrer_id')->orderBy('id')->chunk(self::CHUNK, function ($rows) {
            foreach ($rows as $row) {
                if (! $row->referrer_id) {
                    continue;
                }

                DB::connection()->table('users')
                    ->where('id', $row->id)
                    ->update(['referrer_id' => $row->referrer_id]);
            }
        });

        $output->line("  users: {$count}");
    }

    private function importProducts(Command $output): void
    {
        $productCount = 0;
        $imageCount = 0;
        $tagCount = 0;

        DB::connection('legacy')->table('products')
            ->select([
                'id', 'name', 'category_id', 'thumb_image',
                'price', 'purchase_price',
                'display_order', 'is_published', 'stock_quantity', 'video_url',
                'meta_keyword', 'meta_description', 'created_at', 'updated_at',
            ])
            ->orderBy('id')
            ->chunk(100, function ($rows) use (&$productCount, &$imageCount) {
                $products = [];
                $images = [];

                foreach ($rows as $row) {
                    $products[] = [
                        'id' => $row->id,
                        'category_id' => $row->category_id ?: null,
                        'name' => $row->name,
                        'slug' => $this->importSlug($row->name, (int) $row->id),
                        'sku' => null,
                        'description' => null,
                        'description_bn' => null,
                        'price' => $row->price ?? 0,
                        'compare_at_price' => null,
                        'purchase_price' => $row->purchase_price ?? 0,
                        'stock_quantity' => $row->stock_quantity ?? 0,
                        'is_published' => (bool) ($row->is_published ?? false),
                        'is_featured' => false,
                        'is_new' => false,
                        'is_best_seller' => false,
                        'display_order' => $row->display_order ?? 0,
                        'video_url' => $row->video_url ?: null,
                        'rating_avg' => 0,
                        'review_count' => 0,
                        'meta_title' => null,
                        'meta_keyword' => $row->meta_keyword,
                        'meta_description' => $row->meta_description,
                        'legacy_id' => $row->id,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ];

                    if ($row->thumb_image) {
                        $images[] = [
                            'product_id' => $row->id,
                            'path' => $this->normalizeAssetPath($row->thumb_image),
                            'alt' => $row->name,
                            'sort_order' => 0,
                            'is_primary' => true,
                            'created_at' => $row->created_at,
                            'updated_at' => $row->updated_at,
                        ];
                        $imageCount++;
                    }

                    $productCount++;
                }

                DB::connection()->table('products')->insert($products);

                if ($images !== []) {
                    DB::connection()->table('product_images')->insert($images);
                }
            });

        DB::connection('legacy')->table('product_tag')->orderBy('product_id')->chunk(self::CHUNK, function ($rows) use (&$tagCount) {
            $payload = [];

            foreach ($rows as $row) {
                $payload[] = [
                    'product_id' => $row->product_id,
                    'tag_id' => $row->tag_id,
                ];
                $tagCount++;
            }

            if ($payload !== []) {
                DB::connection()->table('product_tag')->insertOrIgnore($payload);
            }
        });

        $output->line("  products: {$productCount}");
        $output->line("  product_images: {$imageCount}");
        $output->line("  product_tag: {$tagCount}");
    }

    private function importOrders(Command $output): void
    {
        $legacyCount = DB::connection('legacy')->table('orders')->count();
        $existingCount = DB::connection()->table('orders')->count();

        if ($existingCount >= $legacyCount) {
            $output->line("  orders: {$existingCount} (already imported)");

            return;
        }

        $orderCount = 0;

        DB::connection('legacy')->table('orders')
            ->select([
                'id', 'user_id', 'name', 'address', 'area', 'city', 'state', 'postcode',
                'phone', 'email', 'subtotal', 'charge', 'discount', 'total', 'order_date',
                'courier_id', 'courier_name', 'courier_tracker', 'delivery_charge', 'cod',
                'collected_amount', 'due_amount', 'paid_amount', 'payment_gateway', 'status',
                'is_replacement', 'courier_note', 'note', 'has_return', 'dispatch_date',
                'expected_delivery_date', 'actual_delivery_date', 'payment_date',
                'created_by', 'updated_by', 'created_at', 'updated_at',
            ])
            ->orderBy('id')
            ->chunk(250, function ($rows) use (&$orderCount) {
                $orders = [];

                foreach ($rows as $row) {
                    $paid = $this->decimalFromMixed($row->paid_amount);
                    $due = (float) $row->due_amount;
                    $collected = (float) $row->collected_amount;

                    $orders[] = [
                        'id' => $row->id,
                        'order_number' => (string) $row->id,
                        'user_id' => $row->user_id ?: null,
                        'name' => $row->name,
                        'phone' => $this->normalizePhone((string) $row->phone),
                        'email' => trim((string) $row->email) ?: null,
                        'address' => $row->address,
                        'area' => $row->area ?: null,
                        'city' => $row->city ?: null,
                        'state' => $row->state ?: null,
                        'postcode' => $row->postcode ?: null,
                        'delivery_type' => 'home',
                        'subtotal' => $row->subtotal ?? 0,
                        'delivery_charge' => $row->delivery_charge ?? 0,
                        'charge' => $row->charge ?? 0,
                        'discount' => $row->discount ?? 0,
                        'coupon_id' => null,
                        'total' => $row->total ?? 0,
                        'cod_amount' => $row->cod ?? 0,
                        'collected_amount' => $collected,
                        'paid_amount' => $paid,
                        'due_amount' => $due,
                        'payment_status' => $this->paymentStatus($paid, $due, (string) $row->status),
                        'payment_method' => $this->normalizePaymentMethod((string) $row->payment_gateway),
                        'status' => $this->normalizeOrderStatus((string) $row->status),
                        'courier_id' => $row->courier_id ?: null,
                        'courier_tracker' => $row->courier_tracker ?: null,
                        'is_replacement' => (bool) $row->is_replacement,
                        'has_return' => (bool) $row->has_return,
                        'admin_note' => $row->note ?: null,
                        'customer_note' => $row->courier_note ?: null,
                        'placed_at' => $this->validTimestamp($row->order_date),
                        'dispatch_date' => $this->validTimestamp($row->dispatch_date),
                        'expected_delivery_date' => $this->validTimestamp($row->expected_delivery_date),
                        'actual_delivery_date' => $this->validTimestamp($row->actual_delivery_date),
                        'payment_date' => $this->validTimestamp($row->payment_date),
                        'created_by' => $this->validUserId($row->created_by),
                        'updated_by' => $this->validUserId($row->updated_by),
                        'placed_via' => $this->inferPlacedVia($row),
                        'legacy_id' => $row->id,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ];

                    $orderCount++;
                }

                DB::connection()->table('orders')->insert($orders);
            });

        $output->line("  orders: {$orderCount}");
    }

    private function importOrderProducts(Command $output): void
    {
        $legacyCount = DB::connection('legacy')->table('order_products')->count();
        $existingCount = DB::connection()->table('order_products')->count();

        if ($existingCount >= $legacyCount) {
            $output->line("  order_products: {$existingCount} (already imported)");

            return;
        }

        if ($existingCount > 0) {
            DB::connection()->table('order_products')->truncate();
        }

        $lineCount = 0;

        DB::connection('legacy')->table('order_products')->orderBy('id')->chunk(self::CHUNK, function ($rows) use (&$lineCount) {
            $payload = [];

            foreach ($rows as $row) {
                $payload[] = [
                    'id' => $row->id,
                    'order_id' => $row->order_id,
                    'product_id' => $row->product_id ?: null,
                    'name' => $row->name ?: 'Product',
                    'product_image' => $this->normalizeAssetPath($row->product_image),
                    'quantity' => $row->quantity ?? 0,
                    'price' => $row->price ?? 0,
                    'purchase_price' => $row->purchase_price ?? 0,
                    'line_total' => $row->value ?? 0,
                    'to_be_returned' => (bool) $row->to_be_returned,
                    'return_received' => (bool) $row->return_received,
                    'legacy_id' => $row->id,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];
                $lineCount++;
            }

            if ($payload !== []) {
                DB::connection()->table('order_products')->insert($payload);
            }
        });

        $output->line("  order_products: {$lineCount}");
    }

    private function importSettings(Command $output): void
    {
        $row = DB::connection('legacy')->table('settings')->orderBy('id')->first();

        if (! $row) {
            $output->line('  settings: 0');

            return;
        }

        DB::connection()->table('settings')->insert([
            'id' => $row->id,
            'key' => 'site',
            'value' => json_encode([
                'application_name' => $row->application_name,
                'application_slogan' => $row->application_slogan,
                'business_name' => $row->business_name,
                'owners_name' => $row->owners_name,
                'address' => $row->address,
                'city' => $row->city,
                'country' => $row->country,
                'postcode' => $row->postcode,
                'contact' => $row->contact,
                'helpline' => $row->helpline,
                'helpmail' => $row->helpmail,
                'email' => $row->email,
                'logo_photo' => $this->normalizeAssetPath($row->logo_photo),
                'icon_photo' => $this->normalizeAssetPath($row->icon_photo),
                'facebook' => $row->facebook,
                'twitter' => $row->twitter,
            ]),
            'group' => 'general',
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ]);

        $output->line('  settings: 1');
    }

    private function importSlug(string $value, int $id): string
    {
        $base = Str::slug($value) ?: 'item';

        return $base.'-'.$id;
    }

    private function uniqueSlug(string $value, string $table, int $id): string
    {
        $base = Str::slug($value) ?: 'item';
        $slug = $base;
        $suffix = 1;

        while (
            DB::connection()->table($table)
                ->where('slug', $slug)
                ->where('id', '!=', $id)
                ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function normalizePhone(string $phone): string
    {
        $phone = str_replace(
            ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            trim($phone),
        );
        $phone = trim(preg_replace('/\s+/', '', $phone));

        if ($phone === '') {
            return 'unknown';
        }

        if (! str_starts_with($phone, '+') && preg_match('/^88\d{10,}$/', $phone)) {
            $phone = '+'.$phone;
        }

        return $phone;
    }

    private function uniquePhone(string $phone, int $userId): string
    {
        $key = preg_replace('/\D+/', '', $phone) ?: 'unknown';

        if (isset($this->usedPhoneKeys[$key])) {
            $phone = $phone.'-'.$userId;
            $key = preg_replace('/\D+/', '', $phone) ?: 'unknown-'.$userId;
        }

        $this->usedPhones[] = $phone;
        $this->usedPhoneKeys[$key] = true;

        return $phone;
    }

    private function uniqueEmail(string $email, int $userId): ?string
    {
        $email = trim(strtolower($email));

        if ($email === '') {
            return null;
        }

        if (isset($this->usedEmails[$email])) {
            return 'user'.$userId.'@imported.sundoritoma.local';
        }

        $this->usedEmails[$email] = $userId;

        return $email;
    }

    /** @return list<string> */
    private function parseImageList(mixed $raw): array
    {
        if (! is_string($raw) || trim($raw) === '' || trim($raw) === '[]') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return array_values(array_filter(array_map('strval', $decoded)));
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    private function normalizeAssetPath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $path = str_replace('\\', '/', $path);

        return preg_replace('#^/public#', '', $path) ?: $path;
    }

    private function decimalFromMixed(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return $clean === '' || $clean === '-' ? 0.0 : (float) $clean;
    }

    private function paymentStatus(float $paid, float $due, string $legacyStatus): string
    {
        if ($due > 0.01) {
            return 'partial';
        }

        if ($paid > 0.01 || in_array(strtolower($legacyStatus), ['paid', 'delivered'], true)) {
            return 'paid';
        }

        return 'unpaid';
    }

    private function normalizePaymentMethod(string $gateway): ?string
    {
        $gateway = trim($gateway);

        if ($gateway === '' || $gateway === '-PAYMENT GATEWAY-') {
            return 'cod';
        }

        return Str::slug($gateway);
    }

    private function normalizeOrderStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'new' => 'new',
            'confirmed' => 'confirmed',
            'dispatched' => 'dispatched',
            'delivered', 'paid' => 'delivered',
            'returned', 'cancel and return' => 'returned',
            'cancelled', 'canceled' => 'cancelled',
            default => Str::slug($status) ?: 'new',
        };
    }

    private function validTimestamp(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        $string = (string) $value;

        if (str_starts_with($string, '0000-00-00')) {
            return null;
        }

        return $string;
    }

    private function inferPlacedVia(object $row): string
    {
        $createdBy = $this->validUserId($row->created_by ?? null);
        $resellerId = $this->validUserId($row->reseller_id ?? null);

        if ($createdBy && $resellerId && $createdBy === $resellerId) {
            return Order::PLACED_VIA_RESELLER;
        }

        if ($createdBy) {
            return Order::PLACED_VIA_ADMIN;
        }

        return Order::PLACED_VIA_STOREFRONT;
    }

    private function validUserId(mixed $value): ?int
    {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
