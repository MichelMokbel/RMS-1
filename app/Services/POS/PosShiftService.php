<?php

namespace App\Services\POS;

use App\Models\PosShift;
use App\Models\Sale;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosShiftService
{
    public function open(int $branchId, int $userId, int $openingCashCents, ?int $actorId = null): PosShift
    {
        $branchId = $branchId > 0 ? $branchId : 1;

        return DB::transaction(function () use ($branchId, $userId, $openingCashCents, $actorId) {
            try {
                return PosShift::create([
                    'branch_id' => $branchId,
                    'user_id' => $userId,
                    'active' => true,
                    'status' => 'open',
                    'opening_cash_cents' => max(0, (int) $openingCashCents),
                    'opened_at' => now(),
                    'created_by' => $actorId ?? $userId,
                    'created_at' => now(),
                ]);
            } catch (QueryException $e) {
                throw ValidationException::withMessages([
                    'shift' => __('An active shift already exists for this user and branch.'),
                ]);
            }
        });
    }

    public function close(PosShift $shift, int $countedCashCents, ?string $notes, int $actorId): PosShift
    {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => __('Shift is not open.')]);
        }

        return DB::transaction(function () use ($shift, $countedCashCents, $notes, $actorId) {
            // Expected cash = opening cash + cash payments in this shift - cash refunds (future hook).
            $cashCollected = (int) DB::table('payments as p')
                ->join('payment_allocations as pa', 'pa.payment_id', '=', 'p.id')
                ->join('sales as s', function ($join) {
                    $join->on('s.id', '=', 'pa.allocatable_id')
                        ->where('pa.allocatable_type', '=', Sale::class);
                })
                ->whereNull('p.voided_at')
                ->whereNull('pa.voided_at')
                ->where('p.method', 'cash')
                ->where('s.pos_shift_id', $shift->id)
                ->sum('pa.amount_cents');

            $expected = (int) $shift->opening_cash_cents + $cashCollected;
            $counted = (int) $countedCashCents;
            $variance = $counted - $expected;

            $shift->update([
                'closing_cash_cents' => $counted,
                'expected_cash_cents' => $expected,
                'variance_cents' => $variance,
                'notes' => $notes,
                'closed_at' => now(),
                'closed_by' => $actorId,
                'status' => 'closed',
                'active' => null,
            ]);

            return $shift->fresh();
        });
    }

    public function activeShiftFor(int $branchId, int $userId): ?PosShift
    {
        $branchId = $branchId > 0 ? $branchId : 1;

        return PosShift::query()
            ->where('branch_id', $branchId)
            ->where('user_id', $userId)
            ->where('active', 1)
            ->where('status', 'open')
            ->whereNull('closed_at')
            ->first();
    }
}

