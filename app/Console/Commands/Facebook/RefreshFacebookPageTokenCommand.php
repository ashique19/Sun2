<?php

namespace App\Console\Commands\Facebook;

use App\Services\Facebook\FacebookPageTokenService;
use Illuminate\Console\Command;

class RefreshFacebookPageTokenCommand extends Command
{
    protected $signature = 'facebook:refresh-page-token';

    protected $description = 'Exchange the stored Facebook token for a long-lived one and persist it';

    public function handle(FacebookPageTokenService $tokens): int
    {
        $result = $tokens->refreshStoredToken();

        if (! $result['ok']) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        $this->info($result['message']);

        return self::SUCCESS;
    }
}
