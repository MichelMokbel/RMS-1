<?php

namespace App\Services\Orders;

use App\Models\AccountingCompany;
use App\Models\Order;
use App\Models\OrderLabelPrint;
use App\Models\OrderLabelPrinterProfile;
use App\Models\PastryOrder;
use App\Models\PosPrintJob;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\POS\PosPrintJobService;
use App\Services\Security\BranchAccessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderLabelService
{
    public function __construct(
        private readonly BranchAccessService $branchAccess,
        private readonly PosPrintJobService $printJobs,
        private readonly OrderLabelPdfRenderer $renderer,
        private readonly AccountingAuditLogService $audit,
    ) {}

    public function request(
        User $actor,
        string $sourceType,
        int $sourceId,
        int $profileId,
        int $copies,
        string $requestUuid,
        ?int $reprintOfId = null,
        ?string $reprintReason = null,
    ): OrderLabelPrint {
        $this->assertCanPrint($actor);
        $this->validateRequest($sourceType, $sourceId, $profileId, $copies, $requestUuid, $reprintOfId, $reprintReason);

        $existing = OrderLabelPrint::query()->where('uuid', $requestUuid)->first();
        if ($existing) {
            abort_unless($this->branchAccess->canAccessBranch($actor, (int) $existing->branch_id), 403);
            $this->assertIdempotencyMatch($existing, $sourceType, $sourceId, $profileId, $copies);

            return $existing;
        }

        $source = $this->source($sourceType, $sourceId);
        $branchId = (int) $source->branch_id;
        abort_unless($this->branchAccess->canAccessBranch($actor, $branchId), 403);

        $profile = OrderLabelPrinterProfile::query()->with('terminal')->findOrFail($profileId);
        $this->assertUsableProfile($profile, $branchId);
        $branchCompanyId = (int) (DB::table('branches')->where('id', $branchId)->value('company_id') ?: 0);
        $expectedCompanyId = $branchCompanyId > 0
            ? $branchCompanyId
            : (int) AccountingCompany::query()->where('is_default', true)->value('id');
        abort_unless($expectedCompanyId > 0 && (int) $profile->company_id === $expectedCompanyId, 422, __('The printer profile belongs to another company.'));
        $snapshot = $this->snapshot($sourceType, $source, $profile);
        if ($this->renderer->overflows($snapshot, $profile)) {
            throw ValidationException::withMessages([
                'label' => __('This order does not fit the selected fixed label. Choose a larger or continuous-media printer profile.'),
            ]);
        }
        $snapshotHash = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $sourceType, $sourceId, $profile, $copies, $requestUuid, $reprintOfId, $reprintReason, $snapshot, $snapshotHash, $branchId): OrderLabelPrint {
            $profile = OrderLabelPrinterProfile::query()->with('terminal')->lockForUpdate()->findOrFail($profile->id);
            $this->assertUsableProfile($profile, $branchId);

            $existing = OrderLabelPrint::query()->where('uuid', $requestUuid)->lockForUpdate()->first();
            if ($existing) {
                $this->assertIdempotencyMatch($existing, $sourceType, $sourceId, (int) $profile->id, $copies);

                return $existing;
            }

            $sequence = 1;
            $reprintOf = null;
            if ($reprintOfId !== null) {
                $reprintOf = OrderLabelPrint::query()->lockForUpdate()->findOrFail($reprintOfId);
                abort_unless(
                    (int) $reprintOf->printer_profile_id === (int) $profile->id
                    && $reprintOf->source_type === $sourceType
                    && (int) $reprintOf->source_id === $sourceId,
                    422
                );
                $sequence = (int) OrderLabelPrint::query()
                    ->where('printer_profile_id', $profile->id)
                    ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->lockForUpdate()
                    ->max('sequence') + 1;
            }

            $label = OrderLabelPrint::query()->create([
                'uuid' => $requestUuid,
                'company_id' => (int) $profile->company_id,
                'branch_id' => $branchId,
                'printer_profile_id' => (int) $profile->id,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'service_date' => $snapshot['service_date'],
                'snapshot' => $snapshot,
                'snapshot_hash' => $snapshotHash,
                'copy_count' => $copies,
                'sequence' => $sequence,
                'reprint_of_id' => $reprintOf?->id,
                'reprint_reason' => $reprintOf ? trim((string) $reprintReason) : null,
                'requested_by' => (int) $actor->id,
                'status' => OrderLabelPrint::STATUS_PREPARING,
            ]);

            $pdf = $this->renderer->render($snapshot, $profile, $copies, $sequence);
            $job = $this->printJobs->enqueueServerDocument($profile->terminal, [
                'server_job_uuid' => $requestUuid,
                'order_label_print_id' => (int) $label->id,
                'branch_id' => $branchId,
                'target' => 'order_label_printer',
                'doc_type' => 'order_label_pdf',
                'payload_base64' => base64_encode($pdf),
                'metadata' => [
                    'printer_profile_id' => (int) $profile->id,
                    'printer_queue' => (string) $profile->os_queue_name,
                    'media_mode' => (string) $profile->media_mode,
                    'width_tenths_mm' => (int) $profile->width_tenths_mm,
                    'height_tenths_mm' => $this->renderer->heightTenthsMm($snapshot, $profile),
                    'resolution_dpi' => (int) $profile->resolution_dpi,
                    'snapshot_hash' => $snapshotHash,
                ],
            ], (int) $actor->id)['job'];

            $label->forceFill([
                'pos_print_job_id' => (int) $job->id,
                'status' => OrderLabelPrint::STATUS_QUEUED,
                'queued_at' => now(),
            ])->save();

            $this->audit->log(
                $reprintOf ? 'order_label.reprinted' : 'order_label.queued',
                (int) $actor->id,
                $label,
                [
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'printer_profile_id' => (int) $profile->id,
                    'sequence' => $sequence,
                    'copy_count' => $copies,
                    'reprint_of_id' => $reprintOf?->id,
                    'reprint_reason' => $reprintOf ? trim((string) $reprintReason) : null,
                    'pos_print_job_id' => (int) $job->id,
                ],
                (int) $profile->company_id,
            );

            return $label->fresh(['profile', 'printJob']);
        }, 3);
    }

    public function cancel(User $actor, OrderLabelPrint $label): OrderLabelPrint
    {
        $this->assertCanPrint($actor);
        abort_unless($this->branchAccess->canAccessBranch($actor, (int) $label->branch_id), 403);

        return DB::transaction(function () use ($actor, $label): OrderLabelPrint {
            $locked = OrderLabelPrint::query()->lockForUpdate()->findOrFail($label->id);
            $job = PosPrintJob::query()->whereKey($locked->pos_print_job_id)->lockForUpdate()->firstOrFail();
            if ((string) $job->status !== PosPrintJob::STATUS_QUEUED) {
                throw ValidationException::withMessages(['label' => __('Only a queued, unclaimed label can be cancelled safely.')]);
            }

            $job->update([
                'status' => PosPrintJob::STATUS_CANCELLED,
                'next_retry_at' => null,
                'claim_token' => null,
                'claim_expires_at' => null,
            ]);
            $locked->update(['status' => OrderLabelPrint::STATUS_CANCELLED, 'cancelled_at' => now()]);
            $this->audit->log('order_label.cancelled', (int) $actor->id, $locked, [
                'pos_print_job_id' => (int) $job->id,
            ], (int) $locked->company_id);

            return $locked->fresh(['profile', 'printJob']);
        }, 3);
    }

    public function reassign(User $actor, OrderLabelPrint $label, OrderLabelPrinterProfile $targetProfile): OrderLabelPrint
    {
        $this->assertCanPrint($actor);
        abort_unless($this->branchAccess->canAccessBranch($actor, (int) $label->branch_id), 403);
        $targetProfile->load('terminal');
        $this->assertUsableProfile($targetProfile, (int) $label->branch_id);
        abort_unless((int) $targetProfile->company_id === (int) $label->company_id, 422);

        return DB::transaction(function () use ($actor, $label, $targetProfile): OrderLabelPrint {
            $locked = OrderLabelPrint::query()->lockForUpdate()->findOrFail($label->id);
            $job = PosPrintJob::query()->whereKey($locked->pos_print_job_id)->lockForUpdate()->firstOrFail();
            if ((string) $job->status !== PosPrintJob::STATUS_QUEUED) {
                throw ValidationException::withMessages(['label' => __('Only a queued, unclaimed label can be reassigned safely.')]);
            }
            if ((int) $locked->printer_profile_id === (int) $targetProfile->id) {
                return $locked->fresh(['profile', 'printJob']);
            }

            $sequence = (int) OrderLabelPrint::query()
                ->where('printer_profile_id', $targetProfile->id)
                ->where('source_type', $locked->source_type)
                ->where('source_id', $locked->source_id)
                ->lockForUpdate()
                ->max('sequence') + 1;
            $pdf = $this->renderer->render($locked->snapshot, $targetProfile, (int) $locked->copy_count, $sequence);
            $metadata = (array) ($job->metadata ?? []);
            $metadata = [
                ...$metadata,
                'printer_profile_id' => (int) $targetProfile->id,
                'printer_queue' => (string) $targetProfile->os_queue_name,
                'media_mode' => (string) $targetProfile->media_mode,
                'width_tenths_mm' => (int) $targetProfile->width_tenths_mm,
                'height_tenths_mm' => $this->renderer->heightTenthsMm($locked->snapshot, $targetProfile),
                'resolution_dpi' => (int) $targetProfile->resolution_dpi,
            ];
            $payloadBase64 = base64_encode($pdf);
            $job->update([
                'branch_id' => (int) $targetProfile->branch_id,
                'target_terminal_id' => (int) $targetProfile->terminal_id,
                'payload_base64' => $payloadBase64,
                'payload' => [
                    'target' => (string) $job->target,
                    'doc_type' => (string) $job->doc_type,
                    'payload_base64' => $payloadBase64,
                ],
                'metadata' => $metadata,
                'next_retry_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ]);
            $fromProfileId = (int) $locked->printer_profile_id;
            $locked->update([
                'printer_profile_id' => (int) $targetProfile->id,
                'sequence' => $sequence,
            ]);
            $this->audit->log('order_label.reassigned', (int) $actor->id, $locked, [
                'from_printer_profile_id' => $fromProfileId,
                'to_printer_profile_id' => (int) $targetProfile->id,
                'pos_print_job_id' => (int) $job->id,
            ], (int) $locked->company_id);

            DB::afterCommit(function () use ($targetProfile): void {
                if ($this->printJobs->isStreamActive($targetProfile->terminal)) {
                    $this->printJobs->dispatchPendingJobsForTerminal($targetProfile->terminal, source: 'label.reassign');
                }
            });

            return $locked->fresh(['profile', 'printJob']);
        }, 3);
    }

    private function source(string $sourceType, int $sourceId): Model
    {
        return match ($sourceType) {
            'order' => Order::query()
                ->with(['items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
                ->findOrFail($sourceId),
            'pastry_order' => PastryOrder::query()
                ->with(['items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
                ->findOrFail($sourceId),
            default => throw ValidationException::withMessages(['source_type' => __('Unsupported order label source.')]),
        };
    }

    private function snapshot(string $sourceType, Model $source, OrderLabelPrinterProfile $profile): array
    {
        abort_if((string) $source->status === 'Cancelled', 422, __('Cancelled orders cannot be labelled.'));
        $companyName = (string) (AccountingCompany::query()->whereKey($profile->company_id)->value('name') ?: 'Layla Kitchen');

        return [
            'source_type' => $sourceType,
            'source_id' => (int) $source->id,
            'brand' => $companyName,
            'order_number' => (string) $source->order_number,
            'service_date' => $source->scheduled_date?->toDateString(),
            'service_time' => $this->formatTime($source->scheduled_time),
            'customer_name' => (string) ($source->customer_name_snapshot ?: __('Customer')),
            'destination' => (string) ($source->delivery_address_snapshot ?: __('Pickup')),
            'items' => $source->items->map(fn ($item): array => [
                'quantity' => $this->formatQuantity($item->quantity),
                'description' => Str::limit(trim((string) $item->description_snapshot), 90, '…'),
            ])->values()->all(),
        ];
    }

    private function assertUsableProfile(OrderLabelPrinterProfile $profile, int $branchId): void
    {
        abort_unless((int) $profile->branch_id === $branchId, 422, __('The printer belongs to another branch.'));
        abort_unless($profile->is_active && $profile->is_verified, 422, __('The printer profile is not active and verified.'));
        abort_unless($profile->terminal && $profile->terminal->active, 422, __('The assigned print terminal is inactive.'));
        abort_unless((int) $profile->terminal->branch_id === $branchId, 422, __('The assigned print terminal belongs to another branch.'));
        abort_unless((int) $profile->width_tenths_mm > 0 && (int) $profile->resolution_dpi > 0, 422);

        if ((string) $profile->media_mode === 'fixed') {
            abort_unless((int) $profile->height_tenths_mm > 0, 422, __('Fixed media requires a height.'));
        } else {
            abort_unless(
                (int) $profile->min_height_tenths_mm > 0
                && (int) $profile->max_height_tenths_mm >= (int) $profile->min_height_tenths_mm,
                422,
                __('Continuous media height limits are invalid.')
            );
        }
    }

    private function validateRequest(string $sourceType, int $sourceId, int $profileId, int $copies, string $uuid, ?int $reprintOfId, ?string $reason): void
    {
        if (! in_array($sourceType, ['order', 'pastry_order'], true) || $sourceId <= 0 || $profileId <= 0) {
            throw ValidationException::withMessages(['source' => __('The order label source is invalid.')]);
        }
        if ($copies < 1 || $copies > 10) {
            throw ValidationException::withMessages(['copies' => __('Choose between 1 and 10 copies.')]);
        }
        if (! Str::isUuid($uuid)) {
            throw ValidationException::withMessages(['request_uuid' => __('The print request identifier is invalid.')]);
        }
        if ($reprintOfId !== null && ($reprintOfId <= 0 || mb_strlen(trim((string) $reason)) < 3)) {
            throw ValidationException::withMessages(['reprint_reason' => __('Enter a reason for this reprint.')]);
        }
    }

    private function assertCanPrint(User $actor): void
    {
        abort_unless($actor->hasAnyRole(['admin', 'manager']) || $actor->can('order-labels.print'), 403);
    }

    private function assertIdempotencyMatch(OrderLabelPrint $label, string $sourceType, int $sourceId, int $profileId, int $copies): void
    {
        if (
            $label->source_type !== $sourceType
            || (int) $label->source_id !== $sourceId
            || (int) $label->printer_profile_id !== $profileId
            || (int) $label->copy_count !== $copies
        ) {
            throw ValidationException::withMessages(['request_uuid' => __('This print request identifier was already used for different data.')]);
        }
    }

    private function formatQuantity(mixed $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 3, '.', ''), '0'), '.');
    }

    private function formatTime(mixed $time): ?string
    {
        if (! $time) {
            return null;
        }
        if ($time instanceof \DateTimeInterface) {
            return $time->format('H:i');
        }

        try {
            return Carbon::parse((string) $time)->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }
}
