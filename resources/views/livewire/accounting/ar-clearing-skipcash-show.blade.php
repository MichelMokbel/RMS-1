<?php

use App\Models\ArClearingSettlement;
use App\Models\GatewaySettlementImport;
use App\Models\User;
use App\Services\Payments\GatewaySettlementConflictException;
use App\Services\Payments\GatewaySettlementEvidenceService;
use App\Services\Payments\GatewaySettlementPostingService;
use App\Services\Payments\GatewaySettlementReviewService;
use App\Support\Money\MinorUnits;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithFileUploads;
    use WithPagination;

    public GatewaySettlementImport $settlementImport;

    /** @var array<string, int|string|null> */
    public array $row_selections = [];

    /** @var array<string, mixed> */
    public array $evidence_files = [];

    /** @var array<string, string> */
    public array $provider_fields = [];

    /** @var array<string, string> */
    public array $provider_values = [];

    /** @var array<string, string> */
    public array $row_evidence = [];

    /** @var array<string, string> */
    public array $unmatch_reasons = [];

    /** @var array<string, int|string|null> */
    public array $bank_evidence = [];

    /** @var array<string, mixed> */
    public array $remittance_files = [];

    /** @var array<string, string> */
    public array $remittance_amounts = [];

    /** @var array<string, string> */
    public array $remittance_dates = [];

    /** @var array<string, string> */
    public array $remittance_evidence = [];

    /** @var array<string, bool> */
    public array $confirm_posts = [];

    /** @var array<string, string> */
    public array $post_client_uuids = [];

    protected $paginationTheme = 'tailwind';

    public function mount(GatewaySettlementImport $import, GatewaySettlementReviewService $reviews): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $reviews->authorizeImport($import, $actor);

        $this->settlementImport = $import;
        foreach ($import->evidence_manifest ?? [] as $entry) {
            if (($entry['purpose'] ?? null) === 'provider_reference' && ! empty($entry['row_id']) && ! empty($entry['id'])) {
                $this->row_evidence[(string) $entry['row_id']] = (string) $entry['id'];
            }
            if (($entry['purpose'] ?? null) === 'bank_remittance' && ! empty($entry['payout_reference']) && ! empty($entry['id'])) {
                $this->remittance_evidence[$this->payoutKey((string) $entry['payout_reference'])] = (string) $entry['id'];
            }
        }
        $this->initializePayoutState($reviews);
    }

    public function with(GatewaySettlementReviewService $reviews): array
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $reviews->authorizeImport($this->settlementImport, $actor);

        $rows = $this->settlementImport->rows()
            ->with(['branch:id,name', 'duplicateOf.import:id', 'providerTransaction.payment.customer'])
            ->orderBy('row_sequence')
            ->paginate(50, ['*'], 'rowsPage');
        $payouts = collect($reviews->payoutsForImport($this->settlementImport, $actor));
        $contributingImports = GatewaySettlementImport::query()
            ->whereIn('id', $payouts->flatMap(fn (array $payout): array => $payout['contributing_import_ids'])->unique()->values())
            ->get();
        $remittanceEvidenceByPayout = $contributingImports
            ->flatMap(fn (GatewaySettlementImport $import) => collect($import->evidence_manifest ?? [])
                ->where('purpose', 'bank_remittance')
                ->map(fn (array $entry): array => [...$entry, 'import_id' => (int) $import->id]))
            ->groupBy(fn (array $entry): string => $this->payoutKey((string) $entry['payout_reference']));
        $candidateOptions = [];
        foreach ($rows->getCollection() as $row) {
            if ($row->order_type === 'sale' && $row->error_code === null && $row->match_state !== 'matched') {
                $candidateOptions[$row->id] = $reviews->candidatesForRow($row, $actor);
            }
        }

        $bankCandidates = [];
        foreach ($payouts as $payout) {
            $key = $this->payoutKey((string) $payout['payout_reference']);
            $bankCandidates[$key] = $reviews->bankEvidenceCandidates(
                $this->settlementImport,
                (string) $payout['payout_reference'],
                $actor,
            );
        }

        $settlements = ArClearingSettlement::query()
            ->where('payment_source_id', $this->settlementImport->payment_source_id)
            ->whereIn('payout_reference', $payouts->pluck('payout_reference'))
            ->get()
            ->keyBy('payout_reference');

        return [
            'rows' => $rows,
            'payouts' => $payouts,
            'candidateOptions' => $candidateOptions,
            'bankCandidates' => $bankCandidates,
            'settlementsByPayout' => $settlements,
            'reviewSnapshots' => $this->settlementImport->review_snapshots ?? [],
            'evidenceByRow' => collect($this->settlementImport->evidence_manifest ?? [])
                ->where('purpose', 'provider_reference')
                ->groupBy(fn (array $entry): string => (string) $entry['row_id']),
            'remittanceEvidenceByPayout' => $remittanceEvidenceByPayout,
            'identifierMappingReady' => $this->identifierMappingReady(),
        ];
    }

    public function uploadRowEvidence(int $rowId, GatewaySettlementEvidenceService $evidence): void
    {
        $key = (string) $rowId;
        $data = $this->validate([
            'evidence_files.'.$key => ['required', 'file', 'max:'.max(1, (int) config('skipcash.settlements.max_upload_kb', 10_240))],
            'provider_fields.'.$key => ['required', 'in:provider_payment_id,merchant_transaction_id,visa_id'],
            'provider_values.'.$key => ['required', 'string', 'max:160'],
        ]);
        $row = $this->settlementImport->rows()->findOrFail($rowId);

        try {
            $stored = $evidence->storeProviderReference(
                $this->settlementImport,
                $row,
                $data['evidence_files'][$key],
                $data['provider_fields'][$key],
                $data['provider_values'][$key],
                (int) $this->settlementImport->revision,
                (int) $row->revision,
                Auth::user(),
            );
            $this->row_evidence[$key] = (string) $stored['id'];
            unset($this->evidence_files[$key]);
            $this->refreshImport();
            session()->flash('status', __('Provider reference evidence was retained for report row :row.', ['row' => $row->row_sequence]));
        } catch (\Throwable $exception) {
            $this->handleActionFailure($exception);
        }
    }

    public function matchRow(int $rowId, GatewaySettlementReviewService $reviews): void
    {
        $key = (string) $rowId;
        $providerTransactionId = filter_var($this->row_selections[$key] ?? null, FILTER_VALIDATE_INT);
        if (! $providerTransactionId) {
            $this->addError('row_selections.'.$key, __('Choose a verified SkipCash payment.'));

            return;
        }
        $evidenceReference = $this->identifierMappingReady()
            ? null
            : trim((string) ($this->row_evidence[$key] ?? ''));
        if ($evidenceReference === '') {
            $this->addError('row_evidence.'.$key, __('Choose retained provider reference evidence.'));

            return;
        }

        $row = $this->settlementImport->rows()->findOrFail($rowId);
        try {
            $reviews->match(
                $this->settlementImport,
                $row,
                (int) $providerTransactionId,
                (int) $this->settlementImport->revision,
                (int) $row->revision,
                Auth::user(),
                $evidenceReference,
            );
            unset($this->row_selections[$key]);
            $this->refreshImport();
            session()->flash('status', __('Report row :row was matched.', ['row' => $row->row_sequence]));
        } catch (\Throwable $exception) {
            $this->handleActionFailure($exception);
        }
    }

    public function unmatchRow(int $rowId, GatewaySettlementReviewService $reviews): void
    {
        $key = (string) $rowId;
        $reason = trim((string) ($this->unmatch_reasons[$key] ?? ''));
        if ($reason === '') {
            $this->addError('unmatch_reasons.'.$key, __('Give a reason for removing this match.'));

            return;
        }

        $row = $this->settlementImport->rows()->findOrFail($rowId);
        try {
            $reviews->match(
                $this->settlementImport,
                $row,
                null,
                (int) $this->settlementImport->revision,
                (int) $row->revision,
                Auth::user(),
                null,
                $reason,
            );
            unset($this->unmatch_reasons[$key]);
            $this->refreshImport();
            session()->flash('status', __('The match for report row :row was removed.', ['row' => $row->row_sequence]));
        } catch (\Throwable $exception) {
            $this->handleActionFailure($exception);
        }
    }

    public function reviewPayout(string $payoutReference, GatewaySettlementReviewService $reviews): void
    {
        $key = $this->payoutKey($payoutReference);
        $bankTransactionId = filter_var($this->bank_evidence[$key] ?? null, FILTER_VALIDATE_INT);
        $remittanceEvidenceReference = trim((string) ($this->remittance_evidence[$key] ?? ''));
        if (! $bankTransactionId && $remittanceEvidenceReference === '') {
            $this->addError('bank_evidence.'.$key, __('Choose the exact bank statement deposit or retained remittance evidence.'));

            return;
        }

        try {
            $summary = $reviews->payoutSummary((int) $this->settlementImport->payment_source_id, $payoutReference);
            $reviews->review(
                $this->settlementImport,
                $payoutReference,
                (string) $summary['payout_fingerprint'],
                $bankTransactionId ? (int) $bankTransactionId : null,
                (int) $this->settlementImport->revision,
                Auth::user(),
                $remittanceEvidenceReference !== '' ? $remittanceEvidenceReference : null,
            );
            $this->refreshImport();
            session()->flash('status', __('Payout :reference is reviewed and ready to post.', ['reference' => $payoutReference]));
        } catch (\Throwable $exception) {
            $this->handleActionFailure($exception);
        }
    }

    public function uploadRemittanceEvidence(string $payoutReference, GatewaySettlementEvidenceService $evidence): void
    {
        $key = $this->payoutKey($payoutReference);
        $data = $this->validate([
            'remittance_files.'.$key => ['required', 'file', 'max:'.max(1, (int) config('skipcash.settlements.max_upload_kb', 10_240))],
            'remittance_amounts.'.$key => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'remittance_dates.'.$key => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            $stored = $evidence->storeBankRemittance(
                $this->settlementImport,
                $data['remittance_files'][$key],
                $payoutReference,
                MinorUnits::parse((string) $data['remittance_amounts'][$key], 100),
                $data['remittance_dates'][$key],
                (int) $this->settlementImport->revision,
                Auth::user(),
            );
            $this->remittance_evidence[$key] = (string) $stored['id'];
            unset($this->remittance_files[$key]);
            $this->refreshImport();
            session()->flash('status', __('Bank remittance evidence was retained for payout :reference.', ['reference' => $payoutReference]));
        } catch (\Throwable $exception) {
            $this->handleActionFailure($exception);
        }
    }

    public function postPayout(string $payoutReference, GatewaySettlementPostingService $posting): void
    {
        abort_unless(Auth::user()?->can('gateway_settlements.post'), 403);
        $key = $this->payoutKey($payoutReference);
        if (! ($this->confirm_posts[$key] ?? false)) {
            $this->addError('confirm_posts.'.$key, __('Confirm that the reviewed payout should be posted.'));

            return;
        }

        $snapshot = ($this->settlementImport->review_snapshots ?? [])[$payoutReference] ?? null;
        if (! is_array($snapshot) || empty($snapshot['reviewed_fingerprint'])) {
            $this->addError('confirm_posts.'.$key, __('Review this payout before posting it.'));

            return;
        }

        try {
            $settlement = $posting->post(
                $this->settlementImport,
                $payoutReference,
                (string) $snapshot['reviewed_fingerprint'],
                $this->post_client_uuids[$key] ?? (string) Str::uuid(),
                Auth::user(),
            );
            session()->flash('status', __('SkipCash payout :reference was posted.', ['reference' => $payoutReference]));
            $this->redirectRoute('accounting.ar-clearing-show', $settlement, navigate: true);
        } catch (\Throwable $exception) {
            $this->handleActionFailure($exception);
        }
    }

    public function payoutKey(string $reference): string
    {
        return hash('sha256', $reference);
    }

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    private function initializePayoutState(GatewaySettlementReviewService $reviews): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        foreach ($reviews->payoutsForImport($this->settlementImport, $actor) as $payout) {
            $key = $this->payoutKey((string) $payout['payout_reference']);
            $this->post_client_uuids[$key] ??= (string) Str::uuid();
            $this->remittance_amounts[$key] ??= MinorUnits::format((int) $payout['net_cents'], 100);
        }
    }

    private function refreshImport(): void
    {
        $this->settlementImport = $this->settlementImport->fresh();
    }

    private function identifierMappingReady(): bool
    {
        $profile = config('skipcash.report_profiles.'.strtolower((string) $this->settlementImport->paymentSource->code), []);
        $mapping = is_array($profile) ? ($profile['identifier_mapping'] ?? []) : [];
        $normalize = fn (mixed $value): string => strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $value));

        return $normalize($mapping['report_field'] ?? null) === 'referencenumber'
            && in_array($normalize($mapping['provider_field'] ?? null), ['providerpaymentid', 'merchanttransactionid', 'visaid'], true)
            && trim((string) ($mapping['evidence_reference'] ?? '')) !== '';
    }

    private function handleActionFailure(\Throwable $exception): void
    {
        $this->refreshImport();
        if ($exception instanceof AuthorizationException) {
            abort(403, $exception->getMessage());
        }
        if ($exception instanceof GatewaySettlementConflictException) {
            session()->flash('error', '['.$exception->conflictCode.'] '.__($exception->getMessage()));

            return;
        }
        if ($exception instanceof ValidationException) {
            session()->flash('error', (string) collect($exception->errors())->flatten()->first());

            return;
        }

        report($exception);
        session()->flash('error', __('The settlement action failed. No accounting entry was created.'));
    }
}; ?>

