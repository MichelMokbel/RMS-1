<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Pos\BootstrapRequest;
use App\Services\POS\PosBootstrapService;

class BootstrapController extends Controller
{
    public function __invoke(BootstrapRequest $request, PosBootstrapService $bootstrap)
    {
        $terminal = $request->attributes->get('pos_terminal');

        return response()->json(
            $bootstrap->bootstrap(
                terminal: $terminal,
                since: $request->validated('since')
            )
        );
    }
}

