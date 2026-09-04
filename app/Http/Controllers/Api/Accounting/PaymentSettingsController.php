<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Accounting\AccountingContextService;
use App\Services\Payments\PaymentOperationsAccessService;
use App\Services\Payments\PaymentSettingsConflictException;
use App\Services\Payments\PaymentSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentSettingsController extends Controller
{
    public function show(
        Request $request,
        AccountingContextService $context,
        PaymentOperationsAccessService $access,
        PaymentSettingsService $settings,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $companyId = $context->defaultCompanyId();
        abort_unless($companyId, 422, __('An active default accounting company is required.'));
        $access->assertCanManageSettings($actor, $companyId);
        $record = $settings->forCompany($companyId);
        abort_unless($record, 404, __('Payment settings have not been initialized.'));

        return response()->json(['settings' => $settings->payload($record)]);
    }

    public function update(
        Request $request,
        AccountingContextService $context,
        PaymentSettingsService $settings,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $companyId = $context->defaultCompanyId();
        abort_unless($companyId, 422, __('An active default accounting company is required.'));
        $data = $request->validate([
            'checkout_duration_minutes' => ['required', 'integer', 'between:5,60'],
            'booking_cutoff_time' => ['required', 'date_format:H:i'],
            'order_support_phone' => ['required', 'string', 'max:50'],
            'expected_version' => ['required', 'string', 'size:64'],
        ]);

        try {
            $result = $settings->saveVersioned($companyId, [
                'checkout_duration_minutes' => $data['checkout_duration_minutes'],
                'booking_cutoff_time' => $data['booking_cutoff_time'],
                'timezone' => 'Asia/Qatar',
                'order_support_phone' => $data['order_support_phone'],
            ], $actor, $data['expected_version']);
        } catch (PaymentSettingsConflictException $exception) {
            return response()->json([
                'code' => 'PAYMENT_SETTINGS_STALE',
                'message' => $exception->getMessage(),
                'settings' => $exception->current,
            ], 409);
        }

        return response()->json([
            'settings' => $settings->payload($result['settings']),
            'audit_id' => $result['audit_id'],
        ]);
    }
}
