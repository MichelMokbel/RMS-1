<?php

namespace App\Services\Orders;

use App\Models\AccountingCompany;
use App\Models\OrderLabelPrint;
use App\Models\OrderLabelPrinterProfile;
use App\Models\PosTerminal;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OrderLabelPrinterProfileService
{
    public function __construct(
        private readonly AccountingAuditLogService $audit,
    ) {}

    public function save(?OrderLabelPrinterProfile $profile, array $input, int $expectedRevision, User $actor): OrderLabelPrinterProfile
    {
        $this->assertCanManage($actor);
        $data = Validator::make($input, [
            'branch_id' => ['required', 'integer', 'min:1'],
            'terminal_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:100'],
            'department' => ['required', 'string', 'in:kitchen,pastry,packing'],
            'model_code' => ['required', 'string', 'max:100'],
            'os_queue_name' => ['required', 'string', 'max:180'],
            'connection_description' => ['nullable', 'string', 'max:255'],
            'resolution_dpi' => ['required', 'integer', 'in:203,300,600'],
            'media_mode' => ['required', 'string', 'in:fixed,continuous'],
            'width_tenths_mm' => ['required', 'integer', 'min:100', 'max:1200'],
            'height_tenths_mm' => ['nullable', 'integer', 'min:100', 'max:3000', 'required_if:media_mode,fixed'],
            'min_height_tenths_mm' => ['nullable', 'integer', 'min:100', 'max:3000', 'required_if:media_mode,continuous'],
            'max_height_tenths_mm' => ['nullable', 'integer', 'min:100', 'max:3000', 'required_if:media_mode,continuous'],
            'default_copies' => ['required', 'integer', 'min:1', 'max:10'],
        ])->validate();

        if (strpbrk((string) $data['os_queue_name'], '/\\') !== false || str_contains((string) $data['os_queue_name'], "\0")) {
            throw ValidationException::withMessages(['os_queue_name' => __('Enter an operating system printer queue name, not a path.')]);
        }

        if ($data['media_mode'] === 'continuous' && (int) $data['max_height_tenths_mm'] < (int) $data['min_height_tenths_mm']) {
            throw ValidationException::withMessages(['max_height_tenths_mm' => __('Maximum height must be at least the minimum height.')]);
        }

        $branch = DB::table('branches')->where('id', $data['branch_id'])->where('is_active', 1)->first();
        abort_unless($branch, 422, __('Choose an active branch.'));
        $companyId = (int) (($branch->company_id ?? null) ?: AccountingCompany::query()->where('is_default', true)->value('id'));
        abort_unless($companyId > 0, 422, __('Configure a default company before adding printers.'));

        $terminal = PosTerminal::query()->whereKey($data['terminal_id'])->where('active', true)->first();
        abort_unless($terminal && (int) $terminal->branch_id === (int) $data['branch_id'], 422, __('Choose an active terminal from the same branch.'));

        return DB::transaction(function () use ($profile, $data, $expectedRevision, $actor, $companyId): OrderLabelPrinterProfile {
            $locked = $profile?->exists
                ? OrderLabelPrinterProfile::query()->lockForUpdate()->findOrFail($profile->id)
                : null;
            if ($locked && (int) $locked->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['revision' => __('This printer profile changed in another session. Refresh and try again.')]);
            }

            $before = $locked?->only(array_keys($data)) ?? [];
            $criticalFields = [
                'branch_id', 'terminal_id', 'model_code', 'os_queue_name', 'connection_description',
                'resolution_dpi', 'media_mode', 'width_tenths_mm', 'height_tenths_mm',
                'min_height_tenths_mm', 'max_height_tenths_mm',
            ];
            $criticalChanged = $locked && collect($criticalFields)->contains(
                fn (string $field): bool => (string) ($locked->{$field} ?? '') !== (string) ($data[$field] ?? '')
            );

            $attributes = [
                ...$data,
                'company_id' => $companyId,
                'height_tenths_mm' => $data['media_mode'] === 'fixed' ? $data['height_tenths_mm'] : null,
                'min_height_tenths_mm' => $data['media_mode'] === 'continuous' ? $data['min_height_tenths_mm'] : null,
                'max_height_tenths_mm' => $data['media_mode'] === 'continuous' ? $data['max_height_tenths_mm'] : null,
                'updated_by' => (int) $actor->id,
            ];

            if ($locked) {
                $attributes['revision'] = (int) $locked->revision + 1;
                if ($criticalChanged) {
                    $attributes += [
                        'is_verified' => false,
                        'is_active' => false,
                        'verified_by' => null,
                        'verified_at' => null,
                        'last_tested_by' => null,
                        'last_tested_at' => null,
                    ];
                }
                $locked->update($attributes);
                $saved = $locked->fresh();
            } else {
                $saved = OrderLabelPrinterProfile::query()->create([
                    ...$attributes,
                    'is_verified' => false,
                    'is_active' => false,
                    'revision' => 1,
                    'created_by' => (int) $actor->id,
                ]);
            }

            $this->audit->log('order_label_printer.saved', (int) $actor->id, $saved, [
                'before' => $before,
                'after' => $saved->only(array_keys($data)),
                'verification_reset' => (bool) $criticalChanged,
                'revision' => (int) $saved->revision,
            ], $companyId);

            return $saved;
        }, 3);
    }

    public function verify(OrderLabelPrinterProfile $profile, int $expectedRevision, User $actor): OrderLabelPrinterProfile
    {
        $this->assertCanManage($actor);

        return DB::transaction(function () use ($profile, $expectedRevision, $actor): OrderLabelPrinterProfile {
            $locked = OrderLabelPrinterProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $this->assertRevision($locked, $expectedRevision);
            $test = OrderLabelPrint::query()
                ->where('printer_profile_id', $locked->id)
                ->where('source_type', 'printer_test')
                ->where('status', OrderLabelPrint::STATUS_PRINTED)
                ->where('printed_at', '>=', $locked->updated_at)
                ->latest('printed_at')
                ->first();
            if (! $test) {
                throw ValidationException::withMessages(['verification' => __('Print and acknowledge a successful test label after the latest profile change first.')]);
            }

            $locked->update([
                'is_verified' => true,
                'verified_by' => (int) $actor->id,
                'verified_at' => now(),
                'last_tested_by' => (int) $actor->id,
                'last_tested_at' => $test->printed_at,
                'updated_by' => (int) $actor->id,
                'revision' => (int) $locked->revision + 1,
            ]);
            $this->audit->log('order_label_printer.verified', (int) $actor->id, $locked, [
                'test_label_id' => (int) $test->id,
                'revision' => (int) $locked->revision,
            ], (int) $locked->company_id);

            return $locked->fresh();
        }, 3);
    }

    public function setActive(OrderLabelPrinterProfile $profile, bool $active, int $expectedRevision, User $actor): OrderLabelPrinterProfile
    {
        $this->assertCanManage($actor);

        return DB::transaction(function () use ($profile, $active, $expectedRevision, $actor): OrderLabelPrinterProfile {
            $locked = OrderLabelPrinterProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $this->assertRevision($locked, $expectedRevision);
            if ($active && ! $locked->is_verified) {
                throw ValidationException::withMessages(['active' => __('Verify a successful physical test before activation.')]);
            }
            $locked->update([
                'is_active' => $active,
                'updated_by' => (int) $actor->id,
                'revision' => (int) $locked->revision + 1,
            ]);
            $this->audit->log('order_label_printer.status_changed', (int) $actor->id, $locked, [
                'is_active' => $active,
                'revision' => (int) $locked->revision,
            ], (int) $locked->company_id);

            return $locked->fresh();
        }, 3);
    }

    private function assertRevision(OrderLabelPrinterProfile $profile, int $expectedRevision): void
    {
        if ((int) $profile->revision !== $expectedRevision) {
            throw ValidationException::withMessages(['revision' => __('This printer profile changed in another session. Refresh and try again.')]);
        }
    }

    private function assertCanManage(User $actor): void
    {
        abort_unless($actor->hasRole('admin') && $actor->can('order-label-printers.manage'), 403);
    }
}
