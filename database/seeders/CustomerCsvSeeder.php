<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CustomerCsvSeeder extends Seeder
{
    /**
     * Seed customers from docs/Customers (1).csv.
     *
     * - Skips rows whose name already exists (case-insensitive).
     * - Auto-generates customer_code as CUST-XXXX.
     */
    public function run(): void
    {
        $csvPath = base_path('docs/Customers (1).csv');

        if (! file_exists($csvPath)) {
            $this->command->error("CSV file not found at: {$csvPath}");

            return;
        }

        $raw = file_get_contents($csvPath);
        if ($raw === false) {
            $this->command->error("Unable to read CSV file: {$csvPath}");

            return;
        }
        // Fix encoding: replace non-breaking spaces
        $raw = str_replace(["\xC2\xA0", "\xA0"], ' ', $raw);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $raw);
        rewind($handle);

        // Read header row
        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            $this->command->error('CSV file is empty or unreadable.');

            return;
        }

        // Normalise headers (trim BOM and whitespace)
        $headers = array_map(function ($h) {
            return trim($h, "\xEF\xBB\xBF \t\n\r\0\x0B");
        }, $headers);

        // Parse all rows
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($headers)) {
                continue;
            }
            $rows[] = array_combine($headers, $row);
        }
        fclose($handle);

        $this->command->info(count($rows).' rows read from CSV.');

        // ---------------------------------------------------------------
        // 1. Determine starting counter for customer_code
        // ---------------------------------------------------------------
        $maxCode = Customer::query()
            ->where('customer_code', 'like', 'CUST-%')
            ->selectRaw("MAX(CAST(SUBSTRING(customer_code, 6) AS UNSIGNED)) as max_num")
            ->value('max_num');
        $nextNum = ($maxCode ?? 0) + 1;

        // ---------------------------------------------------------------
        // 2. Collect existing names for skip check
        // ---------------------------------------------------------------
        $existingNames = Customer::pluck('name')
            ->map(fn ($n) => mb_strtolower(trim($n)))
            ->flip()
            ->toArray();

        // ---------------------------------------------------------------
        // 3. Process rows inside a transaction
        // ---------------------------------------------------------------
        $inserted = 0;
        $skipped = 0;

        DB::transaction(function () use (
            $rows,
            &$nextNum,
            &$existingNames,
            &$inserted,
            &$skipped,
        ) {
            foreach ($rows as $row) {
                $name = trim($row['Name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $nameLower = mb_strtolower($name);

                if (isset($existingNames[$nameLower])) {
                    $skipped++;

                    continue;
                }

                $phone = trim($row['Phone Number'] ?? '');
                $email = trim($row['Email'] ?? '');
                $address = trim($row['Address'] ?? '');
                $country = trim($row['Country'] ?? '');
                $creditStatus = trim($row['Credit Status'] ?? '');
                $creditLimit = (float) ($row['Credit Limit'] ?? 0);
                $isActive = mb_strtoupper(trim($row['Active'] ?? '')) === 'Y';

                // Clean up phone/email placeholders
                if ($phone === '0' || $phone === '-') {
                    $phone = null;
                }
                if ($email === '-' || $email === '') {
                    $email = null;
                }

                $customerCode = 'CUST-'.str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
                $nextNum++;

                Customer::create([
                    'customer_code' => $customerCode,
                    'name' => $name,
                    'customer_type' => 'retail',
                    'phone' => $phone ?: null,
                    'email' => $email ?: null,
                    'billing_address' => $address !== '' ? $address : null,
                    'country' => $country !== '' ? $country : null,
                    'credit_status' => $creditStatus !== '' ? $creditStatus : null,
                    'credit_limit' => $creditLimit,
                    'is_active' => $isActive,
                ]);

                $existingNames[$nameLower] = true;
                $inserted++;
            }
        });

        $this->command->info("Customers — inserted: {$inserted}, skipped: {$skipped}");
        $this->command->info('Customer CSV seeding complete.');
    }
}
