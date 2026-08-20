<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\User;
use App\Support\SimpleXlsxExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;

class CustomerExportService
{
    public static function cacheKey(string $token): string
    {
        return 'customer-export:'.$token;
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
     * @return array{path: string, filename: string}
     */
    public function writeXlsx(array $filters, SimpleXlsxExporter $xlsx): array
    {
        $customers = $this->filteredCustomersQuery($filters)
            ->withCount([
                'orders as orders_count' => fn ($q) => $q->where('status', '!=', Order::STATUS_DRAFT),
            ])
            ->orderByDesc('id')
            ->get(['users.id', 'users.name', 'users.phone', 'users.email', 'users.is_active', 'users.created_at']);

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
        $dir = storage_path('app/exports');
        File::ensureDirectoryExists($dir);
        $path = $dir.DIRECTORY_SEPARATOR.$filename;
        $xlsx->writeToFile($path, $headers, $rows);

        return ['path' => $path, 'filename' => $filename];
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
