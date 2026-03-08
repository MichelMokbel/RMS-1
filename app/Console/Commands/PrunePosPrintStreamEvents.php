<?php

namespace App\Console\Commands;

use App\Services\POS\PosPrintJobService;
use Illuminate\Console\Command;

class PrunePosPrintStreamEvents extends Command
{
    protected $signature = 'pos:prune-print-stream-events {--hours=24 : Remove events older than this many hours}';

    protected $description = 'Prune old POS print stream events used by SSE fanout.';

    public function handle(PosPrintJobService $service): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $deleted = $service->pruneStreamEvents($hours);

        $this->info("Deleted {$deleted} pos_print_stream_events row(s) older than {$hours} hour(s).");

        return self::SUCCESS;
    }
}
