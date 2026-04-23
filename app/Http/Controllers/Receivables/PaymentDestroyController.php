<?php

namespace App\Http\Controllers\Receivables;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\AR\ArPaymentDeleteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class PaymentDestroyController extends Controller
{
    public function __invoke(Payment $payment, ArPaymentDeleteService $deleteService): RedirectResponse
    {
        abort_unless(Auth::user()?->hasRole('admin'), 403);

        $deleteService->delete($payment, (int) Auth::id());

        return redirect()
            ->route('receivables.payments.index')
            ->with('status', __('Payment voided.'));
    }
}
