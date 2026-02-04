<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Pos\SequenceReserveRequest;
use App\Services\POS\PosSequenceService;

class SequenceController extends Controller
{
    public function reserve(SequenceReserveRequest $request, PosSequenceService $sequences)
    {
        $terminal = $request->attributes->get('pos_terminal');
        $data = $request->validated();

        $range = $sequences->reserve(
            terminalId: (int) $terminal->id,
            businessDate: (string) $data['business_date'],
            count: (int) $data['count'],
        );

        return response()->json([
            'terminal' => ['id' => (int) $terminal->id, 'code' => (string) $terminal->code],
            'business_date' => $data['business_date'],
            'count' => (int) $data['count'],
            'reserved_start' => $range['reserved_start'],
            'reserved_end' => $range['reserved_end'],
        ]);
    }
}

