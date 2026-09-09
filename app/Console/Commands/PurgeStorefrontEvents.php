<?php

namespace App\Console\Commands;

use App\Models\StorefrontEvent;
use Illuminate\Console\Command;

class PurgeStorefrontEvents extends Command
{
    protected $signature = 'storefront:purge-events {--days=180}';

    protected $description = 'Delete expired anonymous storefront browsing events';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $deleted = StorefrontEvent::query()
            ->where('received_at', '<', now('UTC')->subDays($days))
            ->delete();
        $this->info("Deleted {$deleted} expired storefront events.");

        return self::SUCCESS;
    }
}
