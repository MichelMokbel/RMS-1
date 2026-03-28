<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArInvoice;
use App\Models\MealSubscription;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Money\MinorUnits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerPortalDashboardController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $customer = $request->user()->customer;
        $today = now()->toDateString();

        $openInvoices = ArInvoice::query()
            ->where('customer_id', $customer->id)
            ->where('status', '!=', 'voided')
            ->where('balance_cents', '>', 0);

        $latestPayment = Payment::query()
            ->where('customer_id', $customer->id)
            ->whereNull('voided_at')
            ->latest('received_at')
            ->first();

        return response()->json([
            'summary' => [
                'active_subscriptions' => MealSubscription::query()
                    ->where('customer_id', $customer->id)
                    ->where('status', 'active')
                    ->count(),
                'unpaid_invoice_count' => (clone $openInvoices)->count(),
                'outstanding_balance' => $this->money((int) (clone $openInvoices)->sum('balance_cents')),
                'overdue_balance' => $this->money((int) (clone $openInvoices)->whereDate('due_date', '<', $today)->sum('balance_cents')),
                'last_payment' => $latestPayment ? $this->serializePayment($latestPayment) : null,
            ],
            'due_payments' => [
                'overdue' => $this->money((int) (clone $openInvoices)->whereDate('due_date', '<', $today)->sum('balance_cents')),
                'due_today' => $this->money((int) (clone $openInvoices)->whereDate('due_date', '=', $today)->sum('balance_cents')),
                'upcoming' => $this->money((int) (clone $openInvoices)->whereDate('due_date', '>', $today)->sum('balance_cents')),
            ],
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->with(['invoice'])
            ->where('customer_id', $request->user()->customer_id)
            ->latest('scheduled_date')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($orders->items())->map(fn (Order $order) => $this->serializeOrder($order))->values(),
            'meta' => $this->paginationMeta($orders),
        ]);
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $subscriptions = MealSubscription::query()
            ->with(['days'])
            ->where('customer_id', $request->user()->customer_id)
            ->latest('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($subscriptions->items())->map(fn (MealSubscription $subscription) => $this->serializeSubscription($subscription))->values(),
            'meta' => $this->paginationMeta($subscriptions),
        ]);
    }

    public function showSubscription(Request $request, int $subscription): JsonResponse
    {
        $record = MealSubscription::query()
            ->with(['days', 'pauses'])
            ->where('customer_id', $request->user()->customer_id)
            ->whereKey($subscription)
            ->firstOrFail();

        return response()->json([
            'data' => $this->serializeSubscription($record, true),
        ]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $invoices = ArInvoice::query()
            ->where('customer_id', $request->user()->customer_id)
            ->where('status', '!=', 'voided')
            ->latest('issue_date')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($invoices->items())->map(fn (ArInvoice $invoice) => $this->serializeInvoice($invoice))->values(),
            'meta' => $this->paginationMeta($invoices),
        ]);
    }

    public function showInvoice(Request $request, int $invoice): JsonResponse
    {
        $record = ArInvoice::query()
            ->where('customer_id', $request->user()->customer_id)
            ->whereKey($invoice)
            ->firstOrFail();

        return response()->json([
            'data' => $this->serializeInvoice($record),
        ]);
    }

    public function payments(Request $request): JsonResponse
    {
        $payments = Payment::query()
            ->where('customer_id', $request->user()->customer_id)
            ->latest('received_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($payments->items())->map(fn (Payment $payment) => $this->serializePayment($payment))->values(),
            'meta' => $this->paginationMeta($payments),
        ]);
    }

    public function showPayment(Request $request, int $payment): JsonResponse
    {
        $record = Payment::query()
            ->where('customer_id', $request->user()->customer_id)
            ->whereKey($payment)
            ->firstOrFail();

        return response()->json([
            'data' => $this->serializePayment($record),
        ]);
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, (int) $request->integer('per_page', 15)));
    }

    /**
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator  $paginator
     * @return array<string, int|null>
     */
    private function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'source' => $order->source,
            'status' => $order->status,
            'scheduled_date' => optional($order->scheduled_date)->toDateString(),
            'scheduled_time' => optional($order->scheduled_time)->format('H:i:s'),
            'total' => $this->decimalMoney((float) $order->total_amount),
            'invoice' => $order->invoice ? [
                'id' => $order->invoice->id,
                'invoice_number' => $order->invoice->invoice_number,
                'status' => $order->invoice->status,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSubscription(MealSubscription $subscription, bool $includePauses = false): array
    {
        $payload = [
            'id' => $subscription->id,
            'subscription_code' => $subscription->subscription_code,
            'status' => $subscription->status,
            'start_date' => optional($subscription->start_date)->toDateString(),
            'end_date' => optional($subscription->end_date)->toDateString(),
            'plan_meals_total' => $subscription->plan_meals_total,
            'meals_used' => $subscription->meals_used,
            'delivery_time' => $subscription->delivery_time,
            'address_snapshot' => $subscription->address_snapshot,
            'phone_snapshot' => $subscription->phone_snapshot,
            'days' => $subscription->relationLoaded('days')
                ? $subscription->days->pluck('weekday')->map(fn ($weekday) => (int) $weekday)->values()->all()
                : [],
        ];

        if ($includePauses && $subscription->relationLoaded('pauses')) {
            $payload['pauses'] = $subscription->pauses->map(fn ($pause) => [
                'id' => $pause->id,
                'pause_start' => optional($pause->pause_start)->toDateString(),
                'pause_end' => optional($pause->pause_end)->toDateString(),
            ])->values()->all();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeInvoice(ArInvoice $invoice): array
    {
        $today = now()->toDateString();
        $dueBucket = 'upcoming';
        if ($invoice->due_date && $invoice->due_date->lt($today)) {
            $dueBucket = 'overdue';
        } elseif ($invoice->due_date && $invoice->due_date->toDateString() === $today) {
            $dueBucket = 'due_today';
        }

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'type' => $invoice->type,
            'issue_date' => optional($invoice->issue_date)->toDateString(),
            'due_date' => optional($invoice->due_date)->toDateString(),
            'due_bucket' => $dueBucket,
            'amounts' => [
                'total' => $this->money((int) $invoice->total_cents, $invoice->currency),
                'paid' => $this->money((int) $invoice->paid_total_cents, $invoice->currency),
                'balance' => $this->money((int) $invoice->balance_cents, $invoice->currency),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePayment(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'source' => $payment->source,
            'method' => $payment->method,
            'received_at' => optional($payment->received_at)->toIso8601String(),
            'reference' => $payment->reference,
            'notes' => $payment->notes,
            'is_voided' => $payment->voided_at !== null,
            'amount' => $this->money((int) $payment->amount_cents, $payment->currency),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function money(int $minorUnits, ?string $currency = null): array
    {
        return [
            'cents' => $minorUnits,
            'formatted' => MinorUnits::format($minorUnits),
            'currency' => $currency ?: (string) config('pos.currency'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decimalMoney(float $amount): array
    {
        return [
            'amount' => round($amount, 3),
            'formatted' => number_format($amount, 3, '.', ''),
            'currency' => (string) config('pos.currency'),
        ];
    }
}
