<?php

use App\Models\AccountingAuditLog;
use App\Models\AccountingCompany;
use App\Models\MailSetting;
use App\Models\User;
use App\Services\Mail\MailConfigurationUnavailableException;
use App\Services\Mail\MailSettingsConflictException;
use App\Services\Mail\MailSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('staff', 'web');
    Role::findOrCreate('customer', 'web');

    $this->admin = User::factory()->create(['status' => 'active']);
    $this->admin->assignRole('admin');
    $this->company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
});

function freshMailSettingsService(): MailSettingsService
{
    app()->forgetInstance(MailSettingsService::class);

    return app(MailSettingsService::class);
}

/** @return array<string, mixed> */
function validMailSettings(array $overrides = []): array
{
    return array_merge([
        'smtp_host' => 'smtp.mail.example',
        'smtp_port' => 587,
        'security_mode' => 'starttls',
        'from_address' => 'orders@example.test',
        'from_name' => 'Layla Kitchen',
        'smtp_username' => '',
        'smtp_password' => '',
        'daily_dish_admin_emails' => '',
    ], $overrides);
}

it('allows only an active administrator to open and mutate mail settings', function (): void {
    $staff = User::factory()->create(['status' => 'active']);
    $staff->assignRole('staff');
    $customer = User::factory()->create(['status' => 'active']);
    $customer->assignRole('customer');
    $inactiveAdmin = User::factory()->create(['status' => 'inactive']);
    $inactiveAdmin->assignRole('admin');

    $this->actingAs($this->admin)
        ->get(route('settings.mail'))
        ->assertOk()
        ->assertSee('SMTP connection')
        ->assertSee('Daily dish administrator emails');

    $this->actingAs($staff)->get(route('settings.mail'))->assertForbidden();
    $this->actingAs($customer)->get(route('settings.mail'))->assertForbidden();
    $this->actingAs($inactiveAdmin)->get(route('settings.mail'))->assertRedirect(route('login'));

    $this->actingAs($staff);
    expect(fn () => freshMailSettingsService()->save(validMailSettings([
        'daily_dish_admin_emails' => 'ops@example.test',
    ]), $staff, 0))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect(MailSetting::query()->count())->toBe(0);
});

it('inherits installation secrets on first save and never returns them to the page', function (): void {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'installation.mail.example',
        'mail.mailers.smtp.port' => 465,
        'mail.mailers.smtp.scheme' => 'smtps',
        'mail.mailers.smtp.username' => 'installation-user',
        'mail.mailers.smtp.password' => 'installation-password',
        'mail.from.address' => 'installation@example.test',
        'mail.from.name' => 'Installation Sender',
        'mail.daily_dish_admin_emails' => ['first@example.test', 'second@example.test'],
    ]);
    $service = freshMailSettingsService();

    $this->actingAs($this->admin);
    Volt::test('settings.mail')
        ->assertSet('smtp_username', '')
        ->assertSet('smtp_password', '')
        ->assertSet('daily_dish_admin_emails', '')
        ->assertSet('recipient_count', 2)
        ->set('smtp_host', 'smtp.saved.example')
        ->set('smtp_port', '587')
        ->set('security_mode', 'starttls')
        ->set('from_address', 'orders@example.test')
        ->set('from_name', 'Layla Kitchen Orders')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('expected_revision', 1)
        ->assertSet('smtp_username', '')
        ->assertSet('smtp_password', '')
        ->assertSet('daily_dish_admin_emails', '');

    $row = DB::table('mail_settings')->where('id', 1)->first();
    expect($row)->not->toBeNull()
        ->and($row->smtp_username)->not->toContain('installation-user')
        ->and($row->smtp_password)->not->toContain('installation-password')
        ->and($row->daily_dish_admin_emails)->not->toContain('first@example.test')
        ->and(Crypt::decryptString($row->smtp_username))->toBe('installation-user')
        ->and(Crypt::decryptString($row->smtp_password))->toBe('installation-password')
        ->and(json_decode(Crypt::decryptString($row->daily_dish_admin_emails), true))->toBe([
            'first@example.test',
            'second@example.test',
        ]);

    $audit = AccountingAuditLog::query()->where('action', 'mail_settings.updated')->firstOrFail();
    $auditJson = json_encode($audit->payload, JSON_THROW_ON_ERROR);
    expect((int) $audit->company_id)->toBe((int) $this->company->id)
        ->and((int) $audit->actor_id)->toBe((int) $this->admin->id)
        ->and($auditJson)->not->toContain('installation-user')
        ->and($auditJson)->not->toContain('installation-password')
        ->and($auditJson)->not->toContain('first@example.test')
        ->and($auditJson)->not->toContain('smtp.saved.example')
        ->and($audit->payload['recipient_count'])->toBe(2);

    $this->actingAs($this->admin)
        ->get(route('settings.mail'))
        ->assertOk()
        ->assertDontSee('installation-user')
        ->assertDontSee('installation-password')
        ->assertDontSee('first@example.test')
        ->assertSee('2 addresses');

    expect($service->state()['revision'])->toBe(1);
});

