<?php

namespace App\Services\Admin;

use App\Models\InvestorPitchShare;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvestorPitchShareService
{
    public const MIGRATION = '2026_08_12_060743_create_investor_pitch_shares_table';

    /**
     * Create the share table if a deploy skipped the migration.
     * Safe to call repeatedly.
     */
    public function ensureSchema(): void
    {
        if (Schema::hasTable('investor_pitch_shares')) {
            $this->ensureMigrationRecorded();

            return;
        }

        Schema::create('investor_pitch_shares', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('label')->nullable();
            $table->string('password');
            $table->timestamp('expires_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        $this->ensureMigrationRecorded();
    }

    /**
     * @return array{share: InvestorPitchShare, plain_password: string, url: string}
     */
    public function create(?string $label, string $password, int $days, ?int $createdBy = null): array
    {
        $this->ensureSchema();

        $days = max(1, min(90, $days));
        $password = trim($password);

        if ($password === '') {
            throw ValidationException::withMessages([
                'sharePassword' => 'Enter a password for the recipient.',
            ]);
        }

        if (strlen($password) < 6) {
            throw ValidationException::withMessages([
                'sharePassword' => 'Use at least 6 characters for the share password.',
            ]);
        }

        $label = trim((string) $label);
        $label = $label !== '' ? $label : null;

        $share = InvestorPitchShare::query()->create([
            'token' => Str::random(48),
            'label' => $label,
            'password' => $password,
            'expires_at' => now()->addDays($days),
            'created_by' => $createdBy,
        ]);

        return [
            'share' => $share,
            'plain_password' => $password,
            'url' => $share->url(),
        ];
    }

    private function ensureMigrationRecorded(): void
    {
        if (! Schema::hasTable('migrations')) {
            return;
        }

        $exists = DB::table('migrations')
            ->where('migration', self::MIGRATION)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('migrations')->insert([
            'migration' => self::MIGRATION,
            'batch' => ((int) DB::table('migrations')->max('batch')) + 1,
        ]);
    }
}
