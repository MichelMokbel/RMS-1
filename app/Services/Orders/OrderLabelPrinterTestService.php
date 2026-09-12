<?php

namespace App\Services\Orders;

use App\Models\OrderLabelPrint;
use App\Models\OrderLabelPrinterProfile;
use App\Models\User;
use App\Services\POS\PosPrintJobService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderLabelPrinterTestService
{
    public function __construct(
        private readonly PosPrintJobService $printJobs,
        private readonly OrderLabelPdfRenderer $renderer,
    ) {}

    public function send(OrderLabelPrinterProfile $profile, User $actor): OrderLabelPrint
    {
        abort_unless($actor->hasRole('admin') && $actor->can('order-label-printers.manage'), 403);
        $profile->load('terminal');
        abort_unless($profile->terminal && $profile->terminal->active, 422, __('The assigned print terminal is inactive.'));
        abort_unless((int) $profile->terminal->branch_id === (int) $profile->branch_id, 422);

        $snapshot = $this->sampleSnapshot($profile);
        $hash = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $uuid = (string) Str::uuid();

        return DB::transaction(function () use ($profile, $actor, $snapshot, $hash, $uuid): OrderLabelPrint {
            $sequence = (int) OrderLabelPrint::query()
                ->where('printer_profile_id', $profile->id)
                ->where('source_type', 'printer_test')
                ->where('source_id', $profile->id)
                ->lockForUpdate()
                ->max('sequence') + 1;
            $label = OrderLabelPrint::query()->create([
                'uuid' => $uuid,
                'company_id' => (int) $profile->company_id,
                'branch_id' => (int) $profile->branch_id,
                'printer_profile_id' => (int) $profile->id,
                'source_type' => 'printer_test',
                'source_id' => (int) $profile->id,
                'service_date' => now()->toDateString(),
                'snapshot' => $snapshot,
                'snapshot_hash' => $hash,
                'copy_count' => 1,
                'sequence' => $sequence,
                'requested_by' => (int) $actor->id,
                'status' => OrderLabelPrint::STATUS_PREPARING,
            ]);
            $pdf = $this->renderer->render($snapshot, $profile, 1, $sequence);
            $job = $this->printJobs->enqueueServerDocument($profile->terminal, [
                'server_job_uuid' => $uuid,
                'order_label_print_id' => (int) $label->id,
                'branch_id' => (int) $profile->branch_id,
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
                    'is_test' => true,
                ],
            ], (int) $actor->id)['job'];

            $label->update([
                'pos_print_job_id' => (int) $job->id,
                'status' => OrderLabelPrint::STATUS_QUEUED,
                'queued_at' => now(),
            ]);

            return $label->fresh(['printJob']);
        }, 3);
    }

    public function preview(OrderLabelPrinterProfile $profile, User $actor): string
    {
        abort_unless($actor->hasRole('admin') && $actor->can('order-label-printers.manage'), 403);

        return $this->renderer->render($this->sampleSnapshot($profile), $profile, 1, 1);
    }

    private function sampleSnapshot(OrderLabelPrinterProfile $profile): array
    {
        return [
            'source_type' => 'printer_test',
            'source_id' => (int) $profile->id,
            'brand' => 'Layla Kitchen',
            'order_number' => 'TEST LABEL',
            'service_date' => now()->toDateString(),
            'service_time' => now()->format('H:i'),
            'customer_name' => $profile->name,
            'destination' => $profile->model_code.' · '.$profile->os_queue_name,
            'items' => [
                ['quantity' => '1', 'description' => 'English / العربية'],
                ['quantity' => '2.5', 'description' => 'Width '.($profile->width_tenths_mm / 10).' mm · '.$profile->resolution_dpi.' dpi'],
            ],
        ];
    }
}
