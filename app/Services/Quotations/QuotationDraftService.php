<?php

namespace App\Services\Quotations;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\DocumentTemplateVersion;
use App\Models\MenuItem;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Security\BranchAccessService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class QuotationDraftService
{
    public function __construct(
        private readonly BranchAccessService $branchAccess,
        private readonly QuotationSchemaValidator $schema,
        private readonly QuotationTotalsCalculator $totals,
        private readonly QuotationTemplateService $templates,
    ) {}

    public function create(User $actor, array $payload): Quotation
    {
        $branch = $this->accessibleBranch($actor, (int) ($payload['branch_id'] ?? 0));
        $companyId = (int) $branch->company_id;
        if ($companyId <= 0) {
            throw ValidationException::withMessages(['branch_id' => __('The selected branch is not assigned to an accounting company.')]);
        }

        $layout = $this->layout($companyId, $payload, $actor);
        $customer = $this->customer($payload['customer_id'] ?? null);
        $payload = $this->withRecipientSnapshot($payload, $customer);
        $calculation = $this->totals->calculate(
            (array) ($payload['items'] ?? []),
            $payload['quotation_discount_type'] ?? null,
            (int) ($payload['quotation_discount_value'] ?? 0)
        );

        return DB::transaction(function () use ($actor, $payload, $branch, $companyId, $layout, $calculation) {
            $quotation = Quotation::query()->create(array_merge(
                $this->quotationAttributes($payload, $layout, $calculation),
                [
                    'company_id' => $companyId,
                    'branch_id' => $branch->id,
                    'status' => Quotation::STATUS_DRAFT,
                    'current_revision' => 0,
                    'currency' => 'QAR',
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]
            ));
            $this->replaceItems($quotation, $calculation['items'], (int) $branch->id);

            return $quotation->fresh(['items', 'templateVersion']);
        });
    }

    public function update(User $actor, Quotation $quotation, array $payload): Quotation
    {
        $this->assertAccessible($actor, $quotation);
        if (! $quotation->isDraft()) {
            throw ValidationException::withMessages(['quotation' => __('Only draft quotations can be edited.')]);
        }

        $branch = $this->accessibleBranch($actor, (int) ($payload['branch_id'] ?? $quotation->branch_id));
        if ((int) $branch->company_id !== (int) $quotation->company_id) {
            throw ValidationException::withMessages(['branch_id' => __('A quotation cannot be moved to a branch owned by another company.')]);
        }
        $layout = $this->layout((int) $quotation->company_id, $payload, $actor, $quotation);
        $customer = $this->customer($payload['customer_id'] ?? $quotation->customer_id);
        $payload = $this->withRecipientSnapshot($payload, $customer, $quotation);
        $items = array_key_exists('items', $payload) ? (array) $payload['items'] : $quotation->items->toArray();
        $calculation = $this->totals->calculate(
            $items,
            $payload['quotation_discount_type'] ?? $quotation->quotation_discount_type,
            (int) ($payload['quotation_discount_value'] ?? $quotation->quotation_discount_value)
        );

        return DB::transaction(function () use ($actor, $quotation, $payload, $branch, $layout, $calculation) {
            $locked = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            if (! $locked->isDraft()) {
                throw ValidationException::withMessages(['quotation' => __('The quotation is no longer editable.')]);
            }
            $locked->update(array_merge(
                $this->quotationAttributes($payload, $layout, $calculation, $locked),
                ['branch_id' => $branch->id, 'updated_by' => $actor->id]
            ));
            $this->replaceItems($locked, $calculation['items'], (int) $branch->id);

            return $locked->fresh(['items', 'templateVersion']);
        });
    }

    public function save(User $actor, ?Quotation $quotation, array $payload): Quotation
    {
        return $quotation ? $this->update($actor, $quotation, $payload) : $this->create($actor, $payload);
    }

    public function duplicate(User $actor, Quotation $quotation): Quotation
    {
        $this->assertAccessible($actor, $quotation);
        $quotation->loadMissing('items');
        $payload = $quotation->only([
            'branch_id', 'customer_id', 'template_version_id', 'recipient_name', 'recipient_contact_name',
            'recipient_email', 'recipient_phone', 'recipient_address', 'page_settings', 'styles',
            'table_columns', 'blocks', 'quotation_discount_type', 'quotation_discount_value',
        ]);
        $payload['issue_date'] = now()->toDateString();
        $payload['valid_until'] = now()->addDays((int) config('quotations.default_validity_days', 30))->toDateString();
        $payload['items'] = $quotation->items->map->only([
            'menu_item_id', 'description', 'unit', 'quantity', 'unit_price_cents',
            'discount_cents', 'sort_order', 'catalog_snapshot',
        ])->all();
        $copy = $this->create($actor, $payload);
        $copy->update(['duplicated_from_quotation_id' => $quotation->id]);

        return $copy->fresh('items');
    }

    public function reapplyTemplate(User $actor, Quotation $quotation, DocumentTemplateVersion $version): Quotation
    {
        $this->assertAccessible($actor, $quotation);
        if (! $quotation->isDraft()) {
            throw ValidationException::withMessages(['quotation' => __('Only draft quotations can use another template.')]);
        }
        $version->loadMissing('template');
        if (! $version->template || (int) $version->template->company_id !== (int) $quotation->company_id || $version->template->type !== 'quotation') {
            throw ValidationException::withMessages(['template_version_id' => __('The template must belong to the quotation company.')]);
        }
        $quotation->update([
            'template_version_id' => $version->id,
            'page_settings' => $version->page_settings,
            'styles' => $version->styles,
            'table_columns' => $version->table_columns,
            'blocks' => $version->blocks,
            'updated_by' => $actor->id,
        ]);

        return $quotation->fresh(['items', 'templateVersion']);
    }

    public function assertAccessible(User $actor, Quotation $quotation): void
    {
        if (! $this->branchAccess->canAccessBranch($actor, (int) $quotation->branch_id)) {
            throw ValidationException::withMessages(['branch_id' => __('You do not have access to this quotation branch.')]);
        }
        $branch = Branch::query()->find($quotation->branch_id);
        if (! $branch || (int) $branch->company_id !== (int) $quotation->company_id) {
            throw ValidationException::withMessages(['quotation' => __('Quotation company and branch ownership do not match.')]);
        }
    }

    private function accessibleBranch(User $actor, int $branchId): Branch
    {
        $branch = Branch::query()->find($branchId);
        if (! $branch || ! $branch->is_active || ! $this->branchAccess->canAccessBranch($actor, $branchId)) {
            throw ValidationException::withMessages(['branch_id' => __('The selected branch is not accessible.')]);
        }

        return $branch;
    }

    private function layout(int $companyId, array $payload, User $actor, ?Quotation $existing = null): array
    {
        $versionId = $payload['template_version_id'] ?? $existing?->template_version_id;
        $version = $versionId ? DocumentTemplateVersion::query()->with('template')->find($versionId) : null;
        if ($versionId && ! $version) {
            throw ValidationException::withMessages(['template_version_id' => __('The selected template version does not exist.')]);
        }
        if (! $version && ! $existing) {
            $version = $this->templates->ensureDefault($companyId, $actor)->currentVersion;
        }
        if ($version && (! $version->template || (int) $version->template->company_id !== $companyId || $version->template->type !== 'quotation')) {
            throw ValidationException::withMessages(['template_version_id' => __('The template must belong to the quotation company.')]);
        }

        $fallback = $existing ? $existing->only(['page_settings', 'styles', 'table_columns', 'blocks']) : ($version?->only(['page_settings', 'styles', 'table_columns', 'blocks']) ?? $this->templates->defaultLayout());

        $styles = $this->schema->validateStyles((array) ($payload['styles'] ?? $fallback['styles']));

        return [
            'template_version_id' => $version?->id ?? $existing?->template_version_id,
            'page_settings' => $this->schema->validatePageSettings((array) ($payload['page_settings'] ?? $fallback['page_settings'])),
            'styles' => $styles,
            'table_columns' => $this->schema->validateTableColumns((array) ($payload['table_columns'] ?? $fallback['table_columns'])),
            'blocks' => $this->schema->validateBlocks(
                (array) ($payload['blocks'] ?? $fallback['blocks']),
                (string) ($styles['menu_content_mode'] ?? 'structured')
            ),
        ];
    }

    private function quotationAttributes(array $payload, array $layout, array $calculation, ?Quotation $existing = null): array
    {
        try {
            $issueDate = Carbon::parse($payload['issue_date'] ?? $existing?->issue_date ?? now())->toDateString();
            $validUntil = Carbon::parse($payload['valid_until'] ?? $existing?->valid_until ?? now()->addDays((int) config('quotations.default_validity_days', 30)))->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['issue_date' => __('Issue and validity dates must be valid dates.')]);
        }
        if ($validUntil < $issueDate) {
            throw ValidationException::withMessages(['valid_until' => __('Valid-until date cannot be before the issue date.')]);
        }

        return array_merge($layout, collect($payload)->only([
            'customer_id', 'recipient_name', 'recipient_contact_name', 'recipient_email',
            'recipient_phone', 'recipient_address', 'quotation_discount_type', 'quotation_discount_value',
        ])->all(), collect($calculation)->except('items')->all(), [
            'issue_date' => $issueDate,
            'valid_until' => $validUntil,
            'recipient_name' => (string) ($payload['recipient_name'] ?? $existing?->recipient_name ?? ''),
            'quotation_discount_value' => (int) ($payload['quotation_discount_value'] ?? $existing?->quotation_discount_value ?? 0),
        ]);
    }

    private function customer(mixed $customerId): ?Customer
    {
        return $customerId ? Customer::query()->findOrFail((int) $customerId) : null;
    }

    private function withRecipientSnapshot(array $payload, ?Customer $customer, ?Quotation $existing = null): array
    {
        $payload['customer_id'] = $customer?->id;
        $payload['recipient_name'] = $payload['recipient_name'] ?? $existing?->recipient_name ?? $customer?->name ?? '';
        $payload['recipient_contact_name'] = $payload['recipient_contact_name'] ?? $existing?->recipient_contact_name ?? $customer?->contact_name;
        $payload['recipient_email'] = $payload['recipient_email'] ?? $existing?->recipient_email ?? $customer?->email;
        $payload['recipient_phone'] = $payload['recipient_phone'] ?? $existing?->recipient_phone ?? $customer?->phone;
        $payload['recipient_address'] = $payload['recipient_address'] ?? $existing?->recipient_address ?? $customer?->billing_address;

        return $payload;
    }

    private function replaceItems(Quotation $quotation, array $items, int $branchId): void
    {
        $quotation->items()->delete();
        foreach ($items as $index => $item) {
            $menuItem = ! empty($item['menu_item_id']) ? MenuItem::query()->find((int) $item['menu_item_id']) : null;
            if (! empty($item['menu_item_id']) && ! $menuItem) {
                throw ValidationException::withMessages(["items.$index.menu_item_id" => __('The selected catalog item no longer exists.')]);
            }
            if ($menuItem && Schema::hasTable('menu_item_branches') && ! DB::table('menu_item_branches')->where('menu_item_id', $menuItem->id)->where('branch_id', $branchId)->exists()) {
                throw ValidationException::withMessages(["items.$index.menu_item_id" => __('The catalog item is not available in the selected branch.')]);
            }
            $snapshot = $menuItem ? [
                'id' => $menuItem->id, 'code' => $menuItem->code, 'name' => $menuItem->name,
                'arabic_name' => $menuItem->arabic_name, 'unit' => $menuItem->unit,
                'selling_price_per_unit' => $menuItem->selling_price_per_unit,
            ] : ($item['catalog_snapshot'] ?? null);
            $description = trim((string) ($item['description'] ?? $menuItem?->name ?? ''));
            if (mb_strlen($description) > 255) {
                throw ValidationException::withMessages(["items.$index.description" => __('Item descriptions cannot exceed 255 characters.')]);
            }
            $quotation->items()->create([
                'menu_item_id' => $menuItem?->id,
                'description' => $description,
                'unit' => $item['unit'] ?? $menuItem?->unit,
                'quantity' => $item['quantity'],
                'unit_price_cents' => $item['unit_price_cents'],
                'discount_cents' => $item['discount_cents'],
                'line_total_cents' => $item['line_total_cents'],
                'sort_order' => (int) ($item['sort_order'] ?? $index),
                'catalog_snapshot' => $snapshot,
            ]);
        }
    }
}
