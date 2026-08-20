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

class CustomerDuplicateMergeService
{
    public const BATCH_SIZE = 25;

    /**
     * @return Collection<string, Collection<int, User>>
     */
    public function duplicateGroups(): Collection
    {
        $customers = User::query()
            ->role('customers')
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
     * Merge up to $limit duplicate phone groups. Keeps the highest-id (latest) user.
     *
     * @return array{
     *     scanned_groups: int,
     *     merged_groups: int,
     *     deleted_users: int,
     *     reassigned_orders: int,
     *     remaining_groups: int,
     *     done: bool,
     *     samples: list<string>
     * }
     */
    public function mergeNextBatch(int $limit = self::BATCH_SIZE): array
    {
        $limit = max(1, min(self::BATCH_SIZE, $limit));
        $groups = $this->duplicateGroups()->take($limit);

        $mergedGroups = 0;
        $deletedUsers = 0;
        $reassignedOrders = 0;
        $samples = [];

        foreach ($groups as $normalizedPhone => $group) {
            $result = DB::transaction(fn (): array => $this->mergeGroup($group));

            if ($result['deleted_users'] > 0) {
                $mergedGroups++;
                $deletedUsers += $result['deleted_users'];
                $reassignedOrders += $result['reassigned_orders'];

                if (count($samples) < 8) {
                    $samples[] = PhoneNumber::display((string) $normalizedPhone)
                        .' → kept #'.$result['keeper_id']
                        .' (removed '.$result['deleted_users'].')';
                }
            }
        }

        $remaining = $this->duplicateGroupCount();

        return [
            'scanned_groups' => $groups->count(),
            'merged_groups' => $mergedGroups,
            'deleted_users' => $deletedUsers,
            'reassigned_orders' => $reassignedOrders,
            'remaining_groups' => $remaining,
            'done' => $remaining === 0,
            'samples' => $samples,
        ];
    }

    /**
     * @param  Collection<int, User>  $group
     * @return array{keeper_id: int, deleted_users: int, reassigned_orders: int}
     */
    private function mergeGroup(Collection $group): array
    {
        $sorted = $group->sortByDesc('id')->values();
        $keeper = User::query()->findOrFail((int) $sorted->first()->id);
        $losers = $sorted->slice(1)->values();

        $deleted = 0;
        $ordersMoved = 0;

        foreach ($losers as $loserStub) {
            $loser = User::query()->find((int) $loserStub->id);

            if (! $loser || (int) $loser->id === (int) $keeper->id) {
                continue;
            }

            $ordersMoved += $this->reassignUserOwnedRows($loser, $keeper);
            $this->absorbProfileFields($keeper, $loser);
            $passwordHash = $loser->getRawOriginal('password');
            $loser->delete();
            $deleted++;

            if ($keeper->getRawOriginal('password') === null && filled($passwordHash)) {
                DB::table('users')->where('id', $keeper->id)->update(['password' => $passwordHash]);
                $keeper->refresh();
            }
        }

        $displayPhone = PhoneNumber::display((string) $keeper->phone);
        if (PhoneNumber::isValidDisplayMobile($displayPhone) && $keeper->phone !== $displayPhone) {
            if (! User::query()->where('phone', $displayPhone)->where('id', '!=', $keeper->id)->exists()) {
                $keeper->phone = $displayPhone;
            }
        }

        $keeper->save();

        return [
            'keeper_id' => (int) $keeper->id,
            'deleted_users' => $deleted,
            'reassigned_orders' => $ordersMoved,
        ];
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

        if (Schema::hasTable('reseller_wallet_transactions')) {
            DB::table('reseller_wallet_transactions')
                ->where('user_id', $loserId)
                ->update(['user_id' => $keeperId]);
        }

        return $ordersMoved;
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
}
