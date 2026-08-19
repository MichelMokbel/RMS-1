<?php

namespace App\Http\Controllers\AP;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Job;
use App\Models\Supplier;
use App\Services\AP\ApWorkspaceQueryService;
use App\Support\AP\DocumentTypeMap;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApWorkspacePrintController extends Controller
{
    public function __invoke(Request $request, ApWorkspaceQueryService $queries): View
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['documents', 'payments'])],
            'tab' => ['nullable', Rule::in(['all', 'bills', 'expenses', 'reimbursements', 'approvals'])],
            'document_type' => ['nullable', Rule::in(['all', ...DocumentTypeMap::types()])],
            'approval_status' => ['nullable', Rule::in(['all', 'draft', 'submitted', 'manager_approved', 'approved', 'rejected'])],
            'workflow_state' => ['nullable', Rule::in(['all', 'draft', 'submitted', 'manager_approved', 'approved_pending_post', 'posted', 'posted_pending_settlement', 'partially_paid', 'closed', 'rejected', 'void'])],
            'payment_state' => ['nullable', Rule::in(['all', 'pending', 'open', 'partially_paid', 'settled', 'paid', 'void'])],
            'expense_channel' => ['nullable', Rule::in(['all', 'vendor', 'petty_cash', 'reimbursement'])],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'job_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:191'],
            'payment_supplier_id' => ['nullable', 'integer', 'min:1'],
            'payment_method' => ['nullable', Rule::in(['cash', 'bank_transfer', 'card', 'cheque', 'other', 'petty_cash'])],
            'payment_date_from' => ['nullable', 'date_format:Y-m-d'],
            'payment_date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if (isset($validated['date_from'], $validated['date_to']) && $validated['date_to'] < $validated['date_from']) {
            throw ValidationException::withMessages(['date_to' => __('The date to must be on or after the date from.')]);
        }

        if (isset($validated['payment_date_from'], $validated['payment_date_to']) && $validated['payment_date_to'] < $validated['payment_date_from']) {
            throw ValidationException::withMessages(['payment_date_to' => __('The payment date to must be on or after the payment date from.')]);
        }

        $type = $validated['type'];
        if ($type === 'payments') {
            $user = $request->user();
            abort_unless($user?->hasAnyRole(['admin', 'manager']) || $user?->can('finance.access'), 403);
        }

        $filters = collect($validated)->except('type')->all();
        $records = $type === 'payments'
            ? $queries->payments($filters)->get()
            : $queries->documents($filters)->get();

        return view('payables.filtered-print', [
            'type' => $type,
            'records' => $records,
            'filterLabels' => $this->filterLabels($type, $filters),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function filterLabels(string $type, array $filters): array
    {
        $labels = [];

        if ($type === 'payments') {
            $this->addModelLabel($labels, 'Supplier', Supplier::class, $filters['payment_supplier_id'] ?? null);
            $this->addTextLabel($labels, 'Method', $filters['payment_method'] ?? null);
            $this->addTextLabel($labels, 'Date From', $filters['payment_date_from'] ?? null);
            $this->addTextLabel($labels, 'Date To', $filters['payment_date_to'] ?? null);

            return $labels;
        }

        $this->addTextLabel($labels, 'Tab', $filters['tab'] ?? null);
        $this->addTextLabel($labels, 'Search', $filters['search'] ?? null);
        $this->addModelLabel($labels, 'Supplier', Supplier::class, $filters['supplier_id'] ?? null);
        $this->addModelLabel($labels, 'Branch', Branch::class, $filters['branch_id'] ?? null);
        $this->addModelLabel($labels, 'Department', Department::class, $filters['department_id'] ?? null);
        $this->addModelLabel($labels, 'Job', Job::class, $filters['job_id'] ?? null, 'code');
        $this->addTextLabel($labels, 'Document Type', $filters['document_type'] ?? null);
        $this->addTextLabel($labels, 'Approval', $filters['approval_status'] ?? null);
        $this->addTextLabel($labels, 'Workflow', $filters['workflow_state'] ?? null);
        $this->addTextLabel($labels, 'Payment', $filters['payment_state'] ?? null);
        $this->addTextLabel($labels, 'Channel', $filters['expense_channel'] ?? null);
        $this->addTextLabel($labels, 'Date From', $filters['date_from'] ?? null);
        $this->addTextLabel($labels, 'Date To', $filters['date_to'] ?? null);

        return $labels;
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function addTextLabel(array &$labels, string $label, mixed $value): void
    {
        $value = trim((string) $value);
        if ($value === '' || $value === 'all') {
            return;
        }

        $labels[$label] = str($value)->replace('_', ' ')->headline()->toString();
    }

    /**
     * @param  array<string, string>  $labels
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    private function addModelLabel(array &$labels, string $label, string $model, mixed $id, string $column = 'name'): void
    {
        if (! $id) {
            return;
        }

        $labels[$label] = (string) ($model::query()->whereKey($id)->value($column) ?? $id);
    }
}
