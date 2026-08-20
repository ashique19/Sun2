<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\User;
use App\Support\SimpleXlsxExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
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
        $query = $this->filteredCustomersQuery($filters)
            ->withCount([
                'orders as orders_count' => fn ($q) => $q->where('status', '!=', Order::STATUS_DRAFT),
            ])
            ->orderByDesc('id');

        // #region agent log
        file_put_contents('/opt/cursor/logs/debug.log', json_encode(['hypothesisId' => 'A,E,F', 'location' => 'CustomerExportService.php:writeXlsx:beforeGet', 'message' => 'about to run export query', 'data' => ['sql' => $query->toSql(), 'bindings' => $query->getBindings(), 'orderClause' => 'orderByDesc(id)', 'selectHint' => ['users.id', 'users.name', 'users.phone', 'users.email', 'users.is_active', 'users.created_at'], 'filters' => array_diff_key($filters, ['user_id' => true]), 'dbDriver' => config('database.default'), 'memoryBefore' => memory_get_usage(true)], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND);
        // #endregion

        try {
            $customers = $query->get(['users.id', 'users.name', 'users.phone', 'users.email', 'users.is_active', 'users.created_at']);
        } catch (\Throwable $e) {
            // #region agent log
            file_put_contents('/opt/cursor/logs/debug.log', json_encode(['hypothesisId' => 'A,E', 'location' => 'CustomerExportService.php:writeXlsx:queryFail', 'message' => 'export query failed', 'data' => ['exception' => $e::class, 'error' => $e->getMessage(), 'sqlState' => $e instanceof QueryException ? ($e->errorInfo[0] ?? null) : null, 'driverCode' => $e instanceof QueryException ? ($e->errorInfo[1] ?? null) : null, 'sql' => method_exists($e, 'getSql') ? $e->getSql() : $query->toSql()], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND);
            // #endregion
            throw $e;
        }

        // #region agent log
        file_put_contents('/opt/cursor/logs/debug.log', json_encode(['hypothesisId' => 'A,F', 'location' => 'CustomerExportService.php:writeXlsx:afterGet', 'message' => 'export query succeeded', 'data' => ['rowCount' => $customers->count(), 'memoryAfterQuery' => memory_get_usage(true), 'firstId' => $customers->first()?->id], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND);
        // #endregion

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

        // #region agent log
        file_put_contents('/opt/cursor/logs/debug.log', json_encode(['hypothesisId' => 'C,F', 'location' => 'CustomerExportService.php:writeXlsx:beforeWrite', 'message' => 'about to write xlsx', 'data' => ['dir' => $dir, 'path' => $path, 'dirExists' => is_dir($dir), 'dirWritable' => is_writable($dir), 'zipLoaded' => extension_loaded('zip'), 'rowCount' => count($rows), 'memoryBeforeWrite' => memory_get_usage(true)], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND);
        // #endregion

        $xlsx->writeToFile($path, $headers, $rows);

        // #region agent log
        file_put_contents('/opt/cursor/logs/debug.log', json_encode(['hypothesisId' => 'C', 'location' => 'CustomerExportService.php:writeXlsx:afterWrite', 'message' => 'xlsx write finished', 'data' => ['path' => $path, 'exists' => is_file($path), 'size' => is_file($path) ? filesize($path) : null, 'memoryPeak' => memory_get_peak_usage(true)], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND);
        // #endregion

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
