<?php

namespace App\Http\Controllers\AP;

use App\Http\Controllers\Controller;
use App\Models\ApInvoice;
use App\Models\CompanyDocumentProfile;
use Illuminate\Contracts\View\View;

class ApInvoicePrintController extends Controller
{
    public function __invoke(ApInvoice $invoice): View
    {
        $invoice->load([
            'company',
            'supplier',
            'category',
            'items',
            'expenseProfile.wallet',
            'department',
            'job',
            'jobPhase',
            'jobCostCode',
            'period',
            'createdBy',
            'allocations.payment',
        ]);

        $profile = $invoice->company_id
            ? CompanyDocumentProfile::query()->where('company_id', $invoice->company_id)->first()
            : null;

        return view('payables.invoice-print', [
            'invoice' => $invoice,
            'profile' => $profile,
        ]);
    }
}