it('preserves blank replacements and clears both authentication fields explicitly', function (): void {
    config([
        'mail.mailers.smtp.username' => 'initial-user',
        'mail.mailers.smtp.password' => 'initial-password',
        'mail.daily_dish_admin_emails' => ['ops@example.test'],
    ]);
    $service = freshMailSettingsService();
    $created = $service->save(validMailSettings(), $this->admin, 0);

    $updated = $service->save(validMailSettings([
        'from_name' => 'Updated Sender',
        'smtp_password' => 'replacement-password',
    ]), $this->admin, $created['state']['revision']);
    $row = MailSetting::query()->findOrFail(1);
    expect(Crypt::decryptString($row->smtp_username))->toBe('initial-user')
        ->and(Crypt::decryptString($row->smtp_password))->toBe('replacement-password')
        ->and($updated['state']['recipient_count'])->toBe(1);

    expect(fn () => $service->save(validMailSettings([
        'smtp_username' => 'new-user',
    ]), $this->admin, $updated['state']['revision']))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->clearAuthentication($this->admin, $updated['state']['revision'], false))
        ->toThrow(ValidationException::class);

    $cleared = $service->clearAuthentication($this->admin, $updated['state']['revision'], true);
    $row->refresh();
    expect($row->smtp_username)->toBeNull()
        ->and($row->smtp_password)->toBeNull()
        ->and($cleared['state']['authentication_configured'])->toBeFalse()
        ->and($cleared['state']['revision'])->toBe(3)
        ->and(AccountingAuditLog::query()->where('action', 'mail_settings.authentication_cleared')->count())->toBe(1);
});

it('rejects stale and repeated initial revisions without overwriting the winner', function (): void {
    config(['mail.daily_dish_admin_emails' => ['ops@example.test']]);
    $service = freshMailSettingsService();
    $first = $service->save(validMailSettings(), $this->admin, 0);

    expect(fn () => $service->save(validMailSettings([
        'smtp_host' => 'loser.mail.example',
    ]), $this->admin, 0))->toThrow(MailSettingsConflictException::class);

    $second = $service->save(validMailSettings([
        'smtp_host' => 'winner.mail.example',
    ]), $this->admin, $first['state']['revision']);

    expect(fn () => $service->save(validMailSettings([
        'smtp_host' => 'stale.mail.example',
    ]), $this->admin, $first['state']['revision']))->toThrow(MailSettingsConflictException::class);

    expect(MailSetting::query()->count())->toBe(1)
        ->and(MailSetting::query()->value('smtp_host'))->toBe('winner.mail.example')
        ->and($second['state']['revision'])->toBe(2)
        ->and(AccountingAuditLog::query()->where('action', 'mail_settings.updated')->count())->toBe(2);
});

it('applies a saved secure SMTP revision and ignores an installation mail URL', function (): void {
    config([
        'mail.default' => 'log',
        'mail.mailers.smtp.url' => 'smtp://obsolete.example:2525',
        'mail.daily_dish_admin_emails' => ['installation@example.test'],
    ]);
    $service = freshMailSettingsService();
    $created = $service->save(validMailSettings([
        'smtp_host' => 'smtp.runtime.example',
        'smtp_username' => 'runtime-user',
        'smtp_password' => 'runtime-password',
        'daily_dish_admin_emails' => "OPS@example.test\nops@example.test\nfinance@example.test",
    ]), $this->admin, 0);

    $prepared = $service->prepareForDelivery();
    expect($prepared['revision'])->toBe($created['state']['revision'])
        ->and(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.url'))->toBeNull()
        ->and(config('mail.mailers.smtp.scheme'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.require_tls'))->toBeTrue()
        ->and(config('mail.mailers.smtp.verify_peer'))->toBeTrue()
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.runtime.example')
        ->and(config('mail.mailers.smtp.username'))->toBe('runtime-user')
        ->and(config('mail.from.address'))->toBe('orders@example.test')
        ->and($service->adminRecipientsForCompany((int) $this->company->id))->toBe([
            'ops@example.test',
            'finance@example.test',
        ]);

    $otherCompany = AccountingCompany::query()->create([
        'name' => 'Other Company',
        'code' => 'OTHER-MAIL',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => false,
    ]);
    expect($service->adminRecipientsForCompany((int) $otherCompany->id))->toBe([]);
});

it('fails closed on unreadable saved secrets and supports complete repair', function (): void {
    config(['mail.daily_dish_admin_emails' => ['ops@example.test']]);
    $service = freshMailSettingsService();
    $created = $service->save(validMailSettings([
        'smtp_username' => 'saved-user',
        'smtp_password' => 'saved-password',
    ]), $this->admin, 0);
    DB::table('mail_settings')->where('id', 1)->update(['smtp_password' => 'not-valid-ciphertext']);

    expect($service->state()['needs_repair'])->toBeTrue()
        ->and(fn () => $service->prepareForDelivery())
        ->toThrow(MailConfigurationUnavailableException::class, 'Mail configuration is unavailable.');

    $repaired = $service->save(validMailSettings([
        'smtp_username' => 'repaired-user',
        'smtp_password' => 'repaired-password',
        'daily_dish_admin_emails' => 'repaired@example.test',
    ]), $this->admin, $created['state']['revision']);

    expect($repaired['state']['needs_repair'])->toBeFalse()
        ->and($repaired['state']['recipient_count'])->toBe(1);
    expect(fn () => $service->prepareForDelivery())->not->toThrow(MailConfigurationUnavailableException::class);
});

it('rolls back a settings save when required audit storage is unavailable', function (): void {
    config(['mail.daily_dish_admin_emails' => ['ops@example.test']]);
    $service = freshMailSettingsService();
    AccountingAuditLog::creating(function (): void {
        throw new RuntimeException('Synthetic audit failure.');
    });

    expect(fn () => $service->save(validMailSettings(), $this->admin, 0))
        ->toThrow(ValidationException::class);

    expect(MailSetting::query()->count())->toBe(0);
});
