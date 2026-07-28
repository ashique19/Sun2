<?php

namespace App\Console\Commands;

use App\Services\Channels\ChannelInboxPurgeService;
use Illuminate\Console\Command;

class PurgeChannelInboxCommand extends Command
{
    protected $signature = 'channels:purge-inbox
                            {--days= : Retention days (default from config)}
                            {--dry-run : Count stale conversations without deleting}';

    protected $description = 'Delete Admin Inbox conversations inactive longer than the retention window';

    public function handle(ChannelInboxPurgeService $purge): int
    {
        $days = $this->option('days');
        $result = $purge->purge(
            retentionDays: $days !== null && $days !== '' ? (int) $days : null,
            dryRun: (bool) $this->option('dry-run'),
        );

        $verb = $this->option('dry-run') ? 'Would purge' : 'Purged';

        $this->info(sprintf(
            '%s %d conversation(s) older than cutoff %s (%d AI draft(s) discarded).',
            $verb,
            $result['purged'],
            $result['cutoff'],
            $result['drafts_discarded'],
        ));

        return self::SUCCESS;
    }
}
