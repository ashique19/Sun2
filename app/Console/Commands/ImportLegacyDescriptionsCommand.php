<?php

namespace App\Console\Commands;

use App\Services\LegacyImport\LegacyDescriptionImporter;
use Illuminate\Console\Command;

class ImportLegacyDescriptionsCommand extends Command
{
    protected $signature = 'import:legacy-descriptions
                            {--dry-run : Count updates without writing}
                            {--from-id= : Resume from this legacy/product id}
                            {--force : Overwrite non-empty sun2 descriptions}';

    protected $description = 'Phase 2: backfill product description / description_bn from legacy product_detail fields';

    public function handle(LegacyDescriptionImporter $importer): int
    {
        $fromId = $this->option('from-id');

        $this->info('Legacy description import starting…');

        try {
            $result = $importer->run(
                output: $this,
                dryRun: (bool) $this->option('dry-run'),
                fromId: $fromId !== null && $fromId !== '' ? (int) $fromId : null,
                force: (bool) $this->option('force'),
            );
        } catch (\Throwable $e) {
            $this->error('Description import failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['scanned', $result['scanned']],
                ['updated', $result['updated']],
                ['skipped', $result['skipped']],
            ],
        );

        $this->info($this->option('dry-run') ? 'Dry run complete.' : 'Description import complete.');

        return self::SUCCESS;
    }
}
