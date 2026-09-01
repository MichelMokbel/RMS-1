<?php

namespace App\Services\Reports;

use App\Mail\ApJournalRangeMail;
use App\Mail\DailyApJournalMail;
use App\Models\AccountingCompany;
use App\Models\ApDailyJournalReport;
use App\Models\FinanceSetting;
use App\Services\Mail\EmailLogService;
use App\Services\Sequences\DocumentSequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DailyApJournalService
{
    public function __construct(
        private readonly ApReportService $reports,
        private readonly EmailLogService $emailLogs,
        private readonly DocumentSequenceService $sequences,
    ) {}

    public function generate(int $companyId, string $date): ApDailyJournalReport
    {
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();

        return DB::transaction(function () use ($companyId, $date) {
            // Lock the company even before the first report exists, serializing initial generation.
            $company = AccountingCompany::query()->lockForUpdate()->findOrFail($companyId);
            $report = ApDailyJournalReport::query()->where('company_id', $companyId)->where('report_date', $date)->lockForUpdate()->first();
            $filters = ['date_from' => $date, 'date_to' => $date, 'company_id' => $companyId];
            $snapshot = $this->reports->journal($companyId, $filters) + ['company' => $company->name, 'filters' => $filters, 'reportKey' => 'ap-journal'];
            $entryCount = (int) $snapshot['entryCount'];

            // MySQL JSON storage may reorder object keys. Compare the data, not key order.
            if ($report && $report->snapshot == $snapshot) {
                return $report;
            }

            $report ??= new ApDailyJournalReport(['company_id' => $companyId, 'report_date' => $date, 'email_status' => 'pending']);
            if (! $report->exists) {
                if (! Schema::hasTable('document_sequences')) {
                    throw new \RuntimeException(__('Document sequences must be installed before generating AP reports.'));
                }
                $year = substr($date, 0, 4);
                // Company namespace, like accounting journals; use the report year for backdated dates.
                $report->document_number = sprintf('APJ-%s-%04d', $year, $this->sequences->next('ap_daily_journal', $companyId, $year));
            }
            $report->fill(['snapshot' => $snapshot, 'entry_count' => $entryCount,
                'revision' => $report->exists ? $report->revision + 1 : 1, 'generated_at' => now()])->save();

            return $report;
        });
    }

    public function refreshChanged(): int
    {
        // AP entries are append only. Check for newly committed entries for any saved date.
        // Count committed entries instead of using the largest ID, because transactions can commit out of ID order.
        $changed = ApDailyJournalReport::query()->where(function ($query) {
            $query->where(function ($entries) {
                $entries->selectRaw('COUNT(*)')->from('subledger_entries as e')
                    ->whereColumn('e.company_id', 'ap_daily_journal_reports.company_id')
                    ->whereColumn('e.entry_date', 'ap_daily_journal_reports.report_date')
                    ->whereIn('e.source_type', ApReportService::JOURNAL_SOURCES)
                    ->where('e.status', 'posted')->whereNull('e.voided_at');
            }, '>', DB::raw('ap_daily_journal_reports.entry_count'))
                ->orWhereNull('snapshot->layoutVersion')
                ->orWhere('snapshot->layoutVersion', '<>', ApReportService::JOURNAL_LAYOUT_VERSION);
        })->get(['id', 'company_id', 'report_date']);

        foreach ($changed as $report) {
            $this->generate($report->company_id, $report->report_date->toDateString());
        }

        return $changed->count();
    }

    public function send(string $date, bool $retryFailed = false): string
    {
        $settings = FinanceSetting::query()->find(1);
        if (! $settings?->ap_report_enabled) {
            return 'disabled';
        }
        Validator::make($settings->only(['ap_report_email', 'ap_report_company_id']), [
            'ap_report_email' => ['required', 'email:rfc'],
            'ap_report_company_id' => ['required', 'integer', 'exists:accounting_companies,id'],
        ])->validate();

        $report = $this->generate($settings->ap_report_company_id, $date);
        $claimed = DB::transaction(function () use ($report, $settings, $retryFailed) {
            $locked = ApDailyJournalReport::query()->lockForUpdate()->findOrFail($report->id);
            if (! in_array($locked->email_status, $retryFailed ? ['pending', 'failed'] : ['pending'], true)) {
                return null;
            }
            $locked->fill(['email_status' => 'sending', 'recipient' => $settings->ap_report_email])->save();

            return $locked;
        });
        if (! $claimed) {
            return 'already-attempted';
        }

        $mail = new DailyApJournalMail($claimed->snapshot, $date, $claimed->revision, $claimed->generated_at, $claimed->document_number);
        try {
            // No external side effects inside the database transaction.
            Mail::to($claimed->recipient)->send($mail);
        } catch (\Throwable $exception) {
            $claimed->update(['email_status' => 'failed']);
            $this->log($claimed, $mail, 'failed', $exception);
            throw new \RuntimeException(__('AP journal delivery failed. Check email history and the mail provider before retrying.'));
        }

        // Keep a sending state if persistence fails after SMTP accepted the message, preventing automatic duplicates.
        $claimed->update(['email_status' => 'sent', 'sent_at' => now(), 'emailed_revision' => $claimed->revision]);
        $this->log($claimed, $mail, 'sent');

        return 'sent';
    }

    /**
     * Generate one saved report per accounting day, then email the range as one PDF.
     *
     * @return array{status: string, count: int, recipient: string}
     */
    public function sendRange(int $companyId, string $dateFrom, string $dateTo, int $actorId, bool $retryFailed = false): array
    {
        Validator::make([
            'company_id' => $companyId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ], [
            'company_id' => ['required', 'integer', 'exists:accounting_companies,id'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from', 'before_or_equal:today'],
        ])->validate();

        $from = CarbonImmutable::parse($dateFrom, config('app.timezone'))->startOfDay();
        $to = CarbonImmutable::parse($dateTo, config('app.timezone'))->startOfDay();
        $dayCount = (int) $from->diffInDays($to) + 1;
        if ($dayCount > 366) {
            throw ValidationException::withMessages([
                'date_to' => __('Select a range of 366 days or fewer.'),
            ]);
        }

        $settings = FinanceSetting::query()->find(1);
        if (! $settings?->ap_report_enabled) {
            throw ValidationException::withMessages([
                'delivery' => __('Enable the daily AP report email in Finance settings before sending a range.'),
            ]);
        }
        Validator::make($settings->only(['ap_report_email', 'ap_report_company_id']), [
            'ap_report_email' => ['required', 'email:rfc'],
            'ap_report_company_id' => ['required', 'integer', 'exists:accounting_companies,id'],
        ])->validate();
        if ((int) $settings->ap_report_company_id !== $companyId) {
            throw ValidationException::withMessages([
                'company_id' => __('Select the company configured for the daily AP report email.'),
            ]);
        }

        $company = AccountingCompany::query()->findOrFail($companyId);
        $reports = collect();
        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $reports->push($this->generate($companyId, $date->toDateString()));
        }

        $claim = DB::transaction(function () use ($reports, $settings, $retryFailed) {
            $locked = ApDailyJournalReport::query()
                ->whereIn('id', $reports->pluck('id'))
                ->orderBy('report_date')
                ->lockForUpdate()
                ->get();

            if ($locked->count() !== $reports->count()) {
                throw new \RuntimeException(__('One or more generated AP reports could not be claimed for delivery.'));
            }
            if ($locked->contains(fn (ApDailyJournalReport $report) => $report->email_status === 'sending')) {
                return ['status' => 'in-progress', 'reports' => $locked];
            }

            $needsDelivery = $locked->filter(fn (ApDailyJournalReport $report) => $report->recipient !== $settings->ap_report_email
                || $report->emailed_revision !== $report->revision
            );
            if ($needsDelivery->isEmpty()) {
                return ['status' => 'already-sent', 'reports' => $locked];
            }
            if (! $retryFailed && $needsDelivery->contains(fn (ApDailyJournalReport $report) => $report->email_status === 'failed')) {
                return ['status' => 'failed', 'reports' => $locked];
            }

            ApDailyJournalReport::query()->whereIn('id', $locked->pluck('id'))->update([
                'email_status' => 'sending',
                'recipient' => $settings->ap_report_email,
            ]);
            $locked->each(function (ApDailyJournalReport $report) use ($settings) {
                $report->email_status = 'sending';
                $report->recipient = $settings->ap_report_email;
            });

            return ['status' => 'claimed', 'reports' => $locked];
        });

        if ($claim['status'] !== 'claimed') {
            return [
                'status' => $claim['status'],
                'count' => $claim['reports']->count(),
                'recipient' => (string) $settings->ap_report_email,
            ];
        }

        $payload = $claim['reports']->map(fn (ApDailyJournalReport $report) => [
            'id' => $report->id,
            'date' => $report->report_date->toDateString(),
            'document_number' => $report->document_number,
            'revision' => $report->revision,
            'generated_at' => $report->generated_at,
            'snapshot' => $report->snapshot,
        ])->all();
        $mail = new ApJournalRangeMail(
            reports: $payload,
            companyId: $companyId,
            company: $company->name,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            generatedAt: now(),
        );

        try {
            Mail::to($settings->ap_report_email)->send($mail);
        } catch (\Throwable $exception) {
            ApDailyJournalReport::query()->whereIn('id', collect($payload)->pluck('id'))->where('email_status', 'sending')->update([
                'email_status' => 'failed',
            ]);
            $this->logRange($mail, $payload, (string) $settings->ap_report_email, $actorId, 'failed', $exception);
            throw new \RuntimeException(__('AP journal range delivery failed. Check email history and the mail provider before retrying.'));
        }

        // If persistence fails after SMTP accepts the email, the sending state remains and blocks a duplicate retry.
        DB::transaction(function () use ($payload) {
            foreach ($payload as $item) {
                $report = ApDailyJournalReport::query()->lockForUpdate()->findOrFail($item['id']);
                if ($report->email_status === 'sending') {
                    $report->update([
                        'email_status' => 'sent',
                        'sent_at' => now(),
                        'emailed_revision' => $item['revision'],
                    ]);
                }
            }
        });
        $this->logRange($mail, $payload, (string) $settings->ap_report_email, $actorId, 'sent');

        return [
            'status' => 'sent',
            'count' => count($payload),
            'recipient' => (string) $settings->ap_report_email,
        ];
    }

    private function log(ApDailyJournalReport $report, DailyApJournalMail $mail, string $status, ?\Throwable $exception = null): void
    {
        $this->emailLogs->log(category: 'ap_daily_journal', recipientType: 'finance', status: $status, mailable: $mail,
            toRecipients: [$report->recipient], mailer: config('mail.default'),
            context: ['report_id' => $report->id, 'document_number' => $report->document_number, 'report_date' => $report->report_date->toDateString(), 'revision' => $report->revision,
                'error_class' => $exception ? $exception::class : null]);
    }

    /** @param array<int, array<string, mixed>> $reports */
    private function logRange(ApJournalRangeMail $mail, array $reports, string $recipient, int $actorId, string $status, ?\Throwable $exception = null): void
    {
        $this->emailLogs->log(category: 'ap_journal_range', recipientType: 'finance', status: $status, mailable: $mail,
            toRecipients: [$recipient], userId: $actorId, mailer: config('mail.default'),
            context: [
                'company_id' => $mail->companyId,
                'date_from' => $mail->dateFrom,
                'date_to' => $mail->dateTo,
                'report_ids' => collect($reports)->pluck('id')->all(),
                'document_numbers' => collect($reports)->pluck('document_number')->all(),
                'revisions' => collect($reports)->pluck('revision')->all(),
                'error_class' => $exception ? $exception::class : null,
            ]);
    }
}
