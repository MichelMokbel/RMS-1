<?php

namespace Database\Seeders;

use App\Models\ArInvoice;
use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SalesEntryCsvSeeder extends Seeder
{
    /**
     * Import legacy sales from docs/sales-entry.csv.
     *
     * 1. Clears existing test AR invoice/payment data.
     * 2. Imports each CSV row as an ArInvoice + single ArInvoiceItem.
     * 3. Invoices before 2026-01-01 are marked "paid"; on/after are "issued"
     *    unless the Cash field is non-zero, in which case they are also "paid".
     * 4. All invoices are assigned to branch 1.
     */
    public function run(): void
    {
        $csvPath = base_path('docs/sales-entry.csv');

        if (! file_exists($csvPath)) {
            $this->command->error("CSV file not found at: {$csvPath}");

            return;
        }

        $raw = file_get_contents($csvPath);
        if ($raw === false) {
            $this->command->error("Unable to read CSV file: {$csvPath}");

            return;
        }
        // Fix encoding issues
        $raw = str_replace(["\xC2\xA0", "\xA0"], ' ', $raw);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $raw);
        rewind($handle);

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            $this->command->error('CSV file is empty or unreadable.');

            return;
        }

        $headers = array_map(function ($h) {
            return trim($h, "\xEF\xBB\xBF \t\n\r\0\x0B");
        }, $headers);

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
        // 1. Clear existing test data
        // ---------------------------------------------------------------
        $this->command->info('Clearing existing test data...');

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Clear payment allocations linked to AR invoices
        DB::table('payment_allocations')
            ->where('allocatable_type', ArInvoice::class)
            ->delete();

        DB::table('ar_invoice_items')->truncate();
        DB::table('ar_invoices')->truncate();
        DB::table('payments')->truncate();

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->command->info('Test data cleared.');

        // ---------------------------------------------------------------
        // 2. Build customer name -> id map
        // ---------------------------------------------------------------
        $customerMap = Customer::pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtolower(trim($name)) => $id])
            ->toArray();

        // Track next customer code for on-the-fly creation
        $maxCustCode = Customer::query()
            ->where('customer_code', 'like', 'CUST-%')
            ->selectRaw("MAX(CAST(SUBSTRING(customer_code, 6) AS UNSIGNED)) as max_num")
            ->value('max_num');
        $nextCustNum = ($maxCustCode ?? 0) + 1;

        $customersCreated = 0;
        $cutoffDate = Carbon::parse('2026-01-01');

        // ---------------------------------------------------------------
        // 3. Import rows in batched transaction
        // ---------------------------------------------------------------
        $invoicesInserted = 0;
        $skipped = 0;
        $now = now();

        // Process in chunks to keep memory and transaction size manageable
        $chunks = array_chunk($rows, 500);

        foreach ($chunks as $chunk) {
            DB::transaction(function () use (
                $chunk,
                &$customerMap,
                &$nextCustNum,
                &$customersCreated,
                &$invoicesInserted,
                &$skipped,
                $cutoffDate,
                $now,
            ) {
                foreach ($chunk as $row) {
                    $customerName = trim($row['Customer'] ?? '');
                    $docNo = trim($row['Document No'] ?? '');
                    $dateTimeStr = trim($row['Date & Time'] ?? '');

                    if ($customerName === '' || $docNo === '' || $dateTimeStr === '') {
                        $skipped++;

                        continue;
                    }

                    // Parse date (format: "2025-04-10 14:39:05.26+03")
                    try {
                        $issueDate = Carbon::parse($dateTimeStr);
                    } catch (\Throwable $e) {
                        $skipped++;

                        continue;
                    }

                    // Resolve customer
                    $customerLower = mb_strtolower($customerName);
                    if (! isset($customerMap[$customerLower])) {
                        // Create customer on the fly
                        $code = 'CUST-'.str_pad((string) $nextCustNum, 4, '0', STR_PAD_LEFT);
                        $nextCustNum++;

                        $customer = Customer::create([
                            'customer_code' => $code,
                            'name' => $customerName,
                            'customer_type' => 'retail',
                            'is_active' => true,
                            'credit_limit' => 0,
                        ]);

                        $customerMap[$customerLower] = $customer->id;
                        $customersCreated++;
                    }

                    $customerId = $customerMap[$customerLower];

                    // Parse money (whole QAR -> cents)
                    $subtotalCents = (int) round((float) ($row['Total Trade Revenue'] ?? 0) * 100);
                    $discountCents = (int) round((float) ($row['Discount'] ?? 0) * 100);
                    $totalCents = (int) round((float) ($row['Net Amount'] ?? 0) * 100);
                    $cashCents = (int) round((float) ($row['Cash'] ?? 0) * 100);
                    $cardCents = (int) round((float) ($row['Card'] ?? 0) * 100);
                    $creditCents = (int) round((float) ($row['Credit'] ?? 0) * 100);

                    // Determine payment type
                    if ($cashCents > 0 && $cardCents === 0 && $creditCents === 0) {
                        $paymentType = 'cash';
                    } elseif ($cardCents > 0 && $cashCents === 0 && $creditCents === 0) {
                        $paymentType = 'card';
                    } elseif ($creditCents > 0 && $cashCents === 0 && $cardCents === 0) {
                        $paymentType = 'credit';
                    } elseif ($cashCents > 0) {
                        $paymentType = 'cash';
                    } elseif ($cardCents > 0) {
                        $paymentType = 'card';
                    } else {
                        $paymentType = 'credit';
                    }

                    // Determine status: paid if before cutoff OR if cash was collected
                    $isPaid = $issueDate->lt($cutoffDate) || $cashCents > 0;
                    $status = $isPaid ? 'paid' : 'issued';
                    $paidTotalCents = $isPaid ? $totalCents : 0;
                    $balanceCents = $isPaid ? 0 : $totalCents;

                    $posReference = trim($row['POS Reference'] ?? '');

                    // Insert invoice directly via DB (bypass model booted hook)
                    $invoiceId = DB::table('ar_invoices')->insertGetId([
                        'branch_id' => 1,
                        'customer_id' => $customerId,
                        'source' => 'import',
                        'type' => 'invoice',
                        'invoice_number' => $docNo,
                        'status' => $status,
                        'payment_type' => $paymentType,
                        'issue_date' => $issueDate->toDateString(),
                        'due_date' => $issueDate->toDateString(),
                        'currency' => 'QAR',
                        'subtotal_cents' => $subtotalCents,
                        'discount_total_cents' => $discountCents,
                        'invoice_discount_type' => 'fixed',
                        'invoice_discount_value' => $discountCents,
                        'invoice_discount_cents' => $discountCents,
                        'tax_total_cents' => 0,
                        'total_cents' => $totalCents,
                        'paid_total_cents' => $paidTotalCents,
                        'balance_cents' => $balanceCents,
                        'pos_reference' => $posReference !== '' ? $posReference : null,
                        'notes' => 'Imported from legacy system',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    // Insert single line item
                    DB::table('ar_invoice_items')->insert([
                        'invoice_id' => $invoiceId,
                        'description' => 'Legacy import',
                        'qty' => 1.000,
                        'unit_price_cents' => $totalCents,
                        'discount_cents' => 0,
                        'tax_cents' => 0,
                        'line_total_cents' => $totalCents,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $invoicesInserted++;
                }
            });

            $this->command->info("  Processed chunk... ({$invoicesInserted} inserted so far)");
        }

        $this->command->info("Invoices inserted: {$invoicesInserted}");
        $this->command->info("Rows skipped: {$skipped}");
        $this->command->info("Customers created on the fly: {$customersCreated}");
        $this->command->info('Legacy sales import complete.');
    }
}
