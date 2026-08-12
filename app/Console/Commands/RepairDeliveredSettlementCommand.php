<?php

namespace App\Console\Commands;

use App\Services\Admin\OrderPackagingCourierRepairService;
use App\Services\Admin\OrderPaidStatusRepairService;
use Illuminate\Console\Command;

class RepairDeliveredSettlementCommand extends Command
{
    protected $signature = 'orders:repair-delivered-settlement
                            {--dry-run : Count eligible orders without writing}
                            {--estimate-courier : Fill ৳0 courier_charge from rate card before settlement}
                            {--limit=100 : Batch size per pass (max 100)}';

    protected $description = 'For delivered non-exchange orders: settle payment ledger to bill total (subtotal + delivery + charges − discount) and mark fully paid';

    public function handle(
        OrderPaidStatusRepairService $settlement,
        OrderPackagingCourierRepairService $courierRepair,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, min(OrderPaidStatusRepairService::BATCH_SIZE, (int) $this->option('limit')));

        if ($this->option('estimate-courier') && ! $dryRun) {
            $this->info('Filling missing courier charges from rate cards…');
            $afterId = 0;
            $courierFixed = 0;

            do {
                $batch = $courierRepair->repairNextBatch($afterId, $limit);
                $courierFixed += $batch['fixed_orders'];
                $afterId = $batch['next_after_id'];
            } while (! $batch['done']);

            $this->line('Courier charges filled on '.$courierFixed.' order(s).');
        }

        $eligible = $settlement->eligibleOrderCount();
        $this->line('Eligible delivered/legacy-paid settlement repairs: '.$eligible);

        if ($dryRun) {
            $this->warn('Dry run — no settlement rows written.');

            return self::SUCCESS;
        }

        if ($eligible < 1) {
            $this->info('Nothing to repair.');

            return self::SUCCESS;
        }

        $afterId = 0;
        $scanned = 0;
        $fixed = 0;
        $payments = 0;

        do {
            $batch = $settlement->repairNextBatch($afterId, $limit);
            $scanned += $batch['scanned'];
            $fixed += $batch['fixed_orders'];
            $payments += $batch['payments_created'];
            $afterId = $batch['next_after_id'];

            if ($batch['sample_order_numbers'] !== []) {
                $this->line('  … '.implode(', ', $batch['sample_order_numbers']));
            }
        } while (! $batch['done']);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Scanned', $scanned],
                ['Orders fixed', $fixed],
                ['Payment txns created', $payments],
            ],
        );

        $this->info('Delivered settlement repair complete.');

        return self::SUCCESS;
    }
}
