<?php

namespace App\Jobs;

use App\Mail\MembershipPromotionRequestConfirmationMail;
use App\Models\MealPlanRequest;
use App\Services\Mail\EmailLogService;
use App\Services\Mail\MailConfigurationUnavailableException;
use App\Services\Mail\MailSettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendMembershipPromotionRequestConfirmation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const AUDIENCES = ['customer', 'admin'];

    public function __construct(
        public readonly int $requestId,
        public readonly string $audience,
    ) {}

    public static function slotForAudience(string $audience): ?string
    {
        return match ($audience) {
            'customer' => 'customer_confirmation',
            'admin' => 'admin_confirmation',
            default => null,
        };
    }

    public function handle(EmailLogService $emailLogs, MailSettingsService $mailSettings): void
    {
        $slotKey = self::slotForAudience($this->audience);
        if ($slotKey === null) {
            return;
        }

        $request = DB::transaction(function () use ($slotKey): ?MealPlanRequest {
            $request = MealPlanRequest::query()->lockForUpdate()->find($this->requestId);
            $dispatch = is_array($request?->notification_dispatch) ? $request->notification_dispatch : [];
            $slot = is_array($dispatch[$slotKey] ?? null) ? $dispatch[$slotKey] : [];
            $state = (string) ($slot['state'] ?? '');
            $nextRetryAt = $slot['next_retry_at'] ?? null;
            if (! $request
                || $request->submission_kind !== 'promo_request'
                || ! $request->redemption_id
                || ! in_array($state, ['pending', 'retryable'], true)
                || $state === 'retryable' && $nextRetryAt && now('UTC')->lt($nextRetryAt)) {
                return null;
            }

            $dispatch[$slotKey] = [
                'state' => 'sending',
                'started_at' => now('UTC')->toIso8601String(),
                'attempts' => max(0, (int) ($slot['attempts'] ?? 0)) + 1,
            ];
            $request->update(['notification_dispatch' => $dispatch]);

            return $request->fresh();
        });
        if (! $request) {
            return;
        }

        $snapshot = is_array($request->notification_snapshots) ? $request->notification_snapshots : [];
        $recipients = $this->audience === 'customer'
            ? array_values(array_filter([(string) ($snapshot['customer_email'] ?? '')]))
            : array_values(array_filter((array) ($snapshot['admin_emails'] ?? [])));
        $mail = new MembershipPromotionRequestConfirmationMail($snapshot, $this->audience);
        if ($recipients === []) {
            $this->finish(
                $this->audience === 'admin' ? 'ADMIN_RECIPIENT_MISSING' : 'CUSTOMER_RECIPIENT_MISSING',
                'failed',
            );

            return;
        }

        try {
            $mailSettings->prepareForDelivery();
            $mailer = (string) config('mail.default', 'log');
            if (in_array($mailer, ['log', 'array'], true)) {
                $emailLogs->log(
                    'membership_promotion_request_confirmation',
                    $this->audience,
                    'skipped',
                    $mail,
                    $recipients,
                    userId: $request->user_id,
                    mealPlanRequestId: $request->id,
                    mailer: $mailer,
                    context: ['reason' => 'mail_delivery_disabled'],
                );
            } else {
                Mail::to($recipients)->send($mail);
                $emailLogs->log(
                    'membership_promotion_request_confirmation',
                    $this->audience,
                    'sent',
                    $mail,
                    $recipients,
                    userId: $request->user_id,
                    mealPlanRequestId: $request->id,
                    mailer: $mailer,
                );
            }
        } catch (MailConfigurationUnavailableException) {
            $this->finish('MAIL_CONFIGURATION_UNAVAILABLE', 'retryable');

            return;
        } catch (\Throwable $exception) {
            $emailLogs->log(
                'membership_promotion_request_confirmation',
                $this->audience,
                'failed',
                $mail,
                $recipients,
                userId: $request->user_id,
                mealPlanRequestId: $request->id,
                mailer: (string) config('mail.default', 'log'),
                context: ['delivery_state' => 'unknown'],
                exception: $exception,
            );
            $this->finish('EMAIL_DELIVERY_UNKNOWN', 'unknown');

            return;
        }

        $this->finish(null, 'sent');
    }

    private function finish(?string $errorCode, string $state): void
    {
        $slotKey = self::slotForAudience($this->audience);
        if ($slotKey === null) {
            return;
        }

        DB::transaction(function () use ($slotKey, $errorCode, $state): void {
            $request = MealPlanRequest::query()->lockForUpdate()->find($this->requestId);
            if (! $request) {
                return;
            }
            $dispatch = is_array($request->notification_dispatch) ? $request->notification_dispatch : [];
            $slot = is_array($dispatch[$slotKey] ?? null) ? $dispatch[$slotKey] : [];
            $attempts = max(1, (int) ($slot['attempts'] ?? 0));
            $finalState = $state === 'retryable'
                && $attempts >= (int) config('payments.skipcash.notification_max_attempts', 5)
                    ? 'failed'
                    : $state;
            $dispatch[$slotKey] = [
                'state' => $finalState,
                'error_code' => $errorCode,
                'attempts' => $attempts,
                'next_retry_at' => $finalState === 'retryable'
                    ? now('UTC')->addMinutes($this->retryDelayMinutes($attempts))->toIso8601String()
                    : null,
                'finished_at' => now('UTC')->toIso8601String(),
            ];
            $request->update(['notification_dispatch' => $dispatch]);
        });
    }

    private function retryDelayMinutes(int $attempts): int
    {
        $base = max(1, (int) config('payments.skipcash.notification_retry_base_minutes', 1));

        return min(30, $base * (2 ** max(0, $attempts - 1)));
    }
}
