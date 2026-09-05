<?php

namespace App\Jobs;

use App\Mail\DailyDishOrderAdminMail;
use App\Mail\DailyDishOrderCustomerMail;
use App\Models\Order;
use App\Models\PaymentCheckoutAttempt;
use App\Services\Mail\EmailLogService;
use App\Services\Mail\MailConfigurationUnavailableException;
use App\Services\Mail\MailSettingsService;
use App\Services\Payments\PaymentOperationsTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendSkipCashOrderConfirmation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const AUDIENCES = ['customer', 'admin'];

    public function __construct(
        public readonly int $attemptId,
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

    public function handle(
        EmailLogService $emailLogs,
        PaymentOperationsTrackingService $operations,
        MailSettingsService $mailSettings,
    ): void {
        $slotKey = self::slotForAudience($this->audience);
        if ($slotKey === null) {
            return;
        }

        $attempt = DB::transaction(function () use ($slotKey): ?PaymentCheckoutAttempt {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($this->attemptId);
            $dispatch = is_array($attempt?->notification_dispatch) ? $attempt->notification_dispatch : [];
            $slot = is_array($dispatch[$slotKey] ?? null) ? $dispatch[$slotKey] : [];
            $state = (string) ($slot['state'] ?? '');
            $nextRetryAt = $slot['next_retry_at'] ?? null;
            if (! $attempt || $attempt->state !== 'completed'
                || ! in_array($state, ['pending', 'retryable'], true)
                || $state === 'retryable' && $nextRetryAt && now('UTC')->lt($nextRetryAt)) {
                return null;
            }
            $dispatch[$slotKey] = [
                'state' => 'sending',
                'started_at' => now('UTC')->toIso8601String(),
                'attempts' => max(0, (int) ($slot['attempts'] ?? 0)) + 1,
            ];
            $attempt->update(['notification_dispatch' => $dispatch]);

            return $attempt->fresh();
        });
        if (! $attempt) {
            return;
        }

        $snapshot = $attempt->notification_snapshots;
        $recipients = $this->audience === 'customer'
            ? array_values(array_filter([(string) ($snapshot['customer_email'] ?? '')]))
            : array_values(array_filter((array) ($snapshot['admin_emails'] ?? [])));
        if ($recipients === []) {
            $this->markFailed($this->audience === 'admin' ? 'ADMIN_RECIPIENT_MISSING' : 'CUSTOMER_RECIPIENT_MISSING', false, $operations);

            return;
        }
        $orders = Order::query()
            ->whereIn('id', array_map('intval', (array) ($snapshot['order_ids'] ?? [])))
            ->orderBy('scheduled_date')
            ->get();
        if ($orders->isEmpty()) {
            $this->markFailed('ORDERS_UNAVAILABLE', true, $operations);

            return;
        }

        $mail = $this->audience === 'customer'
            ? new DailyDishOrderCustomerMail($orders, null, null)
            : new DailyDishOrderAdminMail($orders, null, null);
        try {
            $mailSettings->prepareForDelivery();
            $mailer = (string) config('mail.default', 'log');
            if (in_array($mailer, ['log', 'array'], true)) {
                $emailLogs->log(
                    'skipcash_order_confirmation', $this->audience, 'skipped', $mail, $recipients,
                    userId: $attempt->portal_user_id, orderId: $orders->first()->id, mailer: $mailer,
                    context: ['checkout_attempt_id' => $attempt->id, 'reason' => 'mail_delivery_disabled'],
                );
            } else {
                Mail::to($recipients)->send($mail);
                $emailLogs->log(
                    'skipcash_order_confirmation', $this->audience, 'sent', $mail, $recipients,
                    userId: $attempt->portal_user_id, orderId: $orders->first()->id, mailer: $mailer,
                    context: ['checkout_attempt_id' => $attempt->id],
                );
            }
        } catch (MailConfigurationUnavailableException) {
            $this->markFailed('MAIL_CONFIGURATION_UNAVAILABLE', true, $operations);

            return;
        } catch (\Throwable) {
            $this->markFailed('EMAIL_SEND_FAILED', true, $operations);

            return;
        }

        DB::transaction(function () use ($slotKey): void {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($this->attemptId);
            if (! $attempt) {
                return;
            }
            $dispatch = is_array($attempt->notification_dispatch) ? $attempt->notification_dispatch : [];
            $slot = is_array($dispatch[$slotKey] ?? null) ? $dispatch[$slotKey] : [];
            $dispatch[$slotKey] = [
                'state' => 'sent',
                'sent_at' => now('UTC')->toIso8601String(),
                'attempts' => (int) ($slot['attempts'] ?? 0),
            ];
            $attempt->update(['notification_dispatch' => $dispatch]);
        });
        $operations->resolveConfirmationIssue($this->attemptId, $this->audience);
    }

    private function markFailed(string $code, bool $canRetry, PaymentOperationsTrackingService $operations): void
    {
        $slotKey = self::slotForAudience($this->audience);
        if ($slotKey === null) {
            return;
        }

        $terminal = DB::transaction(function () use ($code, $canRetry, $slotKey): bool {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($this->attemptId);
            if (! $attempt) {
                return false;
            }
            $dispatch = is_array($attempt->notification_dispatch) ? $attempt->notification_dispatch : [];
            $slot = is_array($dispatch[$slotKey] ?? null) ? $dispatch[$slotKey] : [];
            $attempts = max(1, (int) ($slot['attempts'] ?? 0));
            $retryable = $canRetry && $attempts < (int) config('payments.skipcash.notification_max_attempts', 5);
            $dispatch[$slotKey] = [
                'state' => $retryable ? 'retryable' : 'failed',
                'error_code' => $code,
                'attempts' => $attempts,
                'next_retry_at' => $retryable
                    ? now('UTC')->addMinutes($this->retryDelayMinutes($attempts))->toIso8601String()
                    : null,
                'failed_at' => now('UTC')->toIso8601String(),
            ];
            $attempt->update(['notification_dispatch' => $dispatch]);

            return ! $retryable;
        });
        if ($terminal) {
            $operations->recordConfirmationFailure($this->attemptId, $this->audience, $code);
        }
    }

    private function retryDelayMinutes(int $attempts): int
    {
        $base = max(1, (int) config('payments.skipcash.notification_retry_base_minutes', 1));

        return min(30, $base * (2 ** max(0, $attempts - 1)));
    }
}
