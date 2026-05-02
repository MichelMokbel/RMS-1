<?php

namespace App\Services\Orders;

use App\Models\OpsEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerPortalOrderAuditService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function submissionReceived(Request $request, array $payload, string $auditId): void
    {
        $user = $request->user();

        OpsEvent::create([
            'event_type' => 'customer_portal_order_submission_received',
            'branch_id' => 1,
            'service_date' => $this->resolveFirstServiceDate($payload),
            'order_id' => null,
            'order_item_id' => null,
            'actor_user_id' => $user?->id,
            'metadata_json' => [
                'audit_id' => $auditId,
                'route' => $request->path(),
                'method' => $request->method(),
                'user_id' => $user?->id,
                'customer_id' => $user?->customer_id,
                'link_status' => $user && $user->customer_id ? 'linked' : 'unlinked',
                'submitted_branch_id' => Arr::get($payload, 'branch_id'),
                'client_uuid' => Arr::get($payload, 'client_uuid'),
                'meal_plan' => Arr::get($payload, 'mealPlan'),
                'item_count' => count((array) Arr::get($payload, 'items', [])),
                'service_dates' => $this->resolveServiceDates($payload),
                'customer_name' => Arr::get($payload, 'customerName'),
                'customer_phone' => Arr::get($payload, 'phone'),
                'customer_email' => Arr::get($payload, 'email'),
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{success:bool,order_ids:array<int,int>,meal_plan_request_id:int|null,email_sent_admin:bool,email_sent_customer:bool}  $result
     */
    public function submissionCompleted(User $user, array $payload, array $result, string $auditId): void
    {
        OpsEvent::create([
            'event_type' => 'customer_portal_order_submission_completed',
            'branch_id' => 1,
            'service_date' => $this->resolveFirstServiceDate($payload),
            'order_id' => $result['order_ids'][0] ?? null,
            'order_item_id' => null,
            'actor_user_id' => $user->id,
            'metadata_json' => [
                'audit_id' => $auditId,
                'user_id' => $user->id,
                'customer_id' => $user->customer_id,
                'link_status' => $user->customer_id ? 'linked' : 'unlinked',
                'client_uuid' => Arr::get($payload, 'client_uuid'),
                'meal_plan' => Arr::get($payload, 'mealPlan'),
                'service_dates' => $this->resolveServiceDates($payload),
                'order_ids' => array_values($result['order_ids']),
                'order_count' => count($result['order_ids']),
                'meal_plan_request_id' => $result['meal_plan_request_id'],
                'email_sent_admin' => (bool) $result['email_sent_admin'],
                'email_sent_customer' => (bool) $result['email_sent_customer'],
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function submissionFailed(?User $user, ?array $payload, \Throwable $e, string $stage, string $auditId): void
    {
        $validationErrors = $e instanceof ValidationException ? $e->errors() : null;

        OpsEvent::create([
            'event_type' => 'customer_portal_order_submission_failed',
            'branch_id' => 1,
            'service_date' => $payload ? $this->resolveFirstServiceDate($payload) : null,
            'order_id' => null,
            'order_item_id' => null,
            'actor_user_id' => $user?->id,
            'metadata_json' => [
                'audit_id' => $auditId,
                'stage' => $stage,
                'user_id' => $user?->id,
                'customer_id' => $user?->customer_id,
                'link_status' => $user?->customer_id ? 'linked' : 'unlinked',
                'client_uuid' => Arr::get($payload, 'client_uuid'),
                'meal_plan' => Arr::get($payload, 'mealPlan'),
                'service_dates' => $payload ? $this->resolveServiceDates($payload) : [],
                'customer_name' => Arr::get($payload, 'customerName'),
                'customer_phone' => Arr::get($payload, 'phone'),
                'customer_email' => Arr::get($payload, 'email'),
                'error_class' => $e::class,
                'error_message' => Str::limit($e->getMessage(), 500),
                'validation_errors' => $validationErrors,
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $response
     */
    public function submissionReplayed(User $user, array $payload, array $response, string $auditId, ?string $originalAuditId, string $keyType): void
    {
        OpsEvent::create([
            'event_type' => 'customer_portal_order_submission_replayed',
            'branch_id' => 1,
            'service_date' => $this->resolveFirstServiceDate($payload),
            'order_id' => $response['order_ids'][0] ?? null,
            'order_item_id' => null,
            'actor_user_id' => $user->id,
            'metadata_json' => [
                'audit_id' => $auditId,
                'original_audit_id' => $originalAuditId,
                'key_type' => $keyType,
                'user_id' => $user->id,
                'customer_id' => $user->customer_id,
                'link_status' => $user->customer_id ? 'linked' : 'unlinked',
                'client_uuid' => Arr::get($payload, 'client_uuid'),
                'meal_plan' => Arr::get($payload, 'mealPlan'),
                'service_dates' => $this->resolveServiceDates($payload),
                'order_ids' => array_values((array) ($response['order_ids'] ?? [])),
                'meal_plan_request_id' => $response['meal_plan_request_id'] ?? null,
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function resolveServiceDates(array $payload): array
    {
        return collect((array) Arr::get($payload, 'items', []))
            ->map(fn ($item) => trim((string) Arr::get($item, 'key', '')))
            ->filter(fn (string $value) => $value !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveFirstServiceDate(array $payload): ?string
    {
        return $this->resolveServiceDates($payload)[0] ?? null;
    }
}
