<?php

namespace Database\Seeders;

use App\Services\Locations\BangladeshLocationImporter;
use Illuminate\Database\Seeder;

class BangladeshLocationSeeder extends Seeder
{
    public function run(BangladeshLocationImporter $importer): void
    {
        $importer->import();
    }
}
