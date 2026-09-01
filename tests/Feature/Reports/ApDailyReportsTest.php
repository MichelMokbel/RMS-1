<?php

use App\Mail\ApJournalRangeMail;
use App\Mail\DailyApJournalMail;
use App\Models\AccountingCompany;
use App\Models\ApDailyJournalReport;
use App\Models\ApInvoice;
use App\Models\Branch;
use App\Models\EmailLog;
use App\Models\ExpenseCategory;
use App\Models\FinanceSetting;
use App\Models\LedgerAccount;
use App\Models\SubledgerEntry;
use App\Models\SubledgerLine;
use App\Models\User;
use App\Services\Finance\ApReportSettingsService;
use App\Services\Reports\ApReportService;
use App\Services\Reports\DailyApJournalService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-31 17:00:00', config('app.timezone')));
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('manager', 'web');
    $this->admin = User::factory()->create(['status' => 'active']);
    $this->admin->assignRole('admin');
    $this->manager = User::factory()->create(['status' => 'active']);
    $this->manager->assignRole('manager');
    $this->company = AccountingCompany::create(['name' => 'AP Report Company', 'code' => 'APR', 'base_currency' => 'QAR', 'is_active' => true]);
    $this->otherCompany = AccountingCompany::create(['name' => 'Other Report Company', 'code' => 'APX', 'base_currency' => 'QAR', 'is_active' => true]);
    $this->branch = Branch::create(['name' => 'Report Branch', 'code' => 'RPT1', 'company_id' => $this->company->id, 'is_active' => true]);
    $this->otherBranch = Branch::create(['name' => 'Forbidden Branch', 'code' => 'RPT2', 'company_id' => $this->company->id, 'is_active' => true]);
    DB::table('user_branch_access')->insert(['user_id' => $this->manager->id, 'branch_id' => $this->branch->id]);
    $this->filters = ['company_id' => $this->company->id, 'date_from' => '2026-08-31', 'date_to' => '2026-08-31'];
    $this->accounts = LedgerAccount::factory()->count(2)->create(['company_id' => $this->company->id]);
    $this->mailManager = app('mail.manager');
    Mail::fake();
});

function apDailyTestEntry($test, array $attributes = [], string $amount = '12.3400'): SubledgerEntry
{
    $entry = SubledgerEntry::forceCreate($attributes + [
        'company_id' => $test->company->id, 'branch_id' => $test->branch->id,
        'source_type' => 'ap_invoice', 'source_id' => random_int(100000, 999999999), 'event' => 'post',
        'entry_date' => '2026-08-31', 'posted_at' => now(), 'status' => 'posted', 'currency_code' => 'QAR', 'description' => 'AP journal fixture',
    ]);
    SubledgerLine::create(['entry_id' => $entry->id, 'account_id' => $test->accounts[0]->id, 'debit' => $amount, 'credit' => 0]);
    SubledgerLine::create(['entry_id' => $entry->id, 'account_id' => $test->accounts[1]->id, 'debit' => 0, 'credit' => $amount]);

    return $entry;
}

function enableApDailyEmail($test): void
{
    app(ApReportSettingsService::class)->save(['ap_report_enabled' => true, 'ap_report_email' => 'accounts@example.test', 'ap_report_company_id' => $test->company->id], $test->admin);
}

