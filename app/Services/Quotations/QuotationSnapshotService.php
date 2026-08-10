<?php

namespace App\Services\Quotations;

use App\Models\DocumentAsset;
use App\Models\Quotation;
use App\Services\Quotations\Storage\QuotationAssetService;

class QuotationSnapshotService
{
    public function __construct(private readonly QuotationAssetService $assets) {}

    /** @return array<string, mixed> */
    public function make(Quotation $quotation): array
    {
        $quotation->loadMissing(['company', 'branch', 'customer', 'items', 'templateVersion.template']);
        $profile = app(CompanyDocumentProfileService::class)->getOrCreate($quotation->company);
        $assetIds = collect($this->assets->assetIdsFromBlocks((array) $quotation->blocks))
            ->push((int) $profile->logo_asset_id)
            ->filter()->unique()->values();
        $assets = DocumentAsset::query()
            ->where('company_id', $quotation->company_id)
            ->whereIn('id', $assetIds)
            ->get()->keyBy(fn (DocumentAsset $asset) => (string) $asset->id)
            ->map->only(['id', 'disk', 'storage_key', 'mime_type', 'size_bytes', 'checksum_sha256'])
            ->all();

        $items = $quotation->items->map(fn ($item) => [
            'menu_item_id' => $item->menu_item_id,
            'description' => $item->description,
            'unit' => $item->unit,
            'quantity' => $item->quantity,
            'unit_price_cents' => (int) $item->unit_price_cents,
            'unit_price_minor' => (int) $item->unit_price_cents,
            'discount_cents' => (int) $item->discount_cents,
            'line_discount_minor' => (int) $item->discount_cents,
            'line_total_cents' => (int) $item->line_total_cents,
            'line_total_minor' => (int) $item->line_total_cents,
            'sort_order' => (int) $item->sort_order,
            'catalog_snapshot' => $item->catalog_snapshot,
        ])->values()->all();

        return [
            'schema_version' => 1,
            'quotation' => [
                'id' => $quotation->id,
                'number' => $quotation->quotation_number,
                'quotation_number' => $quotation->quotation_number,
                'revision' => (int) $quotation->current_revision + 1,
                'issue_date' => $quotation->issue_date?->toDateString(),
                'valid_until' => $quotation->valid_until?->toDateString(),
                'currency' => 'QAR',
                'quotation_discount_type' => $quotation->quotation_discount_type,
                'quotation_discount_value' => (int) $quotation->quotation_discount_value,
            ],
            'company_profile' => array_merge($profile->toArray(), ['company_name' => $quotation->company?->name]),
            'branch' => $quotation->branch?->only(['id', 'name', 'code']),
            'recipient' => $quotation->only([
                'recipient_name', 'recipient_contact_name', 'recipient_email',
                'recipient_phone', 'recipient_address',
            ]),
            'customer' => $quotation->customer?->only([
                'id', 'customer_code', 'name', 'contact_name', 'phone', 'email',
                'billing_address', 'country',
            ]),
            'template' => [
                'template_id' => $quotation->templateVersion?->document_template_id,
                'template_version_id' => $quotation->template_version_id,
                'version' => $quotation->templateVersion?->version,
                'settings' => [
                    'page' => $quotation->page_settings,
                    'margins' => data_get($quotation->page_settings, 'margins_mm', []),
                    ...($quotation->styles ?? []),
                    'table_columns' => $quotation->table_columns,
                ],
                'blocks' => $quotation->blocks,
            ],
            'items' => $items,
            'totals' => [
                'gross_subtotal_cents' => (int) $quotation->gross_subtotal_cents,
                'gross_subtotal_minor' => (int) $quotation->gross_subtotal_cents,
                'subtotal_cents' => (int) $quotation->subtotal_cents,
                'subtotal_minor' => (int) $quotation->subtotal_cents,
                'line_discount_total_cents' => (int) $quotation->line_discount_total_cents,
                'line_discount_minor' => (int) $quotation->line_discount_total_cents,
                'quotation_discount_cents' => (int) $quotation->quotation_discount_cents,
                'quotation_discount_minor' => (int) $quotation->quotation_discount_cents,
                'discount_total_cents' => (int) $quotation->discount_total_cents,
                'discount_total_minor' => (int) $quotation->discount_total_cents,
                'total_cents' => (int) $quotation->total_cents,
                'total_minor' => (int) $quotation->total_cents,
                'discount_type' => $quotation->quotation_discount_type,
                'discount_value' => (int) $quotation->quotation_discount_value,
            ],
            'assets' => $assets,
            'captured_at' => now()->toIso8601String(),
        ];
    }
}
