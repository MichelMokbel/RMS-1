<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentCheckoutException;
use App\Services\Payments\SkipCashWebhookService;
use Illuminate\Http\Request;

class SkipCashWebhookController extends Controller
{
    public function __construct(
        private readonly SkipCashWebhookService $webhooks,
    ) {}

    public function store(Request $request)
    {
        if (strlen($request->getContent()) > 1024 * 1024) {
            return response()->json(['message' => __('The payment callback is too large.')], 413);
        }
        $payload = $request->json()->all();
        if (! is_array($payload)) {
            return response()->json(['message' => __('The payment callback is malformed.')], 400);
        }

        try {
            $result = $this->webhooks->handle(
                $payload,
                $request->header('Authorization'),
                $request->getContent(),
            );

            return response()->json(['accepted' => true, 'duplicate' => $result['duplicate']], 200);
        } catch (PaymentCheckoutException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->codeName,
            ], $exception->status);
        }
    }
}
