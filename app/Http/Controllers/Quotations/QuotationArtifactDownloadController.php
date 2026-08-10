<?php

namespace App\Http\Controllers\Quotations;

use App\Http\Controllers\Controller;
use App\Models\QuotationArtifact;
use App\Services\Quotations\Storage\QuotationArtifactService;
use App\Services\Security\BranchAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class QuotationArtifactDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        QuotationArtifact $artifact,
        BranchAccessService $branchAccess,
        QuotationArtifactService $artifacts,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user?->can('quotations.access'), 403);

        $artifact->loadMissing('version.quotation');
        $quotation = $artifact->version?->quotation;
        abort_unless($quotation && $branchAccess->canAccessBranch($user, (int) $quotation->branch_id), 403);
        abort_unless((int) $quotation->company_id > 0 && $artifact->isReady(), 404);

        return redirect()->away($artifacts->temporaryUrl($artifact));
    }
}
