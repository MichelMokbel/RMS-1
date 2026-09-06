<?php

namespace App\Jobs;

use App\Mail\MembershipBookingConfirmationMail;
use App\Models\MealSubscriptionOrder;
use App\Services\Mail\EmailLogService;
use App\Services\Mail\MailSettingsService;
use App\Services\Payments\PaymentConsistencyDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendMembershipBookingConfirmation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $subscriptionOrderId,
        public readonly string $kind,
    ) {}

    public function handle(
        EmailLogService $emailLogs,
        MailSettingsService $mailSettings,
        PaymentConsistencyDispatchService $paymentConsistency,
    ): void {
        $slotKey = $this->slotKey();
        if ($slotKey === null) {
            return;
        }
        $mapping = DB::transaction(function () use ($slotKey): ?MealSubscriptionOrder {
            $mapping = MealSubscriptionOrder::query()
                ->with('order')
                ->lockForUpdate()
                ->find($this->subscriptionOrderId);
            if (! $mapping) {
                return null;
            }
            $dispatch = is_array($mapping->notification_dispatch) ? $mapping->notification_dispatch : [];
            $slot = is_array($dispatch[$slotKey] ?? null) ? $dispatch[$slotKey] : [];
            if (($slot['state'] ?? null) !== 'pending') {
                return null;
            }
            if ($this->kind !== 'cancelled') {
                $latestRevision = MealSubscriptionOrder::query()
                    ->where('subscription_id', $mapping->subscription_id)
                    ->where('booking_uuid', $mapping->booking_uuid)
                    ->max('booking_revision');
                if ((int) $latestRevision !== (int) $mapping->booking_revision || $mapping->order?->status === 'Cancelled') {
                    $dispatch[$slotKey] = ['state' => 'superseded', 'superseded_at' => now()->toIso8601String()];
                    $mapping->update(['notification_dispatch' => $dispatch]);

                    return null;
                }
            }
            $dispatch[$slotKey] = ['state' => 'sending', 'started_at' => now()->toIso8601String()];
            $mapping->update(['notification_dispatch' => $dispatch]);

            return $mapping->fresh('order');
        });
        if (! $mapping) {
            return;
        }

        $snapshot = (array) $mapping->notification_snapshots;
        $recipient = trim((string) ($snapshot['customer_email'] ?? ''));
        $mail = new MembershipBookingConfirmationMail($snapshot, $this->kind);
        if ($recipient === '') {
            $this->finish('failed', 'CUSTOMER_RECIPIENT_MISSING', $paymentConsistency);

            return;
        }

        try {
            $mailSettings->prepareForDelivery();
            $mailer = (string) config('mail.default', 'log');
            if (in_array($mailer, ['log', 'array'], true)) {
                $emailLogs->log(
                    'membership_booking_confirmation',
                    'customer',
                    'skipped',
                    $mail,
                    [$recipient],
                    userId: $mapping->order?->user_id,
                    orderId: $mapping->order_id,
                    mailer: $mailer,
                    context: ['subscription_order_id' => $mapping->id, 'kind' => $this->kind],
                );
            } else {
                Mail::to([$recipient])->send($mail);
                $emailLogs->log(
                    'membership_booking_confirmation',
                    'customer',
                    'sent',
                    $mail,
                    [$recipient],
                    userId: $mapping->order?->user_id,
                    orderId: $mapping->order_id,
                    mailer: $mailer,
                    context: ['subscription_order_id' => $mapping->id, 'kind' => $this->kind],
                );
            }
        } catch (\Throwable $exception) {
            $emailLogs->log(
                'membership_booking_confirmation',
                'customer',
                'failed',
                $mail,
                [$recipient],
                userId: $mapping->order?->user_id,
                orderId: $mapping->order_id,
                mailer: (string) config('mail.default', 'log'),
                context: ['subscription_order_id' => $mapping->id, 'kind' => $this->kind],
                exception: $exception,
            );
            $this->finish('failed', 'EMAIL_SEND_FAILED', $paymentConsistency);

            return;
        }

        $this->finish('sent', null, $paymentConsistency);
    }

    private function finish(
        string $state,
        ?string $errorCode,
        PaymentConsistencyDispatchService $paymentConsistency,
    ): void {
        $slotKey = $this->slotKey();
        if ($slotKey === null) {
            return;
        }
        $updated = DB::transaction(function () use ($slotKey, $state, $errorCode): bool {
            $mapping = MealSubscriptionOrder::query()->lockForUpdate()->find($this->subscriptionOrderId);
            if (! $mapping) {
                return false;
            }
            $dispatch = is_array($mapping->notification_dispatch) ? $mapping->notification_dispatch : [];
            $dispatch[$slotKey] = [
                'state' => $state,
                'finished_at' => now()->toIso8601String(),
                'error_code' => $errorCode,
            ];
            $mapping->update(['notification_dispatch' => $dispatch]);

            return true;
        });
        if ($updated) {
            $paymentConsistency->notificationAfterCommit(
                'meal_subscription_order',
                $this->subscriptionOrderId,
                'membership_booking_notification',
                $this->subscriptionOrderId,
                $state,
            );
        }
    }

    private function slotKey(): ?string
    {
        return match ($this->kind) {
            'created' => 'customer_creation',
            'changed' => 'customer_change',
            'cancelled' => 'customer_cancellation',
            default => null,
        };
    }
}