it('groups all posted expenses by category and currency with identical filtered exports', function () {
    $category = ExpenseCategory::factory()->create(['name' => 'Utilities']);
    $base = ['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'is_expense' => true,
        'status' => 'posted', 'category_id' => $category->id, 'invoice_date' => '2026-08-31', 'currency_code' => 'QAR', 'tax_amount' => '0.00'];
    ApInvoice::factory()->create(['subtotal' => '0.10', 'total_amount' => '0.10'] + $base);
    ApInvoice::factory()->create(['subtotal' => '0.20', 'total_amount' => '0.20', 'status' => 'paid'] + $base);
    ApInvoice::factory()->create(['subtotal' => '2.00', 'tax_amount' => '0.10', 'total_amount' => '2.10', 'currency_code' => 'USD'] + $base);
    ApInvoice::factory()->create(['category_id' => null, 'subtotal' => '50.00', 'total_amount' => '50.00', 'status' => 'partially_paid'] + $base);
    foreach ([['status' => 'void'], ['status' => 'draft'], ['is_expense' => false], ['invoice_date' => '2026-08-30'],
        ['branch_id' => $this->otherBranch->id], ['company_id' => $this->otherCompany->id]] as $override) {
        ApInvoice::factory()->create($override + ['subtotal' => '999.00', 'total_amount' => '999.00'] + $base);
    }
    $response = $this->actingAs($this->manager)->get(route('reports.expenses-by-category', $this->filters))->assertOk();
    $expected = [['Total', 'QAR', 3, '50.300', '0.000', '50.300'], ['Total', 'USD', 1, '2.000', '0.100', '2.100']];
    $response->assertViewHas('totals', $expected)->assertSee('Uncategorized')->assertSee('Utilities')->assertDontSee('999.000');
    $this->get(route('reports.expenses-by-category.print', $this->filters))->assertOk()->assertViewHas('totals', $expected);
    $csv = $this->get(route('reports.expenses-by-category.csv', $this->filters))->assertOk()->streamedContent();
    expect($csv)->toContain('Total,QAR,3,50.300,0.000,50.300')->not->toContain('999.000');
    $this->get(route('reports.expenses-by-category.pdf', $this->filters))->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('includes every AP source and reversal without including other dates companies or branches', function () {
    foreach (ApReportService::JOURNAL_SOURCES as $source) {
        apDailyTestEntry($this, ['source_type' => $source, 'description' => $source]);
    }
    $original = apDailyTestEntry($this, ['source_id' => 55, 'description' => 'Original remains visible']);
    $reversal = SubledgerEntry::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'source_type' => 'ap_invoice',
        'source_id' => 55, 'event' => 'void', 'entry_date' => '2026-08-31', 'posted_at' => now(), 'status' => 'posted', 'currency_code' => 'QAR', 'description' => 'Reversal remains visible']);
    foreach ($original->lines as $line) {
        SubledgerLine::create(['entry_id' => $reversal->id, 'account_id' => $line->account_id, 'debit' => $line->credit, 'credit' => $line->debit]);
    }
    foreach ([['source_type' => 'ar_invoice'], ['entry_date' => '2026-08-30'], ['entry_date' => '2026-09-01'], ['status' => 'draft'],
        ['voided_at' => now()], ['company_id' => $this->otherCompany->id], ['branch_id' => $this->otherBranch->id], ['branch_id' => null]] as $override) {
        apDailyTestEntry($this, $override + ['description' => 'Excluded journal']);
    }
    $response = $this->actingAs($this->manager)->get(route('reports.ap-journal', $this->filters))->assertOk();
    $response->assertSee('Original remains visible')->assertSee('Reversal remains visible')->assertDontSee('Excluded journal');
    expect($response->viewData('rows'))->toHaveCount(12);
    expect(array_slice($response->viewData('totals')[0], -2))->toBe(['74.0400', '74.0400']);
    $this->get(route('reports.ap-journal.print', $this->filters))->assertOk()->assertDontSee('Excluded journal');
    $csv = $this->get(route('reports.ap-journal.csv', $this->filters))->assertOk()->streamedContent();
    expect($csv)->toContain('74.0400,74.0400')->not->toContain('Excluded journal');
});

it('protects every report output and rejects unauthorized company and branch filters', function (string $report, string $suffix) {
    $url = route('reports.'.$report.$suffix, $this->filters);
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['status' => 'active']))->get($url)->assertForbidden();
    $this->actingAs($this->manager)->get(route('reports.'.$report.$suffix, ['company_id' => $this->otherCompany->id] + $this->filters))->assertForbidden();
    $this->get(route('reports.'.$report.$suffix, ['branch_id' => $this->otherBranch->id] + $this->filters))->assertForbidden();
})->with(['ap-journal', 'expenses-by-category'])->with(['', '.print', '.csv', '.pdf']);

it('validates dates and company branch consistency', function () {
    $this->actingAs($this->admin)->getJson(route('reports.ap-journal', ['date_from' => 'invalid'] + $this->filters))->assertUnprocessable()->assertJsonValidationErrors('date_from');
    $this->getJson(route('reports.ap-journal', ['date_to' => '2026-08-01'] + $this->filters))->assertUnprocessable()->assertJsonValidationErrors('date_to');
    $this->getJson(route('reports.ap-journal', ['company_id' => $this->otherCompany->id, 'branch_id' => $this->branch->id] + $this->filters))->assertUnprocessable()->assertJsonValidationErrors('branch_id');
});

