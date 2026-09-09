<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Storefront\StorefrontEventService;
use Illuminate\Http\Request;

class PublicStorefrontEventController extends Controller
{
    public function __invoke(Request $request, StorefrontEventService $events)
    {
        $result = $events->record($request->all());

        return response()->json([
            'accepted' => true,
            'duplicate' => $result['duplicate'],
        ], 202);
    }
}
