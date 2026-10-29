<?php

namespace App\Console\Commands;

use App\Services\Admin\CustomerDuplicateMergeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('customers:merge-duplicate-phones {--dry-run : List duplicate groups without writing} {--limit=25 : Duplicate groups per batch} {--once : Merge a single batch instead of looping until done}')]
#[Description('Temporary helper: merge duplicate phone users onto the latest user, move orders, then delete extras')]
class MergeDuplicatePhoneUsersCommand extends Command
{
    public function handle(CustomerDuplicateMergeService $merger): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, min(CustomerDuplicateMergeService::BATCH_SIZE, (int) $this->option('limit')));

        $preview = $merger->preview();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Duplicate phone groups', $preview['groups']],
                ['Users to remove', $preview['users_to_remove']],
                ['Orders to move', $preview['orders_to_move']],
            ],
        );

        foreach ($preview['samples'] as $sample) {
            $this->line('  '.$sample);
        }

        if ($preview['done']) {
            $this->info('No duplicate customer phones found.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('Dry run — no users merged or deleted.');

            return self::SUCCESS;
        }

        $mergedGroups = 0;
        $deletedUsers = 0;
        $reassignedOrders = 0;

        do {
            $result = $merger->mergeNextBatch($limit);
            $mergedGroups += $result['merged_groups'];
            $deletedUsers += $result['deleted_users'];
            $reassignedOrders += $result['reassigned_orders'];

            foreach ($result['samples'] as $sample) {
                $this->line('  … '.$sample);
            }
        } while (! $result['done'] && ! $this->option('once') && $result['merged_groups'] > 0);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Groups merged', $mergedGroups],
                ['Users deleted', $deletedUsers],
                ['Orders moved', $reassignedOrders],
                ['Groups remaining', $result['remaining_groups']],
            ],
        );

        $this->info($result['done']
            ? 'Duplicate phone merge complete.'
            : 'Batch finished — rerun to continue.');

        return self::SUCCESS;
    }
}
