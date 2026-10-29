<?php

namespace App\Services\Admin;

use App\Models\Address;
use App\Models\ChannelConversation;
use App\Models\Order;
use App\Models\ProductReview;
use App\Models\User;
use App\Models\Wishlist;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CustomerDuplicateMergeService
{
    public const BATCH_SIZE = 25;

    public const UI_BATCH_SIZE = 5;

    public const MAX_LOSERS_PER_BATCH = 8;

    /**
     * @return Collection<string, Collection<int, User>>
     */
    public function duplicateGroups(): Collection
    {
        $customers = User::query()
            ->whereDoesntHave('roles', function ($query) {
                $query->whereIn('name', ['admin', 'dev', 'moderator', 'reseller']);
            })
            ->orderByDesc('id')
            ->get(['id', 'name', 'phone', 'email', 'password', 'is_active', 'created_at', 'updated_at']);

        return $customers
            ->groupBy(function (User $user) {
                $normalized = PhoneNumber::normalize((string) $user->phone);

                return $normalized !== '' ? $normalized : 'id:'.$user->id;
            })
            ->filter(fn (Collection $group, string $key) => ! str_starts_with($key, 'id:') && $group->count() > 1)
            ->sortKeys();
    }

    public function duplicateGroupCount(): int
    {
        return $this->duplicateGroups()->count();
    }

    /**
     * @return array{
     *     groups: int,
     *     users_to_remove: int,
     *     orders_to_move: int,
     *     samples: list<string>,
     *     done: bool
     * }
     */
    public function preview(int $sampleLimit = 12): array
    {
        $groups = $this->duplicateGroups();
        $usersToRemove = 0;
        $loserIds = [];
        $samples = [];

        foreach ($groups as $normalizedPhone => $group) {
            $sorted = $group->sortByDesc('id')->values();
            $keeper = $sorted->first();
            $losers = $sorted->slice(1);
            $usersToRemove += $losers->count();
            $loserIds = array_merge($loserIds, $losers->pluck('id')->all());

            if (count($samples) < max(1, $sampleLimit)) {
                $samples[] = $this->sampleLine((string) $normalizedPhone, (int) $keeper->id, $losers->pluck('id'));
            }
        }

        $ordersToMove = 0;

        foreach (array_chunk($loserIds, 500) as $chunk) {
            $ordersToMove += Order::query()->whereIn('user_id', $chunk)->count();
        }

        return [
            'groups' => $groups->count(),
            'users_to_remove' => $usersToRemove,
            'orders_to_move' => $ordersToMove,
            'samples' => $samples,
            'done' => $groups->isEmpty(),
        ];
    }

    /**
     * Merge a small slice of duplicate phone groups. Keeps the highest-id (latest) user.
     *
     * @param  list<int>  $skipUserIds
     * @return array{
     *     scanned_groups: int,
     *     merged_groups: int,
     *     deleted_users: int,
     *     reassigned_orders: int,
     *     remaining_groups: int,
     *     done: bool,
     *     samples: list<string>,
     *     skipped_user_ids: list<int>,
     *     errors: list<string>
     * }
     */
    public function mergeNextBatch(
        int $limit = self::BATCH_SIZE,
        int $maxLosers = self::MAX_LOSERS_PER_BATCH,
        bool $recountRemaining = true,
        array $skipUserIds = [],
    ): array {
        $limit = max(1, min(self::BATCH_SIZE, $limit));
        $maxLosers = max(1, $maxLosers);
        $skipUserIds = array_values(array_unique(array_map('intval', $skipUserIds)));

        $groups = $this->duplicateGroups();
        $work = [];

        foreach ($groups as $normalizedPhone => $group) {
            $sorted = $group->sortByDesc('id')->values();
            $losers = $sorted->slice(1)
                ->reject(fn (User $user): bool => in_array((int) $user->id, $skipUserIds, true))
                ->values();

            if ($losers->isEmpty()) {
                continue;
            }

            $work[] = [
                'phone' => (string) $normalizedPhone,
                'sorted' => $sorted,
                'losers' => $losers,
            ];

            if (count($work) >= $limit) {
                break;
            }
        }

        $mergedGroups = 0;
        $deletedUsers = 0;
        $reassignedOrders = 0;
        $samples = [];
        $skippedUserIds = [];
        $errors = [];
        $loserBudget = $maxLosers;

        foreach ($work as $item) {
            if ($loserBudget <= 0) {
                break;
            }

            $result = $this->mergeGroup($item['sorted'], $item['losers']->take($loserBudget)->values(), $skipUserIds);
            $deletedUsers += $result['deleted_users'];
            $reassignedOrders += $result['reassigned_orders'];
            $skippedUserIds = array_merge($skippedUserIds, $result['skipped_user_ids']);
            $errors = array_merge($errors, $result['errors']);
            $loserBudget -= $result['deleted_users'] + count($result['skipped_user_ids']);

            if ($result['fully_merged']) {
                $mergedGroups++;
            }

            if ($result['deleted_users'] > 0 && count($samples) < 8) {
                $samples[] = $this->sampleLine(
                    $item['phone'],
                    $result['keeper_id'],
                    collect($result['removed_user_ids']),
                );
            }
        }

        $allSkip = array_values(array_unique(array_merge($skipUserIds, $skippedUserIds)));
        $remaining = $recountRemaining
            ? $this->duplicateGroups()
                ->filter(function (Collection $group) use ($allSkip): bool {
                    $sorted = $group->sortByDesc('id')->values();

                    return $sorted->slice(1)->contains(
                        fn (User $user): bool => ! in_array((int) $user->id, $allSkip, true)
                    );
                })
                ->count()
            : (count($work) === 0 ? 0 : max(0, $groups->count() - $mergedGroups));

        return [
            'scanned_groups' => count($work),
            'merged_groups' => $mergedGroups,
            'deleted_users' => $deletedUsers,
            'reassigned_orders' => $reassignedOrders,
            'remaining_groups' => $remaining,
            'done' => count($work) === 0 || $remaining === 0,
            'samples' => $samples,
            'skipped_user_ids' => array_values(array_unique($skippedUserIds)),
            'errors' => $errors,
        ];
    }

    /**
     * @param  Collection<int, User>  $sorted
     * @param  Collection<int, User>  $losers
     * @param  list<int>  $skipUserIds
     * @return array{
     *     keeper_id: int,
     *     deleted_users: int,
     *     reassigned_orders: int,
     *     fully_merged: bool,
     *     skipped_user_ids: list<int>,
     *     errors: list<string>,
     *     removed_user_ids: list<int>
     * }
     */
    private function mergeGroup(Collection $sorted, Collection $losers, array $skipUserIds): array
    {
        $keeper = User::query()->findOrFail((int) $sorted->first()->id);
        $deleted = 0;
        $ordersMoved = 0;
        $skipped = [];
        $errors = [];
        $removedIds = [];

        foreach ($losers as $loserStub) {
            $loserId = (int) $loserStub->id;

            if ($loserId === (int) $keeper->id || in_array($loserId, $skipUserIds, true)) {
                continue;
            }

            try {
                $ordersMoved += DB::transaction(function () use ($keeper, $loserId): int {
                    $loser = User::query()->find($loserId);

                    if (! $loser || (int) $loser->id === (int) $keeper->id) {
                        return 0;
                    }

                    $moved = $this->reassignUserOwnedRows($loser, $keeper);
                    $this->absorbProfileFields($keeper, $loser);
                    $passwordHash = $loser->getRawOriginal('password');
                    $this->detachLeftoverUserRows($loser);
                    $loser->delete();

                    if ($keeper->getRawOriginal('password') === null && filled($passwordHash)) {
                        DB::table('users')->where('id', $keeper->id)->update(['password' => $passwordHash]);
                        $keeper->refresh();
                    }

                    $keeper->save();

                    return $moved;
                });

                $deleted++;
                $removedIds[] = $loserId;
            } catch (Throwable $e) {
                report($e);
                $skipped[] = $loserId;
                $errors[] = 'User #'.$loserId.': '.$e->getMessage();
            }
        }

        $remainingLosers = $sorted->slice(1)->filter(function (User $user) use ($removedIds, $skipUserIds, $skipped): bool {
            $id = (int) $user->id;

            if (in_array($id, $removedIds, true) || in_array($id, $skipUserIds, true) || in_array($id, $skipped, true)) {
                return false;
            }

            return User::query()->whereKey($id)->exists();
        });

        $fullyMerged = $remainingLosers->isEmpty() && $deleted > 0 && $skipped === [];

        if ($fullyMerged) {
            $this->canonicalizeKeeperPhone($keeper);
        }

        return [
            'keeper_id' => (int) $keeper->id,
            'deleted_users' => $deleted,
            'reassigned_orders' => $ordersMoved,
            'fully_merged' => $fullyMerged,
            'skipped_user_ids' => $skipped,
            'errors' => $errors,
            'removed_user_ids' => $removedIds,
        ];
    }

    private function canonicalizeKeeperPhone(User $keeper): void
    {
        $displayPhone = PhoneNumber::display((string) $keeper->phone);

        if (! PhoneNumber::isValidDisplayMobile($displayPhone) || $keeper->phone === $displayPhone) {
            return;
        }

        $taken = User::query()
            ->where('phone', $displayPhone)
            ->where('id', '!=', $keeper->id)
            ->exists();

        if ($taken) {
            return;
        }

        $keeper->phone = $displayPhone;
        $keeper->save();
    }

    private function reassignUserOwnedRows(User $loser, User $keeper): int
    {
        $loserId = (int) $loser->id;
        $keeperId = (int) $keeper->id;

        $ordersMoved = Order::query()
            ->where('user_id', $loserId)
            ->update(['user_id' => $keeperId]);

        Address::query()
            ->where('user_id', $loserId)
            ->update(['user_id' => $keeperId]);

        if (Schema::hasTable('carts')) {
            DB::table('carts')->where('user_id', $loserId)->update(['user_id' => $keeperId]);
        }

        $this->mergeWishlists($loserId, $keeperId);

        ProductReview::query()
            ->where('user_id', $loserId)
            ->update(['user_id' => $keeperId]);

        ChannelConversation::query()
            ->where('user_id', $loserId)
            ->update(['user_id' => $keeperId]);

        User::query()
            ->where('referrer_id', $loserId)
            ->update(['referrer_id' => $keeperId]);

        $this->reassignTable('reseller_wallet_entries', $loserId, $keeperId);
        $this->reassignTable('comments', $loserId, $keeperId);
        $this->reassignTable('ai_image_prompts', $loserId, $keeperId);
        $this->reassignUniqueRows('expense_assistant_dismissals', $loserId, $keeperId, 'dedupe_key');

        return $ordersMoved;
    }

    private function detachLeftoverUserRows(User $loser): void
    {
        $loserId = (int) $loser->id;

        if (method_exists($loser, 'roles')) {
            $loser->roles()->detach();
        }

        if (method_exists($loser, 'permissions')) {
            $loser->permissions()->detach();
        }

        if (Schema::hasTable('sessions') && Schema::hasColumn('sessions', 'user_id')) {
            DB::table('sessions')->where('user_id', $loserId)->delete();
        }

        if (filled($loser->email)
            && Schema::hasTable('password_reset_tokens')
            && Schema::hasColumn('password_reset_tokens', 'email')) {
            DB::table('password_reset_tokens')->where('email', $loser->email)->delete();
        }
    }

    private function reassignTable(string $table, int $loserId, int $keeperId): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_id')) {
            return;
        }

        DB::table($table)->where('user_id', $loserId)->update(['user_id' => $keeperId]);
    }

    private function reassignUniqueRows(string $table, int $loserId, int $keeperId, string $uniqueColumn): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_id')) {
            return;
        }

        $keeperKeys = DB::table($table)->where('user_id', $keeperId)->pluck($uniqueColumn);

        if ($keeperKeys->isNotEmpty()) {
            DB::table($table)
                ->where('user_id', $loserId)
                ->whereIn($uniqueColumn, $keeperKeys)
                ->delete();
        }

        DB::table($table)->where('user_id', $loserId)->update(['user_id' => $keeperId]);
    }

    private function mergeWishlists(int $loserId, int $keeperId): void
    {
        $keeperProductIds = Wishlist::query()
            ->where('user_id', $keeperId)
            ->pluck('product_id')
            ->all();

        Wishlist::query()
            ->where('user_id', $loserId)
            ->whereIn('product_id', $keeperProductIds)
            ->delete();

        Wishlist::query()
            ->where('user_id', $loserId)
            ->update(['user_id' => $keeperId]);
    }

    private function absorbProfileFields(User $keeper, User $loser): void
    {
        if (trim((string) $keeper->name) === '' && trim((string) $loser->name) !== '') {
            $keeper->name = $loser->name;
        }

        if (! filled($keeper->email) && filled($loser->email)) {
            $emailTaken = User::query()
                ->where('email', $loser->email)
                ->where('id', '!=', $keeper->id)
                ->exists();

            if (! $emailTaken) {
                $keeper->email = $loser->email;
            }
        }

        if (! $keeper->is_active && $loser->is_active) {
            $keeper->is_active = true;
        }
    }

    /**
     * @param  Collection<int, mixed>  $loserIds
     */
    private function sampleLine(string $normalizedPhone, int $keeperId, Collection $loserIds): string
    {
        $ids = $loserIds->map(fn ($id): int => (int) $id)->values();
        $shown = $ids->take(3)->implode(', ');
        $extra = $ids->count() - 3;
        $remove = $extra > 0 ? $shown.', +'.$extra.' more' : $shown;

        return PhoneNumber::display($normalizedPhone)
            .' → keep #'.$keeperId
            .' (remove '.$remove.')';
    }
}
