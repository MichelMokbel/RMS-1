<?php

namespace App\Services\Customers;

use App\Models\Customer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use SplFileObject;

class CustomerImportService
{
    private array $headers = [
        'customer_code',
        'name',
        'customer_type',
        'contact_name',
        'phone',
        'email',
        'billing_address',
        'delivery_address',
        'country',
        'default_payment_method_id',
        'credit_limit',
        'credit_terms_days',
        'credit_status',
        'is_active',
        'notes',
    ];

    public function preview(string $path, int $limit = 20): array
    {
        $rows = [];
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

        $headerRow = $this->normalizeRow($file->fgetcsv());
        $headerMap = $this->mapHeaders($headerRow);

        $count = 0;
        while (! $file->eof() && $count < $limit) {
            $row = $this->normalizeRow($file->fgetcsv());
            if (empty(array_filter($row))) {
                continue;
            }
            $rows[] = $this->mapRow($row, $headerMap);
            $count++;
        }

        return [
            'headers' => $headerMap,
            'rows' => $rows,
        ];
    }

    public function import(string $path, string $mode = 'create'): array
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

        $headerRow = $this->normalizeRow($file->fgetcsv());
        $headerMap = $this->mapHeaders($headerRow);
        $matcher = new CustomerUpsertMatcher();

        $result = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        DB::beginTransaction();
        try {
            while (! $file->eof()) {
                $rowRaw = $this->normalizeRow($file->fgetcsv());
                if (empty(array_filter($rowRaw))) {
                    continue;
                }

                $row = $this->mapRow($rowRaw, $headerMap);
                // Defensive defaults: CSVs may omit optional columns like credit_limit / credit_terms_days.
                $row['is_active'] = array_key_exists('is_active', $row) ? (bool) $row['is_active'] : true;

                $creditLimitRaw = $row['credit_limit'] ?? '';
                $row['credit_limit'] = ($creditLimitRaw === '' || $creditLimitRaw === null) ? 0 : (float) $creditLimitRaw;

                $termsRaw = $row['credit_terms_days'] ?? '';
                $row['credit_terms_days'] = ($termsRaw === '' || $termsRaw === null) ? 0 : (int) $termsRaw;

                $validator = Validator::make($row, [
                    'customer_code' => ['nullable', 'string', 'max:50'],
                    'name' => ['required', 'string', 'max:255'],
                    'customer_type' => ['required', 'in:retail,corporate,subscription'],
                    'contact_name' => ['nullable', 'string', 'max:255'],
                    'phone' => ['nullable', 'string', 'max:50'],
                    'email' => ['nullable', 'email', 'max:255'],
                    'billing_address' => ['nullable', 'string'],
                    'delivery_address' => ['nullable', 'string'],
                    'country' => ['nullable', 'string', 'max:100'],
                    'default_payment_method_id' => ['nullable', 'integer', 'min:1'],
                    'credit_limit' => ['required', 'numeric', 'min:0'],
                    'credit_terms_days' => ['required', 'integer', 'min:0'],
                    'credit_status' => ['nullable', 'string', 'max:100'],
                    'is_active' => ['required', 'boolean'],
                    'notes' => ['nullable', 'string'],
                ]);

                if ($validator->fails()) {
                    $result['failed']++;
                    $result['errors'][] = [
                        'row' => $row,
                        'messages' => $validator->errors()->all(),
                    ];
                    continue;
                }

                if ($row['customer_type'] === Customer::TYPE_RETAIL) {
                    $row['credit_limit'] = 0;
                    $row['credit_terms_days'] = 0;
                }

                $match = $mode === 'upsert' ? $matcher->findMatch($row) : null;

                if ($match) {
                    $match->update($row);
                    $result['updated']++;
                } else {
                    if ($mode === 'create' && $matcher->findMatch($row)) {
                        $result['skipped']++;
                        continue;
                    }
                    Customer::create($row);
                    $result['created']++;
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $result;
    }

    private function normalizeRow(array|false $row): array
    {
        if ($row === false) {
            return [];
        }

        return array_map(fn ($value) => is_string($value) ? trim($value) : $value, $row);
    }

    private function mapHeaders(array $headerRow): array
    {
        $headerRow = array_map(fn ($h) => strtolower(str_replace(' ', '_', $h ?? '')), $headerRow);

        $map = [];
        foreach ($headerRow as $index => $header) {
            if (in_array($header, $this->headers, true)) {
                $map[$index] = $header;
            }
        }

        return $map;
    }

    private function mapRow(array $row, array $headerMap): array
    {
        $mapped = [];
        foreach ($headerMap as $index => $field) {
            $mapped[$field] = Arr::get($row, $index);
        }

        return $mapped;
    }
}