it('sends a calendar day report once then regenerates the same report for late and backdated additions without another email', function () {
    enableApDailyEmail($this);
    apDailyTestEntry($this, ['posted_at' => '2026-08-31 00:00:00', 'description' => 'Midnight today']);
    apDailyTestEntry($this, ['entry_date' => '2026-08-30', 'posted_at' => '2026-08-30 23:59:59', 'description' => 'Yesterday excluded']);
    apDailyTestEntry($this, ['company_id' => $this->otherCompany->id, 'description' => 'Other company excluded']);
    $this->artisan('reports:send-ap-journal')->assertSuccessful();
    $report = ApDailyJournalReport::sole();
    expect($report->email_status)->toBe('sent')->and($report->revision)->toBe(1)->and($report->snapshot['rows'])->toHaveCount(2)
        ->and($report->document_number)->toBe('APJ-2026-0001');
    Mail::assertSent(DailyApJournalMail::class, fn ($mail) => $mail->hasTo('accounts@example.test') && $mail->reportDate === '2026-08-31' && count($mail->snapshot['rows']) === 2 && $mail->documentNumber === 'APJ-2026-0001');
    expect(EmailLog::where('category', 'ap_daily_journal')->where('status', 'sent')->count())->toBe(1);
    $this->travelTo(now()->setTime(23, 59, 59));
    apDailyTestEntry($this, ['description' => 'Late same day']);
    $this->artisan('reports:refresh-ap-journals')->assertSuccessful();
    expect($report->fresh()->revision)->toBe(2)->and($report->fresh()->snapshot['rows'])->toHaveCount(4)->and($report->fresh()->emailed_revision)->toBe(1);
    $this->artisan('reports:send-ap-journal')->assertSuccessful();
    $this->artisan('reports:refresh-ap-journals')->assertSuccessful();
    expect($report->fresh()->revision)->toBe(2)->and(ApDailyJournalReport::count())->toBe(1);
    Mail::assertSentCount(1);
    $this->travelTo(now()->addDay()->setTime(10, 0));
    apDailyTestEntry($this, ['description' => 'Backdated addition']);
    $this->artisan('reports:refresh-ap-journals')->assertSuccessful();
    expect($report->fresh()->revision)->toBe(3)->and($report->fresh()->snapshot['rows'])->toHaveCount(6);
    expect($report->fresh()->document_number)->toBe('APJ-2026-0001');
    $this->artisan('reports:send-ap-journal')->assertSuccessful();
    $next = ApDailyJournalReport::whereDate('report_date', '2026-09-01')->sole();
    expect($next->snapshot['rows'])->toBe([])->and($next->document_number)->toBe('APJ-2026-0002');
    Mail::assertSentCount(2);
});

it('does nothing when disabled and rejects incomplete enabled settings before sending', function () {
    $this->artisan('reports:send-ap-journal')->assertSuccessful();
    expect(ApDailyJournalReport::count())->toBe(0);
    FinanceSetting::query()->firstOrCreate(['id' => 1])->update(['ap_report_enabled' => true]);
    expect(fn () => app(DailyApJournalService::class)->send('2026-08-31'))->toThrow(\Illuminate\Validation\ValidationException::class);
    Mail::assertNothingSent();
});

it('records failed delivery and only retries it when explicitly requested', function () {
    enableApDailyEmail($this);
    apDailyTestEntry($this);
    Mail::shouldReceive('to')->once()->with('accounts@example.test')->andReturnSelf();
    Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Provider failure with sensitive payload'));
    expect(fn () => app(DailyApJournalService::class)->send('2026-08-31'))->toThrow(\RuntimeException::class);
    expect(ApDailyJournalReport::sole()->email_status)->toBe('failed');
    expect(EmailLog::where('status', 'failed')->sole()->error_message)->toBeNull();
    Mail::swap($this->mailManager);
    Mail::fake();
    $this->artisan('reports:send-ap-journal')->assertSuccessful();
    Mail::assertNothingSent();
    $this->artisan('reports:send-ap-journal', ['--retry-failed' => true])->assertSuccessful();
    Mail::assertSentCount(1);
    expect(ApDailyJournalReport::sole()->email_status)->toBe('sent')->and(ApDailyJournalReport::sole()->document_number)->toBe('APJ-2026-0001');
    Mail::assertSent(DailyApJournalMail::class, fn ($mail) => $mail->documentNumber === 'APJ-2026-0001');
});

