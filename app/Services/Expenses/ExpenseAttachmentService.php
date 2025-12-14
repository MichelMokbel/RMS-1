<?php

namespace App\Services\Expenses;

use App\Models\Expense;
use App\Models\ExpenseAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExpenseAttachmentService
{
    public function upload(Expense $expense, UploadedFile $file, int $userId): ExpenseAttachment
    {
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        if (! in_array(strtolower($file->getClientOriginalExtension()), $allowed, true)) {
            throw ValidationException::withMessages(['file' => __('Only images or PDF allowed.')]);
        }

        $maxKb = (int) config('expenses.max_attachment_kb', 4096);
        if ($file->getSize() / 1024 > $maxKb) {
            throw ValidationException::withMessages(['file' => __('File too large.')]);
        }

        $path = $file->storeAs(
            'expenses/'.$expense->id,
            Str::uuid().'.'.$file->getClientOriginalExtension(),
            'public'
        );

        return ExpenseAttachment::create([
            'expense_id' => $expense->id,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'uploaded_by' => $userId,
        ]);
    }

    public function delete(ExpenseAttachment $attachment): void
    {
        if ($attachment->file_path && Storage::disk('public')->exists($attachment->file_path)) {
            Storage::disk('public')->delete($attachment->file_path);
        }
        $attachment->delete();
    }
}
