<?php

namespace App\Console\Commands;

use App\Services\Locations\BangladeshLocationImporter;
use App\Services\Storefront\AddressLocationGuesser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedBangladeshLocationsCommand extends Command
{
    protected $signature = 'locations:seed {--fresh : Truncate cities and areas before seeding}';

    protected $description = 'Seed districts and police stations from the public Bangladesh dataset';

    public function handle(BangladeshLocationImporter $importer): int
    {
        if ($this->option('fresh')) {
            $this->warn('Truncating existing cities and areas…');
            DB::table('areas')->delete();
            DB::table('cities')->delete();
        }

        $this->info('Importing Bangladesh districts and police stations…');

        try {
            $importer->import($this->output);
            AddressLocationGuesser::clearCache();
        } catch (\Throwable $e) {
            $this->error('Import failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