<div class="mx-auto w-full max-w-7xl space-y-6 px-4">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                    {{ __('SkipCash report') }} #{{ $settlementImport->id }}
                </h1>
                @if ($settlementImport->posting_state === 'posted')
                    <span class="inline-flex rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200">{{ __('Posted') }}</span>
                @elseif ($settlementImport->review_state === 'blocked')
                    <span class="inline-flex rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-semibold text-rose-800 dark:bg-rose-900/50 dark:text-rose-200">{{ __('Blocked') }}</span>
                @elseif ($settlementImport->review_state === 'reviewed')
                    <span class="inline-flex rounded-full bg-sky-100 px-2.5 py-0.5 text-xs font-semibold text-sky-800 dark:bg-sky-900/50 dark:text-sky-200">{{ __('Reviewed') }}</span>
                @else
                    <span class="inline-flex rounded-full bg-neutral-100 px-2.5 py-0.5 text-xs font-semibold text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">{{ __('Draft') }}</span>
                @endif
            </div>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-300">
                {{ $settlementImport->original_name }} · {{ $settlementImport->report_period_start?->format('Y-m-d') ?? '—' }} – {{ $settlementImport->report_period_end?->format('Y-m-d') ?? '—' }}
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('accounting.ar-clearing.skipcash.file', $settlementImport)" variant="ghost">{{ __('Download source') }}</flux:button>
            <flux:button :href="route('accounting.ar-clearing', ['tab' => 'skipcash'])" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
        </div>
    </header>

    @if (session('status'))
        <div role="status" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div role="alert" class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-100">{{ session('error') }}</div>
    @endif
    @if (! config('skipcash.settlements.enabled'))
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100">
            {{ __('This report is available for inspection, but matching, review, and posting are disabled by configuration.') }}
        </div>
    @endif
    @if (! $identifierMappingReady)
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100">
            {{ __('Automatic matching remains blocked until the report reference mapping is proven and configured. For a controlled case, retain provider evidence on the individual sale row.') }}
        </div>
    @endif

    <section aria-label="{{ __('Report totals') }}" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            __('Gross sales') => $settlementImport->gross_cents,
            __('Commission') => $settlementImport->commission_cents,
            __('Settlement fees') => $settlementImport->settlement_fee_cents,
            __('Net payout') => $settlementImport->net_cents,
        ] as $label => $amount)
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ $label }}</p>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($amount) }}</p>
            </div>
        @endforeach
    </section>

    <section aria-labelledby="payout-review-heading" class="space-y-4">
        <div>
            <h2 id="payout-review-heading" class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Payout review') }}</h2>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-300">{{ __('Each payout needs complete sale matches and bank evidence for its exact net amount and settlement date.') }}</p>
        </div>

        @forelse ($payouts as $payout)
            @php
                $payoutReference = (string) $payout['payout_reference'];
                $payoutKey = $this->payoutKey($payoutReference);
                $snapshot = $reviewSnapshots[$payoutReference] ?? null;
                $existingSettlement = $settlementsByPayout->get($payoutReference);
            @endphp
            <article class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Payout') }} {{ $payoutReference }}</h3>
                        <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-300">
                            {{ trans_choice(':count sale|:count sales', $payout['sale_count'], ['count' => $payout['sale_count']]) }} ·
                            {{ __('Gross :gross · deductions :deductions · net :net', [
                                'gross' => $this->formatMoney($payout['gross_cents']),
                                'deductions' => $this->formatMoney($payout['commission_cents'] + $payout['settlement_fee_cents']),
                                'net' => $this->formatMoney($payout['net_cents']),
                            ]) }}
                        </p>
                        @if (count($payout['contributing_import_ids']) > 1)
                            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                {{ __('Combined evidence from reports:') }}
                                @foreach ($payout['contributing_import_ids'] as $contributingImportId)
                                    <a class="font-medium text-primary-700 underline-offset-2 hover:underline dark:text-primary-300" href="{{ route('accounting.ar-clearing.skipcash.show', $contributingImportId) }}" wire:navigate>#{{ $contributingImportId }}</a>{{ ! $loop->last ? ',' : '' }}
                                @endforeach
                                · {{ trans_choice(':count distinct row|:count distinct rows', $payout['distinct_row_count'], ['count' => $payout['distinct_row_count']]) }}
                                @if ($payout['duplicate_count'] > 0)
                                    · {{ trans_choice(':count retained duplicate|:count retained duplicates', $payout['duplicate_count'], ['count' => $payout['duplicate_count']]) }}
                                @endif
                            </p>
                        @endif
                    </div>
                    @if ($existingSettlement)
                        <flux:button size="sm" :href="route('accounting.ar-clearing-show', $existingSettlement)" wire:navigate>
                            {{ $existingSettlement->voided_at ? __('View voided settlement') : __('View settlement') }}
                        </flux:button>
                    @endif
                </div>

                @if ($payout['blocking_reasons'] !== [])
                    <div class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 dark:border-rose-900 dark:bg-rose-950/30">
                        <p class="text-sm font-medium text-rose-800 dark:text-rose-200">{{ __('Review required before posting') }}</p>
                        <ul class="mt-1 list-disc space-y-1 pl-5 text-xs text-rose-700 dark:text-rose-300">
                            @foreach ($payout['blocking_reasons'] as $reason)
                                <li>{{ str($reason)->replace('_', ' ')->lower()->ucfirst() }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (! $existingSettlement && $snapshot === null)
                    <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                        <div class="space-y-3">
                            <div>
                            <label for="bank-evidence-{{ $payoutKey }}" class="block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Bank statement deposit') }}</label>
                            <select id="bank-evidence-{{ $payoutKey }}" wire:model="bank_evidence.{{ $payoutKey }}" class="mt-1 min-h-11 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                <option value="">{{ __('Choose exact net deposit, if imported') }}</option>
                                @foreach ($bankCandidates[$payoutKey] ?? [] as $bankTransaction)
                                    <option value="{{ $bankTransaction->id }}">
                                        {{ $bankTransaction->transaction_date?->format('Y-m-d') }} · {{ $bankTransaction->reference ?: __('no reference') }} · {{ $this->formatMoney($payout['net_cents']) }}
                                    </option>
                                @endforeach
                            </select>
                            @error('bank_evidence.'.$payoutKey)<p role="alert" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror
                            @if (($bankCandidates[$payoutKey] ?? collect())->isEmpty())
                                <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ __('No available deposit matches this payout net amount in the default bank account.') }}</p>
                            @endif
                            </div>

                            <div class="rounded-md border border-neutral-200 bg-neutral-50 p-3 dark:border-neutral-700 dark:bg-neutral-800/60">
                                <label for="remittance-evidence-{{ $payoutKey }}" class="block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Retained bank remittance evidence') }}</label>
                                @if (($remittanceEvidenceByPayout[$payoutKey] ?? collect())->isNotEmpty())
                                    <select id="remittance-evidence-{{ $payoutKey }}" wire:model="remittance_evidence.{{ $payoutKey }}" class="mt-2 min-h-11 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-50">
                                        <option value="">{{ __('Choose retained remittance') }}</option>
                                        @foreach ($remittanceEvidenceByPayout[$payoutKey] as $remittance)
                                            <option value="{{ $remittance['id'] }}">{{ $remittance['bank_date'] }} · {{ $this->formatMoney($remittance['amount_cents']) }} · {{ $remittance['original_name'] }} · {{ __('report #:id', ['id' => $remittance['import_id']]) }}</option>
                                        @endforeach
                                    </select>
                                    <div class="mt-1 flex flex-wrap gap-2">
                                        @foreach ($remittanceEvidenceByPayout[$payoutKey] as $remittance)
                                            <a class="text-xs font-medium text-primary-700 hover:underline dark:text-primary-300" href="{{ route('accounting.ar-clearing.skipcash.evidence.file', [$settlementImport, $remittance['id']]) }}">{{ __('Download :name', ['name' => $remittance['original_name']]) }}</a>
                                        @endforeach
                                    </div>
                                @endif
                                <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ __('Use this when no statement deposit is imported yet, or when its reference does not identify the SkipCash payout.') }}</p>
                                <details class="mt-2">
                                    <summary class="cursor-pointer text-xs font-medium text-primary-700 dark:text-primary-300">{{ __('Upload remittance evidence') }}</summary>
                                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                        <label class="text-xs font-medium text-neutral-700 dark:text-neutral-200">
                                            {{ __('Amount shown in QAR') }}
                                            <input wire:model="remittance_amounts.{{ $payoutKey }}" inputmode="decimal" class="mt-1 min-h-11 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                                        </label>
                                        <label class="text-xs font-medium text-neutral-700 dark:text-neutral-200">
                                            {{ __('Bank date shown') }}
                                            <input wire:model="remittance_dates.{{ $payoutKey }}" type="date" class="mt-1 min-h-11 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                                        </label>
                                        <label class="text-xs font-medium text-neutral-700 dark:text-neutral-200 sm:col-span-2">
                                            {{ __('Private evidence file') }}
                                            <input wire:model="remittance_files.{{ $payoutKey }}" type="file" accept=".pdf,.png,.jpg,.jpeg,.xlsx" class="mt-1 min-h-11 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                                        </label>
                                        <div class="sm:col-span-2">
                                            <flux:button size="xs" type="button" wire:click="uploadRemittanceEvidence(@js($payoutReference))" wire:loading.attr="disabled" wire:target="uploadRemittanceEvidence,remittance_files.{{ $payoutKey }}" :disabled="! config('skipcash.settlements.enabled')">{{ __('Retain remittance') }}</flux:button>
                                        </div>
                                    </div>
                                </details>
                                @error('remittance_evidence.'.$payoutKey)<p role="alert" class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror
                                @error('remittance_amounts.'.$payoutKey)<p role="alert" class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror
                                @error('remittance_dates.'.$payoutKey)<p role="alert" class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror
                                @error('remittance_files.'.$payoutKey)<p role="alert" class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror
                            </div>
                        </div>
                        <flux:button type="button" variant="primary" wire:click="reviewPayout(@js($payoutReference))" wire:loading.attr="disabled" wire:target="reviewPayout" :disabled="! config('skipcash.settlements.enabled') || $payout['blocking_reasons'] !== []">
                            {{ __('Review payout') }}
                        </flux:button>
                    </div>
                @elseif (! $existingSettlement && is_array($snapshot))
                    <div class="mt-4 rounded-md border border-sky-200 bg-sky-50 p-3 dark:border-sky-900 dark:bg-sky-950/30">
                        <p class="text-sm font-medium text-sky-900 dark:text-sky-100">
                            @if (! empty($snapshot['evidence_bank_transaction_id']))
                                {{ __('Reviewed against bank deposit #:id on :date.', ['id' => $snapshot['evidence_bank_transaction_id'], 'date' => $snapshot['settlement_date']]) }}
                            @else
                                {{ __('Reviewed against retained bank remittance on :date.', ['date' => $snapshot['settlement_date']]) }}
                            @endif
                        </p>
                        @can('gateway_settlements.post')
                            <label class="mt-3 flex min-h-11 items-center gap-2 text-sm text-neutral-800 dark:text-neutral-100">
                                <input type="checkbox" wire:model="confirm_posts.{{ $payoutKey }}" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500 dark:border-neutral-600 dark:bg-neutral-800" />
                                <span>{{ __('I confirm the gross sales, deductions, and net bank deposit shown above.') }}</span>
                            </label>
                            @error('confirm_posts.'.$payoutKey)<p role="alert" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror
                            <div class="mt-3 flex justify-end">
                                <flux:button type="button" variant="primary" wire:click="postPayout(@js($payoutReference))" wire:loading.attr="disabled" wire:target="postPayout" :disabled="! config('skipcash.settlements.enabled')">
                                    {{ __('Post settlement') }}
                                </flux:button>
                            </div>
                        @endcan
                    </div>
                @endif
            </article>
        @empty
            <div class="rounded-lg border border-dashed border-neutral-300 p-8 text-center text-sm text-neutral-600 dark:border-neutral-700 dark:text-neutral-300">{{ __('No payout reference was found in this report.') }}</div>
        @endforelse
    </section>

    <section aria-labelledby="report-rows-heading" class="space-y-3">
        <div>
            <h2 id="report-rows-heading" class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Report rows') }}</h2>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-300">{{ __('Customer phone evidence is kept private and is not shown here. Only verified RMS payments can be selected.') }}</p>
        </div>
        <div class="app-table-shell overflow-x-auto">
            <table class="w-full min-w-[1100px] divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Row') }}</th>
                        <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Type / payout') }}</th>
                        <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Reference') }}</th>
                        <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Branch / date') }}</th>
                        <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Gross') }}</th>
                        <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Deductions') }}</th>
                        <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Net') }}</th>
                        <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Payment match') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @foreach ($rows as $row)
                        <tr class="align-top hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                            <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $row->worksheet }}:{{ $row->physical_row }}</td>
                            <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                                <span class="font-medium text-neutral-900 dark:text-neutral-100">{{ str($row->order_type)->replace('_', ' ')->title() }}</span>
                                <span class="mt-0.5 block text-xs text-neutral-500 dark:text-neutral-400">{{ $row->payout_reference ?: '—' }}</span>
                            </td>
                            <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $row->row_reference ?: '—' }}</td>
                            <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                                {{ $row->branch?->name ?? $row->branch_code ?? '—' }}
                                <span class="mt-0.5 block text-xs text-neutral-500 dark:text-neutral-400">{{ $row->transaction_at?->format('Y-m-d H:i') ?? '—' }}</span>
                            </td>
                            <td class="px-3 py-3 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($row->gross_cents) }}</td>
                            <td class="px-3 py-3 text-right text-sm text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney(($row->total_commission_cents ?? 0) + ($row->settlement_fee_cents ?? 0)) }}</td>
                            <td class="px-3 py-3 text-right text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($row->net_cents) }}</td>
                            <td class="px-3 py-3 text-sm">
                                @if ($row->error_code)
                                    <span class="inline-flex rounded-full bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-800 dark:bg-rose-900/50 dark:text-rose-200">{{ str($row->error_code)->replace('_', ' ')->lower()->ucfirst() }}</span>
                                @elseif ($row->order_type === 'settlement_fee')
                                    <span class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('No customer payment required') }}</span>
                                @elseif ($row->match_state === 'duplicate')
                                    <p class="font-medium text-sky-700 dark:text-sky-300">{{ __('Retained duplicate evidence') }}</p>
                                    @if ($row->duplicateOf?->import)
                                        <a class="mt-1 inline-flex text-xs font-medium text-primary-700 hover:underline dark:text-primary-300" href="{{ route('accounting.ar-clearing.skipcash.show', $row->duplicateOf->import) }}" wire:navigate>
                                            {{ __('Open original row in report #:id', ['id' => $row->duplicateOf->import_id]) }}
                                        </a>
                                    @endif
                                @elseif ($row->match_state === 'matched')
                                    <p class="font-medium text-emerald-700 dark:text-emerald-300">{{ __('Payment #:id', ['id' => $row->providerTransaction?->payment_id]) }}</p>
                                    <p class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">{{ $row->providerTransaction?->payment?->customer?->name ?? __('Verified customer') }}</p>
                                    @if (! $settlementsByPayout->has($row->payout_reference))
                                        <div class="mt-2 flex gap-2">
                                            <input wire:model="unmatch_reasons.{{ $row->id }}" aria-label="{{ __('Reason to remove row :row match', ['row' => $row->row_sequence]) }}" placeholder="{{ __('Reason') }}" class="min-h-11 min-w-48 rounded-md border border-neutral-200 bg-white px-2 py-1.5 text-sm dark:border-neutral-700 dark:bg-neutral-800" />
                                            <flux:button size="xs" type="button" wire:click="unmatchRow({{ $row->id }})" wire:loading.attr="disabled" :disabled="! config('skipcash.settlements.enabled')">{{ __('Remove') }}</flux:button>
                                        </div>
                                        @error('unmatch_reasons.'.$row->id)<p role="alert" class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror
                                    @endif
                                @else
                                    @if (! $identifierMappingReady)
                                        <div class="mb-3 rounded-md border border-neutral-200 bg-neutral-50 p-3 dark:border-neutral-700 dark:bg-neutral-800/60">
                                            <p class="text-xs font-medium text-neutral-700 dark:text-neutral-200">{{ __('Retained provider reference evidence') }}</p>
                                            @if (($evidenceByRow[(string) $row->id] ?? collect())->isNotEmpty())
                                                <select wire:model="row_evidence.{{ $row->id }}" aria-label="{{ __('Provider evidence for row :row', ['row' => $row->row_sequence]) }}" class="mt-2 min-h-11 w-full rounded-md border border-neutral-200 bg-white px-2 py-1.5 text-sm text-neutral-800 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-50">
                                                    <option value="">{{ __('Choose retained evidence') }}</option>
                                                    @foreach ($evidenceByRow[(string) $row->id] as $evidence)
                                                        <option value="{{ $evidence['id'] }}">{{ $evidence['original_name'] }} · {{ str($evidence['provider_field'])->replace('_', ' ') }}</option>
                                                    @endforeach
                                                </select>
                                                <div class="mt-1 flex flex-wrap gap-2">
                                                    @foreach ($evidenceByRow[(string) $row->id] as $evidence)
                                                        <a class="text-xs font-medium text-primary-700 hover:underline dark:text-primary-300" href="{{ route('accounting.ar-clearing.skipcash.evidence.file', [$settlementImport, $evidence['id']]) }}">{{ __('Download :name', ['name' => $evidence['original_name']]) }}</a>
                                                    @endforeach
                                                </div>
                                            @endif
                                            <details class="mt-2">
                                                <summary class="cursor-pointer text-xs font-medium text-primary-700 dark:text-primary-300">{{ __('Upload provider proof') }}</summary>
                                                <div class="mt-3 grid gap-2">
                                                    <select wire:model="provider_fields.{{ $row->id }}" aria-label="{{ __('Provider field') }}" class="min-h-11 rounded-md border border-neutral-200 bg-white px-2 py-1.5 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                                        <option value="">{{ __('Choose provider field') }}</option>
                                                        <option value="provider_payment_id">{{ __('Provider payment ID') }}</option>
                                                        <option value="merchant_transaction_id">{{ __('Merchant transaction ID') }}</option>
                                                        <option value="visa_id">{{ __('Visa ID') }}</option>
                                                    </select>
                                                    <input wire:model="provider_values.{{ $row->id }}" aria-label="{{ __('Exact provider value') }}" placeholder="{{ __('Exact provider value') }}" class="min-h-11 rounded-md border border-neutral-200 bg-white px-2 py-1.5 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                                                    <input wire:model="evidence_files.{{ $row->id }}" aria-label="{{ __('Provider evidence file') }}" type="file" accept=".pdf,.png,.jpg,.jpeg,.xlsx" class="min-h-11 rounded-md border border-neutral-200 bg-white px-2 py-1.5 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                                                    <flux:button size="xs" type="button" wire:click="uploadRowEvidence({{ $row->id }})" wire:loading.attr="disabled" wire:target="uploadRowEvidence,evidence_files.{{ $row->id }}" :disabled="! config('skipcash.settlements.enabled')">{{ __('Retain evidence') }}</flux:button>
                                                    @error('provider_fields.'.$row->id)<p role="alert" class="text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror
                                                    @error('provider_values.'.$row->id)<p role="alert" class="text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror
                                                    @error('evidence_files.'.$row->id)<p role="alert" class="text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror
                                                </div>
                                            </details>
                                        </div>
                                        @error('row_evidence.'.$row->id)<p role="alert" class="mb-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror
                                    @endif
                                    <div class="flex gap-2">
                                        <select wire:model="row_selections.{{ $row->id }}" aria-label="{{ __('Verified payment for row :row', ['row' => $row->row_sequence]) }}" class="min-h-11 min-w-64 rounded-md border border-neutral-200 bg-white px-2 py-1.5 text-sm text-neutral-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                            <option value="">{{ __('Choose verified payment') }}</option>
                                            @foreach ($candidateOptions[$row->id] ?? [] as $candidate)
                                                @php
                                                    $candidateReasons = collect($candidate->settlement_candidate_reasons ?? [])->map(fn (string $reason) => match ($reason) {
                                                        'reference_value' => __('reference value'),
                                                        'phone' => __('phone'),
                                                        'date' => __('report date'),
                                                        default => $reason,
                                                    })->implode(', ');
                                                @endphp
                                                <option value="{{ $candidate->id }}">#{{ $candidate->payment_id }} · {{ $candidate->payment?->customer?->name ?? __('Customer') }} · {{ $candidate->verified_paid_at?->format('Y-m-d H:i') }}{{ $candidateReasons !== '' ? ' · '.__('suggested by :reasons', ['reasons' => $candidateReasons]) : '' }}</option>
                                            @endforeach
                                        </select>
                                        <flux:button size="xs" type="button" wire:click="matchRow({{ $row->id }})" wire:loading.attr="disabled" :disabled="! config('skipcash.settlements.enabled') || (! $identifierMappingReady && empty($row_evidence[(string) $row->id]))">{{ __('Match') }}</flux:button>
                                    </div>
                                    @error('row_selections.'.$row->id)<p role="alert" class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror
                                    @if (($candidateOptions[$row->id] ?? collect())->isEmpty())
                                        <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ __('No verified payment candidate has this source, company, branch, currency, and amount.') }}</p>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div>{{ $rows->links() }}</div>
    </section>
</div>
