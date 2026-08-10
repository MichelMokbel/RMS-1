<?php

namespace App\Http\Controllers\Quotations;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\QuotationVersion;
use App\Services\Quotations\Rendering\QuotationHtmlRenderer;
use App\Services\Security\BranchAccessService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class QuotationPreviewController extends Controller
{
    public function __invoke(
        Request $request,
        Quotation $quotation,
        BranchAccessService $branchAccess,
        QuotationHtmlRenderer $renderer,
        ?QuotationVersion $version = null,
    ): Response {
        $user = $request->user();
        abort_unless($user?->can('quotations.access'), 403);
        abort_unless($branchAccess->canAccessBranch($user, (int) $quotation->branch_id), 403);

        $version ??= $quotation->versions()->latest('revision')->first();
        abort_unless($version && (int) $version->quotation_id === (int) $quotation->id, 404);

        return response($renderer->render((array) $version->snapshot))
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'")
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
