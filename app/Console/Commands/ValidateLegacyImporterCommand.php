<?php

namespace App\Console\Commands;

use App\Services\LegacyImport\LegacyImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

#[Signature('legacy:validate-cutover {--strict : Fail when the dump file is missing}')]
#[Description('Validate legacy ETL cutover readiness (commands, docs, dump path)')]
class ValidateLegacyImporterCommand extends Command
{
    /**
     * @var list<string>
     */
    private const REQUIRED_STEPS = [
        'countries',
        'categories',
        'tags',
        'couriers',
        'users',
        'products',
        'orders',
        'settings',
    ];

    public function handle(): int
    {
        $ok = true;

        $this->info('Legacy ETL cutover readiness check');
        $this->newLine();

        if (! class_exists(LegacyImporter::class)) {
            $this->error('LegacyImporter class missing.');
            $ok = false;
        } else {
            $this->line('✓ LegacyImporter present');
        }

        foreach (['import:legacy', 'import:legacy-descriptions', 'legacy:validate-cutover'] as $command) {
            if (! array_key_exists($command, Artisan::all())) {
                $this->error("✗ Artisan command missing: {$command}");
                $ok = false;
            } else {
                $this->line("✓ Command registered: {$command}");
            }
        }

        $readme = database_path('legacy/README.md');
        if (! File::isFile($readme)) {
            $this->error('✗ database/legacy/README.md missing');
            $ok = false;
        } else {
            $this->line('✓ Ops README present');
        }

        $dumpCandidates = [
            database_path('legacy/legacy_dump.sql'),
            base_path('sun.sql'),
            storage_path('sun.sql'),
        ];
        $dumpFound = null;
        foreach ($dumpCandidates as $path) {
            if (File::isFile($path)) {
                $dumpFound = $path;
                break;
            }
        }

        if ($dumpFound === null) {
            $msg = 'Legacy dump not present locally (expected legacy_dump.sql / sun.sql). OK for CI; place dump before cutover.';
            if ($this->option('strict')) {
                $this->error('✗ '.$msg);
                $ok = false;
            } else {
                $this->warn('⚠ '.$msg);
            }
        } else {
            $this->line('✓ Dump found: '.$dumpFound);
        }

        $this->newLine();
        $this->line('Required import steps: '.implode(', ', self::REQUIRED_STEPS));
        $this->line('Phase 2: php artisan import:legacy-descriptions (HTML product details).');
        $this->line('After import: php artisan admin:ensure-user && php artisan products:index-image-hashes');

        if (! $ok) {
            $this->newLine();
            $this->error('Cutover readiness: FAILED');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Cutover readiness: OK (run import:legacy with a dump before production cutover).');

        return self::SUCCESS;
    }
}
