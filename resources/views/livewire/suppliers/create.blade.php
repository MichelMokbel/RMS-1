<?php

use App\Models\LedgerAccount;
use App\Models\PaymentTerm;
use App\Models\Supplier;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $name = '';
    public ?string $contact_person = null;
    public ?string $email = null;
    public ?string $phone = null;
    public ?string $address = null;
    public ?string $qid_cr = null;
    public string $status = 'active';
    public ?int $payment_term_id = null;
    public ?int $default_expense_account_id = null;
    public ?string $preferred_payment_method = null;
    public string $hold_status = 'open';
    public bool $requires_1099 = false;
    public ?float $approval_threshold = null;

    public function create(): void
    {
        $data = $this->validate($this->rules());

        if (! $data['status']) {
            $data['status'] = 'active';
        }

        Supplier::create($data);

        session()->flash('status', __('Supplier created.'));
        $this->redirectRoute('suppliers.index', navigate: true);
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'unique:suppliers,name'],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+()\\-\\s\\.xX]+$/'],
            'address' => ['nullable', 'string'],
            'qid_cr' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'payment_term_id' => ['nullable', 'integer', 'exists:payment_terms,id'],
            'default_expense_account_id' => ['nullable', 'integer', 'exists:ledger_accounts,id'],
            'preferred_payment_method' => ['nullable', 'in:cash,bank_transfer,card,cheque,other,petty_cash'],
            'hold_status' => ['required', 'in:open,hold,blocked'],
            'requires_1099' => ['boolean'],
            'approval_threshold' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function paymentTerms()
    {
        return Schema::hasTable('payment_terms') ? PaymentTerm::query()->where('is_active', true)->orderBy('name')->get() : collect();
    }

    public function expenseAccounts()
    {
        return Schema::hasTable('ledger_accounts')
            ? LedgerAccount::query()->where('type', 'expense')->orderBy('code')->get()
            : collect();
    }
}; ?>

<div class="w-full max-w-3xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __('Create Supplier') }}
        </h1>
        <flux:button :href="route('suppliers.index')" wire:navigate variant="ghost">
            {{ __('Back') }}
        </flux:button>
    </div>

    <form wire:submit="create" class="space-y-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <flux:input wire:model="name" :label="__('Name')" required maxlength="100" />
            <flux:input wire:model="contact_person" :label="__('Contact Person')" maxlength="100" />
            <flux:input wire:model="email" :label="__('Email')" type="email" maxlength="100" />
            <flux:input wire:model="phone" :label="__('Phone')" maxlength="20" />
            <flux:input wire:model="qid_cr" :label="__('QID/CR')" maxlength="100" />
            <div>
                <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Payment Term') }}</label>
                <select wire:model="payment_term_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('None') }}</option>
                    @foreach($this->paymentTerms() as $term)
                        <option value="{{ $term->id }}">{{ $term->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Default Expense Account') }}</label>
                <select wire:model="default_expense_account_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('None') }}</option>
                    @foreach($this->expenseAccounts() as $account)
                        <option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Preferred Payment Method') }}</label>
                <select wire:model="preferred_payment_method" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('None') }}</option>
                    <option value="bank_transfer">{{ __('Bank Transfer') }}</option>
                    <option value="cash">{{ __('Cash') }}</option>
                    <option value="card">{{ __('Card') }}</option>
                    <option value="cheque">{{ __('Cheque') }}</option>
                    <option value="other">{{ __('Other') }}</option>
                    <option value="petty_cash">{{ __('Petty Cash') }}</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Hold Status') }}</label>
                <select wire:model="hold_status" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="open">{{ __('Open') }}</option>
                    <option value="hold">{{ __('Hold') }}</option>
                    <option value="blocked">{{ __('Blocked') }}</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Status') }}</label>
                <select
                    wire:model="status"
                    class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                >
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                </select>
            </div>
            <flux:input wire:model="approval_threshold" :label="__('Approval Threshold')" type="number" step="0.01" min="0" />
        </div>

        <flux:textarea wire:model="address" :label="__('Address')" rows="3" />
        <div class="flex items-center">
            <flux:checkbox wire:model="requires_1099" :label="__('Requires 1099 tracking')" />
        </div>

        <div class="flex justify-end gap-3">
            <flux:button :href="route('suppliers.index')" wire:navigate variant="ghost">
                {{ __('Cancel') }}
            </flux:button>
            <flux:button type="submit" variant="primary">
                {{ __('Create') }}
            </flux:button>
        </div>
    </form>
</div>
