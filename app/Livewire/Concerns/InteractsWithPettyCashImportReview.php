<?php

namespace App\Livewire\Concerns;

use App\Models\ExpenseCategory;
use App\Models\PettyCashImportBatch;
use App\Services\PettyCash\PettyCashImportEditor;

trait InteractsWithPettyCashImportReview
{
    /** @var array<int, array<string, mixed>> */
    public array $invoiceForms = [];

    /** @var array<int, array<string, mixed>> */
    public array $rowForms = [];

    public string $filter_date = '';

    public string $filter_category = '';

    public string $filter_supplier = '';

    public string $filter_wallet = '';

    public string $filter_paid = '';

    public string $filter_status = '';

    public string $bulk_supplier_id = '';

    public string $bulk_wallet_id = '';

    public string $bulk_paid = '';

    public array $newInvoice = [
        'entry_id' => '', 'business_date' => '', 'supplier_id' => '', 'reference_number' => '',
        'due_date' => '', 'category' => '', 'wallet_id' => '', 'paid' => '0', 'notes' => '',
        'description' => '', 'quantity' => '1', 'unit_price' => '',
    ];

    public function saveInvoice(int $invoiceId, PettyCashImportEditor $editor): void
    {
        $invoice = $this->editableInvoice($invoiceId);
        $data = $this->validate([
            "invoiceForms.$invoiceId.business_date" => ['required', 'date'],
            "invoiceForms.$invoiceId.supplier_id" => ['required', 'integer', 'exists:suppliers,id'],
            "invoiceForms.$invoiceId.reference_number" => ['nullable', 'string', 'max:100'],
            "invoiceForms.$invoiceId.due_date" => ['nullable', 'date'],
            "invoiceForms.$invoiceId.category" => ['required', 'string', 'max:100'],
            "invoiceForms.$invoiceId.wallet_id" => ['required', 'integer', 'exists:petty_cash_wallets,id'],
            "invoiceForms.$invoiceId.paid" => ['required', 'boolean'],
            "invoiceForms.$invoiceId.notes" => ['nullable', 'string', 'max:2000'],
        ])['invoiceForms'][$invoiceId];

        $editor->updateInvoice($invoice, $this->revision(), $data, auth()->user());
        $this->afterEdit(__('Invoice changes saved and the batch was revalidated.'));
    }

    public function saveRow(int $rowId, PettyCashImportEditor $editor): void
    {
        $row = $this->editableRow($rowId);
        $data = $this->validate([
            "rowForms.$rowId.description" => ['required', 'string', 'max:255'],
            "rowForms.$rowId.quantity" => ['required', 'numeric', 'min:0.001', 'decimal:0,3'],
            "rowForms.$rowId.unit_price" => ['required', 'numeric', 'min:0', 'decimal:0,4'],
        ])['rowForms'][$rowId];

        $editor->updateRow($row, $this->revision(), $data, auth()->user());
        $this->afterEdit(__('Line-item changes saved and the batch was revalidated.'));
    }

    public function addRow(int $invoiceId, PettyCashImportEditor $editor): void
    {
        $editor->addRow($this->editableInvoice($invoiceId), $this->revision(), [
            'description' => __('New expense line'), 'quantity' => 1, 'unit_price' => 0,
        ], auth()->user());
        $this->afterEdit(__('A new line was added. Update its description and amount below.'));
    }

    public function addInvoice(PettyCashImportEditor $editor): void
    {
        $batch = $this->editableBatch();
        $data = $this->validate([
            'newInvoice.entry_id' => ['required', 'string', 'max:191'],
            'newInvoice.business_date' => ['required', 'date'],
            'newInvoice.supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'newInvoice.reference_number' => ['nullable', 'string', 'max:100'],
            'newInvoice.due_date' => ['nullable', 'date'],
            'newInvoice.category' => ['required', 'string', 'max:100'],
            'newInvoice.wallet_id' => ['required', 'integer', 'exists:petty_cash_wallets,id'],
            'newInvoice.paid' => ['required', 'boolean'],
            'newInvoice.notes' => ['nullable', 'string', 'max:2000'],
            'newInvoice.description' => ['required', 'string', 'max:255'],
            'newInvoice.quantity' => ['required', 'numeric', 'min:0.001', 'decimal:0,3'],
            'newInvoice.unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
        ])['newInvoice'];

        $editor->addInvoice($batch, $this->revision(), [
            'entry_id' => $data['entry_id'], 'business_date' => $data['business_date'],
            'supplier_id' => (int) $data['supplier_id'], 'reference_number' => $data['reference_number'] ?: null,
            'due_date' => $data['due_date'] ?: null, 'category' => $data['category'],
            'wallet_id' => (int) $data['wallet_id'],
            'paid' => $data['paid'] === '1' || $data['paid'] === 1 || $data['paid'] === true,
            'notes' => $data['notes'] ?: null,
            'row' => ['description' => $data['description'], 'quantity' => $data['quantity'], 'unit_price' => $data['unit_price']],
        ], auth()->user());

        $this->newInvoice = [
            'entry_id' => '', 'business_date' => '', 'supplier_id' => '', 'reference_number' => '',
            'due_date' => '', 'category' => '', 'wallet_id' => '', 'paid' => '0', 'notes' => '',
            'description' => '', 'quantity' => '1', 'unit_price' => '',
        ];
        $this->afterEdit(__('A staged expense was added and the batch was revalidated.'));
    }

