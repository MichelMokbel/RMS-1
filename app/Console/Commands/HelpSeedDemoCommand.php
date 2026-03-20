<?php

namespace App\Console\Commands;

use Database\Seeders\HelpContentSeeder;
use Database\Seeders\HelpDemoSeeder;
use Illuminate\Console\Command;

class HelpSeedDemoCommand extends Command
{
    protected $signature = 'help:seed-demo';

    protected $description = 'Seed Help Center content and a controlled demo dataset for screenshot capture.';

    public function handle(): int
    {
        app(HelpContentSeeder::class)->run();
        app(HelpDemoSeeder::class)->run();

        $this->info('Help content and demo dataset seeded.');

        return self::SUCCESS;
    }
}
