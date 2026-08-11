<?php

namespace App\Console\Commands;

use App\Services\Orders\MigrationDataGapBackfill;
use Illuminate\Console\Command;

class BackfillMigrationDataGapsCommand extends Command
{
    protected $signature = 'orders:backfill-migration-gaps
                            {--dry-run : Report counts without writing}
                            {--skip-purchase-price : Do not copy catalog purchase_price onto zero-cost lines}
                            {--skip-settlement : Do not rebuild payment ledger from collected_amount}
                            {--estimate-courier : Fill courier_charge=0 from courier rate card (estimate)}
                            {--reclassify-unsettled-delivered : Move delivered+uncollected+no delivery date → dispatched}';

    protected $description = 'Repair legacy→sun2 migration gaps: line COGS proxy, settlement scalars/ledger, optional courier fee estimates';

    public function handle(MigrationDataGapBackfill $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run — no rows will be updated.');
        }

        $result = $backfill->run(
            dryRun: $dryRun,
            purchasePrice: ! $this->option('skip-purchase-price'),
            settlement: ! $this->option('skip-settlement'),
            estimateCourier: (bool) $this->option('estimate-courier'),
            reclassifyUnsettledDelivered: (bool) $this->option('reclassify-unsettled-delivered'),
        );

        $this->table(
            ['Repair', 'Rows'],
            [
                ['purchase_price / unit_cost lines', $result['purchase_price_lines']],
                ['settlement orders (ledger + sync)', $result['settlement_orders']],
                ['courier_charge estimates', $result['courier_estimate_orders']],
                ['reclassified unsettled delivered', $result['reclassified_orders']],
            ],
        );

        $this->info($dryRun ? 'Dry run complete.' : 'Backfill complete.');

        return self::SUCCESS;
    }
}
