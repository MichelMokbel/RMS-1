<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROOT_REVISION_UNIQUE = 'ap_invoices_revision_root_number_unique';

    private const SOURCE_INDEX = 'ap_invoices_revision_source_index';

    private const ROOT_FOREIGN = 'ap_invoices_revision_root_fk';

    private const SOURCE_FOREIGN = 'ap_invoices_revision_source_fk';

    public function up(): void
    {
        if (! Schema::hasTable('ap_invoices')) {
            return;
        }

        Schema::table('ap_invoices', function (Blueprint $table): void {
            // ap_invoices.id is a legacy signed INT, so these self-references must
            // remain signed integers rather than Laravel's unsigned BIGINT foreignId.
            if (! Schema::hasColumn('ap_invoices', 'revision_root_id')) {
                $table->integer('revision_root_id')->nullable()->after('id');
            }

            if (! Schema::hasColumn('ap_invoices', 'revision_source_id')) {
                $table->integer('revision_source_id')->nullable()->after('revision_root_id');
            }

            if (! Schema::hasColumn('ap_invoices', 'revision_number')) {
                $table->unsignedInteger('revision_number')->default(0)->after('revision_source_id');
            }

            if (! Schema::hasColumn('ap_invoices', 'void_reason')) {
                $table->string('void_reason', 255)->nullable()->after('voided_by');
            }
        });

        if (! Schema::hasIndex('ap_invoices', self::ROOT_REVISION_UNIQUE, 'unique')) {
            Schema::table('ap_invoices', function (Blueprint $table): void {
                $table->unique(
                    ['revision_root_id', 'revision_number'],
                    self::ROOT_REVISION_UNIQUE
                );
            });
        }

        if (! Schema::hasIndex('ap_invoices', self::SOURCE_INDEX)) {
            Schema::table('ap_invoices', function (Blueprint $table): void {
                $table->index('revision_source_id', self::SOURCE_INDEX);
            });
        }

        $this->addSelfForeignKeyIfClean('revision_root_id', self::ROOT_FOREIGN);
        $this->addSelfForeignKeyIfClean('revision_source_id', self::SOURCE_FOREIGN);
    }

    public function down(): void
    {
        if (! Schema::hasTable('ap_invoices')) {
            return;
        }

        $this->dropForeignKeyIfExists('revision_source_id', self::SOURCE_FOREIGN);
        $this->dropForeignKeyIfExists('revision_root_id', self::ROOT_FOREIGN);

        if (Schema::hasIndex('ap_invoices', self::SOURCE_INDEX)) {
            Schema::table('ap_invoices', function (Blueprint $table): void {
                $table->dropIndex(self::SOURCE_INDEX);
            });
        }

        if (Schema::hasIndex('ap_invoices', self::ROOT_REVISION_UNIQUE)) {
            Schema::table('ap_invoices', function (Blueprint $table): void {
                $table->dropUnique(self::ROOT_REVISION_UNIQUE);
            });
        }

        Schema::table('ap_invoices', function (Blueprint $table): void {
            foreach (['void_reason', 'revision_number', 'revision_source_id', 'revision_root_id'] as $column) {
                if (Schema::hasColumn('ap_invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function addSelfForeignKeyIfClean(string $column, string $constraint): void
    {
        if ($this->foreignKeyExists($column, $constraint)) {
            return;
        }

        $hasOrphans = DB::table('ap_invoices as revision')
            ->leftJoin('ap_invoices as referenced_invoice', "revision.{$column}", '=', 'referenced_invoice.id')
            ->whereNotNull("revision.{$column}")
            ->whereNull('referenced_invoice.id')
            ->exists();

        if ($hasOrphans) {
            throw new RuntimeException(
                "Cannot add {$constraint}: ap_invoices.{$column} contains orphaned invoice references."
            );
        }

        Schema::table('ap_invoices', function (Blueprint $table) use ($column, $constraint): void {
            $table->foreign($column, $constraint)
                ->references('id')
                ->on('ap_invoices')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
    }

    private function dropForeignKeyIfExists(string $column, string $constraint): void
    {
        if (! $this->foreignKeyExists($column, $constraint)) {
            return;
        }

        $dropByColumn = DB::connection()->getDriverName() === 'sqlite';

        Schema::table('ap_invoices', function (Blueprint $table) use ($column, $constraint, $dropByColumn): void {
            // SQLite does not preserve constraint names and rebuilds the table by
            // matching constrained columns. MySQL must use our explicit name.
            $table->dropForeign($dropByColumn ? [$column] : $constraint);
        });
    }

    private function foreignKeyExists(string $column, string $constraint): bool
    {
        foreach (Schema::getForeignKeys('ap_invoices') as $foreignKey) {
            if (($foreignKey['name'] ?? null) === $constraint) {
                return true;
            }

            if (($foreignKey['columns'] ?? []) === [$column]
                && ($foreignKey['foreign_table'] ?? null) === 'ap_invoices') {
                return true;
            }
        }

        return false;
    }
};