it('does not resend an in flight or uncertain delivery', function () {
    enableApDailyEmail($this);
    app(DailyApJournalService::class)->generate($this->company->id, '2026-08-31')->update(['email_status' => 'sending']);
    $this->artisan('reports:send-ap-journal', ['--retry-failed' => true])->assertSuccessful();
    Mail::assertNothingSent();
});

it('allows only admins to configure validated report delivery and preserves existing finance settings', function () {
    FinanceSetting::query()->firstOrCreate(['id' => 1])->update(['default_company_id' => $this->otherCompany->id, 'lock_date' => '2026-08-01']);
    Volt::actingAs($this->admin)->test('finance.settings')
        ->set('ap_report_enabled', true)->set('ap_report_email', 'invalid')->set('ap_report_company_id', $this->company->id)
        ->call('saveReportSettings')->assertHasErrors('ap_report_email')
        ->set('ap_report_email', 'accounts@example.test')->call('saveReportSettings')->assertHasNoErrors();
    $settings = FinanceSetting::find(1);
    expect($settings->ap_report_enabled)->toBeTrue()->and($settings->ap_report_company_id)->toBe($this->company->id)
        ->and($settings->default_company_id)->toBe($this->otherCompany->id)->and($settings->lock_date->toDateString())->toBe('2026-08-01');
    Volt::actingAs($this->manager)->test('finance.settings')->assertForbidden();
    expect(fn () => app(ApReportSettingsService::class)->save(['ap_report_enabled' => false], $this->manager))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('registers a 5 pm application timezone delivery and minute refresh schedule', function () {
    $this->artisan('schedule:list')->assertSuccessful();
    $events = collect(app(Schedule::class)->events());
    $send = $events->first(fn ($event) => str_contains($event->command ?? '', 'reports:send-ap-journal'));
    $refresh = $events->first(fn ($event) => str_contains($event->command ?? '', 'reports:refresh-ap-journals'));
    expect($send)->not->toBeNull()->and($send->expression)->toBe('0 17 * * *')->and($send->timezone)->toBe(config('app.timezone'))
        ->and($send->withoutOverlapping)->toBeTrue()->and($refresh->expression)->toBe('* * * * *');
});

it('regenerates when an earlier allocated entry id commits after the initial report', function () {
    apDailyTestEntry($this, ['id' => 90002]);
    $service = app(DailyApJournalService::class);
    $report = $service->generate($this->company->id, '2026-08-31');
    apDailyTestEntry($this, ['id' => 90001, 'description' => 'Committed after a larger id']);
    expect($service->refreshChanged())->toBe(1);
    expect($report->fresh()->revision)->toBe(2)->and($report->fresh()->entry_count)->toBe(2);
});

it('does not regenerate merely because MySQL reordered stored JSON keys', function () {
    apDailyTestEntry($this);
    $service = app(DailyApJournalService::class);
    $report = $service->generate($this->company->id, '2026-08-31');
    $snapshot = $report->snapshot;
    ksort($snapshot);
    $report->update(['snapshot' => $snapshot]);
    expect($service->generate($this->company->id, '2026-08-31')->revision)->toBe(1);
});

it('preserves the generation timestamp when an unchanged report is emailed later', function () {
    enableApDailyEmail($this);
    $this->travelTo(now()->setTime(14, 0));
    apDailyTestEntry($this);
    $report = app(DailyApJournalService::class)->generate($this->company->id, '2026-08-31');
    $this->travelTo(now()->setTime(17, 0));
    $this->artisan('reports:send-ap-journal')->assertSuccessful();
    expect($report->fresh()->generated_at->format('H:i'))->toBe('14:00')
        ->and($report->fresh()->sent_at->format('H:i'))->toBe('17:00');
});

it('aggregates beyond the legacy 5000 row cap and neutralizes spreadsheet formulas', function () {
    $category = ExpenseCategory::factory()->create(['name' => '=1+1']);
    $invoice = ApInvoice::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'is_expense' => true, 'status' => 'posted', 'category_id' => $category->id, 'invoice_date' => '2026-08-31',
        'currency_code' => 'QAR', 'subtotal' => '0.10', 'tax_amount' => 0, 'total_amount' => '0.10']);
    $template = $invoice->getAttributes();
    unset($template['id']);
    foreach (array_chunk(range(1, 5001), 500) as $chunk) {
        DB::table('ap_invoices')->insert(array_map(fn ($i) => ['invoice_number' => 'BULK-'.$i] + $template, $chunk));
    }
    $response = $this->actingAs($this->manager)->get(route('reports.expenses-by-category', $this->filters))->assertOk();
    expect($response->viewData('totals'))->toBe([['Total', 'QAR', 5002, '500.200', '0.000', '500.200']]);
    $csv = $this->get(route('reports.expenses-by-category.csv', $this->filters))->assertOk()->streamedContent();
    expect($csv)->toContain("'=1+1")->toContain('5002,500.200,0.000,500.200');
});

