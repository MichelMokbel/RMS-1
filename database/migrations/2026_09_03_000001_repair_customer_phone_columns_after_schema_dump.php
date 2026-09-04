<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'phone_e164')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->string('phone_e164', 20)->nullable()->after('phone');
            });
        }

        if (! Schema::hasColumn('customers', 'phone_verified_at')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->timestamp('phone_verified_at')->nullable()->after('phone_e164');
            });
        }

        $this->ensurePhoneIndex();
        $this->backfillMissingNormalizedPhones();
    }

    public function down(): void
    {
        // The historical portal migration owns these columns. This repair must not remove
        // them when it is rolled back from a database whose schema dump omitted them.
    }

    private function ensurePhoneIndex(): void
    {
        $exists = DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'customers')
            ->where('index_name', 'customers_phone_e164_index')
            ->exists();

        if (! $exists) {
            DB::statement('ALTER TABLE `customers` ADD INDEX `customers_phone_e164_index` (`phone_e164`)');
        }
    }

    private function backfillMissingNormalizedPhones(): void
    {
        $countryDigits = preg_replace('/\D+/', '', (string) env('CUSTOMERS_DEFAULT_COUNTRY_CODE', '+974')) ?: '974';
        $localPhoneLength = max(1, (int) env('CUSTOMERS_LOCAL_PHONE_LENGTH', 8));

        DB::table('customers')
            ->whereNull('phone_e164')
            ->whereNotNull('phone')
            ->select(['id', 'phone'])
            ->orderBy('id')
            ->chunkById(200, function ($customers) use ($countryDigits, $localPhoneLength): void {
                foreach ($customers as $customer) {
                    $normalized = $this->normalizePhone(
                        (string) $customer->phone,
                        $countryDigits,
                        $localPhoneLength,
                    );

                    if ($normalized === null) {
                        continue;
                    }

                    DB::table('customers')
                        ->where('id', $customer->id)
                        ->whereNull('phone_e164')
                        ->update(['phone_e164' => $normalized]);
                }
            });
    }

    private function normalizePhone(string $phone, string $countryDigits, int $localPhoneLength): ?string
    {
        $normalized = preg_replace('/[^\d+]+/', '', trim($phone)) ?? '';

        if ($normalized === '') {
            return null;
        }

        if (str_starts_with($normalized, '00')) {
            $normalized = '+'.substr($normalized, 2);
        }

        if (str_starts_with($normalized, '+')) {
            $digits = preg_replace('/\D+/', '', substr($normalized, 1)) ?? '';

            return $digits === '' ? null : '+'.$digits;
        }

        $digits = ltrim(preg_replace('/\D+/', '', $normalized) ?? '', '0');

        if ($digits === '') {
            return null;
        }

        return strlen($digits) <= $localPhoneLength
            ? '+'.$countryDigits.$digits
            : '+'.$digits;
    }
};
