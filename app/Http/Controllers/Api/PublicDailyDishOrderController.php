<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Orders\CustomerPortalOrderAuditService;
use App\Services\Orders\CustomerDailyDishOrderService;
use App\Services\Orders\CustomerPortalOrderIdempotencyService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicDailyDishOrderController extends Controller
{
    public function __construct(
        private readonly CustomerDailyDishOrderService $service,
        private readonly CustomerPortalOrderAuditService $auditService,
        private readonly CustomerPortalOrderIdempotencyService $idempotencyService,
    ) {
    }

    public function store(Request $request)
    {
        $auditId = (string) Str::uuid();
        $payload = null;

        try {
            $payload = $request->validate([
                'branch_id' => ['nullable', 'integer'],
                'client_uuid' => ['nullable', 'uuid'],
                'customerName' => ['required', 'string', 'max:255'],
                'phone' => ['required', 'string', 'max:50'],
                'email' => ['nullable', 'email', 'max:255'],
                'address' => ['nullable', 'string'],
                'notes' => ['nullable', 'string'],
                'mealPlan' => ['nullable', 'in:20,26'],
                'items' => ['required', 'array', 'min:1'],
                'items.*.key' => ['required', 'date'],
                'items.*.mains' => ['nullable', 'array'],
                'items.*.mains.*.name' => ['required_with:items.*.mains', 'string'],
                'items.*.mains.*.portion' => ['required_with:items.*.mains', 'in:plate,half,full'],
                'items.*.mains.*.qty' => ['required_with:items.*.mains', 'integer', 'min:1'],
                'items.*.salad' => ['nullable', 'string'],
                'items.*.dessert' => ['nullable', 'string'],
                'items.*.salad_qty' => ['nullable', 'integer', 'min:0'],
                'items.*.dessert_qty' => ['nullable', 'integer', 'min:0'],
                'items.*.notes' => ['nullable', 'string'],
                'items.*.mealType' => ['nullable', 'string'],
                'items.*.main' => ['nullable', 'string'],
                'items.*.menu_item_id' => ['nullable', 'integer'],
                'items.*.day_total' => ['nullable', 'numeric', 'min:0'],
            ]);

            $this->auditService->submissionReceived($request, $payload, $auditId);
            $execution = $this->idempotencyService->execute($request->user(), $payload, function () use ($request, $payload, $auditId) {
                return $this->service->create($request->user(), $payload, $auditId) + ['audit_id' => $auditId];
            });

            if ($execution['replayed']) {
                $this->auditService->submissionReplayed(
                    $request->user(),
                    $payload,
                    $execution['response'],
                    $auditId,
                    $execution['replayed_audit_id'],
                    $execution['key_type']
                );
            }

            $response = $execution['response'] + [
                'audit_id' => $auditId,
                'replayed' => (bool) $execution['replayed'],
            ];

            if ($execution['replayed'] && $execution['replayed_audit_id']) {
                $response['replayed_audit_id'] = $execution['replayed_audit_id'];
            }

            return response()->json($response);
        } catch (\Throwable $e) {
            $this->auditService->submissionFailed(
                $request->user(),
                is_array($payload) ? $payload : null,
                $e,
                $payload === null ? 'validation' : 'create',
                $auditId
            );

            throw $e;
        }
    }
}
