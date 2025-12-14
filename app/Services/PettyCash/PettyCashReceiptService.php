<?php

namespace App\Services\PettyCash;

use App\Models\PettyCashExpense;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PettyCashReceiptService
{
    public function upload(PettyCashExpense $expense, UploadedFile $file): PettyCashExpense
    {
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        if (! in_array(strtolower($file->getClientOriginalExtension()), $allowed, true)) {
            throw ValidationException::withMessages(['file' => __('Only images or PDF allowed.')]);
        }

        $maxKb = (int) config('petty_cash.max_receipt_kb', 4096);
        if ($file->getSize() / 1024 > $maxKb) {
            throw ValidationException::withMessages(['file' => __('File too large.')]);
        }

        if ($expense->receipt_path && Storage::disk('public')->exists($expense->receipt_path)) {
            Storage::disk('public')->delete($expense->receipt_path);
        }

        $path = $file->storeAs(
            'petty-cash/expenses/'.$expense->id,
            Str::uuid().'.'.$file->getClientOriginalExtension(),
            'public'
        );

        $expense->receipt_path = $path;
        $expense->save();

        return $expense->fresh();
    }
}