it('renders the daily email and complete journal PDF attachment', function () {
    apDailyTestEntry($this, ['description' => 'Attachment verification']);
    $report = app(DailyApJournalService::class)->generate($this->company->id, '2026-08-31');
    $mail = new DailyApJournalMail($report->snapshot, '2026-08-31', 1, $report->generated_at, $report->document_number);
    expect($mail->render())->toContain('2026-08-31')->toContain('AP Report Company')->toContain('View current daily report')->toContain('APJ-2026-0001');
    expect($mail->envelope()->subject)->toContain('APJ-2026-0001');
    $sent = $this->mailManager->mailer('array')->send((new DailyApJournalMail($report->snapshot, '2026-08-31', 1, $report->generated_at, $report->document_number))->to('accounts@example.test'));
    $attachments = $sent->getSymfonySentMessage()->getOriginalMessage()->getAttachments();
    expect($attachments)->toHaveCount(1);
    expect($attachments[0]->getFilename())->toBe('APJ-2026-0001.pdf')->and($attachments[0]->getBody())->toStartWith('%PDF');
});

it('generates each selected day in order and emails the range as one PDF', function () {
    enableApDailyEmail($this);
    foreach (['2026-08-29', '2026-08-30', '2026-08-31'] as $date) {
        apDailyTestEntry($this, ['entry_date' => $date, 'description' => 'Range '.$date]);
    }
    $filters = ['company_id' => $this->company->id, 'date_from' => '2026-08-29', 'date_to' => '2026-08-31'];

    $this->actingAs($this->admin)->post(route('reports.ap-journal.generate-email'), $filters)
        ->assertRedirect(route('reports.ap-journal', $filters))
        ->assertSessionHas('status');

    $reports = ApDailyJournalReport::query()->orderBy('report_date')->get();
    expect($reports)->toHaveCount(3)
        ->and($reports->pluck('document_number')->all())->toBe(['APJ-2026-0001', 'APJ-2026-0002', 'APJ-2026-0003'])
        ->and($reports->pluck('entry_count')->all())->toBe([1, 1, 1])
        ->and($reports->pluck('email_status')->unique()->all())->toBe(['sent'])
        ->and($reports->pluck('emailed_revision')->all())->toBe([1, 1, 1]);
    Mail::assertSentCount(1);
    Mail::assertNotSent(DailyApJournalMail::class);
    Mail::assertSent(ApJournalRangeMail::class, function (ApJournalRangeMail $mail) {
        return $mail->hasTo('accounts@example.test')
            && $mail->dateFrom === '2026-08-29'
            && $mail->dateTo === '2026-08-31'
            && collect($mail->reports)->pluck('document_number')->all() === ['APJ-2026-0001', 'APJ-2026-0002', 'APJ-2026-0003'];
    });
    expect(EmailLog::where('category', 'ap_journal_range')->where('status', 'sent')->count())->toBe(1);

    $this->post(route('reports.ap-journal.generate-email'), $filters)->assertSessionHas('status');
    Mail::assertSentCount(1);
    expect(ApDailyJournalReport::count())->toBe(3);

    apDailyTestEntry($this, ['entry_date' => '2026-08-30', 'description' => 'Late range addition']);
    $this->post(route('reports.ap-journal.generate-email'), $filters)->assertSessionHas('status');
    Mail::assertSentCount(2);
    expect(ApDailyJournalReport::whereDate('report_date', '2026-08-30')->sole()->revision)->toBe(2)
        ->and(ApDailyJournalReport::whereDate('report_date', '2026-08-30')->sole()->emailed_revision)->toBe(2);
});

