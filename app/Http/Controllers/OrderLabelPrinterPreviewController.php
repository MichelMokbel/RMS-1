<?php

namespace App\Http\Controllers;

use App\Models\OrderLabelPrinterProfile;
use App\Models\User;
use App\Services\Orders\OrderLabelPrinterTestService;
use Illuminate\Http\Request;

class OrderLabelPrinterPreviewController extends Controller
{
    public function __invoke(Request $request, OrderLabelPrinterProfile $profile, OrderLabelPrinterTestService $service)
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $pdf = $service->preview($profile, $actor);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="order-label-preview.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
