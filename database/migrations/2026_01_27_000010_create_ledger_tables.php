<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ledger_accounts')) {
            Schema::create('ledger_accounts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('code', 50)->unique();
                $table->string('name', 255);
                $table->string('type', 20);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('subledger_entries')) {
            Schema::create('subledger_entries', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('source_type', 50);
                $table->unsignedBigInteger('source_id');
                $table->string('event', 50);
                $table->date('entry_date');
                $table->string('description', 255)->nullable();
                $table->integer('branch_id')->nullable();
                $table->string('status', 20)->default('posted');
                $table->timestamp('posted_at')->nullable();
                $table->integer('posted_by')->nullable();
                $table->timestamp('voided_at')->nullable();
                $table->integer('voided_by')->nullable();
                $table->timestamps();

                $table->index(['source_type', 'source_id']);
                $table->unique(['source_type', 'source_id', 'event']);
                $table->index('entry_date');
            });
        }

        if (! Schema::hasTable('subledger_lines')) {
            Schema::create('subledger_lines', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('entry_id');
                $table->unsignedBigInteger('account_id');
                $table->decimal('debit', 14, 4)->default(0);
                $table->decimal('credit', 14, 4)->default(0);
                $table->string('memo', 255)->nullable();
                $table->timestamps();

                $table->index(['entry_id', 'account_id']);
            });
        }

        if (! Schema::hasTable('gl_batches')) {
            Schema::create('gl_batches', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->date('period_start');
                $table->date('period_end');
                $table->string('status', 20)->default('open');
                $table->timestamp('generated_at')->nullable();
                $table->integer('created_by')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->integer('posted_by')->nullable();
                $table->timestamps();

                $table->unique(['period_start', 'period_end']);
            });
        }

        if (! Schema::hasTable('gl_batch_lines')) {
            Schema::create('gl_batch_lines', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('batch_id');
                $table->unsignedBigInteger('account_id');
                $table->decimal('debit_total', 14, 4)->default(0);
                $table->decimal('credit_total', 14, 4)->default(0);
                $table->timestamps();

                $table->unique(['batch_id', 'account_id']);
                $table->index(['batch_id', 'account_id']);
            });
        }

        if (Schema::hasTable('ledger_accounts')) {
            $defaults = [
                ['code' => '1000', 'name' => 'Cash', 'type' => 'asset'],
                ['code' => '1100', 'name' => 'Petty Cash', 'type' => 'asset'],
                ['code' => '1200', 'name' => 'Inventory', 'type' => 'asset'],
                ['code' => '1300', 'name' => 'Supplier Advances', 'type' => 'asset'],
                ['code' => '1400', 'name' => 'Input Tax', 'type' => 'asset'],
                ['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability'],
                ['code' => '2100', 'name' => 'GRNI Clearing', 'type' => 'liability'],
                ['code' => '5000', 'name' => 'COGS', 'type' => 'expense'],
                ['code' => '5100', 'name' => 'Inventory Adjustments', 'type' => 'expense'],
                ['code' => '5200', 'name' => 'Petty Cash Over/Short', 'type' => 'expense'],
                ['code' => '6000', 'name' => 'General Expense', 'type' => 'expense'],
            ];

            foreach ($defaults as $row) {
                $exists = DB::table('ledger_accounts')->where('code', $row['code'])->exists();
                if (! $exists) {
                    DB::table('ledger_accounts')->insert([
                        'code' => $row['code'],
                        'name' => $row['name'],
                        'type' => $row['type'],
                        'is_active' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        if (Schema::hasTable('subledger_entries')) {
            if (DB::connection()->getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE subledger_entries MODIFY branch_id INT NULL');
                DB::statement('ALTER TABLE subledger_entries MODIFY posted_by INT NULL');
                DB::statement('ALTER TABLE subledger_entries MODIFY voided_by INT NULL');
            }
            if (Schema::hasTable('users')) {
                // #region agent log
                try {
                    $logPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'.cursor'.DIRECTORY_SEPARATOR.'debug.log';
                    $fkPostedExists = $this->foreignKeyExists('subledger_entries', 'subledger_entries_posted_by_foreign');
                    $fkVoidedExists = $this->foreignKeyExists('subledger_entries', 'subledger_entries_voided_by_foreign');
                    file_put_contents(
                        $logPath,
                        json_encode([
                            'sessionId' => 'debug-session',
                            'runId' => 'pre-fix',
                            'hypothesisId' => 'H3',
                            'location' => 'database/migrations/2026_01_27_000010_create_ledger_tables.php:123',
                            'message' => 'subledger_entries FK check BEFORE add',
                            'data' => [
                                'fk_posted_by_exists' => $fkPostedExists,
                                'fk_voided_by_exists' => $fkVoidedExists,
                            ],
                            'timestamp' => (int) (microtime(true) * 1000),
                        ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
                        FILE_APPEND
                    );
                } catch (\Throwable $e) {
                    // ignore
                }
                // #endregion
                Schema::table('subledger_entries', function (Blueprint $table) {
                    if (!$this->foreignKeyExists('subledger_entries', 'subledger_entries_posted_by_foreign')) {
                        $table->foreign('posted_by')->references('id')->on('users')->nullOnDelete();
                    }
                    if (!$this->foreignKeyExists('subledger_entries', 'subledger_entries_voided_by_foreign')) {
                        $table->foreign('voided_by')->references('id')->on('users')->nullOnDelete();
                    }
                });
            }
            if (Schema::hasTable('branches')) {
                // #region agent log
                try {
                    $logPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'.cursor'.DIRECTORY_SEPARATOR.'debug.log';
                    $fkExists = $this->foreignKeyExists('subledger_entries', 'subledger_entries_branch_id_foreign');
                    file_put_contents(
                        $logPath,
                        json_encode([
                            'sessionId' => 'debug-session',
                            'runId' => 'pre-fix',
                            'hypothesisId' => 'H3',
                            'location' => 'database/migrations/2026_01_27_000010_create_ledger_tables.php:129',
                            'message' => 'subledger_entries branch_id FK check BEFORE add',
                            'data' => ['fk_branch_id_exists' => $fkExists],
                            'timestamp' => (int) (microtime(true) * 1000),
                        ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
                        FILE_APPEND
                    );
                } catch (\Throwable $e) {
                    // ignore
                }
                // #endregion
                Schema::table('subledger_entries', function (Blueprint $table) {
                    if (!$this->foreignKeyExists('subledger_entries', 'subledger_entries_branch_id_foreign')) {
                        $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
                    }
                });
            }
        }

        if (Schema::hasTable('subledger_lines')) {
            if (Schema::hasTable('subledger_entries')) {
                // #region agent log
                try {
                    $logPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'.cursor'.DIRECTORY_SEPARATOR.'debug.log';
                    $fkExists = $this->foreignKeyExists('subledger_lines', 'subledger_lines_entry_id_foreign');
                    file_put_contents(
                        $logPath,
                        json_encode([
                            'sessionId' => 'debug-session',
                            'runId' => 'pre-fix',
                            'hypothesisId' => 'H3',
                            'location' => 'database/migrations/2026_01_27_000010_create_ledger_tables.php:137',
                            'message' => 'subledger_lines entry_id FK check BEFORE add',
                            'data' => ['fk_entry_id_exists' => $fkExists],
                            'timestamp' => (int) (microtime(true) * 1000),
                        ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
                        FILE_APPEND
                    );
                } catch (\Throwable $e) {
                    // ignore
                }
                // #endregion
                Schema::table('subledger_lines', function (Blueprint $table) {
                    if (!$this->foreignKeyExists('subledger_lines', 'subledger_lines_entry_id_foreign')) {
                        $table->foreign('entry_id')->references('id')->on('subledger_entries')->cascadeOnDelete();
                    }
                });
            }
            if (Schema::hasTable('ledger_accounts')) {
                // #region agent log
                try {
                    $logPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'.cursor'.DIRECTORY_SEPARATOR.'debug.log';
                    $fkExists = $this->foreignKeyExists('subledger_lines', 'subledger_lines_account_id_foreign');
                    file_put_contents(
                        $logPath,
                        json_encode([
                            'sessionId' => 'debug-session',
                            'runId' => 'pre-fix',
                            'hypothesisId' => 'H3',
                            'location' => 'database/migrations/2026_01_27_000010_create_ledger_tables.php:142',
                            'message' => 'subledger_lines account_id FK check BEFORE add',
                            'data' => ['fk_account_id_exists' => $fkExists],
                            'timestamp' => (int) (microtime(true) * 1000),
                        ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
                        FILE_APPEND
                    );
                } catch (\Throwable $e) {
                    // ignore
                }
                // #endregion
                Schema::table('subledger_lines', function (Blueprint $table) {
                    if (!$this->foreignKeyExists('subledger_lines', 'subledger_lines_account_id_foreign')) {
                        $table->foreign('account_id')->references('id')->on('ledger_accounts')->restrictOnDelete();
                    }
                });
            }
        }

        if (Schema::hasTable('gl_batches')) {
            if (DB::connection()->getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE gl_batches MODIFY created_by INT NULL');
                DB::statement('ALTER TABLE gl_batches MODIFY posted_by INT NULL');
            }
        }

        if (Schema::hasTable('gl_batches') && Schema::hasTable('users')) {
            // #region agent log
            try {
                $logPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'.cursor'.DIRECTORY_SEPARATOR.'debug.log';
                $fkCreatedExists = $this->foreignKeyExists('gl_batches', 'gl_batches_created_by_foreign');
                $fkPostedExists = $this->foreignKeyExists('gl_batches', 'gl_batches_posted_by_foreign');
                file_put_contents(
                    $logPath,
                    json_encode([
                        'sessionId' => 'debug-session',
                        'runId' => 'pre-fix',
                        'hypothesisId' => 'H3',
                        'location' => 'database/migrations/2026_01_27_000010_create_ledger_tables.php:156',
                        'message' => 'gl_batches FK check BEFORE add',
                        'data' => [
                            'fk_created_by_exists' => $fkCreatedExists,
                            'fk_posted_by_exists' => $fkPostedExists,
                        ],
                        'timestamp' => (int) (microtime(true) * 1000),
                    ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
                    FILE_APPEND
                );
            } catch (\Throwable $e) {
                // ignore
            }
            // #endregion
            Schema::table('gl_batches', function (Blueprint $table) {
                if (!$this->foreignKeyExists('gl_batches', 'gl_batches_created_by_foreign')) {
                    $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                }
                if (!$this->foreignKeyExists('gl_batches', 'gl_batches_posted_by_foreign')) {
                    $table->foreign('posted_by')->references('id')->on('users')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('gl_batch_lines')) {
            if (Schema::hasTable('gl_batches')) {
                // #region agent log
                try {
                    $logPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'.cursor'.DIRECTORY_SEPARATOR.'debug.log';
                    $fkExists = $this->foreignKeyExists('gl_batch_lines', 'gl_batch_lines_batch_id_foreign');
                    file_put_contents(
                        $logPath,
                        json_encode([
                            'sessionId' => 'debug-session',
                            'runId' => 'pre-fix',
                            'hypothesisId' => 'H3',
                            'location' => 'database/migrations/2026_01_27_000010_create_ledger_tables.php:164',
                            'message' => 'gl_batch_lines batch_id FK check BEFORE add',
                            'data' => ['fk_batch_id_exists' => $fkExists],
                            'timestamp' => (int) (microtime(true) * 1000),
                        ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
                        FILE_APPEND
                    );
                } catch (\Throwable $e) {
                    // ignore
                }
                // #endregion
                Schema::table('gl_batch_lines', function (Blueprint $table) {
                    if (!$this->foreignKeyExists('gl_batch_lines', 'gl_batch_lines_batch_id_foreign')) {
                        $table->foreign('batch_id')->references('id')->on('gl_batches')->cascadeOnDelete();
                    }
                });
            }
            if (Schema::hasTable('ledger_accounts')) {
                // #region agent log
                try {
                    $logPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'.cursor'.DIRECTORY_SEPARATOR.'debug.log';
                    $fkExists = $this->foreignKeyExists('gl_batch_lines', 'gl_batch_lines_account_id_foreign');
                    file_put_contents(
                        $logPath,
                        json_encode([
                            'sessionId' => 'debug-session',
                            'runId' => 'pre-fix',
                            'hypothesisId' => 'H3',
                            'location' => 'database/migrations/2026_01_27_000010_create_ledger_tables.php:169',
                            'message' => 'gl_batch_lines account_id FK check BEFORE add',
                            'data' => ['fk_account_id_exists' => $fkExists],
                            'timestamp' => (int) (microtime(true) * 1000),
                        ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
                        FILE_APPEND
                    );
                } catch (\Throwable $e) {
                    // ignore
                }
                // #endregion
                Schema::table('gl_batch_lines', function (Blueprint $table) {
                    if (!$this->foreignKeyExists('gl_batch_lines', 'gl_batch_lines_account_id_foreign')) {
                        $table->foreign('account_id')->references('id')->on('ledger_accounts')->restrictOnDelete();
                    }
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_batch_lines');
        Schema::dropIfExists('gl_batches');
        Schema::dropIfExists('subledger_lines');
        Schema::dropIfExists('subledger_entries');
        Schema::dropIfExists('ledger_accounts');
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'mysql') {
            $database = $connection->getDatabaseName();
            if (! $database) {
                return false;
            }

            $row = DB::selectOne(
                'SELECT 1 FROM information_schema.table_constraints WHERE table_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = "FOREIGN KEY" LIMIT 1',
                [$database, $table, $constraint]
            );

            return $row !== null;
        }

        return false;
    }
};
