<?php

namespace App\Services\PettyCash;

use App\Models\ExpenseCategory;
use App\Models\PettyCashImportBatch;
use App\Models\PettyCashImportCategoryProposal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    /** Resolve staged data only; global categories are never changed during review. */
    public function resolve(PettyCashImportCategoryProposal $proposal, array $data): PettyCashImportCategoryProposal
    {
        $data = Validator::make($data, [
            'category_id' => ['nullable', 'required_without:name', Rule::prohibitedIf(filled($data['name'] ?? null)), 'integer'],
            'name' => ['nullable', 'required_without:category_id', Rule::prohibitedIf(filled($data['category_id'] ?? null)), 'string', 'max:100'],
        ])->validate();
        $sourceId = $proposal->id;
        $sourceName = $proposal->normalized_name;
        $batch = $proposal->batch;
        $categories = ExpenseCategory::query()->lockForUpdate()->get();
        $category = null;
        $resolved = $proposal;

        if (filled($data['category_id'] ?? null)) {
            $category = $categories->firstWhere('id', (int) $data['category_id']);
            if (! $category || ! $category->active) {
                throw ValidationException::withMessages(['category_id' => __('Choose an active existing category.')]);
            }
            $resolved->forceFill(['status' => 'mapped', 'expense_category_id' => $category->id])->save();
            $name = $category->name;
        } else {
            $name = preg_replace('/\s+/u', ' ', trim($data['name'])) ?: '';
            $normalized = $this->normalize($name);
            if ($name === '' || app(PettyCashImportValueParser::class)->tokenId($name)) {
                throw ValidationException::withMessages(['name' => __('Enter a category name, not a category ID or lookup token.')]);
            }
            if ($categories->contains(fn (ExpenseCategory $item): bool => $this->normalize($item->name) === $normalized)) {
                throw ValidationException::withMessages(['name' => __('This category name already exists. Select an active existing category or enter a different name.')]);
            }
            $resolved = $batch->categoryProposals()->where('normalized_name', $normalized)->lockForUpdate()->first() ?? $proposal;
            if (! $resolved->is($proposal) && $resolved->status !== 'proposed') {
                throw ValidationException::withMessages(['name' => __('This name is already used by a reviewed category in this import. Choose a different new name.')]);
            }
            $resolved->forceFill([
                'source_name' => $name, 'normalized_name' => $normalized,
                'is_declared' => $resolved->is_declared || $proposal->is_declared,
                'source_code' => $resolved->source_code ?? $proposal->source_code,
                'status' => 'proposed', 'expense_category_id' => null,
            ])->save();
        }

        foreach ($batch->rows()->lockForUpdate()->get() as $row) {
            $payload = $row->payload;
            $owner = $payload['_category_proposal_id'] ?? null;
            $raw = (string) ($payload['category'] ?? '');
            $id = app(PettyCashImportValueParser::class)->tokenId($raw);
            $rowName = $payload['category_normalized'] ?? $this->normalize(
                $id ? ($categories->firstWhere('id', $id)?->name ?? $raw) : $raw
            );
            if ($owner !== null ? (int) $owner !== $sourceId : $rowName !== $sourceName) {
                continue;
            }
            $payload = array_merge($payload, [
                'category' => $category ? $category->id.' | '.$category->name : $name,
                'category_id' => $category?->id, 'category_name' => $name,
                'category_normalized' => $this->normalize($name), '_category_proposal_id' => $resolved->id,
            ]);
            $row->forceFill([
                'payload' => $payload,
                'row_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            ])->save();
        }
        if (! $resolved->is($proposal)) {
            $proposal->delete();
        }

        return $resolved;
    }

    public function sync(PettyCashImportBatch $batch, array $validated): void
    {
        $headers = collect($validated['invoices'])->pluck('header');
        $used = $headers->pluck('category_normalized')->filter()->unique();
        $reviewed = $batch->rows()->get()->pluck('payload._category_proposal_id')->filter()->unique();
        $batch->categoryProposals()->where('is_declared', false)
            ->whereNotIn('normalized_name', $used)->whereNotIn('id', $reviewed)->delete();
        $mappedIds = $batch->categoryProposals()->where('status', 'mapped')->pluck('expense_category_id');
        foreach ($headers as $header) {
            if (empty($header['category_normalized']) || empty($header['category_name'])) {
                continue;
            }
            if ($mappedIds->contains($header['category_id'] ?? null)) {
                continue;
            }
            $batch->categoryProposals()->firstOrCreate([
                'normalized_name' => $header['category_normalized'],
            ], ['source_name' => $header['category_name'], 'is_declared' => false, 'status' => 'proposed']);
        }

        $categories = ExpenseCategory::query()->get();
        $byName = $categories->groupBy(fn (ExpenseCategory $category): string => $this->normalize($category->name));
        foreach ($batch->categoryProposals()->lockForUpdate()->get() as $proposal) {
            if (in_array($proposal->status, ['mapped', 'mapped_inactive'], true)) {
                $category = $categories->firstWhere('id', $proposal->expense_category_id);
                $proposal->forceFill(['status' => $category?->active ? 'mapped' : 'mapped_inactive'])->save();

                continue;
            }
            $matches = $byName->get($proposal->normalized_name, collect());
            $category = $matches->count() === 1 ? $matches->first() : null;
            $selected = $this->explicitSelection($headers->where('category_normalized', $proposal->normalized_name), $matches);
            $proposal->forceFill([
                'status' => $matches->count() > 1
                    ? ($selected ? 'mapped' : 'ambiguous')
                    : (! $category ? 'proposed' : ($category->active ? 'matched' : 'inactive')),
                'expense_category_id' => $selected?->id ?? $category?->id,
            ])->save();
        }
    }

    private function explicitSelection(Collection $headers, Collection $matches): ?ExpenseCategory
    {
        $ids = $headers->pluck('category_id')->unique();
        if ($ids->count() !== 1 || ! $ids->first()) {
            return null;
        }

        return $matches->first(fn (ExpenseCategory $category): bool => $category->active && $category->id === $ids->first());
    }

    private function normalize(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?: trim($name));
    }
}