it('renders one combined PDF attachment for a generated AP journal range', function () {
    foreach (['2026-08-30', '2026-08-31'] as $date) {
        apDailyTestEntry($this, ['entry_date' => $date, 'description' => 'Combined attachment '.$date]);
    }
    $service = app(DailyApJournalService::class);
    $reports = collect([
        $service->generate($this->company->id, '2026-08-30'),
        $service->generate($this->company->id, '2026-08-31'),
    ])->map(fn (ApDailyJournalReport $report) => [
        'id' => $report->id,
        'date' => $report->report_date->toDateString(),
        'document_number' => $report->document_number,
        'revision' => $report->revision,
        'generated_at' => $report->generated_at,
        'snapshot' => $report->snapshot,
    ])->all();
    $mail = new ApJournalRangeMail($reports, $this->company->id, $this->company->name, '2026-08-30', '2026-08-31', now());

    expect($mail->render())->toContain('2 daily reports were generated')->toContain('APJ-2026-0001')->toContain('APJ-2026-0002');
    $sent = $this->mailManager->mailer('array')->send(
        (new ApJournalRangeMail($reports, $this->company->id, $this->company->name, '2026-08-30', '2026-08-31', now()))->to('accounts@example.test')
    );
    $attachments = $sent->getSymfonySentMessage()->getOriginalMessage()->getAttachments();
    expect($attachments)->toHaveCount(1)
        ->and($attachments[0]->getFilename())->toBe('AP-journals-2026-08-30-to-2026-08-31.pdf')
        ->and($attachments[0]->getBody())->toStartWith('%PDF');
});

it('allows only admins to generate company wide daily AP report ranges', function () {
    enableApDailyEmail($this);
    $filters = ['company_id' => $this->company->id, 'date_from' => '2026-08-31', 'date_to' => '2026-08-31'];

    $this->actingAs($this->manager)->get(route('reports.ap-journal', $filters))
        ->assertOk()
        ->assertDontSee('Generate daily reports and email one PDF');
    $this->post(route('reports.ap-journal.generate-email'), $filters)->assertForbidden();

    $this->actingAs($this->admin)->get(route('reports.ap-journal', $filters))
        ->assertOk()
        ->assertSee('Generate daily reports and email one PDF')
        ->assertSee('accounts@example.test');
    $this->post(route('reports.ap-journal.generate-email'), ['company_id' => $this->otherCompany->id] + $filters)
        ->assertSessionHasErrors('company_id');
    Mail::assertNothingSent();
});

it('rejects a historical AP journal email range longer than one year', function () {
    enableApDailyEmail($this);
    $this->actingAs($this->admin)->post(route('reports.ap-journal.generate-email'), [
        'company_id' => $this->company->id,
        'date_from' => '2025-08-30',
        'date_to' => '2026-08-31',
    ])->assertSessionHasErrors('date_to');

    expect(ApDailyJournalReport::count())->toBe(0);
    Mail::assertNothingSent();
});

it('requires an explicit retry after a combined range email fails', function () {
    enableApDailyEmail($this);
    apDailyTestEntry($this);
    $filters = ['company_id' => $this->company->id, 'date_from' => '2026-08-31', 'date_to' => '2026-08-31'];
    Mail::shouldReceive('to')->once()->with('accounts@example.test')->andReturnSelf();
    Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Provider failure with sensitive payload'));

    $this->actingAs($this->admin)->post(route('reports.ap-journal.generate-email'), $filters)
        ->assertSessionHas('error')
        ->assertSessionHas('ap_range_retry_available', true);
    expect(ApDailyJournalReport::sole()->email_status)->toBe('failed')
        ->and(EmailLog::where('category', 'ap_journal_range')->where('status', 'failed')->sole()->error_message)->toBeNull();

    Mail::swap($this->mailManager);
    Mail::fake();
    $this->post(route('reports.ap-journal.generate-email'), $filters)
        ->assertSessionHas('ap_range_retry_available', true);
    Mail::assertNothingSent();

    $this->post(route('reports.ap-journal.generate-email'), $filters + ['retry_failed' => true])
        ->assertSessionHas('status');
    Mail::assertSentCount(1);
    expect(ApDailyJournalReport::sole()->email_status)->toBe('sent')
        ->and(ApDailyJournalReport::sole()->document_number)->toBe('APJ-2026-0001');
});

