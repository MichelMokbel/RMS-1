<?php

use App\Contracts\PhoneVerificationProvider;
use App\Services\Customers\TelnyxPhoneVerificationProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    Config::set('services.customer_sms.telnyx', [
        'api_key' => 'test-api-key',
        'from' => 'Layla Kitch',
        'messaging_profile_id' => 'profile-123',
        'base_url' => 'https://api.telnyx.test/v2',
        'connect_timeout_seconds' => 3,
        'timeout_seconds' => 10,
    ]);
});

it('sends the verification code through Telnyx and returns its message identifier', function (): void {
    Http::fake([
        'https://api.telnyx.test/v2/messages' => Http::response([
            'data' => [
                'id' => 'message-123',
                'to' => [['phone_number' => '+97455123456', 'status' => 'queued']],
            ],
        ]),
    ]);

    $result = app(TelnyxPhoneVerificationProvider::class)->sendVerificationCode(
        '+97455123456',
        'Layla Kitchen verification code: 123456.',
        ['purpose' => 'signup', 'challenge_id' => 41],
    );

    expect($result)->toBe([
        'provider' => 'telnyx',
        'message_id' => 'message-123',
    ]);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.telnyx.test/v2/messages'
            && $request->hasHeader('Authorization', 'Bearer test-api-key')
            && $request['from'] === 'Layla Kitch'
            && $request['to'] === '+97455123456'
            && $request['text'] === 'Layla Kitchen verification code: 123456.'
            && $request['type'] === 'SMS'
            && $request['messaging_profile_id'] === 'profile-123'
            && ! array_key_exists('context', $request->data());
    });
});

it('is selected by the phone verification provider binding', function (): void {
    Config::set('services.customer_sms.provider', 'telnyx');
    app()->forgetInstance(PhoneVerificationProvider::class);

    expect(app(PhoneVerificationProvider::class))->toBeInstanceOf(TelnyxPhoneVerificationProvider::class);
});

it('fails before sending when required Telnyx configuration is missing', function (): void {
    Config::set('services.customer_sms.telnyx.api_key', '');
    Http::fake();

    expect(fn () => app(TelnyxPhoneVerificationProvider::class)->sendVerificationCode(
        '+97455123456',
        'Verification code: 123456.',
    ))->toThrow(\RuntimeException::class, 'Telnyx SMS is not configured.');

    Http::assertNothingSent();
});

it('rejects a non e164 recipient before sending', function (): void {
    Http::fake();

    expect(fn () => app(TelnyxPhoneVerificationProvider::class)->sendVerificationCode(
        '55123456',
        'Verification code: 123456.',
    ))->toThrow(\RuntimeException::class, 'SMS recipient must be in E.164 format.');

    Http::assertNothingSent();
});

it('requires a messaging profile for an alphanumeric sender', function (): void {
    Config::set('services.customer_sms.telnyx.messaging_profile_id', '');
    Http::fake();

    expect(fn () => app(TelnyxPhoneVerificationProvider::class)->sendVerificationCode(
        '+97455123456',
        'Verification code: 123456.',
    ))->toThrow(\RuntimeException::class, 'Telnyx messaging profile is required for the configured sender.');

    Http::assertNothingSent();
});

it('reports a rejected request without exposing the provider response', function (): void {
    Http::fake([
        'https://api.telnyx.test/v2/messages' => Http::response([
            'errors' => [['detail' => 'sensitive provider response']],
        ], 401),
    ]);

    expect(fn () => app(TelnyxPhoneVerificationProvider::class)->sendVerificationCode(
        '+97455123456',
        'Verification code: 123456.',
    ))->toThrow(\RuntimeException::class, 'Telnyx rejected the SMS request with HTTP 401.');

    Http::assertSentCount(1);
});

it('rejects a successful response without a message identifier', function (): void {
    Http::fake([
        'https://api.telnyx.test/v2/messages' => Http::response(['data' => []]),
    ]);

    expect(fn () => app(TelnyxPhoneVerificationProvider::class)->sendVerificationCode(
        '+97455123456',
        'Verification code: 123456.',
    ))->toThrow(\RuntimeException::class, 'Telnyx SMS response did not contain a message identifier.');
});
