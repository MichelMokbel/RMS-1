<?php

namespace App\Services\Mail;

use App\Models\EmailLog;
use Illuminate\Mail\Mailable;

class EmailLogService
{
    /**
     * @param  array<int, string>  $toRecipients
     * @param  array<int, string>  $ccRecipients
     * @param  array<int, string>  $bccRecipients
     * @param  array<string, mixed>|null  $context
     */
    public function log(
        string $category,
        string $recipientType,
        string $status,
        Mailable $mailable,
        array $toRecipients,
        array $ccRecipients = [],
        array $bccRecipients = [],
        ?int $userId = null,
        ?int $orderId = null,
        ?int $mealPlanRequestId = null,
        ?string $mailer = null,
        ?array $context = null,
        ?\Throwable $exception = null,
    ): EmailLog {
        return EmailLog::query()->create([
            'category' => $category,
            'recipient_type' => $recipientType,
            'mailable' => $mailable::class,
            'subject' => $mailable->envelope()->subject,
            'mailer' => $mailer,
            'status' => $status,
            'to_recipients' => array_values($toRecipients),
            'cc_recipients' => array_values($ccRecipients),
            'bcc_recipients' => array_values($bccRecipients),
            'user_id' => $userId,
            'order_id' => $orderId,
            'meal_plan_request_id' => $mealPlanRequestId,
            'context' => $context,
            'error_class' => $exception ? $exception::class : null,
            'error_message' => $exception?->getMessage(),
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }
}
