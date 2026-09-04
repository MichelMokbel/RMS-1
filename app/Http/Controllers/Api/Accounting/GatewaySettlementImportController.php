<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Models\GatewaySettlementImport;
use App\Models\GatewaySettlementRow;
use App\Models\PaymentSource;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use App\Services\Payments\GatewaySettlementConflictException;
use App\Services\Payments\GatewaySettlementDuplicateImportException;
use App\Services\Payments\GatewaySettlementEvidenceService;
use App\Services\Payments\GatewaySettlementImportService;
use App\Services\Payments\GatewaySettlementPostingService;
use App\Services\Payments\GatewaySettlementReviewService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GatewaySettlementImportController extends Controller
{
    public function index(Request $request, AccountingContextService $context): JsonResponse
    {
        $data = $request->validate([
            'review_state' => ['nullable', 'in:draft,blocked,reviewed'],
            'posting_state' => ['nullable', 'in:unposted,partially_posted,posted'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = $this->visibleQuery($request, $context)
            ->with(['paymentSource:id,name,code,method'])
            ->withCount('rows')
            ->when($data['review_state'] ?? null, fn (Builder $builder, string $state) => $builder->where('review_state', $state))
            ->when($data['posting_state'] ?? null, fn (Builder $builder, string $state) => $builder->where('posting_state', $state))
            ->when($data['date_from'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('created_at', '>=', $date))
            ->when($data['date_to'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('created_at', '<=', $date))
            ->latest('id');

        return response()->json($query->paginate((int) ($data['per_page'] ?? 50)));
    }

    public function store(Request $request, GatewaySettlementImportService $service): JsonResponse
    {
        $data = $request->validate([
            'payment_source_id' => ['required', 'integer', 'exists:payment_sources,id'],
            'workbook' => ['required', 'file'],
        ]);
        $source = PaymentSource::query()->findOrFail((int) $data['payment_source_id']);

        try {
            $import = $service->stage($request->file('workbook'), $source, $request->user());
        } catch (GatewaySettlementDuplicateImportException $exception) {
            if (! $this->isVisible($exception->existingImport, $request->user())) {
                abort(404);
            }

            return response()->json([
                'code' => 'DUPLICATE_IMPORT',
                'message' => __($exception->getMessage()),
                'existing_import_id' => (int) $exception->existingImport->id,
            ], 409);
        }

        return response()->json($this->detailPayload($import), 201);
    }

    public function show(
        Request $request,
        GatewaySettlementImport $import,
        AccountingContextService $context,
        GatewaySettlementReviewService $reviews,
    ): JsonResponse {
        $import = $this->visibleQuery($request, $context)
            ->with(['paymentSource:id,name,code,method'])
            ->findOrFail($import->id);

        $data = $request->validate([
            'match_state' => ['nullable', 'in:unmatched,matched,blocked,not_applicable,duplicate,conflict'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $rows = $import->rows()
            ->when($data['match_state'] ?? null, fn (Builder $builder, string $state) => $builder->where('match_state', $state))
            ->orderBy('row_sequence')
            ->paginate((int) ($data['per_page'] ?? 50));
        $rows->through(fn ($row): array => [
            'id' => (int) $row->id,
            'row_sequence' => (int) $row->row_sequence,
            'worksheet' => $row->worksheet,
            'physical_row' => (int) $row->physical_row,
            'order_type' => $row->order_type,
            'status' => $row->status,
            'payout_reference' => $row->payout_reference,
            'row_reference' => $row->row_reference,
            'merchant' => $row->merchant,
            'branch_code' => $row->branch_code,
            'branch_id' => $row->branch_id,
            'transaction_at' => $row->transaction_at?->toISOString(),
            'sales_cents' => $row->sales_cents,
            'gross_cents' => $row->gross_cents,
            'variable_commission_cents' => $row->variable_commission_cents,
            'fixed_commission_cents' => $row->fixed_commission_cents,
            'total_commission_cents' => $row->total_commission_cents,
            'settlement_fee_cents' => $row->settlement_fee_cents,
            'net_cents' => $row->net_cents,
            'match_state' => $row->match_state,
            'matched_provider_transaction_id' => $row->matched_provider_transaction_id,
            'error_code' => $row->error_code,
            'errors' => $row->source_evidence['errors'] ?? [],
            'revision' => (int) $row->revision,
        ]);

        return response()->json([
            'import' => $this->detailPayload($import),
            'payouts' => $reviews->payoutsForImport($import, $request->user()),
            'evidence' => $this->evidencePayload($import),
            'rows' => $rows,
        ]);
    }

    public function match(
        Request $request,
        GatewaySettlementImport $import,
        GatewaySettlementRow $row,
        AccountingContextService $context,
        GatewaySettlementReviewService $reviews,
    ): JsonResponse {
        $import = $this->visibleQuery($request, $context)->findOrFail($import->id);
        $row = $import->rows()->findOrFail($row->id);
        $data = $request->validate([
            'import_revision' => ['required', 'integer', 'min:1'],
            'row_revision' => ['required', 'integer', 'min:1'],
            'provider_transaction_id' => ['nullable', 'integer', 'exists:payment_provider_transactions,id'],
            'evidence_reference' => ['nullable', 'string', 'max:80'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $row = $reviews->match(
                $import,
                $row,
                isset($data['provider_transaction_id']) ? (int) $data['provider_transaction_id'] : null,
                (int) $data['import_revision'],
                (int) $data['row_revision'],
                $request->user(),
                $data['evidence_reference'] ?? null,
                $data['reason'] ?? null,
            );
        } catch (GatewaySettlementConflictException $exception) {
            return $this->conflict($exception);
        }

        return response()->json([
            'id' => (int) $row->id,
            'match_state' => $row->match_state,
            'matched_provider_transaction_id' => $row->matched_provider_transaction_id,
            'revision' => (int) $row->revision,
            'import_revision' => (int) $import->fresh()->revision,
        ]);
    }

    public function review(
        Request $request,
        GatewaySettlementImport $import,
        AccountingContextService $context,
        GatewaySettlementReviewService $reviews,
    ): JsonResponse {
        $import = $this->visibleQuery($request, $context)->findOrFail($import->id);
        $data = $request->validate([
            'import_revision' => ['required', 'integer', 'min:1'],
            'payout_reference' => ['required', 'string', 'max:160'],
            'payout_fingerprint' => ['required', 'string', 'size:64'],
            'evidence_bank_transaction_id' => ['nullable', 'required_without:remittance_evidence_reference', 'integer', 'exists:bank_transactions,id'],
            'remittance_evidence_reference' => ['nullable', 'required_without:evidence_bank_transaction_id', 'string', 'max:80'],
        ]);

        try {
            $review = $reviews->review(
                $import,
                $data['payout_reference'],
                $data['payout_fingerprint'],
                isset($data['evidence_bank_transaction_id']) ? (int) $data['evidence_bank_transaction_id'] : null,
                (int) $data['import_revision'],
                $request->user(),
                $data['remittance_evidence_reference'] ?? null,
            );
        } catch (GatewaySettlementConflictException $exception) {
            return $this->conflict($exception);
        }

        return response()->json($review);
    }

    public function evidence(
        Request $request,
        GatewaySettlementImport $import,
        AccountingContextService $context,
        GatewaySettlementEvidenceService $evidence,
    ): JsonResponse {
        $import = $this->visibleQuery($request, $context)->findOrFail($import->id);
        $purpose = (string) $request->input('purpose', 'provider_reference');
        if ($purpose === 'bank_remittance') {
            $data = $request->validate([
                'purpose' => ['required', 'in:bank_remittance'],
                'payout_reference' => ['required', 'string', 'max:160'],
                'amount_cents' => ['required', 'integer', 'min:1'],
                'bank_date' => ['required', 'date_format:Y-m-d'],
                'import_revision' => ['required', 'integer', 'min:1'],
                'evidence_file' => ['required', 'file'],
            ]);

            try {
                $stored = $evidence->storeBankRemittance(
                    $import,
                    $request->file('evidence_file'),
                    $data['payout_reference'],
                    (int) $data['amount_cents'],
                    $data['bank_date'],
                    (int) $data['import_revision'],
                    $request->user(),
                );
            } catch (GatewaySettlementConflictException $exception) {
                return $this->conflict($exception);
            }

            return response()->json($stored, 201);
        }

        $data = $request->validate([
            'purpose' => ['nullable', 'in:provider_reference'],
            'row_id' => ['required', 'integer', 'exists:gateway_settlement_rows,id'],
            'provider_field' => ['required', 'in:provider_payment_id,merchant_transaction_id,visa_id'],
            'provider_value' => ['required', 'string', 'max:160'],
            'import_revision' => ['required', 'integer', 'min:1'],
            'row_revision' => ['required', 'integer', 'min:1'],
            'evidence_file' => ['required', 'file'],
        ]);
        $row = $import->rows()->findOrFail((int) $data['row_id']);

        try {
            $stored = $evidence->storeProviderReference(
                $import,
                $row,
                $request->file('evidence_file'),
                $data['provider_field'],
                $data['provider_value'],
                (int) $data['import_revision'],
                (int) $data['row_revision'],
                $request->user(),
            );
        } catch (GatewaySettlementConflictException $exception) {
            return $this->conflict($exception);
        }

        return response()->json($stored, 201);
    }

    public function evidenceFile(
        Request $request,
        GatewaySettlementImport $import,
        string $evidence,
        AccountingContextService $context,
        GatewaySettlementEvidenceService $evidenceService,
    ): StreamedResponse {
        $import = $this->visibleQuery($request, $context)->findOrFail($import->id);
        $metadata = $evidenceService->downloadMetadata($import, $evidence, $request->user());

        return Storage::disk($metadata['private_disk'])->download(
            $metadata['object_key'],
            $metadata['original_name'],
            ['Content-Type' => $metadata['mime_type']],
        );
    }

    public function post(
        Request $request,
        GatewaySettlementImport $import,
        AccountingContextService $context,
        GatewaySettlementPostingService $posting,
    ): JsonResponse {
        $import = $this->visibleQuery($request, $context)->findOrFail($import->id);
        $data = $request->validate([
            'payout_reference' => ['required', 'string', 'max:160'],
            'reviewed_fingerprint' => ['required', 'string', 'size:64'],
            'client_uuid' => ['required', 'uuid'],
        ]);
        $alreadyPosted = \App\Models\ArClearingSettlement::query()
            ->where('payment_source_id', $import->payment_source_id)
            ->where('payout_reference', $data['payout_reference'])
            ->whereNull('voided_at')
            ->exists();

        try {
            $settlement = $posting->post(
                $import,
                $data['payout_reference'],
                $data['reviewed_fingerprint'],
                $data['client_uuid'],
                $request->user(),
            );
        } catch (GatewaySettlementConflictException $exception) {
            return $this->conflict($exception);
        }

        return response()->json([
            'id' => (int) $settlement->id,
            'payout_reference' => $settlement->payout_reference,
            'gross_cents' => (int) $settlement->amount_cents,
            'commission_cents' => (int) $settlement->commission_cents,
            'settlement_fee_cents' => (int) $settlement->settlement_fee_cents,
            'net_cents' => (int) $settlement->net_cents,
            'settlement_date' => $settlement->settlement_date?->toDateString(),
        ], $alreadyPosted ? 200 : 201);
    }

    public function file(
        Request $request,
        GatewaySettlementImport $import,
        AccountingContextService $context,
        AccountingAuditLogService $audit,
    ): StreamedResponse {
        $import = $this->visibleQuery($request, $context)->findOrFail($import->id);
        $disk = Storage::disk($import->getRawOriginal('private_disk'));
        $objectKey = (string) $import->getRawOriginal('object_key');
        abort_unless($disk->exists($objectKey), 404);

        $audit->log('gateway_settlement_import.file_accessed', (int) $request->user()->id, $import, [
            'file_hash' => $import->file_hash,
        ], (int) $import->company_id);

        return $disk->download(
            $objectKey,
            basename((string) $import->original_name),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    private function visibleQuery(Request $request, AccountingContextService $context): Builder
    {
        $companyId = $context->defaultCompanyId();
        abort_if($companyId === null, 404);

        $query = GatewaySettlementImport::query()->where('company_id', $companyId);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        if ($user->isAdmin()) {
            return $query;
        }

        $allowedBranchIds = $user->allowedBranchIds();
        if ($allowedBranchIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereDoesntHave('rows', function (Builder $rows) use ($allowedBranchIds): void {
            $rows->where(fn (Builder $scope) => $scope
                ->whereNull('branch_id')
                ->orWhereNotIn('branch_id', $allowedBranchIds));
        });
    }

    private function isVisible(GatewaySettlementImport $import, mixed $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        $allowed = $user->allowedBranchIds();

        return $allowed !== [] && ! $import->rows()
            ->where(fn (Builder $rows) => $rows->whereNull('branch_id')->orWhereNotIn('branch_id', $allowed))
            ->exists();
    }

    /** @return array<string, mixed> */
    private function detailPayload(GatewaySettlementImport $import): array
    {
        return [
            'id' => (int) $import->id,
            'company_id' => (int) $import->company_id,
            'payment_source_id' => (int) $import->payment_source_id,
            'original_name' => $import->original_name,
            'file_hash' => $import->file_hash,
            'parser_version' => $import->parser_version,
            'currency' => $import->currency,
            'timezone' => $import->timezone,
            'report_period_start' => $import->report_period_start?->toDateString(),
            'report_period_end' => $import->report_period_end?->toDateString(),
            'gross_cents' => (int) $import->gross_cents,
            'commission_cents' => (int) $import->commission_cents,
            'settlement_fee_cents' => (int) $import->settlement_fee_cents,
            'net_cents' => (int) $import->net_cents,
            'totals_complete' => (bool) $import->totals_complete,
            'review_state' => $import->review_state,
            'posting_state' => $import->posting_state,
            'revision' => (int) $import->revision,
            'error_code' => $import->error_code,
            'created_at' => $import->created_at?->toISOString(),
        ];
    }

    private function conflict(GatewaySettlementConflictException $exception): JsonResponse
    {
        return response()->json([
            'code' => $exception->conflictCode,
            'message' => __($exception->getMessage()),
        ], 409);
    }

    /** @return array<int, array<string, mixed>> */
    private function evidencePayload(GatewaySettlementImport $import): array
    {
        return collect($import->evidence_manifest ?? [])->map(function (array $entry): array {
            $public = [
                'id' => $entry['id'],
                'purpose' => $entry['purpose'],
                'payout_reference' => $entry['payout_reference'],
                'original_name' => $entry['original_name'],
                'mime_type' => $entry['mime_type'],
                'byte_count' => $entry['byte_count'],
                'sha256' => $entry['sha256'],
                'uploaded_at' => $entry['uploaded_at'],
            ];
            if (($entry['purpose'] ?? null) === 'provider_reference') {
                return [
                    ...$public,
                    'row_id' => $entry['row_id'],
                    'report_field' => $entry['report_field'],
                    'provider_field' => $entry['provider_field'],
                ];
            }

            return [
                ...$public,
                'bank_account_id' => $entry['bank_account_id'],
                'amount_cents' => $entry['amount_cents'],
                'bank_date' => $entry['bank_date'],
            ];
        })->values()->all();
    }
}
