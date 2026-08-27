<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LegacyCutoverValidateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function legacy_validate_cutover_command_succeeds_without_dump(): void
    {
        $this->assertArrayHasKey('legacy:validate-cutover', Artisan::all());

        $this->artisan('legacy:validate-cutover')
            ->expectsOutputToContain('Cutover readiness: OK')
            ->assertSuccessful();
    }
}