    public function toggleInvoice(int $invoiceId, bool $excluded, PettyCashImportEditor $editor): void
    {
        $editor->toggleInvoiceExcluded($this->editableInvoice($invoiceId), $this->revision(), $excluded, auth()->user());
        $this->afterEdit($excluded ? __('Invoice excluded from this import.') : __('Invoice restored to this import.'));
    }

    public function toggleRow(int $rowId, bool $excluded, PettyCashImportEditor $editor): void
    {
        $editor->toggleRowExcluded($this->editableRow($rowId), $this->revision(), $excluded, auth()->user());
        $this->afterEdit($excluded ? __('Line excluded from this import.') : __('Line restored to this import.'));
    }

    public function applyBulkOverride(PettyCashImportEditor $editor): void
    {
        $values = array_filter([
            'supplier_id' => $this->bulk_supplier_id !== '' ? (int) $this->bulk_supplier_id : null,
            'wallet_id' => $this->bulk_wallet_id !== '' ? (int) $this->bulk_wallet_id : null,
            'paid' => $this->bulk_paid !== '' ? $this->bulk_paid === '1' : null,
        ], fn ($value): bool => $value !== null);

        if ($values === []) {
            $this->addError('bulk_override', __('Choose at least one override value.'));

            return;
        }

        $editor->bulkOverride($this->editableBatch(), $this->revision(), $this->activeFilters(), $values, auth()->user());
        $this->reset('bulk_supplier_id', 'bulk_wallet_id', 'bulk_paid');
        $this->afterEdit(__('Overrides were applied to the filtered invoices and the batch was revalidated.'));
    }

    public function revalidateImport(PettyCashImportEditor $editor): void
    {
        $editor->revalidate($this->editableBatch(), $this->revision(), auth()->user());
        $this->afterEdit(__('The complete import was revalidated against the latest accounting configuration.'));
    }

    private function syncForms(): void
    {
        $batch = $this->batch()->load('invoices.rows');
        $categoryNames = ExpenseCategory::query()
            ->whereIn('id', $batch->invoices->pluck('header')->pluck('category_id')->filter()->unique())
            ->pluck('name', 'id');
        $this->invoiceForms = [];
        $this->rowForms = [];

        foreach ($batch->invoices as $invoice) {
            $header = $invoice->header ?? [];
            $this->invoiceForms[$invoice->id] = [
                'business_date' => optional($invoice->business_date)->toDateString() ?: ($header['business_date'] ?? optional($batch->business_date)->toDateString()),
                'supplier_id' => (string) ($header['supplier_id'] ?? ''),
                'reference_number' => (string) ($header['reference_number'] ?? ''),
                'due_date' => (string) ($header['due_date'] ?? ''),
                'category' => (string) ($header['category'] ?? $header['category_name'] ?? $categoryNames->get((int) ($header['category_id'] ?? 0), '')),
                'wallet_id' => (string) ($header['wallet_id'] ?? ''),
                'paid' => (string) ((bool) ($header['paid_requested'] ?? $header['paid'] ?? false) ? 1 : 0),
                'notes' => (string) ($header['notes'] ?? ''),
            ];
            foreach ($invoice->rows as $row) {
                $payload = $row->payload ?? [];
                $this->rowForms[$row->id] = [
                    'description' => (string) ($payload['description'] ?? ''),
                    'quantity' => (string) ($payload['quantity'] ?? 1),
                    'unit_price' => (string) ($payload['unit_price'] ?? ''),
                ];
            }
        }
    }

    private function afterEdit(string $message): void
    {
        $this->resetValidation();
        $this->syncForms();
        session()->flash('status', $message);
    }

    private function revision(): int
    {
        return (int) ($this->batch()->revision ?? 0);
    }

    private function editableBatch(): PettyCashImportBatch
    {
        $batch = $this->batch();
        abort_unless(($batch->import_mode ?? 'daily') === 'bulk' && in_array($this->statusValue($batch), ['needs_review', 'ready'], true), 422);

        return $batch;
    }

    private function editableInvoice(int $invoiceId)
    {
        return $this->editableBatch()->invoices()->findOrFail($invoiceId);
    }

    private function editableRow(int $rowId)
    {
        return $this->editableBatch()->rows()->findOrFail($rowId);
    }

    /** @return array<string, mixed> */
    private function activeFilters(): array
    {
        return array_filter([
            'business_date' => $this->filter_date, 'category' => $this->filter_category,
            'supplier_id' => $this->filter_supplier, 'wallet_id' => $this->filter_wallet,
            'paid' => $this->filter_paid, 'status' => $this->filter_status,
        ], fn ($value): bool => $value !== '');
    }
}
