<?php

namespace App\Services\Reports;

use App\Mail\DailyApJournalMail;
use App\Models\AccountingCompany;
use App\Models\ApDailyJournalReport;
use App\Models\FinanceSetting;
use App\Services\Mail\EmailLogService;
use App\Services\Sequences\DocumentSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

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
            $entryCount = collect($snapshot['rows'])->pluck(0)->unique()->count();

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
            $query->selectRaw('COUNT(*)')->from('subledger_entries as e')
                ->whereColumn('e.company_id', 'ap_daily_journal_reports.company_id')
                ->whereColumn('e.entry_date', 'ap_daily_journal_reports.report_date')
                ->whereIn('e.source_type', ApReportService::JOURNAL_SOURCES)
                ->where('e.status', 'posted')->whereNull('e.voided_at');
        }, '>', DB::raw('ap_daily_journal_reports.entry_count'))->get(['id', 'company_id', 'report_date']);

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

    private function log(ApDailyJournalReport $report, DailyApJournalMail $mail, string $status, ?\Throwable $exception = null): void
    {
        $this->emailLogs->log(category: 'ap_daily_journal', recipientType: 'finance', status: $status, mailable: $mail,
            toRecipients: [$report->recipient], mailer: config('mail.default'),
            context: ['report_id' => $report->id, 'document_number' => $report->document_number, 'report_date' => $report->report_date->toDateString(), 'revision' => $report->revision,
                'error_class' => $exception ? $exception::class : null]);
    }
}
