<?php

namespace App\Console\Commands;

use App\Services\LegacyImport\LegacyImporter;
use Illuminate\Console\Command;

class ImportLegacyCommand extends Command
{
    protected $signature = 'import:legacy
                            {--fresh : Truncate target tables before importing}
                            {--only= : Comma-separated importers (countries,categories,tags,couriers,users,products,orders,order_products,settings)}';

    protected $description = 'Import production data from the legacy MySQL database into the sun2 schema';

    public function handle(LegacyImporter $importer): int
    {
        $this->info('Legacy import starting…');

        try {
            $importer->run(
                fresh: (bool) $this->option('fresh'),
                only: $this->option('only') ? array_map('trim', explode(',', $this->option('only'))) : null,
                output: $this,
            );
        } catch (\Throwable $e) {
            $this->error('Import failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Legacy import completed successfully.');

        return self::SUCCESS;
    }
}
