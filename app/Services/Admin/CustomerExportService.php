<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\User;
use App\Support\SimpleXlsxExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Throwable;

class CustomerExportService
{
    public static function cacheKey(string $token): string
    {
        return 'customer-export:'.$token;
    }

    public static function sessionKey(string $token): string
    {
        return 'customer_export_'.$token;
    }

    /**
     * @param  array{
     *     user_id?: int,
     *     search?: string,
     *     cityFilter?: string,
     *     cityNoneOnly?: bool,
     *     ordersMin?: string,
     *     ordersMax?: string,
     *     categoryId?: string
     * }  $payload
     */
    public function remember(string $token, array $payload): void
    {
        $ttl = now()->addMinutes(5);

        session()->put(self::sessionKey($token), $payload);

        try {
            Cache::put(self::cacheKey($token), $payload, $ttl);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array{
     *     user_id?: int,
     *     search?: string,
     *     cityFilter?: string,
     *     cityNoneOnly?: bool,
     *     ordersMin?: string,
     *     ordersMax?: string,
     *     categoryId?: string
     * }|null
     */
    public function pull(string $token): ?array
    {
        $sessionKey = self::sessionKey($token);
        $payload = session()->pull($sessionKey);

        if (is_array($payload)) {
            try {
                Cache::forget(self::cacheKey($token));
            } catch (Throwable) {
                // Session hit is enough.
            }

            return $payload;
        }

        try {
            $cached = Cache::pull(self::cacheKey($token));
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return is_array($cached) ? $cached : null;
    }

    /**
     * @param  array{
     *     search?: string,
     *     cityFilter?: string,
     *     cityNoneOnly?: bool,
     *     ordersMin?: string,
     *     ordersMax?: string,
     *     categoryId?: string
     * }  $filters
     * @return array{binary: string, filename: string}
     */
    public function buildXlsx(array $filters, SimpleXlsxExporter $xlsx): array
    {
        Role::findOrCreate('customers');

        $customers = $this->filteredCustomersQuery($filters)
            ->select([
                'users.id',
                'users.name',
                'users.phone',
                'users.email',
                'users.is_active',
                'users.created_at',
            ])
            ->withCount([
                'orders as orders_count' => fn ($q) => $q->where('status', '!=', Order::STATUS_DRAFT),
            ])
            ->orderByDesc('users.id')
            ->get();

        $headers = ['Name', 'Phone', 'Email', 'Orders', 'Status', 'Joined'];
        $rows = $customers->map(fn (User $user) => [
            $user->name,
            $user->phone,
            $user->email ?: '',
            (int) ($user->orders_count ?? 0),
            $user->is_active ? 'Active' : 'Off',
            $user->created_at?->format('Y-m-d') ?? '',
        ])->all();

        $filename = 'customers-'.now()->format('Y-m-d-His').'.xlsx';
        $binary = $xlsx->build($headers, $rows);

        if ($binary === '' || ! str_starts_with($binary, 'PK')) {
            throw new RuntimeException('Generated export file was empty or invalid.');
        }

        return ['binary' => $binary, 'filename' => $filename];
    }

    /**
     * @param  array{
     *     search?: string,
     *     cityFilter?: string,
     *     cityNoneOnly?: bool,
     *     ordersMin?: string,
     *     ordersMax?: string,
     *     categoryId?: string
     * }  $filters
     */
    public function filteredCustomersQuery(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $cityFilter = trim((string) ($filters['cityFilter'] ?? ''));
        $cityNoneOnly = (bool) ($filters['cityNoneOnly'] ?? false);
        $ordersMin = $this->normalizedOrderBound((string) ($filters['ordersMin'] ?? ''));
        $ordersMax = $this->normalizedOrderBound((string) ($filters['ordersMax'] ?? ''));
        $categoryId = trim((string) ($filters['categoryId'] ?? ''));

        if ($ordersMin !== null && $ordersMax !== null && $ordersMin > $ordersMax) {
            [$ordersMin, $ordersMax] = [$ordersMax, $ordersMin];
        }

        return User::query()
            ->role('customers')
            ->when($search !== '', function (Builder $query) use ($search) {
                $term = '%'.$search.'%';
                $query->where(function (Builder $q) use ($term) {
                    $q->where('users.name', 'like', $term)
                        ->orWhere('users.phone', 'like', $term)
                        ->orWhere('users.email', 'like', $term)
                        ->orWhere(function (Builder $cityQuery) use ($term) {
                            $cityQuery->whereHas('orders', function (Builder $orders) use ($term) {
                                $orders->where('city', 'like', $term);
                            })->orWhereHas('addresses', function (Builder $addresses) use ($term) {
                                $addresses->where(function (Builder $a) use ($term) {
                                    $a->where('city', 'like', $term)
                                        ->orWhereHas('city', fn (Builder $city) => $city->where('name', 'like', $term));
                                });
                            });
                        });
                });
            })
            ->when($cityNoneOnly, function (Builder $query) {
                $query->whereDoesntHave('orders', function (Builder $orders) {
                    $orders->where('status', '!=', Order::STATUS_DRAFT)
                        ->whereNotNull('city')
                        ->where('city', '!=', '');
                })->whereDoesntHave('addresses', function (Builder $addresses) {
                    $addresses->where(function (Builder $a) {
                        $a->where(function (Builder $b) {
                            $b->whereNotNull('city')->where('city', '!=', '');
                        })->orWhereHas('city', fn (Builder $c) => $c->whereNotNull('name')->where('name', '!=', ''));
                    });
                });
            })
            ->when(! $cityNoneOnly && $cityFilter !== '', function (Builder $query) use ($cityFilter) {
                $term = '%'.$cityFilter.'%';
                $query->where(function (Builder $cityQuery) use ($term) {
                    $cityQuery->whereHas('orders', function (Builder $orders) use ($term) {
                        $orders->where('city', 'like', $term);
                    })->orWhereHas('addresses', function (Builder $addresses) use ($term) {
                        $addresses->where(function (Builder $a) use ($term) {
                            $a->where('city', 'like', $term)
                                ->orWhereHas('city', fn (Builder $city) => $city->where('name', 'like', $term));
                        });
                    });
                });
            })
            ->when($ordersMin !== null || $ordersMax !== null, function (Builder $query) use ($ordersMin, $ordersMax) {
                $lifetime = '(select count(*) from orders where orders.user_id = users.id and orders.status != ?)';
                if ($ordersMin !== null) {
                    $query->whereRaw($lifetime.' >= ?', [Order::STATUS_DRAFT, $ordersMin]);
                }
                if ($ordersMax !== null) {
                    $query->whereRaw($lifetime.' <= ?', [Order::STATUS_DRAFT, $ordersMax]);
                }
            })
            ->when($categoryId !== '' && ctype_digit($categoryId), function (Builder $query) use ($categoryId) {
                $id = (int) $categoryId;
                $query->whereHas('orders', function (Builder $orders) use ($id) {
                    $orders->where('status', '!=', Order::STATUS_DRAFT)
                        ->whereHas('items', function (Builder $items) use ($id) {
                            $items->whereHas('product', fn (Builder $product) => $product->where('category_id', $id));
                        });
                });
            });
    }

    private function normalizedOrderBound(string $value): ?int
    {
        $trimmed = trim($value);

        if ($trimmed === '' || ! is_numeric($trimmed)) {
            return null;
        }

        return max(0, (int) $trimmed);
    }
}
