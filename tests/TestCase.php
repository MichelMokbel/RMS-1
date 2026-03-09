<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        if (is_file(base_path('bootstrap/cache/config.php'))) {
            throw new RuntimeException(
                'Refusing to run tests with cached config. Run `php artisan config:clear` first.'
            );
        }

        $connection = (string) ($_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION') ?: '');
        $database = (string) ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: '');

        if ($connection === 'mysql' && ! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Unsafe test DB target: {$connection}/{$database}. Expected a dedicated *_test database."
            );
        }

        parent::setUp();
    }
}
