<?php

namespace App\Console\Commands;

use App\Services\Channels\MessengerConversationSyncService;
use Illuminate\Console\Command;

class SyncMessengerConversationsCommand extends Command
{
    protected $signature = 'messenger:sync-conversations
                            {--conversations=50 : Max Graph conversation threads to pull}
                            {--messages=30 : Max messages per thread}';

    protected $description = 'Pull recent Facebook Messenger conversations into Admin Inbox';

    public function handle(MessengerConversationSyncService $sync): int
    {
        $result = $sync->sync(
            conversationLimit: (int) $this->option('conversations'),
            messagesPerThread: (int) $this->option('messages'),
        );

        if (! $result['ok']) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        $this->info($result['message']);

        return self::SUCCESS;
    }
}
