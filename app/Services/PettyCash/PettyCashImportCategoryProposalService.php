<?php

namespace App\Services\PettyCash;

use App\Models\ExpenseCategory;
use App\Models\PettyCashImportBatch;
use App\Models\PettyCashImportCategoryProposal;
use Illuminate\Support\Str;

class PettyCashImportCategoryProposalService
{
    /** @return array<int, array{code:string|null,name:string}> */
    public function definitions(array $sheets, array $headers): array
    {
        $sheet = PettyCashImportService::CATEGORY_DEFINITION_SHEET;
        if (! array_key_exists($sheet, $sheets)
            || ($headers[$sheet] ?? []) !== PettyCashImportService::CATEGORY_DEFINITION_HEADERS) {
            return [];
        }

        return collect($sheets[$sheet])->map(fn (array $row): array => [
            'code' => filled($row['code'] ?? null) ? trim((string) $row['code']) : null,
            'name' => trim((string) ($row['name'] ?? '')),
        ])->filter(fn (array $row): bool => $row['name'] !== '')->values()->all();
    }

    public function persist(PettyCashImportBatch $batch, array $validated, array $definitions): void
    {
        $candidates = collect($definitions)->map(fn (array $definition): array => [
            ...$definition,
            'is_declared' => true,
        ]);
        foreach ($validated['invoices'] as $invoice) {
            if (filled($invoice['header']['category_name'] ?? null)) {
                $candidates->push([
                    'code' => null,
                    'name' => $invoice['header']['category_name'],
                    'is_declared' => false,
                ]);
            }
        }

        $existing = ExpenseCategory::query()->get()->groupBy(
            fn (ExpenseCategory $category): string => $this->normalize($category->name)
        );
        foreach ($candidates->unique(fn (array $item): string => $this->normalize($item['name'])) as $item) {
            $normalized = $this->normalize($item['name']);
            $matches = $existing->get($normalized, collect());
            $match = $matches->count() === 1 ? $matches->first() : null;
            PettyCashImportCategoryProposal::query()->create([
                'import_batch_id' => $batch->id,
                'source_code' => filled($item['code'] ?? null) ? Str::limit((string) $item['code'], 100, '') : null,
                'source_name' => Str::limit(trim($item['name']), 100, ''),
                'normalized_name' => Str::limit($normalized, 100, ''),
                'is_declared' => (bool) ($item['is_declared'] ?? false),
                'status' => $matches->count() > 1
                    ? 'ambiguous'
                    : (! $match ? 'proposed' : ($match->active ? 'matched' : 'inactive')),
                'expense_category_id' => $match?->id,
            ]);
        }
    }

    public function conflictCount(array $definitions): int
    {
        $existing = ExpenseCategory::query()->get()->groupBy(
            fn (ExpenseCategory $category): string => $this->normalize($category->name)
        );

        return collect($definitions)->filter(
            function (array $definition) use ($existing): bool {
                $matches = $existing->get($this->normalize($definition['name']), collect());

                return $matches->count() > 1 || ($matches->count() === 1 && ! $matches->first()->active);
            }
        )->count();
    }

    private function normalize(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?: trim($name));
    }
}
