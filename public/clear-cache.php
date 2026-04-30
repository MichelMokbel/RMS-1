<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain');

$secret = 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET';

if (! hash_equals($secret, $_GET['secret'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

try {
    require __DIR__.'/../vendor/autoload.php';

    $app = require __DIR__.'/../bootstrap/app.php';

    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

    foreach ([
        'view:clear',
        'cache:clear',
        'config:clear',
        'route:clear',
        'permission:cache-reset',
    ] as $command) {
        echo "Running {$command}...\n";

        $status = $kernel->call($command);
        echo $kernel->output();

        if ($status !== 0) {
            echo "\nFAILED with status {$status}\n";
        } else {
            echo "OK\n\n";
        }
    }

    echo "Done. DELETE THIS FILE NOW.\n";
} catch (Throwable $e) {
    http_response_code(500);

    echo "ERROR\n";
    echo get_class($e)."\n";
    echo $e->getMessage()."\n\n";
    echo $e->getFile().':'.$e->getLine()."\n\n";
    echo $e->getTraceAsString();
}