it('numbers generated documents sequentially per company and accounting year without consuming numbers on regeneration', function () {
    $service = app(DailyApJournalService::class);
    $first = $service->generate($this->company->id, '2026-08-31');
    expect($first->document_number)->toBe('APJ-2026-0001');
    expect($service->generate($this->company->id, '2026-08-31')->id)->toBe($first->id);
    expect($service->generate($this->company->id, '2026-09-01')->document_number)->toBe('APJ-2026-0002');
    expect($service->generate($this->company->id, '2026-08-01')->document_number)->toBe('APJ-2026-0003');
    expect($service->generate($this->otherCompany->id, '2026-08-31')->document_number)->toBe('APJ-2026-0001');
    expect($service->generate($this->company->id, '2027-01-01')->document_number)->toBe('APJ-2027-0001');
    expect($service->generate($this->company->id, '2025-12-31')->document_number)->toBe('APJ-2025-0001');
    expect(DB::table('document_sequences')->where('branch_id', $this->company->id)->where('type', 'ap_daily_journal')->where('year', '2026')->value('next_number'))->toBe(4);
});

it('allocates the report and its number atomically and rejects duplicate document numbers', function () {
    $service = app(DailyApJournalService::class);
    expect(fn () => DB::transaction(function () use ($service) {
        $service->generate($this->company->id, '2026-08-31');
        throw new \RuntimeException('Rollback report generation');
    }))->toThrow(\RuntimeException::class, 'Rollback report generation');
    expect(ApDailyJournalReport::count())->toBe(0);
    expect(DB::table('document_sequences')->where('type', 'ap_daily_journal')->count())->toBe(0);
    $first = $service->generate($this->company->id, '2026-08-31');
    $second = $service->generate($this->company->id, '2026-09-01');
    expect($first->document_number)->toBe('APJ-2026-0001')->and($second->document_number)->toBe('APJ-2026-0002');
    expect(fn () => DB::table('ap_daily_journal_reports')->where('id', $second->id)->update(['document_number' => $first->document_number]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('refuses to generate an unsequenced document when the sequence table is unavailable', function () {
    Schema::shouldReceive('hasTable')->once()->with('document_sequences')->andReturn(false);
    expect(fn () => app(DailyApJournalService::class)->generate($this->company->id, '2026-08-31'))
        ->toThrow(\RuntimeException::class, 'Document sequences must be installed');
    expect(ApDailyJournalReport::count())->toBe(0);
    Mail::assertNothingSent();
});

it('shows saved document numbers in scoped daily and range exports without allocating numbers on reads', function () {
    apDailyTestEntry($this, ['description' => 'Allowed numbered entry']);
    apDailyTestEntry($this, ['branch_id' => $this->otherBranch->id, 'description' => 'Private numbered entry']);
    apDailyTestEntry($this, ['entry_date' => '2026-09-01']);
    $this->actingAs($this->manager)->get(route('reports.ap-journal', $this->filters))->assertOk()->assertViewHas('documentNumber', null);
    expect(ApDailyJournalReport::count())->toBe(0);
    $service = app(DailyApJournalService::class);
    $service->generate($this->company->id, '2026-08-31');
    $service->generate($this->company->id, '2026-09-01');
    $service->generate($this->otherCompany->id, '2026-08-31');
    $response = $this->get(route('reports.ap-journal', $this->filters))->assertOk()
        ->assertViewHas('documentNumber', 'APJ-2026-0001')->assertSee('APJ-2026-0001')->assertDontSee('Private numbered entry');
    expect($response->viewData('rows'))->toHaveCount(2);
    $this->get(route('reports.ap-journal.print', $this->filters))->assertOk()->assertSee('APJ-2026-0001')->assertDontSee('Private numbered entry');
    $this->get(route('reports.ap-journal.pdf', $this->filters))->assertOk()->assertDownload('APJ-2026-0001.pdf');
    $csv = $this->get(route('reports.ap-journal.csv', $this->filters))->assertOk()->assertDownload('APJ-2026-0001.csv')->streamedContent();
    expect($csv)->toContain('APJ-2026-0001')->not->toContain('Private numbered entry');
    $range = ['date_to' => '2026-09-01'] + $this->filters;
    $csv = $this->get(route('reports.ap-journal.csv', $range))->assertOk()->streamedContent();
    expect($csv)->toContain('APJ-2026-0001')->toContain('APJ-2026-0002')->not->toContain('Private numbered entry');
    $this->get(route('reports.ap-journal', ['date_from' => '2026-09-02', 'date_to' => '2026-09-02'] + $this->filters))->assertOk()->assertViewHas('documentNumber', null);
    expect(ApDailyJournalReport::count())->toBe(3);
});
