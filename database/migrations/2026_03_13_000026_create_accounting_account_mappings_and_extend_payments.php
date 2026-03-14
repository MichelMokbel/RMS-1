<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_account_mappings')) {
            Schema::create('accounting_account_mappings', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('company_id');
                $table->string('mapping_key', 60);
                $table->unsignedBigInteger('ledger_account_id');
                $table->timestamps();

                $table->unique(['company_id', 'mapping_key'], 'acct_account_mappings_company_key_unique');
                $table->index(['company_id', 'ledger_account_id'], 'acct_account_mappings_company_account_index');
            });
        }

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                if (! Schema::hasColumn('payments', 'company_id')) {
                    $table->unsignedBigInteger('company_id')->nullable()->after('customer_id');
                }
                if (! Schema::hasColumn('payments', 'bank_account_id')) {
                    $table->unsignedBigInteger('bank_account_id')->nullable()->after('company_id');
                }
                if (! Schema::hasColumn('payments', 'period_id')) {
                    $table->unsignedBigInteger('period_id')->nullable()->after('bank_account_id');
                }
            });
        }

        if (Schema::hasTable('ledger_accounts')) {
            $defaults = [
                ['code' => '1010', 'name' => 'Operating Bank', 'type' => 'asset'],
                ['code' => '1020', 'name' => 'Card Clearing', 'type' => 'asset'],
                ['code' => '1030', 'name' => 'Cheque Clearing', 'type' => 'asset'],
                ['code' => '1040', 'name' => 'Other Clearing', 'type' => 'asset'],
            ];

            foreach ($defaults as $row) {
                $exists = DB::table('ledger_accounts')->where('code', $row['code'])->exists();
                if (! $exists) {
                    DB::table('ledger_accounts')->insert([
                        'company_id' => Schema::hasTable('accounting_companies')
                            ? DB::table('accounting_companies')->where('is_default', true)->value('id')
                            : null,
                        'code' => $row['code'],
                        'name' => $row['name'],
                        'type' => $row['type'],
                        'account_class' => $row['type'],
                        'is_active' => true,
                        'allow_direct_posting' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        if (Schema::hasTable('bank_accounts') && Schema::hasTable('ledger_accounts')) {
            $operatingBankAccountId = DB::table('ledger_accounts')->where('code', '1010')->value('id');

            if ($operatingBankAccountId) {
                DB::table('bank_accounts')
                    ->where(function ($query) {
                        $query->whereNull('ledger_account_id')
                            ->orWhere('ledger_account_id', DB::table('ledger_accounts')->where('code', '1000')->value('id'));
                    })
                    ->update([
                        'ledger_account_id' => $operatingBankAccountId,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                if (Schema::hasColumn('payments', 'period_id')) {
                    $table->dropColumn('period_id');
                }
                if (Schema::hasColumn('payments', 'bank_account_id')) {
                    $table->dropColumn('bank_account_id');
                }
                if (Schema::hasColumn('payments', 'company_id')) {
                    $table->dropColumn('company_id');
                }
            });
        }

        Schema::dropIfExists('accounting_account_mappings');
    }
};
