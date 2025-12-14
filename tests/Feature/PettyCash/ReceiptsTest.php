<?php

use App\Models\PettyCashExpense;
use App\Services\PettyCash\PettyCashReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('stores receipt and path on expense', function () {
    Storage::fake('public');
    $expense = PettyCashExpense::factory()->create();
    $service = app(PettyCashReceiptService::class);

    $file = UploadedFile::fake()->image('receipt.jpg');
    $service->upload($expense, $file);

    $expense->refresh();

    expect($expense->receipt_path)->not->toBeNull();
    Storage::disk('public')->assertExists($expense->receipt_path);
});

it('rejects invalid mime type for receipt', function () {
    Storage::fake('public');
    $expense = PettyCashExpense::factory()->create();
    $service = app(PettyCashReceiptService::class);

    $file = UploadedFile::fake()->create('bad.txt', 10, 'text/plain');

    expect(fn () => $service->upload($expense, $file))->toThrow(ValidationException::class);
});
