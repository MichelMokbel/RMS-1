<?php

namespace App\Services\Quotations;

use App\Models\DocumentAsset;
use App\Models\Quotation;
use App\Models\QuotationStatusEvent;
use App\Models\QuotationVersion;
use App\Models\User;
use App\Services\Quotations\Storage\QuotationArtifactService;
use App\Services\Quotations\Storage\QuotationAssetService;
use App\Services\Sequences\DocumentSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuotationLifecycleService
{
    public function __construct(
        private readonly QuotationDraftService $drafts,
        private readonly QuotationTotalsCalculator $totals,
        private readonly QuotationSchemaValidator $schema,
        private readonly QuotationSnapshotService $snapshots,
        private readonly DocumentSequenceService $sequences,
        private readonly QuotationArtifactService $artifacts,
        private readonly QuotationAssetService $assets,
    ) {}

    public function finalize(User $actor, Quotation $quotation): QuotationVersion
    {
        $this->drafts->assertAccessible($actor, $quotation);
        DB::beginTransaction();
        try {
            $locked = Quotation::query()->with(['items', 'templateVersion.template'])->lockForUpdate()->findOrFail($quotation->id);
            if (! $locked->isDraft()) {
                $this->fail('quotation', __('Only a draft quotation can be finalized.'));
            }
            $this->validateFinalDraft($locked);
            $calculation = $this->totals->calculate(
                $locked->items->toArray(),
                $locked->quotation_discount_type,
                (int) $locked->quotation_discount_value
            );
            $locked->forceFill(collect($calculation)->except('items')->all());
            if (! $locked->quotation_number) {
                $year = $locked->issue_date->format('Y');
                $sequence = $this->sequences->next('quotation', (int) $locked->branch_id, $year);
                $locked->quotation_number = sprintf('QT-%s-%05d', $year, $sequence);
            }
            $revision = ((int) $locked->current_revision) + 1;
            $locked->save();

            $snapshot = $this->snapshots->make($locked->fresh(['items', 'company', 'branch', 'customer', 'templateVersion.template']));
            data_set($snapshot, 'quotation.revision', $revision);
            $version = QuotationVersion::query()->create([
                'quotation_id' => $locked->id,
                'revision' => $revision,
                'quotation_number' => $locked->quotation_number,
                'status' => Quotation::STATUS_SENT,
                'snapshot' => $snapshot,
                'subtotal_cents' => $locked->subtotal_cents,
                'discount_total_cents' => $locked->discount_total_cents,
                'total_cents' => $locked->total_cents,
                'created_by' => $actor->id,
                'finalized_at' => now(),
            ]);

            $this->artifacts->generateForVersion($version);
            $locked->update([
                'status' => Quotation::STATUS_SENT,
                'current_revision' => $revision,
                'updated_by' => $actor->id,
            ]);
            $this->event($locked, $version, 'sent', Quotation::STATUS_DRAFT, Quotation::STATUS_SENT, $actor);
            DB::commit();

            return $version->fresh('artifacts');
        } catch (\Throwable $exception) {
            if (isset($version) && $version instanceof QuotationVersion && $version->exists) {
                try {
                    $this->artifacts->deleteForVersion($version);
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            DB::rollBack();
            throw $exception;
        }
    }

    public function startRevision(User $actor, Quotation $quotation): Quotation
    {
        $this->drafts->assertAccessible($actor, $quotation);
        if (! in_array($quotation->status, [Quotation::STATUS_SENT, Quotation::STATUS_REJECTED, Quotation::STATUS_EXPIRED], true)) {
            $this->fail('quotation', __('Only sent, rejected, or expired quotations can be revised.'));
        }
        $version = $quotation->versions()->latest('revision')->first();
        if (! $version) {
            $this->fail('quotation', __('The quotation has no finalized revision to copy.'));
        }
        $snapshot = (array) $version->snapshot;
        $payload = [
            ...data_get($snapshot, 'quotation', []),
            ...data_get($snapshot, 'recipient', []),
            'customer_id' => data_get($snapshot, 'customer.id'),
            'template_version_id' => data_get($snapshot, 'template.template_version_id'),
            'page_settings' => data_get($snapshot, 'template.settings.page', $quotation->page_settings),
            'styles' => collect(data_get($snapshot, 'template.settings', []))->except(['page', 'margins', 'table_columns'])->all(),
            'table_columns' => data_get($snapshot, 'template.settings.table_columns', $quotation->table_columns),
            'blocks' => data_get($snapshot, 'template.blocks', $quotation->blocks),
            'items' => data_get($snapshot, 'items', []),
            'quotation_discount_type' => data_get($snapshot, 'quotation.quotation_discount_type'),
            'quotation_discount_value' => data_get($snapshot, 'quotation.quotation_discount_value', 0),
        ];
        $from = $quotation->status;

        return DB::transaction(function () use ($actor, $quotation, $version, $payload, $from) {
            Quotation::query()->whereKey($quotation->id)->update(['status' => Quotation::STATUS_DRAFT, 'updated_by' => $actor->id]);
            $draft = $this->drafts->update($actor, $quotation->fresh(), $payload);
            $this->event($draft, $version, 'revision_created', $from, Quotation::STATUS_DRAFT, $actor, null, ['next_revision' => $draft->current_revision + 1]);

            return $draft->fresh('items');
        });
    }

    public function markAccepted(User $actor, Quotation $quotation, ?string $note = null): Quotation
    {
        $this->drafts->assertAccessible($actor, $quotation);
        if ($quotation->status !== Quotation::STATUS_SENT) {
            $this->fail('quotation', __('Only a sent quotation can be accepted.'));
        }
        if ($quotation->valid_until->isBefore(today())) {
            $this->fail('quotation', __('An expired quotation cannot be accepted.'));
        }

        return $this->transition($actor, $quotation, Quotation::STATUS_ACCEPTED, 'accepted', $note);
    }

    public function markRejected(User $actor, Quotation $quotation, ?string $note = null): Quotation
    {
        $this->drafts->assertAccessible($actor, $quotation);
        if ($quotation->status !== Quotation::STATUS_SENT) {
            $this->fail('quotation', __('Only a sent quotation can be rejected.'));
        }

        return $this->transition($actor, $quotation, Quotation::STATUS_REJECTED, 'rejected', $note);
    }

    public function expireOverdue(): int
    {
        $count = 0;
        Quotation::query()->where('status', Quotation::STATUS_SENT)->whereDate('valid_until', '<', today())
            ->orderBy('id')->chunkById(200, function ($quotations) use (&$count): void {
                foreach ($quotations as $quotation) {
                    DB::transaction(function () use ($quotation, &$count): void {
                        $changed = Quotation::query()->whereKey($quotation->id)
                            ->where('status', Quotation::STATUS_SENT)
                            ->update(['status' => Quotation::STATUS_EXPIRED, 'updated_at' => now()]);
                        if ($changed) {
                            $fresh = $quotation->fresh();
                            $this->event($fresh, $fresh->versions()->latest('revision')->first(), 'expired', Quotation::STATUS_SENT, Quotation::STATUS_EXPIRED);
                            $count++;
                        }
                    });
                }
            });

        return $count;
    }

    private function validateFinalDraft(Quotation $quotation): void
    {
        $styles = $this->schema->validateStyles((array) $quotation->styles);
        $this->schema->validateBlocks(
            (array) $quotation->blocks,
            (string) ($styles['menu_content_mode'] ?? 'structured')
        );
        $this->schema->validatePageSettings((array) $quotation->page_settings);
        $this->schema->validateStyles((array) $quotation->styles);
        $this->schema->validateTableColumns((array) $quotation->table_columns);
        if (blank($quotation->recipient_name)) {
            $this->fail('recipient_name', __('A recipient name is required before finalization.'));
        }
        if ($quotation->items->isEmpty() || $quotation->items->contains(fn ($item) => blank($item->description))) {
            $this->fail('items', __('At least one described quotation item is required.'));
        }
        $assetIds = collect($this->assets->assetIdsFromBlocks((array) $quotation->blocks));
        if ($assetIds->isNotEmpty() && DocumentAsset::query()->where('company_id', $quotation->company_id)->whereIn('id', $assetIds)->count() !== $assetIds->count()) {
            $this->fail('blocks', __('All document images must belong to the quotation company.'));
        }
    }

    private function transition(User $actor, Quotation $quotation, string $to, string $event, ?string $note): Quotation
    {
        return DB::transaction(function () use ($actor, $quotation, $to, $event, $note) {
            $locked = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            if ($locked->status !== Quotation::STATUS_SENT) {
                $this->fail('quotation', __('The quotation status changed before this action completed.'));
            }
            $from = $locked->status;
            $locked->update(['status' => $to, 'updated_by' => $actor->id]);
            $this->event($locked, $locked->versions()->latest('revision')->first(), $event, $from, $to, $actor, $note);

            return $locked->fresh();
        });
    }

    private function event(Quotation $quotation, ?QuotationVersion $version, string $event, ?string $from, ?string $to, ?User $actor = null, ?string $note = null, ?array $metadata = null): void
    {
        QuotationStatusEvent::query()->create([
            'quotation_id' => $quotation->id,
            'quotation_version_id' => $version?->id,
            'event' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'metadata' => $metadata,
            'actor_id' => $actor?->id,
        ]);
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
