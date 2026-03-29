<?php

use App\Services\Customers\CustomerCodeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers') || ! Schema::hasColumn('customers', 'customer_code')) {
            return;
        }

        DB::transaction(function () {
            $service = app(CustomerCodeService::class);
            $customers = DB::table('customers')
                ->select('id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($customers as $index => $customer) {
                DB::table('customers')
                    ->where('id', $customer->id)
                    ->update([
                        'customer_code' => $service->format($index + 1),
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    public function down(): void
    {
        // Intentionally irreversible: previous customer codes are not recoverable.
    }
};
