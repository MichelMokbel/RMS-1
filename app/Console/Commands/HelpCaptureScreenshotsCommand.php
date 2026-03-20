<?php

namespace App\Console\Commands;

use App\Services\Help\HelpCaptureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class HelpCaptureScreenshotsCommand extends Command
{
    protected $signature = 'help:capture-screenshots
        {--force : Allow capture in non-local environments}
        {--fresh : Rebuild all screenshots even if output files already exist}';

    protected $description = 'Capture deterministic Help Center screenshots from the configured demo dataset.';

    public function handle(HelpCaptureService $capture): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Screenshot capture is blocked in production without --force.');

            return self::FAILURE;
        }

        $errors = $capture->validateManifest();
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $manifest = $capture->buildManifest();

        if (! $this->option('fresh')) {
            $manifest = array_values(array_filter($manifest, fn (array $scenario): bool => ! is_file($scenario['output_absolute_path'])));
        }

        if ($manifest === []) {
            $count = $capture->syncCapturedAssets();
            $this->info("No new screenshots needed. Synchronized {$count} captured screenshot assets.");

            return self::SUCCESS;
        }

        $manifestPath = storage_path('app/help-capture-manifest.json');
        File::ensureDirectoryExists(dirname($manifestPath));
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $process = new Process(['node', 'scripts/help-capture.mjs', $manifestPath], base_path(), [
            'APP_URL' => config('app.url'),
        ]);
        $process->setTimeout(1200);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            $this->error('Help screenshot capture failed.');

            return self::FAILURE;
        }

        $count = $capture->syncCapturedAssets();
        $this->info("Synchronized {$count} captured screenshot assets.");

        return self::SUCCESS;
    }
}
