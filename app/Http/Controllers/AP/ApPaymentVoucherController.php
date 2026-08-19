<?php

namespace App\Http\Controllers\AP;

use App\Http\Controllers\Controller;
use App\Models\ApPayment;
use App\Models\CompanyDocumentProfile;
use Illuminate\Contracts\View\View;

class ApPaymentVoucherController extends Controller
{
    public function __invoke(ApPayment $payment): View
    {
        $payment->load([
            'supplier',
            'company',
            'branch',
            'department',
            'job',
            'bankAccount',
            'createdBy',
            'postedBy',
            'voidedBy',
            'allAllocations.invoice',
        ]);

        $profile = $payment->company_id
            ? CompanyDocumentProfile::query()->where('company_id', $payment->company_id)->first()
            : null;

        return view('payables.payment-voucher', [
            'payment' => $payment,
            'profile' => $profile,
            'amountInWords' => $this->amountInWords($payment),
        ]);
    }

    private function amountInWords(ApPayment $payment): string
    {
        [$whole, $fraction] = explode('.', number_format(abs((float) $payment->amount), 2, '.', ''));
        $words = number_format((int) $whole);

        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter('en', \NumberFormatter::SPELLOUT);
            $formatted = $formatter->format((int) $whole);
            if (is_string($formatted) && $formatted !== '') {
                $words = ucfirst($formatted);
            }
        }

        $currency = strtoupper((string) ($payment->currency_code ?: $payment->company?->base_currency ?: config('pos.currency', 'QAR')));

        return $words.' '.$currency.' and '.$fraction.'/100 only';
    }
}
